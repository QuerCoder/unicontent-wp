<div class="wrap ucg-wrap">
    <?php $this->render_admin_notice(); ?>
    <?php include UCG_PLUGIN_DIR . 'templates/partials/plugin-header.php'; ?>

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
