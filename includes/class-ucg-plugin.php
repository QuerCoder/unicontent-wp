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
            add_filter('cron_schedules', array('UCG_Generator', 'register_cron_schedule'));

            $this->admin = new UCG_Admin();
            $this->admin->hooks();

            $this->generator = new UCG_Generator();
            $this->generator->hooks();

            UCG_Generator::ensure_worker_scheduled();
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
