<?php

if (!defined('ABSPATH')) {
    exit;
}

require_once plugin_dir_path(__FILE__) . 'class-product-dto.php';


class TimeStorr_Product_Extractor {

	private $debug_enabled = false;
	private $debug_trace   = array();

    public function __construct() {
        add_action('admin_menu', array($this, 'add_admin_menu'));
    }

    public function add_admin_menu() {
        add_submenu_page(
            'edit.php?post_type=source_profile',
            'TimeStorr Extractor',
            'TimeStorr Extractor',
            'manage_options',
            'timestorr-extractor',
            array($this, 'render_admin_page')
        );
    }

    public function render_admin_page() {
        ?>
        <div class="wrap">
            <h1>TimeStorr Product Extractor</h1>
            <p>Enter a product URL from <strong>time-storr.ir</strong>.</p>
            <form method="post" action="">
                <?php wp_nonce_field('timestorr_extractor_action', 'timestorr_extractor_nonce'); ?>
                <table class="form-table">
                    <tr>
                        <th scope="row"><label for="product_url">Product Page URL</label></th>
                        <td>
                            <input
                                type="url"
                                id="product_url"
                                name="product_url"
                                value="<?php echo isset($_POST['product_url']) ? esc_url($_POST['product_url']) : ''; ?>"
                                placeholder="https://time-storr.ir/product/..."
                                size="60"
                                required
                            />
                        </td>
                    </tr>
                </table>
                <?php submit_button('Extract Product Data'); ?>
            </form>
            <?php
            if (isset($_POST['product_url']) && check_admin_referer('timestorr_extractor_action', 'timestorr_extractor_nonce')) {
                $url = esc_url_raw($_POST['product_url']);
                if (filter_var($url, FILTER_VALIDATE_URL)) {
                    $this->extract_and_display($url);
                } else {
                    echo '<div class="notice notice-error"><p>Invalid URL.</p></div>';
                }
            }
            ?>
        </div>
        <?php
    }

