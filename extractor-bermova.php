<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once plugin_dir_path( __FILE__ ) . 'class-product-dto.php';

class Bermova_Product_Extractor {

	const MENU_SLUG = 'bermova-product-extractor';
	const NONCE_ACTION = 'bermova_extractor_action';

	/**
	 * Complete, trustworthy first-party product payload used by the manual viewer.
	 *
	 * @var array
	 */
	private $last_source_data = array();

	public function __construct() {
		add_action( 'admin_menu', array( $this, 'register_admin_page' ) );
	}
	
	public function register_admin_page() {
		add_submenu_page(
			'edit.php?post_type=source_profile',
			'Bermova Extractor',
			'Bermova Extractor',
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

		if ( isset( $_POST['bermova_extractor_submit'] ) ) {
			check_admin_referer( self::NONCE_ACTION );

			$url = isset( $_POST['bermova_url'] ) ? esc_url_raw( wp_unslash( $_POST['bermova_url'] ) ) : '';

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
			<h1>Bermova Product Extractor</h1>
			<style>
				.bermova-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(320px,1fr));gap:16px;max-width:1400px;margin:16px 0}.bermova-card{background:#fff;border:1px solid #c3c4c7;border-radius:8px;padding:16px;box-shadow:0 1px 2px rgba(0,0,0,.04)}.bermova-card h3{margin:0 0 12px}.bermova-card h4{margin:16px 0 8px}.bermova-wide{grid-column:1/-1}.bermova-table{width:100%;border-collapse:collapse}.bermova-table th,.bermova-table td{padding:8px 10px;border-bottom:1px solid #e2e4e7;text-align:right;vertical-align:top}.bermova-table th{width:190px;color:#50575e}.bermova-badges{display:flex;flex-wrap:wrap;gap:6px}.bermova-badge{display:inline-block;padding:4px 9px;border-radius:999px;background:#f0f0f1}.bermova-images{display:grid;grid-template-columns:repeat(auto-fill,minmax(180px,1fr));gap:12px}.bermova-image{border:1px solid #dcdcde;border-radius:6px;padding:8px}.bermova-image img{width:100%;height:170px;object-fit:contain;background:#f6f7f7}.bermova-image dl{margin:8px 0 0;font-size:12px;word-break:break-word}.bermova-image dt{font-weight:600}.bermova-image dd{margin:0 0 6px}.bermova-scroll{overflow:auto}.bermova-json{max-height:700px;overflow:auto;white-space:pre-wrap;word-break:break-word;direction:ltr;text-align:left;background:#1d2327;color:#f0f0f1;padding:14px;border-radius:6px}.bermova-muted{color:#646970}.bermova-section-title{margin-top:28px!important;border-bottom:1px solid #c3c4c7;padding-bottom:8px}.bermova-content{line-height:1.9}
			</style>
			<form method="post">
				<?php wp_nonce_field( self::NONCE_ACTION ); ?>
				<table class="form-table">
					<tr>
						<th scope="row"><label for="bermova_url">آدرس محصول</label></th>
						<td>
							<input type="url" id="bermova_url" name="bermova_url" class="regular-text" required
								value="<?php echo esc_attr( $url ); ?>" placeholder="https://bermova.com/product/..." />
						</td>
					</tr>
				</table>
				<p class="submit">
					<input type="submit" name="bermova_extractor_submit" class="button button-primary" value="استخراج اطلاعات" />
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
		$currency       = isset( $data['currency'] ) ? $data['currency'] : '';
		$stock_status   = isset( $data['stock_status'] ) ? $data['stock_status'] : '';
		$stock_quantity = isset( $data['stock_quantity'] ) ? $data['stock_quantity'] : 0;
		$manage_stock   = ! empty( $data['manage_stock'] );
		$categories     = isset( $data['categories'] ) && is_array( $data['categories'] ) ? $data['categories'] : array();
		$featured_image = isset( $data['featured_image'] ) ? $data['featured_image'] : '';
		$gallery_images = isset( $data['gallery_images'] ) && is_array( $data['gallery_images'] ) ? $data['gallery_images'] : array();
		$attributes     = isset( $data['attributes'] ) && is_array( $data['attributes'] ) ? $data['attributes'] : array();
		$variations     = isset( $data['variations'] ) && is_array( $data['variations'] ) ? $data['variations'] : array();
		$source_data    = isset( $data['source_data'] ) && is_array( $data['source_data'] ) ? $data['source_data'] : $this->last_source_data;
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
				<tr><th>تعداد موجودی</th><td><?php echo esc_html( $stock_quantity ); ?></td></tr>
				<tr><th>مدیریت تعداد موجودی</th><td><?php echo $manage_stock ? 'بله' : 'خیر'; ?></td></tr>
				<tr><th>دسته‌بندی</th><td><?php echo esc_html( implode( ', ', $categories ) ); ?></td></tr>
				<tr><th>خلاصه</th><td><?php echo esc_html( $excerpt ); ?></td></tr>
			</tbody>
		</table>

		<?php if ( '' !== trim( wp_strip_all_tags( $content ) ) ) : ?>
			<h3>توضیحات اصلی (<?php echo (int) mb_strlen( wp_strip_all_tags( $content ) ); ?> کاراکتر)</h3>
			<div style="max-width:900px;max-height:400px;overflow:auto;border:1px solid #ccd0d4;background:#fff;padding:12px 16px;">
				<?php echo wp_kses_post( $content ); ?>
			</div>
		<?php else : ?>
			<div class="notice notice-warning inline"><p>توضیحات اصلی (محتوای review_content) برای این محصول یافت نشد.</p></div>
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
						<th>ویژگی</th><th>SKU</th><th>کد</th><th>قیمت اصلی</th><th>قیمت با تخفیف</th>
						<th>وضعیت موجودی</th><th>تعداد موجودی</th><th>مدیریت موجودی</th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $variations as $var ) : ?>
						<?php
						$v_summary  = isset( $var['attributes_summary'] ) ? $var['attributes_summary'] : '';
						$v_sku      = isset( $var['sku'] ) ? $var['sku'] : '';
						$v_code     = isset( $var['code'] ) ? $var['code'] : '';
						$v_regular  = isset( $var['regular_price'] ) ? $var['regular_price'] : 0;
						$v_sale     = isset( $var['sale_price'] ) ? $var['sale_price'] : null;
						$v_status   = isset( $var['stock_status'] ) ? $var['stock_status'] : '';
						$v_qty      = isset( $var['stock_quantity'] ) ? $var['stock_quantity'] : 0;
						$v_manage   = ! empty( $var['manage_stock'] );
						?>
						<tr>
							<td><?php echo esc_html( $v_summary ); ?></td>
							<td><?php echo esc_html( $v_sku ); ?></td>
							<td><?php echo esc_html( $v_code ); ?></td>
							<td><?php echo esc_html( number_format( (float) $v_regular ) ); ?></td>
							<td><?php echo ( null !== $v_sale ) ? esc_html( number_format( (float) $v_sale ) ) : '-'; ?></td>
							<td><?php echo esc_html( $v_status ); ?></td>
							<td><?php echo esc_html( $v_qty ); ?></td>
							<td><?php echo $v_manage ? 'بله' : 'خیر'; ?></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		<?php endif; ?>

		<?php if ( ! empty( $source_data ) ) : ?>
			<?php $this->display_source_data_sections( $source_data ); ?>
		<?php endif; ?>
		<?php
	}

