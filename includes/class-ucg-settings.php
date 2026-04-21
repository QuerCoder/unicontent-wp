<?php

if (!defined('ABSPATH')) {
    exit;
}

if (!class_exists('UCG_Settings')) {
    class UCG_Settings {
        const OPTION_KEY = 'ucg_settings';

        public static function defaults() {
            return array(
                'api_base_url'     => 'https://unicontent.net',
                'api_key'          => '',
                'api_key_verified' => 0,
                'request_timeout'  => 60,
                'batch_size'       => 20,
                'generation_mode'  => 'review',
                'max_tokens'       => 1500,
                'system_prompt'    => '',
                'credits_cache_ttl'=> 60,
                'logs_keep_latest' => 2000,
                'logs_keep_days'   => 7,
                'default_language' => 'auto',
                'default_tone' => 'neutral',
                'default_uniqueness' => 'medium',
                'safety_no_medical_financial' => 1,
                'safety_no_competitors' => 1,
                'safety_no_caps' => 1,
            );
        }

        public static function sanitize($input) {
            $input = is_array($input) ? $input : array();
            $defaults = self::defaults();

            $api_base_url = isset($input['api_base_url']) ? trim((string) $input['api_base_url']) : $defaults['api_base_url'];
            if ($api_base_url === '') {
                $api_base_url = $defaults['api_base_url'];
            }
            $api_base_url = esc_url_raw($api_base_url);
            if ($api_base_url === '') {
                $api_base_url = $defaults['api_base_url'];
            }

            $api_key = isset($input['api_key']) ? sanitize_text_field((string) $input['api_key']) : '';
            $api_key_verified = !empty($input['api_key_verified']) ? 1 : 0;
            if ($api_key === '') {
                $api_key_verified = 0;
            }

            $request_timeout = isset($input['request_timeout']) ? (int) $input['request_timeout'] : (int) $defaults['request_timeout'];
            $request_timeout = max(10, min(180, $request_timeout));

            $batch_size = isset($input['batch_size']) ? (int) $input['batch_size'] : (int) $defaults['batch_size'];
            $batch_size = max(1, min(100, $batch_size));

            $generation_mode = isset($input['generation_mode']) ? sanitize_key((string) $input['generation_mode']) : (string) $defaults['generation_mode'];
            if (!in_array($generation_mode, array('review', 'publish'), true)) {
                $generation_mode = (string) $defaults['generation_mode'];
            }

            $max_tokens = isset($input['max_tokens']) ? (int) $input['max_tokens'] : (int) $defaults['max_tokens'];
            $max_tokens = max(1, min(4000, $max_tokens));

            $system_prompt = isset($input['system_prompt']) ? sanitize_textarea_field((string) $input['system_prompt']) : '';

            $credits_cache_ttl = isset($input['credits_cache_ttl']) ? (int) $input['credits_cache_ttl'] : (int) $defaults['credits_cache_ttl'];
            $credits_cache_ttl = max(10, min(600, $credits_cache_ttl));

            $logs_keep_latest = isset($input['logs_keep_latest']) ? (int) $input['logs_keep_latest'] : (int) $defaults['logs_keep_latest'];
            $logs_keep_latest = max(200, min(100000, $logs_keep_latest));

            $logs_keep_days = isset($input['logs_keep_days']) ? (int) $input['logs_keep_days'] : (int) $defaults['logs_keep_days'];
            $logs_keep_days = max(1, min(365, $logs_keep_days));

            $default_language = isset($input['default_language']) ? sanitize_key((string) $input['default_language']) : (string) $defaults['default_language'];
            if (!in_array($default_language, array('auto', 'ru', 'en'), true)) {
                $default_language = (string) $defaults['default_language'];
            }

            $default_tone = isset($input['default_tone']) ? sanitize_key((string) $input['default_tone']) : (string) $defaults['default_tone'];
            if (!in_array($default_tone, array('neutral', 'official', 'friendly'), true)) {
                $default_tone = (string) $defaults['default_tone'];
            }

            $default_uniqueness = isset($input['default_uniqueness']) ? sanitize_key((string) $input['default_uniqueness']) : (string) $defaults['default_uniqueness'];
            if (!in_array($default_uniqueness, array('low', 'medium', 'high'), true)) {
                $default_uniqueness = (string) $defaults['default_uniqueness'];
            }

            $safety_no_medical_financial = !empty($input['safety_no_medical_financial']) ? 1 : 0;
            $safety_no_competitors = !empty($input['safety_no_competitors']) ? 1 : 0;
            $safety_no_caps = !empty($input['safety_no_caps']) ? 1 : 0;

            return array(
                'api_base_url'      => $api_base_url,
                'api_key'           => $api_key,
                'api_key_verified'  => $api_key_verified,
                'request_timeout'   => $request_timeout,
                'batch_size'        => $batch_size,
                'generation_mode'   => $generation_mode,
                'max_tokens'        => $max_tokens,
                'system_prompt'     => $system_prompt,
                'credits_cache_ttl' => $credits_cache_ttl,
                'logs_keep_latest'  => $logs_keep_latest,
                'logs_keep_days'    => $logs_keep_days,
                'default_language' => $default_language,
                'default_tone' => $default_tone,
                'default_uniqueness' => $default_uniqueness,
                'safety_no_medical_financial' => $safety_no_medical_financial,
                'safety_no_competitors' => $safety_no_competitors,
                'safety_no_caps' => $safety_no_caps,
            );
        }

        public static function get() {
            $stored = get_option(self::OPTION_KEY, array());
            if (!is_array($stored)) {
                $stored = array();
            }

            return self::sanitize(wp_parse_args($stored, self::defaults()));
        }

        public static function get_option($key, $default = null) {
            $settings = self::get();
            return array_key_exists($key, $settings) ? $settings[$key] : $default;
        }

        public static function get_api_base_url() {
            $base = (string) self::get_option('api_base_url', self::defaults()['api_base_url']);
            return rtrim($base, '/');
        }

        public static function update($values) {
            $current = self::get();
            $values = is_array($values) ? $values : array();
            $next = self::sanitize(array_merge($current, $values));
            update_option(self::OPTION_KEY, $next, false);
            return $next;
        }

        public static function save_api_key($api_key, $verified = 0) {
            $api_key = sanitize_text_field((string) $api_key);
            return self::update(
                array(
                    'api_key' => $api_key,
                    'api_key_verified' => ($api_key !== '' && $verified) ? 1 : 0,
                )
            );
        }

        public static function has_valid_api_key() {
            $settings = self::get();
            return !empty($settings['api_key']) && !empty($settings['api_key_verified']);
        }

        public static function get_masked_api_key() {
            $api_key = (string) self::get_option('api_key', '');
            if ($api_key === '') {
                return '';
            }

            $length = strlen($api_key);
            if ($length <= 10) {
                return substr($api_key, 0, 2) . str_repeat('*', max(1, $length - 2));
            }

            return substr($api_key, 0, 4) . str_repeat('*', max(1, $length - 8)) . substr($api_key, -4);
        }
    }
}
