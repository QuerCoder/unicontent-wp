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

        public static function has_woocommerce_support() {
            return class_exists('WooCommerce') && post_type_exists('product');
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

        public static function write_generated_value($post_id, $target_field, $text, $context = array()) {
            $post_id = (int) $post_id;
            $text = (string) $text;
            $context = is_array($context) ? $context : array();
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

            if (strpos($target_field, 'seo:') === 0) {
                $profile = substr($target_field, 4);
                return self::write_seo_package($post_id, $profile, $text);
            }

            if (strpos($target_field, 'comment:') === 0) {
                return self::write_generated_comment($post_id, $text, $context);
            }

            if (strpos($target_field, 'woo_review:') === 0) {
                return self::write_generated_woo_review($post_id, $text, $context);
            }

            return new WP_Error('ucg_target_not_supported', __('Тип целевого поля не поддерживается.', 'unicontent-ai-generator'));
        }

        protected static function write_generated_comment($post_id, $text, $context = array()) {
            if (!post_type_supports(get_post_type($post_id), 'comments')) {
                return new WP_Error('ucg_comments_not_supported', __('Этот тип записи не поддерживает комментарии.', 'unicontent-ai-generator'));
            }
            $payload = self::parse_generated_comment_payload($text, false);
            return self::insert_generated_comment($post_id, $payload, 'comment', $context);
        }

        protected static function write_generated_woo_review($post_id, $text, $context = array()) {
            if (!self::has_woocommerce_support()) {
                return new WP_Error('ucg_woo_missing', __('WooCommerce не активен.', 'unicontent-ai-generator'));
            }

            if (get_post_type($post_id) !== 'product') {
                return new WP_Error('ucg_woo_not_product', __('Отзывы WooCommerce можно создавать только для товаров.', 'unicontent-ai-generator'));
            }

            $payload = self::parse_generated_comment_payload($text, true);
            return self::insert_generated_comment($post_id, $payload, 'review', $context);
        }

        protected static function parse_generated_comment_payload($text, $is_review = false) {
            $text = trim((string) $text);
            $is_review = !empty($is_review);

            $payload = array(
                'author_name' => '',
                'author_email' => '',
                'content' => '',
                'rating' => 0,
            );

            $json_payload = self::extract_json_payload($text);
            if (!empty($json_payload)) {
                $author_keys = array('author_name', 'author', 'name');
                $email_keys = array('author_email', 'email');
                $content_keys = array('content', 'text', 'comment', 'review');
                $rating_keys = array('rating', 'stars', 'score');

                foreach ($author_keys as $key) {
                    if (!empty($json_payload[$key])) {
                        $payload['author_name'] = self::value_to_string($json_payload[$key]);
                        break;
                    }
                }
                foreach ($email_keys as $key) {
                    if (!empty($json_payload[$key])) {
                        $payload['author_email'] = self::value_to_string($json_payload[$key]);
                        break;
                    }
                }
                foreach ($content_keys as $key) {
                    if (!empty($json_payload[$key])) {
                        $payload['content'] = self::value_to_string($json_payload[$key]);
                        break;
                    }
                }
                foreach ($rating_keys as $key) {
                    if ($json_payload[$key] !== '' && $json_payload[$key] !== null) {
                        $payload['rating'] = (int) $json_payload[$key];
                        break;
                    }
                }
            }

            if ($payload['content'] === '' && $text !== '') {
                $lines = preg_split('/\r\n|\r|\n/', $text);
                if (is_array($lines)) {
                    foreach ($lines as $line) {
                        $line = trim((string) $line);
                        if ($line === '') {
                            continue;
                        }
                        if ($payload['author_name'] === '' && preg_match('/^(author|name|имя)\s*[:\-]\s*(.+)$/iu', $line, $match)) {
                            $payload['author_name'] = isset($match[2]) ? (string) $match[2] : '';
                            continue;
                        }
                        if ($payload['content'] === '' && preg_match('/^(comment|review|text|комментарий|отзыв)\s*[:\-]\s*(.+)$/iu', $line, $match)) {
                            $payload['content'] = isset($match[2]) ? (string) $match[2] : '';
                            continue;
                        }
                        if ($payload['rating'] <= 0 && preg_match('/^(rating|stars|оценка)\s*[:\-]\s*([0-9]+)/iu', $line, $match)) {
                            $payload['rating'] = isset($match[2]) ? (int) $match[2] : 0;
                            continue;
                        }
                    }
                }
            }

            if ($payload['content'] === '') {
                $payload['content'] = $text;
            }

            $payload['author_name'] = trim(sanitize_text_field((string) $payload['author_name']));
            $payload['author_email'] = sanitize_email((string) $payload['author_email']);
            $payload['content'] = trim((string) wp_kses_post((string) $payload['content']));
            $payload['rating'] = self::normalize_rating_value($payload['rating'], $is_review);

            if ($payload['author_name'] === '') {
                $payload['author_name'] = $is_review
                    ? __('Покупатель', 'unicontent-ai-generator')
                    : __('Посетитель', 'unicontent-ai-generator');
            }

            return $payload;
        }

        protected static function normalize_rating_value($rating, $is_review) {
            $rating = (int) $rating;
            if (!$is_review) {
                return 0;
            }
            if ($rating < 1 || $rating > 5) {
                return 5;
            }
            return $rating;
        }

        protected static function insert_generated_comment($post_id, $payload, $comment_type = 'comment', $context = array()) {
            $post_id = (int) $post_id;
            $comment_type = sanitize_key((string) $comment_type);
            $payload = is_array($payload) ? $payload : array();
            $context = is_array($context) ? $context : array();

            $content = isset($payload['content']) ? trim((string) $payload['content']) : '';
            if ($post_id <= 0 || $content === '') {
                return new WP_Error('ucg_comment_empty', __('Пустой текст комментария.', 'unicontent-ai-generator'));
            }

            $author_name = isset($payload['author_name']) ? trim((string) $payload['author_name']) : '';
            if ($author_name === '') {
                $author_name = __('Пользователь', 'unicontent-ai-generator');
            }
            $author_email = isset($payload['author_email']) ? sanitize_email((string) $payload['author_email']) : '';
            if ($author_email === '') {
                $author_email = 'noreply+' . $post_id . '+' . wp_generate_password(6, false, false) . '@example.local';
            }

            $publish_dates = self::resolve_comment_publish_dates($context);

            $comment_id = wp_insert_comment(
                array(
                    'comment_post_ID' => $post_id,
                    'comment_author' => $author_name,
                    'comment_author_email' => $author_email,
                    'comment_content' => $content,
                    'comment_type' => $comment_type === 'review' ? 'review' : '',
                    'comment_approved' => 1,
                    'comment_author_url' => '',
                    'user_id' => 0,
                    'comment_date' => isset($publish_dates['local']) ? (string) $publish_dates['local'] : current_time('mysql'),
                    'comment_date_gmt' => isset($publish_dates['gmt']) ? (string) $publish_dates['gmt'] : current_time('mysql', true),
                )
            );

            if (!$comment_id) {
                return new WP_Error('ucg_comment_insert_failed', __('Не удалось создать комментарий.', 'unicontent-ai-generator'));
            }

            if ($comment_type === 'review') {
                $rating = isset($payload['rating']) ? (int) $payload['rating'] : 5;
                if ($rating < 1 || $rating > 5) {
                    $rating = 5;
                }
                update_comment_meta($comment_id, 'rating', $rating);
            }

            return true;
        }

        protected static function resolve_comment_publish_dates($context) {
            $context = is_array($context) ? $context : array();
            $date_from = isset($context['publish_date_from']) ? self::normalize_publish_date((string) $context['publish_date_from']) : '';
            $date_to = isset($context['publish_date_to']) ? self::normalize_publish_date((string) $context['publish_date_to']) : '';

            if ($date_from !== '' && $date_to !== '') {
                try {
                    $timezone = wp_timezone();
                    $start = new DateTimeImmutable($date_from . ' 00:00:00', $timezone);
                    $end = new DateTimeImmutable($date_to . ' 23:59:59', $timezone);
                    $start_ts = $start->getTimestamp();
                    $end_ts = $end->getTimestamp();
                    if ($end_ts < $start_ts) {
                        $tmp = $start_ts;
                        $start_ts = $end_ts;
                        $end_ts = $tmp;
                    }
                    $random_ts = wp_rand($start_ts, $end_ts);
                    $local = wp_date('Y-m-d H:i:s', $random_ts, $timezone);
                    return array(
                        'local' => $local,
                        'gmt' => get_gmt_from_date($local),
                    );
                } catch (Exception $e) {
                    // Fallback to current time below.
                }
            }

            $local_now = current_time('mysql');
            return array(
                'local' => $local_now,
                'gmt' => get_gmt_from_date($local_now),
            );
        }

        protected static function normalize_publish_date($value) {
            $value = trim((string) $value);
            if ($value === '') {
                return '';
            }
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
                return '';
            }
            return $value;
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

        public static function get_seo_profile_options($include_auto = true, $include_unavailable = false) {
            $include_auto = !empty($include_auto);
            $include_unavailable = !empty($include_unavailable);
            $options = array();
            $yoast_available = defined('WPSEO_VERSION') || class_exists('WPSEO_Meta');
            $rank_math_available = defined('RANK_MATH_VERSION') || class_exists('RankMath');
            $aioseo_available = defined('AIOSEO_VERSION') || class_exists('AIOSEO\\Plugin\\Common\\Main\\Main') || class_exists('AIOSEO\\Plugin\\Common\\Main');
            $has_active = $yoast_available || $rank_math_available || $aioseo_available;

            if ($include_auto) {
                $options[] = array(
                    'value' => 'auto',
                    'label' => __('Авто (активный SEO плагин)', 'unicontent-ai-generator'),
                    'is_available' => $has_active,
                );
            }

            if ($include_unavailable || $yoast_available) {
                $options[] = array(
                    'value' => 'yoast',
                    'label' => 'Yoast SEO',
                    'is_available' => $yoast_available,
                );
            }

            if ($include_unavailable || $rank_math_available) {
                $options[] = array(
                    'value' => 'rank_math',
                    'label' => 'Rank Math',
                    'is_available' => $rank_math_available,
                );
            }

            if ($include_unavailable || $aioseo_available) {
                $options[] = array(
                    'value' => 'aioseo',
                    'label' => 'All in One SEO',
                    'is_available' => $aioseo_available,
                );
            }

            return $options;
        }

        public static function has_supported_seo_plugin() {
            $options = self::get_seo_profile_options(false, false);
            return !empty($options);
        }

        public static function get_default_seo_profile() {
            $detected = self::detect_active_seo_profile();
            return $detected !== '' ? $detected : 'auto';
        }

        protected static function detect_active_seo_profile() {
            if (defined('WPSEO_VERSION') || class_exists('WPSEO_Meta')) {
                return 'yoast';
            }
            if (defined('RANK_MATH_VERSION') || class_exists('RankMath')) {
                return 'rank_math';
            }
            if (defined('AIOSEO_VERSION') || class_exists('AIOSEO\\Plugin\\Common\\Main\\Main') || class_exists('AIOSEO\\Plugin\\Common\\Main')) {
                return 'aioseo';
            }
            return '';
        }

        protected static function resolve_seo_profile($profile) {
            $profile = sanitize_key((string) $profile);
            if ($profile === '' || $profile === 'auto') {
                return self::detect_active_seo_profile();
            }

            $known = array('yoast' => true, 'rank_math' => true, 'aioseo' => true);
            if (!isset($known[$profile])) {
                return '';
            }

            foreach (self::get_seo_profile_options(false, false) as $item) {
                if (!is_array($item) || empty($item['value'])) {
                    continue;
                }
                if ((string) $item['value'] === $profile) {
                    return $profile;
                }
            }

            return '';
        }

        protected static function extract_json_payload($text) {
            $text = trim((string) $text);
            if ($text === '') {
                return array();
            }

            $decoded = json_decode($text, true);
            if (is_array($decoded)) {
                return $decoded;
            }

            if (preg_match('/\{[\s\S]*\}/u', $text, $match)) {
                $decoded_fragment = json_decode((string) $match[0], true);
                if (is_array($decoded_fragment)) {
                    return $decoded_fragment;
                }
            }

            return array();
        }

        protected static function parse_seo_package($text) {
            $text = trim((string) $text);
            $parsed = array(
                'title' => '',
                'description' => '',
                'focus_keyword' => '',
            );

            $json_payload = self::extract_json_payload($text);
            if (!empty($json_payload)) {
                $title_keys = array('title', 'seo_title', 'meta_title');
                $description_keys = array('description', 'meta_description', 'desc');
                $keyword_keys = array('focus_keyword', 'keyword', 'keyphrase');

                foreach ($title_keys as $key) {
                    if (!empty($json_payload[$key])) {
                        $parsed['title'] = self::value_to_string($json_payload[$key]);
                        break;
                    }
                }
                foreach ($description_keys as $key) {
                    if (!empty($json_payload[$key])) {
                        $parsed['description'] = self::value_to_string($json_payload[$key]);
                        break;
                    }
                }
                foreach ($keyword_keys as $key) {
                    if (!empty($json_payload[$key])) {
                        $parsed['focus_keyword'] = self::value_to_string($json_payload[$key]);
                        break;
                    }
                }
            }

            if ($parsed['title'] === '' && $parsed['description'] === '') {
                $lines = preg_split('/\r\n|\r|\n/', $text);
                if (is_array($lines)) {
                    foreach ($lines as $line) {
                        $line = trim((string) $line);
                        if ($line === '') {
                            continue;
                        }
                        if ($parsed['title'] === '' && preg_match('/^(title|заголовок)\s*[:\-]\s*(.+)$/iu', $line, $match)) {
                            $parsed['title'] = isset($match[2]) ? (string) $match[2] : '';
                            continue;
                        }
                        if ($parsed['description'] === '' && preg_match('/^(description|desc|описание)\s*[:\-]\s*(.+)$/iu', $line, $match)) {
                            $parsed['description'] = isset($match[2]) ? (string) $match[2] : '';
                            continue;
                        }
                        if ($parsed['focus_keyword'] === '' && preg_match('/^(keyword|focus|keyphrase|ключ|ключевая фраза)\s*[:\-]\s*(.+)$/iu', $line, $match)) {
                            $parsed['focus_keyword'] = isset($match[2]) ? (string) $match[2] : '';
                            continue;
                        }
                    }
                }
            }

            if ($parsed['title'] === '' && $parsed['description'] === '' && $text !== '') {
                $parts = preg_split('/\r\n|\r|\n/', $text, 2);
                if (is_array($parts) && !empty($parts[0])) {
                    $parsed['title'] = (string) $parts[0];
                    $parsed['description'] = isset($parts[1]) ? (string) $parts[1] : '';
                }
            }

            $parsed['title'] = trim(wp_strip_all_tags((string) $parsed['title']));
            $parsed['description'] = trim(wp_strip_all_tags((string) $parsed['description']));
            $parsed['focus_keyword'] = trim(wp_strip_all_tags((string) $parsed['focus_keyword']));

            return $parsed;
        }

        protected static function get_seo_meta_keys($profile) {
            $profile = sanitize_key((string) $profile);
            switch ($profile) {
                case 'yoast':
                    return array(
                        'title' => '_yoast_wpseo_title',
                        'description' => '_yoast_wpseo_metadesc',
                        'focus_keyword' => '_yoast_wpseo_focuskw',
                        'og_title' => '_yoast_wpseo_opengraph-title',
                        'og_description' => '_yoast_wpseo_opengraph-description',
                    );
                case 'rank_math':
                    return array(
                        'title' => 'rank_math_title',
                        'description' => 'rank_math_description',
                        'focus_keyword' => 'rank_math_focus_keyword',
                        'og_title' => array('rank_math_facebook_title', 'rank_math_twitter_title'),
                        'og_description' => array('rank_math_facebook_description', 'rank_math_twitter_description'),
                    );
                case 'aioseo':
                    return array(
                        'title' => '_aioseo_title',
                        'description' => '_aioseo_description',
                        'focus_keyword' => '_aioseo_keywords',
                        'og_title' => '',
                        'og_description' => '',
                    );
            }
            return array();
        }

        protected static function write_seo_package($post_id, $profile, $text) {
            $profile = self::resolve_seo_profile($profile);
            if ($profile === '') {
                return new WP_Error('ucg_seo_provider_missing', __('Не найден активный SEO плагин для записи тегов.', 'unicontent-ai-generator'));
            }

            $parsed = self::parse_seo_package($text);
            $title = isset($parsed['title']) ? (string) $parsed['title'] : '';
            $description = isset($parsed['description']) ? (string) $parsed['description'] : '';
            $focus_keyword = isset($parsed['focus_keyword']) ? (string) $parsed['focus_keyword'] : '';

            if ($title === '' && $description === '' && $focus_keyword === '') {
                return new WP_Error('ucg_seo_payload_empty', __('Не удалось извлечь SEO поля из ответа модели.', 'unicontent-ai-generator'));
            }

            $meta_keys = self::get_seo_meta_keys($profile);
            if (empty($meta_keys)) {
                return new WP_Error('ucg_seo_profile_invalid', __('Некорректный SEO профиль.', 'unicontent-ai-generator'));
            }

            if (!empty($meta_keys['title']) && $title !== '') {
                update_post_meta($post_id, $meta_keys['title'], $title);
            }
            if (!empty($meta_keys['description']) && $description !== '') {
                update_post_meta($post_id, $meta_keys['description'], $description);
            }
            if (!empty($meta_keys['focus_keyword']) && $focus_keyword !== '') {
                update_post_meta($post_id, $meta_keys['focus_keyword'], $focus_keyword);
            }

            if (!empty($meta_keys['og_title']) && $title !== '') {
                $og_title_keys = is_array($meta_keys['og_title']) ? $meta_keys['og_title'] : array($meta_keys['og_title']);
                foreach ($og_title_keys as $og_title_key) {
                    $og_title_key = sanitize_key((string) $og_title_key);
                    if ($og_title_key === '') {
                        continue;
                    }
                    update_post_meta($post_id, $og_title_key, $title);
                }
            }
            if (!empty($meta_keys['og_description']) && $description !== '') {
                $og_description_keys = is_array($meta_keys['og_description']) ? $meta_keys['og_description'] : array($meta_keys['og_description']);
                foreach ($og_description_keys as $og_description_key) {
                    $og_description_key = sanitize_key((string) $og_description_key);
                    if ($og_description_key === '') {
                        continue;
                    }
                    update_post_meta($post_id, $og_description_key, $description);
                }
            }

            return true;
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

            if (strpos($target_field, 'seo:') === 0) {
                $profile = substr($target_field, 4);
                $profile = self::resolve_seo_profile($profile);
                $meta_keys = self::get_seo_meta_keys($profile);
                if (empty($meta_keys)) {
                    return '';
                }
                $title = !empty($meta_keys['title']) ? self::value_to_string(get_post_meta($post_id, $meta_keys['title'], true)) : '';
                $description = !empty($meta_keys['description']) ? self::value_to_string(get_post_meta($post_id, $meta_keys['description'], true)) : '';

                $parts = array();
                if ($title !== '') {
                    $parts[] = __('Title:', 'unicontent-ai-generator') . ' ' . $title;
                }
                if ($description !== '') {
                    $parts[] = __('Description:', 'unicontent-ai-generator') . ' ' . $description;
                }
                return implode(' ', $parts);
            }

            if (strpos($target_field, 'comment:') === 0) {
                $comment = get_comments(
                    array(
                        'post_id' => $post_id,
                        'status' => 'approve',
                        'type' => 'comment',
                        'number' => 1,
                        'orderby' => 'comment_ID',
                        'order' => 'DESC',
                    )
                );
                if (!is_array($comment) || empty($comment[0]) || !($comment[0] instanceof WP_Comment)) {
                    return '';
                }
                $latest = $comment[0];
                return trim((string) $latest->comment_content);
            }

            if (strpos($target_field, 'woo_review:') === 0) {
                $review = get_comments(
                    array(
                        'post_id' => $post_id,
                        'status' => 'approve',
                        'type' => 'review',
                        'number' => 1,
                        'orderby' => 'comment_ID',
                        'order' => 'DESC',
                    )
                );
                if (!is_array($review) || empty($review[0]) || !($review[0] instanceof WP_Comment)) {
                    return '';
                }
                $latest = $review[0];
                $rating = (int) get_comment_meta((int) $latest->comment_ID, 'rating', true);
                $result = trim((string) $latest->comment_content);
                if ($rating > 0) {
                    $result = '[' . __('Оценка', 'unicontent-ai-generator') . ': ' . $rating . '/5] ' . $result;
                }
                return $result;
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
