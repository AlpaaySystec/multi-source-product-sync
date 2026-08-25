<?php
/**
 * Product_Mapper
 *
 * جدول اختصاصی نگاشت «لینک محصول در سایت مبدأ» ↔ «شناسه محصول در سایت من»، به ازای هر پروفایل.
 * این کلاس جایگزین وابستگی قبلی به SKU / متای _source_url برای تشخیص محصول موجود در حین
 * بروزرسانی شده است.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Product_Mapper {

    const DB_VERSION_OPTION = 'mss_product_map_db_version';
    const DB_VERSION        = '1.1';

    /**
     * کش داخل‌درخواستی: profile_id => [ url_hash => product_id ]
     */
    private static $cache = array();

    private static function table() {
        global $wpdb;
        return $wpdb->prefix . 'mss_product_map';
    }

    /**
     * ایجاد/بروزرسانی جدول (در فعال‌سازی افزونه و هوک init فراخوانی می‌شود)
     */
    public static function maybe_create_table() {
        if ( get_option( self::DB_VERSION_OPTION ) === self::DB_VERSION ) {
            return;
        }

        global $wpdb;
        $table_name      = self::table();
        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE {$table_name} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            profile_id BIGINT UNSIGNED NOT NULL,
            source_url TEXT NOT NULL,
            url_hash CHAR(32) NOT NULL,
            product_id BIGINT UNSIGNED NOT NULL,
            source_title TEXT NOT NULL,
            created_at DATETIME NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY profile_url (profile_id, url_hash),
            KEY product_id (product_id),
            KEY profile_id (profile_id)
        ) {$charset_collate};";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta( $sql );

        update_option( self::DB_VERSION_OPTION, self::DB_VERSION, true );
    }

    private static function hash_url( $url ) {
        return md5( trim( $url ) );
    }

    /**
     * دریافت شناسه محصول متناظر با یک لینک مبدأ (در صورت وجود و معتبر بودن)
     *
     * @return int|null
     */
    public static function get_product_id( $profile_id, $source_url ) {
        $profile_id = (int) $profile_id;
        $hash       = self::hash_url( $source_url );

        if ( isset( self::$cache[ $profile_id ] ) && array_key_exists( $hash, self::$cache[ $profile_id ] ) ) {
            return self::$cache[ $profile_id ][ $hash ];
        }

        global $wpdb;
        $table = self::table();
        $product_id = $wpdb->get_var( $wpdb->prepare(
            "SELECT product_id FROM {$table} WHERE profile_id = %d AND url_hash = %s LIMIT 1",
            $profile_id, $hash
        ) );

        $product_id = $product_id ? (int) $product_id : null;

        // اگر محصول دیگر در سایت وجود ندارد، نگاشت را نامعتبر در نظر بگیر (اما پاک‌سازی واقعی در cleanup_stale انجام می‌شود)
        if ( $product_id && ! get_post( $product_id ) ) {
            $product_id = null;
        }

        if ( ! isset( self::$cache[ $profile_id ] ) ) {
            self::$cache[ $profile_id ] = array();
        }
        self::$cache[ $profile_id ][ $hash ] = $product_id;

        return $product_id;
    }

    /**
     * ثبت/بروزرسانی نگاشت
     *
     * @param int         $profile_id
     * @param string      $source_url
     * @param int         $product_id
     * @param string|null $source_title عنوان محصول در سایت مبدأ (اختیاری). اگر null باشد،
     *                                  مقدار قبلی (در صورت وجود ردیف) دست‌نخورده باقی می‌ماند.
     */
    public static function set_mapping( $profile_id, $source_url, $product_id, $source_title = null ) {
        global $wpdb;
        $table = self::table();
        $profile_id = (int) $profile_id;
        $product_id = (int) $product_id;
        $hash = self::hash_url( $source_url );

        $existing = $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM {$table} WHERE profile_id = %d AND url_hash = %s LIMIT 1",
            $profile_id, $hash
        ) );

        if ( $existing ) {
            $update_data   = array( 'product_id' => $product_id, 'source_url' => $source_url );
            $update_format = array( '%d', '%s' );
            if ( null !== $source_title ) {
                $update_data['source_title'] = (string) $source_title;
                $update_format[]              = '%s';
            }
            $wpdb->update(
                $table,
                $update_data,
                array( 'id' => $existing ),
                $update_format,
                array( '%d' )
            );
        } else {
            $wpdb->insert(
                $table,
                array(
                    'profile_id'    => $profile_id,
                    'source_url'    => $source_url,
                    'url_hash'      => $hash,
                    'product_id'    => $product_id,
                    'source_title'  => (string) ( $source_title ?? '' ),
                    'created_at'    => current_time( 'mysql' ),
                ),
                array( '%d', '%s', '%s', '%d', '%s', '%s' )
            );
        }

        if ( ! isset( self::$cache[ $profile_id ] ) ) {
            self::$cache[ $profile_id ] = array();
        }
        self::$cache[ $profile_id ][ $hash ] = $product_id;

        // این محصول دیگر «رهاشده» نیست (در صورتی که قبلاً به این عنوان ثبت شده باشد)
        if ( class_exists( 'MSS_Abandoned_Products' ) ) {
            MSS_Abandoned_Products::unmark( $product_id );
        }
    }

    /**
     * حذف یک نگاشت با لینک مبدأ
     */
    public static function delete_mapping_by_url( $profile_id, $source_url ) {
        global $wpdb;
        $wpdb->delete( self::table(), array(
            'profile_id' => (int) $profile_id,
            'url_hash'   => self::hash_url( $source_url ),
        ), array( '%d', '%s' ) );

        $profile_id = (int) $profile_id;
        $hash = self::hash_url( $source_url );
        if ( isset( self::$cache[ $profile_id ][ $hash ] ) ) {
            unset( self::$cache[ $profile_id ][ $hash ] );
        }
    }

    /**
     * حذف تمام نگاشت‌های یک محصول (در تمام پروفایل‌ها) – هنگام حذف دائمی محصول
     */
    public static function delete_mapping_by_product( $product_id ) {
        global $wpdb;
        $wpdb->delete( self::table(), array( 'product_id' => (int) $product_id ), array( '%d' ) );
        self::$cache = array();
    }

    /**
     * حذف تمام نگاشت‌های یک پروفایل (هنگام حذف دائمی پروفایل)
     */
    public static function delete_all_for_profile( $profile_id ) {
        global $wpdb;
        $wpdb->delete( self::table(), array( 'profile_id' => (int) $profile_id ), array( '%d' ) );
        unset( self::$cache[ (int) $profile_id ] );
    }

    /**
     * دریافت لینک مبدأ متناظر با یک محصول در یک پروفایل خاص
     */
    public static function get_source_url( $profile_id, $product_id ) {
        global $wpdb;
        $table = self::table();
        return $wpdb->get_var( $wpdb->prepare(
            "SELECT source_url FROM {$table} WHERE profile_id = %d AND product_id = %d LIMIT 1",
            (int) $profile_id, (int) $product_id
        ) );
    }

    /**
     * دریافت تمام نگاشت‌های یک پروفایل به‌صورت [source_url => product_id]
     */
    public static function get_all_for_profile( $profile_id ) {
        global $wpdb;
        $table = self::table();
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT source_url, product_id FROM {$table} WHERE profile_id = %d",
            (int) $profile_id
        ), ARRAY_A );

        $map = array();
        foreach ( (array) $rows as $row ) {
            $map[ $row['source_url'] ] = (int) $row['product_id'];
        }
        return $map;
    }

    /**
     * دریافت تمام شناسه‌های محصول یک پروفایل
     */
    public static function get_product_ids_for_profile( $profile_id ) {
        global $wpdb;
        $table = self::table();
        $ids = $wpdb->get_col( $wpdb->prepare(
            "SELECT product_id FROM {$table} WHERE profile_id = %d",
            (int) $profile_id
        ) );
        return array_map( 'intval', $ids );
    }

    /**
     * پاک‌سازی نگاشت‌هایی که محصولشان دیگر در سایت وجود ندارد.
     * طبق درخواست: آیتم‌ها از جدول نگاشت فقط زمانی پاک می‌شوند که محصول موردنظر واقعاً پیدا نشود.
     *
     * @param int|null $profile_id در صورت null، تمام پروفایل‌ها بررسی می‌شوند.
     * @return int تعداد ردیف‌های حذف‌شده
     */
    public static function cleanup_stale( $profile_id = null ) {
        global $wpdb;
        $table = self::table();

        if ( null !== $profile_id ) {
            $rows = $wpdb->get_results( $wpdb->prepare(
                "SELECT id, product_id FROM {$table} WHERE profile_id = %d",
                (int) $profile_id
            ), ARRAY_A );
        } else {
            $rows = $wpdb->get_results( "SELECT id, product_id FROM {$table}", ARRAY_A );
        }

        $deleted = 0;
        foreach ( (array) $rows as $row ) {
            if ( ! get_post( $row['product_id'] ) ) {
                $wpdb->delete( $table, array( 'id' => $row['id'] ), array( '%d' ) );
                $deleted++;
            }
        }

        if ( $deleted > 0 ) {
            self::$cache = array();
        }

        return $deleted;
    }

    /* ------------------------------------------------------------------ */
    /*  متدهای پشتیبان تب «جدول نگاشت» در داشبورد                          */
    /* ------------------------------------------------------------------ */

    /**
     * دریافت یک ردیف نگاشت بر اساس id
     */
    public static function get_row( $id ) {
        global $wpdb;
        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM " . self::table() . " WHERE id = %d", (int) $id
        ), ARRAY_A );
        return $row ? $row : null;
    }

    /**
     * تعداد کل ردیف‌های یک پروفایل (با پشتیبانی از جست‌وجو)
     */
    public static function count_for_profile( $profile_id, $search = '' ) {
        global $wpdb;
        $table      = self::table();
        $profile_id = (int) $profile_id;
        $search     = trim( (string) $search );

        if ( '' === $search ) {
            return (int) $wpdb->get_var( $wpdb->prepare(
                "SELECT COUNT(*) FROM {$table} WHERE profile_id = %d", $profile_id
            ) );
        }

        $like    = '%' . $wpdb->esc_like( $search ) . '%';
        $numeric = is_numeric( $search ) ? (int) $search : 0;
        return (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$table} m LEFT JOIN {$wpdb->posts} p ON p.ID = m.product_id
             WHERE m.profile_id = %d AND ( p.post_title LIKE %s OR m.source_title LIKE %s OR m.source_url LIKE %s OR m.product_id = %d )",
            $profile_id, $like, $like, $like, $numeric
        ) );
    }

    /**
     * دریافت یک صفحه از ردیف‌های نگاشت یک پروفایل، همراه با عنوان و وضعیت محصول در سایت من
     * (با LEFT JOIN به wp_posts) و پشتیبانی از جست‌وجو در عنوان محصول من/عنوان مبدأ/لینک/شناسه.
     */
    public static function get_page_for_profile( $profile_id, $page, $per_page, $search = '' ) {
        global $wpdb;
        $table      = self::table();
        $profile_id = (int) $profile_id;
        $page       = max( 1, (int) $page );
        $per_page   = max( 1, min( 200, (int) $per_page ) );
        $offset     = ( $page - 1 ) * $per_page;
        $search     = trim( (string) $search );

        $select = "SELECT m.id, m.product_id, m.source_title, m.source_url,
                          p.post_title AS local_title, p.post_status AS local_status
                   FROM {$table} m LEFT JOIN {$wpdb->posts} p ON p.ID = m.product_id";

        if ( '' === $search ) {
            $rows = $wpdb->get_results( $wpdb->prepare(
                "{$select} WHERE m.profile_id = %d ORDER BY m.id DESC LIMIT %d OFFSET %d",
                $profile_id, $per_page, $offset
            ), ARRAY_A );
        } else {
            $like    = '%' . $wpdb->esc_like( $search ) . '%';
            $numeric = is_numeric( $search ) ? (int) $search : 0;
            $rows = $wpdb->get_results( $wpdb->prepare(
                "{$select} WHERE m.profile_id = %d AND ( p.post_title LIKE %s OR m.source_title LIKE %s OR m.source_url LIKE %s OR m.product_id = %d )
                 ORDER BY m.id DESC LIMIT %d OFFSET %d",
                $profile_id, $like, $like, $like, $numeric, $per_page, $offset
            ), ARRAY_A );
        }

        return $rows ? $rows : array();
    }

    /**
     * بروزرسانی شناسه محصول یک ردیف نگاشت (ستون دوم/سوم جدول نگاشت در رابط کاربری).
     * محصول مقصد باید واقعاً در سایت وجود داشته باشد.
     *
     * @return array|WP_Error آرایه شامل product_id, local_title, local_status یا خطا
     */
    public static function admin_update_product( $id, $profile_id, $new_product_id ) {
        global $wpdb;
        $id             = (int) $id;
        $profile_id     = (int) $profile_id;
        $new_product_id = (int) $new_product_id;

        if ( ! $new_product_id || 'product' !== get_post_type( $new_product_id ) ) {
            return new WP_Error( 'invalid_product', 'محصولی با این شناسه در سایت شما پیدا نشد.' );
        }

        $row = self::get_row( $id );
        if ( ! $row || (int) $row['profile_id'] !== $profile_id ) {
            return new WP_Error( 'not_found', 'ردیف نگاشت یافت نشد.' );
        }

        $updated = $wpdb->update(
            self::table(),
            array( 'product_id' => $new_product_id ),
            array( 'id' => $id, 'profile_id' => $profile_id ),
            array( '%d' ),
            array( '%d', '%d' )
        );

        if ( false === $updated ) {
            return new WP_Error( 'db_error', 'بروزرسانی نگاشت در دیتابیس ناموفق بود.' );
        }

        self::$cache = array();

        if ( class_exists( 'MSS_Abandoned_Products' ) ) {
            MSS_Abandoned_Products::unmark( $new_product_id );
        }

        $product = get_post( $new_product_id );
        return array(
            'product_id'   => $new_product_id,
            'local_title'  => $product ? get_the_title( $new_product_id ) : '',
            'local_status' => $product ? $product->post_status : '',
        );
    }

    /**
     * بروزرسانی لینک مبدأ و عنوان مبدأ یک ردیف نگاشت (پس از استخراج زندهٔ عنوان از لینک جدید،
     * یا برای بازگردانی/undo با مقادیر اولیهٔ از پیش شناخته‌شده).
     *
     * @return true|WP_Error
     */
    public static function admin_update_url( $id, $profile_id, $new_url, $new_title ) {
        global $wpdb;
        $id         = (int) $id;
        $profile_id = (int) $profile_id;
        $new_url    = trim( (string) $new_url );

        if ( '' === $new_url || ! preg_match( '#^https?://#i', $new_url ) ) {
            return new WP_Error( 'invalid_url', 'لینک وارد شده معتبر نیست.' );
        }

        $row = self::get_row( $id );
        if ( ! $row || (int) $row['profile_id'] !== $profile_id ) {
            return new WP_Error( 'not_found', 'ردیف نگاشت یافت نشد.' );
        }

        $hash  = self::hash_url( $new_url );
        $table = self::table();

        $conflict = $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM {$table} WHERE profile_id = %d AND url_hash = %s AND id != %d LIMIT 1",
            $profile_id, $hash, $id
        ) );
        if ( $conflict ) {
            return new WP_Error( 'duplicate_url', 'این لینک قبلاً برای ردیف دیگری در همین پروفایل ثبت شده است.' );
        }

        $updated = $wpdb->update(
            $table,
            array(
                'source_url'   => $new_url,
                'url_hash'     => $hash,
                'source_title' => (string) $new_title,
            ),
            array( 'id' => $id, 'profile_id' => $profile_id ),
            array( '%s', '%s', '%s' ),
            array( '%d', '%d' )
        );

        if ( false === $updated && $wpdb->last_error ) {
            return new WP_Error( 'db_error', 'بروزرسانی لینک در دیتابیس ناموفق بود.' );
        }

        self::$cache = array();

        return true;
    }

    /**
     * حذف یک ردیف نگاشت با شناسه (id) — برای حذف نگاشت‌های اشتباه از رابط کاربری.
     */
    public static function admin_delete_row( $id, $profile_id ) {
        global $wpdb;
        $deleted = $wpdb->delete(
            self::table(),
            array( 'id' => (int) $id, 'profile_id' => (int) $profile_id ),
            array( '%d', '%d' )
        );
        self::$cache = array();
        return (bool) $deleted;
    }
}
