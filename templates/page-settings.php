<?php $has_api_key = $masked_api_key !== ''; ?>
<div class="wrap ucg-wrap">
    <?php $this->render_admin_notice(); ?>
    <?php include UCG_PLUGIN_DIR . 'templates/partials/plugin-header.php'; ?>

    <h1>Настройки</h1>
    <p class="ucg-muted">Минимальные настройки для запуска генерации: API ключ и размер шага обработки.</p>

    <div class="ucg-cards">
        <section class="ucg-card">
            <h3>API ключ</h3>
            <p class="ucg-muted">Вставьте ключ из личного кабинета UNICONTENT. Без ключа генерация не начнётся.</p>
            <p class="ucg-actions-row">
                <a href="https://unicontent.net/dashboard/api-keys" target="_blank" rel="noopener noreferrer" class="button ucg-btn ucg-btn--secondary">Создать ключ</a>
            </p>

            <div class="ucg-form-grid">
                <label class="ucg-field">
                    <span>Ключ API</span>
                    <div class="ucg-key-input-wrap">
                        <input
                            type="text"
                            id="ucg-api-key-input"
                            value="<?php echo $has_api_key ? esc_attr($masked_api_key) : ''; ?>"
                            placeholder="<?php echo $has_api_key ? 'Ключ сохранён' : 'Вставьте API ключ'; ?>"
                            <?php echo $has_api_key ? 'readonly aria-readonly="true"' : ''; ?>
                        >
                        <button type="button" class="button ucg-icon-button" id="ucg-delete-api-key" title="Удалить ключ" <?php if (!$has_api_key) : ?>style="display:none;"<?php endif; ?>>
                            <span class="dashicons dashicons-trash" aria-hidden="true"></span>
                        </button>
                    </div>
                </label>
                <div class="ucg-actions-row">
                    <span class="ucg-muted">Текущий: <strong id="ucg-current-key"><?php echo $masked_api_key !== '' ? esc_html($masked_api_key) : 'не задан'; ?></strong></span>
                    <?php if ($api_ready) : ?>
                        <span class="ucg-chip ucg-chip--ok" id="ucg-key-chip">Проверен</span>
                    <?php else : ?>
                        <span class="ucg-chip ucg-chip--bad" id="ucg-key-chip">Не проверен</span>
                    <?php endif; ?>
                </div>
            </div>

            <div class="ucg-balance-row">
                <span>Кредиты:</span>
                <strong class="ucg-balance-value">—</strong>
                <button type="button" class="button ucg-btn ucg-btn--ghost" id="ucg-refresh-balance">Обновить</button>
            </div>

            <div class="ucg-actions-row ucg-actions-row--footer">
                <button type="button" class="button ucg-btn ucg-btn--primary" id="ucg-save-api-key" <?php if ($has_api_key) : ?>style="display:none;"<?php endif; ?>>Сохранить ключ</button>
            </div>
        </section>

        <section class="ucg-card">
            <h3>Скорость обработки</h3>
            <p class="ucg-muted">Параметр определяет, сколько записей обрабатывается за один шаг очереди.</p>
            <ul class="ucg-hints">
                <li>Меньше значение: стабильнее на слабом хостинге.</li>
                <li>Больше значение: быстрее общий прогон, но выше нагрузка.</li>
                <li>Рекомендуем начать с <strong>20</strong> и увеличивать постепенно.</li>
            </ul>
            <label class="ucg-field">
                <span>Записей за шаг (1-100)</span>
                <input type="number" id="ucg-batch-size-input" min="1" max="100" value="<?php echo (int) $settings['batch_size']; ?>">
            </label>
            <label class="ucg-field">
                <span>После генерации</span>
                <select id="ucg-generation-mode" class="ucg-enhanced-select" data-search-enabled="false">
                    <option value="review" <?php selected((string) $settings['generation_mode'], 'review'); ?>>Сначала проверка (по умолчанию)</option>
                    <option value="publish" <?php selected((string) $settings['generation_mode'], 'publish'); ?>>Публиковать сразу без проверки</option>
                </select>
            </label>
            <div class="ucg-actions-row ucg-actions-row--footer">
                <button type="button" class="button ucg-btn ucg-btn--primary" id="ucg-save-batch-size">Сохранить</button>
            </div>
        </section>

        <section class="ucg-card">
            <h3>Стиль генерации (по умолчанию)</h3>
            <p class="ucg-muted">Эти настройки применяются ко всем новым запускам. В мастере запуска их можно будет переопределить.</p>

            <label class="ucg-field">
                <span>Язык</span>
                <select id="ucg-default-language" class="ucg-enhanced-select" data-search-enabled="false">
                    <option value="auto" <?php selected((string) $settings['default_language'], 'auto'); ?>>Авто</option>
                    <option value="ru" <?php selected((string) $settings['default_language'], 'ru'); ?>>Русский</option>
                    <option value="en" <?php selected((string) $settings['default_language'], 'en'); ?>>English</option>
                </select>
            </label>

            <label class="ucg-field">
                <span>Тон</span>
                <select id="ucg-default-tone" class="ucg-enhanced-select" data-search-enabled="false">
                    <option value="neutral" <?php selected((string) $settings['default_tone'], 'neutral'); ?>>Нейтральный</option>
                    <option value="official" <?php selected((string) $settings['default_tone'], 'official'); ?>>Официальный</option>
                    <option value="friendly" <?php selected((string) $settings['default_tone'], 'friendly'); ?>>Дружелюбный</option>
                </select>
            </label>

            <label class="ucg-field">
                <span>Уникальность</span>
                <select id="ucg-default-uniqueness" class="ucg-enhanced-select" data-search-enabled="false">
                    <option value="low" <?php selected((string) $settings['default_uniqueness'], 'low'); ?>>Низкая</option>
                    <option value="medium" <?php selected((string) $settings['default_uniqueness'], 'medium'); ?>>Средняя</option>
                    <option value="high" <?php selected((string) $settings['default_uniqueness'], 'high'); ?>>Высокая</option>
                </select>
            </label>

            <div class="ucg-actions-row ucg-actions-row--footer">
                <button type="button" class="button ucg-btn ucg-btn--primary" id="ucg-save-style-defaults">Сохранить</button>
            </div>
        </section>
    </div>

    <div id="ucg-api-status" class="ucg-api-status" aria-live="polite"></div>
</div>
