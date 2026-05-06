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

                $value = $this->normalize_model_identifier(isset($item['id']) ? (string) $item['id'] : '');
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

                $input_modalities = $this->normalize_model_modalities(
                    isset($item['input_modalities']) ? $item['input_modalities'] : array('text'),
                    array('text')
                );
                $output_modalities = $this->normalize_model_modalities(
                    isset($item['output_modalities']) ? $item['output_modalities'] : array('text'),
                    array('text')
                );
                $supported_parameters = $this->normalize_model_modalities(
                    isset($item['supported_parameters']) ? $item['supported_parameters'] : array(),
                    array()
                );
                $architecture_modality = isset($item['architecture_modality']) ? trim((string) $item['architecture_modality']) : '';
                if ($architecture_modality === '') {
                    $architecture_modality = implode('+', $input_modalities) . '->' . implode('+', $output_modalities);
                }

                $normalized_models[] = array(
                    'id' => $value,
                    'name' => $label,
                    'provider' => isset($item['provider']) ? trim((string) $item['provider']) : '',
                    'resolved_model' => isset($item['resolved_model']) ? trim((string) $item['resolved_model']) : '',
                    'developer' => isset($item['developer']) ? trim((string) $item['developer']) : '',
                    'developer_slug' => isset($item['developer_slug']) ? sanitize_key((string) $item['developer_slug']) : '',
                    'architecture_modality' => $architecture_modality,
                    'input_modalities' => $input_modalities,
                    'output_modalities' => $output_modalities,
                    'supported_parameters' => $supported_parameters,
                    'context_length' => isset($item['context_length']) ? max(0, (int) $item['context_length']) : 0,
                    'is_default' => !empty($item['is_default']),
                    'estimated_credits_by_length' => $normalized_estimated,
                    'multiplier' => isset($item['multiplier']) ? max(0.1, (float) $item['multiplier']) : 1.0,
                );
            }

            $default_model = $this->normalize_model_identifier(isset($response['default_model']) ? (string) $response['default_model'] : 'auto');
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

        protected function normalize_operation_type($value, $fallback = 'text') {
            $value = sanitize_key((string) $value);
            if (!in_array($value, array('text', 'seo_tags', 'long_text', 'image'), true)) {
                $value = sanitize_key((string) $fallback);
            }
            if (!in_array($value, array('text', 'seo_tags', 'long_text', 'image'), true)) {
                $value = 'text';
            }
            return $value;
        }

        protected function normalize_model_modalities($value, $fallback = array()) {
            $fallback_values = array();
            if (is_array($fallback)) {
                foreach ($fallback as $fallback_item) {
                    $fallback_value = sanitize_key((string) $fallback_item);
                    if ($fallback_value !== '' && !in_array($fallback_value, $fallback_values, true)) {
                        $fallback_values[] = $fallback_value;
                    }
                }
            }

            if (is_string($value)) {
                $value = explode(',', $value);
            }
            if (!is_array($value)) {
                return $fallback_values;
            }

            $result = array();
            foreach ($value as $item) {
                $item_value = strtolower(trim((string) $item));
                $item_value = preg_replace('/[^a-z0-9_\\-+]/', '', $item_value);
                if (!is_string($item_value) || $item_value === '' || in_array($item_value, $result, true)) {
                    continue;
                }
                $result[] = $item_value;
            }

            if (!empty($result)) {
                return $result;
            }
            return $fallback_values;
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

        public function generate_text($prompt, $system_prompt = '', $max_tokens = 1500, $length_option_id = 0, $vary_length = false, $model = 'auto', $operation_type = 'text') {
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
                'operation_type' => $this->normalize_operation_type($operation_type, 'text'),
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

        public function generate_multi_field($prompt, $system_prompt = '', $fields_spec = array(), $model = 'auto', $max_tokens = 1500, $operation_type = 'text') {
            $prompt = trim((string) $prompt);
            if ($prompt === '') {
                return new WP_Error('ucg_empty_prompt', __('Пустой промпт.', 'unicontent-ai-generator'));
            }

            $model = trim((string) $model);
            if ($model === '') {
                $model = 'auto';
            }

            $normalized_fields = array();
            if (is_array($fields_spec)) {
                foreach ($fields_spec as $field) {
                    if (!is_array($field)) {
                        continue;
                    }
                    $key = sanitize_key(isset($field['key']) ? (string) $field['key'] : '');
                    if ($key === '') {
                        continue;
                    }
                    $normalized_fields[] = array(
                        'key' => $key,
                        'label' => sanitize_text_field(isset($field['label']) ? (string) $field['label'] : $key),
                        'max_chars' => isset($field['max_chars']) ? max(0, (int) $field['max_chars']) : 0,
                        'hint' => isset($field['hint']) ? sanitize_text_field((string) $field['hint']) : '',
                        'required' => !array_key_exists('required', $field) || !empty($field['required']),
                    );
                }
            }

            if (empty($normalized_fields)) {
                return new WP_Error('ucg_invalid_fields_spec', __('Не переданы поля для multi-field генерации.', 'unicontent-ai-generator'));
            }

            $body = array(
                'prompt' => $prompt,
                'max_tokens' => max(1, min(4000, (int) $max_tokens)),
                'model' => $model,
                'operation_type' => $this->normalize_operation_type($operation_type, 'text'),
                'fields' => $normalized_fields,
            );

            $system_prompt = trim((string) $system_prompt);
            if ($system_prompt !== '') {
                $body['system_prompt'] = $system_prompt;
            }

            return $this->request('POST', '/generate', $body);
        }

        public function generate_image($prompt, $system_prompt = '', $model = 'auto', $images_count = 1, $aspect_ratio = '', $image_size = '', $operation_type = 'image') {
            $prompt = trim((string) $prompt);
            if ($prompt === '') {
                return new WP_Error('ucg_empty_prompt', __('Пустой промпт.', 'unicontent-ai-generator'));
            }

            $model = trim((string) $model);
            if ($model === '') {
                $model = 'auto';
            }

            $images_count = max(1, min(8, (int) $images_count));

            $body = array(
                'prompt' => $prompt,
                'model' => $model,
                'images_count' => $images_count,
                'operation_type' => $this->normalize_operation_type($operation_type, 'image'),
            );

            $system_prompt = trim((string) $system_prompt);
            if ($system_prompt !== '') {
                $body['system_prompt'] = $system_prompt;
            }

            $aspect_ratio = trim((string) $aspect_ratio);
            if ($aspect_ratio !== '') {
                $body['aspect_ratio'] = $aspect_ratio;
            }

            $image_size = trim((string) $image_size);
            if ($image_size !== '') {
                $body['image_size'] = $image_size;
            }

            // Image generation can legitimately take longer than text requests.
            // Timeout scales with number of requested images and selected size.
            $image_timeout = 90 + (max(0, $images_count - 1) * 60);
            $normalized_image_size = strtoupper($image_size);
            if ($normalized_image_size === '2K') {
                $image_timeout += 30;
            } elseif ($normalized_image_size === '4K') {
                $image_timeout += 60;
            }
            $image_timeout = max(90, min(600, (int) $image_timeout));

            return $this->request('POST', '/generate-image', $body, $image_timeout);
        }

        public function create_pricing_quote($params = array()) {
            $params = is_array($params) ? $params : array();

            $operation_type = $this->normalize_operation_type(
                isset($params['operation_type']) ? (string) $params['operation_type'] : 'text',
                'text'
            );
            $model = $this->normalize_model_identifier(isset($params['model']) ? (string) $params['model'] : 'auto');
            if ($model === '') {
                $model = 'auto';
            }

            $body = array(
                'operation_type' => $operation_type,
                'model' => $model,
                'prompt' => isset($params['prompt']) ? (string) $params['prompt'] : '',
                'max_tokens' => max(1, min(4000, isset($params['max_tokens']) ? (int) $params['max_tokens'] : 1500)),
                'images_count' => max(1, min(8, isset($params['images_count']) ? (int) $params['images_count'] : 1)),
                'vary_length' => !empty($params['vary_length']),
                'create_hold' => !empty($params['create_hold']),
                'ttl_seconds' => max(60, min(3600, isset($params['ttl_seconds']) ? (int) $params['ttl_seconds'] : 600)),
            );

            $aspect_ratio = trim((string) (isset($params['aspect_ratio']) ? $params['aspect_ratio'] : ''));
            if ($aspect_ratio !== '') {
                $body['aspect_ratio'] = $aspect_ratio;
            }

            $image_size = strtoupper(trim((string) (isset($params['image_size']) ? $params['image_size'] : '')));
            if (in_array($image_size, array('0.5K', '1K', '2K', '4K'), true)) {
                $body['image_size'] = $image_size;
            }

            $length_option_id = isset($params['length_option_id']) ? (int) $params['length_option_id'] : 0;
            if ($length_option_id > 0) {
                $body['length_option_id'] = $length_option_id;
            }

            $fields = isset($params['fields']) && is_array($params['fields']) ? $params['fields'] : array();
            if (!empty($fields)) {
                $normalized_fields = array();
                foreach ($fields as $field) {
                    if (!is_array($field)) {
                        continue;
                    }
                    $key = sanitize_key(isset($field['key']) ? (string) $field['key'] : '');
                    if ($key === '') {
                        continue;
                    }
                    $normalized_fields[] = array(
                        'key' => $key,
                        'label' => sanitize_text_field(isset($field['label']) ? (string) $field['label'] : $key),
                        'max_chars' => isset($field['max_chars']) ? max(0, min(10000, (int) $field['max_chars'])) : 0,
                        'hint' => isset($field['hint']) ? sanitize_text_field((string) $field['hint']) : '',
                        'required' => !array_key_exists('required', $field) || !empty($field['required']),
                    );
                }
                if (!empty($normalized_fields)) {
                    $body['fields'] = $normalized_fields;
                }
            }

            // Quote is a UI helper. Keep timeout lower than generation requests
            // so the wizard can quickly fall back to local estimate.
            $quote_timeout = 20;
            $settings = UCG_Settings::get();
            if (isset($settings['request_timeout'])) {
                $quote_timeout = max(10, min(30, (int) $settings['request_timeout']));
            }

            return $this->request('POST', '/pricing/quote', $body, $quote_timeout);
        }

        protected function request($method, $path, $body = null, $timeout_override = null) {
            $settings = UCG_Settings::get();
            $api_key = $this->override_api_key !== '' ? $this->override_api_key : (isset($settings['api_key']) ? trim((string) $settings['api_key']) : '');
            if ($api_key === '') {
                if (class_exists('UCG_Logger')) {
                    UCG_Logger::warn('api', 'no_api_key', 'API key is missing.', array('path' => (string) $path));
                }
                return new WP_Error('ucg_no_api_key', __('Сначала добавьте API ключ на дашборде плагина.', 'unicontent-ai-generator'));
            }

            $base = $this->override_base_url !== '' ? rtrim($this->override_base_url, '/') : UCG_Settings::get_api_base_url();
            $url = $base . '/api/v1' . $path;
            $timeout = isset($settings['request_timeout']) ? (int) $settings['request_timeout'] : 60;
            if (is_numeric($timeout_override)) {
                $timeout = (int) $timeout_override;
            }

            $args = array(
                'method' => strtoupper((string) $method),
                'timeout' => max(10, min(600, $timeout)),
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
                if (class_exists('UCG_Logger')) {
                    UCG_Logger::error(
                        'api',
                        'request_failed',
                        'API request failed.',
                        array(
                            'method' => (string) $args['method'],
                            'path' => (string) $path,
                            'timeout' => isset($args['timeout']) ? (int) $args['timeout'] : 0,
                            'error' => (string) $response->get_error_message(),
                        )
                    );
                }
                return new WP_Error('ucg_api_connection', $response->get_error_message());
            }

            $status_code = (int) wp_remote_retrieve_response_code($response);
            $raw_body = (string) wp_remote_retrieve_body($response);
            $data = json_decode($raw_body, true);

            if ($status_code >= 200 && $status_code < 300) {
                return is_array($data) ? $data : array();
            }

            $message = $this->extract_error_message($data, $raw_body);
            if (class_exists('UCG_Logger')) {
                $body_snippet = $this->build_http_error_body_snippet($raw_body);
                UCG_Logger::warn(
                    'api',
                    'http_error',
                    'API HTTP error.',
                    array(
                        'method' => (string) $args['method'],
                        'path' => (string) $path,
                        'status_code' => $status_code,
                        'message' => (string) $message,
                        'body_snippet' => $body_snippet,
                    )
                );
            }
            return new WP_Error('ucg_api_http_' . $status_code, $message, array('status_code' => $status_code, 'response' => $data));
        }

        protected function build_http_error_body_snippet($raw_body) {
            $raw_body = trim((string) $raw_body);
            if ($raw_body === '') {
                return '';
            }
            $raw_body = preg_replace('/\s+/', ' ', $raw_body);
            if (!is_string($raw_body)) {
                return '';
            }
            if (strlen($raw_body) > 800) {
                return substr($raw_body, 0, 800) . '...';
            }
            return $raw_body;
        }

        protected function extract_error_message($data, $raw_body) {
            if (is_array($data)) {
                if (isset($data['detail']) && is_string($data['detail']) && $data['detail'] !== '') {
                    return $data['detail'];
                }

                if (isset($data['detail']) && is_array($data['detail'])) {
                    if (!empty($data['detail']['message'])) {
                        return (string) $data['detail']['message'];
                    }
                    if (!empty($data['detail']['error'])) {
                        return (string) $data['detail']['error'];
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
