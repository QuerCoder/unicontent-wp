<div class="wrap ucg-wrap">
    <?php $this->render_admin_notice(); ?>
    <?php include UCG_PLUGIN_DIR . 'templates/partials/plugin-header.php'; ?>

    <div class="ucg-page-head ucg-page-head--ready">
        <div class="ucg-page-head__meta">
            <h1><?php esc_html_e('Каталог готовых шаблонов', 'unicontent-ai-generator'); ?></h1>
            <p class="ucg-muted"><?php esc_html_e('Запрос к библиотеке UNICONTENT выполняется только на этой странице. Выберите шаблон и установите его в нужный post type.', 'unicontent-ai-generator'); ?></p>
        </div>
        <div class="ucg-page-head__actions">
            <a class="button button-small ucg-btn--secondary" href="<?php echo esc_url(admin_url('admin.php?page=ucg-templates')); ?>">
                <?php esc_html_e('Назад к шаблонам', 'unicontent-ai-generator'); ?>
            </a>
        </div>
    </div>

    <section class="ucg-card ucg-ready-templates ucg-ready-templates--page">
        <div class="ucg-ready-templates__head">
            <div class="ucg-ready-templates__head-main">
                <h2><?php esc_html_e('Готовые шаблоны', 'unicontent-ai-generator'); ?></h2>
                <p class="ucg-muted"><?php esc_html_e('Фильтруйте по типу и устанавливайте нужные карточки в один клик.', 'unicontent-ai-generator'); ?></p>
            </div>

            <div class="ucg-ready-templates__controls">
                <label class="ucg-field ucg-ready-templates__filter" for="ucg-ready-type-filter">
                    <span><?php esc_html_e('Тип', 'unicontent-ai-generator'); ?></span>
                    <select id="ucg-ready-type-filter" class="ucg-enhanced-select" data-search-enabled="false" data-placeholder="<?php echo esc_attr__('Все типы', 'unicontent-ai-generator'); ?>">
                        <option value=""><?php esc_html_e('Все типы', 'unicontent-ai-generator'); ?></option>
                        <?php foreach ($ready_template_types as $ready_type_item) : ?>
                            <?php
                            $ready_type_slug = isset($ready_type_item['slug']) ? (string) $ready_type_item['slug'] : '';
                            $ready_type_name = isset($ready_type_item['name']) ? (string) $ready_type_item['name'] : $ready_type_slug;
                            if ($ready_type_slug === '') {
                                continue;
                            }
                            ?>
                            <option value="<?php echo esc_attr($ready_type_slug); ?>"><?php echo esc_html($ready_type_name); ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
            </div>
        </div>

        <?php if (empty($ready_prompts)) : ?>
            <?php if (!empty($ready_prompts_error)) : ?>
                <div class="ucg-api-status">
                    <div class="ucg-status-message ucg-status-message--error">
                        <?php
                        echo esc_html(
                            sprintf(
                                __('Не удалось загрузить готовые шаблоны: %s', 'unicontent-ai-generator'),
                                (string) $ready_prompts_error
                            )
                        );
                        ?>
                    </div>
                </div>
            <?php else : ?>
                <p class="ucg-muted"><?php esc_html_e('Подходящих готовых шаблонов не найдено.', 'unicontent-ai-generator'); ?></p>
            <?php endif; ?>
        <?php else : ?>
            <div class="ucg-ready-grid">
                <?php foreach ($ready_prompts as $ready_prompt_item) : ?>
                    <?php
                    $ready_prompt_id = isset($ready_prompt_item['id']) ? (int) $ready_prompt_item['id'] : 0;
                    if ($ready_prompt_id <= 0) {
                        continue;
                    }
                    $ready_prompt_key = (string) $ready_prompt_id;
                    $ready_prompt_name = isset($ready_prompt_item['name']) ? (string) $ready_prompt_item['name'] : ('#' . $ready_prompt_id);
                    $ready_prompt_summary = isset($ready_prompt_item['summary']) ? trim((string) $ready_prompt_item['summary']) : '';
                    $ready_prompt_body = isset($ready_prompt_item['body']) ? (string) $ready_prompt_item['body'] : '';
                    $ready_prompt_type = isset($ready_prompt_item['type']) && is_array($ready_prompt_item['type']) ? $ready_prompt_item['type'] : array();
                    $ready_prompt_type_name = isset($ready_prompt_type['name']) ? (string) $ready_prompt_type['name'] : '';
                    $ready_prompt_type_slug = isset($ready_prompt_type['slug']) ? (string) $ready_prompt_type['slug'] : '';
                    $ready_prompt_category = isset($ready_prompt_item['category']) && is_array($ready_prompt_item['category']) ? $ready_prompt_item['category'] : array();
                    $ready_prompt_category_name = isset($ready_prompt_category['name']) ? trim((string) $ready_prompt_category['name']) : '';
                    $ready_prompt_preview_source = $ready_prompt_summary !== '' ? $ready_prompt_summary : wp_strip_all_tags($ready_prompt_body);
                    $ready_prompt_preview = trim((string) preg_replace('/\s+/u', ' ', (string) $ready_prompt_preview_source));
                    if ($ready_prompt_preview !== '') {
                        if (function_exists('mb_substr')) {
                            $ready_prompt_preview = mb_substr($ready_prompt_preview, 0, 180, 'UTF-8');
                        } else {
                            $ready_prompt_preview = substr($ready_prompt_preview, 0, 180);
                        }
                    }
                    $ready_install = isset($ready_installed_templates[$ready_prompt_key]) && is_array($ready_installed_templates[$ready_prompt_key])
                        ? $ready_installed_templates[$ready_prompt_key]
                        : null;
                    $ready_is_installed = !empty($ready_install);
                    ?>
                    <article class="ucg-ready-card" data-ready-type="<?php echo esc_attr($ready_prompt_type_slug); ?>">
                        <div class="ucg-ready-card__head">
                            <h3><?php echo esc_html($ready_prompt_name); ?></h3>
                            <div class="ucg-ready-card__chips">
                                <?php if ($ready_prompt_type_name !== '') : ?>
                                    <span class="ucg-chip"><?php echo esc_html($ready_prompt_type_name); ?></span>
                                <?php endif; ?>
                                <?php if ($ready_prompt_category_name !== '') : ?>
                                    <span class="ucg-chip"><?php echo esc_html($ready_prompt_category_name); ?></span>
                                <?php endif; ?>
                            </div>
                        </div>

                        <?php if ($ready_prompt_preview !== '') : ?>
                            <p class="ucg-ready-card__summary"><?php echo esc_html($ready_prompt_preview); ?></p>
                        <?php endif; ?>

                        <?php if ($ready_is_installed) : ?>
                            <p class="ucg-ready-card__meta">
                                <span class="ucg-chip ucg-chip--ok"><?php esc_html_e('Установлено', 'unicontent-ai-generator'); ?></span>
                                <span class="ucg-muted">
                                    <?php
                                    echo wp_kses_post(
                                        sprintf(
                                            __('Шаблон #%1$d, post type: <code>%2$s</code>', 'unicontent-ai-generator'),
                                            (int) $ready_install['template_id'],
                                            esc_html((string) $ready_install['post_type'])
                                        )
                                    );
                                    ?>
                                </span>
                            </p>
                        <?php endif; ?>

                        <div class="ucg-ready-card__actions">
                            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                                <?php wp_nonce_field('ucg_install_ready_template'); ?>
                                <input type="hidden" name="action" value="ucg_install_ready_template">
                                <input type="hidden" name="redirect_page" value="ucg-ready-templates">
                                <input type="hidden" name="prompt_id" value="<?php echo (int) $ready_prompt_id; ?>">
                                <input type="hidden" name="post_type" value="<?php echo esc_attr($selected_post_type); ?>">
                                <button type="submit" class="button button-primary">
                                    <?php echo esc_html($ready_is_installed ? __('Переустановить', 'unicontent-ai-generator') : __('Установить', 'unicontent-ai-generator')); ?>
                                </button>
                            </form>

                            <?php if ($ready_is_installed) : ?>
                                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" onsubmit="return confirm('<?php echo esc_js(__('Удалить установленный шаблон?', 'unicontent-ai-generator')); ?>');">
                                    <?php wp_nonce_field('ucg_delete_ready_template'); ?>
                                    <input type="hidden" name="action" value="ucg_delete_ready_template">
                                    <input type="hidden" name="redirect_page" value="ucg-ready-templates">
                                    <input type="hidden" name="prompt_id" value="<?php echo (int) $ready_prompt_id; ?>">
                                    <input type="hidden" name="post_type" value="<?php echo esc_attr($selected_post_type); ?>">
                                    <button type="submit" class="button"><?php esc_html_e('Удалить', 'unicontent-ai-generator'); ?></button>
                                </form>
                            <?php endif; ?>
                        </div>
                    </article>
                <?php endforeach; ?>
            </div>
            <p class="ucg-muted ucg-ready-empty" id="ucg-ready-empty" hidden><?php esc_html_e('По выбранному типу шаблонов не найдено.', 'unicontent-ai-generator'); ?></p>
        <?php endif; ?>
    </section>
</div>
