<?php
/**
 * Plugin Name: YOOtheme Essentials — Form Entries (shared table + viewer/export)
 * Description: Receives YOOtheme Essentials Webhook posts, stores as JSON in one shared table, adds wp-admin lists (Forms summary + per-form entries + single-entry printable view) and CSV export. Accepts JSON or Form-Data payloads. Supports per-form manual column ordering and bulk delete.
 * Version: 1.5.4
 * Author: OSS Helper
 */

if (!defined('ABSPATH')) exit;

if (!class_exists('YTP_Form_Entries')):

class YTP_Form_Entries {
    private $table;
    private $secret_option = 'ytp_form_webhook_secret';
    private $order_option  = 'ytp_columns_order_map'; // array: form_key => [field, field, ...]

    public function __construct() {
        global $wpdb;
        $this->table = $wpdb->prefix . 'ytp_form_submissions';

        register_activation_hook(__FILE__, [$this, 'on_activate']);

        add_action('rest_api_init',           [$this, 'register_rest']);
        add_action('admin_menu',              [$this, 'admin_menu']);
        add_filter('set-screen-option',       [$this, 'set_screen_option'], 10, 3);
        add_action('admin_post_ytp_export_entries', [$this, 'handle_export']);

        add_action('admin_enqueue_scripts',   [$this, 'enqueue_admin_assets']);
    }

    private function reserved_keys(): array {
        return [
            'secret','form_key','form_id','formid','files',
            '_wpnonce','_wpnonce_yooessentials','_wp_http_referer',
            'action','format','method','url','_locale','_method','_charset_'
        ];
    }

    public function on_activate() {
        global $wpdb;
        $table = $this->table;
        $charset_collate = $wpdb->get_charset_collate();
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $sql = "CREATE TABLE {$table} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            form_key VARCHAR(120) NOT NULL,
            form_id  VARCHAR(64)  NOT NULL,
            submitted_at DATETIME NOT NULL,
            ip VARCHAR(45) NULL,
            user_agent TEXT NULL,
            payload_json LONGTEXT NOT NULL,
            files_json   LONGTEXT NULL,
            PRIMARY KEY  (id),
            KEY idx_form_key (form_key),
            KEY idx_form_id  (form_id),
            KEY idx_submitted_at (submitted_at)
        ) $charset_collate;";
        dbDelta($sql);

