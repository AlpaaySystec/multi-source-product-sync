<?php
/**
 * Product Importer / Updater – نهایی
 *
 * وابستگی‌ها: class-sync-logger.php, class-source-profile-manager.php
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// ── پشتیبانی کامل از تمام پسوندهای تصویری ──
add_filter( 'upload_mimes', function( $mimes ) {
    $mimes['avif'] = 'image/avif';
    $mimes['jfif'] = 'image/jpeg';
    $mimes['webp'] = 'image/webp';
    $mimes['bmp']  = 'image/bmp';
    $mimes['tiff'] = 'image/tiff';
    $mimes['svg']  = 'image/svg+xml';
    return $mimes;
} );

add_filter( 'wp_check_filetype_and_ext', function( $data, $file, $filename, $mimes ) {
    // در صورت نیاز می‌توانید این فیلتر را پاک کنید یا نگه دارید – در نسخهٔ جدید ما تمام کارها را در process_image انجام می‌دهیم.
    // اما برای امنیت بیشتر، اگر پسوند avif یا jfif شناسایی نشود، با MIME صحیح جایگزین می‌کنیم.
    $ext = pathinfo( $filename, PATHINFO_EXTENSION );
    if ( $ext === 'avif' && empty( $data['type'] ) ) {
        $data['type'] = 'image/avif';
        $data['ext']  = 'avif';
    }
    if ( $ext === 'jfif' && empty( $data['type'] ) ) {
        $data['type'] = 'image/jpeg';
        $data['ext']  = 'jfif';
    }
    return $data;
}, 10, 4 );

// ── جلوگیری از ایجاد نسخه‌های Scaled برای تصاویر بزرگ ──
add_filter( 'big_image_size_threshold', '__return_false' );



class Product_Importer {

    /**
     * نقطهٔ ورود اصلی.
     *
     * @param array  $dto        آرایهٔ ProductDTO استاندارد.
     * @param int    $profile_id شناسهٔ پروفایل منبع.
     * @param string $source_url لینک محصول در سایت مبدأ (اختیاری).
     * @return array|WP_Error    آرایه شامل 'product_id' و 'action' (created|updated) یا خطا.
     */
    public static function import( $dto, $profile_id, $source_url = '' ) {
        if ( ! class_exists( 'Source_Profile_Manager' ) ) {
            return new WP_Error( 'missing_class', 'کلاس Source_Profile_Manager یافت نشد.' );
        }
        $profile = Source_Profile_Manager::get_profile( $profile_id );
        if ( empty( $profile['extractor_id'] ) ) {
            return new WP_Error( 'invalid_profile', 'پروفایل نامعتبر یا ناقص است.' );
        }

        $author_id = intval( $profile['product_author'] ?? 0 );

        // تولید SKU فقط در صورتی که پروفایل این قابلیت را فعال کرده باشد. SKU دیگر برای
        // تشخیص محصول موجود استفاده نمی‌شود؛ این کار اکنون بر عهدهٔ جدول نگاشت (Product_Mapper) است.
        $sku = ! empty( $profile['use_sku_pattern'] ) ? self::generate_sku( $dto['title'], $dto['categories'], $profile['sku_pattern'], $dto ) : '';

        // تشخیص محصول موجود از طریق جدول نگاشت لینک مبدأ ↔ شناسه محصول (نه SKU)
        $existing_id = ! empty( $source_url ) && class_exists( 'Product_Mapper' )
            ? Product_Mapper::get_product_id( $profile_id, $source_url )
            : null;

        // حالت «تطبیق با نسخهٔ قبلی»: اگر این لینک هنوز در جدول نگاشت ثبت نشده، ولی محصولی با
        // همین SKوی تولیدی از قبل در سایت موجود است (باقی‌مانده از نسخهٔ قدیمی افزونه که بر
        // اساس SKU کار می‌کرد)، آن را به‌جای ساخت محصول تکراری، همان محصول موجود در نظر بگیر
        // و بلافاصله در جدول نگاشت هم ثبتش کن تا از این پس از همان مسیر عادی شناسایی شود.
        if ( ! $existing_id && ! empty( $sku ) && ! empty( $profile['match_legacy_sku'] ) ) {
            $legacy_id = wc_get_product_id_by_sku( $sku );
            if ( $legacy_id ) {
                $existing_id = $legacy_id;
                if ( ! empty( $source_url ) && class_exists( 'Product_Mapper' ) ) {
                    Product_Mapper::set_mapping( $profile_id, $source_url, $legacy_id, $dto['title'] ?? '' );
                }
                Sync_Logger::log( sprintf( 'تطبیق با محصول نسخهٔ قبلی: SKU=%s با محصول موجود #%d نگاشت شد.', $sku, $legacy_id ), 'success', array( 'product_id' => $legacy_id ) );
            }
        }

        $action = $existing_id ? 'updated' : 'created';

        if ( $existing_id ) {
			$result = self::update_existing_product( $existing_id, $dto, $profile, $source_url, $profile_id, $author_id );
            if ( is_wp_error( $result ) ) {
                Sync_Logger::log( sprintf( 'خطا در بروزرسانی محصول SKU=%s: %s', $sku, $result->get_error_message() ), 'error' );
                return $result;
            }

            $changed = $result['changed'] ?? array();
            $msg = sprintf( 'بروزرسانی محصول: SKU=%s', $sku );
            if ( empty( $changed ) ) {
                $msg .= ' | چیزی تغییر نکرد.';
            } else {
                $msg .= ' | ' . implode( '؛ ', $changed ) . ' بروزرسانی شدند.';
            }
            Sync_Logger::log( $msg, 'success', array( 'product_id' => $existing_id ) );
            return array( 'product_id' => $existing_id, 'action' => $action, 'has_changes' => ! empty( $changed ) );
        }

        $result = self::create_new_product( $dto, $profile, $sku, $source_url, $profile_id, $author_id );
        if ( is_wp_error( $result ) ) {
            Sync_Logger::log( sprintf( 'خطا در ایجاد محصول SKU=%s: %s', $sku, $result->get_error_message() ), 'error' );
            return $result;
        }

        $product_id = $result['product_id'];
        $fields_set = $result['fields_set'] ?? array();
        $msg = sprintf( 'ایجاد محصول: SKU=%s', $sku );
        if ( ! empty( $fields_set ) ) {
            $msg .= ' | ' . implode( '؛ ', $fields_set ) . ' مقداردهی شدند.';
        } else {
            $msg .= ' | بدون فیلد خاصی ایجاد شد.';
        }
        Sync_Logger::log( $msg, 'success', array( 'product_id' => $product_id ) );
        return array( 'product_id' => $product_id, 'action' => $action, 'has_changes' => true );
    }

    /* ------------------------------------------------------------------ */
    /*  ایجاد محصول جدید                                                   */
    /* ------------------------------------------------------------------ */

	private static function create_new_product( $dto, $profile, $sku, $source_url, $profile_id, $author_id = 0 ) {
        $is_variable = ( 'variable' === $dto['product_type'] );
        $product = $is_variable ? new WC_Product_Variable() : new WC_Product_Simple();

        $product->set_sku( $sku );
        $product->set_name( $dto['title'] );
        $product->set_status( $profile['new_product_status'] );

        $allowed = (array) $profile['first_import_fields'];
        $fields_set = array();

        $fields_set[] = 'عنوان';

        if ( in_array( 'content', $allowed ) && ! empty( $dto['content'] ) ) {
            $product->set_description( $dto['content'] );
            $fields_set[] = 'توضیحات اصلی';
        }
        if ( in_array( 'excerpt', $allowed ) && ! empty( $dto['excerpt'] ) ) {
            $product->set_short_description( $dto['excerpt'] );
            $fields_set[] = 'توضیحات مختصر';
        }

        // بازه‌های قیمتی می‌توانند ضریب/مقدار ثابت اختصاصی خودشان را برای «محصول جدید» تحمیل کنند.
        $price_override = self::get_price_override_for_data( $dto, $profile );

        self::set_price_and_stock( $product, $dto, $profile, true, $allowed, $price_override );
        if ( in_array( 'regular_price', $allowed ) && $dto['regular_price'] > 0 ) {
            $fields_set[] = 'قیمت';
        }
        if ( in_array( 'sale_price', $allowed ) && $dto['sale_price'] ) {
            $fields_set[] = 'قیمت ویژه';
        }
        if ( in_array( 'stock_status', $allowed ) || in_array( 'stock_quantity', $allowed ) ) {
            $fields_set[] = 'موجودی';
        }

        if ( in_array( 'featured_image', $allowed ) && ! empty( $dto['featured_image'] ) ) {
            $attach_id = self::process_image( $dto['featured_image'], $author_id );
            if ( ! is_wp_error( $attach_id ) ) {
                $product->set_image_id( $attach_id );
                $fields_set[] = 'عکس اصلی';
            } else {
                Sync_Logger::log( 'خطا در دانلود تصویر شاخص: ' . $attach_id->get_error_message(), 'warning' );
            }
        }
        if ( in_array( 'gallery_images', $allowed ) && ! empty( $dto['gallery_images'] ) ) {
            $gallery_ids = array();
            foreach ( $dto['gallery_images'] as $img_url ) {
                $attach_id = self::process_image( $img_url, $author_id );
                if ( ! is_wp_error( $attach_id ) ) {
                    $gallery_ids[] = $attach_id;
                }
            }
            if ( ! empty( $gallery_ids ) ) {
                $product->set_gallery_image_ids( $gallery_ids );
                $fields_set[] = count( $gallery_ids ) . ' عکس گالری';
            }
        }

        if ( in_array( 'categories', $allowed ) ) {
            $mapped_cats = self::apply_category_mapping( $dto['categories'], $profile );
            if ( ! empty( $mapped_cats ) ) {
                $product->set_category_ids( $mapped_cats );
                $fields_set[] = 'دسته‌بندی';
            }
        }

        if ( in_array( 'tags', $allowed ) && ! empty( $dto['tags'] ) ) {
            $tag_ids = self::maybe_create_tags( $dto['tags'] );
            if ( ! empty( $tag_ids ) ) {
                $product->set_tag_ids( $tag_ids );
                $fields_set[] = 'برچسب‌ها';
            }
        }

        $attributes_processed = array();
        if ( in_array( 'attributes', $allowed ) ) {
            $attributes_processed = self::prepare_attributes( $dto['attributes'], $profile );
            if ( ! $is_variable ) {
                $product->set_attributes( $attributes_processed );
            }
            if ( ! empty( $attributes_processed ) ) {
                $fields_set[] = 'ویژگی‌ها';
            }
        }

        if ( ! empty( $profile['shipping_class'] ) ) {
            $product->set_shipping_class_id( (int) $profile['shipping_class'] );
            $fields_set[] = 'کلاس ارسال';
        }

        $product_id = $product->save();

        // ثبت نگاشت لینک مبدأ ↔ شناسه محصول (جایگزین وابستگی قبلی به SKU/متای _source_url)
        if ( ! empty( $source_url ) && class_exists( 'Product_Mapper' ) ) {
            Product_Mapper::set_mapping( $profile_id, $source_url, $product_id, $dto['title'] ?? '' );
        }

        // تنظیم نویسندهٔ محصول
        if ( $author_id ) {
            wp_update_post( array(
                'ID'          => $product_id,
                'post_author' => $author_id,
            ) );
        }

        update_post_meta( $product_id, '_source_profile_id', $profile_id );

        // تنظیم post_parent تصاویر
        $feat_id = $product->get_image_id();
        if ( $feat_id ) {
            self::set_attachment_parent( $feat_id, $product_id );
        }
        foreach ( $product->get_gallery_image_ids() as $g_id ) {
            self::set_attachment_parent( $g_id, $product_id );
        }

        if ( $is_variable ) {
            if ( in_array( 'attributes', $allowed ) && ! empty( $attributes_processed ) ) {
                $product->set_attributes( $attributes_processed );
                $product->save();
            }
            if ( in_array( 'variations', $allowed ) && ! empty( $dto['variations'] ) ) {
                self::create_variations( $product_id, $dto['variations'], $dto['attributes'], $profile, $author_id, $allowed );
                $fields_set[] = 'متغیرها (' . count( $dto['variations'] ) . ' عدد)';
            }
        }

        return array(
            'product_id' => $product_id,
            'fields_set' => array_unique( $fields_set ),
        );
    }

    /* ------------------------------------------------------------------ */
    /*  بروزرسانی محصول موجود                                              */
    /* ------------------------------------------------------------------ */

	private static function update_existing_product( $product_id, $dto, $profile, $source_url, $profile_id, $author_id) {
        $product = wc_get_product( $product_id );
        if ( ! $product ) {
            return new WP_Error( 'product_not_found', 'محصول یافت نشد.' );
        }

        $update_fields = (array) $profile['update_fields'];
        if ( empty( $update_fields ) ) {
			$guard_changed = self::enforce_zero_price_stock_guard( $product, $dto );
			$child_changes = $product->is_type( 'variable' ) && ! empty( $dto['variations'] )
				? self::enforce_existing_zero_price_variations( $product->get_id(), $dto['variations'], $profile )
				: 0;
			if ( $guard_changed ) {
				$product->save();
			}
			$guard_changes = array();
			if ( $guard_changed ) $guard_changes[] = 'موجودی';
			if ( $child_changes ) $guard_changes[] = 'وارییشن‌ها';
			return array( 'changed' => $guard_changes );
        }

        $is_variable = $product->is_type( 'variable' );
        $changed = array();

        // تنظیم نویسنده در صورت نیاز
		$current_author = get_post_field( 'post_author', $product->get_id() );
        if ( $current_author != $author_id ) {
            wp_update_post( array(
                'ID'          => $product_id,
                'post_author' => $author_id,
            ) );
            $changed[] = 'نویسنده';
        }

        // تنظیم کلاس ارسال طبق پروفایل، در صورت نیاز
        if ( ! empty( $profile['shipping_class'] ) ) {
            $target_shipping_class = (int) $profile['shipping_class'];
            if ( (int) $product->get_shipping_class_id() !== $target_shipping_class ) {
                $product->set_shipping_class_id( $target_shipping_class );
                $changed[] = 'کلاس ارسال';
            }
        }

        // ── تبدیل نوع محصول در صورت نیاز ──
        if ( $dto['product_type'] !== $product->get_type() ) {
            $product = self::convert_product_type( $product, $dto, $profile, $source_url, $author_id );
            if ( is_wp_error( $product ) ) {
                return $product;
            }
            $is_variable = $product->is_type( 'variable' );
            $changed[] = 'نوع محصول (تبدیل به ' . ( $is_variable ? 'متغیر' : 'ساده' ) . ')';
        }

        // ── فیلدهای متنی ──
        // ── فیلدهای متنی (با نرمال‌سازی HTML) ──
        if ( in_array( 'title', $update_fields ) && $product->get_name() !== $dto['title'] ) {
            $product->set_name( $dto['title'] );
            $changed[] = 'عنوان';
        }
        if ( in_array( 'content', $update_fields ) ) {
            $old_desc = wp_kses_post( $product->get_description() );
            $new_desc = wp_kses_post( $dto['content'] );
            if ( $old_desc !== $new_desc ) {
                $product->set_description( $dto['content'] );
                $changed[] = 'توضیحات اصلی';
            }
        }
        if ( in_array( 'excerpt', $update_fields ) ) {
            $old_excerpt = wp_kses_post( $product->get_short_description() );
            $new_excerpt = wp_kses_post( $dto['excerpt'] );
            if ( $old_excerpt !== $new_excerpt ) {
                $product->set_short_description( $dto['excerpt'] );
                $changed[] = 'توضیحات مختصر';
            }
        }

        // ── قیمت و موجودی ──
        // بازه‌های قیمتی («قوانین قیمت‌گذاری یکپارچه») روی بروزرسانی محصولات موجود هم اثر دارند
        // (طبق قیمت مؤثر فعلیِ محصول در سایت مبدأ)، دقیقاً مثل حالت ایجاد محصول جدید.
        $price_override = self::get_price_override_for_data( $dto, $profile );

        $old_regular = $product->get_regular_price();
        $old_sale    = $product->get_sale_price();
        $old_stock_status_raw = $product->get_stock_status();
        $old_stock_qty        = $product->get_stock_quantity();
        $old_manage_stock     = $product->get_manage_stock();

        // تبدیل وضعیت موجودی داخلی ووکامرس به فرمت استاندارد DTO (با خط تیره)
        if ( 'instock' === $old_stock_status_raw ) {
            $old_stock_status = 'in-stock';
        } elseif ( 'outofstock' === $old_stock_status_raw ) {
            $old_stock_status = 'out-of-stock';
        } else {
            $old_stock_status = $old_stock_status_raw;
        }

        $new_regular = in_array( 'regular_price', $update_fields ) ? self::apply_price_transform( $dto['regular_price'], $profile, $price_override, 'regular' ) : $old_regular;
        $new_sale    = in_array( 'sale_price', $update_fields ) ? ( $dto['sale_price'] ? self::apply_price_transform( $dto['sale_price'], $profile, $price_override, 'sale' ) : '' ) : $old_sale;
        $set_stock_status   = in_array( 'stock_status', $update_fields, true );
        $set_stock_quantity = in_array( 'stock_quantity', $update_fields, true );
        $new_stock_status   = $set_stock_status ? $dto['stock_status'] : $old_stock_status;
        $new_stock_qty      = $old_stock_qty;
        $new_manage_stock   = $old_manage_stock;
        if ( $set_stock_quantity ) {
            $incoming_manage = array_key_exists( 'manage_stock', $dto ) ? $dto['manage_stock'] : null;
            if ( true === $incoming_manage && null !== $dto['stock_quantity'] ) {
                $new_manage_stock = true;
                $new_stock_qty = $dto['stock_quantity'];
            } elseif ( false === $incoming_manage ) {
                $new_manage_stock = false;
                $new_stock_qty = null;
            }
        }

        // نرمال‌سازی مقادیر موجودی برای مقایسهٔ منصفانه
        $norm_old_qty = ( $old_manage_stock && is_numeric( $old_stock_qty ) && $old_stock_qty > 0 ) ? (int) $old_stock_qty : -1;
        $norm_new_qty = ( $new_manage_stock && $new_stock_qty > 0 ) ? (int) $new_stock_qty : -1;

        $price_changed = ( (string) $new_regular !== (string) $old_regular ) || ( (string) $new_sale !== (string) $old_sale );
        $stock_changed = ( $set_stock_status && $new_stock_status !== $old_stock_status )
			|| ( $set_stock_quantity && ( $norm_new_qty !== $norm_old_qty || $new_manage_stock !== $old_manage_stock ) )
			|| ( ! self::has_positive_effective_source_price( $dto )
				&& ( 'outofstock' !== $old_stock_status_raw
					|| ( $old_manage_stock && ( ! is_numeric( $old_stock_qty ) || 0.0 !== (float) $old_stock_qty ) ) ) );

        if ( $price_changed ) $changed[] = 'قیمت';
        if ( $stock_changed ) $changed[] = 'موجودی';

        self::set_price_and_stock( $product, $dto, $profile, false, $update_fields, $price_override );

		// ----- تصاویر (عکس اصلی) -----
		if ( in_array( 'featured_image', $update_fields ) && ! empty( $dto['featured_image'] ) ) {
			$current_image_id = $product->get_image_id();
			$existing_source = $current_image_id ? get_post_meta( $current_image_id, '_source_image_url', true ) : '';

			// اگر URL جدید با متای عکس فعلی یکی است، دوباره دانلود نکن
			if ( $existing_source === $dto['featured_image'] ) {
				$new_attach_id = $current_image_id;
			} else {
				$new_attach_id = self::process_image( $dto['featured_image'], $author_id );
			}

			if ( ! is_wp_error( $new_attach_id ) && $current_image_id !== $new_attach_id ) {
				$product->set_image_id( $new_attach_id );
				$changed[] = 'عکس اصلی';
			}
		}

		// ----- تصاویر (گالری) -----
		if ( in_array( 'gallery_images', $update_fields ) && ! empty( $dto['gallery_images'] ) ) {
			$new_gallery_ids = array();
			foreach ( $dto['gallery_images'] as $img_url ) {
				// جستجوی تصویر موجود با متای _source_image_url
				$existing_attach = get_posts( array(
					'post_type'   => 'attachment',
					'post_status' => 'inherit',
					'meta_key'    => '_source_image_url',
					'meta_value'  => $img_url,
					'fields'      => 'ids',
					'numberposts' => 1,
				) );
				if ( ! empty( $existing_attach ) ) {
					$new_gallery_ids[] = $existing_attach[0];
				} else {
					$attach_id = self::process_image( $img_url, $author_id );
					if ( ! is_wp_error( $attach_id ) ) {
						$new_gallery_ids[] = $attach_id;
					}
				}
			}
			$old_gallery = $product->get_gallery_image_ids();
			// فقط در صورت تفاوت، گالری را به‌روز کن
			if ( $old_gallery !== $new_gallery_ids ) {
				$product->set_gallery_image_ids( $new_gallery_ids );
				$changed[] = 'عکس‌های گالری';
			}
		}

        // ── دسته‌بندی و برچسب ──
        if ( in_array( 'categories', $update_fields ) ) {
            $new_cats = self::apply_category_mapping( $dto['categories'], $profile );
            $old_cats = $product->get_category_ids();
            if ( array_diff( $old_cats, $new_cats ) || array_diff( $new_cats, $old_cats ) ) {
                $product->set_category_ids( $new_cats );
                $changed[] = 'دسته‌بندی';
            }
        }
        if ( in_array( 'tags', $update_fields ) ) {
            $new_tags = self::maybe_create_tags( $dto['tags'] );
            $old_tags = $product->get_tag_ids();
            if ( array_diff( $old_tags, $new_tags ) || array_diff( $new_tags, $old_tags ) ) {
                $product->set_tag_ids( $new_tags );
                $changed[] = 'برچسب‌ها';
            }
        }

        // ── ویژگی‌های والد (برای محصول ساده و متغیر) ──
        if ( in_array( 'attributes', $update_fields, true ) ) {
            $new_attrs = self::prepare_attributes( $dto['attributes'], $profile );
            $old_attrs = $product->get_attributes();
            if ( self::attributes_differ( $old_attrs, $new_attrs ) ) {
                $product->set_attributes( $new_attrs );
                $changed[] = 'ویژگی‌ها';
            }
        }

        // ── ذخیره تغییرات معمولی ──
        if ( ! empty( $changed ) ) {
            $product->save();
        }


        // ── به‌روزرسانی وارییشن‌ها (اگر محصول متغیر است و در update_fields وجود دارد) ──
		if ( $is_variable && ! empty( $dto['variations'] ) ) {
			if ( in_array( 'variations', $update_fields, true ) ) {
				self::sync_variations( $product_id, $dto['variations'], $dto['attributes'], $profile, $author_id, $update_fields );
				$changed[] = 'وارییشن‌ها';
			} elseif ( self::enforce_existing_zero_price_variations( $product_id, $dto['variations'], $profile ) ) {
				$changed[] = 'وارییشن‌ها';
			}
        }


        // ── به‌روزرسانی تاریخ‌ها مطابق تنظیمات پروفایل ──
        $dates_updated = false;
        if ( ! empty( $profile['update_post_date'] ) ) {
            wp_update_post( array(
                'ID'            => $product_id,
                'post_date'     => current_time( 'mysql' ),
                'post_date_gmt' => get_gmt_from_date( current_time( 'mysql' ) ),
            ) );
            $changed[] = 'تاریخ انتشار';
            $dates_updated = true;
        }
        if ( ! empty( $profile['update_post_modified'] ) ) {
            wp_update_post( array(
                'ID'                => $product_id,
                'post_modified'     => current_time( 'mysql' ),
                'post_modified_gmt' => get_gmt_from_date( current_time( 'mysql' ) ),
            ) );
            $changed[] = 'تاریخ آخرین ویرایش';
            $dates_updated = true;
        }

        // اگر تاریخ‌ها بروز شد ولی ذخیرهٔ عادی انجام نشده بود، نیازی به save مجدد نیست
        // (زیرا wp_update_post مستقیماً دیتابیس را تغییر داد)

        // نگاشت لینک مبدأ ↔ شناسه محصول از قبل موجود است؛ در صورت نیاز بازتأیید می‌شود
        // (مثلاً وقتی این محصول در ماژول تشخیص داپلیکیت به این پروفایل متصل شده باشد)
        if ( ! empty( $source_url ) && class_exists( 'Product_Mapper' ) ) {
            Product_Mapper::set_mapping( $profile_id, $source_url, $product_id, $dto['title'] ?? '' );
        }
        update_post_meta( $product_id, '_source_profile_id', $profile_id );

        // تنظیم post_parent برای تصاویر
        if ( in_array( 'featured_image', $update_fields ) ) {
            $feat_id = $product->get_image_id();
            if ( $feat_id ) self::set_attachment_parent( $feat_id, $product_id );
        }
        if ( in_array( 'gallery_images', $update_fields ) ) {
            foreach ( $product->get_gallery_image_ids() as $g_id ) {
                self::set_attachment_parent( $g_id, $product_id );
            }
        }

        return array( 'changed' => $changed );
    }

    private static function convert_product_type( $product, $dto, $profile, $source_url, $author_id = 0 ) {
        $current_type = $product->get_type();
        $new_type     = $dto['product_type'];

        if ( $current_type === $new_type ) {
            return $product;
        }

        $product_id = $product->get_id();

        // ۱. پاک‌سازی کش
        wp_cache_delete( $product_id, 'posts' );
        wc_delete_product_transients( $product_id );
        clean_object_term_cache( $product_id, 'product' );
        wp_cache_delete( $product_id, 'product_type' );

        $update_fields = (array) $profile['update_fields'];

        // ۲. تبدیل متغیر → ساده
        if ( 'variable' === $current_type && 'simple' === $new_type ) {
            // حذف تمام متغیرها
            $variation_ids = $product->get_children();
            foreach ( $variation_ids as $var_id ) {
                wp_delete_post( $var_id, true );
            }

            // تغییر نوع به simple
            wp_set_object_terms( $product_id, 'simple', 'product_type' );
            clean_object_term_cache( $product_id, 'product' );
            wp_cache_delete( $product_id, 'product_type' );

            // دریافت مجدد محصول
            $product = wc_get_product( $product_id );
            if ( ! $product ) {
                return new WP_Error( 'product_load_failed', 'خطا در بارگذاری مجدد محصول.' );
            }

            // حذف ویژگی‌ها و تنظیمات قیمت
            $product->set_attributes( array() );
            $product->set_regular_price( '' );
            $product->set_sale_price( '' );
            $product->set_price( '' );
            $product->set_stock_status( 'instock' );
            $product->set_manage_stock( false );
            $product->save();

            // بازنویسی قطعی نوع محصول
            wp_set_object_terms( $product_id, 'simple', 'product_type' );
            clean_object_term_cache( $product_id, 'product' );
            wp_cache_delete( $product_id, 'product_type' );
            $product = wc_get_product( $product_id );

            Sync_Logger::log( "محصول #{$product_id} از متغیر به ساده تبدیل شد.", 'info' );
        }
        // ۳. تبدیل ساده → متغیر
        elseif ( 'simple' === $current_type && 'variable' === $new_type ) {
            // تغییر نوع به variable
            wp_set_object_terms( $product_id, 'variable', 'product_type' );
            clean_object_term_cache( $product_id, 'product' );
            wp_cache_delete( $product_id, 'product_type' );

            $product = wc_get_product( $product_id );
            if ( ! $product ) {
                return new WP_Error( 'product_load_failed', 'خطا در بارگذاری مجدد محصول.' );
            }

            // پاک‌سازی قیمت و موجودی والد
            $product->set_regular_price( '' );
            $product->set_sale_price( '' );
            $product->set_price( '' );
            $product->set_manage_stock( false );

            // تنظیم ویژگی‌های متغیر
            if ( in_array( 'attributes', $update_fields ) ) {
                $attrs = self::prepare_attributes( $dto['attributes'], $profile );
                $product->set_attributes( $attrs );
                $product->save();
            }

            // ساخت متغیرها
            if ( in_array( 'variations', $update_fields ) && ! empty( $dto['variations'] ) ) {
                self::create_variations( $product_id, $dto['variations'], $dto['attributes'], $profile, $author_id, $update_fields );
            }

            // بازنویسی قطعی نوع محصول
            wp_set_object_terms( $product_id, 'variable', 'product_type' );
            clean_object_term_cache( $product_id, 'product' );
            wp_cache_delete( $product_id, 'product_type' );
            $product = wc_get_product( $product_id );

            Sync_Logger::log( "محصول #{$product_id} از ساده به متغیر تبدیل شد.", 'info' );
        }

        return $product;
    }

    /* ------------------------------------------------------------------ */
    /*  متد کمکی تشخیص تغییر ویژگی‌ها                                     */
    /* ------------------------------------------------------------------ */

    private static function attributes_differ( $old_attrs, $new_attrs ) {
        // تبدیل هر دو آرایه به ساختار یکسان با کلید نام ویژگی
        $normalize = function( $attrs ) {
            $result = array();
            foreach ( $attrs as $attr ) {
                if ( ! $attr instanceof WC_Product_Attribute ) {
                    continue;
                }
                $name = $attr->get_name();
                $result[ $name ] = array(
                    'options'   => $attr->get_options(),
                    'visible'   => $attr->get_visible(),
                    'variation' => $attr->get_variation(),
                );
            }
            return $result;
        };

        $old = $normalize( $old_attrs );
        $new = $normalize( $new_attrs );

        // مقایسه تعداد ویژگی‌ها
        if ( count( $old ) !== count( $new ) ) {
            return true;
        }

        // مقایسه هر ویژگی
        foreach ( $old as $name => $old_data ) {
            if ( ! isset( $new[ $name ] ) ) {
                return true;
            }
            $new_data = $new[ $name ];
            // loose comparison برای options (چون ممکن است نوع مقادیر int/string مخلوط باشد)
            if ( $old_data['options'] != $new_data['options'] ) {
                return true;
            }
            if ( $old_data['visible'] !== $new_data['visible'] ) {
                return true;
            }
            if ( $old_data['variation'] !== $new_data['variation'] ) {
                return true;
            }
        }

        return false;
    }

    /* ------------------------------------------------------------------ */
    /*  قیمت و موجودی                                                      */
    /* ------------------------------------------------------------------ */

    /**
     * قیمت مؤثر برای انتخاب rule. در محصول متغیر، کمترین قیمت مؤثر child
     * ملاک است؛ هر child هنگام ذخیره rule خودش را جداگانه انتخاب می‌کند.
     */
    private static function get_effective_price_for_rules( $data ) {
        if ( 'variable' === ( $data['product_type'] ?? '' ) && ! empty( $data['variations'] ) ) {
            $prices = array();
            foreach ( $data['variations'] as $variation ) {
                $price = ( ! empty( $variation['sale_price'] ) && $variation['sale_price'] > 0 )
                    ? $variation['sale_price']
                    : ( $variation['regular_price'] ?? 0 );
                if ( is_numeric( $price ) && $price > 0 ) {
                    $prices[] = (float) $price;
                }
            }
            if ( ! empty( $prices ) ) {
                return min( $prices );
            }
        }

        return ( ! empty( $data['sale_price'] ) && $data['sale_price'] > 0 )
            ? $data['sale_price']
            : ( $data['regular_price'] ?? 0 );
    }

	/**
	 * Whether extracted source data has a sellable effective price. Pricing
	 * transforms are intentionally ignored: a missing/zero source price must not
	 * become sellable because a rule contains a constant component.
	 */
	private static function has_positive_effective_source_price( $data ) {
		return (float) self::get_effective_price_for_rules( $data ) > 0;
	}

	/**
	 * Mandatory final safety boundary for both imports and updates.
	 *
	 * When WooCommerce stock management is active, quantity is forced to zero.
	 * In all modes the stock status is forced to outofstock. Field-selection
	 * settings cannot disable this guard.
	 *
	 * @return bool Whether the WooCommerce object was changed.
	 */
	private static function enforce_zero_price_stock_guard( &$product, $data ) {
		if ( self::has_positive_effective_source_price( $data ) ) {
			return false;
		}

		$changed = false;
		if ( $product->get_manage_stock() ) {
			$current_quantity = $product->get_stock_quantity();
			if ( ! is_numeric( $current_quantity ) || 0.0 !== (float) $current_quantity ) {
				$product->set_stock_quantity( 0 );
				$changed = true;
			}
		}

		if ( 'outofstock' !== $product->get_stock_status() ) {
			$product->set_stock_status( 'outofstock' );
			$changed = true;
		}

		return $changed;
	}

    private static function get_price_override_for_data( $data, $profile ) {
        if ( ! class_exists( 'Price_Rules' ) || empty( $profile['price_rules'] ) ) {
            return null;
        }
        return Price_Rules::get_override(
            self::get_effective_price_for_rules( $data ),
            $profile['price_rules']
        );
    }

    private static function set_price_and_stock( &$product, $dto, $profile, $is_new, $update_fields = array(), $price_override = null ) {
        $set_price          = in_array( 'regular_price', $update_fields, true );
        $set_sale           = in_array( 'sale_price', $update_fields, true );
        $set_stock_status   = in_array( 'stock_status', $update_fields, true );
        $set_stock_quantity = in_array( 'stock_quantity', $update_fields, true );

        if ( $set_price ) {
            $regular_price = self::apply_price_transform( $dto['regular_price'], $profile, $price_override, 'regular' );
            $product->set_regular_price( $regular_price );
        }
        if ( $set_sale ) {
            $sale_price = $dto['sale_price'] ? self::apply_price_transform( $dto['sale_price'], $profile, $price_override, 'sale' ) : '';
            $product->set_sale_price( $sale_price );
        }
		if ( $set_stock_status ) {
			$status_map = array( 'in-stock' => 'instock', 'out-of-stock' => 'outofstock' );
			if ( isset( $status_map[ $dto['stock_status'] ] ) ) {
				$product->set_stock_status( $status_map[ $dto['stock_status'] ] );
			}
        }

		if ( $set_stock_quantity ) {
			if ( $product->is_type( 'variable' ) ) {
				// محصول متغیر: والد فقط stock_status می‌گیرد.
				// manage_stock و stock_quantity در سطح وارییشن‌ها مدیریت می‌شوند.
				$product->set_manage_stock( false );
				$product->set_stock_quantity( null );
			} else {
				// null means the supplier did not publish this fact. Preserve existing
				// WooCommerce inventory during updates instead of inventing zero/false.
				$manage_stock = array_key_exists( 'manage_stock', $dto ) ? $dto['manage_stock'] : null;
				if ( true === $manage_stock && null !== $dto['stock_quantity'] ) {
					$product->set_manage_stock( true );
					$product->set_stock_quantity( $dto['stock_quantity'] );
				} elseif ( false === $manage_stock ) {
					$product->set_manage_stock( false );
					$product->set_stock_quantity( null );
				} elseif ( $is_new ) {
					$product->set_manage_stock( false );
					$product->set_stock_quantity( null );
				}
			}
		}

		self::enforce_zero_price_stock_guard( $product, $dto );
    }

    /* ------------------------------------------------------------------ */
    /*  تبدیل قیمت                                                         */
    /* ------------------------------------------------------------------ */

    private static function apply_price_transform( $price, $profile, $override = null, $price_type = 'regular' ) {
        if ( 0 == $price ) return 0;

        $base_currency = get_woocommerce_currency();
        $source_currency = $profile['source_currency'];
        $currency_map = array( 'تومان' => 'IRT', 'ریال' => 'IRR' );
        $source_code = isset( $currency_map[ $source_currency ] ) ? $currency_map[ $source_currency ] : $source_currency;

        if ( $source_code !== $base_currency ) {
            if ( 'IRT' === $source_code && 'IRR' === $base_currency ) {
                $price = $price * 10;
            } elseif ( 'IRR' === $source_code && 'IRT' === $base_currency ) {
                $price = $price / 10;
            }
        }

        // اگر بازهٔ قیمتی منطبق، ضرایب اختصاصی خودش را داشته باشد (طبق Price_Rules::get_override)،
        // فرمول (قیمت × ضریب اول + ثابت) × ضریب دوم روی قیمت اصلی و قیمت ویژه یکسان اعمال
        // می‌شود - چه در ایجاد محصول جدید، چه در بروزرسانی محصول موجود (نگاه کنید به
        // فراخوانی‌کننده‌ها). در غیر این صورت (بدون هیچ قانون منطبقی) قیمت مبدأ بدون تغییر
        // اعمال می‌شود؛ چون دیگر multiplier/constant سراسری‌ای در سطح پروفایل وجود ندارد.
        if ( null !== $override ) {
            $multiplier1 = (float) ( $override['coef1'] ?? 1 );
            $multiplier2 = (float) ( $override['coef2'] ?? 1 );
            $constant    = (float) ( $override['constant'] ?? 0 );
        } else {
            $multiplier1 = 1.0;
            $multiplier2 = 1.0;
            $constant    = 0.0;
        }

        $price = ( ( $price * $multiplier1 ) + $constant ) * $multiplier2;
        return round( $price );
    }

    /* ------------------------------------------------------------------ */
    /*  نگاشت دسته‌بندی‌ها                                                */
    /* ------------------------------------------------------------------ */

    private static function apply_category_mapping( $source_categories, $profile ) {
        $map = $profile['category_map'];
        $final_term_ids = array();
        $null_target_id   = 0;
        $wildcard_target_id = 0;

        foreach ( $map as $mapping ) {
            $s   = trim( $mapping['source_name'] ?? '' );
            $tid = (int) $mapping['target_id'];
            if ( $tid <= 0 ) continue;
            if ( $s === '' || $s === '-' ) {
                $null_target_id = $tid;
            } elseif ( $s === '*' ) {
                $wildcard_target_id = $tid;
            }
        }

        if ( ! empty( $source_categories ) ) {
            foreach ( $source_categories as $cat_name ) {
                $cat_name = trim( $cat_name );
                if ( empty( $cat_name ) ) continue;

                $matched = false;
                foreach ( $map as $mapping ) {
                    $s = trim( $mapping['source_name'] ?? '' );
                    if ( $s !== '' && $s !== '-' && $s !== '*' && $cat_name === $s ) {
                        $final_term_ids[] = (int) $mapping['target_id'];
                        $matched = true;
                        break;
                    }
                }
                if ( $matched ) continue;

                if ( $wildcard_target_id > 0 ) {
                    $final_term_ids[] = $wildcard_target_id;
                    continue;
                }

                $term = get_term_by( 'name', $cat_name, 'product_cat' );
                if ( ! $term ) {
                    $insert = wp_insert_term( $cat_name, 'product_cat' );
                    if ( ! is_wp_error( $insert ) ) {
                        $final_term_ids[] = $insert['term_id'];
                    }
                } else {
                    $final_term_ids[] = $term->term_id;
                }
            }
        } else {
            if ( $null_target_id > 0 ) {
                $final_term_ids[] = $null_target_id;
            }
        }

        return array_unique( $final_term_ids );
    }

    /* ------------------------------------------------------------------ */
    /*  مدیریت برچسب‌ها                                                    */
    /* ------------------------------------------------------------------ */

    private static function maybe_create_tags( $tags ) {
        $term_ids = array();
        foreach ( $tags as $tag_name ) {
            $tag_name = trim( $tag_name );
            if ( empty( $tag_name ) ) continue;
            $term = get_term_by( 'name', $tag_name, 'product_tag' );
            if ( ! $term ) {
                $new_term = wp_insert_term( $tag_name, 'product_tag' );
                if ( ! is_wp_error( $new_term ) ) {
                    $term_ids[] = $new_term['term_id'];
                }
            } else {
                $term_ids[] = $term->term_id;
            }
        }
        return $term_ids;
    }

    /* ------------------------------------------------------------------ */
    /*  آماده‌سازی ویژگی‌ها                                                */
    /* ------------------------------------------------------------------ */

    private static function prepare_attributes( $dto_attributes, $profile ) {
        $local = (bool) $profile['create_attributes_as_local'];
        $wc_attributes = array();

        foreach ( $dto_attributes as $attr ) {
            $name = $attr['name'];
            $values = $attr['values'];

            $attribute = new WC_Product_Attribute();
            $attribute->set_name( $name );

            if ( $local ) {
                $attribute->set_options( $values );
                $attribute->set_visible( true );
                $attribute->set_variation( ! empty( $attr['used_for_variations'] ) );
            } else {
                $taxonomy = wc_attribute_taxonomy_name( $name );
                $attribute_id = wc_attribute_taxonomy_id_by_name( $taxonomy );
                if ( ! taxonomy_exists( $taxonomy ) ) {
                    $attribute_id = wc_create_attribute( array( 'name' => $name ) );
                    if ( is_wp_error( $attribute_id ) ) continue;
                    $taxonomy = wc_attribute_taxonomy_name( $name );
                    register_taxonomy( $taxonomy, 'product' );
                }

                $term_ids = array();
                foreach ( $values as $val ) {
                    $term = get_term_by( 'name', $val, $taxonomy );
                    if ( ! $term ) {
                        $insert = wp_insert_term( $val, $taxonomy );
                        if ( ! is_wp_error( $insert ) ) {
                            $term_ids[] = $insert['term_id'];
                        }
                    } else {
                        $term_ids[] = $term->term_id;
                    }
                }
                $attribute->set_id( (int) $attribute_id );
                $attribute->set_name( $taxonomy );
                $attribute->set_options( $term_ids );
                $attribute->set_visible( true );
                $attribute->set_variation( ! empty( $attr['used_for_variations'] ) );
            }

            $wc_attributes[] = $attribute;
        }
        return $wc_attributes;
    }

    /* ------------------------------------------------------------------ */
    /*  ساخت متغیرها                                                       */
    /* ------------------------------------------------------------------ */

	private static function normalize_variation_attributes( $attributes_map, $profile ) {
		$local = ! empty( $profile['create_attributes_as_local'] );
		$normalized = array();
		foreach ( (array) $attributes_map as $attr_name => $attr_value ) {
			$key = $local ? sanitize_title( $attr_name ) : wc_attribute_taxonomy_name( $attr_name );
			$normalized[ $key ] = $local ? (string) $attr_value : sanitize_title( $attr_value );
		}
		ksort( $normalized );
		return $normalized;
	}

	/**
	 * Apply only the mandatory zero-price guard to matching existing children.
	 * This path deliberately never creates, removes, reprices, or re-attributes a
	 * variation when the profile has disabled normal variation updates.
	 */
	private static function enforce_existing_zero_price_variations( $parent_id, $variations, $profile ) {
		$local = ! empty( $profile['create_attributes_as_local'] );
		$existing_variations = wc_get_products( array(
			'type'   => 'variation',
			'parent' => $parent_id,
			'limit'  => -1,
			'return' => 'objects',
		) );
		$existing_map = array();

		foreach ( $existing_variations as $variation ) {
			$normalized = array();
			foreach ( $variation->get_attributes() as $key => $value ) {
				$clean_key = sanitize_title( str_replace( 'attribute_', '', $key ) );
				$normalized[ $clean_key ] = $local ? (string) $value : sanitize_title( $value );
			}
			ksort( $normalized );
			$existing_map[ md5( serialize( $normalized ) ) ] = $variation;
		}

		$changed = 0;
		foreach ( $variations as $var_data ) {
			if ( self::has_positive_effective_source_price( $var_data ) ) {
				continue;
			}
			$normalized_map = self::normalize_variation_attributes( $var_data['attributes_map'] ?? array(), $profile );
			$fingerprint = md5( serialize( $normalized_map ) );
			if ( isset( $existing_map[ $fingerprint ] ) && self::enforce_zero_price_stock_guard( $existing_map[ $fingerprint ], $var_data ) ) {
				$existing_map[ $fingerprint ]->save();
				$changed++;
			}
		}

		if ( $changed ) {
			wc_delete_product_transients( $parent_id );
		}
		return $changed;
	}

	private static function create_variations( $parent_id, $variations, $dto_attributes, $profile, $author_id = 0, $allowed_fields = array() ) {
		$set_regular = in_array( 'regular_price', $allowed_fields, true );
		$set_sale = in_array( 'sale_price', $allowed_fields, true );
		$set_stock_status = in_array( 'stock_status', $allowed_fields, true );
		$set_stock_quantity = in_array( 'stock_quantity', $allowed_fields, true );

		foreach ( $variations as $var_data ) {

			$variation = new WC_Product_Variation();
			$variation->set_parent_id( $parent_id );
			$variation->set_attributes( self::normalize_variation_attributes( $var_data['attributes_map'] ?? array(), $profile ) );

			$var_override = self::get_price_override_for_data( $var_data, $profile );
			if ( $set_regular && isset( $var_data['regular_price'] ) ) {
				$regular = self::apply_price_transform( $var_data['regular_price'], $profile, $var_override, 'regular' );
				$variation->set_regular_price( $regular );
			}
			if ( $set_sale ) {
				$sale = ! empty( $var_data['sale_price'] )
					? self::apply_price_transform( $var_data['sale_price'], $profile, $var_override, 'sale' )
					: '';
				$variation->set_sale_price( $sale );
			}

			// ──── تبدیل وضعیت موجودی به فرمت ووکامرس ────
			$status_map = array( 'in-stock' => 'instock', 'out-of-stock' => 'outofstock' );
			$wc_stock_status = isset( $status_map[ $var_data['stock_status'] ?? '' ] ) ? $status_map[ $var_data['stock_status'] ] : null;
			if ( $set_stock_status && null !== $wc_stock_status ) $variation->set_stock_status( $wc_stock_status );

			$manage_stock = array_key_exists( 'manage_stock', $var_data ) ? $var_data['manage_stock'] : null;
			if ( $set_stock_quantity ) {
				if ( true === $manage_stock && null !== ( $var_data['stock_quantity'] ?? null ) ) {
					$variation->set_manage_stock( true );
					$variation->set_stock_quantity( $var_data['stock_quantity'] );
				} else {
					$variation->set_manage_stock( false );
					$variation->set_stock_quantity( null );
				}
			}

			self::enforce_zero_price_stock_guard( $variation, $var_data );

			Sync_Logger::trace( sprintf(
				'Variation created: stock_status=%s, qty=%d, manage=%s',
				$variation->get_stock_status() ?: 'unknown',
				$variation->get_stock_quantity() ?? 0,
				$variation->get_manage_stock() ? 'yes' : 'no'
			), array( 'sku' => $var_data['sku'] ?? '' ) );

			if ( ! empty( $var_data['sku'] ) ) {
				$variation->set_sku( $var_data['sku'] );
			}

			if ( ! empty( $var_data['image'] ) ) {
				$attach_id = self::process_image( $var_data['image'], $author_id );
				if ( ! is_wp_error( $attach_id ) ) {
					$variation->set_image_id( $attach_id );
				}
			}

			$variation->save();

			if ( $variation->get_image_id() ) {
				self::set_attachment_parent( $variation->get_image_id(), $parent_id );
			}
		}
	}

    /**
     * همگام‌سازی وارییشن‌های یک محصول متغیر موجود
     *
     * @param int   $parent_id
     * @param array $variations آرایهٔ وارییشن‌های جدید (از DTO)
     * @param array $dto_attributes ویژگی‌های محصول
     * @param array $profile تنظیمات پروفایل
     * @param int   $author_id
     */
	private static function sync_variations( $parent_id, $variations, $dto_attributes, $profile, $author_id = 0, $update_fields = array() ) {
		$local = ! empty( $profile['create_attributes_as_local'] );
		$set_regular = in_array( 'regular_price', $update_fields, true );
		$set_sale = in_array( 'sale_price', $update_fields, true );
		$set_stock_status = in_array( 'stock_status', $update_fields, true );
		$set_stock_quantity = in_array( 'stock_quantity', $update_fields, true );
		$existing_variations = wc_get_products( array(
			'type'   => 'variation',
			'parent' => $parent_id,
			'limit'  => -1,
			'return' => 'objects',
		) );

		$existing_map = array();
		foreach ( $existing_variations as $var ) {
			$attrs = $var->get_attributes();
			$normalized = array();
			foreach ( $attrs as $key => $value ) {
				$clean_key = sanitize_title( str_replace( 'attribute_', '', $key ) );
				$normalized[ $clean_key ] = $local ? (string) $value : sanitize_title( $value );
			}
			ksort( $normalized );
			$fingerprint = md5( serialize( $normalized ) );
			$existing_map[ $fingerprint ] = $var;
		}

		$processed_fingerprints = array();

		foreach ( $variations as $var_data ) {
			$map = $var_data['attributes_map'] ?? array();
			$normalized_map = self::normalize_variation_attributes( $map, $profile );
			$fingerprint = md5( serialize( $normalized_map ) );

			$processed_fingerprints[] = $fingerprint;

			if ( isset( $existing_map[ $fingerprint ] ) ) {
				// به‌روزرسانی وارییشن موجود
				$variation = $existing_map[ $fingerprint ];
				$var_override = self::get_price_override_for_data( $var_data, $profile );
				if ( $set_regular && isset( $var_data['regular_price'] ) ) {
					$regular = self::apply_price_transform( $var_data['regular_price'], $profile, $var_override, 'regular' );
					$variation->set_regular_price( $regular );
				}
				if ( $set_sale ) {
					$sale = ! empty( $var_data['sale_price'] )
						? self::apply_price_transform( $var_data['sale_price'], $profile, $var_override, 'sale' )
						: '';
					$variation->set_sale_price( $sale );
				}

				// ──── تبدیل وضعیت موجودی به فرمت ووکامرس ────
				$status_map = array( 'in-stock' => 'instock', 'out-of-stock' => 'outofstock' );
				$wc_stock_status = isset( $status_map[ $var_data['stock_status'] ?? '' ] ) ? $status_map[ $var_data['stock_status'] ] : null;
				if ( $set_stock_status && null !== $wc_stock_status ) $variation->set_stock_status( $wc_stock_status );

				$manage_stock = array_key_exists( 'manage_stock', $var_data ) ? $var_data['manage_stock'] : null;
				if ( $set_stock_quantity ) {
					if ( true === $manage_stock && null !== ( $var_data['stock_quantity'] ?? null ) ) {
						$variation->set_manage_stock( true );
						$variation->set_stock_quantity( $var_data['stock_quantity'] );
					} elseif ( false === $manage_stock ) {
						$variation->set_manage_stock( false );
						$variation->set_stock_quantity( null );
					}
				}

				self::enforce_zero_price_stock_guard( $variation, $var_data );

				Sync_Logger::trace( sprintf(
					'Variation updated: stock_status=%s, qty=%d, manage=%s',
					$variation->get_stock_status() ?: 'unknown',
					$variation->get_stock_quantity() ?? 0,
					$variation->get_manage_stock() ? 'yes' : 'no'
				), array( 'variation_id' => $variation->get_id() ) );

				if ( ! empty( $var_data['image'] ) ) {
					$existing_image = $variation->get_image_id();
					$attach_id = self::process_image( $var_data['image'], $author_id );
					if ( ! is_wp_error( $attach_id ) && $attach_id != $existing_image ) {
						$variation->set_image_id( $attach_id );
					}
				}
				$variation->save();
			} else {
				// وارییشن جدید
				$variation = new WC_Product_Variation();
				$variation->set_parent_id( $parent_id );
				$variation->set_attributes( $normalized_map );
				$var_override = self::get_price_override_for_data( $var_data, $profile );
				if ( $set_regular && isset( $var_data['regular_price'] ) ) {
					$regular = self::apply_price_transform( $var_data['regular_price'], $profile, $var_override, 'regular' );
					$variation->set_regular_price( $regular );
				}
				if ( $set_sale ) {
					$sale = ! empty( $var_data['sale_price'] )
						? self::apply_price_transform( $var_data['sale_price'], $profile, $var_override, 'sale' )
						: '';
					$variation->set_sale_price( $sale );
				}

				// ──── تبدیل وضعیت موجودی به فرمت ووکامرس ────
				$status_map = array( 'in-stock' => 'instock', 'out-of-stock' => 'outofstock' );
				$wc_stock_status = isset( $status_map[ $var_data['stock_status'] ?? '' ] ) ? $status_map[ $var_data['stock_status'] ] : null;
				if ( $set_stock_status && null !== $wc_stock_status ) $variation->set_stock_status( $wc_stock_status );

				$manage_stock = array_key_exists( 'manage_stock', $var_data ) ? $var_data['manage_stock'] : null;
				if ( $set_stock_quantity ) {
					if ( true === $manage_stock && null !== ( $var_data['stock_quantity'] ?? null ) ) {
						$variation->set_manage_stock( true );
						$variation->set_stock_quantity( $var_data['stock_quantity'] );
					} else {
						$variation->set_manage_stock( false );
						$variation->set_stock_quantity( null );
					}
				}

				self::enforce_zero_price_stock_guard( $variation, $var_data );

				Sync_Logger::trace( sprintf(
					'Variation created (sync): stock_status=%s, qty=%d, manage=%s',
					$variation->get_stock_status() ?: 'unknown',
					$variation->get_stock_quantity() ?? 0,
					$variation->get_manage_stock() ? 'yes' : 'no'
				), array( 'sku' => $var_data['sku'] ?? '' ) );

				if ( ! empty( $var_data['image'] ) ) {
					$attach_id = self::process_image( $var_data['image'], $author_id );
					if ( ! is_wp_error( $attach_id ) ) {
						$variation->set_image_id( $attach_id );
					}
				}
				$variation->save();
			}
		}

		// حذف وارییشن‌های اضافی
		foreach ( $existing_map as $fingerprint => $variation ) {
			if ( ! in_array( $fingerprint, $processed_fingerprints ) ) {
				$variation->delete( true );
			}
		}

		wc_delete_product_transients( $parent_id );
	}

    /* ------------------------------------------------------------------ */
    /*  دانلود تصویر                                                       */
    /* ------------------------------------------------------------------ */

    private static function process_image( $url, $author_id) {

		if ( empty( $url ) ) {
			return new WP_Error( 'empty_url', 'آدرس تصویر خالی است.' );
		}

		// بارگذاری فایل به کتابخانهٔ وردپرس
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';

		$clean_url = preg_replace( '/[?#].*$/', '', $url );

		// ۱. بررسی تصویر تکراری بر اساس متای _source_image_url
		$existing = get_posts( array(
			'post_type'   => 'attachment',
			'post_status' => 'inherit',
			'meta_key'    => '_source_image_url',
			'meta_value'  => $clean_url,
			'fields'      => 'ids',
			'numberposts' => 1,
		) );

		if ( ! empty( $existing ) ) {
			// موقت
			$attachment_id = $existing[0];

			// به‌روزرسانی نویسنده تصویر تکراری (موقتی برای عکس‌های قدیمی)
			if ( $author_id && get_post_field( 'post_author', $attachment_id ) != $author_id ) {
				wp_update_post( array(
					'ID'          => $attachment_id,
					'post_author' => $author_id,
				) );
			}
			// موقت
			return $existing[0];
		}

		// ۲. دانلود محتوای تصویر
		$response = wp_remote_get( $clean_url, array( 'timeout' => 30 ) );
		if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
			return new WP_Error( 'download_failed', 'دانلود تصویر ناموفق بود.' );
		}

		$image_content = wp_remote_retrieve_body( $response );
		if ( empty( $image_content ) ) {
			return new WP_Error( 'empty_body', 'محتوای تصویر خالی است.' );
		}

		// ۳. تشخیص نوع MIME از روی محتوا
		$finfo = new finfo( FILEINFO_MIME_TYPE );
		$mime_type = $finfo->buffer( $image_content );

		// ۴. نگاشت MIME به پسوند فایل
		$mime_to_ext = array(
			'image/jpeg'      => 'jpg',
			'image/png'       => 'png',
			'image/gif'       => 'gif',
			'image/webp'      => 'webp',
			'image/avif'      => 'avif',
			'image/jfif'      => 'jpg',   // jfif با jpg یکسان است
			'image/bmp'       => 'bmp',
			'image/tiff'      => 'tiff',
			'image/svg+xml'   => 'svg',
		);

		$extension = isset( $mime_to_ext[ $mime_type ] ) ? $mime_to_ext[ $mime_type ] : 'jpg'; // پیش‌فرض jpg

		// ۵. ایجاد نام فایل یکتا با پسوند صحیح
		$file_name = 'synced-image-' . wp_generate_password( 8, false ) . '.' . $extension;

		// ۶. نوشتن فایل موقت با نام صحیح
		$temp_file = wp_tempnam( $file_name );
		// نام فایل موقت را بازنویسی می‌کنیم تا پسوند صحیح داشته باشد
		$temp_file_with_ext = dirname( $temp_file ) . '/' . $file_name;
		rename( $temp_file, $temp_file_with_ext );
		file_put_contents( $temp_file_with_ext, $image_content );

		// ۷. شبیه‌سازی آرایهٔ $_FILES برای media_handle_sideload
		$file_array = array(
			'name'     => $file_name,
			'tmp_name' => $temp_file_with_ext,
		);

        $overrides = array(
            'post_title'  => sanitize_file_name( $file_name ),
            'post_author' => $author_id,
        );
		
		@set_time_limit( 20 ); // جلوگیری از timeout در پردازش تصویر
        $attachment_id = media_handle_sideload( $file_array, 0, null, $overrides );

		if ( is_wp_error( $attachment_id ) ) {
			// حذف فایل موقت در صورت خطا
			@unlink( $temp_file_with_ext );
			return $attachment_id;
		}

		// ۹. ثبت متای منبع تصویر برای تشخیص تکراری در آینده
		update_post_meta( $attachment_id, '_source_image_url', $clean_url );

		return $attachment_id;
	}

    /* ------------------------------------------------------------------ */
    /*  تولید SKU                                                          */
    /* ------------------------------------------------------------------ */

    public static function generate_sku( $title, $categories, $pattern, $dto = array() ) {
        $parts = $pattern['parts'];
        $part_delimiter   = $pattern['part_delimiter'];
        $abbrev_delimiter = $pattern['abbrev_delimiter'];
        $abbrev_length    = $pattern['abbrev_length'];

        $sku_parts = array();

        foreach ( $parts as $part ) {
            $type = $part['type'];
            switch ( $type ) {
                case 'static':
                    $sku_parts[] = $part['value'] ?? '';
                    break;
                case 'title':
                    $sku_parts[] = self::abbreviate( $title, $abbrev_length, $abbrev_delimiter );
                    break;
                case 'product_id':
                    $sku_parts[] = isset( $dto['product_id'] ) ? $dto['product_id'] : '';
                    break;
                case 'category':
                    $first_cat = ! empty( $categories ) ? $categories[0] : '';
                    if ( $first_cat ) {
                        $sku_parts[] = self::abbreviate( $first_cat, $abbrev_length, $abbrev_delimiter );
                    }
                    break;
            }
        }

        $sku_parts = array_filter( $sku_parts, function( $p ) { return '' !== trim( $p ); } );
        return implode( $part_delimiter, $sku_parts );
    }

    /* ------------------------------------------------------------------ */
    /*  مخفف‌سازی و فینگلیش                                                */
    /* ------------------------------------------------------------------ */

    private static function abbreviate( $phrase, $length, $separator ) {
        $phrase = self::finglish( $phrase );
        $words = preg_split( '/\s+/', $phrase );
        $abbrevs = array();
        foreach ( $words as $word ) {
            $word = trim( $word );
            if ( mb_strlen( $word ) > 0 ) {
                $abbrevs[] = mb_substr( $word, 0, $length );
            }
        }
        return implode( $separator, $abbrevs );
    }
	
	private static function finglish( $text ) {
		$map = array(
			// حروف فارسی و عربی
			'ا' => 'a',		'آ' => 'aa',	'ب' => 'b',		'پ' => 'p',		'ت' => 't',
			'ث' => 'sc',	'ج' => 'j',		'چ' => 'ch',	'ح' => 'h',		'خ' => 'kh',
			'د' => 'd',		'ذ' => 'zal',	'ر' => 'r',		'ز' => 'z',		'ژ' => 'zh',
			'س' => 's',		'ش' => 'sh',	'ص' => 'sad',	'ض' => 'zad',	'ط' => 'ta',
			'ظ' => 'za',	'ع' => 'ain',	'غ' => 'gha',	'ف' => 'f',		'ق' => 'gh',
			'ک' => 'k',		'گ' => 'g',		'ل' => 'l',		'م' => 'm',		'ن' => 'n',
			'و' => 'v',		'ه' => 'he',	'ی' => 'i',		'ي' => 'y',		'ئ' => 'iy',	'ء' => '',
			// اضافه‌های جدید برای پوشش کیبورد عربی
			'أ' => 'a',  'إ' => 'a',  'ؤ' => 'v',  'ة' => 'h',  'ى' => 'a',
			'ك' => 'k',  'ۀ' => 'e',  'ٱ' => 'a',  'ٲ' => 'a',  'ٳ' => 'a',
			// اعراب (حرکات) – حذف می‌شوند
			'َ' => '',   'ُ' => '',   'ِ' => '',   'ً' => '',   'ٌ' => '',
			'ٍ' => '',   'ْ' => '',   'ّ' => '',   'ٔ' => '',   'ٕ' => '',
			// نشانه‌های نگارشی و فاصله
			' ' => ' ',  '-' => '-',  '،' => '',   '؛' => '',   '؟' => '',
			// اعداد فارسی (هندی)
			'۰' => '0',  '۱' => '1',  '۲' => '2',  '۳' => '3',  '۴' => '4',
			'۵' => '5',  '۶' => '6',  '۷' => '7',  '۸' => '8',  '۹' => '9',
			// اعداد عربی (مشابه فارسی)
			'٠' => '0',  '١' => '1',  '٢' => '2',  '٣' => '3',  '٤' => '4',
			'٥' => '5',  '٦' => '6',  '٧' => '7',  '٨' => '8',  '٩' => '9',
		);

		$finglish = '';
		$len = mb_strlen( $text, 'UTF-8' );
		for ( $i = 0; $i < $len; $i++ ) {
			$char = mb_substr( $text, $i, 1, 'UTF-8' );
			if ( isset( $map[ $char ] ) ) {
				$finglish .= $map[ $char ];
			} else {
				// اگر کاراکتر غیر‑ASCII باشد و در نقشه نباشد، به ۰ تبدیل می‌شود
				if ( ord( $char ) > 127 ) {
					$finglish .= '0';
				} else {
					$finglish .= $char; // ASCII باقی می‌ماند
				}
			}
		}
		return $finglish;
	}



    private static function set_attachment_parent( $attachment_id, $product_id ) {
        if ( $attachment_id && $product_id ) {
            wp_update_post( array(
                'ID'          => $attachment_id,
                'post_parent' => $product_id,
            ) );
        }
    }

}

