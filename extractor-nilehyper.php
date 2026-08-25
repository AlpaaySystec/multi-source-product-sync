<?php

if (!defined('ABSPATH')) {
    exit;
}

// بارگذاری کلاس استانداردسازی
require_once plugin_dir_path(__FILE__) . 'class-product-dto.php';

class NileHyper_Product_Extractor {

    /**
     * فعال‌سازی حالت دیباگ دستی – با true کردن این پراپرتی از داخل کد
     */
    public static $debug_mode = false;

    /**
     * آرایه‌ای برای ذخیره‌ی اطلاعات دیباگ مربوط به موجودی و قیمت
     */
    public $debug_info = [];

    /** Complete trustworthy Livewire and document metadata for manual inspection. */
    public $source_data = [];

    public function __construct() {
        add_action('admin_menu', array($this, 'add_admin_menu'));
    }

    public function add_admin_menu() {
        add_submenu_page(
            'edit.php?post_type=source_profile',
            'NileHyper Extractor',
            'NileHyper Extractor',
            'manage_options',
            'nilehyper-extractor',
            array($this, 'render_admin_page')
        );
    }

    public function render_admin_page() {
		if (!current_user_can('manage_options')) {
			return;
		}
        ?>
        <div class="wrap">
            <h1>NileHyper Product Extractor</h1>
            <p>Enter the URL of a product page on <strong>nilehyper.com</strong> to extract its data.</p>

            <form method="post" action="">
                <?php wp_nonce_field('nilehyper_extractor_action', 'nilehyper_extractor_nonce'); ?>
                <table class="form-table">
                    <tr>
                        <th scope="row"><label for="product_url">Product Page URL</label></th>
                        <td>
                            <input type="url" id="product_url" name="product_url"
                                   value="<?php echo isset($_POST['product_url']) ? esc_url($_POST['product_url']) : ''; ?>"
                                   placeholder="https://nilehyper.com/..." size="60" required />
                        </td>
                    </tr>
                </table>
                <?php submit_button('Extract Product Data'); ?>
            </form>

            <?php
            if (isset($_POST['product_url']) && check_admin_referer('nilehyper_extractor_action', 'nilehyper_extractor_nonce')) {
                $url = esc_url_raw($_POST['product_url']);
                if (filter_var($url, FILTER_VALIDATE_URL)) {
                    $this->extract_and_display($url);
                } else {
                    echo '<div class="notice notice-error"><p>Invalid URL. Please enter a valid product page URL.</p></div>';
                }
            }
            ?>
        </div>
        <?php
    }

    /**
     * متد استاتیک استخراج محصول – برای Sync Engine
     *
     * @param string $url
     * @return array|false
     */
	public static function extract($url) {
		$instance = new self();
		$validated = $instance->validate_product_url($url);
		if (is_array($validated)) {
			return false;
		}
		$url = $validated;
		$response = $instance->request_product_page($url);
		if (is_wp_error($response) || 200 !== wp_remote_retrieve_response_code($response)) {
			return false;
		}
		$html = wp_remote_retrieve_body($response);
		if (empty($html)) {
			return false;
		}

		if (self::$debug_mode) {
			$instance->debug_info = [];
		}

		$raw_data = $instance->parse_product_html($html, $url);
		if (!$raw_data) {
			return false;
		}
		$normalized = ProductDTO::normalize($raw_data);
		$normalized['source_data'] = isset($raw_data['source_data']) && is_array($raw_data['source_data']) ? $raw_data['source_data'] : array();

		if (self::$debug_mode && !empty($instance->debug_info)) {
			error_log('=== NileHyper Extractor Debug for URL: ' . $url . ' ===');
			error_log(print_r($instance->debug_info, true));
			error_log('=== END Debug ===');
		}

		return $normalized;
	}

    private function extract_and_display($url) {
		$validated = $this->validate_product_url($url);
		if (is_array($validated)) {
			echo '<div class="notice notice-error"><p>' . esc_html($validated['error']) . '</p></div>';
			return;
		}
		$url = $validated;
		$response = $this->request_product_page($url);

        if (is_wp_error($response)) {
            echo '<div class="notice notice-error"><p>Error fetching page: ' . esc_html($response->get_error_message()) . '</p></div>';
            return;
        }

        $html = wp_remote_retrieve_body($response);
        if (empty($html)) {
            echo '<div class="notice notice-error"><p>Empty response from the server.</p></div>';
            return;
        }

        $this->debug_info = self::$debug_mode ? [] : null;

        $raw_data = $this->parse_product_html($html, $url);

        if (!$raw_data) {
            echo '<div class="notice notice-error"><p>Could not extract product data. The page might not be a valid NileHyper product page.</p></div>';
            return;
        }

		$product_data = ProductDTO::normalize($raw_data);
		$product_data['source_data'] = isset($raw_data['source_data']) && is_array($raw_data['source_data']) ? $raw_data['source_data'] : array();
		$this->display_product_data($product_data, $this->debug_info, $product_data['source_data']);
    }

