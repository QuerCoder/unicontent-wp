<?php

if (!defined('ABSPATH')) {
    exit;
}

if (!class_exists('UCG_Api_Client')) {
    class UCG_Api_Client {
        protected $override_api_key;
        protected $override_base_url;

        public function __construct($override_api_key = '', $override_base_url = '') {
            $this->override_api_key = trim((string) $override_api_key);
            $this->override_base_url = trim((string) $override_base_url);
        }

        public function test_connection() {
            return $this->get_balance();
        }

        public function get_balance() {
            $response = $this->request('GET', '/balance');
            if (is_wp_error($response)) {
                return $response;
            }

            $credits = isset($response['credits']) ? (float) $response['credits'] : 0.0;
            $masked_key = isset($response['api_key']) ? (string) $response['api_key'] : '';

            return array(
                'credits' => $credits,
                'api_key' => $masked_key,
            );
        }

        public function get_text_length_options($model = 'auto') {
            $model = trim((string) $model);
            if ($model === '') {
                $model = 'auto';
            }

            $path = '/text-length-options?model=' . rawurlencode($model);
            $response = $this->request('GET', $path);
            if (is_wp_error($response)) {
                return $response;
            }

            $options = isset($response['options']) && is_array($response['options']) ? $response['options'] : array();
            $result_options = array();

            foreach ($options as $item) {
                if (!is_array($item)) {
                    continue;
                }

                $id = isset($item['id']) ? (int) $item['id'] : 0;
                $name = isset($item['name']) ? trim((string) $item['name']) : '';
                $max_chars = isset($item['max_chars']) ? (int) $item['max_chars'] : 0;
                $credits_cost = isset($item['credits_cost']) ? (float) $item['credits_cost'] : 0.0;

                if ($id <= 0 || $name === '' || $max_chars <= 0 || $credits_cost <= 0) {
                    continue;
                }

                $result_options[] = array(
                    'id' => $id,
                    'name' => $name,
                    'max_chars' => $max_chars,
                    'credits_cost' => $credits_cost,
                );
            }

            return array(
                'options' => $result_options,
                'default_option_id' => isset($response['default_option_id']) ? (int) $response['default_option_id'] : 0,
                'vary_length_hint' => isset($response['vary_length_hint']) ? (string) $response['vary_length_hint'] : '',
            );
        }

        public function get_generation_models($scenario = 'field_update') {
            $scenario = sanitize_key((string) $scenario);
            if ($scenario === '') {
                $scenario = 'field_update';
            }

            $path = '/generation-models?scenario=' . rawurlencode($scenario);
            $response = $this->request('GET', $path);
            if (is_wp_error($response)) {
                return $response;
            }

            $models = isset($response['models']) && is_array($response['models']) ? $response['models'] : array();
            $normalized_models = array();

            foreach ($models as $item) {
                if (!is_array($item)) {
                    continue;
                }

                $value = isset($item['id']) ? sanitize_key((string) $item['id']) : '';
                $label = isset($item['name']) ? trim((string) $item['name']) : '';
                if ($value === '' || $label === '') {
                    continue;
                }

                $estimated = isset($item['estimated_credits_by_length']) && is_array($item['estimated_credits_by_length'])
                    ? $item['estimated_credits_by_length']
                    : array();
                $normalized_estimated = array();
                foreach ($estimated as $key => $credits) {
                    $length_key = (string) $key;
                    $normalized_estimated[$length_key] = max(0.0, (float) $credits);
                }

                $normalized_models[] = array(
                    'id' => $value,
                    'name' => $label,
                    'provider' => isset($item['provider']) ? trim((string) $item['provider']) : '',
                    'resolved_model' => isset($item['resolved_model']) ? trim((string) $item['resolved_model']) : '',
                    'is_default' => !empty($item['is_default']),
                    'estimated_credits_by_length' => $normalized_estimated,
                    'multiplier' => isset($item['multiplier']) ? max(0.1, (float) $item['multiplier']) : 1.0,
                );
            }

            $default_model = isset($response['default_model']) ? sanitize_key((string) $response['default_model']) : 'auto';
            if ($default_model === '') {
                $default_model = 'auto';
            }

            return array(
                'scenario' => isset($response['scenario']) ? sanitize_key((string) $response['scenario']) : $scenario,
                'unit_label' => isset($response['unit_label']) ? trim((string) $response['unit_label']) : __('1 единица', 'unicontent-ai-generator'),
                'default_model' => $default_model,
                'models' => $normalized_models,
            );
        }

        public function get_prompt_library($args = array()) {
            $args = is_array($args) ? $args : array();

            $query = array();
            if (!empty($args['category_slug'])) {
                $query['category_slug'] = sanitize_key((string) $args['category_slug']);
            }
            if (!empty($args['type_slug'])) {
                $query['type_slug'] = sanitize_key((string) $args['type_slug']);
            }
            if (!empty($args['search'])) {
                $query['search'] = sanitize_text_field((string) $args['search']);
            }
            if (!empty($args['limit'])) {
                $query['limit'] = max(1, min(1000, (int) $args['limit']));
            }

            $path = '/prompt-library';
            if (!empty($query)) {
                $path .= '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
            }

            $response = $this->request('GET', $path);
            if (is_wp_error($response)) {
                return $response;
            }

            $categories = isset($response['categories']) && is_array($response['categories']) ? $response['categories'] : array();
            $types = isset($response['types']) && is_array($response['types']) ? $response['types'] : array();
            $prompts = isset($response['prompts']) && is_array($response['prompts']) ? $response['prompts'] : array();

            $normalized_categories = array();
            foreach ($categories as $item) {
                if (!is_array($item)) {
                    continue;
                }
                $id = isset($item['id']) ? (int) $item['id'] : 0;
                $name = isset($item['name']) ? trim((string) $item['name']) : '';
                $slug = isset($item['slug']) ? sanitize_key((string) $item['slug']) : '';
                $description = isset($item['description']) ? trim((string) $item['description']) : '';
                if ($id <= 0 || $name === '' || $slug === '') {
                    continue;
                }

                $normalized_categories[] = array(
                    'id' => $id,
                    'name' => $name,
                    'slug' => $slug,
                    'description' => $description,
                );
            }

            $normalized_types = array();
            foreach ($types as $item) {
                if (!is_array($item)) {
                    continue;
                }
                $id = isset($item['id']) ? (int) $item['id'] : 0;
                $name = isset($item['name']) ? trim((string) $item['name']) : '';
                $slug = isset($item['slug']) ? sanitize_key((string) $item['slug']) : '';
                $description = isset($item['description']) ? trim((string) $item['description']) : '';
                if ($id <= 0 || $name === '' || $slug === '') {
                    continue;
                }

                $normalized_types[] = array(
                    'id' => $id,
                    'name' => $name,
                    'slug' => $slug,
                    'description' => $description,
                );
            }

            $normalized_prompts = array();
            foreach ($prompts as $item) {
                if (!is_array($item)) {
                    continue;
                }
                $id = isset($item['id']) ? (int) $item['id'] : 0;
                $name = isset($item['name']) ? trim((string) $item['name']) : '';
                $slug = isset($item['slug']) ? sanitize_key((string) $item['slug']) : '';
                $summary = isset($item['summary']) ? trim((string) $item['summary']) : '';
                $body = isset($item['body']) ? (string) $item['body'] : '';

                $category_item = isset($item['category']) && is_array($item['category']) ? $item['category'] : array();
                $type_item = isset($item['type']) && is_array($item['type']) ? $item['type'] : array();

                $category_id = isset($category_item['id']) ? (int) $category_item['id'] : 0;
                $category_name = isset($category_item['name']) ? trim((string) $category_item['name']) : '';
                $category_slug = isset($category_item['slug']) ? sanitize_key((string) $category_item['slug']) : '';

                $type_id = isset($type_item['id']) ? (int) $type_item['id'] : 0;
                $type_name = isset($type_item['name']) ? trim((string) $type_item['name']) : '';
                $type_slug = isset($type_item['slug']) ? sanitize_key((string) $type_item['slug']) : '';

                if (
                    $id <= 0 || $name === '' || $slug === '' || trim($body) === ''
                    || $category_id <= 0 || $category_slug === '' || $type_id <= 0 || $type_slug === ''
                ) {
                    continue;
                }

                $normalized_prompts[] = array(
                    'id' => $id,
                    'name' => $name,
                    'slug' => $slug,
                    'summary' => $summary,
                    'body' => $body,
                    'category' => array(
                        'id' => $category_id,
                        'name' => $category_name,
                        'slug' => $category_slug,
                    ),
                    'type' => array(
                        'id' => $type_id,
                        'name' => $type_name,
                        'slug' => $type_slug,
                    ),
                );
            }

            return array(
                'categories' => $normalized_categories,
                'types' => $normalized_types,
                'prompts' => $normalized_prompts,
            );
        }

        public function generate_text($prompt, $system_prompt = '', $max_tokens = 1500, $length_option_id = 0, $vary_length = false, $model = 'auto') {
            $prompt = trim((string) $prompt);
            if ($prompt === '') {
                return new WP_Error('ucg_empty_prompt', __('Пустой промпт.', 'unicontent-ai-generator'));
            }

            $model = trim((string) $model);
            if ($model === '') {
                $model = 'auto';
            }

            $body = array(
                'prompt' => $prompt,
                'max_tokens' => max(1, min(4000, (int) $max_tokens)),
                'model' => $model,
            );

            $system_prompt = trim((string) $system_prompt);
            if ($system_prompt !== '') {
                $body['system_prompt'] = $system_prompt;
            }

            $length_option_id = (int) $length_option_id;
            if ($length_option_id > 0) {
                $body['length_option_id'] = $length_option_id;
            }
            $body['vary_length'] = !empty($vary_length);

            return $this->request('POST', '/generate', $body);
        }

        protected function request($method, $path, $body = null) {
            $settings = UCG_Settings::get();
            $api_key = $this->override_api_key !== '' ? $this->override_api_key : (isset($settings['api_key']) ? trim((string) $settings['api_key']) : '');
            if ($api_key === '') {
                return new WP_Error('ucg_no_api_key', __('Сначала добавьте API ключ на дашборде плагина.', 'unicontent-ai-generator'));
            }

            $base = $this->override_base_url !== '' ? rtrim($this->override_base_url, '/') : UCG_Settings::get_api_base_url();
            $url = $base . '/api/v1' . $path;
            $timeout = isset($settings['request_timeout']) ? (int) $settings['request_timeout'] : 60;

            $args = array(
                'method' => strtoupper((string) $method),
                'timeout' => max(10, min(180, $timeout)),
                'headers' => array(
                    'Content-Type' => 'application/json',
                    'Accept' => 'application/json',
                    'X-API-Key' => $api_key,
                ),
            );

            if ($body !== null) {
                $args['body'] = wp_json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            }

            $response = wp_remote_request($url, $args);
            if (is_wp_error($response)) {
                return new WP_Error('ucg_api_connection', $response->get_error_message());
            }

            $status_code = (int) wp_remote_retrieve_response_code($response);
            $raw_body = (string) wp_remote_retrieve_body($response);
            $data = json_decode($raw_body, true);

            if ($status_code >= 200 && $status_code < 300) {
                return is_array($data) ? $data : array();
            }

            $message = $this->extract_error_message($data, $raw_body);
            return new WP_Error('ucg_api_http_' . $status_code, $message, array('status_code' => $status_code, 'response' => $data));
        }

        protected function extract_error_message($data, $raw_body) {
            if (is_array($data)) {
                if (isset($data['detail']) && is_string($data['detail']) && $data['detail'] !== '') {
                    return $data['detail'];
                }

                if (isset($data['detail']) && is_array($data['detail'])) {
                    if (!empty($data['detail']['error'])) {
                        return (string) $data['detail']['error'];
                    }
                    if (!empty($data['detail']['message'])) {
                        return (string) $data['detail']['message'];
                    }
                }

                if (!empty($data['error'])) {
                    return (string) $data['error'];
                }

                if (!empty($data['message'])) {
                    return (string) $data['message'];
                }
            }

            $raw_body = trim((string) $raw_body);
            if ($raw_body !== '') {
                return $raw_body;
            }

            return __('Ошибка ответа API.', 'unicontent-ai-generator');
        }
    }
}