// ── حذف تصاویر متصل به محصول هنگام حذف دائمی ──
add_action( 'before_delete_post', function( $post_id ) {
    $post = get_post( $post_id );
    if ( ! $post ) return;

    $attachments_to_delete = array();

    if ( 'product' === $post->post_type ) {
        // پاک‌سازی نگاشت‌های این محصول در جدول URL↔Product ID (در تمام پروفایل‌ها)
        if ( class_exists( 'Product_Mapper' ) ) {
            Product_Mapper::delete_mapping_by_product( $post_id );
        }

        $product = wc_get_product( $post_id );
        if ( ! $product ) return;

        if ( $product->get_image_id() ) {
            $attachments_to_delete[] = $product->get_image_id();
        }
        foreach ( $product->get_gallery_image_ids() as $gallery_id ) {
            $attachments_to_delete[] = $gallery_id;
        }

        $variation_ids = $product->get_children();
        foreach ( $variation_ids as $var_id ) {
            $var_image_id = get_post_meta( $var_id, '_thumbnail_id', true );
            if ( $var_image_id ) {
                $attachments_to_delete[] = (int) $var_image_id;
            }
        }
    } elseif ( 'product_variation' === $post->post_type ) {
        $var_image_id = get_post_meta( $post_id, '_thumbnail_id', true );
        if ( $var_image_id ) {
            $attachments_to_delete[] = (int) $var_image_id;
        }
    }

    foreach ( array_unique( $attachments_to_delete ) as $att_id ) {
        if ( $att_id > 0 && get_post( $att_id ) ) {
            wp_delete_attachment( $att_id, true );
        }
    }
} );
