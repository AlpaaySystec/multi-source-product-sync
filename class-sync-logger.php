<?php
/**
 * کلاس ثبت رویدادهای همگام‌سازی (Sync Logger)
 *
 * یک ابزار متمرکز برای ثبت لاگ با سطوح مختلف و امکان بازیابی و پاک‌سازی.
 * کاملاً مستقل و فقط با استفاده از توابع وردپرس پیاده‌سازی شده است.
 */

// جلوگیری از دسترسی مستقیم
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Sync_Logger {

    /**
     * نام آپشن ذخیره‌سازی لاگ‌ها
     */
    const OPTION_KEY = 'sync_logger_entries';

    /**
     * حداکثر تعداد لاگ‌های ذخیره‌شده
     */
    const MAX_ENTRIES = 200;

    /**
     * سطوح مجاز لاگ
     */
    const ALLOWED_LEVELS = array( 'info', 'success', 'error', 'warning' );

    /**
     * ثبت یک رکورد لاگ جدید
     *
     * @param string $message پیام اصلی
     * @param string $level   سطح لاگ (info, success, error, warning)
     * @param array  $context آرایه‌ای از داده‌های زمینه (اختیاری)
     */
    public static function log( $message, $level = 'info', $context = array() ) {
        if ( ! in_array( $level, self::ALLOWED_LEVELS, true ) ) {
            $level = 'info';
        }

        $entry = array(
            'time'    => current_time( 'mysql' ),
            'level'   => $level,
            'message' => sanitize_text_field( $message ),
            'context' => $context, // انتظار می‌رود داده‌های ساده باشند
        );

        $entries = get_option( self::OPTION_KEY, array() );
        $entries[] = $entry;

        // حفظ حداکثر تعداد رکوردها
        if ( count( $entries ) > self::MAX_ENTRIES ) {
            $entries = array_slice( $entries, -self::MAX_ENTRIES );
        }

        update_option( self::OPTION_KEY, $entries, 'no' );
    }

    /**
     * دریافت آخرین لاگ‌ها
     *
     * @param int $limit تعداد لاگ‌های درخواستی (0 برای همه)
     * @return array
     */
    public static function get_logs( $limit = 50 ) {
        $entries = get_option( self::OPTION_KEY, array() );
        if ( $limit > 0 && count( $entries ) > $limit ) {
            return array_slice( $entries, -$limit );
        }
        return $entries;
    }

    /**
     * پاک کردن تمام لاگ‌ها
     */
    public static function clear_logs() {
        delete_option( self::OPTION_KEY );
    }
	
	/**
	 * لاگ ردیابی (فقط در حالت MSS_DEBUG فعال است)
	 *
	 * @param string $message
	 * @param array  $context
	 */
	public static function trace( $message, $context = array() ) {
		if ( defined( 'MSS_DEBUG' ) && MSS_DEBUG ) {
			$now = DateTime::createFromFormat( 'U.u', microtime( true ) );
			if ( $now ) {
				$timezone = new DateTimeZone( wp_timezone_string() );
				$now->setTimezone( $timezone );
				$time = $now->format( 'H:i:s.u' );
			} else {
				$time = microtime( true );
			}
			self::log( $time . ' | ' . $message, 'info', $context );
		}
	}
}