	private function display_source_data_sections( $source_data ) {
		$payload = isset( $source_data['payload'] ) && is_array( $source_data['payload'] ) ? $source_data['payload'] : array();
		if ( isset( $payload['source_payload'] ) && is_array( $payload['source_payload'] ) ) {
			$payload = $payload['source_payload'];
		}
		if ( empty( $payload ) ) {
			return;
		}

		$brand       = isset( $payload['brand'] ) && is_array( $payload['brand'] ) ? $payload['brand'] : array();
		$breadcrumb  = isset( $payload['breadcrumb'] ) && is_array( $payload['breadcrumb'] ) ? $payload['breadcrumb'] : array();
		$images      = isset( $payload['images'] ) && is_array( $payload['images'] ) ? $payload['images'] : array();
		$specs       = isset( $payload['specs'] ) && is_array( $payload['specs'] ) ? $payload['specs'] : array();
		$variants    = isset( $payload['variants'] ) && is_array( $payload['variants'] ) ? $payload['variants'] : array();
		$review      = isset( $payload['review_content'] ) && is_array( $payload['review_content'] ) ? $payload['review_content'] : array();
		$source_json = wp_json_encode( $source_data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT );
		?>
		<h2 class="bermova-section-title">اطلاعات کامل و قابل اتکای منبع برموا</h2>
		<p class="description">تمام بخش‌های زیر مستقیماً از payload ساختاریافتهٔ عمومی صفحهٔ محصول استخراج شده‌اند.</p>

		<div class="bermova-grid">
			<section class="bermova-card">
				<h3>منبع استخراج</h3>
				<table class="bermova-table"><tbody>
					<?php $this->render_source_row( 'روش استخراج', $source_data['extracted_via'] ?? '' ); ?>
					<?php $this->render_source_row( 'URL منبع', $source_data['source_url'] ?? '' ); ?>
					<?php $this->render_source_row( 'شناسه داخلی', $payload['id'] ?? '' ); ?>
					<?php $this->render_source_row( 'کد محصول', $payload['code'] ?? '' ); ?>
					<?php $this->render_source_row( 'Slug', $payload['slug'] ?? '' ); ?>
					<?php $this->render_source_row( 'وضعیت', $payload['status'] ?? '' ); ?>
				</tbody></table>
			</section>

			<section class="bermova-card">
				<h3>SEO و انتشار</h3>
				<table class="bermova-table"><tbody>
					<?php $this->render_source_row( 'عنوان SEO', $payload['seo_title'] ?? '' ); ?>
					<?php $this->render_source_row( 'توضیح SEO', $payload['seo_description'] ?? '' ); ?>
					<?php $this->render_source_row( 'Noindex', $this->source_yes_no( $payload['noindex'] ?? null ) ); ?>
					<?php $this->render_source_row( 'تصویر Open Graph', $payload['og_image_url'] ?? ( $payload['og_image_auto_url'] ?? '' ) ); ?>
					<?php $this->render_source_row( 'تاریخ انتشار', $payload['published_at'] ?? '' ); ?>
					<?php $this->render_source_row( 'تاریخ ایجاد', $payload['created_at'] ?? '' ); ?>
					<?php $this->render_source_row( 'آخرین بروزرسانی', $payload['updated_at'] ?? '' ); ?>
				</tbody></table>
			</section>

			<section class="bermova-card">
				<h3>وضعیت و بازخورد</h3>
				<table class="bermova-table"><tbody>
					<?php $this->render_source_row( 'امتیاز میانگین', $payload['rating_avg'] ?? '' ); ?>
					<?php $this->render_source_row( 'تعداد امتیاز', $payload['rating_count'] ?? '' ); ?>
					<?php $this->render_source_row( 'ارسال سریع', $this->source_yes_no( $payload['fast_delivery'] ?? null ) ); ?>
					<?php $this->render_source_row( 'شناسه دسته', $payload['category_id'] ?? '' ); ?>
					<?php $this->render_source_row( 'شناسه برند', $payload['brand_id'] ?? '' ); ?>
				</tbody></table>
			</section>

			<section class="bermova-card">
				<h3>برند</h3>
				<?php if ( ! empty( $brand ) ) : ?>
					<table class="bermova-table"><tbody>
						<?php foreach ( $brand as $key => $value ) : ?>
							<?php if ( ! is_array( $value ) && ! is_object( $value ) ) : ?>
								<?php $this->render_source_row( $this->source_field_label( $key ), $this->source_scalar( $value ) ); ?>
							<?php endif; ?>
						<?php endforeach; ?>
					</tbody></table>
				<?php else : ?><p class="bermova-muted">اطلاعات برند موجود نیست.</p><?php endif; ?>
			</section>

			<section class="bermova-card bermova-wide">
				<h3>مسیر کامل دسته‌بندی</h3>
				<div class="bermova-badges">
					<?php foreach ( $breadcrumb as $category ) : ?>
						<?php if ( is_array( $category ) ) : ?>
							<span class="bermova-badge" title="<?php echo esc_attr( $category['id'] ?? '' ); ?>"><?php echo esc_html( $category['name'] ?? ( $category['slug'] ?? '' ) ); ?></span>
						<?php endif; ?>
					<?php endforeach; ?>
				</div>
			</section>

			<section class="bermova-card bermova-wide">
				<h3>تصاویر منبع و متادیتا (<?php echo (int) count( $images ); ?>)</h3>
				<div class="bermova-images">
					<?php foreach ( $images as $image ) : ?>
						<?php if ( ! is_array( $image ) ) { continue; } ?>
						<div class="bermova-image">
							<?php if ( ! empty( $image['image_url'] ) ) : ?><img src="<?php echo esc_url( $image['image_url'] ); ?>" alt="<?php echo esc_attr( $image['alt_text'] ?? '' ); ?>" loading="lazy" /><?php endif; ?>
							<dl>
								<dt>Alt</dt><dd><?php echo esc_html( $image['alt_text'] ?? '-' ); ?></dd>
								<dt>موقعیت</dt><dd><?php echo esc_html( $image['position'] ?? '-' ); ?></dd>
								<dt>شناسه تصویر</dt><dd><?php echo esc_html( $image['id'] ?? '-' ); ?></dd>
								<dt>شناسه واریانت</dt><dd><?php echo esc_html( $image['variant_id'] ?? '-' ); ?></dd>
								<dt>URL</dt><dd><a href="<?php echo esc_url( $image['image_url'] ?? '' ); ?>" target="_blank" rel="noopener noreferrer">مشاهده فایل</a></dd>
							</dl>
						</div>
					<?php endforeach; ?>
				</div>
			</section>

			<section class="bermova-card bermova-wide bermova-scroll">
				<h3>مشخصات فنی منبع (<?php echo (int) count( $specs ); ?>)</h3>
				<table class="widefat striped"><thead><tr><th>نام</th><th>کد</th><th>نوع</th><th>مقدار</th><th>واحد</th><th>گروه</th><th>شناسه Attribute</th><th>شناسه Optionها</th></tr></thead><tbody>
				<?php foreach ( $specs as $spec ) : ?>
					<?php if ( ! is_array( $spec ) ) { continue; } ?>
					<tr><td><?php echo esc_html( $spec['attribute_name'] ?? '' ); ?></td><td><?php echo esc_html( $spec['attribute_code'] ?? '' ); ?></td><td><?php echo esc_html( $spec['type'] ?? '' ); ?></td><td><?php echo esc_html( $this->source_spec_value( $spec ) ); ?></td><td><?php echo esc_html( $spec['unit'] ?? '' ); ?></td><td><?php echo esc_html( $spec['group'] ?? '' ); ?></td><td><?php echo esc_html( $spec['attribute_id'] ?? '' ); ?></td><td><?php echo esc_html( implode( ' | ', array_map( 'strval', (array) ( $spec['option_ids'] ?? array() ) ) ) ); ?></td></tr>
				<?php endforeach; ?>
				</tbody></table>
			</section>

			<section class="bermova-card bermova-wide bermova-scroll">
				<h3>واریانت‌های کامل منبع (<?php echo (int) count( $variants ); ?>)</h3>
				<table class="widefat striped"><thead><tr><th>عنوان/ویژگی</th><th>شناسه</th><th>کد</th><th>SKU</th><th>قیمت اصلی</th><th>قیمت تخفیف</th><th>ارز</th><th>موجودی</th><th>Available</th><th>پیش‌فرض</th><th>فعال</th><th>موقعیت</th><th>ایجاد/بروزرسانی</th></tr></thead><tbody>
				<?php foreach ( $variants as $variant ) : ?>
					<?php if ( ! is_array( $variant ) ) { continue; } ?>
					<tr><td><?php echo esc_html( $this->source_variant_title( $variant ) ); ?></td><td><?php echo esc_html( $variant['id'] ?? '' ); ?></td><td><?php echo esc_html( $variant['code'] ?? '' ); ?></td><td><?php echo esc_html( $variant['sku'] ?? '' ); ?></td><td><?php echo esc_html( $this->source_price( $variant['base_price_amount'] ?? null ) ); ?></td><td><?php echo esc_html( $this->source_price( $variant['discount_price_amount'] ?? null ) ); ?></td><td><?php echo esc_html( $variant['currency_code'] ?? '' ); ?></td><td><?php echo esc_html( $variant['stock_quantity'] ?? 'نامشخص' ); ?></td><td><?php echo esc_html( $variant['available'] ?? '' ); ?></td><td><?php echo esc_html( $this->source_yes_no( $variant['is_default'] ?? null ) ); ?></td><td><?php echo esc_html( $this->source_yes_no( $variant['is_active'] ?? null ) ); ?></td><td><?php echo esc_html( $variant['position'] ?? '' ); ?></td><td><?php echo esc_html( ( $variant['created_at'] ?? '' ) . ' / ' . ( $variant['updated_at'] ?? '' ) ); ?></td></tr>
				<?php endforeach; ?>
				</tbody></table>
			</section>

			<?php if ( ! empty( $review ) ) : ?>
			<section class="bermova-card bermova-wide">
				<h3>ساختار محتوای بررسی تخصصی</h3>
				<table class="bermova-table"><tbody>
					<?php $this->render_source_row( 'تعداد بلوک‌ها', count( (array) ( $review['blocks'] ?? array() ) ) ); ?>
					<?php $this->render_source_row( 'نوع بلوک‌ها', implode( '، ', $this->source_review_types( $review ) ) ); ?>
				</tbody></table>
			</section>
			<?php endif; ?>

			<section class="bermova-card bermova-wide">
				<h3>سایر فیلدهای سطح محصول</h3>
				<table class="bermova-table"><tbody>
				<?php foreach ( $payload as $key => $value ) : ?>
					<?php if ( in_array( $key, array( 'brand', 'breadcrumb', 'images', 'specs', 'variants', 'review_content', 'source_payload' ), true ) || is_array( $value ) || is_object( $value ) ) { continue; } ?>
					<?php $this->render_source_row( $this->source_field_label( $key ), $this->source_scalar( $value ) ); ?>
				<?php endforeach; ?>
				</tbody></table>
			</section>

			<section class="bermova-card bermova-wide">
				<details>
					<summary style="cursor:pointer;font-weight:600;">JSON کامل و بدون حذف منبع</summary>
					<pre class="bermova-json"><?php echo esc_html( false !== $source_json ? $source_json : '{}' ); ?></pre>
				</details>
			</section>
		</div>
		<?php
	}

