<?php
/**
 * MSS_Duplicate_Finder
 *
 * ماژول تشخیص محصولات تکراری، پیش از ایمپورت محصول «جدید».
 * موتور تطبیق عنوان از افزونهٔ مستقل duplicate-finder (Sitemap Product Matcher) اقتباس شده
 * و برای مقایسهٔ یک محصول تازه‌استخراج‌شده با محصولات از قبل موجود در ووکامرس بازنویسی شده است.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class MSS_Duplicate_Finder {

    const DB_VERSION_OPTION = 'mss_dup_queue_db_version';
    const DB_VERSION        = '1.0';
    const RESULT_JOB_BATCH_SIZE = 10;
    const RESULT_PAGE_SIZE      = 100;
    const RESULT_JOB_TTL        = 7200;
    const RESULT_JOB_TIME_BUDGET = 8;
    private static $catalog_id_cache = array();

    public function __construct() {
        add_action( 'wp_ajax_mss_dup_resolve_link', array( __CLASS__, 'ajax_resolve_link' ) );
        add_action( 'wp_ajax_mss_dup_resolve_new', array( __CLASS__, 'ajax_resolve_new' ) );
        add_action( 'wp_ajax_mss_dup_dismiss', array( __CLASS__, 'ajax_dismiss' ) );
        add_action( 'wp_ajax_mss_dup_bulk', array( __CLASS__, 'ajax_bulk' ) );
        add_action( 'wp_ajax_mss_dup_apply_filters', array( __CLASS__, 'ajax_apply_filters' ) );
        add_action( 'wp_ajax_mss_dup_load_rules', array( __CLASS__, 'ajax_load_rules' ) );
        add_action( 'wp_ajax_mss_dup_preview', array( __CLASS__, 'ajax_preview' ) );
        add_action( 'wp_ajax_mss_dup_save_rules', array( __CLASS__, 'ajax_save_rules' ) );
        add_action( 'wp_ajax_mss_dup_results', array( __CLASS__, 'ajax_results' ) );
        add_action( 'wp_ajax_mss_dup_results_step', array( __CLASS__, 'ajax_results_step' ) );
        add_action( 'wp_ajax_mss_dup_results_page', array( __CLASS__, 'ajax_results_page' ) );
        add_action( 'wp_ajax_mss_dup_search_products', array( __CLASS__, 'ajax_search_products' ) );
        add_action( 'wp_ajax_mss_dup_process_rows', array( __CLASS__, 'ajax_process_rows' ) );
        add_action( 'wp_ajax_mss_dup_reset_profile_queue', array( __CLASS__, 'ajax_reset_profile_queue' ) );
    }

    private static function table() {
        global $wpdb;
        return $wpdb->prefix . 'mss_duplicate_queue';
    }

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
            title TEXT NOT NULL,
            dto_json LONGTEXT NOT NULL,
            candidates_json LONGTEXT NOT NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'pending',
            created_at DATETIME NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY profile_url (profile_id, url_hash),
            KEY status (status)
        ) {$charset_collate};";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta( $sql );

        update_option( self::DB_VERSION_OPTION, self::DB_VERSION, true );
    }

    /* ------------------------------------------------------------------ */
    /*  توکنایز و امتیازدهی (اقتباس از افزونهٔ duplicate-finder)          */
    /* ------------------------------------------------------------------ */

    private static function tokenize_for_similarity( $title, $delimiters, $exclude, $min_length ) {
        $delim_pattern = '[\s' . preg_quote( $delimiters, '/' ) . ']';
        $parts = preg_split( "/$delim_pattern/u", $title, -1, PREG_SPLIT_NO_EMPTY );
        $tokens = array();
        foreach ( $parts as $part ) {
            $part = mb_strtolower( trim( $part ) );
            if ( '' === $part ) continue;
            if ( in_array( $part, $exclude, true ) ) continue;
            if ( mb_strlen( $part ) < $min_length ) continue;
            $tokens[] = $part;
        }
        return $tokens;
    }

    private static function tokenize_raw( $title, $delimiters ) {
        $delim_pattern = '[\s' . preg_quote( $delimiters, '/' ) . ']';
        $parts = preg_split( "/$delim_pattern/u", $title, -1, PREG_SPLIT_NO_EMPTY );
        $tokens = array();
        foreach ( $parts as $part ) {
            $part = trim( $part );
            if ( '' !== $part ) $tokens[] = $part;
        }
        return $tokens;
    }

    /* ------------------------------------------------------------------ */
    /*  اولویت کدهای عددی/حرفی‌عددی + تطبیق جزئی (کد کامل ↔ کد خلاصه)      */
    /* ------------------------------------------------------------------ */

    /**
     * آیا این توکن یک رشتهٔ عددی خالص است؟ (فقط رقم انگلیسی، مثل 45718)
     */
    private static function is_numeric_token( $token ) {
        return (bool) preg_match( '/^[0-9]+$/', $token );
    }

    /**
     * آیا این توکن ترکیبی از رقم و حروف انگلیسی است؟ (حداقل یک رقم + حداقل یک حرف انگلیسی،
     * و کلاً فقط شامل رقم/حرف انگلیسی — مثل v002d یا mtp1b). کلمات فارسی یا کلمات کاملاً حرفی
     * در این دسته قرار نمی‌گیرند.
     */
    private static function is_alphanumeric_token( $token ) {
        return (bool) preg_match( '/^(?=.*[0-9])(?=.*[a-z])[a-z0-9]+$/', $token );
    }

    /**
     * آیا این توکن «کدمانند» است؟ (عددی خالص یا ترکیبی رقم+حرف انگلیسی)
     * فقط همین دو نوع توکن وارد منطق اولویت‌بندی/تطبیق جزئی می‌شوند؛ کلمات معمولی دست‌نخورده می‌مانند.
     */
    private static function is_code_token( $token ) {
        return self::is_numeric_token( $token ) || self::is_alphanumeric_token( $token );
    }

    /**
     * تعداد دنباله‌های پیوستهٔ رقم را با پشتیبانی از ارقام انگلیسی، فارسی و عربی می‌شمارد.
     * رشتهٔ عددی داخل کدهایی مانند A55 نیز یک sequence مستقل محسوب می‌شود.
     */
    private static function count_numeric_sequences( $title ) {
        return preg_match_all( '/[0-9۰-۹٠-٩]+/u', (string) $title, $matches );
    }

    /**
     * تطبیق زیررشته‌ای دو توکن کدمانند — برای شناسایی «کد کامل» در برابر «کد خلاصه‌شده»
     * (مثلاً mtp-v002d-1budf در برابر v002d-1b). هر دو باید حداقل به‌اندازهٔ $min_len حرف
     * داشته باشند تا از تطبیق‌های کاذب با رشته‌های خیلی کوتاه جلوگیری شود.
     */
    private static function tokens_partial_match( $a, $b, $min_len ) {
        if ( $a === $b ) return true;
        if ( mb_strlen( $a ) < $min_len || mb_strlen( $b ) < $min_len ) return false;
        return ( false !== mb_strpos( $a, $b ) ) || ( false !== mb_strpos( $b, $a ) );
    }

    /**
     * آیا عبارت تک‌کلمه‌ای یا چندکلمه‌ای به‌صورت زیردنبالهٔ پیوسته حضور دارد؟
     */
    private static function phrase_present( $raw_tokens, $phrase ) {
        $phrase_words = preg_split( '/\s+/u', trim( (string) $phrase ), -1, PREG_SPLIT_NO_EMPTY );
        $n = count( $phrase_words );
        if ( 0 === $n ) return false;

        $phrase_words = array_map( 'mb_strtolower', $phrase_words );
        $raw_lower    = array_map( 'mb_strtolower', $raw_tokens );
        $count        = count( $raw_lower );

        for ( $i = 0; $i <= $count - $n; $i++ ) {
            $match = true;
            for ( $j = 0; $j < $n; $j++ ) {
                if ( $raw_lower[ $i + $j ] !== $phrase_words[ $j ] ) { $match = false; break; }
            }
            if ( $match ) return true;
        }
        return false;
    }

    private static function has_conflict( $raw1, $raw2, $conflict_groups ) {
        foreach ( $conflict_groups as $group ) {
            $present1 = $present2 = array();
            foreach ( $group as $phrase ) {
                if ( self::phrase_present( $raw1, $phrase ) ) $present1[] = mb_strtolower( $phrase );
                if ( self::phrase_present( $raw2, $phrase ) ) $present2[] = mb_strtolower( $phrase );
            }
            if ( ! empty( $present1 ) && ! empty( $present2 ) && empty( array_intersect( $present1, $present2 ) ) ) {
                return true;
            }
        }
        return false;
    }

    private static function parse_exclude_list( $raw ) {
        $out = array();
        foreach ( explode( ',', (string) $raw ) as $p ) {
            $p = mb_strtolower( trim( $p ) );
            if ( '' !== $p ) $out[] = $p;
        }
        return $out;
    }

    private static function parse_conflict_groups( $raw ) {
        $groups = array();
        foreach ( explode( "\n", (string) $raw ) as $line ) {
            $line = trim( $line );
            if ( '' === $line ) continue;
            $words = array_filter( array_map( 'mb_strtolower', array_map( 'trim', explode( ',', $line ) ) ) );
            if ( count( $words ) > 1 ) $groups[] = array_values( $words );
        }
        return $groups;
    }

    /**
     * جست‌وجوی محصولات کاندید تکراری در ووکامرس برای یک عنوان مشخص.
     *
     * @param string $title
     * @param array  $profile آرایهٔ تنظیمات پروفایل (شامل dup_*)
     * @return array لیست کاندیدها:
     *   [ ['product_id'=>, 'title'=>, 'score'=>, 'shared'=>[], 'priority_match'=>bool,
     *      'matched_tokens'=>[ ['own'=>,'other'=>,'type'=>'exact|partial'], ... ],
     *      'image'=>, 'edit_link'=>] ... ]
     *   مرتب‌شده نزولی: اول کاندیدهای اولویت‌دار (priority_match)، سپس بر اساس امتیاز.
     */
    public static function find_candidates( $title, $profile, $catalog_filters = array() ) {

        $delimiters = $profile['dup_delimiters'] ?? ' -';
        $exclude    = self::parse_exclude_list( $profile['dup_exclude_strings'] ?? '' );
        $min_length = intval( $profile['dup_min_token_length'] ?? 0 );
        $conflict_groups = self::parse_conflict_groups( $profile['dup_conflict_groups'] ?? '' );
        $min_score = max( 1, intval( $profile['dup_min_score'] ?? 1 ) );

        // اولویت رشته‌های عددی/حرفی‌عددی: اگر فعال باشند، وجود یک توکن مشترک از این نوع
        // به‌تنهایی برای کاندید شدن کافی است؛ نیازی به رعایت حداقل امتیاز نیست.
        $numeric_priority      = ! empty( $profile['dup_numeric_priority'] );
        $alphanumeric_priority = ! empty( $profile['dup_alphanumeric_priority'] );

        // تطبیق جزئی کد: کد کامل («mtp-v002d-1budf») در برابر کد خلاصه‌شده («v002d-1b») هم
        // به‌عنوان تطابق در نظر گرفته می‌شود (بر اساس زیررشته بودن، نه فقط برابری کامل).
        // توجه: تطبیق جزئی هرگز بین دو رشتهٔ «کاملاً عددی» رخ نمی‌دهد (مثلاً 200 هرگز معادل 2000
        // در نظر گرفته نمی‌شود)؛ فقط وقتی حداقل یکی از دو طرف ترکیبی عدد+حرف باشد اعمال می‌شود
        // (مثلاً 2482 می‌تواند بخشی از km2482 باشد).
        $partial_code_match = ! empty( $profile['dup_partial_code_match'] );
        $partial_min_length = max( 1, intval( $profile['dup_partial_match_min_length'] ?? 3 ) );
        $equal_numeric_count = ! empty( $profile['dup_equal_numeric_count'] );
        $source_numeric_count = $equal_numeric_count ? self::count_numeric_sequences( $title ) : 0;

        $sim_tokens = self::tokenize_for_similarity( $title, $delimiters, $exclude, $min_length );
        $raw_tokens = self::tokenize_raw( $title, $delimiters );
        if ( empty( $sim_tokens ) ) {
            return array();
        }

        // همهٔ محصولات داخل scope فیلتر بررسی می‌شوند؛ سقف ثابت ۴۰۰تایی حذف شده است.
        $product_ids = self::get_catalog_product_ids( $catalog_filters );
        $rows = array();
        foreach ( $product_ids as $product_id ) {
            $candidate_title = get_post_field( 'post_title', $product_id );
            if ( '' === $candidate_title ) continue;
            if ( $equal_numeric_count && self::count_numeric_sequences( $candidate_title ) !== $source_numeric_count ) continue;
            $rows[] = array( 'ID' => $product_id, 'post_title' => $candidate_title );
        }

        if ( empty( $rows ) ) {
            return array();
        }

        $candidates = array();
        foreach ( $rows as $row ) {
            $cand_sim = self::tokenize_for_similarity( $row['post_title'], $delimiters, $exclude, $min_length );
            if ( empty( $cand_sim ) ) continue;

            // تطابق دقیق (رفتار قبلی، بدون تغییر)
            $shared = array_values( array_intersect( $sim_tokens, $cand_sim ) );

            // اگر نه تطابق دقیقی هست و نه تطبیق جزئی فعال است، ادامه دادن بی‌فایده است
            if ( empty( $shared ) && ! $partial_code_match ) continue;

            $cand_raw = self::tokenize_raw( $row['post_title'], $delimiters );
            // نکته مهم: این چک همیشه، برای همهٔ کاندیدها، اجرا می‌شود — از جمله آن‌هایی که قرار است
            // با اولویت رشتهٔ عددی/ترکیبی («priority») تطبیق داده شوند. یعنی «گروه‌های کلمات متعارض»
            // همیشه در اولویت اول قرار دارند: اگر تعارضی وجود داشته باشد، کاندید همین‌جا کنار گذاشته
            // می‌شود و اصلاً به مرحلهٔ محاسبهٔ priority_match زیر نمی‌رسد — حتی اگر یک کد عددی/ترکیبی هم
            // بین دو عنوان مشترک باشد. عمداً این ترتیب را به‌هم نزنید.
            if ( self::has_conflict( $raw_tokens, $cand_raw, $conflict_groups ) ) continue;

            $matched_tokens = array();
            $score_tokens   = array();
            $is_priority    = false;

            foreach ( $shared as $tok ) {
                $score_tokens[]   = $tok;
                $matched_tokens[] = array( 'own' => $tok, 'other' => $tok, 'type' => 'exact' );
                if ( ( $numeric_priority && self::is_numeric_token( $tok ) ) || ( $alphanumeric_priority && self::is_alphanumeric_token( $tok ) ) ) {
                    $is_priority = true;
                }
            }

            // تطبیق جزئی (زیررشته‌ای) بین توکن‌های کدمانندی که هنوز دقیقاً تطبیق نیافته‌اند
            // (کد کامل ↔ کد خلاصه). کلمات معمولی (غیر کدمانند) وارد این منطق نمی‌شوند، و دو رشتهٔ
            // «کاملاً عددی» هم هرگز با هم تطبیق جزئی داده نمی‌شوند (200 هرگز معادل 2000 نیست).
            if ( $partial_code_match ) {
                foreach ( $sim_tokens as $own_tok ) {
                    if ( in_array( $own_tok, $shared, true ) ) continue;
                    if ( ! self::is_code_token( $own_tok ) ) continue;
                    foreach ( $cand_sim as $other_tok ) {
                        if ( in_array( $other_tok, $shared, true ) ) continue;
                        if ( ! self::is_code_token( $other_tok ) ) continue;
                        // اگر هر دو طرف کاملاً عددی‌اند، تطبیق جزئی مجاز نیست؛ فقط وقتی حداقل یکی از
                        // دو طرف ترکیبی عدد+حرف باشد (مثل km2482 در برابر 2482) اجازه داده می‌شود.
                        if ( self::is_numeric_token( $own_tok ) && self::is_numeric_token( $other_tok ) ) continue;
                        if ( self::tokens_partial_match( $own_tok, $other_tok, $partial_min_length ) ) {
                            $score_tokens[]   = $own_tok;
                            $matched_tokens[] = array( 'own' => $own_tok, 'other' => $other_tok, 'type' => 'partial' );
                            if ( ( $numeric_priority && ( self::is_numeric_token( $own_tok ) || self::is_numeric_token( $other_tok ) ) )
                                || ( $alphanumeric_priority && ( self::is_alphanumeric_token( $own_tok ) || self::is_alphanumeric_token( $other_tok ) ) ) ) {
                                $is_priority = true;
                            }
                            break; // برای این توکنِ خودمان، یک تطبیق جزئی کافی است
                        }
                    }
                }
            }

            if ( empty( $matched_tokens ) ) continue;

            $score = count( array_unique( $score_tokens ) );
            if ( $score < $min_score && ! $is_priority ) continue;

            $product_id = (int) $row['ID'];
            $candidates[] = array(
                'product_id'     => $product_id,
                'title'          => $row['post_title'],
                'score'          => $score,
                'shared'         => $shared,
                'priority_match' => $is_priority,
                'matched_tokens' => $matched_tokens,
                'image'          => get_the_post_thumbnail_url( $product_id, 'thumbnail' ),
                'edit_link'      => get_edit_post_link( $product_id, 'raw' ),
            );
        }

        usort( $candidates, function( $a, $b ) {
            if ( $a['priority_match'] !== $b['priority_match'] ) {
                return $a['priority_match'] ? -1 : 1;
            }
            return $b['score'] - $a['score'];
        } );

        return $candidates;
    }

    /**
     * پاک‌سازی فیلترهای کاتالوگ که از رابط مدیریت می‌آیند.
     */
    public static function sanitize_catalog_filters( $raw ) {
        $raw = is_array( $raw ) ? $raw : array();
        $categories = isset( $raw['categories'] ) ? (array) $raw['categories'] : array();
        $authors    = isset( $raw['authors'] ) ? (array) $raw['authors'] : array();

        return array(
            'categories' => array_values( array_filter( array_unique( array_map( 'absint', $categories ) ) ) ),
            'authors'    => array_values( array_filter( array_unique( array_map( 'absint', $authors ) ) ) ),
            'date_from'  => self::sanitize_date( $raw['date_from'] ?? '' ),
            'date_to'    => self::sanitize_date( $raw['date_to'] ?? '' ),
            'title'      => sanitize_text_field( wp_unslash( $raw['title'] ?? '' ) ),
        );
    }

    private static function sanitize_date( $date ) {
        $date = sanitize_text_field( wp_unslash( (string) $date ) );
        return preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ? $date : '';
    }

    /**
     * دریافت همه شناسه‌های محصول داخل scope؛ عمداً limit ثابت ندارد.
     */
    public static function get_catalog_product_ids( $raw_filters = array(), $limit = 0 ) {
        global $wpdb;
        $filters = self::sanitize_catalog_filters( $raw_filters );
        $cache_key = md5( wp_json_encode( $filters ) . '|' . (int) $limit );
        if ( array_key_exists( $cache_key, self::$catalog_id_cache ) ) {
            return self::$catalog_id_cache[ $cache_key ];
        }
        $joins   = array();
        $where   = array(
            "p.post_type = 'product'",
            "p.post_status IN ('publish','draft','pending','private')",
        );
        $values = array();

        if ( ! empty( $filters['categories'] ) ) {
            $joins[] = "INNER JOIN {$wpdb->term_relationships} tr ON tr.object_id = p.ID";
            $joins[] = "INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id AND tt.taxonomy = 'product_cat'";
            $placeholders = implode( ',', array_fill( 0, count( $filters['categories'] ), '%d' ) );
            $where[] = "tt.term_id IN ({$placeholders})";
            $values = array_merge( $values, $filters['categories'] );
        }
        if ( ! empty( $filters['authors'] ) ) {
            $placeholders = implode( ',', array_fill( 0, count( $filters['authors'] ), '%d' ) );
            $where[] = "p.post_author IN ({$placeholders})";
            $values = array_merge( $values, $filters['authors'] );
        }
        if ( '' !== $filters['date_from'] ) {
            $where[] = 'p.post_date >= %s';
            $values[] = $filters['date_from'] . ' 00:00:00';
        }
        if ( '' !== $filters['date_to'] ) {
            $where[] = 'p.post_date <= %s';
            $values[] = $filters['date_to'] . ' 23:59:59';
        }
        if ( '' !== $filters['title'] ) {
            $where[] = 'p.post_title LIKE %s';
            $values[] = '%' . $wpdb->esc_like( $filters['title'] ) . '%';
        }

        $sql = 'SELECT DISTINCT p.ID FROM ' . $wpdb->posts . ' p ' . implode( ' ', $joins )
            . ' WHERE ' . implode( ' AND ', $where ) . ' ORDER BY p.ID DESC';
        if ( $limit > 0 ) {
            $sql .= ' LIMIT ' . absint( $limit );
        }
        if ( ! empty( $values ) ) {
            $sql = $wpdb->prepare( $sql, $values );
        }
        self::$catalog_id_cache[ $cache_key ] = array_map( 'intval', (array) $wpdb->get_col( $sql ) );
        return self::$catalog_id_cache[ $cache_key ];
    }

    public static function count_catalog_products( $filters = array() ) {
        return count( self::get_catalog_product_ids( $filters ) );
    }

    /**
     * Ruleهای رابط را به شکل مورد انتظار find_candidates تبدیل می‌کند.
     */
    public static function sanitize_rules( $raw ) {
        $raw = is_array( $raw ) ? $raw : array();
        return array(
            'dup_delimiters'               => sanitize_text_field( wp_unslash( $raw['dup_delimiters'] ?? ' -' ) ),
            'dup_exclude_strings'          => sanitize_textarea_field( wp_unslash( $raw['dup_exclude_strings'] ?? '' ) ),
            'dup_min_token_length'         => max( 0, absint( $raw['dup_min_token_length'] ?? 0 ) ),
            'dup_conflict_groups'          => sanitize_textarea_field( wp_unslash( $raw['dup_conflict_groups'] ?? '' ) ),
            'dup_min_score'                => max( 1, absint( $raw['dup_min_score'] ?? 1 ) ),
            'dup_numeric_priority'         => ! empty( $raw['dup_numeric_priority'] ),
            'dup_alphanumeric_priority'    => ! empty( $raw['dup_alphanumeric_priority'] ),
            'dup_equal_numeric_count'      => ! empty( $raw['dup_equal_numeric_count'] ),
            'dup_partial_code_match'       => ! empty( $raw['dup_partial_code_match'] ),
            'dup_partial_match_min_length' => max( 1, absint( $raw['dup_partial_match_min_length'] ?? 3 ) ),
        );
    }

    public static function save_rules( $profile_id, $raw_rules ) {
        if ( get_post_type( $profile_id ) !== Source_Profile_Manager::POST_TYPE ) {
            return new WP_Error( 'bad_profile', 'پروفایل نامعتبر است.' );
        }
        $rules = self::sanitize_rules( $raw_rules );
        foreach ( $rules as $key => $value ) {
            update_post_meta( $profile_id, '_' . $key, is_bool( $value ) ? ( $value ? '1' : '0' ) : $value );
        }
        return $rules;
    }

    /* ------------------------------------------------------------------ */
    /*  صف بررسی داپلیکیت                                                 */
    /* ------------------------------------------------------------------ */

    /**
     * آیا این لینک از قبل در صف بررسی (در انتظار تصمیم) قرار دارد؟
     */
    public static function is_pending( $profile_id, $source_url ) {
        global $wpdb;
        $table = self::table();
        $hash  = md5( trim( $source_url ) );
        $status = $wpdb->get_var( $wpdb->prepare(
            "SELECT status FROM {$table} WHERE profile_id = %d AND url_hash = %s LIMIT 1",
            (int) $profile_id, $hash
        ) );
        return 'pending' === $status;
    }

    /**
     * افزودن یک محصول جدید به صف بررسی داپلیکیت (به‌جای ایمپورت فوری)
     */
    public static function queue_for_review( $profile_id, $source_url, $dto, $candidates = array() ) {
        global $wpdb;
        $table = self::table();
        $hash  = md5( trim( $source_url ) );

        $existing = $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM {$table} WHERE profile_id = %d AND url_hash = %s LIMIT 1",
            (int) $profile_id, $hash
        ) );

        $data = array(
            'profile_id'       => (int) $profile_id,
            'source_url'       => $source_url,
            'url_hash'         => $hash,
            'title'            => $dto['title'] ?? '',
            'dto_json'         => wp_json_encode( $dto ),
            'candidates_json'  => wp_json_encode( $candidates ),
        );

        if ( $existing ) {
            $wpdb->update( $table, $data, array( 'id' => $existing ) );
        } else {
            $data['status'] = 'pending';
            $data['created_at'] = current_time( 'mysql' );
            $wpdb->insert( $table, $data );
        }
        update_option( 'mss_last_duplicate_profile', (int) $profile_id, false );
    }

    public static function get_pending( $profile_id = 0, $limit = 100, $offset = 0 ) {
        global $wpdb;
        self::recover_stale_claims();
        $table = self::table();
        $limit_sql = $limit > 0 ? $wpdb->prepare( ' LIMIT %d OFFSET %d', (int) $limit, (int) $offset ) : '';
        if ( $profile_id ) {
            $sql = $wpdb->prepare(
                "SELECT * FROM {$table} WHERE status = 'pending' AND profile_id = %d ORDER BY created_at DESC, id DESC",
                (int) $profile_id
            ) . $limit_sql;
            $rows = $wpdb->get_results( $sql, ARRAY_A );
        } else {
            $rows = $wpdb->get_results(
                "SELECT * FROM {$table} WHERE status = 'pending' ORDER BY created_at DESC, id DESC" . $limit_sql,
                ARRAY_A
            );
        }
        return $rows ? $rows : array();
    }

    public static function get_pending_profiles() {
        global $wpdb;
        self::recover_stale_claims();
        $table = self::table();
        $rows = $wpdb->get_results(
            "SELECT profile_id, COUNT(*) AS queue_count, MAX(created_at) AS latest FROM {$table} WHERE status = 'pending' GROUP BY profile_id ORDER BY latest DESC",
            ARRAY_A
        );
        $profiles = array();
        foreach ( (array) $rows as $row ) {
            $profile_id = (int) $row['profile_id'];
            if ( get_post_type( $profile_id ) !== Source_Profile_Manager::POST_TYPE ) continue;
            $profiles[] = array(
                'profile_id' => $profile_id,
                'title'      => get_the_title( $profile_id ),
                'count'      => (int) $row['queue_count'],
                'latest'     => $row['latest'],
            );
        }
        return $profiles;
    }

    public static function get_default_pending_profile() {
        $profiles = self::get_pending_profiles();
        if ( empty( $profiles ) ) return 0;
        $last = (int) get_option( 'mss_last_duplicate_profile', 0 );
        foreach ( $profiles as $profile ) {
            if ( $last === $profile['profile_id'] ) return $last;
        }
        return (int) $profiles[0]['profile_id'];
    }

    /**
     * تمام state صف بررسی همان پروفایل را حذف می‌کند. این متد عمداً هیچ
     * محصول، mapping، تنظیمات rule یا blacklist را تغییر نمی‌دهد.
     *
     * @return int|WP_Error تعداد ردیف‌های حذف‌شده یا خطای ایمنی/دیتابیس.
     */
    public static function reset_profile_queue( $profile_id ) {
        global $wpdb;
        $profile_id = absint( $profile_id );
        if ( ! $profile_id || get_post_type( $profile_id ) !== Source_Profile_Manager::POST_TYPE ) {
            return new WP_Error( 'invalid_profile', 'پروفایل انتخاب‌شده معتبر نیست.' );
        }
        if ( get_option( 'sync_lock_' . $profile_id, false ) ) {
            return new WP_Error( 'sync_running', 'همگام‌سازی این پروفایل هنوز فعال است؛ ابتدا آن را متوقف کنید و سپس صف را ریست کنید.' );
        }

        self::recover_stale_claims();
        $processing = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM " . self::table() . " WHERE profile_id = %d AND status = 'processing'",
            $profile_id
        ) );
        if ( $processing > 0 ) {
            return new WP_Error( 'queue_busy', 'یک یا چند ردیف این پروفایل در حال پردازش است؛ چند لحظه بعد دوباره تلاش کنید.' );
        }

        $deleted = $wpdb->delete(
            self::table(),
            array( 'profile_id' => $profile_id ),
            array( '%d' )
        );
        if ( false === $deleted ) {
            return new WP_Error( 'queue_reset_failed', 'حذف صف این پروفایل از دیتابیس ناموفق بود.' );
        }

        delete_option( 'mss_duplicate_redirect_profile_' . $profile_id );
        delete_transient( self::active_result_job_key( $profile_id ) );
        delete_transient( self::workspace_key( $profile_id ) );
        if ( $profile_id === (int) get_option( 'mss_last_duplicate_profile', 0 ) ) {
            delete_option( 'mss_last_duplicate_profile' );
        }
        return (int) $deleted;
    }

    private static function update_candidates( $queue_id, $candidates ) {
        global $wpdb;
        return false !== $wpdb->update(
            self::table(),
            array( 'candidates_json' => wp_json_encode( $candidates ) ),
            array( 'id' => (int) $queue_id ),
            array( '%s' ),
            array( '%d' )
        );
    }

    public static function count_pending() {
        global $wpdb;
        self::recover_stale_claims();
        $table = self::table();
        return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE status = 'pending'" );
    }

    private static function get_row( $queue_id ) {
        global $wpdb;
        $table = self::table();
        return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", (int) $queue_id ), ARRAY_A );
    }

    private static function claim_row( $queue_id ) {
        global $wpdb;
        $updated = $wpdb->query( $wpdb->prepare(
            "UPDATE " . self::table() . " SET status = 'processing', created_at = %s WHERE id = %d AND status = 'pending'",
            current_time( 'mysql' ), (int) $queue_id
        ) );
        return 1 === (int) $updated;
    }

    private static function recover_stale_claims() {
        global $wpdb;
        $cutoff = date( 'Y-m-d H:i:s', current_time( 'timestamp' ) - HOUR_IN_SECONDS );
        $wpdb->query( $wpdb->prepare(
            "UPDATE " . self::table() . " SET status = 'pending' WHERE status = 'processing' AND created_at < %s",
            $cutoff
        ) );
    }

    private static function restore_pending( $queue_id ) {
        global $wpdb;
        $wpdb->update( self::table(), array( 'status' => 'pending' ), array( 'id' => (int) $queue_id ), array( '%s' ), array( '%d' ) );
    }

    /**
     * تصمیم: این محصول همان کاندید انتخاب‌شده است. نگاشت ثبت و بروزرسانی اجرا می‌شود.
     */
    public static function resolve_link( $queue_id, $product_id ) {
        $row = self::get_row( $queue_id );
        if ( ! $row || ! class_exists( 'Product_Mapper' ) || ! class_exists( 'Product_Importer' ) ) {
            return new WP_Error( 'not_found', 'رکورد یافت نشد.' );
        }
        $product_id = (int) $product_id;
        if ( ! $product_id || 'product' !== get_post_type( $product_id ) ) {
            return new WP_Error( 'bad_product', 'محصول انتخاب‌شده معتبر نیست.' );
        }
        if ( ! self::claim_row( $queue_id ) ) {
            return new WP_Error( 'already_processing', 'این ردیف قبلاً پردازش شده یا هم‌اکنون در حال پردازش است.' );
        }
        $dto = json_decode( $row['dto_json'], true );
        if ( ! is_array( $dto ) ) {
            self::restore_pending( $queue_id );
            return new WP_Error( 'bad_dto', 'داده محصول نامعتبر است.' );
        }

        // ثبت نگاشت: از این پس این لینک مبدأ به این محصول موجود متصل است
        Product_Mapper::set_mapping( $row['profile_id'], $row['source_url'], $product_id, $dto['title'] ?? '' );

        // اجرای فوری بروزرسانی محصول موجود با آخرین داده‌های استخراج‌شده
        try {
            $result = Product_Importer::import( $dto, $row['profile_id'], $row['source_url'] );
        } catch ( \Throwable $error ) {
            Product_Mapper::delete_mapping_by_url( $row['profile_id'], $row['source_url'] );
            self::restore_pending( $queue_id );
            return new WP_Error( 'import_exception', $error->getMessage() );
        }
        if ( is_wp_error( $result ) ) {
            // این URL پیش از resolve نگاشت نداشت؛ در شکست import آن را به حالت قابل retry برگردان.
            Product_Mapper::delete_mapping_by_url( $row['profile_id'], $row['source_url'] );
            self::restore_pending( $queue_id );
            return $result;
        }

        self::mark_resolved( $queue_id );

        return $result;
    }

    /**
     * تصمیم: این محصول واقعاً جدید است؛ به‌صورت عادی ایمپورت شود.
     */
    public static function resolve_as_new( $queue_id ) {
        $row = self::get_row( $queue_id );
        if ( ! $row || ! class_exists( 'Product_Importer' ) ) {
            return new WP_Error( 'not_found', 'رکورد یافت نشد.' );
        }
        if ( ! self::claim_row( $queue_id ) ) {
            return new WP_Error( 'already_processing', 'این ردیف قبلاً پردازش شده یا هم‌اکنون در حال پردازش است.' );
        }
        $dto = json_decode( $row['dto_json'], true );
        if ( ! is_array( $dto ) ) {
            self::restore_pending( $queue_id );
            return new WP_Error( 'bad_dto', 'داده محصول نامعتبر است.' );
        }

        try {
            $result = Product_Importer::import( $dto, $row['profile_id'], $row['source_url'] );
        } catch ( \Throwable $error ) {
            self::restore_pending( $queue_id );
            return new WP_Error( 'import_exception', $error->getMessage() );
        }
        if ( is_wp_error( $result ) ) {
            self::restore_pending( $queue_id );
            return $result;
        }

        self::mark_resolved( $queue_id );

        return $result;
    }

    /**
     * حذف از صف بدون هیچ اقدامی (در همگام‌سازی بعدی، در صورت تکرار وضعیت، دوباره بررسی می‌شود)
     */
    public static function dismiss( $queue_id ) {
        global $wpdb;
        if ( ! self::claim_row( $queue_id ) ) {
            return new WP_Error( 'already_processing', 'این ردیف قبلاً پردازش شده یا هم‌اکنون در حال پردازش است.' );
        }
        $deleted = $wpdb->delete( self::table(), array( 'id' => (int) $queue_id ), array( '%d' ) );
        if ( 1 !== (int) $deleted ) {
            self::restore_pending( $queue_id );
            return new WP_Error( 'dismiss_failed', 'حذف ردیف از صف ناموفق بود.' );
        }
        return true;
    }

    /**
     * نادیده‌گرفتن دائمی: URL به blacklist پروفایل افزوده و سپس از صف حذف می‌شود.
     */
    public static function blacklist_and_dismiss( $queue_id ) {
        $row = self::get_row( $queue_id );
        if ( ! $row ) return new WP_Error( 'not_found', 'رکورد یافت نشد.' );
        if ( ! self::claim_row( $queue_id ) ) {
            return new WP_Error( 'already_processing', 'این ردیف قبلاً پردازش شده یا هم‌اکنون در حال پردازش است.' );
        }

        $profile_id = (int) $row['profile_id'];
        $current = (string) get_post_meta( $profile_id, '_blacklist_urls', true );
        $urls = preg_split( '/\r\n|\r|\n/', $current, -1, PREG_SPLIT_NO_EMPTY );
        $urls = array_map( 'trim', (array) $urls );
        if ( ! in_array( trim( $row['source_url'] ), $urls, true ) ) {
            $urls[] = trim( $row['source_url'] );
            $saved = update_post_meta( $profile_id, '_blacklist_urls', implode( "\n", array_filter( $urls ) ) );
            if ( false === $saved ) {
                self::restore_pending( $queue_id );
                return new WP_Error( 'blacklist_failed', 'ذخیره لینک در لیست سیاه ناموفق بود.' );
            }
        }
        self::mark_resolved( $queue_id );
        return true;
    }

    private static function mark_resolved( $queue_id ) {
        global $wpdb;
        $deleted = $wpdb->delete( self::table(), array( 'id' => (int) $queue_id ), array( '%d' ) );
        if ( 1 !== (int) $deleted ) {
            // عملیات محصول موفق شده است؛ fallback مانع نمایش/اجرای دوبارهٔ ردیف می‌شود.
            $wpdb->update( self::table(), array( 'status' => 'resolved' ), array( 'id' => (int) $queue_id ), array( '%s' ), array( '%d' ) );
        }
    }

    /* ------------------------------------------------------------------ */
    /*  AJAX                                                               */
    /* ------------------------------------------------------------------ */

    private static function check_ajax_perm() {
        if ( ! current_user_can( 'manage_options' ) || ! check_ajax_referer( 'sync_dashboard_ajax', '_ajax_nonce', false ) ) {
            wp_send_json_error( 'دسترسی غیرمجاز' );
        }
    }

    private static function request_array( $key ) {
        return isset( $_POST[ $key ] ) && is_array( $_POST[ $key ] ) ? wp_unslash( $_POST[ $key ] ) : array();
    }

    /**
     * فیلترها را ترجیحاً از JSON صریح می‌خواند تا serialization آرایه‌های Select2
     * در مرورگر/وردپرس باعث از دست‌رفتن scope نشود.
     */
    private static function request_catalog_filters( $method = 'post' ) {
        $request = 'get' === $method ? $_GET : $_POST;
        if ( isset( $request['filters_json'] ) && is_string( $request['filters_json'] ) ) {
            $decoded = json_decode( wp_unslash( $request['filters_json'] ), true );
            if ( is_array( $decoded ) ) {
                return self::sanitize_catalog_filters( $decoded );
            }
        }
        $raw = isset( $request['filters'] ) && is_array( $request['filters'] ) ? wp_unslash( $request['filters'] ) : array();
        return self::sanitize_catalog_filters( $raw );
    }

    private static function catalog_scope_transient_key( $token ) {
        return 'mss_dup_scope_' . get_current_user_id() . '_' . md5( (string) $token );
    }

    /**
     * محدودهٔ جست‌وجو را سمت سرور نگه می‌دارد تا Select2 نتواند در اثر
     * درخواست ناقص یا دستکاری‌شده ناخواسته روی کل کاتالوگ جست‌وجو کند.
     */
    private static function create_catalog_scope( $filters ) {
        $token = wp_generate_password( 24, false, false );
        set_transient( self::catalog_scope_transient_key( $token ), $filters, HOUR_IN_SECONDS );
        return $token;
    }

    private static function get_catalog_scope_from_request() {
        $token = isset( $_GET['scope_token'] ) ? sanitize_text_field( wp_unslash( $_GET['scope_token'] ) ) : '';
        if ( '' === $token ) return false;
        $filters = get_transient( self::catalog_scope_transient_key( $token ) );
        return is_array( $filters ) ? self::sanitize_catalog_filters( $filters ) : false;
    }

    private static function get_valid_profile_id() {
        $profile_id = isset( $_POST['profile_id'] ) ? absint( $_POST['profile_id'] ) : 0;
        return $profile_id && get_post_type( $profile_id ) === Source_Profile_Manager::POST_TYPE ? $profile_id : 0;
    }

    private static function profile_rules( $profile_id ) {
        $profile = Source_Profile_Manager::get_profile( $profile_id );
        return self::sanitize_rules( $profile );
    }

    private static function result_job_key( $token ) {
        return 'mss_dup_job_' . get_current_user_id() . '_' . md5( (string) $token );
    }

    private static function active_result_job_key( $profile_id ) {
        return 'mss_dup_active_' . absint( $profile_id );
    }

    private static function workspace_key( $profile_id ) {
        return 'mss_dup_work_' . get_current_user_id() . '_' . absint( $profile_id );
    }

    private static function save_workspace( $profile_id, $workspace ) {
        $workspace['profile_id'] = (int) $profile_id;
        $workspace['user_id'] = (int) get_current_user_id();
        set_transient( self::workspace_key( $profile_id ), $workspace, self::RESULT_JOB_TTL );
    }

    /**
     * state امن و مختص کاربر برای بازسازی تب پس از refresh.
     */
    public static function get_duplicate_workspace( $profile_id ) {
        $workspace = get_transient( self::workspace_key( $profile_id ) );
        if ( ! is_array( $workspace )
            || (int) ( $workspace['profile_id'] ?? 0 ) !== (int) $profile_id
            || (int) ( $workspace['user_id'] ?? 0 ) !== (int) get_current_user_id() ) {
            return array();
        }

        $scope_token = (string) ( $workspace['scope_token'] ?? '' );
        $scope = '' !== $scope_token ? get_transient( self::catalog_scope_transient_key( $scope_token ) ) : false;
        if ( ! is_array( $scope ) ) {
            return array();
        }
        set_transient( self::catalog_scope_transient_key( $scope_token ), $scope, self::RESULT_JOB_TTL );

        $workspace['filters'] = self::sanitize_catalog_filters( $scope );
        $job_token = (string) ( $workspace['job_token'] ?? '' );
        $job = '' !== $job_token ? get_transient( self::result_job_key( $job_token ) ) : false;
        if ( is_array( $job ) && self::is_result_job_active( $job ) ) {
            $workspace['job'] = array(
                'token'     => $job['token'],
                'processed' => (int) $job['cursor'],
                'total'     => (int) $job['total'],
                'complete'  => ! empty( $job['complete'] ),
                'percent'   => $job['total'] > 0 ? min( 100, (int) floor( 100 * $job['cursor'] / $job['total'] ) ) : 100,
            );
        } else {
            unset( $workspace['job_token'], $workspace['job'] );
        }
        self::save_workspace( $profile_id, $workspace );
        return $workspace;
    }

    private static function get_pending_ids( $profile_id ) {
        global $wpdb;
        self::recover_stale_claims();
        return array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare(
            "SELECT id FROM " . self::table() . " WHERE status = 'pending' AND profile_id = %d ORDER BY created_at DESC, id DESC",
            (int) $profile_id
        ) ) );
    }

    /**
     * یک snapshot سبک از شناسه‌های صف می‌سازد. خود محاسبه در درخواست‌های step
     * انجام می‌شود تا طول صف هرگز طول یک HTTP request را تعیین نکند.
     */
    private static function create_result_job( $profile_id, $rules, $filters ) {
        $token = wp_generate_password( 32, false, false );
        $queue_ids = self::get_pending_ids( $profile_id );
        $job = array(
            'token'         => $token,
            'user_id'       => (int) get_current_user_id(),
            'profile_id'    => (int) $profile_id,
            'queue_ids'     => $queue_ids,
            'cursor'        => 0,
            'total'         => count( $queue_ids ),
            'catalog_count' => self::count_catalog_products( $filters ),
            'filters'       => $filters,
            'rules'         => $rules,
            'complete'      => empty( $queue_ids ),
        );
        set_transient( self::result_job_key( $token ), $job, self::RESULT_JOB_TTL );
        set_transient(
            self::active_result_job_key( $profile_id ),
            array( 'token' => $token, 'user_id' => (int) get_current_user_id() ),
            self::RESULT_JOB_TTL
        );
        $workspace = get_transient( self::workspace_key( $profile_id ) );
        if ( ! is_array( $workspace ) || self::sanitize_catalog_filters( $workspace['filters'] ?? array() ) !== $filters ) {
            $workspace = array(
                'filters'     => $filters,
                'scope_token' => self::create_catalog_scope( $filters ),
                'catalog_count' => $job['catalog_count'],
            );
        }
        $workspace['job_token'] = $token;
        $workspace['last_page'] = 1;
        self::save_workspace( $profile_id, $workspace );
        return $job;
    }

    private static function request_result_job_token() {
        return isset( $_POST['job_token'] )
            ? sanitize_text_field( wp_unslash( $_POST['job_token'] ) )
            : '';
    }

    private static function get_result_job( $profile_id ) {
        $token = self::request_result_job_token();
        if ( '' === $token ) {
            return new WP_Error( 'job_expired', 'شناسه پردازش نتایج ارسال نشده است؛ محاسبه را دوباره آغاز کنید.' );
        }
        $job = get_transient( self::result_job_key( $token ) );
        if ( ! is_array( $job ) || ! self::is_result_job_active( $job )
            || (int) ( $job['user_id'] ?? 0 ) !== (int) get_current_user_id()
            || (int) ( $job['profile_id'] ?? 0 ) !== (int) $profile_id ) {
            return new WP_Error( 'job_expired', 'این پردازش منقضی یا با پردازش جدیدتری جایگزین شده است؛ دوباره «نمایش نتایج» را بزنید.' );
        }
        return $job;
    }

    private static function is_result_job_active( $job ) {
        if ( ! is_array( $job ) || empty( $job['token'] ) || empty( $job['profile_id'] ) ) return false;
        $active = get_transient( self::active_result_job_key( $job['profile_id'] ) );
        return is_array( $active )
            && isset( $active['token'], $active['user_id'] )
            && (string) $active['token'] === (string) $job['token']
            && (int) $active['user_id'] === (int) get_current_user_id()
            && (int) $job['user_id'] === (int) get_current_user_id();
    }

    private static function save_result_job( $job ) {
        if ( ! self::is_result_job_active( $job ) ) return false;
        set_transient( self::result_job_key( $job['token'] ), $job, self::RESULT_JOB_TTL );
        set_transient(
            self::active_result_job_key( $job['profile_id'] ),
            array( 'token' => $job['token'], 'user_id' => (int) $job['user_id'] ),
            self::RESULT_JOB_TTL
        );
        $workspace = get_transient( self::workspace_key( $job['profile_id'] ) );
        if ( is_array( $workspace ) && (string) ( $workspace['job_token'] ?? '' ) === (string) $job['token'] ) {
            self::save_workspace( $job['profile_id'], $workspace );
        }
        return true;
    }

    private static function queue_payload( $row, $rules, $filters, $persist = false ) {
        $candidates = self::find_candidates( $row['title'], $rules, $filters );
        if ( $persist ) self::update_candidates( $row['id'], $candidates );
        return array(
            'queue_id'         => (int) $row['id'],
            'profile_id'       => (int) $row['profile_id'],
            'title'            => $row['title'],
            'source_url'       => esc_url_raw( $row['source_url'] ),
            'candidates'       => $candidates,
            'default_decision' => empty( $candidates ) ? 'import_new' : 'link',
        );
    }

    private static function cached_queue_payload( $row ) {
        $candidates = json_decode( $row['candidates_json'], true );
        if ( ! is_array( $candidates ) ) $candidates = array();
        return array(
            'queue_id'         => (int) $row['id'],
            'profile_id'       => (int) $row['profile_id'],
            'title'            => $row['title'],
            'source_url'       => esc_url_raw( $row['source_url'] ),
            'candidates'       => $candidates,
            'default_decision' => empty( $candidates ) ? 'import_new' : 'link',
        );
    }

    public static function ajax_apply_filters() {
        self::check_ajax_perm();
        $filters = self::request_catalog_filters();
        $scope_token = self::create_catalog_scope( $filters );
        $count = self::count_catalog_products( $filters );
        $profile_id = self::get_valid_profile_id();
        if ( $profile_id ) {
            self::save_workspace( $profile_id, array(
                'filters'       => $filters,
                'scope_token'   => $scope_token,
                'catalog_count' => $count,
                'last_page'     => 1,
            ) );
        }
        wp_send_json_success( array(
            'count'       => $count,
            'filters'     => $filters,
            'scope_token' => $scope_token,
        ) );
    }

    public static function ajax_load_rules() {
        self::check_ajax_perm();
        $profile_id = self::get_valid_profile_id();
        if ( ! $profile_id ) wp_send_json_error( 'پروفایل نامعتبر است.' );
        wp_send_json_success( array( 'rules' => self::profile_rules( $profile_id ) ) );
    }

    public static function ajax_reset_profile_queue() {
        self::check_ajax_perm();
        $confirmed = isset( $_POST['confirm_reset'] ) && '1' === sanitize_text_field( wp_unslash( $_POST['confirm_reset'] ) );
        if ( ! $confirmed ) wp_send_json_error( 'تأیید صریح ریست صف ارسال نشده است.' );
        $profile_id = self::get_valid_profile_id();
        if ( ! $profile_id ) wp_send_json_error( 'پروفایل نامعتبر است.' );

        $deleted = self::reset_profile_queue( $profile_id );
        if ( is_wp_error( $deleted ) ) {
            wp_send_json_error( $deleted->get_error_message() );
        }

        $next_profile_id = self::get_default_pending_profile();
        if ( $next_profile_id ) {
            update_option( 'mss_last_duplicate_profile', $next_profile_id, false );
        }
        wp_send_json_success( array(
            'deleted'         => (int) $deleted,
            'remaining_total' => self::count_pending(),
            'next_profile_id' => (int) $next_profile_id,
        ) );
    }

    public static function ajax_preview() {
        self::check_ajax_perm();
        $profile_id = self::get_valid_profile_id();
        if ( ! $profile_id ) wp_send_json_error( 'پروفایل نامعتبر است.' );
        $filters = self::request_catalog_filters();
        $rules   = self::sanitize_rules( self::request_array( 'rules' ) );
        $rows    = self::get_pending( $profile_id, 5, 0 );
        $payload = array();
        foreach ( $rows as $row ) $payload[] = self::queue_payload( $row, $rules, $filters, false );
        wp_send_json_success( array( 'rows' => $payload, 'catalog_count' => self::count_catalog_products( $filters ) ) );
    }

    public static function ajax_save_rules() {
        self::check_ajax_perm();
        $profile_id = self::get_valid_profile_id();
        if ( ! $profile_id ) wp_send_json_error( 'پروفایل نامعتبر است.' );
        $rules = self::save_rules( $profile_id, self::request_array( 'rules' ) );
        if ( is_wp_error( $rules ) ) wp_send_json_error( $rules->get_error_message() );
        $filters = self::request_catalog_filters();
        $rows = self::get_pending( $profile_id, 5, 0 );
        $payload = array();
        foreach ( $rows as $row ) $payload[] = self::queue_payload( $row, $rules, $filters, false );
        wp_send_json_success( array( 'rules' => $rules, 'rows' => $payload, 'message' => 'تنظیمات در پروفایل ذخیره شد.' ) );
    }

    public static function ajax_results() {
        self::check_ajax_perm();
        $profile_id = self::get_valid_profile_id();
        if ( ! $profile_id ) wp_send_json_error( 'پروفایل نامعتبر است.' );
        $filters = self::request_catalog_filters();
        $rules   = self::sanitize_rules( self::request_array( 'rules' ) );
        $job = self::create_result_job( $profile_id, $rules, $filters );
        wp_send_json_success( array(
            'job_token'    => $job['token'],
            'processed'    => 0,
            'total'        => $job['total'],
            'complete'     => $job['complete'],
            'catalog_count'=> $job['catalog_count'],
        ) );
    }

    public static function ajax_results_step() {
        self::check_ajax_perm();
        $profile_id = self::get_valid_profile_id();
        if ( ! $profile_id ) wp_send_json_error( 'پروفایل نامعتبر است.' );
        $job = self::get_result_job( $profile_id );
        if ( is_wp_error( $job ) ) wp_send_json_error( $job->get_error_message() );

        if ( empty( $job['complete'] ) ) {
            $start = max( 0, (int) $job['cursor'] );
            $batch_ids = array_slice( $job['queue_ids'], $start, self::RESULT_JOB_BATCH_SIZE );
            $step_started = microtime( true );
            $processed_in_batch = 0;
            foreach ( $batch_ids as $queue_id ) {
                $row = self::get_row( $queue_id );
                if ( $row && 'pending' === $row['status'] && (int) $row['profile_id'] === $profile_id ) {
                    $candidates = self::find_candidates( $row['title'], $job['rules'], $job['filters'] );
                    if ( ! self::is_result_job_active( $job ) ) {
                        wp_send_json_error( 'این پردازش با پردازش جدیدتری جایگزین شده است؛ دوباره «نمایش نتایج» را بزنید.' );
                    }
                    if ( ! self::update_candidates( $row['id'], $candidates ) ) {
                        wp_send_json_error( 'ذخیره نتایج یکی از محصولات ناموفق بود؛ می‌توانید همین پردازش را دوباره ادامه دهید.' );
                    }
                }
                $processed_in_batch++;
                if ( microtime( true ) - $step_started >= self::RESULT_JOB_TIME_BUDGET ) break;
            }
            $job['cursor'] = min( $job['total'], $start + $processed_in_batch );
            $job['complete'] = $job['cursor'] >= $job['total'];
            if ( ! self::save_result_job( $job ) ) {
                wp_send_json_error( 'این پردازش با پردازش جدیدتری جایگزین شده است؛ دوباره «نمایش نتایج» را بزنید.' );
            }
        }

        wp_send_json_success( array(
            'job_token' => $job['token'],
            'processed' => (int) $job['cursor'],
            'total'     => (int) $job['total'],
            'complete'  => ! empty( $job['complete'] ),
            'percent'   => $job['total'] > 0 ? min( 100, (int) floor( 100 * $job['cursor'] / $job['total'] ) ) : 100,
        ) );
    }

    public static function ajax_results_page() {
        self::check_ajax_perm();
        $profile_id = self::get_valid_profile_id();
        if ( ! $profile_id ) wp_send_json_error( 'پروفایل نامعتبر است.' );
        $job = self::get_result_job( $profile_id );
        if ( is_wp_error( $job ) ) wp_send_json_error( $job->get_error_message() );
        if ( empty( $job['complete'] ) ) wp_send_json_error( 'محاسبه نتایج هنوز کامل نشده است.' );
        self::save_result_job( $job ); // هنگام مرور صفحات، مهلت job و نشانگر فعال تازه بماند.

        $total_pages = max( 1, (int) ceil( $job['total'] / self::RESULT_PAGE_SIZE ) );
        $page = isset( $_POST['page'] ) ? max( 1, absint( $_POST['page'] ) ) : 1;
        $page = min( $page, $total_pages );
        $workspace = get_transient( self::workspace_key( $profile_id ) );
        if ( is_array( $workspace ) && (string) ( $workspace['job_token'] ?? '' ) === (string) $job['token'] ) {
            $workspace['last_page'] = $page;
            self::save_workspace( $profile_id, $workspace );
        }
        $ids = array_slice( $job['queue_ids'], ( $page - 1 ) * self::RESULT_PAGE_SIZE, self::RESULT_PAGE_SIZE );
        $payload = array();
        foreach ( $ids as $queue_id ) {
            $row = self::get_row( $queue_id );
            if ( ! $row || 'pending' !== $row['status'] || (int) $row['profile_id'] !== $profile_id ) continue;
            $payload[] = self::cached_queue_payload( $row );
        }
        wp_send_json_success( array(
            'rows'          => $payload,
            'page'          => $page,
            'page_size'     => self::RESULT_PAGE_SIZE,
            'total'         => (int) $job['total'],
            'total_pages'   => $total_pages,
            'catalog_count' => (int) $job['catalog_count'],
        ) );
    }

    public static function ajax_search_products() {
        self::check_ajax_perm();
        $term = isset( $_GET['term'] ) ? sanitize_text_field( wp_unslash( $_GET['term'] ) ) : '';
        if ( '' === $term ) wp_send_json( array() );
        $filters = self::get_catalog_scope_from_request();
        if ( false === $filters ) wp_send_json( array() );
        $ids = self::get_catalog_product_ids( $filters );
        $results = array();
        foreach ( $ids as $product_id ) {
            $title = get_post_field( 'post_title', $product_id );
            if ( false === mb_stripos( $title, $term ) ) continue;
            $results[] = array( 'id' => $product_id, 'text' => $title );
            if ( count( $results ) >= 20 ) break;
        }
        wp_send_json( $results );
    }

    private static function process_decision( $queue_id, $decision, $product_id = 0 ) {
        switch ( $decision ) {
            case 'link':
                return self::resolve_link( $queue_id, $product_id );
            case 'import_new':
                return self::resolve_as_new( $queue_id );
            case 'dismiss':
                return self::dismiss( $queue_id );
            case 'blacklist':
                return self::blacklist_and_dismiss( $queue_id );
            default:
                return new WP_Error( 'bad_decision', 'تصمیم انتخاب‌شده معتبر نیست.' );
        }
    }

    public static function ajax_process_rows() {
        self::check_ajax_perm();
        $profile_id = self::get_valid_profile_id();
        if ( ! $profile_id ) wp_send_json_error( 'پروفایل نامعتبر است.' );
        $json = isset( $_POST['rows'] ) ? wp_unslash( $_POST['rows'] ) : '[]';
        $items = json_decode( $json, true );
        if ( ! is_array( $items ) || empty( $items ) ) wp_send_json_error( 'هیچ ردیفی برای پردازش ارسال نشده است.' );

        $summary = array( 'linked' => 0, 'imported' => 0, 'dismissed' => 0, 'blacklisted' => 0, 'failed' => 0, 'processed_ids' => array(), 'errors' => array() );
        $seen = array();
        foreach ( $items as $item ) {
            $queue_id  = absint( $item['queue_id'] ?? 0 );
            $decision  = sanitize_key( $item['decision'] ?? '' );
            $product_id = absint( $item['product_id'] ?? 0 );
            if ( ! $queue_id || isset( $seen[ $queue_id ] ) ) continue;
            $seen[ $queue_id ] = true;
            $row = self::get_row( $queue_id );
            if ( ! $row || (int) $row['profile_id'] !== $profile_id ) {
                $summary['failed']++;
                $summary['errors'][] = "ردیف {$queue_id} متعلق به پروفایل فعال نیست.";
                continue;
            }
            $result = self::process_decision( $queue_id, $decision, $product_id );
            if ( is_wp_error( $result ) ) {
                $summary['failed']++;
                $summary['errors'][] = $row['title'] . ': ' . $result->get_error_message();
                continue;
            }
            $summary['processed_ids'][] = $queue_id;
            if ( 'link' === $decision ) $summary['linked']++;
            elseif ( 'import_new' === $decision ) $summary['imported']++;
            elseif ( 'dismiss' === $decision ) $summary['dismissed']++;
            elseif ( 'blacklist' === $decision ) $summary['blacklisted']++;
        }
        wp_send_json_success( $summary );
    }

    public static function ajax_resolve_link() {
        self::check_ajax_perm();
        $queue_id   = isset( $_POST['queue_id'] ) ? absint( $_POST['queue_id'] ) : 0;
        $product_id = isset( $_POST['product_id'] ) ? absint( $_POST['product_id'] ) : 0;
        if ( ! $queue_id || ! $product_id ) {
            wp_send_json_error( 'پارامتر نامعتبر' );
        }
        $result = self::resolve_link( $queue_id, $product_id );
        if ( is_wp_error( $result ) ) {
            wp_send_json_error( $result->get_error_message() );
        }
        wp_send_json_success( $result );
    }

    public static function ajax_resolve_new() {
        self::check_ajax_perm();
        $queue_id = isset( $_POST['queue_id'] ) ? absint( $_POST['queue_id'] ) : 0;
        if ( ! $queue_id ) {
            wp_send_json_error( 'پارامتر نامعتبر' );
        }
        $result = self::resolve_as_new( $queue_id );
        if ( is_wp_error( $result ) ) {
            wp_send_json_error( $result->get_error_message() );
        }
        wp_send_json_success( $result );
    }

    public static function ajax_dismiss() {
        self::check_ajax_perm();
        $queue_id = isset( $_POST['queue_id'] ) ? absint( $_POST['queue_id'] ) : 0;
        if ( ! $queue_id ) {
            wp_send_json_error( 'پارامتر نامعتبر' );
        }
        $result = self::dismiss( $queue_id );
        if ( is_wp_error( $result ) ) {
            wp_send_json_error( $result->get_error_message() );
        }
        wp_send_json_success();
    }

    public static function ajax_bulk() {
        self::check_ajax_perm();
        $ids    = isset( $_POST['ids'] ) && is_array( $_POST['ids'] ) ? array_map( 'absint', $_POST['ids'] ) : array();
        $action = isset( $_POST['bulk_action'] ) ? sanitize_text_field( wp_unslash( $_POST['bulk_action'] ) ) : '';
        // برای اقدام "لینک به بهترین کاندید"، از بالاترین امتیاز هر ردیف استفاده می‌شود.
        $done = 0;
        foreach ( $ids as $queue_id ) {
            if ( 'import_new' === $action ) {
                $r = self::resolve_as_new( $queue_id );
                if ( ! is_wp_error( $r ) ) $done++;
            } elseif ( 'link_best' === $action ) {
                $row = self::get_row( $queue_id );
                if ( $row ) {
                    $candidates = json_decode( $row['candidates_json'], true );
                    if ( ! empty( $candidates[0]['product_id'] ) ) {
                        $r = self::resolve_link( $queue_id, $candidates[0]['product_id'] );
                        if ( ! is_wp_error( $r ) ) $done++;
                    }
                }
            } elseif ( 'dismiss' === $action ) {
                $r = self::dismiss( $queue_id );
                if ( ! is_wp_error( $r ) ) $done++;
            }
        }
        wp_send_json_success( array( 'processed' => $done ) );
    }
}
