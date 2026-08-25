<?php
/**
 * اکسترکتور محصولات برای فروشگاه‌های ساخته‌شده با پلتفرم Digify.
 *
 * برخلاف پورتال، صفحه‌ی محصول Digify هیچ داده‌ای از خودِ محصول را در HTML
 * سرور رندر نمی‌کند (فقط اطلاعات کلی فروشگاه/تم/دسته‌بندی‌ها به‌صورت
 * سرور-رندر prefetch می‌شود؛ خودِ محصول کاملاً سمت کلاینت و بعد از لود صفحه
 * از طریق یک API عمومی خوانده می‌شود). به همین دلیل این اکسترکتور به‌جای
 * پارس کردن HTML، مستقیماً همان API عمومی را صدا می‌زند:
 *
 *   GET https://{دامنه‌ی فروشگاه}/backend/customer/product/s/{شناسه‌ی محصول}/
 *
 * شناسه‌ی محصول از روی الگوی آدرس صفحه (/product/{id}/{slug}) استخراج
 * می‌شود. این API نیازی به هدر یا نشست خاصی ندارد (به‌عنوان یک مشتری
 * ناشناس/مهمان قابل‌دسترسی است)؛ برای اطمینان، اگر درخواست اول شکست
 * بخورد، یک بار دیگر با یک x-customer-signature تصادفی تلاش می‌شود.
 *
 * فیلدهای قیمت (cost, primary_cost, online_cost, online_primary_cost)
 * مستقیماً به تومان هستند (با مقایسه‌ی مقدار API با متن رندرشده‌ی صفحه
 * تأیید شد؛ نیازی به تبدیل واحد نیست).
 *
 * @package ProductImporter
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // دسترسی مستقیم مجاز نیست.
}

require_once __DIR__ . '/class-product-dto.php';

class Digify_Product_Extractor {

	const MENU_SLUG = 'digify-product-extractor';

	public function __construct() {
		add_action( 'admin_menu', array( $this, 'register_admin_page' ) );
	}

	// =====================================================================
	// صفحه‌ی مدیریت (پیش‌نمایش دستی)
	// =====================================================================

	public function register_admin_page() {
		add_submenu_page(
			'edit.php?post_type=source_profile',
			'اکسترکتور دیجیفای',
			'اکسترکتور دیجیفای',
			'manage_options',
			self::MENU_SLUG,
			array( $this, 'render_admin_page' )
		);
	}

	public function render_admin_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'دسترسی غیرمجاز.' );
		}

		echo '<div class="wrap">';
		echo '<h1>اکسترکتور محصولات دیجیفای (Digify)</h1>';
		echo '<p>آدرس صفحه‌ی هر محصولی که با پلتفرم دیجیفای ساخته شده را وارد کنید.</p>';

		echo '<form method="post">';
		wp_nonce_field( 'digify_extract_action', 'digify_extract_nonce' );
		echo '<table class="form-table"><tr><th><label for="product_url">آدرس محصول</label></th>';
		echo '<td><input type="url" id="product_url" name="product_url" class="regular-text" required value="' . esc_attr( isset( $_POST['product_url'] ) ? sanitize_text_field( wp_unslash( $_POST['product_url'] ) ) : '' ) . '"></td></tr></table>';
		submit_button( 'استخراج اطلاعات محصول' );
		echo '</form>';

		if (
			'POST' === $_SERVER['REQUEST_METHOD']
			&& isset( $_POST['digify_extract_nonce'] )
			&& wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['digify_extract_nonce'] ) ), 'digify_extract_action' )
			&& ! empty( $_POST['product_url'] )
		) {
			$url = esc_url_raw( wp_unslash( $_POST['product_url'] ) );
			$this->extract_and_display( $url );
		}

		echo '</div>';
	}

	private function extract_and_display( $url ) {
		try {
			$data = $this->extract_product_data( $url );
		} catch ( \Throwable $e ) {
			echo '<div class="notice notice-error"><p>خطا در استخراج: ' . esc_html( $e->getMessage() ) . '</p></div>';
			return;
		}

		if ( ! is_array( $data ) || isset( $data['error'] ) ) {
			$message = is_array( $data ) && isset( $data['error'] ) ? $data['error'] : 'اطلاعاتی یافت نشد.';
			echo '<div class="notice notice-error"><p>' . esc_html( $message ) . '</p></div>';
			return;
		}

		$this->display_product_data( $data );
	}

	private function display_product_data( $data ) {
		echo '<h2>نتیجه‌ی استخراج</h2>';

		echo '<table class="widefat striped" style="max-width:900px">';
		$rows = array(
			'شناسه محصول' => $data['product_id'],
			'کد محصول (SKU)' => $data['sku'],
			'عنوان' => $data['title'],
			'نوع محصول' => 'variable' === $data['product_type'] ? 'متغیر (دارای واریانت)' : 'ساده',
			'قیمت اصلی' => number_format_i18n( $data['regular_price'] ) . ' ' . $data['currency'],
			'قیمت با تخفیف' => null !== $data['sale_price'] ? number_format_i18n( $data['sale_price'] ) . ' ' . $data['currency'] : '—',
			'وضعیت موجودی' => 'in-stock' === $data['stock_status'] ? 'موجود' : 'ناموجود',
			'تعداد موجودی' => null !== $data['stock_quantity'] ? $data['stock_quantity'] : 'نامحدود / پیگیری‌نشده',
			'دسته‌بندی‌ها' => implode( ' > ', $data['categories'] ),
			'خلاصه' => $data['excerpt'],
			'آدرس کانونیکال' => $data['canonical'],
		);
		foreach ( $rows as $label => $value ) {
			echo '<tr><th style="width:220px;text-align:right">' . esc_html( $label ) . '</th><td>' . esc_html( (string) $value ) . '</td></tr>';
		}
		echo '</table>';

		if ( ! empty( $data['featured_image'] ) ) {
			echo '<h3>تصویر شاخص</h3>';
			echo '<img src="' . esc_url( $data['featured_image'] ) . '" style="max-width:200px;height:auto;border:1px solid #ddd">';
		}
		if ( ! empty( $data['gallery_images'] ) ) {
			echo '<h3>گالری تصاویر (' . count( $data['gallery_images'] ) . ' تصویر)</h3><div>';
			foreach ( $data['gallery_images'] as $img ) {
				echo '<img src="' . esc_url( $img ) . '" style="max-width:120px;height:auto;margin:4px;border:1px solid #ddd">';
			}
			echo '</div>';
		}

		if ( ! empty( $data['content'] ) ) {
			echo '<h3>توضیحات کامل</h3><div style="max-width:900px;border:1px solid #ddd;padding:12px;background:#fff">' . wp_kses_post( $data['content'] ) . '</div>';
		}

		if ( ! empty( $data['attributes'] ) ) {
			echo '<h3>ویژگی‌ها</h3><table class="widefat striped" style="max-width:900px">';
			echo '<tr><th>نام</th><th>مقادیر</th><th>برای واریانت؟</th></tr>';
			foreach ( $data['attributes'] as $attr ) {
				echo '<tr><td>' . esc_html( $attr['name'] ) . '</td><td>' . esc_html( implode( '، ', $attr['values'] ) ) . '</td><td>' . ( ! empty( $attr['used_for_variations'] ) ? 'بله' : 'خیر' ) . '</td></tr>';
			}
			echo '</table>';
		}

		if ( ! empty( $data['variations'] ) ) {
			echo '<h3>واریانت‌ها (' . count( $data['variations'] ) . ' مورد)</h3><table class="widefat striped" style="max-width:1100px">';
			echo '<tr><th>ترکیب</th><th>SKU</th><th>قیمت اصلی</th><th>قیمت با تخفیف</th><th>موجودی</th><th>تصویر</th></tr>';
			foreach ( $data['variations'] as $v ) {
				echo '<tr>';
				echo '<td>' . esc_html( $v['attributes_summary'] ) . '</td>';
				echo '<td>' . esc_html( $v['sku'] ) . '</td>';
				echo '<td>' . esc_html( number_format_i18n( $v['regular_price'] ) ) . '</td>';
				echo '<td>' . ( null !== $v['sale_price'] ? esc_html( number_format_i18n( $v['sale_price'] ) ) : '—' ) . '</td>';
				echo '<td>' . ( 'in-stock' === $v['stock_status'] ? 'موجود' : 'ناموجود' ) . ( null !== $v['stock_quantity'] ? ' (' . esc_html( $v['stock_quantity'] ) . ')' : '' ) . '</td>';
				echo '<td>' . ( ! empty( $v['image'] ) ? '<img src="' . esc_url( $v['image'] ) . '" style="max-width:60px;height:auto">' : '—' ) . '</td>';
				echo '</tr>';
			}
			echo '</table>';
		}
	}

	// =====================================================================
	// نقاط ورودی عمومی (مورد استفاده‌ی موتور همگام‌سازی)
	// =====================================================================

	/**
	 * استخراج و نرمال‌سازی اطلاعات یک محصول از روی آدرس آن.
	 *
	 * @param string $url آدرس صفحه‌ی محصول.
	 * @return array|false آرایه‌ی نرمال‌شده یا false در صورت شکست.
	 */
	public static function extract( $url ) {
		$instance = new self();
		try {
			$data = $instance->extract_product_data( $url );
		} catch ( \Throwable $e ) {
			return false;
		}

		if ( ! is_array( $data ) || isset( $data['error'] ) ) {
			return false;
		}

		if ( class_exists( 'ProductDTO' ) && method_exists( 'ProductDTO', 'normalize' ) ) {
			$normalized = ProductDTO::normalize( $data );
			if ( is_array( $normalized ) ) {
				return $normalized;
			}
		}

		return $data;
	}

	/**
	 * تشخیص این‌که آیا یک صفحه‌ی HTML متعلق به فروشگاهی ساخته‌شده با
	 * دیجیفای است یا نه. نیازی به دسترسی شبکه ندارد؛ HTML را که از قبل
	 * واکشی شده بپذیرید (نه یک URL).
	 *
	 * نشانه‌ها به ترتیب اطمینان:
	 *   ۱) <meta name="generator" content="Digify">‌ — قطعی‌ترین نشانه.
	 *   ۲) ارجاع به CDN اختصاصی digifycdn.com همراه با assetPrefix
	 *      مربوط به Next.js — نشانه‌ی پشتیبان.
	 *
	 * @param string $html HTML از قبل واکشی‌شده‌ی صفحه.
	 * @return bool
	 */
	public static function is_platform_match( $html ) {
		if ( empty( $html ) || ! is_string( $html ) ) {
			return false;
		}

		if ( preg_match( '/<meta[^>]+name=["\']generator["\'][^>]+content=["\']Digify["\'][^>]*>/i', $html ) ) {
			return true;
		}
		if ( preg_match( '/<meta[^>]+content=["\']Digify["\'][^>]+name=["\']generator["\'][^>]*>/i', $html ) ) {
			return true;
		}

		if ( false !== stripos( $html, 'digifycdn.com' ) && false !== stripos( $html, 'assetPrefix' ) ) {
			return true;
		}

		return false;
	}

	/**
	 * تلاش برای یافتن آدرس محصولات یک فروشگاه دیجیفای از طریق sitemap.xml.
	 * آدرس‌های دیجیفای همیشه الگوی قطعی /product/{شناسه‌ی-عددی}/ دارند که
	 * فیلتر کردن آن‌ها را ساده و مطمئن می‌کند.
	 *
	 * @param array $profile پروفایل منبع؛ باید url یا sitemap_url داشته باشد.
	 * @return array|WP_Error
	 */
	public static function get_product_urls( $profile ) {
		$instance = new self();

		$sitemap_url = ! empty( $profile['sitemap_url'] ) ? $profile['sitemap_url'] : '';
		$site_url    = '';
		if ( ! empty( $profile['url'] ) ) {
			$site_url = $profile['url'];
		} elseif ( ! empty( $profile['base_url'] ) ) {
			$site_url = $profile['base_url'];
		}

		if ( '' === $sitemap_url && '' !== $site_url ) {
			$parts       = wp_parse_url( $site_url );
			$origin      = ( isset( $parts['scheme'] ) ? $parts['scheme'] : 'https' ) . '://' . ( isset( $parts['host'] ) ? $parts['host'] : '' );
			$sitemap_url = $origin . '/sitemap.xml';
		}

		if ( '' === $sitemap_url ) {
			return new WP_Error( 'digify_no_sitemap', 'آدرس سایتمپ یا آدرس پایه‌ی سایت مشخص نیست.' );
		}

		$urls = $instance->collect_sitemap_urls( $sitemap_url, 0 );

		$filtered = array();
		foreach ( $urls as $u ) {
			$path = wp_parse_url( $u, PHP_URL_PATH );
			if ( is_string( $path ) && preg_match( '#^/product/\d+/#', $path ) ) {
				$filtered[] = $u;
			}
		}

		return array_values( array_unique( $filtered ) );
	}

	private function collect_sitemap_urls( $sitemap_url, $depth ) {
		if ( $depth > 3 ) {
			return array();
		}

		$response = wp_safe_remote_get(
			$sitemap_url,
			array(
				'timeout'   => 30,
				'sslverify' => true,
				'limit_response_size' => 6291456,
				'headers'   => array( 'User-Agent' => 'Mozilla/5.0 (compatible; ProductImporterBot/1.0)' ),
			)
		);

		if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			return array();
		}

		$xml = wp_remote_retrieve_body( $response );
		if ( empty( $xml ) ) {
			return array();
		}

		libxml_use_internal_errors( true );
		$dom = new DOMDocument();
		$dom->loadXML( $xml );
		libxml_clear_errors();
		$xpath = new DOMXPath( $dom );
		$xpath->registerNamespace( 'sm', 'http://www.sitemaps.org/schemas/sitemap/0.9' );

		$sub_nodes = $xpath->query( '//sm:sitemap/sm:loc' );
		if ( $sub_nodes && $sub_nodes->length > 0 ) {
			$preferred = array();
			$others    = array();
			foreach ( $sub_nodes as $node ) {
				$loc = trim( $node->textContent );
				if ( '' === $loc ) {
					continue;
				}
				if ( false !== stripos( $loc, 'product' ) ) {
					$preferred[] = $loc;
				} else {
					$others[] = $loc;
				}
			}
			$to_fetch = ! empty( $preferred ) ? $preferred : $others;
			$all      = array();
			foreach ( array_slice( $to_fetch, 0, 20 ) as $loc ) {
				$all = array_merge( $all, $this->collect_sitemap_urls( $loc, $depth + 1 ) );
			}
			return $all;
		}

		$url_nodes = $xpath->query( '//sm:url/sm:loc' );
		$result    = array();
		foreach ( $url_nodes as $node ) {
			$loc = trim( $node->textContent );
			if ( '' !== $loc ) {
				$result[] = $loc;
			}
		}
		return $result;
	}

	// =====================================================================
	// هسته‌ی استخراج: پیدا کردن شناسه‌ی محصول از روی URL و صدا زدن API
	// =====================================================================

	public function extract_product_data( $url ) {
		if ( empty( $url ) || ! filter_var( $url, FILTER_VALIDATE_URL ) ) {
			return array( 'error' => 'آدرس نامعتبر است.' );
		}

		$product_id = $this->extract_product_id_from_url( $url );
		if ( '' === $product_id ) {
			return array( 'error' => 'شناسه‌ی محصول از روی آدرس قابل تشخیص نبود (الگوی مورد انتظار: /product/{شناسه}/...).' );
		}

		$parts    = wp_parse_url( $url );
		$base_url = ( isset( $parts['scheme'] ) ? $parts['scheme'] : 'https' ) . '://' . ( isset( $parts['host'] ) ? $parts['host'] : '' );
		$api_url  = rtrim( $base_url, '/' ) . '/backend/customer/product/s/' . $product_id . '/';

		$product = $this->fetch_product_api( $api_url );
		if ( ! is_array( $product ) ) {
			return array( 'error' => 'اطلاعاتی از API دیجیفای دریافت نشد. آدرس درخواست‌شده: ' . $api_url );
		}
		if ( empty( $product['id'] ) ) {
			return array( 'error' => 'پاسخ API ساختار محصول معتبری نداشت.' );
		}

		return $this->normalize_product( $product, $url, $base_url );
	}

	/**
	 * استخراج شناسه‌ی عددی محصول از آدرس صفحه؛ الگوی ثابت دیجیفای:
	 * /product/{شناسه}/{اسلاگ}
	 */
	private function extract_product_id_from_url( $url ) {
		$path = wp_parse_url( $url, PHP_URL_PATH );
		if ( empty( $path ) ) {
			return '';
		}
		if ( preg_match( '#/product/(\d+)#', $path, $m ) ) {
			return $m[1];
		}
		return '';
	}

	/**
	 * صدا زدن API عمومی محصول دیجیفای. اگر تلاش اول (بدون هدر خاص) شکست
	 * بخورد، یک بار دیگر با یک x-customer-signature تصادفی امتحان می‌شود
	 * (برای احتیاط، هرچند طبق بررسی این هدر برای خواندن محصول لازم نبود).
	 *
	 * @return array|null
	 */
	private function fetch_product_api( $api_url ) {
		$args = array(
			'timeout'   => 30,
			'sslverify' => true,
			'limit_response_size' => 6291456,
			'headers'   => array(
				'Accept'          => 'application/json, text/plain, */*',
				'Accept-Language' => 'fa-IR',
				'User-Agent'      => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0 Safari/537.36',
			),
		);

		$response = wp_safe_remote_get( $api_url, $args );
		$status   = is_wp_error( $response ) ? 0 : (int) wp_remote_retrieve_response_code( $response );

		if ( 200 !== $status ) {
			$args['headers']['x-customer-signature'] = wp_generate_uuid4();
			$response                                = wp_safe_remote_get( $api_url, $args );
			$status                                  = is_wp_error( $response ) ? 0 : (int) wp_remote_retrieve_response_code( $response );
		}

		if ( is_wp_error( $response ) || 200 !== $status ) {
			return null;
		}

		$body = wp_remote_retrieve_body( $response );
		if ( empty( $body ) ) {
			return null;
		}

		$data = json_decode( $body, true );
		return is_array( $data ) ? $data : null;
	}

	// =====================================================================
	// نگاشت پاسخ API به ساختار مشترک
	// =====================================================================

	private function normalize_product( $product, $url, $base_url ) {
		$product_id = (string) $this->array_get( $product, 'id', '' );

		$title = $this->clean_text( $this->array_get( $product, 'name', '' ) );
		if ( '' === $title ) {
			$title = $this->clean_text( $this->array_get( $product, 'label', '' ) );
		}

		// --- توضیحات ---
		$description = (string) $this->array_get( $product, 'description', '' );
		$content     = ( '' !== trim( wp_strip_all_tags( $description ) ) ) ? $description : '';

		$excerpt  = '';
		$seo_data = $this->array_get( $product, 'seo_data', array() );
		if ( is_array( $seo_data ) ) {
			$excerpt = $this->clean_text( $this->array_get( $seo_data, 'description', '' ) );
		}
		if ( '' === $excerpt && '' !== $content ) {
			$excerpt = wp_trim_words( wp_strip_all_tags( $content ), 60, '...' );
		}

		// --- تصاویر: تصویر انتخاب‌شده (chosen_image) در اولویت اول به‌عنوان تصویر شاخص ---
		$gallery_urls = array();
		$chosen       = $this->array_get( $product, 'chosen_image', array() );
		if ( is_array( $chosen ) ) {
			$chosen_path = $this->array_get( $chosen, 'image', '' );
			if ( '' !== $chosen_path ) {
				$gallery_urls[] = $this->make_absolute_url( $chosen_path, $base_url );
			}
		}
		$images_raw = $this->array_get( $product, 'images', array() );
		if ( is_array( $images_raw ) ) {
			foreach ( $images_raw as $img ) {
				$path = is_array( $img ) ? $this->array_get( $img, 'image', '' ) : '';
				if ( '' !== $path ) {
					$abs = $this->make_absolute_url( $path, $base_url );
					if ( ! in_array( $abs, $gallery_urls, true ) ) {
						$gallery_urls[] = $abs;
					}
				}
			}
		}
		$featured_image = ! empty( $gallery_urls ) ? $gallery_urls[0] : '';
		$gallery_images = count( $gallery_urls ) > 1 ? array_slice( $gallery_urls, 1 ) : array();

		// --- دسته‌بندی: زنجیره‌ی کامل با دنبال‌کردن parent ---
		$categories = $this->build_category_chain( $this->array_get( $product, 'category', null ) );

		// --- ویژگی‌های ثابت (مشخصات فنی) ---
		$extra_attributes = array();
		$features          = $this->array_get( $product, 'features', array() );
		if ( is_array( $features ) ) {
			foreach ( $features as $f ) {
				if ( ! is_array( $f ) ) {
					continue;
				}
				$name  = $this->clean_text( $this->array_get( $f, 'title', '' ) );
				$value = $this->clean_text( $this->array_get( $f, 'description', '' ) );
				if ( '' !== $name && '' !== $value ) {
					$extra_attributes[] = array(
						'name'                => $name,
						'values'              => array( $value ),
						'used_for_variations' => false,
					);
				}
			}
		}

		// --- واریانت‌ها ---
		$variants_raw = $this->array_get( $product, 'variants', array() );
		if ( ! is_array( $variants_raw ) ) {
			$variants_raw = array();
		}
		if ( empty( $variants_raw ) ) {
			// حالت غیرمنتظره: آرایه‌ی variants خالی است؛ از مقادیر سطح محصول به‌عنوان تنها واریانت استفاده می‌کنیم.
			$variants_raw[] = array(
				'id'            => $product_id,
				'cost'          => 0,
				'primary_cost'  => 0,
				'stock'         => $this->array_get( $product, 'product_stock', 0 ),
				'is_unlimited'  => false,
				'is_active'     => (bool) $this->array_get( $product, 'has_stock', true ),
				'option_values' => array(),
				'images'        => array(),
			);
		}

		$built = $this->build_attributes_and_variations( $variants_raw, $extra_attributes, $base_url );

		return array(
			'product_id'     => $product_id,
			'sku'            => $product_id,
			'title'          => $title,
			'excerpt'        => $excerpt,
			'content'        => $content,
			'featured_image' => $featured_image,
			'gallery_images' => $gallery_images,
			'regular_price'  => $built['regular_price'],
			'sale_price'     => $built['sale_price'],
			'currency'       => 'تومان',
			'stock_status'   => $built['stock_status'],
			'stock_quantity' => $built['stock_quantity'],
			'categories'     => $categories,
			'tags'           => array(),
			'product_type'   => $built['product_type'],
			'attributes'     => $built['attributes'],
			'variations'     => $built['variations'],
			'meta_title'     => $title,
			'canonical'      => $url,
		);
	}

	/**
	 * دنبال‌کردن زنجیره‌ی category.parent برای ساخت مسیر کامل دسته‌بندی
	 * (از ریشه به سمت برگ).
	 */
	private function build_category_chain( $category ) {
		$chain = array();
		$guard = 0;
		while ( is_array( $category ) && $guard < 10 ) {
			$title = $this->clean_text( $this->array_get( $category, 'title', '' ) );
			if ( '' !== $title ) {
				array_unshift( $chain, $title );
			}
			$category = $this->array_get( $category, 'parent', null );
			++$guard;
		}
		return $chain;
	}

	// =====================================================================
	// منطق مشترک: تبدیل آرایه‌ی variants خام API به attributes/variations
	// =====================================================================

	/**
	 * @param array $variants_raw      آرایه‌ی خام variants از پاسخ API.
	 * @param array $extra_attributes  ویژگی‌های ثابت (از features) که به هر حال باید در خروجی باشند.
	 * @param string $base_url         برای تبدیل مسیرهای نسبی تصویر به آدرس مطلق (تصاویر دیجیفای معمولاً از قبل مطلق هستند).
	 */
	private function build_attributes_and_variations( $variants_raw, $extra_attributes, $base_url ) {
		$variants_raw = array_values( array_filter( $variants_raw, 'is_array' ) );
		$count        = count( $variants_raw );

		if ( $count <= 1 ) {
			$v = $count === 1 ? $variants_raw[0] : array();
			list( $regular, $sale ) = $this->split_price( $this->array_get( $v, 'cost', 0 ), $this->array_get( $v, 'primary_cost', 0 ) );
			list( $stock_status, $stock_quantity ) = $this->resolve_stock( $v );

			return array(
				'product_type'   => 'simple',
				'attributes'     => $extra_attributes,
				'variations'     => array(),
				'regular_price'  => $regular,
				'sale_price'     => $sale,
				'stock_status'   => $stock_status,
				'stock_quantity' => $stock_quantity,
			);
		}

		$attr_map   = array(); // نام ویژگی => [مقادیر یکتا]
		$variations = array();

		foreach ( $variants_raw as $v ) {
			$option_values = $this->array_get( $v, 'option_values', array() );
			$pairs         = array();
			if ( is_array( $option_values ) ) {
				foreach ( $option_values as $ov ) {
					if ( ! is_array( $ov ) ) {
						continue;
					}
					$opt  = $this->array_get( $ov, 'option', array() );
					$name = is_array( $opt ) ? trim( (string) $this->array_get( $opt, 'name', '' ) ) : '';
					$val  = trim( (string) $this->array_get( $ov, 'value', '' ) );
					if ( '' !== $name && '' !== $val ) {
						$pairs[ $name ] = $val;
						if ( ! isset( $attr_map[ $name ] ) ) {
							$attr_map[ $name ] = array();
						}
						if ( ! in_array( $val, $attr_map[ $name ], true ) ) {
							$attr_map[ $name ][] = $val;
						}
					}
				}
			}

			list( $regular, $sale ) = $this->split_price( $this->array_get( $v, 'cost', 0 ), $this->array_get( $v, 'primary_cost', 0 ) );
			list( $v_stock_status, $v_stock_quantity ) = $this->resolve_stock( $v );

			$image      = '';
			$images_raw = $this->array_get( $v, 'images', array() );
			if ( is_array( $images_raw ) && ! empty( $images_raw ) && is_array( $images_raw[0] ) ) {
				$img_path = $this->array_get( $images_raw[0], 'image', '' );
				if ( '' !== $img_path ) {
					$image = $this->make_absolute_url( $img_path, $base_url );
				}
			}

			$summary = implode( '، ', array_map(
				function ( $n, $vv ) {
					return $n . ': ' . $vv;
				},
				array_keys( $pairs ),
				array_values( $pairs )
			) );
			if ( '' === $summary ) {
				$summary = $this->clean_text( $this->array_get( $v, 'name', '' ) );
			}

			$variations[] = array(
				'attributes_summary' => $summary,
				'attributes_map'     => $pairs,
				'sku'                => (string) $this->array_get( $v, 'id', '' ),
				'regular_price'      => $regular,
				'sale_price'         => $sale,
				'stock_status'       => $v_stock_status,
				'stock_quantity'     => $v_stock_quantity,
				'image'              => $image,
			);
		}

		$attributes = $extra_attributes;
		foreach ( $attr_map as $name => $values ) {
			$attributes[] = array(
				'name'                => $name,
				'values'              => $values,
				'used_for_variations' => true,
			);
		}

		// --- جمع‌بندی برای محصول والد ---
		$reg_prices = array();
		foreach ( $variations as $vv ) {
			if ( $vv['regular_price'] > 0 ) {
				$reg_prices[] = $vv['regular_price'];
			}
		}
		$parent_regular = ! empty( $reg_prices ) ? min( $reg_prices ) : 0;

		$sale_prices = array();
		foreach ( $variations as $vv ) {
			if ( null !== $vv['sale_price'] ) {
				$sale_prices[] = $vv['sale_price'];
			}
		}
		$parent_sale = ! empty( $sale_prices ) ? min( $sale_prices ) : null;

		$has_stock     = false;
		$total_qty     = 0;
		$any_unlimited = false;
		foreach ( $variations as $vv ) {
			if ( 'in-stock' === $vv['stock_status'] ) {
				$has_stock = true;
			}
			if ( null === $vv['stock_quantity'] ) {
				$any_unlimited = true;
			} else {
				$total_qty += $vv['stock_quantity'];
			}
		}

		return array(
			'product_type'   => 'variable',
			'attributes'     => $attributes,
			'variations'     => $variations,
			'regular_price'  => $parent_regular,
			'sale_price'     => $parent_sale,
			'stock_status'   => $has_stock ? 'in-stock' : 'out-of-stock',
			'stock_quantity' => $any_unlimited ? null : $total_qty,
		);
	}

	/**
	 * از روی cost (قیمت جاری) و primary_cost (قیمت اصلی/فهرست) قیمت اصلی و
	 * قیمت با تخفیف را استخراج می‌کند.
	 *
	 * @return array{0:int,1:int|null} [قیمت اصلی، قیمت با تخفیف یا null]
	 */
	private function split_price( $cost, $primary_cost ) {
		$cost    = (int) $cost;
		$primary = ( null !== $primary_cost && '' !== $primary_cost ) ? (int) $primary_cost : 0;

		if ( $primary > $cost && $cost > 0 ) {
			return array( $primary, $cost );
		}
		if ( $primary > 0 && 0 === $cost ) {
			return array( $primary, null );
		}
		return array( $cost > 0 ? $cost : $primary, null );
	}

	/**
	 * وضعیت و تعداد موجودی یک واریانت را با درنظرگرفتن is_unlimited (موجودی
	 * مدیریت‌نشده/نامحدود) و is_active (غیرفعال‌بودن دستی) تعیین می‌کند.
	 *
	 * @return array{0:string,1:int|null} [stock_status, stock_quantity]
	 */
	private function resolve_stock( $v ) {
		$is_unlimited = (bool) $this->array_get( $v, 'is_unlimited', false );
		$is_active    = (bool) $this->array_get( $v, 'is_active', true );
		$stock_num    = $this->array_get( $v, 'stock', 0 );

		if ( $is_unlimited ) {
			return array( $is_active ? 'in-stock' : 'out-of-stock', null );
		}

		$quantity = ( null !== $stock_num && '' !== $stock_num ) ? (int) $stock_num : 0;
		$status   = ( $is_active && $quantity > 0 ) ? 'in-stock' : 'out-of-stock';
		return array( $status, $quantity );
	}

	// =====================================================================
	// توابع کمکی عمومی
	// =====================================================================

	private function clean_text( $text ) {
		$text = wp_strip_all_tags( (string) $text );
		$text = html_entity_decode( $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		return trim( preg_replace( '/\s+/u', ' ', $text ) );
	}

	private function array_get( $array, $key, $default = null ) {
		if ( ! is_array( $array ) || ! array_key_exists( $key, $array ) ) {
			return $default;
		}
		$value = $array[ $key ];
		return ( null === $value ) ? $default : $value;
	}

	private function make_absolute_url( $maybe_url, $base_url ) {
		$maybe_url = trim( (string) $maybe_url );
		if ( '' === $maybe_url ) {
			return '';
		}
		if ( 0 === strpos( $maybe_url, 'http://' ) || 0 === strpos( $maybe_url, 'https://' ) ) {
			return $maybe_url;
		}
		if ( 0 === strpos( $maybe_url, '//' ) ) {
			return 'https:' . $maybe_url;
		}
		if ( 0 === strpos( $maybe_url, '/' ) ) {
			return rtrim( $base_url, '/' ) . $maybe_url;
		}
		return rtrim( $base_url, '/' ) . '/' . $maybe_url;
	}
}
