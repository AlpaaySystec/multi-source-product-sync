<?php
/**
 * Sync Dashboard – فاز ۴ (ویرایش نهایی با دکمهٔ توقف)
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Sync_Dashboard {

    const MENU_SLUG = 'sync-dashboard';

    public function __construct() {
        add_action( 'admin_menu', array( $this, 'add_dashboard_page' ) );
        add_action( 'admin_post_clear_sync_logs', array( $this, 'handle_clear_logs' ) );
        add_action( 'admin_post_clear_sync_reports', array( $this, 'handle_clear_reports' ) );
        add_action( 'admin_post_stop_sync', array( $this, 'handle_stop_sync' ) );
		add_action( 'wp_ajax_sync_get_progress', array( __CLASS__, 'ajax_get_progress' ) );
		add_action( 'wp_ajax_sync_get_tab_content', array( $this, 'ajax_get_tab_content' ) );
		add_action( 'wp_ajax_sync_start_profile', array( $this, 'ajax_start_sync' ) );
        add_action( 'wp_ajax_sync_stop_profile',  array( $this, 'ajax_stop_sync' ) );
		add_action( 'admin_post_reset_index', array( $this, 'handle_reset_index' ) );
        add_action( 'wp_ajax_sync_run_orphan_check', array( $this, 'ajax_run_orphan_check' ) );
        add_action( 'wp_ajax_sync_get_logs_table', array( $this, 'ajax_get_logs_table' ) );
		add_action( 'wp_ajax_sync_map_get_rows', array( $this, 'ajax_map_get_rows' ) );
		add_action( 'wp_ajax_sync_map_search_products', array( $this, 'ajax_map_search_products' ) );
		add_action( 'wp_ajax_sync_map_lookup_product', array( $this, 'ajax_map_lookup_product' ) );
		add_action( 'wp_ajax_sync_map_update_product', array( $this, 'ajax_map_update_product' ) );
		add_action( 'wp_ajax_sync_map_update_url', array( $this, 'ajax_map_update_url' ) );
		add_action( 'wp_ajax_sync_map_revert_url', array( $this, 'ajax_map_revert_url' ) );
		add_action( 'wp_ajax_sync_map_delete_row', array( $this, 'ajax_map_delete_row' ) );
        add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_dashboard_assets' ) );
    }

    public function add_dashboard_page() {
        add_submenu_page(
            'edit.php?post_type=source_profile',
            'داشبورد همگام‌سازی',
            'داشبورد',
            'manage_options',
            self::MENU_SLUG,
            array( $this, 'render_dashboard' )
        );
    }

    public function render_dashboard() {
        ?>
        <div class="wrap">
		    <?php
			// نمایش پیام بازنشانی ایندکس (در صورت وجود)
			if ( isset( $_GET['reset_msg'] ) && ! empty( $_GET['reset_msg'] ) ) {
				$msg = sanitize_text_field( wp_unslash( $_GET['reset_msg'] ) );
				echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( $msg ) . '</p></div>';
			}
			
			// محاسبهٔ تعداد محصولات رها شده برای نشان‌گر قرمز
			$orphan_count = 0;
			if ( class_exists( 'Source_Profile_Manager' ) ) {
				$all_profile_ids = Source_Profile_Manager::get_all_profiles();
				foreach ( $all_profile_ids as $pid ) {
					$orphans = get_transient( 'sync_orphans_' . $pid );
					if ( is_array( $orphans ) ) {
						$orphan_count += count( $orphans );
					}
				}
			}
			if ( class_exists( 'MSS_Abandoned_Products' ) ) {
				$orphan_count += MSS_Abandoned_Products::count();
			}
			$dup_count = class_exists( 'MSS_Duplicate_Finder' ) ? MSS_Duplicate_Finder::count_pending() : 0;
			?>
            <h1>داشبورد همگام‌سازی</h1>

            <nav class="nav-tab-wrapper" id="sync-tabs">
                <a href="#" class="nav-tab nav-tab-active" data-tab="status">وضعیت فعلی</a>
                <a href="#" class="nav-tab" data-tab="reports">گزارش‌های همگام‌سازی</a>
                <a href="#" class="nav-tab" data-tab="logs">لاگ‌های سیستم</a>
				<a href="#" class="nav-tab" data-tab="access">دسترسی‌ها</a>
				<a href="#" class="nav-tab" data-tab="mappings">جدول نگاشت</a>
				<?php if ( $dup_count > 0 ) : ?>
					<a href="#" class="nav-tab" data-tab="duplicates" style="position:relative;">
						مدیریت داپلیکیت‌ها
						<span id="mss-dup-badge" style="background:#d63638;color:#fff;border-radius:50%;padding:0 5px;font-size:11px;margin-right:4px;"><?php echo (int) $dup_count; ?></span>
					</a>
				<?php else : ?>
					<a href="#" class="nav-tab" data-tab="duplicates">مدیریت داپلیکیت‌ها</a>
				<?php endif; ?>
				<?php if ( $orphan_count > 0 ) : ?>
                    <a href="#" class="nav-tab" data-tab="orphans" style="position:relative;">
                        محصولات رها شده
                        <span style="background:red;color:#fff;border-radius:50%;padding:0 5px;font-size:11px;margin-right:4px;"><?php echo $orphan_count; ?></span>
                    </a>
                <?php else : ?>
                    <a href="#" class="nav-tab" data-tab="orphans">محصولات رها شده</a>
                <?php endif; ?>
            </nav>

            <div id="sync-tab-content" style="margin-top:20px;">
                <div class="sync-loading" style="text-align:center; padding:30px;">
                    <span class="spinner is-active"></span> در حال بارگذاری...
                </div>
            </div>
        </div>

        <?php
        $ajax_nonce      = wp_create_nonce( 'sync_dashboard_ajax' );
        $toggle_nonce    = wp_create_nonce( 'sync_toggle_action' );
        ?>

        <script type="text/javascript">
        jQuery(document).ready(function($) {
            var $content = $('#sync-tab-content');
            var ajaxurl = '<?php echo admin_url( 'admin-ajax.php' ); ?>';
            var sync_ajax_nonce = '<?php echo esc_js( $ajax_nonce ); ?>';
            var toggle_nonce = '<?php echo esc_js( $toggle_nonce ); ?>';

            // لود کردن تب فعال هنگام بارگذاری صفحه
            function loadTab(tabName) {
                var pageUrl = new URL(window.location.href);
                pageUrl.searchParams.set('tab', tabName);
                window.history.replaceState({}, '', pageUrl.toString());
                $content.html('<div class="sync-loading" style="text-align:center; padding:30px;"><span class="spinner is-active"></span> در حال بارگذاری...</div>');
                var requestData = { action: 'sync_get_tab_content', tab: tabName };
                if (tabName === 'duplicates' && pageUrl.searchParams.get('profile_id')) requestData.profile_id = pageUrl.searchParams.get('profile_id');
                $.get(ajaxurl, requestData, function(response) {
                    if (response.success) {
                        $content.html(response.data.html);
                        // اگر تب وضعیت فعال شد، polling پیشرفت رو استارت بزن
                        if (tabName === 'status') {
                            startProgressPolling();
                            bindToggleButtons();
                        } else {
                            stopProgressPolling();
                        }
                    } else {
                        $content.html('<p>خطا در بارگذاری محتوا.</p>');
                    }
                });
            }

            // فعال‌سازی polling پیشرفت
            var progressInterval;
            function startProgressPolling() {
                stopProgressPolling();
                updateProgress();
                progressInterval = setInterval(updateProgress, 3000);
            }
            function stopProgressPolling() {
                if (progressInterval) clearInterval(progressInterval);
            }

            function updateProgress() {
                $('.sync-row').each(function() {
                    var $row = $(this);
                    var profileId = $row.data('profile-id');
                    var $statusSpan = $row.find('.sync-status span').last();
                    var statusText = $statusSpan.text().trim();
                    if (statusText !== 'در حال اجرا') return;

                    $.get(ajaxurl, {
                        action: 'sync_get_progress',
                        profile_id: profileId,
                        _ajax_nonce: sync_ajax_nonce
                    }, function(response) {
                        if (response.success && response.data) {
                            var data = response.data;
                            if (!data.is_running) {
                                if (data.should_redirect_duplicates) {
                                    $('#sync-tabs .nav-tab').removeClass('nav-tab-active');
                                    $('#sync-tabs .nav-tab[data-tab="duplicates"]').addClass('nav-tab-active');
                                    loadTab('duplicates');
                                } else {
                                    loadTab('status');
                                }
                                return;
                            }
                            if (data.progress && data.progress.total) {
                                var p = data.progress;
                                var processed = p.processed || 0;
                                var total = p.total || 0;
                                var percent = total > 0 ? Math.round((processed / total) * 100) : 0;
                                $row.find('.sync-progress').text(processed + ' / ' + total + ' (' + percent + '%)');
                            }
                        }
                    });
                });
            }

            // دکمه‌های شروع/توقف
            function bindToggleButtons() {
                $('.sync-toggle-btn').off('click').on('click', function(e) {
                    e.preventDefault();
                    var $btn = $(this);
                    var profileId = $btn.data('profile-id');
                    var action = $btn.data('action'); // 'start' or 'stop'

                    $btn.prop('disabled', true);

                    $.post(ajaxurl, {
                        action: action === 'start' ? 'sync_start_profile' : 'sync_stop_profile',
                        profile_id: profileId,
                        _ajax_nonce: toggle_nonce
                    }, function(response) {
                        if (response.success) {
                            // بعد از شروع/توقف، tab status را دوباره لود می‌کنیم
                            loadTab('status');
                        } else {
                            alert('خطا: ' + (response.data || 'عملیات ناموفق'));
                            $btn.prop('disabled', false);
                        }
                    }).fail(function() {
                        alert('خطای ارتباط با سرور');
                        $btn.prop('disabled', false);
                    });
                });
            }

            // کلیک روی تب‌ها
            $('#sync-tabs').on('click', '.nav-tab', function(e) {
                e.preventDefault();
                var $this = $(this);
                if ($this.hasClass('nav-tab-active')) return;
                $('#sync-tabs .nav-tab').removeClass('nav-tab-active');
                $this.addClass('nav-tab-active');
                var tab = $this.data('tab');
                loadTab(tab);
            });

            // بارگذاری اولیه تب active یا تب خواسته‌شده در URL
            var requestedTab = new URLSearchParams(window.location.search).get('tab');
            if (requestedTab && $('#sync-tabs .nav-tab[data-tab="' + requestedTab + '"]').length) {
                $('#sync-tabs .nav-tab').removeClass('nav-tab-active');
                $('#sync-tabs .nav-tab[data-tab="' + requestedTab + '"]').addClass('nav-tab-active');
            }
            var initialTab = $('#sync-tabs .nav-tab-active').data('tab') || 'status';
            loadTab(initialTab);

            // فیلتر لاگ‌ها با AJAX
            $('#sync-tab-content').on('change', '#log-level-filter', function() {
                var level = $(this).val();
                // فقط بخش جدول لاگ‌ها را به‌روز می‌کنیم
                $.get(ajaxurl, {
                    action: 'sync_get_logs_table',
                    log_level: level,
                    _ajax_nonce: sync_ajax_nonce
                }, function(response) {
                    if (response.success) {
                        $('#logs-table-container').html(response.data.html);
                    }
                });
            });

            // دکمهٔ بررسی مجدد orphans
            $('#sync-tab-content').on('click', '#recheck-orphans', function(e) {
                e.preventDefault();
                var $btn = $(this);
                $btn.prop('disabled', true).text('در حال بررسی...');
                $.post(ajaxurl, {
                    action: 'sync_run_orphan_check',
                    _ajax_nonce: sync_ajax_nonce
                }, function(response) {
                    if (response.success) {
                        loadTab('orphans'); // بازخوانی تب orphans
                    } else {
                        alert('خطا در بررسی.');
                        $btn.prop('disabled', false).text('بررسی مجدد محصولات رها شده');
                    }
                }).fail(function() {
                    alert('خطای ارتباط با سرور');
                    $btn.prop('disabled', false).text('بررسی مجدد محصولات رها شده');
                });
            });

        });
        </script>
        <?php
    }

    /* ------------------------------------------------------------------ */
    /*  Tab: وضعیت فعلی                                      */
    /* ------------------------------------------------------------------ */
    private function render_status_tab() {
        if ( ! class_exists( 'Source_Profile_Manager' ) ) {
            echo '<div class="notice notice-error"><p>کلاس Source_Profile_Manager در دسترس نیست.</p></div>';
            return;
        }

        $profile_ids = Source_Profile_Manager::get_all_profiles();

        if ( empty( $profile_ids ) ) {
            echo '<p>هیچ پروفایل منبعی تعریف نشده است.</p>';
            return;
        }

        $total_source    = 0;
        $total_imported  = 0;
        $total_instock   = 0;

        ?>
        <table class="widefat striped sync-status-table" style="width:100%;">
            <thead>
                <tr>
                    <th>نام پروفایل</th>
                    <th>آخرین همگام‌سازی</th>
                    <th>محصولات منبع</th>
                    <th>وارد شده</th>
                    <th>موجود</th>
                    <th>وضعیت</th>
                    <th>پیشرفت</th>
                    <th>نتیجهٔ آخرین</th>
                    <th>عملیات</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ( $profile_ids as $profile_id ) : ?>
                    <?php
                    $title          = get_the_title( $profile_id );
                    $last_sync      = get_post_meta( $profile_id, '_last_sync', true );
                    $lock           = get_option( 'sync_lock_' . $profile_id, false );
                    $is_running     = (bool) $lock;
                    $progress       = get_option( 'sync_progress_' . $profile_id, null );

                    // آمار تعداد محصولات
                    $source_count   = (int) get_post_meta( $profile_id, '_last_sync_total_found', true );
                    $imported_query = new WP_Query( array(
                        'post_type'      => 'product',
                        'posts_per_page' => -1,
                        'fields'         => 'ids',
                        'meta_key'       => '_source_profile_id',
                        'meta_value'     => $profile_id,
                        'no_found_rows'  => false,
                    ) );
                    $imported_count = $imported_query->found_posts;

                    $instock_query  = new WP_Query( array(
                        'post_type'      => 'product',
                        'posts_per_page' => -1,
                        'fields'         => 'ids',
                        'meta_query'     => array(
                            'relation' => 'AND',
                            array( 'key' => '_source_profile_id', 'value' => $profile_id ),
                            array( 'key' => '_stock_status',        'value' => 'instock' ),
                        ),
                        'no_found_rows'  => false,
                    ) );
                    $instock_count  = $instock_query->found_posts;

                    $total_source   += $source_count;
                    $total_imported += $imported_count;
                    $total_instock  += $instock_count;

                    // پیشرفت
                    $progress_text = '—';
                    if ( $is_running && $progress && isset( $progress['total'], $progress['processed'] ) ) {
                        $percent = ( $progress['total'] > 0 ) ? round( ( $progress['processed'] / $progress['total'] ) * 100 ) : 0;
                        $progress_text = sprintf( '%d / %d (%d%%)', $progress['processed'], $progress['total'], $percent );
                    }

                    // نتیجهٔ آخرین
                    $report = get_transient( 'sync_report_' . $profile_id );
                    $report_summary = '—';
                    if ( is_array( $report ) && isset( $report['created'], $report['updated'], $report['failed'] ) ) {
                        $report_summary = sprintf( 'ایجاد: %d, بروزرسانی: %d, خطا: %d', $report['created'], $report['updated'], $report['failed'] );
                    }

                    // دکمه شروع/توقف
                    $button_text  = $is_running ? 'توقف' : 'اجرای دستی';
                    $button_class = $is_running ? 'button delete sync-toggle-btn' : 'button-primary sync-toggle-btn';
                    $data_action  = $is_running ? 'stop' : 'start';
                    ?>
                    <tr class="sync-row" data-profile-id="<?php echo esc_attr( $profile_id ); ?>">
                        <td><strong><a href="<?php echo get_edit_post_link( $profile_id ); ?>"><?php echo esc_html( $title ); ?></a></strong></td>
                        <td><?php echo esc_html( $last_sync ?: '—' ); ?></td>
                        <td><?php echo esc_html( $source_count ); ?></td>
                        <td><?php echo esc_html( $imported_count ); ?></td>
                        <td><?php echo esc_html( $instock_count ); ?></td>
                        <td class="sync-status">
                            <?php if ( $is_running ) : ?>
                                <span class="spinner" style="visibility:visible; float:none; margin:0 5px 0 0;"></span>
                                <span style="color:green;">در حال اجرا</span>
                            <?php else : ?>
                                <span style="color:gray;">بیکار</span>
                            <?php endif; ?>
                        </td>
                        <td class="sync-progress"><?php echo esc_html( $progress_text ); ?></td>
                        <td class="sync-report"><?php echo esc_html( $report_summary ); ?></td>
                        <td>
                            <button type="button"
                                    class="<?php echo esc_attr( $button_class ); ?>"
                                    data-action="<?php echo esc_attr( $data_action ); ?>"
                                    data-profile-id="<?php echo esc_attr( $profile_id ); ?>">
                                <?php echo esc_html( $button_text ); ?>
                            </button>
						    <?php
							$reset_url = wp_nonce_url(
								admin_url( 'admin-post.php?action=reset_index&profile_id=' . $profile_id ),
								'reset_index_' . $profile_id
							);
							?>
							<a href="<?php echo esc_url( $reset_url ); ?>" class="button" style="margin-left:5px; display: inline-flex; align-items: center; gap: 2px;" title="پاک کردن ایندکس">
    							<span class="dashicons dashicons-update" style="font-size:16px; width:16px; height:16px; line-height:1; vertical-align: baseline;"></span>
							</a>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
            <tfoot>
                <tr style="font-weight:bold; background:#f1f1f1;">
                    <td>مجموع</td>
                    <td>—</td>
                    <td><?php echo esc_html( $total_source ); ?></td>
                    <td><?php echo esc_html( $total_imported ); ?></td>
                    <td><?php echo esc_html( $total_instock ); ?></td>
                    <td colspan="4">—</td>
                </tr>
            </tfoot>
        </table>
        <?php
    }

    /* ------------------------------------------------------------------ */
    /*  Tab: گزارش‌های همگام‌سازی                                          */
    /* ------------------------------------------------------------------ */

    private function render_reports_tab() {
        if ( ! class_exists( 'Source_Profile_Manager' ) ) {
            echo '<div class="notice notice-error"><p>کلاس Source_Profile_Manager در دسترس نیست.</p></div>';
            return;
        }

        $profile_ids = Source_Profile_Manager::get_all_profiles();
        $has_report = false;
        ?>
        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-bottom:15px;">
            <?php wp_nonce_field( 'clear_sync_reports', 'clear_reports_nonce' ); ?>
            <input type="hidden" name="action" value="clear_sync_reports">
            <?php submit_button( 'پاک کردن همهٔ گزارش‌ها', 'delete', 'clear_reports', false ); ?>
        </form>

        <table class="widefat striped">
            <thead>
                <tr>
                    <th>پروفایل</th>
                    <th>زمان پایان</th>
                    <th>ایجاد</th>
                    <th>بروزرسانی</th>
                    <th>خطا</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ( $profile_ids as $profile_id ) : ?>
                    <?php
                    $report = get_transient( 'sync_report_' . $profile_id );
                    if ( ! is_array( $report ) ) continue;
                    $has_report = true;
                    $title   = get_the_title( $profile_id );
                    $last_sync = get_post_meta( $profile_id, '_last_sync', true );
                    ?>
                    <tr>
                        <td><?php echo esc_html( $title ); ?></td>
                        <td><?php echo esc_html( $last_sync ?: '—' ); ?></td>
                        <td><?php echo esc_html( $report['created'] ?? 0 ); ?></td>
                        <td><?php echo esc_html( $report['updated'] ?? 0 ); ?></td>
                        <td><?php echo esc_html( $report['failed'] ?? 0 ); ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if ( ! $has_report ) : ?>
                    <tr><td colspan="5">هیچ گزارشی ذخیره نشده است.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
        <?php
    }

    /* ------------------------------------------------------------------ */
    /*  Tab: لاگ‌های سیستم                                                */
    /* ------------------------------------------------------------------ */

    private function render_logs_tab( $current_filter = '' ) {
        if ( ! class_exists( 'Sync_Logger' ) ) {
            echo '<div class="notice notice-error"><p>کلاس Sync_Logger در دسترس نیست.</p></div>';
            return;
        }

        $all_logs = Sync_Logger::get_logs( 100 );

        if ( $current_filter && in_array( $current_filter, array( 'info', 'success', 'error', 'warning' ), true ) ) {
            $logs = array_filter( $all_logs, function( $entry ) use ( $current_filter ) {
                return ( $entry['level'] === $current_filter );
            } );
        } else {
            $logs = $all_logs;
        }

        ?>
        <div style="margin-bottom:15px; display:flex; align-items:center; gap:15px;">
            <label for="log-level-filter" style="margin-right:5px;">سطح لاگ:</label>
            <select id="log-level-filter">
                <option value="" <?php selected( $current_filter, '' ); ?>>همه</option>
                <option value="info" <?php selected( $current_filter, 'info' ); ?>>info</option>
                <option value="success" <?php selected( $current_filter, 'success' ); ?>>success</option>
                <option value="error" <?php selected( $current_filter, 'error' ); ?>>error</option>
                <option value="warning" <?php selected( $current_filter, 'warning' ); ?>>warning</option>
            </select>

            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline-block;">
                <?php wp_nonce_field( 'clear_sync_logs', 'clear_logs_nonce' ); ?>
                <input type="hidden" name="action" value="clear_sync_logs">
                <?php submit_button( 'پاک کردن همهٔ لاگ‌ها', 'delete', 'clear_logs', false ); ?>
            </form>
        </div>

        <div id="logs-table-container">
            <table class="widefat striped">
                <thead>
                    <tr>
                        <th style="width:140px;">زمان</th>
                        <th style="width:80px;">سطح</th>
                        <th>پیام</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ( empty( $logs ) ) : ?>
                        <tr><td colspan="3">لاگی برای نمایش وجود ندارد.</td></tr>
                    <?php else : ?>
                        <?php foreach ( $logs as $entry ) : ?>
                            <?php
                            $level_class = '';
                            switch ( $entry['level'] ) {
                                case 'error':   $level_class = 'color:red; font-weight:bold;'; break;
                                case 'warning': $level_class = 'color:orange;'; break;
                                case 'success': $level_class = 'color:green;'; break;
                            }
                            ?>
                            <tr>
                                <td><?php echo esc_html( $entry['time'] ); ?></td>
                                <td style="<?php echo esc_attr( $level_class ); ?>"><?php echo esc_html( $entry['level'] ); ?></td>
                                <td><?php echo esc_html( $entry['message'] ); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        <?php
    }

    /* ------------------------------------------------------------------ */
    /*  Handler: توقف همگام‌سازی                                            */
    /* ------------------------------------------------------------------ */

    public function handle_stop_sync() {
        if ( ! isset( $_POST['stop_sync_nonce'] ) || ! wp_verify_nonce( $_POST['stop_sync_nonce'], 'stop_sync' ) ) {
            wp_die( 'درخواست نامعتبر.', 'خطا', array( 'response' => 403 ) );
        }
        if ( ! current_user_can( 'manage_options' ) ) wp_die( 'دسترسی غیرمجاز.' );

        $profile_id = isset( $_POST['profile_id'] ) ? absint( $_POST['profile_id'] ) : 0;
        if ( ! $profile_id || get_post_type( $profile_id ) !== 'source_profile' ) wp_die( 'پروفایل نامعتبر.' );

        // ۱. برداشتن قفل
        delete_option( 'sync_lock_' . $profile_id );

		$session_id = get_option( 'sync_session_' . $profile_id, '' );
		if ( $session_id ) {
			delete_option( 'sync_processed_urls_' . $profile_id . '_' . $session_id );
			delete_option( 'sync_session_' . $profile_id );
		}
        // ۲. پاک‌سازی پیشرفت
        delete_option( 'sync_progress_' . $profile_id );

        // ۳. لغو تمام اکشن‌های مرتبط با Action Scheduler برای این پروفایل
        if ( function_exists( 'as_unschedule_all_actions' ) ) {
            as_unschedule_all_actions( 'sync_process_chunk', array( $profile_id, array() ) );
            as_unschedule_all_actions( 'sync_finalize',    array( $profile_id ) );
        }

        $message = 'همگام‌سازی پروفایل متوقف شد و تمام تسک‌های در صف آن لغو گردید.';
        wp_safe_redirect( add_query_arg( array(
            'post_type' => 'source_profile',
            'page'      => self::MENU_SLUG,
            'tab'       => 'status',
            'sync_msg'  => urlencode( $message ),
        ), admin_url( 'edit.php' ) ) );
        exit;
    }

    /* ------------------------------------------------------------------ */
    /*  Handler: پاک‌سازی لاگ‌ها                                           */
    /* ------------------------------------------------------------------ */

    public function handle_clear_logs() {
        if ( ! isset( $_POST['clear_logs_nonce'] ) || ! wp_verify_nonce( $_POST['clear_logs_nonce'], 'clear_sync_logs' ) ) {
            wp_die( 'درخواست نامعتبر.' );
        }
        if ( ! current_user_can( 'manage_options' ) ) wp_die( 'دسترسی غیرمجاز.' );
        if ( class_exists( 'Sync_Logger' ) ) Sync_Logger::clear_logs();

        wp_safe_redirect( add_query_arg( array(
            'post_type' => 'source_profile',
            'page'      => self::MENU_SLUG,
            'tab'       => 'logs',
        ), admin_url( 'edit.php' ) ) );
        exit;
    }

    /* ------------------------------------------------------------------ */
    /*  Handler: پاک‌سازی گزارش‌ها                                         */
    /* ------------------------------------------------------------------ */

    public function handle_clear_reports() {
        if ( ! isset( $_POST['clear_reports_nonce'] ) || ! wp_verify_nonce( $_POST['clear_reports_nonce'], 'clear_sync_reports' ) ) {
            wp_die( 'درخواست نامعتبر.' );
        }
        if ( ! current_user_can( 'manage_options' ) ) wp_die( 'دسترسی غیرمجاز.' );

        if ( class_exists( 'Source_Profile_Manager' ) ) {
            foreach ( Source_Profile_Manager::get_all_profiles() as $pid ) {
                delete_transient( 'sync_report_' . $pid );
            }
        }

        wp_safe_redirect( add_query_arg( array(
            'post_type' => 'source_profile',
            'page'      => self::MENU_SLUG,
            'tab'       => 'reports',
        ), admin_url( 'edit.php' ) ) );
        exit;
    }

	/**
	* AJAX: برگرداندن اطلاعات پیشرفت یک پروفایل
	*/
	public static function ajax_get_progress() {

		// بررسی nonce اختیاری برای امنیت
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'دسترسی غیرمجاز' );
		}
		check_ajax_referer( 'sync_dashboard_ajax', '_ajax_nonce' );

		$profile_id = isset( $_GET['profile_id'] ) ? absint( $_GET['profile_id'] ) : 0;
		if ( ! $profile_id || get_post_type( $profile_id ) !== 'source_profile' ) {
			wp_send_json_error( 'پروفایل نامعتبر' );
		}
		$progress = get_option( 'sync_progress_' . $profile_id, null );
		$lock = get_option( 'sync_lock_' . $profile_id, false );
		$is_running = (bool) $lock;
		if ( ! $is_running && ! $progress ) {
			$progress = get_transient( 'sync_report_' . $profile_id );
		}
		$should_redirect = ! $is_running && '1' === get_option( 'mss_duplicate_redirect_profile_' . $profile_id, '' );
		if ( $should_redirect ) {
			delete_option( 'mss_duplicate_redirect_profile_' . $profile_id );
		}
		wp_send_json_success( array(
			'is_running' => $is_running,
			'progress'   => $progress,
			'should_redirect_duplicates' => $should_redirect,
		) );
	}

	public function ajax_get_tab_content() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'دسترسی غیرمجاز' );
		}
		$tab = isset( $_GET['tab'] ) ? sanitize_text_field( $_GET['tab'] ) : 'status';
		ob_start();
		switch ( $tab ) {
			case 'status':
				$this->render_status_tab();
				break;
			case 'reports':
				$this->render_reports_tab();
				break;
			case 'logs':
                $log_level = isset( $_GET['log_level'] ) ? sanitize_text_field( wp_unslash( $_GET['log_level'] ) ) : '';
                $this->render_logs_tab( $log_level );
                break;
            case 'access':
                $this->render_access_tab();
                break;
			case 'mappings':
				$this->render_mappings_tab();
				break;
			case 'duplicates':
				$this->render_duplicates_tab();
				break;
			case 'orphans':
				$this->render_orphans_tab();
				break;
			default:
				$this->render_status_tab();
		}
		$html = ob_get_clean();
		wp_send_json_success( array( 'html' => $html ) );
	}

    private function render_access_tab() {
        if ( ! class_exists( 'MSS_Menu_Visibility' ) ) {
            echo '<p>کلاس مدیریت دسترسی در دسترس نیست.</p>';
            return;
        }
        $opts = MSS_Menu_Visibility::get_options();
        $current_user = wp_get_current_user()->user_login;
        ?>
        <div class="mss-access-settings" style="max-width:600px;">
            <form id="mss-access-form">
                <table class="form-table">
                    <tr>
                        <th scope="row">حالت نمایش منو</th>
                        <td>
                            <label>
                                <input type="radio" name="mss_mode" value="blacklist" <?php checked( $opts['mode'], 'blacklist' ); ?>>
                                نمایش به همه، به‌جز کاربران زیر (بلک‌لیست)
                            </label><br>
                            <label>
                                <input type="radio" name="mss_mode" value="whitelist" <?php checked( $opts['mode'], 'whitelist' ); ?>>
                                پنهان از همه، به‌جز کاربران زیر (وایت‌لیست)
                            </label>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="mss_users_list">نام‌های کاربری (کاما جدا کنید)</label></th>
                        <td>
                            <textarea id="mss_users_list" name="mss_users" rows="4" class="large-text"><?php echo esc_textarea( $opts['users'] ); ?></textarea>
                            <p class="description">
                                <strong>نام کاربری شما:</strong> <code><?php echo esc_html( $current_user ); ?></code>
                                (برای اطمینان از دسترسی، می‌توانید آن را در لیست قرار دهید)
                            </p>
                        </td>
                    </tr>
                </table>
                <p>
                    <button type="submit" class="button button-primary">ذخیره تنظیمات</button>
                    <span class="spinner" style="float:none; margin-top:0;"></span>
                    <span class="mss-save-message" style="margin-right:10px;"></span>
                </p>
            </form>
        </div>
        <script>
        jQuery(document).ready(function($) {
            $('#mss-access-form').on('submit', function(e) {
                e.preventDefault();
                var $btn = $(this).find('button[type="submit"]');
                var $spinner = $(this).find('.spinner');
                var $msg = $(this).find('.mss-save-message');

                $btn.prop('disabled', true);
                $spinner.addClass('is-active');
                $msg.text('');

                $.post(ajaxurl, {
                    action: 'mss_save_visibility',
                    mode: $('input[name="mss_mode"]:checked').val(),
                    users: $('#mss_users_list').val()
                }, function(response) {
                    $btn.prop('disabled', false);
                    $spinner.removeClass('is-active');
                    if (response.success) {
                        $msg.css('color', 'green').text(response.data);
                    } else {
                        $msg.css('color', 'red').text('خطا: ' + (response.data || ''));
                    }
                });
            });
        });
        </script>
        <?php
    }

	/* ------------------------------------------------------------------ */
	/*  Tab: جدول نگاشت (Product Mapper)                                   */
	/* ------------------------------------------------------------------ */
	private function render_mappings_tab() {
		if ( ! class_exists( 'Product_Mapper' ) || ! class_exists( 'Source_Profile_Manager' ) ) {
			echo '<p>ماژول جدول نگاشت در دسترس نیست.</p>';
			return;
		}

		$profile_ids  = Source_Profile_Manager::get_all_profiles();
		$requested_id = isset( $_GET['profile_id'] ) ? absint( $_GET['profile_id'] ) : 0;
		$profile_id   = ( $requested_id && in_array( $requested_id, $profile_ids, true ) ) ? $requested_id : ( $profile_ids[0] ?? 0 );
		$ajax_nonce   = wp_create_nonce( 'sync_dashboard_ajax' );
		?>
		<div class="mss-map-app" id="mss-map-app" data-profile-id="<?php echo esc_attr( $profile_id ); ?>">

		<?php if ( empty( $profile_ids ) ) : ?>
			<p>هیچ پروفایل منبعی هنوز ایجاد نشده است.</p>
		<?php else : ?>

			<p class="description">
				جدول نگاشت، اتصال بین لینک محصول در سایت مبدأ و شناسه محصول در سایت شما را نشان می‌دهد.
				می‌توانید هر ردیف را ویرایش یا حذف کنید؛ تمام تغییرات بلافاصله و به‌صورت خودکار ذخیره می‌شوند.
			</p>

			<div class="mss-map-toolbar">
				<label for="mss-map-profile">پروفایل:</label>
				<select id="mss-map-profile">
					<?php foreach ( $profile_ids as $pid ) : ?>
						<option value="<?php echo esc_attr( $pid ); ?>" <?php selected( $pid, $profile_id ); ?>><?php echo esc_html( get_the_title( $pid ) ); ?></option>
					<?php endforeach; ?>
				</select>

				<input type="text" id="mss-map-search" class="regular-text" placeholder="جست‌وجو در عنوان محصول، عنوان مبدأ، لینک یا شناسه محصول…" />
				<button type="button" class="button" id="mss-map-search-btn">جست‌وجو</button>
				<button type="button" class="button" id="mss-map-refresh-btn">بروزرسانی</button>
				<span class="spinner mss-map-spinner" style="float:none;"></span>
				<span id="mss-map-count" class="mss-map-count"></span>
			</div>

			<div id="mss-map-message" class="mss-map-message" style="display:none;"></div>

			<div class="mss-table-scroll">
				<table class="widefat striped mss-map-table">
					<thead>
						<tr>
							<th class="mss-map-col-num">#</th>
							<th class="mss-map-col-pid">شناسه محصول<br><small>(سایت من)</small></th>
							<th class="mss-map-col-title">عنوان محصول<br><small>(سایت من)</small></th>
							<th class="mss-map-col-src-title">عنوان محصول<br><small>(سایت مبدأ)</small></th>
							<th class="mss-map-col-url">لینک محصول در سایت مبدأ</th>
							<th class="mss-map-col-actions"></th>
						</tr>
					</thead>
					<tbody id="mss-map-tbody">
						<tr><td colspan="6" style="text-align:center;padding:20px;"><span class="spinner is-active" style="float:none;"></span> در حال بارگذاری…</td></tr>
					</tbody>
				</table>
			</div>

			<div class="mss-map-pagination" id="mss-map-pagination"></div>

		<?php endif; ?>
		</div>

		<script>
		jQuery(document).ready(function($) {
			var app = $('#mss-map-app');
			if ( ! $('#mss-map-profile').length ) return;

			var ajaxurl    = '<?php echo admin_url( 'admin-ajax.php' ); ?>';
			var nonce      = '<?php echo esc_js( $ajax_nonce ); ?>';
			var perPage    = 50;
			var state      = { profileId: parseInt( app.data('profile-id'), 10 ) || 0, page: 1, search: '', totalPages: 1 };
			var rowInitial = {}; // rowId -> { pid, title, sourceTitle, sourceUrl }
			var rowCurrent = {}; // rowId -> { title, sourceTitle, broken }

			/* ---------------- کمک‌توابع عمومی ---------------- */

			function escapeHtml( s ) {
				return String( s == null ? '' : s )
					.replace( /&/g, '&amp;' ).replace( /</g, '&lt;' ).replace( />/g, '&gt;' )
					.replace( /"/g, '&quot;' ).replace( /'/g, '&#039;' );
			}

			function setBusy( on ) {
				app.find( '.mss-map-spinner' ).toggleClass( 'is-active', on );
			}

			function setRowBusy( rowId, on ) {
				$( 'tr[data-row-id="' + rowId + '"]' ).toggleClass( 'mss-map-row-busy', on );
			}

			function showMessage( text, isError ) {
				var $m = $( '#mss-map-message' );
				if ( ! text ) { $m.hide(); return; }
				$m.text( text ).css( { color: isError ? '#b32d2e' : '#00a32a' } ).show();
				clearTimeout( showMessage._t );
				showMessage._t = setTimeout( function() { $m.fadeOut( 200 ); }, 4000 );
			}

			function showFieldError( $el, msg ) {
				$el.closest( 'td' ).find( '.mss-map-field-error' ).text( msg ).show();
			}
			function hideFieldError( $el ) {
				$el.closest( 'td' ).find( '.mss-map-field-error' ).hide();
			}

			/* ---------------- توکنایز و هایلایت کلمات مشترک بین ستون سوم و چهارم ---------------- */

			function tokenize( text ) {
				return String( text || '' )
					.split( /[\s\-_\/,،؛;|()\[\]«»"'.:؟!]+/u )
					.map( function( t ) { return t.trim(); } )
					.filter( Boolean );
			}

			// طبقه‌بندی نوع یک توکن مطابق قاعدهٔ کاربر:
			// عدد/ترکیبی (رقم به‌همراه یا بدون حرف لاتین، بدون حرف فارسی/عربی) → فیروزه‌ای
			// متن فارسی/عربی → زرد
			// متن لاتین → سبز چمنی
			function classifyToken( token ) {
				var hasDigit = /[0-9\u06F0-\u06F9\u0660-\u0669]/.test( token );
				var hasFaAr  = /[\u0621-\u064A\u066E-\u06D3\u06D5-\u06FF]/.test( token );
				var hasLatin = /[A-Za-z]/.test( token );
				if ( hasDigit && ! hasFaAr ) return 'num';
				if ( hasFaAr ) return 'fa';
				if ( hasLatin ) return 'latin';
				return null;
			}

			function commonTokens( a, b ) {
				var setB = {};
				tokenize( b ).forEach( function( t ) { setB[ t.toLowerCase() ] = true; } );
				var seen = {}, result = [];
				tokenize( a ).forEach( function( t ) {
					var key = t.toLowerCase();
					if ( setB[ key ] && ! seen[ key ] ) { seen[ key ] = true; result.push( t ); }
				} );
				return result;
			}

			function highlightHtml( text, matched ) {
				text = String( text == null ? '' : text );
				if ( ! text ) return '<span class="mss-map-empty">—</span>';
				if ( ! matched.length ) return escapeHtml( text );
				var uniq = matched.slice().sort( function( a, b ) { return b.length - a.length; } );
				var escapedPatterns = uniq.map( function( t ) { return t.replace( /[.*+?^${}()|[\]\\]/g, '\\$&' ); } );
				var re;
				try { re = new RegExp( '(' + escapedPatterns.join( '|' ) + ')', 'giu' ); }
				catch ( e ) { return escapeHtml( text ); }
				var parts = text.split( re );
				var html = '';
				for ( var i = 0; i < parts.length; i++ ) {
					if ( ! parts[ i ] ) continue;
					if ( i % 2 === 1 ) {
						var cls = classifyToken( parts[ i ] );
						var colorClass = cls === 'num' ? 'mss-map-hl-num' : ( cls === 'fa' ? 'mss-map-hl-fa' : ( cls === 'latin' ? 'mss-map-hl-latin' : '' ) );
						html += '<mark class="mss-map-hl ' + colorClass + '">' + escapeHtml( parts[ i ] ) + '</mark>';
					} else {
						html += escapeHtml( parts[ i ] );
					}
				}
				return html;
			}

			function updateTitleDisplay( rowId ) {
				var cur = rowCurrent[ rowId ];
				var $disp = $( '#mss-map-title-disp-' + rowId );
				if ( ! cur ) return;
				if ( cur.broken ) {
					$disp.addClass( 'mss-map-broken' ).text( '⚠ محصول یافت نشد — برای اتصال، شناسه یا عنوان جدید وارد کنید' );
				} else {
					$disp.removeClass( 'mss-map-broken' );
					var matched = commonTokens( cur.title, cur.sourceTitle );
					$disp.html( highlightHtml( cur.title, matched ) );
				}
			}

			function refreshRowHighlight( rowId ) {
				var cur = rowCurrent[ rowId ];
				if ( ! cur ) return;
				updateTitleDisplay( rowId );
				var matched = cur.broken ? [] : commonTokens( cur.title, cur.sourceTitle );
				$( '#mss-map-src-' + rowId ).html( highlightHtml( cur.sourceTitle, matched ) );
			}

			/* ---------------- وضعیت «ویرایش‌شده» (پس‌زمینه صورتی-قرمز) ---------------- */

			function refreshEditedState( rowId ) {
				var init = rowInitial[ rowId ];
				var $row = $( 'tr[data-row-id="' + rowId + '"]' );
				if ( ! init || ! $row.length ) return;

				var $pid = $row.find( '.mss-map-pid' );
				$pid.toggleClass( 'mss-map-edited', $.trim( $pid.val() ) !== String( init.pid ) );

				var $url = $row.find( '.mss-map-url' );
				$url.toggleClass( 'mss-map-edited', $.trim( $url.val() ) !== String( init.sourceUrl ).trim() );

				var curTitle = ( rowCurrent[ rowId ] || {} ).title;
				$row.find( '.mss-map-title-disp' ).toggleClass( 'mss-map-edited', String( curTitle ) !== String( init.title ) );
			}

			/* ---------------- رندر ردیف‌ها ---------------- */

			function rowHtml( row, index ) {
				var num     = ( state.page - 1 ) * perPage + index + 1;
				var pid     = parseInt( row.product_id, 10 ) || 0;
				var broken  = ! pid || row.local_title === null || typeof row.local_title === 'undefined';
				var localTitle = broken ? '' : row.local_title;

				var $tr = $( '<tr>' ).attr( 'data-row-id', row.id );
				$tr.append( $( '<td class="mss-map-col-num">' ).text( num ) );

				var $pidTd = $( '<td class="mss-map-col-pid">' );
				$pidTd.append( $( '<input type="text" inputmode="numeric" class="mss-map-pid" dir="ltr" />' ).val( pid || '' ) );
				$pidTd.append( '<div class="mss-map-field-error" style="display:none;"></div>' );
				$tr.append( $pidTd );

				var $titleTd = $( '<td class="mss-map-col-title">' );
				var $titleWrap = $( '<div class="mss-map-title-wrap">' );
				var $titleDisp = $( '<span class="mss-map-title-disp" tabindex="0" id="mss-map-title-disp-' + row.id + '"></span>' );
				var $editIcon  = $( '<span class="mss-map-edit-icon" title="جست‌وجو و انتخاب محصول دیگر">🔍</span>' );
				var $select    = $( '<select class="mss-map-title-select" style="width:100%;display:none;"></select>' );
				$titleWrap.append( $titleDisp ).append( $editIcon ).append( $select );
				$titleTd.append( $titleWrap );
				$titleTd.append( '<div class="mss-map-field-error" style="display:none;"></div>' );
				$tr.append( $titleTd );

				var $srcTd = $( '<td class="mss-map-col-src-title">' );
				$srcTd.append( $( '<span id="mss-map-src-' + row.id + '"></span>' ) );
				$tr.append( $srcTd );

				var $urlTd = $( '<td class="mss-map-col-url">' );
				$urlTd.append( $( '<input type="text" class="mss-map-url" dir="ltr" />' ).val( row.source_url || '' ) );
				$urlTd.append( '<div class="mss-map-field-error" style="display:none;"></div>' );
				$tr.append( $urlTd );

				var $actionsTd = $( '<td class="mss-map-col-actions">' );
				$actionsTd.append( $( '<button type="button" class="button-link mss-map-delete" title="حذف این نگاشت">🗑</button>' ) );
				$tr.append( $actionsTd );

				rowInitial[ row.id ] = { pid: pid || '', title: localTitle, sourceTitle: row.source_title || '', sourceUrl: row.source_url || '' };
				rowCurrent[ row.id ] = { title: localTitle, sourceTitle: row.source_title || '', broken: broken };

				return $tr;
			}

			function renderRows( data ) {
				rowInitial = {}; rowCurrent = {};
				var $tbody = $( '#mss-map-tbody' ).empty();
				if ( ! data.rows.length ) {
					$tbody.append( '<tr><td colspan="6" style="text-align:center;padding:20px;">هیچ نگاشتی برای این پروفایل ثبت نشده است.</td></tr>' );
				} else {
					data.rows.forEach( function( row, idx ) { $tbody.append( rowHtml( row, idx ) ); } );
					data.rows.forEach( function( row ) { refreshRowHighlight( row.id ); refreshEditedState( row.id ); } );
				}
				state.totalPages = data.total_pages || 1;
				$( '#mss-map-count' ).text( data.total + ' ردیف نگاشت' + ( state.search ? ' (فیلترشده)' : '' ) );
				renderPagination();
			}

			function renderPagination() {
				var $p = $( '#mss-map-pagination' ).empty();
				if ( state.totalPages <= 1 ) return;
				var $prev = $( '<button type="button" class="button">قبلی</button>' ).prop( 'disabled', state.page <= 1 );
				$prev.on( 'click', function() { loadRows( state.page - 1 ); } );
				var $info = $( '<span class="mss-map-page-info">' ).text( ' صفحه ' + state.page + ' از ' + state.totalPages + ' ' );
				var $next = $( '<button type="button" class="button">بعدی</button>' ).prop( 'disabled', state.page >= state.totalPages );
				$next.on( 'click', function() { loadRows( state.page + 1 ); } );
				$p.append( $prev ).append( $info ).append( $next );
			}

			function loadRows( page ) {
				if ( ! state.profileId ) return;
				state.page = page || 1;
				setBusy( true );
				$( '#mss-map-tbody' ).html( '<tr><td colspan="6" style="text-align:center;padding:20px;"><span class="spinner is-active" style="float:none;"></span> در حال بارگذاری…</td></tr>' );
				$.get( ajaxurl, { action: 'sync_map_get_rows', profile_id: state.profileId, page: state.page, per_page: perPage, search: state.search, _ajax_nonce: nonce } )
					.done( function( r ) {
						if ( ! r.success ) { $( '#mss-map-tbody' ).html( '<tr><td colspan="6">خطا در بارگذاری اطلاعات.</td></tr>' ); return; }
						renderRows( r.data );
					} )
					.fail( function() { $( '#mss-map-tbody' ).html( '<tr><td colspan="6">خطای ارتباط با سرور.</td></tr>' ); } )
					.always( function() { setBusy( false ); } );
			}

			/* ---------------- اعمال / بازگردانی تغییرات (همه ایجکسی) ---------------- */

			function commitProductChange( rowId, productId, knownTitle ) {
				var $row       = $( 'tr[data-row-id="' + rowId + '"]' );
				var $pidInput  = $row.find( '.mss-map-pid' );
				setRowBusy( rowId, true );
				$.post( ajaxurl, { action: 'sync_map_update_product', id: rowId, profile_id: state.profileId, product_id: productId, _ajax_nonce: nonce } )
					.done( function( r ) {
						if ( ! r.success ) { showFieldError( $pidInput, r.data || 'شناسه محصول نامعتبر است.' ); return; }
						hideFieldError( $pidInput );
						$pidInput.val( r.data.product_id );
						var broken = ! r.data.local_status;
						rowCurrent[ rowId ].title  = broken ? '' : r.data.local_title;
						rowCurrent[ rowId ].broken = broken;
						refreshRowHighlight( rowId );
						refreshEditedState( rowId );
					} )
					.fail( function() { showFieldError( $pidInput, 'خطای ارتباط با سرور' ); } )
					.always( function() { setRowBusy( rowId, false ); } );
			}

			function revertProduct( rowId ) {
				var init = rowInitial[ rowId ];
				if ( ! init || ! init.pid ) {
					$( 'tr[data-row-id="' + rowId + '"] .mss-map-pid' ).val( '' );
					return;
				}
				commitProductChange( rowId, init.pid, init.title );
			}

			function commitPidChange( rowId ) {
				var $row   = $( 'tr[data-row-id="' + rowId + '"]' );
				var $input = $row.find( '.mss-map-pid' );
				var val    = $.trim( $input.val() );
				var init   = rowInitial[ rowId ];
				if ( val === '' ) { revertProduct( rowId ); return; }
				if ( ! /^[0-9]+$/.test( val ) ) { showFieldError( $input, 'شناسه محصول باید عدد باشد.' ); return; }
				if ( val === String( init.pid ) ) { hideFieldError( $input ); refreshEditedState( rowId ); return; }
				hideFieldError( $input );
				commitProductChange( rowId, val, null );
			}

			function applyUrlChange( rowId, url ) {
				var $row      = $( 'tr[data-row-id="' + rowId + '"]' );
				var $urlInput = $row.find( '.mss-map-url' );
				var $src      = $( '#mss-map-src-' + rowId );
				var prevHtml  = $src.html();
				$src.html( '<span class="mss-map-loading">در حال دریافت عنوان از لینک…</span>' );
				setRowBusy( rowId, true );
				$.post( ajaxurl, { action: 'sync_map_update_url', id: rowId, profile_id: state.profileId, url: url, _ajax_nonce: nonce } )
					.done( function( r ) {
						if ( ! r.success ) { showFieldError( $urlInput, r.data || 'دریافت اطلاعات از این لینک ناموفق بود.' ); $src.html( prevHtml ); return; }
						hideFieldError( $urlInput );
						$urlInput.val( r.data.source_url );
						rowCurrent[ rowId ].sourceTitle = r.data.source_title;
						refreshRowHighlight( rowId );
						refreshEditedState( rowId );
					} )
					.fail( function() { showFieldError( $urlInput, 'خطای ارتباط با سرور' ); $src.html( prevHtml ); } )
					.always( function() { setRowBusy( rowId, false ); } );
			}

			function revertUrl( rowId ) {
				var init = rowInitial[ rowId ];
				if ( ! init ) return;
				setRowBusy( rowId, true );
				$.post( ajaxurl, { action: 'sync_map_revert_url', id: rowId, profile_id: state.profileId, url: init.sourceUrl, title: init.sourceTitle, _ajax_nonce: nonce } )
					.done( function( r ) {
						if ( ! r.success ) { showMessage( r.data || 'بازگردانی ناموفق بود.', true ); return; }
						var $row = $( 'tr[data-row-id="' + rowId + '"]' );
						hideFieldError( $row.find( '.mss-map-url' ) );
						$row.find( '.mss-map-url' ).val( init.sourceUrl );
						rowCurrent[ rowId ].sourceTitle = init.sourceTitle;
						refreshRowHighlight( rowId );
						refreshEditedState( rowId );
					} )
					.fail( function() { showMessage( 'خطای ارتباط با سرور', true ); } )
					.always( function() { setRowBusy( rowId, false ); } );
			}

			function commitUrlChange( rowId ) {
				var $row   = $( 'tr[data-row-id="' + rowId + '"]' );
				var $input = $row.find( '.mss-map-url' );
				var val    = $.trim( $input.val() );
				var init   = rowInitial[ rowId ];
				if ( val === '' ) { revertUrl( rowId ); return; }
				if ( val === init.sourceUrl ) { hideFieldError( $input ); refreshEditedState( rowId ); return; }
				if ( ! /^https?:\/\//i.test( val ) ) { showFieldError( $input, 'لینک باید با http:// یا https:// شروع شود.' ); return; }
				hideFieldError( $input );
				applyUrlChange( rowId, val );
			}

			/* ---------------- ویرایش عنوان (ستون سوم) با دراپ‌داون chosen/select2 ایجکسی ---------------- */

			function activateTitleEdit( rowId ) {
				var $row    = $( 'tr[data-row-id="' + rowId + '"]' );
				var $disp   = $row.find( '.mss-map-title-disp' );
				var $icon   = $row.find( '.mss-map-edit-icon' );
				var $select = $row.find( '.mss-map-title-select' );

				$disp.hide();
				$icon.hide();
				$select.show();

				if ( ! $select.data( 'select2' ) ) {
					$select.select2( {
						width: '100%',
						dir: 'rtl',
						placeholder: 'جست‌وجوی عنوان محصول در سایت من…',
						allowClear: true,
						minimumInputLength: 1,
						ajax: {
							url: ajaxurl, dataType: 'json', delay: 300,
							data: function( params ) { return { action: 'sync_map_search_products', term: params.term, _ajax_nonce: nonce }; },
							processResults: function( data ) { return { results: data }; }
						}
					} );
					$select.on( 'select2:select', function( e ) {
						var d = e.params.data;
						commitProductChange( rowId, d.id, d.text );
						deactivateTitleEdit( rowId );
					} );
					$select.on( 'select2:clear', function() {
						revertProduct( rowId );
						deactivateTitleEdit( rowId );
					} );
					$select.on( 'select2:close', function() {
						setTimeout( function() { deactivateTitleEdit( rowId ); }, 0 );
					} );
				}
				$select.select2( 'open' );
			}

			function deactivateTitleEdit( rowId ) {
				var $row = $( 'tr[data-row-id="' + rowId + '"]' );
				$row.find( '.mss-map-title-select' ).hide();
				$row.find( '.mss-map-title-disp' ).show();
				$row.find( '.mss-map-edit-icon' ).show();
			}

			/* ---------------- حذف ردیف ---------------- */

			function deleteRow( rowId ) {
				if ( ! window.confirm( 'آیا از حذف این ردیف نگاشت مطمئنید؟\nاین کار فقط ارتباط بین این لینک مبدأ و محصول را پاک می‌کند؛ خود محصول در سایت شما حذف نخواهد شد.' ) ) return;
				setRowBusy( rowId, true );
				$.post( ajaxurl, { action: 'sync_map_delete_row', id: rowId, profile_id: state.profileId, _ajax_nonce: nonce } )
					.done( function( r ) {
						if ( ! r.success ) { showMessage( r.data || 'حذف ناموفق بود.', true ); setRowBusy( rowId, false ); return; }
						delete rowInitial[ rowId ];
						delete rowCurrent[ rowId ];
						showMessage( 'ردیف حذف شد.', false );
						loadRows( state.page );
					} )
					.fail( function() { showMessage( 'خطای ارتباط با سرور', true ); setRowBusy( rowId, false ); } );
			}

			/* ---------------- اتصال رویدادها ---------------- */

			$( '#mss-map-tbody' )
				.on( 'input', '.mss-map-pid', function() { refreshEditedState( $( this ).closest( 'tr' ).data( 'row-id' ) ); hideFieldError( $( this ) ); } )
				.on( 'blur', '.mss-map-pid', function() { commitPidChange( $( this ).closest( 'tr' ).data( 'row-id' ) ); } )
				.on( 'keydown', '.mss-map-pid', function( e ) { if ( e.which === 13 ) { e.preventDefault(); $( this ).trigger( 'blur' ); } } )
				.on( 'input', '.mss-map-url', function() { refreshEditedState( $( this ).closest( 'tr' ).data( 'row-id' ) ); hideFieldError( $( this ) ); } )
				.on( 'blur', '.mss-map-url', function() { commitUrlChange( $( this ).closest( 'tr' ).data( 'row-id' ) ); } )
				.on( 'keydown', '.mss-map-url', function( e ) { if ( e.which === 13 ) { e.preventDefault(); $( this ).trigger( 'blur' ); } } )
				.on( 'click', '.mss-map-title-disp, .mss-map-edit-icon', function() { activateTitleEdit( $( this ).closest( 'tr' ).data( 'row-id' ) ); } )
				.on( 'keydown', '.mss-map-title-disp', function( e ) { if ( e.which === 13 || e.which === 32 ) { e.preventDefault(); activateTitleEdit( $( this ).closest( 'tr' ).data( 'row-id' ) ); } } )
				.on( 'click', '.mss-map-delete', function() { deleteRow( $( this ).closest( 'tr' ).data( 'row-id' ) ); } );

			$( '#mss-map-profile' ).on( 'change', function() {
				state.profileId = parseInt( $( this ).val(), 10 ) || 0;
				state.search = '';
				$( '#mss-map-search' ).val( '' );
				loadRows( 1 );
			} );
			$( '#mss-map-search-btn' ).on( 'click', function() { state.search = $.trim( $( '#mss-map-search' ).val() ); loadRows( 1 ); } );
			$( '#mss-map-search' ).on( 'keydown', function( e ) { if ( e.which === 13 ) { e.preventDefault(); $( '#mss-map-search-btn' ).trigger( 'click' ); } } );
			$( '#mss-map-refresh-btn' ).on( 'click', function() { loadRows( state.page ); } );

			loadRows( 1 );
		} );
		</script>

		<style>
		.mss-map-toolbar{display:flex;align-items:center;gap:10px;flex-wrap:wrap;margin:10px 0}
		.mss-map-count{color:#646970}
		.mss-map-message{padding:8px 12px;border-radius:4px;background:#f0f6fc;margin-bottom:10px}
		.mss-map-table th small{font-weight:400;color:#646970}
		.mss-map-col-num{width:44px;text-align:center}
		.mss-map-col-pid{width:120px}
		.mss-map-col-url{min-width:260px}
		.mss-map-col-actions{width:40px;text-align:center}
		.mss-map-table input.mss-map-pid,
		.mss-map-table input.mss-map-url{width:100%;box-sizing:border-box;transition:background-color .15s ease}
		.mss-map-title-wrap{display:flex;align-items:center;gap:6px;min-height:26px}
		.mss-map-title-disp{flex:1;cursor:pointer;padding:3px 5px;border-radius:3px;transition:background-color .15s ease;outline:none}
		.mss-map-title-disp:hover,.mss-map-title-disp:focus{background:#f0f0f1}
		.mss-map-title-disp.mss-map-edited{background-color:#ffd6d9!important}
		.mss-map-title-disp.mss-map-broken{color:#b32d2e;font-style:italic}
		.mss-map-edit-icon{cursor:pointer;opacity:.6;font-size:13px}
		.mss-map-edit-icon:hover{opacity:1}
		.mss-map-title-select{width:100%}
		.mss-map-field-error{color:#b32d2e;font-size:12px;margin-top:3px}
		.mss-map-empty{color:#8c8f94}
		.mss-map-loading{color:#646970;font-style:italic}
		.mss-map-row-busy{opacity:.55;pointer-events:none}
		input.mss-map-edited{background-color:#ffd6d9!important;border-color:#b32d2e!important}
		.mss-map-hl{border-radius:3px;padding:0 3px;color:#1d2327}
		.mss-map-hl-fa{background-color:#fff59d}
		.mss-map-hl-latin{background-color:#aed581}
		.mss-map-hl-num{background-color:#4dd0e1}
		.mss-map-pagination{display:flex;align-items:center;gap:10px;justify-content:center;margin-top:14px}
		.mss-map-page-info{color:#646970}
		</style>
		<?php
	}

	/**
	 * دریافت یک صفحه از ردیف‌های جدول نگاشت یک پروفایل (برای رندر AJAX در تب «جدول نگاشت»)
	 */
	public function ajax_map_get_rows() {
		check_ajax_referer( 'sync_dashboard_ajax', '_ajax_nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'دسترسی غیرمجاز' );
		}
		if ( ! class_exists( 'Product_Mapper' ) ) {
			wp_send_json_error( 'ماژول جدول نگاشت در دسترس نیست.' );
		}

		$profile_id = isset( $_GET['profile_id'] ) ? absint( $_GET['profile_id'] ) : 0;
		if ( ! $profile_id || get_post_type( $profile_id ) !== 'source_profile' ) {
			wp_send_json_error( 'پروفایل نامعتبر است.' );
		}

		$page     = isset( $_GET['page'] ) ? max( 1, absint( $_GET['page'] ) ) : 1;
		$per_page = isset( $_GET['per_page'] ) ? max( 1, min( 200, absint( $_GET['per_page'] ) ) ) : 50;
		$search   = isset( $_GET['search'] ) ? sanitize_text_field( wp_unslash( $_GET['search'] ) ) : '';

		$total       = Product_Mapper::count_for_profile( $profile_id, $search );
		$total_pages = max( 1, (int) ceil( $total / $per_page ) );
		$page        = min( $page, $total_pages );
		$rows_raw    = Product_Mapper::get_page_for_profile( $profile_id, $page, $per_page, $search );

		$rows = array();
		foreach ( $rows_raw as $r ) {
			$rows[] = array(
				'id'           => (int) $r['id'],
				'product_id'   => (int) $r['product_id'],
				'local_title'  => isset( $r['local_title'] ) ? $r['local_title'] : null,
				'local_status' => isset( $r['local_status'] ) ? $r['local_status'] : null,
				'source_title' => (string) $r['source_title'],
				'source_url'   => (string) $r['source_url'],
			);
		}

		wp_send_json_success( array(
			'rows'        => $rows,
			'total'       => $total,
			'page'        => $page,
			'total_pages' => $total_pages,
		) );
	}

	/**
	 * جست‌وجوی محصولات سایت من برای دراپ‌داون chosen/select2 ستون سوم جدول نگاشت
	 */
	public function ajax_map_search_products() {
		check_ajax_referer( 'sync_dashboard_ajax', '_ajax_nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json( array() );
		}
		$term = isset( $_GET['term'] ) ? sanitize_text_field( wp_unslash( $_GET['term'] ) ) : '';
		if ( '' === $term ) {
			wp_send_json( array() );
		}

		$ids = get_posts( array(
			'post_type'      => 'product',
			'post_status'    => array( 'publish', 'draft', 'pending', 'private' ),
			's'              => $term,
			'posts_per_page' => 20,
			'fields'         => 'ids',
			'orderby'        => 'title',
			'order'          => 'ASC',
		) );

		$results = array();
		foreach ( $ids as $pid ) {
			$results[] = array( 'id' => $pid, 'text' => get_the_title( $pid ) . ' (#' . $pid . ')' );
		}
		wp_send_json( $results );
	}

	/**
	 * دریافت عنوان یک محصول با شناسه
	 */
	public function ajax_map_lookup_product() {
		check_ajax_referer( 'sync_dashboard_ajax', '_ajax_nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'دسترسی غیرمجاز' );
		}
		$product_id = isset( $_GET['product_id'] ) ? absint( $_GET['product_id'] ) : 0;
		if ( ! $product_id || 'product' !== get_post_type( $product_id ) ) {
			wp_send_json_error( 'محصولی با این شناسه یافت نشد.' );
		}
		wp_send_json_success( array( 'product_id' => $product_id, 'title' => get_the_title( $product_id ) ) );
	}

	/**
	 * بروزرسانی شناسه محصول یک ردیف نگاشت (اعمال ادیت ستون دوم/سوم؛ همچنین برای undo استفاده می‌شود)
	 */
	public function ajax_map_update_product() {
		check_ajax_referer( 'sync_dashboard_ajax', '_ajax_nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'دسترسی غیرمجاز' );
		}
		if ( ! class_exists( 'Product_Mapper' ) ) {
			wp_send_json_error( 'ماژول جدول نگاشت در دسترس نیست.' );
		}

		$id         = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;
		$profile_id = isset( $_POST['profile_id'] ) ? absint( $_POST['profile_id'] ) : 0;
		$product_id = isset( $_POST['product_id'] ) ? absint( $_POST['product_id'] ) : 0;

		$result = Product_Mapper::admin_update_product( $id, $profile_id, $product_id );
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( $result->get_error_message() );
		}
		wp_send_json_success( $result );
	}

	/**
	 * بروزرسانی لینک مبدأ یک ردیف نگاشت: عنوان جدید به‌صورت زنده از لینک استخراج می‌شود.
	 */
	public function ajax_map_update_url() {
		check_ajax_referer( 'sync_dashboard_ajax', '_ajax_nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'دسترسی غیرمجاز' );
		}
		if ( ! class_exists( 'Product_Mapper' ) || ! class_exists( 'Source_Profile_Manager' ) ) {
			wp_send_json_error( 'ماژول جدول نگاشت در دسترس نیست.' );
		}

		$id         = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;
		$profile_id = isset( $_POST['profile_id'] ) ? absint( $_POST['profile_id'] ) : 0;
		$url        = isset( $_POST['url'] ) ? esc_url_raw( trim( wp_unslash( $_POST['url'] ) ) ) : '';

		if ( ! $profile_id || get_post_type( $profile_id ) !== 'source_profile' || '' === $url ) {
			wp_send_json_error( 'درخواست نامعتبر است.' );
		}

		$profile = Source_Profile_Manager::get_profile( $profile_id );
		$title   = self::fetch_source_title_for_url( $profile, $url );
		if ( is_wp_error( $title ) ) {
			wp_send_json_error( $title->get_error_message() );
		}

		$result = Product_Mapper::admin_update_url( $id, $profile_id, $url, $title );
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( $result->get_error_message() );
		}

		wp_send_json_success( array( 'source_url' => $url, 'source_title' => $title ) );
	}

	/**
	 * بازگردانی لینک/عنوان مبدأ به مقدار اولیه (undo) — بدون واکشی مجدد از شبکه.
	 */
	public function ajax_map_revert_url() {
		check_ajax_referer( 'sync_dashboard_ajax', '_ajax_nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'دسترسی غیرمجاز' );
		}
		if ( ! class_exists( 'Product_Mapper' ) ) {
			wp_send_json_error( 'ماژول جدول نگاشت در دسترس نیست.' );
		}

		$id         = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;
		$profile_id = isset( $_POST['profile_id'] ) ? absint( $_POST['profile_id'] ) : 0;
		$url        = isset( $_POST['url'] ) ? esc_url_raw( trim( wp_unslash( $_POST['url'] ) ) ) : '';
		$title      = isset( $_POST['title'] ) ? sanitize_text_field( wp_unslash( $_POST['title'] ) ) : '';

		$result = Product_Mapper::admin_update_url( $id, $profile_id, $url, $title );
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( $result->get_error_message() );
		}
		wp_send_json_success( array( 'source_url' => $url, 'source_title' => $title ) );
	}

	/**
	 * حذف یک ردیف نگاشت
	 */
	public function ajax_map_delete_row() {
		check_ajax_referer( 'sync_dashboard_ajax', '_ajax_nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'دسترسی غیرمجاز' );
		}
		if ( ! class_exists( 'Product_Mapper' ) ) {
			wp_send_json_error( 'ماژول جدول نگاشت در دسترس نیست.' );
		}

		$id         = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;
		$profile_id = isset( $_POST['profile_id'] ) ? absint( $_POST['profile_id'] ) : 0;

		if ( ! Product_Mapper::admin_delete_row( $id, $profile_id ) ) {
			wp_send_json_error( 'حذف ناموفق بود؛ ممکن است ردیف قبلاً حذف شده باشد.' );
		}
		wp_send_json_success( true );
	}

	/**
	 * استخراج زندهٔ عنوان محصول از یک لینک سایت مبدأ، بر اساس extractor پروفایل.
	 *
	 * @return string|WP_Error
	 */
	private static function fetch_source_title_for_url( $profile, $url ) {
		if ( empty( $profile['extractor_id'] ) ) {
			return new WP_Error( 'no_extractor', 'این پروفایل extractor مشخصی ندارد.' );
		}
		$extractors      = $GLOBALS['mss_extractors'] ?? array();
		$extractor_class = $extractors[ $profile['extractor_id'] ]['class'] ?? '';
		if ( empty( $extractor_class ) || ! class_exists( $extractor_class ) || ! method_exists( $extractor_class, 'extract' ) ) {
			return new WP_Error( 'no_extractor_class', 'کلاس استخراج‌کننده این پروفایل یافت نشد.' );
		}
		if ( method_exists( $extractor_class, 'set_credentials' ) ) {
			call_user_func( array( $extractor_class, 'set_credentials' ), $profile['auth_username'] ?? '', $profile['auth_password'] ?? '' );
		}
		$dto = call_user_func( array( $extractor_class, 'extract' ), $url );
		if ( ! is_array( $dto ) || empty( $dto['title'] ) ) {
			return new WP_Error( 'extract_failed', 'دریافت اطلاعات از این لینک ناموفق بود؛ لینک را بررسی کنید.' );
		}
		return $dto['title'];
	}

	/* ------------------------------------------------------------------ */
	/*  Tab: مدیریت داپلیکیت‌ها                                            */
	/* ------------------------------------------------------------------ */
	private function render_duplicates_tab() {
		if ( ! class_exists( 'MSS_Duplicate_Finder' ) || ! class_exists( 'Source_Profile_Manager' ) ) {
			echo '<p>ماژول مدیریت صف تأیید در دسترس نیست.</p>';
			return;
		}

		$profiles = MSS_Duplicate_Finder::get_pending_profiles();
		$has_queue_profiles = ! empty( $profiles );
		$available_ids = array_map( function( $item ) { return (int) $item['profile_id']; }, $profiles );
		$requested_id = isset( $_GET['profile_id'] ) ? absint( $_GET['profile_id'] ) : 0;
		$profile_id = $has_queue_profiles
			? ( in_array( $requested_id, $available_ids, true ) ? $requested_id : MSS_Duplicate_Finder::get_default_pending_profile() )
			: 0;
		$profile = Source_Profile_Manager::get_profile( $profile_id );
		$rules = MSS_Duplicate_Finder::sanitize_rules( $profile );
		$workspace = $profile_id ? MSS_Duplicate_Finder::get_duplicate_workspace( $profile_id ) : array();
		$selected_queue_count = 0;
		foreach ( $profiles as $item ) {
			if ( (int) $item['profile_id'] === $profile_id ) {
				$selected_queue_count = (int) $item['count'];
				break;
			}
		}
		$terms = get_terms( array( 'taxonomy' => 'product_cat', 'hide_empty' => false ) );
		$terms = is_wp_error( $terms ) ? array() : $terms;
		$authors = get_users( array( 'fields' => array( 'ID', 'display_name' ), 'capability' => 'edit_products' ) );
		$ajax_nonce = wp_create_nonce( 'sync_dashboard_ajax' );
		?>
		<div id="mss-approval-app" data-profile-id="<?php echo (int) $profile_id; ?>" data-has-profile="<?php echo $has_queue_profiles ? '1' : '0'; ?>">
			<div id="mss-dup-message" class="notice inline" style="display:none;"><p></p></div>

			<section class="mss-dup-section">
				<div class="mss-dup-profile-bar">
					<label for="mss-dup-profile"><strong>پروفایل فعال:</strong></label>
					<select id="mss-dup-profile" <?php disabled( ! $has_queue_profiles ); ?>>
						<?php if ( ! $has_queue_profiles ) : ?>
							<option value="">هیچ پروفایلی صف ایمپورت ندارد</option>
						<?php endif; ?>
						<?php foreach ( $profiles as $item ) : ?>
							<option value="<?php echo (int) $item['profile_id']; ?>" data-count="<?php echo (int) $item['count']; ?>" data-title="<?php echo esc_attr( $item['title'] ); ?>" <?php selected( $profile_id, $item['profile_id'] ); ?>><?php echo esc_html( $item['title'] . ' (' . $item['count'] . ')' ); ?></option>
						<?php endforeach; ?>
					</select>
					<button type="button" class="button mss-reset-queue" id="mss-reset-profile-queue" data-requires-profile="1">ریست کامل صف این پروفایل</button>
					<span class="mss-queue-notice"><?php if ( $has_queue_profiles ) : ?>این پروفایل <strong><?php echo $selected_queue_count; ?></strong> محصول در صف تأیید دارد.<?php else : ?>فعلاً پروفایل فعالی وجود ندارد؛ تنظیمات برای آشنایی و آماده‌سازی نمایش داده می‌شوند و پس از ایجاد صف فعال خواهند شد.<?php endif; ?></span>
				</div>

				<h2>۱. محدودکردن محصولات سایت برای مقایسه</h2>
				<p class="description">همه محصولات سایت قابل بررسی‌اند. با این فیلترها scope کاندیدها و نتایج جست‌وجوی هر ردیف را محدود کنید.</p>
				<div class="mss-filter-grid">
					<label>دسته‌بندی‌ها
						<select id="mss-filter-categories" multiple>
							<?php foreach ( $terms as $term ) : ?><option value="<?php echo (int) $term->term_id; ?>"><?php echo esc_html( $term->name ); ?></option><?php endforeach; ?>
						</select>
					</label>
					<label>از تاریخ انتشار<input type="date" id="mss-filter-date-from"></label>
					<label>تا تاریخ انتشار<input type="date" id="mss-filter-date-to"></label>
					<label>منتشرکننده
						<select id="mss-filter-authors" multiple>
							<?php foreach ( $authors as $author ) : ?><option value="<?php echo (int) $author->ID; ?>"><?php echo esc_html( $author->display_name ); ?></option><?php endforeach; ?>
						</select>
					</label>
					<label>عنوان شامل عبارت<input type="text" id="mss-filter-title" placeholder="عبارت موردنظر"></label>
				</div>
				<p><button type="button" class="button button-primary" id="mss-apply-filters">اعمال فیلترها</button> <span class="spinner mss-spinner"></span> <strong id="mss-filter-count"></strong></p>
			</section>

			<section class="mss-dup-section">
				<h2>۲. قواعد شناسایی داپلیکیت</h2>
				<div class="mss-rule-grid">
					<label>کاراکترهای جداکننده<input type="text" data-rule="dup_delimiters"></label>
					<label>حداقل طول کلمه<input type="number" min="0" data-rule="dup_min_token_length"></label>
					<label>حداقل امتیاز<input type="number" min="1" data-rule="dup_min_score"></label>
					<label>حداقل طول تطبیق زیررشته‌ای<input type="number" min="1" data-rule="dup_partial_match_min_length"></label>
					<label class="mss-wide">کلمات نادیده‌گرفته‌شده<textarea rows="3" data-rule="dup_exclude_strings" placeholder="با کاما جدا کنید"></textarea></label>
					<label class="mss-conflict-field">گروه‌های عبارت متعارض<textarea rows="5" data-rule="dup_conflict_groups" placeholder="هر گروه یک سطر؛ عبارت‌ها با کاما جدا شوند"></textarea></label>
				</div>
				<div class="mss-rule-checks">
					<label><input type="checkbox" data-rule="dup_numeric_priority"> اولویت رشته عددی</label>
					<label><input type="checkbox" data-rule="dup_alphanumeric_priority"> اولویت رشته عددی‌ـ‌حرفی</label>
					<label><input type="checkbox" data-rule="dup_equal_numeric_count"> تعداد رشته‌های عددی دو عنوان برابر باشد</label>
					<label><input type="checkbox" data-rule="dup_partial_code_match"> تطبیق زیررشته‌ای کد</label>
				</div>

				<h3>پیش‌نمایش زنده (حداکثر ۵ محصول صف)</h3>
				<table class="widefat striped" id="mss-preview-table"><thead><tr><th>محصول صف</th><th>تعداد کاندید</th><th>بهترین نتیجه</th><th>امتیاز</th></tr></thead><tbody><tr><td colspan="4">در حال محاسبه…</td></tr></tbody></table>
				<p class="mss-rule-actions">
					<button type="button" class="button" id="mss-clear-rules">پاک کردن ورودی‌ها</button>
					<button type="button" class="button" id="mss-load-rules" data-requires-profile="1">فراخوانی تنظیمات</button>
					<button type="button" class="button button-secondary" id="mss-show-results" data-requires-profile="1">نمایش نتایج</button>
					<button type="button" class="button button-primary" id="mss-save-show-results" data-requires-profile="1">ذخیره و نمایش نتایج</button>
					<span class="spinner mss-spinner"></span>
				</p>
			</section>

			<section class="mss-dup-section" id="mss-final-section" style="display:none;">
				<h2>۳. تعیین تکلیف محصولات صف</h2>
				<div id="mss-result-progress" class="mss-result-progress" style="display:none;">
					<progress value="0" max="100"></progress>
					<strong>آماده‌سازی محاسبه…</strong>
				</div>
				<div class="mss-bulk-bar">
					<label><input type="checkbox" id="mss-select-all"> انتخاب همهٔ این صفحه</label>
					<button type="button" class="button button-primary" id="mss-process-selected">پردازش موارد انتخاب‌شده بر اساس تصمیم هر سطر</button>
					<button type="button" class="button" id="mss-process-all">پردازش همهٔ این صفحه بر اساس تصمیم هر سطر</button>
					<span class="spinner mss-spinner"></span>
				</div>
				<div class="mss-table-scroll"><table class="widefat striped" id="mss-results-table"><thead><tr><th></th><th>محصول در صف</th><th>تعیین تکلیف</th><th>محصولات سایت من</th><th>عملیات</th></tr></thead><tbody></tbody></table></div>
				<div id="mss-results-pagination" class="mss-results-pagination"></div>
			</section>
		</div>

		<script>
		jQuery(function($) {
			var app = $('#mss-approval-app');
			var profileId = parseInt(app.data('profile-id'), 10);
			var hasProfile = String(app.data('has-profile')) === '1';
			var nonce = <?php echo wp_json_encode( $ajax_nonce ); ?>;
			var savedRules = <?php echo wp_json_encode( $rules ); ?>;
			var savedWorkspace = <?php echo wp_json_encode( $workspace ); ?> || {};
			var appliedFilters = savedWorkspace.filters || null;
			var appliedScopeToken = savedWorkspace.scope_token || '';
			var previewTimer = null;
			var activeJobToken = '';
			var resultJobSerial = 0;
			var resultPage = 1;
			var pendingRowChoices = {};

			function message(text, error) {
				var box = $('#mss-dup-message').removeClass('notice-success notice-error').addClass(error ? 'notice-error' : 'notice-success').show();
				box.find('p').text(text);
			}
			function reloadDuplicatesTab(targetProfileId, successText) {
				var pageUrl = new URL(window.location.href);
				pageUrl.searchParams.set('tab', 'duplicates');
				if (targetProfileId) pageUrl.searchParams.set('profile_id', targetProfileId); else pageUrl.searchParams.delete('profile_id');
				window.history.replaceState({}, '', pageUrl.toString());
				$.get(ajaxurl, {action:'sync_get_tab_content', tab:'duplicates', profile_id:targetProfileId || 0})
					.done(function(r) {
						if (!r.success) return message(r.data || 'بازخوانی تب ناموفق بود.', true);
						$('#sync-tab-content').html(r.data.html);
						if (successText) {
							var box = $('#mss-dup-message').removeClass('notice-error').addClass('notice-success').show();
							box.find('p').text(successText);
						}
					})
					.fail(function(){ message('خطای ارتباط با سرور هنگام بازخوانی تب.', true); });
			}
			function filters() {
				return {
					categories: $('#mss-filter-categories').val() || [],
					authors: $('#mss-filter-authors').val() || [],
					date_from: $('#mss-filter-date-from').val() || '',
					date_to: $('#mss-filter-date-to').val() || '',
					title: $('#mss-filter-title').val() || ''
				};
			}
			function rules() {
				var out = {};
				$('[data-rule]').each(function() {
					var el = $(this), key = el.data('rule');
					out[key] = el.is(':checkbox') ? (el.is(':checked') ? 1 : 0) : el.val();
				});
				return out;
			}
			function fillRules(values) {
				$('[data-rule]').each(function() {
					var el = $(this), value = values[el.data('rule')];
					if (el.is(':checkbox')) el.prop('checked', !!value);
					else el.val(value == null ? '' : value);
				});
			}
			function fillFilters(values) {
				values = values || {};
				$('#mss-filter-categories').val(values.categories || []);
				$('#mss-filter-authors').val(values.authors || []);
				$('#mss-filter-date-from').val(values.date_from || '');
				$('#mss-filter-date-to').val(values.date_to || '');
				$('#mss-filter-title').val(values.title || '');
			}
			function setBusy(on) {
				app.find('button').each(function() {
					var requiresProfile = String($(this).data('requires-profile')) === '1';
					$(this).prop('disabled', on || (requiresProfile && !hasProfile));
				});
				app.find('.mss-spinner').toggleClass('is-active', on);
			}
			function renderPreview(rows) {
				var body = $('#mss-preview-table tbody').empty();
				if (!rows.length) return body.append($('<tr>').append($('<td colspan="4">').text('محصولی در صف این پروفایل نیست.')));
				rows.forEach(function(row) {
					var best = row.candidates.length ? row.candidates[0] : null;
					body.append($('<tr>')
						.append($('<td>').text(row.title))
						.append($('<td>').text(row.candidates.length))
						.append($('<td>').text(best ? best.title : 'کاندیدی پیدا نشد'))
						.append($('<td>').text(best ? best.score : '—')));
				});
			}
			function preview() {
				if (!hasProfile) {
					renderPreview([]);
					return;
				}
				$.post(ajaxurl, {action:'mss_dup_preview', profile_id:profileId, filters_json:JSON.stringify(filters()), rules:rules(), _ajax_nonce:nonce})
					.done(function(r) { if (r.success) { renderPreview(r.data.rows); $('#mss-filter-count').text(r.data.catalog_count + (appliedFilters ? ' محصول در محدوده اعمال‌شده قرار دارند.' : ' محصول در پیش‌نمایش فعلی‌اند؛ برای تثبیت محدوده «اعمال فیلترها» را بزنید.')); } });
			}
			function schedulePreview() {
				clearTimeout(previewTimer);
				previewTimer = setTimeout(preview, 650);
			}

			function appendHighlightedText(target, text, tokens) {
				var clean = (tokens || []).filter(function(token){return token != null && String(token).length;}).map(String);
				clean = clean.filter(function(token,index){return clean.indexOf(token) === index;}).sort(function(a,b){return b.length-a.length;});
				if (!clean.length) { target.text(text); return; }
				var escaped = clean.map(function(token){return token.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');});
				var matcher = new RegExp('(' + escaped.join('|') + ')', 'gi');
				String(text).split(matcher).forEach(function(part,index){
					if (!part) return;
					target.append(index % 2 ? $('<mark class="mss-common-token">').text(part) : document.createTextNode(part));
				});
			}
			function candidateNode(candidate, queueId, selected) {
				var label = $('<label class="mss-candidate-item">').attr('data-product-id', candidate.product_id);
				var radio = $('<input type="radio" class="mss-candidate-radio">').attr('name', 'mss_candidate_' + queueId).val(candidate.product_id).prop('checked', selected);
				var title = $('<span class="mss-candidate-title">');
				appendHighlightedText(title, candidate.title, (candidate.matched_tokens || []).map(function(match){return match.other;}));
				label.append(radio).append(title);
				label.append($('<small>').text(' امتیاز: ' + (candidate.score == null ? 'جست‌وجوی دستی' : candidate.score)));
				if (candidate.priority_match) label.append($('<span class="mss-priority">').text('اولویت‌دار'));
				return label;
			}
			function syncDecision(row) {
				var decision = row.find('.mss-row-decision').val();
				var radios = row.find('.mss-candidate-radio');
				var labels = {link:'اتصال و بروزرسانی',import_new:'ایمپورت به‌عنوان جدید',dismiss:'نادیده‌گرفتن موقت',blacklist:'افزودن به لیست سیاه'};
				if (decision === 'link') {
					radios.prop('disabled', false);
					if (!radios.filter(':checked').length && radios.length) radios.first().prop('checked', true);
				} else {
					radios.prop('checked', false).prop('disabled', true);
				}
				row.find('.mss-selected-product').val(radios.filter(':checked').val() || '');
				row.find('.mss-process-row').text(labels[decision] || 'اعمال تصمیم');
			}
			function rememberRowChoice(row) {
				pendingRowChoices[row.data('queue-id')] = {
					decision: row.find('.mss-row-decision').val(),
					product_id: row.find('.mss-selected-product').val() || 0,
					manual_candidate: row.data('manual-candidate') || null
				};
			}
			function initSearch(row) {
				var select = row.find('.mss-row-search');
				select.select2({
					width:'100%', placeholder:'جست‌وجو در محصولات فیلترشده…', minimumInputLength:1,
					ajax:{url:ajaxurl, dataType:'json', delay:250, data:function(params){return {action:'mss_dup_search_products', term:params.term, scope_token:appliedScopeToken, _ajax_nonce:nonce};}, processResults:function(data){return {results:data};}}
				}).on('select2:select', function(event) {
					var item = event.params.data, box = row.find('.mss-candidates-box');
					var existing = box.find('[data-product-id="' + item.id + '"]');
					if (!existing.length) {
						existing = candidateNode({product_id:item.id,title:item.text,score:null,priority_match:false}, row.data('queue-id'), false);
						box.find('.mss-no-candidate').remove(); box.append(existing);
					}
					row.find('.mss-row-decision').val('link');
					row.find('.mss-candidate-radio').prop('disabled', false).prop('checked', false);
					existing.find('.mss-candidate-radio').prop('checked', true);
					row.find('.mss-selected-product').val(item.id);
					row.data('manual-candidate', {product_id:item.id,title:item.text,score:null,priority_match:false});
					rememberRowChoice(row);
					select.val(null).trigger('change');
				});
			}
			function resultRow(data) {
				var remembered = pendingRowChoices[data.queue_id] || null;
				var row = $('<tr class="mss-result-row">').attr('data-queue-id', data.queue_id).data('queue-id', data.queue_id);
				row.append($('<td>').append('<input type="checkbox" class="mss-row-check">'));
				var ownTokens = [];
				data.candidates.forEach(function(candidate){(candidate.matched_tokens || []).forEach(function(match){ownTokens.push(match.own);});});
				var sourceTitle = $('<strong>');
				appendHighlightedText(sourceTitle, data.title, ownTokens);
				var source = $('<td>').append(sourceTitle).append('<br>').append($('<a target="_blank">').attr('href', data.source_url).text('مشاهده در سایت مبدأ'));
				row.append(source);
				var decision = $('<select class="mss-row-decision">')
					.append('<option value="link">اتصال به محصول</option>')
					.append('<option value="import_new">ایمپورت به‌عنوان جدید</option>')
					.append('<option value="dismiss">فعلاً نادیده گرفته شود</option>')
					.append('<option value="blacklist">کلاً نادیده گرفته شود (لیست سیاه)</option>')
					.val(remembered ? remembered.decision : data.default_decision);
				row.append($('<td>').append(decision));
				var candidatesCell = $('<td>').append('<select class="mss-row-search"><option></option></select>');
				var box = $('<div class="mss-candidates-box">');
				if (remembered && remembered.manual_candidate && !data.candidates.some(function(candidate){return String(candidate.product_id)===String(remembered.manual_candidate.product_id);})) {
					data.candidates.push(remembered.manual_candidate);
					row.data('manual-candidate', remembered.manual_candidate);
				}
				if (!data.candidates.length) box.append($('<em class="mss-no-candidate">').text('داپلیکیتی شناسایی نشده است.'));
				data.candidates.forEach(function(candidate, index){ var selected=remembered ? String(candidate.product_id)===String(remembered.product_id) : index===0; box.append(candidateNode(candidate, data.queue_id, selected)); });
				candidatesCell.append(box).append('<input type="hidden" class="mss-selected-product">');
				row.append(candidatesCell);
				row.append($('<td>').append('<button type="button" class="button button-primary mss-process-row">اعمال تصمیم</button>'));
				decision.on('change', function(){ syncDecision(row); rememberRowChoice(row); });
				box.on('change', '.mss-candidate-radio', function(){ row.find('.mss-selected-product').val($(this).val()); row.find('.mss-row-decision').val('link'); syncDecision(row); rememberRowChoice(row); });
				setTimeout(function(){ initSearch(row); syncDecision(row); }, 0);
				return row;
			}
			function renderResults(rows) {
				var body = $('#mss-results-table tbody').empty();
				rows.forEach(function(row){ body.append(resultRow(row)); });
				if (!rows.length) body.append($('<tr>').append($('<td colspan="5">').text('در این صفحه محصول در انتظار تصمیمی باقی نمانده است.')));
				$('#mss-select-all').prop('checked', false);
				$('#mss-final-section').show();
			}
			function updateResultProgress(processed, total, percent) {
				var progress = $('#mss-result-progress').show();
				progress.find('progress').val(percent);
				progress.find('strong').text('محاسبه داپلیکیت‌ها: ' + processed + ' از ' + total + ' (' + percent + '٪)');
			}
			function renderPagination(data) {
				resultPage = data.page;
				var box = $('#mss-results-pagination').empty();
				if (data.total_pages <= 1) return;
				box.append($('<button type="button" class="button mss-result-page">').attr('data-page', data.page - 1).prop('disabled', data.page <= 1).text('صفحه قبل'));
				box.append($('<strong>').text('صفحه ' + data.page + ' از ' + data.total_pages + ' — ' + data.total + ' محصول'));
				box.append($('<button type="button" class="button mss-result-page">').attr('data-page', data.page + 1).prop('disabled', data.page >= data.total_pages).text('صفحه بعد'));
			}
			function loadResultPage(page, serial) {
				if (!activeJobToken || serial !== resultJobSerial) return;
				$.post(ajaxurl, {action:'mss_dup_results_page', profile_id:profileId, job_token:activeJobToken, page:page, _ajax_nonce:nonce})
					.done(function(r) {
						if (serial !== resultJobSerial) return;
						if (!r.success) { message(r.data || 'بارگذاری صفحه نتایج ناموفق بود.', true); return; }
						renderResults(r.data.rows);
						renderPagination(r.data);
						$('#mss-result-progress').hide();
						$('#mss-filter-count').text(r.data.catalog_count + ' محصول سایت برای بررسی در نظر گرفته شدند.');
					})
					.fail(function(){ if (serial === resultJobSerial) message('خطای ارتباط با سرور هنگام بارگذاری نتایج.', true); })
					.always(function(){ if (serial === resultJobSerial) setBusy(false); });
			}
			function runResultJobStep(serial, retries) {
				if (!activeJobToken || serial !== resultJobSerial) return;
				$.post(ajaxurl, {action:'mss_dup_results_step', profile_id:profileId, job_token:activeJobToken, _ajax_nonce:nonce})
					.done(function(r) {
						if (serial !== resultJobSerial) return;
						if (!r.success) { message(r.data || 'محاسبه نتایج متوقف شد.', true); setBusy(false); return; }
						updateResultProgress(r.data.processed, r.data.total, r.data.percent);
						if (r.data.complete) {
							message('محاسبه داپلیکیت‌ها کامل شد؛ نتایج به‌صورت صفحه‌بندی‌شده آماده‌اند.', false);
							loadResultPage(1, serial);
						} else {
							setTimeout(function(){ runResultJobStep(serial, 0); }, 40);
						}
					})
					.fail(function() {
						if (serial !== resultJobSerial) return;
						if (retries < 2) {
							message('ارتباط موقتاً قطع شد؛ ادامهٔ محاسبه به‌صورت خودکار تکرار می‌شود.', false);
							setTimeout(function(){ runResultJobStep(serial, retries + 1); }, 1000);
						} else {
							message('ارتباط با سرور پس از سه تلاش برقرار نشد. دوباره «نمایش نتایج» را بزنید.', true);
							setBusy(false);
						}
					});
			}
			function loadResults() {
				if (!hasProfile) return message('ابتدا باید یک پروفایل دارای محصولات صف‌شده فعال باشد.', true);
				if (!appliedFilters || !appliedScopeToken) return message('ابتدا دکمه «اعمال فیلترها» را بزنید تا محدوده جست‌وجو تثبیت شود.', true);
				var serial = ++resultJobSerial;
				activeJobToken = '';
				resultPage = 1;
				pendingRowChoices = {};
				setBusy(true);
				$('#mss-final-section').show();
				$('#mss-results-pagination').empty();
				$('#mss-results-table tbody').html('<tr><td colspan="5">در حال آماده‌سازی پردازش قطعه‌ای…</td></tr>');
				updateResultProgress(0, 0, 0);
				$.post(ajaxurl, {action:'mss_dup_results', profile_id:profileId, filters_json:JSON.stringify(appliedFilters), rules:rules(), _ajax_nonce:nonce})
					.done(function(r){
						if (serial !== resultJobSerial) return;
						if (!r.success) { message(r.data || 'شروع محاسبه نتایج ناموفق بود.', true); setBusy(false); return; }
						activeJobToken = r.data.job_token;
						updateResultProgress(r.data.processed, r.data.total, r.data.complete ? 100 : 0);
						if (r.data.complete) loadResultPage(1, serial); else runResultJobStep(serial, 0);
					})
					.fail(function(){ if (serial === resultJobSerial) { message('خطای ارتباط با سرور', true); setBusy(false); } });
			}
			function rowPayload(row) {
				return {queue_id:row.data('queue-id'), decision:row.find('.mss-row-decision').val(), product_id:row.find('.mss-selected-product').val() || 0};
			}
			function processRows(rows) {
				var payload = [], invalid = false;
				rows.each(function(){ var item=rowPayload($(this)); if(item.decision==='link' && !item.product_id) invalid=true; payload.push(item); });
				if (invalid) return message('برای تمام ردیف‌های «اتصال به محصول» باید یک محصول انتخاب شود.', true);
				if (!payload.length) return message('هیچ ردیفی برای پردازش انتخاب نشده است.', true);
				setBusy(true);
				$.post(ajaxurl, {action:'mss_dup_process_rows', profile_id:profileId, rows:JSON.stringify(payload), _ajax_nonce:nonce})
					.done(function(r){
						if(!r.success) return message(r.data || 'پردازش ناموفق بود.', true);
						r.data.processed_ids.forEach(function(id){ $('#mss-results-table tr[data-queue-id="'+id+'"]').remove(); delete pendingRowChoices[id]; });
						var badge = $('#mss-dup-badge'), badgeCount = Math.max(0, (parseInt(badge.text(),10)||0) - r.data.processed_ids.length);
						if (badgeCount) badge.text(badgeCount); else badge.remove();
						var selectedOption = $('#mss-dup-profile option:selected');
						var profileRemaining = Math.max(0, (parseInt(selectedOption.data('count'),10)||0) - r.data.processed_ids.length);
						$('.mss-queue-notice strong').text(profileRemaining);
						selectedOption.data('count', profileRemaining);
						if (!profileRemaining) {
							selectedOption.remove();
							var profileSelect = $('#mss-dup-profile');
							profileSelect.prepend($('<option selected value="">').text(profileSelect.find('option').length ? '— پروفایل بعدی را انتخاب کنید —' : 'هیچ پروفایلی صف ایمپورت ندارد'));
						} else selectedOption.text(selectedOption.data('title')+' ('+profileRemaining+')');
						message('نتیجه: '+r.data.linked+' اتصال، '+r.data.imported+' ایمپورت جدید، '+r.data.dismissed+' نادیده‌گرفتن موقت، '+r.data.blacklisted+' لیست سیاه، '+r.data.failed+' خطا.', r.data.failed > 0);
						preview();
					}).fail(function(){message('خطای ارتباط با سرور', true);}).always(function(){setBusy(false);});
			}

			fillFilters(appliedFilters);
			$('#mss-filter-categories, #mss-filter-authors').select2({width:'100%', placeholder:'همه', dropdownCssClass:'mss-filter-dropdown'});
			fillRules(savedRules);
			setBusy(false);
			if (appliedFilters) {
				$('#mss-filter-count').text((savedWorkspace.catalog_count || 0) + ' محصول سایت در محدوده ذخیره‌شده قرار دارند.');
			}
			$('#mss-dup-profile').on('change', function(){
				reloadDuplicatesTab($(this).val(), '');
			});
			$('#mss-reset-profile-queue').on('click', function(){
				if (!hasProfile) return;
				var selected = $('#mss-dup-profile option:selected');
				var queueCount = parseInt(selected.data('count'), 10) || 0;
				var profileTitle = selected.data('title') || selected.text();
				var warning = 'آیا مطمئنید؟ تمام ' + queueCount + ' محصول صف‌شده و همه نتایج داپلیکیت پروفایل «' + profileTitle + '» حذف می‌شوند. این کار قابل بازگشت نیست؛ صف سایر پروفایل‌ها و محصولات سایت تغییر نمی‌کنند.';
				if (!window.confirm(warning)) return;
				setBusy(true);
				$.post(ajaxurl, {action:'mss_dup_reset_profile_queue', profile_id:profileId, confirm_reset:'1', _ajax_nonce:nonce})
					.done(function(r) {
						if (!r.success) return message(r.data || 'ریست صف ناموفق بود.', true);
						var badge = $('#mss-dup-badge');
						if (r.data.remaining_total > 0) badge.text(r.data.remaining_total); else badge.remove();
						var successText = r.data.deleted + ' ردیف صف پروفایل «' + profileTitle + '» حذف شد. برای شروع دوباره، همگام‌سازی همین پروفایل را اجرا کنید.';
						reloadDuplicatesTab(r.data.next_profile_id || 0, successText);
					})
					.fail(function(){ message('خطای ارتباط با سرور هنگام ریست صف.', true); })
					.always(function(){ setBusy(false); });
			});
			$('#mss-apply-filters').on('click', function(){ var requestedFilters=filters(); setBusy(true); $.post(ajaxurl,{action:'mss_dup_apply_filters',profile_id:profileId,filters_json:JSON.stringify(requestedFilters),_ajax_nonce:nonce}).done(function(r){if(r.success){appliedFilters=r.data.filters;appliedScopeToken=r.data.scope_token||'';$('#mss-filter-count').text(r.data.count+' محصول سایت برای بررسی در نظر گرفته شدند. جست‌وجوی هر ردیف فقط در همین محدوده انجام می‌شود.');preview();}else message(r.data||'خطا',true);}).always(function(){setBusy(false);}); });
			$('[data-rule]').on('input change', schedulePreview);
			$('#mss-filter-title, #mss-filter-date-from, #mss-filter-date-to, #mss-filter-categories, #mss-filter-authors').on('input change', function(){appliedFilters=null;appliedScopeToken='';$('#mss-final-section').hide();$('#mss-filter-count').text('فیلترها تغییر کرده‌اند؛ برای تثبیت محدوده دوباره «اعمال فیلترها» را بزنید.');schedulePreview();});
			$('#mss-clear-rules').on('click', function(){fillRules({dup_delimiters:' -',dup_exclude_strings:'',dup_min_token_length:0,dup_conflict_groups:'',dup_min_score:1,dup_numeric_priority:false,dup_alphanumeric_priority:false,dup_equal_numeric_count:false,dup_partial_code_match:false,dup_partial_match_min_length:3});preview();});
			$('#mss-load-rules').on('click', function(){if(!hasProfile)return;setBusy(true);$.post(ajaxurl,{action:'mss_dup_load_rules',profile_id:profileId,_ajax_nonce:nonce}).done(function(r){if(r.success){savedRules=r.data.rules;fillRules(savedRules);preview();message('تنظیمات ذخیره‌شده فراخوانی شد.',false);}else message(r.data||'فراخوانی ناموفق بود.',true);}).always(function(){setBusy(false);});});
			$('#mss-show-results').on('click', loadResults);
			$('#mss-save-show-results').on('click', function(){if(!hasProfile)return;if(!appliedFilters)return message('ابتدا فیلترها را اعمال کنید.',true);setBusy(true);$.post(ajaxurl,{action:'mss_dup_save_rules',profile_id:profileId,filters_json:JSON.stringify(appliedFilters),rules:rules(),_ajax_nonce:nonce}).done(function(r){if(r.success){savedRules=r.data.rules;renderPreview(r.data.rows);message(r.data.message,false);loadResults();}else{message(r.data||'ذخیره ناموفق بود.',true);setBusy(false);}}).fail(function(){message('خطای ارتباط با سرور',true);setBusy(false);});});
			$('#mss-results-table').on('click','.mss-process-row',function(){processRows($(this).closest('tr'));});
			$('#mss-results-pagination').on('click','.mss-result-page',function(){var page=parseInt($(this).data('page'),10)||1;setBusy(true);loadResultPage(page,resultJobSerial);});
			$('#mss-select-all').on('change',function(){$('.mss-row-check').prop('checked',$(this).is(':checked'));});
			$('#mss-process-selected').on('click',function(){processRows($('.mss-row-check:checked').closest('tr'));});
			$('#mss-process-all').on('click',function(){processRows($('.mss-result-row'));});
			if (savedWorkspace.job && appliedFilters && appliedScopeToken) {
				var restoredSerial = ++resultJobSerial;
				activeJobToken = savedWorkspace.job.token;
				setBusy(true);
				$('#mss-final-section').show();
				updateResultProgress(savedWorkspace.job.processed, savedWorkspace.job.total, savedWorkspace.job.percent);
				if (savedWorkspace.job.complete) loadResultPage(savedWorkspace.last_page || 1, restoredSerial);
				else runResultJobStep(restoredSerial, 0);
			} else {
				preview();
			}
		});
		</script>
		<style>
		.mss-dup-section{background:#fff;border:1px solid #dcdcde;border-radius:6px;padding:18px;margin:0 0 18px}.mss-dup-profile-bar,.mss-bulk-bar,.mss-results-pagination{display:flex;align-items:center;gap:12px;flex-wrap:wrap}.mss-reset-queue{color:#b32d2e!important;border-color:#b32d2e!important}.mss-reset-queue:hover,.mss-reset-queue:focus{color:#fff!important;background:#b32d2e!important}.mss-queue-notice{background:#fff8e5;border-right:4px solid #dba617;padding:8px 12px}.mss-filter-grid,.mss-rule-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(210px,1fr));gap:14px;margin:14px 0}.mss-filter-grid label,.mss-rule-grid label{display:flex;flex-direction:column;gap:6px;font-weight:600}.mss-filter-grid input,.mss-filter-grid select,.mss-rule-grid input,.mss-rule-grid textarea{width:100%}.mss-rule-grid .mss-wide{grid-column:span 2}.mss-rule-grid .mss-conflict-field{grid-column:1/-1}.mss-rule-grid .mss-conflict-field textarea{min-height:130px;resize:vertical}.mss-rule-checks{display:flex;gap:18px;flex-wrap:wrap;margin:12px 0}.mss-rule-actions{display:flex;gap:8px;align-items:center;flex-wrap:wrap}.mss-result-progress{margin:12px 0;padding:10px;background:#f0f6fc;border-right:4px solid #2271b1}.mss-result-progress progress{width:min(520px,70%);vertical-align:middle;margin-left:10px}.mss-results-pagination{justify-content:center;margin-top:14px}.mss-table-scroll{overflow:auto}.mss-candidates-box{border:1px solid #dcdcde;background:#f6f7f7;border-radius:4px;max-height:260px;overflow:auto;margin-top:8px;padding:6px}.mss-candidate-item{display:flex;align-items:center;gap:7px;padding:7px;border-bottom:1px solid #e5e5e5;cursor:pointer}.mss-candidate-item:last-child{border-bottom:0}.mss-candidate-item:has(input:checked){background:#e7f5ff}.mss-candidate-item small{margin-right:auto}.mss-priority{background:#00a32a;color:#fff;border-radius:10px;padding:1px 7px;font-size:10px}.mss-common-token{background:#fff08a;color:#1d2327;border-radius:2px;padding:0 2px}.mss-row-decision{min-width:220px}.mss-row-search{width:100%}.mss-spinner{float:none}.mss-filter-grid .select2-container{width:100%!important;min-width:210px}.mss-filter-grid .select2-selection--multiple{min-height:40px!important;background:#fff!important;border:1px solid #8c8f94!important}.mss-filter-grid .select2-selection--multiple .select2-search--inline{display:block!important;float:none!important;clear:both;width:100%!important}.mss-filter-grid .select2-selection--multiple .select2-search__field{width:100%!important;min-width:180px!important;margin-top:6px!important}.select2-dropdown.mss-filter-dropdown{background:#fff!important;border:1px solid #8c8f94!important;box-shadow:0 4px 12px rgba(0,0,0,.16);z-index:100100}.select2-dropdown.mss-filter-dropdown .select2-results__option{background:#fff;padding:7px 10px}.select2-dropdown.mss-filter-dropdown .select2-results__option--highlighted{background:#2271b1!important;color:#fff!important}@media(max-width:782px){.mss-rule-grid .mss-wide{grid-column:span 1}.mss-filter-grid .select2-container{min-width:0}}
		</style>
		<?php
	}
	private function render_orphans_tab() {
		if ( ! class_exists( 'Source_Profile_Manager' ) ) {
			echo '<p>مدیریت پروفایل در دسترس نیست.</p>';
			return;
		}

		// ── محصولات وابسته به پروفایل‌های حذف‌شده یا بدون زمان‌بندی (دائمی) ──
		if ( class_exists( 'MSS_Abandoned_Products' ) ) {
			$abandoned = MSS_Abandoned_Products::get_all();
			if ( ! empty( $abandoned ) ) {
				echo '<h3>محصولات بدون پروفایل فعال</h3>';
				echo '<p class="description">این محصولات به پروفایلی وصل هستند که یا حذف شده، یا هرگز زمان‌بندی همگام‌سازی نداشته است؛ بنابراین دیگر هیچ‌گاه به‌طور خودکار بروزرسانی نمی‌شوند.</p>';
				echo '<table class="widefat striped"><thead><tr><th>شناسه</th><th>عنوان</th><th>پروفایل</th><th>دلیل</th><th>عملیات</th></tr></thead><tbody>';
				foreach ( $abandoned as $pid => $info ) {
					$reason_text = 'no_schedule' === $info['reason'] ? 'پروفایل زمان‌بندی ندارد' : 'پروفایل حذف شده';
					echo '<tr>';
					echo '<td>' . esc_html( $pid ) . '</td>';
					echo '<td>' . esc_html( $info['title'] ) . '</td>';
					echo '<td>' . esc_html( $info['profile_title'] ) . '</td>';
					echo '<td>' . esc_html( $reason_text ) . '</td>';
					echo '<td><a href="' . esc_url( $info['edit_link'] ) . '" class="button button-small">ویرایش</a></td>';
					echo '</tr>';
				}
				echo '</tbody></table><hr>';
			}
		}

		$profile_ids = Source_Profile_Manager::get_all_profiles();
		if ( empty( $profile_ids ) ) {
			echo '<p>هیچ پروفایلی تعریف نشده است.</p>';
			return;
		}

        echo '<p><button id="recheck-orphans" class="button">بررسی مجدد محصولات رها شده</button></p>';
		echo '<div class="orphans-container">';
		$any_orphan = false;
		foreach ( $profile_ids as $pid ) {
			$orphans = get_transient( 'sync_orphans_' . $pid );
			if ( ! empty( $orphans ) ) {
				$any_orphan = true;
				echo '<h3>' . esc_html( get_the_title( $pid ) ) . ' (شناسه: ' . $pid . ')</h3>';
				echo '<table class="widefat striped"><thead><tr><th>شناسه</th><th>عنوان</th><th>عملیات</th></tr></thead><tbody>';
				foreach ( $orphans as $o ) {
					echo '<tr>';
					echo '<td>' . esc_html( $o['id'] ) . '</td>';
					echo '<td>' . esc_html( $o['title'] ) . '</td>';
					echo '<td><a href="' . esc_url( $o['url'] ) . '" class="button button-small">ویرایش</a> ' .
						'<a href="' . esc_url( get_delete_post_link( $o['id'] ) ) . '" class="button button-small button-link-delete" onclick="return confirm(\'آیا مطمئن هستید؟\')">حذف</a></td>';
					echo '</tr>';
				}
				echo '</tbody></table>';
			}
		}
		if ( ! $any_orphan ) {
			echo '<p>هیچ محصول رها شده‌ای یافت نشد.</p>';
		}
		echo '</div>';
	}

	public function ajax_start_sync() {
        check_ajax_referer( 'sync_toggle_action', '_ajax_nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'دسترسی غیرمجاز' );
        }

        $profile_id = isset( $_POST['profile_id'] ) ? absint( $_POST['profile_id'] ) : 0;
        if ( ! $profile_id || get_post_type( $profile_id ) !== 'source_profile' ) {
            wp_send_json_error( 'پروفایل نامعتبر' );
        }

        if ( ! class_exists( 'Sync_Engine' ) ) {
            wp_send_json_error( 'کلاس Sync_Engine یافت نشد' );
        }

        $result = Sync_Engine::run_sync( $profile_id );
        if ( is_wp_error( $result ) ) {
            wp_send_json_error( $result->get_error_message() );
        }

        wp_send_json_success( 'همگام‌سازی آغاز شد.' );
    }

    public function ajax_stop_sync() {
        check_ajax_referer( 'sync_toggle_action', '_ajax_nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'دسترسی غیرمجاز' );
        }

        $profile_id = isset( $_POST['profile_id'] ) ? absint( $_POST['profile_id'] ) : 0;
        if ( ! $profile_id || get_post_type( $profile_id ) !== 'source_profile' ) {
            wp_send_json_error( 'پروفایل نامعتبر' );
        }

        // ۱. برداشتن قفل
        delete_option( 'sync_lock_' . $profile_id );

		$session_id = get_option( 'sync_session_' . $profile_id, '' );
		if ( $session_id ) {
			delete_option( 'sync_processed_urls_' . $profile_id . '_' . $session_id );
			delete_option( 'sync_session_' . $profile_id );
		}

        // ۲. پاک‌سازی پیشرفت
        delete_option( 'sync_progress_' . $profile_id );

        // ۳. لغو تمام اکشن‌های Action Scheduler مربوطه
        if ( function_exists( 'as_unschedule_all_actions' ) ) {
            as_unschedule_all_actions( 'sync_process_chunk', array( $profile_id, array() ) );
            as_unschedule_all_actions( 'sync_finalize',    array( $profile_id ) );
        }

        wp_send_json_success( 'همگام‌سازی متوقف شد.' );
    }

	/**
	* پردازش درخواست بازنشانی ایندکس یک پروفایل
	*/
	public function handle_reset_index() {
		// بررسی وجود پارامترها
		if ( ! isset( $_GET['profile_id'] ) || ! isset( $_GET['_wpnonce'] ) ) {
			wp_die( 'درخواست نامعتبر.' );
		}

		$profile_id = absint( $_GET['profile_id'] );
		
		// بررسی دسترسی کاربر
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'دسترسی غیرمجاز.' );
		}

		// تأیید نانس امنیتی
		check_admin_referer( 'reset_index_' . $profile_id, '_wpnonce' );

		// حذف آپشن‌های مربوط به ایندکس و قفل
		delete_option( 'sync_product_index_' . $profile_id );
		delete_option( 'sync_sitemap_fingerprint_' . $profile_id );
		delete_option( 'sync_index_build_temp_' . $profile_id );
		delete_option( 'sync_lock_' . $profile_id );

		// پیام موفقیت
		$msg = urlencode( 'ایندکس پروفایل با موفقیت بازنشانی شد.' );

		// بازگشت به داشبورد با پیام
		wp_safe_redirect( add_query_arg( array(
			'post_type' => 'source_profile',
			'page'      => self::MENU_SLUG,
			'tab'       => 'status',
			'reset_msg' => $msg,
		), admin_url( 'edit.php' ) ) );
		exit;
	}

    public function ajax_run_orphan_check() {
        check_ajax_referer( 'sync_dashboard_ajax', '_ajax_nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'دسترسی غیرمجاز' );
        }

        // اجرای تابع بررسی orphans (تعریف‌شده در فایل اصلی)
        if ( function_exists( 'mss_run_daily_orphan_check' ) ) {
            mss_run_daily_orphan_check();
            wp_send_json_success( 'بررسی انجام شد.' );
        } else {
            wp_send_json_error( 'تابع بررسی در دسترس نیست.' );
        }
    }

    public function ajax_get_logs_table() {
        check_ajax_referer( 'sync_dashboard_ajax', '_ajax_nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'دسترسی غیرمجاز' );
        }

        $log_level = isset( $_GET['log_level'] ) ? sanitize_text_field( wp_unslash( $_GET['log_level'] ) ) : '';

        // رندر فقط جدول لاگ‌ها (همان منطق render_logs_tab ولی فقط بخش table)
        ob_start();
        if ( class_exists( 'Sync_Logger' ) ) {
            $all_logs = Sync_Logger::get_logs( 100 );
            if ( $log_level && in_array( $log_level, array( 'info', 'success', 'error', 'warning' ), true ) ) {
                $logs = array_filter( $all_logs, function( $entry ) use ( $log_level ) {
                    return $entry['level'] === $log_level;
                } );
            } else {
                $logs = $all_logs;
            }
            ?>
            <table class="widefat striped">
                <thead>
                    <tr>
                        <th style="width:140px;">زمان</th>
                        <th style="width:80px;">سطح</th>
                        <th>پیام</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ( empty( $logs ) ) : ?>
                        <tr><td colspan="3">لاگی برای نمایش وجود ندارد.</td></tr>
                    <?php else : ?>
                        <?php foreach ( $logs as $entry ) : ?>
                            <?php
                            $level_class = '';
                            switch ( $entry['level'] ) {
                                case 'error':   $level_class = 'color:red; font-weight:bold;'; break;
                                case 'warning': $level_class = 'color:orange;'; break;
                                case 'success': $level_class = 'color:green;'; break;
                            }
                            ?>
                            <tr>
                                <td><?php echo esc_html( $entry['time'] ); ?></td>
                                <td style="<?php echo esc_attr( $level_class ); ?>"><?php echo esc_html( $entry['level'] ); ?></td>
                                <td><?php echo esc_html( $entry['message'] ); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
            <?php
        } else {
            echo '<p>Sync_Logger در دسترس نیست.</p>';
        }
        $html = ob_get_clean();
        wp_send_json_success( array( 'html' => $html ) );
    }

	/**
	* بارگذاری اسکریپت‌ها و استایل‌های اختصاصی تب داپلیکیت
	*/
	public function enqueue_dashboard_assets( $hook ) {
		// فقط در صفحه داشبورد همگام‌سازی
		if ( 'source_profile_page_sync-dashboard' !== $hook ) {
			return;
		}

		// Select2 (کتابخانه‌ای که خود وردپرس/ووکامرس همیشه با این نام ثبت می‌کنند؛
		// به‌صورت احتیاطی، اگر به هر دلیلی ثبت نشده بود، از CDN بارگذاری می‌شود)
		if ( wp_script_is( 'select2', 'registered' ) ) {
			wp_enqueue_style( 'select2' );
			wp_enqueue_script( 'select2' );
		} else {
			wp_enqueue_style( 'select2', 'https://cdnjs.cloudflare.com/ajax/libs/select2/4.0.13/css/select2.min.css', array(), '4.0.13' );
			wp_enqueue_script( 'select2', 'https://cdnjs.cloudflare.com/ajax/libs/select2/4.0.13/js/select2.min.js', array( 'jquery' ), '4.0.13', true );
		}
	}
}
