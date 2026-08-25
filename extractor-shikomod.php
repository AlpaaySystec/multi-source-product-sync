<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

require_once plugin_dir_path( __FILE__ ) . 'class-product-dto.php';

class Shikomod_Product_Extractor {

    const MENU_SLUG = 'shikomod-extractor';
    public $source_data = array();

    public function __construct() {
        add_action( 'admin_menu', array( $this, 'add_admin_menu' ) );
    }

    public function add_admin_menu() {
        add_submenu_page(
            'edit.php?post_type=source_profile',
            'Shikomod Extractor',
            'Shikomod Extractor',
            'manage_options',
            self::MENU_SLUG,
            array( $this, 'render_admin_page' )
        );
    }

    public function render_admin_page() {
        if ( ! current_user_can('manage_options') ) {
            wp_die('Access denied.');
        }
        ?>
        <div class="wrap">
            <h1>Shikomod Product Extractor</h1>
            <p>Enter a product URL from <strong>shikomod.com</strong>.</p>
            <form method="post" action="">
                <?php wp_nonce_field( 'shikomod_extractor_action', 'shikomod_extractor_nonce' ); ?>
                <table class="form-table">
                    <tr>
                        <th scope="row"><label for="product_url">Product Page URL</label></th>
                        <td>
                            <input type="url" id="product_url" name="product_url"
                                   value="<?php echo isset($_POST['product_url']) ? esc_url($_POST['product_url']) : ''; ?>"
                                   placeholder="https://shikomod.com/product/..." size="60" required />
                        </td>
                    </tr>
                </table>
                <?php submit_button( 'Extract Product Data' ); ?>
            </form>
            <?php
            if ( isset($_POST['product_url']) && check_admin_referer('shikomod_extractor_action','shikomod_extractor_nonce') ) {
                $url = esc_url_raw( $_POST['product_url'] );
                if ( filter_var($url, FILTER_VALIDATE_URL) ) {
                    $this->extract_and_display( $url );
                } else {
                    echo '<div class="notice notice-error"><p>Invalid URL.</p></div>';
                }
            }
            ?>
        </div>
        <?php
    }

    /**
     * استخراج یک محصول – توسط موتور همگام‌سازی فراخوانی می‌شود.
     */
    public static function extract( $url ) {
        $instance = new self();
        $data     = $instance->extract_product_data( $url );
        if ( isset($data['error']) ) {
            return false;
        }
        $normalized = ProductDTO::normalize( $data );
        $normalized['source_data'] = $instance->source_data;
        return $normalized;
    }

    /**
     * دریافت لیست URLهای محصولات از سایتمپ شیکومد.
     * این متد برای مواقعی که sitemap_url خالی باشد توسط Sync Engine فراخوانی می‌شود.
     */
    public static function get_product_urls( $profile ) {
        // می‌توانید از فیلد sitemap_url در پروفایل استفاده کنید (اختیاری)
        $sitemap_url = ! empty( $profile['sitemap_url'] ) ? $profile['sitemap_url'] : 'https://shikomod.com/sitemap.xml';

        // ۱. دریافت و پارس سایتمپ اصلی
        $response = wp_remote_get( $sitemap_url, array( 'timeout' => 30 ) );
        if ( is_wp_error( $response ) || wp_remote_retrieve_response_code( $response ) !== 200 ) {
            return new WP_Error( 'sitemap_error', 'خطا در دریافت سایتمپ اصلی.' );
        }
        $main_xml = wp_remote_retrieve_body( $response );

        libxml_use_internal_errors( true );
        $main_dom = new DOMDocument();
        $main_dom->loadXML( $main_xml );
        $main_xpath = new DOMXPath( $main_dom );
        $main_xpath->registerNamespace( 'sm', 'http://www.sitemaps.org/schemas/sitemap/0.9' );

        // یافتن زیرسایتمپ‌های محصولات
        $sub_sitemap_nodes = $main_xpath->query( '//sm:sitemap/sm:loc' );
        $product_sub_urls = array();
        foreach ( $sub_sitemap_nodes as $node ) {
            $loc = trim( $node->textContent );
            if ( strpos( parse_url( $loc, PHP_URL_PATH ), '/sitemap.xml/products/' ) === 0 ) {
                $product_sub_urls[] = $loc;
            }
        }

        if ( empty( $product_sub_urls ) ) {
            return new WP_Error( 'no_product_sitemaps', 'هیچ زیرسایتمپ محصولی یافت نشد.' );
        }

        // ۲. دریافت URLهای محصولات از همهٔ زیرسایتمپ‌ها
        $all_product_urls = array();
        foreach ( $product_sub_urls as $sub_url ) {
            $sub_response = wp_remote_get( $sub_url, array( 'timeout' => 30 ) );
            if ( is_wp_error( $sub_response ) || wp_remote_retrieve_response_code( $sub_response ) !== 200 ) {
                continue;
            }
            $sub_xml = wp_remote_retrieve_body( $sub_response );

            $sub_dom = new DOMDocument();
            $sub_dom->loadXML( $sub_xml );
            $sub_xpath = new DOMXPath( $sub_dom );
            $sub_xpath->registerNamespace( 'sm', 'http://www.sitemaps.org/schemas/sitemap/0.9' );

            $url_nodes = $sub_xpath->query( '//sm:url/sm:loc' );
            foreach ( $url_nodes as $url_node ) {
                $product_url = trim( $url_node->textContent );
                if ( ! empty( $product_url ) ) {
                    $all_product_urls[] = $product_url;
                }
            }
        }

        return array_values( array_unique( $all_product_urls ) );
    }

