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

        public function process_queue($force = false, $batch_size_override = 0, $run_id_override = 0) {
            if (!$this->acquire_lock($force)) {
                return null;
            }

            $fatal_error = null;
            $processed_now = 0;
            $first_issue_type = '';
            $first_issue_message = '';

            try {
                $settings = UCG_Settings::get();
                $batch_size_override = (int) $batch_size_override;
                if ($batch_size_override > 0) {
                    $batch_size = max(1, min(100, $batch_size_override));
                } else {
                    $batch_size = isset($settings['batch_size']) ? (int) $settings['batch_size'] : 20;
                    $batch_size = max(1, min(100, $batch_size));
                }

                $run_id_override = (int) $run_id_override;
                $run = null;
                if ($run_id_override > 0) {
                    $candidate = UCG_DB::get_run($run_id_override);
                    $candidate_status = $candidate && isset($candidate['status']) ? sanitize_key((string) $candidate['status']) : '';
                    if ($candidate && $candidate_status !== '' && in_array($candidate_status, array('queued', 'running'), true)) {
                        $run = $candidate;
                    }
                }
                if (!$run) {
                    $run = UCG_DB::get_next_active_run();
                }
                if (!$run || empty($run['id'])) {
                    return array(
                        'processed_now' => 0,
                        'error_type' => '',
                        'error_message' => '',
                    );
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
                    return array(
                        'processed_now' => 0,
                        'error_type' => '',
                        'error_message' => '',
                    );
                }

                $api_client = new UCG_Api_Client();
                foreach ($items as $item) {
                    $result = $this->process_item($item, $api_client, $settings);
                    $processed_now++;
                    if (is_wp_error($result) && $this->is_fatal_api_error($result)) {
                        $fatal_error = $result->get_error_message();
                        if (class_exists('UCG_Logger')) {
                            UCG_Logger::error(
                                'generator',
                                'fatal_error',
                                'Fatal API error. Marking pending items failed.',
                                array(
                                    'issue' => $fatal_error,
                                ),
                                $run_id,
                                isset($item['id']) ? (int) $item['id'] : null,
                                isset($item['post_id']) ? (int) $item['post_id'] : null
                            );
                        }
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
                    if (is_wp_error($result) && $first_issue_type === '') {
                        $issue = $this->classify_issue($result);
                        $first_issue_type = isset($issue['type']) ? sanitize_key((string) $issue['type']) : '';
                        $first_issue_message = isset($issue['message']) ? (string) $issue['message'] : $result->get_error_message();
                        if (class_exists('UCG_Logger')) {
                            UCG_Logger::warn(
                                'generator',
                                'item_issue',
                                'Generation item issue.',
                                array(
                                    'error_code' => (string) $result->get_error_code(),
                                    'issue_type' => (string) $first_issue_type,
                                    'issue_message' => (string) $first_issue_message,
                                ),
                                $run_id,
                                isset($item['id']) ? (int) $item['id'] : null,
                                isset($item['post_id']) ? (int) $item['post_id'] : null
                            );
                        }
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

            return array(
                'processed_now' => (int) $processed_now,
                'error_type' => (string) $first_issue_type,
                'error_message' => (string) $first_issue_message,
            );
        }

        protected function classify_issue(WP_Error $error) {
            $code = (string) $error->get_error_code();
            $message = (string) $error->get_error_message();
            $type = 'unknown';

            if (strpos($code, 'ucg_api_http_') === 0) {
                $http = (int) substr($code, strlen('ucg_api_http_'));
                if ($http === 429) {
                    $type = 'rate_limit';
                } elseif ($http === 408) {
                    $type = 'timeout';
                } elseif ($http >= 500 && $http <= 599) {
                    $type = 'server_error';
                } else {
                    $type = 'http_error';
                }
            } elseif ($code === 'ucg_api_connection') {
                $lower = strtolower($message);
                if (strpos($lower, 'timed out') !== false || strpos($lower, 'timeout') !== false) {
                    $type = 'timeout';
                } else {
                    $type = 'network';
                }
            }

            return array(
                'type' => $type,
                'message' => $message,
            );
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
            $seo_title_prompt_template = '';
            $seo_description_prompt_template = '';
            $publish_date_from = '';
            $publish_date_to = '';
            $rating_min = 1;
            $rating_max = 5;
            $style_language = 'auto';
            $style_tone = 'neutral';
            $style_uniqueness = 'medium';
            $run_seed = '';
            $options = array();

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
                    $seo_title_prompt_template = isset($options['seo_title_prompt']) ? (string) $options['seo_title_prompt'] : '';
                    $seo_description_prompt_template = isset($options['seo_description_prompt']) ? (string) $options['seo_description_prompt'] : '';
                    $length_option_id = isset($options['length_option_id']) ? (int) $options['length_option_id'] : 0;
                    $vary_length = !empty($options['vary_length']) ? 1 : 0;
                    $model = isset($options['model']) ? $this->normalize_model_identifier((string) $options['model']) : 'auto';
                    if ($model === '') {
                        $model = 'auto';
                    }
                    $publish_date_from = isset($options['publish_date_from']) ? (string) $options['publish_date_from'] : '';
                    $publish_date_to = isset($options['publish_date_to']) ? (string) $options['publish_date_to'] : '';
                    $rating_min = isset($options['rating_min']) ? (int) $options['rating_min'] : 1;
                    $rating_max = isset($options['rating_max']) ? (int) $options['rating_max'] : 5;
                    $style_language = isset($options['style_language']) ? sanitize_key((string) $options['style_language']) : 'auto';
                    $style_tone = isset($options['style_tone']) ? sanitize_key((string) $options['style_tone']) : 'neutral';
                    $style_uniqueness = isset($options['style_uniqueness']) ? sanitize_key((string) $options['style_uniqueness']) : 'medium';
                    $run_seed = isset($options['run_seed']) ? (string) $options['run_seed'] : '';
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

            $system_prompt = isset($settings['system_prompt']) ? (string) $settings['system_prompt'] : '';
            $max_tokens = isset($settings['max_tokens']) ? (int) $settings['max_tokens'] : 1500;
            $generation_mode = isset($settings['generation_mode']) ? sanitize_key((string) $settings['generation_mode']) : 'review';
            if (!in_array($generation_mode, array('review', 'publish'), true)) {
                $generation_mode = 'review';
            }
            if ($scenario === 'comments' || $scenario === 'woo_reviews') {
                $generation_mode = 'review';
            }
            $generation_mode = $this->resolve_effective_generation_mode($generation_mode, $scenario, $options);
            $write_context = $this->build_write_context($scenario, $publish_date_from, $publish_date_to);
            if ($scenario === 'woo_reviews') {
                $rating_min = max(1, min(5, (int) $rating_min));
                $rating_max = max(1, min(5, (int) $rating_max));
                if ($rating_min > $rating_max) {
                    $tmp = $rating_min;
                    $rating_min = $rating_max;
                    $rating_max = $tmp;
                }
                $write_context['rating_min'] = $rating_min;
                $write_context['rating_max'] = $rating_max;
            }

            $system_prompt = $this->build_effective_system_prompt(
                $system_prompt,
                $scenario,
                $style_language,
                $style_tone,
                $style_uniqueness,
                $run_seed,
                $run_id,
                $post_id,
                isset($item['item_index']) ? (int) $item['item_index'] : 1
            );

            $default_target_field = isset($item['target_field']) ? (string) $item['target_field'] : '';
            $ai_fields = $this->normalize_ai_fields_for_item(
                $scenario,
                $options,
                $template_body,
                $seo_title_prompt_template,
                $seo_description_prompt_template,
                $length_option_id,
                $default_target_field
            );
            $static_fields = $this->normalize_static_fields_for_item($options, $scenario);

            if (empty($ai_fields) && empty($static_fields)) {
                UCG_DB::update_run_item(
                    $item_id,
                    array(
                        'status' => 'failed',
                        'error_message' => __('Шаблон не найден или пустой.', 'unicontent-ai-generator'),
                    )
                );
                return new WP_Error('ucg_template_missing', __('Шаблон не найден.', 'unicontent-ai-generator'));
            }

            if ($this->should_use_multi_field_json_mode($scenario, $options)) {
                return $this->process_item_create_json(
                    $item,
                    $api_client,
                    $scenario,
                    $ai_fields,
                    $static_fields,
                    $system_prompt,
                    $max_tokens,
                    $model,
                    $generation_mode,
                    $write_context,
                    $attempts,
                    $length_option_id
                );
            }

            $generated_fields = array(
                'ai_fields' => array(),
                'static_fields' => array(),
            );
            $combined_prompt_parts = array();
            $credits_spent_total = 0.0;
            $credits_remaining = 0.0;
            $generated_values_by_key = array();

            foreach ($ai_fields as $field_index => $field) {
                if (!is_array($field)) {
                    continue;
                }

                $field_key = isset($field['key']) ? sanitize_key((string) $field['key']) : ('field_' . ((int) $field_index + 1));
                if ($field_key === '') {
                    $field_key = 'field_' . ((int) $field_index + 1);
                }
                $field_label = isset($field['label']) ? sanitize_text_field((string) $field['label']) : $field_key;
                $field_enabled = !array_key_exists('enabled', $field) || !empty($field['enabled']);
                $field_prompt_template = isset($field['prompt']) ? (string) $field['prompt'] : '';
                $field_target = $this->normalize_generation_target_field(
                    isset($field['target_field']) ? (string) $field['target_field'] : ''
                );
                if ($field_target === '') {
                    $field_target = $default_target_field;
                }
                $field_length_option_id = isset($field['length_option_id']) ? (int) $field['length_option_id'] : 0;
                if ($field_length_option_id <= 0) {
                    $field_length_option_id = $length_option_id;
                }
                $field_max_chars = isset($field['max_chars']) ? max(0, (int) $field['max_chars']) : 0;
                $field_output_type = sanitize_key(isset($field['output_type']) ? (string) $field['output_type'] : '');
                if ($field_output_type === '') {
                    $field_output_type = strpos($field_target, 'media:') === 0 ? 'image' : 'text';
                }
                if ($field_output_type !== 'image') {
                    $field_output_type = 'text';
                }
                $field_model = $this->normalize_model_identifier(isset($field['model']) ? (string) $field['model'] : 'auto');
                if ($field_model === '') {
                    $field_model = 'auto';
                }
                $field_images_count = isset($field['images_count']) ? max(1, min(8, (int) $field['images_count'])) : 1;
                $field_aspect_ratio = isset($field['aspect_ratio']) ? sanitize_text_field((string) $field['aspect_ratio']) : '';
                if (!in_array($field_aspect_ratio, array('1:1', '2:3', '3:2', '3:4', '4:3', '4:5', '5:4', '9:16', '16:9', '21:9', '1:4', '4:1', '1:8', '8:1'), true)) {
                    $field_aspect_ratio = '';
                }
                $field_image_size = isset($field['image_size']) ? strtoupper(sanitize_text_field((string) $field['image_size'])) : '';
                if (!in_array($field_image_size, array('0.5K', '1K', '2K', '4K'), true)) {
                    $field_image_size = '';
                }

                if (!$field_enabled) {
                    $generated_fields['ai_fields'][] = array(
                        'key' => $field_key,
                        'label' => $field_label,
                        'target_field' => $field_target,
                        'status' => 'skipped',
                        'reason' => 'disabled',
                        'prompt_template' => $field_prompt_template,
                        'prompt' => '',
                        'generated_text' => '',
                        'length_option_id' => $field_length_option_id,
                        'max_chars' => $field_max_chars,
                        'output_type' => $field_output_type,
                        'model' => $field_model,
                        'images_count' => $field_images_count,
                        'aspect_ratio' => $field_aspect_ratio,
                        'image_size' => $field_image_size,
                        'generated_media' => array(),
                        'credits_spent' => 0.0,
                    );
                    continue;
                }

                if (trim($field_prompt_template) === '') {
                    UCG_DB::update_run_item(
                        $item_id,
                        array(
                            'status' => 'failed',
                            'error_message' => __('Промпт поля пустой.', 'unicontent-ai-generator'),
                            'generated_fields_json' => wp_json_encode($generated_fields, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                        )
                    );
                    return new WP_Error('ucg_prompt_empty', __('Промпт пустой.', 'unicontent-ai-generator'));
                }

                $field_prompt = UCG_Tokens::render_prompt_for_post($field_prompt_template, $post_id);
                $field_prompt = $this->inject_generated_field_tokens($field_prompt, $generated_values_by_key);
                if (trim($field_prompt) === '') {
                    UCG_DB::update_run_item(
                        $item_id,
                        array(
                            'status' => 'failed',
                            'error_message' => __('Промпт пустой после подстановки переменных.', 'unicontent-ai-generator'),
                            'generated_fields_json' => wp_json_encode($generated_fields, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                        )
                    );
                    return new WP_Error('ucg_prompt_empty', __('Промпт пустой.', 'unicontent-ai-generator'));
                }

                if ($field_output_type === 'text') {
                    if ($field_target === 'seo_field:title') {
                        $field_prompt = $this->build_prompt_for_single_seo_field($field_prompt, 'title');
                        if ($field_max_chars <= 0) {
                            $field_max_chars = 70;
                        }
                    } elseif ($field_target === 'seo_field:description') {
                        $field_prompt = $this->build_prompt_for_single_seo_field($field_prompt, 'description');
                        if ($field_max_chars <= 0) {
                            $field_max_chars = 160;
                        }
                    } elseif (strpos($field_target, 'comment:') === 0 || strpos($field_target, 'woo_review:') === 0) {
                        $target_scenario = strpos($field_target, 'woo_review:') === 0 ? 'woo_reviews' : 'comments';
                        $field_prompt = $this->build_prompt_for_comment_and_review_scenarios($field_prompt, $target_scenario, $rating_min, $rating_max);
                    } else {
                        $field_prompt = $this->build_prompt_for_comment_and_review_scenarios($field_prompt, $scenario, $rating_min, $rating_max);
                    }
                }

                $combined_prompt_parts[] = '[' . $field_key . "]\n" . $field_prompt;

                if ($field_output_type === 'image') {
                    $image_model = $field_model !== '' ? $field_model : $model;
                    if ($image_model === '') {
                        $image_model = 'auto';
                    }
                    $response = $api_client->generate_image(
                        $field_prompt,
                        $system_prompt,
                        $image_model,
                        $field_images_count,
                        $field_aspect_ratio,
                        $field_image_size,
                        'image'
                    );
                } else {
                    $response = $api_client->generate_text(
                        $field_prompt,
                        $system_prompt,
                        $max_tokens,
                        $field_length_option_id,
                        $vary_length,
                        $model,
                        $this->resolve_operation_type_for_scenario($scenario, 'text')
                    );
                }
                if (is_wp_error($response)) {
                    if ($field_output_type === 'image' && $this->should_skip_image_field_error($scenario, $response)) {
                        $generated_fields['ai_fields'][] = array(
                            'key' => $field_key,
                            'label' => $field_label,
                            'target_field' => $field_target,
                            'status' => 'skipped',
                            'reason' => 'image_service_unavailable',
                            'error_message' => $response->get_error_message(),
                            'prompt_template' => $field_prompt_template,
                            'prompt' => $field_prompt,
                            'generated_text' => '',
                            'length_option_id' => $field_length_option_id,
                            'max_chars' => $field_max_chars,
                            'output_type' => $field_output_type,
                            'model' => $field_model,
                            'images_count' => $field_images_count,
                            'aspect_ratio' => $field_aspect_ratio,
                            'image_size' => $field_image_size,
                            'generated_media' => array(),
                            'credits_spent' => 0.0,
                            'credits_remaining' => $credits_remaining,
                        );
                        continue;
                    }
                    $next_status = ($attempts + 1) >= 3 ? 'failed' : 'queued';
                    if ($this->is_fatal_api_error($response)) {
                        $next_status = 'failed';
                    }
                    UCG_DB::update_run_item(
                        $item_id,
                        array(
                            'status' => $next_status,
                            'prompt' => implode("\n\n---\n\n", $combined_prompt_parts),
                            'error_message' => $response->get_error_message(),
                            'generated_fields_json' => wp_json_encode($generated_fields, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                        )
                    );
                    return $response;
                }

                $generated_media = array();
                $generated_value = '';
                if ($field_output_type === 'image') {
                    $generated_media = $this->extract_image_sources_from_api_response($response);
                    if (!empty($generated_media)) {
                        $generated_value = wp_json_encode($generated_media, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                        $generated_values_by_key[$field_key] = (string) $generated_media[0];
                    }
                } else {
                    $generated_value = isset($response['text']) ? (string) $response['text'] : '';
                    if ($field_target === 'seo_field:title' || $field_target === 'seo_field:description') {
                        $generated_value = $this->normalize_single_line_result($generated_value);
                        if ($field_max_chars > 0) {
                            $generated_value = $this->soft_trim_to_chars($generated_value, $field_max_chars);
                        }
                    } elseif ($field_max_chars > 0) {
                        $generated_value = $this->soft_trim_to_chars($generated_value, $field_max_chars);
                    }
                    $generated_values_by_key[$field_key] = $generated_value;
                }

                if (trim($generated_value) === '') {
                    if ($field_output_type === 'image' && sanitize_key((string) $scenario) !== 'image_generation') {
                        $generated_fields['ai_fields'][] = array(
                            'key' => $field_key,
                            'label' => $field_label,
                            'target_field' => $field_target,
                            'status' => 'skipped',
                            'reason' => 'image_empty_result',
                            'error_message' => __('API вернул пустой результат.', 'unicontent-ai-generator'),
                            'prompt_template' => $field_prompt_template,
                            'prompt' => $field_prompt,
                            'generated_text' => '',
                            'length_option_id' => $field_length_option_id,
                            'max_chars' => $field_max_chars,
                            'output_type' => $field_output_type,
                            'model' => $field_model,
                            'images_count' => $field_images_count,
                            'aspect_ratio' => $field_aspect_ratio,
                            'image_size' => $field_image_size,
                            'generated_media' => array(),
                            'credits_spent' => isset($response['credits_spent']) ? (float) $response['credits_spent'] : 0.0,
                            'credits_remaining' => isset($response['credits_remaining']) ? (float) $response['credits_remaining'] : $credits_remaining,
                        );
                        continue;
                    }
                    $next_status = ($attempts + 1) >= 3 ? 'failed' : 'queued';
                    UCG_DB::update_run_item(
                        $item_id,
                        array(
                            'status' => $next_status,
                            'prompt' => implode("\n\n---\n\n", $combined_prompt_parts),
                            'error_message' => __('API вернул пустой результат.', 'unicontent-ai-generator'),
                            'generated_fields_json' => wp_json_encode($generated_fields, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                            'credits_spent' => isset($response['credits_spent']) ? (float) $response['credits_spent'] : 0.0,
                            'credits_remaining' => isset($response['credits_remaining']) ? (float) $response['credits_remaining'] : 0.0,
                        )
                    );
                    return new WP_Error('ucg_empty_result', __('Пустой ответ от API.', 'unicontent-ai-generator'));
                }

                $field_credits_spent = isset($response['credits_spent']) ? (float) $response['credits_spent'] : 0.0;
                $credits_spent_total += $field_credits_spent;
                $credits_remaining = isset($response['credits_remaining']) ? (float) $response['credits_remaining'] : $credits_remaining;

                $generated_fields['ai_fields'][] = array(
                    'key' => $field_key,
                    'label' => $field_label,
                    'target_field' => $field_target,
                    'status' => 'generated',
                    'prompt_template' => $field_prompt_template,
                    'prompt' => $field_prompt,
                    'generated_text' => $generated_value,
                    'length_option_id' => $field_length_option_id,
                    'max_chars' => $field_max_chars,
                    'output_type' => $field_output_type,
                    'model' => $field_model,
                    'images_count' => $field_images_count,
                    'aspect_ratio' => $field_aspect_ratio,
                    'image_size' => $field_image_size,
                    'generated_media' => $generated_media,
                    'credits_spent' => $field_credits_spent,
                    'credits_remaining' => isset($response['credits_remaining']) ? (float) $response['credits_remaining'] : 0.0,
                );
            }

            foreach ($static_fields as $field) {
                if (!is_array($field)) {
                    continue;
                }
                $field_key = isset($field['key']) ? sanitize_key((string) $field['key']) : '';
                if ($field_key === '') {
                    $field_key = 'static_' . (count($generated_fields['static_fields']) + 1);
                }
                $field_label = isset($field['label']) ? sanitize_text_field((string) $field['label']) : $field_key;
                $field_enabled = !array_key_exists('enabled', $field) || !empty($field['enabled']);
                $field_target = $this->normalize_generation_target_field(
                    isset($field['target_field']) ? (string) $field['target_field'] : ''
                );
                if ($field_target === '') {
                    continue;
                }
                if (!$field_enabled) {
                    $generated_fields['static_fields'][] = array(
                        'key' => $field_key,
                        'label' => $field_label,
                        'target_field' => $field_target,
                        'status' => 'skipped',
                        'reason' => 'disabled',
                        'value' => isset($field['value']) ? $field['value'] : '',
                    );
                    continue;
                }

                $generated_fields['static_fields'][] = array(
                    'key' => $field_key,
                    'label' => $field_label,
                    'target_field' => $field_target,
                    'status' => 'generated',
                    'value' => isset($field['value']) ? $field['value'] : '',
                );
            }

            $combined_prompt = implode("\n\n---\n\n", $combined_prompt_parts);
            $generated_text = $this->build_legacy_generated_text_from_fields_payload($generated_fields, $scenario);
            $generated_fields_json = wp_json_encode($generated_fields, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

            $update_data = array(
                'status' => 'generated',
                'prompt' => $combined_prompt,
                'generated_text' => $generated_text,
                'generated_fields_json' => $generated_fields_json,
                'error_message' => '',
                'credits_spent' => $credits_spent_total,
                'credits_remaining' => $credits_remaining,
                'generated_at' => current_time('mysql', true),
            );

            if ($generation_mode === 'publish') {
                $item_target_field = isset($item['target_field']) ? (string) $item['target_field'] : '';
                $write_result = $this->write_generated_fields_for_item($post_id, $item_target_field, $generated_fields, $write_context);
                if (is_wp_error($write_result)) {
                    UCG_DB::update_run_item(
                        $item_id,
                        array(
                            'status' => 'failed',
                            'prompt' => $combined_prompt,
                            'generated_text' => $generated_text,
                            'generated_fields_json' => $generated_fields_json,
                            'error_message' => $write_result->get_error_message(),
                            'credits_spent' => $credits_spent_total,
                            'credits_remaining' => $credits_remaining,
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

        protected function normalize_model_identifier($value) {
            $value = trim((string) $value);
            if ($value === '') {
                return '';
            }

            $value = sanitize_text_field($value);
            $value = preg_replace('/[^A-Za-z0-9._:\\/-]/', '', (string) $value);
            if (!is_string($value)) {
                return '';
            }
            return trim($value);
        }

        protected function inject_generated_field_tokens($text, $generated_values_by_key) {
            $text = (string) $text;
            $generated_values_by_key = is_array($generated_values_by_key) ? $generated_values_by_key : array();
            if ($text === '' || empty($generated_values_by_key)) {
                return $text;
            }

            foreach ($generated_values_by_key as $field_key => $value) {
                $field_key = sanitize_key((string) $field_key);
                if ($field_key === '') {
                    continue;
                }

                if (is_array($value) || is_object($value)) {
                    $replacement = wp_json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                } elseif ($value === null) {
                    $replacement = '';
                } elseif (is_bool($value)) {
                    $replacement = $value ? '1' : '0';
                } else {
                    $replacement = (string) $value;
                }

                $token_pattern_double = '/\{\{\s*generated\s*:\s*' . preg_quote($field_key, '/') . '\s*\}\}/iu';
                $token_pattern_single = '/\{\s*generated\s*:\s*' . preg_quote($field_key, '/') . '\s*\}/iu';
                $text = preg_replace_callback($token_pattern_double, function () use ($replacement) {
                    return $replacement;
                }, $text);
                $text = preg_replace_callback($token_pattern_single, function () use ($replacement) {
                    return $replacement;
                }, $text);
            }

            return (string) $text;
        }

        protected function extract_image_sources_from_api_response($response) {
            $response = is_array($response) ? $response : array();
            if (empty($response)) {
                return array();
            }

            $sources = array();
            $collector = null;
            $collector = function ($value) use (&$collector, &$sources) {
                if (count($sources) >= 8) {
                    return;
                }
                if (is_string($value)) {
                    $candidate = trim($value);
                    if ($candidate === '') {
                        return;
                    }
                    if (preg_match('#^data:image/[a-zA-Z0-9.+-]+;base64,#', $candidate) || filter_var($candidate, FILTER_VALIDATE_URL)) {
                        $sources[] = $candidate;
                        return;
                    }
                    $decoded = json_decode($candidate, true);
                    if (is_array($decoded)) {
                        $collector($decoded);
                    }
                    return;
                }

                if (!is_array($value)) {
                    return;
                }

                if (isset($value['url'])) {
                    $collector($value['url']);
                }
                if (isset($value['image_url']) && is_array($value['image_url']) && isset($value['image_url']['url'])) {
                    $collector($value['image_url']['url']);
                }
                if (isset($value['imageUrl']) && is_array($value['imageUrl']) && isset($value['imageUrl']['url'])) {
                    $collector($value['imageUrl']['url']);
                }
                if (isset($value['images']) && is_array($value['images'])) {
                    foreach ($value['images'] as $item) {
                        $collector($item);
                    }
                }
                if (array_keys($value) === range(0, count($value) - 1)) {
                    foreach ($value as $item) {
                        $collector($item);
                    }
                }
            };

            $collector($response);

            if (empty($sources)) {
                return array();
            }
            $sources = array_values(array_unique(array_filter(array_map('strval', $sources))));
            if (count($sources) > 8) {
                $sources = array_slice($sources, 0, 8);
            }
            return $sources;
        }

        protected function should_use_multi_field_json_mode($scenario, $options) {
            $scenario = sanitize_key((string) $scenario);
            if ($scenario !== 'post_fields' && $scenario !== 'product_fields') {
                return false;
            }
            $options = is_array($options) ? $options : array();
            $scope = isset($options['scope']) ? sanitize_key((string) $options['scope']) : 'selected';
            return $scope === 'create_new';
        }

        protected function process_item_create_json(
            $item,
            UCG_Api_Client $api_client,
            $scenario,
            $ai_fields,
            $static_fields,
            $system_prompt,
            $max_tokens,
            $model,
            $generation_mode,
            $write_context,
            $attempts,
            $fallback_length_option_id = 0
        ) {
            $item_id = isset($item['id']) ? (int) $item['id'] : 0;
            $post_id = isset($item['post_id']) ? (int) $item['post_id'] : 0;
            $scenario = sanitize_key((string) $scenario);
            $ai_fields = is_array($ai_fields) ? $ai_fields : array();
            $static_fields = is_array($static_fields) ? $static_fields : array();
            $system_prompt = (string) $system_prompt;
            $max_tokens = max(1, (int) $max_tokens);
            $model = $this->normalize_model_identifier((string) $model);
            if ($model === '') {
                $model = 'auto';
            }
            $generation_mode = sanitize_key((string) $generation_mode);
            $write_context = is_array($write_context) ? $write_context : array();
            $attempts = max(0, (int) $attempts);
            $fallback_length_option_id = max(0, (int) $fallback_length_option_id);

            $generated_fields = array(
                'ai_fields' => array(),
                'static_fields' => array(),
            );
            $combined_prompt_parts = array();
            $field_specs = array();
            $enabled_text_field_map = array();
            $enabled_image_field_map = array();
            $generated_values_by_key = array();

            $item_index = isset($item['item_index']) ? max(1, (int) $item['item_index']) : 1;
            $options = array();
            $options_json = isset($item['options_json']) ? (string) $item['options_json'] : '';
            if ($options_json !== '') {
                $decoded = json_decode($options_json, true);
                if (is_array($decoded)) {
                    $options = $decoded;
                }
            }
            $item_topic = $this->resolve_create_item_topic_for_item($post_id, $options, $item_index);
            $item_context = $this->resolve_create_item_context_for_item($post_id, $item_topic);

            foreach ($ai_fields as $field_index => $field) {
                if (!is_array($field)) {
                    continue;
                }

                $field_key = isset($field['key']) ? sanitize_key((string) $field['key']) : ('field_' . ((int) $field_index + 1));
                if ($field_key === '') {
                    $field_key = 'field_' . ((int) $field_index + 1);
                }
                $field_label = isset($field['label']) ? sanitize_text_field((string) $field['label']) : $field_key;
                $field_enabled = !array_key_exists('enabled', $field) || !empty($field['enabled']);
                $field_prompt_template = isset($field['prompt']) ? (string) $field['prompt'] : '';
                $field_target = $this->normalize_generation_target_field(
                    isset($field['target_field']) ? (string) $field['target_field'] : ''
                );
                if ($field_target === '') {
                    $field_target = $this->resolve_field_target_by_key($field_key);
                }
                $field_length_option_id = isset($field['length_option_id']) ? (int) $field['length_option_id'] : 0;
                if ($field_length_option_id <= 0) {
                    $field_length_option_id = $fallback_length_option_id;
                }
                $field_max_chars = isset($field['max_chars']) ? max(0, (int) $field['max_chars']) : 0;
                $field_output_type = sanitize_key(isset($field['output_type']) ? (string) $field['output_type'] : '');
                if ($field_output_type === '') {
                    $field_output_type = strpos($field_target, 'media:') === 0 ? 'image' : 'text';
                }
                if ($field_output_type !== 'image') {
                    $field_output_type = 'text';
                }
                $field_model = $this->normalize_model_identifier(isset($field['model']) ? (string) $field['model'] : 'auto');
                if ($field_model === '') {
                    $field_model = 'auto';
                }
                $field_images_count = isset($field['images_count']) ? max(1, min(8, (int) $field['images_count'])) : 1;
                $field_aspect_ratio = isset($field['aspect_ratio']) ? sanitize_text_field((string) $field['aspect_ratio']) : '';
                if (!in_array($field_aspect_ratio, array('1:1', '2:3', '3:2', '3:4', '4:3', '4:5', '5:4', '9:16', '16:9', '21:9', '1:4', '4:1', '1:8', '8:1'), true)) {
                    $field_aspect_ratio = '';
                }
                $field_image_size = isset($field['image_size']) ? strtoupper(sanitize_text_field((string) $field['image_size'])) : '';
                if (!in_array($field_image_size, array('0.5K', '1K', '2K', '4K'), true)) {
                    $field_image_size = '';
                }
                if ($field_target === 'seo_field:title' && $field_max_chars <= 0) {
                    $field_max_chars = 70;
                } elseif ($field_target === 'seo_field:description' && $field_max_chars <= 0) {
                    $field_max_chars = 160;
                }

                if (!$field_enabled) {
                    $generated_fields['ai_fields'][] = array(
                        'key' => $field_key,
                        'label' => $field_label,
                        'target_field' => $field_target,
                        'status' => 'skipped',
                        'reason' => 'disabled',
                        'prompt_template' => $field_prompt_template,
                        'prompt' => '',
                        'generated_text' => '',
                        'length_option_id' => $field_length_option_id,
                        'max_chars' => $field_max_chars,
                        'output_type' => $field_output_type,
                        'model' => $field_model,
                        'images_count' => $field_images_count,
                        'aspect_ratio' => $field_aspect_ratio,
                        'image_size' => $field_image_size,
                        'generated_media' => array(),
                        'sort_index' => (int) $field_index,
                        'credits_spent' => 0.0,
                    );
                    continue;
                }

                if (trim($field_prompt_template) === '') {
                    UCG_DB::update_run_item(
                        $item_id,
                        array(
                            'status' => 'failed',
                            'error_message' => __('Промпт поля пустой.', 'unicontent-ai-generator'),
                            'generated_fields_json' => wp_json_encode($generated_fields, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                        )
                    );
                    return new WP_Error('ucg_prompt_empty', __('Промпт пустой.', 'unicontent-ai-generator'));
                }

                $field_prompt = trim((string) $field_prompt_template);
                $field_prompt = $this->inject_create_runtime_tokens($field_prompt, $item_topic, $item_context);
                $field_prompt = $this->inject_generated_field_tokens($field_prompt, $generated_values_by_key);
                $field_prompt = $this->prepend_create_topic_context_to_prompt($field_prompt, $item_topic, $item_context);
                if (trim($field_prompt) === '') {
                    UCG_DB::update_run_item(
                        $item_id,
                        array(
                            'status' => 'failed',
                            'error_message' => __('Промпт пустой после подстановки переменных.', 'unicontent-ai-generator'),
                            'generated_fields_json' => wp_json_encode($generated_fields, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                        )
                    );
                    return new WP_Error('ucg_prompt_empty', __('Промпт пустой.', 'unicontent-ai-generator'));
                }

                if ($field_output_type === 'text') {
                    if ($field_target === 'seo_field:title') {
                        $field_prompt = $this->build_prompt_for_single_seo_field($field_prompt, 'title');
                    } elseif ($field_target === 'seo_field:description') {
                        $field_prompt = $this->build_prompt_for_single_seo_field($field_prompt, 'description');
                    }
                }

                $combined_prompt_parts[] = '[' . $field_key . "]\n" . $field_prompt;

                $field_meta = array(
                    'key' => $field_key,
                    'label' => $field_label,
                    'target_field' => $field_target,
                    'prompt_template' => $field_prompt_template,
                    'prompt' => $field_prompt,
                    'length_option_id' => $field_length_option_id,
                    'max_chars' => $field_max_chars,
                    'output_type' => $field_output_type,
                    'model' => $field_model,
                    'images_count' => $field_images_count,
                    'aspect_ratio' => $field_aspect_ratio,
                    'image_size' => $field_image_size,
                    'sort_index' => (int) $field_index,
                );

                if ($field_output_type === 'image') {
                    $enabled_image_field_map[$field_key] = $field_meta;
                } else {
                    $enabled_text_field_map[$field_key] = $field_meta;
                    $field_specs[] = array(
                        'key' => $field_key,
                        'label' => $field_label,
                        'max_chars' => $field_max_chars,
                        'hint' => $this->multi_field_hint_for_target($field_key, $field_target),
                        'required' => true,
                    );
                }
            }

            if (empty($enabled_text_field_map) && empty($enabled_image_field_map)) {
                // In create JSON mode this can still be valid if user selected only static fields.
                if (empty($static_fields)) {
                    UCG_DB::update_run_item(
                        $item_id,
                        array(
                            'status' => 'failed',
                            'error_message' => __('Не выбраны AI-поля для генерации.', 'unicontent-ai-generator'),
                            'generated_fields_json' => wp_json_encode($generated_fields, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                        )
                    );
                    return new WP_Error('ucg_no_ai_fields', __('Нет AI-полей для генерации.', 'unicontent-ai-generator'));
                }
            }

            $credits_spent_total = 0.0;
            $credits_remaining = 0.0;

            if (!empty($enabled_text_field_map)) {
                $json_prompt = $this->build_multi_field_json_prompt($item_topic, $enabled_text_field_map);
                $combined_prompt = $json_prompt . "\n\n---\n\n" . implode("\n\n---\n\n", $combined_prompt_parts);

                $response = $api_client->generate_multi_field(
                    $json_prompt,
                    $system_prompt,
                    $field_specs,
                    $model,
                    $max_tokens,
                    $this->resolve_operation_type_for_scenario($scenario, 'text')
                );
                if (is_wp_error($response)) {
                    $next_status = ($attempts + 1) >= 3 ? 'failed' : 'queued';
                    if ($this->is_fatal_api_error($response)) {
                        $next_status = 'failed';
                    }
                    UCG_DB::update_run_item(
                        $item_id,
                        array(
                            'status' => $next_status,
                            'prompt' => $combined_prompt,
                            'error_message' => $response->get_error_message(),
                            'generated_fields_json' => wp_json_encode($generated_fields, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                        )
                    );
                    return $response;
                }

                $response_fields = $this->extract_multi_field_response_fields($response);
                if (empty($response_fields)) {
                    $fallback_response = $this->generate_multi_field_fallback_response(
                        $api_client,
                        $enabled_text_field_map,
                        $system_prompt,
                        $max_tokens,
                        $model,
                        $this->resolve_operation_type_for_scenario($scenario, 'text')
                    );
                    if (!is_wp_error($fallback_response) && is_array($fallback_response)) {
                        $response_fields = $this->extract_multi_field_response_fields($fallback_response);
                        if (!empty($response_fields)) {
                            $response = array_merge($response, $fallback_response);
                        }
                    }
                }
                if (empty($response_fields) || !is_array($response_fields)) {
                    $next_status = ($attempts + 1) >= 3 ? 'failed' : 'queued';
                    UCG_DB::update_run_item(
                        $item_id,
                        array(
                            'status' => $next_status,
                            'prompt' => $combined_prompt,
                            'error_message' => __('API вернул невалидный JSON для multi-field генерации.', 'unicontent-ai-generator'),
                            'generated_fields_json' => wp_json_encode($generated_fields, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                            'credits_spent' => isset($response['credits_spent']) ? (float) $response['credits_spent'] : 0.0,
                            'credits_remaining' => isset($response['credits_remaining']) ? (float) $response['credits_remaining'] : 0.0,
                        )
                    );
                    return new WP_Error('ucg_invalid_multi_field_response', __('Невалидный ответ multi-field.', 'unicontent-ai-generator'));
                }

                foreach ($enabled_text_field_map as $field_key => $field_meta) {
                    $raw_value = array_key_exists($field_key, $response_fields) ? $response_fields[$field_key] : '';
                    if (is_array($raw_value) || is_object($raw_value)) {
                        $generated_value = wp_json_encode($raw_value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                    } else {
                        $generated_value = (string) $raw_value;
                    }
                    $generated_value = trim((string) $generated_value);

                    $field_target = isset($field_meta['target_field']) ? (string) $field_meta['target_field'] : '';
                    $field_max_chars = isset($field_meta['max_chars']) ? max(0, (int) $field_meta['max_chars']) : 0;
                    if ($field_target === 'seo_field:title' || $field_target === 'seo_field:description') {
                        $generated_value = $this->normalize_single_line_result($generated_value);
                        if ($field_max_chars > 0) {
                            $generated_value = $this->soft_trim_to_chars($generated_value, $field_max_chars);
                        }
                    } elseif ($field_max_chars > 0) {
                        $generated_value = $this->soft_trim_to_chars($generated_value, $field_max_chars);
                    }

                    $generated_values_by_key[$field_key] = $generated_value;

                    if (trim($generated_value) === '') {
                        $next_status = ($attempts + 1) >= 3 ? 'failed' : 'queued';
                        UCG_DB::update_run_item(
                            $item_id,
                            array(
                                'status' => $next_status,
                                'prompt' => $combined_prompt,
                                'error_message' => sprintf(
                                    __('Пустой результат для поля "%s".', 'unicontent-ai-generator'),
                                    isset($field_meta['label']) ? (string) $field_meta['label'] : (string) $field_key
                                ),
                                'generated_fields_json' => wp_json_encode($generated_fields, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                                'credits_spent' => isset($response['credits_spent']) ? (float) $response['credits_spent'] : 0.0,
                                'credits_remaining' => isset($response['credits_remaining']) ? (float) $response['credits_remaining'] : 0.0,
                            )
                        );
                        return new WP_Error('ucg_empty_result', __('Пустой ответ от API.', 'unicontent-ai-generator'));
                    }

                    $generated_fields['ai_fields'][] = array(
                        'key' => $field_key,
                        'label' => isset($field_meta['label']) ? (string) $field_meta['label'] : $field_key,
                        'target_field' => $field_target,
                        'status' => 'generated',
                        'prompt_template' => isset($field_meta['prompt_template']) ? (string) $field_meta['prompt_template'] : '',
                        'prompt' => isset($field_meta['prompt']) ? (string) $field_meta['prompt'] : '',
                        'generated_text' => $generated_value,
                        'length_option_id' => isset($field_meta['length_option_id']) ? (int) $field_meta['length_option_id'] : 0,
                        'max_chars' => $field_max_chars,
                        'output_type' => 'text',
                        'model' => isset($field_meta['model']) ? (string) $field_meta['model'] : 'auto',
                        'images_count' => 1,
                        'aspect_ratio' => '',
                        'image_size' => '',
                        'generated_media' => array(),
                        'sort_index' => isset($field_meta['sort_index']) ? (int) $field_meta['sort_index'] : 0,
                        'credits_spent' => 0.0,
                        'credits_remaining' => isset($response['credits_remaining']) ? (float) $response['credits_remaining'] : 0.0,
                    );
                }

                $credits_spent_total = isset($response['credits_spent']) ? (float) $response['credits_spent'] : 0.0;
                $credits_remaining = isset($response['credits_remaining']) ? (float) $response['credits_remaining'] : 0.0;
            }

            if (!empty($enabled_image_field_map)) {
                foreach ($enabled_image_field_map as $field_key => $field_meta) {
                    if (!is_array($field_meta)) {
                        continue;
                    }

                    $field_prompt = isset($field_meta['prompt']) ? (string) $field_meta['prompt'] : '';
                    $field_prompt = $this->inject_generated_field_tokens($field_prompt, $generated_values_by_key);
                    if (trim($field_prompt) === '') {
                        UCG_DB::update_run_item(
                            $item_id,
                            array(
                                'status' => 'failed',
                                'error_message' => __('Промпт поля пустой после подстановки переменных.', 'unicontent-ai-generator'),
                                'generated_fields_json' => wp_json_encode($generated_fields, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                            )
                        );
                        return new WP_Error('ucg_prompt_empty', __('Промпт пустой.', 'unicontent-ai-generator'));
                    }

                    $field_model = isset($field_meta['model']) ? $this->normalize_model_identifier((string) $field_meta['model']) : '';
                    if ($field_model === '') {
                        $field_model = $model;
                    }
                    if ($field_model === '') {
                        $field_model = 'auto';
                    }
                    $field_images_count = isset($field_meta['images_count']) ? max(1, min(8, (int) $field_meta['images_count'])) : 1;
                    $field_aspect_ratio = isset($field_meta['aspect_ratio']) ? sanitize_text_field((string) $field_meta['aspect_ratio']) : '';
                    $field_image_size = isset($field_meta['image_size']) ? strtoupper(sanitize_text_field((string) $field_meta['image_size'])) : '';

                    $response = $api_client->generate_image(
                        $field_prompt,
                        $system_prompt,
                        $field_model,
                        $field_images_count,
                        $field_aspect_ratio,
                        $field_image_size,
                        'image'
                    );
                    if (is_wp_error($response)) {
                        if ($this->should_skip_image_field_error($scenario, $response)) {
                            $generated_fields['ai_fields'][] = array(
                                'key' => $field_key,
                                'label' => isset($field_meta['label']) ? (string) $field_meta['label'] : $field_key,
                                'target_field' => isset($field_meta['target_field']) ? (string) $field_meta['target_field'] : '',
                                'status' => 'skipped',
                                'reason' => 'image_service_unavailable',
                                'error_message' => $response->get_error_message(),
                                'prompt_template' => isset($field_meta['prompt_template']) ? (string) $field_meta['prompt_template'] : '',
                                'prompt' => $field_prompt,
                                'generated_text' => '',
                                'length_option_id' => isset($field_meta['length_option_id']) ? (int) $field_meta['length_option_id'] : 0,
                                'max_chars' => isset($field_meta['max_chars']) ? max(0, (int) $field_meta['max_chars']) : 0,
                                'output_type' => 'image',
                                'model' => $field_model,
                                'images_count' => $field_images_count,
                                'aspect_ratio' => $field_aspect_ratio,
                                'image_size' => $field_image_size,
                                'generated_media' => array(),
                                'sort_index' => isset($field_meta['sort_index']) ? (int) $field_meta['sort_index'] : 0,
                                'credits_spent' => 0.0,
                                'credits_remaining' => $credits_remaining,
                            );
                            continue;
                        }
                        $next_status = ($attempts + 1) >= 3 ? 'failed' : 'queued';
                        if ($this->is_fatal_api_error($response)) {
                            $next_status = 'failed';
                        }
                        UCG_DB::update_run_item(
                            $item_id,
                            array(
                                'status' => $next_status,
                                'prompt' => implode("\n\n---\n\n", $combined_prompt_parts),
                                'error_message' => $response->get_error_message(),
                                'generated_fields_json' => wp_json_encode($generated_fields, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                            )
                        );
                        return $response;
                    }

                    $generated_media = $this->extract_image_sources_from_api_response($response);
                    if (empty($generated_media)) {
                        if (sanitize_key((string) $scenario) !== 'image_generation') {
                            $generated_fields['ai_fields'][] = array(
                                'key' => $field_key,
                                'label' => isset($field_meta['label']) ? (string) $field_meta['label'] : $field_key,
                                'target_field' => isset($field_meta['target_field']) ? (string) $field_meta['target_field'] : '',
                                'status' => 'skipped',
                                'reason' => 'image_empty_result',
                                'error_message' => __('API вернул пустой результат.', 'unicontent-ai-generator'),
                                'prompt_template' => isset($field_meta['prompt_template']) ? (string) $field_meta['prompt_template'] : '',
                                'prompt' => $field_prompt,
                                'generated_text' => '',
                                'length_option_id' => isset($field_meta['length_option_id']) ? (int) $field_meta['length_option_id'] : 0,
                                'max_chars' => isset($field_meta['max_chars']) ? max(0, (int) $field_meta['max_chars']) : 0,
                                'output_type' => 'image',
                                'model' => $field_model,
                                'images_count' => $field_images_count,
                                'aspect_ratio' => $field_aspect_ratio,
                                'image_size' => $field_image_size,
                                'generated_media' => array(),
                                'sort_index' => isset($field_meta['sort_index']) ? (int) $field_meta['sort_index'] : 0,
                                'credits_spent' => isset($response['credits_spent']) ? (float) $response['credits_spent'] : 0.0,
                                'credits_remaining' => isset($response['credits_remaining']) ? (float) $response['credits_remaining'] : $credits_remaining,
                            );
                            continue;
                        }
                        $next_status = ($attempts + 1) >= 3 ? 'failed' : 'queued';
                        UCG_DB::update_run_item(
                            $item_id,
                            array(
                                'status' => $next_status,
                                'prompt' => implode("\n\n---\n\n", $combined_prompt_parts),
                                'error_message' => __('API вернул пустой результат.', 'unicontent-ai-generator'),
                                'generated_fields_json' => wp_json_encode($generated_fields, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                                'credits_spent' => isset($response['credits_spent']) ? (float) $response['credits_spent'] : 0.0,
                                'credits_remaining' => isset($response['credits_remaining']) ? (float) $response['credits_remaining'] : 0.0,
                            )
                        );
                        return new WP_Error('ucg_empty_result', __('Пустой ответ от API.', 'unicontent-ai-generator'));
                    }

                    $generated_value = wp_json_encode($generated_media, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                    $generated_values_by_key[$field_key] = (string) $generated_media[0];

                    $field_credits_spent = isset($response['credits_spent']) ? (float) $response['credits_spent'] : 0.0;
                    $credits_spent_total += $field_credits_spent;
                    $credits_remaining = isset($response['credits_remaining']) ? (float) $response['credits_remaining'] : $credits_remaining;

                    $generated_fields['ai_fields'][] = array(
                        'key' => $field_key,
                        'label' => isset($field_meta['label']) ? (string) $field_meta['label'] : $field_key,
                        'target_field' => isset($field_meta['target_field']) ? (string) $field_meta['target_field'] : '',
                        'status' => 'generated',
                        'prompt_template' => isset($field_meta['prompt_template']) ? (string) $field_meta['prompt_template'] : '',
                        'prompt' => $field_prompt,
                        'generated_text' => $generated_value,
                        'length_option_id' => isset($field_meta['length_option_id']) ? (int) $field_meta['length_option_id'] : 0,
                        'max_chars' => isset($field_meta['max_chars']) ? max(0, (int) $field_meta['max_chars']) : 0,
                        'output_type' => 'image',
                        'model' => $field_model,
                        'images_count' => $field_images_count,
                        'aspect_ratio' => $field_aspect_ratio,
                        'image_size' => $field_image_size,
                        'generated_media' => $generated_media,
                        'sort_index' => isset($field_meta['sort_index']) ? (int) $field_meta['sort_index'] : 0,
                        'credits_spent' => $field_credits_spent,
                        'credits_remaining' => isset($response['credits_remaining']) ? (float) $response['credits_remaining'] : 0.0,
                    );
                }
            }

            foreach ($static_fields as $field) {
                if (!is_array($field)) {
                    continue;
                }
                $field_key = isset($field['key']) ? sanitize_key((string) $field['key']) : '';
                if ($field_key === '') {
                    $field_key = 'static_' . (count($generated_fields['static_fields']) + 1);
                }
                $field_label = isset($field['label']) ? sanitize_text_field((string) $field['label']) : $field_key;
                $field_enabled = !array_key_exists('enabled', $field) || !empty($field['enabled']);
                $field_target = $this->normalize_generation_target_field(
                    isset($field['target_field']) ? (string) $field['target_field'] : ''
                );
                if ($field_target === '') {
                    continue;
                }
                if (!$field_enabled) {
                    $generated_fields['static_fields'][] = array(
                        'key' => $field_key,
                        'label' => $field_label,
                        'target_field' => $field_target,
                        'status' => 'skipped',
                        'reason' => 'disabled',
                        'value' => isset($field['value']) ? $field['value'] : '',
                        'sort_index' => count($generated_fields['static_fields']),
                    );
                    continue;
                }

                $generated_fields['static_fields'][] = array(
                    'key' => $field_key,
                    'label' => $field_label,
                    'target_field' => $field_target,
                    'status' => 'generated',
                    'value' => isset($field['value']) ? $field['value'] : '',
                    'sort_index' => count($generated_fields['static_fields']),
                );
            }

            if (!empty($generated_fields['ai_fields']) && is_array($generated_fields['ai_fields'])) {
                usort($generated_fields['ai_fields'], function ($left, $right) {
                    $left_index = is_array($left) && isset($left['sort_index']) ? (int) $left['sort_index'] : 0;
                    $right_index = is_array($right) && isset($right['sort_index']) ? (int) $right['sort_index'] : 0;
                    if ($left_index === $right_index) {
                        return 0;
                    }
                    return $left_index < $right_index ? -1 : 1;
                });
                foreach ($generated_fields['ai_fields'] as $field_row_index => $field_row) {
                    if (!is_array($field_row)) {
                        continue;
                    }
                    unset($generated_fields['ai_fields'][$field_row_index]['sort_index']);
                }
            }

            if (!empty($generated_fields['static_fields']) && is_array($generated_fields['static_fields'])) {
                foreach ($generated_fields['static_fields'] as $field_row_index => $field_row) {
                    if (!is_array($field_row)) {
                        continue;
                    }
                    unset($generated_fields['static_fields'][$field_row_index]['sort_index']);
                }
            }

            $combined_prompt = implode("\n\n---\n\n", $combined_prompt_parts);
            if (!empty($enabled_text_field_map)) {
                $json_prompt = $this->build_multi_field_json_prompt($item_topic, $enabled_text_field_map);
                if (trim($json_prompt) !== '') {
                    $combined_prompt = $json_prompt . ($combined_prompt !== '' ? "\n\n---\n\n" . $combined_prompt : '');
                }
            }
            $generated_text = $this->build_legacy_generated_text_from_fields_payload($generated_fields, $scenario);
            $generated_fields_json = wp_json_encode($generated_fields, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

            $update_data = array(
                'status' => 'generated',
                'prompt' => $combined_prompt,
                'generated_text' => $generated_text,
                'generated_fields_json' => $generated_fields_json,
                'error_message' => '',
                'credits_spent' => $credits_spent_total,
                'credits_remaining' => $credits_remaining,
                'generated_at' => current_time('mysql', true),
            );

            if ($generation_mode === 'publish') {
                $item_target_field = isset($item['target_field']) ? (string) $item['target_field'] : '';
                $write_result = $this->write_generated_fields_for_item($post_id, $item_target_field, $generated_fields, $write_context);
                if (is_wp_error($write_result)) {
                    UCG_DB::update_run_item(
                        $item_id,
                        array(
                            'status' => 'failed',
                            'prompt' => $combined_prompt,
                            'generated_text' => $generated_text,
                            'generated_fields_json' => $generated_fields_json,
                            'error_message' => $write_result->get_error_message(),
                            'credits_spent' => $credits_spent_total,
                            'credits_remaining' => $credits_remaining,
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

        protected function extract_multi_field_response_fields($response) {
            if (!is_array($response)) {
                return array();
            }

            $fields = isset($response['fields']) && is_array($response['fields']) ? $response['fields'] : array();
            if (!empty($fields)) {
                return $fields;
            }

            $raw_text = isset($response['text']) ? (string) $response['text'] : '';
            if ($raw_text === '') {
                return array();
            }

            $decoded = json_decode($raw_text, true);
            if (is_array($decoded) && !empty($decoded)) {
                if (isset($decoded['fields']) && is_array($decoded['fields']) && !empty($decoded['fields'])) {
                    return $decoded['fields'];
                }
                return $decoded;
            }

            $decoded = $this->extract_json_object_from_text($raw_text);
            if (!empty($decoded)) {
                if (isset($decoded['fields']) && is_array($decoded['fields']) && !empty($decoded['fields'])) {
                    return $decoded['fields'];
                }
                return $decoded;
            }

            return array();
        }

        protected function generate_multi_field_fallback_response(
            UCG_Api_Client $api_client,
            $enabled_ai_field_map,
            $system_prompt,
            $max_tokens,
            $model,
            $operation_type = 'text'
        ) {
            $enabled_ai_field_map = is_array($enabled_ai_field_map) ? $enabled_ai_field_map : array();
            $system_prompt = (string) $system_prompt;
            $max_tokens = max(1, (int) $max_tokens);
            $model = $this->normalize_model_identifier((string) $model);
            if ($model === '') {
                $model = 'auto';
            }

            if (empty($enabled_ai_field_map)) {
                return new WP_Error('ucg_multi_field_fallback_empty', __('Нет полей для fallback-генерации.', 'unicontent-ai-generator'));
            }

            $fields = array();
            $credits_spent = 0.0;
            $credits_remaining = 0.0;

            foreach ($enabled_ai_field_map as $field_key => $field_meta) {
                if (!is_array($field_meta)) {
                    continue;
                }
                $field_prompt = isset($field_meta['prompt']) ? trim((string) $field_meta['prompt']) : '';
                if ($field_prompt === '') {
                    continue;
                }
                $field_length_option_id = isset($field_meta['length_option_id']) ? max(0, (int) $field_meta['length_option_id']) : 0;

                $response = $api_client->generate_text(
                    $field_prompt,
                    $system_prompt,
                    $max_tokens,
                    $field_length_option_id,
                    false,
                    $model,
                    $operation_type
                );
                if (is_wp_error($response)) {
                    return $response;
                }

                $value = isset($response['text']) ? trim((string) $response['text']) : '';
                if ($value === '') {
                    return new WP_Error(
                        'ucg_multi_field_fallback_empty_value',
                        sprintf(
                            __('Пустой результат fallback-генерации для поля "%s".', 'unicontent-ai-generator'),
                            isset($field_meta['label']) ? (string) $field_meta['label'] : (string) $field_key
                        )
                    );
                }

                $fields[(string) $field_key] = $value;
                $credits_spent += isset($response['credits_spent']) ? (float) $response['credits_spent'] : 0.0;
                if (isset($response['credits_remaining'])) {
                    $credits_remaining = (float) $response['credits_remaining'];
                }
            }

            if (empty($fields)) {
                return new WP_Error('ucg_multi_field_fallback_empty', __('Fallback-генерация не вернула ни одного поля.', 'unicontent-ai-generator'));
            }

            return array(
                'fields' => $fields,
                'text' => wp_json_encode($fields, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'credits_spent' => $credits_spent,
                'credits_remaining' => $credits_remaining,
            );
        }

        protected function extract_json_object_from_text($raw_text) {
            $text = trim((string) $raw_text);
            if ($text === '') {
                return array();
            }

            $decoded = $this->decode_json_object_candidate($text);
            if (!empty($decoded)) {
                return $decoded;
            }

            if (preg_match('/```(?:json)?\s*({[\s\S]*?})\s*```/iu', $text, $match) && !empty($match[1])) {
                $decoded = $this->decode_json_object_candidate((string) $match[1]);
                if (!empty($decoded)) {
                    return $decoded;
                }
            }

            $first_brace = strpos($text, '{');
            $last_brace = strrpos($text, '}');
            if ($first_brace !== false && $last_brace !== false && $last_brace > $first_brace) {
                $candidate = substr($text, (int) $first_brace, (int) ($last_brace - $first_brace + 1));
                $decoded = $this->decode_json_object_candidate($candidate);
                if (!empty($decoded)) {
                    return $decoded;
                }
            }

            return array();
        }

        protected function decode_json_object_candidate($candidate) {
            $candidate = trim((string) $candidate);
            if ($candidate === '') {
                return array();
            }
            $decoded = json_decode($candidate, true);
            if (!is_array($decoded) || empty($decoded)) {
                return array();
            }
            if (!$this->is_associative_array($decoded)) {
                return array();
            }
            return $decoded;
        }

        protected function is_associative_array($value) {
            if (!is_array($value)) {
                return false;
            }
            return array_keys($value) !== range(0, count($value) - 1);
        }

        protected function resolve_create_item_topic_for_item($post_id, $options, $item_index) {
            $post_id = (int) $post_id;
            $item_index = max(1, (int) $item_index);
            $options = is_array($options) ? $options : array();

            $post = get_post($post_id);
            if ($post instanceof WP_Post) {
                $title = trim((string) $post->post_title);
                if ($title !== '') {
                    return $title;
                }
            }

            $topics = isset($options['create_topics']) ? $options['create_topics'] : array();
            if (is_string($topics)) {
                $topics = preg_split('/\r\n|\r|\n/u', $topics);
            }
            if (is_array($topics)) {
                $position = $item_index - 1;
                if (isset($topics[$position])) {
                    $topic = sanitize_text_field((string) $topics[$position]);
                    if ($topic !== '') {
                        return $topic;
                    }
                }
            }

            return sprintf(__('Элемент #%d', 'unicontent-ai-generator'), $item_index);
        }

        protected function resolve_create_item_context_for_item($post_id, $item_topic) {
            $item_topic = trim((string) $item_topic);
            return $item_topic;
        }

        protected function inject_create_runtime_tokens($text, $item_topic, $item_context) {
            $text = (string) $text;
            $item_topic = (string) $item_topic;
            $item_context = (string) $item_context;
            $replacements = array(
                '{{item}}' => $item_topic,
                '{item}' => $item_topic,
                '{{context}}' => $item_context,
                '{context}' => $item_context,
            );
            $text = strtr($text, $replacements);
            $text = preg_replace_callback('/\{\{\s*item\s*\}\}|\{\s*item\s*\}/iu', function () use ($item_topic) {
                return $item_topic;
            }, (string) $text);
            $text = preg_replace_callback('/\{\{\s*context\s*\}\}|\{\s*context\s*\}/iu', function () use ($item_context) {
                return $item_context;
            }, (string) $text);
            return is_string($text) ? $text : '';
        }

        protected function prepend_create_topic_context_to_prompt($prompt, $item_topic, $item_context) {
            $prompt = trim((string) $prompt);
            $item_topic = trim((string) $item_topic);
            $item_context = trim((string) $item_context);

            $parts = array();
            if ($item_topic !== '') {
                $parts[] = __('Тема элемента:', 'unicontent-ai-generator') . ' ' . $item_topic;
            }
            if ($item_context !== '' && $item_context !== $item_topic) {
                $parts[] = __('Контекст элемента:', 'unicontent-ai-generator') . ' ' . $item_context;
            }
            if ($prompt !== '') {
                $parts[] = $prompt;
            }

            return implode("\n\n", $parts);
        }

        protected function multi_field_hint_for_target($field_key, $target_field) {
            $field_key = sanitize_key((string) $field_key);
            $target_field = $this->normalize_generation_target_field($target_field);
            if ($target_field === 'post:post_title') {
                return __('Заголовок, одна строка без markdown.', 'unicontent-ai-generator');
            }
            if ($target_field === 'post:post_content') {
                return __('Основной текст, связный и подробный.', 'unicontent-ai-generator');
            }
            if ($target_field === 'post:post_excerpt') {
                return __('Краткая выжимка из основного текста.', 'unicontent-ai-generator');
            }
            if ($target_field === 'seo_field:title') {
                return __('SEO title, одна строка.', 'unicontent-ai-generator');
            }
            if ($target_field === 'seo_field:description') {
                return __('SEO description, одна строка.', 'unicontent-ai-generator');
            }
            if ($field_key === 'post_title') {
                return __('Заголовок, одна строка.', 'unicontent-ai-generator');
            }
            if ($field_key === 'post_content') {
                return __('Основной текст.', 'unicontent-ai-generator');
            }
            if ($field_key === 'post_excerpt') {
                return __('Краткое описание.', 'unicontent-ai-generator');
            }
            return __('Верни строковое значение для этого поля.', 'unicontent-ai-generator');
        }

        protected function build_multi_field_json_prompt($item_topic, $enabled_ai_field_map) {
            $item_topic = trim((string) $item_topic);
            $enabled_ai_field_map = is_array($enabled_ai_field_map) ? $enabled_ai_field_map : array();
            $parts = array();
            $parts[] = __('Тема элемента:', 'unicontent-ai-generator') . ' ' . $item_topic;
            $parts[] = __('Сгенерируй значения для всех полей и верни JSON с соответствующими ключами.', 'unicontent-ai-generator');
            $parts[] = __('Все поля должны относиться к одной и той же теме элемента. Не смешивай разные товары/темы.', 'unicontent-ai-generator');
            $parts[] = __('Критично: в каждом поле используй именно эту тему. Если тема не подходит, переформулируй, но не подменяй объект.', 'unicontent-ai-generator');
            $parts[] = __('Верни только JSON-объект без markdown и пояснений.', 'unicontent-ai-generator');
            foreach ($enabled_ai_field_map as $field_key => $field_meta) {
                if (!is_array($field_meta)) {
                    continue;
                }
                $label = isset($field_meta['label']) ? trim((string) $field_meta['label']) : $field_key;
                $field_prompt = isset($field_meta['prompt']) ? trim((string) $field_meta['prompt']) : '';
                $field_max_chars = isset($field_meta['max_chars']) ? max(0, (int) $field_meta['max_chars']) : 0;
                $header = '[' . (string) $field_key . '] ' . $label;
                if ($field_max_chars > 0) {
                    $header .= ' (' . sprintf(__('макс. %d символов', 'unicontent-ai-generator'), $field_max_chars) . ')';
                }
                if ($field_prompt !== '') {
                    $parts[] = $header . "\n" . $field_prompt;
                } else {
                    $parts[] = $header;
                }
            }
            return implode("\n\n", $parts);
        }

        protected function resolve_operation_type_for_scenario($scenario, $fallback = 'text') {
            $scenario = sanitize_key((string) $scenario);
            if ($scenario === 'seo_tags') {
                return 'seo_tags';
            }

            $fallback = sanitize_key((string) $fallback);
            if (!in_array($fallback, array('text', 'seo_tags', 'long_text', 'image'), true)) {
                $fallback = 'text';
            }
            return $fallback;
        }

        protected function normalize_generation_target_field($target_field) {
            $target_field = (string) $target_field;
            $target_field = preg_replace('/[^a-zA-Z0-9:_-]/', '', $target_field);
            return (string) $target_field;
        }

        protected function remap_legacy_product_static_field($scenario, $field_key, $target_field) {
            $scenario = sanitize_key((string) $scenario);
            $field_key = sanitize_key((string) $field_key);
            $target_field = $this->normalize_generation_target_field((string) $target_field);
            if ($scenario !== 'product_fields') {
                return array(
                    'key' => $field_key,
                    'target_field' => $target_field,
                );
            }

            if ($field_key === 'post_category') {
                $field_key = 'product_category';
            } elseif ($field_key === 'post_tag') {
                $field_key = 'product_tag';
            }

            if ($target_field === 'tax:category') {
                $target_field = 'tax:product_cat';
            } elseif ($target_field === 'tax:post_tag') {
                $target_field = 'tax:product_tag';
            }

            return array(
                'key' => $field_key,
                'target_field' => $target_field,
            );
        }

        protected function resolve_field_target_by_key($key) {
            $key = sanitize_key((string) $key);
            if ($key === '') {
                return '';
            }
            $map = array(
                'post_title' => 'post:post_title',
                'post_content' => 'post:post_content',
                'post_excerpt' => 'post:post_excerpt',
                'seo_title' => 'seo_field:title',
                'seo_description' => 'seo_field:description',
                'post_status' => 'post:post_status',
                'post_author' => 'post:post_author',
                'post_date' => 'post:post_date',
                'post_category' => 'tax:category',
                'post_tag' => 'tax:post_tag',
                'product_category' => 'tax:product_cat',
                'product_tag' => 'tax:product_tag',
                'stock_status' => 'meta:_stock_status',
                'stock_quantity' => 'meta:_stock',
                'catalog_visibility' => 'meta:_visibility',
                'featured_image' => 'media:featured',
                'product_images' => 'media:product_images',
                'product_gallery' => 'media:gallery',
            );
            if (isset($map[$key])) {
                return (string) $map[$key];
            }
            if (strpos($key, 'post_') === 0) {
                return 'post:' . $key;
            }
            if (strpos($key, 'meta_') === 0) {
                return 'meta:' . substr($key, 5);
            }
            if (strpos($key, 'tax_') === 0) {
                return 'tax:' . substr($key, 4);
            }
            return '';
        }

        protected function normalize_ai_fields_for_item($scenario, $options, $template_body, $seo_title_prompt_template, $seo_description_prompt_template, $length_option_id, $default_target_field) {
            $scenario = sanitize_key((string) $scenario);
            $options = is_array($options) ? $options : array();
            $template_body = (string) $template_body;
            $seo_title_prompt_template = (string) $seo_title_prompt_template;
            $seo_description_prompt_template = (string) $seo_description_prompt_template;
            $length_option_id = (int) $length_option_id;
            $default_target_field = $this->normalize_generation_target_field($default_target_field);

            $raw_fields = isset($options['ai_fields']) && is_array($options['ai_fields']) ? $options['ai_fields'] : array();
            $normalized = array();

            foreach ($raw_fields as $index => $raw_field) {
                if (!is_array($raw_field)) {
                    continue;
                }
                $type = sanitize_key(isset($raw_field['type']) ? (string) $raw_field['type'] : 'ai');
                if ($type !== '' && $type !== 'ai') {
                    continue;
                }
                $key = sanitize_key(isset($raw_field['key']) ? (string) $raw_field['key'] : ('field_' . ((int) $index + 1)));
                if ($key === '') {
                    $key = 'field_' . ((int) $index + 1);
                }
                $prompt_template = sanitize_textarea_field(
                    isset($raw_field['prompt']) ? (string) $raw_field['prompt'] : (isset($raw_field['prompt_template']) ? (string) $raw_field['prompt_template'] : '')
                );
                $field_target = $this->normalize_generation_target_field(
                    isset($raw_field['target_field']) ? (string) $raw_field['target_field'] : ''
                );
                if ($field_target === '') {
                    $field_target = $this->resolve_field_target_by_key($key);
                }
                if ($field_target === '') {
                    $field_target = $default_target_field;
                }
                $output_type = sanitize_key(isset($raw_field['output_type']) ? (string) $raw_field['output_type'] : '');
                if ($output_type === '') {
                    $output_type = strpos($field_target, 'media:') === 0 ? 'image' : 'text';
                }
                if ($output_type !== 'image') {
                    $output_type = 'text';
                }
                $field_model = $this->normalize_model_identifier(isset($raw_field['model']) ? (string) $raw_field['model'] : 'auto');
                if ($field_model === '') {
                    $field_model = 'auto';
                }
                $images_count = isset($raw_field['images_count']) ? max(1, min(8, (int) $raw_field['images_count'])) : 1;
                $aspect_ratio = isset($raw_field['aspect_ratio']) ? sanitize_text_field((string) $raw_field['aspect_ratio']) : '';
                if (!in_array($aspect_ratio, array('1:1', '2:3', '3:2', '3:4', '4:3', '4:5', '5:4', '9:16', '16:9', '21:9', '1:4', '4:1', '1:8', '8:1'), true)) {
                    $aspect_ratio = '';
                }
                $image_size = isset($raw_field['image_size']) ? strtoupper(sanitize_text_field((string) $raw_field['image_size'])) : '';
                if (!in_array($image_size, array('0.5K', '1K', '2K', '4K'), true)) {
                    $image_size = '';
                }
                $normalized[] = array(
                    'key' => $key,
                    'label' => sanitize_text_field(isset($raw_field['label']) ? (string) $raw_field['label'] : $key),
                    'enabled' => !array_key_exists('enabled', $raw_field) || !empty($raw_field['enabled']),
                    'prompt' => $prompt_template,
                    'target_field' => $field_target,
                    'length_option_id' => isset($raw_field['length_option_id']) ? max(0, (int) $raw_field['length_option_id']) : max(0, $length_option_id),
                    'max_chars' => isset($raw_field['max_chars']) ? max(0, (int) $raw_field['max_chars']) : 0,
                    'output_type' => $output_type,
                    'model' => $field_model,
                    'images_count' => $images_count,
                    'aspect_ratio' => $aspect_ratio,
                    'image_size' => $image_size,
                );
            }

            if (!empty($normalized)) {
                return $normalized;
            }

            if ($scenario === 'seo_tags') {
                if ($seo_title_prompt_template === '' && $seo_description_prompt_template === '' && $template_body !== '') {
                    $seo_title_prompt_template = $template_body;
                    $seo_description_prompt_template = $template_body;
                }
                return array(
                    array(
                        'key' => 'seo_title',
                        'label' => 'SEO title',
                        'enabled' => true,
                        'prompt' => $seo_title_prompt_template,
                        'target_field' => 'seo_field:title',
                        'length_option_id' => max(0, $length_option_id),
                        'max_chars' => 70,
                        'output_type' => 'text',
                        'model' => 'auto',
                        'images_count' => 1,
                        'aspect_ratio' => '',
                        'image_size' => '',
                    ),
                    array(
                        'key' => 'seo_description',
                        'label' => 'SEO description',
                        'enabled' => true,
                        'prompt' => $seo_description_prompt_template,
                        'target_field' => 'seo_field:description',
                        'length_option_id' => max(0, $length_option_id),
                        'max_chars' => 160,
                        'output_type' => 'text',
                        'model' => 'auto',
                        'images_count' => 1,
                        'aspect_ratio' => '',
                        'image_size' => '',
                    ),
                );
            }

            if ($template_body !== '') {
                return array(
                    array(
                        'key' => 'main',
                        'label' => 'Main',
                        'enabled' => true,
                        'prompt' => $template_body,
                        'target_field' => $default_target_field,
                        'length_option_id' => max(0, $length_option_id),
                        'max_chars' => 0,
                        'output_type' => strpos($default_target_field, 'media:') === 0 ? 'image' : 'text',
                        'model' => 'auto',
                        'images_count' => 1,
                        'aspect_ratio' => '',
                        'image_size' => '',
                    ),
                );
            }

            return array();
        }

        protected function normalize_static_fields_for_item($options, $scenario = '') {
            $options = is_array($options) ? $options : array();
            $scenario = sanitize_key((string) $scenario);
            $raw_fields = isset($options['static_fields']) && is_array($options['static_fields']) ? $options['static_fields'] : array();
            $normalized = array();
            foreach ($raw_fields as $index => $raw_field) {
                if (!is_array($raw_field)) {
                    continue;
                }
                $key = sanitize_key(isset($raw_field['key']) ? (string) $raw_field['key'] : ('static_' . ((int) $index + 1)));
                if ($key === '') {
                    $key = 'static_' . ((int) $index + 1);
                }
                $target_field = $this->normalize_generation_target_field(
                    isset($raw_field['target_field']) ? (string) $raw_field['target_field'] : ''
                );
                if ($target_field === '') {
                    $target_field = $this->resolve_field_target_by_key($key);
                }
                $legacy_product_field = $this->remap_legacy_product_static_field($scenario, $key, $target_field);
                $key = isset($legacy_product_field['key']) ? sanitize_key((string) $legacy_product_field['key']) : $key;
                if ($key === '') {
                    $key = 'static_' . ((int) $index + 1);
                }
                $target_field = isset($legacy_product_field['target_field'])
                    ? $this->normalize_generation_target_field((string) $legacy_product_field['target_field'])
                    : $target_field;
                if ($target_field === '') {
                    continue;
                }
                $normalized[] = array(
                    'key' => $key,
                    'label' => sanitize_text_field(isset($raw_field['label']) ? (string) $raw_field['label'] : $key),
                    'enabled' => !array_key_exists('enabled', $raw_field) || !empty($raw_field['enabled']),
                    'target_field' => $target_field,
                    'value' => array_key_exists('value', $raw_field) ? $raw_field['value'] : '',
                );
            }

            return $normalized;
        }

        protected function stringify_generated_static_value($value) {
            if (is_array($value) || is_object($value)) {
                return wp_json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            }
            if (is_bool($value)) {
                return $value ? '1' : '0';
            }
            if ($value === null) {
                return '';
            }
            return (string) $value;
        }

        protected function build_legacy_generated_text_from_fields_payload($generated_fields, $scenario) {
            $generated_fields = is_array($generated_fields) ? $generated_fields : array();
            $scenario = sanitize_key((string) $scenario);
            $ai_fields = isset($generated_fields['ai_fields']) && is_array($generated_fields['ai_fields']) ? $generated_fields['ai_fields'] : array();
            $static_fields = isset($generated_fields['static_fields']) && is_array($generated_fields['static_fields']) ? $generated_fields['static_fields'] : array();

            if ($scenario === 'seo_tags') {
                $seo_title = '';
                $seo_description = '';
                foreach ($ai_fields as $field) {
                    if (!is_array($field) || (isset($field['status']) && (string) $field['status'] !== 'generated')) {
                        continue;
                    }
                    $target_field = isset($field['target_field']) ? (string) $field['target_field'] : '';
                    $value = isset($field['generated_text']) ? (string) $field['generated_text'] : '';
                    if ($seo_title === '' && $target_field === 'seo_field:title') {
                        $seo_title = $value;
                    } elseif ($seo_description === '' && $target_field === 'seo_field:description') {
                        $seo_description = $value;
                    }
                }
                return $this->build_seo_payload($seo_title, $seo_description);
            }

            $generated_ai_values = array();
            foreach ($ai_fields as $field) {
                if (!is_array($field) || (isset($field['status']) && (string) $field['status'] !== 'generated')) {
                    continue;
                }
                $generated_ai_values[] = isset($field['generated_text']) ? (string) $field['generated_text'] : '';
            }
            if (count($generated_ai_values) === 1 && empty($static_fields)) {
                return (string) $generated_ai_values[0];
            }

            $payload = array();
            foreach ($ai_fields as $field) {
                if (!is_array($field) || (isset($field['status']) && (string) $field['status'] !== 'generated')) {
                    continue;
                }
                $key = isset($field['key']) ? sanitize_key((string) $field['key']) : '';
                if ($key === '') {
                    $key = 'ai_' . (count($payload) + 1);
                }
                $payload[$key] = isset($field['generated_text']) ? (string) $field['generated_text'] : '';
            }
            foreach ($static_fields as $field) {
                if (!is_array($field) || (isset($field['status']) && (string) $field['status'] !== 'generated')) {
                    continue;
                }
                $key = isset($field['key']) ? sanitize_key((string) $field['key']) : '';
                if ($key === '') {
                    $key = 'static_' . (count($payload) + 1);
                }
                $payload[$key] = array_key_exists('value', $field) ? $field['value'] : '';
            }
            return wp_json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }

        protected function write_generated_fields_for_item($post_id, $run_target_field, $generated_fields, $write_context = array()) {
            $post_id = (int) $post_id;
            $run_target_field = $this->normalize_generation_target_field($run_target_field);
            $generated_fields = is_array($generated_fields) ? $generated_fields : array();
            $write_context = is_array($write_context) ? $write_context : array();

            if ($post_id <= 0) {
                return new WP_Error('ucg_invalid_post', __('Некорректная запись.', 'unicontent-ai-generator'));
            }

            $ai_fields = isset($generated_fields['ai_fields']) && is_array($generated_fields['ai_fields']) ? $generated_fields['ai_fields'] : array();
            $static_fields = isset($generated_fields['static_fields']) && is_array($generated_fields['static_fields']) ? $generated_fields['static_fields'] : array();

            $seo_title = '';
            $seo_description = '';

            foreach ($ai_fields as $field) {
                if (!is_array($field) || (isset($field['status']) && (string) $field['status'] !== 'generated')) {
                    continue;
                }
                $target_field = $this->normalize_generation_target_field(isset($field['target_field']) ? (string) $field['target_field'] : '');
                $output_type = sanitize_key(isset($field['output_type']) ? (string) $field['output_type'] : '');
                if ($output_type === '') {
                    $output_type = strpos($target_field, 'media:') === 0 ? 'image' : 'text';
                }
                if ($output_type !== 'image') {
                    $output_type = 'text';
                }
                $value = isset($field['generated_text']) ? (string) $field['generated_text'] : '';
                if ($target_field === 'seo_field:title') {
                    $seo_title = $value;
                    continue;
                }
                if ($target_field === 'seo_field:description') {
                    $seo_description = $value;
                    continue;
                }
                if ($target_field === '') {
                    continue;
                }
                $value_for_write = $value;
                if ($output_type === 'image') {
                    $generated_media = isset($field['generated_media']) && is_array($field['generated_media']) ? $field['generated_media'] : array();
                    if (!empty($generated_media)) {
                        $value_for_write = $generated_media;
                    }
                }
                $write_result = UCG_Tokens::write_generated_value($post_id, $target_field, $value_for_write, $write_context);
                if (is_wp_error($write_result)) {
                    return $write_result;
                }
            }

            foreach ($static_fields as $field) {
                if (!is_array($field) || (isset($field['status']) && (string) $field['status'] !== 'generated')) {
                    continue;
                }
                $target_field = $this->normalize_generation_target_field(isset($field['target_field']) ? (string) $field['target_field'] : '');
                if ($target_field === '') {
                    continue;
                }
                $value = array_key_exists('value', $field) ? $field['value'] : '';
                if ($target_field === 'seo_field:title') {
                    $seo_title = $this->stringify_generated_static_value($value);
                    continue;
                }
                if ($target_field === 'seo_field:description') {
                    $seo_description = $this->stringify_generated_static_value($value);
                    continue;
                }
                $write_result = UCG_Tokens::write_generated_value(
                    $post_id,
                    $target_field,
                    $value,
                    $write_context
                );
                if (is_wp_error($write_result)) {
                    return $write_result;
                }
            }

            if ($seo_title !== '' || $seo_description !== '') {
                $seo_target_field = strpos($run_target_field, 'seo:') === 0 ? $run_target_field : 'seo:auto';
                $seo_payload = $this->build_seo_payload($seo_title, $seo_description);
                $write_result = UCG_Tokens::write_generated_value($post_id, $seo_target_field, $seo_payload, $write_context);
                if (is_wp_error($write_result)) {
                    return $write_result;
                }
            }

            return true;
        }

        protected function build_prompt_for_comment_and_review_scenarios($prompt, $scenario, $rating_min = 1, $rating_max = 5) {
            $prompt = (string) $prompt;
            $scenario = sanitize_key((string) $scenario);
            $rating_min = (int) $rating_min;
            $rating_max = (int) $rating_max;
            $rating_min = max(1, min(5, $rating_min));
            $rating_max = max(1, min(5, $rating_max));
            if ($rating_min > $rating_max) {
                $tmp = $rating_min;
                $rating_min = $rating_max;
                $rating_max = $tmp;
            }

            if ($scenario === 'comments') {
                $instruction_ru = __('Верни только ОДИН JSON-объект без markdown и текста вокруг: {"author_name":"...","content":"..."}. Не возвращай массивы, списки, несколько объектов или несколько комментариев.', 'unicontent-ai-generator');
                $instruction_en = 'Return ONLY ONE JSON object (not an array, not a list), no markdown, no extra text: {"author_name":"...","content":"..."}. Do NOT return multiple objects.';
                return $prompt . "\n\n" . $instruction_ru . "\n" . $instruction_en;
            }

            if ($scenario === 'woo_reviews') {
                $range_ru = sprintf(__('Рейтинг (rating) должен быть целым числом в диапазоне %d–%d.', 'unicontent-ai-generator'), $rating_min, $rating_max);
                $range_en = 'rating must be an integer in range ' . $rating_min . '..' . $rating_max . '.';
                $instruction_ru = __('Верни только ОДИН JSON-объект без markdown и текста вокруг: {"author_name":"...","content":"...","rating":5}. Не возвращай массивы, списки, несколько объектов или несколько отзывов.', 'unicontent-ai-generator')
                    . ' ' . $range_ru;
                $instruction_en = 'Return ONLY ONE JSON object (not an array, not a list), no markdown, no extra text: {"author_name":"...","content":"...","rating":5}. Do NOT return multiple objects. '
                    . $range_en;
                return $prompt . "\n\n" . $instruction_ru . "\n" . $instruction_en;
            }

            return $prompt;
        }

        protected function build_write_context($scenario, $publish_date_from, $publish_date_to) {
            $scenario = sanitize_key((string) $scenario);
            if ($scenario !== 'comments' && $scenario !== 'woo_reviews') {
                return array();
            }

            $date_from = $this->normalize_publish_date_value($publish_date_from);
            $date_to = $this->normalize_publish_date_value($publish_date_to);

            if ($date_from !== '' && $date_to === '') {
                $date_to = $date_from;
            } elseif ($date_to !== '' && $date_from === '') {
                $date_from = $date_to;
            }

            if ($date_from === '' || $date_to === '') {
                return array();
            }

            if (strcmp($date_from, $date_to) > 0) {
                $tmp = $date_from;
                $date_from = $date_to;
                $date_to = $tmp;
            }

            return array(
                'publish_date_from' => $date_from,
                'publish_date_to' => $date_to,
            );
        }

        protected function normalize_publish_date_value($value) {
            $value = trim((string) $value);
            if ($value === '') {
                return '';
            }
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
                return '';
            }
            return $value;
        }

        protected function build_prompt_for_single_seo_field($prompt, $field) {
            $prompt = (string) $prompt;
            $field = sanitize_key((string) $field);

            if ($field !== 'title' && $field !== 'description') {
                return $prompt;
            }

            if ($field === 'title') {
                $instruction_ru = __('Верни только готовый SEO title без кавычек, markdown и комментариев. Длина: 60–70 символов.', 'unicontent-ai-generator');
                $instruction_en = 'Return only the final SEO title (no quotes, no markdown, no comments). Length: 60-70 characters.';
                return $prompt . "\n\n" . $instruction_ru . "\n" . $instruction_en;
            }

            $instruction_ru = __('Верни только готовый SEO description без кавычек, markdown и комментариев. Длина: 140–160 символов.', 'unicontent-ai-generator');
            $instruction_en = 'Return only the final SEO description (no quotes, no markdown, no comments). Length: 140-160 characters.';

            return $prompt . "\n\n" . $instruction_ru . "\n" . $instruction_en;
        }

        // Public wrappers for wizard preview / admin-side helpers.
        public function build_effective_system_prompt_for_preview(
            $base_system_prompt,
            $scenario,
            $style_language,
            $style_tone,
            $style_uniqueness,
            $run_seed,
            $run_id,
            $post_id,
            $item_index
        ) {
            return $this->build_effective_system_prompt(
                $base_system_prompt,
                $scenario,
                $style_language,
                $style_tone,
                $style_uniqueness,
                $run_seed,
                $run_id,
                $post_id,
                $item_index
            );
        }

        public function build_prompt_for_single_seo_field_for_preview($prompt, $field) {
            return $this->build_prompt_for_single_seo_field($prompt, $field);
        }

        public function build_prompt_for_comment_and_review_scenarios_for_preview($prompt, $scenario, $rating_min = 1, $rating_max = 5) {
            return $this->build_prompt_for_comment_and_review_scenarios($prompt, $scenario, $rating_min, $rating_max);
        }

        protected function build_effective_system_prompt(
            $base_system_prompt,
            $scenario,
            $style_language,
            $style_tone,
            $style_uniqueness,
            $run_seed,
            $run_id,
            $post_id,
            $item_index
        ) {
            $base_system_prompt = trim((string) $base_system_prompt);
            $scenario = sanitize_key((string) $scenario);

            $style_language = sanitize_key((string) $style_language);
            if (!in_array($style_language, array('auto', 'ru', 'en'), true)) {
                $style_language = 'auto';
            }

            $style_tone = sanitize_key((string) $style_tone);
            if (!in_array($style_tone, array('neutral', 'official', 'friendly'), true)) {
                $style_tone = 'neutral';
            }

            $blocks = array();

            if ($base_system_prompt !== '') {
                $blocks[] = $base_system_prompt;
            }

            // Style rules (RU+EN to avoid getting stuck in one language).
            $lang_rule = $style_language === 'ru'
                ? 'Пиши по-русски.'
                : ($style_language === 'en' ? 'Write in English.' : '');
            if ($lang_rule !== '') {
                $blocks[] = $lang_rule;
            }

            if ($style_tone === 'official') {
                $blocks[] = 'Тон: официальный, деловой, без фамильярности.\nTone: official, professional, not casual.';
            } elseif ($style_tone === 'friendly') {
                $blocks[] = 'Тон: дружелюбный, естественный, без жаргона.\nTone: friendly, natural, not slangy.';
            } else {
                $blocks[] = 'Тон: нейтральный.\nTone: neutral.';
            }

            // Anti-repeat: deterministic variation key for comments/reviews.
            if (($scenario === 'comments' || $scenario === 'woo_reviews') && $run_id > 0 && $post_id > 0) {
                $run_seed = trim((string) $run_seed);
                if ($run_seed === '') {
                    $run_seed = wp_generate_uuid4();
                }
                $item_index = max(1, (int) $item_index);
                $key = substr(hash('sha256', $run_seed . '|' . $run_id . '|' . $post_id . '|' . $item_index), 0, 12);
                $blocks[] = 'Variation key: ' . $key . ".\nUse it as a seed to diversify wording and structure. Do not repeat previous phrasings.";
            }

            return trim(implode("\n\n", array_filter($blocks)));
        }

        protected function soft_trim_to_chars($text, $max_chars) {
            $text = trim((string) $text);
            $max_chars = max(1, (int) $max_chars);
            if ($text === '') {
                return '';
            }
            if (function_exists('mb_strlen') && function_exists('mb_substr')) {
                if (mb_strlen($text, 'UTF-8') <= $max_chars) {
                    return $text;
                }
                $cut = rtrim(mb_substr($text, 0, $max_chars, 'UTF-8'));
                $search_from = max(0, (int) floor($max_chars * 0.65));
                $slice = mb_substr($cut, $search_from, null, 'UTF-8');
                $breaks = array(' ', '.', '!', '?', ';', ',', ':');
                $best_pos = -1;
                foreach ($breaks as $br) {
                    $pos = mb_strrpos($slice, $br, 0, 'UTF-8');
                    if ($pos !== false) {
                        $best_pos = max($best_pos, $pos);
                    }
                }
                if ($best_pos >= 0) {
                    $final_len = $search_from + $best_pos;
                    $cut = rtrim(mb_substr($cut, 0, $final_len + 1, 'UTF-8'));
                }
                return trim($cut);
            }

            if (strlen($text) <= $max_chars) {
                return $text;
            }
            $cut = rtrim(substr($text, 0, $max_chars));
            $search_from = max(0, (int) floor($max_chars * 0.65));
            $slice = substr($cut, $search_from);
            $breaks = array(' ', '.', '!', '?', ';', ',', ':');
            $best_pos = -1;
            foreach ($breaks as $br) {
                $pos = strrpos($slice, $br);
                if ($pos !== false) {
                    $best_pos = max($best_pos, $pos);
                }
            }
            if ($best_pos >= 0) {
                $final_len = $search_from + $best_pos;
                $cut = rtrim(substr($cut, 0, $final_len + 1));
            }
            return trim($cut);
        }

        protected function normalize_single_line_result($text) {
            $text = trim((string) $text);
            if ($text === '') {
                return '';
            }

            $text = wp_strip_all_tags($text);
            $text = preg_replace('/\s+/u', ' ', $text);
            $text = trim((string) $text);
            $text = trim($text, "\"'` ");
            return trim($text);
        }

        protected function build_seo_payload($title, $description) {
            $payload = array(
                'title' => (string) $title,
                'description' => (string) $description,
                'focus_keyword' => '',
            );
            return wp_json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }

        protected function sum_credits_spent($first_response, $second_response) {
            $first = is_array($first_response) && isset($first_response['credits_spent']) ? (float) $first_response['credits_spent'] : 0.0;
            $second = is_array($second_response) && isset($second_response['credits_spent']) ? (float) $second_response['credits_spent'] : 0.0;
            return $first + $second;
        }

        protected function is_fatal_api_error(WP_Error $error) {
            $code = (string) $error->get_error_code();
            return strpos($code, 'ucg_api_http_401') === 0 || strpos($code, 'ucg_api_http_402') === 0;
        }

        protected function resolve_effective_generation_mode($generation_mode, $scenario, $options = array()) {
            $generation_mode = sanitize_key((string) $generation_mode);
            if (!in_array($generation_mode, array('review', 'publish'), true)) {
                $generation_mode = 'review';
            }
            if ($generation_mode === 'publish') {
                return 'publish';
            }

            $scenario = sanitize_key((string) $scenario);
            $options = is_array($options) ? $options : array();
            $scope = isset($options['scope']) ? sanitize_key((string) $options['scope']) : '';

            // For create-new runs, apply generated data immediately to avoid empty draft products/posts.
            if (($scenario === 'post_fields' || $scenario === 'product_fields') && $scope === 'create_new') {
                return 'publish';
            }

            return 'review';
        }

        protected function should_skip_image_field_error($scenario, WP_Error $error) {
            $scenario = sanitize_key((string) $scenario);
            if ($scenario === 'image_generation') {
                return false;
            }

            $code = (string) $error->get_error_code();
            if (strpos($code, 'ucg_api_http_503') === 0 || strpos($code, 'ucg_api_http_504') === 0) {
                return true;
            }

            $message = strtolower((string) $error->get_error_message());
            if (
                (strpos($code, 'ucg_api_http_500') === 0 || strpos($code, 'ucg_api_http_502') === 0)
                && (
                    strpos($message, 'service unavailable') !== false
                    || strpos($message, 'image service') !== false
                    || strpos($message, 'no valid images') !== false
                )
            ) {
                return true;
            }

            return false;
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
