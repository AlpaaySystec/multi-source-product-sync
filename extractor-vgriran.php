<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once plugin_dir_path( __FILE__ ) . 'class-product-dto.php';

class VGRIran_Product_Extractor {
	public $source_data = array();

	const MENU_SLUG = 'vgr-iran-product-extractor';
	const NONCE_ACTION = 'vgr-iran_extractor_action';

	public function __construct() {
		add_action( 'admin_menu', array( $this, 'register_admin_page' ) );
	}

	public function register_admin_page() {
		add_submenu_page(
			'edit.php?post_type=source_profile',
			'VGRIran Extractor',
			'VGRIran Extractor',
			'manage_options',
			self::MENU_SLUG,
			array( $this, 'render_admin_page' )
		);
	}

	public function render_admin_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$result = null;
		$error  = '';
		$url    = '';

		if ( isset( $_POST['vgr-iran_extractor_submit'] ) ) {
			check_admin_referer( self::NONCE_ACTION );

			$url = isset( $_POST['vgr-iran_url'] ) ? esc_url_raw( wp_unslash( $_POST['vgr-iran_url'] ) ) : '';

			if ( empty( $url ) ) {
				$error = 'لطفاً یک آدرس معتبر وارد کنید.';
			} else {
				try {
					$outcome = $this->extract_product_data( $url );
				} catch ( \Throwable $e ) {
					$outcome = array( 'error' => 'خطای غیرمنتظره: ' . $e->getMessage() );
				}

				if ( is_array( $outcome ) && isset( $outcome['error'] ) ) {
					$error = $outcome['error'];
				} elseif ( is_array( $outcome ) ) {
					$result = $outcome;
				} else {
					$error = 'استخراج اطلاعات با خطا مواجه شد.';
				}
			}
		}
		?>
		<div class="wrap">
			<h1>VGRIran Product Extractor</h1>
			<form method="post">
				<?php wp_nonce_field( self::NONCE_ACTION ); ?>
				<table class="form-table">
					<tr>
						<th scope="row"><label for="vgr-iran_url">آدرس محصول</label></th>
						<td>
							<input type="url" id="vgr-iran_url" name="vgr-iran_url" class="regular-text" required
								value="<?php echo esc_attr( $url ); ?>" placeholder="https://vgr-iran-iran.com/product/..." />
						</td>
					</tr>
				</table>
				<p class="submit">
					<input type="submit" name="vgr-iran_extractor_submit" class="button button-primary" value="استخراج اطلاعات" />
				</p>
			</form>

			<?php if ( ! empty( $error ) ) : ?>
				<div class="notice notice-error"><p><?php echo esc_html( $error ); ?></p></div>
			<?php endif; ?>

