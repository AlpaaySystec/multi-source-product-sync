<?php
/**
 * Plugin Name: Multi-Source Product Sync
 * Description: همگام‌سازی خودکار محصولات از چندین فروشگاه اینترنتی به ووکامرس
 * Version: 4.2.8
 * Author: Alpaay Salaghi
 * Text Domain: multi-source-sync
 *
 * تغییرات ۳.۵.۱: رفع ناسازگاری‌های ناشی از بازنویسی‌های متعدد بین بخش‌های مختلف افزونه
 * (فایل‌های اکستراکتور تکراری، سیستم قوانین قیمت‌گذاری، مقدار «موجودی نامشخص»، و چند مورد
 * دیگر).
 * تغییرات ۳.۵.۲: رفع باگ تکثیر ردیف‌های جدول «قوانین قیمتی» در هر بار ذخیره‌ی پروفایل
 * (نام‌گذاری نادرست فیلدهای فرم که باعث می‌شد هر ردیف به ۵ ردیف تبدیل شود).
 * تغییرات ۳.۵.۳: کند بودن دراپ‌داون «نویسندهٔ محصول»، اعمال‌نشدن «کلاس ارسال» روی محصول، و
 * از کار افتادن کادر جستجوی محصول در تب «مدیریت داپلیکیت‌ها» (به‌خاطر استفاده از تنظیمات
 * Select2 روی کتابخانه‌ی Chosen).
 * تغییرات ۳.۶.۰: اصلاح فرمول ضریب دوم در قوانین قیمتی؛ قابلیت جدید «تطبیق با محصولات نسخهٔ
 * قبلی بر اساس SKU» برای پروفایل‌هایی که قبلاً با نسخهٔ قدیمی افزونه محصول اضافه کرده‌اند.
 * تغییرات ۴.۰.۰: تبدیل تشخیص داپلیکیت به صف تأیید همهٔ محصولات جدید، انتقال فیلترها و قواعد
 * به داشبورد، تصمیم‌های سطری/گروهی AJAX و پشتیبانی عبارت‌های متعارض چندکلمه‌ای.
 * تغییرات ۴.۰.۱: نمایش کامل تنظیمات مدیریت داپلیکیت حتی هنگام خالی‌بودن صف و اصلاح بستهٔ اصلی انتشار.
 * تغییرات ۴.۱.۰: محدودسازی قطعی جست‌وجوی دستی به فیلترهای اعمال‌شده و پشتیبانی از چند
 * sitemap و کشف خودکار sitemapهای محصول از sitemap index.
 * تغییرات ۴.۲.۱: اصلاح قیمت‌گذاری مستقل هر وارییشن، رعایت فیلدهای انتخاب‌شده برای قیمت و
 * موجودی childها، تبدیل امن ساده/متغیر، ویژگی‌های والد متغیر، fallback رنگ TimeStorr و
 * انتقال ArvindShop به extractor امن و فعلی Mixin. رفتار سادهٔ ZippoGift بدون تغییر است.
 * تغییرات ۴.۲.۲: افزودن ریست کامل صف بررسی فقط برای پروفایل انتخاب‌شده، با تأیید صریح،
 * محافظت nonce/capability، جلوگیری از حذف هنگام sync/processing و بروزرسانی AJAX تب.
 * تغییرات ۴.۲.۳: اجبار سراسری محصول و وارییشن با قیمت مؤثر صفر به وضعیت ناموجود.
 * تغییرات ۴.۲.۴: حذف timeout محاسبهٔ انبوه داپلیکیت با job قطعه‌ای، progress قابل ادامه و
 * نمایش صفحه‌بندی‌شدهٔ نتایج cacheشده.
 * تغییرات ۴.۲.۵: اصلاح Select2 و چیدمان قواعد، بازیابی تب/فیلتر/job پس از refresh، صفحات
 * ۱۰۰تایی، هایلایت واژه‌های مشترک و rule برابری تعداد رشته‌های عددی دو عنوان.
 * تغییرات ۴.۲.۶: افزودن تب جدید «جدول نگاشت» به داشبورد؛ نمایش/ویرایش/حذف ایجکسی نگاشت
 * لینک‌مبدأ↔محصول هر پروفایل (شناسه، عنوان با دراپ‌داون chosen ایجکسی، عنوان مبدأ فقط‌نمایشی،
 * لینک مبدأ با استخراج زندهٔ عنوان)، هایلایت سه‌رنگ کلمات مشترک بین عنوان من/مبدأ (زرد برای
 * فارسی-عربی، سبز چمنی برای لاتین، فیروزه‌ای برای عدد/ترکیبی)، پس‌زمینهٔ صورتی برای فیلدهای
 * ویرایش‌شده و پاک‌کردن کامل فیلد به‌عنوان undo. ستون source_title به جدول نگاشت اضافه شد.
 * تغییرات ۴.۲.۷: افزودن extractor جدید برای raoofictc.com (اکسترکتور راوفی).
 * قیمت/موجودی/گالری هر واریانت مستقیماً از data-product_variations استاندارد
 * ووکامرس (که در همان اولین HTTP response موجود است) خوانده می‌شود؛ برای
 * اطمینان در برابر کش‌های ناقص، در صورت خالی بودن واریانت‌های یک محصول
 * «متغیر»، یک تلاش دوم بدون کش انجام می‌شود. کشف خودکار آدرس محصولات از
 * روی sitemap_index.xml نیز اضافه شد.
 * تغییرات ۴.۲.۸: رفع دو باگ در extractor راوفی — ۱) توضیحات کوتاه دیگر با
 * node_text به یک خط پلین تبدیل نمی‌شود، بلکه ساختار <ul><li> با inner_html
 * حفظ می‌شود. ۲) توضیحات اصلی دیگر از روی #tab-description (که در این سایت
 * وجود ندارد) خوانده نمی‌شود و به‌جای آن، محتوای واقعی از تب اول ویجت
 * Elementor Nested Tabs (data-tab-index="1" داخل .e-n-tabs-content) استخراج
 * می‌شود؛ در نتیجه دیگر توضیحات اصلی با توضیحات کوتاه یکسان نمایش داده
 * نمی‌شود. همچنین یک تابع fix_lazy_images اضافه شد که src تصاویر
 * lazy-load-شده (placeholder با data:image/...base64) را با آدرس واقعی
 * (از data-lzl-src یا <noscript>) جایگزین کرده و تگ‌های noscript/script/style
 * تکراری را از HTML توضیحات حذف می‌کند.
 * هیچ قابلیتی حذف یا ساده نشده است.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// فعال‌سازی لاگ‌های ردیابی دقیق (برای عیب‌یابی)
if ( ! defined( 'MSS_DEBUG' ) ) {
    define( 'MSS_DEBUG', false );
}

// ─── بارگذاری تمامی ماژول‌ها ──────────────────────────────────────
$plugin_dir = plugin_dir_path( __FILE__ );

require_once $plugin_dir . 'class-product-dto.php';
require_once $plugin_dir . 'class-sync-logger.php';
require_once $plugin_dir . 'class-product-mapper.php';
require_once $plugin_dir . 'class-price-rules.php';
require_once $plugin_dir . 'class-source-profile-manager.php';
require_once $plugin_dir . 'class-product-importer.php';
require_once $plugin_dir . 'class-sync-engine.php';
require_once $plugin_dir . 'class-duplicate-finder.php';
require_once $plugin_dir . 'class-abandoned-products.php';
require_once $plugin_dir . 'class-sync-dashboard.php';
require_once $plugin_dir . 'class-menu-visibility.php';
require_once $plugin_dir . 'class-unified-extractor-admin.php';

// ─── رجیستری پویای extractorها ─────────────────────────

$extractors_dir = $plugin_dir;// . 'extractors/';

// بارگذاری تمام فایل‌های اکسترکتور از پوشه‌ی extractors
if ( is_dir( $extractors_dir ) ) {
    foreach ( glob( $extractors_dir . '*.php' ) as $file ) {
        require_once $file;
    }
}

$GLOBALS['mss_extractors'] = array();

// Extractorها (هر کدام به‌طور مستقل کار می‌کنند و ممکن است constructor خود را اجرا کنند)
$GLOBALS['mss_extractors']['nilehyper'] = array(
    'name'  => 'NileHyper',
    'class' => 'NileHyper_Product_Extractor'
);

$GLOBALS['mss_extractors']['mirzaeiwatch'] = array(
    'name'  => 'MirzaeiWatch',
    'class' => 'MirzaeiWatch_Product_Extractor'
);

$GLOBALS['mss_extractors']['timestorr'] = array(
    'name'  => 'TimeStorr',
    'class' => 'TimeStorr_Product_Extractor'
);

$GLOBALS['mss_extractors']['zippogift'] = array(
    'name'  => 'ZippoGift',
    'class' => 'ZippoGift_Product_Extractor'
);

$GLOBALS['mss_extractors']['zudlux'] = array(
    'name'  => 'Zudlux',
    'class' => 'Zudlux_Product_Extractor'
);

$GLOBALS['mss_extractors']['jawaheran'] = array(
    'name'  => 'Jawaheran',
    'class' => 'Jawaheran_Product_Extractor'
);

$GLOBALS['mss_extractors']['ivektools'] = array(
    'name'  => 'IvekTools',
    'class' => 'IvekTools_Product_Extractor'
);

$GLOBALS['mss_extractors']['shikomod'] = array(
    'name'  => 'Shikomod',
    'class' => 'Shikomod_Product_Extractor'
);

$GLOBALS['mss_extractors']['arvindshop'] = array(
    'name'  => 'Arvindshop',
    'class' => 'Arvindshop_Product_Extractor'
);

$GLOBALS['mss_extractors']['bermova'] = array(
    'name'  => 'Bermova',
    'class' => 'Bermova_Product_Extractor'
);

$GLOBALS['mss_extractors']['vgr-iran'] = array(
    'name'  => 'VGR-Iran',
    'class' => 'VGRIran_Product_Extractor'
);

$GLOBALS['mss_extractors']['all-mixin'] = array(
    'name'  => 'All-Mixin',
    'class' => 'Mixin_Product_Extractor'
);

$GLOBALS['mss_extractors']['all-sazito'] = array(
    'name'  => 'All-Sazito',
    'class' => 'Sazito_Product_Extractor'
);

$GLOBALS['mss_extractors']['all-portal'] = array(
    'name'  => 'All-Portal',
    'class' => 'Portal_Product_Extractor'
);

$GLOBALS['mss_extractors']['all-digify'] = array(
    'name'  => 'All-Digify',
    'class' => 'Digify_Product_Extractor'
);

$GLOBALS['mss_extractors']['raoofictc'] = array(
    'name'  => 'Raoofictc',
    'class' => 'Raoofictc_Product_Extractor'
);

// ─── راه‌اندازی کلاس‌های ضروری ─────────────────────────────────

// ─── راه‌اندازی خودکار تمام extractorهای ثبت‌شده ─────────────
if ( ! empty( $GLOBALS['mss_extractors'] ) ) {
    foreach ( $GLOBALS['mss_extractors'] as $ext ) {
        if ( ! empty( $ext['class'] ) && class_exists( $ext['class'] ) ) {
            new $ext['class']();
        }
    }
}

MSS_Unified_Extractor_Admin::init();

// Source_Profile_Manager: ثبت Custom Post Type و مدیریت پروفایل‌ها
new Source_Profile_Manager();

// Sync_Dashboard: افزودن صفحات مدیریتی و هندلرها
new Sync_Dashboard();
MSS_Menu_Visibility::init();

// ماژول تشخیص محصولات تکراری و ردیاب محصولات رهاشده
new MSS_Duplicate_Finder();
new MSS_Abandoned_Products();

// ─── ایجاد/بروزرسانی جداول اختصاصی افزونه (نگاشت URL↔Product و صف داپلیکیت) ───
add_action( 'init', function() {
	if ( class_exists( 'Product_Mapper' ) ) {
		Product_Mapper::maybe_create_table();
	}
	if ( class_exists( 'MSS_Duplicate_Finder' ) ) {
		MSS_Duplicate_Finder::maybe_create_table();
	}
}, 5 );

// ─── سیستم زمان‌بندی خودکار ──────────────────────────────────

/**
 * ثبت cron job اصلی (هر دقیقه یک‌بار)
 */
