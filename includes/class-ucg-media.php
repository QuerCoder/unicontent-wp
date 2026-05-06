<?php

if (!defined('ABSPATH')) {
    exit;
}

if (!class_exists('UCG_Media')) {
    class UCG_Media {
        public static function write_generated_value($post_id, $target_field, $value) {
            $post_id = (int) $post_id;
            $target_field = (string) $target_field;
            if ($post_id <= 0 || $target_field === '') {
                return new WP_Error('ucg_media_invalid_target', __('Некорректная цель для записи изображения.', 'unicontent-ai-generator'));
            }

            $sources = self::extract_image_sources($value);
            if (empty($sources)) {
                return new WP_Error('ucg_media_empty_payload', __('Сервис не вернул изображение.', 'unicontent-ai-generator'));
            }

            $attachment_ids = array();
            foreach ($sources as $source_index => $source) {
                $attachment = self::create_attachment_from_source($post_id, $source, $source_index + 1);
                if (is_wp_error($attachment)) {
                    self::cleanup_created_attachments($attachment_ids);
                    return $attachment;
                }
                $attachment_id = (int) $attachment;
                if ($attachment_id > 0) {
                    $attachment_ids[] = $attachment_id;
                }
            }

            if (empty($attachment_ids)) {
                return new WP_Error('ucg_media_not_created', __('Не удалось создать вложение изображения.', 'unicontent-ai-generator'));
            }

            if ($target_field === 'media:featured') {
                set_post_thumbnail($post_id, (int) $attachment_ids[0]);
                return array(
                    'featured_id' => (int) $attachment_ids[0],
                    'attachment_ids' => $attachment_ids,
                );
            }

            if ($target_field === 'media:product_images') {
                set_post_thumbnail($post_id, (int) $attachment_ids[0]);
                $gallery_ids = array_map('intval', array_slice($attachment_ids, 1));
                update_post_meta($post_id, '_product_image_gallery', implode(',', $gallery_ids));
                return array(
                    'featured_id' => (int) $attachment_ids[0],
                    'gallery_ids' => $gallery_ids,
                    'attachment_ids' => $attachment_ids,
                );
            }

            if ($target_field === 'media:gallery') {
                $gallery_ids = array_map('intval', $attachment_ids);
                update_post_meta($post_id, '_product_image_gallery', implode(',', $gallery_ids));
                return array(
                    'gallery_ids' => $gallery_ids,
                    'attachment_ids' => $attachment_ids,
                );
            }

            self::cleanup_created_attachments($attachment_ids);
            return new WP_Error('ucg_media_target_not_supported', __('Тип целевого поля изображений не поддерживается.', 'unicontent-ai-generator'));
        }

        protected static function extract_image_sources($value) {
            $sources = array();
            self::collect_image_sources($value, $sources);
            $normalized = array();
            foreach ($sources as $source) {
                $source = trim((string) $source);
                if ($source === '') {
                    continue;
                }
                $normalized[] = $source;
                if (count($normalized) >= 8) {
                    break;
                }
            }
            return $normalized;
        }

        protected static function collect_image_sources($value, &$sources) {
            if (count($sources) >= 8) {
                return;
            }

            if (is_string($value)) {
                $text = trim($value);
                if ($text === '') {
                    return;
                }
                $decoded = json_decode($text, true);
                if (is_array($decoded)) {
                    self::collect_image_sources($decoded, $sources);
                    return;
                }
                if (preg_match('#^data:image/[a-zA-Z0-9.+-]+;base64,#', $text) || filter_var($text, FILTER_VALIDATE_URL)) {
                    $sources[] = $text;
                }
                return;
            }

            if (is_array($value)) {
                if (isset($value['url']) && is_string($value['url'])) {
                    self::collect_image_sources((string) $value['url'], $sources);
                }
                if (isset($value['image_url']) && is_array($value['image_url']) && isset($value['image_url']['url'])) {
                    self::collect_image_sources((string) $value['image_url']['url'], $sources);
                }
                if (isset($value['imageUrl']) && is_array($value['imageUrl']) && isset($value['imageUrl']['url'])) {
                    self::collect_image_sources((string) $value['imageUrl']['url'], $sources);
                }
                if (isset($value['images']) && is_array($value['images'])) {
                    foreach ($value['images'] as $image_item) {
                        self::collect_image_sources($image_item, $sources);
                    }
                }
                if (array_keys($value) === range(0, count($value) - 1)) {
                    foreach ($value as $item) {
                        self::collect_image_sources($item, $sources);
                    }
                }
            }
        }

        protected static function create_attachment_from_source($post_id, $source, $index = 1) {
            if (preg_match('#^data:image/([a-zA-Z0-9.+-]+);base64,#', (string) $source, $matches)) {
                return self::create_attachment_from_data_url($post_id, (string) $source, isset($matches[1]) ? (string) $matches[1] : 'png', $index);
            }
            if (filter_var($source, FILTER_VALIDATE_URL)) {
                return self::create_attachment_from_remote_url($post_id, (string) $source, $index);
            }
            return new WP_Error('ucg_media_invalid_source', __('Некорректный формат изображения.', 'unicontent-ai-generator'));
        }

        protected static function create_attachment_from_data_url($post_id, $data_url, $extension_hint = 'png', $index = 1) {
            $comma_pos = strpos($data_url, ',');
            if ($comma_pos === false) {
                return new WP_Error('ucg_media_invalid_data_url', __('Некорректный data URL изображения.', 'unicontent-ai-generator'));
            }
            $encoded = substr($data_url, $comma_pos + 1);
            $binary = base64_decode($encoded, true);
            if ($binary === false || $binary === '') {
                return new WP_Error('ucg_media_invalid_base64', __('Некорректные данные изображения.', 'unicontent-ai-generator'));
            }

            $ext = preg_replace('/[^a-zA-Z0-9]/', '', (string) strtolower($extension_hint));
            if ($ext === '') {
                $ext = 'png';
            }
            if ($ext === 'jpeg') {
                $ext = 'jpg';
            }

            $filename = sprintf('ucg-%d-%d-%s.%s', $post_id, time(), (string) $index, $ext);
            $upload = wp_upload_bits($filename, null, $binary);
            if (!is_array($upload) || !empty($upload['error'])) {
                return new WP_Error('ucg_media_upload_failed', isset($upload['error']) ? (string) $upload['error'] : __('Не удалось сохранить изображение.', 'unicontent-ai-generator'));
            }

            $file_path = isset($upload['file']) ? (string) $upload['file'] : '';
            $file_type = wp_check_filetype($file_path);
            $mime_type = isset($file_type['type']) ? (string) $file_type['type'] : 'image/png';

            $attachment_id = wp_insert_attachment(
                array(
                    'post_mime_type' => $mime_type,
                    'post_title' => sanitize_file_name(pathinfo($filename, PATHINFO_FILENAME)),
                    'post_content' => '',
                    'post_status' => 'inherit',
                    'post_parent' => (int) $post_id,
                ),
                $file_path,
                $post_id
            );

            if (is_wp_error($attachment_id) || (int) $attachment_id <= 0) {
                if ($file_path !== '' && file_exists($file_path)) {
                    @unlink($file_path);
                }
                return is_wp_error($attachment_id)
                    ? $attachment_id
                    : new WP_Error('ucg_media_attachment_failed', __('Не удалось создать вложение изображения.', 'unicontent-ai-generator'));
            }

            require_once ABSPATH . 'wp-admin/includes/image.php';
            $metadata = wp_generate_attachment_metadata((int) $attachment_id, $file_path);
            if (!is_wp_error($metadata) && is_array($metadata)) {
                wp_update_attachment_metadata((int) $attachment_id, $metadata);
            }

            return (int) $attachment_id;
        }

        protected static function create_attachment_from_remote_url($post_id, $source_url, $index = 1) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
            require_once ABSPATH . 'wp-admin/includes/media.php';
            require_once ABSPATH . 'wp-admin/includes/image.php';

            $tmp_file = download_url($source_url, 60);
            if (is_wp_error($tmp_file)) {
                return $tmp_file;
            }

            $parsed_path = wp_parse_url($source_url, PHP_URL_PATH);
            $basename = is_string($parsed_path) ? basename($parsed_path) : '';
            if ($basename === '' || strpos($basename, '.') === false) {
                $basename = sprintf('ucg-%d-%d-%s.png', $post_id, time(), (string) $index);
            }

            $file_array = array(
                'name' => sanitize_file_name($basename),
                'tmp_name' => $tmp_file,
            );

            $attachment_id = media_handle_sideload($file_array, $post_id, '');
            if (is_wp_error($attachment_id)) {
                @unlink($tmp_file);
                return $attachment_id;
            }

            return (int) $attachment_id;
        }

        protected static function cleanup_created_attachments($attachment_ids) {
            if (!is_array($attachment_ids)) {
                return;
            }
            foreach ($attachment_ids as $attachment_id) {
                $attachment_id = (int) $attachment_id;
                if ($attachment_id <= 0) {
                    continue;
                }
                wp_delete_attachment($attachment_id, true);
            }
        }
    }
}