    public static function extract($url) {
        $instance = new self();

        $response = wp_remote_get($url, array(
            'timeout' => 45,
            'redirection' => 5,
            'headers' => array(
                'user-agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/121 Safari/537.36',
                'accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,*/*;q=0.8',
                'accept-language' => 'fa-IR,fa;q=0.9,en-US;q=0.8,en;q=0.7',
            ),
        ));

        if (is_wp_error($response)) {
            return false;
        }

        $html = wp_remote_retrieve_body($response);
        if (empty($html)) {
            return false;
        }

        $result = $instance->parse_product_html($html, $url);
        if (!$result['success']) {
            return false;
        }

        return $result['data'];
    }

private function extract_and_display($url) {
    $response = wp_remote_get($url, array(
        'timeout' => 45,
        'redirection' => 5,
        'headers' => array(
            'user-agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/121 Safari/537.36',
            'accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,*/*;q=0.8',
            'accept-language' => 'fa-IR,fa;q=0.9,en-US;q=0.8,en;q=0.7',
        ),
    ));

    if (is_wp_error($response)) {
        echo '<div class="notice notice-error"><p>Error fetching page: ' . esc_html($response->get_error_message()) . '</p></div>';
        return;
    }

    $html = wp_remote_retrieve_body($response);
    if (empty($html)) {
        echo '<div class="notice notice-error"><p>Empty response.</p></div>';
        return;
    }

    $result = $this->parse_product_html($html, $url,  $this->debug_enabled); // debug=true فقط اینجا

    $this->render_debug_trace();

    if (!$result['success']) {
        echo '<div class="notice notice-error"><p>' . esc_html($result['error']) . '</p></div>';
        return;
    }

    $this->display_product_data($result['data']);
}

public function parse_product_html($html, $base_url, $debug = false) {
    $this->debug_enabled = $debug;
    $this->debug_trace   = array();

    $product_data = $this->parse_next_js_data($html);

    $this->trace(
        $product_data
            ? 'parse_product_html(): JSON payload موجود است، به‌عنوان منبع اصلی استفاده می‌شود'
            : 'parse_product_html(): JSON payload موجود نیست (null) — همه چیز از fallback های <meta>/HTML خوانده می‌شود',
        $product_data ? array_keys($product_data) : null
    );

    $dom = new DOMDocument();
    libxml_use_internal_errors(true);
    $dom->loadHTML('<?xml encoding="UTF-8">' . $html);
    libxml_clear_errors();
    $xpath = new DOMXPath($dom);

    $product_id = $product_data['product_id'] ?? $this->get_meta($xpath, 'product_id');
    if ( ! $product_id && preg_match('/\/product\/(\d+)\//', $base_url, $m) ) {
        $product_id = $m[1];
    }
    if ( ! $product_id ) {
        $this->trace('parse_product_html(): متوقف شد — هیچ product_id پیدا نشد');
        return array( 'success' => false, 'error' => 'Product ID missing.' );
    }

    $data = array();
    $data['product_id'] = $product_id;
    $data['sku']        = $product_id;
    $data['currency']   = 'تومان';

    $data['title'] = $product_data['title'] ?? $this->get_meta($xpath, 'product_name');

    if ( ! empty( $product_data['description'] ) ) {
        $data['excerpt'] = $product_data['description'];
    } else {
        $data['excerpt'] = $this->get_meta($xpath, 'description');
    }

    $parser = $xpath->query("//article[contains(@class,'parser')]");
    $data['content'] = $parser->length ? $dom->saveHTML($parser->item(0)) : '';

    if ( ! empty( $product_data['images'] ) ) {
        $this->trace('IMAGES: از JSON images[] استفاده شد (' . count($product_data['images']) . ' آیتم)');
        $normalize_img = function($url) use ($base_url) {
            $url = $this->make_absolute_url($url, $base_url);
            return strtok($url, '?');
        };
        $featured = '';
        $gallery  = array();
        foreach ( $product_data['images'] as $img ) {
            $abs = $normalize_img( $img['image_url'] );
            if ( ! empty( $img['default'] ) ) {
                $featured = $abs;
            } else {
                $gallery[] = $abs;
            }
        }
        $data['featured_image'] = $featured;
        $data['gallery_images'] = $gallery;
    } else {
        $this->trace('IMAGES: JSON موجود نیست -> FALLBACK به og:image + preload links + DOM scraping');
        $featured = '';
        $gallery  = array();
        $normalize_img = function ($url) {
            return strtok($url, '?');
        };
        $meta_og_image = $this->get_meta($xpath, 'og:image', 'property');
        if ($meta_og_image) {
            $featured = $normalize_img($meta_og_image);
        }
        $preload_links = $xpath->query("//link[@rel='preload'][@as='image']");
        foreach ($preload_links as $link) {
            $href = $link->getAttribute('href');
            if ($href && $this->looks_like_image($href)) {
                $abs = $this->make_absolute_url($href, $base_url);
                $abs = $normalize_img($abs);
                if (!$featured) $featured = $abs;
                $gallery[] = $abs;
            }
        }
        $slider = $xpath->query("//img[@data-zoom]");
        if ($slider->length) {
            $src = $slider->item(0)->getAttribute('src');
            if ($src) {
                $abs = $this->make_absolute_url($src, $base_url);
                $abs = $normalize_img($abs);
                if (!$featured) $featured = $abs;
                $gallery[] = $abs;
            }
        }
        $thumbs = $xpath->query("//img[contains(@src,'product-images') and contains(@src,'200x200')]");
        foreach ($thumbs as $img) {
            $src = $img->getAttribute('src');
            if ($src) {
                $gallery[] = $normalize_img($this->make_absolute_url($src, $base_url));
            }
        }
        $gallery = array_values(array_unique($gallery));
        if ($featured) {
            $gallery = array_values(array_filter($gallery, function ($u) use ($featured) {
                return $u !== $featured;
            }));
        } elseif (!empty($gallery)) {
            $featured = array_shift($gallery);
        }
        $data['featured_image'] = $featured;
        $data['gallery_images'] = $gallery;
    }

    if ( isset( $product_data['price'] ) ) {
        $this->trace('PRICE: از JSON price/compare_at_price استفاده شد');
        $price   = intval( $product_data['price'] );
        $compare = isset( $product_data['compare_at_price'] ) ? intval( $product_data['compare_at_price'] ) : 0;
        if ( $compare > $price && $price > 0 ) {
            $data['regular_price'] = $compare;
            $data['sale_price']    = $price;
        } else {
            $data['regular_price'] = $price;
            $data['sale_price']    = null;
        }
    } else {
        $this->trace('PRICE: JSON موجود نیست -> FALLBACK به <meta product_price / product_old_price>');
        $price   = intval( $this->get_meta($xpath, 'product_price') );
        $compare = $this->get_meta($xpath, 'product_old_price') ? intval( $this->get_meta($xpath, 'product_old_price') ) : 0;
        if ( $compare > $price && $price > 0 ) {
            $data['regular_price'] = $compare;
            $data['sale_price']    = $price;
        } else {
            $data['regular_price'] = $price;
            $data['sale_price']    = null;
        }
    }

    if ( isset( $product_data['stock_type'] ) ) {
        $this->trace('STOCK: از JSON stock_type = "' . $product_data['stock_type'] . '", max_available = ' . ($product_data['max_available'] ?? '(not set)'));
        $stock_type = $product_data['stock_type'];
        $max_qty    = isset( $product_data['max_available'] ) ? intval( $product_data['max_available'] ) : -1;
        if ( $stock_type === 'limited' && $max_qty > 0 ) {
            $data['stock_status']   = 'in-stock';
            $data['stock_quantity'] = $max_qty;
        } elseif ( $stock_type === 'unlimited' ) {
            $data['stock_status']   = 'in-stock';
            $data['stock_quantity'] = null;
        } else {
            $data['stock_status']   = 'out-of-stock';
            $data['stock_quantity'] = 0;
        }
    } else {
        $this->trace('STOCK: JSON موجود نیست -> FALLBACK به <meta availability> (همیشه فقط in-stock نامحدود یا out-of-stock می‌دهد، تعداد واقعی هرگز)');
        $meta_availability = $this->get_meta($xpath, 'availability');
        $meta_is_instock = (strtolower(trim((string) $meta_availability)) === 'instock');
        $data['stock_status']   = $meta_is_instock ? 'in-stock' : 'out-of-stock';
        $data['stock_quantity'] = $meta_is_instock ? null : 0;
    }

    $categories = array();
    $jsonld = $this->parse_json_ld($html);
    foreach ($jsonld as $item) {
        if (($item['@type'] ?? '') === 'BreadcrumbList' && !empty($item['itemListElement'])) {
            foreach ($item['itemListElement'] as $li) {
                if (!empty($li['name']) && $li['name'] !== 'فروشگاه تایم استور' && !preg_match('/^ساعت.*مدل/u', $li['name'])) {
                    $categories[] = $li['name'];
                }
            }
            break;
        }
    }
    if (empty($categories)) {
        $bc = $xpath->query("//div[contains(@class,'ds-caption-v2')]//a");
        foreach ($bc as $a) {
            $txt = trim($a->nodeValue);
            if ($txt && $txt !== 'فروشگاه تایم استور' && !preg_match('/^ساعت.*مدل/u', $txt)) {
                $categories[] = $txt;
            }
        }
    }
    $categories = array_filter($categories, function ($cat) use ($data) {
        return $cat !== $data['title'];
    });
    $data['categories'] = array_values(array_unique($categories));

    $data['tags'] = array();

    // ================== ویژگی‌ها و واریانت‌ها ==================
    $this->trace('--- ساخت جدول Attributes و Variations ---');

    $attributes = array();
    $variations = array();
    $has_variants = false;

    $this->trace(
        !empty($product_data['secondary_attributes'])
            ? 'ATTRS Step 1 (JSON secondary_attributes): ' . count($product_data['secondary_attributes']) . ' آیتم پیدا شد'
            : 'ATTRS Step 1 (JSON secondary_attributes): SKIPPED — در product_data نیست'
    );
    if ( ! empty( $product_data['secondary_attributes'] ) && is_array( $product_data['secondary_attributes'] ) ) {
        foreach ( $product_data['secondary_attributes'] as $attr ) {
            $name  = isset( $attr['name'] )  ? trim( (string) $attr['name'] )  : '';
            $value = isset( $attr['value'] ) ? trim( (string) $attr['value'] ) : '';
            if ( $name === '' || $value === '' ) {
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
    }
    $this->trace('ATTRS Step 1 نتیجه: ' . count($attributes) . ' attribute', array_column($attributes, 'name'));

    $this->trace(
        empty($attributes)
            ? 'ATTRS Step 2 (HTML «مشخصات» fallback): فعال شد چون Step 1 چیزی نساخت'
            : 'ATTRS Step 2 (HTML «مشخصات» fallback): SKIPPED — Step 1 قبلاً پر کرده'
    );
    if ( empty( $attributes ) ) {
        $spec_section = $xpath->query(
            "//section[
                .//div[contains(@class,'ds-h2') and contains(normalize-space(.),'مشخصات')]
            ]"
        );
        if ( $spec_section->length ) {
            $rows = $xpath->query(".//div[contains(@class,'flex gap-x-2')]", $spec_section->item(0));
            $seen_names = array();
            foreach ( $rows as $row ) {
                $ps = $row->getElementsByTagName('p');
                if ( $ps->length < 2 ) {
                    continue;
                }
                $name  = trim( preg_replace('/\s+/u', ' ', $ps->item(0)->nodeValue) );
                $value = trim( preg_replace('/\s+/u', ' ', $ps->item(1)->nodeValue) );
                if ( $name === '' || $value === '' || mb_strlen($name) > 50 || isset($seen_names[$name]) ) {
                    continue;
                }
                $seen_names[$name] = true;
                $attributes[] = array(
                    'id'                  => count( $attributes ),
                    'name'                => $name,
                    'values'              => array( $value ),
                    'option_details'      => array(),
                    'used_for_variations' => false,
                );
            }
        }
    }
    $this->trace('ATTRS Step 2 نتیجه: ' . count($attributes) . ' attribute', array_column($attributes, 'name'));

    $this->trace(
        !empty($product_data['main_attributes'])
            ? 'ATTRS Step 3 (JSON main_attributes مثل «رنگ»): ' . count($product_data['main_attributes']) . ' گروه پیدا شد'
            : 'ATTRS Step 3 (JSON main_attributes مثل «رنگ»): SKIPPED — در product_data نیست. ⚠️ دقیقاً همین دلیل غیبت «رنگ» در جدول است.'
    );

    $main_attributes      = array();
    $attribute_value_map  = array();

    if ( ! empty( $product_data['main_attributes'] ) && is_array( $product_data['main_attributes'] ) ) {
        foreach ( $product_data['main_attributes'] as $main_attr ) {
            if ( ! is_array( $main_attr ) ) {
                continue;
            }
            $attr_name = isset( $main_attr['name'] ) ? trim( (string) $main_attr['name'] ) : '';
            if ( $attr_name === '' ) {
                continue;
            }
            $values = array();
            if ( ! empty( $main_attr['values'] ) && is_array( $main_attr['values'] ) ) {
                foreach ( $main_attr['values'] as $option ) {
                    if ( ! is_array( $option ) ) {
                        continue;
                    }
                    $option_id = isset( $option['id'] ) ? (string) $option['id'] : '';
                    $option_value = isset( $option['value'] ) ? trim( (string) $option['value'] ) : '';
                    if ( $option_id === '' || $option_value === '' ) {
                        continue;
                    }
                    $values[] = $option_value;
                    $attribute_value_map[$option_id] = array(
                        'name'  => $attr_name,
                        'value' => $option_value,
                    );
                }
            }
            $values = array_values( array_unique( $values ) );
            $main_attributes[] = array(
                'name'   => $attr_name,
                'values' => $values,
            );
        }
    }

    foreach ( $main_attributes as $main_attr ) {
        $already_exists = false;
        foreach ( $attributes as $existing_attribute ) {
            if ( isset( $existing_attribute['name'] ) && $existing_attribute['name'] === $main_attr['name'] ) {
                $already_exists = true;
                break;
            }
        }
        if ( $already_exists ) {
            continue;
        }
        $attributes[] = array(
            'id'                  => count( $attributes ),
            'name'                => $main_attr['name'],
            'values'              => $main_attr['values'],
            'option_details'      => array(),
            'used_for_variations' => true,
        );
    }
    $this->trace('ATTRS Step 3 نتیجه: ' . count($attributes) . ' attribute', array_column($attributes, 'name'));

    $this->trace(
        !empty($product_data['variants'])
            ? 'VARIANTS Step 4 (JSON variants[]): ' . count($product_data['variants']) . ' واریانت پیدا شد'
            : 'VARIANTS Step 4 (JSON variants[]): SKIPPED — در product_data نیست'
    );
    if ( ! empty( $product_data['variants'] ) && is_array( $product_data['variants'] ) ) {
        $has_variants = true;
        foreach ( $product_data['variants'] as $var ) {
            if ( ! is_array( $var ) ) {
                continue;
            }
            $attr_map = array();
            $summary  = array();
            if ( ! empty( $var['attributes'] ) && is_array( $var['attributes'] ) ) {
                foreach ( $var['attributes'] as $option_id ) {
                    $option_id = (string) $option_id;
                    if ( ! isset( $attribute_value_map[$option_id] ) ) {
                        continue;
                    }
                    $attr_name  = $attribute_value_map[$option_id]['name'];
                    $attr_value = $attribute_value_map[$option_id]['value'];
                    $attr_map[$attr_name] = $attr_value;
                    $summary[] = $attr_name . ': ' . $attr_value;
                }
            }
            $var_price = isset( $var['price'] ) ? intval( $var['price'] ) : 0;
            $var_compare_at = isset( $var['compare_at_price'] ) ? intval( $var['compare_at_price'] ) : 0;
            if ( $var_compare_at > $var_price && $var_price > 0 ) {
                $var_regular = $var_compare_at;
                $var_sale    = $var_price;
            } else {
                $var_regular = $var_price;
                $var_sale    = null;
            }
            $var_max_available = isset( $var['max_available'] ) ? intval( $var['max_available'] ) : 0;
            if ( $var_max_available > 0 ) {
                $var_stock_status   = 'in-stock';
                $var_stock_quantity = $var_max_available;
            } else {
                $var_stock_status   = 'out-of-stock';
                $var_stock_quantity = 0;
            }
            $var_sku = '';
            if ( ! empty( $var['product_identifier'] ) ) {
                $var_sku = (string) $var['product_identifier'];
            }
            $var_image = '';
            if ( ! empty( $var['image_url'] ) ) {
                $var_image = $this->make_absolute_url( $var['image_url'], $base_url );
                $var_image = strtok( $var_image, '?' );
            }
            $variations[] = array(
                'attributes_summary' => implode( ', ', $summary ),
                'attributes_map'     => $attr_map,
                'sku'                => $var_sku,
                'regular_price'      => $var_regular,
                'sale_price'         => $var_sale,
                'stock_status'       => $var_stock_status,
                'stock_quantity'     => $var_stock_quantity,
                'manage_stock'       => true,
                'image'              => $var_image,
            );
        }
    }
    $this->trace('VARIANTS Step 4 نتیجه: has_variants=' . ($has_variants ? 'true' : 'false') . '، ' . count($variations) . ' واریانت');

    $this->trace(
        !$has_variants
            ? 'VARIANTS Step 5 (fallback دکمه‌های رنگ در HTML): فعال شد چون Step 4 چیزی نساخت'
            : 'VARIANTS Step 5 (fallback دکمه‌های رنگ در HTML): SKIPPED — Step 4 قبلاً از JSON ساخته'
    );
    if ( ! $has_variants ) {
        $variant_buttons = $xpath->query("//div[contains(@class,'flex flex-row flex-wrap gap-2')]//button");
        if ( $variant_buttons->length > 0 && empty( $product_data['main_attributes'] ) ) {
            $fallback_variations = array();
            foreach ( $variant_buttons as $btn ) {
                $text = trim( preg_replace('/\s+/u', ' ', $btn->nodeValue) );
                if ( $text === '' ) {
                    continue;
                }
                $fallback_variations[] = array(
                    'attributes_summary' => 'رنگ: ' . $text,
                    'attributes_map'     => array( 'رنگ' => $text ),
                    'sku'                => '',
                    'regular_price'      => $data['regular_price'],
                    'sale_price'         => $data['sale_price'],
                    'stock_status'       => $data['stock_status'],
                    'stock_quantity'     => $data['stock_quantity'],
                    'manage_stock'       => false,
                    'image'              => '',
                );
            }
            if ( ! empty( $fallback_variations ) ) {
                $has_variants = true;
                $variations = array_merge( $variations, $fallback_variations );
                $fallback_colors = array_values( array_unique( array_map( function( $variation ) {
                    return $variation['attributes_map']['رنگ'];
                }, $fallback_variations ) ) );
                $color_index = null;
                foreach ( $attributes as $index => $attribute ) {
                    if ( 'رنگ' === ( $attribute['name'] ?? '' ) ) {
                        $color_index = $index;
                        break;
                    }
                }
                if ( null === $color_index ) {
                    $attributes[] = array(
                        'id'                  => count( $attributes ),
                        'name'                => 'رنگ',
                        'values'              => $fallback_colors,
                        'option_details'      => array(),
                        'used_for_variations' => true,
                    );
                } else {
                    $attributes[ $color_index ]['values'] = array_values( array_unique( array_merge(
                        (array) ( $attributes[ $color_index ]['values'] ?? array() ),
                        $fallback_colors
                    ) ) );
                    $attributes[ $color_index ]['used_for_variations'] = true;
                }
                $this->trace(
                    'VARIANTS Step 5: ' . count($fallback_variations) . ' واریانت و attribute والد «رنگ» از روی متن دکمه‌های HTML ساخته شد.'
                );
            }
        }
    }
    $this->trace('VARIANTS Step 5 نتیجه: has_variants=' . ($has_variants ? 'true' : 'false') . '، ' . count($variations) . ' واریانت در مجموع');

    if ( empty($attributes) ) {
        $spec_section = $xpath->query("//section[.//div[contains(@class,'ds-h2') and contains(text(),'مشخصات')]]");
        if ( $spec_section->length ) {
            $rows = $xpath->query(".//div[contains(@class,'flex gap-x-2')]", $spec_section->item(0));
            $seen_names = array();
            foreach ( $rows as $row ) {
                $ps = $row->getElementsByTagName('p');
                if ( $ps->length >= 2 ) {
                    $name  = trim($ps->item(0)->nodeValue);
                    $value = trim($ps->item(1)->nodeValue);
                    if ( $name && $value && !in_array($name, $seen_names, true) && mb_strlen($name) <= 50 ) {
                        $seen_names[] = $name;
                        $attributes[] = array(
                            'id' => count($attributes),
                            'name' => $name,
                            'values' => array($value),
                            'option_details' => array(),
                            'used_for_variations' => false,
                        );
                    }
                }
            }
        }
    }

    $data['attributes'] = $attributes;
    $data['variations'] = $variations;
    $data['product_type'] = $has_variants ? 'variable' : 'simple';

    $this->trace(
        'نتیجه‌ی نهایی: product_type = "' . $data['product_type'] . '"',
        array(
            'attributes' => array_map(function($a) {
                return $a['name'] . ' (used_for_variations=' . ($a['used_for_variations'] ? 'YES' : 'no') . ')';
            }, $attributes),
            'variations_count' => count($variations),
        )
    );

    unset( $data['meta_data'] );

    $data = ProductDTO::normalize( $data );
    return array(
        'success' => true,
        'data'    => $data,
    );
}

    private function get_meta($xpath, $name, $attr = 'name') {
        $nodes = $xpath->query("//meta[@{$attr}='{$name}']/@content");
        return $nodes->length ? trim($nodes->item(0)->nodeValue) : '';
    }

    private function make_absolute_url($url, $base) {
        if (empty($url)) {
            return '';
        }

        if (parse_url($url, PHP_URL_SCHEME)) {
            return $url;
        }

        $base_parts = parse_url($base);
        if (!$base_parts || empty($base_parts['scheme']) || empty($base_parts['host'])) {
            return $url;
        }

        $scheme = $base_parts['scheme'];
        $host = $base_parts['host'];

        if (strpos($url, '/') === 0) {
            return $scheme . '://' . $host . $url;
        }

        $path = isset($base_parts['path']) ? $base_parts['path'] : '/';
        $dir = dirname($path);

        return $scheme . '://' . $host . $this->normalize_path($dir . '/' . $url);
    }

    private function normalize_path($path) {
        $parts = explode('/', $path);
        $out = array();

        foreach ($parts as $p) {
            if ($p === '' || $p === '.') {
                continue;
            }
            if ($p === '..') {
                array_pop($out);
            } else {
                $out[] = $p;
            }
        }

        return '/' . implode('/', $out);
    }

    private function parse_json_ld($html) {
        $result = array();
        preg_match_all('/<script\s+type="application\/ld\+json"[^>]*>(.*?)<\/script>/is', $html, $m);

        foreach ($m[1] as $json) {
            $d = json_decode($json, true);
            if ($d && isset($d['@type'])) {
                $result[] = $d;
            }
        }

        return $result;
    }

    private function looks_like_image($url) {
        return preg_match('/\.(jpg|jpeg|png|webp|avif)(\?|$)/i', $url) && stripos($url, 'logo') === false;
    }

    private function display_product_data($data) {
        ?>
        <style>
            .extracted-data { margin-top: 30px; direction: rtl; }
            .extracted-data hr { margin: 24px 0; }
            .extracted-data table { border-collapse: collapse; width: 100%; }
            .extracted-data th, .extracted-data td { border: 1px solid #ccc; padding: 8px; text-align: right; }
            .extracted-data .gallery-images img { width: 100px; height: 100px; object-fit: cover; margin: 4px; }
            .extracted-data .product-images img { max-width: 250px; height: auto; }
            .extracted-data ul { padding-right: 20px; }
        </style>

        <div class="extracted-data">
            <h2>Extracted Product Data</h2>
            <p><strong>Product ID:</strong> <?php echo esc_html($data['product_id']); ?></p>
            <p><strong>SKU:</strong> <?php echo esc_html($data['sku']); ?></p>
            <hr>
            <h1><?php echo esc_html($data['title']); ?></h1>
            <p><strong>Excerpt:</strong> <?php echo esc_html($data['excerpt']); ?></p>
            <div><strong>Content:</strong> <?php echo wp_kses_post($data['content']); ?></div>
            <hr>
            <h3>Images</h3>
            <p><strong>Featured Image:</strong></p>
            <?php if ($data['featured_image']): ?>
                <img src="<?php echo esc_url($data['featured_image']); ?>" alt="" style="max-width:260px;">
            <?php else: ?>
                <p>No featured image.</p>
            <?php endif; ?>

            <p><strong>Gallery:</strong></p>
            <div class="gallery-images">
                <?php if (!empty($data['gallery_images'])): ?>
                    <?php foreach ($data['gallery_images'] as $img): ?>
                        <img src="<?php echo esc_url($img); ?>" alt="">
                    <?php endforeach; ?>
                <?php else: ?>
                    <p>No gallery images.</p>
                <?php endif; ?>
            </div>
            <hr>
            <h3>Pricing & Stock</h3>
            <p>Currency: <?php echo esc_html($data['currency']); ?></p>
            <p>Regular Price: <?php echo esc_html(number_format($data['regular_price'], 0, '.', ',')); ?></p>
            <p>Sale Price: <?php echo $data['sale_price'] ? esc_html(number_format($data['sale_price'], 0, '.', ',')) : '-'; ?></p>
            <p>Stock Status: <?php echo esc_html($data['stock_status']); ?></p>
            <p>Stock Quantity: <?php echo $data['stock_quantity'] !== null ? esc_html($data['stock_quantity']) : '-'; ?></p>
            <hr>
            <h3>Categories</h3>
            <?php if (!empty($data['categories'])): ?>
                <ul>
                    <?php foreach ($data['categories'] as $c): ?>
                        <li><?php echo esc_html($c); ?></li>
                    <?php endforeach; ?>
                </ul>
            <?php else: ?>
                <p>No categories.</p>
            <?php endif; ?>

            <h3>Tags</h3>
            <?php if (!empty($data['tags'])): ?>
                <ul>
                    <?php foreach ($data['tags'] as $t): ?>
                        <li><?php echo esc_html($t); ?></li>
                    <?php endforeach; ?>
                </ul>
            <?php else: ?>
                <p>No tags.</p>
            <?php endif; ?>

            <hr>
            <h3>Product Type</h3>
            <p><?php echo esc_html($data['product_type']); ?></p>
            <hr>
            <h3>Attributes</h3>
            <?php if (!empty($data['attributes'])): ?>
                <table>
                    <thead>
                        <tr>
                            <th>Name</th>
                            <th>Values</th>
                            <th>Used for Variations?</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($data['attributes'] as $a): ?>
                            <tr>
                                <td><?php echo esc_html($a['name']); ?></td>
                                <td><?php echo esc_html(implode('، ', $a['values'])); ?></td>
                                <td><?php echo $a['used_for_variations'] ? 'Yes' : 'No'; ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php else: ?>
                <p>No attributes.</p>
            <?php endif; ?>

            <hr>
            <h3>Variations</h3>
            <?php if ($data['product_type'] === 'variable' && !empty($data['variations'])): ?>
                <table>
                    <thead>
                        <tr>
                            <th>Summary</th>
                            <th>Map</th>
                            <th>SKU</th>
                            <th>Regular Price</th>
                            <th>Sale Price</th>
                            <th>Stock</th>
                            <th>Qty</th>
                            <th>Image</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($data['variations'] as $v): ?>
                            <tr>
                                <td><?php echo esc_html($v['attributes_summary']); ?></td>
                                <td><?php echo esc_html(json_encode($v['attributes_map'])); ?></td>
                                <td><?php echo esc_html($v['sku']); ?></td>
                                <td><?php echo $v['regular_price'] ? esc_html(number_format($v['regular_price'], 0, '.', ',')) : '-'; ?></td>
                                <td><?php echo $v['sale_price'] ? esc_html(number_format($v['sale_price'], 0, '.', ',')) : '-'; ?></td>
                                <td><?php echo esc_html($v['stock_status']); ?></td>
                                <td><?php echo $v['stock_quantity'] !== null ? esc_html($v['stock_quantity']) : '-'; ?></td>
                                <td>
                                    <?php if ($v['image']): ?>
                                        <img src="<?php echo esc_url($v['image']); ?>" style="width:40px;height:40px;object-fit:cover;">
                                    <?php else: ?>
                                        -
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php else: ?>
                <p>No variations.</p>
            <?php endif; ?>
        </div>
        <?php
    }


	    /**
     * استخراج داده‌های محصول از payload Next.js داخل <script>self.__next_f.push
     *
     * @param string $html
     * @return array|null
     */
private function parse_next_js_data($html) {
    $chunks = $this->extract_next_f_chunks($html);

    $this->trace(
        'parse_next_js_data(): scanned HTML for self.__next_f.push(...) chunks',
        count($chunks) . ' chunk(s) found'
    );

    if (empty($chunks)) {
        $this->trace('parse_next_js_data(): NO chunks found at all -> falling back to <meta> tags');
        return $this->fallback_meta_extraction($html);
    }

    $decoded = '';
    foreach ($chunks as $idx => $chunk) {
        $piece = json_decode('"' . $chunk . '"');
        if ($piece === null && json_last_error() !== JSON_ERROR_NONE) {
            $this->trace("parse_next_js_data(): chunk #{$idx} failed json_decode (" . json_last_error_msg() . '), keeping raw text');
            $piece = $chunk;
        }
        $decoded .= $piece;
    }

    $this->trace('parse_next_js_data(): total decoded RSC stream length', strlen($decoded) . ' characters');

    $pos = strpos($decoded, '"ssrProductInfo":');
    if ($pos === false) {
        $this->trace('parse_next_js_data(): "ssrProductInfo" NOT FOUND in decoded stream -> falling back to <meta> tags. یعنی مسیر JSON اصلاً استفاده نشده!');
        return $this->fallback_meta_extraction($html);
    }
    $this->trace('parse_next_js_data(): "ssrProductInfo" found at offset', $pos);

    $pos = strpos($decoded, '{', $pos);
    if ($pos === false) {
        $this->trace('parse_next_js_data(): "{" بعد از ssrProductInfo پیدا نشد -> fallback');
        return $this->fallback_meta_extraction($html);
    }

    $jsonStr = $this->extract_balanced_json($decoded, $pos);
    if ($jsonStr === false) {
        $this->trace('parse_next_js_data(): extract_balanced_json() نتوانست آکولاد بسته را پیدا کند -> fallback');
        return $this->fallback_meta_extraction($html);
    }
    $this->trace('parse_next_js_data(): طول JSON استخراج‌شده‌ی ssrProductInfo', strlen($jsonStr) . ' characters');

    $productData = json_decode($jsonStr, true);
    if (json_last_error() !== JSON_ERROR_NONE || !is_array($productData)) {
        $this->trace('parse_next_js_data(): json_decode شکست خورد: ' . json_last_error_msg() . ' -> fallback', $jsonStr);
        return $this->fallback_meta_extraction($html);
    }

    $this->trace('parse_next_js_data(): ssrProductInfo با موفقیت پارس شد. کلیدهای سطح بالا', array_keys($productData));

    if (empty($productData['product_id']) && preg_match('/"ssrProductId":"(\d+)"/', $decoded, $m)) {
        $productData['product_id'] = $m[1];
        $this->trace('parse_next_js_data(): product_id از ssrProductId پر شد', $m[1]);
    }

    if (empty($productData['product_id'])) {
        $this->trace('parse_next_js_data(): product_id همچنان پیدا نشد -> fallback');
        return $this->fallback_meta_extraction($html);
    }

    $normalized = $this->normalize_product_data($productData);

    $this->trace(
        'parse_next_js_data(): موفق ✅ — از JSON برای همه چیز استفاده می‌شود',
        array(
            'has main_attributes?'      => isset($normalized['main_attributes']) ? 'YES (' . count($normalized['main_attributes']) . ')' : 'NO',
            'has secondary_attributes?' => isset($normalized['secondary_attributes']) ? 'YES (' . count($normalized['secondary_attributes']) . ')' : 'NO',
            'has variants?'              => isset($normalized['variants']) ? 'YES (' . count($normalized['variants']) . ')' : 'NO',
            'stock_type'                 => $normalized['stock_type'] ?? '(not set)',
            'max_available'              => $normalized['max_available'] ?? '(not set)',
        )
    );

    return $normalized;
}



/**
 * تمام محتوای self.__next_f.push([1,"...")‎ را بدون رجکس پیچیده،
 * کاراکتر به کاراکتر و با احترام به escape sequenceها استخراج می‌کند.
 */
private function extract_next_f_chunks($html) {
    $chunks = array();
    $needle = 'self.__next_f.push([1,"';
    $needle_len = strlen($needle);
    $len = strlen($html);
    $offset = 0;

    while (($pos = strpos($html, $needle, $offset)) !== false) {
        $i = $pos + $needle_len;
        $start = $i;
        $escape = false;

        while ($i < $len) {
            $ch = $html[$i];

            if ($escape) {
                $escape = false;
                $i++;
                continue;
            }
            if ($ch === '\\') {
                $escape = true;
                $i++;
                continue;
            }
            if ($ch === '"') {
                break; // پایان رشته‌ی جاوااسکریپت
            }
            $i++;
        }

        $chunks[] = substr($html, $start, $i - $start);
        $offset = $i + 1;
    }

    return $chunks;
}



/**
 * استخراج یک رشته JSON که از موقعیت $start شروع می‌شود و با شمارش
 * آکولادها و در نظر گرفتن رشته‌ها (با کاراکتر فرار) متعادل می‌ماند.
 */
	private function extract_balanced_json($string, $start) {
		$len = strlen($string);
		$depth = 0;
		$inString = false;
		$escape = false;
		$result = '';

		for ($i = $start; $i < $len; $i++) {
			$char = $string[$i];
			$result .= $char;

			if ($escape) {
				$escape = false;
				continue;
			}

			if ($char === '\\') {
				$escape = true;
				continue;
			}

			if ($char === '"') {
				$inString = !$inString;
				continue;
			}

			if (!$inString) {
				if ($char === '{') {
					$depth++;
				} elseif ($char === '}') {
					$depth--;
					if ($depth === 0) {
						return $result; // متعادل شد
					}
				}
			}
		}

		return false; // به انتها رسیدیم و متعادل نشد
	}

private function fallback_meta_extraction($html) {
    $this->trace('fallback_meta_extraction(): در حال اجرا — فقط از متاتگ‌ها می‌خواند، پس main_attributes/secondary_attributes/variants/images از JSON موجود نخواهند بود.');

    $data = array();

    if (preg_match('/<meta\s+name="product_id"\s+content="(\d+)"/i', $html, $m)) {
        $data['product_id'] = $m[1];
    }
    if (preg_match('/<meta\s+name="product_name"\s+content="([^"]*)"/i', $html, $m)) {
        $data['title'] = html_entity_decode($m[1], ENT_QUOTES, 'UTF-8');
    }
    if (preg_match('/<meta\s+name="product_price"\s+content="(\d+)"/i', $html, $m)) {
        $data['price'] = intval($m[1]);
    }
    if (preg_match('/<meta\s+name="product_old_price"\s+content="(\d+)"/i', $html, $m)) {
        $data['compare_at_price'] = intval($m[1]);
    }
    if (preg_match('/<meta\s+name="availability"\s+content="([^"]+)"/i', $html, $m)) {
        $data['stock_type'] = (strtolower(trim($m[1])) === 'instock') ? 'unlimited' : 'outofstock';
    }

    $this->trace('fallback_meta_extraction(): نتیجه', $data);

    return !empty($data['product_id']) ? $data : null;
}

	private function normalize_product_data($data) {
		$clean = array();

		$clean['product_id'] = isset($data['product_id']) ? (string) $data['product_id'] : '';

		// عنوان محصول در JSON با کلید "name" می‌آید نه "title"
		if (isset($data['name'])) {
			$clean['title'] = $data['name'];
		} elseif (isset($data['title'])) {
			$clean['title'] = $data['title'];
		}

		$passthrough_keys = array(
			'description',
			'price',
			'compare_at_price',
			'discount_percent',
			'max_available',
			'stock_type',
			'show_price',
			'has_variants',
			'guarantee',
			'min_order_quantity',
			'processing_time',
			'product_identifier',
			'images',
			'main_attributes',      // <-- قبلاً اصلاً map نمی‌شد
			'secondary_attributes',
			'variants',
			'tags',
			'brand',
			'main_category',
			'main_category_breadcrumb',
			'slug',
		);

		foreach ($passthrough_keys as $key) {
			if (isset($data[$key])) {
				$clean[$key] = $data[$key];
			}
		}

		return $clean;
	}

	private function trace($message, $data = null) {
		if ( ! $this->debug_enabled ) {
			return;
		}
		$this->debug_trace[] = array(
			'message' => $message,
			'data'    => $data,
		);
	}

	private function render_debug_trace() {
		if ( empty( $this->debug_trace ) ) {
			return;
		}
		echo '<div class="notice notice-info" style="padding:12px;">';
		echo '<h2>🔍 Debug Trace — منبع هر بخش از داده</h2>';
		echo '<ol style="font-family:monospace;font-size:12px;line-height:1.7;direction:ltr;text-align:left;">';
		foreach ( $this->debug_trace as $entry ) {
			echo '<li style="margin-bottom:6px;">' . esc_html( $entry['message'] );
			if ( $entry['data'] !== null ) {
				$rendered = is_scalar( $entry['data'] ) ? (string) $entry['data'] : print_r( $entry['data'], true );
				echo '<pre style="background:#111;color:#0f0;padding:8px;margin:4px 0;white-space:pre-wrap;overflow:auto;">' . esc_html( $rendered ) . '</pre>';
			}
			echo '</li>';
		}
		echo '</ol></div>';
	}
}