	private function validate_product_url($url) {
		$url = esc_url_raw(trim((string) $url), array('https'));
		if ('' === $url) {
			return array('error' => 'آدرس محصول باید یک URL معتبر HTTPS باشد.');
		}
		$parts = wp_parse_url($url);
		$host = is_array($parts) && !empty($parts['host']) ? strtolower(rtrim($parts['host'], '.')) : '';
		if (!is_array($parts) || 'https' !== strtolower($parts['scheme'] ?? '') || !in_array($host, array('nilehyper.com', 'www.nilehyper.com'), true)) {
			return array('error' => 'فقط آدرس‌های HTTPS دامنه nilehyper.com مجاز هستند.');
		}
		if (isset($parts['user']) || isset($parts['pass']) || isset($parts['port']) || empty($parts['path']) || '/' === $parts['path']) {
			return array('error' => 'آدرس صفحهٔ محصول هایپرنیل معتبر نیست.');
		}
		return $url;
	}

	private function request_product_page($url) {
		$current = $url;
		for ($redirects = 0; $redirects <= 5; $redirects++) {
			$response = wp_safe_remote_get($current, array(
				'timeout' => 30,
				'redirection' => 0,
				'user-agent' => 'Mozilla/5.0 (compatible; NileHyper-Extractor/1.1; +wordpress)',
				'sslverify' => true,
				'limit_response_size' => 6 * MB_IN_BYTES,
			));
			if (is_wp_error($response)) {
				return $response;
			}
			$status = (int) wp_remote_retrieve_response_code($response);
			if ($status < 300 || $status >= 400) {
				return $response;
			}
			$location = wp_remote_retrieve_header($response, 'location');
			if (empty($location)) {
				return new WP_Error('nilehyper_redirect_missing', 'پاسخ تغییرمسیر بدون مقصد معتبر دریافت شد.');
			}
			$location = $this->make_absolute_url($location, $current);
			$validated = $this->validate_product_url($location);
			if (is_array($validated)) {
				return new WP_Error('nilehyper_redirect_rejected', $validated['error']);
			}
			$current = $validated;
		}
		return new WP_Error('nilehyper_too_many_redirects', 'تعداد تغییرمسیرهای صفحه بیش از حد مجاز است.');
	}

    private function make_absolute_url($relative, $base) {
        if (empty($relative)) {
            return $relative;
        }
        if (parse_url($relative, PHP_URL_SCHEME) !== null) {
            return $relative;
        }

        $base_parts = parse_url($base);
        if ($base_parts === false || !isset($base_parts['scheme'], $base_parts['host'])) {
            return $relative;
        }

        $scheme   = $base_parts['scheme'];
        $host     = $base_parts['host'];
        $base_path = isset($base_parts['path']) ? $base_parts['path'] : '/';

        if (strpos($relative, '/') === 0) {
            $path = $relative;
        } else {
            $dir = dirname($base_path);
            $path = $dir . '/' . $relative;
            $path = $this->normalize_path($path);
        }

        return $scheme . '://' . $host . $path;
    }

    private function normalize_path($path) {
        $parts    = explode('/', $path);
        $filtered = array();
        foreach ($parts as $part) {
            if ($part === '' || $part === '.') {
                continue;
            }
            if ($part === '..') {
                array_pop($filtered);
            } else {
                $filtered[] = $part;
            }
        }
        return '/' . implode('/', $filtered);
    }

