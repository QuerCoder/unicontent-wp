<div class="wrap ucg-wrap">
    <?php $this->render_admin_notice(); ?>
    <?php include UCG_PLUGIN_DIR . 'templates/partials/plugin-header.php'; ?>
    <?php
    $editing_length_option_id = $editing_template && isset($editing_template['length_option_id'])
        ? (int) $editing_template['length_option_id']
        : (int) $default_length_option_id;
    $editing_vary_length = $editing_template ? !empty($editing_template['vary_length']) : false;
    ?>

    <div class="ucg-page-head">
        <div class="ucg-page-head__meta">
            <h1>Шаблоны промптов</h1>
            <p class="ucg-muted">Собирайте промпт из токенов: название, контент, метаполя, ACF и WooCommerce.</p>
        </div>
        <div class="ucg-page-head__actions">
            <a class="button button-small ucg-btn--secondary" href="<?php echo esc_url(admin_url('admin.php?page=ucg-ready-templates&post_type=' . $selected_post_type)); ?>">
                Каталог готовых шаблонов
            </a>
        </div>
    </div>

    <div class="ucg-layout-2">
        <section class="ucg-card">
            <h2><?php echo $editing_template ? 'Редактировать шаблон' : 'Новый шаблон'; ?></h2>

            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="ucg-form-grid">
                <?php wp_nonce_field('ucg_save_template'); ?>
                <input type="hidden" name="action" value="ucg_save_template">
                <input type="hidden" name="template_id" value="<?php echo $editing_template ? (int) $editing_template['id'] : 0; ?>">

                <label class="ucg-field">
                    <span>Название</span>
                    <input type="text" name="name" value="<?php echo esc_attr($editing_template ? (string) $editing_template['name'] : ''); ?>" required>
                </label>

                <label class="ucg-field">
                    <span>Post type</span>
                    <select name="post_type" id="ucg-template-post-type" class="ucg-enhanced-select" data-search-enabled="false" data-placeholder="Выберите тип">
                        <?php foreach ($post_types as $post_type_item) : ?>
                            <option value="<?php echo esc_attr((string) $post_type_item['value']); ?>" <?php selected($selected_post_type, (string) $post_type_item['value']); ?>>
                                <?php echo esc_html((string) $post_type_item['label']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </label>

                <label class="ucg-field">
                    <span>Текст шаблона</span>
                    <textarea name="body" id="ucg-template-body" rows="12" required><?php echo esc_textarea($editing_template ? (string) $editing_template['body'] : ''); ?></textarea>
                </label>

                <label class="ucg-field">
                    <span>Длина текста</span>
                    <select name="length_option_id" class="ucg-enhanced-select" data-search-enabled="false" required>
                        <?php if (!empty($text_length_options) && is_array($text_length_options)) : ?>
                            <?php foreach ($text_length_options as $length_option_item) : ?>
                                <?php
                                $length_option_item_id = isset($length_option_item['id']) ? (int) $length_option_item['id'] : 0;
                                $length_option_item_name = isset($length_option_item['name']) ? (string) $length_option_item['name'] : '';
                                $length_option_item_max_chars = isset($length_option_item['max_chars']) ? (int) $length_option_item['max_chars'] : 0;
                                $length_option_item_credits = isset($length_option_item['credits_cost']) ? (float) $length_option_item['credits_cost'] : 0.0;
                                $length_option_item_credits_label = rtrim(rtrim(number_format($length_option_item_credits, 2, '.', ''), '0'), '.');
                                if ($length_option_item_id <= 0 || $length_option_item_name === '') {
                                    continue;
                                }
                                ?>
                                <option value="<?php echo (int) $length_option_item_id; ?>" <?php selected($editing_length_option_id, $length_option_item_id); ?>>
                                    <?php echo esc_html($length_option_item_name . ' — до ' . number_format_i18n($length_option_item_max_chars) . ' символов / ' . $length_option_item_credits_label . ' кр.'); ?>
                                </option>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </select>
                </label>

                <div class="ucg-vary-length-block">
                    <label class="ucg-checkbox">
                        <input type="checkbox" name="vary_length" value="1" <?php checked($editing_vary_length); ?>>
                        <span>Варьировать длину текста</span>
                    </label>
                    <?php if (!empty($text_length_hint)) : ?>
                        <p class="ucg-muted ucg-field-hint"><?php echo esc_html((string) $text_length_hint); ?></p>
                    <?php endif; ?>
                </div>

                <label class="ucg-checkbox">
                    <input type="checkbox" name="is_default" value="1" <?php checked($editing_template && !empty($editing_template['is_default'])); ?>>
                    <span>Сделать шаблоном по умолчанию для выбранного post type</span>
                </label>

                <div class="ucg-actions-row">
                    <button type="submit" class="button button-primary"><?php echo $editing_template ? 'Сохранить' : 'Создать'; ?></button>
                    <?php if ($editing_template) : ?>
                        <a class="button" href="<?php echo esc_url(admin_url('admin.php?page=ucg-templates')); ?>">Отменить редактирование</a>
                    <?php endif; ?>
                </div>
            </form>

            <div class="ucg-token-panel">
                <div class="ucg-token-panel__head">
                    <h3>Токены</h3>
                    <span>Клик или drag-and-drop в поле шаблона</span>
                </div>
                <div class="ucg-token-grid" id="ucg-template-tokens" data-post-type="<?php echo esc_attr($selected_post_type); ?>">
                    <?php foreach ($tokens as $token_item) : ?>
                        <button type="button" class="ucg-token-btn" draggable="true" data-token="<?php echo esc_attr((string) $token_item['token']); ?>" title="<?php echo esc_attr((string) $token_item['label']); ?>">
                            <?php echo esc_html((string) $token_item['token']); ?>
                        </button>
                    <?php endforeach; ?>
                </div>
            </div>
        </section>

        <section class="ucg-card">
            <h2>Список шаблонов</h2>
            <?php if (empty($templates)) : ?>
                <p class="ucg-muted">Шаблонов пока нет.</p>
            <?php else : ?>
                <table class="widefat striped">
                    <thead>
                    <tr>
                        <th>ID</th>
                        <th>Название</th>
                        <th>Post type</th>
                        <th>По умолчанию</th>
                        <th style="width: 220px;">Действия</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($templates as $template) : ?>
                        <tr>
                            <td>#<?php echo (int) $template['id']; ?></td>
                            <td><?php echo esc_html((string) $template['name']); ?></td>
                            <td><code><?php echo esc_html((string) $template['post_type']); ?></code></td>
                            <td><?php echo !empty($template['is_default']) ? '<span class="ucg-chip ucg-chip--ok">Да</span>' : '—'; ?></td>
                            <td class="ucg-inline-actions">
                                <a class="button button-small" href="<?php echo esc_url(admin_url('admin.php?page=ucg-templates&edit=' . (int) $template['id'])); ?>">Редактировать</a>
                                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" onsubmit="return confirm('Удалить шаблон?');">
                                    <?php wp_nonce_field('ucg_delete_template'); ?>
                                    <input type="hidden" name="action" value="ucg_delete_template">
                                    <input type="hidden" name="template_id" value="<?php echo (int) $template['id']; ?>">
                                    <button type="submit" class="button button-small">Удалить</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </section>
    </div>

</div>
