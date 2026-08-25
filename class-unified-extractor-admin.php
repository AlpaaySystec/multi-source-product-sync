<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/** One manual extraction screen for every registered source extractor. */
class MSS_Unified_Extractor_Admin {
	const MENU_SLUG = 'mss-product-extractor';

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'register_page' ), 9998 );
		add_action( 'admin_menu', array( __CLASS__, 'remove_legacy_pages' ), 9999 );
	}

	public static function register_page() {
		add_submenu_page(
			'edit.php?post_type=source_profile',
			'استخراج محصول',
			'استخراج محصول',
			'manage_options',
			self::MENU_SLUG,
			array( __CLASS__, 'render_page' )
		);
	}

	public static function remove_legacy_pages() {
		$slugs = array(
			'arvindshop-extractor', 'bermova-product-extractor', 'digify-product-extractor',
			'ivektools-extractor', 'jawaheran-extractor', 'mirzaeiwatch-product-extractor',
			'mixin-extractor', 'nilehyper-extractor', 'portal-product-extractor',
			'sazito-extractor', 'shikomod-extractor', 'timestorr-extractor',
			'vgr-iran-product-extractor', 'zippogift-extractor', 'zudlux-product-extractor',
		);
		foreach ( $slugs as $slug ) remove_submenu_page( 'edit.php?post_type=source_profile', $slug );
	}

	public static function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) return;
		$url = isset( $_POST['mss_product_url'] ) ? esc_url_raw( wp_unslash( $_POST['mss_product_url'] ) ) : '';
		$include_source = ! empty( $_POST['mss_include_source_data'] );
		$result = null; $error = ''; $extractor_name = '';

		if ( isset( $_POST['mss_extract_submit'] ) ) {
			check_admin_referer( 'mss_unified_extract_action', 'mss_unified_extract_nonce' );
			$class = self::class_for_url( $url );
			if ( ! $class || ! class_exists( $class ) || ! method_exists( $class, 'extract' ) ) {
				$error = 'برای این دامنه اکسترکتور ثبت‌شده‌ای پیدا نشد.';
			} else {
				$extractor_name = $class;
				if ( method_exists( $class, 'set_credentials' ) ) {
					$username = isset( $_POST['mss_auth_username'] ) ? sanitize_text_field( wp_unslash( $_POST['mss_auth_username'] ) ) : '';
					$password = isset( $_POST['mss_auth_password'] ) ? (string) wp_unslash( $_POST['mss_auth_password'] ) : '';
					if ( '' !== $username || '' !== $password ) call_user_func( array( $class, 'set_credentials' ), $username, $password );
				}
				try { $result = call_user_func( array( $class, 'extract' ), $url ); }
				catch ( \Throwable $e ) { $error = 'خطای استخراج: ' . $e->getMessage(); }
				if ( false === $result || ! is_array( $result ) ) { $result = null; if ( ! $error ) $error = 'استخراج محصول انجام نشد.'; }
				if ( is_array( $result ) && ! $include_source ) unset( $result['source_data'] );
			}
		}
		?>
		<div class="wrap mss-extractor-page" dir="rtl">
			<h1>استخراج اطلاعات محصول</h1>
			<div class="mss-extractor-toolbar">
				<form method="post" autocomplete="off">
					<?php wp_nonce_field( 'mss_unified_extract_action', 'mss_unified_extract_nonce' ); ?>
					<div class="mss-extractor-row">
						<input type="url" name="mss_product_url" value="<?php echo esc_attr( $url ); ?>" placeholder="لینک کامل صفحهٔ محصول" required>
						<button type="submit" name="mss_extract_submit" class="button button-primary button-hero">استخراج اطلاعات محصول</button>
						<label><input type="checkbox" name="mss_include_source_data" value="1" <?php checked( $include_source ); ?>> اطلاعات کامل و قابل اتکای منبع</label>
					</div>
					<details class="mss-auth"><summary>ورود زیپوگیفت (فقط برای لینک‌های zippogift.ir)</summary><div>
						<input type="text" name="mss_auth_username" placeholder="نام کاربری" autocomplete="username">
						<input type="password" name="mss_auth_password" placeholder="رمز عبور" autocomplete="current-password">
					</div></details>
				</form>
			</div>
			<?php if ( $error ) : ?><div class="notice notice-error"><p><?php echo esc_html( $error ); ?></p></div><?php endif; ?>
			<?php if ( $result ) self::render_product( $result, $extractor_name, $include_source ); ?>
		</div>
		<style>
		.mss-extractor-page{max-width:1280px}.mss-extractor-toolbar{position:sticky;top:32px;z-index:100;background:#f0f0f1;padding:12px 0 14px;margin-bottom:18px;border-bottom:1px solid #c3c4c7}.mss-extractor-row{display:flex;align-items:center;gap:12px;flex-wrap:wrap}.mss-extractor-row input[type=url]{flex:1;min-width:360px;height:46px}.mss-extractor-row .button{height:46px;margin:0}.mss-auth{margin-top:8px}.mss-auth div{display:flex;gap:8px;margin-top:8px}.mss-card{background:#fff;border:1px solid #dcdcde;border-radius:10px;padding:20px;margin:0 0 18px;box-shadow:0 1px 2px rgba(0,0,0,.04)}.mss-hero{display:grid;grid-template-columns:minmax(260px,400px) 1fr;gap:26px}.mss-hero img{width:100%;max-height:440px;object-fit:contain}.mss-table{width:100%;border-collapse:collapse}.mss-table th,.mss-table td{padding:10px;border-bottom:1px solid #eee;text-align:right;vertical-align:top}.mss-table th{width:210px;color:#50575e}.mss-html{line-height:2}.mss-html img{max-width:100%;height:auto}.mss-gallery{display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:12px}.mss-gallery img{width:100%;height:220px;object-fit:contain}.mss-source-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(360px,1fr));gap:14px}.mss-source-grid pre{white-space:pre-wrap;overflow-wrap:anywhere;max-height:520px;overflow:auto;background:#f6f7f7;padding:12px}.mss-wide{grid-column:1/-1}@media(max-width:782px){.mss-extractor-toolbar{top:46px}.mss-hero{grid-template-columns:1fr}.mss-extractor-row input[type=url]{min-width:100%}}
		</style>
		<?php
	}

	private static function class_for_url( $url ) {
		$host = strtolower( preg_replace( '/^www\./', '', (string) wp_parse_url( $url, PHP_URL_HOST ) ) );
		$map = array(
			'arvindshop.ir'=>'Mixin_Product_Extractor',
			'bermova.com'=>'Bermova_Product_Extractor','nilehyper.com'=>'NileHyper_Product_Extractor','mirzaeiwatch.ir'=>'MirzaeiWatch_Product_Extractor',
			'shikomod.com'=>'Shikomod_Product_Extractor','vgr-iran.com'=>'VGRIran_Product_Extractor','zippogift.ir'=>'ZippoGift_Product_Extractor',
			'zudlux.com'=>'Zudlux_Product_Extractor','jawaheran.ir'=>'Jawaheran_Product_Extractor','ivektools.com'=>'IvekTools_Product_Extractor',
			'time-storr.ir'=>'TimeStorr_Product_Extractor','timr-storr.ir'=>'TimeStorr_Product_Extractor',
		);
		if ( isset( $map[ $host ] ) ) return $map[ $host ];
		$platforms = self::platform_hosts();
		foreach ( $platforms as $class => $hosts ) if ( in_array( $host, $hosts, true ) ) return $class;
		return '';
	}

	private static function platform_hosts() {
		return array(
			'Mixin_Product_Extractor'=>array('alinaaccessory.ir','catooni.ir','grouccibag.ir','nozadi-brand.ir','catly-accessories.ir','javan-watch.ir','ducklingkids.ir'),
			'Sazito_Product_Extractor'=>array('maghazak.com','davidjonesonline.ir','xiaomixiaomi.ir','farshadsilver.com','gamerenter.ir'),
			'Portal_Product_Extractor'=>array('markazabzarco.ir','shikotip.ir','bamshi.ir','ayshem.ir','microtel.ir','nini-city.ir','pakhshafsay.ir','elgarstore.com','crystal-market.ir','modakstar.com'),
			'Digify_Product_Extractor'=>array('zimzi.ir','baazibaazar.com','mazhgame.ir','mortin.ir','kidilo.ir','dballoon.ir','kingbarg.com','kidaform.ir','ariyantoys.ir','maahdooz.ir','shiralat-kasra.com','ghahramanstore.com','abzarsanatnovin.com'),
		);
	}

	private static function render_product( $d, $class, $include_source ) {
		$qty = array_key_exists('stock_quantity',$d) && null !== $d['stock_quantity'] ? (string)$d['stock_quantity'] : 'نامشخص (در منبع اعلام نشده)';
		$manage = !array_key_exists('manage_stock',$d) || null === $d['manage_stock'] ? 'نامشخص (در منبع اعلام نشده)' : ($d['manage_stock']?'بله':'خیر');
		?>
		<div class="mss-card mss-hero"><div><?php if(!empty($d['featured_image'])):?><img src="<?php echo esc_url($d['featured_image']); ?>" alt=""><?php endif;?></div><div>
		<h2><?php echo esc_html($d['title']??''); ?></h2><table class="mss-table">
		<?php self::row('اکسترکتور',$class); self::row('شناسه محصول',$d['product_id']??''); self::row('SKU',$d['sku']??''); self::row('نوع محصول',$d['product_type']??''); self::row('قیمت اصلی',$d['regular_price']??0); self::row('قیمت با تخفیف',$d['sale_price']??null); self::row('واحد پول',$d['currency']??''); self::row('وضعیت موجودی',$d['stock_status']??'unknown'); self::row('تعداد موجودی',$qty); self::row('مدیریت موجودی',$manage); ?>
		</table></div></div>
		<div class="mss-card"><h2>توضیحات کوتاه</h2><div class="mss-html"><?php echo !empty($d['excerpt'])?wp_kses_post($d['excerpt']):'<p>اطلاعاتی در منبع اعلام نشده است.</p>';?></div></div>
		<div class="mss-card"><h2>توضیحات کامل</h2><div class="mss-html"><?php echo !empty($d['content'])?wp_kses_post($d['content']):'<p>اطلاعاتی در منبع اعلام نشده است.</p>';?></div></div>
		<?php self::render_attributes($d['attributes']??array()); self::render_variations($d['variations']??array()); self::render_gallery($d['gallery_images']??array()); ?>
		<?php if($include_source && !empty($d['source_data'])) self::render_source_data($d['source_data']); ?>
		<?php
	}

	private static function row($label,$value){ if(null===$value)$value='—'; elseif(is_bool($value))$value=$value?'بله':'خیر'; elseif(is_array($value))$value=implode('، ',array_map('strval',$value)); echo '<tr><th>'.esc_html($label).'</th><td>'.esc_html((string)$value).'</td></tr>'; }
	private static function render_attributes($items){ echo '<div class="mss-card"><h2>ویژگی‌ها</h2><table class="mss-table"><thead><tr><th>نام ویژگی</th><th>مقادیر</th><th>ویژگی واریانت</th></tr></thead><tbody>';foreach((array)$items as $a)echo '<tr><td>'.esc_html($a['name']??'').'</td><td>'.esc_html(implode('، ',(array)($a['values']??array()))).'</td><td>'.(!empty($a['used_for_variations'])?'بله':'خیر').'</td></tr>';echo '</tbody></table></div>'; }
	private static function render_variations($items){ if(!$items)return;echo '<div class="mss-card"><h2>واریانت‌ها</h2><table class="mss-table"><thead><tr><th>ویژگی</th><th>SKU</th><th>قیمت اصلی</th><th>قیمت تخفیفی</th><th>وضعیت</th><th>تعداد</th><th>مدیریت</th></tr></thead><tbody>';foreach($items as $v){$q=null!==($v['stock_quantity']??null)?$v['stock_quantity']:'نامشخص';$m=!array_key_exists('manage_stock',$v)||null===$v['manage_stock']?'نامشخص':($v['manage_stock']?'بله':'خیر');echo '<tr><td>'.esc_html($v['attributes_summary']??'').'</td><td>'.esc_html($v['sku']??'').'</td><td>'.esc_html($v['regular_price']??0).'</td><td>'.esc_html($v['sale_price']??'—').'</td><td>'.esc_html($v['stock_status']??'unknown').'</td><td>'.esc_html($q).'</td><td>'.esc_html($m).'</td></tr>';}echo '</tbody></table></div>'; }
	private static function render_gallery($images){if(!$images)return;echo '<div class="mss-card"><h2>گالری تصاویر</h2><div class="mss-gallery">';foreach($images as $u)echo '<a href="'.esc_url($u).'" target="_blank" rel="noopener"><img src="'.esc_url($u).'" alt=""></a>';echo '</div></div>';}
	private static function render_source_data($source){echo '<h2>اطلاعات کامل و قابل اتکای منبع</h2><div class="mss-source-grid">';foreach((array)$source as $key=>$value){echo '<section class="mss-card"><h3>'.esc_html(self::source_label($key)).'</h3><pre>'.esc_html(wp_json_encode($value,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_PRETTY_PRINT)).'</pre></section>';}echo '<section class="mss-card mss-wide"><h3>JSON کامل source_data</h3><pre>'.esc_html(wp_json_encode($source,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_PRETTY_PRINT)).'</pre></section></div>';}
	private static function source_label($key){$map=array('identity'=>'هویت محصول','document'=>'اطلاعات صفحه و سئو','product_content'=>'محتوای کامل محصول','product_ui'=>'اطلاعات رابط محصول','variation_selectors'=>'انتخاب‌گرهای ویژگی','woocommerce_variations'=>'دادهٔ کامل واریانت‌های ووکامرس','json_ld_product'=>'محصول JSON-LD','json_ld_documents'=>'تمام اسناد JSON-LD صفحه');return $map[$key]??str_replace('_',' ',(string)$key);}
}
