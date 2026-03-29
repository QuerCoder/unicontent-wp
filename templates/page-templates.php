<div class="wrap ucg-wrap">
    <?php $this->render_admin_notice(); ?>
    <?php include UCG_PLUGIN_DIR . 'templates/partials/plugin-header.php'; ?>

    <div class="ucg-page-head">
        <div class="ucg-page-head__meta">
            <h1>Шаблоны промптов</h1>
            <p class="ucg-muted">Гибкий редактор: базовая инструкция + любое количество блоков промпта для разных сценариев.</p>
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
                    <span>Сценарий</span>
                    <select name="scenario" id="ucg-template-scenario" class="ucg-enhanced-select" data-search-enabled="false" data-placeholder="Выберите сценарий">
                        <?php
                        $template_scenario_options_safe = isset($template_scenario_options) && is_array($template_scenario_options)
                            ? $template_scenario_options
                            : array();
                        $editing_template_scenario_safe = isset($editing_template_scenario) ? (string) $editing_template_scenario : 'field_update';
                        foreach ($template_scenario_options_safe as $scenario_item) :
                            $scenario_value = isset($scenario_item['value']) ? sanitize_key((string) $scenario_item['value']) : '';
                            if ($scenario_value === '') {
                                continue;
                            }
                            $scenario_label = isset($scenario_item['label']) ? (string) $scenario_item['label'] : $scenario_value;
                            $scenario_available = !array_key_exists('is_available', $scenario_item) || !empty($scenario_item['is_available']);
                            $scenario_selected = $editing_template_scenario_safe === $scenario_value;
                            ?>
                            <option
                                value="<?php echo esc_attr($scenario_value); ?>"
                                <?php selected($scenario_selected); ?>
                                <?php disabled(!$scenario_available && !$scenario_selected); ?>
                            >
                                <?php
                                $label_text = $scenario_label;
                                if (!$scenario_available && !$scenario_selected) {
                                    $label_text .= ' (недоступно)';
                                }
                                echo esc_html($label_text);
                                ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </label>

                <label class="ucg-field">
                    <span>Базовый промпт (опционально)</span>
                    <textarea name="base_prompt" id="ucg-template-body" rows="5"><?php echo esc_textarea(isset($editing_base_prompt) ? (string) $editing_base_prompt : ''); ?></textarea>
                    <p class="ucg-muted ucg-field-hint">Добавляется перед каждым блоком. Удобно для общих требований стиля, языка и ограничений.</p>
                </label>

                <div class="ucg-field">
                    <div class="ucg-template-blocks-head">
                        <span>Блоки промптов</span>
                        <button type="button" class="button button-small" id="ucg-add-prompt-block">+ Добавить блок</button>
                    </div>
                    <div id="ucg-template-block-rows" class="ucg-template-block-rows">
                        <?php
                        $template_blocks_safe = isset($editing_prompt_blocks) && is_array($editing_prompt_blocks) ? $editing_prompt_blocks : array();
                        if (empty($template_blocks_safe)) {
                            $template_blocks_safe[] = array(
                                'id' => 'main',
                                'label' => 'Основной промпт',
                                'prompt' => '',
                            );
                        }
                        foreach ($template_blocks_safe as $index => $template_block) :
                            $block_key = isset($template_block['id']) ? (string) $template_block['id'] : ('block_' . ((int) $index + 1));
                            $block_label = isset($template_block['label']) ? (string) $template_block['label'] : '';
                            $block_prompt = isset($template_block['prompt']) ? (string) $template_block['prompt'] : '';
                            ?>
                            <div class="ucg-template-block-row" data-index="<?php echo (int) $index; ?>">
                                <div class="ucg-grid-2">
                                    <label class="ucg-field">
                                        <span>Ключ блока</span>
                                        <input type="text" name="prompt_blocks_key[]" value="<?php echo esc_attr($block_key); ?>" placeholder="main / seo_title / excerpt">
                                    </label>
                                    <label class="ucg-field">
                                        <span>Название блока</span>
                                        <input type="text" name="prompt_blocks_label[]" value="<?php echo esc_attr($block_label); ?>" placeholder="Например: SEO title">
                                    </label>
                                </div>
                                <label class="ucg-field">
                                    <span>Промпт блока</span>
                                    <textarea name="prompt_blocks_prompt[]" class="ucg-template-block-input" rows="6" placeholder="Текст промпта для этого блока"><?php echo esc_textarea($block_prompt); ?></textarea>
                                </label>
                                <div class="ucg-template-block-actions">
                                    <button type="button" class="button button-small ucg-remove-prompt-block">Удалить блок</button>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    <p class="ucg-muted ucg-field-hint">Для сценария “Поля” обычно достаточно 1 блока. Для SEO — минимум 2 блока (title/description).</p>
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
                        <th>Сценарий</th>
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
                            <td><code><?php echo esc_html(isset($template['scenario']) ? (string) $template['scenario'] : 'field_update'); ?></code></td>
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
