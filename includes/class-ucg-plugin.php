<?php

if (!defined('ABSPATH')) {
    exit;
}

if (!class_exists('UCG_Plugin')) {
    class UCG_Plugin {
        protected $admin;
        protected $generator;

        public function run() {
            $this->maybe_upgrade();
            add_filter('all_plugins', [$this, 'localize_plugin_meta']);
            add_filter('cron_schedules', array('UCG_Generator', 'register_cron_schedule'));

            $this->admin = new UCG_Admin();
            $this->admin->hooks();

            $this->generator = new UCG_Generator();
            $this->generator->hooks();

            UCG_Generator::ensure_worker_scheduled();
        }

        public function localize_plugin_meta(array $plugins): array {
            $file = 'unicontent-ai-generator/unicontent-ai-generator.php';
            if (!isset($plugins[$file])) {
                return $plugins;
            }
            $locale = get_locale();
            if (strpos($locale, 'ru_') === 0 || $locale === 'ru') {
                $plugins[$file]['Name']        = 'UniContent AI — Генератор контента';
                $plugins[$file]['Description'] = 'Генерирует описания товаров, SEO-метатеги и тексты для WordPress и WooCommerce. Массовая обработка каталога.';
            }
            return $plugins;
        }

        protected function maybe_upgrade() {
            $installed_version = (string) get_option('ucg_version', '');
            if ($installed_version === UCG_VERSION) {
                return;
            }

            UCG_Activator::activate();
        }
    }
}
