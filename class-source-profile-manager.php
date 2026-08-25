<?php
/**
 * مدیریت پروفایل‌های منابع (Source Profile Manager)
 *
 * یک Custom Post Type برای ذخیرهٔ تنظیمات هر منبع محصول ایجاد می‌کند.
 * شامل متاباکس کامل با فیلدهای مختلف، ذخیره‌سازی سفارشی و متدهای کمکی.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

require_once plugin_dir_path( __FILE__ ) . 'class-sync-logger.php';

class Source_Profile_Manager {

    const POST_TYPE = 'source_profile';
    const NONCE_NAME = 'source_profile_meta_nonce';

    const DTO_FIELDS = array(
        'title', 'excerpt', 'content', 'featured_image', 'gallery_images',
        'regular_price', 'sale_price', 'currency', 'stock_status', 'stock_quantity',
        'categories', 'tags', 'attributes', 'variations'
    );

    private static $defaults = array(
        'extractor_id'               => '',
        'sitemap_url'                => '',
        'auth_username'              => '',
        'auth_password'              => '',
        'source_currency'            => 'تومان',
        // ---------- price_multiplier و price_constant حذف شدند ----------
        'category_map'               => array(),
        'create_attributes_as_local' => true,
        'shipping_class'             => '',
        'first_import_fields'        => array(),
        'import_out_of_stock'        => false,
        'new_product_status'         => 'draft',
        'allowed_categories'         => '',
        'disallowed_categories'      => '',
        'update_fields'              => array(),
        'on_product_deleted'         => 'set_outofstock',
        'sku_pattern'                => array(
            'parts'            => array(),
            'part_delimiter'   => '-',
            'abbrev_delimiter' => '',
            'abbrev_length'    => 2,
        ),
        'update_post_date'           => false,
        'update_post_modified'       => false,
        'schedule_days'              => array(),
        'schedule_time'              => '',
        'product_author'             => '',
        'price_filter_enabled'       => false,
        'price_filter_operator'      => 'gt',
        'price_filter_value'         => 0,
        'price_filter_value2'        => 0,
        // ---------- قوانین قیمت‌گذاری یکپارچه ----------
        'price_rules'                => array(),   // هر عضو: threshold, action, coef1, constant, coef2
        'import_mode'                 => 'blacklist',
        'blacklist_urls'               => '',
        'whitelist_urls'               => '',
        'use_sku_pattern'              => true,
        'match_legacy_sku'             => false,
        'enable_duplicate_check'       => false,
        'dup_required_phrase'           => '',
        'dup_delimiters'                => ' -',
        'dup_exclude_strings'           => '',
        'dup_min_token_length'          => 0,
        'dup_conflict_groups'           => '',
        'dup_min_score'                 => 1,
        'dup_numeric_priority'          => false,
        'dup_alphanumeric_priority'     => false,
        'dup_equal_numeric_count'       => false,
        'dup_partial_code_match'        => false,
        'dup_partial_match_min_length'  => 3,
    );

    public function __construct() {
        add_action( 'init', array( $this, 'register_post_type' ) );
        add_action( 'add_meta_boxes', array( $this, 'add_meta_box' ) );
        add_action( 'save_post_' . self::POST_TYPE, array( $this, 'save_profile' ), 10, 2 );
        add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_scripts' ) );
        add_filter( 'post_row_actions', array( $this, 'add_duplicate_link' ), 10, 2 );
        add_action( 'admin_action_duplicate_profile', array( $this, 'handle_duplicate_profile' ) );
    }

    public function register_post_type() {
        $labels = array(
            'name'               => 'پروفایل‌های منبع',
            'singular_name'      => 'پروفایل منبع',
            'add_new'            => 'افزودن پروفایل جدید',
            'add_new_item'       => 'افزودن پروفایل جدید',
            'edit_item'          => 'ویرایش پروفایل منبع',
            'search_items'       => 'جستجوی پروفایل‌ها',
            'not_found'          => 'پروفایلی یافت نشد',
            'menu_name'          => 'مدیریت منابع',
        );

        register_post_type( self::POST_TYPE, array(
            'labels'       => $labels,
            'public'       => false,
            'show_ui'      => true,
            'show_in_menu' => true,
            'menu_icon'    => 'dashicons-networking',
            'supports'     => array( 'title' ),
        ) );
    }

    public function add_meta_box() {
        add_meta_box(
            'source_profile_settings',
            'تنظیمات منبع',
            array( $this, 'render_meta_box' ),
            self::POST_TYPE,
            'normal',
            'high'
        );
    }

    public function enqueue_admin_scripts( $hook ) {
        global $post_type;
        if ( self::POST_TYPE !== $post_type || ! in_array( $hook, array( 'post.php', 'post-new.php' ) ) ) {
            return;
        }

        wp_enqueue_style( 'chosen-css', 'https://cdnjs.cloudflare.com/ajax/libs/chosen/1.8.7/chosen.min.css', array(), '1.8.7' );
        wp_enqueue_script( 'chosen-js', 'https://cdnjs.cloudflare.com/ajax/libs/chosen/1.8.7/chosen.jquery.min.js', array( 'jquery' ), '1.8.7', true );

        wp_add_inline_style( 'wp-admin', $this->get_admin_css() );
        wp_add_inline_script( 'jquery', $this->get_combined_js() );
    }

    public function add_duplicate_link( $actions, $post ) {
        if ( $post->post_type !== self::POST_TYPE ) {
            return $actions;
        }

        $nonce = wp_create_nonce( 'duplicate_profile_' . $post->ID );
        $url = admin_url( 'edit.php?post_type=' . self::POST_TYPE
            . '&action=duplicate_profile&post=' . $post->ID
            . '&_wpnonce=' . $nonce );

        $duplicate_link = sprintf(
            '<a href="%s" title="%s">%s</a>',
            esc_url( $url ),
            esc_attr__( 'دوبل کردن این پروفایل', 'multi-source-sync' ),
            esc_html__( 'دوبل کردن', 'multi-source-sync' )
        );

        $new_actions = array();
        foreach ( $actions as $key => $value ) {
            if ( 'trash' === $key ) {
                $new_actions['duplicate'] = $duplicate_link;
            }
            $new_actions[ $key ] = $value;
        }
        if ( ! isset( $new_actions['duplicate'] ) ) {
            $new_actions['duplicate'] = $duplicate_link;
        }

        return $new_actions;
    }

    public function handle_duplicate_profile() {
        if ( empty( $_GET['post'] ) || empty( $_GET['_wpnonce'] ) ) {
            wp_die( 'درخواست نامعتبر.' );
        }

        $post_id = absint( $_GET['post'] );
        if ( ! current_user_can( 'edit_post', $post_id ) ) {
            wp_die( 'شما اجازهٔ انجام این کار را ندارید.' );
        }

        check_admin_referer( 'duplicate_profile_' . $post_id, '_wpnonce' );

        $post = get_post( $post_id );
        if ( ! $post || $post->post_type !== self::POST_TYPE ) {
            wp_die( 'پروفایل یافت نشد.' );
        }

        $new_post_id = wp_insert_post( array(
            'post_title'   => $post->post_title . ' (کپی)',
            'post_type'    => self::POST_TYPE,
            'post_status'  => 'draft',
            'post_author'  => get_current_user_id(),
        ) );

        if ( is_wp_error( $new_post_id ) || ! $new_post_id ) {
            wp_die( 'خطا در ایجاد کپی پروفایل.' );
        }

        $meta = get_post_meta( $post_id );
        foreach ( $meta as $key => $values ) {
            foreach ( $values as $value ) {
                update_post_meta( $new_post_id, $key, maybe_unserialize( $value ) );
            }
        }

        wp_redirect( admin_url( 'post.php?action=edit&post=' . $new_post_id ) );
        exit;
    }

    public function render_meta_box( $post ) {
        wp_nonce_field( 'save_source_profile', self::NONCE_NAME );
        $data = self::get_profile( $post->ID );

        echo '<div class="mss-profile-form">';

        // 1. تنظیمات منبع
        $this->start_section( 'تنظیمات منبع' );

        $extractors = $GLOBALS['mss_extractors'] ?? array();
        $options = array();
        foreach ( $extractors as $id => $ext ) {
            $options[ $id ] = $ext['name'];
        }
        if ( empty( $options ) ) {
            $options[''] = 'هیچ استخراج‌کننده‌ای یافت نشد';
        }
        $this->field_select( 'extractor_id', 'شناسهٔ استخراج‌کننده', $data['extractor_id'], $options );
        $this->field_textarea(
            'sitemap_url',
            'آدرس نقشهٔ سایت (یک یا چند مورد)',
            $data['sitemap_url'],
            "هر آدرس را در یک خط وارد کنید. می‌توانید آدرس sitemap index اصلی را وارد کنید تا sitemapهای محصول داخل آن خودکار تجمیع شوند. sitemapهای ناموجود نادیده گرفته می‌شوند."
        );
        $this->field_text( 'auth_username', 'نام کاربری', $data['auth_username'] );
        $this->field_text( 'auth_password', 'رمز عبور', $data['auth_password'], 'password' );
        $this->field_select( 'source_currency', 'واحد پول منبع', $data['source_currency'], array(
            'تومان' => 'تومان',
            'ریال'  => 'ریال',
        ) );

        $this->end_section();

        // 2. قوانین قیمت‌گذاری یکپارچه (جایگزین بخش قبلی)
        $this->start_section( 'قوانین قیمت‌گذاری' );
        echo '<tr><td colspan="2"><p class="description">این قوانین هم برای ایمپورت محصول جدید و هم برای بروزرسانی محصولات موجود اعمال می‌شوند. ترتیب اولویت از بالا به پایین است؛ اولین قانونی که قیمت مؤثر محصول (برای محصولات متغیر: کمینهٔ قیمت وارییشن‌ها) کمتر از آستانهٔ آن باشد اعمال می‌شود. اگر هیچ قانونی مطابقت نکند (یا لیست خالی باشد)، قیمت بدون تغییر باقی می‌ماند.</p></td></tr>';
        $this->field_price_rules( $data['price_rules'] );
        $this->end_section();

        // 3. نگاشت دسته‌بندی‌ها
        $this->start_section( 'نگاشت دسته‌بندی‌ها' );
        $this->field_category_map( $data['category_map'] );
        $this->end_section();

        // 3ب. کنترل لینک محصولات
        $this->start_section( 'کنترل لینک محصولات (فقط ایمپورت محصولات جدید)' );
        $this->field_select( 'import_mode', 'حالت ایمپورت', $data['import_mode'], array(
            'blacklist' => 'لیست سیاه — طبق کراولر/سایت‌مپ ایمپورت کن، به‌جز لینک‌های زیر',
            'whitelist' => 'لیست سفید — کراولر/سایت‌مپ نادیده گرفته شود، فقط همین لینک‌ها ایمپورت شوند',
        ) );
        $this->field_textarea( 'blacklist_urls', 'لینک‌های ممنوعه (لیست سیاه)', $data['blacklist_urls'], 'هر لینک در یک سطر' );
        $this->field_textarea( 'whitelist_urls', 'لینک‌های مجاز (لیست سفید)', $data['whitelist_urls'], 'هر لینک در یک سطر' );
        echo '<tr><td colspan="2"><p class="description">توجه: این دو لیست فقط تعیین می‌کنند که چه محصول جدیدی ایمپورت شود. محصولاتی که قبلاً ایمپورت شده‌اند، صرف‌نظر از این تنظیمات، همیشه بروزرسانی می‌شوند.</p></td></tr>';
        $this->end_section();

        // تنظیمات تخصصی تطبیق به تب مدیریت داپلیکیت‌ها منتقل شده‌اند.
        $this->start_section( 'تأیید محصولات جدید' );
        $this->field_checkbox( 'enable_duplicate_check', 'هیچ محصول جدیدی از این پروفایل بدون بررسی و تأیید وارد سایت نشود', $data['enable_duplicate_check'] );
        echo '<tr><td colspan="2"><p class="description">با فعال‌بودن این گزینه، همهٔ محصولات جدید پس از همگام‌سازی در صف «مدیریت داپلیکیت‌ها» قرار می‌گیرند. بروزرسانی محصولات موجود بدون تغییر ادامه پیدا می‌کند.</p></td></tr>';
        $this->end_section();

        // 4. ایمپورت اولیه
        $this->start_section( 'تنظیمات ایمپورت اولیه' );

        $this->field_checkbox_group( 'first_import_fields', 'فیلدهای اولین ایمپورت', self::DTO_FIELDS, $data['first_import_fields'] );
        $this->field_checkbox( 'use_sku_pattern', 'تولید SKU طبق الگوی زیر برای محصولات', $data['use_sku_pattern'] );
        $this->field_checkbox( 'import_out_of_stock', 'ورود محصولات ناموجود', $data['import_out_of_stock'] );
        $this->field_select( 'new_product_status', 'وضعیت انتشار محصول جدید', $data['new_product_status'], array(
            'draft'   => 'پیش‌نویس',
            'publish' => 'منتشرشده',
            'pending' => 'در انتظار بازبینی',
            'private' => 'خصوصی',
        ) );
        $this->field_textarea( 'allowed_categories', 'دسته‌بندی‌های مجاز', $data['allowed_categories'], 'نام دسته‌ها را با | جدا کنید (خالی = همه)' );
        $this->field_textarea( 'disallowed_categories', 'دسته‌بندی‌های غیرمجاز', $data['disallowed_categories'], 'نام دسته‌ها را با | جدا کنید' );
        $this->field_shipping_class( 'shipping_class', 'کلاس ارسال', $data['shipping_class'] );

        $this->end_section();

        // 5. بروزرسانی
        $this->start_section( 'تنظیمات بروزرسانی' );

        $this->field_checkbox_group( 'update_fields', 'فیلدهای قابل بروزرسانی', self::DTO_FIELDS, $data['update_fields'] );
        $this->field_select( 'on_product_deleted', 'رفتار با محصول حذف‌شده', $data['on_product_deleted'], array(
            'set_outofstock' => 'ناموجود کردن',
            'delete'         => 'حذف کامل',
        ) );
        $this->field_checkbox( 'update_post_date', 'بروزرسانی تاریخ انتشار در هر اجرا', $data['update_post_date'] );
        $this->field_checkbox( 'update_post_modified', 'بروزرسانی تاریخ آخرین ویرایش در هر اجرا', $data['update_post_modified'] );

        $this->end_section();

        // 6. الگوی SKU
        $this->start_section( 'الگوی SKU' );
        $this->field_checkbox( 'match_legacy_sku', 'تطبیق با محصولات نسخهٔ قبلی (بر اساس SKU)', $data['match_legacy_sku'] );
        echo '<tr><td colspan="2"><p class="description">اگر این پروفایل قبلاً (در نسخهٔ قدیمی افزونه) با تشخیص بر اساس SKU محصولاتی به سایت اضافه کرده، این گزینه را فعال کنید: برای هر محصولِ هنوز-نگاشت‌نشده، پیش از ساخت به‌عنوان محصول جدید، ابتدا بررسی می‌شود آیا محصولی با همان SKU (طبق الگوی زیر) از قبل در سایت وجود دارد؛ در این صورت به‌جای ایجاد محصول تکراری، همان محصول موجود در جدول نگاشت ثبت و بروزرسانی می‌شود.</p></td></tr>';
        $this->field_sku_pattern( $data['sku_pattern'] );
        $this->end_section();

        // 7. زمان‌بندی
        $this->start_section( 'زمان‌بندی همگام‌سازی' );
        $this->field_schedule_days( $data['schedule_days'] );
        $this->field_text( 'schedule_time', 'زمان اجرا', $data['schedule_time'], 'time' );
        $this->end_section();

        // 8. سایر
        $this->start_section( 'سایر تنظیمات' );

        $this->field_checkbox( 'create_attributes_as_local', 'ذخیره ویژگی‌ها به‌صورت لوکال', $data['create_attributes_as_local'] );

        $users = get_users( array(
            'fields'     => array( 'ID', 'display_name' ),
            'capability' => 'edit_products',
        ) );
        $user_options = array( '' => '— انتخاب کنید —' );
        foreach ( $users as $user ) {
            $user_options[ $user->ID ] = $user->display_name;
        }
        $this->field_select( 'product_author', 'نویسندهٔ محصول', $data['product_author'], $user_options );

        $this->end_section();

        echo '</div>'; // .mss-profile-form
    }

    // -------------------------------------------------------------------------
    // متدهای کمکی برای رسم فیلدها (بدون تغییر)
    // -------------------------------------------------------------------------

    private function start_section( $title ) {
        echo '<fieldset class="mss-section"><legend class="mss-section-title">' . esc_html( $title ) . '</legend>';
        echo '<table class="form-table"><tbody>';
    }

    private function end_section() {
        echo '</tbody></table></fieldset>';
    }

    private function field_text( $key, $label, $value, $type = 'text', $placeholder = '' ) {
        ?>
        <tr>
            <th scope="row"><label for="profile_<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $label ); ?></label></th>
            <td><input type="<?php echo esc_attr( $type ); ?>" name="<?php echo esc_attr( $key ); ?>" id="profile_<?php echo esc_attr( $key ); ?>" value="<?php echo esc_attr( $value ); ?>" placeholder="<?php echo esc_attr( $placeholder ); ?>" class="regular-text"></td>
        </tr>
        <?php
    }

    private function field_number( $key, $label, $value, $atts = array() ) {
        ?>
        <tr>
            <th scope="row"><label for="profile_<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $label ); ?></label></th>
            <td><input type="number" name="<?php echo esc_attr( $key ); ?>" id="profile_<?php echo esc_attr( $key ); ?>" value="<?php echo esc_attr( $value ); ?>" <?php foreach ( $atts as $k => $v ) echo esc_attr( $k ) . '="' . esc_attr( $v ) . '" '; ?>></td>
        </tr>
        <?php
    }

    private function field_textarea( $key, $label, $value, $placeholder = '' ) {
        ?>
        <tr>
            <th scope="row"><label for="profile_<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $label ); ?></label></th>
            <td>
                <textarea name="<?php echo esc_attr( $key ); ?>" id="profile_<?php echo esc_attr( $key ); ?>" rows="3" class="large-text" placeholder="<?php echo esc_attr( $placeholder ); ?>"><?php echo esc_textarea( $value ); ?></textarea>
                <?php if ( ! empty( $placeholder ) ) : ?>
                    <p class="description"><?php echo esc_html( $placeholder ); ?></p>
                <?php endif; ?>
            </td>
        </tr>
        <?php
    }

    private function field_select( $key, $label, $selected, $options ) {
        ?>
        <tr>
            <th scope="row"><label for="profile_<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $label ); ?></label></th>
            <td>
                <select name="<?php echo esc_attr( $key ); ?>" id="profile_<?php echo esc_attr( $key ); ?>">
                    <?php foreach ( $options as $val => $text ) : ?>
                        <option value="<?php echo esc_attr( $val ); ?>" <?php selected( $selected, $val ); ?>><?php echo esc_html( $text ); ?></option>
                    <?php endforeach; ?>
                </select>
            </td>
        </tr>
        <?php
    }

    private function field_checkbox( $key, $label, $checked ) {
        ?>
        <tr>
            <th scope="row"><?php echo esc_html( $label ); ?></th>
            <td>
                <label><input type="checkbox" name="<?php echo esc_attr( $key ); ?>" value="1" <?php checked( $checked, true ); ?>> فعال</label>
            </td>
        </tr>
        <?php
    }

    private function field_checkbox_group( $key, $label, $all_options, $checked_values ) {
        $farsi_labels = array(
            'title'           => 'عنوان',
            'excerpt'         => 'خلاصه',
            'content'         => 'محتوا',
            'featured_image'  => 'تصویر شاخص',
            'gallery_images'  => 'تصاویر گالری',
            'regular_price'   => 'قیمت اصلی',
            'sale_price'      => 'قیمت فروش',
            'currency'        => 'واحد پول',
            'stock_status'    => 'وضعیت موجودی',
            'stock_quantity'  => 'تعداد موجودی',
            'categories'      => 'دسته‌بندی‌ها',
            'tags'            => 'برچسب‌ها',
            'attributes'      => 'ویژگی‌ها',
            'variations'      => 'وارییشن‌ها',
        );

        $group_id = 'group_' . $key;
        ?>
        <tr>
            <th scope="row"><?php echo esc_html( $label ); ?></th>
            <td>
                <div class="mss-checkbox-actions">
                    <button type="button" class="button mss-select-all" data-group="<?php echo esc_attr( $group_id ); ?>">انتخاب همه</button>
                    <button type="button" class="button mss-deselect-all" data-group="<?php echo esc_attr( $group_id ); ?>">حذف همه</button>
                </div>
                <div class="mss-checkbox-grid" id="<?php echo esc_attr( $group_id ); ?>">
                    <?php foreach ( $all_options as $opt ) : 
                        $label_text = isset( $farsi_labels[ $opt ] ) ? $farsi_labels[ $opt ] : $opt;
                    ?>
                        <label>
                            <input type="checkbox" name="<?php echo esc_attr( $key ); ?>[]" value="<?php echo esc_attr( $opt ); ?>" <?php checked( in_array( $opt, (array) $checked_values ), true ); ?>>
                            <?php echo esc_html( $label_text ); ?>
                        </label>
                    <?php endforeach; ?>
                </div>
            </td>
        </tr>
        <?php
    }

    private function field_shipping_class( $key, $label, $selected ) {
        if ( ! class_exists( 'WooCommerce' ) ) {
            echo '<tr><th>' . esc_html( $label ) . '</th><td><em>ووکامرس فعال نیست</em></td></tr>';
            return;
        }
        $shipping_classes = WC()->shipping->get_shipping_classes();
        $options = array( '' => '— بدون کلاس —' );
        foreach ( $shipping_classes as $class ) {
            $options[ $class->term_id ] = $class->name;
        }
        $this->field_select( $key, $label, $selected, $options );
    }

    /**
     * فیلد جدید قوانین قیمت‌گذاری
     */
    private function field_price_rules( $rules ) {
        $rules = is_array( $rules ) ? $rules : array();
        $row_html = function( $rule, $index, $is_template = false ) {
            $threshold = isset( $rule['threshold'] ) ? $rule['threshold'] : '';
            $action    = isset( $rule['action'] ) ? $rule['action'] : 'import_update';
            $coef1     = isset( $rule['coef1'] ) ? $rule['coef1'] : 1;
            $constant  = isset( $rule['constant'] ) ? $rule['constant'] : 0;
            $coef2     = isset( $rule['coef2'] ) ? $rule['coef2'] : 1;
            $dis = $is_template ? ' disabled' : '';
            ob_start();
            ?>
            <tr class="price-rule-row<?php echo $is_template ? ' price-rule-template' : ''; ?>"<?php echo $is_template ? ' style="display:none;"' : ''; ?>>
                <td class="priority-cell"></td>
                <td>
                    <label style="display:flex; align-items:center; gap:4px;">
                        کمتر از
                        <input type="number" step="any" name="price_rules[<?php echo $index; ?>][threshold]" value="<?php echo esc_attr( $threshold ); ?>" style="width:100px;" placeholder="نامحدود"<?php echo $dis; ?>>
                    </label>
                </td>
                <td>
                    <select name="price_rules[<?php echo $index; ?>][action]"<?php echo $dis; ?>>
                        <option value="import_update" <?php selected( $action, 'import_update' ); ?>>ایمپورت و بروزرسانی بشود</option>
                        <option value="update_only" <?php selected( $action, 'update_only' ); ?>>ایمپورت نشود؛ بروزرسانی بشود</option>
                    </select>
                </td>
                <td><input type="number" step="any" name="price_rules[<?php echo $index; ?>][coef1]" value="<?php echo esc_attr( $coef1 ); ?>" style="width:80px;" placeholder="1"<?php echo $dis; ?>></td>
                <td><input type="number" step="any" name="price_rules[<?php echo $index; ?>][constant]" value="<?php echo esc_attr( $constant ); ?>" style="width:100px;" placeholder="0"<?php echo $dis; ?>></td>
                <td><input type="number" step="any" name="price_rules[<?php echo $index; ?>][coef2]" value="<?php echo esc_attr( $coef2 ); ?>" style="width:80px;" placeholder="1"<?php echo $dis; ?>></td>
                <td><button type="button" class="button remove-row">حذف</button></td>
            </tr>
            <?php
            return ob_get_clean();
        };
        ?>
        <tr>
            <th scope="row">قوانین</th>
            <td>
                <table class="widefat" id="price-rules-table" style="width:auto;">
                    <thead>
                        <tr>
                            <th style="width:30px;">#</th>
                            <th>آستانه</th>
                            <th>عملکرد</th>
                            <th>ضریب اول</th>
                            <th>ثابت</th>
                            <th>ضریب دوم</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ( $rules as $i => $rule ) : echo $row_html( $rule, $i, false ); endforeach; ?>
                        <?php echo $row_html( array(), 'RULE_INDEX', true ); ?>
                    </tbody>
                    <tfoot>
                        <tr>
                            <td colspan="7"><button type="button" class="button add-price-rule-row">+ افزودن قانون</button></td>
                        </tr>
                    </tfoot>
                </table>
                <p class="description">برای قانون آخر (پایین‌ترین اولویت) آستانه را خالی بگذارید تا به‌عنوان «نامحدود» در نظر گرفته شود. آستانه‌ها باید از بالا به پایین افزایشی باشند.</p>
            </td>
        </tr>
        <?php
    }

    /**
     * فیلد ریپیتر نگاشت دسته‌بندی‌ها (بدون تغییر)
     */
    private function field_category_map( $map_data ) {
        $hidden_template = '<tr class="cat-map-row" style="display:none;">';
        $hidden_template .= '<td><input type="text" name="category_map[CAT_INDEX][source_name]" class="regular-text" value=""></td>';
        $hidden_template .= '<td>';
        if ( taxonomy_exists( 'product_cat' ) ) {
            ob_start();
            wp_dropdown_categories( array(
                'taxonomy'        => 'product_cat',
                'name'            => 'category_map[CAT_INDEX][target_id]',
                'show_option_none'=> '— انتخاب کنید —',
                'hierarchical'     => true,
                'hide_empty'      => false,
                'value_field'     => 'term_id',
                'selected'        => 0,
                'echo'            => true,
                'class'           => 'cat-dropdown',
                'placeholder_text'=> 'انتخاب/جستجو',
            ) );
            $hidden_template .= ob_get_clean();
        } else {
            $hidden_template .= 'دسته‌بندی محصول در دسترس نیست';
        }
        $hidden_template .= '</td>';
        $hidden_template .= '<td><button type="button" class="button remove-row">حذف</button></td>';
        $hidden_template .= '</tr>';
        ?>
        <tr>
            <th scope="row">نگاشت دسته‌بندی‌ها</th>
            <td>
                <table class="widefat" id="category-map-table" style="width:auto;">
                    <thead>
                        <tr>
                            <th>نام دستهٔ منبع</th>
                            <th>دستهٔ مقصد (ووکامرس)</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ( ! empty( $map_data ) ) : ?>
                            <?php foreach ( $map_data as $index => $row ) : ?>
                                <tr class="cat-map-row">
                                    <td><input type="text" name="category_map[<?php echo $index; ?>][source_name]" value="<?php echo esc_attr( $row['source_name'] ?? '' ); ?>" class="regular-text"></td>
                                    <td><?php $this->category_dropdown( "category_map[{$index}][target_id]", $row['target_id'] ?? 0 ); ?></td>
                                    <td><button type="button" class="button remove-row">حذف</button></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                        <?php echo $hidden_template; ?>
                    </tbody>
                    <tfoot>
                        <tr>
                            <td colspan="3"><button type="button" class="button add-cat-row">+ افزودن سطر</button></td>
                        </tr>
                    </tfoot>
                </table>
            </td>
        </tr>
        <?php
    }

    private function category_dropdown( $name, $selected ) {
        if ( ! taxonomy_exists( 'product_cat' ) ) {
            echo 'دسته‌بندی محصول در دسترس نیست';
            return;
        }
        wp_dropdown_categories( array(
            'taxonomy'        => 'product_cat',
            'name'            => $name,
            'selected'        => $selected,
            'show_option_none'=> '— انتخاب کنید —',
            'hierarchical'     => true,
            'hide_empty'      => false,
            'value_field'     => 'term_id',
            'class'           => 'cat-dropdown chosen-select',
            'placeholder_text'=> 'انتخاب/جستجو',
        ) );
    }

    /**
     * فیلد الگوی SKU (بدون تغییر)
     */
    private function field_sku_pattern( $pattern ) {
        $parts = isset( $pattern['parts'] ) ? $pattern['parts'] : array();
        $part_delimiter   = $pattern['part_delimiter'] ?? '-';
        $abbrev_delimiter = $pattern['abbrev_delimiter'] ?? '';
        $abbrev_length    = $pattern['abbrev_length'] ?? 2;
        ?>
        <tr>
            <th scope="row">الگوی SKU</th>
            <td>
                <table class="widefat" id="sku-pattern-table" style="width:auto;">
                    <thead>
                        <tr>
                            <th>نوع بخش</th>
                            <th>مقدار (برای static)</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ( ! empty( $parts ) ) : ?>
                            <?php foreach ( $parts as $idx => $part ) : ?>
                                <tr class="sku-part-row">
                                    <td>
                                        <select name="sku_pattern[parts][<?php echo $idx; ?>][type]" class="part-type">
                                            <option value="static" <?php selected( $part['type'], 'static' ); ?>>Static</option>
                                            <option value="title" <?php selected( $part['type'], 'title' ); ?>>Title</option>
                                            <option value="category" <?php selected( $part['type'], 'category' ); ?>>Category</option>
                                            <option value="product_id" <?php selected( $part['type'], 'product_id' ); ?>>Product ID</option>
                                        </select>
                                    </td>
                                    <td>
                                        <input type="text" name="sku_pattern[parts][<?php echo $idx; ?>][value]" value="<?php echo esc_attr( $part['value'] ?? '' ); ?>" class="regular-text part-value" <?php if ( $part['type'] !== 'static' ) echo 'style="display:none;"'; ?>>
                                    </td>
                                    <td><button type="button" class="button remove-row">حذف</button></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                    <tfoot>
                        <tr>
                            <td colspan="3"><button type="button" class="button add-sku-row">+ افزودن بخش</button></td>
                        </tr>
                    </tfoot>
                </table>

                <p style="margin-top:10px;">
                    <label>جداکنندهٔ بخش‌ها: <input type="text" name="sku_pattern[part_delimiter]" value="<?php echo esc_attr( $part_delimiter ); ?>" size="3"></label>
                    <label style="margin-left:15px;">جداکنندهٔ کلمات مخفف: <input type="text" name="sku_pattern[abbrev_delimiter]" value="<?php echo esc_attr( $abbrev_delimiter ); ?>" size="3"></label>
                    <label style="margin-left:15px;">طول مخفف‌سازی: <input type="number" name="sku_pattern[abbrev_length]" value="<?php echo esc_attr( $abbrev_length ); ?>" min="1" max="10" step="1" style="width:70px;"></label>
                </p>
            </td>
        </tr>
        <?php
    }

    /**
     * فیلد روزهای زمان‌بندی (بدون تغییر)
     */
    private function field_schedule_days( $selected_days ) {
        $days = array(
            0 => 'یکشنبه',
            1 => 'دوشنبه',
            2 => 'سه‌شنبه',
            3 => 'چهارشنبه',
            4 => 'پنج‌شنبه',
            5 => 'جمعه',
            6 => 'شنبه',
        );
        ?>
        <tr>
            <th scope="row">روزهای زمان‌بندی</th>
            <td>
                <div class="mss-checkbox-grid" style="grid-template-columns: repeat(auto-fill, minmax(100px, 1fr));">
                    <?php foreach ( $days as $value => $label ) : ?>
                        <label>
                            <input type="checkbox" name="schedule_days[]" value="<?php echo $value; ?>" <?php checked( in_array( (int) $value, (array) $selected_days, true ) ); ?>>
                            <?php echo esc_html( $label ); ?>
                        </label>
                    <?php endforeach; ?>
                </div>
            </td>
        </tr>
        <?php
    }

    // -------------------------------------------------------------------------
    // CSS و JavaScript
    // -------------------------------------------------------------------------

    private function get_admin_css() {
        return '
        .mss-section {
            border: 1px solid #c3c4c7;
            background: #fff;
            padding: 0 12px 12px;
            margin-bottom: 20px;
            border-radius: 4px;
        }
        .mss-section-title {
            font-size: 1.15em;
            font-weight: 600;
            padding: 8px 0;
            margin-bottom: 8px;
            display: block;
            border-bottom: 1px solid #eee;
            width: 100%;
        }
        .mss-section .form-table {
            margin-top: 0;
        }
        .mss-section .form-table th {
            width: 200px;
            padding: 10px 10px 10px 0;
        }
        .mss-checkbox-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(160px, 1fr));
            gap: 6px 12px;
            margin-top: 6px;
        }
        .mss-checkbox-grid label {
            display: flex;
            align-items: center;
            margin: 0;
            padding: 4px 0;
        }
        .mss-checkbox-actions {
            margin-bottom: 4px;
        }
        .mss-checkbox-actions .button {
            margin-right: 4px;
        }
        .chosen-container {
            min-width: 200px;
        }
        ';
    }

    private function get_combined_js() {
        return $this->inline_repeater_js() . $this->get_select_all_js();
    }

    private function inline_repeater_js() {
        return '
        jQuery(function($) {
            // ---------- نگاشت دسته‌بندی‌ها ----------
            var catTable = $("#category-map-table");

            $(".add-cat-row").click(function() {
                var tbody = catTable.find("tbody");
                var templateRow = tbody.find("tr.cat-map-row[style*=\'display:none\']").first();
                if (templateRow.length === 0) return;

                var visibleRows = tbody.find("tr.cat-map-row:visible").length;
                var newIndex = visibleRows;

                var newRow = templateRow.clone();

                newRow.find("[name*=\'CAT_INDEX\']").each(function() {
                    var oldName = $(this).attr("name");
                    if (oldName) {
                        $(this).attr("name", oldName.replace(/CAT_INDEX/g, newIndex));
                    }
                });

                newRow.removeAttr("style").insertBefore(templateRow);
                newRow.find("input[type=text]").val("");

                var newSelect = newRow.find(".cat-dropdown");
                newSelect.addClass("chosen-select");
                newSelect.chosen({
                    width: "100%",
                    search_contains: true,
                    no_results_text: "نتیجه‌ای یافت نشد",
                    placeholder_text: "انتخاب/جستجو"
                });
            });

            $(document).on("click", ".cat-map-row .remove-row", function() {
                $(this).closest("tr").remove();
            });

            // ---------- الگوی SKU ----------
            $(document).on("click", ".sku-part-row .remove-row", function() {
                $(this).closest("tr").remove();
            });

            $(document).on("change", ".part-type", function() {
                var row = $(this).closest("tr");
                if ($(this).val() === "static") {
                    row.find(".part-value").show();
                } else {
                    row.find(".part-value").hide().val("");
                }
            });

            $(".add-sku-row").click(function() {
                var table = $("#sku-pattern-table");
                var tbody = table.find("tbody");
                var index = tbody.find("tr").length;
                var newRow = $(\'<tr class="sku-part-row">\' +
                    \'<td><select name="sku_pattern[parts][\' + index + \'][type]" class="part-type">\' +
                        \'<option value="title">Title</option>\' +
                        \'<option value="category">Category</option>\' +
                        \'<option value="product_id">Product ID</option>\' +
                        \'<option value="static">Static</option>\' +
                    \'</select></td>\' +
                    \'<td><input type="text" name="sku_pattern[parts][\' + index + \'][value]" class="regular-text part-value" style="display:none;"></td>\' +
                    \'<td><button type="button" class="button remove-row">حذف</button></td>\' +
                    \'</tr>\');
                tbody.append(newRow);
                newRow.find(".part-value").hide();
                newRow.find(".part-type").trigger("change");
            });

            // فعال‌سازی Chosen روی تمام سلکت‌های موجود
            $(".chosen-select").each(function() {
                var $this = $(this);
                if ($this.data("chosen")) {
                    $this.chosen("destroy");
                }
                $this.chosen({
                    width: "100%",
                    search_contains: true,
                    no_results_text: "نتیجه‌ای یافت نشد",
                    placeholder_text: "انتخاب/جستجو"
                });
            });

            $(document).on("click", ".cat-map-row .remove-row", function() {
                var row = $(this).closest("tr");
                var select = row.find(".chosen-select");
                if (select.length) {
                    select.chosen("destroy");
                }
                row.remove();
            });

            // ---------- قوانین قیمت‌گذاری جدید ----------
            var priceRulesTable = $("#price-rules-table");

            function updatePriorityNumbers() {
                priceRulesTable.find("tbody tr.price-rule-row:visible").each(function(index) {
                    $(this).find(".priority-cell").text(index + 1);
                });
            }

            $(".add-price-rule-row").click(function() {
                var tbody = priceRulesTable.find("tbody");
                var template = tbody.find("tr.price-rule-template");
                if (template.length === 0) return;

                var visibleRows = tbody.find("tr.price-rule-row:visible").length;
                var newIndex = visibleRows;

                var newRow = template.clone();
                newRow.removeClass("price-rule-template").removeAttr("style");
                newRow.find("input, select").prop("disabled", false);

                newRow.find("[name*=\'RULE_INDEX\']").each(function() {
                    var oldName = $(this).attr("name");
                    if (oldName) {
                        $(this).attr("name", oldName.replace(/RULE_INDEX/g, newIndex));
                    }
                });

                newRow.find("input[name*=\'[threshold]\']").val("");
                newRow.find("select[name*=\'[action]\']").val("import_update");
                newRow.find("input[name*=\'[coef1]\']").val("1");
                newRow.find("input[name*=\'[constant]\']").val("0");
                newRow.find("input[name*=\'[coef2]\']").val("1");
                newRow.insertBefore(template);
                updatePriorityNumbers();
            });

            $(document).on("click", ".price-rule-row .remove-row", function() {
                var row = $(this).closest("tr");
                if (row.hasClass("price-rule-template")) return;
                row.remove();
                updatePriorityNumbers();
            });

			// هشدار افزایشی بودن آستانه‌ها — فقط هنگام افزودن ردیف یا حذف
			function validateThresholds() {
				var rows = priceRulesTable.find("tbody tr.price-rule-row:visible").toArray();
				var prev = -Infinity;
				var ok = true;
				rows.forEach(function(row) {
					var val = parseFloat($(row).find("input[name*=\'[threshold]\']").val());
					if (!isNaN(val)) {
						if (val <= prev) {
							ok = false;
						}
						prev = val;
					}
				});
				return ok;
			}

			// نمایش خطا به‌صورت inline (نه alert بی‌نهایت)
			$(document).on("change", "input[name*=\'[threshold]\']", function() {
				var $row = $(this).closest("tr");
				var $allRows = priceRulesTable.find("tbody tr.price-rule-row:visible");
				var currentIndex = $allRows.index($row);
				var currentVal = parseFloat($(this).val());
				
				// پاک کردن خطاهای قبلی
				$allRows.find(".threshold-error").remove();
				$allRows.css("background-color", "");
				
				if (!isNaN(currentVal)) {
					// بررسی با ردیف بعدی
					var $nextRow = $allRows.eq(currentIndex + 1);
					if ($nextRow.length) {
						var nextVal = parseFloat($nextRow.find("input[name*=\'[threshold]\']").val());
						if (!isNaN(nextVal) && currentVal >= nextVal) {
							$row.css("background-color", "#fdd");
							$row.find("td:first").after("<span class=\"threshold-error\" style=\"color:red;font-size:11px;display:block;\">باید از مقدار سطر بعد کمتر باشد</span>");
						}
					}
					// بررسی با ردیف قبلی
					var $prevRow = $allRows.eq(currentIndex - 1);
					if ($prevRow.length) {
						var prevVal = parseFloat($prevRow.find("input[name*=\'[threshold]\']").val());
						if (!isNaN(prevVal) && currentVal <= prevVal) {
							$row.css("background-color", "#fdd");
							$row.find("td:first").after("<span class=\"threshold-error\" style=\"color:red;font-size:11px;display:block;\">باید از مقدار سطر قبل بیشتر باشد</span>");
						}
					}
				}
			});

            updatePriorityNumbers();
        });';
    }

    private function get_select_all_js() {
        return "
        jQuery(function($) {
            $('.mss-select-all').on('click', function() {
                var group = '#' + $(this).data('group');
                $(group + ' input[type=\"checkbox\"]').prop('checked', true);
            });
            $('.mss-deselect-all').on('click', function() {
                var group = '#' + $(this).data('group');
                $(group + ' input[type=\"checkbox\"]').prop('checked', false);
            });
        });
        ";
    }

    // -------------------------------------------------------------------------
    // ذخیره‌سازی و بازیابی
    // -------------------------------------------------------------------------

    public function save_profile( $post_id, $post ) {
        if ( ! isset( $_POST[ self::NONCE_NAME ] ) || ! wp_verify_nonce( $_POST[ self::NONCE_NAME ], 'save_source_profile' ) ) {
            Sync_Logger::log( 'Nonce verification failed for profile save: ' . $post_id, 'error' );
            return;
        }

        if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
            return;
        }

        if ( ! current_user_can( 'edit_post', $post_id ) ) {
            Sync_Logger::log( 'User lacks permission to edit profile: ' . $post_id, 'error' );
            return;
        }

        // فیلدهای ساده (price_multiplier و price_constant حذف شدند)
        $simple_fields = array(
            'extractor_id', 'source_currency',
            'create_attributes_as_local', 'shipping_class',
            'import_out_of_stock', 'new_product_status',
            'allowed_categories', 'disallowed_categories',
            'on_product_deleted', 'update_post_date', 'update_post_modified',
            'schedule_time', 'auth_username', 'auth_password',
            'product_author',
            'price_filter_enabled', 'price_filter_operator',
            'price_filter_value', 'price_filter_value2',
            'import_mode',
            'use_sku_pattern', 'match_legacy_sku', 'enable_duplicate_check',
        );

        $boolean_fields = array( 'create_attributes_as_local', 'import_out_of_stock', 'price_filter_enabled', 'use_sku_pattern', 'match_legacy_sku', 'enable_duplicate_check' );

        foreach ( $simple_fields as $field ) {
            $key = '_' . $field;
            if ( isset( $_POST[ $field ] ) ) {
                $value = sanitize_text_field( wp_unslash( $_POST[ $field ] ) );
                if ( in_array( $field, $boolean_fields, true ) ) {
                    $value = ( '1' === $value ) ? '1' : '0';
                }
                update_post_meta( $post_id, $key, $value );
            } else {
                if ( in_array( $field, $boolean_fields, true ) ) {
                    update_post_meta( $post_id, $key, '0' );
                } else {
                    delete_post_meta( $post_id, $key );
                }
            }
        }

        $textarea_fields = array( 'sitemap_url', 'blacklist_urls', 'whitelist_urls' );
        foreach ( $textarea_fields as $field ) {
            $key = '_' . $field;
            if ( isset( $_POST[ $field ] ) ) {
                update_post_meta( $post_id, $key, sanitize_textarea_field( wp_unslash( $_POST[ $field ] ) ) );
            } else {
                update_post_meta( $post_id, $key, '' );
            }
        }

        // ذخیره قوانین قیمت جدید
        if ( isset( $_POST['price_rules'] ) && is_array( $_POST['price_rules'] ) ) {
            $rules = array();
            foreach ( $_POST['price_rules'] as $row ) {
                if ( ! is_array( $row ) ) continue;
                $threshold = isset( $row['threshold'] ) ? sanitize_text_field( $row['threshold'] ) : '';
                if ( '' === $threshold || ! is_numeric( $threshold ) ) {
                    $threshold = null;
                } else {
                    $threshold = floatval( $threshold );
                }
                $action = isset( $row['action'] ) && in_array( $row['action'], array( 'import_update', 'update_only' ) ) ? $row['action'] : 'import_update';
                $coef1    = isset( $row['coef1'] ) ? floatval( $row['coef1'] ) : 1;
                $constant = isset( $row['constant'] ) ? floatval( $row['constant'] ) : 0;
                $coef2    = isset( $row['coef2'] ) ? floatval( $row['coef2'] ) : 1;
                $rules[] = compact( 'threshold', 'action', 'coef1', 'constant', 'coef2' );
            }
            update_post_meta( $post_id, '_price_rules', $rules );
        } else {
            update_post_meta( $post_id, '_price_rules', array() );
        }

        // فیلدهای چک‌باکس گروهی
        $checkbox_groups = array( 'first_import_fields', 'update_fields' );
        foreach ( $checkbox_groups as $field ) {
            $key = '_' . $field;
            if ( isset( $_POST[ $field ] ) && is_array( $_POST[ $field ] ) ) {
                $value = array_map( 'sanitize_text_field', wp_unslash( $_POST[ $field ] ) );
                update_post_meta( $post_id, $key, $value );
            } else {
                update_post_meta( $post_id, $key, array() );
            }
        }

        // روزهای زمان‌بندی
        if ( isset( $_POST['schedule_days'] ) && is_array( $_POST['schedule_days'] ) ) {
            $days = array_map( 'intval', $_POST['schedule_days'] );
            update_post_meta( $post_id, '_schedule_days', $days );
        } else {
            update_post_meta( $post_id, '_schedule_days', array() );
        }

        // الگوی SKU
        if ( isset( $_POST['sku_pattern'] ) ) {
            $sku = array();
            $sku['part_delimiter']   = sanitize_text_field( wp_unslash( $_POST['sku_pattern']['part_delimiter'] ?? '-' ) );
            $sku['abbrev_delimiter'] = sanitize_text_field( wp_unslash( $_POST['sku_pattern']['abbrev_delimiter'] ?? '' ) );
            $sku['abbrev_length']    = absint( $_POST['sku_pattern']['abbrev_length'] ?? 2 );
            $sku['parts']            = array();
            if ( isset( $_POST['sku_pattern']['parts'] ) && is_array( $_POST['sku_pattern']['parts'] ) ) {
                foreach ( $_POST['sku_pattern']['parts'] as $part ) {
                    $type = sanitize_text_field( $part['type'] ?? 'title' );
                    $value = ( 'static' === $type && ! empty( $part['value'] ) ) ? sanitize_text_field( $part['value'] ) : '';
                    $sku['parts'][] = array(
                        'type'  => $type,
                        'value' => $value,
                    );
                }
            }
            update_post_meta( $post_id, '_sku_pattern', $sku );
        }

        // نگاشت دسته‌بندی‌ها
        if ( isset( $_POST['category_map'] ) && is_array( $_POST['category_map'] ) ) {
            $map = array();
            foreach ( $_POST['category_map'] as $key => $row ) {
                if ( ! is_numeric( $key ) ) {
                    continue;
                }
                $source = sanitize_text_field( $row['source_name'] ?? '' );
                $target = absint( $row['target_id'] ?? 0 );
                if ( $target > 0 && $source !== '' ) {
                    $map[] = array(
                        'source_name' => $source,
                        'target_id'   => $target,
                    );
                }
            }
            update_post_meta( $post_id, '_category_map', $map );
        } else {
            update_post_meta( $post_id, '_category_map', array() );
        }

        Sync_Logger::log( 'Profile settings saved for source: ' . get_the_title( $post_id ), 'success' );
    }

    public static function get_profile( $profile_id ) {
        $data = self::$defaults;
        if ( ! $profile_id || get_post_type( $profile_id ) !== self::POST_TYPE ) {
            return $data;
        }

        $meta = get_post_meta( $profile_id );
        foreach ( self::$defaults as $key => $default ) {
            $meta_key = '_' . $key;
            if ( isset( $meta[ $meta_key ] ) ) {
                $value = maybe_unserialize( $meta[ $meta_key ][0] );

                switch ( $key ) {
                    case 'price_rules':
                        if ( is_array( $value ) ) {
                            $value = array_map( function( $rule ) {
                                return array(
                                    'threshold' => isset( $rule['threshold'] ) ? $rule['threshold'] : null,
                                    'action'    => isset( $rule['action'] ) && in_array( $rule['action'], array( 'import_update', 'update_only' ) ) ? $rule['action'] : 'import_update',
                                    'coef1'     => isset( $rule['coef1'] ) ? floatval( $rule['coef1'] ) : 1,
                                    'constant'  => isset( $rule['constant'] ) ? floatval( $rule['constant'] ) : 0,
                                    'coef2'     => isset( $rule['coef2'] ) ? floatval( $rule['coef2'] ) : 1,
                                );
                            }, $value );
                        } else {
                            $value = array();
                        }
                        break;
                    // سایر فیلدهای خاص (قبلی)
                    case 'create_attributes_as_local':
                    case 'import_out_of_stock':
                    case 'price_filter_enabled':
                    case 'use_sku_pattern':
                    case 'match_legacy_sku':
                    case 'enable_duplicate_check':
                    case 'dup_numeric_priority':
                    case 'dup_alphanumeric_priority':
                    case 'dup_equal_numeric_count':
                    case 'dup_partial_code_match':
                        $value = ( '1' === $value || true === $value );
                        break;
                    case 'first_import_fields':
                    case 'update_fields':
                    case 'schedule_days':
                        $value = is_array( $value ) ? $value : array();
                        break;
                    case 'category_map':
                        $value = is_array( $value ) ? $value : array();
                        break;
                    case 'sku_pattern':
                        $value = is_array( $value ) ? wp_parse_args( $value, self::$defaults['sku_pattern'] ) : self::$defaults['sku_pattern'];
                        break;
                    case 'disallowed_categories':
                    case 'extractor_id':
                    case 'sitemap_url':
                    case 'source_currency':
                    case 'new_product_status':
                    case 'on_product_deleted':
                    case 'shipping_class':
                        $value = (string) $value;
                        break;
                    case 'schedule_time':
                        $value = (string) $value;
                        break;
                    case 'product_author':
                        $value = (string) $value;
                        break;
                    case 'auth_username':
                    case 'auth_password':
                        $value = (string) $value;
                        break;
                    case 'price_filter_operator':
                    case 'import_mode':
                        $value = (string) $value;
                        break;
                    case 'price_filter_value':
                    case 'price_filter_value2':
                        $value = intval( $value );
                        break;
                    case 'blacklist_urls':
                    case 'whitelist_urls':
                    case 'dup_required_phrase':
                    case 'dup_delimiters':
                    case 'dup_exclude_strings':
                    case 'dup_conflict_groups':
                        $value = (string) $value;
                        break;
                    case 'dup_min_token_length':
                    case 'dup_min_score':
                    case 'dup_partial_match_min_length':
                        $value = intval( $value );
                        break;
                    // price_multiplier و price_constant دیگر وجود ندارند
                }
                $data[ $key ] = $value;
            }
        }
        return $data;
    }

    public static function get_all_profiles() {
        $posts = get_posts( array(
            'post_type'      => self::POST_TYPE,
            'posts_per_page' => -1,
            'fields'         => 'ids',
            'post_status'    => 'publish',
        ) );
        return $posts;
    }
}
