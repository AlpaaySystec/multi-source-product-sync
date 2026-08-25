<?php
/**
 * Sync Engine
 *
 * وابستگی‌ها:
 * - class-sync-logger.php
 * - class-source-profile-manager.php
 * - class-product-importer.php
 * - Action Scheduler
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Sync_Engine {

    /**
     * نگاشت شناسهٔ extractor به نام کلاس
     */
	private static $extractor_map = null;

	private static function get_extractor_map() {
		if ( null === self::$extractor_map ) {
			self::$extractor_map = array();
			$extractors = $GLOBALS['mss_extractors'] ?? array();
			foreach ( $extractors as $id => $ext ) {
				if ( ! empty( $ext['class'] ) && class_exists( $ext['class'] ) ) {
					self::$extractor_map[ $id ] = $ext['class'];
				}
			}
		}
		return self::$extractor_map;
	}

	// تعداد محصولات در هر تسک
    const CHUNK_SIZE = 10;

    /**
     * اجرای یک چرخهٔ کامل همگام‌سازی
     *
     * @param int $profile_id
     * @return bool|WP_Error
     */
	public static function run_sync( $profile_id ) {
		// ۱) دریافت پروفایل
		if ( ! class_exists( 'Source_Profile_Manager' ) ) {
			Sync_Logger::log( 'کلاس Source_Profile_Manager یافت نشد.', 'error' );
			return new WP_Error( 'missing_class', 'Source_Profile_Manager وجود ندارد.' );
		}

		$profile = Source_Profile_Manager::get_profile( $profile_id );
		if ( empty( $profile['extractor_id'] ) ) {
			Sync_Logger::log( 'پروفایل نامعتبر: extractor_id یا sitemap_url خالی.', 'error' );
			return new WP_Error( 'invalid_profile', 'تنظیمات پروفایل ناقص است.' );
		}

		// ۲) تنظیم قفل
		$lock_key = 'sync_lock_' . $profile_id;
		if ( get_option( $lock_key, false ) ) {
			Sync_Logger::log( sprintf( 'همگام‌سازی برای پروفایل %s در حال اجراست.', $profile['extractor_id'] ), 'warning' );
			return new WP_Error( 'locked', 'همگام‌سازی دیگری در حال اجراست.' );
		}
		update_option( $lock_key, true, 'no' );

		// ** جدید: ایجاد یک Session ID یکتا و ذخیره آن **
		$session_id = uniqid( 'sync_', true );
		update_option( 'sync_session_' . $profile_id, $session_id, 'no' );

		// ۳) دریافت لیست URLها
		if ( 'whitelist' === ( $profile['import_mode'] ?? 'blacklist' ) && ! empty( trim( $profile['whitelist_urls'] ?? '' ) ) ) {
			// ── حالت لیست سفید: کراولر/سایت‌مپ نادیده گرفته می‌شود ──
			$urls = self::parse_url_list( $profile['whitelist_urls'] );
			$url_lastmod_map = array();
		} elseif ( ! empty( $profile['sitemap_url'] ) ) {
			// ── یک یا چند sitemap مستقیم، یا sitemap index با کشف خودکار sitemapهای محصول ──
			$sitemap_result = self::collect_sitemap_product_urls( self::parse_url_list( $profile['sitemap_url'] ) );
			$urls = $sitemap_result['urls'];
			$url_lastmod_map = $sitemap_result['lastmod'];
			Sync_Logger::log(
				sprintf(
					'تجمیع sitemap پایان یافت: %d فایل خوانده شد، %d فایل نامعتبر/ناموجود بود و %d لینک یکتا پیدا شد.',
					$sitemap_result['processed_sitemaps'],
					$sitemap_result['failed_sitemaps'],
					count( $urls )
				),
				$sitemap_result['failed_sitemaps'] > 0 ? 'warning' : 'success'
			);
		} else {
			// ── روش خزنده (crawler) ──
			$map = self::get_extractor_map();
			$extractor_class = $map[ $profile['extractor_id'] ] ?? '';

			if ( empty( $extractor_class ) || ! class_exists( $extractor_class ) || ! method_exists( $extractor_class, 'get_product_urls' ) ) {
				self::unlock( $profile_id );
				Sync_Logger::log( 'کلاس extractor متد get_product_urls را ندارد و sitemap نیز خالی است.', 'error' );
				return new WP_Error( 'no_url_source', 'هیچ منبعی برای URLها تعریف نشده است.' );
			}

			$urls_result = call_user_func( array( $extractor_class, 'get_product_urls' ), $profile );
			if ( is_wp_error( $urls_result ) || ! is_array( $urls_result ) ) {
				self::unlock( $profile_id );
				$msg = is_wp_error( $urls_result ) ? $urls_result->get_error_message() : 'خروجی نامعتبر';
				Sync_Logger::log( 'خطا در دریافت URLها از خزنده: ' . $msg, 'error' );
				return is_wp_error( $urls_result ) ? $urls_result : new WP_Error( 'crawler_error', 'خزنده نتوانست URLها را برگرداند.' );
			}

			$urls = $urls_result;
			$url_lastmod_map = array(); // خزنده lastmod ندارد
		}

		// ذخیره تعداد محصولات پیدا شده در منبع
		update_post_meta( $profile_id, '_last_sync_total_found', count( $urls ) );

		if ( empty( $urls ) ) {
			self::unlock( $profile_id );
			Sync_Logger::log( 'هیچ URL جدیدی برای پردازش یافت نشد.', 'info' );
			update_post_meta( $profile_id, '_last_sync', current_time( 'mysql' ) );
			return true;
		}

		// ذخیره کل URLها برای مدیریت محصولات حذف‌شده
		update_option( 'sync_urls_' . $profile_id, $urls, 'no' );

		// محاسبه اثرانگشت sitemap
		$sitemap_urls_sorted = $urls;
		sort( $sitemap_urls_sorted );
		$current_fingerprint = md5( serialize( $sitemap_urls_sorted ) );

		// --- ایندکس هوشمند ---
		$stored_fingerprint = get_option( 'sync_sitemap_fingerprint_' . $profile_id, '' );
		$index = get_option( 'sync_product_index_' . $profile_id, array() );
		$index_valid = ( $current_fingerprint === $stored_fingerprint && ! empty( $index ) );
		// اگر هیچ فیلتر دسته‌بندی تنظیم نشده باشد، ایندکس را نادیده بگیر و همیشه کامل همگام‌سازی کن
		if ( empty( trim( $profile['allowed_categories'] ?? '' ) ) && empty( trim( $profile['disallowed_categories'] ?? '' ) ) ) {
			$index_valid = false;
		}
		$need_index_build = false;

		if ( $index_valid ) {
			$filtered_urls = array();
			foreach ( $urls as $loc ) {
				// محصولاتی که از قبل نگاشت (ایمپورت) شده‌اند همیشه باید بروزرسانی شوند و
				// نباید توسط ایندکس دسته‌بندی (که فقط برای فیلتر «ایمپورت جدید» است) کنار گذاشته شوند.
				if ( class_exists( 'Product_Mapper' ) && Product_Mapper::get_product_id( $profile_id, $loc ) ) {
					$filtered_urls[] = $loc;
					continue;
				}

				$index_entry = isset( $index[ $loc ] ) ? $index[ $loc ] : null;

				if ( ! $index_entry || $url_lastmod_map[ $loc ] !== $index_entry['lastmod'] ) {
					$filtered_urls[] = $loc;
					continue;
				}

				$product_cats = $index_entry['categories'] ?? array();
				if ( ! self::is_product_allowed_by_categories( $product_cats, $profile ) ) {
					continue;
				}
				$filtered_urls[] = $loc;
			}

			Sync_Logger::log( sprintf( 'ایندکس معتبر است. از %d محصول، %d محصول برای پردازش انتخاب شدند.', count( $urls ), count( $filtered_urls ) ), 'info' );
			$urls = $filtered_urls;
		} else {
			Sync_Logger::log( 'ایندکس معتبر نیست یا وجود ندارد. بازسازی ایندکس همزمان با پردازش...', 'info' );
			$need_index_build = true;
			update_option( 'sync_index_build_temp_' . $profile_id, array(
				'fingerprint'     => $current_fingerprint,
				'url_lastmod_map' => $url_lastmod_map,
				'index_data'      => array(),
			), 'no' );
		}

		// ۵) ذخیره پیشرفت اولیه
		$progress = array(
			'status'    => 'running',
			'total'     => count( $urls ),
			'processed' => 0,
			'created'   => 0,
			'updated'   => 0,
			'failed'    => 0,
			'skipped'   => 0,
			'queued'    => 0,
			'errors'    => array(),
			'session_id'=> $session_id,  // ذخیره session_id در progress
		);
		update_option( 'sync_progress_' . $profile_id, $progress, 'no' );

		Sync_Logger::log( sprintf( 'شروع همگام‌سازی: %d محصول برای پردازش.', count( $urls ) ), 'info', array( 'profile_id' => $profile_id ) );

		// ۶) تقسیم به تکه‌ها و زمان‌بندی
		$chunks = array_chunk( $urls, self::CHUNK_SIZE );
		foreach ( $chunks as $chunk ) {
			as_schedule_single_action( time(), 'sync_process_chunk', array( $profile_id, $chunk ), 'sync_engine' );
		}

		// ۷) زمان‌بندی finalize با تأخیر
		as_schedule_single_action( time() + 10, 'sync_finalize', array( $profile_id ), 'sync_engine' );

		return true;
	}

    /**
     * پردازش یک تکه از URLها
     *
     * @param int   $profile_id
     * @param array $chunk_urls
     */
	public static function process_chunk( $profile_id, $chunk_urls ) {
		if ( ! class_exists( 'Source_Profile_Manager' ) || ! class_exists( 'Product_Importer' ) ) {
			Sync_Logger::log( 'کلاس‌های وابسته در process_chunk یافت نشدند.', 'error' );
			return;
		}

		$profile = Source_Profile_Manager::get_profile( $profile_id );
		$progress = get_option( 'sync_progress_' . $profile_id, null );
		if ( ! $progress || 'running' !== $progress['status'] ) {
			return;
		}

		// ** جدید: دریافت Session ID و مجموعه URLهای پردازش‌شده **
		$session_id = isset( $progress['session_id'] ) ? $progress['session_id'] : get_option( 'sync_session_' . $profile_id, '' );
		$processed_key = 'sync_processed_urls_' . $profile_id . '_' . $session_id;
		$processed_urls = get_option( $processed_key, array() );
		if ( ! is_array( $processed_urls ) ) $processed_urls = array();

		// آرایه‌ای برای جمع‌آوری شناسه‌های محصولاتی که در این chunk پردازش می‌شوند.
		$processed_ids_in_chunk = array();
		$map = self::get_extractor_map();
		$extractor_class = $map[ $profile['extractor_id'] ] ?? '';
		if ( empty( $extractor_class ) || ! class_exists( $extractor_class ) ) {
			Sync_Logger::log( 'کلاس extractor برای ' . $profile['extractor_id'] . ' یافت نشد.', 'error' );
			return;
		}

		if ( ! method_exists( $extractor_class, 'extract' ) ) {
			Sync_Logger::log( 'متد extract در کلاس ' . $extractor_class . ' وجود ندارد.', 'error' );
			return;
		}

		// اگر extractor از احراز هویت استفاده می‌کند، اعتبارنامه را تنظیم کن
		if ( method_exists( $extractor_class, 'set_credentials' ) ) {
			call_user_func( array( $extractor_class, 'set_credentials' ), $profile['auth_username'] ?? '', $profile['auth_password'] ?? '' );
		}

		foreach ( $chunk_urls as $url ) {
			set_time_limit( 30 );

			// ** جدید: اگر این URL قبلاً در این session پردازش شده، رد کن **
			if ( in_array( $url, $processed_urls, true ) ) {
				Sync_Logger::trace( 'Skipping already processed URL: ' . $url, array( 'profile' => $profile_id ) );
				continue;
			}

			// ────────── پردازش URL جاری ──────────
			$dto       = false;
			$error_msg = '';

			try {
				$dto = call_user_func( array( $extractor_class, 'extract' ), $url );
				// Complete diagnostic source payloads belong to the opt-in manual
				// extractor screen and must not inflate routine sync/update records.
				if ( is_array( $dto ) ) unset( $dto['source_data'] );
			} catch ( \Throwable $e ) {
				$error_msg = $e->getMessage();
				Sync_Logger::log( 'استثناء مرگبار در استخراج: ' . $url . ' - ' . $error_msg, 'error' );
			} catch ( \Exception $e ) {
				$error_msg = $e->getMessage();
				Sync_Logger::log( 'استثناء در استخراج: ' . $url . ' - ' . $error_msg, 'error' );
			}

			// ردیابی وضعیت محصول استخراج‌شده
			// if ( $dto && is_array( $dto ) ) {
			// 	Sync_Logger::trace( sprintf(
			// 		'Product status check: stock_status=%s, quantity=%d, manage_stock=%s, product_type=%s',
			// 		$dto['stock_status'],
			// 		$dto['stock_quantity'] ?? -1,
			// 		isset($dto['manage_stock']) ? ($dto['manage_stock'] ? 'true' : 'false') : 'not set',
			// 		$dto['product_type']
			// 	), array( 'url' => $url ) );
			// }

			// اگر استخراج شکست خورد
			if ( false === $dto ) {
				// اگر این لینک قبلاً به یک محصول موجود نگاشت شده، شکست استخراج را به‌عنوان
				// «۴۰۴ / حذف‌شده در مبدأ» در نظر می‌گیریم و طبق تنظیمات پروفایل عمل می‌کنیم
				// (تا لازم نباشد منتظر مقایسهٔ نهایی sitemap در پایان همگام‌سازی بمانیم).
				$mapped_id_on_fail = class_exists( 'Product_Mapper' ) ? Product_Mapper::get_product_id( $profile_id, $url ) : null;
				if ( $mapped_id_on_fail ) {
					self::handle_unavailable_product( $mapped_id_on_fail, $profile, $url );
				}

				$progress['failed']++;
				$progress['errors'][] = array(
					'url'     => $url,
					'message' => 'استخراج شکست خورد' . ( $error_msg ? ': ' . $error_msg : '' )
				);
				$progress['processed']++;
				// ** جدید: علامت‌گذاری URL به‌عنوان پردازش‌شده **
				$processed_urls[] = $url;
				update_option( $processed_key, $processed_urls, 'no' );
				// ذخیره فوری پیشرفت
				update_option( 'sync_progress_' . $profile_id, $progress, 'no' );
				continue;
			}

			// ──────── به‌روزرسانی ایندکس موقت (همیشه، صرف‌نظر از مسیر جدید/موجود) ────────
			$index_build = get_option( 'sync_index_build_temp_' . $profile_id, null );
			if ( $index_build ) {
				$index_build['index_data'][ $url ] = array(
					'categories' => $dto['categories'] ?? array(),
					'product_id' => $dto['product_id'] ?? '',
					'sku'        => $dto['sku'] ?? '',
					'lastmod'    => $index_build['url_lastmod_map'][ $url ] ?? '',
				);
				update_option( 'sync_index_build_temp_' . $profile_id, $index_build, 'no' );
			}

			// تابع کمکی محلی برای رد کردن URL جاری با ثبت در پیشرفت/لاگ
			$skip_url = function( $reason ) use ( &$progress, $url, $processed_key, &$processed_urls, $profile_id ) {
				$progress['processed']++;
				if ( ! isset( $progress['skipped'] ) ) $progress['skipped'] = 0;
				$progress['skipped']++;
				Sync_Logger::log( $reason . ': ' . $url, 'info' );
				$processed_urls[] = $url;
				update_option( $processed_key, $processed_urls, 'no' );
				update_option( 'sync_progress_' . $profile_id, $progress, 'no' );
			};

			// ═══════════════════════════════════════════════════════════════
			// تشخیص محصول موجود از طریق جدول نگاشت (نه SKU). این تشخیص باید قبل
			// از هر فیلتری انجام شود، چون طبق سیاست افزونه، «بروزرسانی همیشه باید
			// انجام شود» و تمام فیلترهای زیر فقط برای ایمپورت محصول جدید هستند.
			// ═══════════════════════════════════════════════════════════════
			$existing_id = class_exists( 'Product_Mapper' ) ? Product_Mapper::get_product_id( $profile_id, $url ) : null;

			// حالت «تطبیق با نسخهٔ قبلی»: این تشخیص هم باید همین‌جا (پیش از فیلترهای
			// محصول جدید مثل تشخیص داپلیکیت/لیست سیاه/بازه‌های قیمتی) انجام شود؛ وگرنه
			// محصولات نسخهٔ قبلی که هنوز در جدول نگاشت ثبت نشده‌اند، به‌جای بروزرسانی
			// ساده، وارد همان مسیر فیلترهای «محصول جدید» می‌شوند.
			if ( ! $existing_id && class_exists( 'Product_Importer' ) && ! empty( $profile['match_legacy_sku'] ) && ! empty( $profile['use_sku_pattern'] ) ) {
				$legacy_sku = Product_Importer::generate_sku( $dto['title'] ?? '', $dto['categories'] ?? array(), $profile['sku_pattern'], $dto );
				if ( ! empty( $legacy_sku ) ) {
					$legacy_id = wc_get_product_id_by_sku( $legacy_sku );
					if ( $legacy_id ) {
						$existing_id = $legacy_id;
						if ( class_exists( 'Product_Mapper' ) ) {
							Product_Mapper::set_mapping( $profile_id, $url, $legacy_id, $dto['title'] ?? '' );
						}
						Sync_Logger::log( sprintf( 'تطبیق با محصول نسخهٔ قبلی: SKU=%s با محصول موجود #%d نگاشت شد.', $legacy_sku, $legacy_id ), 'success', array( 'product_id' => $legacy_id ) );
					}
				}
			}

			if ( ! $existing_id ) {
				// ── این URL یک محصول «جدید» بالقوه است: فیلترهای ایمپورت اعمال می‌شوند ──

				// blacklist حتی در حالت whitelist هم یک رد دائمی است (از جمله تصمیم «کلاً نادیده بگیر» در صف).
				$blacklist = self::parse_url_list( $profile['blacklist_urls'] ?? '' );
				if ( in_array( $url, $blacklist, true ) ) {
					$skip_url( 'محصول جدید به دلیل قرار داشتن در لیست سیاه رد شد' );
					continue;
				}

				// فیلتر دسته‌بندی‌های غیرمجاز
				$disallowed_str = trim( $profile['disallowed_categories'] ?? '' );
				if ( ! empty( $disallowed_str ) ) {
					$disallowed = array_map( 'trim', explode( '|', $disallowed_str ) );
					$product_cats = $dto['categories'] ?? array();
					$hit = false;
					foreach ( $product_cats as $cat ) {
						if ( in_array( trim( $cat ), $disallowed, true ) ) { $hit = true; break; }
					}
					if ( $hit ) {
						$skip_url( 'محصول جدید به دلیل دسته‌بندی غیرمجاز رد شد' );
						continue;
					}
				}

				// فیلتر دسته‌بندی‌های مجاز
				$allowed_str = trim( $profile['allowed_categories'] ?? '' );
				if ( ! empty( $allowed_str ) ) {
					$allowed = array_map( 'trim', explode( '|', $allowed_str ) );
					$product_cats = $dto['categories'] ?? array();
					$is_allowed = false;
					foreach ( $product_cats as $cat ) {
						if ( in_array( trim( $cat ), $allowed, true ) ) { $is_allowed = true; break; }
					}
					if ( ! $is_allowed ) {
						$skip_url( 'محصول جدید به دلیل عدم تطابق با دسته‌بندی مجاز رد شد' );
						continue;
					}
				}

				// فیلتر بازه‌های قیمتی
				if ( class_exists( 'Price_Rules' ) && ! empty( $profile['price_rules'] ) ) {
					$eval = Price_Rules::evaluate_dto( $dto, $profile['price_rules'], $profile['price_rules_variable_edge'] ?? 'import_all' );
					if ( $eval['excluded'] ) {
						$skip_url( 'محصول جدید به دلیل فیلتر بازهٔ قیمتی رد شد (' . $eval['reason'] . ')' );
						continue;
					}
				}

				// محصولات ناموجود (فقط برای محصول جدید بررسی می‌شود)
				// نکته: null یعنی این منبع تعداد دقیق موجودی را گزارش نمی‌کند (نامحدود/ردیابی‌نشده)؛
				// در این حالت فقط بر اساس stock_status تصمیم می‌گیریم (هم‌راستا با ProductDTO::normalize).
				$is_available = false;
				if ( null !== $dto['stock_quantity'] ) {
					$is_available = ( 'in-stock' === $dto['stock_status'] && $dto['stock_quantity'] > 0 );
				} else {
					$is_available = ( 'in-stock' === $dto['stock_status'] );
				}
				if ( ! $profile['import_out_of_stock'] && ! $is_available ) {
					$skip_url( 'محصول جدید ناموجود نادیده گرفته شد' );
					continue;
				}

				// صف تأیید همهٔ محصولات جدید. قواعد و کاندیدها بعداً در داشبورد اعمال می‌شوند.
				if ( ! empty( $profile['enable_duplicate_check'] ) && class_exists( 'MSS_Duplicate_Finder' ) ) {
					MSS_Duplicate_Finder::queue_for_review( $profile_id, $url, $dto, array() );
					if ( ! isset( $progress['queued'] ) ) $progress['queued'] = 0;
					$progress['queued']++;
					$skip_url( 'محصول جدید برای بررسی و تأیید دستی در صف قرار گرفت' );
					continue;
				}
			}

			$action_predicted = $existing_id ? 'updated' : 'created';

			try {
				$result = Product_Importer::import( $dto, $profile_id, $url );
			} catch ( \Throwable $e ) {
				$result = new WP_Error( 'import_fatal', $e->getMessage() );
			}

			// ** جدید: علامت‌گذاری URL به‌عنوان پردازش‌شده (پس از تلاش برای ایمپورت) **
			$processed_urls[] = $url;
			update_option( $processed_key, $processed_urls, 'no' );

			if ( is_wp_error( $result ) ) {
				$progress['failed']++;
				$progress['errors'][] = array(
					'url'     => $url,
					'message' => $result->get_error_message()
				);
				$progress['processed']++;
				Sync_Logger::log( 'خطا در ایمپورت: ' . $result->get_error_message(), 'error' );
			} else {
				$has_changes = $result['has_changes'] ?? true;
				if ( 'created' === $action_predicted && $has_changes ) {
					$progress['created']++;
				} elseif ( 'updated' === $action_predicted && $has_changes ) {
					$progress['updated']++;
				}
				$progress['processed']++;

				// ✅ جمع‌آوری شناسهٔ محصول پردازش‌شده
				if ( ! empty( $result['product_id'] ) ) {
					$processed_ids_in_chunk[] = $result['product_id'];
				}
			}

			// ──────── ذخیره فوری پیشرفت پس از هر محصول ────────
			update_option( 'sync_progress_' . $profile_id, $progress, 'no' );
		}

		// ذخیرهٔ شناسه‌های پردازش‌شدهٔ این chunk (با کلید یکتا)
		if ( ! empty( $processed_ids_in_chunk ) ) {
			$chunk_key = 'sync_chunk_ids_' . $profile_id . '_' . md5( serialize( $chunk_urls ) );
			update_option( $chunk_key, $processed_ids_in_chunk, 'no' );
		}
	}

    /**
     * پایان همگام‌سازی (با مدیریت هوشمند)
     *
     * @param int $profile_id
     */
    public static function finalize_sync( $profile_id ) {
        $progress = get_option( 'sync_progress_' . $profile_id, null );
        if ( ! $progress ) {
            return;
        }

		// بررسی وجود تسک‌های pending یا running برای این پروفایل
		$pending_actions = as_get_scheduled_actions( array(
			'hook'   => 'sync_process_chunk',
			'args'   => array( $profile_id ),
			'status' => array( \ActionScheduler_Store::STATUS_PENDING, \ActionScheduler_Store::STATUS_RUNNING ),
		), 'ids' );

		// اگر هیچ تسکی در صف یا در حال اجرا نیست، یعنی همه chunkها به پایان رسیده‌اند (حتی اگر خطا خورده باشند)
		if ( empty( $pending_actions ) ) {
			// تعداد باقی‌مانده را به عنوان failed در نظر می‌گیریم
			$remaining = $progress['total'] - $progress['processed'];
			if ( $remaining > 0 ) {
				$progress['failed'] += $remaining;
				$progress['processed'] = $progress['total'];
				$progress['errors'][] = array(
					'url'     => 'چند محصول',
					'message' => "$remaining محصول به دلیل خطای مرگبار (timeout) پردازش نشدند."
				);
				update_option( 'sync_progress_' . $profile_id, $progress, 'no' );
			}
		}
		// در غیر این صورت، اگر هنوز تسک pending داریم، طبق معمول ۳۰ ثانیه دیگر صبر می‌کنیم
		else {
			as_schedule_single_action( time() + 30, 'sync_finalize', array( $profile_id ), 'sync_engine' );
			return;
		}

        // ذخیره ایندکس نهایی (در صورت بازسازی)
        $index_build = get_option( 'sync_index_build_temp_' . $profile_id, null );
		if ( $index_build ) {
            update_option( 'sync_product_index_' . $profile_id, $index_build['index_data'], 'no' );
            update_option( 'sync_sitemap_fingerprint_' . $profile_id, $index_build['fingerprint'], 'no' );
            delete_option( 'sync_index_build_temp_' . $profile_id );
            Sync_Logger::log( 'ایندکس محصولات با موفقیت ذخیره شد.', 'success' );
        }


		
        // ═══════ جمع‌آوری تمام شناسه‌های پردازش‌شده از chunkها ═══════
        $all_processed_ids = array();
        // الگوی prefix برای optionهای chunk
        $prefix = 'sync_chunk_ids_' . $profile_id . '_';
        $all_options = wp_load_alloptions();
        foreach ( $all_options as $option_name => $option_value ) {
            if ( strpos( $option_name, $prefix ) === 0 ) {
                $chunk_ids = maybe_unserialize( $option_value );
                if ( is_array( $chunk_ids ) ) {
                    $all_processed_ids = array_merge( $all_processed_ids, $chunk_ids );
                }
                // پاک‌سازی option موقت chunk
                delete_option( $option_name );
            }
        }
        $all_processed_ids = array_unique( $all_processed_ids );

        // ذخیرهٔ لیست پردازش‌شده‌ها به‌عنوان متای پروفایل (برای استفاده‌های بعدی)
        update_post_meta( $profile_id, '_last_sync_product_ids', $all_processed_ids );

        // ═══════ شناسایی محصولات رها شده ═══════
        // دریافت تمام محصولات موجود با این پروفایل (هر وضعیتی به‌جز trash)
        $existing_product_ids = get_posts( array(
            'post_type'      => 'product',
            'posts_per_page' => -1,
            'fields'         => 'ids',
            'post_status'    => array( 'publish', 'draft', 'pending', 'private' ),
            'meta_key'       => '_source_profile_id',
            'meta_value'     => $profile_id,
        ) );

        $orphan_ids = array_diff( $existing_product_ids, $all_processed_ids );

        if ( ! empty( $orphan_ids ) ) {
            // ذخیره‌سازی orphans با جزئیات (شناسه و عنوان)
            $orphans_data = array();
            foreach ( $orphan_ids as $oid ) {
                $orphans_data[ $oid ] = array(
                    'id'    => $oid,
                    'title' => get_the_title( $oid ),
                    'url'   => get_edit_post_link( $oid, 'raw' ),
                );
            }
            set_transient( 'sync_orphans_' . $profile_id, $orphans_data, 2 * DAY_IN_SECONDS );
            Sync_Logger::log(
                sprintf( '%d محصول رها شده برای پروفایل %d شناسایی و ذخیره شد.', count( $orphans_data ), $profile_id ),
                'warning'
            );
        } else {
            // اگر orphans قبلی وجود داشته، پاک کن
            delete_transient( 'sync_orphans_' . $profile_id );
        }

        // ──── مدیریت محصولات حذف‌شده (قبرستان) ────
        $profile = Source_Profile_Manager::get_profile( $profile_id );
        $sitemap_urls = get_option( 'sync_urls_' . $profile_id, array() );
        if ( ! empty( $sitemap_urls ) && class_exists( 'Product_Mapper' ) ) {
            // پاک‌سازی نگاشت‌هایی که محصولشان دیگر واقعاً در سایت وجود ندارد
            Product_Mapper::cleanup_stale( $profile_id );

            $mapping = Product_Mapper::get_all_for_profile( $profile_id );
            foreach ( $mapping as $source_url => $pid ) {
                if ( in_array( $source_url, $sitemap_urls, true ) ) {
                    continue;
                }
                // این محصول قبلاً ایمپورت شده بود اما لینک آن دیگر در نتایج این اجرا نیست
                self::handle_unavailable_product( $pid, $profile, $source_url );
            }
        }
        delete_option( 'sync_urls_' . $profile_id );

        // ──── ثبت گزارش نهایی ────
        $message = sprintf(
            'همگام‌سازی به پایان رسید. کل: %d | پردازش‌شده: %d | ایجاد: %d | بروزرسانی: %d | خطا: %d',
            $progress['total'],
            $progress['processed'],
            $progress['created'],
            $progress['updated'],
            $progress['failed']
        );
        Sync_Logger::log( $message, 'success' );

        update_post_meta( $profile_id, '_last_sync', current_time( 'mysql' ) );

		// پاک‌سازی مجموعه URLهای پردازش‌شده session
		$session_id = get_option( 'sync_session_' . $profile_id, '' );
		if ( $session_id ) {
			delete_option( 'sync_processed_urls_' . $profile_id . '_' . $session_id );
			delete_option( 'sync_session_' . $profile_id );
		}

		if ( class_exists( 'Source_Profile_Manager' ) && class_exists( 'MSS_Duplicate_Finder' ) ) {
			$profile = Source_Profile_Manager::get_profile( $profile_id );
			$queued_count = count( MSS_Duplicate_Finder::get_pending( $profile_id, 0, 0 ) );
			$progress['duplicate_pending'] = $queued_count;
			$progress['requires_duplicate_review'] = ! empty( $profile['enable_duplicate_check'] ) && $queued_count > 0;
			if ( $progress['requires_duplicate_review'] ) {
				update_option( 'mss_last_duplicate_profile', (int) $profile_id, false );
				update_option( 'mss_duplicate_redirect_profile_' . (int) $profile_id, '1', false );
			}
			update_option( 'sync_progress_' . $profile_id, $progress, 'no' );
		}

        self::unlock( $profile_id );
        delete_option( 'sync_progress_' . $profile_id );
        set_transient( 'sync_report_' . $profile_id, $progress, WEEK_IN_SECONDS );
    }

    /**
     * رفتار با محصولی که دیگر در منبع پیدا نشد (حذف‌شده یا 404) - طبق تنظیم on_product_deleted
     */
    private static function handle_unavailable_product( $product_id, $profile, $source_url ) {
        if ( ! get_post( $product_id ) ) {
            return; // خودش قبلاً حذف شده
        }
        $behavior = $profile['on_product_deleted'] ?? 'set_outofstock';
        if ( 'delete' === $behavior ) {
            wp_delete_post( $product_id, true );
            Sync_Logger::log( "محصول #{$product_id} به دلیل عدم دسترسی به لینک مبدأ ({$source_url})، کاملاً پاک شد.", 'info' );
        } else {
            $product = wc_get_product( $product_id );
            if ( $product ) {
                $product->set_stock_status( 'outofstock' );
                $product->set_manage_stock( false );
                $product->save();
                Sync_Logger::log( "محصول #{$product_id} به دلیل عدم دسترسی به لینک مبدأ ({$source_url})، ناموجود شد.", 'info' );
            }
        }
    }

    /**
     * بررسی می‌کند که آیا دسته‌بندی‌های یک محصول با تنظیمات مجاز/غیرمجاز پروفایل مطابقت دارد یا خیر.
     *
     * @param array $product_cats آرایه‌ای از نام دسته‌بندی‌های محصول
     * @param array $profile     آرایه تنظیمات پروفایل
     * @return bool
     */
    private static function is_product_allowed_by_categories( $product_cats, $profile ) {
        // بررسی دسته‌بندی‌های غیرمجاز
        $disallowed_str = trim( $profile['disallowed_categories'] ?? '' );
        if ( ! empty( $disallowed_str ) ) {
            $disallowed = array_map( 'trim', explode( '|', $disallowed_str ) );
            foreach ( $product_cats as $cat ) {
                if ( in_array( trim( $cat ), $disallowed, true ) ) {
                    return false;
                }
            }
        }

        // بررسی دسته‌بندی‌های مجاز
        $allowed_str = trim( $profile['allowed_categories'] ?? '' );
        if ( ! empty( $allowed_str ) ) {
            $allowed = array_map( 'trim', explode( '|', $allowed_str ) );
            $is_allowed = false;
            foreach ( $product_cats as $cat ) {
                if ( in_array( trim( $cat ), $allowed, true ) ) {
                    $is_allowed = true;
                    break;
                }
            }
            if ( ! $is_allowed ) {
                return false;
            }
        }

        return true;
    }

	/**
	 * XML یک sitemap را مستقل از namespace به index یا urlset تبدیل می‌کند.
	 *
	 * @return array|false
	 */
	private static function parse_sitemap_document( $xml_body ) {
		$previous_errors = libxml_use_internal_errors( true );
		$xml = simplexml_load_string( (string) $xml_body, 'SimpleXMLElement', LIBXML_NONET | LIBXML_NOCDATA );
		if ( false === $xml ) {
			libxml_clear_errors();
			libxml_use_internal_errors( $previous_errors );
			return false;
		}

		$sitemap_nodes = $xml->xpath( '/*[local-name()="sitemapindex"]/*[local-name()="sitemap"]' );
		$url_nodes = $xml->xpath( '/*[local-name()="urlset"]/*[local-name()="url"]' );
		$type = ! empty( $sitemap_nodes ) ? 'index' : 'urlset';
		$nodes = 'index' === $type ? $sitemap_nodes : $url_nodes;
		$entries = array();

		foreach ( (array) $nodes as $node ) {
			$loc_nodes = $node->xpath( './*[local-name()="loc"]' );
			$lastmod_nodes = $node->xpath( './*[local-name()="lastmod"]' );
			$loc = ! empty( $loc_nodes ) ? trim( (string) $loc_nodes[0] ) : '';
			if ( '' === $loc ) continue;
			$entries[] = array(
				'loc'     => $loc,
				'lastmod' => ! empty( $lastmod_nodes ) ? trim( (string) $lastmod_nodes[0] ) : '',
			);
		}

		libxml_clear_errors();
		libxml_use_internal_errors( $previous_errors );
		return array( 'type' => $type, 'entries' => $entries );
	}

	/**
	 * آیا لینک فرزند sitemap index به احتمال زیاد شامل URL محصولات است؟
	 */
	private static function is_product_sitemap_url( $url ) {
		$haystack = strtolower( rawurldecode( (string) $url ) );
		if ( preg_match( '/(?:product[_-]?(?:cat|category)|category|post|page|tag|author)[_-]?sitemap/', $haystack ) ) {
			return false;
		}
		return false !== strpos( $haystack, 'product' ) || false !== strpos( $haystack, 'shop' );
	}

	private static function is_nested_sitemap_index_url( $url ) {
		$url = strtolower( (string) $url );
		return false !== strpos( $url, 'sitemap' ) && false !== strpos( $url, 'index' );
	}

	/**
	 * چند sitemap مستقیم را ادغام می‌کند و sitemap indexها را تا عمق محدود دنبال می‌کند.
	 * فایل‌های خراب/ناموجود مانع استفاده از فایل‌های سالم نمی‌شوند.
	 */
	private static function collect_sitemap_product_urls( $seed_urls ) {
		$queue = array();
		foreach ( array_values( array_unique( array_filter( array_map( 'trim', (array) $seed_urls ) ) ) ) as $url ) {
			$queue[] = array( 'url' => $url, 'depth' => 0, 'explicit' => true );
		}

		$seen = array();
		$product_urls = array();
		$lastmod = array();
		$processed = 0;
		$failed = 0;
		$max_sitemaps = 200;
		$max_depth = 5;

		while ( ! empty( $queue ) && count( $seen ) < $max_sitemaps ) {
			$item = array_shift( $queue );
			$sitemap_url = trim( $item['url'] );
			$key = md5( $sitemap_url );
			if ( '' === $sitemap_url || isset( $seen[ $key ] ) || $item['depth'] > $max_depth ) continue;
			$seen[ $key ] = true;

			$response = wp_remote_get( $sitemap_url, array(
				'timeout'    => 15,
				'user-agent' => 'WordPress/MultiSourceSync',
			) );
			if ( is_wp_error( $response ) ) {
				$failed++;
				Sync_Logger::log( 'sitemap در دسترس نبود و نادیده گرفته شد: ' . $sitemap_url . ' — ' . $response->get_error_message(), 'warning' );
				continue;
			}
			$status = (int) wp_remote_retrieve_response_code( $response );
			$body = wp_remote_retrieve_body( $response );
			if ( $status >= 400 || '' === trim( (string) $body ) ) {
				$failed++;
				Sync_Logger::log( sprintf( 'sitemap ناموجود/خالی نادیده گرفته شد: %s (HTTP %d)', $sitemap_url, $status ), 'warning' );
				continue;
			}

			$document = self::parse_sitemap_document( $body );
			if ( false === $document ) {
				$failed++;
				Sync_Logger::log( 'XML نامعتبر sitemap نادیده گرفته شد: ' . $sitemap_url, 'warning' );
				continue;
			}
			$processed++;

			if ( 'index' === $document['type'] ) {
				foreach ( $document['entries'] as $entry ) {
					if ( self::is_product_sitemap_url( $entry['loc'] ) || self::is_nested_sitemap_index_url( $entry['loc'] ) ) {
						$queue[] = array( 'url' => $entry['loc'], 'depth' => $item['depth'] + 1, 'explicit' => false );
					}
				}
				continue;
			}

			foreach ( $document['entries'] as $entry ) {
				$product_urls[ $entry['loc'] ] = true;
				if ( '' !== $entry['lastmod'] ) $lastmod[ $entry['loc'] ] = $entry['lastmod'];
				elseif ( ! isset( $lastmod[ $entry['loc'] ] ) ) $lastmod[ $entry['loc'] ] = '';
			}
		}

		return array(
			'urls'               => array_keys( $product_urls ),
			'lastmod'            => $lastmod,
			'processed_sitemaps' => $processed,
			'failed_sitemaps'    => $failed,
		);
	}

	/**
	 * تبدیل یک متن چندخطی از لینک‌ها به آرایه (هر خط یک لینک، خطوط خالی و فاصله‌ها نادیده گرفته می‌شوند)
	 */
	private static function parse_url_list( $raw ) {
		$lines = preg_split( '/\r\n|\r|\n/', (string) $raw );
		$urls = array();
		foreach ( $lines as $line ) {
			$line = trim( $line );
			if ( '' !== $line ) {
				$urls[] = $line;
			}
		}
		return array_values( array_unique( $urls ) );
	}

	/**
	 * پاک‌سازی قفل همگام‌سازی
	 */
	private static function unlock( $profile_id ) {
        delete_option( 'sync_lock_' . $profile_id );
    }

    /**
     * زمان‌بندی همگام‌سازی بعدی (برای فازهای بعدی)
     */
    public static function schedule_next_sync( $profile_id ) {
        // در فازهای بعدی پیاده‌سازی می‌شود.
    }
}

// ثبت callbackهای Action Scheduler برای Sync Engine
add_action( 'sync_process_chunk', array( 'Sync_Engine', 'process_chunk' ), 10, 2 );
add_action( 'sync_finalize',    array( 'Sync_Engine', 'finalize_sync' ), 10, 1 );
