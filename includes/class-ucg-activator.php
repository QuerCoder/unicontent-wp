<?php

if (!defined('ABSPATH')) {
    exit;
}

if (!class_exists('UCG_Activator')) {
    class UCG_Activator {
        public static function activate() {
            require_once ABSPATH . 'wp-admin/includes/upgrade.php';
            global $wpdb;

            $charset_collate = $wpdb->get_charset_collate();
            $templates_table = UCG_DB::table_templates();
            $runs_table = UCG_DB::table_runs();
            $items_table = UCG_DB::table_run_items();
            $logs_table = UCG_DB::table_logs();

            $sql_templates = "CREATE TABLE {$templates_table} (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                name VARCHAR(190) NOT NULL,
                post_type VARCHAR(64) NOT NULL,
                scenario VARCHAR(32) NOT NULL DEFAULT 'field_update',
                body LONGTEXT NOT NULL,
                length_option_id BIGINT UNSIGNED NULL,
                vary_length TINYINT(1) NOT NULL DEFAULT 0,
                is_active TINYINT(1) NOT NULL DEFAULT 1,
                is_default TINYINT(1) NOT NULL DEFAULT 0,
                created_at DATETIME NOT NULL,
                updated_at DATETIME NOT NULL,
                PRIMARY KEY (id),
                KEY post_type (post_type),
                KEY scenario (scenario),
                KEY is_default (is_default),
                KEY length_option_id (length_option_id)
            ) {$charset_collate};";

            $sql_runs = "CREATE TABLE {$runs_table} (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                status VARCHAR(20) NOT NULL DEFAULT 'queued',
                post_type VARCHAR(64) NOT NULL,
                target_field VARCHAR(191) NOT NULL,
                template_id BIGINT UNSIGNED NULL,
                created_by BIGINT UNSIGNED NULL,
                total_items INT UNSIGNED NOT NULL DEFAULT 0,
                processed_items INT UNSIGNED NOT NULL DEFAULT 0,
                success_items INT UNSIGNED NOT NULL DEFAULT 0,
                failed_items INT UNSIGNED NOT NULL DEFAULT 0,
                options_json LONGTEXT NULL,
                error_message TEXT NULL,
                created_at DATETIME NOT NULL,
                started_at DATETIME NULL,
                finished_at DATETIME NULL,
                updated_at DATETIME NOT NULL,
                PRIMARY KEY (id),
                KEY status (status),
                KEY created_at (created_at),
                KEY template_id (template_id)
            ) {$charset_collate};";

            $sql_items = "CREATE TABLE {$items_table} (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                run_id BIGINT UNSIGNED NOT NULL,
                post_id BIGINT UNSIGNED NOT NULL,
                item_index INT UNSIGNED NOT NULL DEFAULT 1,
                status VARCHAR(20) NOT NULL DEFAULT 'queued',
                prompt LONGTEXT NULL,
                generated_text LONGTEXT NULL,
                error_message TEXT NULL,
                credits_spent DECIMAL(10,2) NULL,
                credits_remaining DECIMAL(10,2) NULL,
                attempts SMALLINT UNSIGNED NOT NULL DEFAULT 0,
                created_at DATETIME NOT NULL,
                generated_at DATETIME NULL,
                reviewed_at DATETIME NULL,
                updated_at DATETIME NOT NULL,
                PRIMARY KEY (id),
                UNIQUE KEY run_post (run_id, post_id, item_index),
                KEY run_status (run_id, status),
                KEY status (status),
                KEY post_id (post_id)
            ) {$charset_collate};";

            $sql_logs = "CREATE TABLE {$logs_table} (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                created_at DATETIME NOT NULL,
                level VARCHAR(10) NOT NULL DEFAULT 'info',
                area VARCHAR(32) NOT NULL DEFAULT 'general',
                event VARCHAR(64) NOT NULL DEFAULT 'event',
                message TEXT NOT NULL,
                context_json LONGTEXT NULL,
                run_id BIGINT UNSIGNED NULL,
                item_id BIGINT UNSIGNED NULL,
                post_id BIGINT UNSIGNED NULL,
                PRIMARY KEY (id),
                KEY created_at (created_at),
                KEY level (level),
                KEY area (area),
                KEY run_id (run_id),
                KEY post_id (post_id)
            ) {$charset_collate};";

            dbDelta($sql_templates);
            dbDelta($sql_runs);
            dbDelta($sql_items);
            dbDelta($sql_logs);

            self::ensure_multi_items_per_post_schema();
            self::ensure_logs_schema();

            if (false === get_option(UCG_Settings::OPTION_KEY, false)) {
                add_option(UCG_Settings::OPTION_KEY, UCG_Settings::defaults());
            }

            add_filter('cron_schedules', array('UCG_Generator', 'register_cron_schedule'));
            UCG_Generator::ensure_worker_scheduled();
            update_option('ucg_version', UCG_VERSION, false);
        }

        protected static function ensure_multi_items_per_post_schema() {
            global $wpdb;
            $items_table = UCG_DB::table_run_items();

            // Add missing column if dbDelta didn't.
            $has_item_index = $wpdb->get_var("SHOW COLUMNS FROM {$items_table} LIKE 'item_index'");
            if (!$has_item_index) {
                $wpdb->query("ALTER TABLE {$items_table} ADD COLUMN item_index INT UNSIGNED NOT NULL DEFAULT 1 AFTER post_id");
            }

            // Ensure unique key is (run_id, post_id, item_index).
            // dbDelta is not reliable for dropping/altering UNIQUE keys, so we do it manually.
            $unique = $wpdb->get_results("SHOW INDEX FROM {$items_table} WHERE Key_name = 'run_post'", ARRAY_A);
            $columns = array();
            if (is_array($unique)) {
                foreach ($unique as $row) {
                    if (!empty($row['Column_name'])) {
                        $columns[(int) $row['Seq_in_index']] = (string) $row['Column_name'];
                    }
                }
            }
            ksort($columns);
            $normalized = array_values($columns);
            $needs_update = empty($normalized) || $normalized !== array('run_id', 'post_id', 'item_index');

            if ($needs_update) {
                // Drop old key if exists, then add the correct one.
                if (!empty($unique)) {
                    $wpdb->query("ALTER TABLE {$items_table} DROP INDEX run_post");
                }
                $wpdb->query("ALTER TABLE {$items_table} ADD UNIQUE KEY run_post (run_id, post_id, item_index)");
            }
        }

        protected static function ensure_logs_schema() {
            global $wpdb;
            $logs_table = UCG_DB::table_logs();

            // Ensure core columns exist even if dbDelta missed them.
            $columns = array(
                'level' => "ALTER TABLE {$logs_table} ADD COLUMN level VARCHAR(10) NOT NULL DEFAULT 'info' AFTER created_at",
                'area' => "ALTER TABLE {$logs_table} ADD COLUMN area VARCHAR(32) NOT NULL DEFAULT 'general' AFTER level",
                'event' => "ALTER TABLE {$logs_table} ADD COLUMN event VARCHAR(64) NOT NULL DEFAULT 'event' AFTER area",
                'message' => "ALTER TABLE {$logs_table} ADD COLUMN message TEXT NOT NULL AFTER event",
                'context_json' => "ALTER TABLE {$logs_table} ADD COLUMN context_json LONGTEXT NULL AFTER message",
                'run_id' => "ALTER TABLE {$logs_table} ADD COLUMN run_id BIGINT UNSIGNED NULL AFTER context_json",
                'item_id' => "ALTER TABLE {$logs_table} ADD COLUMN item_id BIGINT UNSIGNED NULL AFTER run_id",
                'post_id' => "ALTER TABLE {$logs_table} ADD COLUMN post_id BIGINT UNSIGNED NULL AFTER item_id",
            );

            foreach ($columns as $column => $sql) {
                $has = $wpdb->get_var($wpdb->prepare("SHOW COLUMNS FROM {$logs_table} LIKE %s", $column));
                if (!$has) {
                    $wpdb->query($sql);
                }
            }

            // Ensure created_at exists (some MySQL variants may drop it in migrations).
            $has_created_at = $wpdb->get_var("SHOW COLUMNS FROM {$logs_table} LIKE 'created_at'");
            if (!$has_created_at) {
                $wpdb->query("ALTER TABLE {$logs_table} ADD COLUMN created_at DATETIME NOT NULL");
            }
        }

        public static function deactivate() {
            UCG_Generator::clear_worker_schedule();
            delete_transient(UCG_Generator::LOCK_KEY);
        }
    }
}