function mss_register_cron() {
    if ( ! wp_next_scheduled( 'mss_check_schedules' ) ) {
        wp_schedule_event( time(), 'every_minute', 'mss_check_schedules' );
    }
}
add_action( 'init', 'mss_register_cron' );

/**
 * بررسی تمام پروفایل‌ها و اجرای همگام‌سازی در صورت تطابق زمان
 */
function mss_check_profiles_schedules() {

    if ( ! class_exists( 'Source_Profile_Manager' ) || ! class_exists( 'Sync_Engine' ) ) {
        return;
    }


    $profile_ids = Source_Profile_Manager::get_all_profiles();
    $now = current_time( 'timestamp' );
    $current_time = date( 'H:i', $now );

    // تطابق روزهای هفته:
    // date('N') => 1 (دوشنبه) تا 7 (یکشنبه)
    // تنظیمات پروفایل: 0 => یکشنبه, 1 => دوشنبه, ..., 6 => شنبه
    $dow_map = array(
        1 => 1, // دوشنبه
        2 => 2, // سه‌شنبه
        3 => 3, // چهارشنبه
        4 => 4, // پنج‌شنبه
        5 => 5, // جمعه
        6 => 6, // شنبه
        7 => 0, // یکشنبه
    );
    $current_day = $dow_map[ intval( date( 'N', $now ) ) ] ?? 0;


    foreach ( $profile_ids as $profile_id ) {
        $profile = Source_Profile_Manager::get_profile( $profile_id );
        // اگر زمان‌بندی تنظیم نشده باشد، این پروفایل را نادیده می‌گیریم
        if ( empty( $profile['schedule_days'] ) || empty( $profile['schedule_time'] ) ) {
            continue;
        }

        // بررسی روز
        if ( ! in_array( $current_day, $profile['schedule_days'] ) ) {
            continue;
        }

        // بررسی ساعت و دقیقه
        if ( $profile['schedule_time'] !== $current_time ) {
            continue;
        }


        // بررسی قفل (اگر همگام‌سازی دیگری در حال اجراست، اجرا نشود)
        $lock_key = 'sync_lock_' . $profile_id;
        if ( get_option( $lock_key, false ) ) {
            continue;
        }

        // همه چیز آماده است – اجرای همگام‌سازی
        Sync_Logger::log(
            sprintf( 'شروع همگام‌سازی خودکار برای پروفایل %d (%s)', $profile_id, get_the_title( $profile_id ) ),
            'info'
        );
        Sync_Engine::run_sync( $profile_id );
    }
}
add_action( 'mss_check_schedules', 'mss_check_profiles_schedules' );