			<?php if ( is_array( $result ) ) : ?>
				<?php $this->display_product_data( $result ); ?>
			<?php endif; ?>
		</div>
		<?php
	}

	private function display_product_data( $data ) {
		if ( ! is_array( $data ) ) {
			echo '<div class="notice notice-warning"><p>داده‌ای برای نمایش وجود ندارد.</p></div>';
			return;
		}

		$title          = isset( $data['title'] ) ? $data['title'] : '';
		$product_id     = isset( $data['product_id'] ) ? $data['product_id'] : '';
		$sku            = isset( $data['sku'] ) ? $data['sku'] : '';
		$product_type   = isset( $data['product_type'] ) ? $data['product_type'] : '';
		$regular_price  = isset( $data['regular_price'] ) ? $data['regular_price'] : 0;
		$sale_price     = isset( $data['sale_price'] ) ? $data['sale_price'] : null;
		$currency       = isset( $data['currency'] ) ? $data['currency'] : 'تومان';
		$stock_status   = isset( $data['stock_status'] ) ? $data['stock_status'] : '';
		$stock_quantity = isset( $data['stock_quantity'] ) ? $data['stock_quantity'] : null;
		$categories     = isset( $data['categories'] ) && is_array( $data['categories'] ) ? $data['categories'] : array();
		$tags           = isset( $data['tags'] ) && is_array( $data['tags'] ) ? $data['tags'] : array();
		$featured_image = isset( $data['featured_image'] ) ? $data['featured_image'] : '';
		$gallery_images = isset( $data['gallery_images'] ) && is_array( $data['gallery_images'] ) ? $data['gallery_images'] : array();
		$attributes     = isset( $data['attributes'] ) && is_array( $data['attributes'] ) ? $data['attributes'] : array();
		$variations     = isset( $data['variations'] ) && is_array( $data['variations'] ) ? $data['variations'] : array();
		$excerpt        = isset( $data['excerpt'] ) ? $data['excerpt'] : '';
		$content        = isset( $data['content'] ) ? $data['content'] : '';
		?>
		<hr />
		<h2><?php echo esc_html( $title ); ?></h2>
		<table class="widefat striped" style="max-width:900px;">
			<tbody>
				<tr><th>شناسه محصول</th><td><?php echo esc_html( $product_id ); ?></td></tr>
				<tr><th>SKU</th><td><?php echo esc_html( $sku ); ?></td></tr>
				<tr><th>نوع محصول</th><td><?php echo esc_html( $product_type ); ?></td></tr>
				<tr><th>قیمت اصلی</th><td><?php echo esc_html( number_format( (float) $regular_price ) ); ?></td></tr>
				<tr><th>قیمت با تخفیف</th><td><?php echo ( null !== $sale_price ) ? esc_html( number_format( (float) $sale_price ) ) : '-'; ?></td></tr>
				<tr><th>واحد پول</th><td><?php echo esc_html( $currency ); ?></td></tr>
				<tr><th>وضعیت موجودی</th><td><?php echo esc_html( $stock_status ); ?></td></tr>
				<tr><th>تعداد موجودی</th><td><?php echo ( null !== $stock_quantity ) ? esc_html( $stock_quantity ) : 'نامشخص (مدیریت‌نشده)'; ?></td></tr>
				<tr><th>دسته‌بندی</th><td><?php echo esc_html( implode( ', ', $categories ) ); ?></td></tr>
				<tr><th>برچسب‌ها</th><td><?php echo esc_html( implode( ', ', $tags ) ); ?></td></tr>
				<tr><th>خلاصه</th><td><?php echo esc_html( $excerpt ); ?></td></tr>
			</tbody>
		</table>

		<?php if ( '' !== trim( wp_strip_all_tags( $content ) ) ) : ?>
			<h3>توضیحات اصلی (<?php echo (int) mb_strlen( wp_strip_all_tags( $content ) ); ?> کاراکتر)</h3>
			<div style="max-width:900px;max-height:400px;overflow:auto;border:1px solid #ccd0d4;background:#fff;padding:12px 16px;">
				<?php echo wp_kses_post( $content ); ?>
			</div>
		<?php endif; ?>

		<?php if ( ! empty( $featured_image ) ) : ?>
			<h3>تصویر شاخص</h3>
			<img src="<?php echo esc_url( $featured_image ); ?>" style="max-width:200px;height:auto;" />
		<?php endif; ?>

		<?php if ( ! empty( $gallery_images ) ) : ?>
			<h3>گالری تصاویر (<?php echo (int) count( $gallery_images ); ?>)</h3>
			<div>
				<?php foreach ( $gallery_images as $img_url ) : ?>
					<img src="<?php echo esc_url( $img_url ); ?>" style="max-width:100px;height:auto;margin:4px;" />
				<?php endforeach; ?>
			</div>
		<?php endif; ?>

		<?php if ( ! empty( $attributes ) ) : ?>
			<h3>ویژگی‌ها (<?php echo (int) count( $attributes ); ?>)</h3>
			<table class="widefat striped" style="max-width:900px;">
				<thead><tr><th>نام</th><th>مقادیر</th><th>استفاده در واریانت</th></tr></thead>
				<tbody>
					<?php foreach ( $attributes as $attr ) : ?>
						<?php
						$attr_name   = isset( $attr['name'] ) ? $attr['name'] : '';
						$attr_values = isset( $attr['values'] ) && is_array( $attr['values'] ) ? $attr['values'] : array();
						$attr_used   = ! empty( $attr['used_for_variations'] );
						?>
						<tr>
							<td><?php echo esc_html( $attr_name ); ?></td>
							<td><?php echo esc_html( implode( ' | ', $attr_values ) ); ?></td>
							<td><?php echo $attr_used ? 'بله' : 'خیر'; ?></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		<?php endif; ?>

		<?php if ( ! empty( $variations ) ) : ?>
			<h3>واریانت‌ها (<?php echo (int) count( $variations ); ?>)</h3>
			<table class="widefat striped" style="max-width:900px;">
				<thead>
					<tr>
						<th>تصویر</th><th>ویژگی</th><th>SKU</th><th>کد</th><th>قیمت اصلی</th><th>قیمت با تخفیف</th>
						<th>وضعیت موجودی</th><th>تعداد موجودی</th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $variations as $var ) : ?>
						<?php
						$v_summary = isset( $var['attributes_summary'] ) ? $var['attributes_summary'] : '';
						$v_sku     = isset( $var['sku'] ) ? $var['sku'] : '';
						$v_code    = isset( $var['code'] ) ? $var['code'] : '';
						$v_regular = isset( $var['regular_price'] ) ? $var['regular_price'] : 0;
						$v_sale    = isset( $var['sale_price'] ) ? $var['sale_price'] : null;
						$v_status  = isset( $var['stock_status'] ) ? $var['stock_status'] : '';
						$v_qty     = isset( $var['stock_quantity'] ) ? $var['stock_quantity'] : null;
						$v_image   = isset( $var['image'] ) ? $var['image'] : '';
						?>
						<tr>
							<td><?php if ( $v_image ) : ?><img src="<?php echo esc_url( $v_image ); ?>" style="max-width:60px;height:auto;" /><?php endif; ?></td>
							<td><?php echo esc_html( $v_summary ); ?></td>
							<td><?php echo esc_html( $v_sku ); ?></td>
							<td><?php echo esc_html( $v_code ); ?></td>
							<td><?php echo esc_html( number_format( (float) $v_regular ) ); ?></td>
							<td><?php echo ( null !== $v_sale ) ? esc_html( number_format( (float) $v_sale ) ) : '-'; ?></td>
							<td><?php echo esc_html( $v_status ); ?></td>
							<td><?php echo ( null !== $v_qty ) ? esc_html( $v_qty ) : 'نامشخص'; ?></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		<?php endif; ?>
		<?php $this->display_source_data_sections( isset( $data['source_data'] ) && is_array( $data['source_data'] ) ? $data['source_data'] : array() ); ?>
		<?php
	}

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
		return $data;
	}

	public function extract_product_data( $url ) {
		if ( empty( $url ) || ! is_string( $url ) ) {
			return array( 'error' => 'آدرس نامعتبر است.' );
		}

		$validation_error = $this->validate_product_url( $url );
		if ( '' !== $validation_error ) {
			return array( 'error' => $validation_error );
		}

		$response = $this->fetch_product_page( $url );

		if ( is_wp_error( $response ) ) {
			return array( 'error' => 'خطا در دریافت صفحه: ' . $response->get_error_message() );
		}

		$status_code = (int) wp_remote_retrieve_response_code( $response );
		if ( 404 === $status_code ) {
			return array( 'error' => 'محصول یافت نشد (404).' );
		}
		if ( $status_code < 200 || $status_code >= 300 ) {
			return array( 'error' => 'دریافت صفحه با خطا مواجه شد. HTTP Status: ' . $status_code );
		}

		$html = wp_remote_retrieve_body( $response );
		if ( empty( $html ) || ! is_string( $html ) ) {
			return array( 'error' => 'محتوای صفحه خالی است.' );
		}

		return $this->parse_product_html( $html, $url );
	}

	public function parse_product_html( $html, $url ) {
		if ( ! is_string( $html ) || '' === trim( $html ) ) {
			return array( 'error' => 'محتوای صفحه خالی است.' );
		}
		$product_data = null;

		try {
			$product_data = $this->extract_from_dom( $html, $url );
		} catch ( \Throwable $e ) {
			$product_data = null;
		}

		if ( empty( $product_data ) || empty( $product_data['name'] ) ) {
			try {
				$jsonld = $this->extract_from_json_ld( $html, $url );
			} catch ( \Throwable $e ) {
				$jsonld = null;
			}
			if ( ! empty( $jsonld ) ) {
				$product_data = is_array( $product_data ) ? array_merge( $jsonld, $product_data ) : $jsonld;
			}
		} elseif ( empty( $product_data['brand_name'] ) ) {
			try {
				$jsonld = $this->extract_from_json_ld( $html, $url );
			} catch ( \Throwable $e ) {
				$jsonld = null;
			}
			if ( ! empty( $jsonld['brand_name'] ) ) {
				$product_data['brand_name'] = $jsonld['brand_name'];
			}
		}

		if ( empty( $product_data ) || empty( $product_data['name'] ) ) {
			return array( 'error' => 'داده‌های ساختاریافتهٔ محصول در صفحه پیدا نشد.' );
		}

		$this->source_data = $this->extract_source_data( $html, $url, $product_data );

		try {
			$product = $this->normalize_extracted_data( $product_data, $url );
		} catch ( \Throwable $e ) {
			return array( 'error' => 'خطا در پردازش داده‌های محصول: ' . $e->getMessage() );
		}

		try {
			if ( class_exists( 'ProductDTO' ) && method_exists( 'ProductDTO', 'normalize' ) ) {
				$normalized = ProductDTO::normalize( $product );
				if ( is_array( $normalized ) ) {
					$normalized['source_data'] = $this->source_data;
					return $normalized;
				}
			}
		} catch ( \Throwable $e ) {
			// در صورت بروز خطا در نرمال‌سازی نهایی، همان داده‌ی پردازش‌شده برگردانده می‌شود.
		}

		$product['source_data'] = $this->source_data;
		return $product;
	}

	private function validate_product_url( $url ) {
		$parts = wp_parse_url( $url );
		if ( ! is_array( $parts ) || 'https' !== strtolower( isset( $parts['scheme'] ) ? $parts['scheme'] : '' ) ) {
			return 'فقط آدرس HTTPS معتبر پذیرفته می‌شود.';
		}
		$host = strtolower( rtrim( isset( $parts['host'] ) ? $parts['host'] : '', '.' ) );
		if ( ! in_array( $host, array( 'vgr-iran.com', 'www.vgr-iran.com' ), true ) ) {
			return 'آدرس باید متعلق به دامنه vgr-iran.com باشد.';
		}
		$path = isset( $parts['path'] ) ? rawurldecode( $parts['path'] ) : '';
		if ( 0 !== strpos( $path, '/product/' ) ) {
			return 'آدرس واردشده صفحه محصول نیست.';
		}
		return '';
	}

	private function fetch_product_page( $url ) {
		$current = $url;
		for ( $i = 0; $i < 5; $i++ ) {
			if ( '' !== $this->validate_product_url( $current ) ) {
				return new WP_Error( 'unsafe_product_url', 'تغییر مسیر محصول نامعتبر است.' );
			}
			$response = wp_safe_remote_get( $current, array(
				'timeout' => 25, 'redirection' => 0, 'sslverify' => true, 'limit_response_size' => 6291456,
				'user-agent' => 'Mozilla/5.0 (compatible; VGRIran-Extractor/2.0; +wordpress)',
			) );
			if ( is_wp_error( $response ) ) return $response;
			$status = (int) wp_remote_retrieve_response_code( $response );
			if ( $status < 300 || $status >= 400 ) return $response;
			$location = wp_remote_retrieve_header( $response, 'location' );
			if ( ! $location ) return $response;
			$current = $this->make_absolute_url( $location, $current );
		}
		return new WP_Error( 'too_many_redirects', 'تعداد تغییر مسیرها بیش از حد مجاز است.' );
	}

	private function extract_source_data( $html, $url, $standard_raw ) {
		$doc = $this->load_dom( $html );
		if ( ! $doc ) return array();
		$xpath = new DOMXPath( $doc );
		$product = $this->find_main_product_node( $xpath );
		$json_docs = array();
		foreach ( $xpath->query( "//script[@type='application/ld+json']" ) as $node ) {
			$decoded = json_decode( trim( $node->textContent ), true );
			if ( is_array( $decoded ) ) $json_docs[] = $decoded;
		}
		$json_product = array();
		foreach ( $json_docs as $document ) {
			$found = $this->find_json_ld_product( $document );
			if ( $found ) { $json_product = $found; break; }
		}
		$meta = array();
		foreach ( $xpath->query( '//meta[@content]' ) as $node ) {
			$key = $node->getAttribute( 'property' );
			if ( '' === $key ) $key = $node->getAttribute( 'name' );
			if ( '' === $key ) $key = $node->getAttribute( 'itemprop' );
			if ( '' !== $key ) $meta[ $key ] = $node->getAttribute( 'content' );
		}
		$links = array();
		foreach ( $xpath->query( "//nav[contains(@class,'woocommerce-breadcrumb')]//a|//*[contains(@class,'posted_in') or contains(@class,'tagged_as')]//a", $product ?: $doc->documentElement ) as $a ) {
			$links[] = array( 'name' => trim( preg_replace( '/\s+/u', ' ', $a->textContent ) ), 'url' => $this->make_absolute_url( $a->getAttribute( 'href' ), $url ) );
		}
		$images = array();
		foreach ( $xpath->query( ".//*[contains(@class,'woocommerce-product-gallery__image')]", $product ?: $doc->documentElement ) as $wrapper ) {
			$a = $this->query_one( $xpath, './/a[@href]', $wrapper );
			$img = $this->query_one( $xpath, './/img', $wrapper );
			if ( ! $img && ! $a ) continue;
			$images[] = array(
				'full_src' => $a ? $this->make_absolute_url( $a->getAttribute( 'href' ), $url ) : '',
				'src' => $img ? $this->make_absolute_url( $img->getAttribute( 'src' ), $url ) : '',
				'data_src' => $img ? $this->make_absolute_url( $img->getAttribute( 'data-src' ), $url ) : '',
				'data_large_image' => $img ? $this->make_absolute_url( $img->getAttribute( 'data-large_image' ), $url ) : '',
				'srcset' => $img ? $img->getAttribute( 'srcset' ) : '', 'sizes' => $img ? $img->getAttribute( 'sizes' ) : '',
				'alt' => $img ? $img->getAttribute( 'alt' ) : '', 'title' => $img ? $img->getAttribute( 'title' ) : '',
				'width' => $img ? $img->getAttribute( 'width' ) : '', 'height' => $img ? $img->getAttribute( 'height' ) : '',
			);
		}
		$attribute_rows = array();
		foreach ( $xpath->query( "//table[contains(@class,'woocommerce-product-attributes')]//tr" ) as $row ) {
			$th = $this->query_one( $xpath, './/th', $row ); $td = $this->query_one( $xpath, './/td', $row );
			if ( $th && $td ) $attribute_rows[] = array( 'name' => trim( $th->textContent ), 'text' => trim( preg_replace( '/\s+/u', ' ', $td->textContent ) ), 'html' => $this->inner_html( $td ) );
		}
		$form = $this->query_one( $xpath, "//form[contains(@class,'variations_form')]" );
		$raw_variations = array(); $selectors = array();
		if ( $form ) {
			$encoded = $form->getAttribute( 'data-product_variations' );
			$decoded = json_decode( html_entity_decode( $encoded, ENT_QUOTES, 'UTF-8' ), true );
			if ( is_array( $decoded ) ) $raw_variations = $decoded;
			foreach ( $xpath->query( ".//select[starts-with(@name,'attribute_')]", $form ) as $select ) {
				$options = array(); foreach ( $xpath->query( './/option', $select ) as $option ) $options[] = array( 'value' => $option->getAttribute( 'value' ), 'label' => trim( $option->textContent ), 'selected' => $option->hasAttribute( 'selected' ) );
				$selectors[] = array( 'name' => $select->getAttribute( 'name' ), 'id' => $select->getAttribute( 'id' ), 'options' => $options );
			}
		}
		$title = $this->query_one( $xpath, '//title' ); $canonical = $this->query_one( $xpath, "//link[contains(concat(' ',normalize-space(@rel),' '),' canonical ')]" );
		$short = $this->query_one( $xpath, "//*[contains(@class,'woocommerce-product-details__short-description')]", $product ?: $doc->documentElement );
		$desc = $this->query_one( $xpath, "//*[@id='tab-description']" );
		$reviews = $this->query_one( $xpath, "//*[@id='reviews']" );
		return array(
			'extracted_via' => 'woocommerce_dom_jsonld_variations', 'source_url' => $url,
			'identity' => array( 'product_id' => isset( $standard_raw['id'] ) ? $standard_raw['id'] : '', 'sku' => isset( $standard_raw['sku'] ) ? $standard_raw['sku'] : '', 'product_type' => isset( $standard_raw['product_type'] ) ? $standard_raw['product_type'] : '', 'product_classes' => $product ? $product->getAttribute( 'class' ) : '' ),
			'document' => array( 'page_title' => $title ? trim( $title->textContent ) : '', 'canonical' => $canonical ? $this->make_absolute_url( $canonical->getAttribute( 'href' ), $url ) : '', 'meta' => $meta, 'taxonomy_links' => $links ),
			'product_content' => array( 'short_description_text' => $short ? trim( preg_replace( '/\s+/u', ' ', $short->textContent ) ) : '', 'short_description_html' => $short ? $this->inner_html( $short ) : '', 'description_html' => $desc ? $this->inner_html( $desc ) : '', 'attributes' => $attribute_rows, 'images' => $images, 'reviews_html' => $reviews ? $this->inner_html( $reviews ) : '' ),
			'variation_selectors' => $selectors, 'woocommerce_variations' => $raw_variations,
			'json_ld_product' => $json_product, 'json_ld_documents' => $json_docs,
		);
	}

	/* ==================== DOM EXTRACTION (WooCommerce) ==================== */

	private function extract_from_dom( $html, $base_url ) {
		$doc = $this->load_dom( $html );
		if ( ! $doc || ! $doc->documentElement ) {
			return null;
		}
		$xpath = new DOMXPath( $doc );

		$product_node = $this->find_main_product_node( $xpath );
		if ( ! $product_node ) {
			return null;
		}

		$classes = ' ' . $product_node->getAttribute( 'class' ) . ' ';

		$product_type = ( false !== strpos( $classes, ' product-type-variable ' ) ) ? 'variable' : 'simple';

		$stock_status = 'out-of-stock';
		if ( false !== strpos( $classes, ' outofstock ' ) ) {
			$stock_status = 'out-of-stock';
		} elseif ( false !== strpos( $classes, ' instock ' ) ) {
			$stock_status = 'in-stock';
		} elseif ( false !== strpos( $classes, ' onbackorder ' ) ) {
			$stock_status = 'on-backorder';
		}

		$product_id = '';
		$node_id    = $product_node->getAttribute( 'id' );
		if ( preg_match( '/product-(\d+)/', $node_id, $m ) ) {
			$product_id = $m[1];
		}
		if ( '' === $product_id && preg_match( '/post-(\d+)/', $classes, $m ) ) {
			$product_id = $m[1];
		}

		$title_node = $this->query_one( $xpath, ".//h1[contains(concat(' ', normalize-space(@class), ' '), ' product_title ')]", $product_node );
		if ( ! $title_node ) {
			$title_node = $this->query_one( $xpath, './/h1', $product_node );
		}
		$title = $title_node ? trim( $title_node->textContent ) : '';

		$summary_node = $this->query_one( $xpath, ".//div[contains(concat(' ', normalize-space(@class), ' '), ' summary ') and contains(concat(' ', normalize-space(@class), ' '), ' entry-summary ')]", $product_node );
		if ( ! $summary_node ) {
			$summary_node = $product_node;
		}

		$sku = '';
		$sku_node = $this->query_one( $xpath, ".//*[contains(concat(' ', normalize-space(@class), ' '), ' sku ')]", $summary_node );
		if ( $sku_node ) {
			$sku_text = trim( $sku_node->textContent );
			$invalid_sku_values = array( 'نامعلوم', 'n/a', 'na', '-', '—', 'unknown' );
			if ( '' !== $sku_text && ! in_array( mb_strtolower( $sku_text ), $invalid_sku_values, true ) ) {
				$sku = $sku_text;
			}
		}

		$categories = array();
		$cat_nodes  = $xpath->query( ".//*[contains(concat(' ', normalize-space(@class), ' '), ' posted_in ')]//a", $summary_node );
		if ( $cat_nodes ) {
			foreach ( $cat_nodes as $a ) {
				$t = trim( $a->textContent );
				if ( '' !== $t ) {
					$categories[] = $t;
				}
			}
		}
		if ( empty( $categories ) ) {
			$bc_nodes = $xpath->query( ".//nav[contains(concat(' ', normalize-space(@class), ' '), ' woocommerce-breadcrumb ')]//a", $product_node );
			if ( $bc_nodes ) {
				foreach ( $bc_nodes as $a ) {
					$t = trim( $a->textContent );
					if ( '' !== $t && 'خانه' !== $t ) {
						$categories[] = $t;
					}
				}
			}
		}

		$tags = array();
		$tag_nodes = $xpath->query( ".//*[contains(concat(' ', normalize-space(@class), ' '), ' tagged_as ')]//a", $summary_node );
		if ( $tag_nodes ) {
			foreach ( $tag_nodes as $a ) {
				$t = trim( $a->textContent );
				if ( '' !== $t ) {
					$tags[] = $t;
				}
			}
		}

		$gallery_images = array();
		$gallery_nodes  = $xpath->query( ".//*[contains(concat(' ', normalize-space(@class), ' '), ' woocommerce-product-gallery__image ')]//a[@href]", $product_node );
		if ( $gallery_nodes ) {
			foreach ( $gallery_nodes as $a ) {
				$href = trim( $a->getAttribute( 'href' ) );
				if ( '' !== $href ) {
					$gallery_images[] = $this->make_absolute_url( $href, $base_url );
				}
			}
		}
		$gallery_images = array_values( array_unique( $gallery_images ) );

		$short_description = '';
		$short_desc_node    = $this->query_one( $xpath, ".//*[contains(concat(' ', normalize-space(@class), ' '), ' woocommerce-product-details__short-description ')]", $product_node );
		if ( $short_desc_node ) {
			$short_description = trim( $this->node_text( $short_desc_node ) );
		}

		$content_html = '';
		$desc_node     = $this->query_one( $xpath, ".//*[@id='tab-description']", $doc->documentElement );
		if ( $desc_node ) {
			$content_html = $this->inner_html( $desc_node );
		}

		$attributes_table = array();
		$attr_rows = $xpath->query( ".//table[contains(concat(' ', normalize-space(@class), ' '), ' woocommerce-product-attributes ')]//tr", $doc->documentElement );
		if ( $attr_rows ) {
			foreach ( $attr_rows as $row ) {
				$row_class = ' ' . $row->getAttribute( 'class' ) . ' ';
				$slug      = '';
				if ( preg_match( '/woocommerce-product-attributes-item--([a-z0-9_\-]+)/i', $row_class, $mm ) ) {
					$slug = $mm[1];
				}
				$th = $this->query_one( $xpath, './/th', $row );
				$td = $this->query_one( $xpath, './/td', $row );
				if ( ! $th || ! $td ) {
					continue;
				}
				$label = trim( $th->textContent );
				$values = array();
				$value_nodes = $xpath->query( ".//*[contains(concat(' ', normalize-space(@class), ' '), ' wd-attr-term ')]", $td );
				if ( $value_nodes && $value_nodes->length > 0 ) {
					foreach ( $value_nodes as $vn ) {
						$vt = trim( $vn->textContent );
						if ( '' !== $vt ) {
							$values[] = $vt;
						}
					}
				} else {
					$raw_val = trim( $td->textContent );
					if ( '' !== $raw_val ) {
						$values = array_map( 'trim', explode( ',', $raw_val ) );
						$values = array_values( array_filter( $values, function ( $v ) {
							return '' !== $v;
						} ) );
					}
				}
				if ( '' !== $label && ! empty( $values ) ) {
					$attributes_table[] = array(
						'slug'   => $slug,
						'name'   => $label,
						'values' => $values,
					);
				}
			}
		}

		$price_data = $this->extract_price_and_variations( $xpath, $product_node, $product_type, $base_url );

		return array(
			'source'              => 'dom',
			'id'                  => $product_id,
			'code'                => $product_id,
			'name'                => $title,
			'sku'                 => $sku,
			'stock_status'        => $stock_status,
			'stock_quantity'      => $price_data['stock_quantity'],
			'description'         => $short_description,
			'content_html'        => $content_html,
			'categories'          => $categories,
			'tags'                => $tags,
			'gallery_images'      => $gallery_images,
			'attributes_table'    => $attributes_table,
			'product_type'        => $product_type,
			'regular_price'       => $price_data['regular_price'],
			'sale_price'          => $price_data['sale_price'],
			'variants'            => $price_data['variants'],
			'variation_attrs'     => $price_data['variation_attrs'],
		);
	}

	private function extract_price_and_variations( $xpath, $product_node, $product_type, $base_url ) {
		$result = array(
			'regular_price'   => 0,
			'sale_price'      => null,
			'stock_quantity'  => null,
			'variants'        => array(),
			'variation_attrs' => array(),
		);

		if ( 'variable' === $product_type ) {
			$form_node = $this->query_one( $xpath, ".//form[contains(concat(' ', normalize-space(@class), ' '), ' variations_form ')]", $product_node );
			if ( ! $form_node ) {
				return $result;
			}

			$raw_json = $form_node->getAttribute( 'data-product_variations' );
			$variations = array();
			if ( '' !== $raw_json && 'false' !== $raw_json ) {
				$decoded = json_decode( html_entity_decode( $raw_json, ENT_QUOTES, 'UTF-8' ), true );
				if ( is_array( $decoded ) ) {
					$variations = $decoded;
				}
			}

			$select_nodes = $xpath->query( ".//select[starts-with(@name,'attribute_')]", $form_node );
			$attr_meta    = array();
			if ( $select_nodes ) {
				foreach ( $select_nodes as $select ) {
					$name = $select->getAttribute( 'name' );
					$slug = preg_replace( '/^attribute_/', '', $name );
					$select_id = $select->getAttribute( 'id' );
					$label_text = $slug;
					if ( '' !== $select_id ) {
						$label_node = $this->query_one( $xpath, "//label[@for='" . $select_id . "']", $product_node->ownerDocument->documentElement );
						if ( $label_node ) {
							$label_text = trim( $label_node->textContent );
						}
					}
					$options = array();
					$option_nodes = $xpath->query( './/option', $select );
					if ( $option_nodes ) {
						foreach ( $option_nodes as $opt ) {
							$val = $opt->getAttribute( 'value' );
							if ( '' === $val ) {
								continue;
							}
							$options[ $val ] = trim( $opt->textContent );
						}
					}
					$attr_meta[ $name ] = array(
						'label'   => $label_text,
						'options' => $options,
					);
				}
			}

			$var_attr_values = array();

			foreach ( $variations as $var ) {
				if ( ! is_array( $var ) ) {
					continue;
				}
				$is_active  = array_key_exists( 'variation_is_active', $var ) ? (bool) $var['variation_is_active'] : true;
				$is_visible = array_key_exists( 'variation_is_visible', $var ) ? (bool) $var['variation_is_visible'] : true;
				if ( ! $is_active || ! $is_visible ) {
					continue;
				}

				$attr_map      = array();
				$summary_parts = array();
				$var_attributes = $this->array_get( $var, 'attributes', array() );
				if ( is_array( $var_attributes ) ) {
					foreach ( $var_attributes as $attr_key => $attr_val ) {
						$meta       = isset( $attr_meta[ $attr_key ] ) ? $attr_meta[ $attr_key ] : null;
						$attr_label = $meta ? $meta['label'] : preg_replace( '/^attribute_/', '', $attr_key );
						$value_label = $attr_val;
						if ( $meta && isset( $meta['options'][ $attr_val ] ) ) {
							$value_label = $meta['options'][ $attr_val ];
						}
						if ( '' === (string) $attr_val ) {
							continue;
						}
						$attr_map[ $attr_label ] = $value_label;
						$summary_parts[]         = $attr_label . ': ' . $value_label;

						if ( ! isset( $var_attr_values[ $attr_label ] ) ) {
							$var_attr_values[ $attr_label ] = array();
						}
						if ( ! in_array( $value_label, $var_attr_values[ $attr_label ], true ) ) {
							$var_attr_values[ $attr_label ][] = $value_label;
						}
					}
				}

				$is_in_stock = (bool) $this->array_get( $var, 'is_in_stock', true );
				$display_price         = $this->to_number( $this->array_get( $var, 'display_price', 0 ) );
				$display_regular_price = $this->to_number( $this->array_get( $var, 'display_regular_price', $display_price ) );
				$regular = $display_regular_price > 0 ? $display_regular_price : $display_price;
				$sale    = ( $display_price > 0 && $display_price < $regular ) ? $display_price : null;

				$max_qty = $this->array_get( $var, 'max_qty', '' );
				$qty     = ( is_numeric( $max_qty ) && '' !== $max_qty ) ? (int) $max_qty : null;

				$image_url = '';
				$image_obj = $this->array_get( $var, 'image', array() );
				if ( is_array( $image_obj ) ) {
					$image_url = $this->array_get( $image_obj, 'full_src', '' );
					if ( '' === $image_url ) {
						$image_url = $this->array_get( $image_obj, 'src', '' );
					}
				}
				if ( '' !== $image_url ) {
					$image_url = $this->make_absolute_url( $image_url, $base_url );
				}

				$extra_images = array();
				$extra_nodes  = $this->array_get( $var, 'additional_variation_images', array() );
				if ( is_array( $extra_nodes ) ) {
					foreach ( $extra_nodes as $ex ) {
						$src = is_array( $ex ) ? $this->array_get( $ex, 'full_src', $this->array_get( $ex, 'src', '' ) ) : '';
						if ( '' !== $src ) {
							$extra_images[] = $this->make_absolute_url( $src, $base_url );
						}
					}
				}

				$var_sku = (string) $this->array_get( $var, 'sku', '' );
				$var_id  = (string) $this->array_get( $var, 'variation_id', '' );

				$result['variants'][] = array(
					'id'                  => $var_id,
					'code'                => $var_id,
					'sku'                 => '' !== $var_sku ? $var_sku : $var_id,
					'attributes_summary'  => implode( ', ', $summary_parts ),
					'attributes_map'      => $attr_map,
					'regular_price'       => $regular,
					'sale_price'          => $sale,
					'stock_status'        => $is_in_stock ? 'in-stock' : 'out-of-stock',
					'stock_quantity'      => $qty,
					'image'               => $image_url,
					'extra_images'        => $extra_images,
				);
			}

			foreach ( $var_attr_values as $name => $values ) {
				$result['variation_attrs'][] = array(
					'name'   => $name,
					'values' => $values,
				);
			}

			if ( ! empty( $result['variants'] ) ) {
				$regular_prices = array_filter( array_column( $result['variants'], 'regular_price' ), function ( $v ) {
					return $v > 0;
				} );
				$result['regular_price'] = ! empty( $regular_prices ) ? min( $regular_prices ) : 0;

				$sale_prices = array_filter( array_column( $result['variants'], 'sale_price' ), function ( $v ) {
					return null !== $v;
				} );
				$result['sale_price'] = ! empty( $sale_prices ) ? min( $sale_prices ) : null;

				$qtys = array_filter( array_column( $result['variants'], 'stock_quantity' ), function ( $v ) {
					return null !== $v;
				} );
				$result['stock_quantity'] = ! empty( $qtys ) ? (int) array_sum( $qtys ) : null;
			}

			return $result;
		}

		// محصول ساده
		$summary_node = $this->query_one( $xpath, ".//div[contains(concat(' ', normalize-space(@class), ' '), ' summary ') and contains(concat(' ', normalize-space(@class), ' '), ' entry-summary ')]", $product_node );
		if ( ! $summary_node ) {
			$summary_node = $product_node;
		}

		$price_node = $this->query_one(
			$xpath,
			".//*[contains(concat(' ', normalize-space(@class), ' '), ' price ')][not(ancestor::*[contains(concat(' ', normalize-space(@class), ' '), ' wd-products-nav ')])][not(ancestor::*[contains(concat(' ', normalize-space(@class), ' '), ' related ')])][not(ancestor::*[contains(concat(' ', normalize-space(@class), ' '), ' upsells ')])]",
			$summary_node
		);

		if ( $price_node ) {
			$del_node = $this->query_one( $xpath, './/del', $price_node );
			$ins_node = $this->query_one( $xpath, './/ins', $price_node );
			if ( $del_node && $ins_node ) {
				$result['regular_price'] = $this->parse_price_text( $del_node->textContent );
				$result['sale_price']    = $this->parse_price_text( $ins_node->textContent );
			} else {
				$result['regular_price'] = $this->parse_price_text( $price_node->textContent );
				$result['sale_price']    = null;
			}
		}

		$stock_node = $this->query_one( $xpath, ".//*[contains(concat(' ', normalize-space(@class), ' '), ' stock ')]", $summary_node );
		if ( $stock_node ) {
			$stock_text = trim( $stock_node->textContent );
			if ( preg_match( '/([\d,]+)/', $stock_text, $m ) ) {
				$result['stock_quantity'] = (int) str_replace( ',', '', $m[1] );
			}
		}
		if ( null === $result['stock_quantity'] ) {
			$qty_node = $this->query_one( $xpath, ".//input[contains(concat(' ', normalize-space(@class), ' '), ' qty ')]", $summary_node );
			if ( $qty_node ) {
				$max_attr = $qty_node->getAttribute( 'max' );
				if ( is_numeric( $max_attr ) && '' !== $max_attr ) {
					$result['stock_quantity'] = (int) $max_attr;
				}
			}
		}

		return $result;
	}

	private function find_main_product_node( $xpath ) {
		$nodes = $xpath->query( "//*[starts-with(@id,'product-') and contains(concat(' ', normalize-space(@class), ' '), ' type-product ')]" );
		if ( $nodes && $nodes->length > 0 ) {
			return $nodes->item( 0 );
		}
		$nodes = $xpath->query( "//div[contains(concat(' ', normalize-space(@class), ' '), ' single-product-content ')]" );
		if ( $nodes && $nodes->length > 0 ) {
			return $nodes->item( 0 );
		}
		$nodes = $xpath->query( "//*[contains(concat(' ', normalize-space(@class), ' '), ' type-product ')]" );
		if ( $nodes && $nodes->length > 0 ) {
			return $nodes->item( 0 );
		}
		return null;
	}

	private function load_dom( $html ) {
		if ( empty( $html ) || ! is_string( $html ) ) {
			return null;
		}
		$prev = libxml_use_internal_errors( true );
		$doc  = new DOMDocument( '1.0', 'UTF-8' );
		$ok   = $doc->loadHTML( '<?xml encoding="UTF-8">' . $html, LIBXML_NOERROR | LIBXML_NOWARNING );
		libxml_clear_errors();
		libxml_use_internal_errors( $prev );
		if ( ! $ok ) {
			return null;
		}
		return $doc;
	}

	private function query_one( $xpath, $query, $context = null ) {
		try {
			$nodes = $context ? $xpath->query( $query, $context ) : $xpath->query( $query );
		} catch ( \Throwable $e ) {
			return null;
		}
		if ( $nodes && $nodes->length > 0 ) {
			return $nodes->item( 0 );
		}
		return null;
	}

	private function node_text( $node ) {
		if ( ! $node ) {
			return '';
		}
		return $node->textContent;
	}

	private function inner_html( $node ) {
		if ( ! $node ) {
			return '';
		}
		$html = '';
		foreach ( $node->childNodes as $child ) {
			$html .= $node->ownerDocument->saveHTML( $child );
		}
		return trim( $html );
	}

	/* ==================== FALLBACK: JSON-LD ==================== */

	private function extract_from_json_ld( $html, $base_url ) {
		if ( ! preg_match_all( '/<script[^>]*type=["\']application\/ld\+json["\'][^>]*>(.*?)<\/script>/is', $html, $matches ) ) {
			return null;
		}
		if ( empty( $matches[1] ) ) {
			return null;
		}

		foreach ( $matches[1] as $block ) {
			$json = json_decode( trim( $block ), true );
			if ( ! is_array( $json ) ) {
				continue;
			}
			$product = $this->find_json_ld_product( $json );
			if ( ! $product ) {
				continue;
			}

			$offers = $this->array_get( $product, 'offers', array() );
			if ( isset( $offers[0] ) && is_array( $offers[0] ) ) {
				$offers = $offers[0];
			}
			$availability = isset( $offers['availability'] ) ? (string) $offers['availability'] : '';

			$brand      = $this->array_get( $product, 'brand', array() );
			$brand_name = is_array( $brand ) ? (string) $this->array_get( $brand, 'name', '' ) : ( is_string( $brand ) ? $brand : '' );

			return array(
				'source'         => 'jsonld',
				'name'           => (string) $this->array_get( $product, 'name', '' ),
				'sku'            => (string) $this->array_get( $product, 'sku', '' ),
				'description'    => (string) $this->array_get( $product, 'description', '' ),
				'brand_name'     => $brand_name,
				'jsonld_in_stock' => ( false !== stripos( $availability, 'InStock' ) ),
			);
		}

		return null;
	}

	private function find_json_ld_product( $data ) {
		if ( ! is_array( $data ) ) {
			return null;
		}
		if ( isset( $data['@type'] ) ) {
			$types = is_array( $data['@type'] ) ? $data['@type'] : array( $data['@type'] );
			foreach ( $types as $t ) {
				if ( is_string( $t ) && 'product' === strtolower( $t ) ) {
					return $data;
				}
			}
		}
		if ( isset( $data['@graph'] ) && is_array( $data['@graph'] ) ) {
			foreach ( $data['@graph'] as $item ) {
				$found = $this->find_json_ld_product( $item );
				if ( $found ) {
					return $found;
				}
			}
		}
		foreach ( $data as $item ) {
			if ( is_array( $item ) ) {
				$found = $this->find_json_ld_product( $item );
				if ( $found ) {
					return $found;
				}
			}
		}
		return null;
	}

	/* ==================== NORMALIZE ==================== */

	private function normalize_extracted_data( $raw, $base_url ) {
		$product = is_array( $raw ) ? $raw : array();

		$product_id = (string) $this->array_get( $product, 'id', '' );
		if ( '' === $product_id ) {
			$product_id = (string) $this->array_get( $product, 'code', '' );
		}

		$sku = (string) $this->array_get( $product, 'sku', '' );

		$title = (string) $this->array_get( $product, 'name', '' );

		$excerpt = (string) $this->array_get( $product, 'description', '' );

		$content_html = (string) $this->array_get( $product, 'content_html', '' );
		if ( '' === trim( wp_strip_all_tags( $content_html ) ) && '' !== $excerpt ) {
			$content_html = '<p>' . esc_html( $excerpt ) . '</p>';
		}

		$categories = $this->array_get( $product, 'categories', array() );
		$categories = is_array( $categories ) ? array_values( array_unique( $categories ) ) : array();

		$tags = $this->array_get( $product, 'tags', array() );
		$tags = is_array( $tags ) ? array_values( array_unique( $tags ) ) : array();

		$gallery_images = $this->array_get( $product, 'gallery_images', array() );
		$gallery_images = is_array( $gallery_images ) ? array_values( $gallery_images ) : array();
		$featured_image = ! empty( $gallery_images ) ? $gallery_images[0] : '';
		$rest_gallery   = ! empty( $gallery_images ) ? array_slice( $gallery_images, 1 ) : array();

		$attributes = array();
		$attributes_table = $this->array_get( $product, 'attributes_table', array() );
		$variation_attrs   = $this->array_get( $product, 'variation_attrs', array() );

		$variation_slugs = array();
		if ( is_array( $variation_attrs ) ) {
			foreach ( $variation_attrs as $va ) {
				$variation_slugs[] = isset( $va['name'] ) ? $va['name'] : '';
			}
		}

		if ( is_array( $attributes_table ) ) {
			foreach ( $attributes_table as $row ) {
				$name   = isset( $row['name'] ) ? $row['name'] : '';
				$values = isset( $row['values'] ) && is_array( $row['values'] ) ? $row['values'] : array();
				if ( '' === $name || empty( $values ) ) {
					continue;
				}
				$is_variation_attr = in_array( $name, $variation_slugs, true );
				$attributes[] = array(
					'name'                => $name,
					'values'              => $values,
					'used_for_variations' => $is_variation_attr,
				);
			}
		}

		foreach ( $variation_slugs as $vname ) {
			$already = false;
			foreach ( $attributes as $a ) {
				if ( $a['name'] === $vname ) {
					$already = true;
					break;
				}
			}
			if ( ! $already && is_array( $variation_attrs ) ) {
				foreach ( $variation_attrs as $va ) {
					if ( isset( $va['name'] ) && $va['name'] === $vname ) {
						$attributes[] = array(
							'name'                => $va['name'],
							'values'              => isset( $va['values'] ) ? $va['values'] : array(),
							'used_for_variations' => true,
						);
					}
				}
			}
		}

		$brand_name = (string) $this->array_get( $product, 'brand_name', '' );
		if ( '' !== $brand_name ) {
			$has_brand_attr = false;
			foreach ( $attributes as $attr ) {
				if ( 'برند' === $attr['name'] ) {
					$has_brand_attr = true;
					break;
				}
			}
			if ( ! $has_brand_attr ) {
				$attributes[] = array(
					'name'                => 'برند',
					'values'              => array( $brand_name ),
					'used_for_variations' => false,
				);
			}
		}

		$product_type = (string) $this->array_get( $product, 'product_type', 'simple' );
		$variants_raw = $this->array_get( $product, 'variants', array() );
		$variants_raw = is_array( $variants_raw ) ? $variants_raw : array();

		$variations = array();
		foreach ( $variants_raw as $var ) {
			if ( ! is_array( $var ) ) {
				continue;
			}
			$var_image = isset( $var['image'] ) ? $var['image'] : '';
			$variations[] = array(
				'attributes_summary' => isset( $var['attributes_summary'] ) ? $var['attributes_summary'] : '',
				'attributes_map'     => isset( $var['attributes_map'] ) ? $var['attributes_map'] : array(),
				'sku'                => isset( $var['sku'] ) ? $var['sku'] : '',
				'code'               => isset( $var['code'] ) ? $var['code'] : '',
				'regular_price'      => isset( $var['regular_price'] ) ? $var['regular_price'] : 0,
				'sale_price'         => isset( $var['sale_price'] ) ? $var['sale_price'] : null,
				'stock_status'       => isset( $var['stock_status'] ) ? $var['stock_status'] : 'out-of-stock',
				'stock_quantity'     => isset( $var['stock_quantity'] ) ? $var['stock_quantity'] : null,
				'image'              => $var_image,
			);
		}

		$regular_price = $this->to_number( $this->array_get( $product, 'regular_price', 0 ) );
		$sale_price_raw = $this->array_get( $product, 'sale_price', null );
		$sale_price     = ( null !== $sale_price_raw ) ? $this->to_number( $sale_price_raw ) : null;

		$stock_status   = (string) $this->array_get( $product, 'stock_status', 'out-of-stock' );
		$stock_quantity = $this->array_get( $product, 'stock_quantity', -1 );
		if ( -1 !== $stock_quantity ) {
			$stock_quantity = (int) $stock_quantity;
		}

		if ( 'jsonld' === $this->array_get( $product, 'source', '' ) ) {
			$stock_status = ! empty( $product['jsonld_in_stock'] ) ? 'in-stock' : 'out-of-stock';
		}

		return array(
			'product_id'     => $product_id,
			'sku'            => '' !== $sku ? $sku : $product_id,
			'title'          => $title,
			'excerpt'        => $excerpt,
			'content'        => $content_html,
			'featured_image' => $featured_image,
			'gallery_images' => $rest_gallery,
			'regular_price'  => $regular_price,
			'sale_price'     => $sale_price,
			'currency'       => 'تومان',
			'stock_status'   => $stock_status,
			'stock_quantity' => $stock_quantity,
			'categories'     => $categories,
			'tags'           => $tags,
			'product_type'   => $product_type,
			'attributes'     => $attributes,
			'variations'     => $variations,
		);
	}

	/* ==================== HELPERS ==================== */
	private function display_source_data_sections( $source_data ) {
		if ( empty( $source_data ) || ! is_array( $source_data ) ) return;
		$sections = array(
			'هویت و منبع' => array( 'روش استخراج' => isset( $source_data['extracted_via'] ) ? $source_data['extracted_via'] : '', 'URL منبع' => isset( $source_data['source_url'] ) ? $source_data['source_url'] : '', 'هویت محصول' => isset( $source_data['identity'] ) ? $source_data['identity'] : array() ),
			'اطلاعات صفحه و سئو' => isset( $source_data['document'] ) ? $source_data['document'] : array(),
			'محتوای کامل محصول' => isset( $source_data['product_content'] ) ? $source_data['product_content'] : array(),
			'انتخاب‌گرهای ویژگی' => isset( $source_data['variation_selectors'] ) ? $source_data['variation_selectors'] : array(),
			'دادهٔ کامل واریانت‌های ووکامرس' => isset( $source_data['woocommerce_variations'] ) ? $source_data['woocommerce_variations'] : array(),
			'محصول JSON-LD' => isset( $source_data['json_ld_product'] ) ? $source_data['json_ld_product'] : array(),
			'تمام اسناد JSON-LD صفحه' => isset( $source_data['json_ld_documents'] ) ? $source_data['json_ld_documents'] : array(),
		);
		echo '<style>.vgr-source-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(380px,1fr));gap:14px;max-width:1200px}.vgr-source-card{background:#fff;border:1px solid #ccd0d4;border-radius:6px;padding:14px;overflow:auto}.vgr-source-card pre{white-space:pre-wrap;word-break:break-word;max-height:520px;overflow:auto;background:#f6f7f7;padding:10px}.vgr-source-card table{width:100%;border-collapse:collapse}.vgr-source-card th,.vgr-source-card td{padding:7px;border-bottom:1px solid #eee;text-align:right;vertical-align:top}.vgr-source-card th{width:28%}</style>';
		echo '<hr><h2>تمام اطلاعات قابل استخراج از منبع</h2><div class="vgr-source-grid">';
		foreach ( $sections as $heading => $values ) {
			echo '<section class="vgr-source-card"><h3>' . esc_html( $heading ) . '</h3>';
			if ( is_array( $values ) && $this->is_assoc( $values ) ) {
				echo '<table><tbody>'; foreach ( $values as $key => $value ) { echo '<tr><th>' . esc_html( (string) $key ) . '</th><td>' . $this->render_source_value( $value ) . '</td></tr>'; } echo '</tbody></table>';
			} else { echo '<pre>' . esc_html( wp_json_encode( $values, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT ) ) . '</pre>'; }
			echo '</section>';
		}
		echo '<section class="vgr-source-card" style="grid-column:1/-1"><h3>JSON کامل source_data</h3><pre>' . esc_html( wp_json_encode( $source_data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT ) ) . '</pre></section></div>';
	}

	private function render_source_value( $value ) {
		if ( is_bool( $value ) ) return $value ? 'بله' : 'خیر';
		if ( null === $value || '' === $value ) return '<span style="color:#777">—</span>';
		if ( is_array( $value ) || is_object( $value ) ) return '<pre>' . esc_html( wp_json_encode( $value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT ) ) . '</pre>';
		$value = (string) $value;
		if ( preg_match( '#^https?://#i', $value ) ) return '<a href="' . esc_url( $value ) . '" target="_blank" rel="noopener">' . esc_html( $value ) . '</a>';
		return nl2br( esc_html( $value ) );
	}

	private function is_assoc( $array ) {
		if ( ! is_array( $array ) || array() === $array ) return false;
		return array_keys( $array ) !== range( 0, count( $array ) - 1 );
	}

	private function parse_price_text( $text ) {
		if ( null === $text ) {
			return 0;
		}
		$text = (string) $text;
		$text = str_replace( array( "\xC2\xA0", ',', ' ' ), '', $text );
		if ( preg_match( '/[\d]+(?:\.[\d]+)?/', $text, $m ) ) {
			return (float) $m[0];
		}
		return 0;
	}

	private function to_number( $value ) {
		if ( null === $value || '' === $value ) {
			return 0;
		}
		if ( is_numeric( $value ) ) {
			return (float) $value;
		}
		return $this->parse_price_text( (string) $value );
	}

	private function array_get( $array, $key, $default = null ) {
		if ( ! is_array( $array ) ) {
			return $default;
		}
		if ( ! array_key_exists( $key, $array ) ) {
			return $default;
		}
		$value = $array[ $key ];
		return ( null === $value ) ? $default : $value;
	}

	private function make_absolute_url( $maybe_url, $base_url ) {
		if ( empty( $maybe_url ) || ! is_string( $maybe_url ) ) {
			return '';
		}
		if ( preg_match( '#^https?://#i', $maybe_url ) ) {
			return $maybe_url;
		}
		$parts = wp_parse_url( $base_url );
		if ( empty( $parts ) || empty( $parts['scheme'] ) || empty( $parts['host'] ) ) {
			return $maybe_url;
		}
		$origin = $parts['scheme'] . '://' . $parts['host'] . ( isset( $parts['port'] ) ? ':' . $parts['port'] : '' );
		if ( 0 === strpos( $maybe_url, '/' ) ) {
			return $origin . $maybe_url;
		}
		return rtrim( $origin, '/' ) . '/' . ltrim( $maybe_url, '/' );
	}
}
