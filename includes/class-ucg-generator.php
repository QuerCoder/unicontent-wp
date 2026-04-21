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
            $safety_no_medical_financial = 1;
            $safety_no_competitors = 1;
            $safety_no_caps = 1;
            $run_seed = '';

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
                    $model = isset($options['model']) ? sanitize_key((string) $options['model']) : 'auto';
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
                    $safety_no_medical_financial = !empty($options['safety_no_medical_financial']) ? 1 : 0;
                    $safety_no_competitors = !empty($options['safety_no_competitors']) ? 1 : 0;
                    $safety_no_caps = !empty($options['safety_no_caps']) ? 1 : 0;
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
                $safety_no_medical_financial,
                $safety_no_competitors,
                $safety_no_caps,
                $run_seed,
                $run_id,
                $post_id,
                isset($item['item_index']) ? (int) $item['item_index'] : 1
            );

            if ($scenario === 'seo_tags') {
                if ($seo_title_prompt_template === '' && $seo_description_prompt_template === '' && $template_body !== '') {
                    $seo_title_prompt_template = $template_body;
                    $seo_description_prompt_template = $template_body;
                }

                if (trim($seo_title_prompt_template) === '' || trim($seo_description_prompt_template) === '') {
                    UCG_DB::update_run_item(
                        $item_id,
                        array(
                            'status' => 'failed',
                            'error_message' => __('SEO-шаблоны не найдены или пустые.', 'unicontent-ai-generator'),
                        )
                    );
                    return new WP_Error('ucg_template_missing', __('SEO-шаблон не найден.', 'unicontent-ai-generator'));
                }

                $seo_title_prompt = UCG_Tokens::render_prompt_for_post($seo_title_prompt_template, $post_id);
                $seo_description_prompt = UCG_Tokens::render_prompt_for_post($seo_description_prompt_template, $post_id);
                if (trim($seo_title_prompt) === '' || trim($seo_description_prompt) === '') {
                    $combined_prompt = "SEO title:\n" . $seo_title_prompt . "\n\nSEO description:\n" . $seo_description_prompt;
                    UCG_DB::update_run_item(
                        $item_id,
                        array(
                            'status' => 'failed',
                            'prompt' => $combined_prompt,
                            'error_message' => __('SEO-промпт пустой после подстановки переменных.', 'unicontent-ai-generator'),
                        )
                    );
                    return new WP_Error('ucg_prompt_empty', __('SEO-промпт пустой.', 'unicontent-ai-generator'));
                }

                $seo_title_prompt = $this->build_prompt_for_single_seo_field($seo_title_prompt, 'title');
                $seo_description_prompt = $this->build_prompt_for_single_seo_field($seo_description_prompt, 'description');
                $combined_prompt = "SEO title:\n" . $seo_title_prompt . "\n\nSEO description:\n" . $seo_description_prompt;

                $response_title = $api_client->generate_text($seo_title_prompt, $system_prompt, $max_tokens, $length_option_id, $vary_length, $model);
                if (is_wp_error($response_title)) {
                    $next_status = ($attempts + 1) >= 3 ? 'failed' : 'queued';
                    if ($this->is_fatal_api_error($response_title)) {
                        $next_status = 'failed';
                    }
                    UCG_DB::update_run_item(
                        $item_id,
                        array(
                            'status' => $next_status,
                            'prompt' => $combined_prompt,
                            'error_message' => $response_title->get_error_message(),
                        )
                    );
                    return $response_title;
                }

                $generated_title = $this->normalize_single_line_result(isset($response_title['text']) ? (string) $response_title['text'] : '');
                $generated_title = $this->soft_trim_to_chars($generated_title, 70);
                if ($generated_title === '') {
                    $next_status = ($attempts + 1) >= 3 ? 'failed' : 'queued';
                    UCG_DB::update_run_item(
                        $item_id,
                        array(
                            'status' => $next_status,
                            'prompt' => $combined_prompt,
                            'error_message' => __('API вернул пустой SEO title.', 'unicontent-ai-generator'),
                            'credits_spent' => isset($response_title['credits_spent']) ? (float) $response_title['credits_spent'] : 0.0,
                            'credits_remaining' => isset($response_title['credits_remaining']) ? (float) $response_title['credits_remaining'] : 0.0,
                        )
                    );
                    return new WP_Error('ucg_empty_result', __('Пустой SEO title от API.', 'unicontent-ai-generator'));
                }

                $response_description = $api_client->generate_text($seo_description_prompt, $system_prompt, $max_tokens, $length_option_id, $vary_length, $model);
                if (is_wp_error($response_description)) {
                    $next_status = ($attempts + 1) >= 3 ? 'failed' : 'queued';
                    if ($this->is_fatal_api_error($response_description)) {
                        $next_status = 'failed';
                    }
                    UCG_DB::update_run_item(
                        $item_id,
                        array(
                            'status' => $next_status,
                            'prompt' => $combined_prompt,
                            'error_message' => $response_description->get_error_message(),
                            'credits_spent' => isset($response_title['credits_spent']) ? (float) $response_title['credits_spent'] : 0.0,
                            'credits_remaining' => isset($response_title['credits_remaining']) ? (float) $response_title['credits_remaining'] : 0.0,
                        )
                    );
                    return $response_description;
                }

                $generated_description = $this->normalize_single_line_result(isset($response_description['text']) ? (string) $response_description['text'] : '');
                $generated_description = $this->soft_trim_to_chars($generated_description, 160);
                if ($generated_description === '') {
                    $next_status = ($attempts + 1) >= 3 ? 'failed' : 'queued';
                    UCG_DB::update_run_item(
                        $item_id,
                        array(
                            'status' => $next_status,
                            'prompt' => $combined_prompt,
                            'error_message' => __('API вернул пустой SEO description.', 'unicontent-ai-generator'),
                            'credits_spent' => $this->sum_credits_spent($response_title, $response_description),
                            'credits_remaining' => isset($response_description['credits_remaining'])
                                ? (float) $response_description['credits_remaining']
                                : (isset($response_title['credits_remaining']) ? (float) $response_title['credits_remaining'] : 0.0),
                        )
                    );
                    return new WP_Error('ucg_empty_result', __('Пустой SEO description от API.', 'unicontent-ai-generator'));
                }

                $generated_text = $this->build_seo_payload($generated_title, $generated_description);
                $credits_spent = $this->sum_credits_spent($response_title, $response_description);
                $credits_remaining = isset($response_description['credits_remaining'])
                    ? (float) $response_description['credits_remaining']
                    : (isset($response_title['credits_remaining']) ? (float) $response_title['credits_remaining'] : 0.0);

                $update_data = array(
                    'status' => 'generated',
                    'prompt' => $combined_prompt,
                    'generated_text' => $generated_text,
                    'error_message' => '',
                    'credits_spent' => $credits_spent,
                    'credits_remaining' => $credits_remaining,
                    'generated_at' => current_time('mysql', true),
                );

                if ($generation_mode === 'publish') {
                    $target_field = isset($item['target_field']) ? (string) $item['target_field'] : '';
                    $write_result = UCG_Tokens::write_generated_value($post_id, $target_field, $generated_text, $write_context);
                    if (is_wp_error($write_result)) {
                        UCG_DB::update_run_item(
                            $item_id,
                            array(
                                'status' => 'failed',
                                'prompt' => $combined_prompt,
                                'generated_text' => $generated_text,
                                'error_message' => $write_result->get_error_message(),
                                'credits_spent' => $credits_spent,
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
            $prompt = $this->build_prompt_for_comment_and_review_scenarios($prompt, $scenario, $rating_min, $rating_max);

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
                $write_result = UCG_Tokens::write_generated_value($post_id, $target_field, $generated_text, $write_context);
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

        protected function build_effective_system_prompt(
            $base_system_prompt,
            $scenario,
            $style_language,
            $style_tone,
            $style_uniqueness,
            $safety_no_medical_financial,
            $safety_no_competitors,
            $safety_no_caps,
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

            $style_uniqueness = sanitize_key((string) $style_uniqueness);
            if (!in_array($style_uniqueness, array('low', 'medium', 'high'), true)) {
                $style_uniqueness = 'medium';
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

            if ($style_uniqueness === 'high') {
                $blocks[] = 'Уникальность: высокая. Избегай повторов шаблонов и клише; используй разные вступления и структуру.\nUniqueness: high. Avoid templates/cliches; vary openings and structure.';
            } elseif ($style_uniqueness === 'low') {
                $blocks[] = 'Уникальность: низкая. Допускается более прямой стиль.\nUniqueness: low. More direct wording is ok.';
            } else {
                $blocks[] = 'Уникальность: средняя. Старайся разнообразить формулировки.\nUniqueness: medium. Try to vary wording.';
            }

            // Safety.
            $safety_lines = array();
            if (!empty($safety_no_medical_financial)) {
                $safety_lines[] = 'Запрещены медицинские и финансовые обещания/гарантии.\nNo medical or financial promises/guarantees.';
            }
            if (!empty($safety_no_competitors)) {
                $safety_lines[] = 'Не упоминай конкурентов, бренды конкурентов и сравнения.\nDo not mention competitors or comparisons.';
            }
            if (!empty($safety_no_caps)) {
                $safety_lines[] = 'Не используй CAPS (все буквы заглавные).\nDo not use ALL CAPS.';
            }
            if (!empty($safety_lines)) {
                $blocks[] = implode("\n", $safety_lines);
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