// ─── بازهٔ cron سفارشی (هر دقیقه) ───────────────────────────────
function mss_add_cron_interval( $schedules ) {
    $schedules['every_minute'] = array(
        'interval' => 60,
        'display'  => 'هر یک دقیقه',
    );
    return $schedules;
}
add_filter( 'cron_schedules', 'mss_add_cron_interval' );

// ─── هوک‌های فعال‌سازی و غیرفعال‌سازی ──────────────────────────
function mss_activate() {
    // اطمینان از ثبت cron job هنگام فعال‌سازی
    if ( ! wp_next_scheduled( 'mss_check_schedules' ) ) {
        wp_schedule_event( time(), 'every_minute', 'mss_check_schedules' );
    }
    if ( class_exists( 'Product_Mapper' ) ) {
        Product_Mapper::maybe_create_table();
    }
    if ( class_exists( 'MSS_Duplicate_Finder' ) ) {
        MSS_Duplicate_Finder::maybe_create_table();
    }
}
register_activation_hook( __FILE__, 'mss_activate' );

function mss_deactivate() {
    // پاک‌سازی cron job
    wp_clear_scheduled_hook( 'mss_check_schedules' );
    wp_clear_scheduled_hook( 'mss_daily_orphan_check' );
}

register_deactivation_hook( __FILE__, 'mss_deactivate' );