    // ============================================================
    //  بخش استخراج محصول (دست‌نخورده و همان کدی که فرستادید)
    // ============================================================

    public function extract_product_data( $url ) {
        $validation_error = $this->validate_product_url($url);
        if ($validation_error !== '') return array('error' => $validation_error);
        $response = $this->request_product_page($url);
        if ( is_wp_error($response) || wp_remote_retrieve_response_code($response) !== 200 ) {
            return array( 'error' => 'Failed to fetch page.' );
        }
        $html = wp_remote_retrieve_body( $response );
        if ( empty($html) ) return array( 'error' => 'Empty body.' );

        return $this->parse_product_html($html, $url);
    }

    public function parse_product_html($html, $url) {

        $jsonld = $this->parse_json_ld_product( $html );
        if ( empty($jsonld) ) return array( 'error' => 'JSON‑LD not found.' );

        libxml_use_internal_errors(true);
        $dom = new DOMDocument();
        $dom->loadHTML( '<?xml encoding="UTF-8">' . $html );
        libxml_clear_errors();
        $xpath = new DOMXPath( $dom );

        $product_id = $jsonld['productID'] ?? $jsonld['sku'] ?? '';
        $sku        = $jsonld['sku'] ?? $product_id;
        $title      = $jsonld['name'] ?? '';

        $excerpt = $jsonld['description'] ?? '';
        $content = $this->extract_content( $xpath );

        $images          = $jsonld['image'] ?? array();
        $featured_image  = ! empty($images) ? $images[0] : '';
        $gallery_images  = array_slice( $images, 1 );

        $stock_status = $this->extract_stock_status( $jsonld );

        $categories = $this->extract_categories_from_jsonld( $jsonld );

        $is_variable = $xpath->query( "//select[@id='variant_id']" )->length > 0;
        $attributes  = array();
        $variations  = array();
        $regular_price = 0;
        $sale_price    = null;
        $currency      = 'تومان';

        if ( $is_variable ) {
            $pv_data = $this->parse_product_variants( $html );
            if ( $pv_data && isset( $pv_data['product']['variants'] ) ) {
                $product_obj  = $pv_data['product'];
                $attr_names   = $product_obj['options'] ?? array();
                $variants_raw = $product_obj['variants'] ?? array();

                $attr_values = array_fill(0, count($attr_names), array());
                foreach ( $variants_raw as $var ) {
                    for ( $i = 0; $i < count($attr_names); $i++ ) {
                        $opt_key = 'option' . ($i+1);
                        $val = $var[ $opt_key ] ?? '';
                        if ( ! in_array($val, $attr_values[$i]) ) {
                            $attr_values[$i][] = $val;
                        }
                    }
                }
                foreach ( $attr_names as $i => $name ) {
                    $attributes[] = array(
                        'id'                  => $i,
                        'name'                => $name,
                        'values'              => $attr_values[$i],
                        'option_details'      => array(),
                        'used_for_variations' => true,
                    );
                }

                foreach ( $variants_raw as $var ) {
                    $attr_map = array();
                    $summary_parts = array();
                    for ( $i = 0; $i < count($attr_names); $i++ ) {
                        $key = 'option' . ($i+1);
                        $val = $var[ $key ] ?? '';
                        $attr_map[ $attr_names[$i] ] = $val;
                        $summary_parts[] = $attr_names[$i] . ': ' . $val;
                    }

                    $var_price      = intval( $var['price'] ?? 0 );
                    $var_compare_at = intval( $var['compare_at_price'] ?? 0 );
                    if ( $var_compare_at > $var_price && $var_price > 0 ) {
                        $var_regular = $var_compare_at;
                        $var_sale    = $var_price;
                    } else {
                        $var_regular = $var_price;
                        $var_sale    = null;
                    }

                    $var_available  = (bool)( $var['available'] ?? false );
                    $var_stock_qty  = intval( $var['inventory_quantity'] ?? 0 );
                    $var_sku        = $var['sku'] ?? '';
                    $var_image      = $var['image'] ?? '';

                    if ( $var_image && ! preg_match('#^https?://#', $var_image) ) {
                        $var_image = 'https://cdnfa.com/shikomod/dfb3/files/' . ltrim($var_image, '/');
                    }

                    $variations[] = array(
                        'attributes_summary' => implode(', ', $summary_parts),
                        'attributes_map'     => $attr_map,
                        'sku'                => $var_sku,
                        'regular_price'      => $var_regular,
                        'sale_price'         => $var_sale,
                        'stock_status'       => $var_available ? 'in-stock' : 'out-of-stock',
                        'stock_quantity'     => $var_available ? $var_stock_qty : 0,
                        'manage_stock'       => true,
                        'image'              => $var_image,
                    );
                }

                if ( ! empty($variations) ) {
                    $regular_price = $variations[0]['regular_price'];
                    $sale_price    = $variations[0]['sale_price'];
                }
            } else {
                list( $attributes, $variations, $regular_price, $sale_price ) = $this->fallback_variable( $xpath );
            }
            $product_type = 'variable';
        } else {
            $product_type = 'simple';
            $price_node = $xpath->query( "//span[@id='ProductPrice']" );
            if ( $price_node->length ) {
                $price_text = $price_node->item(0)->getAttribute('data-price');
                if ( empty($price_text) ) {
                    $price_text = $this->clean_text( $price_node->item(0)->nodeValue );
                }
                $current_price = $this->normalize_price( $price_text );
            } else {
                $current_price = 0;
            }
            $old_price_node = $xpath->query( "//del[@id='ComparePrice']" );
            if ( $old_price_node->length ) {
                $old_price = $this->normalize_price( $this->clean_text( $old_price_node->item(0)->nodeValue ) );
                if ( $old_price > $current_price ) {
                    $regular_price = $old_price;
                    $sale_price    = $current_price;
                } else {
                    $regular_price = $current_price;
                }
            } else {
                $regular_price = $current_price;
            }
            $attributes = $this->extract_simple_attributes( $xpath );
        }

        $stock_quantity = null;
        if (!empty($variations)) {
            $stock_quantity = 0;
            foreach ($variations as $variation) {
                if (($variation['stock_status'] ?? '') === 'in-stock') $stock_quantity += max(0, (int) ($variation['stock_quantity'] ?? 0));
            }
        }

        $data = array(
            'product_id'     => (string) $product_id,
            'sku'            => $sku,
            'title'          => $this->clean_text( $title ),
            'excerpt'        => $excerpt,
            'content'        => $content,
            'featured_image' => $featured_image,
            'gallery_images' => $gallery_images,
            'regular_price'  => $regular_price,
            'sale_price'     => $sale_price,
            'currency'       => $currency,
            'stock_status'   => $stock_status,
            'stock_quantity' => $stock_quantity,
            'categories'     => $categories,
            'tags'           => array(),
            'product_type'   => $product_type,
            'attributes'     => $attributes,
            'variations'     => $variations,
        );
        $this->source_data = $this->extract_source_data($xpath, $html, $url, $jsonld, $data);
        $normalized = ProductDTO::normalize($data);
        $normalized['source_data'] = $this->source_data;
        return $normalized;
    }

