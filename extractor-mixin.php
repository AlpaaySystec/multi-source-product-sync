<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

require_once plugin_dir_path( __FILE__ ) . 'class-product-dto.php';

/**
 * Mixin_Product_Extractor
 * ============================================================
 * استخراج‌کننده‌ی عمومی محصول برای هر فروشگاهی که با پلتفرم
 * فروشگاه‌ساز «میکسین» (Mixin) ساخته شده باشد؛ نه فقط arvindshop.ir.
 *
 * همه‌ی سایت‌های میکسین با Next.js (App Router) ساخته می‌شوند و در
 * HTML خروجی‌شان، React یک payload به اسم «RSC Flight Stream» را در
 * تگ‌های <script>self.__next_f.push([...])</script> جاسازی می‌کند.
 * داخل همین payload یک شیء JSON کامل به اسم "ssrProductInfo" +
 * "ssrProductId" وجود دارد که دقیقاً همان داده‌ی خامی است که خودِ
 * کامپوننت React برای رندر صفحه از سرور گرفته — یعنی منبعی به‌مراتب
 * کامل‌تر و قابل‌اعتمادتر از اسکرپ کردن DOM رندر شده.
 *
 * این کلاس با اولویت زیر عمل می‌کند:
 *
 *   ۱) ssrProductInfo  (اصلی و ترجیحی)
 *      شامل: نام، sku، توضیح کوتاه/کامل، قیمت و قیمت مقایسه‌ای،
 *      درصد تخفیف، موجودی، برند، دسته‌بندی/breadcrumb، گالری تصاویر،
 *      و — چیزی که نسخه‌ی قبلی اصلاً پیاده نمی‌کرد — attributes و
 *      variants کامل برای محصولات متغیر.
 *
 *   ۲) Schema.org JSON-LD (Product + BreadcrumbList)
 *      اگر به هر دلیلی payload پیدا/پارس نشد.
 *      نکته: قیمت در JSON-LD با واحد ریال (IRR) است، نه تومان؛
 *      یعنی دقیقاً ۱۰ برابر قیمت نمایشی سایت. اگر این مسیر fallback
 *      برای قیمت استفاده شود باید بر ۱۰ تقسیم گردد.
 *
 *   ۳) متاتگ‌های اختصاصی میکسین + اسکرپ DOM (روش نسخه‌ی قبلی)
 *      آخرین خط دفاعی، برای زمانی که ساختار صفحه به‌کلی متفاوت باشد.
 *
 * تشخیص سایت میکسین: متد استاتیک is_mixin_site() با استفاده از تگ
 * <meta name="mixin_hash_id" content="..."> (که در هر ۱۲ سایت نمونه‌ی
 * بررسی‌شده بدون استثنا وجود داشت) یا وجود ssrProductInfo در payload.
 *
 * سازگاری با نسخه‌ی قبلی: امضای extract()، get_product_urls() و
 * extract_product_data() و شکل آرایه‌ی خروجی (همان کلیدهایی که
 * ProductDTO::normalize() انتظار دارد) کاملاً حفظ شده؛ فقط چند کلید
 * اضافه (brand، discount_percent، ...) به انتهای آرایه اضافه شده که
 * اگر ProductDTO آن‌ها را نشناسد، بی‌ضرر نادیده گرفته می‌شوند.
 * کلاس Arvindshop_Product_Extractor هم در انتهای فایل به‌عنوان alias
 * نگه داشته شده تا کدهای موجودی که این نام را صدا می‌زنند نشکنند.
 */
class Mixin_Product_Extractor {

    const MENU_SLUG = 'mixin-extractor';

    public function __construct() {
        add_action( 'admin_menu', array( $this, 'add_admin_menu' ) );
    }

    public function add_admin_menu() {
        add_submenu_page(
            'edit.php?post_type=source_profile',
            'Mixin Extractor',
            'Mixin Extractor',
            'manage_options',
            self::MENU_SLUG,
            array( $this, 'render_admin_page' )
        );
    }

