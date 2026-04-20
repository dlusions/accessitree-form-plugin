<?php
if (!class_exists('WP_List_Table')) {
  require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

class YTP_Forms_Summary_Table extends WP_List_Table {
    private $table;

    public function __construct($table_name) {
        parent::__construct([
            'singular' => 'form',
            'plural'   => 'forms',
            'ajax'     => false,
        ]);
        $this->table = $table_name;
    }

    public function get_columns() {
        return [
            'form_key'   => 'Form',
            'entries'    => '# Entries',
            'last_entry' => 'Last Entry Date',
        ];
    }

    public function get_sortable_columns() {
        return [
            'form_key' => ['form_key', true], // sortable A–Z/Z–A
        ];
    }

    public function column_form_key($item) {
        $url = add_query_arg(
            ['page' => 'ytp-form-entries', 'form_key' => $item['form_key']],
            admin_url('admin.php')
        );
        return '<a href="' . esc_url($url) . '"><strong>' . esc_html($item['form_key']) . '</strong></a>';
    }

    public function column_default($item, $column_name) {
        switch ($column_name) {
            case 'entries':
                return number_format_i18n((int)$item['entries']);
            case 'last_entry':
                return esc_html( mysql2date( get_option('date_format') . ' ' . get_option('time_format'), $item['last_entry']) );
            default:
                return '';
        }
    }

    public function prepare_items() {
        global $wpdb;

        $per_page = $this->get_items_per_page('ytp_forms_per_page', 20);

        // Only allow sorting by form_key (per spec)
        $orderby = (isset($_GET['orderby']) && $_GET['orderby'] === 'form_key') ? 'form_key' : 'form_key';
        $order   = (isset($_GET['order']) && strtolower($_GET['order']) === 'asc') ? 'ASC' : 'DESC';

        $total = (int) $wpdb->get_var("SELECT COUNT(DISTINCT form_key) FROM {$this->table}");

        $paged  = max(1, (int)($_GET['paged'] ?? 1));
        $offset = ($paged - 1) * $per_page;

        $sql = "SELECT form_key, COUNT(*) AS entries, MAX(submitted_at) AS last_entry
                FROM {$this->table}
                GROUP BY form_key
                ORDER BY {$orderby} {$order}
                LIMIT %d OFFSET %d";

        $rows = $wpdb->get_results($wpdb->prepare($sql, [$per_page, $offset]), ARRAY_A);
        if (!is_array($rows)) $rows = [];

        $this->_column_headers = [$this->get_columns(), [], $this->get_sortable_columns(), 'form_key'];
        $this->items = $rows;

        $this->set_pagination_args([
            'total_items' => $total,
            'per_page'    => $per_page,
        ]);
    }
}