    // ============================================================
    //  متدهای کمکی استخراج (همان کد اصلی)
    // ============================================================

    private function validate_product_url($url) {
        $parts = wp_parse_url($url);
        if (!is_array($parts) || empty($parts['scheme']) || empty($parts['host']) || empty($parts['path'])) return 'Invalid product URL.';
        $host = strtolower(rtrim($parts['host'], '.'));
        if (strtolower($parts['scheme']) !== 'https' || !in_array($host, array('shikomod.com', 'www.shikomod.com'), true)) return 'Only HTTPS Shikomod product URLs are allowed.';
        if (!empty($parts['user']) || !empty($parts['pass']) || !empty($parts['port']) || !preg_match('#^/product/\d+/?$#', $parts['path'])) return 'URL must be a valid Shikomod product page.';
        return '';
    }

    private function request_product_page($url) {
        $current_url = $url;
        for ($redirect = 0; $redirect <= 5; $redirect++) {
            $response = wp_safe_remote_get($current_url, array(
                'timeout' => 30,
                'redirection' => 0,
                'limit_response_size' => 6 * MB_IN_BYTES,
                'user-agent' => 'Mozilla/5.0 (compatible; Shikomod-Extractor/1.2; +WordPress)',
                'sslverify' => true,
            ));
            if (is_wp_error($response)) return $response;
            $status = (int) wp_remote_retrieve_response_code($response);
            if ($status < 300 || $status >= 400) return $response;
            $location = wp_remote_retrieve_header($response, 'location');
            if (!$location) return $response;
            $current_url = $this->make_absolute_url($location, $current_url);
            if ($this->validate_product_url($current_url) !== '') return new WP_Error('unsafe_redirect', 'Unsafe product redirect.');
        }
        return new WP_Error('too_many_redirects', 'Too many product redirects.');
    }