    public function parse_product_html($html, $base_url) {
        $dom = new DOMDocument();
        libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="UTF-8">' . $html);
        libxml_clear_errors();

        $xpath = new DOMXPath($dom);

        $content_div = $xpath->query("//div[@id='content']")->item(0);
        if (!$content_div) {
            return false;
        }

        $snapshot = $content_div->getAttribute('wire:snapshot');
        if (!$snapshot) {
            return false;
        }

        $data = json_decode($snapshot, true);
        if (!isset($data['data']['product'][0])) {
            return false;
        }

        $product    = $data['data']['product'][0];
        $base_price = isset($data['data']['basePrice']) ? $data['data']['basePrice'] : $product['price'];
		$this->source_data = array(
			'extracted_via' => 'livewire_snapshot',
			'source_url' => $base_url,
			'payload' => $data['data'],
			'document' => $this->extract_document_metadata($xpath, $base_url),
		);

        $extracted = array();

        // IDs
        $extracted['product_id'] = $product['id'] ?? '';
        $extracted['sku']        = $product['sku'] ?? '';
        if (empty($extracted['sku']) && isset($product['title'])) {
            if (preg_match('/کد\s+([\d\-]+)/u', $product['title'], $matches)) {
                $extracted['sku'] = $matches[1];
            }
        }

        // Title and content
        $extracted['title']   = $product['title'] ?? '';
        $meta_desc            = $xpath->query("//meta[@name='description']/@content")->item(0);
        $extracted['excerpt'] = $meta_desc ? $meta_desc->nodeValue : '';
        $desc                 = $product['description'] ?? '';
        if (empty($desc)) {
            $detail_div = $xpath->query("//div[contains(@class, 'mb-5')]//p")->item(0);
            if ($detail_div) {
                $desc = $detail_div->nodeValue;
            }
        }
        $extracted['content'] = $desc;

        // Images
        $og_image = $xpath->query("//meta[@property='og:image']/@content")->item(0);
        $featured = $og_image ? $og_image->nodeValue : '';
        if (empty($featured)) {
            $main_img = $xpath->query("//img[@id='mainImage']/@src")->item(0);
            if ($main_img) {
                $featured = $main_img->nodeValue;
            }
        }
        $extracted['featured_image'] = $this->make_absolute_url($featured, $base_url);

        $extracted['gallery_images'] = array();
        $thumbnails = $xpath->query("//div[contains(@class, 'thumbnail-hover')]//img/@src");
        $featured_src = $extracted['featured_image'];
        foreach ($thumbnails as $img) {
            $src = $img->nodeValue;
            if (empty($src)) continue;
            $absolute_src = $this->make_absolute_url($src, $base_url);
            if ($absolute_src === $featured_src && empty($extracted['gallery_images'])) {
                continue;
            }
            $extracted['gallery_images'][] = $absolute_src;
        }

        // ---------- قیمت و موجودی محصول والد (اصلاح نهایی) ----------
        $extracted['regular_price'] = $product['price'] ?? '';
        if (is_array($this->debug_info)) {
            $this->debug_info['parent']['regular_price'] = [
                'method' => 'مستقیم از JSON فیلد product.price',
                'source' => 'JSON data.data.product[0].price',
                'line'   => __LINE__
            ];
        }

        $extracted['sale_price'] = $product['special_price'] ?? '';
        if (is_array($this->debug_info)) {
            $this->debug_info['parent']['sale_price'] = [
                'method' => 'مستقیم از JSON فیلد product.special_price (در صورت نبود خالی)',
                'source' => 'JSON data.data.product[0].special_price',
                'line'   => __LINE__
            ];
        }

        $extracted['currency'] = 'ریال';

        // دریافت تعداد واقعی موجودی – پیش‌فرض ۰
		$raw_quantity = isset($product['quantity']) ? max(0, intval($product['quantity'])) : 0;

        // تعیین وضعیت موجودی فقط بر اساس quantity
        if ($raw_quantity > 0) {
            $final_stock_status = 'in-stock';
        } else {
            $final_stock_status = 'out-of-stock';
        }

        $extracted['stock_status']   = $final_stock_status;
        $extracted['stock_quantity'] = $raw_quantity;
        $extracted['manage_stock']   = true; // فعال‌سازی مدیریت انبار در ووکامرس

        if (is_array($this->debug_info)) {
            $this->debug_info['parent']['stock_status'] = [
                'method' => 'تنها بر اساس quantity > 0 (فیلد stock نادیده گرفته شد)',
                'source' => 'JSON data.data.product[0].quantity',
                'line'   => __LINE__
            ];
            $this->debug_info['parent']['stock_quantity'] = [
                'method' => 'مستقیم از JSON فیلد product.quantity (پیش‌فرض ۰)',
                'source' => 'JSON data.data.product[0].quantity',
                'line'   => __LINE__
            ];
        }

        // Categories
        $categories = array();
        $breadcrumb_items = $xpath->query("//ul[contains(@class, 'container') and contains(@class, 'flex')]//li/a");
        if ($breadcrumb_items && $breadcrumb_items->length > 0) {
            $count = $breadcrumb_items->length;
            for ($i = 1; $i < $count - 1; $i++) {
                $item = $breadcrumb_items->item($i);
                if ($item) {
                    $cat_name = trim($item->nodeValue);
                    if (!empty($cat_name)) {
                        $categories[] = $cat_name;
                    }
                }
            }
        }
        if (empty($categories) && isset($product['category'])) {
            $categories[] = $product['category'];
        }
        if (empty($categories)) {
            $aria_breadcrumbs = $xpath->query("//nav[@aria-label='breadcrumb']//li/a");
            if ($aria_breadcrumbs && $aria_breadcrumbs->length > 0) {
                foreach ($aria_breadcrumbs as $item) {
                    $cat_name = trim($item->nodeValue);
                    if (!empty($cat_name)) {
                        $categories[] = $cat_name;
                    }
                }
            }
        }
        $extracted['categories'] = $categories;
        $extracted['tags']       = array();

        // Attributes & Variations
        $attributes  = array();
        $variations  = array();
        $is_variable = false;

        if (isset($product['attributes']) && is_array($product['attributes'])) {
            $attributes_raw = isset($product['attributes'][0]) ? $product['attributes'][0] : array();

            $select_attr_counts = array();
            foreach ($attributes_raw as $attr_id => $attr_data) {
                if (!is_array($attr_data) || !isset($attr_data[0])) continue;
                $attr_obj = $attr_data[0];
                if (!isset($attr_obj['name'], $attr_obj['options'])) continue;
                if (isset($attr_obj['type']) && $attr_obj['type'] === 'select') {
                    $options_container = $attr_obj['options'];
                    if (is_array($options_container) && isset($options_container[0])) {
                        $select_attr_counts[$attr_id] = count($options_container[0]);
                    }
                }
            }

            $total_combinations = 1;
            foreach ($select_attr_counts as $count) {
                $total_combinations *= $count;
            }
            $max_combinations = 500;

            if ($total_combinations > $max_combinations) {
                if (class_exists('Sync_Logger')) {
                    Sync_Logger::log(
                        sprintf('محصول %s تعداد ترکیب‌های بسیار زیادی دارد (%d). به %d محدود شد.',
                            $extracted['product_id'] ?? $url, $total_combinations, $max_combinations),
                        'warning'
                    );
                }
                $is_variable    = false;
                $extracted['product_type'] = 'simple';
                $extracted['variations']   = array();
            } else {
                $is_variable = ($total_combinations > 1);
            }

            foreach ($attributes_raw as $attr_id => $attr_data) {
                if (!is_array($attr_data) || !isset($attr_data[0])) continue;
                $attr_obj = $attr_data[0];
                if (!isset($attr_obj['name'], $attr_obj['options'])) continue;

                $attr_name = $attr_obj['name'];
                $options_container = $attr_obj['options'];
                if (!is_array($options_container) || !isset($options_container[0])) continue;
                $options_list = $options_container[0];

                $values         = array();
                $option_details = array();
                foreach ($options_list as $opt_array) {
                    if (is_array($opt_array) && isset($opt_array[0])) {
                        $opt = $opt_array[0];
                        if (isset($opt['label'], $opt['value'])) {
                            $values[] = $opt['label'];
                            $option_details[$opt['value']] = array(
                                'label' => $opt['label'],
                                'price' => isset($opt['price']) ? $opt['price'] : 0,
                                'image' => isset($opt['image']) ? $this->make_absolute_url($opt['image'], $base_url) : null,
                            );
                        }
                    }
                }

                $is_select_attr = isset($attr_obj['type']) && $attr_obj['type'] === 'select';
                $used_for_variations = $is_variable && $is_select_attr;

                $attributes[] = array(
                    'id'                  => $attr_id,
                    'name'                => $attr_name,
                    'values'              => $values,
                    'option_details'      => $option_details,
                    'used_for_variations' => $used_for_variations,
                );
            }
        }

        $extracted['attributes'] = $attributes;

        if ($is_variable && count($attributes) > 0) {
            $variable_attributes = array_filter($attributes, function($attr) {
                return $attr['used_for_variations'];
            });

            $combinations = array(array());
            foreach ($variable_attributes as $attr) {
                $new_combos = array();
                $option_values = array_keys($attr['option_details']);
                foreach ($combinations as $combo) {
                    foreach ($option_values as $val) {
                        $new_combo = $combo;
                        $new_combo[$attr['id']] = $val;
                        $new_combos[] = $new_combo;
                    }
                }
                $combinations = $new_combos;
            }

            $base_price_int = intval($base_price);
            $var_index = 0;
            foreach ($combinations as $combo) {
                $summary_parts  = array();
                $attributes_map = array();
                $total_price    = $base_price_int;
                $var_image      = '';

                foreach ($combo as $attr_id => $opt_value) {
                    foreach ($attributes as $attr) {
                        if ($attr['id'] == $attr_id && isset($attr['option_details'][$opt_value])) {
                            $attr_name = $attr['name'];
                            $opt_label = $attr['option_details'][$opt_value]['label'];
                            $total_price += intval($attr['option_details'][$opt_value]['price']);
                            $summary_parts[] = $attr_name . ': ' . $opt_label;
                            $attributes_map[$attr_name] = $opt_label;
                            if (empty($var_image) && !empty($attr['option_details'][$opt_value]['image'])) {
                                $var_image = $attr['option_details'][$opt_value]['image'];
                            }
                            break;
                        }
                    }
                }
                $summary = implode(', ', $summary_parts);

                $sku_suffix = '';
                if (!empty($extracted['sku'])) {
                    $sku_suffix = '-' . implode('-', array_values($combo));
                }
                $sku = $extracted['sku'] ? $extracted['sku'] . $sku_suffix : '';

                // واریانت‌ها وضعیت و تعداد موجودی والد (اصلاح‌شده) را به ارث می‌برند
                $variations[] = array(
                    'attributes_summary' => $summary,
                    'attributes_map'     => $attributes_map,
                    'sku'                => $sku,
                    'regular_price'      => $total_price,
                    'sale_price'         => null,
                    'stock_status'       => $extracted['stock_status'],
                    'stock_quantity'     => $extracted['stock_quantity'],
    				'manage_stock'       => true,   // ← اضافه شد
                    'image'              => $var_image,
                );

                if (is_array($this->debug_info)) {
                    $this->debug_info['variations'][$var_index] = [
                        'regular_price' => [
                            'method' => 'محاسبه: basePrice + جمع قیمت‌های گزینه‌ها',
                            'source' => 'basePrice از snapshot، قیمت‌های گزینه‌ها از attributes',
                            'line'   => __LINE__
                        ],
                        'sale_price' => [
                            'method' => 'همواره null (قیمت ویژه برای متغیرها استخراج نمی‌شود)',
                            'source' => 'ندارد',
                            'line'   => __LINE__
                        ],
                        'stock_status' => [
                            'method' => 'به ارث برده شده از وضعیت موجودی محصول والد (تنها بر اساس quantity)',
                            'source' => 'JSON data.data.product[0].quantity (از طریق parent)',
                            'line'   => __LINE__
                        ],
                        'stock_quantity' => [
                            'method' => 'به ارث برده شده از تعداد موجودی محصول والد',
                            'source' => 'JSON data.data.product[0].quantity (از طریق parent)',
                            'line'   => __LINE__
                        ]
                    ];
                    $var_index++;
                }
            }
            $extracted['product_type'] = 'variable';
        } else {
            $extracted['product_type'] = 'simple';
        }

        $extracted['variations'] = $variations;
		$extracted['source_data'] = $this->source_data;

        return $extracted;
    }

