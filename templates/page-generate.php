<div class="wrap ucg-wrap">
    <?php $this->render_admin_notice(); ?>
    <?php include UCG_PLUGIN_DIR . 'templates/partials/plugin-header.php'; ?>

    <h1>Генерация контента</h1>

    <?php if (!$api_ready) : ?>
        <section class="ucg-card">
            <h2>Нужен API ключ</h2>
            <p class="ucg-muted">Перед использованием мастера добавьте и проверьте API ключ на дашборде.</p>
            <p>
                <a href="<?php echo esc_url(admin_url('admin.php?page=ucg-dashboard')); ?>" class="button button-primary">Перейти на дашборд</a>
            </p>
        </section>
    <?php else : ?>
        <div class="ucg-card ucg-wizard" id="ucg-wizard">
            <div class="ucg-stepper">
                <button type="button" class="ucg-stepper__item is-active" data-step-target="1">
                    <span class="ucg-stepper__num">1</span>
                    <span class="ucg-stepper__label">Сценарий</span>
                </button>
                <button type="button" class="ucg-stepper__item" data-step-target="2">
                    <span class="ucg-stepper__num">2</span>
                    <span class="ucg-stepper__label">Фильтрация</span>
                </button>
                <button type="button" class="ucg-stepper__item" data-step-target="3">
                    <span class="ucg-stepper__num">3</span>
                    <span class="ucg-stepper__label">Запуск</span>
                </button>
            </div>

            <section class="ucg-step-panel is-active" data-step="1">
                <label class="ucg-field">
                    <span>Что генерировать:</span>
                    <div class="ucg-scenario-picker" id="ucg-wizard-scenario-picker">
                        <?php if (!empty($scenario_options) && is_array($scenario_options)) : ?>
                            <?php foreach ($scenario_options as $scenario_item) : ?>
                                <?php
                                $scenario_value = isset($scenario_item['value']) ? sanitize_key((string) $scenario_item['value']) : '';
                                $scenario_label = isset($scenario_item['label']) ? (string) $scenario_item['label'] : $scenario_value;
                                $scenario_icon = isset($scenario_item['icon']) ? (string) $scenario_item['icon'] : 'dashicons-admin-generic';
                                $scenario_description = isset($scenario_item['description']) ? trim((string) $scenario_item['description']) : '';
                                $scenario_available = !empty($scenario_item['is_available']);
                                if ($scenario_value === '') {
                                    continue;
                                }
                                ?>
                                <label class="ucg-scenario-card<?php echo $scenario_available ? '' : ' is-disabled'; ?>">
                                    <input
                                        class="ucg-scenario-card__input"
                                        type="radio"
                                        name="ucg-wizard-scenario"
                                        value="<?php echo esc_attr($scenario_value); ?>"
                                        <?php checked((string) $scenario, $scenario_value); ?>
                                        <?php disabled(!$scenario_available); ?>
                                    >
                                    <span class="ucg-scenario-card__surface">
                                        <span class="ucg-scenario-card__icon dashicons <?php echo esc_attr($scenario_icon); ?>" aria-hidden="true"></span>
                                        <span class="ucg-scenario-card__content">
                                            <span class="ucg-scenario-card__label"><?php echo esc_html($scenario_label); ?></span>
                                            <?php if ($scenario_description !== '') : ?>
                                                <span class="ucg-scenario-card__desc"><?php echo esc_html($scenario_description); ?></span>
                                            <?php endif; ?>
                                        </span>
                                        <?php if (!$scenario_available) : ?>
                                            <span class="ucg-scenario-card__meta">Скоро</span>
                                        <?php endif; ?>
                                    </span>
                                </label>
                            <?php endforeach; ?>
                        <?php else : ?>
                            <label class="ucg-scenario-card">
                                <input class="ucg-scenario-card__input" type="radio" name="ucg-wizard-scenario" value="field_update" checked>
                                <span class="ucg-scenario-card__surface">
                                    <span class="ucg-scenario-card__icon dashicons dashicons-edit-page" aria-hidden="true"></span>
                                    <span class="ucg-scenario-card__content">
                                        <span class="ucg-scenario-card__label">Поля</span>
                                    </span>
                                </span>
                            </label>
                        <?php endif; ?>
                    </div>
                </label>

                <div class="ucg-grid-3">
                    <label class="ucg-field">
                        <span>Тип записей</span>
                        <select id="ucg-wizard-post-type" class="ucg-enhanced-select" data-search-enabled="false" data-placeholder="Выберите тип">
                            <?php foreach ($post_types as $post_type_item) : ?>
                                <option value="<?php echo esc_attr((string) $post_type_item['value']); ?>" <?php selected($post_type, (string) $post_type_item['value']); ?>>
                                    <?php echo esc_html((string) $post_type_item['label']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </label>

                    <label class="ucg-field">
                        <span id="ucg-wizard-target-field-label"><?php echo esc_html(isset($target_field_label) ? $target_field_label : 'Целевое поле'); ?></span>
                        <select
                            id="ucg-wizard-target-field"
                            class="ucg-enhanced-select"
                            data-search-enabled="false"
                            data-placeholder="Выберите поле"
                        >
                            <option value="">Выберите поле</option>
                            <?php if (!empty($target_fields) && is_array($target_fields)) : ?>
                                <?php foreach ($target_fields as $field_item) : ?>
                                    <?php
                                    $field_value = isset($field_item['value']) ? (string) $field_item['value'] : '';
                                    $field_label = isset($field_item['label']) ? (string) $field_item['label'] : $field_value;
                                    if ($field_value === '') {
                                        continue;
                                    }
                                    ?>
                                    <option value="<?php echo esc_attr($field_value); ?>"><?php echo esc_html($field_label); ?></option>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </select>
                    </label>

                </div>

                <div class="ucg-step-footer">
                    <button type="button" class="button button-primary" id="ucg-step-1-next">Далее</button>
                </div>
            </section>

            <section class="ucg-step-panel" data-step="2">
                <div class="ucg-filter-toolbar">
                    <div class="ucg-filter-toolbar__meta">
                        <h2>Фильтры</h2>
                        <p class="ucg-muted">Необязательно. Без фильтров показываем все записи выбранного типа.</p>
                    </div>
                    <div class="ucg-filter-toolbar__actions">
                        <button type="button" class="button" id="ucg-add-filter-row">+ Добавить фильтр</button>
                        <button type="button" class="button button-primary" id="ucg-preview-posts">Обновить список</button>
                    </div>
                </div>

                <div id="ucg-filter-rows" class="ucg-filter-rows"></div>

                <div class="ucg-preview-summary">
                    <span class="ucg-muted" id="ucg-preview-summary">Загружаем записи выбранного типа...</span>
                </div>

                <div class="ucg-selection-mode">
                    <label class="ucg-checkbox">
                        <input type="radio" name="ucg-selection-mode" value="selected" checked>
                        <span>Выбрать нужные записи вручную</span>
                    </label>
                    <label class="ucg-checkbox">
                        <input type="radio" name="ucg-selection-mode" value="filtered">
                        <span>Генерировать для всех найденных записей</span>
                    </label>
                </div>

                <div class="ucg-preview-table-wrap">
                    <table class="widefat striped">
                        <thead>
                        <tr>
                            <th style="width:45px;"><input type="checkbox" id="ucg-preview-select-page"></th>
                            <th style="width:80px;">ID</th>
                            <th>Заголовок</th>
                            <th style="width:140px;">Статус</th>
                            <th style="width:190px;">Дата</th>
                        </tr>
                        </thead>
                        <tbody id="ucg-preview-tbody">
                        <tr><td colspan="5">Загружаем записи...</td></tr>
                        </tbody>
                    </table>
                </div>

                <div class="ucg-pagination-wrap">
                    <div class="ucg-mini-pagination" id="ucg-preview-pagination"></div>
                    <span class="ucg-muted">Выбрано вручную: <strong id="ucg-selected-count">0</strong></span>
                </div>

                <div class="ucg-step-footer">
                    <button type="button" class="button" id="ucg-step-2-back">Назад</button>
                    <button type="button" class="button button-primary" id="ucg-step-2-next">Далее</button>
                </div>
            </section>

            <section class="ucg-step-panel" data-step="3">
                <div class="ucg-template-block">
                    <label class="ucg-field">
                        <span>Шаблон</span>
                        <select id="ucg-wizard-template" class="ucg-enhanced-select" data-search-enabled="false" data-placeholder="Не выбрано">
                            <option value="">Не выбрано</option>
                            <?php if (!empty($wizard_templates) && is_array($wizard_templates)) : ?>
                                <?php foreach ($wizard_templates as $wizard_template_item) : ?>
                                    <?php
                                    $wizard_template_id = isset($wizard_template_item['id']) ? (int) $wizard_template_item['id'] : 0;
                                    $wizard_template_name = isset($wizard_template_item['name']) ? (string) $wizard_template_item['name'] : '';
                                    $wizard_template_post_type = isset($wizard_template_item['post_type']) ? (string) $wizard_template_item['post_type'] : '';
                                    if ($wizard_template_id <= 0) {
                                        continue;
                                    }
                                    ?>
                                    <option value="<?php echo (int) $wizard_template_id; ?>" <?php selected($wizard_default_template_id, $wizard_template_id); ?>>
                                        <?php
                                        $wizard_template_label = '#' . $wizard_template_id . ' — ' . ($wizard_template_name !== '' ? $wizard_template_name : ('Шаблон #' . $wizard_template_id));
                                        if ($wizard_template_post_type !== '') {
                                            $wizard_template_label .= ' (' . $wizard_template_post_type . ')';
                                        }
                                        echo esc_html($wizard_template_label);
                                        ?>
                                    </option>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </select>
                    </label>

                    <label class="ucg-field" id="ucg-template-name-wrap">
                        <span>Название шаблона</span>
                        <input type="text" id="ucg-wizard-template-name" placeholder="Например: SEO описание товара">
                    </label>

                    <label class="ucg-checkbox">
                        <input type="checkbox" id="ucg-save-template-changes">
                        <span id="ucg-save-template-label">Сохранить шаблон</span>
                    </label>
                </div>

                <div class="ucg-grid-2 ucg-length-controls">
                    <label class="ucg-field">
                        <span>Длина текста</span>
                        <select id="ucg-wizard-length-option" class="ucg-enhanced-select" data-search-enabled="false">
                            <?php if (!empty($text_length_options) && is_array($text_length_options)) : ?>
                                <?php foreach ($text_length_options as $length_option_item) : ?>
                                    <?php
                                    $length_option_id = isset($length_option_item['id']) ? (int) $length_option_item['id'] : 0;
                                    $length_option_name = isset($length_option_item['name']) ? (string) $length_option_item['name'] : '';
                                    $length_option_max_chars = isset($length_option_item['max_chars']) ? (int) $length_option_item['max_chars'] : 0;
                                    $length_option_credits = isset($length_option_item['credits_cost']) ? (float) $length_option_item['credits_cost'] : 0.0;
                                    $length_option_credits_label = rtrim(rtrim(number_format($length_option_credits, 2, '.', ''), '0'), '.');
                                    if ($length_option_id <= 0 || $length_option_name === '') {
                                        continue;
                                    }
                                    ?>
                                    <option value="<?php echo (int) $length_option_id; ?>" <?php selected($default_length_option_id, $length_option_id); ?>>
                                        <?php echo esc_html($length_option_name . ' — до ' . number_format_i18n($length_option_max_chars) . ' символов / ' . $length_option_credits_label . ' кр.'); ?>
                                    </option>
                                <?php endforeach; ?>
                            <?php else : ?>
                                <option value="">Нет доступных диапазонов</option>
                            <?php endif; ?>
                        </select>
                    </label>

                    <div class="ucg-vary-length-block">
                        <label class="ucg-checkbox">
                            <input type="checkbox" id="ucg-wizard-vary-length">
                            <span>Варьировать длину текста</span>
                        </label>
                        <p class="ucg-muted ucg-field-hint" id="ucg-wizard-vary-length-hint"></p>
                    </div>
                </div>

                <div class="ucg-grid-2 ucg-model-controls">
                    <label class="ucg-field">
                        <span>Модель</span>
                        <select id="ucg-wizard-model" class="ucg-enhanced-select" data-search-enabled="false">
                            <option value="auto">По умолчанию</option>
                        </select>
                        <p class="ucg-muted ucg-field-hint" id="ucg-wizard-model-hint"></p>
                    </label>
                    <div class="ucg-model-unit-hint-wrap">
                        <p class="ucg-muted ucg-field-hint" id="ucg-wizard-unit-hint"></p>
                    </div>
                </div>

                <div id="ucg-template-body-standard-wrap">
                    <label class="ucg-field">
                        <span>Текст шаблона</span>
                        <textarea id="ucg-wizard-template-body" class="ucg-wizard-template-input" rows="12"></textarea>
                    </label>
                </div>

                <div id="ucg-template-body-seo-wrap" hidden>
                    <div class="ucg-grid-2">
                        <label class="ucg-field">
                            <span>Шаблон для SEO title</span>
                            <textarea id="ucg-wizard-template-body-seo-title" class="ucg-wizard-template-input" rows="8" placeholder="Например: Сгенерируй только SEO title до 60 символов"></textarea>
                        </label>
                        <label class="ucg-field">
                            <span>Шаблон для SEO description</span>
                            <textarea id="ucg-wizard-template-body-seo-description" class="ucg-wizard-template-input" rows="8" placeholder="Например: Сгенерируй meta description 140-160 символов"></textarea>
                        </label>
                    </div>
                </div>

                <div class="ucg-token-panel">
                    <div class="ucg-token-panel__head">
                        <h3>Переменные</h3>
                        <span>Кликните или перетащите переменную в текст</span>
                    </div>
                    <div class="ucg-token-grid" id="ucg-wizard-tokens"></div>
                </div>

                <div id="ucg-run-result" class="ucg-api-status" aria-live="polite"></div>

                <div class="ucg-step-footer">
                    <button type="button" class="button" id="ucg-step-3-back">Назад</button>
                    <button type="button" class="button button-primary" id="ucg-start-run">Запустить генерацию</button>
                </div>
            </section>
        </div>

        <script type="application/json" id="ucg-wizard-initial"><?php echo wp_json_encode($wizard_schema); ?></script>
    <?php endif; ?>
</div>