    private function extract_source_data($xpath, $html, $url, $jsonld_product, $standard_data) {
        $json_ld_documents = array();
        foreach ($xpath->query("//script[@type='application/ld+json']") as $node) {
            $decoded = json_decode(trim($node->textContent), true);
            if (is_array($decoded)) $json_ld_documents[] = $decoded;
        }
        $meta = array();
        foreach ($xpath->query('//meta[@content]') as $node) {
            $key = $node->getAttribute('property');
            if ($key === '') $key = $node->getAttribute('name');
            if ($key === '') $key = $node->getAttribute('itemprop');
            if ($key !== '') $meta[$key] = $node->getAttribute('content');
        }
        $canonical = $xpath->query("//link[contains(concat(' ',normalize-space(@rel),' '),' canonical ')]/@href")->item(0);
        $title = $xpath->query('//title')->item(0);

        $breadcrumbs = array();
        foreach ($xpath->query("//*[contains(@class,'breadcrumbs')]//a") as $link) {
            $breadcrumbs[] = array('name' => $this->clean_text($link->textContent), 'url' => $this->make_absolute_url($link->getAttribute('href'), $url));
        }
        $category_links = array();
        foreach ($xpath->query("//*[@id='description-pane']//*[contains(@class,'link-list')]//a") as $link) {
            $category_links[] = array('name' => $this->clean_text($link->textContent), 'url' => $this->make_absolute_url($link->getAttribute('href'), $url));
        }

        $images = array();
        $seen = array();
        foreach ($xpath->query("//*[@id='box_product_details']//a[@data-fancybox='slides']") as $link) {
            $img = $xpath->query('.//img', $link)->item(0);
            $src = $this->make_absolute_url($link->getAttribute('href'), $url);
            if ($src === '' || isset($seen[$src])) continue;
            $seen[$src] = true;
            $images[] = array(
                'src' => $src,
                'alt' => $img ? $img->getAttribute('alt') : '',
                'caption' => $link->getAttribute('data-caption'),
                'thumbnail' => $img ? $this->make_absolute_url($img->getAttribute('src'), $url) : '',
                'lazy_src' => $img ? $this->make_absolute_url($img->getAttribute('data-src'), $url) : '',
                'zoom_image' => $img ? $this->make_absolute_url($img->getAttribute('data-zoom-image'), $url) : '',
                'width' => $img ? $img->getAttribute('width') : '',
                'height' => $img ? $img->getAttribute('height') : '',
            );
        }

        $select_options = array();
        foreach ($xpath->query("//select[@id='variant_id']/option") as $option) {
            $select_options[] = array('variant_id' => $option->getAttribute('value'), 'label' => $this->clean_text($option->textContent), 'selected' => $option->hasAttribute('selected'));
        }
        $variant_payload = $this->parse_product_variants($html);
        if (!is_array($variant_payload)) $variant_payload = array();
        $price_node = $xpath->query("//span[@id='ProductPrice']")->item(0);
        $compare_node = $xpath->query("//del[@id='ComparePrice']")->item(0);
        $quantity_node = $xpath->query("//input[@id='quantity']")->item(0);
        $rating_node = $xpath->query("//input[contains(@class,'rating')]")->item(0);
        $subtitle_node = $xpath->query("//*[@id='box_product_details']//*[contains(@class,'subtitle')]")->item(0);
        $content_node = $xpath->query("//*[@id='description-pane']//*[contains(@class,'text-area')]")->item(0);

        return array(
            'extracted_via' => 'jsonld_product_variants_html',
            'source_url' => $url,
            'identity' => array('product_id' => $standard_data['product_id'], 'sku' => $standard_data['sku'], 'product_type' => $standard_data['product_type']),
            'document' => array('page_title' => $title ? $this->clean_text($title->textContent) : '', 'canonical' => $canonical ? $this->make_absolute_url($canonical->nodeValue, $url) : '', 'meta' => $meta, 'breadcrumbs' => $breadcrumbs),
            'product_ui' => array(
                'price_text' => $price_node ? $this->clean_text($price_node->textContent) : '',
                'price_data' => $price_node ? $price_node->getAttribute('data-price') : '',
                'compare_price_text' => $compare_node ? $this->clean_text($compare_node->textContent) : '',
                'quantity_min' => $quantity_node ? $quantity_node->getAttribute('min') : '',
                'quantity_max' => $quantity_node ? $quantity_node->getAttribute('max') : '',
                'rating_value' => $rating_node ? $rating_node->getAttribute('value') : '',
                'subtitle_text' => $subtitle_node ? $this->clean_text($subtitle_node->textContent) : '',
                'subtitle_html' => $subtitle_node ? $this->clean_html($this->inner_html($subtitle_node)) : '',
                'description_html' => $content_node ? $this->clean_html($this->inner_html($content_node)) : '',
                'category_links' => $category_links,
                'variant_select_options' => $select_options,
                'images' => $images,
            ),
            'json_ld_product' => $jsonld_product,
            'json_ld_documents' => $json_ld_documents,
            'product_variants' => $variant_payload,
        );
    }

    private function make_absolute_url($maybe_url, $base_url) {
        $maybe_url = trim((string) $maybe_url);
        if ($maybe_url === '') return '';
        if (preg_match('#^https?://#i', $maybe_url)) return $maybe_url;
        if (strpos($maybe_url, '//') === 0) return (wp_parse_url($base_url, PHP_URL_SCHEME) ?: 'https') . ':' . $maybe_url;
        $parts = wp_parse_url($base_url);
        if (empty($parts['scheme']) || empty($parts['host'])) return $maybe_url;
        return $parts['scheme'] . '://' . $parts['host'] . '/' . ltrim($maybe_url, '/');
    }

    private function extract_js_object( $html, $var_name ) {
        $pos = strpos( $html, "var $var_name" );
        if ( $pos === false ) return null;

        $start = strpos( $html, '{', $pos );
        if ( $start === false ) return null;

        $level = 0;
        $len   = strlen( $html );
        $quote = null;
        $escaped = false;
        $line_comment = false;
        $block_comment = false;
        for ( $i = $start; $i < $len; $i++ ) {
            $char = $html[$i];
            $next = ($i + 1 < $len) ? $html[$i + 1] : '';
            if ($line_comment) { if ($char === "\n") $line_comment = false; continue; }
            if ($block_comment) { if ($char === '*' && $next === '/') { $block_comment = false; $i++; } continue; }
            if ($quote !== null) {
                if ($escaped) { $escaped = false; continue; }
                if ($char === '\\') { $escaped = true; continue; }
                if ($char === $quote) $quote = null;
                continue;
            }
            if ($char === '/' && $next === '/') { $line_comment = true; $i++; continue; }
            if ($char === '/' && $next === '*') { $block_comment = true; $i++; continue; }
            if ($char === '"' || $char === "'") { $quote = $char; continue; }
            if ( $char === '{' ) {
                $level++;
            } elseif ( $char === '}' ) {
                $level--;
                if ( $level === 0 ) {
                    return substr( $html, $start, $i - $start + 1 );
                }
            }
        }
        return null;
    }

