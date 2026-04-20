<?php
if (!class_exists('WP_List_Table')) {
  require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

class YTP_Form_Entries_Table extends WP_List_Table {
    private $table;
    private $form_key;
    private $all_columns;
    private $payload_keys;

    /** Reserved transport/meta keys we never display */
    private function reserved_keys(): array {
        return [
            'secret','form_key','form_id','formid','files',
            '_wpnonce','_wpnonce_yooessentials','_wp_http_referer',
            'action','format','method','url','_locale','_method','_charset_'
        ];
    }
    /** Option storing per-form order map */
    private function get_order_map() {
        $map = get_option('ytp_columns_order_map', []);
        return is_array($map) ? $map : [];
    }
    private function get_form_order() {
        $map = $this->get_order_map();
        return isset($map[$this->form_key]) && is_array($map[$this->form_key]) ? $map[$this->form_key] : [];
    }

    public function __construct($table_name, $form_key = '') {
        parent::__construct([
            'singular' => 'entry',
            'plural'   => 'entries',
            'ajax'     => false,
        ]);
        $this->table    = $table_name;
        $this->form_key = $form_key;
    }

    /** Build fixed meta columns + dynamic payload keys (filters out reserved keys) */
    private function compute_columns() {
        global $wpdb;

        $limit  = 500;
        $params = [];
        if ($this->form_key) {
            $sql    = "SELECT payload_json FROM {$this->table} WHERE form_key=%s ORDER BY submitted_at DESC LIMIT %d";
            $params = [$this->form_key, $limit];
        } else {
            $sql    = "SELECT payload_json FROM {$this->table} ORDER BY submitted_at DESC LIMIT %d";
            $params = [$limit];
        }

        $rows = $wpdb->get_col($wpdb->prepare($sql, $params));
        if (!is_array($rows)) $rows = [];

        $keys = [];
        foreach ($rows as $j) {
            $a = json_decode((string)$j, true);
            if (is_array($a)) {
                array_walk_recursive($a, function($v, $k) use (&$keys){ $keys[$k] = true; });
            }
        }

        foreach ($this->reserved_keys() as $rk) unset($keys[$rk]);

        $payload = array_keys($keys);

        // Apply saved order (payload only): saved first, then the rest alphabetically
        $saved = $this->get_form_order();
        $rest  = array_values(array_diff($payload, $saved));
        sort($rest, SORT_NATURAL|SORT_FLAG_CASE);
        $this->payload_keys = array_values(array_unique(array_merge($saved, $rest)));

        // Fixed meta columns + payload (ordered)
        $cols = [
            'cb'           => '<input type="checkbox" />',
            'id'           => 'ID',
            'form_key'     => 'Form',
            'form_id'      => 'Form ID',
            'submitted_at' => 'Submitted At',
            'ip'           => 'IP',
        ];
        foreach ($this->payload_keys as $k) {
            if (!isset($cols[$k])) $cols[$k] = $k;
        }
        $this->all_columns = $cols;
    }

    /** Expose payload keys to the UI builder */
    public function get_payload_keys_for_ui() {
        if (!$this->all_columns) $this->compute_columns();
        return $this->payload_keys ?: [];
    }

    public function get_columns() {
        if (!$this->all_columns) $this->compute_columns();
        return $this->all_columns;
    }

    public function get_hidden_columns() {
        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        $hidden = $screen ? get_hidden_columns($screen) : [];
        return is_array($hidden) ? $hidden : [];
    }

    public function get_sortable_columns() {
        return [
            'id'           => ['id', true],
            'submitted_at' => ['submitted_at', true],
        ];
    }

    /** Choose a primary column. If "id" is hidden, fall back to submitted_at. */
    protected function get_primary_column_name() {
        $hidden = $this->get_hidden_columns();
        if (is_array($hidden) && in_array('id', $hidden, true)) {
            return 'submitted_at';
        }
        return 'id';
    }

    /** Row actions builder */
    private function build_row_actions(array $item): array {
        $id = (int) $item['id'];

        $view_url = add_query_arg(
            [
                'page'     => 'ytp-form-entries',
                'view'     => 'entry',
                'id'       => $id,
                'form_key' => $this->form_key,
            ],
            admin_url('admin.php')
        );
        $actions['view'] = '<a href="' . esc_url($view_url) . '">' . esc_html__('View', 'ytp-form-entries') . '</a>';

        $del_url = add_query_arg(
            [
                'page'     => 'ytp-form-entries',
                'form_key' => $this->form_key,
                'action'   => 'delete',
                'entry'    => $id,
            ],
            admin_url('admin.php')
        );
        $del_url = wp_nonce_url($del_url, 'ytp_delete_entry_' . $id);
        $actions['delete'] = '<a class="submitdelete" href="' . esc_url($del_url) . '" onclick="return confirm(\'' . esc_js(__('Delete this entry?', 'ytp-form-entries')) . '\');">' . esc_html__('Delete', 'ytp-form-entries') . '</a>';

        return $actions;
    }

    /** Checkbox column for bulk actions */
    public function column_cb($item) {
        return sprintf('<input type="checkbox" name="ids[]" value="%d" />', (int) $item['id']);
    }

    /** ID column (shows row actions if it's the primary column) */
    public function column_id($item) {
        $out = '<strong>' . esc_html((string)$item['id']) . '</strong>';
        if ($this->get_primary_column_name() === 'id') {
            $out .= $this->row_actions($this->build_row_actions($item));
        }
        return $out;
    }

    /** Submitted At column (also shows row actions if it is primary) */
    public function column_submitted_at($item) {
        $val = isset($item['submitted_at']) ? (string)$item['submitted_at'] : '';
        $out = esc_html($val);
        if ($this->get_primary_column_name() === 'submitted_at') {
            $out .= $this->row_actions($this->build_row_actions($item));
        }
        return $out;
    }

    public function column_default($item, $column_name) {
        if (isset($item[$column_name])) {
            $v = $item[$column_name];
            if (is_array($v)) $v = implode('|', $v);
            return esc_html(mb_strimwidth((string)$v, 0, 120, '…'));
        }
        return '';
    }

    protected function get_bulk_actions() {
        return ['delete' => __('Delete', 'ytp-form-entries')];
    }

    protected function process_bulk_action() {
        if ($this->current_action() !== 'delete') return;

        if (!current_user_can('manage_options')) {
            wp_die(__('Insufficient permissions.', 'ytp-form-entries'));
        }

        global $wpdb;

        // Single delete via row action (GET)
        if (isset($_GET['entry'])) {
            $id = (int) $_GET['entry'];
            check_admin_referer('ytp_delete_entry_' . $id);

            $deleted = $wpdb->delete($this->table, ['id' => $id], ['%d']) ? 1 : 0;

            $redir = add_query_arg(
                [
                    'page'     => 'ytp-form-entries',
                    'form_key' => $this->form_key,
                    'deleted'  => $deleted,
                ],
                admin_url('admin.php')
            );
            wp_safe_redirect(esc_url_raw($redir));
            exit;
        }

        // Bulk delete via POST (checkboxes)
        if (!empty($_POST['ids']) && is_array($_POST['ids'])) {
            check_admin_referer('ytp_delete_entries', '_ytpnonce_del');

            $ids = array_map('intval', $_POST['ids']);
            $ids = array_values(array_filter($ids));
            $deleted = 0;

            if ($ids) {
                $placeholders = implode(',', array_fill(0, count($ids), '%d'));
                $sql = "DELETE FROM {$this->table} WHERE id IN ($placeholders)";
                $wpdb->query($wpdb->prepare($sql, $ids));
                $deleted = (int) $wpdb->rows_affected;
            }

            $redir = add_query_arg(
                [
                    'page'     => 'ytp-form-entries',
                    'form_key' => $this->form_key,
                    'deleted'  => $deleted,
                ],
                admin_url('admin.php')
            );
            wp_safe_redirect(esc_url_raw($redir));
            exit;
        }
    }

    public function prepare_items() {
        global $wpdb;

        // Process row/bulk actions BEFORE querying items
        $this->process_bulk_action();

        $per_page = $this->get_items_per_page('ytp_entries_per_page', 20);

        $orderby = isset($_GET['orderby']) ? sanitize_sql_orderby($_GET['orderby']) : 'submitted_at';
        if (!in_array($orderby, ['id','submitted_at'], true)) $orderby = 'submitted_at';
        $order   = (isset($_GET['order']) && strtolower($_GET['order']) === 'asc') ? 'ASC' : 'DESC';

        $where  = [];
        $params = [];
        if ($this->form_key) {
            $where[]  = 'form_key = %s';
            $params[] = $this->form_key;
        }
        $where_sql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

        // COUNT
        if ($params) {
            $total = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(1) FROM {$this->table} {$where_sql}",
                $params
            ));
        } else {
            $total = (int) $wpdb->get_var("SELECT COUNT(1) FROM {$this->table} {$where_sql}");
        }

        $paged  = max(1, (int)($_GET['paged'] ?? 1));
        $offset = ($paged - 1) * $per_page;

        $sql = "SELECT id, form_key, form_id, submitted_at, ip, payload_json
                FROM {$this->table} {$where_sql}
                ORDER BY {$orderby} {$order}
                LIMIT %d OFFSET %d";
        $params2   = $params;
        $params2[] = $per_page;
        $params2[] = $offset;

        $rows = $wpdb->get_results($wpdb->prepare($sql, $params2), ARRAY_A);
        if (!is_array($rows)) $rows = [];

        $columns  = $this->get_columns();
        $hidden   = $this->get_hidden_columns();
        $sortable = $this->get_sortable_columns();

        if (!is_array($this->payload_keys)) $this->payload_keys = [];
        $reserved = array_flip($this->reserved_keys());

        $items = [];
        foreach ($rows as $r) {
            $payload = json_decode((string)$r['payload_json'], true) ?: [];
            unset($r['payload_json']);
            foreach ($this->payload_keys as $k) {
                if (isset($reserved[$k])) continue;
                $val = $payload[$k] ?? '';
                if (is_array($val)) $val = implode('|', $val);
                $r[$k] = $val;
            }
            $items[] = $r;
        }

        $this->_column_headers = [$columns, $hidden, $sortable, $this->get_primary_column_name()];
        $this->items = $items;

        $this->set_pagination_args([
            'total_items' => $total,
            'per_page'    => $per_page,
        ]);
    }
}