	private function extract_document_metadata($xpath, $base_url) {
		$meta = array();
		foreach (array('description', 'keywords', 'robots') as $name) {
			$node = $xpath->query("//meta[@name='" . $name . "']/@content")->item(0);
			$meta[$name] = $node ? $node->nodeValue : null;
		}
		foreach (array('og:title', 'og:description', 'og:image', 'og:url', 'og:type') as $property) {
			$node = $xpath->query("//meta[@property='" . $property . "']/@content")->item(0);
			$meta[$property] = $node ? $node->nodeValue : null;
		}
		$title = $xpath->query('//title')->item(0);
		$canonical = $xpath->query("//link[@rel='canonical']/@href")->item(0);
		$meta['page_title'] = $title ? trim($title->nodeValue) : null;
		$meta['canonical'] = $canonical ? $this->make_absolute_url($canonical->nodeValue, $base_url) : null;

		$meta['breadcrumbs'] = array();
		foreach ($xpath->query("//nav[@aria-label='breadcrumb']//a | //ul[contains(@class,'container') and contains(@class,'flex')]//li/a") as $item) {
			$name = trim($item->nodeValue);
			$href = $item->getAttribute('href');
			if ('' !== $name) {
				$meta['breadcrumbs'][] = array('name' => $name, 'url' => $this->make_absolute_url($href, $base_url));
			}
		}

		$meta['images'] = array();
		$seen = array();
		foreach ($xpath->query("//img[@id='mainImage'] | //div[contains(@class,'thumbnail-hover')]//img") as $image) {
			$src = $this->make_absolute_url($image->getAttribute('src'), $base_url);
			if ('' === $src || isset($seen[$src])) {
				continue;
			}
			$seen[$src] = true;
			$meta['images'][] = array(
				'src' => $src,
				'alt' => $image->getAttribute('alt'),
				'title' => $image->getAttribute('title'),
			);
		}
		return $meta;
	}