    private function clean_js_to_json( $js ) {
        $js = preg_replace( '/^\s*var\s+\w+\s*=\s*/', '', trim($js) );
        $js = preg_replace( '/;\s*$/', '', $js );
        $js = preg_replace( '~^\s*//.*$~m', '', $js );
        $js = preg_replace( '/:\s*function\s*\([^)]*\)\s*\{[^}]*\}\s*(,|\})/', ': null$1', $js );
        $js = preg_replace( '/:\s*(?!true\b|false\b|null\b)([a-zA-Z_$][a-zA-Z0-9_$]*)\s*(,|\})/', ': null$2', $js );
        $js = preg_replace( '/,\s*([\]}])/', '$1', $js );
        $js = preg_replace( '/([{,]\s*)([a-zA-Z_]\w*)\s*:/', '$1"$2":', $js );
        return $js;
    }

    private function parse_product_variants( $html ) {
        $js_object = $this->extract_js_object( $html, 'product_variants' );
        if ( ! $js_object ) return null;

        $json = $this->clean_js_to_json( $js_object );
        $data = json_decode( $json, true );
        if ( json_last_error() !== JSON_ERROR_NONE ) {
            return null;
        }
        return $data;
    }

    private function fallback_variable( $xpath ) {
        $attributes = array();
        $variations = array();
        $regular_price = 0;
        $sale_price    = null;

        $attr_names = array();
        $selector_nodes = $xpath->query( "//div[contains(@class, 'selector-variant')]" );
        foreach ( $selector_nodes as $sel ) {
            $label = $xpath->query( ".//label", $sel );
            if ( $label->length ) {
                $attr_names[] = $this->clean_text( $label->item(0)->nodeValue );
            }
        }
        if ( empty($attr_names) ) {
            $first_option = $xpath->query( "//select[@id='variant_id']/option[1]" );
            if ( $first_option->length ) {
                $text = $this->clean_text( $first_option->item(0)->nodeValue );
                $parts = explode( '/', $text );
                $cnt = count($parts);
                for ( $i = 0; $i < $cnt; $i++ ) {
                    $attr_names[] = 'ویژگی ' . ($i+1);
                }
            }
        }

        $options = $xpath->query( "//select[@id='variant_id']/option" );
        $attr_values = array_fill(0, count($attr_names), array());
        $var_list = array();

        foreach ( $options as $opt ) {
            $val = $opt->getAttribute( 'value' );
            $text = $this->clean_text( $opt->nodeValue );
            if ( empty($text) ) continue;
            $parts = array_map('trim', explode('/', $text));
            if ( count($parts) !== count($attr_names) ) continue;

            foreach ( $parts as $i => $v ) {
                if ( ! in_array($v, $attr_values[$i]) ) {
                    $attr_values[$i][] = $v;
                }
            }
            $var_list[] = array( 'value' => $val, 'attrs' => $parts );
        }

        foreach ( $attr_names as $i => $name ) {
            $attributes[] = array(
                'id'                  => $i,
                'name'                => $name,
                'values'              => $attr_values[$i],
                'option_details'      => array(),
                'used_for_variations' => true,
            );
        }

        $price_node = $xpath->query( "//span[@id='ProductPrice']" );
        if ( $price_node->length ) {
            $price_text = $price_node->item(0)->getAttribute('data-price');
            if ( empty($price_text) ) {
                $price_text = $this->clean_text( $price_node->item(0)->nodeValue );
            }
            $regular_price = $this->normalize_price( $price_text );
        }
        $old_price_node = $xpath->query( "//del[@id='ComparePrice']" );
        if ( $old_price_node->length ) {
            $old_price = $this->normalize_price( $this->clean_text( $old_price_node->item(0)->nodeValue ) );
            if ( $old_price > $regular_price ) {
                $sale_price    = $regular_price;
                $regular_price = $old_price;
            }
        }

        foreach ( $var_list as $var ) {
            $attr_map = array();
            $summary = array();
            foreach ( $attr_names as $i => $name ) {
                $attr_map[ $name ] = $var['attrs'][$i];
                $summary[] = $name . ': ' . $var['attrs'][$i];
            }
            $variations[] = array(
                'attributes_summary' => implode(', ', $summary),
                'attributes_map'     => $attr_map,
                'sku'                => '',
                'regular_price'      => $regular_price,
                'sale_price'         => $sale_price,
                'stock_status'       => 'in-stock',
                'stock_quantity'     => null,
                'image'              => '',
            );
        }

        return array( $attributes, $variations, $regular_price, $sale_price );
    }

