<?php

if (!defined('ABSPATH')) {
    exit;
}

// بارگذاری کلاس استانداردسازی
require_once plugin_dir_path(__FILE__) . 'class-product-dto.php';

class MirzaeiWatch_Product_Extractor {
	
    const MENU_SLUG = 'mirzaeiwatch-product-extractor';
    public $source_data = array();

    public function __construct() {
        add_action('admin_menu', array($this, 'register_admin_page'));
    }

    public function register_admin_page() {
		add_submenu_page(
			'edit.php?post_type=source_profile',
			'MirzaeiWatch Extractor',
			'MirzaeiWatch Extractor',
			'manage_options',
			self::MENU_SLUG,
			array( $this, 'render_admin_page' )
		);
    }

    public function render_admin_page() {
        if (!current_user_can('manage_options')) {
            wp_die('Access denied.');
        }
        $url = '';
        $result = null;
        $error = '';

        if (!empty($_POST['mwpe_submit'])) {
            check_admin_referer('mirzaeiwatch_extractor_action', 'mirzaeiwatch_extractor_nonce');

            $url = isset($_POST['product_url']) ? esc_url_raw(trim(wp_unslash($_POST['product_url']))) : '';

            if (empty($url)) {
                $error = 'لطفاً لینک محصول را وارد کنید.';
            } else {
                $result = $this->extract_product_data($url);

                if (isset($result['error'])) {
                    $error = $result['error'];
                    $result = null;
                }
            }
        }

        ?>
        <div class="wrap">
            <h1>MirzaeiWatch Product Extractor</h1>
            <p>Enter the URL of a product page on <strong>mirzaeiwatch.ir</strong> to extract its data.</p>

            <form method="post" action="">
                    <?php wp_nonce_field('mirzaeiwatch_extractor_action', 'mirzaeiwatch_extractor_nonce'); ?>
                <table class="form-table">
                    <tr>
                        <th scope="row"><label for="product_url">Product Page URL</label></th>
                        <td>
                            <input type="url" id="product_url" name="product_url"
                                   value="<?php echo esc_attr($url); ?>"
                                   placeholder="https://mirzaeiwatch.ir/product/..." size="60" required />
                        </td>
                    </tr>
                </table>
                <?php submit_button('Extract Product Data', 'primary', 'mwpe_submit'); ?>
            </form>

            <?php if (!empty($error)) : ?>
                <div class="notice notice-error"><p><?php echo esc_html($error); ?></p></div>
            <?php elseif (is_array($result)) : ?>
                <?php $this->display_product_data($result, $this->source_data); ?>
            <?php endif; ?>
        </div>
        <?php
    }

