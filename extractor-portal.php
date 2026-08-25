<?php
/**
 * اکسترکتور واحد محصولات برای تمام فروشگاه‌های ساخته‌شده با پلتفرم Portal (portal.ir)
 *
 * پلتفرم پورتال دو نسل رابط کاربری متفاوت دارد که هر دو در این کلاس پشتیبانی می‌شوند:
 *
 *   ۱) نسل جدید (Next.js / App Router + React Query):
 *      داده‌ی محصول به‌صورت کامل (شامل واریانت‌ها) داخل تگ‌های
 *      <script>self.__next_f.push(...)</script> به‌صورت یک stream فشرده‌ی
 *      React Server Components ارسال می‌شود. این stream را دیکد می‌کنیم، رفرنس‌های
 *      $xx:path را resolve می‌کنیم و سراغ کوئری‌ای می‌رویم که queryKey آن
 *      ["product", <id>] است؛ state.data همان کوئری، آبجکت کامل محصول است.
 *
 *   ۲) نسل کلاسیک (AngularJS SPA روی HTML سرور-رندرشده):
 *      محصول (چه ساده و چه متغیر) همیشه با یک <select id="variant"> و
 *      <option>های آن مدل می‌شود؛ هر option از طریق data-price, data-stock,
 *      data-compare-price, data-image, data-sku اطلاعات کامل آن واریانت را
 *      در خودش دارد. برای محصول ساده، دقیقاً یک option با برچسب عمومی
 *      (مثل «primary») وجود دارد.
 *
 * در هر دو حالت، JSON-LD (در صورت وجود) به‌عنوان منبع کمکی/پشتیبان برای
 * عنوان، تصاویر، توضیحات و دسته‌بندی استفاده می‌شود (هرگز برای قیمت؛ چون در
 * برخی فروشگاه‌های کلاسیک قیمت JSON-LD به ریال و ۱۰ برابر قیمت واقعی درج
 * شده است، درحالی‌که در نسل جدید قیمت JSON-LD مستقیماً به تومان است — این
 * ناهم‌خوانی باعث می‌شود اتکا به آن برای قیمت غیرقابل‌اعتماد باشد).
 *
 * @package ProductImporter
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // دسترسی مستقیم مجاز نیست.
}

require_once __DIR__ . '/class-product-dto.php';

class Portal_Product_Extractor {

	const MENU_SLUG = 'portal-product-extractor';

	public function __construct() {
		add_action( 'admin_menu', array( $this, 'register_admin_page' ) );
	}

	// =====================================================================
	// صفحه‌ی مدیریت (پیش‌نمایش دستی)
	// =====================================================================

	public function register_admin_page() {
		add_submenu_page(
			'edit.php?post_type=source_profile',
			'اکسترکتور پورتال',
			'اکسترکتور پورتال',
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
		echo '<h1>اکسترکتور محصولات پورتال (Portal.ir)</h1>';
		echo '<p>آدرس صفحه‌ی هر محصولی که با پلتفرم پورتال ساخته شده (نسل جدید Next.js یا نسل کلاسیک) را وارد کنید.</p>';

		echo '<form method="post">';
		wp_nonce_field( 'portal_extract_action', 'portal_extract_nonce' );
		echo '<table class="form-table"><tr><th><label for="product_url">آدرس محصول</label></th>';
		echo '<td><input type="url" id="product_url" name="product_url" class="regular-text" required value="' . esc_attr( isset( $_POST['product_url'] ) ? sanitize_text_field( wp_unslash( $_POST['product_url'] ) ) : '' ) . '"></td></tr></table>';
		submit_button( 'استخراج اطلاعات محصول' );
		echo '</form>';

		if (
			'POST' === $_SERVER['REQUEST_METHOD']
			&& isset( $_POST['portal_extract_nonce'] )
			&& wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['portal_extract_nonce'] ) ), 'portal_extract_action' )
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
		$source_label = isset( $data['_debug_source'] ) && 'classic' === $data['_debug_source'] ? 'قالب کلاسیک (AngularJS)' : 'قالب جدید (Next.js)';
		echo '<p><em>روش تشخیص‌داده‌شده: ' . esc_html( $source_label ) . '</em></p>';

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
			'دسته‌بندی‌ها' => implode( '، ', $data['categories'] ),
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

		unset( $data['_debug_source'] );

		if ( class_exists( 'ProductDTO' ) && method_exists( 'ProductDTO', 'normalize' ) ) {
			$normalized = ProductDTO::normalize( $data );
			if ( is_array( $normalized ) ) {
				return $normalized;
			}
		}

		return $data;
	}

	/**
	 * تلاش برای یافتن آدرس محصولات یک فروشگاه پورتال از طریق sitemap.xml.
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
			return new WP_Error( 'portal_no_sitemap', 'آدرس سایتمپ یا آدرس پایه‌ی سایت مشخص نیست.' );
		}

		$urls = $instance->collect_sitemap_urls( $sitemap_url, 0 );

		$deny_patterns = array( '/site/', '/cart', '/checkout', '/login', '/register', '/search', '/blog/', '/category/', '/tag/', '/page/', '.xml', '/wishlist', '/compare', '/user/', '/panel' );
		$filtered      = array();
		foreach ( $urls as $u ) {
			$skip = false;
			foreach ( $deny_patterns as $pattern ) {
				if ( false !== stripos( $u, $pattern ) ) {
					$skip = true;
					break;
				}
			}
			if ( ! $skip ) {
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

		// حالت sitemap index: چند زیرنقشه.
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

		// حالت نقشه‌سایت مستقیم.
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
	// هسته‌ی استخراج: واکشی صفحه و مسیریابی بین دو نسل قالب پورتال
	// =====================================================================

	public function extract_product_data( $url ) {
		if ( empty( $url ) || ! filter_var( $url, FILTER_VALIDATE_URL ) ) {
			return array( 'error' => 'آدرس نامعتبر است.' );
		}

		$response = wp_safe_remote_get(
			$url,
			array(
				'timeout'   => 30,
				'sslverify' => true,
				'limit_response_size' => 6291456,
				'headers'   => array(
					'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0 Safari/537.36',
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return array( 'error' => 'خطا در واکشی صفحه: ' . $response->get_error_message() );
		}

		$status = (int) wp_remote_retrieve_response_code( $response );
		if ( $status < 200 || $status >= 400 ) {
			return array( 'error' => 'کد پاسخ نامعتبر از سرور: ' . $status );
		}

		$html = wp_remote_retrieve_body( $response );
		if ( empty( $html ) ) {
			return array( 'error' => 'محتوای صفحه خالی است.' );
		}

		$parts    = wp_parse_url( $url );
		$base_url = ( isset( $parts['scheme'] ) ? $parts['scheme'] : 'https' ) . '://' . ( isset( $parts['host'] ) ? $parts['host'] : '' );

		libxml_use_internal_errors( true );
		$dom = new DOMDocument();
		$dom->loadHTML( '<?xml encoding="UTF-8">' . $html );
		libxml_clear_errors();
		$xpath = new DOMXPath( $dom );

		list( $jsonld_product, $jsonld_breadcrumb ) = $this->parse_json_ld( $html );

		$api_payload = null;
		try {
			$api_payload = $this->extract_from_portal_api( $html );
		} catch ( \Throwable $e ) {
			$api_payload = null;
		}

		if ( is_array( $api_payload ) && ! empty( $api_payload['product'] ) && is_array( $api_payload['product'] ) ) {
			$normalized                     = $this->normalize_modern_product( $api_payload['product'], $base_url, $xpath, $jsonld_product, $jsonld_breadcrumb );
			$normalized['_debug_source']    = 'modern';
		} else {
			$normalized                  = $this->normalize_classic_product( $xpath, $html, $base_url, $jsonld_product, $jsonld_breadcrumb );
			$normalized['_debug_source'] = 'classic';
		}

		if ( empty( $normalized['title'] ) ) {
			return array( 'error' => 'نتوانستیم عنوان محصول را در این صفحه پیدا کنیم؛ احتمالاً این صفحه، صفحه‌ی محصول نیست.' );
		}

		return $normalized;
	}

	// =====================================================================
	// نسل جدید (Next.js): یافتن و دیکد کردن payload محصول از React Query cache
	// =====================================================================

	/**
	 * تلاش برای پیدا کردن آبجکت { httpStatus, success, product:{...} } که پورتال
	 * برای هیدریت کردن React Query در سمت کلاینت داخل HTML جاسازی می‌کند.
	 *
	 * @return array|null
	 */
	private function extract_from_portal_api( $html ) {
		$chunks = $this->extract_push_chunks( $html );
		if ( empty( $chunks ) ) {
			return null;
		}

		$stream = '';
		foreach ( $chunks as $chunk_json ) {
			$decoded = json_decode( $chunk_json, true );
			if ( is_array( $decoded ) && count( $decoded ) >= 2 && is_string( $decoded[1] ) ) {
				$stream .= $decoded[1];
			}
		}
		if ( '' === $stream ) {
			return null;
		}

		$rows = $this->parse_rsc_rows( $stream );
		if ( empty( $rows ) ) {
			return null;
		}

		$cache = array();
		foreach ( $rows as $row_id => $row ) {
			if ( 'J' !== $row[0] ) {
				continue;
			}
			$raw = $row[1];
			if ( 0 === strpos( $raw, 'I[' ) || 0 === strpos( $raw, 'HL[' ) ) {
				continue;
			}
			$decoded = json_decode( $raw, true );
			if ( null === $decoded ) {
				continue;
			}
			$resolved = $this->resolve_rsc_value( $decoded, $rows, $cache, 0 );
			$found    = $this->find_product_query_data( $resolved, 0 );
			if ( is_array( $found ) ) {
				return $found;
			}
		}

		return null;
	}

	/**
	 * جست‌وجوی بازگشتی و محدود برای یافتن آرایه‌ای شبیه
	 * { state: { queries: [ { queryKey:["product",id], state:{data:{...}} }, ... ] } }
	 * و بازگرداندن state.data کوئری‌ای که queryKey آن با "product" شروع می‌شود.
	 */
	private function find_product_query_data( $value, $depth ) {
		if ( $depth > 14 || ! is_array( $value ) ) {
			return null;
		}

		if ( isset( $value['state'] ) && is_array( $value['state'] ) && isset( $value['state']['queries'] ) && is_array( $value['state']['queries'] ) ) {
			foreach ( $value['state']['queries'] as $query ) {
				if ( ! is_array( $query ) || empty( $query['queryKey'] ) || ! is_array( $query['queryKey'] ) ) {
					continue;
				}
				if ( isset( $query['queryKey'][0] ) && 'product' === $query['queryKey'][0] ) {
					$data = isset( $query['state'] ) && is_array( $query['state'] ) && isset( $query['state']['data'] ) ? $query['state']['data'] : null;
					if ( is_array( $data ) && isset( $data['product'] ) && is_array( $data['product'] ) ) {
						return $data;
					}
				}
			}
		}

		foreach ( $value as $item ) {
			if ( is_array( $item ) ) {
				$found = $this->find_product_query_data( $item, $depth + 1 );
				if ( is_array( $found ) ) {
					return $found;
				}
			}
		}

		return null;
	}

	/**
	 * پیدا کردن تمام تکه‌های self.__next_f.push([...]) در HTML خام و برگرداندن
	 * محتوای داخل پرانتز هر فراخوانی (بدون خود پرانتزها)، با درنظرگرفتن
	 * escape شدن رشته‌ها تا مرز واقعی هر فراخوانی درست تشخیص داده شود.
	 */
	private function extract_push_chunks( $html ) {
		$chunks = array();
		$marker = 'self.__next_f.push(';
		$mlen   = strlen( $marker );
		$n      = strlen( $html );
		$idx    = 0;
		$guard  = 0;

		while ( true ) {
			++$guard;
			if ( $guard > 20000 ) {
				break;
			}
			$start = strpos( $html, $marker, $idx );
			if ( false === $start ) {
				break;
			}
			$arr_start = $start + $mlen;
			$depth     = 0;
			$i         = $arr_start;
			$in_str    = false;
			$str_char  = '';
			$escape    = false;
			$end       = null;

			while ( $i < $n ) {
				$c = $html[ $i ];
				if ( $in_str ) {
					if ( $escape ) {
						$escape = false;
					} elseif ( '\\' === $c ) {
						$escape = true;
					} elseif ( $c === $str_char ) {
						$in_str = false;
					}
				} else {
					if ( '"' === $c || "'" === $c ) {
						$in_str   = true;
						$str_char = $c;
					} elseif ( '(' === $c || '[' === $c ) {
						++$depth;
					} elseif ( ']' === $c ) {
						--$depth;
					} elseif ( ')' === $c ) {
						if ( 0 === $depth ) {
							$end = $i;
							break;
						}
						--$depth;
					}
				}
				++$i;
			}

			if ( null === $end ) {
				break;
			}
			$chunks[] = substr( $html, $arr_start, $end - $arr_start );
			$idx      = $end + 1;
		}

		return $chunks;
	}

	/**
	 * تجزیه‌ی stream متصل‌شده به سطرهای RSC. هر سطر با «شناسه‌ی هگزادسیمال:»
	 * شروع می‌شود. اگر بلافاصله بعد از «:» الگوی T<hex-length>, دیده شود، سطر
	 * از نوع متن خام است و طول آن به‌صورت بایت اعلام شده (چون در PHP رشته‌ها
	 * پیش‌فرض بایتی هستند، این طول مستقیماً با strlen/substr قابل استفاده است).
	 * در غیر این صورت سطر تا اولین خط‌جدید، یک مقدار JSON است.
	 *
	 * @return array<string, array{0:string,1:string}> نگاشت شناسه‌ی سطر به [نوع, محتوا].
	 *         نوع یکی از 'T' (متن) یا 'J' (JSON) است.
	 */
	private function parse_rsc_rows( $stream ) {
		$rows  = array();
		$n     = strlen( $stream );
		$pos   = 0;
		$guard = 0;

		while ( $pos < $n ) {
			++$guard;
			if ( $guard > 200000 ) {
				break;
			}

			if ( ! preg_match( '/\G([0-9a-fA-F]{0,16}):/', $stream, $m, 0, $pos ) ) {
				$next = $this->find_rsc_boundary( $stream, $pos, $pos, 400 );
				if ( null === $next || $next <= $pos ) {
					break;
				}
				$pos = $next;
				continue;
			}

			$row_id        = $m[1];
			$content_start = $pos + strlen( $m[0] );

			if ( preg_match( '/\GT([0-9a-fA-F]+),/', $stream, $m2, 0, $content_start ) ) {
				$declared_len = hexdec( $m2[1] );
				$text_start   = $content_start + strlen( $m2[0] );
				$naive_end    = $text_start + $declared_len;
				$true_end     = $this->find_rsc_boundary( $stream, $text_start, $naive_end, 48 );
				if ( null === $true_end ) {
					$true_end = min( $naive_end, $n );
				}
				$content        = substr( $stream, $text_start, $true_end - $text_start );
				$rows[ $row_id ] = array( 'T', $content );
				$pos             = $true_end;
				if ( $pos < $n && "\n" === $stream[ $pos ] ) {
					++$pos;
				}
			} else {
				$nl = strpos( $stream, "\n", $content_start );
				if ( false === $nl ) {
					$content = substr( $stream, $content_start );
					$pos     = $n;
				} else {
					$content = substr( $stream, $content_start, $nl - $content_start );
					$pos     = $nl + 1;
				}
				$rows[ $row_id ] = array( 'J', $content );
			}
		}

		return $rows;
	}

	/**
	 * از آنجا که طول اعلام‌شده‌ی سطرهای متنی گاهی با مرز واقعی بعدی هم‌خوانی
	 * ندارد (به‌خاطر جزئیات انکودینگ داخلی Next.js)، نزدیک‌ترین نقطه‌ای که با
	 * الگوی شروع یک سطر جدید مطابقت دارد را در بازه‌ای اطراف موقعیت هدف پیدا
	 * می‌کنیم.
	 */
	private function find_rsc_boundary( $stream, $min_pos, $target_pos, $window ) {
		$n          = strlen( $stream );
		$target_pos = min( $target_pos, $n );
		$scan_from  = max( $min_pos, $target_pos - $window );
		$scan_to    = min( $n, $target_pos + $window );
		if ( $scan_to <= $scan_from ) {
			return null;
		}

		$region = substr( $stream, $scan_from, $scan_to - $scan_from );
		if ( ! preg_match_all( '/[0-9a-fA-F]{0,8}:(?:I\[|HL\[|T[0-9a-fA-F]+,|[\[{"]|-?[0-9]|true|false|null)/', $region, $matches, PREG_OFFSET_CAPTURE ) ) {
			return null;
		}

		$best      = null;
		$best_dist = null;
		foreach ( $matches[0] as $match ) {
			$abs_pos = $scan_from + $match[1];
			if ( $abs_pos <= $min_pos ) {
				continue;
			}
			$dist = abs( $abs_pos - $target_pos );
			if ( null === $best_dist || $dist < $best_dist ) {
				$best_dist = $dist;
				$best      = $abs_pos;
			}
		}

		return $best;
	}

	private function decode_rsc_row( $rows, $row_id ) {
		if ( ! isset( $rows[ $row_id ] ) ) {
			return null;
		}
		list( $kind, $raw ) = $rows[ $row_id ];
		if ( 'T' === $kind ) {
			return $raw;
		}
		if ( 0 === strpos( $raw, 'I[' ) || 0 === strpos( $raw, 'HL[' ) ) {
			return null;
		}
		$decoded = json_decode( $raw, true );
		return ( null === $decoded && 'null' !== trim( $raw ) ) ? null : $decoded;
	}

	/**
	 * رفع ارجاع‌های به‌شکل "$<hex-id>[:مسیر:مسیر...]" که در فرمت RSC برای
	 * اشاره به مقدار سطرهای دیگر (یا زیرمسیری از props یک المان React) به‌کار
	 * می‌روند.
	 */
	private function resolve_rsc_value( $value, $rows, &$cache, $depth = 0 ) {
		if ( $depth > 60 ) {
			return $value;
		}

		if ( is_string( $value ) && preg_match( '/^\$([0-9a-fA-F]+)((?::[^:]*)*)$/', $value, $m ) ) {
			$row_id    = $m[1];
			$path_str  = isset( $m[2] ) ? $m[2] : '';
			if ( ! isset( $rows[ $row_id ] ) ) {
				return $value;
			}
			if ( array_key_exists( $row_id, $cache ) ) {
				$target = $cache[ $row_id ];
			} else {
				$cache[ $row_id ] = null;
				$target           = $this->decode_rsc_row( $rows, $row_id );
				$target           = $this->resolve_rsc_value( $target, $rows, $cache, $depth + 1 );
				$cache[ $row_id ] = $target;
			}

			if ( '' !== $path_str ) {
				$segments = array_values( array_filter( explode( ':', $path_str ), function ( $s ) {
					return '' !== $s;
				} ) );
				$cur = $target;
				foreach ( $segments as $seg ) {
					if ( is_array( $cur ) && isset( $cur[0] ) ) {
						if ( 'props' === $seg && count( $cur ) >= 4 && isset( $cur[0] ) && '$' === $cur[0] && array_key_exists( 3, $cur ) ) {
							$cur = $cur[3];
							continue;
						}
						if ( ctype_digit( $seg ) && array_key_exists( (int) $seg, $cur ) ) {
							$cur = $cur[ (int) $seg ];
							continue;
						}
						return $value;
					} elseif ( is_array( $cur ) ) {
						if ( 'props' === $seg && array_key_exists( 'props', $cur ) ) {
							$cur = $cur['props'];
							continue;
						}
						if ( array_key_exists( $seg, $cur ) ) {
							$cur = $cur[ $seg ];
							continue;
						}
						return $value;
					} else {
						return $value;
					}
				}
				return $this->resolve_rsc_value( $cur, $rows, $cache, $depth + 1 );
			}

			return $target;
		}

		if ( is_array( $value ) ) {
			$out = array();
			foreach ( $value as $k => $v ) {
				$out[ $k ] = $this->resolve_rsc_value( $v, $rows, $cache, $depth + 1 );
			}
			return $out;
		}

		return $value;
	}

	// =====================================================================
	// نگاشت داده‌ی نسل جدید (Next.js) به ساختار مشترک
	// =====================================================================

	private function normalize_modern_product( $product, $base_url, $xpath, $jsonld_product, $jsonld_breadcrumb ) {
		$product_id = (string) $this->array_get( $product, 'productID', '' );

		$title = $this->clean_text( $this->array_get( $product, 'title', '' ) );
		if ( '' === $title && is_array( $jsonld_product ) ) {
			$title = $this->clean_text( $this->array_get( $jsonld_product, 'name', '' ) );
		}
		if ( '' === $title ) {
			$title = $this->extract_h1_title( $xpath );
		}

		$description = (string) $this->array_get( $product, 'description', '' );
		$contents    = $this->array_get( $product, 'contents', array() );
		$content     = '';
		if ( is_array( $contents ) && ! empty( $contents ) && is_array( $contents[0] ) ) {
			$content = (string) $this->array_get( $contents[0], 'value', '' );
		}
		if ( '' === trim( wp_strip_all_tags( $content ) ) ) {
			if ( '' !== $description ) {
				$content = ( false !== strpos( $description, '<' ) ) ? $description : ( '<p>' . esc_html( $description ) . '</p>' );
			}
		}
		$excerpt = $this->clean_text( $description );
		if ( '' === $excerpt && '' !== $content ) {
			$excerpt = wp_trim_words( wp_strip_all_tags( $content ), 60, '...' );
		}

		// --- تصاویر ---
		$gallery_urls = array();
		$images_raw   = $this->array_get( $product, 'images', array() );
		if ( is_array( $images_raw ) ) {
			foreach ( $images_raw as $img ) {
				$path = is_array( $img ) ? $this->array_get( $img, 'path', '' ) : '';
				if ( '' !== $path ) {
					$gallery_urls[] = $this->make_absolute_url( $path, $base_url );
				}
			}
		}
		if ( empty( $gallery_urls ) ) {
			$single = $this->array_get( $product, 'image', '' );
			if ( '' !== $single ) {
				$gallery_urls[] = $this->make_absolute_url( $single, $base_url );
			}
		}
		if ( empty( $gallery_urls ) && is_array( $jsonld_product ) ) {
			$jl_images = $this->array_get( $jsonld_product, 'image', array() );
			if ( is_string( $jl_images ) ) {
				$jl_images = array( $jl_images );
			}
			if ( is_array( $jl_images ) ) {
				foreach ( $jl_images as $jli ) {
					if ( is_string( $jli ) && '' !== $jli ) {
						$gallery_urls[] = $jli;
					}
				}
			}
		}
		$gallery_urls   = array_values( array_unique( array_filter( $gallery_urls ) ) );
		$featured_image = ! empty( $gallery_urls ) ? $gallery_urls[0] : '';
		$gallery_images = count( $gallery_urls ) > 1 ? array_slice( $gallery_urls, 1 ) : array();

		// --- دسته‌بندی ---
		$categories = array();
		$cats_raw   = $this->array_get( $product, 'categories', array() );
		if ( is_array( $cats_raw ) ) {
			foreach ( $cats_raw as $c ) {
				$t = is_array( $c ) ? $this->array_get( $c, 'title', '' ) : '';
				if ( '' !== $t ) {
					$categories[] = $t;
				}
			}
		}
		if ( empty( $categories ) && is_array( $jsonld_breadcrumb ) ) {
			$categories = $jsonld_breadcrumb;
		}
		$categories = array_values( array_unique( array_filter( $categories ) ) );

		// --- ویژگی‌های ثابت (مشخصات فنی) + برند ---
		$extra_attributes = array();
		$attrs_raw         = $this->array_get( $product, 'attributes', array() );
		if ( is_array( $attrs_raw ) ) {
			foreach ( $attrs_raw as $a ) {
				if ( ! is_array( $a ) ) {
					continue;
				}
				$val    = $this->array_get( $a, 'title', '' );
				$parent = $this->array_get( $a, 'parent', array() );
				$name   = is_array( $parent ) ? $this->array_get( $parent, 'title', '' ) : '';
				$name   = rtrim( trim( (string) $name ), " :" );
				if ( '' !== $name && '' !== $val ) {
					$extra_attributes[] = array(
						'name'                => $name,
						'values'              => array( $val ),
						'used_for_variations' => false,
					);
				}
			}
		}
		$brand      = $this->array_get( $product, 'brand', array() );
		$brand_name = is_array( $brand ) ? (string) $this->array_get( $brand, 'title', '' ) : '';
		if ( '' !== $brand_name ) {
			$extra_attributes[] = array(
				'name'                => 'برند',
				'values'              => array( $brand_name ),
				'used_for_variations' => false,
			);
		}

		// --- واریانت‌ها ---
		$variants_raw = $this->array_get( $product, 'variants', array() );
		$variant_list = array();
		if ( is_array( $variants_raw ) ) {
			foreach ( $variants_raw as $v ) {
				if ( ! is_array( $v ) ) {
					continue;
				}
				$v_image = $this->array_get( $v, 'image', '' );
				$variant_list[] = array(
					'id'            => (string) $this->array_get( $v, 'variantID', '' ),
					'label'         => (string) $this->array_get( $v, 'title', '' ),
					'price'         => $this->array_get( $v, 'price', 0 ),
					'compare_price' => $this->array_get( $v, 'comparePrice', null ),
					'stock'         => $this->array_get( $v, 'stock', null ),
					'tracking'      => $this->array_get( $v, 'tracking', true ),
					'image'         => '' !== $v_image ? $this->make_absolute_url( $v_image, $base_url ) : '',
					'sku'           => '',
				);
			}
		}
		if ( empty( $variant_list ) ) {
			$variant_list[] = array(
				'id'            => $product_id,
				'label'         => '',
				'price'         => $this->array_get( $product, 'price', 0 ),
				'compare_price' => $this->array_get( $product, 'comparePrice', null ),
				'stock'         => $this->array_get( $product, 'stock', null ),
				'tracking'      => true,
				'image'         => '',
				'sku'           => '',
			);
		}

		$built = $this->build_attributes_and_variations( $variant_list, $extra_attributes );

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
			'meta_title'     => $this->get_meta_property( $xpath, 'og:title' ) ?: $title,
			'canonical'      => $this->get_link_href( $xpath, 'canonical' ),
		);
	}

	// =====================================================================
	// نگاشت داده‌ی نسل کلاسیک (AngularJS) به ساختار مشترک
	// =====================================================================

	private function normalize_classic_product( $xpath, $html, $base_url, $jsonld_product, $jsonld_breadcrumb ) {
		$title = $this->extract_h1_title( $xpath, 'product-title' );
		if ( '' === $title && is_array( $jsonld_product ) ) {
			$title = $this->clean_text( $this->array_get( $jsonld_product, 'name', '' ) );
		}
		if ( '' === $title ) {
			$title = $this->clean_text( $this->get_meta_property( $xpath, 'og:title' ) );
		}

		$product_id = '';
		if ( preg_match( '/compare\/add\?id=(\d+)/', $html, $m ) ) {
			$product_id = $m[1];
		}

		// --- گزینه‌های واریانت (این پلتفرم حتی محصول ساده را هم با یک option مدل می‌کند) ---
		$option_nodes = $xpath->query( "//select[@id='variant']/option" );
		$variant_list = array();
		if ( $option_nodes && $option_nodes->length > 0 ) {
			foreach ( $option_nodes as $opt ) {
				$label       = $this->clean_text( $opt->textContent );
				$has_stock   = $opt->hasAttribute( 'data-stock' ) && '' !== trim( $opt->getAttribute( 'data-stock' ) );
				$variant_list[] = array(
					'id'            => $opt->getAttribute( 'value' ),
					'label'         => $label,
					'price'         => $opt->hasAttribute( 'data-price' ) ? $opt->getAttribute( 'data-price' ) : 0,
					'compare_price' => $opt->hasAttribute( 'data-compare-price' ) ? $opt->getAttribute( 'data-compare-price' ) : null,
					'stock'         => $has_stock ? $opt->getAttribute( 'data-stock' ) : null,
					'tracking'      => $has_stock,
					'image'         => $opt->hasAttribute( 'data-image' ) && '' !== $opt->getAttribute( 'data-image' ) ? $this->make_absolute_url( $opt->getAttribute( 'data-image' ), $base_url ) : '',
					'sku'           => $opt->hasAttribute( 'data-sku' ) ? trim( $opt->getAttribute( 'data-sku' ) ) : '',
				);
			}
		}
		if ( empty( $variant_list ) ) {
			$variant_list[] = array(
				'id'            => $product_id,
				'label'         => '',
				'price'         => 0,
				'compare_price' => null,
				'stock'         => null,
				'tracking'      => false,
				'image'         => '',
				'sku'           => '',
			);
		}

		$sku = '';
		foreach ( $variant_list as $v ) {
			if ( ! empty( $v['sku'] ) ) {
				$sku = $v['sku'];
				break;
			}
		}
		if ( '' === $sku ) {
			$sku = $product_id;
		}

		$built = $this->build_attributes_and_variations( $variant_list, array() );

		// --- توضیحات ---
		// برخی نسخه‌های تم کلاسیک، «توضیحات کامل» را در یک تب جداگانه با
		// فرمت‌بندی غنی (تیتر، تصویر و...) نمایش می‌دهند که با itemprop='description'
		// (که معمولاً فقط یک خلاصه‌ی کوتاه است) یکی نیست؛ آن را در اولویت اول قرار می‌دهیم.
		$content       = '';
		$rich_content_nodes = $xpath->query( "//*[contains(concat(' ', normalize-space(@class), ' '), ' product-content ')]" );
		if ( $rich_content_nodes && $rich_content_nodes->length > 0 ) {
			$content = trim( $this->inner_html( $rich_content_nodes->item( 0 ) ) );
		}
		$desc_nodes = $xpath->query( "//*[@itemprop='description']" );
		if ( '' === trim( wp_strip_all_tags( $content ) ) && $desc_nodes && $desc_nodes->length > 0 ) {
			$content = trim( $this->inner_html( $desc_nodes->item( 0 ) ) );
		}
		if ( '' === trim( wp_strip_all_tags( $content ) ) && is_array( $jsonld_product ) ) {
			$d = $this->array_get( $jsonld_product, 'description', '' );
			if ( '' !== $d ) {
				$content = '<p>' . esc_html( $d ) . '</p>';
			}
		}

		$excerpt = $this->clean_text( $this->get_meta_content( $xpath, 'description' ) );
		if ( '' === $excerpt && $desc_nodes && $desc_nodes->length > 0 ) {
			$excerpt = $this->clean_text( $desc_nodes->item( 0 )->textContent );
		}
		if ( '' === $excerpt && '' !== $content ) {
			$excerpt = wp_trim_words( wp_strip_all_tags( $content ), 60, '...' );
		}

		// --- تصاویر ---
		$gallery_urls = array();
		if ( is_array( $jsonld_product ) ) {
			$jl_images = $this->array_get( $jsonld_product, 'image', array() );
			if ( is_string( $jl_images ) ) {
				$jl_images = array( $jl_images );
			}
			if ( is_array( $jl_images ) ) {
				foreach ( $jl_images as $jli ) {
					if ( is_string( $jli ) && '' !== $jli ) {
						$gallery_urls[] = $jli;
					}
				}
			}
		}
		if ( empty( $gallery_urls ) ) {
			foreach ( $variant_list as $v ) {
				if ( ! empty( $v['image'] ) ) {
					$gallery_urls[] = $v['image'];
				}
			}
		}
		if ( empty( $gallery_urls ) ) {
			$og_img = $this->get_meta_property( $xpath, 'og:image' );
			if ( '' !== $og_img ) {
				$gallery_urls[] = $this->make_absolute_url( $og_img, $base_url );
			}
		}
		if ( empty( $gallery_urls ) ) {
			// آخرین راه: تصاویر داخل اسلایدر گالری محصول.
			$img_nodes = $xpath->query( "//*[contains(@class,'product-images')]//img" );
			if ( $img_nodes ) {
				foreach ( $img_nodes as $img_node ) {
					$src = $img_node->getAttribute( 'src' );
					if ( '' !== $src ) {
						$gallery_urls[] = $this->make_absolute_url( preg_replace( '/\?.*$/', '', $src ), $base_url );
					}
				}
			}
		}
		$gallery_urls   = array_values( array_unique( array_filter( $gallery_urls ) ) );
		$featured_image = ! empty( $gallery_urls ) ? $gallery_urls[0] : '';
		$gallery_images = count( $gallery_urls ) > 1 ? array_slice( $gallery_urls, 1 ) : array();

		// --- دسته‌بندی از breadcrumb ---
		$categories = array();
		$bc_nodes   = $xpath->query( "//*[contains(concat(' ', normalize-space(@class), ' '), ' breadcrumb ')]//*[@itemprop='name']" );
		if ( $bc_nodes ) {
			$i = 0;
			foreach ( $bc_nodes as $node ) {
				if ( 0 === $i ) {
					++$i;
					continue; // آیتم اول معمولاً «خانه» است.
				}
				$t = $this->clean_text( $node->textContent );
				if ( '' !== $t ) {
					$categories[] = $t;
				}
				++$i;
			}
		}
		if ( empty( $categories ) && is_array( $jsonld_breadcrumb ) ) {
			$categories = $jsonld_breadcrumb;
			// اولین آیتم لیست محصولات/خانه است، در صورت وجود بیش از یک آیتم حذفش می‌کنیم.
			if ( count( $categories ) > 1 ) {
				array_shift( $categories );
			}
		}
		$categories = array_values( array_unique( array_filter( $categories ) ) );

		// --- مشخصات فنی (در صورت وجود جدول/لیست مشخصات) ---
		$extra_attributes = $this->extract_classic_specs( $xpath );

		return array(
			'product_id'     => $product_id,
			'sku'            => $sku,
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
			'attributes'     => array_merge( $extra_attributes, $built['attributes'] ),
			'variations'     => $built['variations'],
			'meta_title'     => $this->get_meta_content( $xpath, 'title' ) ?: $this->get_meta_property( $xpath, 'og:title' ),
			'canonical'      => $this->get_link_href( $xpath, 'canonical' ),
		);
	}

	/**
	 * یافتن مشخصات فنی (ویژگی‌های غیرمرتبط با واریانت) محصول در قالب کلاسیک.
	 * این بخش، بسته به تم بصری فروشگاه، به یکی از دو شکل زیر رندر می‌شود
	 * (هر دو زیر یک والد با کلاس product-fields قرار دارند):
	 *
	 *   نوع «آ» (مثل zamanigallery.com): هر ویژگی یک آیتم جداگانه با نام و
	 *   مقدار مشخص است:
	 *     <div class="product-fields-item">
	 *       <div class="product-fields-item-name">جعبه</div>
	 *       <div class="product-fields-item-value">یه جعبه هدیه دارد</div>
	 *     </div>
	 *
	 *   نوع «ب» (مثل sepandaasa.com): یک عنوان کلی (اغلب همان «ویژگی ها»)
	 *   دارد و چند مقدار متنی آزاد به‌شکل «نام مقدار» (بدون جداکننده‌ی
	 *   مشخص، فقط یک فاصله) پشت‌سرهم می‌آیند:
	 *     <div class="product-field">
	 *       <h6 class="product-field-name">ویژگی ها</h6>
	 *       <div class="product-field-value">ابعاد 20*10*10</div>
	 *       <div class="product-field-value">وزن 500 گرم</div>
	 *       ...
	 *     </div>
	 */
	private function extract_classic_specs( $xpath ) {
		$attributes = array();

		// نوع «آ»: نام و مقدار برای هر ویژگی جداگانه مشخص شده.
		$item_nodes = $xpath->query( "//*[contains(concat(' ', normalize-space(@class), ' '), ' product-fields-item ')]" );
		if ( $item_nodes && $item_nodes->length > 0 ) {
			foreach ( $item_nodes as $item ) {
				$name_nodes  = $xpath->query( ".//*[contains(concat(' ', normalize-space(@class), ' '), ' product-fields-item-name ')]", $item );
				$value_nodes = $xpath->query( ".//*[contains(concat(' ', normalize-space(@class), ' '), ' product-fields-item-value ')]", $item );
				$name  = $name_nodes && $name_nodes->length > 0 ? $this->clean_text( $name_nodes->item( 0 )->textContent ) : '';
				$value = $value_nodes && $value_nodes->length > 0 ? $this->clean_text( $value_nodes->item( 0 )->textContent ) : '';
				if ( '' !== $name && '' !== $value ) {
					$attributes[] = array(
						'name'                => $name,
						'values'              => array( $value ),
						'used_for_variations' => false,
					);
				}
			}
			if ( ! empty( $attributes ) ) {
				return $attributes;
			}
		}

		// نوع «ب»: یک عنوان کلی + چند مقدار متنی آزاد که باید نام/مقدار هرکدام را جدا کنیم.
		$field_nodes      = $xpath->query( "//*[contains(concat(' ', normalize-space(@class), ' '), ' product-field ')]" );
		$generic_headings = array( 'ویژگی ها', 'ویژگی‌ها', 'مشخصات', 'مشخصات فنی' );
		if ( $field_nodes ) {
			foreach ( $field_nodes as $field ) {
				$name_nodes  = $xpath->query( ".//*[contains(concat(' ', normalize-space(@class), ' '), ' product-field-name ')]", $field );
				$value_nodes = $xpath->query( ".//*[contains(concat(' ', normalize-space(@class), ' '), ' product-field-value ')]", $field );
				if ( ! $value_nodes || 0 === $value_nodes->length ) {
					continue;
				}
				$heading = $name_nodes && $name_nodes->length > 0 ? $this->clean_text( $name_nodes->item( 0 )->textContent ) : '';

				if ( '' !== $heading && ! in_array( $heading, $generic_headings, true ) && 1 === $value_nodes->length ) {
					$value = $this->clean_text( $value_nodes->item( 0 )->textContent );
					if ( '' !== $value ) {
						$attributes[] = array(
							'name'                => $heading,
							'values'              => array( $value ),
							'used_for_variations' => false,
						);
					}
					continue;
				}

				foreach ( $value_nodes as $vn ) {
					$text = $this->clean_text( $vn->textContent );
					if ( '' === $text ) {
						continue;
					}
					$space_pos = strpos( $text, ' ' );
					if ( false !== $space_pos ) {
						$name  = trim( substr( $text, 0, $space_pos ) );
						$value = trim( substr( $text, $space_pos + 1 ) );
					} else {
						$name  = $text;
						$value = '';
					}
					if ( '' !== $name && '' !== $value ) {
						$attributes[] = array(
							'name'                => $name,
							'values'              => array( $value ),
							'used_for_variations' => false,
						);
					}
				}
			}
		}

		return $attributes;
	}

	// =====================================================================
	// منطق مشترک: تبدیل لیست نرمال‌شده‌ی واریانت‌ها به attributes/variations
	// =====================================================================

	/**
	 * ورودی مشترک بین هر دو نسل قالب: آرایه‌ای از واریانت‌ها با کلیدهای
	 * id, label, price, compare_price, stock, tracking, image, sku.
	 * اگر فقط یک واریانت با برچسب خالی/عمومی وجود داشته باشد، محصول «ساده»
	 * در نظر گرفته می‌شود؛ در غیر این صورت «متغیر».
	 */
	private function build_attributes_and_variations( $variant_list, $extra_attributes ) {
		$variant_list = array_values( array_filter( $variant_list, 'is_array' ) );
		$count        = count( $variant_list );

		if ( $count <= 1 ) {
			$v = $count === 1 ? $variant_list[0] : array();
			list( $regular, $sale ) = $this->split_price( $this->array_get( $v, 'price', 0 ), $this->array_get( $v, 'compare_price', null ) );
			$stock_num = $this->array_get( $v, 'stock', null );
			$tracking  = (bool) $this->array_get( $v, 'tracking', true );

			if ( ! $tracking ) {
				$stock_status   = 'in-stock';
				$stock_quantity = null;
			} else {
				$stock_quantity = ( null !== $stock_num && '' !== $stock_num ) ? (int) $stock_num : 0;
				$stock_status   = $stock_quantity > 0 ? 'in-stock' : 'out-of-stock';
			}

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

		foreach ( $variant_list as $v ) {
			$label = trim( (string) $this->array_get( $v, 'label', '' ) );
			$pairs = $this->parse_variant_label( $label );

			foreach ( $pairs as $name => $val ) {
				if ( ! isset( $attr_map[ $name ] ) ) {
					$attr_map[ $name ] = array();
				}
				if ( ! in_array( $val, $attr_map[ $name ], true ) ) {
					$attr_map[ $name ][] = $val;
				}
			}

			list( $regular, $sale ) = $this->split_price( $this->array_get( $v, 'price', 0 ), $this->array_get( $v, 'compare_price', null ) );
			$stock_num = $this->array_get( $v, 'stock', null );
			$tracking  = (bool) $this->array_get( $v, 'tracking', true );

			if ( ! $tracking ) {
				$v_stock_status = 'in-stock';
				$v_stock_qty    = null;
			} else {
				$v_stock_qty    = ( null !== $stock_num && '' !== $stock_num ) ? (int) $stock_num : 0;
				$v_stock_status = $v_stock_qty > 0 ? 'in-stock' : 'out-of-stock';
			}

			$summary = '' !== $label ? $label : implode( '، ', array_map(
				function ( $n, $vv ) {
					return $n . ': ' . $vv;
				},
				array_keys( $pairs ),
				array_values( $pairs )
			) );

			$variations[] = array(
				'attributes_summary' => $summary,
				'attributes_map'     => $pairs,
				'sku'                => (string) ( $this->array_get( $v, 'sku', '' ) ?: $this->array_get( $v, 'id', '' ) ),
				'regular_price'      => $regular,
				'sale_price'         => $sale,
				'stock_status'       => $v_stock_status,
				'stock_quantity'     => $v_stock_qty,
				'image'              => (string) $this->array_get( $v, 'image', '' ),
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
	 * از روی قیمت جاری و قیمت مقایسه‌ای (در صورت وجود)، قیمت اصلی و قیمت
	 * تخفیف‌خورده را استخراج می‌کند. اگر قیمت مقایسه‌ای بزرگ‌تر از قیمت جاری
	 * باشد یعنی محصول تخفیف دارد.
	 *
	 * @return array{0:int,1:int|null} [قیمت اصلی، قیمت با تخفیف یا null]
	 */
	private function split_price( $price, $compare_price ) {
		$price   = (int) $price;
		$compare = ( null !== $compare_price && '' !== $compare_price ) ? (int) $compare_price : 0;

		if ( $compare > $price && $price > 0 ) {
			return array( $compare, $price );
		}
		if ( $compare > 0 && 0 === $price ) {
			return array( $compare, null );
		}
		return array( $price, null );
	}

	/**
	 * برچسب یک واریانت (مثل «رنگ: قرمز» یا حتی چند جفت پشت‌سرهم) را به نگاشت
	 * نام‌ویژگی => مقدار تبدیل می‌کند. برچسب‌های عمومی (placeholder) مثل
	 * «primary» که فقط برای محصول ساده استفاده می‌شوند، نادیده گرفته می‌شوند.
	 *
	 * @return array<string,string>
	 */
	private function parse_variant_label( $label ) {
		$label = trim( (string) $label );
		if ( '' === $label ) {
			return array();
		}

		$placeholders = array( 'primary', 'default', 'پیش فرض', 'پیش‌فرض' );
		if ( in_array( mb_strtolower( $label ), $placeholders, true ) ) {
			return array();
		}

		$segments = preg_split( '/\s*[،,|]\s*|\r?\n/u', $label );
		$pairs    = array();
		if ( is_array( $segments ) ) {
			foreach ( $segments as $seg ) {
				$seg = trim( $seg );
				if ( '' === $seg ) {
					continue;
				}
				$colon_pos = strpos( $seg, ':' );
				if ( false !== $colon_pos ) {
					$name  = trim( substr( $seg, 0, $colon_pos ) );
					$value = trim( substr( $seg, $colon_pos + 1 ) );
					if ( '' !== $name && '' !== $value ) {
						$pairs[ $name ] = $value;
					}
				} else {
					$pairs['ویژگی'] = $seg;
				}
			}
		}

		return $pairs;
	}

	// =====================================================================
	// JSON-LD (منبع کمکی، مشترک بین هر دو نسل)
	// =====================================================================

	/**
	 * @return array{0: array|null, 1: array} [آبجکت Product در صورت وجود، لیست نام‌های BreadcrumbList]
	 */
	private function parse_json_ld( $html ) {
		$product    = null;
		$breadcrumb = array();

		if ( preg_match_all( '/<script[^>]*type=["\']application\/ld\+json["\'][^>]*>(.*?)<\/script>/is', $html, $matches ) ) {
			foreach ( $matches[1] as $json ) {
				$data = json_decode( trim( $json ), true );
				if ( ! is_array( $data ) ) {
					continue;
				}
				$type = isset( $data['@type'] ) ? $data['@type'] : '';
				if ( is_array( $type ) ) {
					$type = isset( $type[0] ) ? $type[0] : '';
				}
				if ( 'Product' === $type && null === $product ) {
					$product = $data;
				} elseif ( 'BreadcrumbList' === $type && empty( $breadcrumb ) ) {
					$items = isset( $data['itemListElement'] ) && is_array( $data['itemListElement'] ) ? $data['itemListElement'] : array();
					foreach ( $items as $item ) {
						if ( is_array( $item ) && ! empty( $item['name'] ) ) {
							$breadcrumb[] = $item['name'];
						}
					}
				}
			}
		}

		return array( $product, $breadcrumb );
	}

	// =====================================================================
	// توابع کمکی عمومی
	// =====================================================================

	private function extract_h1_title( $xpath, $preferred_class = '' ) {
		if ( '' !== $preferred_class ) {
			$nodes = $xpath->query( "//h1[contains(@class,'" . $preferred_class . "')]" );
			if ( $nodes && $nodes->length > 0 ) {
				$t = $this->clean_text( $nodes->item( 0 )->textContent );
				if ( '' !== $t ) {
					return $t;
				}
			}
		}
		$nodes = $xpath->query( '//h1' );
		if ( $nodes && $nodes->length > 0 ) {
			$t = $this->clean_text( $nodes->item( 0 )->textContent );
			if ( '' !== $t ) {
				return $t;
			}
		}
		return '';
	}

	private function get_meta_content( $xpath, $name ) {
		$nodes = $xpath->query( "//meta[@name='" . $name . "']" );
		if ( $nodes && $nodes->length > 0 ) {
			return trim( $nodes->item( 0 )->getAttribute( 'content' ) );
		}
		if ( 'title' === $name ) {
			$nodes = $xpath->query( '//title' );
			if ( $nodes && $nodes->length > 0 ) {
				return trim( $nodes->item( 0 )->textContent );
			}
		}
		return '';
	}

	private function get_meta_property( $xpath, $property ) {
		$nodes = $xpath->query( "//meta[@property='" . $property . "']" );
		if ( $nodes && $nodes->length > 0 ) {
			return trim( $nodes->item( 0 )->getAttribute( 'content' ) );
		}
		return '';
	}

	private function get_link_href( $xpath, $rel ) {
		$nodes = $xpath->query( "//link[@rel='" . $rel . "']" );
		if ( $nodes && $nodes->length > 0 ) {
			return trim( $nodes->item( 0 )->getAttribute( 'href' ) );
		}
		return '';
	}

	private function inner_html( $node ) {
		$html = '';
		if ( ! $node || ! $node->childNodes ) {
			return $html;
		}
		foreach ( $node->childNodes as $child ) {
			$html .= $node->ownerDocument->saveHTML( $child );
		}
		return $html;
	}

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