    public function render_admin_page() {
        ?>
        <div class="wrap">
            <h1>Mixin Platform Product Extractor</h1>
            <p>آدرس صفحه‌ی محصول را از هر فروشگاهی که با پلتفرم <strong>میکسین</strong> ساخته شده وارد کنید (arvindshop.ir و مشابه آن).</p>
            <form method="post" action="">
                <?php wp_nonce_field( 'mixin_extractor_action', 'mixin_extractor_nonce' ); ?>
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
            if ( isset($_POST['product_url']) && check_admin_referer('mixin_extractor_action','mixin_extractor_nonce') ) {
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
     * پلتفرم میکسین ساخته شده یا نه. برای فیلتر کردن پروفایل‌ها یا
     * صحت‌سنجی قبل از پردازش کامل مفید است.
     */
    public static function is_mixin_site( $html ) {
        if ( empty( $html ) ) return false;
        // فینگرپرینت قطعی پلتفرم میکسین: در هر ۱۴ سایت نمونه‌ی بررسی‌شده
        // بدون استثنا وجود داشت.
        if ( preg_match( '/<meta[^>]+mixin_hash_id[^>]*>/i', $html ) ) {
            return true;
        }
        // فینگرپرینت دوم (پشتیبان): ساختار داده‌ی RSC که این کلاس برایش نوشته شده.
        if ( strpos( $html, '__next_f' ) !== false && strpos( $html, 'ssrProductInfo' ) !== false ) {
            return true;
        }
        return false;
    }

    /**
     * دریافت لیست URLهای محصولات از سایتمپ یک سایت میکسین (هر دامنه‌ای).
     *
     * $profile می‌تواند شامل هرکدام از این کلیدها باشد:
     *   - 'sitemap_url'  آدرس مستقیم سایتمپ (اگر ست شود، اولویت دارد)
     *   - 'base_url'     آدرس پایه‌ی سایت، مثل https://example.ir
     *   - 'domain'       فقط دامنه، مثل example.ir (اگر base_url نبود)
     *
     * نکته‌ی مهم نسبت به نسخه‌ی قبلی: آدرس سایتمپ دیگر هاردکد به
     * arvindshop.ir نیست — قبلاً حتی اگر sitemap_url خالی بود و
     * دامنه‌ی دیگری پاس داده می‌شد، همچنان روی آدرس آروین fallback
     * می‌کرد که برای بقیه‌ی سایت‌های میکسین اشتباه بود.
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

        // حالت ۱: URLهای محصول مستقیماً در سایتمپ اصلی هستند.
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

        // حالت ۲: سایتمپ اصلی یک sitemap index است و به زیرسایتمپ‌ها اشاره می‌کند.
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
                if ( is_wp_error( $sub_xml ) ) {
                    continue;
                }
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

    /**
     * دریافت بدنه‌ی یک سایتمپ (با پشتیبانی از حالت gzip که بعضی
     * پیاده‌سازی‌های Next.js sitemap استفاده می‌کنند).
     */
    private static function fetch_xml_body( $url ) {
        $response = wp_safe_remote_get( $url, array(
            'timeout'     => 30,
            'user-agent'  => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36',
            'sslverify'   => true,
            'limit_response_size' => 6291456,
        ) );
        if ( is_wp_error( $response ) || wp_remote_retrieve_response_code( $response ) !== 200 ) {
            return new WP_Error( 'sitemap_error', 'خطا در دریافت سایتمپ: ' . $url );
        }
        $body = wp_remote_retrieve_body( $response );
        // اگر فایل gzip بود (مثلاً sitemap.xml.gz) رمزگشایی کن.
        if ( substr( $body, 0, 2 ) === "\x1f\x8b" && function_exists( 'gzdecode' ) ) {
            $decoded = gzdecode( $body );
            if ( $decoded !== false ) {
                $body = $decoded;
            }
        }
        return $body;
    }

    // ============================================================
    //  بخش اصلی استخراج محصول (عمومی برای هر سایت میکسین)
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

        // ---- روش ۱ (اصلی): ssrProductInfo از RSC flight payload ----
        $ssr = $this->extract_ssr_product_info( $html );
        if ( null !== $ssr ) {
            $data = $this->build_from_ssr( $ssr['product'], $ssr['product_id'], $url, $html );
            if ( $data ) {
                return $data;
            }
        }

        // ---- روش ۲/۳ (fallback): JSON-LD + متاتگ‌ها + DOM ----
        return $this->extract_via_fallback( $html, $url );
    }

    // ============================================================
    //  روش ۱: استخراج از ssrProductInfo (RSC flight payload)
    // ============================================================

    /**
     * تمام قطعات self.__next_f.push([1,"..."]) را به ترتیب کنار هم
     * می‌چیند تا رشته‌ی کامل flight payload بازسازی شود. هر push یک
     * تکه از یک استریم پیوسته است؛ رشته‌ی دوم آرایه (ایندکس ۱) را باید
     * به ترتیب ظهور در HTML کنار هم گذاشت.
     */
    private function reassemble_next_f_payload( $html ) {
        if ( ! preg_match_all( '/self\.__next_f\.push\((\[.*?\])\)<\/script>/s', $html, $matches ) ) {
            return '';
        }
        $payload = '';
        foreach ( $matches[1] as $chunk ) {
            $arr = json_decode( $chunk, true );
            if ( is_array( $arr ) && count( $arr ) === 2 && is_string( $arr[1] ) ) {
                $payload .= $arr[1];
            }
        }
        return $payload;
    }

    /**
     * از یک نقطه‌ی شروع، اولین '{' را پیدا می‌کند و با شمارش
     * آکولادهای باز/بسته (با درنظرگرفتن رشته‌های داخل آن، تا آکولاد
     * داخل یک رشته اشتباهی شمارش نشود) بلوک JSON متوازن را برمی‌گرداند.
     *
     * ایمنی UTF-8: پیمایش بر اساس بایت انجام می‌شود، نه کاراکتر؛ این
     * کاملاً بی‌خطر است چون کاراکترهای خاص ما ({ } " \) همگی ASCII
     * تک‌بایتی هستند و هرگز به‌عنوان بایت ادامه‌ی یک کاراکتر چندبایتی
     * UTF-8 (که همیشه >= 0x80 است) ظاهر نمی‌شوند.
     */
    private function extract_balanced_json( $payload, $start_idx ) {
        $start = strpos( $payload, '{', $start_idx );
        if ( false === $start ) {
            return null;
        }
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
                    if ( '\\' === $payload[ $i ] ) {
                        $i++;
                    }
                    $i++;
                }
            }
            $i++;
        }
        return null;
    }

    /**
     * پیدا کردن و پارس کردن ssrProductInfo + ssrProductId از HTML.
     * در صورت موفقیت آرایه‌ی ['product'=>..., 'product_id'=>...]،
     * در غیر این‌صورت null برمی‌گرداند.
     */
    private function extract_ssr_product_info( $html ) {
        $payload = $this->reassemble_next_f_payload( $html );
        if ( empty( $payload ) ) {
            return null;
        }

        $idx = strpos( $payload, '"ssrProductInfo"' );
        if ( false === $idx ) {
            return null;
        }

        $json_str = $this->extract_balanced_json( $payload, $idx );
        if ( null === $json_str ) {
            return null;
        }

        $product = json_decode( $json_str, true );
        if ( JSON_ERROR_NONE !== json_last_error() || ! is_array( $product ) ) {
            return null;
        }

        $product_id = '';
        if ( preg_match( '/"ssrProductId":"?([^",}]*)"?/', $payload, $m ) ) {
            $product_id = $m[1];
        }

        return array( 'product' => $product, 'product_id' => $product_id );
    }

    /**
     * ساخت آرایه‌ی نهایی محصول از شیء ssrProductInfo پارس‌شده.
     * این آرایه دقیقاً همان کلیدهایی را دارد که نسخه‌ی قبلی برمی‌گرداند
     * (سازگار با ProductDTO::normalize) به‌علاوه چند کلید تکمیلی جدید.
     */
    private function build_from_ssr( $p, $product_id, $url, $html ) {
        $base_url = $this->get_base_url( $url );

        // ---- تصاویر ----
        $images   = isset( $p['images'] ) && is_array( $p['images'] ) ? $p['images'] : array();
        $featured = '';
        $gallery  = array();
        foreach ( $images as $img ) {
            $abs = $this->abs_url( $base_url, $img['image_url'] ?? '' );
            if ( ! empty( $img['default'] ) && '' === $featured ) {
                $featured = $abs;
            } else {
                $gallery[] = $abs;
            }
        }
        if ( '' === $featured && ! empty( $images ) ) {
            $featured = $this->abs_url( $base_url, $images[0]['image_url'] ?? '' );
            $gallery  = array();
            foreach ( array_slice( $images, 1 ) as $img ) {
                $gallery[] = $this->abs_url( $base_url, $img['image_url'] ?? '' );
            }
        }

        // ---- دسته‌بندی‌ها (breadcrumb، از ریشه تا برگ) ----
        $categories = array();
        if ( ! empty( $p['main_category_breadcrumb'] ) && is_array( $p['main_category_breadcrumb'] ) ) {
            foreach ( $p['main_category_breadcrumb'] as $cat ) {
                if ( ! empty( $cat['name'] ) ) {
                    $categories[] = $cat['name'];
                }
            }
        }

        // ---- برند ----
        $brand_name = ! empty( $p['brand']['name'] ) ? $p['brand']['name'] : '';

        // ---- قیمت ----
        $price      = isset( $p['price'] ) ? (int) $p['price'] : 0;
        $compare_at = isset( $p['compare_at_price'] ) ? $p['compare_at_price'] : null;
        if ( ! empty( $compare_at ) && $compare_at > $price ) {
            $regular_price = (int) $compare_at;
            $sale_price    = $price;
        } else {
            $regular_price = $price;
            $sale_price    = null;
        }

        // ---- موجودی ----
        $stock_type     = $p['stock_type'] ?? 'unlimited';
        $max_available  = $p['max_available'] ?? null;
        $show_price     = array_key_exists( 'show_price', $p ) ? $p['show_price'] : true;
        if ( 'limited' === $stock_type ) {
            $stock_status   = ( (int) $max_available > 0 ) ? 'in-stock' : 'out-of-stock';
            $stock_quantity = null !== $max_available ? (int) $max_available : 0;
        } else {
            $stock_status   = ( false !== $show_price ) ? 'in-stock' : 'out-of-stock';
            $stock_quantity = null; // یعنی نامحدود/بدون ردیابی دقیق تعداد
        }

        // ---- SKU ----
        $sku = trim( (string) ( $p['product_identifier'] ?? '' ) );
        if ( '' === $sku ) {
            $sku = trim( (string) ( $p['english_name'] ?? '' ) );
        }

        // ---- attributes + variations ----
        $main_attrs = isset( $p['main_attributes'] ) && is_array( $p['main_attributes'] ) ? $p['main_attributes'] : array();
        $sec_attrs  = isset( $p['secondary_attributes'] ) && is_array( $p['secondary_attributes'] ) ? $p['secondary_attributes'] : array();

        // نگاشت value_id => [attr_name, value] برای رمزگشایی ترکیب هر variant.
        $id_map    = array();
        $attr_order = array();
        foreach ( $main_attrs as $attr ) {
            $attr_order[] = $attr['name'];
            foreach ( ( $attr['values'] ?? array() ) as $v ) {
                $id_map[ $v['id'] ] = array( $attr['name'], $v['value'] );
            }
        }

        $attributes = array();
        foreach ( $main_attrs as $attr ) {
            $values = array();
            foreach ( ( $attr['values'] ?? array() ) as $v ) {
                $values[] = $v['value'];
            }
            $attributes[] = array(
                'name'                => $attr['name'],
                'values'              => $values,
                'used_for_variations' => true,
            );
        }
        foreach ( $sec_attrs as $attr ) {
            // ساختار secondary_attributes با main_attributes فرق دارد:
            // {name, value:"رشته‌ی تکی"} به‌جای {name, values:[{id,value}]}.
            if ( isset( $attr['values'] ) && is_array( $attr['values'] ) ) {
                $values = array();
                foreach ( $attr['values'] as $v ) {
                    $values[] = $v['value'] ?? $v;
                }
            } else {
                $values = ( isset( $attr['value'] ) && '' !== $attr['value'] ) ? array( $attr['value'] ) : array();
            }
            $attributes[] = array(
                'name'                => $attr['name'],
                'values'              => $values,
                'used_for_variations' => false,
            );
        }

        $has_variants = ! empty( $p['has_variants'] );
        $variations   = array();
        if ( $has_variants && ! empty( $p['variants'] ) && is_array( $p['variants'] ) ) {
            foreach ( $p['variants'] as $var ) {
                $var_attr_ids = $var['attributes'] ?? array();
                $attr_map     = array();
                foreach ( $attr_order as $name ) {
                    foreach ( $var_attr_ids as $aid ) {
                        if ( isset( $id_map[ $aid ] ) && $id_map[ $aid ][0] === $name ) {
                            $attr_map[ $name ] = $id_map[ $aid ][1];
                            break;
                        }
                    }
                }

                $v_price      = isset( $var['price'] ) ? (int) $var['price'] : 0;
                $v_compare_at = $var['compare_at_price'] ?? null;
                if ( ! empty( $v_compare_at ) && $v_compare_at > $v_price ) {
                    $v_regular = (int) $v_compare_at;
                    $v_sale    = $v_price;
                } else {
                    $v_regular = $v_price;
                    $v_sale    = null;
                }

                $v_max = $var['max_available'] ?? null;
                if ( null !== $v_max ) {
                    $v_stock_status = ( (int) $v_max > 0 ) ? 'in-stock' : 'out-of-stock';
                    $v_stock_qty    = (int) $v_max;
                } else {
                    $v_show_price   = array_key_exists( 'show_price', $var ) ? $var['show_price'] : true;
                    $v_stock_status = ( false !== $v_show_price ) ? 'in-stock' : 'out-of-stock';
                    $v_stock_qty    = null;
                }

                $v_sku   = trim( (string) ( $var['product_identifier'] ?? '' ) );
                $v_image = $this->abs_url( $base_url, $var['image_url'] ?? '' );
                if ( '' === $v_image ) {
                    $v_image = $featured;
                }

                $variations[] = array(
                    'attributes_summary' => implode( '، ', array_values( $attr_map ) ),
                    'attributes_map'     => $attr_map,
                    'sku'                => $v_sku,
                    'regular_price'      => $v_regular,
                    'sale_price'         => $v_sale,
                    'stock_status'       => $v_stock_status,
                    'stock_quantity'     => $v_stock_qty,
                    'image'              => $v_image,
                );
            }
        }

        // ---- تگ‌ها (ساختار دقیق در نمونه‌های بررسی‌شده هرگز پر نبود؛
        // هر دو شکل احتمالی — آرایه‌ی رشته یا آرایه‌ی آبجکت {name} — پشتیبانی می‌شود) ----
        $tags = array();
        if ( ! empty( $p['tags'] ) && is_array( $p['tags'] ) ) {
            foreach ( $p['tags'] as $tag ) {
                $tags[] = is_array( $tag ) ? ( $tag['name'] ?? '' ) : $tag;
            }
            $tags = array_values( array_filter( $tags ) );
        }

        // ---- canonical: ترجیحاً از <link rel="canonical"> واقعی در DOM ----
        $canonical = $this->quick_extract_link_canonical( $html );
        if ( '' === $canonical && $base_url && isset( $p['slug'] ) ) {
            $canonical = $base_url . '/product/' . rawurlencode( (string) $product_id ) . '/' . implode( '/', array_map( 'rawurlencode', explode( '/', $p['slug'] ) ) ) . '/';
        }

        $meta_title = ! empty( $p['seo_title'] ) ? $p['seo_title'] : ( $p['name'] ?? '' );

        return array(
            'product_id'     => (string) $product_id,
            'sku'            => $sku,
            'title'          => $this->clean_text( $p['name'] ?? '' ),
            'excerpt'        => $p['analysis'] ?? '',
            'content'        => $p['description'] ?? '',
            'featured_image' => $featured,
            'gallery_images' => $gallery,
            'regular_price'  => $regular_price,
            'sale_price'     => $sale_price,
            'currency'       => 'تومان',
            'stock_status'   => $stock_status,
            'stock_quantity' => $stock_quantity,
            'categories'     => $categories,
            'tags'           => $tags,
            'product_type'   => $has_variants ? 'variable' : 'simple',
            'attributes'     => $attributes,
            'variations'     => $variations,
            'guarantee'      => $p['guarantee'] ?? '',
            'meta_title'     => $meta_title,
            'canonical'      => $canonical,
            // ---- کلیدهای تکمیلی جدید (اضافه، بدون شکستن ساختار قبلی) ----
            'brand'               => $brand_name,
            'is_digital'          => ! empty( $p['is_digital'] ),
            'discount_percent'    => $p['discount_percent'] ?? null,
            'min_order_quantity'  => $p['min_order_quantity'] ?? null,
            'processing_time'     => $p['processing_time'] ?? null,
            'source_platform'     => 'mixin',
        );
    }

    private function quick_extract_link_canonical( $html ) {
        if ( preg_match( '/<link[^>]+rel=["\']canonical["\'][^>]+href=["\']([^"\']+)["\']/i', $html, $m ) ) {
            return $m[1];
        }
        return '';
    }

    private function get_base_url( $url ) {
        $parts = parse_url( $url );
        if ( isset( $parts['scheme'], $parts['host'] ) ) {
            return $parts['scheme'] . '://' . $parts['host'];
        }
        return '';
    }

    private function abs_url( $base_url, $path ) {
        if ( empty( $path ) ) {
            return '';
        }
        if ( preg_match( '/^https?:\/\//i', $path ) ) {
            return $path;
        }
        if ( empty( $base_url ) ) {
            return $path;
        }
        return rtrim( $base_url, '/' ) . '/' . ltrim( $path, '/' );
    }

    // ============================================================
    //  روش fallback (وقتی ssrProductInfo پیدا/پارس نشد):
    //  JSON-LD + متاتگ‌های اختصاصی میکسین + اسکرپ DOM.
    //  این همان منطق نسخه‌ی قبلی است، کمی سخت‌تر و عمومی‌تر شده.
    // ============================================================

    private function extract_via_fallback( $html, $url ) {
        $jsonld = $this->parse_json_ld_product( $html );

        libxml_use_internal_errors( true );
        $dom = new DOMDocument();
        $dom->loadHTML( '<?xml encoding="UTF-8">' . $html );
        libxml_clear_errors();
        $xpath = new DOMXPath( $dom );

        $product_id        = $this->get_meta_content( $xpath, 'product_id' );
        $product_price     = $this->get_meta_content( $xpath, 'product_price' );
        $product_old_price = $this->get_meta_content( $xpath, 'product_old_price' );
        $availability      = $this->get_meta_content( $xpath, 'availability' );
        $guarantee         = $this->clean_null_string( $this->get_meta_content( $xpath, 'guarantee' ) );
        $meta_description  = $this->get_meta_content( $xpath, 'description' );
        $og_image          = $this->get_meta_property( $xpath, 'og:image' );
        $og_title          = $this->get_meta_property( $xpath, 'og:title' );
        $og_description     = $this->get_meta_property( $xpath, 'og:description' );

        $sku   = $this->extract_sku( $xpath, $html );
        $title = $this->extract_title( $xpath, $jsonld );
        $content = $this->extract_content( $xpath );
        $images  = $this->extract_images( $xpath, $jsonld, $og_image, $url );
        $categories = $this->extract_categories( $xpath, $jsonld );

        $regular_price = 0;
        $sale_price    = null;
        if ( $product_old_price && $product_price ) {
            $regular_price = $this->normalize_price( $product_old_price );
            $sale_price    = $this->normalize_price( $product_price );
            if ( $regular_price < $sale_price ) {
                $tmp           = $regular_price;
                $regular_price = $sale_price;
                $sale_price    = $tmp;
            }
        } elseif ( $product_price ) {
            $regular_price = $this->normalize_price( $product_price );
        } else {
            $offers = $jsonld['offers'] ?? array();
            if ( isset( $offers['price'] ) ) {
                // قیمت JSON-LD واحدش ریال است؛ برای تبدیل به تومان بر ۱۰ تقسیم می‌شود.
                $regular_price = intval( $offers['price'] ) / 10;
            }
        }

        $stock_status = ( 'instock' === $availability ) ? 'in-stock' : ( $availability ? 'out-of-stock' : 'in-stock' );

        return array(
            'product_id'     => (string) $product_id,
            'sku'            => $sku,
            'title'          => $this->clean_text( $title ),
            'excerpt'        => $meta_description ?: $og_description ?: '',
            'content'        => $content,
            'featured_image' => $images['featured'] ?? '',
            'gallery_images' => $images['gallery'] ?? array(),
            'regular_price'  => $regular_price,
            'sale_price'     => $sale_price,
            'currency'       => 'تومان',
            'stock_status'   => $stock_status,
            'stock_quantity' => null,
            'categories'     => $categories,
            'tags'           => array(),
            'product_type'   => 'simple', // در این مسیر fallback نمی‌توانیم variantها را با اطمینان تشخیص دهیم
            'attributes'     => array(),
            'variations'     => array(),
            'guarantee'      => $guarantee,
            'meta_title'     => $this->get_meta_content( $xpath, 'title' ) ?: $og_title,
            'canonical'      => $this->get_link_href( $xpath, 'canonical' ),
            'brand'          => '',
            'is_digital'     => false,
            'discount_percent'   => null,
            'min_order_quantity' => null,
            'processing_time'    => null,
            'source_platform'    => 'mixin',
        );
    }

    /**
     * برخی مقادیر خالی در سایت‌های میکسین به‌جای null، رشته‌ی تحت‌اللفظی
     * "null" در متاتگ رندر می‌شوند (باگ سمت سرور آن‌ها). این متد آن را
     * به رشته‌ی خالی تبدیل می‌کند تا در خروجی گمراه‌کننده نباشد.
     */
    private function clean_null_string( $value ) {
        $trimmed = trim( (string) $value );
        return ( 'null' === strtolower( $trimmed ) ) ? '' : $trimmed;
    }

    // ============================================================
    //  متدهای کمکی اسکرپ DOM (از نسخه‌ی قبلی، برای fallback)
    // ============================================================

    private function get_meta_content( $xpath, $name ) {
        $nodes = $xpath->query( "//meta[@name='$name']/@content" );
        if ( $nodes->length ) {
            return $nodes->item(0)->nodeValue;
        }
        return '';
    }

    private function get_meta_property( $xpath, $property ) {
        $nodes = $xpath->query( "//meta[@property='$property']/@content" );
        if ( $nodes->length ) {
            return $nodes->item(0)->nodeValue;
        }
        return '';
    }

    private function get_link_href( $xpath, $rel ) {
        $nodes = $xpath->query( "//link[@rel='$rel']/@href" );
        if ( $nodes->length ) {
            return $nodes->item(0)->nodeValue;
        }
        return '';
    }

    private function extract_sku( $xpath, $html ) {
        $nodes = $xpath->query( "//div[contains(@class,'ds-caption-v2') and contains(@class,'text_text--caption')]" );
        foreach ( $nodes as $node ) {
            $text = trim( $node->nodeValue );
            if ( preg_match( '/^[A-Za-z0-9\-]+$/', $text ) ) {
                return $text;
            }
        }
        if ( preg_match( '/"english_name":"([^"]+)"/', $html, $match ) ) {
            return $match[1];
        }
        if ( preg_match( '/"product_identifier":"([^"]+)"/', $html, $match ) ) {
            return $match[1];
        }
        return '';
    }

    private function extract_title( $xpath, $jsonld ) {
        $nodes = $xpath->query( "//h1[contains(@class,'ds-h1')]" );
        if ( $nodes->length ) {
            return trim( $nodes->item(0)->nodeValue );
        }
        if ( ! empty($jsonld['name']) ) {
            return $jsonld['name'];
        }
        $title = $this->get_meta_content( $xpath, 'title' );
        if ( $title ) return $title;
        return '';
    }

    private function extract_content( $xpath ) {
        $nodes = $xpath->query( "//article[contains(@class,'parser')]" );
        if ( $nodes->length ) {
            return $this->inner_html( $nodes->item(0) );
        }
        $nodes = $xpath->query( "//div[contains(@class,'text-start')]" );
        if ( $nodes->length ) {
            return $this->inner_html( $nodes->item(0) );
        }
        return '';
    }

    private function inner_html( $node ) {
        $html = '';
        foreach ( $node->childNodes as $child ) {
            $html .= $node->ownerDocument->saveHTML( $child );
        }
        return $html;
    }

    private function extract_images( $xpath, $jsonld, $og_image, $product_url ) {
        $images = array(
            'featured' => '',
            'gallery'  => array(),
        );

        if ( ! empty($jsonld['image']) ) {
            if ( is_array($jsonld['image']) ) {
                $images['featured'] = $jsonld['image'][0] ?? '';
                $images['gallery']  = array_slice( $jsonld['image'], 1 );
            } else {
                $images['featured'] = $jsonld['image'];
            }
        }

        if ( empty($images['featured']) && $og_image ) {
            $images['featured'] = $og_image;
        }

        $base_url = $this->get_base_url( $product_url );

        if ( empty($images['gallery']) ) {
            $img_nodes = $xpath->query( "//div[contains(@class,'flex')]//img[contains(@src,'product-images')]" );
            $all = array();

            foreach ( $img_nodes as $img ) {
                $src = $img->getAttribute('src');
                if ( $src && $base_url && ! preg_match( '/^https?:\/\//i', $src ) ) {
                    $src = $this->abs_url( $base_url, $src );
                }
                if ( ! $src ) continue;

                $clean_src      = strtok( $src, '?' );
                $clean_featured = strtok( $images['featured'], '?' );

                if ( $clean_src && $clean_src !== $clean_featured ) {
                    $all[] = $clean_src;
                }
            }

            $images['gallery'] = array_values( array_unique( $all ) );
        }

        return $images;
    }

    private function extract_categories( $xpath, $jsonld ) {
        $categories = array();

        $scripts = $xpath->query( "//script[@type='application/ld+json']" );
        foreach ( $scripts as $script ) {
            $data = json_decode( $script->nodeValue, true );
            if ( isset($data['@type']) && $data['@type'] === 'BreadcrumbList' && isset($data['itemListElement']) ) {
                foreach ( $data['itemListElement'] as $item ) {
                    if ( isset($item['name']) && $item['position'] > 1 ) {
                        $categories[] = $item['name'];
                    }
                }
                break;
            }
        }

        if ( empty($categories) ) {
            $nodes = $xpath->query( "//div[contains(@class,'ds-caption-v2')]//a" );
            $names = array();
            foreach ( $nodes as $node ) {
                $text = trim( $node->nodeValue );
                if ( $text ) {
                    $names[] = $text;
                }
            }
            // اولین آیتم breadcrumb معمولاً نام فروشگاه/صفحه‌ی اصلی است؛ آن را حذف کن.
            if ( count( $names ) > 1 ) {
                array_shift( $names );
            }
            $categories = $names;
        }

        $categories = array_filter( array_unique( $categories ) );
        return array_values( $categories );
    }

    private function parse_json_ld_product( $html ) {
        if ( ! preg_match_all('/<script\s+type="application\/ld\+json"[^>]*>(.*?)<\/script>/is', $html, $m) ) return array();
        foreach ( $m[1] as $json ) {
            $data = json_decode( $json, true );
            if ( ! $data ) continue;
            if ( isset($data[0]) && is_array($data[0]) ) {
                foreach ( $data as $item ) {
                    if ( isset($item['@type']) && $item['@type'] === 'Product' ) return $item;
                }
            } elseif ( isset($data['@type']) && $data['@type'] === 'Product' ) {
                return $data;
            }
        }
        return array();
    }

    private function clean_text( $text ) {
        $text = wp_strip_all_tags( (string)$text );
        $text = html_entity_decode( $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
        return trim( preg_replace('/\s+/u', ' ', $text) );
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

    // ============================================================
    //  نمایش داده‌ها (صفحه‌ی ادمین)
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
            <h2>Extracted Product Data</h2>

            <div class="product-ids">
                <span><strong>Product ID (شناسه همگام‌سازی):</strong> <?php echo esc_html($data['product_id']); ?></span><br>
                <span><strong>SKU:</strong> <?php echo esc_html($data['sku']); ?></span><br>
                <?php if ( ! empty($raw['brand']) ): ?>
                <span><strong>برند:</strong> <?php echo esc_html($raw['brand']); ?></span><br>
                <?php endif; ?>
                <?php if ( ! empty($raw['source_platform']) ): ?>
                <span><strong>پلتفرم منبع:</strong> <?php echo esc_html($raw['source_platform']); ?></span>
                <?php endif; ?>
            </div>

            <hr>

            <div class="product-content">
                <h1>عنوان محصول (Title): <?php echo esc_html($data['title']); ?></h1>
                <p><strong>توضیحات کوتاه (Excerpt):</strong> <?php echo wp_kses_post($data['excerpt']); ?></p>
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
                <?php if ( isset($raw['discount_percent']) && null !== $raw['discount_percent'] ): ?>
                <p><strong>درصد تخفیف:</strong> <?php echo esc_html($raw['discount_percent']); ?>%</p>
                <?php endif; ?>
                <p><strong>موجودی (Stock Status):</strong> <?php echo esc_html($data['stock_status']); ?></p>
                <p><strong>تعداد موجودی (Stock Quantity):</strong> <?php echo null !== $data['stock_quantity'] ? esc_html($data['stock_quantity']) : '-'; ?></p>
                <p><strong>گارانتی (Guarantee):</strong> <?php echo esc_html($data['guarantee'] ?? ''); ?></p>
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
                <p><small>شامل نام، مقدار، و اینکه برای متغیر استفاده می‌شود یا نه.</small></p>
                <?php if (!empty($data['attributes'])): ?>
                    <table>
                        <thead>
                            <tr><th>نام ویژگی</th><th>مقدار(ها)</th><th>برای متغیر استفاده می‌شود؟</th></tr>
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
                            <tr><th>ترکیب ویژگی‌ها</th><th>attributes_map</th><th>SKU</th><th>قیمت اصلی</th><th>قیمت ویژه</th><th>وضعیت موجودی</th><th>تعداد موجودی</th><th>تصویر</th></tr>
                        </thead>
                        <tbody>
                            <?php foreach ($data['variations'] as $var): ?>
                                <tr>
                                    <td><?php echo esc_html($var['attributes_summary']); ?></td>
                                    <td><?php echo esc_html(json_encode($var['attributes_map'], JSON_UNESCAPED_UNICODE)); ?></td>
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

/**
 * Alias برای سازگاری با نسخه‌ی قبلی: هر کدی که هنوز
 * Arvindshop_Product_Extractor را صدا می‌زند بدون تغییر کار می‌کند،
 * چون این کلاس همان Mixin_Product_Extractor عمومی است (arvindshop.ir
 * خودش یکی از سایت‌های میکسین است).
 */
if ( ! class_exists( 'Arvindshop_Product_Extractor' ) ) {
    class Arvindshop_Product_Extractor extends Mixin_Product_Extractor {
        const MENU_SLUG = 'arvindshop-extractor';
    }
}
