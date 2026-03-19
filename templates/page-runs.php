<div class="wrap ucg-wrap">
    <?php $this->render_admin_notice(); ?>
    <?php include UCG_PLUGIN_DIR . 'templates/partials/plugin-header.php'; ?>

    <h1>История</h1>
    <p class="ucg-muted">История запусков и текущий статус обработки очереди.</p>

    <section class="ucg-card">
        <?php if (empty($runs)) : ?>
            <p class="ucg-muted">Запусков пока нет.</p>
        <?php else : ?>
            <table class="widefat striped">
                <thead>
                <tr>
                    <th>ID</th>
                    <th>Статус</th>
                    <th>Post type</th>
                    <th>Целевое поле</th>
                    <th>Шаблон</th>
                    <th>Прогресс</th>
                    <th>Создан</th>
                    <th>Завершен</th>
                    <th style="width: 180px;">Действия</th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($runs as $run) : ?>
                    <?php
                    $total = max(1, (int) $run['total_items']);
                    $processed = (int) $run['processed_items'];
                    $progress = min(100, (int) floor(($processed / $total) * 100));
                    ?>
                    <tr>
                        <td>#<?php echo (int) $run['id']; ?></td>
                        <td><span class="ucg-chip ucg-chip--status ucg-status-<?php echo esc_attr((string) $run['status']); ?>"><?php echo esc_html($this->status_label((string) $run['status'])); ?></span></td>
                        <td><code><?php echo esc_html((string) $run['post_type']); ?></code></td>
                        <td><code><?php echo esc_html((string) $run['target_field']); ?></code></td>
                        <td><?php echo esc_html((string) $run['template_name']); ?></td>
                        <td>
                            <div class="ucg-progress">
                                <div class="ucg-progress__bar" style="width: <?php echo (int) $progress; ?>%"></div>
                            </div>
                            <small><?php echo esc_html($processed . ' / ' . (int) $run['total_items']); ?></small>
                        </td>
                        <td><?php echo esc_html((string) $run['created_at']); ?></td>
                        <td><?php echo esc_html((string) $run['finished_at']); ?></td>
                        <td class="ucg-inline-actions">
                            <?php if (in_array((string) $run['status'], array('queued', 'running'), true)) : ?>
                                <a class="button button-small" href="<?php echo esc_url(admin_url('admin.php?page=ucg-run-progress&run_id=' . (int) $run['id'])); ?>">Прогресс</a>
                            <?php endif; ?>
                            <a class="button button-small" href="<?php echo esc_url(admin_url('admin.php?page=ucg-review&run_id=' . (int) $run['id'])); ?>">Проверка</a>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </section>
</div>