	private function render_source_row( $label, $value ) {
		echo '<tr><th>' . esc_html( $label ) . '</th><td>' . esc_html( $this->source_scalar( $value ) ) . '</td></tr>';
	}

	private function source_scalar( $value ) {
		if ( null === $value || '' === $value ) { return '-'; }
		if ( is_bool( $value ) ) { return $value ? 'بله' : 'خیر'; }
		return (string) $value;
	}

	private function source_yes_no( $value ) {
		if ( null === $value || '' === $value ) { return 'نامشخص'; }
		return (bool) $value ? 'بله' : 'خیر';
	}

	private function source_field_label( $key ) {
		$labels = array( 'seo_title'=>'عنوان SEO','seo_description'=>'توضیح SEO','og_image_url'=>'تصویر Open Graph','og_image_auto_url'=>'تصویر Open Graph خودکار','noindex'=>'Noindex','id'=>'شناسه','code'=>'کد','name'=>'نام','slug'=>'Slug','description'=>'توضیحات','category_id'=>'شناسه دسته','brand_id'=>'شناسه برند','status'=>'وضعیت','published_at'=>'تاریخ انتشار','rating_avg'=>'میانگین امتیاز','rating_count'=>'تعداد امتیاز','fast_delivery'=>'ارسال سریع','created_at'=>'تاریخ ایجاد','updated_at'=>'آخرین بروزرسانی','logo_url'=>'لوگوی برند' );
		return isset( $labels[ $key ] ) ? $labels[ $key ] : str_replace( '_', ' ', (string) $key );
	}

	private function source_spec_value( $spec ) {
		$values = isset( $spec['values'] ) && is_array( $spec['values'] ) ? $spec['values'] : array();
		if ( ! empty( $values ) ) { return implode( ' | ', array_map( 'strval', $values ) ); }
		foreach ( array( 'value_text', 'value_int', 'value_decimal', 'value_bool' ) as $key ) {
			if ( array_key_exists( $key, $spec ) && null !== $spec[ $key ] ) { return is_bool( $spec[ $key ] ) ? ( $spec[ $key ] ? 'بله' : 'خیر' ) : (string) $spec[ $key ]; }
		}
		return '-';
	}

	private function source_variant_title( $variant ) {
		$parts = array();
		foreach ( (array) ( $variant['axis_values'] ?? array() ) as $axis ) {
			if ( is_array( $axis ) ) { $parts[] = ( $axis['attribute_name'] ?? $axis['attribute_code'] ?? '' ) . ': ' . ( $axis['label'] ?? $axis['option_label'] ?? '' ); }
		}
		return ! empty( $parts ) ? implode( '، ', $parts ) : ( $variant['title'] ?? 'بدون ویژگی متغیر' );
	}

