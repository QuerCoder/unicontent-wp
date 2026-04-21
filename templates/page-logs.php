<div class="wrap ucg-wrap">
    <?php $this->render_admin_notice(); ?>
    <?php include UCG_PLUGIN_DIR . 'templates/partials/plugin-header.php'; ?>

    <h1><?php echo esc_html__('Логи', 'unicontent-ai-generator'); ?></h1>

    <section class="ucg-card">
        <form method="get" class="ucg-form-grid">
            <input type="hidden" name="page" value="ucg-logs">
            <div class="ucg-grid-3">
                <label class="ucg-field">
                    <span><?php echo esc_html__('Уровень', 'unicontent-ai-generator'); ?></span>
                    <select name="level" class="ucg-enhanced-select" data-search-enabled="false">
                        <?php foreach ($log_levels as $value => $label) : ?>
                            <option value="<?php echo esc_attr($value); ?>" <?php selected(isset($filters['level']) ? (string) $filters['level'] : '', $value); ?>>
                                <?php echo esc_html($label); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </label>

                <label class="ucg-field">
                    <span><?php echo esc_html__('Область', 'unicontent-ai-generator'); ?></span>
                    <select name="area" class="ucg-enhanced-select" data-search-enabled="false">
                        <?php foreach ($log_areas as $value => $label) : ?>
                            <option value="<?php echo esc_attr($value); ?>" <?php selected(isset($filters['area']) ? (string) $filters['area'] : '', $value); ?>>
                                <?php echo esc_html($label); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </label>

                <label class="ucg-field">
                    <span><?php echo esc_html__('Run ID (опционально)', 'unicontent-ai-generator'); ?></span>
                    <input type="number" name="run_id" value="<?php echo esc_attr(isset($filters['run_id']) ? (int) $filters['run_id'] : 0); ?>" min="0" step="1">
                </label>
            </div>

            <div class="ucg-grid-3">
                <label class="ucg-field">
                    <span><?php echo esc_html__('Дата: с', 'unicontent-ai-generator'); ?></span>
                    <input type="date" name="since" value="<?php echo esc_attr(isset($filters['since']) ? (string) $filters['since'] : ''); ?>">
                </label>
                <label class="ucg-field">
                    <span><?php echo esc_html__('Дата: по', 'unicontent-ai-generator'); ?></span>
                    <input type="date" name="until" value="<?php echo esc_attr(isset($filters['until']) ? (string) $filters['until'] : ''); ?>">
                </label>
                <div class="ucg-field">
                    <span>&nbsp;</span>
                    <button type="submit" class="button button-primary"><?php echo esc_html__('Показать', 'unicontent-ai-generator'); ?></button>
                </div>
            </div>
        </form>
    </section>

    <section class="ucg-card">
        <div class="ucg-actions-row">
            <button type="button" class="button" id="ucg-copy-logs"><?php echo esc_html__('Скопировать', 'unicontent-ai-generator'); ?></button>
            <button type="button" class="button" id="ucg-download-logs"><?php echo esc_html__('Скачать JSON', 'unicontent-ai-generator'); ?></button>
            <button type="button" class="button" id="ucg-copy-diagnostics"><?php echo esc_html__('Скопировать диагностику', 'unicontent-ai-generator'); ?></button>
        </div>

        <textarea id="ucg-logs-json" class="large-text code" rows="16" readonly><?php
            echo esc_textarea(
                wp_json_encode(
                    array(
                        'diagnostics' => $diagnostics,
                        'filters' => $filters,
                        'logs' => $logs,
                    ),
                    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT
                )
            );
            ?></textarea>

        <p class="ucg-muted ucg-field-hint">
            <?php echo esc_html__('Логи не содержат промптов/сгенерированного текста и API ключей. Можно безопасно отправлять в поддержку.', 'unicontent-ai-generator'); ?>
        </p>
    </section>

    <section class="ucg-card">
        <?php if (empty($logs)) : ?>
            <p class="ucg-muted"><?php echo esc_html__('Пока нет записей логов.', 'unicontent-ai-generator'); ?></p>
        <?php else : ?>
            <table class="widefat striped">
                <thead>
                <tr>
                    <th style="width:90px;">ID</th>
                    <th style="width:160px;"><?php echo esc_html__('Дата (UTC)', 'unicontent-ai-generator'); ?></th>
                    <th style="width:80px;"><?php echo esc_html__('Level', 'unicontent-ai-generator'); ?></th>
                    <th style="width:120px;"><?php echo esc_html__('Area', 'unicontent-ai-generator'); ?></th>
                    <th style="width:140px;"><?php echo esc_html__('Event', 'unicontent-ai-generator'); ?></th>
                    <th><?php echo esc_html__('Message', 'unicontent-ai-generator'); ?></th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($logs as $row) : ?>
                    <?php
                    $ctx = '';
                    if (!empty($row['context_json'])) {
                        $ctx = (string) $row['context_json'];
                    }
                    ?>
                    <tr>
                        <td><?php echo (int) $row['id']; ?></td>
                        <td><?php echo esc_html(isset($row['created_at']) ? (string) $row['created_at'] : ''); ?></td>
                        <td><?php echo esc_html(isset($row['level']) ? (string) $row['level'] : ''); ?></td>
                        <td><?php echo esc_html(isset($row['area']) ? (string) $row['area'] : ''); ?></td>
                        <td><?php echo esc_html(isset($row['event']) ? (string) $row['event'] : ''); ?></td>
                        <td>
                            <div><?php echo esc_html(isset($row['message']) ? (string) $row['message'] : ''); ?></div>
                            <?php if ($ctx !== '') : ?>
                                <div class="ucg-muted" style="margin-top:4px; font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace; font-size: 12px;">
                                    <?php echo esc_html($ctx); ?>
                                </div>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </section>
</div>

