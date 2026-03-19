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

            $sql_templates = "CREATE TABLE {$templates_table} (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                name VARCHAR(190) NOT NULL,
                post_type VARCHAR(64) NOT NULL,
                body LONGTEXT NOT NULL,
                length_option_id BIGINT UNSIGNED NULL,
                vary_length TINYINT(1) NOT NULL DEFAULT 0,
                is_active TINYINT(1) NOT NULL DEFAULT 1,
                is_default TINYINT(1) NOT NULL DEFAULT 0,
                created_at DATETIME NOT NULL,
                updated_at DATETIME NOT NULL,
                PRIMARY KEY (id),
                KEY post_type (post_type),
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
                UNIQUE KEY run_post (run_id, post_id),
                KEY run_status (run_id, status),
                KEY status (status),
                KEY post_id (post_id)
            ) {$charset_collate};";

            dbDelta($sql_templates);
            dbDelta($sql_runs);
            dbDelta($sql_items);

            if (false === get_option(UCG_Settings::OPTION_KEY, false)) {
                add_option(UCG_Settings::OPTION_KEY, UCG_Settings::defaults());
            }

            add_filter('cron_schedules', array('UCG_Generator', 'register_cron_schedule'));
            UCG_Generator::ensure_worker_scheduled();
            update_option('ucg_version', UCG_VERSION, false);
        }

        public static function deactivate() {
            UCG_Generator::clear_worker_schedule();
            delete_transient(UCG_Generator::LOCK_KEY);
        }
    }
}
