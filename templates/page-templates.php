<div class="wrap ucg-wrap ucg-flat-ui">
    <?php $this->render_admin_notice(); ?>
    <?php include UCG_PLUGIN_DIR . 'templates/partials/plugin-header.php'; ?>

    <div class="ucg-page-head">
        <div class="ucg-page-head__meta">
            <h1>Шаблоны промптов</h1>
            <p class="ucg-muted">
                Управление шаблонами перенесено в раздел «Генерация»: там же создание, редактирование и запуск.
            </p>
        </div>
        <div class="ucg-page-head__actions">
            <a class="button button-small" href="<?php echo esc_url(admin_url('admin.php?page=ucg-generate&post_type=' . $selected_post_type)); ?>">
                Открыть генерацию
            </a>
            <a class="button button-small ucg-btn--secondary" href="<?php echo esc_url(admin_url('admin.php?page=ucg-ready-templates&post_type=' . $selected_post_type)); ?>">
                Каталог готовых шаблонов
            </a>
            <?php if ($show_legacy_template_editor) : ?>
                <a class="button button-small" href="<?php echo esc_url(admin_url('admin.php?page=ucg-templates&post_type=' . $selected_post_type)); ?>">
                    Скрыть legacy-редактор
                </a>
            <?php else : ?>
                <a class="button button-small" href="<?php echo esc_url(admin_url('admin.php?page=ucg-templates&post_type=' . $selected_post_type . '&legacy_editor=1')); ?>">
                    Открыть legacy-редактор
                </a>
            <?php endif; ?>
        </div>
    </div>

    <?php
    $template_scenario_options_safe = isset($template_scenario_options) && is_array($template_scenario_options)
        ? $template_scenario_options
        : array();
    $editing_template_scenario_safe = isset($editing_template_scenario) ? (string) $editing_template_scenario : 'field_update';
    $template_blocks_safe = isset($editing_prompt_blocks) && is_array($editing_prompt_blocks) ? $editing_prompt_blocks : array();
    if (empty($template_blocks_safe)) {
        $template_blocks_safe[] = array(
            'id' => 'main',
            'label' => 'Основной промпт',
            'prompt' => '',
        );
    }
    $template_length_options_safe = isset($template_text_length_options) && is_array($template_text_length_options)
        ? $template_text_length_options
        : array();
    $template_default_length_option_id_safe = isset($template_default_length_option_id)
        ? (int) $template_default_length_option_id
        : 0;
    $template_field_editor_presets_safe = isset($template_field_editor_presets) && is_array($template_field_editor_presets)
        ? $template_field_editor_presets
        : array();
    $template_fields_safe = isset($editing_template_fields) && is_array($editing_template_fields) ? $editing_template_fields : array();
    if (empty($template_fields_safe) && isset($template_field_editor_presets_safe[$editing_template_scenario_safe]) && is_array($template_field_editor_presets_safe[$editing_template_scenario_safe])) {
        $template_fields_safe = $template_field_editor_presets_safe[$editing_template_scenario_safe];
    }
    $template_simple_prompt_safe = '';
    if (!empty($template_blocks_safe[0]['prompt'])) {
        $template_simple_prompt_safe = (string) $template_blocks_safe[0]['prompt'];
    }
    ?>

    <?php if ($show_legacy_template_editor) : ?>
    <div class="ucg-layout-2">
        <section class="ucg-card">
            <h2><?php echo $editing_template ? 'Редактировать шаблон' : 'Новый шаблон'; ?></h2>

            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="ucg-form-grid">
                <?php wp_nonce_field('ucg_save_template'); ?>
                <input type="hidden" name="action" value="ucg_save_template">
                <input type="hidden" name="template_id" value="<?php echo $editing_template ? (int) $editing_template['id'] : 0; ?>">
                <input type="hidden" name="template_fields_json" id="ucg-template-fields-json" value="">

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
                        <?php foreach ($template_scenario_options_safe as $scenario_item) :
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
                    <p class="ucg-muted ucg-field-hint">Добавляется перед каждым полем/блоком. Удобно для общих требований стиля, языка и ограничений.</p>
                </label>

                <div class="ucg-field" id="ucg-template-fields-editor">
                    <div class="ucg-template-blocks-head">
                        <span>Поля шаблона</span>
                        <button type="button" class="button button-small" id="ucg-add-template-field">+ Добавить поле</button>
                    </div>
                    <div id="ucg-template-field-rows" class="ucg-template-block-rows">
                        <?php foreach ($template_fields_safe as $field_index => $template_field) : ?>
                            <?php
                            $field_key = isset($template_field['key']) ? sanitize_key((string) $template_field['key']) : ('field_' . ((int) $field_index + 1));
                            if ($field_key === '') {
                                $field_key = 'field_' . ((int) $field_index + 1);
                            }
                            $field_label = isset($template_field['label']) ? (string) $template_field['label'] : '';
                            $field_target = isset($template_field['target_field']) ? (string) $template_field['target_field'] : '';
                            $field_prompt = isset($template_field['prompt']) ? (string) $template_field['prompt'] : '';
                            $field_enabled = !array_key_exists('enabled', $template_field) || !empty($template_field['enabled']);
                            $field_length_option_id = isset($template_field['length_option_id']) ? (int) $template_field['length_option_id'] : 0;
                            if ($field_length_option_id <= 0) {
                                $field_length_option_id = $template_default_length_option_id_safe;
                            }
                            $field_max_chars = isset($template_field['max_chars']) ? max(0, (int) $template_field['max_chars']) : 0;
                            ?>
                            <div
                                class="ucg-template-field-row ucg-template-block-row"
                                data-index="<?php echo (int) $field_index; ?>"
                            >
                                <label class="ucg-field">
                                    <span>Название поля</span>
                                    <input type="text" class="ucg-template-field-label" value="<?php echo esc_attr($field_label); ?>" placeholder="Например: Заголовок">
                                </label>
                                <div class="ucg-grid-3 ucg-template-field-row__meta-grid">
                                    <label class="ucg-field">
                                        <span>Длина</span>
                                        <select class="ucg-template-field-length ucg-enhanced-select" data-search-enabled="false" data-placeholder="По умолчанию">
                                            <option value="0">По умолчанию</option>
                                            <?php foreach ($template_length_options_safe as $length_option) : ?>
                                                <?php
                                                $length_id = isset($length_option['id']) ? (int) $length_option['id'] : 0;
                                                if ($length_id <= 0) {
                                                    continue;
                                                }
                                                $length_name = isset($length_option['name']) ? (string) $length_option['name'] : ('#' . $length_id);
                                                $length_credits = isset($length_option['credits_cost']) ? (float) $length_option['credits_cost'] : 0.0;
                                                $length_label = $length_name;
                                                if ($length_credits > 0) {
                                                    $length_label .= ' (' . rtrim(rtrim((string) $length_credits, '0'), '.') . ' кр.)';
                                                }
                                                ?>
                                                <option value="<?php echo (int) $length_id; ?>" <?php selected($field_length_option_id, $length_id); ?>>
                                                    <?php echo esc_html($length_label); ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </label>
                                    <label class="ucg-field">
                                        <span>Макс. символов (опц.)</span>
                                        <input type="number" min="0" step="1" class="ucg-template-field-max-chars" value="<?php echo (int) $field_max_chars; ?>" placeholder="0">
                                    </label>
                                    <label class="ucg-checkbox ucg-template-field-enabled-wrap">
                                        <input type="checkbox" class="ucg-template-field-enabled" <?php checked($field_enabled); ?>>
                                        <span>Поле включено</span>
                                    </label>
                                </div>
                                <details class="ucg-template-field-advanced">
                                    <summary>Расширенные настройки</summary>
                                    <div class="ucg-template-field-advanced__grid">
                                        <label class="ucg-field">
                                            <span>Ключ поля</span>
                                            <input type="text" class="ucg-template-field-key" value="<?php echo esc_attr($field_key); ?>" placeholder="post_title / seo_title / custom">
                                        </label>
                                        <label class="ucg-field">
                                            <span>Целевое поле</span>
                                            <input type="text" class="ucg-template-field-target" value="<?php echo esc_attr($field_target); ?>" placeholder="post:post_title / seo_field:title">
                                        </label>
                                    </div>
                                </details>
                                <label class="ucg-field">
                                    <span>Промпт поля</span>
                                    <textarea class="ucg-template-field-prompt ucg-template-block-input" rows="6" placeholder="Текст промпта для поля"><?php echo esc_textarea($field_prompt); ?></textarea>
                                </label>
                                <div class="ucg-template-block-actions">
                                    <button type="button" class="button button-small ucg-remove-template-field">Удалить поле</button>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    <p class="ucg-muted ucg-field-hint">Для сценариев «SEO-теги», «Записи» и «Товары» настройте нужные поля и их промпты.</p>
                </div>

                <div class="ucg-field" id="ucg-template-simple-editor">
                    <div class="ucg-template-blocks-head">
                        <span>Промпт</span>
                    </div>
                    <label class="ucg-field">
                        <span>Основной промпт</span>
                        <textarea id="ucg-template-simple-prompt" rows="8" class="ucg-template-block-input" placeholder="Текст основного промпта"><?php echo esc_textarea($template_simple_prompt_safe); ?></textarea>
                    </label>
                    <p class="ucg-muted ucg-field-hint">Используется для сценариев с одним текстовым результатом (например, комментарии и отзывы).</p>
                </div>

                <div class="ucg-field" id="ucg-template-block-editor">
                    <div class="ucg-template-blocks-head">
                        <span>Блоки промптов (расширенный режим)</span>
                        <button type="button" class="button button-small" id="ucg-add-prompt-block">+ Добавить блок</button>
                    </div>
                    <div id="ucg-template-block-rows" class="ucg-template-block-rows">
                        <?php foreach ($template_blocks_safe as $index => $template_block) :
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
                    <p class="ucg-muted ucg-field-hint">Доступно в сценарии «Поля» для сложных шаблонов из нескольких блоков.</p>
                </div>

                <label class="ucg-checkbox">
                    <input type="checkbox" name="is_default" value="1" <?php checked($editing_template && !empty($editing_template['is_default'])); ?>>
                    <span>Сделать шаблоном по умолчанию для выбранного post type</span>
                </label>

                <div class="ucg-actions-row">
                    <button type="submit" class="button button-primary"><?php echo $editing_template ? 'Сохранить' : 'Создать'; ?></button>
                    <?php if ($editing_template) : ?>
                        <a class="button" href="<?php echo esc_url(admin_url('admin.php?page=ucg-templates&post_type=' . $selected_post_type . '&legacy_editor=1')); ?>">Отменить редактирование</a>
                    <?php endif; ?>
                </div>
            </form>

            <script type="application/json" id="ucg-template-fields-data"><?php echo wp_json_encode(
                array(
                    'presets' => $template_field_editor_presets_safe,
                    'length_options' => $template_length_options_safe,
                    'default_length_option_id' => $template_default_length_option_id_safe,
                ),
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
            ); ?></script>

            <div class="ucg-token-panel">
                <div class="ucg-token-panel__head">
                    <div class="ucg-token-panel__title">
                        <h3>Переменные</h3>
                        <button
                            type="button"
                            class="ucg-help-tip"
                            aria-label="Подсказка по переменным"
                            data-tip="Кликните или перетащите переменную в поле шаблона."
                        >
                            <span class="dashicons dashicons-editor-help" aria-hidden="true"></span>
                        </button>
                    </div>
                    <label class="ucg-token-search">
                        <span class="dashicons dashicons-search" aria-hidden="true"></span>
                        <input type="search" id="ucg-template-token-search" placeholder="Поиск переменной...">
                    </label>
                </div>
                <div class="ucg-token-groups" id="ucg-template-tokens" data-post-type="<?php echo esc_attr($selected_post_type); ?>">
                    <?php foreach ($tokens as $token_item) : ?>
                        <button type="button" class="ucg-token-btn" draggable="true" data-token="<?php echo esc_attr((string) $token_item['token']); ?>" title="<?php echo esc_attr((string) $token_item['label']); ?>">
                            <span class="ucg-token-btn__text"><?php echo esc_html((string) $token_item['token']); ?></span>
                        </button>
                    <?php endforeach; ?>
                </div>
            </div>
        </section>
    <?php endif; ?>

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
                        <?php
                        $template_id = (int) $template['id'];
                        $template_post_type = isset($template['post_type']) ? sanitize_key((string) $template['post_type']) : '';
                        if ($template_post_type === '') {
                            $template_post_type = $selected_post_type;
                        }
                        $template_scenario = isset($template['scenario']) ? sanitize_key((string) $template['scenario']) : 'field_update';
                        if ($template_scenario === '') {
                            $template_scenario = 'field_update';
                        }
                        $open_in_generate_url = add_query_arg(
                            array(
                                'page' => 'ucg-generate',
                                'template_id' => $template_id,
                                'post_type' => $template_post_type,
                                'scenario' => $template_scenario,
                            ),
                            admin_url('admin.php')
                        );
                        $legacy_edit_url = add_query_arg(
                            array(
                                'page' => 'ucg-templates',
                                'post_type' => $template_post_type,
                                'legacy_editor' => 1,
                                'edit' => $template_id,
                            ),
                            admin_url('admin.php')
                        );
                        ?>
                        <tr>
                            <td>#<?php echo $template_id; ?></td>
                            <td><?php echo esc_html((string) $template['name']); ?></td>
                            <td><code><?php echo esc_html(isset($template['scenario']) ? (string) $template['scenario'] : 'field_update'); ?></code></td>
                            <td><code><?php echo esc_html((string) $template['post_type']); ?></code></td>
                            <td><?php echo !empty($template['is_default']) ? '<span class="ucg-chip ucg-chip--ok">Да</span>' : '—'; ?></td>
                            <td class="ucg-inline-actions">
                                <a class="button button-small" href="<?php echo esc_url($open_in_generate_url); ?>">Открыть в генерации</a>
                                <?php if ($show_legacy_template_editor) : ?>
                                    <a class="button button-small ucg-btn--secondary" href="<?php echo esc_url($legacy_edit_url); ?>">Редактировать (legacy)</a>
                                <?php endif; ?>
                                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" onsubmit="return confirm('Удалить шаблон?');">
                                    <?php wp_nonce_field('ucg_delete_template'); ?>
                                    <input type="hidden" name="action" value="ucg_delete_template">
                                    <input type="hidden" name="template_id" value="<?php echo $template_id; ?>">
                                    <button type="submit" class="button button-small">Удалить</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </section>

    <?php if ($show_legacy_template_editor) : ?>
    </div>
    <?php endif; ?>

</div>
