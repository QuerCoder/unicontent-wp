<div class="wrap ucg-wrap">
    <?php $this->render_admin_notice(); ?>
    <?php include UCG_PLUGIN_DIR . 'templates/partials/plugin-header.php'; ?>

    <h1>Процесс генерации</h1>

    <?php if (empty($run) || empty($run['id'])) : ?>
        <section class="ucg-card">
            <p class="ucg-muted">Запуск не найден или не указан.</p>
            <p class="ucg-actions-row">
                <a href="<?php echo esc_url(admin_url('admin.php?page=ucg-generate')); ?>" class="button button-primary">К генерации</a>
                <a href="<?php echo esc_url(admin_url('admin.php?page=ucg-runs')); ?>" class="button">К истории</a>
            </p>
        </section>
    <?php else : ?>
        <?php
        $total = max(0, (int) $run['total_items']);
        $processed = max(0, (int) $run['processed_items']);
        $queued = max(0, $total - $processed);
        $success = max(0, (int) $run['success_items']);
        $failed = max(0, (int) $run['failed_items']);
        $progress = $total > 0 ? min(100, (int) floor(($processed / $total) * 100)) : 0;
        $status = sanitize_key((string) $run['status']);
        $is_finished = in_array($status, array('completed', 'failed'), true);
        ?>
        <section class="ucg-card ucg-run-progress-page" id="ucg-run-progress-page" data-run-id="<?php echo (int) $run['id']; ?>">
            <div class="ucg-run-monitor__head">
                <strong id="ucg-run-monitor-title">Запуск #<?php echo (int) $run['id']; ?></strong>
                <span class="ucg-chip ucg-chip--status ucg-status-<?php echo esc_attr($status); ?>" id="ucg-run-monitor-status">
                    <?php echo esc_html($this->status_label($status)); ?>
                </span>
            </div>

            <div class="ucg-progress ucg-progress--wide ucg-progress--xl">
                <div class="ucg-progress__bar" id="ucg-run-progress-bar" style="width: <?php echo (int) $progress; ?>%;"></div>
            </div>

            <div class="ucg-run-monitor__stats" id="ucg-run-monitor-stats">
                <?php echo esc_html($progress . '% • обработано ' . $processed . ' из ' . $total . ' • в очереди ' . $queued . ' • ошибок ' . $failed . ' • готово ' . $success); ?>
            </div>

            <div id="ucg-run-progress-status" class="ucg-api-status" aria-live="polite">
                <div class="ucg-status-message ucg-status-message--ok">
                    <?php echo $is_finished ? 'Запуск завершён. Можно перейти к проверке.' : 'Генерация в процессе. Страница обновляется автоматически.'; ?>
                </div>
            </div>

            <div class="ucg-actions-row" id="ucg-run-progress-actions" style="display:none;">
                <button type="button" class="button button-primary" id="ucg-run-continue">Продолжить</button>
                <span class="ucg-muted" id="ucg-run-continue-hint"></span>
            </div>

            <div class="ucg-run-log" id="ucg-run-log">
                <div class="ucg-muted">Логи появятся после обработки первых записей.</div>
            </div>

            <div class="ucg-actions-row ucg-actions-row--footer">
                <a href="<?php echo esc_url(admin_url('admin.php?page=ucg-review&run_id=' . (int) $run['id'])); ?>" class="button button-primary" id="ucg-run-review-link" <?php if (!$is_finished) : ?>style="display:none;"<?php endif; ?>>Проверить результаты</a>
            </div>
        </section>
    <?php endif; ?>
</div>
