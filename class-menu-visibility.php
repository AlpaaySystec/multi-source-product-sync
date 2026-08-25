<?php
/**
 * کنترل نمایش منوی افزونه بر اساس نام کاربری
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class MSS_Menu_Visibility {

    private static $option_mode  = 'mss_menu_visibility_mode';
    private static $option_users = 'mss_menu_visibility_users';

    /**
     * راه‌اندازی هوک‌ها
     */
    public static function init() {
        add_action( 'admin_menu', array( __CLASS__, 'apply_menu_visibility' ), 9999 );
        add_action( 'wp_ajax_mss_save_visibility', array( __CLASS__, 'ajax_save' ) );
    }

    /**
     * دریافت تنظیمات فعلی
     */
    public static function get_options() {
        return array(
            'mode'  => get_option( self::$option_mode, 'blacklist' ),
            'users' => get_option( self::$option_users, '' ),
        );
    }

    /**
     * پردازش AJAX ذخیره تنظیمات
     */
    public static function ajax_save() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'دسترسی غیرمجاز' );
        }

        $mode  = isset( $_POST['mode'] ) ? sanitize_text_field( wp_unslash( $_POST['mode'] ) ) : 'blacklist';
        $users = isset( $_POST['users'] ) ? sanitize_text_field( wp_unslash( $_POST['users'] ) ) : '';

        // اعتبارسنجی حالت
        if ( ! in_array( $mode, array( 'blacklist', 'whitelist' ) ) ) {
            $mode = 'blacklist';
        }

        // پاک‌سازی لیست کاربران
        $users_arr = array_map( 'trim', explode( ',', $users ) );
        $users_arr = array_filter( $users_arr, function( $u ) {
            return ! empty( $u );
        } );
        $clean_users = implode( ', ', $users_arr );

        update_option( self::$option_mode, $mode, true );
        update_option( self::$option_users, $clean_users, true );

        wp_send_json_success( 'تنظیمات ذخیره شد.' );
    }

    /**
     * اعمال محدودیت نمایش منو
     */
    public static function apply_menu_visibility() {
        $opts  = self::get_options();
        $mode  = $opts['mode'];
        $users = array_map( 'trim', explode( ',', $opts['users'] ) );
        $users = array_filter( $users );

        if ( empty( $users ) ) {
            // اگر لیست خالی باشد، whitelist یعنی همه پنهان
            if ( 'whitelist' === $mode ) {
                self::remove_all_menus();
            }
            return;
        }

        $current_user = wp_get_current_user()->user_login;
        $is_listed    = in_array( $current_user, $users, true );

        $should_hide = ( 'blacklist' === $mode && $is_listed ) || ( 'whitelist' === $mode && ! $is_listed );

        if ( $should_hide ) {
            self::remove_all_menus();
        }
    }

    /**
     * حذف تمام منوهای افزونه
     */
    private static function remove_all_menus() {
        $parent_slug = 'edit.php?post_type=source_profile';

        // حذف آیتم اصلی منو (لیست پروفایل‌ها)
        remove_menu_page( $parent_slug );

        // حذف زیرمنوهایی که ممکن است باقی بمانند
        // این لیست باید هم‌گام با تمام زیرمنوهای «تست استخراج» ثبت‌شده توسط هر
        // اکستراکتور (ثابت MENU_SLUG هر کلاس) به‌روز نگه داشته شود.
        $submenus = array(
            'post-new.php?post_type=source_profile',
            'edit.php?post_type=source_profile',
            'sync-dashboard',
			'mss-product-extractor',
            'nilehyper-extractor',
            'mirzaeiwatch-product-extractor',
            'timestorr-extractor',
            'zippogift-extractor',
            'zudlux-extractor',
            'shikomod-extractor',
            'arvindshop-extractor',
            'bermova-product-extractor',
            'digify-product-extractor',
            'mixin-extractor',
            'portal-product-extractor',
            'sazito-extractor',
            'vgr-iran-product-extractor',
        );

        foreach ( $submenus as $submenu ) {
            remove_submenu_page( $parent_slug, $submenu );
        }
    }
}
