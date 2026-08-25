<?php
/**
 * Price_Rules
 *
 * موتور ارزیابی «قوانین قیمتی» یک پروفایل. قوانین به‌ترتیب (از بالا به پایین، طبق آستانه‌های
 * افزایشی) بررسی می‌شوند و اولین قانونی که آستانه‌اش با قیمت مطابقت دارد اعمال می‌شود:
 *  - action = 'update_only'   → این محصول (اگر هنوز ایمپورت نشده) به‌عنوان محصول جدید ایمپورت
 *                                 نمی‌شود؛ فقط اگر از قبل موجود باشد بروزرسانی می‌شود.
 *  - action = 'import_update' → ایمپورت/بروزرسانی طبق معمول انجام می‌شود، با ضرایب اختصاصی
 *                                 همین قانون روی قیمت اصلی و قیمت ویژه (هر دو یکسان):
 *                                 (قیمت × coef1 + constant) × coef2.
 *
 * این قوانین هم روی ایمپورت محصول جدید و هم روی بروزرسانی محصولات از قبل موجود اثر می‌گذارند
 * (نگاه کنید به Product_Importer::create_new_product و Product_Importer::update_existing_product
 * که هر دو پیش از فراخوانی set_price_and_stock، بازهٔ قیمتی منطبق را محاسبه می‌کنند).
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Price_Rules {

    /**
     * نرمال‌سازی آرایه‌ی قوانین قیمتی ذخیره‌شده در پروفایل.
     * ساختار هر قانون: threshold (عدد یا null/'' برای «نامحدود»), action ('import_update'|'update_only'),
     * coef1 (ضریب اول), constant (مقدار ثابت مشترک), coef2 (ضریب دوم) — فرمول نهایی روی هر
     * دو قیمت اصلی و ویژه یکسان است: (قیمت × coef1 + constant) × coef2.
     */
    public static function normalize( $rules ) {
        if ( ! is_array( $rules ) ) {
            return array();
        }
        $out = array();
        foreach ( $rules as $rule ) {
            if ( ! is_array( $rule ) ) {
                continue;
            }
            $threshold = ( isset( $rule['threshold'] ) && '' !== $rule['threshold'] ) ? floatval( $rule['threshold'] ) : null;
            $action    = in_array( $rule['action'] ?? 'import_update', array( 'import_update', 'update_only' ), true ) ? $rule['action'] : 'import_update';
            $out[] = array(
                'threshold' => $threshold,
                'action'    => $action,
                'coef1'     => isset( $rule['coef1'] ) && '' !== $rule['coef1'] ? floatval( $rule['coef1'] ) : 1,
                'constant'  => isset( $rule['constant'] ) && '' !== $rule['constant'] ? floatval( $rule['constant'] ) : 0,
                'coef2'     => isset( $rule['coef2'] ) && '' !== $rule['coef2'] ? floatval( $rule['coef2'] ) : 1,
            );
        }
        return $out;
    }

    /**
     * اولین قانونی که قیمت داده‌شده در آن قرار می‌گیرد را برمی‌گرداند (اولویت با ترتیب تعریف؛
     * قوانین باید بر اساس آستانه‌ی افزایشی مرتب شده باشند). آستانه‌ی خالی/null یعنی «نامحدود»
     * (کمتر از هر عددی که باشد، این قانون برای هر قیمتی مطابقت دارد).
     *
     * @return array|null
     */
    public static function match( $price, $rules ) {
        $price = floatval( $price );
        foreach ( (array) $rules as $rule ) {
            $threshold = $rule['threshold'] ?? null;
            if ( null === $threshold || '' === $threshold || $price < floatval( $threshold ) ) {
                return $rule;
            }
        }
        return null;
    }

    /**
     * آیا این قیمت باید از «ایمپورت به‌عنوان محصول جدید» کنار گذاشته شود؟
     * (طبق قانون منطبق با action = 'update_only')
     */
    public static function is_excluded( $price, $rules ) {
        $rule = self::match( $price, $rules );
        return $rule && 'update_only' === $rule['action'];
    }

    /**
     * دریافت ضرایب/مقدار ثابت اختصاصیِ قانون منطبق (در صورتی که اجازهٔ ایمپورت بدهد)، برای
     * استفاده در محاسبهٔ قیمت — هم در لحظهٔ ایجاد محصول جدید و هم در بروزرسانی محصول موجود.
     *
     * @return array|null ['coef1'=>float, 'constant'=>float, 'coef2'=>float]
     */
    public static function get_override( $price, $rules ) {
        $rule = self::match( $price, $rules );
        if ( $rule && 'import_update' === $rule['action'] ) {
            return array(
                'coef1'    => isset( $rule['coef1'] ) ? (float) $rule['coef1'] : 1,
                'constant' => isset( $rule['constant'] ) ? (float) $rule['constant'] : 0,
                'coef2'    => isset( $rule['coef2'] ) ? (float) $rule['coef2'] : 1,
            );
        }
        return null;
    }

    /**
     * بررسی یک DTO محصول (ساده یا متغیر) در برابر قوانین قیمتی، برای تصمیم‌گیری «رد شود یا نه».
     *
     * @param array  $dto
     * @param array  $rules
     * @param string $variable_edge_behavior 'import_all' | 'skip_all'
     * @return array ['excluded'=>bool, 'reason'=>string]
     */
    public static function evaluate_dto( $dto, $rules, $variable_edge_behavior = 'import_all' ) {
        if ( empty( $rules ) ) {
            return array( 'excluded' => false, 'reason' => '' );
        }

        $is_variable = ( 'variable' === ( $dto['product_type'] ?? 'simple' ) );

        if ( ! $is_variable ) {
            $effective_price = ( ! empty( $dto['sale_price'] ) && $dto['sale_price'] > 0 ) ? $dto['sale_price'] : ( $dto['regular_price'] ?? 0 );
            if ( self::is_excluded( $effective_price, $rules ) ) {
                return array( 'excluded' => true, 'reason' => sprintf( 'قیمت %d در بازه‌ی «فقط بروزرسانی» قرار دارد', $effective_price ) );
            }
            return array( 'excluded' => false, 'reason' => '' );
        }

        // محصول متغیر
        $variations = $dto['variations'] ?? array();
        if ( empty( $variations ) ) {
            return array( 'excluded' => true, 'reason' => 'محصول متغیر بدون وارییشن' );
        }

        $total = count( $variations );
        $excluded_count = 0;
        foreach ( $variations as $var ) {
            $price = ( ! empty( $var['sale_price'] ) && $var['sale_price'] > 0 ) ? $var['sale_price'] : ( $var['regular_price'] ?? 0 );
            if ( self::is_excluded( $price, $rules ) ) {
                $excluded_count++;
            }
        }

        if ( 0 === $excluded_count ) {
            return array( 'excluded' => false, 'reason' => '' );
        }
        if ( $excluded_count === $total ) {
            return array( 'excluded' => true, 'reason' => sprintf( 'همهٔ %d وارییشن در بازه‌ی «فقط بروزرسانی» قرار دارند', $total ) );
        }

        // حالت مرزی
        if ( 'skip_all' === $variable_edge_behavior ) {
            return array( 'excluded' => true, 'reason' => sprintf( 'حالت مرزی (فقط بروزرسانی: %d از %d) - رفتار: رد کل محصول', $excluded_count, $total ) );
        }
        return array( 'excluded' => false, 'reason' => '' );
    }
}
