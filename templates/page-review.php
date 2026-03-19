<div class="wrap ucg-wrap">
    <?php $this->render_admin_notice(); ?>
    <?php include UCG_PLUGIN_DIR . 'templates/partials/plugin-header.php'; ?>

    <h1>Проверка результатов</h1>
    <p class="ucg-muted">Сначала смотрите сгенерированный текст, затем массово одобряйте или отклоняйте.</p>

    <section class="ucg-card">
        <form method="get" class="ucg-filter-row">
            <input type="hidden" name="page" value="ucg-review">

            <label>
                <span>Запуск</span>
                <select
                    name="run_id"
                    class="ucg-enhanced-select"
                    data-search-enabled="false"
                    data-placeholder="Вся история"
                    data-ajax-enabled="true"
                    data-ajax-action="ucg_search_runs"
                    data-ajax-min-chars="0"
                    data-ajax-preload="true"
                    data-ajax-limit="25"
                >
                    <option value="0">Вся история</option>
                    <?php foreach ($runs as $run) : ?>
                        <option value="<?php echo (int) $run['id']; ?>" <?php selected($run_id, (int) $run['id']); ?>>
                            #<?php echo (int) $run['id']; ?> — <?php echo esc_html((string) $run['post_type']); ?> (<?php echo esc_html($this->status_label((string) $run['status'])); ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>

            <label>
                <span>Статус</span>
                <select name="status" class="ucg-enhanced-select" data-search-enabled="false">
                    <option value="generated" <?php selected($status, 'generated'); ?>>Сгенерировано</option>
                    <option value="approved" <?php selected($status, 'approved'); ?>>Одобрено</option>
                    <option value="rejected" <?php selected($status, 'rejected'); ?>>Отклонено</option>
                    <option value="failed" <?php selected($status, 'failed'); ?>>Ошибка</option>
                </select>
            </label>

            <button type="submit" class="button button-primary">Показать</button>
        </form>
    </section>

    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
        <?php wp_nonce_field('ucg_review_bulk'); ?>
        <input type="hidden" name="action" value="ucg_review_bulk">

        <section class="ucg-card">
            <div class="ucg-table-head">
                <h2>Элементы</h2>
                <span class="ucg-muted">Всего: <?php echo (int) $total_items; ?></span>
            </div>

            <?php if (empty($items)) : ?>
                <p class="ucg-muted">По текущему фильтру элементов нет.</p>
            <?php else : ?>
                <table class="widefat striped ucg-review-table">
                    <thead>
                    <tr>
                        <th style="width:45px;"><input type="checkbox" id="ucg-select-all-review"></th>
                        <th style="width:80px;">ID</th>
                        <th style="width:90px;">Run</th>
                        <th style="width:220px;">Запись</th>
                        <th>Текущее значение</th>
                        <th>Сгенерированный текст</th>
                        <th style="width:150px;">Просмотр</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($items as $item) : ?>
                        <?php
                        $post_id = (int) $item['post_id'];
                        $current_value = UCG_Tokens::get_field_value_for_preview($post_id, (string) $item['target_field']);
                        $current_preview = is_string($current_value) ? trim($current_value) : '';
                        $generated_preview = is_string($item['generated_text']) ? trim((string) $item['generated_text']) : '';
                        if (function_exists('mb_strlen') && function_exists('mb_substr')) {
                            if (mb_strlen($current_preview, 'UTF-8') > 500) {
                                $current_preview = rtrim(mb_substr($current_preview, 0, 499, 'UTF-8')) . '…';
                            }
                            if (mb_strlen($generated_preview, 'UTF-8') > 500) {
                                $generated_preview = rtrim(mb_substr($generated_preview, 0, 499, 'UTF-8')) . '…';
                            }
                        } else {
                            if (strlen($current_preview) > 500) {
                                $current_preview = rtrim(substr($current_preview, 0, 499)) . '…';
                            }
                            if (strlen($generated_preview) > 500) {
                                $generated_preview = rtrim(substr($generated_preview, 0, 499)) . '…';
                            }
                        }
                        $review_view_id = 'ucg-review-item-' . (int) $item['id'];
                        ?>
                        <tr>
                            <td>
                                <?php if ((string) $item['status'] === 'generated') : ?>
                                    <input type="checkbox" class="ucg-review-checkbox" name="item_ids[]" value="<?php echo (int) $item['id']; ?>">
                                <?php endif; ?>
                            </td>
                            <td>#<?php echo (int) $item['id']; ?></td>
                            <td>#<?php echo (int) $item['run_id']; ?></td>
                            <td>
                                <strong>#<?php echo $post_id; ?></strong><br>
                                <?php echo esc_html((string) $item['post_title']); ?><br>
                                <code><?php echo esc_html((string) $item['target_field']); ?></code>
                            </td>
                            <td>
                                <div class="ucg-review-preview"><?php echo esc_html($current_preview !== '' ? $current_preview : '—'); ?></div>
                            </td>
                            <td>
                                <div class="ucg-review-preview"><?php echo esc_html($generated_preview !== '' ? $generated_preview : '—'); ?></div>
                            </td>
                            <td>
                                <button
                                    type="button"
                                    class="button ucg-review-view-btn"
                                    data-view-id="<?php echo esc_attr($review_view_id); ?>"
                                >
                                    Просмотр
                                </button>

                                <div id="<?php echo esc_attr($review_view_id); ?>" class="ucg-review-source" hidden>
                                    <div class="ucg-review-source-current"><?php echo esc_html((string) $current_value); ?></div>
                                    <div class="ucg-review-source-generated"><?php echo esc_html((string) $item['generated_text']); ?></div>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>

            <div class="ucg-actions-row ucg-review-bulk-bar">
                <select name="bulk_action" class="ucg-enhanced-select ucg-review-bulk-select" data-search-enabled="false">
                    <option value="">Выберите действие</option>
                    <option value="approve">Одобрить</option>
                    <option value="reject">Отклонить</option>
                </select>
                <button type="submit" class="button button-primary">Применить</button>
            </div>

            <?php if ($total_pages > 1) : ?>
                <div class="tablenav ucg-review-pagination">
                    <div class="tablenav-pages">
                        <?php
                        echo wp_kses_post(
                            paginate_links(array(
                                'base' => add_query_arg(array('paged' => '%#%')),
                                'format' => '',
                                'current' => $paged,
                                'total' => $total_pages,
                                'prev_text' => '&laquo;',
                                'next_text' => '&raquo;',
                            ))
                        );
                        ?>
                    </div>
                </div>
            <?php endif; ?>
        </section>
    </form>

    <div class="ucg-review-modal" id="ucg-review-modal" hidden>
        <div class="ucg-review-modal__backdrop" data-close-review-modal></div>
        <div class="ucg-review-modal__dialog" role="dialog" aria-modal="true" aria-labelledby="ucg-review-modal-title">
            <div class="ucg-review-modal__head">
                <h3 id="ucg-review-modal-title">Просмотр результата</h3>
                <button type="button" class="button ucg-btn--ghost" data-close-review-modal>Закрыть</button>
            </div>

            <div class="ucg-review-tabs">
                <button type="button" class="ucg-review-tab is-active" data-review-tab="generated">Сгенерированный текст</button>
                <button type="button" class="ucg-review-tab" data-review-tab="current">Текущее значение</button>
            </div>

            <div class="ucg-review-modal__body">
                <div class="ucg-review-pane is-active" data-review-pane="generated">
                    <div class="ucg-review-content" id="ucg-review-generated-content"></div>
                </div>
                <div class="ucg-review-pane" data-review-pane="current">
                    <div class="ucg-review-content" id="ucg-review-current-content"></div>
                </div>
            </div>
        </div>
    </div>
</div>
