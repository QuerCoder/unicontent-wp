<?php $has_api_key = $masked_api_key !== ''; ?>
<div class="wrap ucg-wrap">
    <?php $this->render_admin_notice(); ?>
    <?php include UCG_PLUGIN_DIR . 'templates/partials/plugin-header.php'; ?>

    <div class="ucg-hero">
        <div>
            <h1>UNICONTENT — AI генератор контента</h1>
            <p>Сначала добавьте API ключ из личного кабинета, затем запускайте генерацию в пару шагов.</p>
        </div>
        <div class="ucg-hero__actions">
            <a href="<?php echo esc_url(admin_url('admin.php?page=ucg-generate')); ?>" class="button button-primary">Новый запуск</a>
            <a href="<?php echo esc_url(admin_url('admin.php?page=ucg-review')); ?>" class="button">Проверка</a>
        </div>
    </div>

    <div class="ucg-cards">
        <section class="ucg-card">
            <h3>API ключ</h3>
            <p class="ucg-muted">Скопируйте ключ в личном кабинете и вставьте его в поле ниже.</p>
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
            <div id="ucg-api-status" class="ucg-api-status" aria-live="polite"></div>
            <div class="ucg-actions-row ucg-actions-row--footer">
                <button type="button" class="button ucg-btn ucg-btn--primary" id="ucg-save-api-key" <?php if ($has_api_key) : ?>style="display:none;"<?php endif; ?>>Сохранить</button>
            </div>
        </section>

        <section class="ucg-card">
            <h3>Очередь</h3>
            <div class="ucg-stat-grid">
                <div><span>В очереди</span><strong><?php echo (int) $stats['queued']; ?></strong></div>
                <div><span>В работе</span><strong><?php echo (int) $stats['running']; ?></strong></div>
                <div><span>Готово</span><strong><?php echo (int) $stats['completed']; ?></strong></div>
                <div><span>Ошибки</span><strong><?php echo (int) $stats['failed']; ?></strong></div>
            </div>
        </section>
    </div>

    <section class="ucg-card">
        <h3>Последние запуски</h3>
        <?php if (empty($recent_runs)) : ?>
            <p class="ucg-muted">Запусков пока нет.</p>
        <?php else : ?>
            <table class="widefat striped">
                <thead>
                <tr>
                    <th>ID</th>
                    <th>Статус</th>
                    <th>Post type</th>
                    <th>Поле</th>
                    <th>Прогресс</th>
                    <th>Создан</th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($recent_runs as $run) : ?>
                    <?php
                    $total = max(1, (int) $run['total_items']);
                    $processed = (int) $run['processed_items'];
                    $progress = min(100, (int) floor(($processed / $total) * 100));
                    ?>
                    <tr>
                        <td>
                            <a href="<?php echo esc_url(admin_url('admin.php?page=ucg-review&run_id=' . (int) $run['id'])); ?>">
                                #<?php echo (int) $run['id']; ?>
                            </a>
                        </td>
                        <td><span class="ucg-chip ucg-chip--status ucg-status-<?php echo esc_attr((string) $run['status']); ?>"><?php echo esc_html($this->status_label((string) $run['status'])); ?></span></td>
                        <td><?php echo esc_html((string) $run['post_type']); ?></td>
                        <td><code><?php echo esc_html((string) $run['target_field']); ?></code></td>
                        <td><?php echo esc_html($processed . ' / ' . (int) $run['total_items'] . ' (' . $progress . '%)'); ?></td>
                        <td><?php echo esc_html((string) $run['created_at']); ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </section>
</div>
