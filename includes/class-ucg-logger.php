<?php

if (!defined('ABSPATH')) {
    exit;
}

if (!class_exists('UCG_Logger')) {
    class UCG_Logger {
        const LEVEL_INFO = 'info';
        const LEVEL_WARN = 'warn';
        const LEVEL_ERROR = 'error';

        const DEFAULT_KEEP_LATEST = 2000;
        const DEFAULT_KEEP_DAYS = 7;

        public static function log($level, $area, $event, $message, $context = array(), $run_id = null, $item_id = null, $post_id = null) {
            if (!class_exists('UCG_DB')) {
                return 0;
            }

            $level = sanitize_key((string) $level);
            if (!in_array($level, array(self::LEVEL_INFO, self::LEVEL_WARN, self::LEVEL_ERROR), true)) {
                $level = self::LEVEL_INFO;
            }
            $area = sanitize_key((string) $area);
            if ($area === '') {
                $area = 'general';
            }
            $event = sanitize_key((string) $event);
            if ($event === '') {
                $event = 'event';
            }

            $message = trim((string) $message);
            if ($message === '') {
                return 0;
            }

            $sanitized_context = self::sanitize_context($context);
            $context_json = $sanitized_context ? wp_json_encode($sanitized_context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : '';
            if (!is_string($context_json)) {
                $context_json = '';
            }
            if (strlen($context_json) > 8000) {
                $context_json = substr($context_json, 0, 8000);
            }

            $inserted = UCG_DB::insert_log($level, $area, $event, $message, $context_json, $run_id, $item_id, $post_id);

            // Best-effort cleanup. Keep it cheap and non-blocking.
            self::maybe_rotate();

            return $inserted;
        }

        public static function info($area, $event, $message, $context = array(), $run_id = null, $item_id = null, $post_id = null) {
            return self::log(self::LEVEL_INFO, $area, $event, $message, $context, $run_id, $item_id, $post_id);
        }

        public static function warn($area, $event, $message, $context = array(), $run_id = null, $item_id = null, $post_id = null) {
            return self::log(self::LEVEL_WARN, $area, $event, $message, $context, $run_id, $item_id, $post_id);
        }

        public static function error($area, $event, $message, $context = array(), $run_id = null, $item_id = null, $post_id = null) {
            return self::log(self::LEVEL_ERROR, $area, $event, $message, $context, $run_id, $item_id, $post_id);
        }

        public static function sanitize_context($context) {
            $context = is_array($context) ? $context : array();

            $blocked_keys = array(
                'api_key',
                'authorization',
                'prompt',
                'system_prompt',
                'generated_text',
                'text',
                'content',
                'body',
                'response_body',
                'request_body',
            );

            $result = array();
            foreach ($context as $key => $value) {
                $key_str = sanitize_key((string) $key);
                if ($key_str === '') {
                    continue;
                }
                if (in_array($key_str, $blocked_keys, true)) {
                    continue;
                }

                if (is_scalar($value) || $value === null) {
                    $result[$key_str] = self::trim_scalar($value);
                    continue;
                }

                if (is_array($value)) {
                    $result[$key_str] = self::trim_array($value, 2);
                }
            }

            return $result;
        }

        protected static function trim_scalar($value) {
            if ($value === null) {
                return null;
            }
            if (is_bool($value)) {
                return $value;
            }
            if (is_int($value) || is_float($value)) {
                return $value;
            }
            $text = (string) $value;
            $text = preg_replace('/\s+/u', ' ', $text);
            $text = trim((string) $text);
            if (strlen($text) > 500) {
                $text = substr($text, 0, 500) . '…';
            }
            return $text;
        }

        protected static function trim_array($value, $depth) {
            $depth = (int) $depth;
            if ($depth <= 0 || !is_array($value)) {
                return array();
            }

            $out = array();
            $count = 0;
            foreach ($value as $k => $v) {
                $count++;
                if ($count > 25) {
                    $out['_truncated'] = true;
                    break;
                }
                $key_str = sanitize_key((string) $k);
                if ($key_str === '') {
                    continue;
                }
                if (is_scalar($v) || $v === null) {
                    $out[$key_str] = self::trim_scalar($v);
                } elseif (is_array($v)) {
                    $out[$key_str] = self::trim_array($v, $depth - 1);
                }
            }

            return $out;
        }

        protected static function maybe_rotate() {
            // Run rotation at most once per minute.
            $lock_key = 'ucg_logs_rotate_lock';
            if (get_transient($lock_key)) {
                return;
            }
            set_transient($lock_key, 1, 60);

            $keep_latest = (int) (class_exists('UCG_Settings') ? UCG_Settings::get_option('logs_keep_latest', self::DEFAULT_KEEP_LATEST) : self::DEFAULT_KEEP_LATEST);
            $keep_days = (int) (class_exists('UCG_Settings') ? UCG_Settings::get_option('logs_keep_days', self::DEFAULT_KEEP_DAYS) : self::DEFAULT_KEEP_DAYS);

            $keep_latest = max(200, min(100000, $keep_latest));
            $keep_days = max(1, min(365, $keep_days));

            UCG_DB::delete_logs_older_than_days($keep_days);
            UCG_DB::delete_logs_keep_latest($keep_latest);
        }
    }
}

