<?php

if (!defined('ABSPATH')) {
    exit;
}

if (!class_exists('UCG_Generator')) {
    class UCG_Generator {
        const CRON_HOOK = 'ucg_process_queue_event';
        const FAST_CRON_HOOK = 'ucg_process_queue_fast_event';
        const LOCK_KEY = 'ucg_worker_lock';
        const LOCK_TTL = 55;

        public function hooks() {
            add_action(self::CRON_HOOK, array($this, 'process_queue'));
            add_action(self::FAST_CRON_HOOK, array($this, 'process_queue'));
        }

        public static function register_cron_schedule($schedules) {
            if (!is_array($schedules)) {
                $schedules = array();
            }

            if (!isset($schedules['ucg_every_minute'])) {
                $schedules['ucg_every_minute'] = array(
                    'interval' => 60,
                    'display' => __('Every minute (UNICONTENT)', 'unicontent-ai-generator'),
                );
            }

            return $schedules;
        }

        public static function ensure_worker_scheduled() {
            if (!wp_next_scheduled(self::CRON_HOOK)) {
                wp_schedule_event(time() + 60, 'ucg_every_minute', self::CRON_HOOK);
            }
        }

        public static function schedule_fast_worker($delay = 1) {
            $delay = max(1, min(30, (int) $delay));
            $target = time() + $delay;
            $next = wp_next_scheduled(self::FAST_CRON_HOOK);

            if ($next && $next <= ($target + 1)) {
                return;
            }

            while ($next) {
                wp_unschedule_event($next, self::FAST_CRON_HOOK);
                $next = wp_next_scheduled(self::FAST_CRON_HOOK);
            }

            wp_schedule_single_event($target, self::FAST_CRON_HOOK);
        }

        public static function kickstart_queue($immediate_items = 1) {
            self::ensure_worker_scheduled();
            self::schedule_fast_worker(1);

            $immediate_items = max(0, min(5, (int) $immediate_items));
            if ($immediate_items <= 0) {
                return;
            }

            $generator = new self();
            $generator->process_queue(false, $immediate_items);
        }

        public static function clear_worker_schedule() {
            $timestamp = wp_next_scheduled(self::CRON_HOOK);
            while ($timestamp) {
                wp_unschedule_event($timestamp, self::CRON_HOOK);
                $timestamp = wp_next_scheduled(self::CRON_HOOK);
            }

            $timestamp = wp_next_scheduled(self::FAST_CRON_HOOK);
            while ($timestamp) {
                wp_unschedule_event($timestamp, self::FAST_CRON_HOOK);
                $timestamp = wp_next_scheduled(self::FAST_CRON_HOOK);
            }
        }

        public function process_queue($force = false, $batch_size_override = 0) {
            if (!$this->acquire_lock($force)) {
                return;
            }

            $fatal_error = null;

            try {
                $settings = UCG_Settings::get();
                $batch_size_override = (int) $batch_size_override;
                if ($batch_size_override > 0) {
                    $batch_size = max(1, min(100, $batch_size_override));
                } else {
                    $batch_size = isset($settings['batch_size']) ? (int) $settings['batch_size'] : 20;
                    $batch_size = max(1, min(100, $batch_size));
                }

                $run = UCG_DB::get_next_active_run();
                if (!$run || empty($run['id'])) {
                    return;
                }

                $run_id = (int) $run['id'];
                if ((string) $run['status'] === 'queued') {
                    UCG_DB::update_run(
                        $run_id,
                        array(
                            'status' => 'running',
                            'started_at' => current_time('mysql', true),
                        )
                    );
                }

                $items = UCG_DB::get_run_items_for_processing($run_id, $batch_size);
                if (empty($items)) {
                    UCG_DB::finalize_run_if_done($run_id);
                    if (UCG_DB::get_next_active_run()) {
                        self::schedule_fast_worker(1);
                    }
                    return;
                }

                $api_client = new UCG_Api_Client();
                foreach ($items as $item) {
                    $result = $this->process_item($item, $api_client, $settings);
                    if (is_wp_error($result) && $this->is_fatal_api_error($result)) {
                        $fatal_error = $result->get_error_message();
                        UCG_DB::mark_pending_items_failed($run_id, $fatal_error);
                        UCG_DB::update_run(
                            $run_id,
                            array(
                                'status' => 'failed',
                                'error_message' => $fatal_error,
                                'finished_at' => current_time('mysql', true),
                            )
                        );
                        break;
                    }
                }

                UCG_DB::recalculate_run_counters($run_id);
                if (!$fatal_error) {
                    UCG_DB::finalize_run_if_done($run_id);
                    if (UCG_DB::get_next_active_run()) {
                        self::schedule_fast_worker(1);
                    }
                }
            } finally {
                $this->release_lock();
            }
        }

        protected function process_item($item, UCG_Api_Client $api_client, $settings) {
            $item_id = isset($item['id']) ? (int) $item['id'] : 0;
            $post_id = isset($item['post_id']) ? (int) $item['post_id'] : 0;
            $run_id = isset($item['run_id']) ? (int) $item['run_id'] : 0;
            $template_body = isset($item['template_body']) ? (string) $item['template_body'] : '';
            $options_json = isset($item['options_json']) ? (string) $item['options_json'] : '';
            $attempts = isset($item['attempts']) ? (int) $item['attempts'] : 0;
            $length_option_id = 0;
            $vary_length = 0;
            $model = 'auto';
            $scenario = 'field_update';

            if ($item_id <= 0 || $post_id <= 0 || $run_id <= 0) {
                return new WP_Error('ucg_invalid_queue_item', __('Некорректный элемент очереди.', 'unicontent-ai-generator'));
            }

            if ($options_json !== '') {
                $options = json_decode($options_json, true);
                if (is_array($options) && !empty($options['template_body'])) {
                    $template_body = (string) $options['template_body'];
                }
                if (is_array($options)) {
                    $scenario = isset($options['scenario']) ? sanitize_key((string) $options['scenario']) : 'field_update';
                    if ($scenario === '') {
                        $scenario = 'field_update';
                    }
                    $length_option_id = isset($options['length_option_id']) ? (int) $options['length_option_id'] : 0;
                    $vary_length = !empty($options['vary_length']) ? 1 : 0;
                    $model = isset($options['model']) ? sanitize_key((string) $options['model']) : 'auto';
                    if ($model === '') {
                        $model = 'auto';
                    }
                }
            }

            UCG_DB::update_run_item(
                $item_id,
                array(
                    'status' => 'processing',
                    'attempts' => $attempts + 1,
                    'error_message' => '',
                )
            );

            if ($template_body === '') {
                UCG_DB::update_run_item(
                    $item_id,
                    array(
                        'status' => 'failed',
                        'error_message' => __('Шаблон не найден или пустой.', 'unicontent-ai-generator'),
                    )
                );
                return new WP_Error('ucg_template_missing', __('Шаблон не найден.', 'unicontent-ai-generator'));
            }

            $prompt = UCG_Tokens::render_prompt_for_post($template_body, $post_id);
            if (trim($prompt) === '') {
                UCG_DB::update_run_item(
                    $item_id,
                    array(
                        'status' => 'failed',
                        'prompt' => $prompt,
                        'error_message' => __('Промпт пустой после подстановки переменных.', 'unicontent-ai-generator'),
                    )
                );
                return new WP_Error('ucg_prompt_empty', __('Промпт пустой.', 'unicontent-ai-generator'));
            }
            $prompt = $this->build_prompt_for_scenario($prompt, $scenario);

            $system_prompt = isset($settings['system_prompt']) ? (string) $settings['system_prompt'] : '';
            $max_tokens = isset($settings['max_tokens']) ? (int) $settings['max_tokens'] : 1500;
            $generation_mode = isset($settings['generation_mode']) ? sanitize_key((string) $settings['generation_mode']) : 'review';
            if (!in_array($generation_mode, array('review', 'publish'), true)) {
                $generation_mode = 'review';
            }

            $response = $api_client->generate_text($prompt, $system_prompt, $max_tokens, $length_option_id, $vary_length, $model);
            if (is_wp_error($response)) {
                $next_status = ($attempts + 1) >= 3 ? 'failed' : 'queued';
                if ($this->is_fatal_api_error($response)) {
                    $next_status = 'failed';
                }
                UCG_DB::update_run_item(
                    $item_id,
                    array(
                        'status' => $next_status,
                        'prompt' => $prompt,
                        'error_message' => $response->get_error_message(),
                    )
                );
                return $response;
            }

            $generated_text = isset($response['text']) ? (string) $response['text'] : '';
            if (trim($generated_text) === '') {
                $next_status = ($attempts + 1) >= 3 ? 'failed' : 'queued';
                UCG_DB::update_run_item(
                    $item_id,
                    array(
                        'status' => $next_status,
                        'prompt' => $prompt,
                        'error_message' => __('API вернул пустой результат.', 'unicontent-ai-generator'),
                    )
                );
                return new WP_Error('ucg_empty_result', __('Пустой ответ от API.', 'unicontent-ai-generator'));
            }

            $update_data = array(
                'status' => 'generated',
                'prompt' => $prompt,
                'generated_text' => $generated_text,
                'error_message' => '',
                'credits_spent' => isset($response['credits_spent']) ? (float) $response['credits_spent'] : 0.0,
                'credits_remaining' => isset($response['credits_remaining']) ? (float) $response['credits_remaining'] : 0.0,
                'generated_at' => current_time('mysql', true),
            );

            if ($generation_mode === 'publish') {
                $target_field = isset($item['target_field']) ? (string) $item['target_field'] : '';
                $write_result = UCG_Tokens::write_generated_value($post_id, $target_field, $generated_text);
                if (is_wp_error($write_result)) {
                    UCG_DB::update_run_item(
                        $item_id,
                        array(
                            'status' => 'failed',
                            'prompt' => $prompt,
                            'generated_text' => $generated_text,
                            'error_message' => $write_result->get_error_message(),
                            'credits_spent' => isset($response['credits_spent']) ? (float) $response['credits_spent'] : 0.0,
                            'credits_remaining' => isset($response['credits_remaining']) ? (float) $response['credits_remaining'] : 0.0,
                            'generated_at' => current_time('mysql', true),
                        )
                    );
                    return $write_result;
                }

                $update_data['status'] = 'approved';
                $update_data['reviewed_at'] = current_time('mysql', true);
            }

            UCG_DB::update_run_item($item_id, $update_data);

            return true;
        }

        protected function build_prompt_for_scenario($prompt, $scenario) {
            $prompt = (string) $prompt;
            $scenario = sanitize_key((string) $scenario);

            if ($scenario !== 'seo_tags') {
                return $prompt;
            }

            $instruction = "Верни только JSON без markdown и комментариев. Формат: "
                . "{\"title\":\"...\",\"description\":\"...\",\"focus_keyword\":\"...\"}. "
                . "title и description обязательны.";

            return $prompt . "\n\n" . $instruction;
        }

        protected function is_fatal_api_error(WP_Error $error) {
            $code = (string) $error->get_error_code();
            return strpos($code, 'ucg_api_http_401') === 0 || strpos($code, 'ucg_api_http_402') === 0;
        }

        protected function acquire_lock($force = false) {
            if ($force) {
                set_transient(self::LOCK_KEY, (string) microtime(true), self::LOCK_TTL);
                return true;
            }

            if (get_transient(self::LOCK_KEY)) {
                return false;
            }

            set_transient(self::LOCK_KEY, (string) microtime(true), self::LOCK_TTL);
            return true;
        }

        protected function release_lock() {
            delete_transient(self::LOCK_KEY);
        }
    }
}
