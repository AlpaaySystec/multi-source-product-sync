<?php
/**
 * MSS_Abandoned_Products
 *
 * محصولاتی که پروفایل مبدأشان حذف شده، یا هرگز زمان‌بندی همگام‌سازی نداشته (و در نتیجه دیگر
 * هیچ‌گاه بروزرسانی نمی‌شوند) را به‌صورت دائمی (نه transient) فهرست می‌کند تا در تب «محصولات
 * رها شده» به مدیر فروشگاه نمایش داده شوند.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class MSS_Abandoned_Products {

    const OPTION_KEY = 'mss_abandoned_products';

    public function __construct() {
        add_action( 'wp_trash_post', array( __CLASS__, 'on_profile_trashed' ) );
        add_action( 'before_delete_post', array( __CLASS__, 'on_profile_deleted' ) );
        add_action( 'untrash_post', array( __CLASS__, 'on_profile_restored' ) );
        add_action( 'mss_daily_orphan_check', array( __CLASS__, 'scan' ), 20 );
    }

    private static function get_store() {
        $data = get_option( self::OPTION_KEY, array() );
        return is_array( $data ) ? $data : array();
    }

    private static function save_store( $data ) {
        update_option( self::OPTION_KEY, $data, false );
    }

    public static function get_all() {
        return self::get_store();
    }

    public static function count() {
        return count( self::get_store() );
    }

    /**
     * علامت‌گذاری یک محصول به‌عنوان رهاشده
     */
    public static function mark( $product_id, $reason, $profile_id = 0, $profile_title = '' ) {
        $store = self::get_store();
        $store[ (int) $product_id ] = array(
            'reason'        => $reason, // 'profile_deleted' | 'no_schedule'
            'profile_id'    => (int) $profile_id,
            'profile_title' => $profile_title,
            'title'         => get_the_title( $product_id ),
            'edit_link'     => get_edit_post_link( $product_id, 'raw' ),
            'marked_at'     => current_time( 'mysql' ),
        );
        self::save_store( $store );
    }

    /**
     * خروج یک محصول از لیست رهاشده‌ها (وقتی دوباره در جدول نگاشت یک پروفایل ثبت شود)
     */
    public static function unmark( $product_id ) {
        $store = self::get_store();
        $product_id = (int) $product_id;
        if ( isset( $store[ $product_id ] ) ) {
            unset( $store[ $product_id ] );
            self::save_store( $store );
        }
    }

    /**
     * وقتی یک پروفایل به سطل زباله منتقل می‌شود
     */
    public static function on_profile_trashed( $post_id ) {
        if ( get_post_type( $post_id ) !== 'source_profile' || ! class_exists( 'Product_Mapper' ) ) {
            return;
        }
        self::mark_profile_products( $post_id, 'profile_deleted' );
    }

    /**
     * وقتی یک پروفایل به‌طور دائم حذف می‌شود
     */
    public static function on_profile_deleted( $post_id ) {
        if ( get_post_type( $post_id ) !== 'source_profile' || ! class_exists( 'Product_Mapper' ) ) {
            return;
        }
        self::mark_profile_products( $post_id, 'profile_deleted' );
        // خود جدول نگاشت هم پاک شود؛ محصولات به‌عنوان رهاشده در OPTION_KEY باقی می‌مانند.
        Product_Mapper::delete_all_for_profile( $post_id );
    }

    /**
     * وقتی یک پروفایل از سطل زباله بازیابی می‌شود، محصولاتش دیگر رهاشده نیستند
     * (به شرط این‌که زمان‌بندی هم داشته باشد - در اجرای بعدی scan() دوباره بررسی می‌شود)
     */
    public static function on_profile_restored( $post_id ) {
        if ( get_post_type( $post_id ) !== 'source_profile' || ! class_exists( 'Product_Mapper' ) ) {
            return;
        }
        $store = self::get_store();
        $changed = false;
        foreach ( Product_Mapper::get_product_ids_for_profile( $post_id ) as $pid ) {
            if ( isset( $store[ $pid ] ) && 'profile_deleted' === $store[ $pid ]['reason'] ) {
                unset( $store[ $pid ] );
                $changed = true;
            }
        }
        if ( $changed ) {
            self::save_store( $store );
        }
    }

    private static function mark_profile_products( $profile_id, $reason ) {
        $title = get_the_title( $profile_id );
        foreach ( Product_Mapper::get_product_ids_for_profile( $profile_id ) as $pid ) {
            self::mark( $pid, $reason, $profile_id, $title );
        }
    }

    /**
     * بررسی روزانه: پروفایل‌هایی که زمان‌بندی ندارند (و بنابراین هیچ‌گاه بروزرسانی نمی‌شوند)
     */
    public static function scan() {
        if ( ! class_exists( 'Source_Profile_Manager' ) || ! class_exists( 'Product_Mapper' ) ) {
            return;
        }

        $store = self::get_store();

        // پاک‌سازی رکوردهایی که محصولشان دیگر در سایت وجود ندارد
        foreach ( array_keys( $store ) as $pid ) {
            if ( ! get_post( $pid ) ) {
                unset( $store[ $pid ] );
            }
        }

        $active_profile_ids = Source_Profile_Manager::get_all_profiles();
        foreach ( $active_profile_ids as $profile_id ) {
            $profile = Source_Profile_Manager::get_profile( $profile_id );
            $has_schedule = ! empty( $profile['schedule_days'] ) && ! empty( $profile['schedule_time'] );
            $mapped_ids = Product_Mapper::get_product_ids_for_profile( $profile_id );

            if ( ! $has_schedule ) {
                foreach ( $mapped_ids as $pid ) {
                    if ( ! get_post( $pid ) ) continue;
                    $store[ $pid ] = array(
                        'reason'        => 'no_schedule',
                        'profile_id'    => (int) $profile_id,
                        'profile_title' => get_the_title( $profile_id ),
                        'title'         => get_the_title( $pid ),
                        'edit_link'     => get_edit_post_link( $pid, 'raw' ),
                        'marked_at'     => $store[ $pid ]['marked_at'] ?? current_time( 'mysql' ),
                    );
                }
            } else {
                // اگر این پروفایل الان زمان‌بندی دارد، محصولاتش که قبلاً به همین دلیل رهاشده
                // علامت خورده بودند را از لیست خارج کن
                foreach ( $mapped_ids as $pid ) {
                    if ( isset( $store[ $pid ] ) && 'no_schedule' === $store[ $pid ]['reason'] ) {
                        unset( $store[ $pid ] );
                    }
                }
            }
        }

        self::save_store( $store );
    }
}
