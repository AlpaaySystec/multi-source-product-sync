<?php
/**
 * ZippoGift Product Extractor for Main Plugin
 * Clean final version – no debug logging
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! class_exists( 'ProductDTO' ) ) {
    require_once plugin_dir_path( __FILE__ ) . 'class-product-dto.php';
}

class ZippoGift_Product_Extractor {
	public static $source_data = array();

    const SUPPLIER_URL = 'https://zippogift.ir';
    const LOG_TRANSIENT = 'zgext_main_log';
    const SESSION_TRANSIENT = 'zgext_main_session';

    private static $auth_username;
    private static $auth_password;

    const USER_AGENT = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36';

    private static function log( $message ) {
        $log = get_transient( self::LOG_TRANSIENT ) ?: '';
        $log .= date('[H:i:s] ') . $message . "\n";
        set_transient( self::LOG_TRANSIENT, $log, 5 * MINUTE_IN_SECONDS );
    }

    public static function clear_log() {
        delete_transient( self::LOG_TRANSIENT );
    }

    public static function get_log() {
        return get_transient( self::LOG_TRANSIENT );
    }

    public static function set_credentials( $username, $password ) {
        self::$auth_username = $username;
        self::$auth_password = $password;
    }

    public static function extract( $url ) {
        self::clear_log();
        self::log( '--- شروع استخراج تک‌محصول ---' );
        self::log( 'URL: ' . $url );

        if ( ! self::is_valid_product_url( $url ) ) {
            self::log( 'خطا: URL محصول معتبر نیست.' );
            return false;
        }
        if ( empty( self::$auth_username ) || empty( self::$auth_password ) ) {
            self::log( 'خطا: اطلاعات کاربری تنظیم نشده است.' );
            return false;
        }

        $session = self::get_cached_session( self::$auth_username, self::$auth_password );
        if ( is_wp_error( $session ) ) {
            self::log( 'خطای نشست: ' . $session->get_error_message() );
            return false;
        }
        self::log( 'نشست معتبر دریافت شد. دریافت صفحه محصول...' );

        $response = wp_safe_remote_get( $url, array(
            'headers'    => array( 'Cookie' => $session ),
            'timeout'    => 20,
			'redirection'=> 0,
			'limit_response_size' => 6291456,
			'sslverify'  => true,
            'user-agent' => self::USER_AGENT,
        ) );

        if ( is_wp_error( $response ) ) {
            self::log( 'خطا در دریافت محصول: ' . $response->get_error_message() );
            return false;
        }

        $code = wp_remote_retrieve_response_code( $response );
        if ( $code !== 200 ) {
            self::log( 'HTTP Status: ' . $code );
            return false;
        }

        $html = wp_remote_retrieve_body( $response );
        if ( strpos( $html, 'auth.login_form' ) !== false ) {
            self::log( 'صفحه دریافت‌شده فرم لاگین است. نشست نامعتبر – حذف و تلاش مجدد.' );
            delete_transient( self::SESSION_TRANSIENT );
            return false;
        }

        $raw = self::parse_product_html( $html, $url );
        if ( empty( $raw['title'] ) ) {
            self::log( 'استخراج ناموفق: عنوان محصول یافت نشد.' );
            return false;
        }
        self::log( 'استخراج موفق: ' . $raw['title'] );
        return $raw;
    }

	public static function parse_product_html( $html, $url ) {
		if ( ! is_string( $html ) || '' === trim( $html ) || ! self::is_valid_product_url( $url ) ) return false;
		$raw = self::parse_product( $html, $url );
		if ( empty( $raw['title'] ) ) return false;
		$normalized = ProductDTO::normalize( $raw );
		$normalized['source_data'] = isset( $raw['source_data'] ) ? $raw['source_data'] : array();
		self::$source_data = $normalized['source_data'];
		return $normalized;
	}

    public static function get_product_urls( $profile ) {
        self::clear_log();
        self::log( '--- شروع خزش (get_product_urls) ---' );

        $username = $profile['auth_username'] ?? '';
        $password = $profile['auth_password'] ?? '';

        if ( empty( $username ) || empty( $password ) ) {
            self::log( 'خطا: نام کاربری یا رمز عبور خالی.' );
            return new WP_Error( 'missing_credentials', 'نام کاربری و رمز عبور الزامی است.' );
        }

        self::set_credentials( $username, $password );

        $session = self::get_cached_session( $username, $password );
        if ( is_wp_error( $session ) ) {
            self::log( 'خطای لاگین: ' . $session->get_error_message() );
            return $session;
        }
        self::log( 'لاگین موفق. دریافت sitemap...' );

        $sitemap_url = self::SUPPLIER_URL . '/index.php?dispatch=sitemap.view';
        $resp = wp_remote_get( $sitemap_url, array(
            'headers'   => array( 'Cookie' => $session ),
            'timeout'   => 20,
            'sslverify' => true,
            'user-agent'=> self::USER_AGENT,
        ) );
        if ( is_wp_error( $resp ) || wp_remote_retrieve_response_code( $resp ) !== 200 ) {
            $error_msg = is_wp_error( $resp ) ? $resp->get_error_message() : 'HTTP ' . wp_remote_retrieve_response_code( $resp );
            self::log( 'خطا در دریافت sitemap: ' . $error_msg );
            return new WP_Error( 'sitemap_error', 'دریافت sitemap ناموفق.' );
        }

        $html = wp_remote_retrieve_body( $resp );
        $categories = self::parse_sitemap_categories( $html );
        if ( empty( $categories ) ) {
            self::log( 'هیچ دسته‌بندی در sitemap پیدا نشد.' );
            return new WP_Error( 'no_categories', 'دسته‌بندی یافت نشد.' );
        }
        self::log( 'تعداد دسته‌ها: ' . count( $categories ) );

        $all_urls = array();

        foreach ( $categories as $cat ) {
            $page = 1;
            self::log( 'پیمایش دسته: ' . $cat['name'] . ' (ID: ' . $cat['id'] . ')' );
            while ( true ) {
                $cat_url = self::SUPPLIER_URL . '/index.php?dispatch=categories.view&category_id=' . $cat['id'] . '&page=' . $page;
                $resp = wp_remote_get( $cat_url, array(
                    'headers'   => array( 'Cookie' => $session ),
                    'timeout'   => 20,
                    'sslverify' => true,
                    'user-agent'=> self::USER_AGENT,
                ) );
                if ( is_wp_error( $resp ) ) {
                    break;
                }

                $cat_html = wp_remote_retrieve_body( $resp );
                $new_urls = self::extract_product_urls_from_page( $cat_html );
                if ( empty( $new_urls ) ) {
                    break;
                }

                self::log( 'صفحه ' . $page . ': ' . count( $new_urls ) . ' محصول' );
                $all_urls = array_merge( $all_urls, $new_urls );
                $page++;
                sleep( 1 );
            }
            self::log( 'پایان دسته ' . $cat['name'] . ' - مجموع: ' . count( $all_urls ) );
        }

        if ( empty( $all_urls ) ) {
            return new WP_Error( 'no_products', 'هیچ محصولی یافت نشد.' );
        }
        self::log( 'خزش کامل شد. تعداد کل محصولات: ' . count( $all_urls ) );
        return array_values( array_unique( $all_urls ) );
    }

    private static function get_cached_session( $username, $password ) {
        $cached = get_transient( self::SESSION_TRANSIENT );
        if ( $cached ) {
            $test_url = self::SUPPLIER_URL . '/index.php?dispatch=profiles.update';
            $test_resp = wp_remote_get( $test_url, array(
                'headers'   => array( 'Cookie' => $cached ),
                'timeout'   => 10,
                'sslverify' => true,
                'user-agent'=> self::USER_AGENT,
            ) );
            if ( ! is_wp_error( $test_resp ) ) {
                $test_html = wp_remote_retrieve_body( $test_resp );
                if ( strpos( $test_html, 'خروج از سیستم' ) !== false || strpos( $test_html, 'auth.logout' ) !== false ) {
                    return $cached;
                }
            }
            delete_transient( self::SESSION_TRANSIENT );
        }

        $session = self::do_login( $username, $password );
        if ( ! is_wp_error( $session ) ) {
            set_transient( self::SESSION_TRANSIENT, $session, 50 * MINUTE_IN_SECONDS );
        }
        return $session;
    }

    private static function do_login( $username, $password ) {
        $login_url = self::SUPPLIER_URL . '/index.php?dispatch=auth.login_form&return_url=index.php';
        $resp = wp_remote_get( $login_url, array(
            'timeout'    => 15,
            'sslverify'  => true,
            'user-agent' => self::USER_AGENT,
        ) );
        if ( is_wp_error( $resp ) ) {
            self::log( 'خطا در دریافت صفحه لاگین: ' . $resp->get_error_message() );
            return $resp;
        }

        $html = wp_remote_retrieve_body( $resp );
        if ( ! preg_match( '/name="security_hash".*?value="([^"]+)"/', $html, $m ) ) {
            self::log( 'توکن امنیتی در صفحه لاگین پیدا نشد.' );
            return new WP_Error( 'hash_error', 'توکن امنیتی یافت نشد.' );
        }
        $hash = $m[1];

        $cookies = self::extract_cookies( $resp );

        $post_fields = array(
            'dispatch[auth.login]' => '',
            'user_login'           => $username,
            'password'             => $password,
            'security_hash'        => $hash,
            'return_url'           => 'index.php',
        );
        $login_resp = wp_remote_post( self::SUPPLIER_URL . '/index.php', array(
            'headers'     => array( 'Cookie' => self::format_cookies_string( $cookies ) ),
            'body'        => $post_fields,
            'redirection' => 0,
            'timeout'     => 15,
            'sslverify'   => true,
            'user-agent'  => self::USER_AGENT,
        ) );
        if ( is_wp_error( $login_resp ) ) {
            self::log( 'خطا در POST لاگین: ' . $login_resp->get_error_message() );
            return $login_resp;
        }

        $status = wp_remote_retrieve_response_code( $login_resp );

        if ( $status == 302 || $status == 301 ) {
            $location = wp_remote_retrieve_header( $login_resp, 'location' );
            if ( empty( $location ) ) {
                return new WP_Error( 'redirect_error', 'مقصد ریدایرکت خالی.' );
            }
            if ( strpos( $location, 'http' ) !== 0 ) {
                $location = self::SUPPLIER_URL . '/' . ltrim( $location, '/' );
            }
            $merged = self::merge_cookies( $cookies, self::extract_cookies( $login_resp ) );
            $redirect_resp = wp_remote_get( $location, array(
                'headers'    => array( 'Cookie' => self::format_cookies_string( $merged ) ),
                'timeout'    => 15,
                'sslverify'  => true,
                'user-agent' => self::USER_AGENT,
            ) );
            if ( is_wp_error( $redirect_resp ) ) {
                self::log( 'خطا در دنبال کردن ریدایرکت: ' . $redirect_resp->get_error_message() );
                return $redirect_resp;
            }
            $final_html = wp_remote_retrieve_body( $redirect_resp );
            $final_cookies = self::merge_cookies( $merged, self::extract_cookies( $redirect_resp ) );
        } else {
            $final_html = wp_remote_retrieve_body( $login_resp );
            $final_cookies = self::merge_cookies( $cookies, self::extract_cookies( $login_resp ) );
        }

        if ( strpos( $final_html, 'خروج از سیستم' ) === false && strpos( $final_html, 'auth.logout' ) === false ) {
            self::log( 'ورود ناموفق: عبارت "خروج از سیستم" در پاسخ نهایی یافت نشد.' );
            return new WP_Error( 'login_failed', 'ورود ناموفق. نام کاربری یا رمز عبور اشتباه است.' );
        }

        $session_cookie = self::format_cookies_string( $final_cookies );
        return $session_cookie;
    }

    private static function parse_product( $html, $url ) {
        libxml_use_internal_errors( true );
        $dom = new DOMDocument();
        $dom->loadHTML( mb_convert_encoding( $html, 'HTML-ENTITIES', 'UTF-8' ) );
        libxml_clear_errors();
        $xpath = new DOMXPath( $dom );

        $data = array();

        $n = $xpath->query( "//h1[@id='et_prod_title']" );
        $data['title'] = ( $n->length ) ? trim( $n->item(0)->nodeValue ) : '';

        $p = $xpath->query( "//span[starts-with(@id, 'sec_discounted_price_')]" );
        $price_text = ( $p->length ) ? trim( $p->item(0)->nodeValue ) : '0';
		$current_price = self::parse_price( $price_text );
		$list = $xpath->query( "//*[starts-with(@id,'sec_list_price_') or starts-with(@id,'sec_old_price_') or contains(@class,'ty-list-price')]" );
		$list_price = ( $list->length ) ? self::parse_price( $list->item( 0 )->textContent ) : 0;
		$data['regular_price'] = $list_price > $current_price ? $list_price : $current_price;
		$data['sale_price'] = $list_price > $current_price && $current_price > 0 ? $current_price : null;

        $s = $xpath->query( "//span[starts-with(@id, 'product_code_')]" );
        $data['sku'] = ( $s->length ) ? trim( $s->item(0)->nodeValue ) : '';

        preg_match( '/product_id=(\d+)/', $url, $m );
        $data['product_id'] = isset( $m[1] ) ? $m[1] : '';

        $st = $xpath->query( "//div[contains(@class, 'et-grid-stock')]//span" );
        $stock_text = ( $st->length ) ? trim( $st->item(0)->nodeValue ) : '';
        $data['stock_status'] = ( $stock_text === 'موجود' ) ? 'in-stock' : 'out-of-stock';
        $data['stock_quantity'] = ( $data['stock_status'] === 'in-stock' ) ? null : 0;

        $categories = array();
        $bc = $xpath->query( "//div[contains(@class, 'et-breadcrumbs')]//a" );
        foreach ( $bc as $a ) { $categories[] = trim( $a->nodeValue ); }
        $categories = array_filter( $categories, function( $cat ) {
            return ! in_array( $cat, array( 'صفحه اصلی', 'همه' ) );
        });
        $data['categories'] = array_values( array_unique( $categories ) );

        $desc = $xpath->query( "//div[@id='content_description']" );
        $data['excerpt'] = '';
        $data['content'] = ( $desc->length ) ? $desc->item(0)->ownerDocument->saveHTML( $desc->item(0) ) : '';

        $images = array();
        $imgs = $xpath->query( "//div[contains(@class, 'cm-image-gallery-wrapper')]//img" );
        foreach ( $imgs as $img ) {
            $src = $img->getAttribute( 'src' );
            if ( $src && strpos( $src, 'et-empty.png' ) === false && strpos( $src, 'thumbnails' ) !== false ) {
                $src = preg_replace( '#/thumbnails/\d+/\d+/#', '/', $src );
                if ( strpos( $src, 'http' ) !== 0 ) $src = self::SUPPLIER_URL . '/' . ltrim( $src, '/' );
                $images[] = $src;
            }
        }
        $data['featured_image'] = isset( $images[0] ) ? $images[0] : '';
        $data['gallery_images'] = $images;

        $attributes = array();
        $featureNodes = $xpath->query( "//div[contains(@class, 'ty-product-feature')]" );
        foreach ( $featureNodes as $f ) {
            $label = $xpath->query( ".//div[contains(@class, 'ty-product-feature__label')]", $f );
            $value = $xpath->query( ".//div[contains(@class, 'ty-product-feature__value')]", $f );
            if ( $label->length && $value->length ) {
                $l = trim( $label->item(0)->nodeValue );
                $v = trim( $value->item(0)->nodeValue );
                $l = rtrim( $l, ':' );
                if ( $v !== '' ) {
                    $attributes[] = array(
                        'id'                  => count( $attributes ),
                        'name'                => $l,
                        'values'              => array( $v ),
                        'option_details'      => array(),
                        'used_for_variations' => false,
                    );
                }
            }
        }
        $data['attributes'] = $attributes;
		$option_nodes = $xpath->query( "//*[@id='product_options_update_" . $data['product_id'] . "']//select|//*[@id='product_options_update_" . $data['product_id'] . "']//input[@type='radio' or @type='checkbox']" );
        $data['product_type'] = ( $option_nodes && $option_nodes->length > 0 ) ? 'variable' : 'simple';
        $data['variations']   = array();
        $data['tags']         = array();
        $data['currency']     = 'تومان';
		$data['source_data']    = self::extract_source_data( $dom, $xpath, $url, $data );

        return $data;
    }

	private static function extract_source_data( $dom, $xpath, $url, $data ) {
		$meta = array();
		foreach ( $xpath->query( '//meta[@content]' ) as $node ) {
			$key = $node->getAttribute( 'property' );
			if ( '' === $key ) $key = $node->getAttribute( 'name' );
			if ( '' === $key ) $key = $node->getAttribute( 'itemprop' );
			if ( '' !== $key ) $meta[ $key ] = $node->getAttribute( 'content' );
		}
		$json_documents = array();
		foreach ( $xpath->query( "//script[@type='application/ld+json']" ) as $node ) {
			$decoded = json_decode( trim( $node->textContent ), true );
			if ( is_array( $decoded ) ) $json_documents[] = $decoded;
		}
		$breadcrumbs = array();
		foreach ( $xpath->query( "//div[contains(@class,'et-breadcrumbs')]//a" ) as $link ) {
			$breadcrumbs[] = array( 'name' => self::clean_text( $link->textContent ), 'url' => self::absolute_url( $link->getAttribute( 'href' ) ) );
		}
		$images = array(); $seen = array();
		foreach ( $xpath->query( "//div[contains(@class,'cm-image-gallery-wrapper')]//img" ) as $img ) {
			$src = $img->getAttribute( 'data-src' ); if ( '' === $src ) $src = $img->getAttribute( 'src' );
			$src = self::absolute_url( $src ); if ( '' === $src || false !== strpos( $src, 'et-empty.png' ) || isset( $seen[ $src ] ) ) continue; $seen[ $src ] = true;
			$parent = $img->parentNode;
			$images[] = array( 'src' => $src, 'full_src' => $parent instanceof DOMElement && 'a' === strtolower( $parent->tagName ) ? self::absolute_url( $parent->getAttribute( 'href' ) ) : '', 'alt' => $img->getAttribute( 'alt' ), 'title' => $img->getAttribute( 'title' ), 'srcset' => $img->getAttribute( 'srcset' ), 'width' => $img->getAttribute( 'width' ), 'height' => $img->getAttribute( 'height' ) );
		}
		$features = array();
		foreach ( $xpath->query( "//div[contains(@class,'ty-product-feature')]" ) as $feature ) {
			$label = $xpath->query( ".//div[contains(@class,'ty-product-feature__label')]", $feature )->item( 0 );
			$value = $xpath->query( ".//div[contains(@class,'ty-product-feature__value')]", $feature )->item( 0 );
			if ( $label && $value ) $features[] = array( 'name' => rtrim( self::clean_text( $label->textContent ), ':' ), 'text' => self::clean_text( $value->textContent ), 'html' => self::inner_html( $value ) );
		}
		$options = array();
		$option_root = $xpath->query( "//*[@id='product_options_update_" . $data['product_id'] . "']" )->item( 0 );
		if ( $option_root ) {
			foreach ( $xpath->query( './/select', $option_root ) as $select ) {
				$items = array(); foreach ( $xpath->query( './/option', $select ) as $option ) $items[] = array( 'value' => $option->getAttribute( 'value' ), 'label' => self::clean_text( $option->textContent ), 'selected' => $option->hasAttribute( 'selected' ), 'disabled' => $option->hasAttribute( 'disabled' ) );
				$options[] = array( 'type' => 'select', 'name' => $select->getAttribute( 'name' ), 'id' => $select->getAttribute( 'id' ), 'values' => $items );
			}
			foreach ( $xpath->query( ".//input[@type='radio' or @type='checkbox']", $option_root ) as $input ) $options[] = array( 'type' => $input->getAttribute( 'type' ), 'name' => $input->getAttribute( 'name' ), 'id' => $input->getAttribute( 'id' ), 'value' => $input->getAttribute( 'value' ), 'checked' => $input->hasAttribute( 'checked' ) );
		}
		$content = $xpath->query( "//*[@id='content_description']" )->item( 0 );
		$reviews = $xpath->query( "//*[@id='content_discussion' or contains(@class,'ty-discussion')]" )->item( 0 );
		$title = $xpath->query( '//title' )->item( 0 ); $canonical = $xpath->query( "//link[contains(concat(' ',normalize-space(@rel),' '),' canonical ')]" )->item( 0 );
		$price = $xpath->query( "//*[starts-with(@id,'line_discounted_price_')]" )->item( 0 );
		$stock = $xpath->query( "//div[contains(@class,'et-grid-stock')]" )->item( 0 );
		$quantity = $xpath->query( "//input[contains(@class,'cm-amount') and contains(@name,'[amount]')]" )->item( 0 );
		return array(
			'extracted_via' => 'authenticated_cs_cart_html', 'source_url' => $url,
			'identity' => array( 'product_id' => $data['product_id'], 'sku' => $data['sku'], 'product_type' => $data['product_type'] ),
			'document' => array( 'page_title' => $title ? self::clean_text( $title->textContent ) : '', 'canonical' => $canonical ? self::absolute_url( $canonical->getAttribute( 'href' ) ) : '', 'meta' => $meta, 'breadcrumbs' => $breadcrumbs ),
			'commerce' => array( 'price_text' => $price ? self::clean_text( $price->textContent ) : '', 'regular_price' => $data['regular_price'], 'sale_price' => $data['sale_price'], 'currency' => $data['currency'], 'stock_text' => $stock ? self::clean_text( $stock->textContent ) : '', 'stock_status' => $data['stock_status'], 'quantity_default' => $quantity ? $quantity->getAttribute( 'value' ) : '', 'quantity_min' => $quantity ? $quantity->getAttribute( 'data-ca-min-qty' ) : '', 'quantity_max' => $quantity ? $quantity->getAttribute( 'max' ) : '' ),
			'product_content' => array( 'description_html' => $content ? self::inner_html( $content ) : '', 'features' => $features, 'options' => $options, 'images' => $images, 'reviews_html' => $reviews ? self::inner_html( $reviews ) : '' ),
			'json_ld_documents' => $json_documents,
		);
	}

    private static function extract_product_urls_from_page( $html ) {
        $dom = new DOMDocument();
        libxml_use_internal_errors( true );
        $dom->loadHTML( mb_convert_encoding( $html, 'HTML-ENTITIES', 'UTF-8' ) );
        $xpath = new DOMXPath( $dom );
        $urls = array();
        $nodes = $xpath->query( "//a[contains(@class, 'product-title')]" );
        foreach ( $nodes as $a ) {
            $href = $a->getAttribute( 'href' );
            if ( $href ) {
                if ( strpos( $href, 'http' ) !== 0 ) $href = self::SUPPLIER_URL . '/' . ltrim( $href, '/' );
                $urls[] = $href;
            }
        }
        return array_values( array_unique( $urls ) );
    }

	private static function parse_price( $text ) {
		$text = str_replace( array( '۰','۱','۲','۳','۴','۵','۶','۷','۸','۹', '٬', ',', "\xC2\xA0" ), array( '0','1','2','3','4','5','6','7','8','9', '', '', '' ), (string) $text );
		return preg_match( '/\d+/', $text, $match ) ? (int) $match[0] : 0;
	}

	private static function clean_text( $text ) {
		$text = html_entity_decode( wp_strip_all_tags( (string) $text ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		return trim( preg_replace( '/\s+/u', ' ', $text ) );
	}

	private static function inner_html( $node ) {
		$html = ''; if ( ! $node ) return $html;
		foreach ( $node->childNodes as $child ) $html .= $node->ownerDocument->saveHTML( $child );
		return trim( $html );
	}

	private static function absolute_url( $url ) {
		$url = trim( (string) $url ); if ( '' === $url ) return '';
		if ( 0 === strpos( $url, '//' ) ) return 'https:' . $url;
		if ( preg_match( '#^https?://#i', $url ) ) return $url;
		return self::SUPPLIER_URL . '/' . ltrim( $url, '/' );
	}

    private static function parse_sitemap_categories( $html ) {
        $categories = array();
        if ( preg_match_all( '/<a\s[^>]*href="[^"]*category_id=(\d+)[^"]*"[^>]*>([^<]*)<\/a>/i', $html, $matches, PREG_SET_ORDER ) ) {
            foreach ( $matches as $match ) {
                $categories[] = array(
                    'id'   => $match[1],
                    'name' => trim( $match[2] )
                );
            }
        }
        return $categories;
    }

    private static function extract_cookies( $response ) {
        $cookies = wp_remote_retrieve_cookies( $response );
        $out = array();
        foreach ( $cookies as $c ) {
            if ( is_object( $c ) && isset( $c->name ) ) $out[ $c->name ] = $c->value;
        }
        return $out;
    }

	private static function is_valid_product_url( $url ) {
		$parts = wp_parse_url( $url );
		if ( ! is_array( $parts ) || 'https' !== strtolower( isset( $parts['scheme'] ) ? $parts['scheme'] : '' ) ) return false;
		$host = strtolower( rtrim( isset( $parts['host'] ) ? $parts['host'] : '', '.' ) );
		if ( ! in_array( $host, array( 'zippogift.ir', 'www.zippogift.ir' ), true ) ) return false;
		parse_str( isset( $parts['query'] ) ? $parts['query'] : '', $query );
		return isset( $query['dispatch'], $query['product_id'] ) && 'products.view' === $query['dispatch'] && ctype_digit( (string) $query['product_id'] );
	}

    private static function merge_cookies( $old, $new ) {
        foreach ( $new as $k => $v ) $old[ $k ] = $v;
        return $old;
    }

    private static function format_cookies_string( $cookies ) {
        $pairs = array();
        foreach ( $cookies as $n => $v ) $pairs[] = "$n=$v";
        return implode( '; ', $pairs );
    }

    /* ========== Optional Admin UI ========== */
    public function __construct() {
        add_action( 'admin_menu', array( $this, 'add_admin_menu' ) );
    }

    public function add_admin_menu() {
        add_submenu_page(
            'edit.php?post_type=source_profile',
            'ZippoGift Extractor',
            'ZippoGift Extractor',
            'manage_options',
            'zippogift-extractor',
            array( $this, 'render_admin_page' )
        );
    }

    public function render_admin_page() {
        $url = '';
        $log = '';
        if ( isset( $_POST['product_url'] ) && check_admin_referer( 'zippogift_extractor_action', 'zippogift_extractor_nonce' ) ) {
            $url = esc_url_raw( wp_unslash( $_POST['product_url'] ) );
            $user = sanitize_text_field( wp_unslash( $_POST['auth_username'] ) );
            $pass = sanitize_text_field( wp_unslash( $_POST['auth_password'] ) );

            if ( empty( $url ) || empty( $user ) || empty( $pass ) ) {
                echo '<div class="notice notice-error"><p>All fields are required.</p></div>';
            } elseif ( ! filter_var( $url, FILTER_VALIDATE_URL ) ) {
                echo '<div class="notice notice-error"><p>Invalid URL.</p></div>';
            } else {
                self::set_credentials( $user, $pass );
                $data = self::extract( $url );
                $log = self::get_log();

                if ( $data === false || empty( $data['title'] ) ) {
                    echo '<div class="notice notice-error"><p>Could not extract product data. Check log below.</p></div>';
                } else {
                    $this->display_product_data( $data );
                }
            }
        }
        ?>
        <div class="wrap">
            <h1>ZippoGift Product Extractor</h1>
            <form method="post" action="">
                <?php wp_nonce_field( 'zippogift_extractor_action', 'zippogift_extractor_nonce' ); ?>
                <table class="form-table">
                    <tr>
                        <th scope="row"><label for="product_url">Product Page URL</label></th>
                        <td>
                            <input type="url" id="product_url" name="product_url"
                                   value="<?php echo esc_attr($url); ?>"
                                   placeholder="https://zippogift.ir/..." size="60" required />
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="auth_username">Username</label></th>
                        <td>
                            <input type="text" id="auth_username" name="auth_username"
                                   value="<?php echo isset($_POST['auth_username']) ? esc_attr($_POST['auth_username']) : ''; ?>"
                                   placeholder="Your login username" size="30" required />
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="auth_password">Password</label></th>
                        <td>
                            <input type="password" id="auth_password" name="auth_password"
                                   value=""
                                   autocomplete="current-password"
                                   placeholder="Your login password" size="30" required />
                        </td>
                    </tr>
                </table>
                <?php submit_button( 'Extract Product Data' ); ?>
            </form>

            <?php if ( $log ): ?>
                <div class="notice notice-info" style="padding:15px;">
                    <h2>Extraction Log</h2>
                    <pre style="background:#f1f1f1; padding:10px; max-height:300px; overflow:auto;"><?php echo esc_html( $log ); ?></pre>
                </div>
            <?php endif; ?>
        </div>
        <?php
    }

    private function display_product_data( $data ) {
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
                <span><strong>Product ID:</strong> <?php echo esc_html( $data['product_id'] ); ?></span><br>
                <span><strong>SKU:</strong> <?php echo esc_html( $data['sku'] ); ?></span>
            </div>

            <hr>

            <div class="product-content">
                <h1><?php echo esc_html( $data['title'] ); ?></h1>
                <p><strong>Excerpt:</strong> <?php echo esc_html( $data['excerpt'] ); ?></p>
                <div><strong>Content:</strong> <?php echo wp_kses_post( $data['content'] ); ?></div>
            </div>

            <hr>

            <div class="product-images">
                <div><strong>Featured Image:</strong></div>
                <?php if ( ! empty( $data['featured_image'] ) ) : ?>
                    <img src="<?php echo esc_url( $data['featured_image'] ); ?>" alt="Featured">
                <?php else : ?>
                    <p>No featured image.</p>
                <?php endif; ?>

                <p><strong>Gallery:</strong></p>
                <div class="gallery-images">
                    <?php if ( ! empty( $data['gallery_images'] ) ) : ?>
                        <?php foreach ( $data['gallery_images'] as $img ) : ?>
                            <img src="<?php echo esc_url( $img ); ?>" alt="Gallery">
                        <?php endforeach; ?>
                    <?php else : ?>
                        <p>No gallery images.</p>
                    <?php endif; ?>
                </div>
            </div>

            <hr>

            <div class="product-pricing-stock">
                <p><strong>Currency:</strong> <?php echo esc_html( $data['currency'] ); ?></p>
                <p><strong>Regular Price:</strong> <?php echo esc_html( number_format( $data['regular_price'] ) ); ?></p>
                <p><strong>Sale Price:</strong> <?php echo $data['sale_price'] ? esc_html( number_format( $data['sale_price'] ) ) : '-'; ?></p>
                <p><strong>Stock Status:</strong> <?php echo esc_html( $data['stock_status'] ); ?></p>
                <p><strong>Stock Quantity:</strong> <?php echo null !== $data['stock_quantity'] ? esc_html( $data['stock_quantity'] ) : '-'; ?></p>
            </div>

            <hr>

            <div class="product-taxonomies">
                <p><strong>Categories:</strong></p>
                <?php if ( ! empty( $data['categories'] ) ) : ?>
                    <ul>
                        <?php foreach ( $data['categories'] as $cat ) : ?>
                            <li><?php echo esc_html( $cat ); ?></li>
                        <?php endforeach; ?>
                    </ul>
                <?php else : ?>
                    <p>No categories.</p>
                <?php endif; ?>

                <p><strong>Tags:</strong></p>
                <?php if ( ! empty( $data['tags'] ) ) : ?>
                    <ul>
                        <?php foreach ( $data['tags'] as $tag ) : ?>
                            <li><?php echo esc_html( $tag ); ?></li>
                        <?php endforeach; ?>
                    </ul>
                <?php else : ?>
                    <p>No tags.</p>
                <?php endif; ?>
            </div>

            <hr>

            <div class="product-type">
                <p><strong>Product Type:</strong> <?php echo esc_html( $data['product_type'] ); ?></p>
            </div>

            <hr>

            <div class="product-attributes">
                <h2>Attributes</h2>
                <?php if ( ! empty( $data['attributes'] ) ) : ?>
                    <table>
                        <thead>
                            <tr>
                                <th>Name</th>
                                <th>Value(s)</th>
                                <th>For Variations?</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ( $data['attributes'] as $attr ) : ?>
                                <tr>
                                    <td><?php echo esc_html( $attr['name'] ); ?></td>
                                    <td><?php echo esc_html( implode( ', ', $attr['values'] ) ); ?></td>
                                    <td><?php echo $attr['used_for_variations'] ? 'Yes' : 'No'; ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php else : ?>
                    <p>No attributes.</p>
                <?php endif; ?>
            </div>

            <hr>

            <div class="product-variations">
                <h2>Variations</h2>
                <?php if ( $data['product_type'] === 'variable' && ! empty( $data['variations'] ) ) : ?>
                    <table>
                        <thead>
                            <tr>
                                <th>Summary</th>
                                <th>Attributes Map</th>
                                <th>SKU</th>
                                <th>Price</th>
                                <th>Sale Price</th>
                                <th>Stock</th>
                                <th>Qty</th>
                                <th>Image</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ( $data['variations'] as $var ) : ?>
                                <tr>
                                    <td><?php echo esc_html( $var['attributes_summary'] ); ?></td>
                                    <td><?php echo esc_html( json_encode( $var['attributes_map'] ) ); ?></td>
                                    <td><?php echo esc_html( $var['sku'] ); ?></td>
                                    <td><?php echo esc_html( number_format( $var['regular_price'] ) ); ?></td>
                                    <td><?php echo $var['sale_price'] ? esc_html( number_format( $var['sale_price'] ) ) : '-'; ?></td>
                                    <td><?php echo esc_html( $var['stock_status'] ); ?></td>
                                    <td><?php echo null !== $var['stock_quantity'] ? esc_html( $var['stock_quantity'] ) : '-'; ?></td>
                                    <td>
                                        <?php if ( ! empty( $var['image'] ) ) : ?>
                                            <img src="<?php echo esc_url( $var['image'] ); ?>" style="width:40px;height:40px;">
                                        <?php else : ?>
                                            -
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php else : ?>
                    <p>Product is simple; no variations.</p>
                <?php endif; ?>
            </div>
			<?php $this->display_source_data_sections( isset( $data['source_data'] ) && is_array( $data['source_data'] ) ? $data['source_data'] : array() ); ?>
        </div>
        <?php
    }

	private function display_source_data_sections( $source ) {
		if ( empty( $source ) ) return;
		$sections = array(
			'هویت و منبع' => array( 'روش استخراج' => $source['extracted_via'] ?? '', 'URL منبع' => $source['source_url'] ?? '', 'هویت محصول' => $source['identity'] ?? array() ),
			'اطلاعات صفحه و SEO' => $source['document'] ?? array(),
			'قیمت، موجودی و سفارش' => $source['commerce'] ?? array(),
			'توضیحات و مشخصات کامل' => array( 'description_html' => $source['product_content']['description_html'] ?? '', 'features' => $source['product_content']['features'] ?? array() ),
			'گزینه‌های محصول' => $source['product_content']['options'] ?? array(),
			'تصاویر و metadata کامل' => $source['product_content']['images'] ?? array(),
			'دیدگاه‌ها و امتیازها' => $source['product_content']['reviews_html'] ?? '',
			'تمام اسناد JSON-LD صفحه' => $source['json_ld_documents'] ?? array(),
		);
		echo '<style>.zg-source-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(380px,1fr));gap:14px;margin-top:16px}.zg-source-card{background:#fff;border:1px solid #ccd0d4;border-radius:7px;padding:14px;overflow:auto}.zg-source-card pre{white-space:pre-wrap;word-break:break-word;max-height:520px;overflow:auto;background:#f6f7f7;padding:10px}.zg-source-card table{width:100%}.zg-source-card th,.zg-source-card td{padding:7px;border-bottom:1px solid #eee;text-align:right;vertical-align:top}.zg-source-card th{width:28%}</style>';
		echo '<hr><h2>تمام اطلاعات قابل استخراج محصول</h2><div class="zg-source-grid">';
		foreach ( $sections as $heading => $value ) {
			echo '<section class="zg-source-card"><h3>' . esc_html( $heading ) . '</h3>';
			if ( is_array( $value ) && self::is_assoc( $value ) ) { echo '<table><tbody>'; foreach ( $value as $key => $item ) echo '<tr><th>' . esc_html( (string) $key ) . '</th><td>' . self::render_source_value( $item ) . '</td></tr>'; echo '</tbody></table>'; }
			else echo '<pre>' . esc_html( wp_json_encode( $value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT ) ) . '</pre>';
			echo '</section>';
		}
		echo '<section class="zg-source-card" style="grid-column:1/-1"><h3>JSON کامل source_data</h3><pre>' . esc_html( wp_json_encode( $source, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT ) ) . '</pre></section></div>';
	}

	private static function render_source_value( $value ) {
		if ( is_bool( $value ) ) return $value ? 'بله' : 'خیر';
		if ( null === $value || '' === $value ) return '—';
		if ( is_array( $value ) || is_object( $value ) ) return '<pre>' . esc_html( wp_json_encode( $value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT ) ) . '</pre>';
		if ( preg_match( '#^https?://#i', (string) $value ) ) return '<a href="' . esc_url( $value ) . '" target="_blank" rel="noopener">' . esc_html( $value ) . '</a>';
		return nl2br( esc_html( (string) $value ) );
	}

	private static function is_assoc( $array ) {
		return is_array( $array ) && array() !== $array && array_keys( $array ) !== range( 0, count( $array ) - 1 );
	}
}