    private function display_product_data($data, $source_data = array()) {
        ?>
        <style>
            .extracted-data { margin-top: 30px; }
            .extracted-data hr { margin: 24px 0; }
            .extracted-data table { border-collapse: collapse; width: 100%; }
            .extracted-data th, .extracted-data td { border: 1px solid #ccc; padding: 8px; text-align: right; }
            .extracted-data .gallery-images img { width: 100px; height: 100px; object-fit: cover; margin: 4px; }
            .extracted-data .product-images img { max-width: 250px; height: auto; }
            .extracted-data ul { padding-right: 20px; }
            .mw-section-title { margin-top: 34px; border-right: 4px solid #7c3aed; padding: 10px 14px; background: #f5f3ff; }
            .mw-source-grid { display: grid; grid-template-columns: repeat(auto-fit,minmax(360px,1fr)); gap: 16px; align-items: start; }
            .mw-source-card { background: #fff; border: 1px solid #dcdcde; border-radius: 8px; padding: 15px; overflow-wrap: anywhere; }
            .mw-wide { grid-column: 1 / -1; }
            .mw-scroll { overflow-x: auto; }
            .mw-source-card h3 { margin-top: 0; }
            .mw-source-card table th { width: 210px; background: #f6f7f7; }
            .mw-images { display:grid; grid-template-columns:repeat(auto-fill,minmax(190px,1fr)); gap:12px; }
            .mw-image { border:1px solid #ddd; padding:10px; border-radius:6px; }
            .mw-image img { width:100%; height:170px; object-fit:contain; }
            .mw-json { direction:ltr; text-align:left; white-space:pre-wrap; max-height:650px; overflow:auto; background:#111827; color:#e5e7eb; padding:14px; }
        </style>

        <div class="extracted-data">
            <h2>Extracted Product Data</h2>

            <div class="product-ids">
                <span><strong>Product ID (شناسه همگام‌سازی):</strong> <?php echo esc_html($data['product_id']); ?></span><br>
                <span><strong>SKU:</strong> <?php echo esc_html($data['sku']); ?></span>
            </div>

            <hr>

            <div class="product-content">
                <h1>عنوان محصول (Title): <?php echo esc_html($data['title']); ?></h1>
                <p><strong>توضیحات کوتاه (Excerpt):</strong> <?php echo esc_html($data['excerpt']); ?></p>
                <div><strong>توضیحات اصلی (Content):</strong> <?php echo wp_kses_post($data['content']); ?></div>
            </div>

            <hr>

            <div class="product-images">
                <div><strong>تصویر اصلی (Featured Image):</strong></div>
                <?php if (!empty($data['featured_image'])): ?>
                    <img src="<?php echo esc_url($data['featured_image']); ?>" alt="Featured Image">
                <?php else: ?>
                    <p>No featured image.</p>
                <?php endif; ?>

                <p><strong>گالری تصاویر (Gallery Images - آرایه):</strong></p>
                <div class="gallery-images">
                    <?php if (!empty($data['gallery_images'])): ?>
                        <?php foreach ($data['gallery_images'] as $img): ?>
                            <img src="<?php echo esc_url($img); ?>" alt="Gallery Image">
                        <?php endforeach; ?>
                    <?php else: ?>
                        <p>No gallery images.</p>
                    <?php endif; ?>
                </div>
            </div>

            <hr>

            <div class="product-pricing-stock">
                <p><strong>واحد پولی:</strong> <?php echo esc_html($data['currency']); ?></p>
                <p><strong>قیمت اصلی (Regular Price):</strong> <?php echo esc_html(number_format($data['regular_price'], 0, '.', ',')); ?></p>
                <p><strong>قیمت فروش ویژه (Sale Price):</strong> <?php echo $data['sale_price'] ? esc_html(number_format($data['sale_price'], 0, '.', ',')) : '-'; ?></p>
                <p><strong>وضعیت قیمت:</strong> <?php echo $data['sale_price'] ? 'در حراج' : 'عادی'; ?></p>
                <p><strong>موجودی (Stock Status):</strong> <?php echo esc_html($data['stock_status']); ?></p>
                <p><strong>تعداد موجودی (Stock Quantity):</strong> <?php echo null !== $data['stock_quantity'] ? esc_html($data['stock_quantity']) : '-'; ?></p>
            </div>

            <hr>

            <div class="product-taxonomies">
                <p><strong>دسته‌بندی‌ها (Categories - ساختار درختی):</strong></p>
                <?php if (!empty($data['categories'])): ?>
                    <ul>
                        <?php foreach ($data['categories'] as $cat): ?>
                            <li><?php echo esc_html($cat); ?></li>
                        <?php endforeach; ?>
                    </ul>
                <?php else: ?>
                    <p>No categories.</p>
                <?php endif; ?>

                <p><strong>برچسب‌ها (Tags):</strong></p>
                <?php if (!empty($data['tags'])): ?>
                    <ul>
                        <?php foreach ($data['tags'] as $tag): ?>
                            <li><?php echo esc_html($tag); ?></li>
                        <?php endforeach; ?>
                    </ul>
                <?php else: ?>
                    <p>No tags.</p>
                <?php endif; ?>
            </div>

            <hr>

            <div class="product-type">
                <p><strong>نوع محصول (Product Type):</strong> <?php echo esc_html($data['product_type']); ?></p>
            </div>

            <hr>

            <div class="product-attributes">
                <h2>ویژگی‌ها (Attributes)</h2>
                <p><small>شامل نام، مقدار، و اینکه برای متغیر استفاده می‌شود یا نه.</small></p>
                <?php if (!empty($data['attributes'])): ?>
                    <table>
                        <thead>
                            <tr>
                                <th>نام ویژگی</th>
                                <th>مقدار(ها)</th>
                                <th>برای متغیر استفاده می‌شود؟</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($data['attributes'] as $attr): ?>
                                <tr>
                                    <td><?php echo esc_html($attr['name']); ?></td>
                                    <td><?php echo esc_html(implode('، ', $attr['values'])); ?></td>
                                    <td><?php echo $attr['used_for_variations'] ? 'بله' : 'خیر'; ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php else: ?>
                    <p>No attributes.</p>
                <?php endif; ?>
            </div>

            <hr>

            <div class="product-variations">
                <h2>متغیرها (Variations)</h2>
                <p>محصول <?php echo ($data['product_type'] === 'variable') ? 'متغیر است. لیست ترکیب‌های مختلف:' : 'ساده است و متغیری ندارد.'; ?></p>
                <?php if ($data['product_type'] === 'variable' && !empty($data['variations'])): ?>
                    <table>
                        <thead>
                            <tr>
                                <th>ترکیب ویژگی‌ها</th>
                                <th>attributes_map</th>
                                <th>SKU</th>
                                <th>قیمت اصلی</th>
                                <th>قیمت ویژه</th>
                                <th>وضعیت موجودی</th>
                                <th>تعداد موجودی</th>
                                <th>تصویر متغیر</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($data['variations'] as $var): ?>
                                <tr>
                                    <td><?php echo esc_html($var['attributes_summary']); ?></td>
                                    <td><?php echo esc_html(json_encode($var['attributes_map'])); ?></td>
                                    <td><?php echo esc_html($var['sku']); ?></td>
                                    <td><?php echo esc_html(number_format($var['regular_price'], 0, '.', ',')); ?></td>
                                    <td><?php echo $var['sale_price'] ? esc_html(number_format($var['sale_price'], 0, '.', ',')) : '-'; ?></td>
                                    <td><?php echo esc_html($var['stock_status']); ?></td>
                                    <td><?php echo null !== $var['stock_quantity'] ? esc_html($var['stock_quantity']) : '-'; ?></td>
                                    <td>
                                        <?php if (!empty($var['image'])): ?>
                                            <img src="<?php echo esc_url($var['image']); ?>" alt="Variation image" style="width:40px;height:40px;object-fit:cover;">
                                        <?php else: ?>
                                            -
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php else: ?>
                    <p><small>* برای محصولات ساده (simple) این بخش خالی می‌ماند.</small></p>
                <?php endif; ?>
            </div>

            <?php if (!empty($source_data)) { $this->display_source_data_sections($source_data); } ?>
        </div>
        <?php
    }

    /**
     * متد استاتیک استخراج محصول
     */
    public static function extract( $url ) {
        $instance = new self();
        $data = $instance->extract_product_data( $url );
        if ( isset( $data['error'] ) ) {
            return false;
        }
        $normalized = ProductDTO::normalize( $data );
        $normalized['source_data'] = $instance->source_data;
        return $normalized;
    }

    /**
     * Extract all product data and return a standardized ProductDTO array.
     */
    public function extract_product_data( $url ) {
        $validation_error = $this->validate_product_url($url);
        if ($validation_error !== '') {
            return array('error' => $validation_error);
        }

        $response = $this->request_product_page($url);

        if (is_wp_error($response)) {
            return array('error' => 'خطا در دریافت صفحه: ' . $response->get_error_message());
        }

        $status_code = wp_remote_retrieve_response_code($response);
        if ((int) $status_code !== 200) {
            return array('error' => 'دریافت صفحه با خطا مواجه شد. HTTP Status: ' . $status_code);
        }

        $html = wp_remote_retrieve_body($response);
        if (empty($html)) {
            return array('error' => 'محتوای صفحه خالی است.');
        }

        libxml_use_internal_errors(true);
        $dom = new DOMDocument();
        $loaded = $dom->loadHTML('<?xml encoding="utf-8" ?>' . $html);
        libxml_clear_errors();

        if (!$loaded) {
            return array('error' => 'ساختار HTML صفحه قابل پردازش نیست.');
        }

        $xpath = new DOMXPath($dom);
        $json_ld = $this->extract_json_ld_product($xpath, $url);

        // Title
        $title = $this->first_non_empty(array(
            $this->extract_text_by_xpath($xpath, "//*[contains(@class,'product_title')]"),
            $this->array_get($json_ld, 'name'),
            $this->extract_meta_content($xpath, 'property', 'og:title'),
            $this->extract_text_by_xpath($xpath, '//h1'),
        ));

        // Content & Excerpt
        $content_html = $this->first_non_empty(array(
            $this->extract_inner_html_by_xpath($xpath, "//*[contains(@class,'woocommerce-Tabs-panel') and contains(@class,'description')]"),
            $this->extract_inner_html_by_xpath($xpath, "//*[@id='tab-description']"),
            $this->extract_inner_html_by_xpath($xpath, "//*[contains(@class,'product-short-description')]"),
        ));

        $excerpt = $this->first_non_empty(array(
            $this->extract_inner_html_by_xpath($xpath, "//*[contains(@class,'woocommerce-product-details__short-description')]"),
            $this->extract_meta_content($xpath, 'name', 'description'),
        ));

        // Featured image
        $featured_image = $this->first_non_empty(array(
            $this->extract_attribute_by_xpath($xpath, "//*[contains(@class,'woocommerce-product-gallery__image')][1]//a", 'href'),
            $this->extract_attribute_by_xpath($xpath, "//*[contains(@class,'woocommerce-product-gallery__image')][1]//img", 'src'),
            $this->array_get($json_ld, 'image.0'),
            $this->array_get($json_ld, 'image'),
            $this->extract_meta_content($xpath, 'property', 'og:image'),
        ));

        $gallery_images = $this->extract_gallery_images($xpath, $featured_image, $url);

        // SKU
        $sku = $this->first_non_empty(array(
            $this->extract_text_by_xpath($xpath, "//span[@class='sku']"),
            $this->extract_text_by_xpath($xpath, "//*[contains(@class,'sku_wrapper')]//span[@class='sku']"),
            $this->extract_meta_content($xpath, 'itemprop', 'sku'),
            $this->extract_labeled_text($xpath, 'شناسه محصول'),
            $this->array_get($json_ld, 'sku'),
            $this->extract_sku_from_text($this->normalize_whitespace(wp_strip_all_tags($html))),
        ));

        // Product ID – استخراج از منابع دقیق و قابل‌اعتماد
        $pid_from_button  = $this->extract_attribute_by_xpath( $xpath, "//button[@name='add-to-cart']", 'value' );
        $pid_from_body_id = '';
        $body_class = $xpath->query( '/html/body' )->item(0);
        if ( $body_class ) {
            $classes = $body_class->getAttribute( 'class' );
            if ( preg_match( '/postid-(\d+)/', $classes, $m ) ) {
                $pid_from_body_id = $m[1];
            }
        }
        $pid_from_product_div = '';
        $divs = $xpath->query( "//div[contains(@class, 'product') and starts-with(@id, 'product-')]" );
        if ( $divs->length ) {
            $id_attr = $divs->item(0)->getAttribute( 'id' );
            if ( preg_match( '/^product-(\d+)$/', $id_attr, $m ) ) {
                $pid_from_product_div = $m[1];
            }
        }

        $product_id = $this->first_non_empty( array(
            $pid_from_button,
            $pid_from_body_id,
            $pid_from_product_div,
            $sku, // fallback نهایی
        ) );

        list($regular_price, $sale_price, $currency) = $this->extract_prices($xpath, $json_ld, $html);

        list($stock_status, $stock_quantity) = $this->extract_stock($xpath, $json_ld, $html);

        $categories = $this->extract_taxonomy_links($xpath, "//*[contains(@class,'posted_in')]//a");
        if (empty($categories)) {
            $categories = $this->extract_categories_from_text($xpath);
        }

        $tags = $this->extract_taxonomy_links($xpath, "//*[contains(@class,'tagged_as')]//a");

        // Detect product type and extract variable data if needed
        $is_variable = $this->detect_variable_product($xpath, $html);
        $attributes = array();
        $variations = array();

        if ($is_variable) {
            $product_type = 'variable';
            list($attributes, $variations) = $this->extract_variable_product_data($xpath, $html, $url);
        } else {
            $product_type = 'simple';
            $attributes = $this->extract_simple_attributes($xpath);
        }

        // Build raw data array (will be normalized later)
        $regular_price_int = !empty($regular_price) ? intval($regular_price) : 0;
        $sale_price_int = !empty($sale_price) ? intval($sale_price) : null;
        $stock_quantity_int = ($stock_quantity !== '') ? intval($stock_quantity) : null;

        $data = array(
            'product_id'     => (string) $product_id,
            'sku'            => (string) $sku,
            'title'          => $this->clean_text($title),
            'excerpt'        => $this->clean_html($excerpt),
            'content'        => $this->clean_html($content_html),
            'featured_image' => $this->make_absolute_url($featured_image, $url),
            'gallery_images' => $gallery_images,
            'regular_price'  => $regular_price_int,
            'sale_price'     => $sale_price_int,
            'currency'       => $currency,
            'stock_status'   => $stock_status,
            'stock_quantity' => $stock_quantity_int,
            'categories'     => array_values(array_filter(array_map(array($this, 'clean_text'), $categories))),
            'tags'           => array_values(array_filter(array_map(array($this, 'clean_text'), $tags))),
            'product_type'   => $product_type,
            'attributes'     => $attributes,
            'variations'     => $variations,
        );

        if (empty($data['excerpt']) && !empty($data['content'])) {
            $data['excerpt'] = wp_trim_words(wp_strip_all_tags($data['content']), 60, '...');
        }

        $this->source_data = $this->extract_source_data($xpath, $json_ld, $html, $url, $data);

        // استانداردسازی نهایی با حفظ payload تکمیلی خارج از قرارداد DTO
        $normalized = ProductDTO::normalize($data);
        $normalized['source_data'] = $this->source_data;
        return $normalized;
    }

    private function validate_product_url($url) {
        $parts = wp_parse_url($url);
        if (!is_array($parts) || empty($parts['scheme']) || empty($parts['host']) || empty($parts['path'])) {
            return 'آدرس محصول معتبر نیست.';
        }
        $host = strtolower(rtrim($parts['host'], '.'));
        if ('https' !== strtolower($parts['scheme']) || !in_array($host, array('mirzaeiwatch.ir', 'www.mirzaeiwatch.ir'), true)) {
            return 'فقط آدرس HTTPS محصول در دامنه mirzaeiwatch.ir مجاز است.';
        }
        if (!empty($parts['user']) || !empty($parts['pass']) || !empty($parts['port']) || !preg_match('#^/product/[^/]+/?$#i', $parts['path'])) {
            return 'لینک باید یک صفحهٔ محصول معتبر میرزایی‌واچ باشد.';
        }
        return '';
    }

    private function request_product_page($url) {
        $current_url = $url;
        for ($redirect = 0; $redirect <= 5; $redirect++) {
            $response = wp_safe_remote_get($current_url, array(
                'timeout' => 25,
                'redirection' => 0,
                'limit_response_size' => 6 * MB_IN_BYTES,
                'user-agent' => 'Mozilla/5.0 (compatible; MirzaeiWatch-Extractor/1.2; +WordPress)',
                'sslverify' => true,
            ));
            if (is_wp_error($response)) {
                return $response;
            }
            $status = (int) wp_remote_retrieve_response_code($response);
            if ($status < 300 || $status >= 400) {
                return $response;
            }
            $location = wp_remote_retrieve_header($response, 'location');
            if (!$location) {
                return $response;
            }
            $current_url = $this->make_absolute_url($location, $current_url);
            if ($this->validate_product_url($current_url) !== '') {
                return new WP_Error('unsafe_redirect', 'ریدایرکت صفحهٔ محصول به مقصد غیرمجاز انجام شد.');
            }
        }
        return new WP_Error('too_many_redirects', 'تعداد ریدایرکت‌های صفحه بیش از حد مجاز است.');
    }

    private function extract_source_data($xpath, $json_ld_product, $html, $url, $standard_data) {
        $json_ld = array();
        foreach ($xpath->query("//script[@type='application/ld+json']") as $node) {
            $decoded = json_decode(trim($node->textContent), true);
            if (is_array($decoded)) {
                $json_ld[] = $decoded;
            }
        }

        $meta = array();
        foreach ($xpath->query('//meta[@content]') as $node) {
            $key = $node->getAttribute('property');
            if ($key === '') { $key = $node->getAttribute('name'); }
            if ($key === '') { $key = $node->getAttribute('itemprop'); }
            if ($key !== '') { $meta[$key] = $node->getAttribute('content'); }
        }
        $title_node = $xpath->query('//title')->item(0);
        $canonical_node = $xpath->query("//link[contains(concat(' ',normalize-space(@rel),' '),' canonical ')]/@href")->item(0);

        $attributes = array();
        foreach ($xpath->query("//*[contains(@class,'woocommerce-product-attributes')]//tr") as $row) {
            $label_node = $xpath->query('.//th', $row)->item(0);
            $value_node = $xpath->query('.//td', $row)->item(0);
            if (!$label_node || !$value_node) { continue; }
            $links = array();
            foreach ($xpath->query('.//a', $value_node) as $link) {
                $links[] = array('text' => $this->clean_text($link->textContent), 'url' => $this->make_absolute_url($link->getAttribute('href'), $url));
            }
            $attributes[] = array(
                'label' => $this->clean_text($label_node->textContent),
                'value' => $this->clean_text($value_node->textContent),
                'html' => $this->clean_html($this->node_inner_html($value_node)),
                'links' => $links,
            );
        }

        $images = array();
        $seen = array();
        foreach ($xpath->query("//*[contains(@class,'woocommerce-product-gallery')]//img") as $image) {
            $src = $this->first_non_empty(array($image->getAttribute('data-large_image'), $image->getAttribute('data-src'), $image->getAttribute('src')));
            $src = $this->make_absolute_url($src, $url);
            if ($src === '' || isset($seen[$src])) { continue; }
            $seen[$src] = true;
            $images[] = array(
                'src' => $src,
                'alt' => $image->getAttribute('alt'),
                'title' => $image->getAttribute('title'),
                'srcset' => $image->getAttribute('srcset'),
                'width' => $image->getAttribute('width'),
                'height' => $image->getAttribute('height'),
                'attachment_id' => $image->getAttribute('data-attachment_id'),
            );
        }

        $taxonomies = array('categories' => array(), 'tags' => array());
        foreach (array('categories' => "//*[contains(@class,'posted_in')]//a", 'tags' => "//*[contains(@class,'tagged_as')]//a") as $key => $query) {
            foreach ($xpath->query($query) as $link) {
                $taxonomies[$key][] = array('name' => $this->clean_text($link->textContent), 'url' => $this->make_absolute_url($link->getAttribute('href'), $url));
            }
        }

        $breadcrumbs = array();
        foreach ($xpath->query("//*[contains(@class,'woocommerce-breadcrumb')]//a | //nav[contains(@class,'woocommerce-breadcrumb')]//a") as $link) {
            $breadcrumbs[] = array('name' => $this->clean_text($link->textContent), 'url' => $this->make_absolute_url($link->getAttribute('href'), $url));
        }

        $product_node = $xpath->query("//*[starts-with(@id,'product-') and contains(@class,'product')]")->item(0);
        $stock_node = $xpath->query("//*[contains(@class,'stock')]")->item(0);
        $price_node = $xpath->query("//*[contains(@class,'summary')]//*[contains(@class,'price')]")->item(0);
        $variation_raw = $this->extract_variations_json($xpath, $html);
        $variation_payload = $variation_raw !== '' ? json_decode(html_entity_decode($variation_raw, ENT_QUOTES | ENT_HTML5, 'UTF-8'), true) : array();
        if (!is_array($variation_payload)) { $variation_payload = array(); }

        return array(
            'extracted_via' => 'woocommerce_html_jsonld',
            'source_url' => $url,
            'identity' => array(
                'product_id' => $standard_data['product_id'],
                'sku' => $standard_data['sku'],
                'product_type' => $standard_data['product_type'],
                'product_classes' => $product_node ? $product_node->getAttribute('class') : '',
            ),
            'document' => array(
                'page_title' => $title_node ? $this->clean_text($title_node->textContent) : '',
                'canonical' => $canonical_node ? $this->make_absolute_url($canonical_node->nodeValue, $url) : '',
                'meta' => $meta,
                'breadcrumbs' => $breadcrumbs,
            ),
            'woocommerce' => array(
                'price_text' => $price_node ? $this->clean_text($price_node->textContent) : '',
                'price_html' => $price_node ? $this->clean_html($this->node_inner_html($price_node)) : '',
                'stock_text' => $stock_node ? $this->clean_text($stock_node->textContent) : '',
                'stock_classes' => $stock_node ? $stock_node->getAttribute('class') : '',
                'short_description_html' => $this->extract_inner_html_by_xpath($xpath, "//*[contains(@class,'woocommerce-product-details__short-description')]"),
                'description_html' => $this->first_non_empty(array($this->extract_inner_html_by_xpath($xpath, "//*[@id='tab-description']"), $this->extract_inner_html_by_xpath($xpath, "//*[contains(@class,'woocommerce-Tabs-panel--description')]"))),
                'attributes' => $attributes,
                'images' => $images,
                'taxonomies' => $taxonomies,
                'variation_payload' => $variation_payload,
            ),
            'json_ld_product' => $json_ld_product,
            'json_ld_documents' => $json_ld,
        );
    }

    private function node_inner_html($node) {
        $html = '';
        foreach ($node->childNodes as $child) { $html .= $node->ownerDocument->saveHTML($child); }
        return $html;
    }

    /**
     * Check if page contains a variable product form.
     */
    private function detect_variable_product($xpath, $html) {
        if ($xpath->query("//form[contains(@class, 'variations_form')]")->length > 0) {
            return true;
        }
        if (strpos($html, 'data-product_variations') !== false) {
            return true;
        }
        if ($xpath->query("//input[@name='variation_id']")->length > 0) {
            return true;
        }
        return false;
    }

    /**
     * Extract attributes and variations from variable product.
     */
    private function extract_variable_product_data($xpath, $html, $base_url) {
        $attributes = array();
        $variations = array();

        // Parse data-product_variations JSON
        $variations_json = $this->extract_variations_json($xpath, $html);
        $variations_data = array();
        if (!empty($variations_json)) {
            $variations_data = json_decode(html_entity_decode($variations_json), true);
            if (!is_array($variations_data)) {
                $variations_data = array();
            }
        }

        // Build attributes from select dropdowns
        $select_nodes = $xpath->query("//form[contains(@class, 'variations_form')]//select");
        $attr_index = 0;
        $attribute_keys = array();
        if ($select_nodes && $select_nodes->length) {
            foreach ($select_nodes as $select) {
                $attr_name = $select->getAttribute('data-attribute_name') ?: $select->getAttribute('name');
                $attr_name = str_replace('attribute_', '', $attr_name);
                $attr_label = $this->get_select_label($xpath, $select) ?: $attr_name;
                $option_nodes = $xpath->query('.//option', $select);
                $values = array();
                $option_details = array();
                if ($option_nodes) {
                    foreach ($option_nodes as $option) {
                        $value = $option->getAttribute('value');
                        if ($value === '') continue;
                        $label = trim($option->nodeValue);
                        $values[] = $label;
                        $option_details[$value] = array(
                            'label' => $label,
                            'price' => 0,
                            'image' => null,
                        );
                    }
                }
                $used_for_variations = true;
                $attribute_keys[$attr_index] = $select->getAttribute('name');
                $attributes[] = array(
                    'id'                  => $attr_index++,
                    'name'                => $attr_label,
                    'values'              => $values,
                    'option_details'      => $option_details,
                    'used_for_variations' => $used_for_variations,
                );
            }
        }

        // Build variations from JSON data
        if (!empty($variations_data)) {
            foreach ($variations_data as $var) {
                $attributes_map = array();
                $summary_parts = array();
                $var_image = '';
                $regular_price = '';
                $sale_price = null;

                // Map attributes
                foreach ($attributes as $attribute_index => $attr) {
                    $attr_key = $attribute_keys[$attribute_index] ?? ('attribute_' . sanitize_title($attr['name']));
                    if (isset($var['attributes'][$attr_key])) {
                        $attr_value = $var['attributes'][$attr_key];
                        $attributes_map[$attr['name']] = $attr_value;
                        $summary_parts[] = $attr['name'] . ': ' . $attr_value;
                    }
                }

                if (isset($var['display_regular_price'])) {
                    $regular_price = $var['display_regular_price'];
                    if (isset($var['display_price']) && $var['display_regular_price'] != $var['display_price']) {
                        $sale_price = $var['display_price'];
                    }
                } elseif (isset($var['display_price'])) {
                    $regular_price = $var['display_price'];
                }

                $stock_status = ($var['is_in_stock'] ?? false) ? 'in-stock' : 'out-of-stock';
                $stock_quantity = ( 'out-of-stock' === $stock_status ) ? 0 : null;
                if ($stock_status === 'in-stock' && isset($var['max_qty']) && is_numeric($var['max_qty'])) {
                    $stock_quantity = intval($var['max_qty']);
                }

                $sku = isset($var['sku']) ? (string) $var['sku'] : '';
                if (isset($var['image']['src'])) {
                    $var_image = $this->make_absolute_url($var['image']['src'], $base_url);
                }

                $variations[] = array(
                    'attributes_summary' => implode(', ', $summary_parts),
                    'attributes_map'     => $attributes_map,
                    'sku'                => $sku,
                    'regular_price'      => intval($regular_price),
                    'sale_price'         => !is_null($sale_price) ? intval($sale_price) : null,
                    'stock_status'       => $stock_status,
                    'stock_quantity'     => $stock_quantity,
                    'image'              => $var_image,
                );
            }
        }

        return array($attributes, $variations);
    }

    /**
     * Extract the JSON string of variations from the page.
     */
    private function extract_variations_json($xpath, $html) {
        $nodes = $xpath->query("//form[contains(@class, 'variations_form')]");
        if ($nodes && $nodes->length) {
            $json = $nodes->item(0)->getAttribute('data-product_variations');
            if (!empty($json)) {
                return $json;
            }
        }
        if (preg_match('/"data-product_variations"\s*:\s*(\\[.*?\\])/s', $html, $m)) {
            return $m[1];
        }
        return '';
    }

    /**
     * Find the <label> associated with a select element to get readable attribute name.
     */
    private function get_select_label($xpath, $select) {
        $label_nodes = $xpath->query("//label[@for='" . $select->getAttribute('id') . "']");
        if ($label_nodes && $label_nodes->length) {
            return $this->clean_text($label_nodes->item(0)->nodeValue);
        }
        return '';
    }

    /**
     * Build simple product attributes.
     */
    private function extract_simple_attributes($xpath) {
        $assoc = $this->extract_attributes_assoc($xpath);
        $formatted = array();
        $attr_index = 0;
        foreach ($assoc as $name => $value) {
            $values = $this->split_attribute_values($value);
            $formatted[] = array(
                'id'                  => $attr_index++,
                'name'                => $name,
                'values'              => $values,
                'option_details'      => array(),
                'used_for_variations' => false,
            );
        }
        return $formatted;
    }

    /**
     * Split a string by common separators into multiple attribute values.
     */
    private function split_attribute_values($value) {
        $value = $this->clean_text($value);
        if (empty($value)) return array();
        $parts = preg_split('/\s*[،,]\s*/u', $value, -1, PREG_SPLIT_NO_EMPTY);
        return array_map('trim', $parts);
    }

    /**
     * Original associative attribute extraction.
     */
    private function extract_attributes_assoc($xpath) {
        $attributes = array();

        $rows = $xpath->query("//*[contains(@class,'woocommerce-product-attributes')]//tr");
        if ($rows && $rows->length) {
            foreach ($rows as $row) {
                $label = '';
                $value = '';
                $th = $xpath->query('.//th', $row);
                if ($th && $th->length) {
                    $label = $this->clean_text($th->item(0)->textContent);
                }
                $td = $xpath->query('.//td', $row);
                if ($td && $td->length) {
                    $value = $this->clean_text($td->item(0)->textContent);
                }
                if ($label !== '' && $value !== '') {
                    $attributes[$label] = $value;
                }
            }
        }

        if (!empty($attributes)) {
            return $attributes;
        }

        $panel_text = $this->extract_text_by_xpath($xpath, "//*[@id='tab-additional_information' or contains(@class,'woocommerce-Tabs-panel--additional_information')]");
        if ($panel_text === '') {
            return $attributes;
        }

        $lines = preg_split('/\R/u', $panel_text);
        foreach ($lines as $line) {
            $line = $this->clean_text($line);
            if ($line === '' || mb_strpos($line, '|') === false) {
                continue;
            }
            $parts = preg_split('/\s*\|\s*/u', $line, 2);
            if (count($parts) === 2) {
                $label = $this->clean_text($parts[0]);
                $value = $this->clean_text($parts[1]);
                if ($label !== '' && $value !== '') {
                    $attributes[$label] = $value;
                }
            }
        }

        return $attributes;
    }

    private function display_source_data_sections($source_data) {
        $identity = isset($source_data['identity']) && is_array($source_data['identity']) ? $source_data['identity'] : array();
        $document = isset($source_data['document']) && is_array($source_data['document']) ? $source_data['document'] : array();
        $meta = isset($document['meta']) && is_array($document['meta']) ? $document['meta'] : array();
        $woo = isset($source_data['woocommerce']) && is_array($source_data['woocommerce']) ? $source_data['woocommerce'] : array();
        $attributes = isset($woo['attributes']) && is_array($woo['attributes']) ? $woo['attributes'] : array();
        $images = isset($woo['images']) && is_array($woo['images']) ? $woo['images'] : array();
        $variations = isset($woo['variation_payload']) && is_array($woo['variation_payload']) ? $woo['variation_payload'] : array();
        $json = wp_json_encode($source_data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        ?>
        <h2 class="mw-section-title">اطلاعات کامل و قابل‌اتکای منبع میرزایی‌واچ</h2>
        <p>این بخش از HTML عمومی WooCommerce، داده‌های JSON-LD و payload رسمی variation صفحه استخراج شده است.</p>
        <div class="mw-source-grid">
            <section class="mw-source-card"><h3>هویت و منبع</h3><table><tbody>
                <?php $this->source_row('روش استخراج', $source_data['extracted_via'] ?? ''); ?>
                <?php $this->source_row('URL منبع', $source_data['source_url'] ?? ''); ?>
                <?php $this->source_row('شناسه محصول', $identity['product_id'] ?? ''); ?>
                <?php $this->source_row('SKU', $identity['sku'] ?? ''); ?>
                <?php $this->source_row('نوع محصول', $identity['product_type'] ?? ''); ?>
                <?php $this->source_row('کلاس‌های WooCommerce', $identity['product_classes'] ?? ''); ?>
            </tbody></table></section>

            <section class="mw-source-card"><h3>SEO و metadata صفحه</h3><table><tbody>
                <?php $this->source_row('عنوان صفحه', $document['page_title'] ?? ''); ?>
                <?php $this->source_row('Canonical', $document['canonical'] ?? ''); ?>
                <?php foreach ($meta as $key => $value) { $this->source_row($key, $value); } ?>
            </tbody></table></section>

            <section class="mw-source-card"><h3>قیمت و موجودی خام WooCommerce</h3><table><tbody>
                <?php $this->source_row('متن قیمت', $woo['price_text'] ?? ''); ?>
                <?php $this->source_row('HTML قیمت', $woo['price_html'] ?? ''); ?>
                <?php $this->source_row('متن موجودی', $woo['stock_text'] ?? ''); ?>
                <?php $this->source_row('کلاس موجودی', $woo['stock_classes'] ?? ''); ?>
            </tbody></table></section>

            <section class="mw-source-card"><h3>طبقه‌بندی‌ها</h3>
                <?php foreach (array('categories' => 'دسته‌ها', 'tags' => 'برچسب‌ها') as $key => $label): ?>
                    <h4><?php echo esc_html($label); ?></h4><ul>
                    <?php foreach (($woo['taxonomies'][$key] ?? array()) as $item): ?><li><a href="<?php echo esc_url($item['url'] ?? ''); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html($item['name'] ?? ''); ?></a></li><?php endforeach; ?>
                    </ul>
                <?php endforeach; ?>
            </section>

            <section class="mw-source-card mw-wide"><h3>مسیر Breadcrumb</h3><table><tbody>
                <?php foreach (($document['breadcrumbs'] ?? array()) as $index => $item) { $this->source_row(($index + 1) . '. ' . ($item['name'] ?? ''), $item['url'] ?? ''); } ?>
            </tbody></table></section>

            <section class="mw-source-card mw-wide"><h3>تصاویر و metadata کامل (<?php echo (int) count($images); ?>)</h3><div class="mw-images">
                <?php foreach ($images as $image): ?><div class="mw-image">
                    <?php if (!empty($image['src'])): ?><img src="<?php echo esc_url($image['src']); ?>" alt="<?php echo esc_attr($image['alt'] ?? ''); ?>" loading="lazy"><?php endif; ?>
                    <p><strong>Alt:</strong> <?php echo esc_html($image['alt'] ?? '-'); ?></p>
                    <p><strong>Title:</strong> <?php echo esc_html($image['title'] ?? '-'); ?></p>
                    <p><strong>ابعاد:</strong> <?php echo esc_html(($image['width'] ?? '-') . ' × ' . ($image['height'] ?? '-')); ?></p>
                    <p><strong>Attachment ID:</strong> <?php echo esc_html($image['attachment_id'] ?? '-'); ?></p>
                    <p><a href="<?php echo esc_url($image['src'] ?? ''); ?>" target="_blank" rel="noopener noreferrer">مشاهده فایل اصلی</a></p>
                    <?php if (!empty($image['srcset'])): ?><details><summary>Srcset</summary><code><?php echo esc_html($image['srcset']); ?></code></details><?php endif; ?>
                </div><?php endforeach; ?>
            </div></section>

            <section class="mw-source-card mw-wide mw-scroll"><h3>تمام ویژگی‌های محصول (<?php echo (int) count($attributes); ?>)</h3><table><thead><tr><th>نام</th><th>مقدار</th><th>لینک‌های taxonomy</th><th>HTML منبع</th></tr></thead><tbody>
                <?php foreach ($attributes as $attribute): ?><tr>
                    <td><?php echo esc_html($attribute['label'] ?? ''); ?></td><td><?php echo esc_html($attribute['value'] ?? ''); ?></td>
                    <td><?php echo esc_html($this->source_compact_json($attribute['links'] ?? array())); ?></td><td><?php echo esc_html($attribute['html'] ?? ''); ?></td>
                </tr><?php endforeach; ?>
            </tbody></table></section>

            <section class="mw-source-card mw-wide"><h3>توضیحات خام قابل‌اتکا</h3>
                <h4>توضیح کوتاه</h4><div><?php echo wp_kses_post($woo['short_description_html'] ?? ''); ?></div>
                <h4>توضیحات کامل</h4><div><?php echo wp_kses_post($woo['description_html'] ?? ''); ?></div>
            </section>

            <section class="mw-source-card mw-wide mw-scroll"><h3>Payload کامل variationها (<?php echo (int) count($variations); ?>)</h3>
                <?php if ($variations): ?><table><thead><tr><th>ID</th><th>SKU</th><th>ویژگی‌ها</th><th>قیمت نمایشی</th><th>قیمت اصلی</th><th>موجودی</th><th>تعداد</th><th>تصویر</th><th>تمام داده</th></tr></thead><tbody>
                <?php foreach ($variations as $variation): ?><tr><td><?php echo esc_html($variation['variation_id'] ?? ''); ?></td><td><?php echo esc_html($variation['sku'] ?? ''); ?></td><td><?php echo esc_html($this->source_compact_json($variation['attributes'] ?? array())); ?></td><td><?php echo esc_html($variation['display_price'] ?? ''); ?></td><td><?php echo esc_html($variation['display_regular_price'] ?? ''); ?></td><td><?php echo esc_html(isset($variation['is_in_stock']) ? ($variation['is_in_stock'] ? 'بله' : 'خیر') : '-'); ?></td><td><?php echo esc_html($variation['max_qty'] ?? ''); ?></td><td><?php echo esc_html($variation['image']['full_src'] ?? ($variation['image']['src'] ?? '')); ?></td><td><details><summary>JSON</summary><code><?php echo esc_html($this->source_compact_json($variation)); ?></code></details></td></tr><?php endforeach; ?>
                </tbody></table><?php else: ?><p>این محصول payload متغیر ندارد و در WooCommerce ساده است.</p><?php endif; ?>
            </section>

            <section class="mw-source-card mw-wide"><h3>JSON-LD کامل صفحه (<?php echo (int) count($source_data['json_ld_documents'] ?? array()); ?> سند)</h3><pre class="mw-json"><?php echo esc_html(wp_json_encode($source_data['json_ld_documents'] ?? array(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT)); ?></pre></section>
            <section class="mw-source-card mw-wide"><details><summary style="cursor:pointer;font-weight:600">JSON کامل و بدون حذف دادهٔ قابل‌اتکا</summary><pre class="mw-json"><?php echo esc_html(false !== $json ? $json : '{}'); ?></pre></details></section>
        </div>
        <?php
    }

    private function source_row($label, $value) {
        echo '<tr><th>' . esc_html($label) . '</th><td>' . esc_html($this->source_scalar($value)) . '</td></tr>';
    }

    private function source_scalar($value) {
        if ($value === null || $value === '') { return '-'; }
        if (is_bool($value)) { return $value ? 'بله' : 'خیر'; }
        if (is_array($value) || is_object($value)) { return $this->source_compact_json($value); }
        return (string) $value;
    }

    private function source_compact_json($value) {
        $json = wp_json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        return false !== $json ? $json : '-';
    }

    // ──── Unchanged helper methods ────

    private function extract_json_ld_product($xpath, $url) {
        $nodes = $xpath->query("//script[@type='application/ld+json']");
        if (!$nodes || !$nodes->length) {
            return array();
        }

        foreach ($nodes as $node) {
            $raw = trim($node->textContent);
            if ($raw === '') {
                continue;
            }

            $decoded = json_decode($raw, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                continue;
            }

            $product = $this->find_product_in_json_ld($decoded);
            if (!empty($product)) {
                if (!empty($product['image'])) {
                    if (is_array($product['image'])) {
                        foreach ($product['image'] as $k => $image) {
                            $product['image'][$k] = $this->make_absolute_url($image, $url);
                        }
                    } else {
                        $product['image'] = $this->make_absolute_url($product['image'], $url);
                    }
                }
                return $product;
            }
        }

        return array();
    }

    private function find_product_in_json_ld($data) {
        if (!is_array($data)) {
            return array();
        }

        if (isset($data['@type'])) {
            $type = $data['@type'];
            if ((is_string($type) && strtolower($type) === 'product') ||
                (is_array($type) && in_array('Product', $type, true))) {
                return $data;
            }
        }

        if (isset($data['@graph']) && is_array($data['@graph'])) {
            foreach ($data['@graph'] as $item) {
                $found = $this->find_product_in_json_ld($item);
                if (!empty($found)) {
                    return $found;
                }
            }
        }

        foreach ($data as $item) {
            if (is_array($item)) {
                $found = $this->find_product_in_json_ld($item);
                if (!empty($found)) {
                    return $found;
                }
            }
        }

        return array();
    }

    private function extract_prices($xpath, $json_ld, $html) {
        $currency = 'تومان';
        $regular_price = '';
        $sale_price = '';

        $sale_node = $this->extract_text_by_xpath($xpath, "//*[contains(@class,'price')]//ins");
        $regular_node = $this->extract_text_by_xpath($xpath, "//*[contains(@class,'price')]//del");

        if ($sale_node !== '') {
            $sale_price = $this->extract_first_price_from_string($sale_node);
        }
        if ($regular_node !== '') {
            $regular_price = $this->extract_first_price_from_string($regular_node);
        }
        if ($regular_price === '' && $sale_price === '') {
            $price_text = $this->extract_text_by_xpath($xpath, "//*[contains(@class,'price')]");
            $single_price = $this->extract_first_price_from_string($price_text);
            if ($single_price !== '') {
                $regular_price = $single_price;
            }
        }

        $currency_symbol_text = $this->extract_text_by_xpath($xpath, "//*[contains(@class,'woocommerce-Price-currencySymbol')]");
        if (!empty($currency_symbol_text)) {
            $currency = $currency_symbol_text;
        }

        if ($regular_price === '' && $sale_price === '') {
            $offer = $this->array_get($json_ld, 'offers');
            if (is_array($offer)) {
                if (isset($offer[0]) && is_array($offer[0])) {
                    $offer = $offer[0];
                }
                $currency = $this->first_non_empty(array(
                    $this->array_get($offer, 'priceCurrency'),
                    $currency,
                ));
                $price = $this->normalize_price($this->array_get($offer, 'price'));
                if ($price !== '') {
                    $regular_price = $price;
                }
            }
        }

        return array($regular_price, $sale_price, $currency);
    }

    private function extract_stock($xpath, $json_ld, $html) {
        $stock_status = '';
        $stock_quantity = '';

        $stock_text = $this->normalize_whitespace($this->extract_text_by_xpath($xpath, "//*[contains(@class,'stock') or contains(@class,'availability')]"));
        if ($stock_text === '') {
            $stock_text = $this->normalize_whitespace(wp_strip_all_tags($html));
        }

        $availability = strtolower((string) $this->array_get($json_ld, 'offers.availability'));

        if ($availability !== '') {
            if (strpos($availability, 'instock') !== false) {
                $stock_status = 'in-stock';
            } elseif (strpos($availability, 'outofstock') !== false) {
                $stock_status = 'out-of-stock';
            }
        }

        if ($stock_status === '') {
            if (preg_match('/در\s+انبار\s+موجود\s+نمی\s+باشد/u', $stock_text)) {
                $stock_status = 'out-of-stock';
            } elseif (preg_match('/(\d+)\s*در\s+انبار/u', $stock_text, $m)) {
                $stock_status = 'in-stock';
                $stock_quantity = (string) intval($m[1]);
            } elseif (preg_match('/موجود/u', $stock_text)) {
                $stock_status = 'in-stock';
            }
        }

        if ($stock_quantity === '' && preg_match('/دسترسی\s*:\s*(\d+)/u', $stock_text, $m)) {
            $stock_quantity = (string) intval($m[1]);
        }
        if ($stock_quantity === '' && preg_match('/(\d+)\s*در\s+انبار/u', $stock_text, $m)) {
            $stock_quantity = (string) intval($m[1]);
        }

        if ($stock_status === 'out-of-stock') {
            $stock_quantity = '';
        }

        return array($stock_status, $stock_quantity);
    }

    private function extract_gallery_images($xpath, $featured_image, $base_url) {
        $images = array();
        $queries = array(
            "//*[contains(@class,'woocommerce-product-gallery__image')]//a/@href",
            "//*[contains(@class,'woocommerce-product-gallery__image')]//img/@src",
            "//*[@data-large_image]/@data-large_image",
            "//*[@data-src]/@data-src",
        );

        foreach ($queries as $query) {
            $nodes = $xpath->query($query);
            if (!$nodes || !$nodes->length) continue;
            foreach ($nodes as $node) {
                $value = trim($node->nodeValue);
                if ($value !== '') {
                    $images[] = $this->make_absolute_url($value, $base_url);
                }
            }
        }

        $images = array_values(array_unique($this->normalize_image_urls($images)));
        if (!empty($featured_image)) {
            $featured_clean = $this->normalize_image_url_single($this->make_absolute_url($featured_image, $base_url));
            $images = array_values(array_filter($images, function ($img) use ($featured_clean) {
                return $this->normalize_image_url_single($img) !== $featured_clean;
            }));
        }
        return $images;
    }

    private function normalize_image_urls($urls) {
        $result = array();
        foreach ($urls as $url) {
            $url = trim($url);
            if ($url === '') continue;
            $normalized = preg_replace('/[?#].*$/', '', $url);
            $normalized = preg_replace('/\-\d+x\d+(\.[^.]+)$/i', '$1', $normalized);
            if (!isset($result[$normalized])) {
                $result[$normalized] = $normalized;
            }
        }
        return array_values($result);
    }

    private function normalize_image_url_single($url) {
        $url = preg_replace('/[?#].*$/', '', $url);
        $url = preg_replace('/\-\d+x\d+(\.[^.]+)$/i', '$1', $url);
        return $url;
    }

    private function extract_categories_from_text($xpath) {
        $text = $this->extract_text_by_xpath($xpath, "//*[contains(@class,'product_meta')]");
        if ($text === '') {
            $text = $this->extract_text_by_xpath($xpath, '//body');
        }
        if (preg_match('/دسته\s*:\s*([^\n\r]+)/u', $text, $m)) {
            $raw = $this->clean_text($m[1]);
            $parts = preg_split('/\s*،\s*|\s*,\s*/u', $raw);
            return array_values(array_filter(array_map(array($this, 'clean_text'), $parts)));
        }
        return array();
    }

    private function extract_taxonomy_links($xpath, $query) {
        $items = array();
        $nodes = $xpath->query($query);
        if (!$nodes || !$nodes->length) return $items;
        foreach ($nodes as $node) {
            $text = $this->clean_text($node->textContent);
            if ($text !== '') $items[] = $text;
        }
        return array_values(array_unique($items));
    }

    private function extract_sku_from_text($text) {
        if (preg_match('/شناسه\s+محصول\s*:\s*([^\n\r]+)/u', $text, $m)) {
            return $this->clean_text($m[1]);
        }
        return '';
    }

    private function extract_labeled_text($xpath, $label) {
        $text = $this->extract_text_by_xpath($xpath, '//body');
        if ($text === '') return '';
        $pattern = '/' . preg_quote($label, '/') . '\s*:\s*([^\n\r]+)/u';
        if (preg_match($pattern, $text, $m)) {
            return $this->clean_text($m[1]);
        }
        return '';
    }

    private function extract_meta_content($xpath, $attr_name, $attr_value) {
        $query = "//meta[@" . $attr_name . "='" . $attr_value . "']";
        $nodes = $xpath->query($query);
        if ($nodes && $nodes->length) {
            return trim($nodes->item(0)->getAttribute('content'));
        }
        return '';
    }

    private function extract_text_by_xpath($xpath, $query) {
        $nodes = $xpath->query($query);
        if ($nodes && $nodes->length) {
            return $this->clean_text($nodes->item(0)->textContent);
        }
        return '';
    }

    private function extract_attribute_by_xpath($xpath, $query, $attribute) {
        $nodes = $xpath->query($query);
        if ($nodes && $nodes->length) {
            $node = $nodes->item(0);
            if ($node instanceof DOMAttr) {
                return trim($node->value);
            }
            if ($node instanceof DOMElement && $node->hasAttribute($attribute)) {
                return trim($node->getAttribute($attribute));
            }
        }
        return '';
    }

    private function extract_inner_html_by_xpath($xpath, $query) {
        $nodes = $xpath->query($query);
        if (!$nodes || !$nodes->length) return '';
        $node = $nodes->item(0);
        $html = '';
        foreach ($node->childNodes as $child) {
            $html .= $node->ownerDocument->saveHTML($child);
        }
        return trim($html);
    }

    private function clean_text($text) {
        $text = wp_strip_all_tags((string) $text);
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        return $this->normalize_whitespace($text);
    }

    private function clean_html($html) {
        $html = (string) $html;
        $html = html_entity_decode($html, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $html = trim($html);
        if ($html === '') return '';
        return wp_kses_post($html);
    }

    private function normalize_whitespace($text) {
        $text = str_replace(array("\xc2\xa0", '&nbsp;'), ' ', (string) $text);
        $text = preg_replace('/[ \t]+/u', ' ', $text);
        $text = preg_replace('/\s*\n\s*/u', "\n", $text);
        return trim($text);
    }

    private function extract_first_price_from_string($text) {
        $text = $this->convert_persian_digits((string) $text);
        if (preg_match('/(\d[\d,\.]*)/u', $text, $m)) {
            return $this->normalize_price($m[1]);
        }
        return '';
    }

    private function normalize_price($value) {
        $value = $this->convert_persian_digits((string) $value);
        $value = preg_replace('/[^\d]/u', '', $value);
        return trim($value);
    }

    private function convert_persian_digits($text) {
        $find = array('۰', '۱', '۲', '۳', '۴', '۵', '۶', '۷', '۸', '۹', '٬', '،');
        $replace = array('0', '1', '2', '3', '4', '5', '6', '7', '8', '9', ',', ',');
        return str_replace($find, $replace, (string) $text);
    }

    private function first_non_empty($values) {
        foreach ($values as $value) {
            if (is_array($value)) {
                if (!empty($value)) return $value;
            } elseif (trim((string) $value) !== '') {
                return $value;
            }
        }
        return '';
    }

    private function looks_like_url($value) {
        return is_string($value) && preg_match('#^https?://#i', $value);
    }

    private function make_absolute_url($maybe_url, $base_url) {
        $maybe_url = trim((string) $maybe_url);
        if ($maybe_url === '') return '';
        if (preg_match('#^https?://#i', $maybe_url)) return $maybe_url;
        if (strpos($maybe_url, '//') === 0) {
            $scheme = wp_parse_url($base_url, PHP_URL_SCHEME);
            if (!$scheme) $scheme = 'https';
            return $scheme . ':' . $maybe_url;
        }
        $base_parts = wp_parse_url($base_url);
        if (empty($base_parts['scheme']) || empty($base_parts['host'])) return $maybe_url;
        $scheme = $base_parts['scheme'];
        $host = $base_parts['host'];
        if (strpos($maybe_url, '/') === 0) return $scheme . '://' . $host . $maybe_url;
        $path = isset($base_parts['path']) ? $base_parts['path'] : '/';
        $path = preg_replace('#/[^/]*$#', '/', $path);
        return $scheme . '://' . $host . $path . ltrim($maybe_url, '/');
    }

    private function array_get($array, $path, $default = '') {
        if (!is_array($array) || $path === '') return $default;
        $segments = explode('.', $path);
        $value = $array;
        foreach ($segments as $segment) {
            if (is_array($value) && array_key_exists($segment, $value)) {
                $value = $value[$segment];
            } else {
                return $default;
            }
        }
        return $value;
    }
}
