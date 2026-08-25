<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

require_once plugin_dir_path( __FILE__ ) . 'class-product-dto.php';

/**
 * Sazito_Product_Extractor
 * ============================================================
 * استخراج‌کننده‌ی عمومی محصول برای هر فروشگاهی که با پلتفرم
 * فروشگاه‌ساز «سازیتو» (Sazito) ساخته شده باشد (dorfaam.ir,
 * gadget-bazaar.ir, hugmugg.ir, sullyshop.ir, technobiil.ir,
 * shsweethome.ir, beroozdigi.ir و مشابه آن‌ها).
 *
 * معماری این کلاس دقیقاً هم‌خانواده‌ی Mixin_Product_Extractor است:
 * همان زنجیره‌ی fallback، همان امضای متدها، همان شکل خروجی —
 * فقط منبع داده و نحوه‌ی پارس کردنش با میکسین فرق دارد.
 *
 * سایت‌های سازیتو (بر خلاف میکسین که Next.js/RSC است) یک اپ
 * React کلاسیک با Redux SSR هستند؛ سرور state اولیه‌ی صفحه را در
 * یک تگ اسکریپت به این شکل جاسازی می‌کند:
 *
 *   window.__PRELOADED_STATE__ = JSON.parse('{...}');
 *
 * نکته‌ی فنی مهم: محتوای داخل JSON.parse('...') یک رشته‌ی جاوااسکریپتیِ
 * تک‌کوتیشن است که خودِ متن JSON (با کوتیشن‌های دوتایی معمولی، بدون
 * اسکیپ اضافه) داخلش قرار دارد؛ فقط "'" با "\'" اسکیپ شده. اما در
 * بعضی محصولات (خصوصاً وقتی توضیحات HTML شامل style="..." باشد)
 * یک باگ انکودینگ سمت سرور سازیتو باعث می‌شود کوتیشن داخل چنین
 * attributeهایی به‌جای \" به‌صورت ناقص \\" درج شود که رشته‌ی JSON را
 * زودتر از موعد می‌بندد. متد repair_and_parse() این الگو را قبل از
 * json_decode با یک عبارت باقاعده‌ی هدفمند تعمیر می‌کند.
 *
 * داخل این state، مسیر قابل‌اعتماد و همیشگی برای داده‌ی محصولِ
 * صفحه‌ی جاری این است:
 *
 *   entity_route.entity_name === "product"
 *   entity_route.other_props  ==>  شیء کامل محصول
 *
 * این شیء شامل: id، name، product_type، url، product_attributes
 * (فیلدهای SEO مثل description/metaTitle/... به‌علاوه‌ی attributeهای
 * «differentiator» که برای واریانت استفاده می‌شوند)، product_variants
 * (هر واریانت با sku/price/raw_price/stock_number/is_stock_managed/
 * image_id خودش)، product_categories (لیست تخت چندعضویتی، نه یک
 * breadcrumb سلسله‌مراتبی)، images (با id، order، و url مطلق).
 *
 * استراتژی استخراج (به ترتیب اولویت):
 *   ۱) window.__PRELOADED_STATE__ → entity_route.other_props (اصلی)
 *   ۲) Schema.org JSON-LD (Product + BreadcrumbList)
 *   ۳) متاتگ‌ها (og:*, description) + اسکرپ DOM عمومی
 *      نکته: بر خلاف میکسین، قالب‌های سازیتو (themeA، themeDigi،
 *      themeE، themeF، ...) کلاس‌های CSS متفاوتی دارند، پس این لایه‌ی
 *      fallback عمداً به کلاس‌های خاص یک قالب متکی نیست.
 *
 * تشخیص سایت سازیتو: <meta name="generator" content="Sazito">
 * که در تمام نمونه‌های بررسی‌شده (۷ دامنه، هم در HTML خام سرور و
 * هم در snapshot بعد از اجرای جاوااسکریپت) بدون استثنا وجود داشت.
 *
 * سازگاری: امضای extract()/get_product_urls() و شکل کلیدهای آرایه‌ی
 * خروجی دقیقاً با Mixin_Product_Extractor یکسان است تا هر دو
 * اکسترکتور بتوانند بدون تغییر در بقیه‌ی سیستم (از جمله
 * ProductDTO::normalize) استفاده شوند.
 */
class Sazito_Product_Extractor {

    const MENU_SLUG = 'sazito-extractor';


    public function __construct() {
        add_action( 'admin_menu', array( $this, 'add_admin_menu' ) );
    }