        if (!get_option($this->secret_option)) {
            update_option($this->secret_option, wp_generate_password(32, false));
        }
        if (!get_option($this->order_option)) {
            update_option($this->order_option, []);
        }
    }

    /* ---------- REST ---------- */
    public function register_rest() {
        register_rest_route('ytp/v1', '/forms/ingest', [
            'methods'  => 'POST',
            'callback' => [$this, 'ingest'],
            'permission_callback' => '__return_true',
        ]);
    }

    public function ingest(\WP_REST_Request $req) {
        $secret = get_option($this->secret_option);

        $body = $req->get_json_params();
        if (!is_array($body) || !$body) $body = $req->get_body_params();
        if (!is_array($body)) $body = [];

        $provided = isset($body['secret']) ? (string)$body['secret'] : '';
        if (!$secret || !$provided || !hash_equals($secret, $provided)) {
            return new \WP_REST_Response(['error' => 'Unauthorized'], 401);
        }

        if (isset($body['payload']) && is_array($body['payload'])) {
            $payload = $body['payload'];
        } else {
            $payload = array_diff_key($body, array_flip($this->reserved_keys()));
        }

        $form_key = isset($body['form_key']) ? sanitize_key($body['form_key']) : (isset($payload['form_key']) ? sanitize_key($payload['form_key']) : '');
        $form_id  = isset($body['form_id'])  ? sanitize_text_field($body['form_id']) : (isset($payload['formid']) ? sanitize_text_field($payload['formid']) : '');
        if (!$form_key || !$form_id) {
            return new \WP_REST_Response(['error' => 'Missing form identifiers'], 400);
        }

        $meta = [
            'ip'         => $_SERVER['REMOTE_ADDR'] ?? null,
            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? null,
        ];
        $files = (isset($body['files']) && is_array($body['files'])) ? $body['files'] : [];

        global $wpdb;
        $wpdb->insert($this->table, [
            'form_key'      => $form_key,
            'form_id'       => $form_id,
            'submitted_at'  => current_time('mysql'),
            'ip'            => $meta['ip'],
            'user_agent'    => $meta['user_agent'],
            'payload_json'  => wp_json_encode($payload, JSON_UNESCAPED_UNICODE),
            'files_json'    => $files ? wp_json_encode($files, JSON_UNESCAPED_UNICODE) : null,
        ], ['%s','%s','%s','%s','%s','%s','%s']);

        return ['ok' => true, 'id' => (int)$wpdb->insert_id];
    }

    /* ---------- Admin UI ---------- */
    public function admin_menu() {
        $hook = add_menu_page(
            'Form Entries',
            'Form Entries',
            'manage_options',
            'ytp-form-entries',
            [$this, 'render_admin'],
            'dashicons-feedback',
            56
        );
        add_action("load-$hook", [$this, 'load_screen']);
    }

    public function set_screen_option($status, $option, $value) {
        if ($option === 'ytp_entries_per_page') return (int) $value;
        if ($option === 'ytp_forms_per_page')   return (int) $value;
        return $status;
    }

    public function enqueue_admin_assets($hook) {
        if ($hook !== 'toplevel_page_ytp-form-entries') return;
        wp_enqueue_script('jquery-ui-sortable');

        $js = <<<JS
jQuery(function($){
  var panel = $('#ytp-order-panel');
  $('#ytp-toggle-order').on('click', function(e){ e.preventDefault(); panel.toggleClass('is-open'); });
  $('#ytp-order-list').sortable({axis:'y', placeholder:'ytp-sort-ph', handle:'.ytp-handle'});
  $('#ytp-save-order').on('click', function(){ $(this).closest('form')[0].submit(); });
});
JS;
        wp_add_inline_script('jquery-ui-sortable', $js);

        $css = <<<CSS
#ytp-order-panel{display:none;margin-top:10px;border:1px solid #ccd0d4;background:#fff;padding:12px;border-radius:6px;max-width:980px}
#ytp-order-panel.is-open{display:block}
#ytp-order-list{list-style:none;margin:0;padding:0}
#ytp-order-list li{display:flex;align-items:center;gap:10px;margin:0 0 6px;padding:8px 10px;border:1px solid #e2e4e7;background:#f9f9fb;border-radius:4px;cursor:move}
#ytp-order-list .ytp-handle{width:16px;height:16px;opacity:.7}
#ytp-order-panel .desc{margin:0 0 10px;color:#555}
.ytp-sort-ph{height:38px;border:2px dashed #bbb;margin:0 0 6px;background:#fff}
CSS;
        wp_add_inline_style('wp-admin', $css);
    }

    public function load_screen() {
        // Save manual order
        if (isset($_POST['ytp_save_order']) && current_user_can('manage_options')) {
            check_admin_referer('ytp_save_order');
            $form_key = isset($_POST['form_key']) ? sanitize_text_field($_POST['form_key']) : '';
            $ordered  = isset($_POST['ordered']) && is_array($_POST['ordered']) ? array_map('sanitize_text_field', $_POST['ordered']) : [];
            if ($form_key) {
                $map = get_option($this->order_option, []);
                if (!is_array($map)) $map = [];
                $map[$form_key] = array_values(array_unique(array_filter($ordered, 'strlen')));
                update_option($this->order_option, $map);
                wp_safe_redirect(add_query_arg([
                    'page'     => 'ytp-form-entries',
                    'form_key' => $form_key,
                    'saved'    => 1,
                ], admin_url('admin.php')));
                exit;
            }
        }

        $view = isset($_GET['view']) ? sanitize_key($_GET['view']) : '';
        $is_detail = !empty($_GET['form_key']) && $view !== 'entry';
        $is_entry  = ($view === 'entry');

        if ($is_detail) {
            add_screen_option('per_page', [
                'label'   => 'Entries per page',
                'default' => 20,
                'option'  => 'ytp_entries_per_page'
            ]);
            require_once __DIR__ . '/class-ytp-form-entries-table.php';
            $current_form_key = sanitize_text_field($_GET['form_key']);
            $GLOBALS['ytp_form_entries_table'] = new YTP_Form_Entries_Table($this->table, $current_form_key);
            $GLOBALS['ytp_form_entries_table']->prepare_items();

        } elseif (!$is_entry) {
            add_screen_option('per_page', [
                'label'   => 'Forms per page',
                'default' => 20,
                'option'  => 'ytp_forms_per_page'
            ]);
            require_once __DIR__ . '/class-ytp-forms-summary-table.php';
            $GLOBALS['ytp_forms_summary_table'] = new YTP_Forms_Summary_Table($this->table);
            $GLOBALS['ytp_forms_summary_table']->prepare_items();
        }
    }

    private function get_secret() { return get_option($this->secret_option); }
    private function get_order_map() { $m = get_option($this->order_option, []); return is_array($m) ? $m : []; }
    private function get_form_order($form_key) {
        $map = $this->get_order_map();
        return isset($map[$form_key]) && is_array($map[$form_key]) ? $map[$form_key] : [];
    }

    private function get_form_keys() {
        global $wpdb;
        $keys = $wpdb->get_col("SELECT DISTINCT form_key FROM {$this->table} ORDER BY form_key ASC");
        return is_array($keys) ? $keys : [];
    }

    private function get_entry_by_id($id) {
        global $wpdb;
        $row = $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM {$this->table} WHERE id = %d", (int)$id),
            ARRAY_A
        );
        if (!$row) return null;
        $row['payload'] = json_decode((string)($row['payload_json'] ?? ''), true) ?: [];
        $row['files']   = json_decode((string)($row['files_json'] ?? ''), true) ?: [];
        return $row;
    }

    private function humanize_label($key) {
        $label = str_replace(['_', '-'], ' ', (string)$key);
        $label = preg_replace('/\s+/', ' ', $label);
        return ucwords(trim($label));
    }

    /** Normalize long text for on-screen/print display (single-entry view). */
    private function normalize_display_text($val) {
        if (is_array($val)) {
            $val = implode(", ", array_map('strval', $val));
        } else {
            $val = (string) $val;
        }
        // Replace <br> variants with LF; normalize CRLF/CR to LF
        $val = preg_replace('/<br\s*\/?>/i', "\n", $val);
        $val = str_replace(["\r\n", "\r"], "\n", $val);
        // Strip any remaining HTML safely and decode entities (smart quotes, etc.)
        $val = wp_strip_all_tags($val, false); // strips tags/scripts/styles. :contentReference[oaicite:2]{index=2}
        $val = html_entity_decode($val, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        // Keep ONE blank line between paragraphs (collapse 3+ to 2)
        $val = preg_replace("/\n{3,}/", "\n\n", $val);
        // Tidy whitespace around line breaks
        $val = preg_replace("/[ \t]+\n/", "\n", $val);
        $val = preg_replace("/\n[ \t]+/", "\n", $val);
        return trim($val);
    }

    /**
     * Linkify URLs and emails, handling filenames with spaces:
     *  - Displays the original text (with spaces),
     *  - Encodes spaces as %20 for the <a href>, so links work.
     */
    private function linkify_text_preserving_spaces($text) {
        // Linkify http/https (allow spaces in the visible text)
        $text = preg_replace_callback(
            '~(?P<url>https?://[^\s<>"\']+(?:\s+[^\s<>"\']+)*)~i',
            function($m){
                $raw = $m['url'];

                // Trim trailing punctuation not usually part of a URL
                $trail = '';
                while ($raw !== '' && preg_match('/[)\]\.,;:]+$/', $raw)) {
                    $trail = substr($raw, -1) . $trail;
                    $raw   = substr($raw, 0, -1);
                }

                // Encode spaces for the href (valid URL must not contain literal spaces). :contentReference[oaicite:3]{index=3}
                $href = preg_replace('/\s+/', '%20', $raw);
                $href = esc_url_raw($href);

                $display = esc_html($raw); // keep spaces visible in link text
                return '<a href="' . esc_url($href) . '" target="_blank" rel="noopener noreferrer">' . $display . '</a>' . esc_html($trail);
            },
            $text
        );

        // Linkify plain emails
        $text = preg_replace(
            '/(?<![\w@])([A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,})/i',
            '<a href="mailto:$1">$1</a>',
            $text
        );

        return $text;
    }

    public function handle_export() {
        if (!current_user_can('manage_options')) wp_die('Insufficient permissions');
        check_admin_referer('ytp_export_csv');

        $selected = isset($_GET['form_key']) ? sanitize_text_field($_GET['form_key']) : '';

        require_once __DIR__ . '/class-ytp-form-entries-table.php';
        $tmp = new YTP_Form_Entries_Table($this->table, $selected);
        $all_cols = $tmp->get_columns();

        $screen_id = 'toplevel_page_ytp-form-entries';
        $hidden = get_user_option("manage{$screen_id}columnshidden", get_current_user_id());
        if (!is_array($hidden)) $hidden = [];
        $visible = array_values(array_diff(array_keys($all_cols), $hidden));

        $this->export_csv($visible, $selected);
    }

    private function csv_clean_value($val) {
        if (is_array($val)) $val = implode('|', $val);
        $val = (string) $val;
        $val = preg_replace('/<br\s*\/?>/i', "\n", $val);
        $val = wp_strip_all_tags($val, false);
        $val = html_entity_decode($val, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $val = str_replace("\xC2\xA0", ' ', $val);
        $val = str_replace(["\r\n","\r"], "\n", $val);
        $val = preg_replace("/[ \t]+\n/", "\n", $val);
        $val = preg_replace("/\n[ \t]+/", "\n", $val);
        $val = preg_replace("/\n{3,}/", "\n\n", $val); // keep a blank line between paragraphs in CSV
        $val = str_replace("\n", "\r\n", $val);
        if (function_exists('seems_utf8') && !seems_utf8($val)) {
            $val = mb_convert_encoding($val, 'UTF-8', 'auto');
        }
        return $val;
    }

    private function export_csv($visible_cols, $form_key = '') {
        global $wpdb;

        if (function_exists('ob_get_level')) { while (ob_get_level() > 0) { @ob_end_clean(); } }

        $where = '';
        $params = [];
        if ($form_key) { $where = "WHERE form_key = %s"; $params[] = $form_key; }

        if ($params) {
            $rows = $wpdb->get_results($wpdb->prepare(
                "SELECT id, form_key, form_id, submitted_at, ip, payload_json FROM {$this->table} {$where} ORDER BY submitted_at DESC LIMIT %d",
                array_merge($params, [100000])
            ), ARRAY_A);
        } else {
            $rows = $wpdb->get_results(
                "SELECT id, form_key, form_id, submitted_at, ip, payload_json FROM {$this->table} {$where} ORDER BY submitted_at DESC LIMIT 100000",
                ARRAY_A
            );
        }
        if (!is_array($rows)) $rows = [];

        $reserved = array_flip($this->reserved_keys());

        $payload_keys = [];
        foreach ($rows as $r) {
            $j = json_decode((string)$r['payload_json'], true) ?: [];
            foreach ($j as $k => $v) {
                if (isset($reserved[$k])) continue;
                if (in_array($k, $visible_cols, true)) $payload_keys[$k] = true;
            }
        }
        $payload_keys = array_values(array_intersect($visible_cols, array_keys($payload_keys)));

        $filename = 'entries' . ($form_key ? "_{$form_key}" : '') . '_' . date('Y-m-d_H-i-s') . '.csv';

        if (function_exists('nocache_headers')) nocache_headers();
        header('Content-Type: text/csv; charset=utf-8');
        header('X-Content-Type-Options: nosniff');
        header('Content-Disposition: attachment; filename=' . $filename);
        header('Pragma: no-cache');
        header('Expires: 0');

        echo "\xEF\xBB\xBF";
        $out = fopen('php://output', 'w');

        $meta_cols = array_values(array_intersect($visible_cols, ['id','form_key','form_id','submitted_at','ip']));
        fputcsv($out, array_merge($meta_cols, $payload_keys));

        foreach ($rows as $r) {
            $payload = json_decode((string)$r['payload_json'], true) ?: [];
            $line = [];
            foreach ($meta_cols as $mc) $line[] = isset($r[$mc]) ? $r[$mc] : '';
            foreach ($payload_keys as $pk) $line[] = $this->csv_clean_value($payload[$pk] ?? '');
            fputcsv($out, $line);
        }
        fclose($out);
        exit;
    }

    public function render_admin() {
        if (!current_user_can('manage_options')) wp_die(__('Insufficient permissions.', 'ytp-form-entries'));

        $secret   = esc_html(get_option($this->secret_option));
        $webhook  = esc_url_raw(rest_url('ytp/v1/forms/ingest'));
        $selected = isset($_GET['form_key']) ? sanitize_text_field($_GET['form_key']) : '';
        $view     = isset($_GET['view']) ? sanitize_key($_GET['view']) : '';
        $is_detail = (!empty($selected) && $view !== 'entry');
        $is_entry  = ($view === 'entry');

        echo '<div class="wrap"><h1>Form Entries</h1>';
        echo '<div class="notice notice-info ytp-no-print"><p><strong>Webhook Endpoint:</strong> ' . esc_html($webhook) . '</p>';
        echo '<p><strong>Body/Form-Data secret:</strong> <code>' . $secret . '</code></p></div>';

        if (!empty($_GET['deleted'])) {
            $cnt = (int) $_GET['deleted'];
            echo '<div class="notice notice-success is-dismissible"><p>' .
                 esc_html(sprintf(_n('Deleted %d entry.', 'Deleted %d entries.', $cnt, 'ytp-form-entries'), $cnt)) .
                 '</p></div>';
        }
        if (!empty($_GET['saved'])) {
            echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__('Column order saved.', 'ytp-form-entries') . '</p></div>';
        }

        if ($is_entry) {
            /* ---------- SINGLE ENTRY (printable) ---------- */
            $id = isset($_GET['id']) ? (int) $_GET['id'] : 0;
            $entry = $id ? $this->get_entry_by_id($id) : null;

            $back_entries_url = esc_url(add_query_arg(
                ['page' => 'ytp-form-entries', 'form_key' => $selected],
                admin_url('admin.php')
            ));

            echo '<div class="ytp-entry-toolbar ytp-no-print" style="margin:8px 0 16px; display:flex; gap:8px;">';
            echo '<a class="button" href="' . $back_entries_url . '">&larr; ' . esc_html__('Back to Entries', 'ytp-form-entries') . '</a>';
            echo '<a class="button button-primary" href="#" onclick="window.print();return false;">' . esc_html__('Print', 'ytp-form-entries') . '</a>';
            echo '</div>';

            if (!$entry) {
                echo '<div class="notice notice-error"><p>' . esc_html__('Entry not found.', 'ytp-form-entries') . '</p></div></div>';
                return;
            }

            echo '<style>
                .ytp-entry-card{background:#fff;border:1px solid #ccd0d4;border-radius:8px;max-width:940px;padding:24px;}
                .ytp-entry-head{display:flex;flex-wrap:wrap;gap:12px 24px;margin-bottom:20px;border-bottom:1px solid #eee;padding-bottom:12px;}
                .ytp-meta{font-size:13px;color:#555;}
                .ytp-fields{display:grid;grid-template-columns:1fr;row-gap:20px;}
                .ytp-field{display:grid;grid-template-columns:220px 1fr;column-gap:12px;row-gap:6px;align-items:start;}
                .ytp-label{font-weight:600;color:#222;margin-top:2px;}
                .ytp-value{word-break:break-word;}
                .ytp-value p{margin:0 0 16px; line-height:1.65;} /* comfy paragraph spacing */
                .ytp-no-print{ }
                @media print{
                    #adminmenumain, #wpadminbar, .update-nag, .notice, .ytp-no-print{ display:none !important; }
                    html, body{ background:#fff !important; }
                    #wpcontent, #wpbody-content{ margin-left:0 !important; padding-left:0 !important; }
                    .wrap{ margin:0 !important; }
                    .ytp-entry-card{ border:none; padding:0; max-width:100%; box-shadow:none; }
                    .ytp-entry-head{ border:none; }
                }
            </style>';

            echo '<div class="ytp-entry-card">';
            echo '<div class="ytp-entry-head">';
            echo '<div><h2 style="margin:0 0 4px;">' . esc_html($entry['form_key']) . '</h2>';
            echo '<div class="ytp-meta">' . esc_html__('Entry ID:', 'ytp-form-entries') . ' ' . esc_html($entry['id']) . '</div></div>';
            $submitted = mysql2date(get_option('date_format').' '.get_option('time_format'), $entry['submitted_at']);
            echo '<div class="ytp-meta"><strong>' . esc_html__('Submitted:', 'ytp-form-entries') . '</strong> ' . esc_html($submitted) . '</div>';
            if (!empty($entry['ip'])) {
                echo '<div class="ytp-meta"><strong>' . esc_html__('IP:', 'ytp-form-entries') . '</strong> ' . esc_html($entry['ip']) . '</div>';
            }
            echo '</div>';

            $reserved = array_flip($this->reserved_keys());
            $fields = $entry['payload']; if (!is_array($fields)) $fields = [];

            $saved = $this->get_form_order($entry['form_key']);
            $all_keys = array_keys($fields);
            $rest = array_values(array_diff($all_keys, $saved));
            sort($rest, SORT_NATURAL | SORT_FLAG_CASE);
            $ordered_keys = array_values(array_unique(array_merge($saved, $rest)));

            foreach ($ordered_keys as $k) {
                if (isset($reserved[$k])) continue;
                $label = $this->humanize_label($k);

                // 1) normalize -> 2) linkify (http/https + emails; URLs with spaces handled) -> 3) paragraphs
                $plain = $this->normalize_display_text($fields[$k] ?? '');
                $linked = $this->linkify_text_preserving_spaces($plain);
                $html   = wpautop($linked, true); // convert blank lines to paragraphs/br. :contentReference[oaicite:4]{index=4}

                echo '<div class="ytp-field">';
                echo '<div class="ytp-label">' . esc_html($label) . '</div>';
                echo '<div class="ytp-value">' . wp_kses_post($html) . '</div>'; // allow safe post HTML. :contentReference[oaicite:5]{index=5}
                echo '</div>';
            }

            // Files (if any)
            if (!empty($entry['files']) && is_array($entry['files'])) {
                echo '<div class="ytp-field">';
                echo '<div class="ytp-label">' . esc_html__('Files', 'ytp-form-entries') . '</div>';
                echo '<div class="ytp-value">';
                foreach ($entry['files'] as $fk => $fv) {
                    if (is_string($fv)) {
                        $u = esc_url($fv);
                        echo '<div><a href="' . $u . '" target="_blank" rel="noopener noreferrer">' . esc_html($fk) . '</a></div>';
                    } elseif (is_array($fv)) {
                        foreach ($fv as $one) {
                            $u = esc_url($one);
                            echo '<div><a href="' . $u . '" target="_blank" rel="noopener noreferrer">' . esc_html($fk) . '</a></div>';
                        }
                    }
                }
                echo '</div></div>';
            }

            echo '</div></div>'; // card/wrap
            return;
        }

        if ($is_detail) {
            /* ---------- DETAIL LIST VIEW ---------- */
            $form_keys = $this->get_form_keys();

            $back_url = esc_url(add_query_arg(['page' => 'ytp-form-entries'], admin_url('admin.php')));
            echo '<p class="ytp-no-print" style="margin: 8px 0 16px;">';
            echo '<a class="button" href="' . $back_url . '">&larr; ' . esc_html__('Back to Forms', 'ytp-form-entries') . '</a>';
            echo '</p>';

            echo '<form method="get" class="ytp-no-print" style="margin:12px 0;">';
            echo '<input type="hidden" name="page" value="ytp-form-entries" />';
            echo '<label for="form_key">Form:</label> ';
            echo '<select name="form_key" id="form_key">';
            echo '<option value="">All forms</option>';
            foreach ($form_keys as $fk) {
                printf('<option value="%s"%s>%s</option>',
                    esc_attr($fk),
                    selected($selected, $fk, false),
                    esc_html($fk)
                );
            }
            echo '</select> ';
            echo '<button class="button">Filter</button> ';

            $export_url = wp_nonce_url(
                add_query_arg(['action' => 'ytp_export_entries', 'form_key' => $selected], admin_url('admin-post.php')),
                'ytp_export_csv'
            );
            echo '<a class="button button-primary" href="' . esc_url($export_url) . '">Export CSV (respects visible columns)</a> ';

            echo '<a href="#" id="ytp-toggle-order" class="button">Customize Order</a>';
            echo '</form>';

            $saved_order = $this->get_form_order($selected);

            if (isset($GLOBALS['ytp_form_entries_table']) && $GLOBALS['ytp_form_entries_table'] instanceof YTP_Form_Entries_Table) {
                $payload_keys = $GLOBALS['ytp_form_entries_table']->get_payload_keys_for_ui();
            } else {
                $payload_keys = [];
            }
            $rest = array_values(array_diff($payload_keys, $saved_order));
            sort($rest, SORT_NATURAL | SORT_FLAG_CASE);
            $ui_keys = array_values(array_unique(array_merge($saved_order, $rest)));

            echo '<div id="ytp-order-panel" class="ytp-no-print">';
            echo '<p class="desc">Drag to set the order of <strong>form fields</strong>. Meta columns stay fixed. This order is used for the table, CSV export, and single-entry view.</p>';
            echo '<form method="post">';
            wp_nonce_field('ytp_save_order');
            echo '<input type="hidden" name="ytp_save_order" value="1" />';
            echo '<input type="hidden" name="page" value="ytp-form-entries" />';
            echo '<input type="hidden" name="form_key" value="' . esc_attr($selected) . '" />';
            echo '<ul id="ytp-order-list">';
            foreach ($ui_keys as $k) {
                echo '<li>';
                echo '<svg class="ytp-handle" viewBox="0 0 24 24" aria-hidden="true"><path d="M10 4h4v2h-4V4zm0 7h4v2h-4v-2zm0 7h4v2h-4v-2z"/></svg>';
                echo '<input type="hidden" name="ordered[]" value="' . esc_attr($k) . '"/>';
                echo '<span>' . esc_html($this->humanize_label($k)) . ' <code style="opacity:.6">' . esc_html($k) . '</code></span>';
                echo '</li>';
            }
            echo '</ul>';
            echo '<p><button type="button" id="ytp-save-order" class="button button-primary">Save Order</button></p>';
            echo '</form>';
            echo '</div>';

            if (isset($GLOBALS['ytp_form_entries_table']) && $GLOBALS['ytp_form_entries_table'] instanceof YTP_Form_Entries_Table) {
                $table = $GLOBALS['ytp_form_entries_table'];
            } else {
                require_once __DIR__ . '/class-ytp-form-entries-table.php';
                $table = new YTP_Form_Entries_Table($this->table, $selected);
                $table->prepare_items();
            }

            echo '<form method="post" class="ytp-no-print">';
            echo '<input type="hidden" name="page" value="ytp-form-entries" />';
            if ($selected) echo '<input type="hidden" name="form_key" value="' . esc_attr($selected) . '" />';
            wp_nonce_field('ytp_delete_entries', '_ytpnonce_del');
            $table->display();
            echo '</form>';

        } else {
            /* ---------- SUMMARY VIEW ---------- */
            if (isset($GLOBALS['ytp_forms_summary_table']) && $GLOBALS['ytp_forms_summary_table'] instanceof YTP_Forms_Summary_Table) {
                $summary = $GLOBALS['ytp_forms_summary_table'];
            } else {
                require_once __DIR__ . '/class-ytp-forms-summary-table.php';
                $summary = new YTP_Forms_Summary_Table($this->table);
                $summary->prepare_items();
            }

            echo '<p class="ytp-no-print">Overview of forms. Click a form to view its entries.</p>';
            echo '<form method="get" class="ytp-no-print">';
            echo '<input type="hidden" name="page" value="ytp-form-entries" />';
            $summary->display();
            echo '</form>';
        }

        echo '</div>'; // .wrap
    }
}

new YTP_Form_Entries();

endif;
