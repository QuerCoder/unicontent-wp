<?php

if (!defined('ABSPATH')) {
    exit;
}

if (!class_exists('UCG_Tokens')) {
    class UCG_Tokens {
        const MAX_META_KEYS = 250;

        public static function get_post_types_for_ui() {
            $objects = get_post_types(array('show_ui' => true), 'objects');
            if (!is_array($objects)) {
                return array();
            }

            $items = array();
            foreach ($objects as $post_type => $obj) {
                if (!is_object($obj)) {
                    continue;
                }
                $label = isset($obj->labels->singular_name) ? (string) $obj->labels->singular_name : (string) $post_type;
                $items[] = array(
                    'value' => (string) $post_type,
                    'label' => $label . ' (' . $post_type . ')',
                );
            }

            return $items;
        }

        public static function get_default_post_type() {
            $post_types = self::get_post_types_for_ui();
            if (empty($post_types)) {
                return 'post';
            }

            foreach ($post_types as $item) {
                if (!empty($item['value']) && $item['value'] === 'post') {
                    return 'post';
                }
            }

            return (string) $post_types[0]['value'];
        }

        public static function get_target_fields_for_post_type($post_type) {
            $post_type = sanitize_key((string) $post_type);
            if ($post_type === '' || !post_type_exists($post_type)) {
                return array();
            }

            $fields = array(
                array('value' => 'post:post_content', 'label' => __('Содержание (post_content)', 'unicontent-ai-generator')),
                array('value' => 'post:post_title', 'label' => __('Заголовок (post_title)', 'unicontent-ai-generator')),
                array('value' => 'post:post_excerpt', 'label' => __('Краткое описание (post_excerpt)', 'unicontent-ai-generator')),
            );
            $fields = array_merge($fields, self::get_known_seo_target_fields());

            $meta_keys = self::get_meta_keys_for_post_type($post_type);
            foreach ($meta_keys as $meta_key) {
                $fields[] = array(
                    'value' => 'meta:' . $meta_key,
                    'label' => sprintf(__('Метаполе (%s)', 'unicontent-ai-generator'), $meta_key),
                );
            }

            $acf_fields = self::get_acf_field_names($post_type);
            foreach ($acf_fields as $acf_field_name) {
                $fields[] = array(
                    'value' => 'acf:' . $acf_field_name,
                    'label' => sprintf(__('ACF поле (%s)', 'unicontent-ai-generator'), $acf_field_name),
                );
            }

            return self::unique_fields($fields);
        }

        public static function get_prompt_tokens_for_post_type($post_type) {
            $post_type = sanitize_key((string) $post_type);
            if ($post_type === '' || !post_type_exists($post_type)) {
                return array();
            }

            $tokens = array(
                array('token' => '{post_id}', 'label' => __('ID записи', 'unicontent-ai-generator')),
                array('token' => '{post_title}', 'label' => __('Заголовок', 'unicontent-ai-generator')),
                array('token' => '{post_content}', 'label' => __('Контент', 'unicontent-ai-generator')),
                array('token' => '{post_excerpt}', 'label' => __('Краткое описание', 'unicontent-ai-generator')),
                array('token' => '{post_slug}', 'label' => __('Слаг', 'unicontent-ai-generator')),
                array('token' => '{post_status}', 'label' => __('Статус', 'unicontent-ai-generator')),
                array('token' => '{post_type}', 'label' => __('Тип записи', 'unicontent-ai-generator')),
                array('token' => '{site_url}', 'label' => __('URL сайта', 'unicontent-ai-generator')),
                array('token' => '{today_date}', 'label' => __('Текущая дата', 'unicontent-ai-generator')),
                array('token' => '{current_datetime}', 'label' => __('Текущие дата и время', 'unicontent-ai-generator')),
            );

            $taxonomies = get_object_taxonomies($post_type, 'objects');
            if (is_array($taxonomies)) {
                foreach ($taxonomies as $taxonomy => $taxonomy_obj) {
                    if (!is_object($taxonomy_obj)) {
                        continue;
                    }
                    $label = isset($taxonomy_obj->labels->singular_name) ? (string) $taxonomy_obj->labels->singular_name : (string) $taxonomy;
                    $tokens[] = array(
                        'token' => '{tax:' . $taxonomy . '}',
                        'label' => sprintf(__('Термины таксономии %s', 'unicontent-ai-generator'), $label),
                    );
                }
            }

            $meta_keys = self::get_meta_keys_for_post_type($post_type);
            foreach ($meta_keys as $meta_key) {
                $tokens[] = array(
                    'token' => '{meta:' . $meta_key . '}',
                    'label' => sprintf(__('Метаполе %s', 'unicontent-ai-generator'), $meta_key),
                );
            }

            $acf_fields = self::get_acf_field_names($post_type);
            foreach ($acf_fields as $acf_field_name) {
                $tokens[] = array(
                    'token' => '{acf:' . $acf_field_name . '}',
                    'label' => sprintf(__('ACF поле %s', 'unicontent-ai-generator'), $acf_field_name),
                );
            }

            if (class_exists('WooCommerce') && $post_type === 'product') {
                $tokens[] = array('token' => '{wc:sku}', 'label' => 'SKU');
                $tokens[] = array('token' => '{wc:price}', 'label' => __('Цена', 'unicontent-ai-generator'));
                $tokens[] = array('token' => '{wc:regular_price}', 'label' => __('Обычная цена', 'unicontent-ai-generator'));
                $tokens[] = array('token' => '{wc:sale_price}', 'label' => __('Цена со скидкой', 'unicontent-ai-generator'));
                $tokens[] = array('token' => '{wc:short_description}', 'label' => 'Woo short description');
                $tokens[] = array('token' => '{wc:description}', 'label' => 'Woo description');
                $tokens[] = array('token' => '{wc:attributes}', 'label' => __('Woo атрибуты', 'unicontent-ai-generator'));
                $tokens[] = array('token' => '{wc:categories}', 'label' => __('Woo категории', 'unicontent-ai-generator'));
                $tokens[] = array('token' => '{wc:tags}', 'label' => __('Woo теги', 'unicontent-ai-generator'));
            }

            return self::unique_tokens($tokens);
        }

        public static function render_prompt_for_post($template_body, $post_id) {
            $post_id = (int) $post_id;
            $post = get_post($post_id);
            if (!$post instanceof WP_Post) {
                return '';
            }

            $template_body = (string) $template_body;
            if ($template_body === '') {
                return '';
            }

            $rendered = preg_replace_callback('/\{([a-zA-Z0-9:_-]+)\}/', function ($matches) use ($post) {
                $token = isset($matches[1]) ? (string) $matches[1] : '';
                $value = self::resolve_token($token, $post);
                if ($value === null) {
                    return $matches[0];
                }
                return self::value_to_string($value);
            }, $template_body);

            return is_string($rendered) ? $rendered : '';
        }

        public static function write_generated_value($post_id, $target_field, $text) {
            $post_id = (int) $post_id;
            $text = (string) $text;
            $target_field = self::normalize_field($target_field);
            if ($post_id <= 0 || $target_field === '') {
                return new WP_Error('ucg_invalid_target', __('Некорректное целевое поле.', 'unicontent-ai-generator'));
            }

            if (strpos($target_field, 'post:') === 0) {
                $field_name = substr($target_field, 5);
                $allowed = array('post_content', 'post_excerpt', 'post_title');
                if (!in_array($field_name, $allowed, true)) {
                    return new WP_Error('ucg_invalid_post_field', __('Недоступное поле записи.', 'unicontent-ai-generator'));
                }

                $result = wp_update_post(
                    array(
                        'ID' => $post_id,
                        $field_name => $text,
                    ),
                    true
                );

                if (is_wp_error($result)) {
                    return $result;
                }

                return true;
            }

            if (strpos($target_field, 'meta:') === 0) {
                $meta_key = substr($target_field, 5);
                if ($meta_key === '') {
                    return new WP_Error('ucg_invalid_meta_key', __('Некорректный meta key.', 'unicontent-ai-generator'));
                }

                update_post_meta($post_id, $meta_key, $text);
                return true;
            }

            if (strpos($target_field, 'acf:') === 0) {
                $acf_name = substr($target_field, 4);
                if ($acf_name === '') {
                    return new WP_Error('ucg_invalid_acf_key', __('Некорректное ACF поле.', 'unicontent-ai-generator'));
                }

                if (function_exists('update_field')) {
                    update_field($acf_name, $text, $post_id);
                } else {
                    update_post_meta($post_id, $acf_name, $text);
                }
                return true;
            }

            return new WP_Error('ucg_target_not_supported', __('Тип целевого поля не поддерживается.', 'unicontent-ai-generator'));
        }

        protected static function get_known_seo_target_fields() {
            $fields = array();

            if (defined('WPSEO_VERSION') || class_exists('WPSEO_Meta')) {
                $fields[] = array('value' => 'meta:_yoast_wpseo_title', 'label' => 'Yoast SEO title (_yoast_wpseo_title)');
                $fields[] = array('value' => 'meta:_yoast_wpseo_metadesc', 'label' => 'Yoast meta description (_yoast_wpseo_metadesc)');
                $fields[] = array('value' => 'meta:_yoast_wpseo_focuskw', 'label' => 'Yoast focus keyphrase (_yoast_wpseo_focuskw)');
                $fields[] = array('value' => 'meta:_yoast_wpseo_opengraph-title', 'label' => 'Yoast Open Graph title (_yoast_wpseo_opengraph-title)');
                $fields[] = array('value' => 'meta:_yoast_wpseo_opengraph-description', 'label' => 'Yoast Open Graph description (_yoast_wpseo_opengraph-description)');
            }

            if (defined('RANK_MATH_VERSION') || class_exists('RankMath')) {
                $fields[] = array('value' => 'meta:rank_math_title', 'label' => 'Rank Math title (rank_math_title)');
                $fields[] = array('value' => 'meta:rank_math_description', 'label' => 'Rank Math description (rank_math_description)');
                $fields[] = array('value' => 'meta:rank_math_focus_keyword', 'label' => 'Rank Math focus keyword (rank_math_focus_keyword)');
                $fields[] = array('value' => 'meta:rank_math_twitter_title', 'label' => 'Rank Math Twitter title (rank_math_twitter_title)');
                $fields[] = array('value' => 'meta:rank_math_twitter_description', 'label' => 'Rank Math Twitter description (rank_math_twitter_description)');
            }

            if (defined('AIOSEO_VERSION') || class_exists('AIOSEO\\Plugin\\Common\\Main\\Main') || class_exists('AIOSEO\\Plugin\\Common\\Main')) {
                $fields[] = array('value' => 'meta:_aioseo_title', 'label' => 'AIOSEO title (_aioseo_title)');
                $fields[] = array('value' => 'meta:_aioseo_description', 'label' => 'AIOSEO description (_aioseo_description)');
                $fields[] = array('value' => 'meta:_aioseo_keywords', 'label' => 'AIOSEO keywords (_aioseo_keywords)');
            }

            return $fields;
        }

        public static function get_field_value_for_preview($post_id, $target_field) {
            $post_id = (int) $post_id;
            $target_field = self::normalize_field($target_field);
            if ($post_id <= 0 || $target_field === '') {
                return '';
            }

            $post = get_post($post_id);
            if (!$post instanceof WP_Post) {
                return '';
            }

            if (strpos($target_field, 'post:') === 0) {
                $field_name = substr($target_field, 5);
                if (!in_array($field_name, array('post_content', 'post_excerpt', 'post_title'), true)) {
                    return '';
                }
                return isset($post->{$field_name}) ? (string) $post->{$field_name} : '';
            }

            if (strpos($target_field, 'meta:') === 0) {
                $meta_key = substr($target_field, 5);
                return self::value_to_string(get_post_meta($post_id, $meta_key, true));
            }

            if (strpos($target_field, 'acf:') === 0) {
                $acf_name = substr($target_field, 4);
                if (function_exists('get_field')) {
                    return self::value_to_string(get_field($acf_name, $post_id));
                }
                return self::value_to_string(get_post_meta($post_id, $acf_name, true));
            }

            return '';
        }

        protected static function resolve_token($token, WP_Post $post) {
            switch ($token) {
                case 'post_id':
                    return (int) $post->ID;
                case 'post_title':
                    return (string) $post->post_title;
                case 'post_content':
                    return (string) $post->post_content;
                case 'post_excerpt':
                    return (string) $post->post_excerpt;
                case 'post_slug':
                    return (string) $post->post_name;
                case 'post_status':
                    return (string) $post->post_status;
                case 'post_type':
                    return (string) $post->post_type;
                case 'site_url':
                    return (string) home_url('/');
                case 'today_date':
                    return (string) wp_date('Y-m-d');
                case 'current_datetime':
                    return (string) current_time('mysql');
            }

            if (strpos($token, 'meta:') === 0) {
                $meta_key = substr($token, 5);
                if ($meta_key === '') {
                    return '';
                }
                return get_post_meta($post->ID, $meta_key, true);
            }

            if (strpos($token, 'acf:') === 0) {
                $acf_key = substr($token, 4);
                if ($acf_key === '') {
                    return '';
                }
                if (function_exists('get_field')) {
                    return get_field($acf_key, $post->ID);
                }
                return get_post_meta($post->ID, $acf_key, true);
            }

            if (strpos($token, 'tax:') === 0) {
                $taxonomy = sanitize_key(substr($token, 4));
                if ($taxonomy === '' || !taxonomy_exists($taxonomy)) {
                    return '';
                }

                $terms = get_the_terms($post->ID, $taxonomy);
                if (is_wp_error($terms) || !is_array($terms)) {
                    return '';
                }

                $names = array();
                foreach ($terms as $term) {
                    if (isset($term->name)) {
                        $names[] = (string) $term->name;
                    }
                }
                return implode(', ', $names);
            }

            if (strpos($token, 'wc:') === 0) {
                return self::resolve_wc_token($token, $post);
            }

            return null;
        }

        protected static function resolve_wc_token($token, WP_Post $post) {
            if (!class_exists('WooCommerce') || !function_exists('wc_get_product') || $post->post_type !== 'product') {
                return '';
            }

            $product = wc_get_product($post->ID);
            if (!$product) {
                return '';
            }

            switch ($token) {
                case 'wc:sku':
                    return (string) $product->get_sku();
                case 'wc:price':
                    return (string) $product->get_price();
                case 'wc:regular_price':
                    return (string) $product->get_regular_price();
                case 'wc:sale_price':
                    return (string) $product->get_sale_price();
                case 'wc:short_description':
                    return (string) $product->get_short_description();
                case 'wc:description':
                    return (string) $product->get_description();
                case 'wc:attributes':
                    $values = array();
                    $attrs = $product->get_attributes();
                    if (is_array($attrs)) {
                        foreach ($attrs as $attr) {
                            if (is_object($attr) && method_exists($attr, 'get_name')) {
                                $values[] = $attr->get_name();
                            }
                        }
                    }
                    return implode(', ', array_filter($values));
                case 'wc:categories':
                    return wc_get_product_category_list($post->ID, ', ');
                case 'wc:tags':
                    return wc_get_product_tag_list($post->ID, ', ');
            }

            return '';
        }

        protected static function get_meta_keys_for_post_type($post_type) {
            global $wpdb;

            $post_type = sanitize_key((string) $post_type);
            if ($post_type === '' || !post_type_exists($post_type)) {
                return array();
            }

            $posts_table = $wpdb->posts;
            $meta_table = $wpdb->postmeta;
            $rows = $wpdb->get_col(
                $wpdb->prepare(
                    "SELECT DISTINCT pm.meta_key
                     FROM {$meta_table} pm
                     INNER JOIN {$posts_table} p ON p.ID = pm.post_id
                     WHERE p.post_type = %s
                       AND p.post_status IN ('publish','draft','private','pending')
                       AND pm.meta_key <> ''
                       AND pm.meta_key NOT LIKE %s
                     ORDER BY pm.meta_key ASC
                     LIMIT %d",
                    $post_type,
                    '\_%',
                    self::MAX_META_KEYS
                )
            );

            if (!is_array($rows)) {
                return array();
            }

            $rows = array_map('strval', $rows);
            $rows = array_values(array_unique(array_filter($rows)));
            return $rows;
        }

        protected static function get_acf_field_names($post_type) {
            $post_type = sanitize_key((string) $post_type);
            if ($post_type === '' || !function_exists('acf_get_field_groups') || !function_exists('acf_get_fields')) {
                return array();
            }

            $names = array();
            $groups = acf_get_field_groups(array('post_type' => $post_type));
            if (!is_array($groups)) {
                return array();
            }

            foreach ($groups as $group) {
                if (empty($group['key'])) {
                    continue;
                }

                $fields = acf_get_fields($group['key']);
                if (!is_array($fields)) {
                    continue;
                }

                foreach ($fields as $field) {
                    if (!is_array($field) || empty($field['name'])) {
                        continue;
                    }
                    $names[] = sanitize_key((string) $field['name']);
                }
            }

            $names = array_values(array_unique(array_filter($names)));
            return $names;
        }

        protected static function unique_fields($items) {
            $items = is_array($items) ? $items : array();
            $seen = array();
            $result = array();

            foreach ($items as $item) {
                if (!is_array($item) || empty($item['value'])) {
                    continue;
                }
                $value = (string) $item['value'];
                if (isset($seen[$value])) {
                    continue;
                }
                $seen[$value] = true;
                $result[] = array(
                    'value' => $value,
                    'label' => isset($item['label']) ? (string) $item['label'] : $value,
                );
            }

            return $result;
        }

        protected static function unique_tokens($items) {
            $items = is_array($items) ? $items : array();
            $seen = array();
            $result = array();

            foreach ($items as $item) {
                if (!is_array($item) || empty($item['token'])) {
                    continue;
                }
                $token = (string) $item['token'];
                if (isset($seen[$token])) {
                    continue;
                }
                $seen[$token] = true;
                $result[] = array(
                    'token' => $token,
                    'label' => isset($item['label']) ? (string) $item['label'] : $token,
                );
            }

            return $result;
        }

        protected static function value_to_string($value) {
            if (is_array($value) || is_object($value)) {
                return wp_json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            }

            if ($value === null) {
                return '';
            }

            if (is_bool($value)) {
                return $value ? '1' : '0';
            }

            return (string) $value;
        }

        protected static function normalize_field($field) {
            $field = (string) $field;
            $field = preg_replace('/[^a-zA-Z0-9:_-]/', '', $field);
            return (string) $field;
        }
    }
}