// ─── Cron روزانه برای بررسی محصولات رها شده ─────────────────
function mss_register_daily_orphan_check() {
    if ( ! wp_next_scheduled( 'mss_daily_orphan_check' ) ) {
        wp_schedule_event( time(), 'daily', 'mss_daily_orphan_check' );
    }
}
add_action( 'init', 'mss_register_daily_orphan_check' );

function mss_run_daily_orphan_check() {
    if ( ! class_exists( 'Source_Profile_Manager' ) ) {
        return;
    }
    $profile_ids = Source_Profile_Manager::get_all_profiles();
    foreach ( $profile_ids as $pid ) {
        // دریافت لیست آخرین پردازش‌شده‌ها
        $last_ids = get_post_meta( $pid, '_last_sync_product_ids', true );
        if ( ! is_array( $last_ids ) ) {
            $last_ids = array();
        }
        // تمام محصولات این پروفایل
        $existing_ids = get_posts( array(
            'post_type'      => 'product',
            'posts_per_page' => -1,
            'fields'         => 'ids',
            'post_status'    => array( 'publish', 'draft', 'pending', 'private' ),
            'meta_key'       => '_source_profile_id',
            'meta_value'     => $pid,
        ) );
        $orphan_ids = array_diff( $existing_ids, $last_ids );
        if ( ! empty( $orphan_ids ) ) {
            $orphans_data = array();
            foreach ( $orphan_ids as $oid ) {
                $orphans_data[ $oid ] = array(
                    'id'    => $oid,
                    'title' => get_the_title( $oid ),
                    'url'   => get_edit_post_link( $oid, 'raw' ),
                );
            }
            set_transient( 'sync_orphans_' . $pid, $orphans_data, 2 * DAY_IN_SECONDS );
        } else {
            delete_transient( 'sync_orphans_' . $pid );
        }
    }
}
add_action( 'mss_daily_orphan_check', 'mss_run_daily_orphan_check' );