    public function add_admin_menu() {
        add_submenu_page(
            'edit.php?post_type=source_profile',
            'Sazito Extractor',
            'Sazito Extractor',
            'manage_options',
            self::MENU_SLUG,
            array( $this, 'render_admin_page' )
        );
    }

    public function render_admin_page() {
        ?>
        <div class="wrap">
            <h1>Sazito Platform Product Extractor</h1>
            <p>آدرس صفحه‌ی محصول را از هر فروشگاهی که با پلتفرم <strong>سازیتو</strong> ساخته شده وارد کنید.</p>
            <form method="post" action="">
                <?php wp_nonce_field( 'sazito_extractor_action', 'sazito_extractor_nonce' ); ?>
                <table class="form-table">
                    <tr>
                        <th scope="row"><label for="product_url">Product Page URL</label></th>
                        <td>
                            <input type="url" id="product_url" name="product_url"
                                   value="<?php echo isset($_POST['product_url']) ? esc_url($_POST['product_url']) : ''; ?>"
                                   placeholder="https://example.ir/product/..." size="60" required />
                        </td>
                    </tr>
                </table>
                <?php submit_button( 'Extract Product Data' ); ?>
            </form>
            <?php
            if ( isset($_POST['product_url']) && check_admin_referer('sazito_extractor_action','sazito_extractor_nonce') ) {
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
        return ProductDTO::normalize( $data );
    }

    /**
     * تشخیص می‌دهد که آیا HTML داده‌شده متعلق به سایتی است که با
     * پلتفرم سازیتو ساخته شده یا نه.
     */
    public static function is_sazito_site( $html ) {
        if ( empty( $html ) ) return false;
        if ( preg_match( '/<meta\s+name="generator"\s+content="Sazito"/i', $html ) ) {
            return true;
        }
        if ( strpos( $html, '__PRELOADED_STATE__' ) !== false ) {
            return true;
        }
        return false;
    }

    /**
     * دریافت لیست URLهای محصولات از سایتمپ یک سایت سازیتو (هر دامنه‌ای).
     * همان منطق Mixin_Product_Extractor::get_product_urls؛ چون هر دو
     * پلتفرم از یک سایتمپ استاندارد sitemap.xml / sitemap index استفاده
     * می‌کنند و آدرس محصولات هر دو زیر مسیر «/product/» است.
     */
    public static function get_product_urls( $profile ) {
        $base_url = self::resolve_base_url( $profile );
        if ( empty( $base_url ) ) {
            return new WP_Error( 'missing_base_url', 'برای این پروفایل نه sitemap_url و نه base_url/domain مشخص شده است.' );
        }

        $sitemap_url = ! empty( $profile['sitemap_url'] ) ? $profile['sitemap_url'] : ( $base_url . '/sitemap.xml' );

        $main_xml = self::fetch_xml_body( $sitemap_url );
        if ( is_wp_error( $main_xml ) ) {
            return $main_xml;
        }

        libxml_use_internal_errors( true );
        $main_dom = new DOMDocument();
        $main_dom->loadXML( $main_xml );
        $main_xpath = new DOMXPath( $main_dom );
        $main_xpath->registerNamespace( 'sm', 'http://www.sitemaps.org/schemas/sitemap/0.9' );

        $url_nodes = $main_xpath->query( '//sm:url/sm:loc' );
        $all_product_urls = array();
        if ( $url_nodes->length > 0 ) {
            foreach ( $url_nodes as $node ) {
                $u = trim( $node->textContent );
                if ( strpos( $u, '/product/' ) !== false ) {
                    $all_product_urls[] = $u;
                }
            }
        }

        if ( empty( $all_product_urls ) ) {
            $sub_sitemap_nodes = $main_xpath->query( '//sm:sitemap/sm:loc' );
            $sub_urls = array();
            foreach ( $sub_sitemap_nodes as $node ) {
                $loc = trim( $node->textContent );
                if ( strpos( $loc, 'product' ) !== false || strpos( $loc, 'sitemap' ) !== false ) {
                    $sub_urls[] = $loc;
                }
            }
            foreach ( $sub_urls as $sub_url ) {
                $sub_xml = self::fetch_xml_body( $sub_url );
                if ( is_wp_error( $sub_xml ) ) continue;
                libxml_use_internal_errors( true );
                $sub_dom = new DOMDocument();
                $sub_dom->loadXML( $sub_xml );
                $sub_xpath = new DOMXPath( $sub_dom );
                $sub_xpath->registerNamespace( 'sm', 'http://www.sitemaps.org/schemas/sitemap/0.9' );
                $sub_url_nodes = $sub_xpath->query( '//sm:url/sm:loc' );
                foreach ( $sub_url_nodes as $url_node ) {
                    $product_url = trim( $url_node->textContent );
                    if ( ! empty( $product_url ) && strpos( $product_url, '/product/' ) !== false ) {
                        $all_product_urls[] = $product_url;
                    }
                }
            }
        }

        return array_values( array_unique( $all_product_urls ) );
    }

    private static function resolve_base_url( $profile ) {
        if ( ! empty( $profile['base_url'] ) ) {
            return rtrim( $profile['base_url'], '/' );
        }
        if ( ! empty( $profile['domain'] ) ) {
            $domain = preg_replace( '#^https?://#i', '', $profile['domain'] );
            return 'https://' . rtrim( $domain, '/' );
        }
        if ( ! empty( $profile['sitemap_url'] ) ) {
            $parts = parse_url( $profile['sitemap_url'] );
            if ( isset( $parts['scheme'], $parts['host'] ) ) {
                return $parts['scheme'] . '://' . $parts['host'];
            }
        }
        return '';
    }

    private static function fetch_xml_body( $url ) {
        $response = wp_safe_remote_get( $url, array(
            'timeout'    => 30,
            'user-agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36',
            'sslverify'  => true,
            'limit_response_size' => 6291456,
        ) );
        if ( is_wp_error( $response ) || wp_remote_retrieve_response_code( $response ) !== 200 ) {
            return new WP_Error( 'sitemap_error', 'خطا در دریافت سایتمپ: ' . $url );
        }
        $body = wp_remote_retrieve_body( $response );
        if ( substr( $body, 0, 2 ) === "\x1f\x8b" && function_exists( 'gzdecode' ) ) {
            $decoded = gzdecode( $body );
            if ( $decoded !== false ) $body = $decoded;
        }
        return $body;
    }

    // ============================================================
    //  بخش اصلی استخراج محصول
    // ============================================================

    public function extract_product_data( $url ) {
        $response = wp_safe_remote_get( $url, array(
            'timeout'     => 30,
            'redirection' => 5,
            'user-agent'  => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36',
            'sslverify'   => true,
            'limit_response_size' => 6291456,
        ) );
        if ( is_wp_error($response) || wp_remote_retrieve_response_code($response) !== 200 ) {
            return array( 'error' => 'Failed to fetch page.' );
        }
        $html = wp_remote_retrieve_body( $response );
        if ( empty($html) ) return array( 'error' => 'Empty body.' );

        // ---- روش ۱ (اصلی): window.__PRELOADED_STATE__ ----
        $product = $this->extract_preloaded_product( $html );
        if ( null !== $product ) {
            return $this->build_from_state( $product, $url );
        }

        // ---- روش ۲/۳ (fallback): JSON-LD + متاتگ‌ها + DOM ----
        return $this->extract_via_fallback( $html, $url );
    }

    // ============================================================
    //  روش ۱: استخراج از window.__PRELOADED_STATE__
    // ============================================================

    const STATE_MARKER = "window.__PRELOADED_STATE__ = JSON.parse('";

    /**
     * از نقطه‌ی شروع رشته‌ی جاوااسکریپتیِ تک‌کوتیشن، تا اولین "'"
     * اسکیپ‌نشده جلو می‌رود و محتوای خام (هنوز JSON) را برمی‌گرداند.
     * ایمنی UTF-8: پیمایش بایتی است، دقیقاً به همان دلیلی که در
     * Mixin_Product_Extractor::extract_balanced_json توضیح داده شده.
     */
    private function extract_js_string_body( $html, $start_pos ) {
        $len = strlen( $html );
        $i   = $start_pos;
        while ( $i < $len ) {
            $c = $html[ $i ];
            if ( '\\' === $c ) {
                $i += 2;
                continue;
            }
            if ( "'" === $c ) {
                break;
            }
            $i++;
        }
        return substr( $html, $start_pos, $i - $start_pos );
    }

    /**
     * همان الگوریتم extract_balanced_json در Mixin_Product_Extractor؛
     * برای پیدا کردن بلوک JSON متوازن از یک نقطه‌ی شروع مشخص.
     */
    private function extract_balanced_json( $payload, $start_idx ) {
        $start = strpos( $payload, '{', $start_idx );
        if ( false === $start ) return null;
        $len   = strlen( $payload );
        $depth = 0;
        $i     = $start;
        while ( $i < $len ) {
            $c = $payload[ $i ];
            if ( '{' === $c ) {
                $depth++;
            } elseif ( '}' === $c ) {
                $depth--;
                if ( 0 === $depth ) {
                    return substr( $payload, $start, $i - $start + 1 );
                }
            } elseif ( '"' === $c ) {
                $i++;
                while ( $i < $len && $payload[ $i ] !== '"' ) {
                    if ( '\\' === $payload[ $i ] ) $i++;
                    $i++;
                }
            }
            $i++;
        }
        return null;
    }

    /**
     * تبدیل رشته‌ی خام استخراج‌شده به JSON معتبر و دیکود آن:
     *   ۱) "\'" را به "'" برمی‌گرداند (تنها اسکیپ واقعی رشته‌ی JS).
     *   ۲) الگوی باگ‌دار "\\"" را که به‌جای "\"" درج شده (و رشته‌ی
     *      JSON را زودتر از موعد می‌بندد) با یک regex هدفمند تعمیر
     *      می‌کند: هر "\\"" که بلافاصله با یک کاراکتر ساختاری JSON
     *      (,  :  }  ]) ادامه پیدا نکند، یعنی واقعاً باید بسته نمی‌شد.
     *      این باگ فقط داخل فیلدهای HTML غنی (مثل توضیحات محصول با
     *      style="...") دیده شده، نه در همه‌جای state.
     */
    private function repair_and_decode( $fragment ) {
        $text = str_replace( "\\'", "'", $fragment );
        $text = preg_replace( '/\\\\\\\\"(?!\s*[,:}\]])/', '\\\\"', $text );
        $decoded = json_decode( $text, true );
        if ( JSON_ERROR_NONE !== json_last_error() ) {
            return null;
        }
        return $decoded;
    }

    /**
     * پیدا کردن و پارس کردن entity_route.other_props (شیء کامل محصول)
     * از window.__PRELOADED_STATE__. در صورت موفقیت شیء محصول،
     * در غیر این‌صورت null برمی‌گرداند.
     */
    private function extract_preloaded_product( $html ) {
        $midx = strpos( $html, self::STATE_MARKER );
        if ( false === $midx ) {
            return null;
        }
        $start = $midx + strlen( self::STATE_MARKER );
        $body  = $this->extract_js_string_body( $html, $start );

        $er_idx = strpos( $body, '"entity_route"' );
        if ( false === $er_idx ) {
            return null;
        }
        $er_json = $this->extract_balanced_json( $body, $er_idx );
        if ( null === $er_json ) {
            return null;
        }
        $entity_route = $this->repair_and_decode( $er_json );
        if ( ! is_array( $entity_route ) ) {
            return null;
        }
        if ( ( $entity_route['entity_name'] ?? '' ) !== 'product' ) {
            return null; // این صفحه یک صفحه‌ی محصول نیست (شاید دسته‌بندی یا صفحه‌ی اصلی است).
        }
        return $entity_route['other_props'] ?? null;
    }

    /** فیلدهای SEO/متا که در product_attributes ثابت هستند (بدون attribute_type). */
    const SEO_ATTR_NAMES = array( 'description', 'metaTitle', 'metaDescription', 'metaKeywords', 'redirect', 'canonical', 'noindex' );

    /**
     * ساخت آرایه‌ی نهایی محصول از شیء other_props پارس‌شده.
     * کلیدهای خروجی دقیقاً هم‌شکل با Mixin_Product_Extractor::build_from_ssr.
     */
    private function build_from_state( $p, $url ) {
        $base_url = $this->get_base_url( $url );

        // ---- جدا کردن فیلدهای SEO از attributeهای differentiator (واریانت‌ساز) ----
        $seo            = array();
        $differentiators = array(); // نام attributeهایی که برای واریانت استفاده می‌شوند، به ترتیب تعریف
        foreach ( ( $p['product_attributes'] ?? array() ) as $attr ) {
            $name = $attr['name'] ?? '';
            if ( 'differentiator' === ( $attr['attribute_type'] ?? '' ) ) {
                $differentiators[] = $name;
            } elseif ( in_array( $name, self::SEO_ATTR_NAMES, true ) ) {
                $seo[ $name ] = $attr['value'] ?? '';
            }
        }
        $content = $seo['description'] ?? '';
        $excerpt = $p['summary'] ?? '';

        // ---- تصاویر: نگاشت id=>url، مرتب بر اساس 'order' صعودی (order=1 یعنی اول) ----
        $images_raw = $p['images'] ?? array();
        usort( $images_raw, function ( $a, $b ) {
            return ( $a['order'] ?? 0 ) <=> ( $b['order'] ?? 0 );
        } );
        $image_by_id = array();
        foreach ( $images_raw as $im ) {
            if ( isset( $im['id'] ) ) {
                $image_by_id[ $im['id'] ] = $im['url'] ?? '';
            }
        }
        $featured = ! empty( $images_raw ) ? ( $images_raw[0]['url'] ?? '' ) : '';
        $gallery  = array();
        foreach ( array_slice( $images_raw, 1 ) as $im ) {
            $gallery[] = $im['url'] ?? '';
        }

        // ---- دسته‌بندی‌ها: لیست تخت چندعضویتی (نه یک breadcrumb سلسله‌مراتبی) ----
        $categories = array();
        foreach ( ( $p['product_categories'] ?? array() ) as $cat ) {
            if ( ! empty( $cat['name'] ) ) {
                $categories[] = $cat['name'];
            }
        }

        // ---- واریانت‌ها ----
        $variants_raw = $p['product_variants'] ?? array();
        $has_variants = count( $variants_raw ) > 1 || count( $differentiators ) > 0;

        $build_variant = function ( $v ) use ( $image_by_id, $featured ) {
            $v_price     = $v['price'] ?? 0;
            $v_raw_price = $v['raw_price'] ?? null;
            if ( ! empty( $v_raw_price ) && $v_raw_price > $v_price ) {
                $v_regular = $v_raw_price;
                $v_sale    = $v_price;
            } else {
                $v_regular = $v_price;
                $v_sale    = null;
            }

            if ( ! empty( $v['is_stock_managed'] ) ) {
                $v_stock_status = ( ( $v['stock_number'] ?? 0 ) > 0 ) ? 'in-stock' : 'out-of-stock';
                $v_stock_qty    = $v['stock_number'] ?? 0;
            } else {
                $v_stock_status = 'in-stock';
                $v_stock_qty    = null;
            }
            if ( isset( $v['enabled'] ) && false === $v['enabled'] ) {
                $v_stock_status = 'out-of-stock';
            }

            $attr_map = array();
            foreach ( ( $v['product_attributes'] ?? array() ) as $a ) {
                $raw_val = $a['value'] ?? '';
                // attributeهای رنگی به‌صورت آبجکت {extra, fieldType, value} می‌آیند؛
                // بقیه رشته‌ی ساده هستند.
                $val = is_array( $raw_val ) ? ( $raw_val['value'] ?? '' ) : $raw_val;
                $attr_map[ $a['name'] ] = $val;
            }

            $image_url = $image_by_id[ $v['image_id'] ?? null ] ?? '';
            if ( '' === $image_url ) {
                $image_url = $featured;
            }

            return array(
                'attributes_summary' => implode( '، ', array_values( $attr_map ) ),
                'attributes_map'     => $attr_map,
                'sku'                => $v['sku'] ?? '',
                'regular_price'      => $v_regular,
                'sale_price'         => $v_sale,
                'stock_status'       => $v_stock_status,
                'stock_quantity'     => $v_stock_qty,
                'image'              => $image_url,
            );
        };

        $variations = array();
        if ( $has_variants ) {
            foreach ( $variants_raw as $v ) {
                $variations[] = $build_variant( $v );
            }
        }

        // ---- attributes: مقادیر ممکن هر differentiator را از خودِ واریانت‌ها جمع می‌کنیم
        //      (بر خلاف میکسین، سازیتو یک لیست مقادیرِ از پیش‌تعریف‌شده در سطح محصول نمی‌دهد) ----
        $attributes = array();
        foreach ( $differentiators as $name ) {
            $values = array();
            foreach ( $variants_raw as $v ) {
                foreach ( ( $v['product_attributes'] ?? array() ) as $a ) {
                    if ( ( $a['name'] ?? '' ) === $name ) {
                        $raw_val = $a['value'] ?? '';
                        $val     = is_array( $raw_val ) ? ( $raw_val['value'] ?? '' ) : $raw_val;
                        if ( '' !== $val && ! in_array( $val, $values, true ) ) {
                            $values[] = $val;
                        }
                    }
                }
            }
            $attributes[] = array( 'name' => $name, 'values' => $values, 'used_for_variations' => true );
        }

        // ---- قیمت/موجودی/SKU سطح محصول از روی اولین (پیش‌فرض) واریانت ----
        $default_variant = ! empty( $variants_raw ) ? $variants_raw[0] : null;
        $dv              = $default_variant ? $build_variant( $default_variant ) : null;

        // ---- تگ‌ها: ساختار دقیق در نمونه‌های بررسی‌شده همیشه خالی بود؛
        // هر دو شکل احتمالی پشتیبانی می‌شود ----
        $tags = array();
        foreach ( ( $p['tags'] ?? array() ) as $tag ) {
            $tags[] = is_array( $tag ) ? ( $tag['name'] ?? '' ) : $tag;
        }
        $tags = array_values( array_filter( $tags ) );

        $canonical = $this->quick_extract_meta_og_url( $url );
        if ( '' === $canonical && $base_url && ! empty( $p['url'] ) ) {
            $canonical = $base_url . $p['url'];
        }

        return array(
            'product_id'     => (string) ( $p['id'] ?? '' ),
            'sku'            => $default_variant['sku'] ?? '',
            'title'          => $p['name'] ?? '',
            'excerpt'        => $excerpt,
            'content'        => $content,
            'featured_image' => $featured,
            'gallery_images' => $gallery,
            'regular_price'  => $dv['regular_price'] ?? 0,
            'sale_price'     => $dv['sale_price'] ?? null,
            'currency'       => 'تومان',
            'stock_status'   => $dv['stock_status'] ?? 'out-of-stock',
            'stock_quantity' => $dv['stock_quantity'] ?? null,
            'categories'     => $categories,
            'tags'           => $tags,
            'product_type'   => $has_variants ? 'variable' : 'simple',
            'attributes'     => $attributes,
            'variations'     => $variations,
            // سازیتو، بر خلاف میکسین، فیلد ساخت‌یافته‌ی مجزا برای گارانتی ندارد؛
            // اگر لازم شد باید از متن توضیحات استخراج شود (فعلاً خالی می‌ماند).
            'guarantee'      => '',
            'meta_title'     => $seo['metaTitle'] ?? ( $p['name'] ?? '' ),
            'canonical'      => $canonical,
            'brand'          => '',
            'is_digital'     => false,
            'discount_percent'   => null,
            'min_order_quantity' => $default_variant['min_order_count'] ?? null,
            'processing_time'    => null,
            'source_platform'    => 'sazito',
        );
    }

    /** این متد فقط placeholder برای نگه‌داشتن امضای یکسان با نسخه‌ی fallback DOM است؛ کار اصلی‌اش در extract_via_fallback انجام می‌شود. */
    private function quick_extract_meta_og_url( $url ) {
        return '';
    }

    private function get_base_url( $url ) {
        $parts = parse_url( $url );
        if ( isset( $parts['scheme'], $parts['host'] ) ) {
            return $parts['scheme'] . '://' . $parts['host'];
        }
        return '';
    }

    // ============================================================
    //  روش fallback: JSON-LD + متاتگ‌ها + اسکرپ DOM عمومی
    //  (وقتی window.__PRELOADED_STATE__ پیدا/پارس نشد)
    //
    //  نکته: قالب‌های سازیتو (themeA/themeDigi/themeE/themeF/...)
    //  کلاس‌های CSS متفاوتی دارند، پس این لایه عمداً به کلاس خاصِ
    //  یک قالب متکی نیست و فقط از سیگنال‌های عمومی/استاندارد
    //  (JSON-LD، متاتگ‌های og:*، تگ h1، تصاویر مسیر apiuploads)
    //  استفاده می‌کند.
    // ============================================================

    private function extract_via_fallback( $html, $url ) {
        $jsonld = $this->parse_json_ld_product( $html );

        libxml_use_internal_errors( true );
        $dom = new DOMDocument();
        $dom->loadHTML( '<?xml encoding="UTF-8">' . $html );
        libxml_clear_errors();
        $xpath = new DOMXPath( $dom );

        $og_title       = $this->get_meta_property( $xpath, 'og:title' );
        $og_description = $this->get_meta_property( $xpath, 'og:description' );
        $og_image       = $this->get_meta_property( $xpath, 'og:image' );
        $og_url         = $this->get_meta_property( $xpath, 'og:url' );
        $meta_desc      = $this->get_meta_content( $xpath, 'description' );

        $title = $og_title ?: ( $jsonld['name'] ?? '' );
        if ( '' === $title ) {
            $nodes = $xpath->query( '//h1' );
            if ( $nodes->length ) {
                $title = trim( $nodes->item(0)->nodeValue );
            }
        }

        $sku = $jsonld['sku'] ?? '';

        $regular_price = 0;
        $sale_price    = null;
        if ( isset( $jsonld['offers']['price'] ) ) {
            // در سازیتو، بر خلاف میکسین، واحد قیمت JSON-LD همان تومان (واحد نمایشی سایت) است.
            $regular_price = intval( $jsonld['offers']['price'] );
        }

        $images = array();
        if ( ! empty( $jsonld['image'] ) ) {
            $images = is_array( $jsonld['image'] ) ? $jsonld['image'] : array( $jsonld['image'] );
        } elseif ( $og_image ) {
            $images = array( $og_image );
        }
        $featured = $images[0] ?? '';
        $gallery  = array_slice( $images, 1 );

        $categories = array();
        foreach ( $xpath->query( "//script[@type='application/ld+json']" ) as $script ) {
            $data = json_decode( $script->nodeValue, true );
            if ( isset( $data['@type'] ) && 'BreadcrumbList' === $data['@type'] ) {
                foreach ( ( $data['itemListElement'] ?? array() ) as $item ) {
                    if ( isset( $item['position'] ) && $item['position'] > 1 && $item['position'] < count( $data['itemListElement'] ) ) {
                        $categories[] = $item['item']['name'] ?? '';
                    }
                }
                break;
            }
        }
        $categories = array_values( array_filter( $categories ) );

        $availability = $jsonld['offers']['availability'] ?? '';
        $stock_status = ( false !== stripos( $availability, 'outofstock' ) ) ? 'out-of-stock' : 'in-stock';

        return array(
            'product_id'     => '',
            'sku'            => $sku,
            'title'          => $this->clean_text( $title ),
            'excerpt'        => $meta_desc ?: $og_description,
            'content'        => '',
            'featured_image' => $featured,
            'gallery_images' => $gallery,
            'regular_price'  => $regular_price,
            'sale_price'     => $sale_price,
            'currency'       => 'تومان',
            'stock_status'   => $stock_status,
            'stock_quantity' => null,
            'categories'     => $categories,
            'tags'           => array(),
            'product_type'   => 'simple', // در مسیر fallback نمی‌توانیم واریانت‌ها را با اطمینان تشخیص دهیم
            'attributes'     => array(),
            'variations'     => array(),
            'guarantee'      => '',
            'meta_title'     => $og_title,
            'canonical'      => $og_url ?: $this->get_link_href( $xpath, 'canonical' ),
            'brand'          => '',
            'is_digital'     => false,
            'discount_percent'   => null,
            'min_order_quantity' => null,
            'processing_time'    => null,
            'source_platform'    => 'sazito',
        );
    }

    private function get_meta_content( $xpath, $name ) {
        $nodes = $xpath->query( "//meta[@name='$name']/@content" );
        return $nodes->length ? $nodes->item(0)->nodeValue : '';
    }

    private function get_meta_property( $xpath, $property ) {
        $nodes = $xpath->query( "//meta[@property='$property']/@content" );
        return $nodes->length ? $nodes->item(0)->nodeValue : '';
    }

    private function get_link_href( $xpath, $rel ) {
        $nodes = $xpath->query( "//link[@rel='$rel']/@href" );
        return $nodes->length ? $nodes->item(0)->nodeValue : '';
    }

    private function parse_json_ld_product( $html ) {
        if ( ! preg_match_all( '/<script[^>]+type="application\/ld\+json"[^>]*>(.*?)<\/script>/is', $html, $m ) ) {
            return array();
        }
        foreach ( $m[1] as $json ) {
            $data = json_decode( $json, true );
            if ( ! $data ) continue;
            if ( isset( $data['@type'] ) && 'Product' === $data['@type'] ) {
                return $data;
            }
        }
        return array();
    }

    private function clean_text( $text ) {
        $text = wp_strip_all_tags( (string) $text );
        $text = html_entity_decode( $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
        return trim( preg_replace( '/\s+/u', ' ', $text ) );
    }

    // ============================================================
    //  نمایش داده‌ها (صفحه‌ی ادمین) — عیناً هم‌شکل با Mixin_Product_Extractor
    // ============================================================

    private function extract_and_display( $url ) {
        $data = $this->extract_product_data( $url );
        if ( isset($data['error']) ) {
            echo '<div class="notice notice-error"><p>' . esc_html($data['error']) . '</p></div>';
            return;
        }
        $normalized = ProductDTO::normalize( $data );
        $this->display_product_data( $normalized, $data );
    }

    private function display_product_data( $data, $raw = array() ) {
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
            <h2>Extracted Product Data (Sazito)</h2>

            <div class="product-ids">
                <span><strong>Product ID:</strong> <?php echo esc_html($data['product_id']); ?></span><br>
                <span><strong>SKU:</strong> <?php echo esc_html($data['sku']); ?></span><br>
                <?php if ( ! empty($raw['source_platform']) ): ?>
                <span><strong>پلتفرم منبع:</strong> <?php echo esc_html($raw['source_platform']); ?></span>
                <?php endif; ?>
            </div>

            <hr>

            <div class="product-content">
                <h1>عنوان محصول: <?php echo esc_html($data['title']); ?></h1>
                <p><strong>توضیحات کوتاه:</strong> <?php echo wp_kses_post($data['excerpt']); ?></p>
                <div><strong>توضیحات اصلی:</strong> <?php echo wp_kses_post($data['content']); ?></div>
            </div>

            <hr>

            <div class="product-images">
                <div><strong>تصویر اصلی:</strong></div>
                <?php if (!empty($data['featured_image'])): ?>
                    <img src="<?php echo esc_url($data['featured_image']); ?>" alt="Featured Image">
                <?php else: ?>
                    <p>No featured image.</p>
                <?php endif; ?>

                <p><strong>گالری تصاویر:</strong></p>
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
                <p><strong>قیمت اصلی:</strong> <?php echo esc_html(number_format($data['regular_price'], 0, '.', ',')); ?></p>
                <p><strong>قیمت فروش ویژه:</strong> <?php echo $data['sale_price'] ? esc_html(number_format($data['sale_price'], 0, '.', ',')) : '-'; ?></p>
                <p><strong>موجودی:</strong> <?php echo esc_html($data['stock_status']); ?></p>
                <p><strong>تعداد موجودی:</strong> <?php echo null !== $data['stock_quantity'] ? esc_html($data['stock_quantity']) : '-'; ?></p>
            </div>

            <hr>

            <div class="product-taxonomies">
                <p><strong>دسته‌بندی‌ها (عضویت چندگانه):</strong></p>
                <?php if (!empty($data['categories'])): ?>
                    <ul>
                        <?php foreach ($data['categories'] as $cat): ?>
                            <li><?php echo esc_html($cat); ?></li>
                        <?php endforeach; ?>
                    </ul>
                <?php else: ?>
                    <p>No categories.</p>
                <?php endif; ?>
            </div>

            <hr>

            <div class="product-type">
                <p><strong>نوع محصول:</strong> <?php echo esc_html($data['product_type']); ?></p>
                <?php if ( ! empty($data['meta_title']) ): ?>
                    <p><strong>Meta Title:</strong> <?php echo esc_html($data['meta_title']); ?></p>
                <?php endif; ?>
                <?php if ( ! empty($data['canonical']) ): ?>
                    <p><strong>Canonical URL:</strong> <?php echo esc_url($data['canonical']); ?></p>
                <?php endif; ?>
            </div>

            <hr>

            <div class="product-attributes">
                <h2>ویژگی‌ها (Attributes)</h2>
                <?php if (!empty($data['attributes'])): ?>
                    <table>
                        <thead><tr><th>نام</th><th>مقدار(ها)</th><th>برای واریانت؟</th></tr></thead>
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
                <h2>واریانت‌ها (Variations)</h2>
                <?php if ($data['product_type'] === 'variable' && !empty($data['variations'])): ?>
                    <table>
                        <thead><tr><th>ترکیب</th><th>SKU</th><th>قیمت اصلی</th><th>قیمت ویژه</th><th>موجودی</th><th>تعداد</th><th>تصویر</th></tr></thead>
                        <tbody>
                            <?php foreach ($data['variations'] as $var): ?>
                                <tr>
                                    <td><?php echo esc_html($var['attributes_summary']); ?></td>
                                    <td><?php echo esc_html($var['sku']); ?></td>
                                    <td><?php echo esc_html(number_format($var['regular_price'], 0, '.', ',')); ?></td>
                                    <td><?php echo $var['sale_price'] ? esc_html(number_format($var['sale_price'], 0, '.', ',')) : '-'; ?></td>
                                    <td><?php echo esc_html($var['stock_status']); ?></td>
                                    <td><?php echo null !== $var['stock_quantity'] ? esc_html($var['stock_quantity']) : '-'; ?></td>
                                    <td><?php echo !empty($var['image']) ? '<img src="'.esc_url($var['image']).'" style="width:40px;height:40px;object-fit:cover;">' : '-'; ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php else: ?>
                    <p><small>* برای محصولات ساده (simple) این بخش خالی می‌ماند.</small></p>
                <?php endif; ?>
            </div>
        </div>
        <?php
    }
}