	private function source_price( $amount ) {
		if ( null === $amount || '' === $amount || ! is_numeric( $amount ) ) { return '-'; }
		return number_format( (float) $amount ) . ' ریال / ' . number_format( (float) $amount / 10 ) . ' تومان';
	}

	private function source_review_types( $review ) {
		$types = array();
		foreach ( (array) ( $review['blocks'] ?? array() ) as $block ) {
			if ( is_array( $block ) && ! empty( $block['type'] ) && ! in_array( $block['type'], $types, true ) ) { $types[] = $block['type']; }
		}
		return $types;
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

		$url_validation = $this->validate_product_url( $url );
		if ( is_array( $url_validation ) && isset( $url_validation['error'] ) ) {
			return $url_validation;
		}
		$url = $url_validation;
		$this->last_source_data = array();

		$response = $this->request_product_page( $url );

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

		$product_data = null;
		$source_type  = '';

		try {
			$product_data = $this->extract_from_rsc( $html, $url );
			if ( ! empty( $product_data ) ) {
				$source_type = 'nextjs_rsc';
			}
		} catch ( \Throwable $e ) {
			$product_data = null;
		}

		if ( empty( $product_data ) ) {
			try {
				$product_data = $this->extract_from_next_data( $html );
				if ( ! empty( $product_data ) ) {
					$source_type = 'next_data';
				}
			} catch ( \Throwable $e ) {
				$product_data = null;
			}
		}

		if ( empty( $product_data ) ) {
			try {
				$product_data = $this->extract_from_json_ld( $html, $url );
				if ( ! empty( $product_data ) ) {
					$source_type = 'json_ld';
				}
			} catch ( \Throwable $e ) {
				$product_data = null;
			}
		}

		$has_code = ! empty( $product_data ) && is_array( $product_data )
			&& ( ! empty( $product_data['code'] ) || ! empty( $product_data['id'] ) || ! empty( $product_data['name'] ) );

		if ( ! $has_code ) {
			return array( 'error' => 'داده‌های ساختاریافتهٔ محصول در صفحه پیدا نشد.' );
		}

		$this->last_source_data = array(
			'extracted_via' => $source_type,
			'source_url'    => $url,
			'payload'       => $product_data,
		);

		try {
			$product = $this->normalize_extracted_data( $product_data, $url, $html );
		} catch ( \Throwable $e ) {
			return array( 'error' => 'خطا در پردازش داده‌های محصول: ' . $e->getMessage() );
		}

		try {
			if ( class_exists( 'ProductDTO' ) && method_exists( 'ProductDTO', 'normalize' ) ) {
				$normalized = ProductDTO::normalize( $product );
				if ( is_array( $normalized ) ) {
					$normalized['source_data'] = $this->last_source_data;
					return $normalized;
				}
			}
		} catch ( \Throwable $e ) {
			// در صورت بروز خطا در نرمال‌سازی نهایی، همان داده‌ی پردازش‌شده برگردانده می‌شود.
		}

		$product['source_data'] = $this->last_source_data;
		return $product;
	}

	private function validate_product_url( $url ) {
		$url = esc_url_raw( trim( $url ), array( 'https' ) );
		if ( '' === $url ) {
			return array( 'error' => 'آدرس محصول باید یک URL معتبر HTTPS باشد.' );
		}

		$parts = wp_parse_url( $url );
		if ( ! is_array( $parts ) || empty( $parts['scheme'] ) || empty( $parts['host'] ) ) {
			return array( 'error' => 'ساختار آدرس محصول معتبر نیست.' );
		}

		$host = strtolower( rtrim( $parts['host'], '.' ) );
		if ( 'https' !== strtolower( $parts['scheme'] ) || ! in_array( $host, array( 'bermova.com', 'www.bermova.com' ), true ) ) {
			return array( 'error' => 'فقط آدرس محصولات دامنه bermova.com مجاز است.' );
		}
		if ( isset( $parts['user'] ) || isset( $parts['pass'] ) || isset( $parts['port'] ) ) {
			return array( 'error' => 'آدرس دارای اطلاعات ورود یا پورت سفارشی مجاز نیست.' );
		}

		$path = isset( $parts['path'] ) ? (string) $parts['path'] : '';
		if ( ! preg_match( '#^/product/bmv-[0-9]+(?:/|$)#i', $path ) ) {
			return array( 'error' => 'آدرس باید متعلق به یک صفحهٔ محصول برموا باشد.' );
		}

		return $url;
	}

	private function request_product_page( $url ) {
		$current_url = $url;
		for ( $redirect_count = 0; $redirect_count <= 5; $redirect_count++ ) {
			$response = wp_safe_remote_get(
				$current_url,
				array(
					'timeout'             => 25,
					'redirection'         => 0,
					'user-agent'          => 'Mozilla/5.0 (compatible; Bermova-Extractor/1.1; +wordpress)',
					'sslverify'           => true,
					'limit_response_size' => 6 * MB_IN_BYTES,
				)
			);
			if ( is_wp_error( $response ) ) {
				return $response;
			}

			$status = (int) wp_remote_retrieve_response_code( $response );
			if ( $status < 300 || $status >= 400 ) {
				return $response;
			}

			$location = wp_remote_retrieve_header( $response, 'location' );
			if ( empty( $location ) ) {
				return new WP_Error( 'bermova_redirect_missing', 'پاسخ تغییرمسیر بدون مقصد معتبر دریافت شد.' );
			}
			$location = $this->make_absolute_url( $location, $current_url );
			$validated = $this->validate_product_url( $location );
			if ( is_array( $validated ) ) {
				return new WP_Error( 'bermova_redirect_rejected', $validated['error'] );
			}
			$current_url = $validated;
		}

		return new WP_Error( 'bermova_too_many_redirects', 'تعداد تغییرمسیرهای صفحه بیش از حد مجاز است.' );
	}

	/* ==================== RSC EXTRACTION ==================== */

	private function extract_from_rsc( $html, $base_url ) {
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

		$product = null;
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
			$found = $this->find_product_object( $decoded, 0 );
			if ( $found ) {
				$product = $found;
				break;
			}
		}

		if ( ! $product ) {
			return null;
		}

		$cache    = array();
		$resolved = $this->resolve_rsc_value( $product, $rows, $cache, 0 );