    private function display_product_data($data, $debug_info = null, $source_data = array()) {
        ?>
        <style>
            .extracted-data { margin-top: 30px; }
            .extracted-data hr { margin: 24px 0; }
            .extracted-data table { border-collapse: collapse; width: 100%; }
            .extracted-data th, .extracted-data td { border: 1px solid #ccc; padding: 8px; text-align: right; }
            .extracted-data .gallery-images img { width: 100px; height: 100px; object-fit: cover; margin: 4px; }
            .extracted-data .product-images img { max-width: 250px; height: auto; }
            .extracted-data ul { padding-right: 20px; }
            .debug-info { color: #999; font-size: 0.9em; display: block; margin-top: 4px; }
            .debug-var-table td { vertical-align: top; }
			.nile-source-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(320px,1fr));gap:16px;margin-top:16px}.nile-source-card{background:#fff;border:1px solid #c3c4c7;border-radius:8px;padding:16px;box-shadow:0 1px 2px rgba(0,0,0,.04)}.nile-source-card h3{margin:0 0 12px}.nile-wide{grid-column:1/-1}.nile-table{width:100%;border-collapse:collapse}.nile-table th,.nile-table td{padding:8px 10px;border-bottom:1px solid #e2e4e7;text-align:right;vertical-align:top}.nile-table th{width:190px;color:#50575e}.nile-scroll{overflow:auto}.nile-badges{display:flex;flex-wrap:wrap;gap:6px}.nile-badge{padding:4px 9px;border-radius:999px;background:#f0f0f1}.nile-images{display:grid;grid-template-columns:repeat(auto-fill,minmax(180px,1fr));gap:12px}.nile-image{border:1px solid #dcdcde;border-radius:6px;padding:8px}.nile-image img{width:100%;height:170px;object-fit:contain;background:#f6f7f7}.nile-json{max-height:700px;overflow:auto;white-space:pre-wrap;word-break:break-word;direction:ltr;text-align:left;background:#1d2327;color:#f0f0f1;padding:14px;border-radius:6px}.nile-section-title{margin-top:30px;border-bottom:1px solid #c3c4c7;padding-bottom:8px}
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

                <p>
                    <strong>قیمت اصلی (Regular Price):</strong> <?php echo esc_html(number_format($data['regular_price'], 0, '.', ',')); ?>
                    <?php if (!empty($debug_info['parent']['regular_price'])): ?>
                        <span class="debug-info">
                            [Debug] روش: <?php echo esc_html($debug_info['parent']['regular_price']['method']); ?> |
                            منبع: <?php echo esc_html($debug_info['parent']['regular_price']['source']); ?> |
                            خط: <?php echo intval($debug_info['parent']['regular_price']['line']); ?>
                        </span>
                    <?php endif; ?>
                </p>

                <p>
                    <strong>قیمت فروش ویژه (Sale Price):</strong> <?php echo $data['sale_price'] ? esc_html(number_format($data['sale_price'], 0, '.', ',')) : '-'; ?>
                    <?php if (!empty($debug_info['parent']['sale_price'])): ?>
                        <span class="debug-info">
                            [Debug] روش: <?php echo esc_html($debug_info['parent']['sale_price']['method']); ?> |
                            منبع: <?php echo esc_html($debug_info['parent']['sale_price']['source']); ?> |
                            خط: <?php echo intval($debug_info['parent']['sale_price']['line']); ?>
                        </span>
                    <?php endif; ?>
                </p>

                <p><strong>وضعیت قیمت:</strong> <?php echo $data['sale_price'] ? 'در حراج' : 'عادی'; ?></p>

                <p>
                    <strong>موجودی (Stock Status):</strong> <?php echo esc_html($data['stock_status']); ?>
                    <?php if (!empty($debug_info['parent']['stock_status'])): ?>
                        <span class="debug-info">
                            [Debug] روش: <?php echo esc_html($debug_info['parent']['stock_status']['method']); ?> |
                            منبع: <?php echo esc_html($debug_info['parent']['stock_status']['source']); ?> |
                            خط: <?php echo intval($debug_info['parent']['stock_status']['line']); ?>
                        </span>
                    <?php endif; ?>
                </p>

                <p>
                    <strong>تعداد موجودی (Stock Quantity):</strong> <?php echo esc_html($data['stock_quantity']); ?>
                    <?php if (!empty($debug_info['parent']['stock_quantity'])): ?>
                        <span class="debug-info">
                            [Debug] روش: <?php echo esc_html($debug_info['parent']['stock_quantity']['method']); ?> |
                            منبع: <?php echo esc_html($debug_info['parent']['stock_quantity']['source']); ?> |
                            خط: <?php echo intval($debug_info['parent']['stock_quantity']['line']); ?>
                        </span>
                    <?php endif; ?>
                </p>
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
                    <table class="debug-var-table">
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
                                <?php if (!empty($debug_info['variations'])): ?>
                                    <th>دیباگ قیمت/موجودی</th>
                                <?php endif; ?>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($data['variations'] as $idx => $var): ?>
                                <tr>
                                    <td><?php echo esc_html($var['attributes_summary']); ?></td>
                                    <td><?php echo esc_html(json_encode($var['attributes_map'])); ?></td>
                                    <td><?php echo esc_html($var['sku']); ?></td>
                                    <td><?php echo esc_html(number_format($var['regular_price'], 0, '.', ',')); ?></td>
                                    <td><?php echo $var['sale_price'] ? esc_html(number_format($var['sale_price'], 0, '.', ',')) : '-'; ?></td>
                                    <td><?php echo esc_html($var['stock_status']); ?></td>
                                    <td><?php echo esc_html($var['stock_quantity']); ?></td>
                                    <td>
                                        <?php if (!empty($var['image'])): ?>
                                            <img src="<?php echo esc_url($var['image']); ?>" alt="Variation image" style="width:40px;height:40px;object-fit:cover;">
                                        <?php else: ?>
                                            -
                                        <?php endif; ?>
                                    </td>
                                    <?php if (!empty($debug_info['variations'][$idx])): ?>
                                        <td style="font-size:0.85em;">
                                            <?php
                                            $vdb = $debug_info['variations'][$idx];
                                            echo '<div><strong>قیمت اصلی:</strong> ' . esc_html($vdb['regular_price']['method']) . ' | منبع: ' . esc_html($vdb['regular_price']['source']) . ' | خط: ' . intval($vdb['regular_price']['line']) . '</div>';
                                            echo '<div><strong>قیمت ویژه:</strong> ' . esc_html($vdb['sale_price']['method']) . ' | خط: ' . intval($vdb['sale_price']['line']) . '</div>';
                                            echo '<div><strong>موجودی:</strong> ' . esc_html($vdb['stock_status']['method']) . ' | منبع: ' . esc_html($vdb['stock_status']['source']) . ' | خط: ' . intval($vdb['stock_status']['line']) . '</div>';
                                            echo '<div><strong>تعداد:</strong> ' . esc_html($vdb['stock_quantity']['method']) . ' | منبع: ' . esc_html($vdb['stock_quantity']['source']) . ' | خط: ' . intval($vdb['stock_quantity']['line']) . '</div>';
                                            ?>
                                        </td>
                                    <?php endif; ?>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php else: ?>
                    <p><small>* برای محصولات ساده (simple) این بخش خالی می‌ماند.</small></p>
                <?php endif; ?>
            </div>

			<?php if (!empty($source_data)): ?>
				<?php $this->display_source_data_sections($source_data); ?>
			<?php endif; ?>
        </div>
        <?php
    }

	private function display_source_data_sections($source_data) {
		$payload = isset($source_data['payload']) && is_array($source_data['payload']) ? $source_data['payload'] : array();
		$product = isset($payload['product'][0]) && is_array($payload['product'][0]) ? $payload['product'][0] : array();
		$document = isset($source_data['document']) && is_array($source_data['document']) ? $source_data['document'] : array();
		$attributes = $this->source_attributes($product);
		$images = isset($document['images']) && is_array($document['images']) ? $document['images'] : array();
		$breadcrumbs = isset($document['breadcrumbs']) && is_array($document['breadcrumbs']) ? $document['breadcrumbs'] : array();
		$source_json = wp_json_encode($source_data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
		?>
		<h2 class="nile-section-title">اطلاعات کامل و قابل اتکای منبع هایپرنیل</h2>
		<p>این بخش مستقیماً از Livewire snapshot عمومی صفحه و metadata سند استخراج شده است.</p>
		<div class="nile-source-grid">
			<section class="nile-source-card">
				<h3>هویت و منبع</h3>
				<table class="nile-table"><tbody>
					<?php $this->source_row('روش استخراج', $source_data['extracted_via'] ?? ''); ?>
					<?php $this->source_row('URL منبع', $source_data['source_url'] ?? ''); ?>
					<?php $this->source_row('شناسه محصول', $product['id'] ?? ''); ?>
					<?php $this->source_row('عنوان', $product['title'] ?? ''); ?>
					<?php $this->source_row('SKU', $product['sku'] ?? ''); ?>
					<?php $this->source_row('برند', $product['brand'] ?? ''); ?>
					<?php $this->source_row('دسته', $product['category'] ?? ''); ?>
				</tbody></table>
			</section>

			<section class="nile-source-card">
				<h3>SEO و metadata صفحه</h3>
				<table class="nile-table"><tbody>
					<?php $this->source_row('عنوان صفحه', $document['page_title'] ?? ''); ?>
					<?php $this->source_row('عنوان Meta محصول', $product['meta_title'] ?? ''); ?>
					<?php $this->source_row('توضیح Meta محصول', $product['meta_description'] ?? ''); ?>
					<?php $this->source_row('Description سند', $document['description'] ?? ''); ?>
					<?php $this->source_row('Keywords محصول', $this->source_keywords($product['keywords'] ?? null)); ?>
					<?php $this->source_row('Robots', $document['robots'] ?? ''); ?>
					<?php $this->source_row('Canonical', $document['canonical'] ?? ''); ?>
					<?php $this->source_row('OG Title', $document['og:title'] ?? ''); ?>
					<?php $this->source_row('OG Description', $document['og:description'] ?? ''); ?>
					<?php $this->source_row('OG Image', $document['og:image'] ?? ''); ?>
					<?php $this->source_row('OG URL', $document['og:url'] ?? ''); ?>
				</tbody></table>
			</section>

			<section class="nile-source-card">
				<h3>قیمت و موجودی منبع</h3>
				<table class="nile-table"><tbody>
					<?php $this->source_row('قیمت محصول', $this->source_price($product['price'] ?? null)); ?>
					<?php $this->source_row('قیمت ویژه', $this->source_price($product['special_price'] ?? null)); ?>
					<?php $this->source_row('Base Price کامپوننت', $this->source_price($payload['basePrice'] ?? null)); ?>
					<?php $this->source_row('Total Price کامپوننت', $this->source_price($payload['totalPrice'] ?? null)); ?>
					<?php $this->source_row('وضعیت متنی انبار', $product['stock'] ?? ''); ?>
					<?php $this->source_row('تعداد واقعی انبار', $product['quantity'] ?? ''); ?>
					<?php $this->source_row('حداکثر سفارش محصول', $product['max_order_qty'] ?? ''); ?>
					<?php $this->source_row('حداکثر خرید کامپوننت', $payload['maxPurchasableQuantity'] ?? ''); ?>
					<?php $this->source_row('تعداد انتخاب‌شده کامپوننت', $payload['quantity'] ?? ''); ?>
				</tbody></table>
			</section>

			<section class="nile-source-card">
				<h3>فروش، ارسال و بازخورد</h3>
				<table class="nile-table"><tbody>
					<?php $this->source_row('Reward', $product['reward'] ?? ''); ?>
					<?php $this->source_row('تعداد دیدگاه‌ها', $product['total_reviews'] ?? ''); ?>
					<?php $this->source_row('میانگین امتیاز', $product['average_rating'] ?? ''); ?>
					<?php $this->source_row('ویژگی‌های خاص', $this->source_yes_no($product['spicalAttributes'] ?? null)); ?>
					<?php $this->source_row('اطلاعات ارسال', $this->source_compact_json($product['shippings'] ?? array())); ?>
					<?php $this->source_row('شناسه‌های Gallery', $this->source_compact_json($product['gallery'] ?? array())); ?>
				</tbody></table>
			</section>

			<section class="nile-source-card nile-wide">
				<h3>مسیر کامل دسته‌بندی</h3>
				<div class="nile-badges">
				<?php foreach ($breadcrumbs as $crumb): ?>
					<?php if (is_array($crumb)): ?><a class="nile-badge" href="<?php echo esc_url($crumb['url'] ?? ''); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html($crumb['name'] ?? ''); ?></a><?php endif; ?>
				<?php endforeach; ?>
				</div>
			</section>

			<section class="nile-source-card nile-wide">
				<h3>تصاویر و metadata آن‌ها (<?php echo (int) count($images); ?>)</h3>
				<div class="nile-images">
				<?php foreach ($images as $image): ?>
					<?php if (!is_array($image)) { continue; } ?>
					<div class="nile-image">
						<?php if (!empty($image['src'])): ?><img src="<?php echo esc_url($image['src']); ?>" alt="<?php echo esc_attr($image['alt'] ?? ''); ?>" loading="lazy" /><?php endif; ?>
						<p><strong>Alt:</strong> <?php echo esc_html($image['alt'] ?? '-'); ?></p>
						<p><strong>Title:</strong> <?php echo esc_html($image['title'] ?? '-'); ?></p>
						<p><a href="<?php echo esc_url($image['src'] ?? ''); ?>" target="_blank" rel="noopener noreferrer">مشاهده فایل اصلی</a></p>
					</div>
				<?php endforeach; ?>
				</div>
				<table class="nile-table"><tbody><?php $this->source_row('Thumbnail منبع', $product['thumbnail'] ?? ''); ?></tbody></table>
			</section>

			<section class="nile-source-card nile-wide nile-scroll">
				<h3>ویژگی‌ها و گزینه‌های کامل منبع (<?php echo (int) count($attributes); ?>)</h3>
				<table class="widefat striped"><thead><tr><th>شناسه</th><th>نام</th><th>نوع</th><th>Variable</th><th>Required</th><th>گزینه</th><th>Value</th><th>افزایش قیمت</th><th>تصویر گزینه</th></tr></thead><tbody>
				<?php foreach ($attributes as $attribute): ?>
					<?php $options = !empty($attribute['options']) ? $attribute['options'] : array(array()); $first = true; ?>
					<?php foreach ($options as $option): ?>
					<tr>
						<?php if ($first): ?><td rowspan="<?php echo (int) count($options); ?>"><?php echo esc_html($attribute['id']); ?></td><td rowspan="<?php echo (int) count($options); ?>"><?php echo esc_html($attribute['name']); ?></td><td rowspan="<?php echo (int) count($options); ?>"><?php echo esc_html($attribute['type']); ?></td><td rowspan="<?php echo (int) count($options); ?>"><?php echo esc_html($this->source_yes_no($attribute['variable'])); ?></td><td rowspan="<?php echo (int) count($options); ?>"><?php echo esc_html($this->source_yes_no($attribute['required'])); ?></td><?php endif; ?>
						<td><?php echo esc_html($option['label'] ?? '-'); ?></td><td><?php echo esc_html($option['value'] ?? '-'); ?></td><td><?php echo esc_html($this->source_price($option['price'] ?? null)); ?></td><td><?php echo esc_html($option['image'] ?? '-'); ?></td>
					</tr>
					<?php $first = false; endforeach; ?>
				<?php endforeach; ?>
				</tbody></table>
			</section>

			<section class="nile-source-card nile-wide">
				<h3>State کامپوننت Livewire</h3>
				<table class="nile-table"><tbody>
					<?php $this->source_row('Product URL', $payload['productUrl'] ?? ''); ?>
					<?php $this->source_row('Category URL', $payload['categoryUrl'] ?? ''); ?>
					<?php $this->source_row('Title state', $payload['title'] ?? ''); ?>
					<?php $this->source_row('Selected Attributes', $this->source_compact_json($payload['selectedAttributes'] ?? array())); ?>
					<?php $this->source_row('Attribute Prices', $this->source_compact_json($payload['attributePrices'] ?? array())); ?>
				</tbody></table>
			</section>

			<section class="nile-source-card nile-wide">
				<h3>تمام فیلدهای سطح محصول</h3>
				<table class="nile-table"><tbody>
				<?php foreach ($product as $key => $value): ?>
					<?php if (is_array($value) || is_object($value) || in_array($key, array('description'), true)) { continue; } ?>
					<?php $this->source_row($this->source_label($key), $value); ?>
				<?php endforeach; ?>
				</tbody></table>
			</section>

			<section class="nile-source-card nile-wide">
				<details><summary style="cursor:pointer;font-weight:600">JSON کامل و بدون حذف منبع</summary><pre class="nile-json"><?php echo esc_html(false !== $source_json ? $source_json : '{}'); ?></pre></details>
			</section>
		</div>
		<?php
	}

	private function source_row($label, $value) {
		echo '<tr><th>' . esc_html($label) . '</th><td>' . esc_html($this->source_scalar($value)) . '</td></tr>';
	}

	private function source_scalar($value) {
		if (null === $value || '' === $value) { return '-'; }
		if (is_bool($value)) { return $value ? 'بله' : 'خیر'; }
		return (string) $value;
	}

	private function source_yes_no($value) {
		if (null === $value || '' === $value) { return 'نامشخص'; }
		return (bool) $value ? 'بله' : 'خیر';
	}

	private function source_price($value) {
		if (null === $value || '' === $value || !is_numeric($value)) { return '-'; }
		return number_format((float) $value) . ' ریال / ' . number_format((float) $value / 10) . ' تومان';
	}

	private function source_compact_json($value) {
		$json = wp_json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
		return false !== $json ? $json : '-';
	}

	private function source_keywords($value) {
		if (!is_string($value) || '' === $value) { return '-'; }
		$decoded = json_decode($value, true);
		return is_array($decoded) ? $this->source_compact_json($decoded) : $value;
	}

	private function source_label($key) {
		$labels = array('id'=>'شناسه','title'=>'عنوان','description'=>'توضیحات','category'=>'دسته','price'=>'قیمت','special_price'=>'قیمت ویژه','reward'=>'امتیاز پاداش','stock'=>'وضعیت انبار','max_order_qty'=>'حداکثر سفارش','sku'=>'SKU','brand'=>'برند','quantity'=>'تعداد','thumbnail'=>'تصویر بندانگشتی','meta_title'=>'عنوان Meta','meta_description'=>'توضیح Meta','keywords'=>'کلمات کلیدی','total_reviews'=>'تعداد دیدگاه','average_rating'=>'میانگین امتیاز','spicalAttributes'=>'ویژگی‌های خاص');
		return isset($labels[$key]) ? $labels[$key] : str_replace('_', ' ', (string) $key);
	}

	private function source_attributes($product) {
		$result = array();
		$raw = isset($product['attributes'][0]) && is_array($product['attributes'][0]) ? $product['attributes'][0] : array();
		foreach ($raw as $attribute_id => $container) {
			$attribute = isset($container[0]) && is_array($container[0]) ? $container[0] : null;
			if (!$attribute) { continue; }
			$options = array();
			$option_rows = isset($attribute['options'][0]) && is_array($attribute['options'][0]) ? $attribute['options'][0] : array();
			foreach ($option_rows as $option_container) {
				if (isset($option_container[0]) && is_array($option_container[0])) { $options[] = $option_container[0]; }
			}
			$result[] = array('id'=>$attribute['id'] ?? $attribute_id,'name'=>$attribute['name'] ?? '','type'=>$attribute['type'] ?? '','variable'=>$attribute['variable'] ?? null,'required'=>$attribute['required'] ?? null,'options'=>$options);
		}
		return $result;
	}
}
