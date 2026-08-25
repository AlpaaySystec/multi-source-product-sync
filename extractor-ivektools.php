<?php
/**
 * Dedicated simple-product extractor for ivektools.com.
 *
 * Ivek is a catalogue-only site. Import-safe price and stock defaults are
 * deliberately fixed according to the source-profile workflow requirements.
 *
 * @package MultiSourceProductSync
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$ivektools_dto_file = __DIR__ . '/class-product-dto.php';
if ( file_exists( $ivektools_dto_file ) ) {
	require_once $ivektools_dto_file;
}

class IvekTools_Product_Extractor {

	const MENU_SLUG          = 'ivektools-extractor';
	const IMPORT_PRICE       = 1000000000;
	const DEFAULT_CURRENCY   = 'تومان';
	const DEFAULT_SITEMAP_URL = 'https://ivektools.com/product-sitemap.xml';

	public function __construct() {
		if ( function_exists( 'add_action' ) ) {
			add_action( 'admin_menu', array( $this, 'register_admin_page' ) );
		}
	}

	public function register_admin_page() {
		add_submenu_page(
			'edit.php?post_type=source_profile',
			'اکسترکتور ایوک تولز',
			'اکسترکتور ایوک تولز',
			'manage_options',
			self::MENU_SLUG,
			array( $this, 'render_admin_page' )
		);
	}

	public function render_admin_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'دسترسی غیرمجاز.' );
		}

		$url = isset( $_POST['product_url'] ) ? esc_url_raw( wp_unslash( $_POST['product_url'] ) ) : '';
		?>
		<div class="wrap" dir="rtl">
			<h1>اکسترکتور محصولات ایوک تولز</h1>
			<form method="post">
				<?php wp_nonce_field( 'ivektools_extract_action', 'ivektools_extract_nonce' ); ?>
				<input type="url" class="regular-text" name="product_url" required placeholder="https://ivektools.com/products/..." value="<?php echo esc_attr( $url ); ?>">
				<?php submit_button( 'استخراج اطلاعات محصول' ); ?>
			</form>
			<?php
			if (
				'POST' === ( isset( $_SERVER['REQUEST_METHOD'] ) ? $_SERVER['REQUEST_METHOD'] : '' ) &&
				isset( $_POST['ivektools_extract_nonce'] ) &&
				wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['ivektools_extract_nonce'] ) ), 'ivektools_extract_action' )
			) {
				$data = self::extract( $url );
				if ( ! is_array( $data ) ) {
					echo '<div class="notice notice-error"><p>استخراج اطلاعات محصول انجام نشد.</p></div>';
				} else {
					$this->display_product_data( $data );
					echo '<h2 class="jawaheran-json-title">خروجی JSON متد extract()</h2>';
					echo '<pre class="jawaheran-json-output">' . esc_html( wp_json_encode( $data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT ) ) . '</pre>';
				}
			}
			?>
		</div>
		<?php
	}

	/**
	 * Sync-engine entry point.
	 *
	 * @param string $url Product URL.
	 * @return array|false
	 */
	public static function extract( $url ) {
		$instance = new self();
		$data     = $instance->extract_product_data( $url );
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
	 * Discover Ivek product URLs through a WordPress sitemap.
	 *
	 * @param array $profile Source profile.
	 * @return array|WP_Error
	 */
	public static function get_product_urls( $profile ) {
		$instance = new self();
		$explicit = ! empty( $profile['sitemap_url'] );
		$sitemaps = $explicit
			? array( $profile['sitemap_url'] )
			: array(
				self::DEFAULT_SITEMAP_URL,
				'https://ivektools.com/sitemap_index.xml',
				'https://ivektools.com/wp-sitemap-posts-product-1.xml',
				'https://ivektools.com/wp-sitemap.xml',
			);
		$urls      = array();
		$last_error = null;
		foreach ( $sitemaps as $sitemap ) {
			if ( ! self::is_allowed_url( $sitemap ) ) {
				return new WP_Error( 'ivektools_invalid_sitemap', 'آدرس سایت‌مپ باید متعلق به ivektools.com باشد.' );
			}
			$found = $instance->read_sitemap( $sitemap, 0 );
			if ( is_wp_error( $found ) ) {
				$last_error = $found;
				if ( $explicit ) {
					return $found;
				}
				continue;
			}
			$urls = array_merge( $urls, $found );
			if ( ! empty( array_filter( $found, array( __CLASS__, 'is_allowed_product_url' ) ) ) ) {
				break;
			}
		}
		if ( empty( $urls ) && $last_error ) {
			return $last_error;
		}

		return array_values(
			array_unique(
				array_filter(
					$urls,
					function ( $url ) {
						return self::is_allowed_product_url( $url );
					}
				)
			)
		);
	}

	public function extract_product_data( $url ) {
		if ( ! self::is_allowed_product_url( $url ) ) {
			return array( 'error' => 'آدرس باید یک صفحه محصول از دامنه ivektools.com و مسیر products باشد.' );
		}

		$response = wp_remote_get(
			$url,
			array(
				'timeout'     => 45,
				'redirection' => 5,
				'headers'     => array(
					'user-agent'      => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/121 Safari/537.36',
					'accept-language' => 'fa-IR,fa;q=0.9,en;q=0.7',
				),
			)
		);
		if ( is_wp_error( $response ) ) {
			return array( 'error' => 'خطا در دریافت صفحه: ' . $response->get_error_message() );
		}

		$status = (int) wp_remote_retrieve_response_code( $response );
		if ( 200 !== $status ) {
			return array( 'error' => 'پاسخ نامعتبر از ایوک تولز (HTTP ' . $status . ').' );
		}

		$html = wp_remote_retrieve_body( $response );
		if ( '' === trim( $html ) ) {
			return array( 'error' => 'محتوای صفحه خالی است.' );
		}

		return $this->parse_product_html( $html, $url );
	}

	/**
	 * Deterministic HTML parser boundary.
	 *
	 * @param string $html Product HTML.
	 * @param string $url  Product URL.
	 * @return array
	 */
	public function parse_product_html( $html, $url ) {
		if ( ! self::is_allowed_product_url( $url ) ) {
			return array( 'error' => 'آدرس باید یک صفحه محصول از دامنه ivektools.com و مسیر products باشد.' );
		}

		$previous = libxml_use_internal_errors( true );
		$dom      = new DOMDocument();
		$loaded   = $dom->loadHTML( '<?xml encoding="UTF-8">' . $html, LIBXML_NONET | LIBXML_COMPACT );
		libxml_clear_errors();
		libxml_use_internal_errors( $previous );
		if ( ! $loaded ) {
			return array( 'error' => 'ساختار HTML صفحه قابل خواندن نیست.' );
		}

		$xpath = new DOMXPath( $dom );
		$product_page = $xpath->query( "//*[@id='product-page']" )->item( 0 );
		$body         = $xpath->query( '//body' )->item( 0 );
		$is_product   = $product_page || ( $body && preg_match( '/(?:^|\s)single-product(?:\s|$)/', $body->getAttribute( 'class' ) ) );
		if ( ! $is_product ) {
			return array( 'error' => 'صفحه دریافت‌شده یک صفحه محصول معتبر ایوک تولز نیست.' );
		}

		$title = $this->first_text(
			$xpath,
			array(
				"//*[@id='product-head']//h1//*[contains(concat(' ', normalize-space(@class), ' '), ' headline ')]",
				"//*[@id='product-head']//h1",
				"//meta[@property='og:title']/@content",
				'//h1',
			)
		);
		if ( '' === $title ) {
			return array( 'error' => 'عنوان محصول یافت نشد.' );
		}

		$product_id = $this->extract_product_id( $xpath, $html );
		$sku        = $this->extract_sku( $title, $product_id );
		$excerpt    = $this->extract_excerpt( $xpath );
		if ( '' === $excerpt ) {
			$meta = $this->first_attribute( $xpath, "//meta[@property='og:description']", 'content' );
			$excerpt = '' !== $meta ? '<p>' . esc_html( $meta ) . '</p>' : '';
		}

		$featured = $this->extract_featured_image( $xpath, $url );
		$gallery  = $this->extract_gallery_images( $xpath, $featured, $url );

		return array(
			'product_id'     => (string) $product_id,
			'sku'            => (string) $sku,
			'title'          => $title,
			'excerpt'        => $excerpt,
			'content'        => $this->extract_content( $xpath ),
			'featured_image' => $featured,
			'gallery_images' => $gallery,
			'regular_price'  => self::IMPORT_PRICE,
			'sale_price'     => null,
			'currency'       => self::DEFAULT_CURRENCY,
			'stock_status'   => 'in-stock',
			'stock_quantity' => null,
			'manage_stock'   => false,
			'categories'     => $this->extract_terms( $xpath, 'posted_in' ),
			'tags'           => $this->extract_terms( $xpath, 'tagged_as' ),
			'product_type'   => 'simple',
			'attributes'     => $this->extract_attributes( $xpath ),
			'variations'     => array(),
			'canonical'      => $this->first_attribute( $xpath, "//link[@rel='canonical']", 'href' ) ?: $url,
		);
	}

	private static function is_allowed_url( $url ) {
		$parts = wp_parse_url( (string) $url );
		if ( ! is_array( $parts ) || ! in_array( strtolower( isset( $parts['scheme'] ) ? $parts['scheme'] : '' ), array( 'http', 'https' ), true ) ) {
			return false;
		}
		$host = strtolower( rtrim( isset( $parts['host'] ) ? $parts['host'] : '', '.' ) );
		return 'ivektools.com' === $host || ( strlen( $host ) > 14 && '.ivektools.com' === substr( $host, -14 ) );
	}

	private static function is_allowed_product_url( $url ) {
		if ( ! self::is_allowed_url( $url ) ) {
			return false;
		}
		$path = wp_parse_url( $url, PHP_URL_PATH );
		return is_string( $path ) && 1 === preg_match( '#^/products/.+/?$#u', $path );
	}

	private function extract_product_id( $xpath, $html ) {
		$body = $xpath->query( '//body' )->item( 0 );
		if ( $body && preg_match( '/(?:^|\s)postid-(\d+)(?:\s|$)/', $body->getAttribute( 'class' ), $match ) ) {
			return $match[1];
		}
		if ( preg_match( '/"product_id"\s*:\s*"?(\d+)/', $html, $match ) ) {
			return $match[1];
		}
		return '';
	}

	private function extract_sku( $title, $fallback ) {
		if ( preg_match( '/\bK[\-\s]?\d+[A-Z]*\b/i', $title, $match ) ) {
			return strtoupper( preg_replace( '/\s+/', '-', $match[0] ) );
		}
		return $fallback;
	}

	private function extract_excerpt( $xpath ) {
		$node = $xpath->query( "//*[@id='product-head']//*[contains(concat(' ', normalize-space(@class), ' '), ' product-excerpt ')]" )->item( 0 );
		return $node ? $this->inner_html( $node ) : '';
	}

	private function extract_content( $xpath ) {
		$node = $xpath->query( "//*[@id='description']//*[contains(concat(' ', normalize-space(@class), ' '), ' product-article-description ')]" )->item( 0 );
		if ( ! $node ) {
			$node = $xpath->query( "//*[@id='description']" )->item( 0 );
		}
		return $node ? $this->inner_html( $node ) : '';
	}

	private function extract_attributes( $xpath ) {
		$attributes = array();
		$rows       = $xpath->query( "//*[@id='specifications']//table//tr" );
		foreach ( $rows as $row ) {
			$name_node  = $xpath->query( './th[1]', $row )->item( 0 );
			$value_node = $xpath->query( './td[1]', $row )->item( 0 );
			$name       = $name_node ? $this->clean_text( $name_node->textContent ) : '';
			$value      = $value_node ? $this->clean_text( $value_node->textContent ) : '';
			if ( '' === $name || '' === $value ) {
				continue;
			}
			$attributes[] = array(
				'id'                  => count( $attributes ),
				'name'                => $name,
				'values'              => array( $value ),
				'option_details'      => array(),
				'used_for_variations' => false,
			);
		}
		return $attributes;
	}

	private function extract_featured_image( $xpath, $base_url ) {
		$image = $this->first_attribute(
			$xpath,
			"//*[@id='product-head']//*[contains(concat(' ', normalize-space(@class), ' '), ' product-gallery ')]//a[@data-fancybox='product-gallery'][1]",
			'href'
		);
		if ( '' === $image ) {
			$image = $this->first_attribute( $xpath, "//meta[@property='og:image']", 'content' );
		}
		return '' !== $image ? $this->absolute_url( $image, $base_url ) : '';
	}

	private function extract_gallery_images( $xpath, $featured, $base_url ) {
		$images = array();
		$nodes  = $xpath->query( "//*[@id='product-head']//*[contains(concat(' ', normalize-space(@class), ' '), ' product-gallery ')]//a[@data-fancybox='product-gallery'][@href]" );
		foreach ( $nodes as $node ) {
			$url = $this->absolute_url( $node->getAttribute( 'href' ), $base_url );
			if ( '' !== $url && $url !== $featured ) {
				$images[] = $url;
			}
		}
		return array_values( array_unique( $images ) );
	}

	private function extract_terms( $xpath, $class ) {
		$terms = array();
		$nodes = $xpath->query( "//*[contains(concat(' ', normalize-space(@class), ' '), ' " . $class . " ')]//a" );
		foreach ( $nodes as $node ) {
			$text = $this->clean_text( $node->textContent );
			if ( '' !== $text ) {
				$terms[] = $text;
			}
		}
		return array_values( array_unique( $terms ) );
	}

	private function first_text( $xpath, $queries ) {
		foreach ( $queries as $query ) {
			$node = $xpath->query( $query )->item( 0 );
			if ( $node ) {
				$text = $this->clean_text( $node->nodeValue );
				if ( '' !== $text ) {
					return $text;
				}
			}
		}
		return '';
	}

	private function first_attribute( $xpath, $query, $attribute ) {
		$node = $xpath->query( $query )->item( 0 );
		return $node instanceof DOMElement ? trim( $node->getAttribute( $attribute ) ) : '';
	}

	private function inner_html( $node ) {
		$html = '';
		foreach ( $node->childNodes as $child ) {
			$html .= $node->ownerDocument->saveHTML( $child );
		}
		return trim( $html );
	}

	private function clean_text( $text ) {
		$text = html_entity_decode( strip_tags( (string) $text ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		return trim( preg_replace( '/\s+/u', ' ', $text ) );
	}

	private function absolute_url( $url, $base_url ) {
		$url = html_entity_decode( trim( $url ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		if ( preg_match( '#^https?://#i', $url ) ) {
			return $url;
		}
		$base = wp_parse_url( $base_url );
		if ( 0 === strpos( $url, '//' ) ) {
			return ( isset( $base['scheme'] ) ? $base['scheme'] : 'https' ) . ':' . $url;
		}
		$origin = ( isset( $base['scheme'] ) ? $base['scheme'] : 'https' ) . '://' . ( isset( $base['host'] ) ? $base['host'] : 'ivektools.com' );
		return $origin . '/' . ltrim( $url, '/' );
	}

	private function read_sitemap( $url, $depth ) {
		if ( $depth > 2 ) {
			return array();
		}
		$response = wp_remote_get( $url, array( 'timeout' => 30, 'redirection' => 3 ) );
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		$xml = wp_remote_retrieve_body( $response );
		if ( '' === trim( $xml ) ) {
			return new WP_Error( 'ivektools_empty_sitemap', 'سایت‌مپ ایوک تولز خالی است.' );
		}
		preg_match_all( '#<loc>\s*(.*?)\s*</loc>#is', $xml, $matches );
		$urls = array();
		foreach ( $matches[1] as $location ) {
			$location = html_entity_decode( trim( strip_tags( $location ) ), ENT_QUOTES | ENT_XML1, 'UTF-8' );
			if ( preg_match( '/\.xml(?:\?|$)/i', $location ) && self::is_allowed_url( $location ) ) {
				$children = $this->read_sitemap( $location, $depth + 1 );
				if ( ! is_wp_error( $children ) ) {
					$urls = array_merge( $urls, $children );
				}
			} else {
				$urls[] = $location;
			}
		}
		return $urls;
	}

	private function display_product_data( $data ) {
		$regular_price = isset( $data['regular_price'] ) ? (int) $data['regular_price'] : 0;
		$sale_price    = isset( $data['sale_price'] ) && null !== $data['sale_price'] ? (int) $data['sale_price'] : null;
		$currency      = isset( $data['currency'] ) ? $data['currency'] : self::DEFAULT_CURRENCY;
		$in_stock     = isset( $data['stock_status'] ) && 'in-stock' === $data['stock_status'];
		$attributes   = isset( $data['attributes'] ) && is_array( $data['attributes'] ) ? $data['attributes'] : array();
		$gallery      = isset( $data['gallery_images'] ) && is_array( $data['gallery_images'] ) ? $data['gallery_images'] : array();
		?>
		<style>
			.jawaheran-preview { max-width: 1180px; margin: 28px 0; color: #1d2327; }
			.jawaheran-preview * { box-sizing: border-box; }
			.jawaheran-preview__hero, .jawaheran-preview__section { background: #fff; border: 1px solid #dcdcde; border-radius: 10px; box-shadow: 0 1px 2px rgba(0,0,0,.04); margin-bottom: 18px; padding: 22px; }
			.jawaheran-preview__hero { display: grid; grid-template-columns: minmax(280px, 420px) 1fr; gap: 28px; align-items: start; }
			.jawaheran-preview__featured { width: 100%; max-width: 420px; max-height: 480px; object-fit: contain; border: 1px solid #e2e4e7; border-radius: 8px; background: #fafafa; }
			.jawaheran-preview__placeholder { min-height: 280px; display: grid; place-items: center; border: 1px dashed #c3c4c7; border-radius: 8px; color: #646970; background: #f6f7f7; }
			.jawaheran-preview__title { margin: 0 0 18px; font-size: 27px; line-height: 1.5; }
			.jawaheran-preview__price { font-size: 22px; font-weight: 700; color: #2271b1; margin: 16px 0; }
			.jawaheran-preview__price del { color: #787c82; font-size: 16px; font-weight: 400; margin-left: 8px; }
			.jawaheran-preview__badge { display: inline-block; padding: 6px 12px; border-radius: 999px; font-weight: 700; }
			.jawaheran-preview__badge--in { color: #116329; background: #edfaef; }
			.jawaheran-preview__badge--out { color: #8a2424; background: #fcf0f1; }
			.jawaheran-preview__facts { width: 100%; border-collapse: collapse; margin-top: 18px; }
			.jawaheran-preview__facts th, .jawaheran-preview__facts td { border-bottom: 1px solid #eee; padding: 10px 8px; text-align: right; vertical-align: top; }
			.jawaheran-preview__facts th { width: 150px; color: #50575e; }
			.jawaheran-preview__section h2 { margin-top: 0; }
			.jawaheran-preview__html { font-size: 15px; line-height: 2; overflow-wrap: anywhere; }
			.jawaheran-preview__html img { max-width: 100%; height: auto; }
			.jawaheran-preview__gallery { display: grid; grid-template-columns: repeat(auto-fit, minmax(280px, 380px)); gap: 16px; }
			.jawaheran-preview__gallery img { width: 100%; max-width: 380px; height: 360px; object-fit: contain; padding: 8px; border: 1px solid #e2e4e7; border-radius: 8px; background: #fafafa; }
			.jawaheran-preview__attributes { width: 100%; border-collapse: collapse; }
			.jawaheran-preview__attributes th, .jawaheran-preview__attributes td { padding: 12px; border: 1px solid #dcdcde; text-align: right; }
			.jawaheran-preview__attributes thead { background: #f6f7f7; }
			.jawaheran-json-title { margin-top: 32px; }
			.jawaheran-json-output { max-width: 1180px; direction: ltr; text-align: left; white-space: pre-wrap; overflow-wrap: anywhere; padding: 20px; border-radius: 8px; background: #1d2327; color: #f0f0f1; }
			@media (max-width: 782px) { .jawaheran-preview__hero { grid-template-columns: 1fr; } }
		</style>

		<div class="jawaheran-preview">
			<div class="jawaheran-preview__hero">
				<div>
					<?php if ( ! empty( $data['featured_image'] ) ) : ?>
						<img class="jawaheran-preview__featured" src="<?php echo esc_url( $data['featured_image'] ); ?>" alt="<?php echo esc_attr( isset( $data['title'] ) ? $data['title'] : '' ); ?>">
					<?php else : ?>
						<div class="jawaheran-preview__placeholder">تصویر شاخص موجود نیست</div>
					<?php endif; ?>
				</div>
				<div>
					<h2 class="jawaheran-preview__title"><?php echo esc_html( isset( $data['title'] ) ? $data['title'] : '' ); ?></h2>
					<span class="jawaheran-preview__badge <?php echo $in_stock ? 'jawaheran-preview__badge--in' : 'jawaheran-preview__badge--out'; ?>"><?php echo $in_stock ? 'موجود' : 'ناموجود'; ?></span>
					<div class="jawaheran-preview__price">
						<?php if ( null !== $sale_price ) : ?>
							<del><?php echo esc_html( number_format_i18n( $regular_price ) . ' ' . $currency ); ?></del>
							<span><?php echo esc_html( number_format_i18n( $sale_price ) . ' ' . $currency ); ?></span>
						<?php else : ?>
							<span><?php echo esc_html( number_format_i18n( $regular_price ) . ' ' . $currency ); ?></span>
						<?php endif; ?>
					</div>
					<table class="jawaheran-preview__facts">
						<tr><th>شناسه محصول</th><td><?php echo esc_html( isset( $data['product_id'] ) ? $data['product_id'] : '' ); ?></td></tr>
						<tr><th>SKU</th><td><?php echo esc_html( isset( $data['sku'] ) ? $data['sku'] : '' ); ?></td></tr>
						<tr><th>نوع محصول</th><td>ساده</td></tr>
						<tr><th>دسته‌بندی‌ها</th><td><?php echo esc_html( ! empty( $data['categories'] ) ? implode( '، ', $data['categories'] ) : '—' ); ?></td></tr>
						<tr><th>برچسب‌ها</th><td><?php echo esc_html( ! empty( $data['tags'] ) ? implode( '، ', $data['tags'] ) : '—' ); ?></td></tr>
						<?php if ( ! empty( $data['canonical'] ) ) : ?><tr><th>آدرس کانونیکال</th><td><a href="<?php echo esc_url( $data['canonical'] ); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html( $data['canonical'] ); ?></a></td></tr><?php endif; ?>
					</table>
				</div>
			</div>

			<div class="jawaheran-preview__section">
				<h2>توضیحات کوتاه</h2>
				<div class="jawaheran-preview__html"><?php echo ! empty( $data['excerpt'] ) ? wp_kses_post( $data['excerpt'] ) : '<p>توضیحات کوتاه موجود نیست.</p>'; ?></div>
			</div>

			<div class="jawaheran-preview__section">
				<h2>توضیحات کامل</h2>
				<div class="jawaheran-preview__html"><?php echo ! empty( $data['content'] ) ? wp_kses_post( $data['content'] ) : '<p>توضیحات کامل موجود نیست.</p>'; ?></div>
			</div>

			<div class="jawaheran-preview__section">
				<h2>ویژگی‌های محصول</h2>
				<?php if ( ! empty( $attributes ) ) : ?>
					<table class="jawaheran-preview__attributes">
						<thead><tr><th>نام ویژگی</th><th>مقدار</th></tr></thead>
						<tbody>
						<?php foreach ( $attributes as $attribute ) : ?>
							<tr><th><?php echo esc_html( isset( $attribute['name'] ) ? $attribute['name'] : '' ); ?></th><td><?php echo esc_html( ! empty( $attribute['values'] ) ? implode( '، ', $attribute['values'] ) : '—' ); ?></td></tr>
						<?php endforeach; ?>
						</tbody>
					</table>
				<?php else : ?>
					<p>ویژگی‌ای برای این محصول ثبت نشده است.</p>
				<?php endif; ?>
			</div>

			<div class="jawaheran-preview__section">
				<h2>گالری تصاویر (<?php echo (int) count( $gallery ); ?>)</h2>
				<?php if ( ! empty( $gallery ) ) : ?>
					<div class="jawaheran-preview__gallery">
						<?php foreach ( $gallery as $image_url ) : ?>
							<a href="<?php echo esc_url( $image_url ); ?>" target="_blank" rel="noopener noreferrer"><img loading="lazy" src="<?php echo esc_url( $image_url ); ?>" alt=""></a>
						<?php endforeach; ?>
					</div>
				<?php else : ?>
					<p>تصویر دیگری در گالری موجود نیست.</p>
				<?php endif; ?>
			</div>
		</div>
		<?php
	}
}
