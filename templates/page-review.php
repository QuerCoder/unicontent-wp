<div class="wrap ucg-wrap ucg-flat-ui">
    <?php $this->render_admin_notice(); ?>
    <?php include UCG_PLUGIN_DIR . 'templates/partials/plugin-header.php'; ?>

    <h1>Проверка результатов</h1>
    <p class="ucg-muted">Можно одобрять/отклонять как весь элемент, так и отдельные поля внутри него.</p>

    <section class="ucg-card">
        <form method="get" class="ucg-filter-row">
            <input type="hidden" name="page" value="ucg-review">

            <label>
                <span>Запуск</span>
                <select
                    name="run_id"
                    class="ucg-enhanced-select"
                    data-search-enabled="false"
                    data-placeholder="Вся история"
                    data-ajax-enabled="true"
                    data-ajax-action="ucg_search_runs"
                    data-ajax-min-chars="0"
                    data-ajax-preload="true"
                    data-ajax-limit="25"
                >
                    <option value="0">Вся история</option>
                    <?php foreach ($runs as $run) : ?>
                        <option value="<?php echo (int) $run['id']; ?>" <?php selected($run_id, (int) $run['id']); ?>>
                            #<?php echo (int) $run['id']; ?> — <?php echo esc_html((string) $run['post_type']); ?> (<?php echo esc_html($this->status_label((string) $run['status'])); ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>

            <label>
                <span>Статус</span>
                <select name="status" class="ucg-enhanced-select" data-search-enabled="false">
                    <option value="generated" <?php selected($status, 'generated'); ?>>Сгенерировано</option>
                    <option value="approved" <?php selected($status, 'approved'); ?>>Одобрено</option>
                    <option value="rejected" <?php selected($status, 'rejected'); ?>>Отклонено</option>
                    <option value="failed" <?php selected($status, 'failed'); ?>>Ошибка</option>
                </select>
            </label>

            <button type="submit" class="button button-primary">Показать</button>
        </form>
    </section>

    <?php
    $ucg_trim_preview_text = static function ($value, $max_length = 500) {
        $text = is_string($value) ? trim($value) : '';
        $max_length = max(20, (int) $max_length);
        if ($text === '') {
            return '';
        }
        if (function_exists('mb_strlen') && function_exists('mb_substr')) {
            if (mb_strlen($text, 'UTF-8') <= $max_length) {
                return $text;
            }
            return rtrim(mb_substr($text, 0, $max_length - 1, 'UTF-8')) . '…';
        }
        if (strlen($text) <= $max_length) {
            return $text;
        }
        return rtrim(substr($text, 0, $max_length - 1)) . '…';
    };

    $ucg_extract_preview_images = static function ($value, $max_items = 8) {
        $max_items = max(1, (int) $max_items);
        $images = array();
        $append = static function ($candidate) use (&$images, $max_items) {
            if (count($images) >= $max_items) {
                return;
            }
            $src = trim((string) $candidate);
            if ($src === '') {
                return;
            }
            if (!preg_match('#^data:image/[a-zA-Z0-9.+-]+;base64,#', $src) && !filter_var($src, FILTER_VALIDATE_URL)) {
                return;
            }
            if (!in_array($src, $images, true)) {
                $images[] = $src;
            }
        };
        $walk = null;
        $walk = static function ($node) use (&$walk, &$append, &$images, $max_items) {
            if (count($images) >= $max_items || $node === null) {
                return;
            }
            if (is_string($node)) {
                $text = trim($node);
                if ($text === '') {
                    return;
                }
                if (preg_match('#^data:image/[a-zA-Z0-9.+-]+;base64,#', $text) || filter_var($text, FILTER_VALIDATE_URL)) {
                    $append($text);
                    return;
                }
                $first_char = substr($text, 0, 1);
                if ($first_char === '[' || $first_char === '{') {
                    $decoded = json_decode($text, true);
                    if (is_array($decoded)) {
                        $walk($decoded);
                        if (count($images) >= $max_items) {
                            return;
                        }
                    }
                }
                $parts = preg_split('/[\r\n,]+/', $text);
                if (is_array($parts)) {
                    foreach ($parts as $part) {
                        $walk($part);
                        if (count($images) >= $max_items) {
                            return;
                        }
                    }
                }
                return;
            }
            if (is_array($node)) {
                if (isset($node['url'])) {
                    $walk($node['url']);
                }
                if (isset($node['image_url']) && is_array($node['image_url']) && isset($node['image_url']['url'])) {
                    $walk($node['image_url']['url']);
                }
                if (isset($node['imageUrl']) && is_array($node['imageUrl']) && isset($node['imageUrl']['url'])) {
                    $walk($node['imageUrl']['url']);
                }
                if (isset($node['images']) && is_array($node['images'])) {
                    foreach ($node['images'] as $item) {
                        $walk($item);
                        if (count($images) >= $max_items) {
                            return;
                        }
                    }
                }
                foreach ($node as $item) {
                    $walk($item);
                    if (count($images) >= $max_items) {
                        return;
                    }
                }
            }
        };
        $walk($value);
        return $images;
    };
    ?>

    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
        <?php wp_nonce_field('ucg_review_bulk'); ?>
        <input type="hidden" name="action" value="ucg_review_bulk">

        <section class="ucg-card">
            <div class="ucg-table-head">
                <h2>Элементы</h2>
                <span class="ucg-muted">Всего: <?php echo (int) $total_items; ?></span>
            </div>

            <?php if (empty($items)) : ?>
                <p class="ucg-muted">По текущему фильтру элементов нет.</p>
            <?php else : ?>
                <table class="widefat striped ucg-review-table">
                    <thead>
                    <tr>
                        <th style="width:45px;"><input type="checkbox" id="ucg-select-all-review"></th>
                        <th style="width:80px;">ID</th>
                        <th style="width:90px;">Run</th>
                        <th style="width:220px;">Запись</th>
                        <th>Текущее значение</th>
                        <th>Сгенерированный текст</th>
                        <th style="width:150px;">Просмотр</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($items as $item) : ?>
                        <?php
                        $post_id = (int) $item['post_id'];
                        $generated_fields_payload = json_decode(isset($item['generated_fields_json']) ? (string) $item['generated_fields_json'] : '', true);
                        $generated_fields_ai = is_array($generated_fields_payload) && isset($generated_fields_payload['ai_fields']) && is_array($generated_fields_payload['ai_fields'])
                            ? $generated_fields_payload['ai_fields']
                            : array();
                        $generated_fields_static = is_array($generated_fields_payload) && isset($generated_fields_payload['static_fields']) && is_array($generated_fields_payload['static_fields'])
                            ? $generated_fields_payload['static_fields']
                            : array();

                        $review_fields = array();

                        foreach ($generated_fields_ai as $field_index => $field) {
                            if (!is_array($field)) {
                                continue;
                            }
                            $field_status = isset($field['status']) ? sanitize_key((string) $field['status']) : 'generated';
                            if ($field_status === '') {
                                $field_status = 'generated';
                            }
                            $field_label = isset($field['label']) ? sanitize_text_field((string) $field['label']) : '';
                            if ($field_label === '') {
                                $field_label = isset($field['key']) ? sanitize_key((string) $field['key']) : __('Поле', 'unicontent-ai-generator');
                            }
                            $field_target = isset($field['target_field']) ? (string) $field['target_field'] : '';
                            if ($field_target === '' && !empty($field['key'])) {
                                $field_target = sanitize_key((string) $field['key']);
                            }
                            $preview_target = $field_target;
                            if ($preview_target === 'seo_field:title' || $preview_target === 'seo_field:description') {
                                $preview_target = 'seo:auto';
                            } elseif ($preview_target === '') {
                                $preview_target = (string) $item['target_field'];
                            }
                            $field_current_value = UCG_Tokens::get_field_value_for_preview($post_id, $preview_target);
                            $field_generated_value = isset($field['generated_text']) ? trim((string) $field['generated_text']) : '';
                            $field_output_type = sanitize_key(isset($field['output_type']) ? (string) $field['output_type'] : '');
                            if ($field_output_type === '') {
                                $field_output_type = strpos($field_target, 'media:') === 0 ? 'image' : 'text';
                            }
                            if ($field_output_type !== 'image') {
                                $field_output_type = 'text';
                            }
                            $generated_media_payload = isset($field['generated_media']) && is_array($field['generated_media'])
                                ? $field['generated_media']
                                : $field_generated_value;
                            $field_generated_images = $field_output_type === 'image'
                                ? $ucg_extract_preview_images($generated_media_payload)
                                : array();
                            $field_current_images = $field_output_type === 'image'
                                ? $ucg_extract_preview_images($field_current_value)
                                : array();

                            $review_fields[] = array(
                                'scope' => 'ai',
                                'index' => (int) $field_index,
                                'status' => $field_status,
                                'label' => $field_label,
                                'target_field' => $field_target,
                                'output_type' => $field_output_type,
                                'generated_value' => $field_generated_value,
                                'current_value' => is_string($field_current_value) ? trim($field_current_value) : '',
                                'generated_images' => $field_generated_images,
                                'current_images' => $field_current_images,
                            );
                        }

                        foreach ($generated_fields_static as $field_index => $field) {
                            if (!is_array($field)) {
                                continue;
                            }
                            $field_status = isset($field['status']) ? sanitize_key((string) $field['status']) : 'generated';
                            if ($field_status === '') {
                                $field_status = 'generated';
                            }
                            $field_label = isset($field['label']) ? sanitize_text_field((string) $field['label']) : '';
                            if ($field_label === '') {
                                $field_label = isset($field['key']) ? sanitize_key((string) $field['key']) : __('Поле', 'unicontent-ai-generator');
                            }
                            $field_target = isset($field['target_field']) ? (string) $field['target_field'] : '';
                            if ($field_target === '' && !empty($field['key'])) {
                                $field_target = sanitize_key((string) $field['key']);
                            }
                            $preview_target = $field_target;
                            if ($preview_target === 'seo_field:title' || $preview_target === 'seo_field:description') {
                                $preview_target = 'seo:auto';
                            } elseif ($preview_target === '') {
                                $preview_target = (string) $item['target_field'];
                            }
                            $field_current_value = UCG_Tokens::get_field_value_for_preview($post_id, $preview_target);

                            $field_raw_value = array_key_exists('value', $field) ? $field['value'] : '';
                            if (is_array($field_raw_value) || is_object($field_raw_value)) {
                                $field_generated_value = wp_json_encode($field_raw_value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                            } elseif ($field_raw_value === null) {
                                $field_generated_value = '';
                            } elseif (is_bool($field_raw_value)) {
                                $field_generated_value = $field_raw_value ? '1' : '0';
                            } else {
                                $field_generated_value = (string) $field_raw_value;
                            }
                            $field_generated_value = trim((string) $field_generated_value);
                            $field_output_type = strpos($field_target, 'media:') === 0 ? 'image' : 'text';
                            $field_generated_images = $field_output_type === 'image'
                                ? $ucg_extract_preview_images($field_raw_value)
                                : array();
                            $field_current_images = $field_output_type === 'image'
                                ? $ucg_extract_preview_images($field_current_value)
                                : array();

                            $review_fields[] = array(
                                'scope' => 'static',
                                'index' => (int) $field_index,
                                'status' => $field_status,
                                'label' => $field_label,
                                'target_field' => $field_target,
                                'output_type' => $field_output_type,
                                'generated_value' => $field_generated_value,
                                'current_value' => is_string($field_current_value) ? trim($field_current_value) : '',
                                'generated_images' => $field_generated_images,
                                'current_images' => $field_current_images,
                            );
                        }

                        $generated_field_lines = array();
                        $current_field_lines = array();
                        $generated_preview_images = array();
                        $current_preview_images = array();
                        foreach ($review_fields as $review_field) {
                            if (!is_array($review_field)) {
                                continue;
                            }
                            $field_generated_images = isset($review_field['generated_images']) && is_array($review_field['generated_images'])
                                ? $review_field['generated_images']
                                : array();
                            foreach ($field_generated_images as $image_src) {
                                $image_src = trim((string) $image_src);
                                if ($image_src === '' || in_array($image_src, $generated_preview_images, true)) {
                                    continue;
                                }
                                $generated_preview_images[] = $image_src;
                                if (count($generated_preview_images) >= 8) {
                                    break;
                                }
                            }
                            $field_current_images = isset($review_field['current_images']) && is_array($review_field['current_images'])
                                ? $review_field['current_images']
                                : array();
                            foreach ($field_current_images as $image_src) {
                                $image_src = trim((string) $image_src);
                                if ($image_src === '' || in_array($image_src, $current_preview_images, true)) {
                                    continue;
                                }
                                $current_preview_images[] = $image_src;
                                if (count($current_preview_images) >= 8) {
                                    break;
                                }
                            }
                            $field_status = isset($review_field['status']) ? sanitize_key((string) $review_field['status']) : 'generated';
                            if ($field_status !== 'generated') {
                                continue;
                            }
                            $field_label = isset($review_field['label']) ? (string) $review_field['label'] : __('Поле', 'unicontent-ai-generator');
                            $generated_field_lines[] = $field_label . ': ' . (trim((string) $review_field['generated_value']) !== '' ? trim((string) $review_field['generated_value']) : '—');
                            $current_field_lines[] = $field_label . ': ' . (trim((string) $review_field['current_value']) !== '' ? trim((string) $review_field['current_value']) : '—');
                        }
                        if (empty($generated_field_lines) && !empty($review_fields)) {
                            foreach ($review_fields as $review_field) {
                                if (!is_array($review_field)) {
                                    continue;
                                }
                                $field_label = isset($review_field['label']) ? (string) $review_field['label'] : __('Поле', 'unicontent-ai-generator');
                                $generated_field_lines[] = $field_label . ': ' . (trim((string) $review_field['generated_value']) !== '' ? trim((string) $review_field['generated_value']) : '—');
                                $current_field_lines[] = $field_label . ': ' . (trim((string) $review_field['current_value']) !== '' ? trim((string) $review_field['current_value']) : '—');
                            }
                        }

                        $has_generated_fields = !empty($generated_field_lines);
                        $current_value = $has_generated_fields
                            ? implode("\n", $current_field_lines)
                            : UCG_Tokens::get_field_value_for_preview($post_id, (string) $item['target_field']);
                        $generated_value = $has_generated_fields
                            ? implode("\n", $generated_field_lines)
                            : (is_string($item['generated_text']) ? (string) $item['generated_text'] : '');
                        $current_preview = $ucg_trim_preview_text($current_value, 500);
                        $generated_preview = $ucg_trim_preview_text($generated_value, 500);
                        $review_view_id = 'ucg-review-item-' . (int) $item['id'];
                        ?>
                        <tr>
                            <td>
                                <?php if ((string) $item['status'] === 'generated') : ?>
                                    <input type="checkbox" class="ucg-review-checkbox" name="item_ids[]" value="<?php echo (int) $item['id']; ?>">
                                <?php endif; ?>
                            </td>
                            <td>#<?php echo (int) $item['id']; ?></td>
                            <td>#<?php echo (int) $item['run_id']; ?></td>
                            <td>
                                <strong>#<?php echo $post_id; ?></strong><br>
                                <?php echo esc_html((string) $item['post_title']); ?><br>
                                <code><?php echo esc_html((string) $item['target_field']); ?></code>
                            </td>
                            <td>
                                <?php if (!empty($current_preview_images)) : ?>
                                    <div class="ucg-review-image-grid">
                                        <?php foreach ($current_preview_images as $image_src) : ?>
                                            <?php
                                            $image_src = trim((string) $image_src);
                                            if ($image_src === '') {
                                                continue;
                                            }
                                            $is_data_url = strpos($image_src, 'data:image/') === 0;
                                            $safe_src = $is_data_url ? esc_attr($image_src) : esc_url($image_src);
                                            $safe_link = $is_data_url ? '#' : esc_url($image_src);
                                            ?>
                                            <a
                                                class="ucg-review-image-card"
                                                href="<?php echo $safe_link; ?>"
                                                <?php echo $is_data_url ? '' : 'target="_blank" rel="noopener noreferrer"'; ?>
                                            >
                                                <img src="<?php echo $safe_src; ?>" alt="">
                                            </a>
                                        <?php endforeach; ?>
                                    </div>
                                <?php endif; ?>
                                <div class="ucg-review-preview"><?php echo esc_html($current_preview !== '' ? $current_preview : '—'); ?></div>
                            </td>
                            <td>
                                <?php if (!empty($generated_preview_images)) : ?>
                                    <div class="ucg-review-image-grid">
                                        <?php foreach ($generated_preview_images as $image_src) : ?>
                                            <?php
                                            $image_src = trim((string) $image_src);
                                            if ($image_src === '') {
                                                continue;
                                            }
                                            $is_data_url = strpos($image_src, 'data:image/') === 0;
                                            $safe_src = $is_data_url ? esc_attr($image_src) : esc_url($image_src);
                                            $safe_link = $is_data_url ? '#' : esc_url($image_src);
                                            ?>
                                            <a
                                                class="ucg-review-image-card"
                                                href="<?php echo $safe_link; ?>"
                                                <?php echo $is_data_url ? '' : 'target="_blank" rel="noopener noreferrer"'; ?>
                                            >
                                                <img src="<?php echo $safe_src; ?>" alt="">
                                            </a>
                                        <?php endforeach; ?>
                                    </div>
                                <?php endif; ?>
                                <div class="ucg-review-preview"><?php echo esc_html($generated_preview !== '' ? $generated_preview : '—'); ?></div>
                                <?php if (!empty($review_fields)) : ?>
                                    <details class="ucg-review-fields-details">
                                        <summary>Поля: <?php echo (int) count($review_fields); ?></summary>
                                        <?php if ((string) $item['status'] === 'generated') : ?>
                                            <div class="ucg-review-field-row__actions">
                                                <button
                                                    type="submit"
                                                    class="button button-small button-primary"
                                                    name="item_action_submit"
                                                    value="<?php echo esc_attr('approve|' . (int) $item['id']); ?>"
                                                >
                                                    Одобрить всё
                                                </button>
                                                <button
                                                    type="submit"
                                                    class="button button-small"
                                                    name="item_action_submit"
                                                    value="<?php echo esc_attr('reject|' . (int) $item['id']); ?>"
                                                >
                                                    Отклонить всё
                                                </button>
                                            </div>
                                        <?php endif; ?>
                                        <div class="ucg-review-fields-list">
                                            <?php foreach ($review_fields as $review_field) : ?>
                                                <?php
                                                $field_scope = isset($review_field['scope']) ? (string) $review_field['scope'] : 'ai';
                                                $is_static_scope = $field_scope === 'static';
                                                $field_index = isset($review_field['index']) ? (int) $review_field['index'] : 0;
                                                $field_status = isset($review_field['status']) ? sanitize_key((string) $review_field['status']) : 'generated';
                                                $field_label = isset($review_field['label']) ? (string) $review_field['label'] : __('Поле', 'unicontent-ai-generator');
                                                $field_target = isset($review_field['target_field']) ? (string) $review_field['target_field'] : '';
                                                $field_output_type = isset($review_field['output_type']) ? sanitize_key((string) $review_field['output_type']) : 'text';
                                                if ($field_output_type !== 'image') {
                                                    $field_output_type = 'text';
                                                }
                                                $field_generated_images = isset($review_field['generated_images']) && is_array($review_field['generated_images'])
                                                    ? $review_field['generated_images']
                                                    : array();
                                                $field_current_images = isset($review_field['current_images']) && is_array($review_field['current_images'])
                                                    ? $review_field['current_images']
                                                    : array();
                                                $field_generated_short = $ucg_trim_preview_text(isset($review_field['generated_value']) ? (string) $review_field['generated_value'] : '', 180);
                                                $field_current_short = $ucg_trim_preview_text(isset($review_field['current_value']) ? (string) $review_field['current_value'] : '', 180);
                                                $field_status_label = $is_static_scope && $field_status === 'generated'
                                                    ? __('Будет применено', 'unicontent-ai-generator')
                                                    : $this->status_label($field_status);
                                                $field_chip_class = 'ucg-chip ucg-chip--status';
                                                if ($field_status === 'approved') {
                                                    $field_chip_class = 'ucg-chip ucg-chip--ok';
                                                } elseif ($field_status === 'rejected' || $field_status === 'failed') {
                                                    $field_chip_class = 'ucg-chip ucg-chip--bad';
                                                }
                                                ?>
                                                <div class="ucg-review-field-row">
                                                    <div class="ucg-review-field-row__head">
                                                        <strong><?php echo esc_html($field_label); ?></strong>
                                                        <code><?php echo esc_html($field_target !== '' ? $field_target : '—'); ?></code>
                                                        <span class="<?php echo esc_attr($field_chip_class); ?>"><?php echo esc_html($field_status_label); ?></span>
                                                    </div>
                                                    <div class="ucg-review-field-row__values">
                                                        <div><span>Текущее:</span> <?php echo esc_html($field_current_short !== '' ? $field_current_short : '—'); ?></div>
                                                        <div><span>Сгенерировано:</span> <?php echo esc_html($field_generated_short !== '' ? $field_generated_short : '—'); ?></div>
                                                    </div>
                                                    <?php if ($field_output_type === 'image' && (!empty($field_current_images) || !empty($field_generated_images))) : ?>
                                                        <div class="ucg-review-field-row__values">
                                                            <?php if (!empty($field_current_images)) : ?>
                                                                <div>
                                                                    <span>Текущее изображение:</span>
                                                                    <div class="ucg-review-image-grid">
                                                                        <?php foreach ($field_current_images as $image_src) : ?>
                                                                            <?php
                                                                            $image_src = trim((string) $image_src);
                                                                            if ($image_src === '') {
                                                                                continue;
                                                                            }
                                                                            $is_data_url = strpos($image_src, 'data:image/') === 0;
                                                                            $safe_src = $is_data_url ? esc_attr($image_src) : esc_url($image_src);
                                                                            $safe_link = $is_data_url ? '#' : esc_url($image_src);
                                                                            ?>
                                                                            <a
                                                                                class="ucg-review-image-card"
                                                                                href="<?php echo $safe_link; ?>"
                                                                                <?php echo $is_data_url ? '' : 'target="_blank" rel="noopener noreferrer"'; ?>
                                                                            >
                                                                                <img src="<?php echo $safe_src; ?>" alt="">
                                                                            </a>
                                                                        <?php endforeach; ?>
                                                                    </div>
                                                                </div>
                                                            <?php endif; ?>
                                                            <?php if (!empty($field_generated_images)) : ?>
                                                                <div>
                                                                    <span>Сгенерированные изображения:</span>
                                                                    <div class="ucg-review-image-grid">
                                                                        <?php foreach ($field_generated_images as $image_src) : ?>
                                                                            <?php
                                                                            $image_src = trim((string) $image_src);
                                                                            if ($image_src === '') {
                                                                                continue;
                                                                            }
                                                                            $is_data_url = strpos($image_src, 'data:image/') === 0;
                                                                            $safe_src = $is_data_url ? esc_attr($image_src) : esc_url($image_src);
                                                                            $safe_link = $is_data_url ? '#' : esc_url($image_src);
                                                                            ?>
                                                                            <a
                                                                                class="ucg-review-image-card"
                                                                                href="<?php echo $safe_link; ?>"
                                                                                <?php echo $is_data_url ? '' : 'target="_blank" rel="noopener noreferrer"'; ?>
                                                                            >
                                                                                <img src="<?php echo $safe_src; ?>" alt="">
                                                                            </a>
                                                                        <?php endforeach; ?>
                                                                    </div>
                                                                </div>
                                                            <?php endif; ?>
                                                        </div>
                                                    <?php endif; ?>
                                                    <?php if ($is_static_scope && $field_status === 'generated') : ?>
                                                        <div class="ucg-review-field-row__values">
                                                            <div><span>Статическое поле:</span> <?php echo esc_html__('будет применено при одобрении элемента', 'unicontent-ai-generator'); ?></div>
                                                        </div>
                                                    <?php endif; ?>
                                                    <?php if ((string) $item['status'] === 'generated' && $field_status === 'generated' && !$is_static_scope) : ?>
                                                        <div class="ucg-review-field-row__actions">
                                                            <button
                                                                type="submit"
                                                                class="button button-small"
                                                                name="field_action_submit"
                                                                value="<?php echo esc_attr('approve|' . (int) $item['id'] . '|' . $field_scope . '|' . $field_index); ?>"
                                                            >
                                                                Одобрить поле
                                                            </button>
                                                            <button
                                                                type="submit"
                                                                class="button button-small"
                                                                name="field_action_submit"
                                                                value="<?php echo esc_attr('reject|' . (int) $item['id'] . '|' . $field_scope . '|' . $field_index); ?>"
                                                            >
                                                                Отклонить поле
                                                            </button>
                                                        </div>
                                                    <?php endif; ?>
                                                </div>
                                            <?php endforeach; ?>
                                        </div>
                                    </details>
                                <?php endif; ?>
                            </td>
                            <td>
                                <button
                                    type="button"
                                    class="button ucg-review-view-btn"
                                    data-view-id="<?php echo esc_attr($review_view_id); ?>"
                                >
                                    Просмотр
                                </button>

                                <div id="<?php echo esc_attr($review_view_id); ?>" class="ucg-review-source" hidden>
                                    <div class="ucg-review-source-current"><?php echo esc_html((string) $current_value); ?></div>
                                    <div class="ucg-review-source-generated"><?php echo esc_html((string) $generated_value); ?></div>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>

            <div class="ucg-actions-row ucg-review-bulk-bar">
                <select name="bulk_action" class="ucg-enhanced-select ucg-review-bulk-select" data-search-enabled="false">
                    <option value="">Выберите действие</option>
                    <option value="approve">Одобрить</option>
                    <option value="reject">Отклонить</option>
                </select>
                <button type="submit" class="button button-primary">Применить</button>
            </div>

            <?php if ($total_pages > 1) : ?>
                <div class="tablenav ucg-review-pagination">
                    <div class="tablenav-pages">
                        <?php
                        echo wp_kses_post(
                            paginate_links(array(
                                'base' => add_query_arg(array('paged' => '%#%')),
                                'format' => '',
                                'current' => $paged,
                                'total' => $total_pages,
                                'prev_text' => '&laquo;',
                                'next_text' => '&raquo;',
                            ))
                        );
                        ?>
                    </div>
                </div>
            <?php endif; ?>
        </section>
    </form>

    <div class="ucg-review-modal" id="ucg-review-modal" hidden>
        <div class="ucg-review-modal__backdrop" data-close-review-modal></div>
        <div class="ucg-review-modal__dialog" role="dialog" aria-modal="true" aria-labelledby="ucg-review-modal-title">
            <div class="ucg-review-modal__head">
                <h3 id="ucg-review-modal-title">Просмотр результата</h3>
                <button type="button" class="button ucg-btn--ghost" data-close-review-modal>Закрыть</button>
            </div>

            <div class="ucg-review-tabs">
                <button type="button" class="ucg-review-tab is-active" data-review-tab="generated">Сгенерированный текст</button>
                <button type="button" class="ucg-review-tab" data-review-tab="current">Текущее значение</button>
            </div>

            <div class="ucg-review-modal__body">
                <div class="ucg-review-pane is-active" data-review-pane="generated">
                    <div class="ucg-review-content" id="ucg-review-generated-content"></div>
                </div>
                <div class="ucg-review-pane" data-review-pane="current">
                    <div class="ucg-review-content" id="ucg-review-current-content"></div>
                </div>
            </div>
        </div>
    </div>
</div>