    private function parse_json_ld_product( $html ) {
        if ( ! preg_match_all('/<script\s+type="application\/ld\+json"[^>]*>(.*?)<\/script>/is', $html, $m) ) return array();
        foreach ( $m[1] as $json ) {
            $data = json_decode( $json, true );
            if ( ! $data ) continue;
            $product = $this->find_json_ld_product($data);
            if (!empty($product)) return $product;
        }
        return array();
    }

    private function find_json_ld_product($data) {
        if (!is_array($data)) return array();
        $type = $data['@type'] ?? null;
        if ((is_string($type) && strtolower($type) === 'product') || (is_array($type) && in_array('Product', $type, true))) return $data;
        foreach ($data as $value) {
            if (is_array($value)) {
                $found = $this->find_json_ld_product($value);
                if (!empty($found)) return $found;
            }
        }
        return array();
    }

    private function extract_stock_status( $jsonld ) {
        $offers = $jsonld['offers'] ?? array();
        $availability = '';
        if ( isset($offers['offers'][0]['availability']) ) {
            $availability = $offers['offers'][0]['availability'];
        } elseif ( isset($offers['availability']) ) {
            $availability = $offers['availability'];
        }
        if (stripos($availability, 'OutOfStock') !== false) return 'out-of-stock';
        if (stripos($availability, 'InStock') !== false) return 'in-stock';
        return 'unknown';
    }

    private function extract_categories_from_jsonld( $jsonld ) {
        if ( ! empty($jsonld['category']) ) {
            $cats = is_array($jsonld['category']) ? $jsonld['category'] : array($jsonld['category']);
            $cats = array_filter( $cats, function($c) {
                return ! in_array($c, array('صفحه اصلی','محصولات','همه'));
            });
            return array_values( array_unique($cats) );
        }
        return array();
    }

    private function extract_content( $xpath ) {
        $nodes = $xpath->query( "//div[contains(@class,'text-area')]" );
        if ( $nodes->length ) {
            return $this->clean_html( $this->inner_html($nodes->item(0)) );
        }
        return '';
    }

    private function extract_simple_attributes( $xpath ) {
        $attrs = array();
        $subtitle = $xpath->query( "//div[contains(@class,'subtitle')]" );
        if ( $subtitle->length ) {
            $text = $this->clean_text( $subtitle->item(0)->nodeValue );
            $lines = preg_split('/\r\n|\r|\n/', $text);
            $index = 0;
            foreach ( $lines as $line ) {
                $line = trim($line);
                if ( empty($line) ) continue;
                if ( strpos($line, ':') !== false ) {
                    list($name, $value) = array_map('trim', explode(':', $line, 2));
                    $attrs[] = array(
                        'id'                  => $index++,
                        'name'                => $name,
                        'values'              => array($value),
                        'option_details'      => array(),
                        'used_for_variations' => false,
                    );
                }
            }
        }
        return $attrs;
    }

    private function inner_html( $node ) {
        $html = '';
        foreach ( $node->childNodes as $child ) {
            $html .= $node->ownerDocument->saveHTML( $child );
        }
        return $html;
    }

