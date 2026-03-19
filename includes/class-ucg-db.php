<?php

if (!defined('ABSPATH')) {
    exit;
}

if (!class_exists('UCG_DB')) {
    class UCG_DB {
        protected static $table_cache = array();

        public static function table_templates() {
            return self::resolve_table_name('ucg_templates');
        }

        public static function table_runs() {
            return self::resolve_table_name('ucg_runs');
        }

        public static function table_run_items() {
            return self::resolve_table_name('ucg_run_items');
        }

        public static function create_template($name, $post_type, $body, $is_default = 0, $length_option_id = 0, $vary_length = 0) {
            global $wpdb;

            $now = current_time('mysql', true);
            $table = self::table_templates();
            $name = trim((string) $name);
            $post_type = sanitize_key((string) $post_type);
            $body = (string) $body;
            $is_default = $is_default ? 1 : 0;
            $length_option_id = max(0, (int) $length_option_id);
            $vary_length = $vary_length ? 1 : 0;

            if ($name === '' || $post_type === '' || $body === '') {
                return 0;
            }

            if ($is_default) {
                self::clear_default_template_for_post_type($post_type);
            }

            $ok = $wpdb->insert(
                $table,
                array(
                    'name' => $name,
                    'post_type' => $post_type,
                    'body' => $body,
                    'length_option_id' => $length_option_id > 0 ? $length_option_id : null,
                    'vary_length' => $vary_length,
                    'is_active' => 1,
                    'is_default' => $is_default,
                    'created_at' => $now,
                    'updated_at' => $now,
                ),
                array('%s', '%s', '%s', '%d', '%d', '%d', '%d', '%s', '%s')
            );

            if (!$ok) {
                return 0;
            }

            return (int) $wpdb->insert_id;
        }

        public static function update_template($template_id, $name, $post_type, $body, $is_default = 0, $length_option_id = 0, $vary_length = 0) {
            global $wpdb;

            $template_id = (int) $template_id;
            if ($template_id <= 0) {
                return false;
            }

            $table = self::table_templates();
            $name = trim((string) $name);
            $post_type = sanitize_key((string) $post_type);
            $body = (string) $body;
            $is_default = $is_default ? 1 : 0;
            $length_option_id = max(0, (int) $length_option_id);
            $vary_length = $vary_length ? 1 : 0;

            if ($name === '' || $post_type === '' || $body === '') {
                return false;
            }

            if ($is_default) {
                self::clear_default_template_for_post_type($post_type, $template_id);
            }

            $result = $wpdb->update(
                $table,
                array(
                    'name' => $name,
                    'post_type' => $post_type,
                    'body' => $body,
                    'length_option_id' => $length_option_id > 0 ? $length_option_id : null,
                    'vary_length' => $vary_length,
                    'is_default' => $is_default,
                    'updated_at' => current_time('mysql', true),
                ),
                array('id' => $template_id),
                array('%s', '%s', '%s', '%d', '%d', '%d', '%s'),
                array('%d')
            );

            return $result !== false;
        }

        public static function delete_template($template_id) {
            global $wpdb;
            $template_id = (int) $template_id;
            if ($template_id <= 0) {
                return false;
            }

            $deleted = $wpdb->delete(self::table_templates(), array('id' => $template_id), array('%d'));
            return $deleted !== false;
        }

        public static function get_template($template_id) {
            global $wpdb;
            $template_id = (int) $template_id;
            if ($template_id <= 0) {
                return null;
            }

            $row = $wpdb->get_row(
                $wpdb->prepare(
                    "SELECT * FROM " . self::table_templates() . " WHERE id = %d",
                    $template_id
                ),
                ARRAY_A
            );

            return is_array($row) ? $row : null;
        }

        public static function get_templates($post_type = '') {
            global $wpdb;
            $table = self::table_templates();
            $post_type = sanitize_key((string) $post_type);

            if ($post_type !== '') {
                $rows = $wpdb->get_results(
                    $wpdb->prepare(
                        "SELECT * FROM {$table} WHERE post_type = %s ORDER BY is_default DESC, id DESC",
                        $post_type
                    ),
                    ARRAY_A
                );
            } else {
                $rows = $wpdb->get_results(
                    "SELECT * FROM {$table} ORDER BY post_type ASC, is_default DESC, id DESC",
                    ARRAY_A
                );
            }

            return is_array($rows) ? $rows : array();
        }

        public static function create_run($post_type, $target_field, $template_id, $created_by, $options = array()) {
            global $wpdb;

            $post_type = sanitize_key((string) $post_type);
            $target_field = sanitize_text_field((string) $target_field);
            $template_id = (int) $template_id;
            $created_by = (int) $created_by;

            if ($post_type === '' || $target_field === '') {
                return 0;
            }

            $now = current_time('mysql', true);
            $options_json = wp_json_encode(is_array($options) ? $options : array(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

            $ok = $wpdb->insert(
                self::table_runs(),
                array(
                    'status' => 'queued',
                    'post_type' => $post_type,
                    'target_field' => $target_field,
                    'template_id' => $template_id > 0 ? $template_id : null,
                    'created_by' => $created_by > 0 ? $created_by : null,
                    'total_items' => 0,
                    'processed_items' => 0,
                    'success_items' => 0,
                    'failed_items' => 0,
                    'options_json' => $options_json,
                    'created_at' => $now,
                    'updated_at' => $now,
                ),
                array('%s', '%s', '%s', '%d', '%d', '%d', '%d', '%d', '%d', '%s', '%s', '%s')
            );

            if (!$ok) {
                return 0;
            }

            return (int) $wpdb->insert_id;
        }

        public static function add_run_items($run_id, $post_ids) {
            global $wpdb;

            $run_id = (int) $run_id;
            if ($run_id <= 0 || !is_array($post_ids) || empty($post_ids)) {
                return 0;
            }

            $table = self::table_run_items();
            $count = 0;
            $now = current_time('mysql', true);

            foreach ($post_ids as $post_id) {
                $post_id = (int) $post_id;
                if ($post_id <= 0) {
                    continue;
                }

                $ok = $wpdb->query(
                    $wpdb->prepare(
                        "INSERT IGNORE INTO {$table}
                        (run_id, post_id, status, attempts, created_at, updated_at)
                        VALUES (%d, %d, 'queued', 0, %s, %s)",
                        $run_id,
                        $post_id,
                        $now,
                        $now
                    )
                );

                if ($ok) {
                    $count++;
                }
            }

            self::recalculate_run_counters($run_id);
            return $count;
        }

        public static function get_next_active_run() {
            global $wpdb;

            $row = $wpdb->get_row(
                "SELECT * FROM " . self::table_runs() . " WHERE status IN ('queued', 'running') ORDER BY id ASC LIMIT 1",
                ARRAY_A
            );

            return is_array($row) ? $row : null;
        }

        public static function get_run($run_id) {
            global $wpdb;
            $run_id = (int) $run_id;
            if ($run_id <= 0) {
                return null;
            }

            $row = $wpdb->get_row(
                $wpdb->prepare(
                    "SELECT r.*, t.name AS template_name
                     FROM " . self::table_runs() . " r
                     LEFT JOIN " . self::table_templates() . " t ON t.id = r.template_id
                     WHERE r.id = %d",
                    $run_id
                ),
                ARRAY_A
            );

            return is_array($row) ? $row : null;
        }

        public static function get_runs($limit = 50, $offset = 0) {
            global $wpdb;

            $limit = max(1, min(200, (int) $limit));
            $offset = max(0, (int) $offset);

            $rows = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT r.*, t.name AS template_name
                     FROM " . self::table_runs() . " r
                     LEFT JOIN " . self::table_templates() . " t ON t.id = r.template_id
                     ORDER BY r.id DESC
                     LIMIT %d OFFSET %d",
                    $limit,
                    $offset
                ),
                ARRAY_A
            );

            return is_array($rows) ? $rows : array();
        }

        public static function update_run($run_id, $data) {
            global $wpdb;

            $run_id = (int) $run_id;
            if ($run_id <= 0 || !is_array($data) || empty($data)) {
                return false;
            }

            $allowed = array(
                'status' => '%s',
                'total_items' => '%d',
                'processed_items' => '%d',
                'success_items' => '%d',
                'failed_items' => '%d',
                'started_at' => '%s',
                'finished_at' => '%s',
                'updated_at' => '%s',
                'error_message' => '%s',
            );

            $payload = array();
            $formats = array();
            foreach ($allowed as $key => $format) {
                if (array_key_exists($key, $data)) {
                    $payload[$key] = $data[$key];
                    $formats[] = $format;
                }
            }

            if (empty($payload)) {
                return false;
            }

            if (!isset($payload['updated_at'])) {
                $payload['updated_at'] = current_time('mysql', true);
                $formats[] = '%s';
            }

            $updated = $wpdb->update(
                self::table_runs(),
                $payload,
                array('id' => $run_id),
                $formats,
                array('%d')
            );

            return $updated !== false;
        }

        public static function get_run_items_for_processing($run_id, $limit = 20) {
            global $wpdb;

            $run_id = (int) $run_id;
            $limit = max(1, min(100, (int) $limit));
            if ($run_id <= 0) {
                return array();
            }

            $rows = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT ri.*, r.post_type, r.target_field, r.template_id, r.options_json, t.body AS template_body
                     FROM " . self::table_run_items() . " ri
                     INNER JOIN " . self::table_runs() . " r ON r.id = ri.run_id
                     LEFT JOIN " . self::table_templates() . " t ON t.id = r.template_id
                     WHERE ri.run_id = %d
                       AND ri.status = 'queued'
                     ORDER BY ri.id ASC
                     LIMIT %d",
                    $run_id,
                    $limit
                ),
                ARRAY_A
            );

            return is_array($rows) ? $rows : array();
        }

        public static function get_run_items_log($run_id, $limit = 20) {
            global $wpdb;

            $run_id = (int) $run_id;
            $limit = max(1, min(100, (int) $limit));
            if ($run_id <= 0) {
                return array();
            }

            $rows = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT id, post_id, status, error_message, attempts, generated_at, updated_at
                     FROM " . self::table_run_items() . "
                     WHERE run_id = %d
                     ORDER BY updated_at DESC, id DESC
                     LIMIT %d",
                    $run_id,
                    $limit
                ),
                ARRAY_A
            );

            return is_array($rows) ? $rows : array();
        }

        public static function update_run_item($item_id, $data) {
            global $wpdb;

            $item_id = (int) $item_id;
            if ($item_id <= 0 || !is_array($data) || empty($data)) {
                return false;
            }

            $allowed = array(
                'status' => '%s',
                'prompt' => '%s',
                'generated_text' => '%s',
                'error_message' => '%s',
                'credits_spent' => '%f',
                'credits_remaining' => '%f',
                'attempts' => '%d',
                'generated_at' => '%s',
                'reviewed_at' => '%s',
                'updated_at' => '%s',
            );

            $payload = array();
            $formats = array();
            foreach ($allowed as $key => $format) {
                if (array_key_exists($key, $data)) {
                    $payload[$key] = $data[$key];
                    $formats[] = $format;
                }
            }

            if (empty($payload)) {
                return false;
            }

            if (!isset($payload['updated_at'])) {
                $payload['updated_at'] = current_time('mysql', true);
                $formats[] = '%s';
            }

            $updated = $wpdb->update(
                self::table_run_items(),
                $payload,
                array('id' => $item_id),
                $formats,
                array('%d')
            );

            return $updated !== false;
        }

        public static function mark_pending_items_failed($run_id, $error_message = '') {
            global $wpdb;

            $run_id = (int) $run_id;
            if ($run_id <= 0) {
                return 0;
            }

            $error_message = (string) $error_message;
            $updated_at = current_time('mysql', true);

            $result = $wpdb->query(
                $wpdb->prepare(
                    "UPDATE " . self::table_run_items() . "
                     SET status = 'failed',
                         error_message = %s,
                         updated_at = %s
                     WHERE run_id = %d
                       AND status IN ('queued', 'processing')",
                    $error_message,
                    $updated_at,
                    $run_id
                )
            );

            return (int) $result;
        }

        public static function get_review_items($run_id = 0, $status = 'generated', $limit = 50, $offset = 0) {
            global $wpdb;

            $run_id = (int) $run_id;
            $status = sanitize_key((string) $status);
            $status = $status === '' ? 'generated' : $status;
            $limit = max(1, min(200, (int) $limit));
            $offset = max(0, (int) $offset);

            $table_items = self::table_run_items();
            $table_runs = self::table_runs();
            $posts_table = $wpdb->posts;

            if ($run_id > 0) {
                $rows = $wpdb->get_results(
                    $wpdb->prepare(
                        "SELECT ri.*, r.post_type, r.target_field, p.post_title
                         FROM {$table_items} ri
                         INNER JOIN {$table_runs} r ON r.id = ri.run_id
                         LEFT JOIN {$posts_table} p ON p.ID = ri.post_id
                         WHERE ri.run_id = %d
                           AND ri.status = %s
                         ORDER BY ri.id DESC
                         LIMIT %d OFFSET %d",
                        $run_id,
                        $status,
                        $limit,
                        $offset
                    ),
                    ARRAY_A
                );
            } else {
                $rows = $wpdb->get_results(
                    $wpdb->prepare(
                        "SELECT ri.*, r.post_type, r.target_field, p.post_title
                         FROM {$table_items} ri
                         INNER JOIN {$table_runs} r ON r.id = ri.run_id
                         LEFT JOIN {$posts_table} p ON p.ID = ri.post_id
                         WHERE ri.status = %s
                         ORDER BY ri.id DESC
                         LIMIT %d OFFSET %d",
                        $status,
                        $limit,
                        $offset
                    ),
                    ARRAY_A
                );
            }

            return is_array($rows) ? $rows : array();
        }

        public static function get_run_item_with_run($item_id) {
            global $wpdb;
            $item_id = (int) $item_id;
            if ($item_id <= 0) {
                return null;
            }

            $row = $wpdb->get_row(
                $wpdb->prepare(
                    "SELECT ri.*, r.post_type, r.target_field, r.status AS run_status
                     FROM " . self::table_run_items() . " ri
                     INNER JOIN " . self::table_runs() . " r ON r.id = ri.run_id
                     WHERE ri.id = %d",
                    $item_id
                ),
                ARRAY_A
            );

            return is_array($row) ? $row : null;
        }

        public static function count_review_items($run_id = 0, $status = 'generated') {
            global $wpdb;

            $run_id = (int) $run_id;
            $status = sanitize_key((string) $status);
            if ($status === '') {
                $status = 'generated';
            }

            $table = self::table_run_items();
            if ($run_id > 0) {
                return (int) $wpdb->get_var(
                    $wpdb->prepare(
                        "SELECT COUNT(*) FROM {$table} WHERE run_id = %d AND status = %s",
                        $run_id,
                        $status
                    )
                );
            }

            return (int) $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT COUNT(*) FROM {$table} WHERE status = %s",
                    $status
                )
            );
        }

        public static function recalculate_run_counters($run_id) {
            global $wpdb;

            $run_id = (int) $run_id;
            if ($run_id <= 0) {
                return;
            }

            $table = self::table_run_items();
            $counts = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT status, COUNT(*) AS cnt
                     FROM {$table}
                     WHERE run_id = %d
                     GROUP BY status",
                    $run_id
                ),
                ARRAY_A
            );

            $total = 0;
            $processed = 0;
            $success = 0;
            $failed = 0;

            if (is_array($counts)) {
                foreach ($counts as $row) {
                    $status = isset($row['status']) ? (string) $row['status'] : '';
                    $cnt = isset($row['cnt']) ? (int) $row['cnt'] : 0;
                    if ($cnt < 0) {
                        continue;
                    }
                    $total += $cnt;

                    if (in_array($status, array('generated', 'approved', 'rejected', 'failed'), true)) {
                        $processed += $cnt;
                    }
                    if (in_array($status, array('generated', 'approved', 'rejected'), true)) {
                        $success += $cnt;
                    }
                    if ($status === 'failed') {
                        $failed += $cnt;
                    }
                }
            }

            self::update_run(
                $run_id,
                array(
                    'total_items' => $total,
                    'processed_items' => $processed,
                    'success_items' => $success,
                    'failed_items' => $failed,
                    'updated_at' => current_time('mysql', true),
                )
            );
        }

        public static function finalize_run_if_done($run_id) {
            global $wpdb;

            $run_id = (int) $run_id;
            if ($run_id <= 0) {
                return;
            }

            self::recalculate_run_counters($run_id);

            $table = self::table_run_items();
            $pending = (int) $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT COUNT(*) FROM {$table}
                     WHERE run_id = %d
                       AND status IN ('queued', 'processing')",
                    $run_id
                )
            );

            if ($pending > 0) {
                return;
            }

            $run = self::get_run($run_id);
            if (!$run) {
                return;
            }

            $status = ((int) $run['failed_items'] > 0 && (int) $run['success_items'] === 0) ? 'failed' : 'completed';
            self::update_run(
                $run_id,
                array(
                    'status' => $status,
                    'finished_at' => current_time('mysql', true),
                )
            );
        }

        public static function get_runs_for_select() {
            global $wpdb;
            $rows = $wpdb->get_results(
                "SELECT id, post_type, status, created_at
                 FROM " . self::table_runs() . "
                 ORDER BY id DESC
                 LIMIT 100",
                ARRAY_A
            );

            return is_array($rows) ? $rows : array();
        }

        public static function search_runs_for_select($query = '', $limit = 25) {
            global $wpdb;

            $query = trim((string) $query);
            $limit = max(5, min(100, (int) $limit));

            if ($query === '') {
                $rows = $wpdb->get_results(
                    $wpdb->prepare(
                        "SELECT id, post_type, status, created_at
                         FROM " . self::table_runs() . "
                         ORDER BY id DESC
                         LIMIT %d",
                        $limit
                    ),
                    ARRAY_A
                );

                return is_array($rows) ? $rows : array();
            }

            $like = '%' . $wpdb->esc_like($query) . '%';
            $rows = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT id, post_type, status, created_at
                     FROM " . self::table_runs() . "
                     WHERE CAST(id AS CHAR) LIKE %s
                        OR post_type LIKE %s
                        OR status LIKE %s
                        OR created_at LIKE %s
                     ORDER BY id DESC
                     LIMIT %d",
                    $like,
                    $like,
                    $like,
                    $like,
                    $limit
                ),
                ARRAY_A
            );

            return is_array($rows) ? $rows : array();
        }

        public static function count_runs_by_status() {
            global $wpdb;

            $rows = $wpdb->get_results(
                "SELECT status, COUNT(*) AS cnt
                 FROM " . self::table_runs() . "
                 GROUP BY status",
                ARRAY_A
            );

            $result = array(
                'queued' => 0,
                'running' => 0,
                'completed' => 0,
                'failed' => 0,
            );

            if (!is_array($rows)) {
                return $result;
            }

            foreach ($rows as $row) {
                $status = isset($row['status']) ? (string) $row['status'] : '';
                if (!array_key_exists($status, $result)) {
                    continue;
                }
                $result[$status] = isset($row['cnt']) ? (int) $row['cnt'] : 0;
            }

            return $result;
        }

        protected static function clear_default_template_for_post_type($post_type, $except_id = 0) {
            global $wpdb;

            $post_type = sanitize_key((string) $post_type);
            $except_id = (int) $except_id;
            if ($post_type === '') {
                return;
            }

            if ($except_id > 0) {
                $wpdb->query(
                    $wpdb->prepare(
                        "UPDATE " . self::table_templates() . "
                         SET is_default = 0
                         WHERE post_type = %s
                           AND id <> %d",
                        $post_type,
                        $except_id
                    )
                );
                return;
            }

            $wpdb->query(
                $wpdb->prepare(
                    "UPDATE " . self::table_templates() . "
                     SET is_default = 0
                     WHERE post_type = %s",
                    $post_type
                )
            );
        }

        protected static function resolve_table_name($suffix) {
            global $wpdb;

            $suffix = (string) $suffix;
            if ($suffix === '') {
                return '';
            }

            if (isset(self::$table_cache[$suffix])) {
                return self::$table_cache[$suffix];
            }

            self::$table_cache[$suffix] = $wpdb->prefix . $suffix;
            return self::$table_cache[$suffix];
        }
    }
}
