<?php
$header_title = 'Генерация контента';
$header_hide_mark = true;
?>
<div class="wrap ucg-wrap ucg-generate-page">
    <?php $this->render_admin_notice(); ?>
    <?php include UCG_PLUGIN_DIR . 'templates/partials/plugin-header.php'; ?>

    <?php if (!$api_ready) : ?>
        <section class="ucg-card">
            <h2>Нужен API ключ</h2>
            <p class="ucg-muted">Перед использованием мастера добавьте и проверьте API ключ на дашборде.</p>
            <p>
                <a href="<?php echo esc_url(admin_url('admin.php?page=ucg-dashboard')); ?>" class="button button-primary">Перейти на дашборд</a>
            </p>
        </section>
    <?php else : ?>
        <div class="ucg-wizard" id="ucg-wizard">
            <div class="ucg-stepper">
                <button type="button" class="ucg-stepper__item is-active" data-step-target="1">
                    <span class="ucg-stepper__num">1</span>
                    <span class="ucg-stepper__label">Сценарий</span>
                </button>
                <span class="ucg-stepper__sep" aria-hidden="true">|</span>
                <button type="button" class="ucg-stepper__item" data-step-target="2">
                    <span class="ucg-stepper__num">2</span>
                    <span class="ucg-stepper__label">Фильтрация</span>
                </button>
                <span class="ucg-stepper__sep" aria-hidden="true">|</span>
                <button type="button" class="ucg-stepper__item" data-step-target="3">
                    <span class="ucg-stepper__num">3</span>
                    <span class="ucg-stepper__label">Запуск</span>
                </button>
            </div>

            <section class="ucg-step-panel is-active" data-step="1">
                <div class="ucg-card ucg-step1-card">
                <div class="ucg-field ucg-scenario-field">
                    <h3 class="ucg-launch-card-title">Что генерировать</h3>
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
                                $unavailable_label = '';
                                if (!$scenario_available) {
                                    $unavailable_label = __('Неактивен', 'unicontent-ai-generator');
                                    if ($scenario_value === 'woo_reviews') {
                                        $unavailable_label = __('WooCommerce отключен', 'unicontent-ai-generator');
                                    } elseif ($scenario_value === 'seo_tags') {
                                        $unavailable_label = __('SEO плагин не найден', 'unicontent-ai-generator');
                                    }
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
                                        <span class="ucg-scenario-card__icon-wrap" aria-hidden="true">
                                            <span class="dashicons <?php echo esc_attr($scenario_icon); ?>"></span>
                                        </span>
                                        <span class="ucg-scenario-card__content">
                                            <span class="ucg-scenario-card__label"><?php echo esc_html($scenario_label); ?></span>
                                            <?php if (!$scenario_available) : ?>
                                                <span class="ucg-scenario-card__status"><?php echo esc_html($unavailable_label); ?></span>
                                            <?php endif; ?>
                                            <?php if ($scenario_description !== '') : ?>
                                                <span class="ucg-scenario-card__desc"><?php echo esc_html($scenario_description); ?></span>
                                            <?php endif; ?>
                                        </span>
                                    </span>
                                </label>
                            <?php endforeach; ?>
                        <?php else : ?>
                            <label class="ucg-scenario-card">
                                <input class="ucg-scenario-card__input" type="radio" name="ucg-wizard-scenario" value="field_update" checked>
                                <span class="ucg-scenario-card__surface">
                                    <span class="ucg-scenario-card__icon-wrap" aria-hidden="true">
                                        <span class="dashicons dashicons-edit-page"></span>
                                    </span>
                                    <span class="ucg-scenario-card__content">
                                        <span class="ucg-scenario-card__label">Поля</span>
                                    </span>
                                </span>
                            </label>
                        <?php endif; ?>
                    </div>
                </div>

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

                    <label class="ucg-field" id="ucg-wizard-target-field-wrap">
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

                    <label class="ucg-field" id="ucg-wizard-items-per-post-wrap" style="display:none;">
                        <span class="ucg-label-with-help">
                            Количество на запись
                            <button
                                type="button"
                                class="ucg-help-tip"
                                id="ucg-items-per-post-help"
                                aria-label="Подсказка по количеству на запись"
                                data-tip="Сколько комментариев/отзывов сгенерировать на одну запись."
                            >
                                <span class="dashicons dashicons-editor-help" aria-hidden="true"></span>
                            </button>
                        </span>
                        <input
                            type="number"
                            id="ucg-wizard-items-per-post"
                            min="1"
                            max="50"
                            step="1"
                            value="1"
                        >
                    </label>

                </div>

                </div>

                <div class="ucg-step-footer ucg-step-footer--sticky ucg-step-footer--wizard ucg-step-footer--wizard-step1">
                    <button type="button" class="button button-primary" id="ucg-step-1-next">Далее →</button>
                </div>
            </section>

            <section class="ucg-step-panel" data-step="2">
                <div class="ucg-card ucg-step2-card">
                    <div class="ucg-step2-head">
                        <div class="ucg-step2-head__meta">
                            <h3 class="ucg-launch-card-title">Фильтры</h3>
                            <p class="ucg-muted">Необязательно. Без фильтров показываем все записи выбранного типа.</p>
                        </div>
                        <div class="ucg-step2-head__actions">
                            <button type="button" class="button" id="ucg-add-filter-row">+ Добавить фильтр</button>
                            <button type="button" class="button button-primary" id="ucg-preview-posts">Обновить список</button>
                        </div>
                    </div>

                    <div id="ucg-filter-rows" class="ucg-filter-rows ucg-step2-filter-rows"></div>

                    <div class="ucg-step2-stats">
                        <span class="ucg-step2-stat ucg-step2-stat--found">Найдено: <strong id="ucg-preview-found-count">0</strong></span>
                        <span class="ucg-step2-stats__divider" aria-hidden="true"></span>
                        <span class="ucg-step2-stat ucg-step2-stat--selected">Выбрано: <strong id="ucg-preview-selected-count">0</strong></span>
                        <span class="screen-reader-text" id="ucg-preview-summary">Загружаем записи выбранного типа...</span>
                    </div>

                    <div class="ucg-selection-mode ucg-step2-mode">
                        <div class="ucg-step2-mode__seg" role="radiogroup" aria-label="Режим выбора записей">
                            <label class="ucg-step2-mode__option">
                                <input type="radio" name="ucg-selection-mode" value="selected" checked>
                                <span>Выбрать вручную</span>
                            </label>
                            <label class="ucg-step2-mode__option">
                                <input type="radio" name="ucg-selection-mode" value="filtered">
                                <span>Все найденные (<strong id="ucg-selection-mode-filtered-total">0</strong>)</span>
                            </label>
                        </div>
                    </div>

                    <div class="ucg-preview-table-wrap ucg-step2-table-wrap">
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

                    <div class="ucg-pagination-wrap ucg-step2-footer">
                        <div class="ucg-mini-pagination" id="ucg-preview-pagination"></div>
                        <span class="ucg-muted ucg-step2-footer__selected">Выбрано вручную: <strong id="ucg-selected-count">0</strong></span>
                    </div>

                </div>

                <div class="ucg-step-footer ucg-step-footer--sticky ucg-step-footer--wizard ucg-step-footer--wizard-step2">
                    <button type="button" class="button" id="ucg-step-2-back">← Назад</button>
                    <button type="button" class="button button-primary" id="ucg-step-2-next">Далее →</button>
                </div>
            </section>

            <section class="ucg-step-panel" data-step="3">
                <div class="ucg-launch-shell">
                    <div class="ucg-launch-main">
                        <div class="ucg-card ucg-launch-settings">
                            <h3 class="ucg-launch-card-title">Параметры</h3>

                            <div class="ucg-launch-settings-row ucg-launch-settings-row--primary">
                                <div class="ucg-template-col">
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
                                </div>

                                <div id="ucg-length-controls-wrap">
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
                                </div>

                                <label class="ucg-field ucg-launch-model-field">
                                    <span>Модель</span>
                                    <select id="ucg-wizard-model" class="ucg-enhanced-select" data-search-enabled="false">
                                        <option value="auto">По умолчанию</option>
                                    </select>
                                </label>
                            </div>

                            <div class="ucg-launch-style-row" id="ucg-advanced-body">
                                <label class="ucg-field">
                                    <span>Язык</span>
                                    <select id="ucg-wizard-language" class="ucg-enhanced-select" data-search-enabled="false">
                                        <option value="auto">Авто</option>
                                        <option value="ru">Русский</option>
                                        <option value="en">English</option>
                                    </select>
                                </label>
                                <label class="ucg-field">
                                    <span>Тон</span>
                                    <select id="ucg-wizard-tone" class="ucg-enhanced-select" data-search-enabled="false">
                                        <option value="neutral">Нейтральный</option>
                                        <option value="official">Официальный</option>
                                        <option value="friendly">Дружелюбный</option>
                                    </select>
                                </label>
                                <label class="ucg-field">
                                    <span>Уникальность</span>
                                    <select id="ucg-wizard-uniqueness" class="ucg-enhanced-select" data-search-enabled="false">
                                        <option value="low">Низкая</option>
                                        <option value="medium">Средняя</option>
                                        <option value="high">Высокая</option>
                                    </select>
                                </label>
                            </div>

                            <div class="ucg-launch-flags-row">
                                <label class="ucg-checkbox ucg-save-template-inline">
                                    <input type="checkbox" id="ucg-save-template-changes">
                                    <span id="ucg-save-template-label">Сохранить как шаблон</span>
                                </label>
                                <label class="ucg-checkbox ucg-vary-length-inline">
                                    <input type="checkbox" id="ucg-wizard-vary-length">
                                    <span class="ucg-label-with-help">
                                        Разброс длины
                                        <button
                                            type="button"
                                            class="ucg-help-tip"
                                            id="ucg-wizard-vary-length-help"
                                            aria-label="Подсказка по разбросу длины"
                                            data-tip=""
                                        >
                                            <span class="dashicons dashicons-editor-help" aria-hidden="true"></span>
                                        </button>
                                    </span>
                                </label>
                                <span id="ucg-wizard-vary-length-hint" class="screen-reader-text"></span>
                            </div>

                            <label class="ucg-field" id="ucg-template-name-wrap" hidden>
                                <span>Название шаблона</span>
                                <input type="text" id="ucg-wizard-template-name" placeholder="Например: SEO описание товара">
                            </label>

                            <div class="ucg-seo-guidelines" id="ucg-seo-guidelines" hidden>
                                <strong>Рекомендации для SEO-тегов</strong>
                                <p class="ucg-muted">Title: до 60-70 символов, Description: до 140-160 символов. Ограничения лучше указывать в самих промптах.</p>
                            </div>

                            <div class="ucg-date-range-block" id="ucg-publish-date-range-wrap" hidden>
                                <div class="ucg-grid-2 ucg-date-range-grid">
                                    <label class="ucg-field">
                                        <span>Дата публикации: от</span>
                                        <input type="date" id="ucg-wizard-publish-date-from">
                                    </label>
                                    <label class="ucg-field">
                                        <span>Дата публикации: до</span>
                                        <input type="date" id="ucg-wizard-publish-date-to">
                                    </label>
                                </div>
                                <p class="ucg-muted ucg-field-hint">Для комментариев и отзывов: если диапазон не задан, используется текущее время и дата.</p>
                            </div>

                            <div class="ucg-date-range-block" id="ucg-woo-rating-range-wrap" hidden>
                                <div class="ucg-grid-2 ucg-date-range-grid">
                                    <label class="ucg-field">
                                        <span>Рейтинг: от</span>
                                        <select id="ucg-woo-rating-min" class="ucg-enhanced-select" data-search-enabled="false">
                                            <option value="1">1</option>
                                            <option value="2">2</option>
                                            <option value="3">3</option>
                                            <option value="4">4</option>
                                            <option value="5">5</option>
                                        </select>
                                    </label>
                                    <label class="ucg-field">
                                        <span>Рейтинг: до</span>
                                        <select id="ucg-woo-rating-max" class="ucg-enhanced-select" data-search-enabled="false">
                                            <option value="1">1</option>
                                            <option value="2">2</option>
                                            <option value="3">3</option>
                                            <option value="4">4</option>
                                            <option value="5" selected>5</option>
                                        </select>
                                    </label>
                                </div>
                                <p class="ucg-muted ucg-field-hint">Для WooCommerce отзывов: модель будет генерировать рейтинг в выбранном диапазоне.</p>
                            </div>
                        </div>

                        <div class="ucg-card ucg-launch-prompt">
                            <h3 class="ucg-launch-card-title ucg-launch-card-title--with-help">
                                Промпт
                                <button
                                    type="button"
                                    class="ucg-help-tip"
                                    id="ucg-prompt-help"
                                    aria-label="Подсказка по тексту промпта"
                                    data-tip="Используйте {переменные} для подстановки данных записи."
                                >
                                    <span class="dashicons dashicons-editor-help" aria-hidden="true"></span>
                                </button>
                            </h3>

                            <div id="ucg-template-body-standard-wrap">
                                <textarea id="ucg-wizard-template-body" class="ucg-wizard-template-input" rows="9" placeholder="Напишите инструкцию. Используйте переменные ниже чтобы вставить данные записи..."></textarea>
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
                                    <div class="ucg-token-panel__title">
                                        <h3>Переменные</h3>
                                        <button
                                            type="button"
                                            class="ucg-help-tip"
                                            id="ucg-token-help"
                                            aria-label="Подсказка по переменным"
                                            data-tip="Кликните или перетащите переменную в поле выше."
                                        >
                                            <span class="dashicons dashicons-editor-help" aria-hidden="true"></span>
                                        </button>
                                    </div>
                                    <label class="ucg-token-search">
                                        <span class="dashicons dashicons-search" aria-hidden="true"></span>
                                        <input type="search" id="ucg-wizard-token-search" placeholder="Поиск переменной...">
                                    </label>
                                </div>
                                <div class="ucg-token-groups" id="ucg-wizard-tokens"></div>
                            </div>
                        </div>

                        <div class="ucg-card" id="ucg-example-wrap" style="margin-top:0; display:none;">
                            <div class="ucg-actions-row" style="justify-content: space-between; align-items: center;">
                                <strong>Пример результата</strong>
                                <span class="ucg-muted" id="ucg-example-credits"></span>
                            </div>
                            <textarea id="ucg-example-output" class="large-text code" rows="8" readonly></textarea>
                        </div>
                    </div>

                    <aside class="ucg-card ucg-launch-summary-card">
                        <div id="ucg-run-summary" class="ucg-run-summary" aria-live="polite"></div>
                    </aside>
                </div>

                <div class="ucg-step-footer ucg-step-footer--sticky ucg-step-footer--launch">
                    <button type="button" class="button" id="ucg-step-3-back">← Назад</button>
                    <div class="ucg-step-footer__total" id="ucg-step-3-total">Итого: <strong>~0 кр.</strong></div>
                    <button type="button" class="button" id="ucg-generate-example">Сгенерировать пример</button>
                    <button type="button" class="button button-primary" id="ucg-start-run">Запустить генерацию</button>
                </div>
            </section>
        </div>

        <div id="ucg-run-result" class="ucg-sr-only" aria-live="polite" aria-atomic="true"></div>
        <div id="ucg-toast-stack" class="ucg-toast-stack" aria-live="polite" aria-atomic="false"></div>

        <script type="application/json" id="ucg-wizard-initial"><?php echo wp_json_encode($wizard_schema); ?></script>
    <?php endif; ?>
</div>