    private function clean_text( $text ) {
        $text = wp_strip_all_tags( (string)$text );
        $text = html_entity_decode( $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
        return trim( preg_replace('/\s+/u', ' ', $text) );
    }

    private function clean_html( $html ) {
        $html = (string)$html;
        $html = html_entity_decode( $html, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
        return wp_kses_post(trim($html));
    }

    private function normalize_price( $price_str ) {
        $price_str = $this->convert_persian_digits( (string)$price_str );
        return intval( preg_replace('/[^\d]/', '', $price_str) );
    }

    private function convert_persian_digits( $text ) {
        return str_replace(
            array('۰','۱','۲','۳','۴','۵','۶','۷','۸','۹'),
            array('0','1','2','3','4','5','6','7','8','9'),
            (string)$text
        );
    }

    private function extract_and_display( $url ) {
        $data = $this->extract_product_data( $url );
        if ( isset($data['error']) ) {
            echo '<div class="notice notice-error"><p>' . esc_html($data['error']) . '</p></div>';
            return;
        }
        $normalized = ProductDTO::normalize( $data );
        $normalized['source_data'] = $this->source_data;
        $this->display_product_data( $normalized, $this->source_data );
    }

    private function display_product_data( $data, $source_data = array() ) {
        ?>
        <style>
            .extracted-data { margin-top: 30px; direction: rtl; }
            .extracted-data hr { margin: 24px 0; }
            .extracted-data table { border-collapse: collapse; width: 100%; }
            .extracted-data th, .extracted-data td { border: 1px solid #ccc; padding: 8px; text-align: right; }
            .extracted-data .gallery-images img { width: 100px; height: 100px; object-fit: cover; margin: 4px; }
            .extracted-data .product-images img { max-width: 250px; height: auto; }
            .extracted-data ul { padding-right: 20px; }
            .shiko-section-title { margin-top:34px; border-right:4px solid #db2777; padding:10px 14px; background:#fdf2f8; }
            .shiko-source-grid { display:grid; grid-template-columns:repeat(auto-fit,minmax(360px,1fr)); gap:16px; align-items:start; }
            .shiko-source-card { background:#fff; border:1px solid #dcdcde; border-radius:8px; padding:15px; overflow-wrap:anywhere; }
            .shiko-wide { grid-column:1/-1; }
            .shiko-scroll { overflow-x:auto; }
            .shiko-source-card h3 { margin-top:0; }
            .shiko-source-card table th { width:210px; background:#f6f7f7; }
            .shiko-images { display:grid; grid-template-columns:repeat(auto-fill,minmax(190px,1fr)); gap:12px; }
            .shiko-image { border:1px solid #ddd; padding:10px; border-radius:6px; }
            .shiko-image img { width:100%; height:180px; object-fit:contain; }
            .shiko-json { direction:ltr; text-align:left; white-space:pre-wrap; max-height:650px; overflow:auto; background:#111827; color:#e5e7eb; padding:14px; }
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

    private function display_source_data_sections($source_data) {
        $identity = is_array($source_data['identity'] ?? null) ? $source_data['identity'] : array();
        $document = is_array($source_data['document'] ?? null) ? $source_data['document'] : array();
        $meta = is_array($document['meta'] ?? null) ? $document['meta'] : array();
        $ui = is_array($source_data['product_ui'] ?? null) ? $source_data['product_ui'] : array();
        $images = is_array($ui['images'] ?? null) ? $ui['images'] : array();
        $pv = is_array($source_data['product_variants'] ?? null) ? $source_data['product_variants'] : array();
        $variants = is_array($pv['product']['variants'] ?? null) ? $pv['product']['variants'] : array();
        $json = wp_json_encode($source_data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        ?>
        <h2 class="shiko-section-title">اطلاعات کامل و قابل‌اتکای منبع شیکومد</h2>
        <p>این بخش از JSON-LD رسمی محصول، payload داخلی product_variants و HTML عمومی صفحه استخراج شده است.</p>
        <div class="shiko-source-grid">
            <section class="shiko-source-card"><h3>هویت و منبع</h3><table><tbody>
                <?php $this->source_row('روش استخراج', $source_data['extracted_via'] ?? ''); ?>
                <?php $this->source_row('URL منبع', $source_data['source_url'] ?? ''); ?>
                <?php $this->source_row('شناسه محصول', $identity['product_id'] ?? ''); ?>
                <?php $this->source_row('SKU', $identity['sku'] ?? ''); ?>
                <?php $this->source_row('نوع محصول', $identity['product_type'] ?? ''); ?>
            </tbody></table></section>

            <section class="shiko-source-card"><h3>SEO و تمام metadata صفحه</h3><table><tbody>
                <?php $this->source_row('عنوان صفحه', $document['page_title'] ?? ''); ?>
                <?php $this->source_row('Canonical', $document['canonical'] ?? ''); ?>
                <?php foreach ($meta as $key => $value) { $this->source_row($key, $value); } ?>
            </tbody></table></section>

            <section class="shiko-source-card"><h3>قیمت، موجودی و سفارش از UI</h3><table><tbody>
                <?php $this->source_row('متن قیمت', $ui['price_text'] ?? ''); ?>
                <?php $this->source_row('data-price', $ui['price_data'] ?? ''); ?>
                <?php $this->source_row('قیمت قبل', $ui['compare_price_text'] ?? ''); ?>
                <?php $this->source_row('حداقل سفارش', $ui['quantity_min'] ?? ''); ?>
                <?php $this->source_row('حداکثر سفارش/موجودی', $ui['quantity_max'] ?? ''); ?>
                <?php $this->source_row('امتیاز UI', $ui['rating_value'] ?? ''); ?>
            </tbody></table></section>

            <section class="shiko-source-card"><h3>اطلاعات اصلی JSON-LD</h3><table><tbody>
                <?php foreach (($source_data['json_ld_product'] ?? array()) as $key => $value) { if (!is_array($value)) $this->source_row($key, $value); } ?>
                <?php $this->source_row('برند', $source_data['json_ld_product']['brand']['name'] ?? ''); ?>
                <?php $this->source_row('Offers', $source_data['json_ld_product']['offers'] ?? array()); ?>
                <?php $this->source_row('Aggregate Rating', $source_data['json_ld_product']['aggregateRating'] ?? array()); ?>
                <?php $this->source_row('Reviews', $source_data['json_ld_product']['review'] ?? array()); ?>
            </tbody></table></section>

            <section class="shiko-source-card shiko-wide"><h3>مسیر کامل دسته‌بندی</h3><table><tbody>
                <?php foreach (($document['breadcrumbs'] ?? array()) as $index => $item) { $this->source_row(($index + 1) . '. ' . ($item['name'] ?? ''), $item['url'] ?? ''); } ?>
                <?php foreach (($ui['category_links'] ?? array()) as $index => $item) { $this->source_row('دسته ' . ($index + 1) . ': ' . ($item['name'] ?? ''), $item['url'] ?? ''); } ?>
            </tbody></table></section>

            <section class="shiko-source-card shiko-wide"><h3>تصاویر و metadata کامل (<?php echo (int) count($images); ?>)</h3><div class="shiko-images">
                <?php foreach ($images as $image): ?><div class="shiko-image">
                    <?php if (!empty($image['src'])): ?><img src="<?php echo esc_url($image['src']); ?>" alt="<?php echo esc_attr($image['alt'] ?? ''); ?>" loading="lazy"><?php endif; ?>
                    <p><strong>Alt:</strong> <?php echo esc_html($image['alt'] ?? '-'); ?></p><p><strong>Caption:</strong> <?php echo esc_html($image['caption'] ?? '-'); ?></p>
                    <p><strong>ابعاد:</strong> <?php echo esc_html(($image['width'] ?? '-') . ' × ' . ($image['height'] ?? '-')); ?></p>
                    <p><strong>Thumbnail:</strong> <?php echo esc_html($image['thumbnail'] ?? '-'); ?></p><p><strong>Lazy:</strong> <?php echo esc_html($image['lazy_src'] ?? '-'); ?></p>
                    <p><a href="<?php echo esc_url($image['src'] ?? ''); ?>" target="_blank" rel="noopener noreferrer">مشاهده فایل اصلی</a></p>
                </div><?php endforeach; ?>
            </div></section>

            <section class="shiko-source-card shiko-wide"><h3>مشخصات و توضیحات کامل</h3>
                <h4>مشخصات خلاصه</h4><div><?php echo wp_kses_post($ui['subtitle_html'] ?? ''); ?></div>
                <table><tbody><?php $this->source_row('متن مشخصات', $ui['subtitle_text'] ?? ''); ?></tbody></table>
                <h4>توضیحات اصلی</h4><div><?php echo wp_kses_post($ui['description_html'] ?? ''); ?></div>
            </section>

            <section class="shiko-source-card shiko-wide shiko-scroll"><h3>گزینه‌های selector محصول (<?php echo (int) count($ui['variant_select_options'] ?? array()); ?>)</h3><table><thead><tr><th>Variant ID</th><th>عنوان</th><th>انتخاب‌شده</th></tr></thead><tbody>
                <?php foreach (($ui['variant_select_options'] ?? array()) as $option): ?><tr><td><?php echo esc_html($option['variant_id'] ?? ''); ?></td><td><?php echo esc_html($option['label'] ?? ''); ?></td><td><?php echo esc_html(!empty($option['selected']) ? 'بله' : 'خیر'); ?></td></tr><?php endforeach; ?>
            </tbody></table></section>

            <section class="shiko-source-card shiko-wide shiko-scroll"><h3>تمام variantها و موجودی دقیق (<?php echo (int) count($variants); ?>)</h3><table><thead><tr><th>ID</th><th>SKU</th><th>Barcode</th><th>عنوان/گزینه‌ها</th><th>قیمت</th><th>قیمت مقایسه</th><th>وضعیت</th><th>موجودی</th><th>حداقل سفارش</th><th>تصویر</th><th>JSON کامل</th></tr></thead><tbody>
                <?php foreach ($variants as $variant): ?><tr><td><?php echo esc_html($variant['id'] ?? ''); ?></td><td><?php echo esc_html($variant['sku'] ?? ''); ?></td><td><?php echo esc_html($variant['barcode'] ?? ''); ?></td><td><?php echo esc_html($this->variant_options_text($variant)); ?></td><td><?php echo esc_html($variant['price'] ?? ''); ?></td><td><?php echo esc_html($variant['compare_at_price'] ?? ''); ?></td><td><?php echo esc_html(!empty($variant['available']) ? 'موجود' : 'ناموجود'); ?></td><td><?php echo esc_html($variant['inventory_quantity'] ?? ''); ?></td><td><?php echo esc_html($variant['min_order'] ?? ''); ?></td><td><?php echo esc_html($variant['image'] ?? ''); ?></td><td><details><summary>JSON</summary><code><?php echo esc_html($this->source_compact_json($variant)); ?></code></details></td></tr><?php endforeach; ?>
            </tbody></table></section>

            <section class="shiko-source-card shiko-wide"><h3>تنظیمات کامل product_variants</h3><pre class="shiko-json"><?php echo esc_html(wp_json_encode($pv, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT)); ?></pre></section>
            <section class="shiko-source-card shiko-wide"><h3>تمام اسناد JSON-LD صفحه</h3><pre class="shiko-json"><?php echo esc_html(wp_json_encode($source_data['json_ld_documents'] ?? array(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT)); ?></pre></section>
            <section class="shiko-source-card shiko-wide"><details><summary style="cursor:pointer;font-weight:600">JSON کامل و بدون حذف دادهٔ قابل‌اتکا</summary><pre class="shiko-json"><?php echo esc_html(false !== $json ? $json : '{}'); ?></pre></details></section>
        </div>
        <?php
    }

    private function variant_options_text($variant) {
        $parts = array();
        for ($i = 1; $i <= 3; $i++) { $key = 'option' . $i; if (isset($variant[$key]) && $variant[$key] !== '') $parts[] = $variant[$key]; }
        return !empty($parts) ? implode(' / ', $parts) : ($variant['title'] ?? '');
    }

    private function source_row($label, $value) {
        echo '<tr><th>' . esc_html($label) . '</th><td>' . esc_html($this->source_scalar($value)) . '</td></tr>';
    }

    private function source_scalar($value) {
        if ($value === null || $value === '') return '-';
        if (is_bool($value)) return $value ? 'بله' : 'خیر';
        if (is_array($value) || is_object($value)) return $this->source_compact_json($value);
        return (string) $value;
    }

    private function source_compact_json($value) {
        $json = wp_json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        return false !== $json ? $json : '-';
    }
}