		return is_array( $resolved ) ? $resolved : null;
	}

	private function extract_push_chunks( $html ) {
		$chunks     = array();
		$marker     = 'self.__next_f.push(';
		$marker_len = strlen( $marker );
		$len        = strlen( $html );
		$idx        = 0;
		$guard      = 0;

		while ( true ) {
			$guard++;
			if ( $guard > 20000 ) {
				break;
			}

			$start = strpos( $html, $marker, $idx );
			if ( false === $start ) {
				break;
			}

			$arr_start = $start + $marker_len;
			$depth     = 0;
			$i         = $arr_start;
			$in_str    = false;
			$str_char  = '';
			$escape    = false;
			$end       = null;

			while ( $i < $len ) {
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
						$depth++;
					} elseif ( ']' === $c ) {
						$depth--;
					} elseif ( ')' === $c ) {
						if ( 0 === $depth ) {
							$end = $i;
							break;
						}
						$depth--;
					}
				}
				$i++;
			}

			if ( null === $end ) {
				break;
			}

			$chunks[] = substr( $html, $arr_start, $end - $arr_start );
			$idx      = $end + 1;
		}

		return $chunks;
	}

	private function parse_rsc_rows( $stream ) {
		$rows  = array();
		$len   = strlen( $stream );
		$pos   = 0;
		$guard = 0;

		while ( $pos < $len ) {
			$guard++;
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

			$row_id         = $m[1];
			$content_start  = $pos + strlen( $m[0] );

			if ( preg_match( '/\GT([0-9a-fA-F]+),/', $stream, $m2, 0, $content_start ) ) {
				$declared_len = hexdec( $m2[1] );
				$text_start   = $content_start + strlen( $m2[0] );
				$naive_end    = $text_start + $declared_len;
				$true_end     = $this->find_rsc_boundary( $stream, $text_start, $naive_end, 48 );
				if ( null === $true_end ) {
					$true_end = min( $naive_end, $len );
				}
				$content         = substr( $stream, $text_start, $true_end - $text_start );
				$rows[ $row_id ] = array( 'T', $content );
				$pos             = $true_end;
				if ( $pos < $len && "\n" === $stream[ $pos ] ) {
					$pos++;
				}
			} else {
				$nl = strpos( $stream, "\n", $content_start );
				if ( false === $nl ) {
					$content = substr( $stream, $content_start );
					$pos     = $len;
				} else {
					$content = substr( $stream, $content_start, $nl - $content_start );
					$pos     = $nl + 1;
				}
				$rows[ $row_id ] = array( 'J', $content );
			}
		}

		return $rows;
	}

	private function find_rsc_boundary( $stream, $min_pos, $target_pos, $window ) {
		$len        = strlen( $stream );
		$target_pos = min( $target_pos, $len );
		$scan_from  = max( $min_pos, $target_pos - $window );
		$scan_to    = min( $len, $target_pos + $window );

		if ( $scan_to <= $scan_from ) {
			return null;
		}

		$region  = substr( $stream, $scan_from, $scan_to - $scan_from );
		$pattern = '/[0-9a-fA-F]{0,8}:(?:I\[|HL\[|T[0-9a-fA-F]+,|[\[{"]|-?[0-9]|true|false|null)/';

		if ( ! preg_match_all( $pattern, $region, $mm, PREG_OFFSET_CAPTURE ) ) {
			return null;
		}

		$best      = null;
		$best_dist = null;

		foreach ( $mm[0] as $match ) {
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

	private function find_product_object( $data, $depth = 0 ) {
		if ( $depth > 100 || ! is_array( $data ) ) {
			return null;
		}

		if ( isset( $data['code'] ) && isset( $data['name'] )
			&& ( isset( $data['breadcrumb'] ) || isset( $data['images'] ) || isset( $data['specs'] ) || isset( $data['variants'] ) ) ) {
			return $data;
		}

		foreach ( $data as $value ) {
			if ( is_array( $value ) ) {
				$found = $this->find_product_object( $value, $depth + 1 );
				if ( $found ) {
					return $found;
				}
			}
		}

		return null;
	}

	private function resolve_rsc_value( $value, $rows, &$cache, $depth = 0 ) {
		if ( $depth > 60 ) {
			return $value;
		}

		if ( is_string( $value ) && preg_match( '/^\$([0-9a-fA-F]+)((?::[^:]*)*)$/', $value, $m ) ) {
			$row_id   = $m[1];
			$path_str = isset( $m[2] ) ? $m[2] : '';

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
				$segments = explode( ':', $path_str );
				$segments = array_values(
					array_filter(
						$segments,
						function ( $s ) {
							return '' !== $s;
						}
					)
				);

				$cur = $target;
				foreach ( $segments as $seg ) {
					if ( is_array( $cur ) ) {
						if ( 'props' === $seg && count( $cur ) >= 4 && isset( $cur[0] ) && '$' === $cur[0] && array_key_exists( 3, $cur ) ) {
							$cur = $cur[3];
							continue;
						}
						if ( array_key_exists( $seg, $cur ) ) {
							$cur = $cur[ $seg ];
							continue;
						}
						if ( ctype_digit( $seg ) && array_key_exists( (int) $seg, $cur ) ) {
							$cur = $cur[ (int) $seg ];
							continue;
						}
						return $value;
					}
					return $value;
				}

				return $this->resolve_rsc_value( $cur, $rows, $cache, $depth + 1 );
			}

			return $target;
		}

		if ( is_array( $value ) ) {
			$new = array();
			foreach ( $value as $k => $v ) {
				$new[ $k ] = $this->resolve_rsc_value( $v, $rows, $cache, $depth + 1 );
			}
			return $new;
		}

		return $value;
	}

	private function decode_rsc_row( $rows, $row_id ) {
		if ( ! isset( $rows[ $row_id ] ) ) {
			return null;
		}

		$kind = $rows[ $row_id ][0];
		$raw  = $rows[ $row_id ][1];

		if ( 'T' === $kind ) {
			return $raw;
		}

		if ( 0 === strpos( $raw, 'I[' ) || 0 === strpos( $raw, 'HL[' ) ) {
			return null;
		}

		return json_decode( $raw, true );
	}

	/* ==================== FALLBACK: __NEXT_DATA__ ==================== */

	private function extract_from_next_data( $html ) {
		if ( ! preg_match( '/<script[^>]*id=["\']__NEXT_DATA__["\'][^>]*>(.*?)<\/script>/is', $html, $m ) ) {
			return null;
		}

		$json = json_decode( trim( $m[1] ), true );
		if ( ! is_array( $json ) ) {
			return null;
		}

		return $this->find_product_object( $json, 0 );
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

			return $this->normalize_json_ld_product( $product );
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
				if ( is_string( $t ) && in_array( strtolower( $t ), array( 'product', 'productgroup' ), true ) ) {
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

	private function normalize_json_ld_product( $product ) {
		$images = array();
		$raw_images = $this->array_get( $product, 'image', array() );
		if ( ! empty( $raw_images ) ) {
			$imgs = is_array( $raw_images ) ? $raw_images : array( $raw_images );
			$pos  = 0;
			foreach ( $imgs as $img ) {
				if ( is_string( $img ) && '' !== $img ) {
					$images[] = array(
						'image_url'  => $img,
						'position'   => $pos,
						'variant_id' => null,
					);
					$pos++;
				}
			}
		}

		$variants_out = array();
		$has_variant  = $this->array_get( $product, 'hasVariant', array() );

		if ( is_array( $has_variant ) && ! empty( $has_variant ) ) {
			foreach ( $has_variant as $variant ) {
				if ( ! is_array( $variant ) ) {
					continue;
				}
				$variants_out[] = $this->json_ld_offer_to_variant( $variant, false );
			}
		} else {
			$variants_out[] = $this->json_ld_offer_to_variant( $product, true );
		}

		$offers_top = $this->array_get( $product, 'offers', array() );
		if ( isset( $offers_top[0] ) && is_array( $offers_top[0] ) ) {
			$offers_top = $offers_top[0];
		}
		$currency_raw = isset( $offers_top['priceCurrency'] ) ? strtoupper( (string) $offers_top['priceCurrency'] ) : '';
		$currency     = in_array( $currency_raw, array( 'IRR', 'IRT' ), true ) ? 'تومان' : ( '' !== $currency_raw ? $currency_raw : 'تومان' );

		$brand      = $this->array_get( $product, 'brand', array() );
		$brand_name = is_array( $brand ) ? (string) $this->array_get( $brand, 'name', '' ) : ( is_string( $brand ) ? $brand : '' );

		$sku = (string) $this->array_get( $product, 'sku', '' );

		return array(
			'code'           => $sku,
			'id'             => $sku,
			'name'           => (string) $this->array_get( $product, 'name', '' ),
			'description'    => (string) $this->array_get( $product, 'description', '' ),
			'review_content' => null,
			'brand'          => array( 'name' => $brand_name ),
			'breadcrumb'     => array(),
			'images'         => $images,
			'specs'          => array(),
			'variants'       => $variants_out,
			'currency'       => $currency,
			'source_payload' => $product,
		);
	}

	private function json_ld_offer_to_variant( $node, $is_default ) {
		$offers = $this->array_get( $node, 'offers', array() );
		if ( isset( $offers[0] ) && is_array( $offers[0] ) ) {
			$offers = $offers[0];
		}

		$current_price = isset( $offers['price'] ) && is_numeric( $offers['price'] ) ? (float) $offers['price'] : 0;
		$regular_price = $current_price;
		$price_spec    = $this->array_get( $offers, 'priceSpecification', array() );
		if ( isset( $price_spec[0] ) && is_array( $price_spec[0] ) ) {
			$price_spec = $price_spec[0];
		}
		if ( is_array( $price_spec ) && isset( $price_spec['price'] ) && is_numeric( $price_spec['price'] ) ) {
			$specified_price = (float) $price_spec['price'];
			if ( $specified_price > $regular_price ) {
				$regular_price = $specified_price;
			}
		}
		$discount_price = ( $current_price > 0 && $regular_price > $current_price ) ? $current_price : null;
		$availability = isset( $offers['availability'] ) ? (string) $offers['availability'] : '';
		$in_stock     = ( false !== stripos( $availability, 'InStock' ) );
		$sku          = (string) $this->array_get( $node, 'sku', '' );

		return array(
			'id'                    => $sku,
			'code'                  => $sku,
			'title'                 => (string) $this->array_get( $node, 'name', '' ),
			'sku'                   => $sku,
			'is_active'             => true,
			'is_default'            => $is_default,
			'available'             => $in_stock ? 1 : 0,
			'stock_quantity'        => $in_stock ? null : 0,
			'base_price_amount'     => $regular_price,
			'discount_price_amount' => $discount_price,
			'axis_values'           => array(),
		);
	}

	/* ==================== NORMALIZE ==================== */

	private function normalize_extracted_data( $raw, $base_url, $html = '' ) {
		$product = is_array( $raw ) ? $raw : array();

		$product_code = (string) $this->array_get( $product, 'code', '' );
		$product_uuid = (string) $this->array_get( $product, 'id', '' );
		if ( '' === $product_code ) {
			$product_code = $product_uuid;
		}

		$title = (string) $this->array_get( $product, 'name', '' );

		$excerpt_source = $this->array_get( $product, 'description', '' );
		$excerpt_source = is_string( $excerpt_source ) ? trim( $excerpt_source ) : '';

		$review_content = $this->array_get( $product, 'review_content', null );
		$content         = is_array( $review_content ) ? $this->blocks_to_html( $review_content ) : '';
		if ( '' === trim( wp_strip_all_tags( (string) $content ) ) && '' !== $excerpt_source ) {
			$content = '<p>' . esc_html( $excerpt_source ) . '</p>';
		}

		$brand      = $this->array_get( $product, 'brand', array() );
		$brand_name = is_array( $brand ) ? (string) $this->array_get( $brand, 'name', '' ) : ( is_string( $brand ) ? $brand : '' );

		$breadcrumb = $this->array_get( $product, 'breadcrumb', array() );
		$categories = array();
		if ( is_array( $breadcrumb ) && ! empty( $breadcrumb ) ) {
			$last      = end( $breadcrumb );
			$last_name = is_array( $last ) ? $this->array_get( $last, 'name', '' ) : '';
			if ( ! empty( $last_name ) ) {
				$categories[] = $last_name;
			}
			reset( $breadcrumb );
		}

		$images = $this->array_get( $product, 'images', array() );
		$images = is_array( $images ) ? array_values( $images ) : array();
		if ( ! empty( $images ) ) {
			usort(
				$images,
				function ( $a, $b ) {
					$pa = ( is_array( $a ) && isset( $a['position'] ) ) ? (int) $a['position'] : PHP_INT_MAX;
					$pb = ( is_array( $b ) && isset( $b['position'] ) ) ? (int) $b['position'] : PHP_INT_MAX;
					return $pa <=> $pb;
				}
			);
		}

		$featured_image = '';
		$gallery_images = array();
		if ( ! empty( $images ) ) {
			$featured_image = is_array( $images[0] ) ? (string) $this->array_get( $images[0], 'image_url', '' ) : '';
			$image_count    = count( $images );
			for ( $gi = 1; $gi < $image_count; $gi++ ) {
				$img_url = is_array( $images[ $gi ] ) ? (string) $this->array_get( $images[ $gi ], 'image_url', '' ) : '';
				if ( '' !== $img_url ) {
					$gallery_images[] = $this->make_absolute_url( $img_url, $base_url );
				}
			}
			if ( '' !== $featured_image ) {
				$featured_image = $this->make_absolute_url( $featured_image, $base_url );
			}
		}

		$specs      = $this->array_get( $product, 'specs', array() );
		$specs      = is_array( $specs ) ? $specs : array();
		$attributes = array();

		foreach ( $specs as $spec ) {
			if ( ! is_array( $spec ) ) {
				continue;
			}
			$attr_name = $this->array_get( $spec, 'attribute_name', '' );
			if ( empty( $attr_name ) ) {
				continue;
			}

			$type   = $this->array_get( $spec, 'type', '' );
			$values = array();

			if ( 'boolean' === $type ) {
				$val = $this->array_get( $spec, 'value_bool', null );
				if ( null !== $val ) {
					$values = array( $val ? 'بله' : 'خیر' );
				}
			} else {
				$raw_values = $this->array_get( $spec, 'values', array() );
				if ( is_array( $raw_values ) && ! empty( $raw_values ) ) {
					$values = array_values(
						array_filter(
							$raw_values,
							function ( $v ) {
								return null !== $v && '' !== $v;
							}
						)
					);
				}
				if ( empty( $values ) ) {
					$unit = (string) $this->array_get( $spec, 'unit', '' );
					if ( 'integer' === $type ) {
						$val = $this->array_get( $spec, 'value_int', null );
						if ( null !== $val ) {
							$values = array( trim( $val . ' ' . $unit ) );
						}
					} elseif ( 'decimal' === $type ) {
						$val = $this->array_get( $spec, 'value_decimal', null );
						if ( null !== $val ) {
							$values = array( trim( $val . ' ' . $unit ) );
						}
					} elseif ( 'text' === $type ) {
						$val = $this->array_get( $spec, 'value_text', '' );
						if ( null !== $val && '' !== $val ) {
							$values = array( $val );
						}
					}
				}
			}

			if ( ! empty( $values ) ) {
				$attributes[] = array(
					'name'                => $attr_name,
					'values'              => array_values( $values ),
					'used_for_variations' => false,
				);
			}
		}

		if ( ! empty( $brand_name ) ) {
			$has_brand_attr = false;
			foreach ( $attributes as $attr ) {
				if ( isset( $attr['name'] ) && 'برند' === $attr['name'] ) {
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

		$variants_raw = $this->array_get( $product, 'variants', array() );
		$variants_raw = is_array( $variants_raw ) ? array_values( $variants_raw ) : array();

		$product_type          = 'simple';
		$variations             = array();
		$parent_regular_price   = 0;
		$parent_sale_price      = null;
		$parent_stock_quantity  = 0;
		$parent_stock_status    = 'out-of-stock';
		$parent_manage_stock    = false;

		$has_variable = false;
		foreach ( $variants_raw as $var ) {
			if ( is_array( $var ) && ! empty( $var['axis_values'] ) ) {
				$has_variable = true;
				break;
			}
		}

		if ( $has_variable ) {
			$product_type = 'variable';
			$var_attr_map = array();

			foreach ( $variants_raw as $var ) {
				if ( ! is_array( $var ) || ! (bool) $this->array_get( $var, 'is_active', true ) ) {
					continue;
				}
				$axis_values = $this->array_get( $var, 'axis_values', array() );
				if ( empty( $axis_values ) || ! is_array( $axis_values ) ) {
					continue;
				}
				foreach ( $axis_values as $axis ) {
					if ( ! is_array( $axis ) ) {
						continue;
					}
					$code = (string) $this->array_get( $axis, 'attribute_code', '' );
					if ( '' === $code ) {
						continue;
					}
					$label = (string) $this->array_get( $axis, 'label', '' );
					if ( ! isset( $var_attr_map[ $code ] ) ) {
						$var_attr_map[ $code ] = array(
							'name'   => (string) $this->array_get( $axis, 'attribute_name', $code ),
							'values' => array(),
						);
					}
					if ( '' !== $label && ! in_array( $label, $var_attr_map[ $code ]['values'], true ) ) {
						$var_attr_map[ $code ]['values'][] = $label;
					}
				}
			}

			foreach ( $var_attr_map as $attr ) {
				$attributes[] = array(
					'name'                => $attr['name'],
					'values'              => $attr['values'],
					'used_for_variations' => true,
				);
			}

			foreach ( $variants_raw as $var ) {
				if ( ! is_array( $var ) || ! (bool) $this->array_get( $var, 'is_active', true ) ) {
					continue;
				}

				$attr_map      = array();
				$summary_parts = array();
				$axis_values   = $this->array_get( $var, 'axis_values', array() );
				if ( is_array( $axis_values ) ) {
					foreach ( $axis_values as $axis ) {
						if ( ! is_array( $axis ) ) {
							continue;
						}
						$attr_name  = (string) $this->array_get( $axis, 'attribute_name', '' );
						$attr_label = (string) $this->array_get( $axis, 'label', '' );
						if ( '' !== $attr_name && '' !== $attr_label ) {
							$attr_map[ $attr_name ] = $attr_label;
							$summary_parts[]        = $attr_name . ': ' . $attr_label;
						}
					}
				}

				$regular_price = $this->price_from_raw( $this->array_get( $var, 'base_price_amount', 0 ) );
				$discount_raw  = $this->array_get( $var, 'discount_price_amount', null );
				$sale_price    = null;
				if ( null !== $discount_raw && '' !== $discount_raw ) {
					$discount = $this->price_from_raw( $discount_raw );
					if ( $discount > 0 && $discount !== $regular_price ) {
						$sale_price = $discount;
					}
				}

				$stock_qty_raw = $this->array_get( $var, 'stock_quantity', null );
				$stock_qty     = is_numeric( $stock_qty_raw ) ? (int) $stock_qty_raw : null;
				$manage_stock  = null !== $stock_qty;
				$available    = (int) $this->array_get( $var, 'available', 0 );
				$stock_status = ( $available > 0 ) ? 'in-stock' : 'out-of-stock';

				$var_image = '';
				if ( ! empty( $images ) ) {
					$var_id = $this->array_get( $var, 'id', '' );
					foreach ( $images as $img ) {
						if ( is_array( $img ) && ! empty( $img['variant_id'] ) && $img['variant_id'] === $var_id ) {
							$candidate = $this->array_get( $img, 'image_url', '' );
							if ( $candidate ) {
								$var_image = $this->make_absolute_url( $candidate, $base_url );
							}
							break;
						}
					}
				}

				$var_sku = (string) $this->array_get( $var, 'sku', '' );
				if ( '' === $var_sku ) {
					$var_sku = (string) $this->array_get( $var, 'id', '' );
				}

				$variations[] = array(
					'attributes_summary' => implode( ', ', $summary_parts ),
					'attributes_map'     => $attr_map,
					'sku'                => $var_sku,
					'code'               => (string) $this->array_get( $var, 'code', '' ),
					'regular_price'      => $regular_price,
					'sale_price'         => $sale_price,
					'stock_status'       => $stock_status,
					'stock_quantity'     => $stock_qty,
					'manage_stock'       => $manage_stock,
					'image'              => $var_image,
				);
			}

			if ( ! empty( $variations ) ) {
				$regular_prices = array_filter(
					array_column( $variations, 'regular_price' ),
					function ( $v ) {
						return $v > 0;
					}
				);
				$parent_regular_price = ! empty( $regular_prices ) ? min( $regular_prices ) : 0;

				$sale_prices = array_filter(
					array_column( $variations, 'sale_price' ),
					function ( $v ) {
						return null !== $v;
					}
				);
				$parent_sale_price = ! empty( $sale_prices ) ? min( $sale_prices ) : null;

				$has_unknown_quantity = in_array( null, array_column( $variations, 'stock_quantity' ), true );
				$parent_stock_quantity = $has_unknown_quantity ? null : (int) array_sum( array_column( $variations, 'stock_quantity' ) );

				$has_stock = false;
				foreach ( $variations as $v ) {
					if ( 'in-stock' === $v['stock_status'] ) {
						$has_stock = true;
						break;
					}
				}
				$parent_stock_status = $has_stock ? 'in-stock' : 'out-of-stock';
			}
		} else {
			$product_type = 'simple';

			if ( ! empty( $variants_raw ) ) {
				$simple_var = null;
				foreach ( $variants_raw as $v ) {
					if ( is_array( $v ) && ! empty( $v['is_default'] ) && (bool) $this->array_get( $v, 'is_active', true ) ) {
						$simple_var = $v;
						break;
					}
				}
				if ( ! $simple_var ) {
					foreach ( $variants_raw as $v ) {
						if ( is_array( $v ) && (bool) $this->array_get( $v, 'is_active', true ) ) {
							$simple_var = $v;
							break;
						}
					}
				}
				if ( ! $simple_var && isset( $variants_raw[0] ) && is_array( $variants_raw[0] ) ) {
					$simple_var = $variants_raw[0];
				}

				if ( $simple_var ) {
					$parent_regular_price = $this->price_from_raw( $this->array_get( $simple_var, 'base_price_amount', 0 ) );
					$discount_raw          = $this->array_get( $simple_var, 'discount_price_amount', null );
					if ( null !== $discount_raw && '' !== $discount_raw ) {
						$discount = $this->price_from_raw( $discount_raw );
						if ( $discount > 0 && $discount !== $parent_regular_price ) {
							$parent_sale_price = $discount;
						}
					}
					$simple_qty_raw         = $this->array_get( $simple_var, 'stock_quantity', null );
					$parent_stock_quantity = is_numeric( $simple_qty_raw ) ? (int) $simple_qty_raw : null;
					$parent_manage_stock   = null !== $parent_stock_quantity;
					$available              = (int) $this->array_get( $simple_var, 'available', 0 );
					$parent_stock_status    = ( $available > 0 ) ? 'in-stock' : 'out-of-stock';
				}
			}
			// اگر آرایه‌ی variants کاملاً خالی باشد (مثلاً محصول ساده‌ی ناموجود)،
			// مقادیر پیش‌فرض (قیمت صفر و ناموجود) بدون خطا باقی می‌مانند.
		}

		if ( '' === $excerpt_source ) {
			$excerpt_source = $this->extract_meta_description( $html, $base_url );
		}
		if ( '' === $excerpt_source && '' !== trim( wp_strip_all_tags( (string) $content ) ) ) {
			$excerpt_source = wp_trim_words( wp_strip_all_tags( $content ), 60, '...' );
		}

		$currency = (string) $this->array_get( $product, 'currency', 'تومان' );
		if ( '' === $currency ) {
			$currency = 'تومان';
		}

		return array(
			'product_id'     => $product_code,
			'sku'            => '' !== $product_uuid ? $product_uuid : $product_code,
			'title'          => $title,
			'excerpt'        => $excerpt_source,
			'content'        => $content,
			'featured_image' => $featured_image,
			'gallery_images' => $gallery_images,
			'regular_price'  => $parent_regular_price,
			'sale_price'     => $parent_sale_price,
			'currency'       => $currency,
			'stock_status'   => $parent_stock_status,
			'stock_quantity' => $parent_stock_quantity,
			'manage_stock'   => $parent_manage_stock,
			'categories'     => $categories,
			'tags'           => array(),
			'product_type'   => $product_type,
			'attributes'     => $attributes,
			'variations'     => $variations,
		);
	}

	private function price_from_raw( $amount ) {
		if ( null === $amount || '' === $amount || ! is_numeric( $amount ) ) {
			return 0;
		}
		return (int) round( ( (float) $amount ) / 10 );
	}

	private function blocks_to_html( $review_content ) {
		$blocks = $this->array_get( $review_content, 'blocks', array() );
		if ( empty( $blocks ) || ! is_array( $blocks ) ) {
			return '';
		}

		$html = '';
		foreach ( $blocks as $block ) {
			if ( ! is_array( $block ) ) {
				continue;
			}
			$type = $this->array_get( $block, 'type', '' );

			if ( 'heading' === $type ) {
				$level = (int) $this->array_get( $block, 'level', 2 );
				if ( $level < 2 ) {
					$level = 2;
				} elseif ( $level > 6 ) {
					$level = 6;
				}
				$text = (string) $this->array_get( $block, 'text', '' );
				if ( '' !== trim( $text ) ) {
					$html .= '<h' . $level . '>' . esc_html( $text ) . '</h' . $level . '>' . "\n";
				}
			} elseif ( 'paragraph' === $type ) {
				$spans = $this->array_get( $block, 'spans', array() );
				$inner = $this->spans_to_html( $spans );
				if ( '' !== trim( $inner ) ) {
					$html .= '<p>' . $inner . '</p>' . "\n";
				}
			} elseif ( 'list' === $type ) {
				$ordered = (bool) $this->array_get( $block, 'ordered', false );
				$tag     = $ordered ? 'ol' : 'ul';
				$items   = $this->array_get( $block, 'items', array() );
				if ( ! empty( $items ) && is_array( $items ) ) {
					$list_html = '';
					foreach ( $items as $item ) {
						$spans = is_array( $item ) ? $this->array_get( $item, 'spans', array() ) : array();
						$inner = $this->spans_to_html( $spans );
						if ( '' !== trim( $inner ) ) {
							$list_html .= '<li>' . $inner . '</li>' . "\n";
						}
					}
					if ( '' !== $list_html ) {
						$html .= '<' . $tag . '>' . "\n" . $list_html . '</' . $tag . '>' . "\n";
					}
				}
			} else {
				$text = $this->array_get( $block, 'text', '' );
				if ( is_string( $text ) && '' !== trim( $text ) ) {
					$html .= '<p>' . esc_html( $text ) . '</p>' . "\n";
				}
			}
		}

		return $html;
	}

	private function spans_to_html( $spans ) {
		if ( empty( $spans ) || ! is_array( $spans ) ) {
			return '';
		}

		$out = '';
		foreach ( $spans as $span ) {
			if ( is_string( $span ) ) {
				$out .= esc_html( $span );
				continue;
			}
			if ( ! is_array( $span ) ) {
				continue;
			}

			$text = (string) $this->array_get( $span, 'text', '' );
			if ( '' === $text ) {
				continue;
			}

			$escaped = esc_html( $text );
			$marks   = $this->array_get( $span, 'marks', array() );
			$bold    = false;
			$italic  = false;

			if ( is_array( $marks ) ) {
				foreach ( $marks as $mark ) {
					$mark_type = '';
					if ( is_array( $mark ) ) {
						$mark_type = (string) $this->array_get( $mark, 'type', '' );
					} elseif ( is_string( $mark ) ) {
						$mark_type = $mark;
					}
					if ( 'bold' === $mark_type ) {
						$bold = true;
					} elseif ( 'italic' === $mark_type ) {
						$italic = true;
					}
				}
			}

			if ( $bold ) {
				$escaped = '<strong>' . $escaped . '</strong>';
			}
			if ( $italic ) {
				$escaped = '<em>' . $escaped . '</em>';
			}

			$out .= $escaped;
		}

		return $out;
	}

	private function extract_meta_description( $html, $base_url ) {
		if ( empty( $html ) || ! is_string( $html ) ) {
			return '';
		}
		if ( preg_match( '/<meta[^>]*name=["\']description["\'][^>]*content=["\'](.*?)["\'][^>]*>/is', $html, $m ) ) {
			return html_entity_decode( trim( $m[1] ), ENT_QUOTES, 'UTF-8' );
		}
		if ( preg_match( '/<meta[^>]*property=["\']og:description["\'][^>]*content=["\'](.*?)["\'][^>]*>/is', $html, $m ) ) {
			return html_entity_decode( trim( $m[1] ), ENT_QUOTES, 'UTF-8' );
		}
		return '';
	}

	/* ==================== HELPERS ==================== */

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
