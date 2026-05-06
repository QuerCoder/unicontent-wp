<?php

if (!defined('ABSPATH')) {
    exit;
}

if (!class_exists('UCG_Admin')) {
    class UCG_Admin {
        const NOTICE_QUERY = 'ucg_notice';
        const NOTICE_TYPE_QUERY = 'ucg_notice_type';
        const READY_TEMPLATE_INSTALLS_OPTION = 'ucg_ready_template_installs_v1';
        const DEFAULT_GENERATION_SCENARIO = 'field_update';
        protected $last_prompt_library_error = '';

        public function hooks() {
            add_action('admin_menu', array($this, 'add_menu'));
            add_action('admin_init', array($this, 'register_settings'));
            add_action('admin_enqueue_scripts', array($this, 'enqueue_assets'));

            add_action('wp_ajax_ucg_test_connection', array($this, 'ajax_test_connection'));
            add_action('wp_ajax_ucg_get_balance', array($this, 'ajax_get_balance'));
            add_action('wp_ajax_ucg_get_tokens', array($this, 'ajax_get_tokens'));
            add_action('wp_ajax_ucg_save_api_key', array($this, 'ajax_save_api_key'));
            add_action('wp_ajax_ucg_delete_api_key', array($this, 'ajax_delete_api_key'));
            add_action('wp_ajax_ucg_save_batch_size', array($this, 'ajax_save_batch_size'));
            add_action('wp_ajax_ucg_save_style_defaults', array($this, 'ajax_save_style_defaults'));
            add_action('wp_ajax_ucg_wizard_schema', array($this, 'ajax_wizard_schema'));
            add_action('wp_ajax_ucg_wizard_preview', array($this, 'ajax_wizard_preview'));
            add_action('wp_ajax_ucg_wizard_load_template', array($this, 'ajax_wizard_load_template'));
            add_action('wp_ajax_ucg_wizard_create_run', array($this, 'ajax_wizard_create_run'));
            add_action('wp_ajax_ucg_wizard_quote', array($this, 'ajax_wizard_quote'));
            add_action('wp_ajax_ucg_wizard_example', array($this, 'ajax_wizard_example'));
            add_action('wp_ajax_ucg_run_status', array($this, 'ajax_run_status'));
            add_action('wp_ajax_ucg_process_now', array($this, 'ajax_process_now'));
            add_action('wp_ajax_ucg_search_runs', array($this, 'ajax_search_runs'));

            add_action('admin_post_ucg_save_template', array($this, 'handle_save_template'));
            add_action('admin_post_ucg_delete_template', array($this, 'handle_delete_template'));
            add_action('admin_post_ucg_install_ready_template', array($this, 'handle_install_ready_template'));
            add_action('admin_post_ucg_delete_ready_template', array($this, 'handle_delete_ready_template'));
            add_action('admin_post_ucg_create_run', array($this, 'handle_create_run'));
            add_action('admin_post_ucg_review_bulk', array($this, 'handle_review_bulk'));
            add_action('admin_post_ucg_process_now', array($this, 'handle_process_now'));
        }

        public function add_menu() {
            $review_pending_count = UCG_DB::count_review_items(0, 'generated');
            $review_badge = $this->build_menu_counter_badge($review_pending_count);

            add_menu_page(
                __('AI-Контент', 'unicontent-ai-generator'),
                __('AI-Контент', 'unicontent-ai-generator') . $review_badge,
                'manage_options',
                'ucg-dashboard',
                array($this, 'render_dashboard'),
                'dashicons-welcome-write-blog',
                57
            );

            add_submenu_page('ucg-dashboard', __('Дашборд', 'unicontent-ai-generator'), __('Дашборд', 'unicontent-ai-generator'), 'manage_options', 'ucg-dashboard', array($this, 'render_dashboard'));
            add_submenu_page('ucg-dashboard', __('Шаблоны', 'unicontent-ai-generator'), __('Шаблоны', 'unicontent-ai-generator'), 'manage_options', 'ucg-templates', array($this, 'render_templates'));
            add_submenu_page('ucg-dashboard', __('Генерация', 'unicontent-ai-generator'), __('Генерация', 'unicontent-ai-generator'), 'manage_options', 'ucg-generate', array($this, 'render_generate'));
            add_submenu_page('ucg-dashboard', __('Проверка', 'unicontent-ai-generator'), __('Проверка', 'unicontent-ai-generator') . $review_badge, 'manage_options', 'ucg-review', array($this, 'render_review'));
            add_submenu_page('ucg-dashboard', __('История', 'unicontent-ai-generator'), __('История', 'unicontent-ai-generator'), 'manage_options', 'ucg-runs', array($this, 'render_runs'));
            add_submenu_page('ucg-dashboard', __('Логи', 'unicontent-ai-generator'), __('Логи', 'unicontent-ai-generator'), 'manage_options', 'ucg-logs', array($this, 'render_logs'));
            add_submenu_page('ucg-dashboard', __('Настройки', 'unicontent-ai-generator'), __('Настройки', 'unicontent-ai-generator'), 'manage_options', 'ucg-settings', array($this, 'render_settings'));
            add_submenu_page(null, __('Готовые шаблоны', 'unicontent-ai-generator'), __('Готовые шаблоны', 'unicontent-ai-generator'), 'manage_options', 'ucg-ready-templates', array($this, 'render_ready_templates'));
            add_submenu_page(null, __('Прогресс запуска', 'unicontent-ai-generator'), __('Прогресс запуска', 'unicontent-ai-generator'), 'manage_options', 'ucg-run-progress', array($this, 'render_run_progress'));
        }

        public function register_settings() {
            register_setting(
                'ucg_settings_group',
                UCG_Settings::OPTION_KEY,
                array(
                    'type' => 'array',
                    'sanitize_callback' => array('UCG_Settings', 'sanitize'),
                    'default' => UCG_Settings::defaults(),
                )
            );
        }

        public function enqueue_assets($hook) {
            $hook = (string) $hook;
            if (strpos($hook, 'ucg-') === false && strpos($hook, 'ucg_dashboard') === false) {
                return;
            }

            $script_deps = array('jquery', 'ucg-tom-select');
            wp_enqueue_style('ucg-tom-select', UCG_PLUGIN_URL . 'assets/vendor/tom-select/tom-select.css', array(), $this->asset_version('assets/vendor/tom-select/tom-select.css'));
            wp_enqueue_script('ucg-tom-select', UCG_PLUGIN_URL . 'assets/vendor/tom-select/tom-select.complete.min.js', array(), $this->asset_version('assets/vendor/tom-select/tom-select.complete.min.js'), true);
            wp_enqueue_style('ucg-admin', UCG_PLUGIN_URL . 'assets/admin.css', array(), $this->asset_version('assets/admin.css'));
            wp_enqueue_script('ucg-admin', UCG_PLUGIN_URL . 'assets/admin.js', $script_deps, $this->asset_version('assets/admin.js'), true);

            wp_localize_script(
                'ucg-admin',
                'ucgAdmin',
                array(
                    'ajaxUrl' => admin_url('admin-ajax.php'),
                    'nonce' => wp_create_nonce('ucg_admin_nonce'),
                    'strings' => array(
                        'testing' => __('Проверяем подключение...', 'unicontent-ai-generator'),
                        'loading' => __('Обновляем баланс...', 'unicontent-ai-generator'),
                        'saving' => __('Сохраняем API ключ...', 'unicontent-ai-generator'),
                        'saving_batch' => __('Сохраняем настройки...', 'unicontent-ai-generator'),
                        'starting_run' => __('Создаем запуск...', 'unicontent-ai-generator'),
                        'polling_run' => __('Обновляем прогресс...', 'unicontent-ai-generator'),
                    ),
                    'i18n' => class_exists('UCG_I18n') ? UCG_I18n::get_js_i18n_map() : array(),
                ),
            );
        }

        public function render_dashboard() {
            $stats = UCG_DB::count_runs_by_status();
            $recent_runs = UCG_DB::get_runs(8, 0);
            $settings = UCG_Settings::get();
            $masked_api_key = UCG_Settings::get_masked_api_key();
            $api_ready = UCG_Settings::has_valid_api_key();
            $header_balance = $this->get_header_balance_snapshot();
            ob_start();
            include UCG_PLUGIN_DIR . 'templates/page-dashboard.php';
            $html = (string) ob_get_clean();
            echo class_exists('UCG_I18n') ? UCG_I18n::translate_markup($html) : $html;
        }

        public function render_settings() {
            $settings = UCG_Settings::get();
            $masked_api_key = UCG_Settings::get_masked_api_key();
            $api_ready = UCG_Settings::has_valid_api_key();
            $header_balance = $this->get_header_balance_snapshot();
            ob_start();
            include UCG_PLUGIN_DIR . 'templates/page-settings.php';
            $html = (string) ob_get_clean();
            echo class_exists('UCG_I18n') ? UCG_I18n::translate_markup($html) : $html;
        }

        public function render_templates() {
            $post_types = UCG_Tokens::get_post_types_for_ui();
            $selected_post_type = sanitize_key($this->get_request_string($_GET, 'post_type', UCG_Tokens::get_default_post_type()));
            if ($selected_post_type === '' || !post_type_exists($selected_post_type)) {
                $selected_post_type = UCG_Tokens::get_default_post_type();
            }
            $show_legacy_template_editor = $this->get_request_int($_GET, 'legacy_editor', 0) === 1;

            $edit_template_id = $show_legacy_template_editor ? $this->get_request_int($_GET, 'edit', 0) : 0;
            $editing_template = $edit_template_id > 0 ? UCG_DB::get_template($edit_template_id) : null;
            if ($editing_template && !empty($editing_template['post_type'])) {
                $selected_post_type = sanitize_key((string) $editing_template['post_type']);
            }
            $template_scenario_options = $this->get_generation_scenario_options();
            $editing_template_scenario = self::DEFAULT_GENERATION_SCENARIO;
            if ($editing_template && !empty($editing_template['scenario'])) {
                $editing_template_scenario = $this->normalize_generation_scenario((string) $editing_template['scenario'], $selected_post_type);
            }
            $editing_template_payload = $this->decode_template_payload(
                $editing_template_scenario,
                $editing_template && isset($editing_template['body']) ? (string) $editing_template['body'] : ''
            );
            if ($editing_template) {
                $this->maybe_upgrade_template_payload_to_v3($editing_template, $editing_template_scenario, $editing_template_payload);
            }
            $editing_base_prompt = isset($editing_template_payload['base_prompt']) ? (string) $editing_template_payload['base_prompt'] : '';
            $editing_prompt_blocks = $this->build_editor_prompt_blocks($editing_template_scenario, $editing_template_payload);
            $editing_template_fields = $this->normalize_template_fields(
                isset($editing_template_payload['fields']) && is_array($editing_template_payload['fields'])
                    ? $editing_template_payload['fields']
                    : array(),
                $editing_template_scenario
            );
            $template_text_length_data = $this->get_text_length_options();
            $template_text_length_options = isset($template_text_length_data['options']) && is_array($template_text_length_data['options'])
                ? $template_text_length_data['options']
                : array();
            $template_default_length_option_id = isset($template_text_length_data['default_option_id'])
                ? (int) $template_text_length_data['default_option_id']
                : 0;
            $template_field_editor_presets = array(
                'seo_tags' => $this->normalize_template_fields(
                    $this->get_ai_field_presets_for_post_type($selected_post_type, 'seo_tags'),
                    'seo_tags'
                ),
                'post_fields' => $this->normalize_template_fields(
                    $this->get_ai_field_presets_for_post_type($selected_post_type, 'post_fields'),
                    'post_fields'
                ),
                'product_fields' => $this->normalize_template_fields(
                    $this->get_ai_field_presets_for_post_type($selected_post_type, 'product_fields'),
                    'product_fields'
                ),
            );

            $templates = UCG_DB::get_templates();
            $tokens = UCG_Tokens::get_prompt_tokens_for_post_type($selected_post_type);
            $header_balance = $this->get_header_balance_snapshot();

            ob_start();
            include UCG_PLUGIN_DIR . 'templates/page-templates.php';
            $html = (string) ob_get_clean();
            echo class_exists('UCG_I18n') ? UCG_I18n::translate_markup($html) : $html;
        }

        public function render_ready_templates() {
            $post_types = UCG_Tokens::get_post_types_for_ui();
            $selected_post_type = sanitize_key($this->get_request_string($_GET, 'post_type', UCG_Tokens::get_default_post_type()));
            if ($selected_post_type === '' || !post_type_exists($selected_post_type)) {
                $selected_post_type = UCG_Tokens::get_default_post_type();
            }

            $ready_prompts = $this->get_ready_wordpress_prompts(false);
            $ready_template_types = $this->get_prompt_type_filters($ready_prompts);
            $ready_installed_templates = $this->get_ready_installed_templates_map();
            $ready_prompts_error = $this->get_last_prompt_library_error();
            $header_balance = $this->get_header_balance_snapshot();

            ob_start();
            include UCG_PLUGIN_DIR . 'templates/page-ready-templates.php';
            $html = (string) ob_get_clean();
            echo class_exists('UCG_I18n') ? UCG_I18n::translate_markup($html) : $html;
        }

        public function render_generate() {
            $post_types = UCG_Tokens::get_post_types_for_ui();
            $post_type = sanitize_key($this->get_request_string($_GET, 'post_type', UCG_Tokens::get_default_post_type()));
            if ($post_type === '' || !post_type_exists($post_type)) {
                $post_type = UCG_Tokens::get_default_post_type();
            }
            $scenario = $this->normalize_generation_scenario(
                $this->get_request_string($_GET, 'scenario', self::DEFAULT_GENERATION_SCENARIO),
                $post_type
            );
            $wizard_prefill = array();
            $repeat_run_id = $this->get_request_int($_GET, 'repeat_run_id', 0);
            if ($repeat_run_id > 0) {
                $repeat_run = UCG_DB::get_run($repeat_run_id);
                if (is_array($repeat_run)) {
                    $run_post_type = isset($repeat_run['post_type']) ? sanitize_key((string) $repeat_run['post_type']) : '';
                    if ($run_post_type !== '' && post_type_exists($run_post_type)) {
                        $post_type = $run_post_type;
                    }

                    $run_options = array();
                    $run_options_json = isset($repeat_run['options_json']) ? (string) $repeat_run['options_json'] : '';
                    if ($run_options_json !== '') {
                        $decoded_options = json_decode($run_options_json, true);
                        if (is_array($decoded_options)) {
                            $run_options = $decoded_options;
                        }
                    }
                    $prefill_scope = isset($run_options['scope']) ? sanitize_key((string) $run_options['scope']) : 'selected';
                    if (!in_array($prefill_scope, array('selected', 'filtered', 'create_new'), true)) {
                        $prefill_scope = 'selected';
                    }
                    $prefill_create_topics = $this->normalize_create_topics(
                        isset($run_options['create_topics']) ? $run_options['create_topics'] : array(),
                        1000
                    );
                    $prefill_create_count = isset($run_options['create_count']) ? (int) $run_options['create_count'] : 0;
                    if ($prefill_scope === 'create_new' && !empty($prefill_create_topics)) {
                        $prefill_create_count = count($prefill_create_topics);
                    }
                    if ($prefill_scope === 'create_new' && $prefill_create_count <= 0) {
                        $prefill_create_count = isset($repeat_run['total_items']) ? (int) $repeat_run['total_items'] : 0;
                    }
                    if ($prefill_create_count <= 0) {
                        $prefill_create_count = 10;
                    }

                    $run_scenario = isset($run_options['scenario'])
                        ? $this->normalize_generation_scenario((string) $run_options['scenario'], $post_type)
                        : $scenario;
                    $scenario = $run_scenario;
                    if ($prefill_scope === 'create_new' && !$this->scenario_supports_create_new_mode($scenario)) {
                        $prefill_scope = 'selected';
                    }

                    $wizard_prefill = array(
                        'repeat_run_id' => $repeat_run_id,
                        'post_type' => $post_type,
                        'scenario' => $scenario,
                        'target_field' => isset($repeat_run['target_field']) ? (string) $repeat_run['target_field'] : '',
                        'template_id' => isset($repeat_run['template_id']) ? (int) $repeat_run['template_id'] : 0,
                        'model' => isset($run_options['model']) ? $this->normalize_model_identifier((string) $run_options['model']) : 'auto',
                        'length_option_id' => isset($run_options['length_option_id']) ? (int) $run_options['length_option_id'] : 0,
                        'vary_length' => !empty($run_options['vary_length']) ? 1 : 0,
                        'selection_mode' => $prefill_scope,
                        'create_count' => $prefill_create_count,
                        'create_topics' => $prefill_create_topics,
                        'create_topics_text' => implode("\n", $prefill_create_topics),
                        'filters' => isset($run_options['filters']) && is_array($run_options['filters']) ? $run_options['filters'] : array(),
                        'items_per_post' => isset($run_options['items_per_post']) ? (int) $run_options['items_per_post'] : 1,
                        'rating_min' => isset($run_options['rating_min']) ? (int) $run_options['rating_min'] : 1,
                        'rating_max' => isset($run_options['rating_max']) ? (int) $run_options['rating_max'] : 5,
                        'publish_date_from' => isset($run_options['publish_date_from']) ? (string) $run_options['publish_date_from'] : '',
                        'publish_date_to' => isset($run_options['publish_date_to']) ? (string) $run_options['publish_date_to'] : '',
                        'style_language' => isset($run_options['style_language']) ? sanitize_key((string) $run_options['style_language']) : 'auto',
                        'style_tone' => isset($run_options['style_tone']) ? sanitize_key((string) $run_options['style_tone']) : 'neutral',
                        'template_body' => isset($run_options['template_body']) ? (string) $run_options['template_body'] : '',
                        'template_body_seo_title' => isset($run_options['seo_title_prompt']) ? (string) $run_options['seo_title_prompt'] : '',
                        'template_body_seo_description' => isset($run_options['seo_description_prompt']) ? (string) $run_options['seo_description_prompt'] : '',
                        'ai_fields' => isset($run_options['ai_fields']) && is_array($run_options['ai_fields']) ? $this->normalize_generation_fields($run_options['ai_fields'], 'ai', $scenario) : array(),
                        'static_fields' => isset($run_options['static_fields']) && is_array($run_options['static_fields']) ? $this->normalize_generation_fields($run_options['static_fields'], 'static', $scenario) : array(),
                    );
                }
            }
            if ($repeat_run_id <= 0) {
                $prefill_template_id = $this->get_request_int($_GET, 'template_id', 0);
                if ($prefill_template_id > 0) {
                    $prefill_template = UCG_DB::get_template($prefill_template_id);
                    if (is_array($prefill_template)) {
                        $template_post_type = isset($prefill_template['post_type']) ? sanitize_key((string) $prefill_template['post_type']) : '';
                        if ($template_post_type !== '' && post_type_exists($template_post_type)) {
                            $post_type = $template_post_type;
                        }

                        $template_scenario = isset($prefill_template['scenario']) ? sanitize_key((string) $prefill_template['scenario']) : $scenario;
                        $template_scenario = $this->normalize_generation_scenario($template_scenario, $post_type);
                        if ($template_scenario !== '') {
                            $scenario = $template_scenario;
                        }

                        $wizard_prefill = array(
                            'post_type' => $post_type,
                            'scenario' => $scenario,
                            'template_id' => $prefill_template_id,
                        );
                    }
                }
            }
            if ($this->scenario_requires_product_post_type($scenario) && post_type_exists('product')) {
                $post_type = 'product';
                if (!empty($wizard_prefill)) {
                    $wizard_prefill['post_type'] = 'product';
                }
            } elseif ($scenario === 'post_fields' && $post_type === 'product' && post_type_exists('post')) {
                $post_type = 'post';
                if (!empty($wizard_prefill)) {
                    $wizard_prefill['post_type'] = 'post';
                }
            }

            $api_ready = UCG_Settings::has_valid_api_key();
            $wizard_schema = $this->build_wizard_schema($post_type, false, $scenario);
            $target_fields = isset($wizard_schema['target_fields']) && is_array($wizard_schema['target_fields'])
                ? $wizard_schema['target_fields']
                : array();
            if (empty($target_fields)) {
                $target_fields = $this->get_target_fields_for_scenario($post_type, $scenario);
            }
            $text_length_options = isset($wizard_schema['text_length_options']) && is_array($wizard_schema['text_length_options'])
                ? $wizard_schema['text_length_options']
                : array();
            if (empty($text_length_options)) {
                $text_length_options = array(
                    array('id' => 1, 'name' => __('Короткое', 'unicontent-ai-generator'), 'max_chars' => 500, 'credits_cost' => 1),
                    array('id' => 2, 'name' => __('Стандартное', 'unicontent-ai-generator'), 'max_chars' => 1500, 'credits_cost' => 3),
                    array('id' => 3, 'name' => __('Расширенное', 'unicontent-ai-generator'), 'max_chars' => 3000, 'credits_cost' => 6),
                    array('id' => 4, 'name' => __('Большое', 'unicontent-ai-generator'), 'max_chars' => 5000, 'credits_cost' => 10),
                );
            }
            $default_length_option_id = isset($wizard_schema['default_length_option_id'])
                ? (int) $wizard_schema['default_length_option_id']
                : 0;
            if ($default_length_option_id <= 0 && !empty($text_length_options[0]['id'])) {
                $default_length_option_id = (int) $text_length_options[0]['id'];
            }
            $target_field_label = isset($wizard_schema['target_field_label']) && trim((string) $wizard_schema['target_field_label']) !== ''
                ? (string) $wizard_schema['target_field_label']
                : __('Целевое поле', 'unicontent-ai-generator');
            $scenario_options = isset($wizard_schema['scenario_options']) && is_array($wizard_schema['scenario_options'])
                ? $wizard_schema['scenario_options']
                : $this->get_generation_scenario_options();
            $wizard_templates = isset($wizard_schema['templates']) && is_array($wizard_schema['templates']) ? $wizard_schema['templates'] : array();
            $wizard_default_template_id = 0;
            foreach ($wizard_templates as $wizard_template_item) {
                if (!is_array($wizard_template_item)) {
                    continue;
                }
                $template_id = isset($wizard_template_item['id']) ? (int) $wizard_template_item['id'] : 0;
                if ($template_id <= 0) {
                    continue;
                }
                if ($wizard_default_template_id <= 0 && !empty($wizard_template_item['is_default'])) {
                    $wizard_default_template_id = $template_id;
                }
            }
            $header_balance = $this->get_header_balance_snapshot();

            ob_start();
            include UCG_PLUGIN_DIR . 'templates/page-generate.php';
            $html = (string) ob_get_clean();
            echo class_exists('UCG_I18n') ? UCG_I18n::translate_markup($html) : $html;
        }

        public function render_review() {
            $run_id = $this->get_request_int($_GET, 'run_id', 0);
            $status = sanitize_key($this->get_request_string($_GET, 'status', 'generated'));
            $status = in_array($status, array('generated', 'approved', 'rejected', 'failed'), true) ? $status : 'generated';

            $paged = max(1, $this->get_request_int($_GET, 'paged', 1));
            $per_page = 20;
            $offset = ($paged - 1) * $per_page;

            $items = UCG_DB::get_review_items($run_id, $status, $per_page, $offset);
            $total_items = UCG_DB::count_review_items($run_id, $status);
            $total_pages = max(1, (int) ceil($total_items / $per_page));
            $runs = UCG_DB::get_runs_for_select();
            $header_balance = $this->get_header_balance_snapshot();

            ob_start();
            include UCG_PLUGIN_DIR . 'templates/page-review.php';
            $html = (string) ob_get_clean();
            echo class_exists('UCG_I18n') ? UCG_I18n::translate_markup($html) : $html;
        }

        public function render_runs() {
            $runs = UCG_DB::get_runs(100, 0);
            $header_balance = $this->get_header_balance_snapshot();
            ob_start();
            include UCG_PLUGIN_DIR . 'templates/page-runs.php';
            $html = (string) ob_get_clean();
            echo class_exists('UCG_I18n') ? UCG_I18n::translate_markup($html) : $html;
        }

        public function render_run_progress() {
            $run_id = $this->get_request_int($_GET, 'run_id', 0);
            $run = $run_id > 0 ? UCG_DB::get_run($run_id) : null;

            ob_start();
            include UCG_PLUGIN_DIR . 'templates/page-run-progress.php';
            $html = (string) ob_get_clean();
            echo class_exists('UCG_I18n') ? UCG_I18n::translate_markup($html) : $html;
        }

        public function render_logs() {
            $filters = array(
                'level' => sanitize_key($this->get_request_string($_GET, 'level', '')),
                'area' => sanitize_key($this->get_request_string($_GET, 'area', '')),
                'run_id' => $this->get_request_int($_GET, 'run_id', 0),
                'since' => sanitize_text_field($this->get_request_string($_GET, 'since', '')),
                'until' => sanitize_text_field($this->get_request_string($_GET, 'until', '')),
            );
            $logs = UCG_DB::get_logs($filters, 400);

            $log_areas = array(
                '' => __('Все', 'unicontent-ai-generator'),
                'general' => __('Общие', 'unicontent-ai-generator'),
                'wizard' => __('Мастер', 'unicontent-ai-generator'),
                'api' => __('API', 'unicontent-ai-generator'),
                'generator' => __('Генератор', 'unicontent-ai-generator'),
                'updater' => __('Обновления', 'unicontent-ai-generator'),
                'settings' => __('Настройки', 'unicontent-ai-generator'),
            );
            $log_levels = array(
                '' => __('Все', 'unicontent-ai-generator'),
                'info' => __('Info', 'unicontent-ai-generator'),
                'warn' => __('Warn', 'unicontent-ai-generator'),
                'error' => __('Error', 'unicontent-ai-generator'),
            );

            $diagnostics = $this->build_logs_diagnostics_snapshot();

            ob_start();
            include UCG_PLUGIN_DIR . 'templates/page-logs.php';
            $html = (string) ob_get_clean();
            echo class_exists('UCG_I18n') ? UCG_I18n::translate_markup($html) : $html;
        }

        public function handle_save_template() {
            $this->guard_admin_post('ucg_save_template');

            $template_id = $this->get_request_int($_POST, 'template_id', 0);
            $name = sanitize_text_field($this->get_request_string($_POST, 'name', ''));
            $post_type = sanitize_key($this->get_request_string($_POST, 'post_type', ''));
            $scenario = $this->normalize_generation_scenario(
                $this->get_request_string($_POST, 'scenario', self::DEFAULT_GENERATION_SCENARIO),
                $post_type
            );
            if ($this->scenario_requires_product_post_type($scenario)) {
                $post_type = 'product';
            }
            $base_prompt = sanitize_textarea_field($this->get_request_string($_POST, 'base_prompt', ''));
            $use_fields_editor = in_array($scenario, array('seo_tags', 'post_fields', 'product_fields'), true);
            $template_fields = $use_fields_editor ? $this->parse_template_fields_from_request($_POST, $scenario) : array();
            $prompt_blocks = array();
            if (!empty($template_fields)) {
                $derived_prompts = $this->derive_template_prompts_from_fields($scenario, $template_fields);
                $prompt_blocks = $this->build_editor_prompt_blocks(
                    $scenario,
                    array(
                        'fields' => $template_fields,
                    )
                );
            } else {
                $prompt_blocks = $this->parse_prompt_blocks_from_request($_POST, $scenario);
                $derived_prompts = $this->derive_template_prompts_from_blocks($scenario, $base_prompt, $prompt_blocks);
            }
            $body = isset($derived_prompts['body']) ? (string) $derived_prompts['body'] : '';
            $seo_title_prompt = isset($derived_prompts['seo_title_prompt']) ? (string) $derived_prompts['seo_title_prompt'] : '';
            $seo_description_prompt = isset($derived_prompts['seo_description_prompt']) ? (string) $derived_prompts['seo_description_prompt'] : '';
            $is_default = !empty($_POST['is_default']) ? 1 : 0;

            if ($name === '' || $post_type === '' || !post_type_exists($post_type)) {
                $this->redirect_with_notice('ucg-templates', __('Заполните название и post type.', 'unicontent-ai-generator'), 'error');
            }

            if ($use_fields_editor) {
                if (empty($template_fields) || !$this->has_enabled_ai_fields_with_prompt($template_fields)) {
                    $this->redirect_with_notice('ucg-templates', __('Добавьте хотя бы одно включенное поле с промптом.', 'unicontent-ai-generator'), 'error');
                }

                if ($scenario === 'seo_tags') {
                    $has_enabled_seo_target = false;
                    foreach ($template_fields as $field) {
                        if (!is_array($field)) {
                            continue;
                        }
                        if (array_key_exists('enabled', $field) && empty($field['enabled'])) {
                            continue;
                        }
                        $field_prompt = isset($field['prompt']) ? trim((string) $field['prompt']) : '';
                        if ($field_prompt === '') {
                            continue;
                        }
                        $field_target = $this->normalize_template_field_target(isset($field['target_field']) ? (string) $field['target_field'] : '');
                        if ($field_target === 'seo_field:title' || $field_target === 'seo_field:description') {
                            $has_enabled_seo_target = true;
                            break;
                        }
                    }
                    if (!$has_enabled_seo_target) {
                        $this->redirect_with_notice('ucg-templates', __('Для SEO шаблона включите хотя бы одно поле title/description.', 'unicontent-ai-generator'), 'error');
                    }
                }
            } elseif (trim($body) === '') {
                $this->redirect_with_notice('ucg-templates', __('Добавьте хотя бы один блок промпта.', 'unicontent-ai-generator'), 'error');
            }

            $encoded_body = $this->encode_template_payload(
                $scenario,
                $body,
                $seo_title_prompt,
                $seo_description_prompt,
                $prompt_blocks,
                $base_prompt,
                !empty($template_fields) ? $template_fields : null
            );

            if ($template_id > 0) {
                $ok = UCG_DB::update_template($template_id, $name, $post_type, $encoded_body, $is_default, 0, 0, $scenario);
                if (!$ok) {
                    $this->redirect_with_notice('ucg-templates', __('Не удалось обновить шаблон.', 'unicontent-ai-generator'), 'error');
                }
                $this->redirect_with_notice('ucg-templates', __('Шаблон обновлен.', 'unicontent-ai-generator'));
            }

            $created_id = UCG_DB::create_template($name, $post_type, $encoded_body, $is_default, 0, 0, $scenario);
            if ($created_id <= 0) {
                $this->redirect_with_notice('ucg-templates', __('Не удалось создать шаблон.', 'unicontent-ai-generator'), 'error');
            }

            $this->redirect_with_notice('ucg-templates', __('Шаблон создан.', 'unicontent-ai-generator'));
        }

        public function handle_delete_template() {
            $this->guard_admin_post('ucg_delete_template');

            $template_id = $this->get_request_int($_POST, 'template_id', 0);
            if ($template_id <= 0) {
                $this->redirect_with_notice('ucg-templates', __('Некорректный ID шаблона.', 'unicontent-ai-generator'), 'error');
            }

            $ok = UCG_DB::delete_template($template_id);
            if (!$ok) {
                $this->redirect_with_notice('ucg-templates', __('Не удалось удалить шаблон.', 'unicontent-ai-generator'), 'error');
            }

            $this->redirect_with_notice('ucg-templates', __('Шаблон удален.', 'unicontent-ai-generator'));
        }

        public function handle_install_ready_template() {
            $this->guard_admin_post('ucg_install_ready_template');
            $redirect_page = $this->resolve_ready_templates_redirect_page($_POST);

            if (!UCG_Settings::has_valid_api_key()) {
                $this->redirect_ready_templates_notice($redirect_page, __('Сначала добавьте и проверьте API ключ.', 'unicontent-ai-generator'), 'error');
            }

            $prompt_id = $this->get_request_int($_POST, 'prompt_id', 0);
            if ($prompt_id <= 0) {
                $this->redirect_ready_templates_notice($redirect_page, __('Некорректный ID готового шаблона.', 'unicontent-ai-generator'), 'error');
            }

            $post_type = sanitize_key($this->get_request_string($_POST, 'post_type', UCG_Tokens::get_default_post_type()));
            if ($post_type === '' || !post_type_exists($post_type)) {
                $post_type = UCG_Tokens::get_default_post_type();
            }

            $prompt = $this->find_ready_prompt_by_id($prompt_id, true);
            if (!$prompt) {
                $this->redirect_ready_templates_notice($redirect_page, __('Готовый шаблон не найден в библиотеке UNICONTENT.', 'unicontent-ai-generator'), 'error', $post_type);
            }

            $name = isset($prompt['name']) ? trim((string) $prompt['name']) : '';
            $body = isset($prompt['body']) ? (string) $prompt['body'] : '';
            if ($name === '' || trim($body) === '') {
                $this->redirect_ready_templates_notice($redirect_page, __('Не удалось установить шаблон: пустое имя или текст.', 'unicontent-ai-generator'), 'error', $post_type);
            }
            $template_scenario = $this->resolve_ready_template_scenario($prompt, $body);
            if ($this->scenario_requires_product_post_type($template_scenario) && post_type_exists('product')) {
                $post_type = 'product';
            }
            $prepared_body = $this->prepare_ready_template_body_for_install($template_scenario, $body);
            if (trim($prepared_body) === '') {
                $this->redirect_ready_templates_notice($redirect_page, __('Не удалось установить шаблон: пустое тело после обработки.', 'unicontent-ai-generator'), 'error', $post_type);
            }

            $installs = $this->get_ready_template_installs();
            $prompt_key = (string) $prompt_id;
            $installed_template_id = isset($installs[$prompt_key]['template_id']) ? (int) $installs[$prompt_key]['template_id'] : 0;
            $template_id = 0;

            if ($installed_template_id > 0) {
                $existing_template = UCG_DB::get_template($installed_template_id);
                if ($existing_template) {
                    $updated = UCG_DB::update_template(
                        $installed_template_id,
                        $name,
                        $post_type,
                        $prepared_body,
                        !empty($existing_template['is_default']) ? 1 : 0,
                        0,
                        0,
                        $template_scenario
                    );
                    if ($updated) {
                        $template_id = $installed_template_id;
                    }
                }
            }

            if ($template_id <= 0) {
                $template_id = UCG_DB::create_template($name, $post_type, $prepared_body, 0, 0, 0, $template_scenario);
                if ($template_id <= 0) {
                    $this->redirect_ready_templates_notice($redirect_page, __('Не удалось установить готовый шаблон.', 'unicontent-ai-generator'), 'error', $post_type);
                }
            }

            $installs[$prompt_key] = array(
                'template_id' => (int) $template_id,
                'post_type' => $post_type,
                'prompt_slug' => isset($prompt['slug']) ? sanitize_key((string) $prompt['slug']) : '',
                'updated_at' => current_time('mysql', true),
            );
            $this->save_ready_template_installs($installs);

            $this->redirect_ready_templates_notice($redirect_page, __('Готовый шаблон установлен.', 'unicontent-ai-generator'), 'success', $post_type);
        }

        public function handle_delete_ready_template() {
            $this->guard_admin_post('ucg_delete_ready_template');
            $redirect_page = $this->resolve_ready_templates_redirect_page($_POST);
            $post_type = sanitize_key($this->get_request_string($_POST, 'post_type', UCG_Tokens::get_default_post_type()));
            if ($post_type === '' || !post_type_exists($post_type)) {
                $post_type = UCG_Tokens::get_default_post_type();
            }

            $prompt_id = $this->get_request_int($_POST, 'prompt_id', 0);
            if ($prompt_id <= 0) {
                $this->redirect_ready_templates_notice($redirect_page, __('Некорректный ID готового шаблона.', 'unicontent-ai-generator'), 'error', $post_type);
            }

            $installs = $this->get_ready_template_installs();
            $prompt_key = (string) $prompt_id;
            $installed_template_id = isset($installs[$prompt_key]['template_id']) ? (int) $installs[$prompt_key]['template_id'] : 0;
            if ($installed_template_id > 0) {
                UCG_DB::delete_template($installed_template_id);
            }

            unset($installs[$prompt_key]);
            $this->save_ready_template_installs($installs);

            $this->redirect_ready_templates_notice($redirect_page, __('Готовый шаблон удален.', 'unicontent-ai-generator'), 'success', $post_type);
        }

        public function handle_create_run() {
            $this->guard_admin_post('ucg_create_run');

            if (!UCG_Settings::has_valid_api_key()) {
                $this->redirect_with_notice('ucg-dashboard', __('Сначала добавьте и проверьте API ключ.', 'unicontent-ai-generator'), 'error');
            }

            $post_type = sanitize_key($this->get_request_string($_POST, 'post_type', ''));
            $target_field = sanitize_text_field($this->get_request_string($_POST, 'target_field', ''));
            $template_id = $this->get_request_int($_POST, 'template_id', 0);
            $scope = sanitize_key($this->get_request_string($_POST, 'generation_scope', 'selected'));
            $status_filter = sanitize_key($this->get_request_string($_POST, 'status_filter', 'publish'));
            $status_filter = $this->normalize_status_filter($status_filter);
            $search = sanitize_text_field($this->get_request_string($_POST, 'search', ''));

            if ($post_type === '' || !post_type_exists($post_type)) {
                $this->redirect_with_notice('ucg-generate', __('Некорректный post type.', 'unicontent-ai-generator'), 'error');
            }

            $allowed_target_fields = UCG_Tokens::get_target_fields_for_post_type($post_type);
            $allowed_map = array();
            foreach ($allowed_target_fields as $field_item) {
                if (!empty($field_item['value'])) {
                    $allowed_map[(string) $field_item['value']] = true;
                }
            }
            if (!isset($allowed_map[$target_field])) {
                $this->redirect_with_notice('ucg-generate', __('Выберите корректное целевое поле.', 'unicontent-ai-generator'), 'error');
            }

            $post_ids = array();
            if ($scope === 'filtered') {
                $post_ids = $this->collect_filtered_post_ids($post_type, $status_filter, $search, 50000);
            } else {
                $raw_ids = isset($_POST['post_ids']) ? (array) $_POST['post_ids'] : array();
                foreach ($raw_ids as $raw_id) {
                    $post_id = (int) $raw_id;
                    if ($post_id > 0) {
                        $post_ids[] = $post_id;
                    }
                }
            }

            $post_ids = array_values(array_unique($post_ids));
            if (empty($post_ids)) {
                $this->redirect_with_notice('ucg-generate', __('Не выбраны записи для генерации.', 'unicontent-ai-generator'), 'error');
            }

            $options = array(
                'scope' => $scope,
                'status_filter' => $status_filter,
                'search' => $search,
            );

            $run_id = UCG_DB::create_run($post_type, $target_field, $template_id, get_current_user_id(), $options);
            if ($run_id <= 0) {
                $this->redirect_with_notice('ucg-generate', __('Не удалось создать запуск.', 'unicontent-ai-generator'), 'error');
            }

            $added_items = UCG_DB::add_run_items($run_id, $post_ids);
            if ($added_items <= 0) {
                UCG_DB::update_run(
                    $run_id,
                    array(
                        'status' => 'failed',
                        'error_message' => __('Не удалось добавить записи в очередь.', 'unicontent-ai-generator'),
                        'finished_at' => current_time('mysql', true),
                    )
                );
                $this->redirect_with_notice('ucg-generate', __('Не удалось добавить записи в очередь.', 'unicontent-ai-generator'), 'error');
            }

            UCG_Generator::kickstart_queue(0);

            $this->redirect_with_notice('ucg-runs', sprintf(__('Запуск #%d создан. В очереди: %d.', 'unicontent-ai-generator'), $run_id, $added_items));
        }

        public function handle_review_bulk() {
            $this->guard_admin_post('ucg_review_bulk');

            $single_field_action = $this->get_request_string($_POST, 'field_action_submit', '');
            if (trim($single_field_action) !== '') {
                $result = $this->process_single_review_field_action($single_field_action);
                if (is_wp_error($result)) {
                    $this->redirect_with_notice('ucg-review', $result->get_error_message(), 'error');
                }

                $notice = isset($result['notice']) ? (string) $result['notice'] : __('Поле обновлено.', 'unicontent-ai-generator');
                $notice_type = isset($result['type']) ? sanitize_key((string) $result['type']) : 'success';
                if (!in_array($notice_type, array('success', 'warning', 'error'), true)) {
                    $notice_type = 'success';
                }
                $this->redirect_with_notice('ucg-review', $notice, $notice_type);
            }

            $action = '';
            $item_ids = array();
            $item_action = $this->get_request_string($_POST, 'item_action_submit', '');
            if (trim($item_action) !== '') {
                $item_parts = explode('|', $item_action);
                if (count($item_parts) < 2) {
                    $this->redirect_with_notice('ucg-review', __('Некорректное действие элемента.', 'unicontent-ai-generator'), 'error');
                }
                $action = sanitize_key((string) $item_parts[0]);
                $single_item_id = isset($item_parts[1]) ? (int) $item_parts[1] : 0;
                if (!in_array($action, array('approve', 'reject'), true) || $single_item_id <= 0) {
                    $this->redirect_with_notice('ucg-review', __('Некорректное действие элемента.', 'unicontent-ai-generator'), 'error');
                }
                $item_ids = array($single_item_id);
            } else {
                $action = sanitize_key($this->get_request_string($_POST, 'bulk_action', ''));
                if (!in_array($action, array('approve', 'reject'), true)) {
                    $this->redirect_with_notice('ucg-review', __('Выберите действие: одобрить или отклонить.', 'unicontent-ai-generator'), 'error');
                }

                $raw_ids = isset($_POST['item_ids']) ? (array) $_POST['item_ids'] : array();
                foreach ($raw_ids as $raw_id) {
                    $item_id = (int) $raw_id;
                    if ($item_id > 0) {
                        $item_ids[] = $item_id;
                    }
                }
                $item_ids = array_values(array_unique($item_ids));
            }

            if (empty($item_ids)) {
                $this->redirect_with_notice('ucg-review', __('Выберите хотя бы один элемент.', 'unicontent-ai-generator'), 'error');
            }

            $success = 0;
            $failed = 0;
            $touched_runs = array();
            $write_context_by_run = array();

            foreach ($item_ids as $item_id) {
                $item = UCG_DB::get_run_item_with_run($item_id);
                if (!$item || (string) $item['status'] !== 'generated') {
                    continue;
                }

                $touched_runs[] = (int) $item['run_id'];

                if ($action === 'approve') {
                    $item_run_id = isset($item['run_id']) ? (int) $item['run_id'] : 0;
                    if (!array_key_exists($item_run_id, $write_context_by_run)) {
                        $write_context_by_run[$item_run_id] = $this->get_write_context_for_run($item_run_id);
                    }
                    $write_context = isset($write_context_by_run[$item_run_id]) && is_array($write_context_by_run[$item_run_id])
                        ? $write_context_by_run[$item_run_id]
                        : array();
                    $generated_fields = $this->decode_generated_fields_payload(
                        isset($item['generated_fields_json']) ? (string) $item['generated_fields_json'] : ''
                    );
                    $has_multi_payload = !empty($generated_fields['ai_fields']) || !empty($generated_fields['static_fields']);
                    if ($has_multi_payload) {
                        $write_result = $this->write_generated_fields_for_review_item(
                            (int) $item['post_id'],
                            (string) $item['target_field'],
                            $generated_fields,
                            $write_context
                        );
                    } else {
                        $write_result = UCG_Tokens::write_generated_value(
                            (int) $item['post_id'],
                            (string) $item['target_field'],
                            (string) $item['generated_text'],
                            $write_context
                        );
                    }
                    if (is_wp_error($write_result)) {
                        UCG_DB::update_run_item(
                            $item_id,
                            array(
                                'status' => 'failed',
                                'error_message' => $write_result->get_error_message(),
                            )
                        );
                        $failed++;
                        continue;
                    }

                    $update_data = array(
                        'status' => 'approved',
                        'reviewed_at' => current_time('mysql', true),
                        'error_message' => '',
                    );
                    if ($has_multi_payload) {
                        $approved_fields = $this->mark_generated_fields_review_status($generated_fields, 'approved');
                        $update_data['generated_fields_json'] = wp_json_encode($approved_fields, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                    }
                    UCG_DB::update_run_item($item_id, $update_data);
                    $success++;
                    continue;
                }

                $reject_update_data = array(
                    'status' => 'rejected',
                    'reviewed_at' => current_time('mysql', true),
                );
                $reject_fields = $this->decode_generated_fields_payload(
                    isset($item['generated_fields_json']) ? (string) $item['generated_fields_json'] : ''
                );
                if (!empty($reject_fields['ai_fields']) || !empty($reject_fields['static_fields'])) {
                    $rejected_fields = $this->mark_generated_fields_review_status($reject_fields, 'rejected');
                    $reject_update_data['generated_fields_json'] = wp_json_encode($rejected_fields, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                }
                UCG_DB::update_run_item($item_id, $reject_update_data);
                $success++;
            }

            foreach (array_unique($touched_runs) as $run_id) {
                $run_id = (int) $run_id;
                if ($run_id > 0) {
                    UCG_DB::recalculate_run_counters($run_id);
                }
            }

            $notice = sprintf(__('Выполнено: %d. Ошибок: %d.', 'unicontent-ai-generator'), $success, $failed);
            $this->redirect_with_notice('ucg-review', $notice, $failed > 0 ? 'warning' : 'success');
        }

        public function handle_process_now() {
            $this->guard_admin_post('ucg_process_now');

            $generator = new UCG_Generator();
            $generator->process_queue(true);

            $this->redirect_with_notice('ucg-runs', __('Очередь обработана одним шагом.', 'unicontent-ai-generator'));
        }

        public function ajax_process_now() {
            $this->guard_ajax();

            $run_id = $this->get_request_int($_POST, 'run_id', 0);
            if ($run_id <= 0) {
                wp_send_json_error(array('message' => __('Некорректный ID запуска.', 'unicontent-ai-generator')));
            }

            $run = UCG_DB::get_run($run_id);
            if (!$run) {
                wp_send_json_error(array('message' => __('Запуск не найден.', 'unicontent-ai-generator')));
            }

            $status = sanitize_key((string) $run['status']);
            if (in_array($status, array('completed', 'failed'), true)) {
                wp_send_json_success(array(
                    'ok' => true,
                    'skipped' => true,
                    'reason' => 'finished',
                ));
            }

            $force_smaller = !empty($_POST['force_smaller']);
            $max_batch = 50;

            $settings = UCG_Settings::get();
            $default_batch = isset($settings['batch_size']) ? (int) $settings['batch_size'] : 20;
            $default_batch = max(1, min($max_batch, $default_batch));

            $batch_key = 'ucg_effective_batch_' . $run_id;
            $effective_batch = (int) get_transient($batch_key);
            if ($effective_batch <= 0) {
                $effective_batch = $default_batch;
            }
            $effective_batch = max(1, min($max_batch, $effective_batch));
            $previous_batch = $effective_batch;

            if ($force_smaller && $effective_batch > 1) {
                $effective_batch = max(1, (int) floor($effective_batch / 2));
            }

            $generator = new UCG_Generator();
            $stats = $generator->process_queue(true, $effective_batch, $run_id);
            if (!is_array($stats)) {
                $stats = array();
            }

            $processed_now = isset($stats['processed_now']) ? (int) $stats['processed_now'] : 0;
            $error_type = isset($stats['error_type']) ? sanitize_key((string) $stats['error_type']) : '';
            $error_message = isset($stats['error_message']) ? (string) $stats['error_message'] : '';

            $issue_key = 'ucg_last_issue_' . $run_id;
            $had_issue = $error_type !== '' && $error_message !== '';

            if ($had_issue) {
                // Back off aggressively on any transient API/network issue.
                if ($effective_batch > 1) {
                    $effective_batch = max(1, (int) floor($effective_batch / 2));
                }
                set_transient($issue_key, array(
                    'type' => $error_type,
                    'message' => $error_message,
                    'at' => time(),
                ), HOUR_IN_SECONDS);
            } else {
                delete_transient($issue_key);
                // Ramp up slowly when we consistently process full batches.
                if ($processed_now >= (int) $previous_batch && $effective_batch < $max_batch) {
                    $effective_batch = min($max_batch, $effective_batch + 2);
                }
            }

            set_transient($batch_key, $effective_batch, HOUR_IN_SECONDS);

            $recommended_poll_ms = $had_issue ? 5000 : 1500;
            if ($processed_now <= 0) {
                $recommended_poll_ms = 3000;
            }

            wp_send_json_success(array(
                'ok' => true,
                'processed_now' => $processed_now,
                'effective_batch_size' => $effective_batch,
                'recommended_poll_ms' => $recommended_poll_ms,
                'issue' => $had_issue ? array('type' => $error_type, 'message' => $error_message) : null,
            ));
        }

        public function ajax_test_connection() {
            $this->guard_ajax();

            $client = new UCG_Api_Client();
            $result = $client->test_connection();
            if (is_wp_error($result)) {
                wp_send_json_error(array('message' => $result->get_error_message()));
            }

            $ttl = (int) UCG_Settings::get_option('credits_cache_ttl', 60);
            $ttl = max(10, min(600, $ttl));
            set_transient('ucg_balance_cache', $result, $ttl);

            wp_send_json_success(
                array(
                    'message' => __('Подключение к API работает.', 'unicontent-ai-generator'),
                    'credits' => isset($result['credits']) ? (float) $result['credits'] : 0,
                    'api_key' => isset($result['api_key']) ? (string) $result['api_key'] : '',
                )
            );
        }

        protected function asset_version($relative_path) {
            $relative_path = ltrim((string) $relative_path, '/');
            $file_path = UCG_PLUGIN_DIR . $relative_path;
            if ($relative_path !== '' && file_exists($file_path)) {
                $mtime = filemtime($file_path);
                if ($mtime) {
                    return (string) $mtime;
                }
            }
            return (string) UCG_VERSION;
        }

        public function ajax_get_balance() {
            $this->guard_ajax();

            $force = !empty($_POST['force']);
            $cached = get_transient('ucg_balance_cache');
            if (!$force && is_array($cached)) {
                wp_send_json_success(
                    array(
                        'credits' => isset($cached['credits']) ? (float) $cached['credits'] : 0,
                        'api_key' => isset($cached['api_key']) ? (string) $cached['api_key'] : '',
                        'cached' => true,
                    )
                );
            }

            $client = new UCG_Api_Client();
            $result = $client->get_balance();
            if (is_wp_error($result)) {
                wp_send_json_error(array('message' => $result->get_error_message()));
            }

            $ttl = (int) UCG_Settings::get_option('credits_cache_ttl', 60);
            $ttl = max(10, min(600, $ttl));
            set_transient('ucg_balance_cache', $result, $ttl);

            wp_send_json_success(
                array(
                    'credits' => isset($result['credits']) ? (float) $result['credits'] : 0,
                    'api_key' => isset($result['api_key']) ? (string) $result['api_key'] : '',
                    'cached' => false,
                )
            );
        }

        public function ajax_get_tokens() {
            $this->guard_ajax();

            $post_type = sanitize_key($this->get_request_string($_POST, 'post_type', ''));
            if ($post_type === '' || !post_type_exists($post_type)) {
                wp_send_json_error(array('message' => __('Некорректный post type.', 'unicontent-ai-generator')));
            }

            $tokens = UCG_Tokens::get_prompt_tokens_for_post_type($post_type);
            $target_fields = UCG_Tokens::get_target_fields_for_post_type($post_type);

            wp_send_json_success(
                array(
                    'tokens' => $tokens,
                    'target_fields' => $target_fields,
                )
            );
        }

        public function ajax_search_runs() {
            $this->guard_ajax();

            $query = sanitize_text_field($this->get_request_string($_POST, 'q', ''));
            $limit = max(5, min(100, $this->get_request_int($_POST, 'limit', 25)));
            $runs = UCG_DB::search_runs_for_select($query, $limit);

            $options = array();
            foreach ($runs as $run) {
                if (!is_array($run)) {
                    continue;
                }

                $run_id = isset($run['id']) ? (int) $run['id'] : 0;
                if ($run_id <= 0) {
                    continue;
                }

                $post_type = isset($run['post_type']) ? (string) $run['post_type'] : '';
                $status = isset($run['status']) ? (string) $run['status'] : '';

                $options[] = array(
                    'value' => (string) $run_id,
                    'label' => '#' . $run_id . ' — ' . $post_type . ' (' . $this->status_label($status) . ')',
                );
            }

            wp_send_json_success(
                array(
                    'options' => $options,
                )
            );
        }

        public function ajax_save_api_key() {
            $this->guard_ajax();

            $api_key = sanitize_text_field($this->get_request_string($_POST, 'api_key', ''));
            if ($api_key === '') {
                UCG_Settings::save_api_key('', 0);
                delete_transient('ucg_balance_cache');
                delete_transient('ucg_text_length_options_cache_v2');
                delete_transient('ucg_prompt_library_cache_v1');
                $this->clear_generation_model_caches();
                wp_send_json_error(array('message' => __('Введите API ключ.', 'unicontent-ai-generator')));
            }

            UCG_Settings::save_api_key($api_key, 0);
            delete_transient('ucg_text_length_options_cache_v2');
            delete_transient('ucg_prompt_library_cache_v1');
            $this->clear_generation_model_caches();

            $client = new UCG_Api_Client($api_key);
            $result = $client->test_connection();
            if (is_wp_error($result)) {
                delete_transient('ucg_balance_cache');
                wp_send_json_success(
                    array(
                        'verified' => false,
                        'message' => sprintf(__('Ключ сохранен, но проверка не пройдена: %s', 'unicontent-ai-generator'), $result->get_error_message()),
                        'masked_key' => UCG_Settings::get_masked_api_key(),
                    )
                );
            }

            UCG_Settings::save_api_key($api_key, 1);
            delete_transient('ucg_text_length_options_cache_v2');
            delete_transient('ucg_prompt_library_cache_v1');
            $this->clear_generation_model_caches();

            $ttl = (int) UCG_Settings::get_option('credits_cache_ttl', 60);
            $ttl = max(10, min(600, $ttl));
            set_transient('ucg_balance_cache', $result, $ttl);

            wp_send_json_success(
                array(
                    'verified' => true,
                    'message' => __('Ключ сохранен и проверен.', 'unicontent-ai-generator'),
                    'masked_key' => UCG_Settings::get_masked_api_key(),
                    'credits' => isset($result['credits']) ? (float) $result['credits'] : 0,
                )
            );
        }

        public function ajax_delete_api_key() {
            $this->guard_ajax();

            UCG_Settings::save_api_key('', 0);
            delete_transient('ucg_balance_cache');
            delete_transient('ucg_text_length_options_cache_v2');
            delete_transient('ucg_prompt_library_cache_v1');
            $this->clear_generation_model_caches();

            wp_send_json_success(
                array(
                    'message' => __('Ключ удален.', 'unicontent-ai-generator'),
                )
            );
        }

        public function ajax_save_batch_size() {
            $this->guard_ajax();

            $batch_size = $this->get_request_int($_POST, 'batch_size', (int) UCG_Settings::get_option('batch_size', 20));
            $batch_size = max(1, min(100, $batch_size));
            $generation_mode = sanitize_key($this->get_request_string($_POST, 'generation_mode', (string) UCG_Settings::get_option('generation_mode', 'review')));
            if (!in_array($generation_mode, array('review', 'publish'), true)) {
                $generation_mode = 'review';
            }

            UCG_Settings::update(
                array(
                    'batch_size' => $batch_size,
                    'generation_mode' => $generation_mode,
                )
            );

            $mode_label = $generation_mode === 'publish'
                ? __('Публиковать сразу', 'unicontent-ai-generator')
                : __('Сначала проверка', 'unicontent-ai-generator');

            wp_send_json_success(
                array(
                    'batch_size' => $batch_size,
                    'generation_mode' => $generation_mode,
                    'message' => sprintf(__('Сохранено. За шаг: до %d записей. Режим: %s.', 'unicontent-ai-generator'), $batch_size, $mode_label),
                )
            );
        }

        public function ajax_save_style_defaults() {
            $this->guard_ajax();

            $default_language = sanitize_key($this->get_request_string($_POST, 'default_language', 'auto'));
            if (!in_array($default_language, array('auto', 'ru', 'en'), true)) {
                $default_language = 'auto';
            }
            $default_tone = sanitize_key($this->get_request_string($_POST, 'default_tone', 'neutral'));
            if (!in_array($default_tone, array('neutral', 'official', 'friendly'), true)) {
                $default_tone = 'neutral';
            }

            $values = array(
                'default_language' => $default_language,
                'default_tone' => $default_tone,
            );

            UCG_Settings::update($values);

            wp_send_json_success(
                array(
                    'message' => __('Сохранено.', 'unicontent-ai-generator'),
                )
            );
        }

        public function ajax_run_status() {
            $this->guard_ajax();

            $run_id = $this->get_request_int($_POST, 'run_id', 0);
            if ($run_id <= 0) {
                wp_send_json_error(array('message' => __('Некорректный ID запуска.', 'unicontent-ai-generator')));
            }

            $run = UCG_DB::get_run($run_id);
            if (!$run) {
                wp_send_json_error(array('message' => __('Запуск не найден.', 'unicontent-ai-generator')));
            }

            $total = max(0, (int) $run['total_items']);
            $processed = max(0, (int) $run['processed_items']);
            $success = max(0, (int) $run['success_items']);
            $failed = max(0, (int) $run['failed_items']);
            $queued = max(0, $total - $processed);
            $progress = $total > 0 ? min(100, (int) floor(($processed / $total) * 100)) : 0;
            $status = sanitize_key((string) $run['status']);
            $is_finished = in_array($status, array('completed', 'failed'), true);

            $batch_key = 'ucg_effective_batch_' . $run_id;
            $effective_batch = (int) get_transient($batch_key);
            if ($effective_batch <= 0) {
                $settings = UCG_Settings::get();
                $default_batch = isset($settings['batch_size']) ? (int) $settings['batch_size'] : 20;
                $effective_batch = max(1, min(50, $default_batch));
            }
            $issue = get_transient('ucg_last_issue_' . $run_id);
            if (!is_array($issue)) {
                $issue = null;
            }

            $log_rows = UCG_DB::get_run_items_log($run_id, 20);
            $logs = array();
            foreach ($log_rows as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $logs[] = array(
                    'id' => isset($row['id']) ? (int) $row['id'] : 0,
                    'post_id' => isset($row['post_id']) ? (int) $row['post_id'] : 0,
                    'status' => isset($row['status']) ? sanitize_key((string) $row['status']) : '',
                    'status_label' => $this->run_item_status_label(isset($row['status']) ? (string) $row['status'] : ''),
                    'error_message' => $this->truncate_log_message(isset($row['error_message']) ? (string) $row['error_message'] : ''),
                    'attempts' => isset($row['attempts']) ? (int) $row['attempts'] : 0,
                    'updated_at' => isset($row['updated_at']) ? (string) $row['updated_at'] : '',
                    'generated_at' => isset($row['generated_at']) ? (string) $row['generated_at'] : '',
                );
            }

            wp_send_json_success(
                array(
                    'run' => array(
                        'id' => $run_id,
                        'status' => $status,
                        'status_label' => $this->status_label($status),
                        'progress' => $progress,
                        'total_items' => $total,
                        'processed_items' => $processed,
                        'success_items' => $success,
                        'failed_items' => $failed,
                        'queued_items' => $queued,
                        'effective_batch_size' => $effective_batch,
                    ),
                    'logs' => $logs,
                    'is_finished' => $is_finished,
                    'issue' => $issue ? array(
                        'type' => isset($issue['type']) ? sanitize_key((string) $issue['type']) : '',
                        'message' => isset($issue['message']) ? (string) $issue['message'] : '',
                        'at' => isset($issue['at']) ? (int) $issue['at'] : 0,
                    ) : null,
                    'review_url' => admin_url('admin.php?page=ucg-review&run_id=' . $run_id),
                    'runs_url' => admin_url('admin.php?page=ucg-runs'),
                )
            );
        }

        public function ajax_wizard_schema() {
            $this->guard_ajax();

            if (!UCG_Settings::has_valid_api_key()) {
                if (class_exists('UCG_Logger')) {
                    UCG_Logger::warn('wizard', 'api_key_missing', 'Wizard create run: API key missing.', array());
                }
                wp_send_json_error(array('message' => __('Сначала добавьте и проверьте API ключ.', 'unicontent-ai-generator')));
            }

            $post_type = sanitize_key($this->get_request_string($_POST, 'post_type', UCG_Tokens::get_default_post_type()));
            if ($post_type === '' || !post_type_exists($post_type)) {
                $post_type = UCG_Tokens::get_default_post_type();
            }
            $scenario = $this->normalize_generation_scenario(
                $this->get_request_string($_POST, 'scenario', self::DEFAULT_GENERATION_SCENARIO),
                $post_type
            );
            if ($this->scenario_requires_product_post_type($scenario) && post_type_exists('product')) {
                $post_type = 'product';
            }

            $force_refresh_lengths = !empty($_POST['force_refresh_lengths']);
            wp_send_json_success($this->build_wizard_schema($post_type, $force_refresh_lengths, $scenario));
        }

        public function ajax_wizard_preview() {
            $this->guard_ajax();

            if (!UCG_Settings::has_valid_api_key()) {
                wp_send_json_error(array('message' => __('Сначала добавьте и проверьте API ключ.', 'unicontent-ai-generator')));
            }

            $post_type = sanitize_key($this->get_request_string($_POST, 'post_type', ''));
            if ($post_type === '' || !post_type_exists($post_type)) {
                wp_send_json_error(array('message' => __('Некорректный post type.', 'unicontent-ai-generator')));
            }
            $scenario = $this->normalize_generation_scenario(
                $this->get_request_string($_POST, 'scenario', self::DEFAULT_GENERATION_SCENARIO),
                $post_type
            );
            if ($this->scenario_requires_product_post_type($scenario) && post_type_exists('product')) {
                $post_type = 'product';
            }

            $filters = $this->normalize_filters_from_request($this->get_request_string($_POST, 'filters', '[]'), $post_type);
            $page = max(1, $this->get_request_int($_POST, 'page', 1));
            $per_page = $this->get_request_int($_POST, 'per_page', 20);
            $per_page = max(1, min(100, $per_page));

            $total = $this->query_filtered_post_ids_count($post_type, $filters);
            $offset = ($page - 1) * $per_page;
            $ids = $this->query_filtered_post_ids($post_type, $filters, $per_page, $offset);
            $posts = $this->map_posts_for_preview($ids);

            $total_pages = max(1, (int) ceil($total / $per_page));
            if ($page > $total_pages) {
                $page = $total_pages;
            }

            wp_send_json_success(
                array(
                    'total' => (int) $total,
                    'page' => (int) $page,
                    'per_page' => (int) $per_page,
                    'total_pages' => (int) $total_pages,
                    'items' => $posts,
                    'filters' => $filters,
                    'scenario' => $scenario,
                )
            );
        }

        public function ajax_wizard_load_template() {
            $this->guard_ajax();

            if (!UCG_Settings::has_valid_api_key()) {
                wp_send_json_error(array('message' => __('Сначала добавьте и проверьте API ключ.', 'unicontent-ai-generator')));
            }

            $template_id = $this->get_request_int($_POST, 'template_id', 0);
            if ($template_id <= 0) {
                wp_send_json_error(array('message' => __('Некорректный ID шаблона.', 'unicontent-ai-generator')));
            }
            $requested_scenario = $this->normalize_generation_scenario(
                $this->get_request_string($_POST, 'scenario', self::DEFAULT_GENERATION_SCENARIO)
            );

            $template = UCG_DB::get_template($template_id);
            if (!$template) {
                wp_send_json_error(array('message' => __('Шаблон не найден.', 'unicontent-ai-generator')));
            }
            $template_post_type = isset($template['post_type']) ? sanitize_key((string) $template['post_type']) : '';
            $template_scenario = isset($template['scenario']) ? sanitize_key((string) $template['scenario']) : '';
            if ($template_scenario === '') {
                $template_scenario = $requested_scenario;
            }
            $template_scenario = $this->normalize_generation_scenario($template_scenario, $template_post_type);
            $requested_scenario = $this->normalize_generation_scenario($requested_scenario, $template_post_type);
            if ($requested_scenario !== '' && $template_scenario !== $requested_scenario) {
                wp_send_json_error(array('message' => __('Шаблон относится к другому сценарию генерации.', 'unicontent-ai-generator')));
            }
            $template_payload = $this->decode_template_payload($template_scenario, isset($template['body']) ? (string) $template['body'] : '');
            $this->maybe_upgrade_template_payload_to_v3($template, $template_scenario, $template_payload);

            $tokens = UCG_Tokens::get_prompt_tokens_for_post_type((string) $template['post_type']);

            wp_send_json_success(
                array(
                    'template' => array(
                        'id' => (int) $template['id'],
                        'name' => (string) $template['name'],
                        'post_type' => (string) $template['post_type'],
                        'scenario' => $template_scenario,
                        'body' => isset($template_payload['body']) ? (string) $template_payload['body'] : '',
                        'seo_title_prompt' => isset($template_payload['seo_title_prompt']) ? (string) $template_payload['seo_title_prompt'] : '',
                        'seo_description_prompt' => isset($template_payload['seo_description_prompt']) ? (string) $template_payload['seo_description_prompt'] : '',
                        'base_prompt' => isset($template_payload['base_prompt']) ? (string) $template_payload['base_prompt'] : '',
                        'prompt_blocks' => isset($template_payload['prompt_blocks']) && is_array($template_payload['prompt_blocks'])
                            ? $template_payload['prompt_blocks']
                            : array(),
                        'fields' => isset($template_payload['fields']) && is_array($template_payload['fields'])
                            ? $template_payload['fields']
                            : array(),
                        'is_default' => !empty($template['is_default']),
                    ),
                    'tokens' => $tokens,
                )
            );
        }

        public function ajax_wizard_create_run() {
            $this->guard_ajax();

            if (!UCG_Settings::has_valid_api_key()) {
                wp_send_json_error(array('message' => __('Сначала добавьте и проверьте API ключ.', 'unicontent-ai-generator')));
            }

            $post_type = sanitize_key($this->get_request_string($_POST, 'post_type', ''));
            $scenario = $this->normalize_generation_scenario(
                $this->get_request_string($_POST, 'scenario', self::DEFAULT_GENERATION_SCENARIO),
                $post_type
            );
            if ($this->scenario_requires_product_post_type($scenario)) {
                $post_type = 'product';
            }
            $target_field = sanitize_text_field($this->get_request_string($_POST, 'target_field', ''));
            $items_per_post = $this->get_request_int($_POST, 'items_per_post', 1);
            $rating_min = $this->get_request_int($_POST, 'rating_min', 1);
            $rating_max = $this->get_request_int($_POST, 'rating_max', 5);
            $template_id = $this->get_request_int($_POST, 'template_id', 0);
            $template_name = sanitize_text_field($this->get_request_string($_POST, 'template_name', ''));
            $template_body = sanitize_textarea_field($this->get_request_string($_POST, 'template_body', ''));
            $template_body_seo_title = sanitize_textarea_field($this->get_request_string($_POST, 'template_body_seo_title', ''));
            $template_body_seo_description = sanitize_textarea_field($this->get_request_string($_POST, 'template_body_seo_description', ''));
            $ai_fields = $this->parse_generation_fields_from_request($this->get_request_string($_POST, 'ai_fields', '[]'), 'ai', $scenario);
            $static_fields = $this->parse_generation_fields_from_request($this->get_request_string($_POST, 'static_fields', '[]'), 'static', $scenario);
            $selection_mode = sanitize_key($this->get_request_string($_POST, 'selection_mode', 'selected'));
            $create_count = $this->get_request_int($_POST, 'create_count', 10);
            $create_topics = $this->normalize_create_topics($this->get_request_string($_POST, 'create_topics', ''), 1000);
            $model = $this->normalize_model_identifier($this->get_request_string($_POST, 'model', 'auto'));
            if ($model === '') {
                $model = 'auto';
            }
            $style_language = sanitize_key($this->get_request_string($_POST, 'style_language', (string) UCG_Settings::get_option('default_language', 'auto')));
            $style_tone = sanitize_key($this->get_request_string($_POST, 'style_tone', (string) UCG_Settings::get_option('default_tone', 'neutral')));
            $save_template = !empty($_POST['save_template']) ? 1 : 0;
            $vary_length = !empty($_POST['vary_length']) ? 1 : 0;
            $publish_date_from = '';
            $publish_date_to = '';

            if ($post_type === '' || !post_type_exists($post_type)) {
                if (class_exists('UCG_Logger')) {
                    UCG_Logger::warn('wizard', 'invalid_post_type', 'Wizard create run: invalid post_type.', array('post_type' => (string) $post_type));
                }
                wp_send_json_error(array('message' => __('Некорректный post type.', 'unicontent-ai-generator')));
            }
            if (!$this->is_scenario_available($scenario)) {
                if (class_exists('UCG_Logger')) {
                    UCG_Logger::warn('wizard', 'scenario_unavailable', 'Wizard create run: scenario unavailable.', array('scenario' => (string) $scenario));
                }
                wp_send_json_error(array('message' => __('Выбранный сценарий пока недоступен.', 'unicontent-ai-generator')));
            }
            if ($scenario === 'post_fields' && $post_type === 'product') {
                wp_send_json_error(array('message' => __('Для товаров используйте сценарий "Товары".', 'unicontent-ai-generator')));
            }
            if ($scenario === 'woo_reviews' && $post_type !== 'product') {
                if (class_exists('UCG_Logger')) {
                    UCG_Logger::warn('wizard', 'woo_not_product', 'Wizard create run: woo_reviews requires product post_type.', array('post_type' => (string) $post_type));
                }
                wp_send_json_error(array('message' => __('Для сценария отзывов WooCommerce выберите тип записей: Товар (product).', 'unicontent-ai-generator')));
            }
            if ($scenario === 'comments' && !post_type_supports($post_type, 'comments')) {
                if (class_exists('UCG_Logger')) {
                    UCG_Logger::warn('wizard', 'comments_not_supported', 'Wizard create run: post_type does not support comments.', array('post_type' => (string) $post_type));
                }
                wp_send_json_error(array('message' => __('Выбранный тип записей не поддерживает комментарии.', 'unicontent-ai-generator')));
            }
            $supports_create_new = $this->scenario_supports_create_new_mode($scenario);
            if ($selection_mode === 'create_new' && !$supports_create_new) {
                wp_send_json_error(array('message' => __('Режим создания новых записей доступен только для сценариев с AI-полями.', 'unicontent-ai-generator')));
            }
            if ($selection_mode !== 'filtered' && $selection_mode !== 'selected' && $selection_mode !== 'create_new') {
                $selection_mode = 'selected';
            }
            $is_create_new_mode = $selection_mode === 'create_new' && $supports_create_new;
            if ($is_create_new_mode) {
                if (empty($create_topics)) {
                    wp_send_json_error(array('message' => __('Добавьте хотя бы одну тему для создания.', 'unicontent-ai-generator')));
                }
                $create_count = count($create_topics);
            } else {
                $create_count = 0;
                $create_topics = array();
            }

            // Comments / reviews can generate multiple items per post.
            if ($scenario === 'comments' || $scenario === 'woo_reviews') {
                $items_per_post = max(1, min(50, (int) $items_per_post));
                if ($target_field === '') {
                    $target_field = $scenario === 'comments' ? 'comment:publish' : 'woo_review:publish';
                }
            } else {
                $items_per_post = 1;
            }

            if ($scenario === 'woo_reviews') {
                $rating_min = max(1, min(5, (int) $rating_min));
                $rating_max = max(1, min(5, (int) $rating_max));
                if ($rating_min > $rating_max) {
                    $tmp = $rating_min;
                    $rating_min = $rating_max;
                    $rating_max = $tmp;
                }
            } else {
                // Keep defaults for other scenarios (not used).
                $rating_min = 1;
                $rating_max = 5;
            }

            if ($this->scenario_supports_publish_date_range($scenario)) {
                $publish_date_range = $this->normalize_publish_date_range(
                    $this->get_request_string($_POST, 'publish_date_from', ''),
                    $this->get_request_string($_POST, 'publish_date_to', '')
                );
                if (is_wp_error($publish_date_range)) {
                    wp_send_json_error(array('message' => $publish_date_range->get_error_message()));
                }
                $publish_date_from = isset($publish_date_range['from']) ? (string) $publish_date_range['from'] : '';
                $publish_date_to = isset($publish_date_range['to']) ? (string) $publish_date_range['to'] : '';
            }

            $schema = $this->build_wizard_schema($post_type, false, $scenario);
            $allowed_models = array('auto' => true);
            $schema_models_source = ($scenario === 'image_generation')
                ? (isset($schema['image_generation_models']) && is_array($schema['image_generation_models']) ? $schema['image_generation_models'] : array())
                : (isset($schema['generation_models']) && is_array($schema['generation_models']) ? $schema['generation_models'] : array());
            if (!empty($schema_models_source) && is_array($schema_models_source)) {
                foreach ($schema_models_source as $model_item) {
                    if (!is_array($model_item) || empty($model_item['id'])) {
                        continue;
                    }
                    $allowed_models[(string) $model_item['id']] = true;
                }
            }
            if (!isset($allowed_models[$model])) {
                wp_send_json_error(array('message' => __('Выберите корректную модель.', 'unicontent-ai-generator')));
            }

            $length_option_id = $this->resolve_length_option_id(
                $this->get_request_int($_POST, 'length_option_id', 0),
                isset($schema['text_length_options']) && is_array($schema['text_length_options']) ? $schema['text_length_options'] : null
            );
            $allowed_target_fields = array();
            if (!empty($schema['target_fields']) && is_array($schema['target_fields'])) {
                foreach ($schema['target_fields'] as $field_item) {
                    if (!empty($field_item['value']) && empty($field_item['disabled'])) {
                        $allowed_target_fields[(string) $field_item['value']] = true;
                    }
                }
            }

            if (!$this->scenario_supports_multi_fields($scenario) && !isset($allowed_target_fields[$target_field])) {
                wp_send_json_error(array('message' => __('Выберите корректное целевое поле.', 'unicontent-ai-generator')));
            }

            $active_template_id = $template_id;
            if ($template_id > 0) {
                $template = UCG_DB::get_template($template_id);
                if (!$template) {
                    wp_send_json_error(array('message' => __('Шаблон не найден.', 'unicontent-ai-generator')));
                }
                $template_post_type = isset($template['post_type']) ? sanitize_key((string) $template['post_type']) : $post_type;
                $template_scenario = isset($template['scenario']) ? sanitize_key((string) $template['scenario']) : '';
                if ($template_scenario === '') {
                    $template_scenario = $scenario;
                }
                $template_scenario = $this->normalize_generation_scenario($template_scenario, $template_post_type);
                if ($template_scenario !== $scenario) {
                    wp_send_json_error(array('message' => __('Шаблон относится к другому сценарию генерации.', 'unicontent-ai-generator')));
                }
                $template_payload = $this->decode_template_payload(
                    $template_scenario,
                    isset($template['body']) ? (string) $template['body'] : ''
                );
                $this->maybe_upgrade_template_payload_to_v3($template, $template_scenario, $template_payload);
                if (empty($ai_fields) && !empty($template_payload['fields']) && is_array($template_payload['fields'])) {
                    $ai_fields = $this->normalize_generation_fields($template_payload['fields'], 'ai', $scenario);
                }

                if ($scenario === 'seo_tags') {
                    if ($template_body_seo_title === '' && isset($template_payload['seo_title_prompt'])) {
                        $template_body_seo_title = (string) $template_payload['seo_title_prompt'];
                    }
                    if ($template_body_seo_description === '' && isset($template_payload['seo_description_prompt'])) {
                        $template_body_seo_description = (string) $template_payload['seo_description_prompt'];
                    }
                    if ($template_body_seo_title === '' && $template_body_seo_description === '' && !empty($template_payload['body'])) {
                        $template_body_seo_title = (string) $template_payload['body'];
                        $template_body_seo_description = (string) $template_payload['body'];
                    }
                } elseif ($template_body === '' && isset($template_payload['body'])) {
                    $template_body = (string) $template_payload['body'];
                }

                if ($scenario === 'seo_tags') {
                    if (trim($template_body_seo_title) === '' || trim($template_body_seo_description) === '') {
                        if (!$this->has_enabled_generation_fields($ai_fields)) {
                            wp_send_json_error(array('message' => __('Заполните шаблоны для SEO title и SEO description.', 'unicontent-ai-generator')));
                        }
                    }
                } elseif (!$this->scenario_supports_multi_fields($scenario) && trim($template_body) === '' && !$this->has_enabled_generation_fields($ai_fields)) {
                    wp_send_json_error(array('message' => __('Текст шаблона не может быть пустым.', 'unicontent-ai-generator')));
                }

                if ($save_template) {
                    $encoded_template_body = $this->encode_template_payload(
                        $scenario,
                        $template_body,
                        $template_body_seo_title,
                        $template_body_seo_description,
                        array(),
                        '',
                        !empty($ai_fields) ? $ai_fields : null
                    );
                    UCG_DB::update_template(
                        $template_id,
                        (string) $template['name'],
                        $post_type,
                        $encoded_template_body,
                        !empty($template['is_default']) ? 1 : 0,
                        0,
                        0,
                        $scenario
                    );
                }
            } else {
                if ($scenario === 'seo_tags') {
                    if (trim($template_body_seo_title) === '' || trim($template_body_seo_description) === '') {
                        if (!$this->has_enabled_generation_fields($ai_fields)) {
                            wp_send_json_error(array('message' => __('Заполните шаблоны для SEO title и SEO description.', 'unicontent-ai-generator')));
                        }
                    }
                } elseif (!$this->scenario_supports_multi_fields($scenario) && trim($template_body) === '' && !$this->has_enabled_generation_fields($ai_fields)) {
                    wp_send_json_error(array('message' => __('Текст шаблона не может быть пустым.', 'unicontent-ai-generator')));
                }

                if ($save_template) {
                    if ($template_name === '') {
                        wp_send_json_error(array('message' => __('Введите название шаблона.', 'unicontent-ai-generator')));
                    }

                    $encoded_template_body = $this->encode_template_payload(
                        $scenario,
                        $template_body,
                        $template_body_seo_title,
                        $template_body_seo_description,
                        array(),
                        '',
                        !empty($ai_fields) ? $ai_fields : null
                    );
                    $created_template_id = UCG_DB::create_template($template_name, $post_type, $encoded_template_body, 0, 0, 0, $scenario);
                    if ($created_template_id <= 0) {
                        wp_send_json_error(array('message' => __('Не удалось сохранить новый шаблон.', 'unicontent-ai-generator')));
                    }
                    $active_template_id = $created_template_id;
                }
            }

            if ($length_option_id <= 0 && !$this->scenario_supports_multi_fields($scenario)) {
                wp_send_json_error(array('message' => __('Выберите диапазон длины текста.', 'unicontent-ai-generator')));
            }

            if ($this->scenario_supports_multi_fields($scenario)) {
                $has_ai_fields_with_prompt = $this->has_enabled_ai_fields_with_prompt($ai_fields);
                $has_static_fields_with_value = $this->has_enabled_static_fields_with_value($static_fields);
                if ($this->has_enabled_ai_fields_without_prompt($ai_fields)) {
                    wp_send_json_error(array('message' => __('Для включённых AI-полей заполните промпт.', 'unicontent-ai-generator')));
                }
                if (!$has_ai_fields_with_prompt && !$has_static_fields_with_value) {
                    wp_send_json_error(array('message' => __('Выберите хотя бы одно AI или static поле.', 'unicontent-ai-generator')));
                }
                if ($target_field === '') {
                    $target_field = $this->resolve_run_target_field_from_fields($ai_fields, $static_fields);
                }
                if ($target_field === '') {
                    $target_field = 'post:post_content';
                }
            }

            $filters = $is_create_new_mode
                ? array()
                : $this->normalize_filters_from_request($this->get_request_string($_POST, 'filters', '[]'), $post_type);
            $post_ids = array();

            if ($is_create_new_mode) {
                $post_ids = array();
            } elseif ($selection_mode === 'filtered') {
                $post_ids = $this->query_filtered_post_ids($post_type, $filters, 50000, 0);
            } else {
                $selected_ids = $this->parse_ids_json($this->get_request_string($_POST, 'selected_ids', '[]'));
                $post_ids = $this->validate_post_ids_for_type($selected_ids, $post_type);
            }

            if (!$is_create_new_mode && empty($post_ids)) {
                wp_send_json_error(array('message' => __('Не выбраны записи для генерации.', 'unicontent-ai-generator')));
            }

            $options = array(
                'scenario' => $scenario,
                'model' => $model,
                'scope' => $is_create_new_mode ? 'create_new' : ($selection_mode === 'filtered' ? 'filtered' : 'selected'),
                'create_count' => $is_create_new_mode ? $create_count : 0,
                'create_topics' => $is_create_new_mode ? $create_topics : array(),
                'filters' => $filters,
                'template_body' => $scenario === 'seo_tags' ? '' : $template_body,
                'seo_title_prompt' => $scenario === 'seo_tags' ? $template_body_seo_title : '',
                'seo_description_prompt' => $scenario === 'seo_tags' ? $template_body_seo_description : '',
                'length_option_id' => $length_option_id,
                'vary_length' => $vary_length,
                'publish_date_from' => $publish_date_from,
                'publish_date_to' => $publish_date_to,
                'items_per_post' => $items_per_post,
                'rating_min' => $rating_min,
                'rating_max' => $rating_max,
                'style_language' => in_array($style_language, array('auto', 'ru', 'en'), true) ? $style_language : 'auto',
                'style_tone' => in_array($style_tone, array('neutral', 'official', 'friendly'), true) ? $style_tone : 'neutral',
            );

            if (!empty($ai_fields)) {
                $options['ai_fields'] = $this->normalize_generation_fields($ai_fields, 'ai', $scenario);
            }
            if (!empty($static_fields)) {
                $options['static_fields'] = $this->normalize_generation_fields($static_fields, 'static', $scenario);
            }

            if (empty($options['run_seed'])) {
                $options['run_seed'] = function_exists('wp_generate_uuid4') ? wp_generate_uuid4() : (string) wp_rand(100000, 999999) . '-' . time();
            }

            $run_id = UCG_DB::create_run($post_type, $target_field, $active_template_id, get_current_user_id(), $options);
            if ($run_id <= 0) {
                wp_send_json_error(array('message' => __('Не удалось создать запуск.', 'unicontent-ai-generator')));
            }

            $created_post_ids = array();
            if ($is_create_new_mode) {
                $created = $this->create_posts_for_generation_run($post_type, $create_count, $run_id, $create_topics);
                if (is_wp_error($created)) {
                    UCG_DB::update_run(
                        $run_id,
                        array(
                            'status' => 'failed',
                            'error_message' => $created->get_error_message(),
                            'finished_at' => current_time('mysql', true),
                        )
                    );
                    wp_send_json_error(array('message' => $created->get_error_message()));
                }
                $created_post_ids = $created;
                $post_ids = $created_post_ids;
            }

            $added_items = UCG_DB::add_run_items($run_id, $post_ids, $items_per_post);
            if ($added_items <= 0) {
                if (!empty($created_post_ids)) {
                    $this->cleanup_posts_created_for_generation_run($created_post_ids);
                }
                UCG_DB::update_run(
                    $run_id,
                    array(
                        'status' => 'failed',
                        'error_message' => __('Не удалось добавить записи в очередь.', 'unicontent-ai-generator'),
                        'finished_at' => current_time('mysql', true),
                    )
                );
                wp_send_json_error(array('message' => __('Не удалось добавить записи в очередь.', 'unicontent-ai-generator')));
            }

            UCG_Generator::kickstart_queue(0);

            wp_send_json_success(
                array(
                    'run_id' => $run_id,
                    'queued' => $added_items,
                    'message' => sprintf(__('Запуск #%d создан. В очереди: %d.', 'unicontent-ai-generator'), $run_id, $added_items),
                    'progress_url' => admin_url('admin.php?page=ucg-run-progress&run_id=' . $run_id),
                )
            );
        }

        public function ajax_wizard_quote() {
            $this->guard_ajax();

            if (!UCG_Settings::has_valid_api_key()) {
                wp_send_json_error(array('message' => __('Сначала добавьте и проверьте API ключ.', 'unicontent-ai-generator')));
            }

            $post_type = sanitize_key($this->get_request_string($_POST, 'post_type', UCG_Tokens::get_default_post_type()));
            if ($post_type === '' || !post_type_exists($post_type)) {
                $post_type = UCG_Tokens::get_default_post_type();
            }
            $scenario = $this->normalize_generation_scenario(
                $this->get_request_string($_POST, 'scenario', self::DEFAULT_GENERATION_SCENARIO),
                $post_type
            );
            if ($this->scenario_requires_product_post_type($scenario) && post_type_exists('product')) {
                $post_type = 'product';
            }
            if (!$this->is_scenario_available($scenario)) {
                wp_send_json_error(array('message' => __('Выбранный сценарий пока недоступен.', 'unicontent-ai-generator')));
            }

            $selection_mode = sanitize_key($this->get_request_string($_POST, 'selection_mode', 'selected'));
            if (!in_array($selection_mode, array('selected', 'filtered', 'create_new'), true)) {
                $selection_mode = 'selected';
            }
            $supports_create_new = $this->scenario_supports_create_new_mode($scenario);
            $is_create_new_mode = $selection_mode === 'create_new' && $supports_create_new;

            $planned_count = max(0, min(50000, $this->get_request_int($_POST, 'planned_count', 0)));
            $signature = sanitize_text_field($this->get_request_string($_POST, 'signature', ''));

            $template_body = sanitize_textarea_field($this->get_request_string($_POST, 'template_body', ''));
            $template_body_seo_title = sanitize_textarea_field($this->get_request_string($_POST, 'template_body_seo_title', ''));
            $template_body_seo_description = sanitize_textarea_field($this->get_request_string($_POST, 'template_body_seo_description', ''));
            $ai_fields = $this->parse_generation_fields_from_request($this->get_request_string($_POST, 'ai_fields', '[]'), 'ai', $scenario);
            $create_topics = $this->normalize_create_topics($this->get_request_string($_POST, 'create_topics', ''), 1000);

            $model = $this->normalize_model_identifier($this->get_request_string($_POST, 'model', 'auto'));
            if ($model === '') {
                $model = 'auto';
            }
            $vary_length = !empty($_POST['vary_length']) ? 1 : 0;

            $items_per_post = 1;
            if ($scenario === 'comments' || $scenario === 'woo_reviews') {
                $items_per_post = max(1, min(50, $this->get_request_int($_POST, 'items_per_post', 1)));
            }

            $text_length_data = $this->get_text_length_options(false);
            $text_length_options = isset($text_length_data['options']) && is_array($text_length_data['options'])
                ? $text_length_data['options']
                : array();
            $length_option_id = $this->resolve_length_option_id(
                $this->get_request_int($_POST, 'length_option_id', 0),
                $text_length_options
            );

            $length_max_chars_map = array();
            foreach ($text_length_options as $option) {
                if (!is_array($option)) {
                    continue;
                }
                $option_id = isset($option['id']) ? (int) $option['id'] : 0;
                if ($option_id <= 0) {
                    continue;
                }
                $length_max_chars_map[$option_id] = isset($option['max_chars']) ? max(0, (int) $option['max_chars']) : 0;
            }

            $generation_models_data = $this->get_generation_models($scenario, false);
            $image_models_data = $this->get_generation_models('image_generation', false);
            $allowed_models = array('auto' => true);
            $generation_models = isset($generation_models_data['models']) && is_array($generation_models_data['models'])
                ? $generation_models_data['models']
                : array();
            if ($scenario === 'image_generation') {
                $generation_models = isset($image_models_data['models']) && is_array($image_models_data['models'])
                    ? $image_models_data['models']
                    : array();
            }
            foreach ($generation_models as $model_item) {
                if (!is_array($model_item) || empty($model_item['id'])) {
                    continue;
                }
                $allowed_models[(string) $model_item['id']] = true;
            }
            if (!isset($allowed_models[$model])) {
                wp_send_json_error(array('message' => __('Выберите корректную модель.', 'unicontent-ai-generator')));
            }

            $allowed_image_models = array('auto' => true);
            $image_models = isset($image_models_data['models']) && is_array($image_models_data['models'])
                ? $image_models_data['models']
                : array();
            foreach ($image_models as $model_item) {
                if (!is_array($model_item) || empty($model_item['id'])) {
                    continue;
                }
                $allowed_image_models[(string) $model_item['id']] = true;
            }

            $settings = UCG_Settings::get();
            $max_tokens = isset($settings['max_tokens']) ? (int) $settings['max_tokens'] : 1500;
            $max_tokens = max(1, min(4000, $max_tokens));
            $api_client = new UCG_Api_Client();

            $zero_payload = array(
                'signature' => $signature,
                'scenario' => $scenario,
                'selection_mode' => $selection_mode,
                'estimate' => array(
                    'credits_per_unit_p50' => 0.0,
                    'credits_per_unit_p90' => 0.0,
                    'credits_per_record_p50' => 0.0,
                    'credits_per_record_p90' => 0.0,
                    'total_credits_p50' => 0.0,
                    'total_credits_p90' => 0.0,
                    'planned_count' => $planned_count,
                    'items_per_post' => $items_per_post,
                    'components_count' => 0,
                    'calculation_mode' => 'empty',
                ),
                'components' => array(),
            );

            if ($planned_count <= 0) {
                wp_send_json_success($zero_payload);
            }

            $component_sum_p50 = 0.0;
            $component_sum_p90 = 0.0;
            $component_details = array();

            $read_component_credits = function ($quote_response) {
                $estimate = isset($quote_response['estimate']) && is_array($quote_response['estimate'])
                    ? $quote_response['estimate']
                    : array();
                $p50 = isset($estimate['credits_p50']) ? max(0.0, (float) $estimate['credits_p50']) : 0.0;
                $p90 = isset($estimate['credits_p90']) ? max($p50, (float) $estimate['credits_p90']) : $p50;
                return array($p50, $p90);
            };

            $quote_component = function ($payload, $component_key) use (&$component_sum_p50, &$component_sum_p90, &$component_details, $api_client, $read_component_credits) {
                $quote_response = $api_client->create_pricing_quote($payload);
                if (is_wp_error($quote_response)) {
                    return $quote_response;
                }

                list($credits_p50, $credits_p90) = $read_component_credits($quote_response);
                $component_sum_p50 += $credits_p50;
                $component_sum_p90 += $credits_p90;
                $component_details[] = array(
                    'component' => (string) $component_key,
                    'credits_p50' => round($credits_p50, 6),
                    'credits_p90' => round($credits_p90, 6),
                );

                return true;
            };

            $resolve_field_max_chars = function ($field_max_chars, $field_length_option_id) use ($length_max_chars_map, $length_option_id) {
                $max_chars = max(0, (int) $field_max_chars);
                if ($max_chars > 0) {
                    return $max_chars;
                }

                $length_candidate = (int) $field_length_option_id;
                if ($length_candidate <= 0) {
                    $length_candidate = (int) $length_option_id;
                }
                if ($length_candidate > 0 && isset($length_max_chars_map[$length_candidate])) {
                    return max(0, (int) $length_max_chars_map[$length_candidate]);
                }

                return 1200;
            };

            $allowed_image_aspect_ratios = array('1:1', '2:3', '3:2', '3:4', '4:3', '4:5', '5:4', '9:16', '16:9', '21:9', '1:4', '4:1', '1:8', '8:1');
            $allowed_image_sizes = array('0.5K', '1K', '2K', '4K');

            $quote_item_topic = '';
            $quote_item_context = '';
            if ($is_create_new_mode && !empty($create_topics)) {
                $quote_item_topic = sanitize_text_field((string) $create_topics[0]);
                $quote_item_context = $quote_item_topic;
            }
            if ($is_create_new_mode && $quote_item_topic === '') {
                $quote_item_topic = 'item';
                $quote_item_context = 'item';
            }

            $build_image_quote_payload = function ($field_prompt, $field_model_raw, $field) use ($allowed_image_models, $model, $max_tokens, $allowed_image_aspect_ratios, $allowed_image_sizes) {
                $image_model = $this->normalize_model_identifier((string) $field_model_raw);
                if ($image_model === '' || !isset($allowed_image_models[$image_model])) {
                    $image_model = $model;
                }
                if ($image_model === '' || !isset($allowed_image_models[$image_model])) {
                    $image_model = 'auto';
                }

                $images_count = isset($field['images_count']) ? max(1, min(8, (int) $field['images_count'])) : 1;
                $aspect_ratio = isset($field['aspect_ratio']) ? sanitize_text_field((string) $field['aspect_ratio']) : '';
                if (!in_array($aspect_ratio, $allowed_image_aspect_ratios, true)) {
                    $aspect_ratio = '';
                }
                $image_size = isset($field['image_size']) ? strtoupper(sanitize_text_field((string) $field['image_size'])) : '';
                if (!in_array($image_size, $allowed_image_sizes, true)) {
                    $image_size = '';
                }

                $payload = array(
                    'operation_type' => 'image',
                    'model' => $image_model,
                    'prompt' => $field_prompt,
                    'max_tokens' => $max_tokens,
                    'images_count' => $images_count,
                );
                if ($aspect_ratio !== '') {
                    $payload['aspect_ratio'] = $aspect_ratio;
                }
                if ($image_size !== '') {
                    $payload['image_size'] = $image_size;
                }

                return $payload;
            };

            $normalized_ai_fields = $this->normalize_generation_fields($ai_fields, 'ai', $scenario);
            $enabled_ai_fields = array();
            foreach ($normalized_ai_fields as $field) {
                if (!is_array($field)) {
                    continue;
                }
                if (array_key_exists('enabled', $field) && empty($field['enabled'])) {
                    continue;
                }
                $field_prompt = isset($field['prompt']) ? trim((string) $field['prompt']) : '';
                if ($field_prompt === '') {
                    continue;
                }
                $enabled_ai_fields[] = $field;
            }

            $per_unit_p50 = 0.0;
            $per_unit_p90 = 0.0;
            $per_record_p50 = 0.0;
            $per_record_p90 = 0.0;
            $calculation_mode = 'api_quote';
            $scenario_operation_type = $this->resolve_operation_type_for_scenario($scenario, 'text');

            if ($this->scenario_supports_multi_fields($scenario)) {
                if (empty($enabled_ai_fields)) {
                    $zero_payload['estimate']['calculation_mode'] = 'no_enabled_ai_fields';
                    wp_send_json_success($zero_payload);
                }

                $is_create_json_mode = ($scenario === 'post_fields' || $scenario === 'product_fields') && $is_create_new_mode;
                if ($is_create_json_mode) {
                    $multi_fields_spec = array();
                    $prepared_text_fields = array();

                    foreach ($enabled_ai_fields as $field) {
                        $field_key = sanitize_key(isset($field['key']) ? (string) $field['key'] : '');
                        if ($field_key === '') {
                            continue;
                        }
                        $field_prompt = isset($field['prompt']) ? trim((string) $field['prompt']) : '';
                        if ($field_prompt === '') {
                            continue;
                        }
                        $field_label = isset($field['label']) ? sanitize_text_field((string) $field['label']) : $field_key;
                        $field_target = $this->normalize_generation_target_field(isset($field['target_field']) ? (string) $field['target_field'] : '');
                        $field_output_type = sanitize_key(isset($field['output_type']) ? (string) $field['output_type'] : '');
                        if ($field_output_type === '') {
                            $field_output_type = strpos($field_target, 'media:') === 0 ? 'image' : 'text';
                        }
                        if ($field_output_type !== 'image') {
                            $field_output_type = 'text';
                        }

                        $field_prompt = $this->inject_create_runtime_tokens_for_preview($field_prompt, $quote_item_topic, $quote_item_context);
                        $field_prompt = $this->prepend_create_topic_context_to_prompt_for_preview($field_prompt, $quote_item_topic, $quote_item_context);
                        if (trim((string) $field_prompt) === '') {
                            continue;
                        }

                        if ($field_output_type === 'text') {
                            if ($field_target === 'seo_field:title') {
                                $field_prompt = $this->build_prompt_for_single_seo_field_for_preview($field_prompt, 'title');
                            } elseif ($field_target === 'seo_field:description') {
                                $field_prompt = $this->build_prompt_for_single_seo_field_for_preview($field_prompt, 'description');
                            }
                        }

                        if ($field_output_type === 'image') {
                            $quote_result = $quote_component(
                                $build_image_quote_payload(
                                    $field_prompt,
                                    isset($field['model']) ? (string) $field['model'] : '',
                                    $field
                                ),
                                'field:image:' . $field_key
                            );
                            if (is_wp_error($quote_result)) {
                                wp_send_json_error(array('message' => $quote_result->get_error_message()));
                            }
                            continue;
                        }

                        $field_length_option_id = isset($field['length_option_id']) ? (int) $field['length_option_id'] : 0;
                        $field_length_option_id = $this->resolve_length_option_id($field_length_option_id, $text_length_options);
                        if ($field_length_option_id <= 0) {
                            $field_length_option_id = $length_option_id;
                        }

                        $multi_fields_spec[] = array(
                            'key' => $field_key,
                            'label' => $field_label,
                            'max_chars' => $resolve_field_max_chars(
                                isset($field['max_chars']) ? (int) $field['max_chars'] : 0,
                                $field_length_option_id
                            ),
                            'required' => true,
                        );
                        $prepared_text_fields[$field_key] = array(
                            'label' => $field_label,
                            'prompt' => $field_prompt,
                            'max_chars' => $resolve_field_max_chars(
                                isset($field['max_chars']) ? (int) $field['max_chars'] : 0,
                                $field_length_option_id
                            ),
                        );
                    }

                    if (!empty($multi_fields_spec)) {
                        $multi_field_prompt = $this->build_multi_field_json_prompt_for_preview($quote_item_topic, $prepared_text_fields);
                        $quote_result = $quote_component(
                            array(
                                'operation_type' => $scenario_operation_type,
                                'model' => $model,
                                'prompt' => $multi_field_prompt,
                                'max_tokens' => $max_tokens,
                                'fields' => $multi_fields_spec,
                            ),
                            'multi_field_json'
                        );
                        if (is_wp_error($quote_result)) {
                            wp_send_json_error(array('message' => $quote_result->get_error_message()));
                        }
                    }

                    $calculation_mode = 'multi_field_json_create';
                } else {
                    foreach ($enabled_ai_fields as $field) {
                        $field_key = sanitize_key(isset($field['key']) ? (string) $field['key'] : '');
                        if ($field_key === '') {
                            continue;
                        }
                        $field_prompt = isset($field['prompt']) ? trim((string) $field['prompt']) : '';
                        if ($field_prompt === '') {
                            continue;
                        }
                        $field_output_type = sanitize_key(isset($field['output_type']) ? (string) $field['output_type'] : '');
                        if ($field_output_type === '') {
                            $field_target = $this->normalize_generation_target_field(isset($field['target_field']) ? (string) $field['target_field'] : '');
                            $field_output_type = strpos($field_target, 'media:') === 0 ? 'image' : 'text';
                        }

                        if ($field_output_type === 'image') {
                            $quote_result = $quote_component(
                                $build_image_quote_payload(
                                    $field_prompt,
                                    isset($field['model']) ? (string) $field['model'] : '',
                                    $field
                                ),
                                'field:image:' . $field_key
                            );
                            if (is_wp_error($quote_result)) {
                                wp_send_json_error(array('message' => $quote_result->get_error_message()));
                            }
                            continue;
                        }

                        $field_length_option_id = isset($field['length_option_id']) ? (int) $field['length_option_id'] : 0;
                        $field_length_option_id = $this->resolve_length_option_id($field_length_option_id, $text_length_options);
                        if ($field_length_option_id <= 0) {
                            $field_length_option_id = $length_option_id;
                        }

                        $quote_payload = array(
                            'operation_type' => $scenario_operation_type,
                            'model' => $model,
                            'prompt' => $field_prompt,
                            'max_tokens' => $max_tokens,
                            'vary_length' => $vary_length,
                        );
                        if ($field_length_option_id > 0) {
                            $quote_payload['length_option_id'] = $field_length_option_id;
                        }

                        $quote_result = $quote_component($quote_payload, 'field:text:' . $field_key);
                        if (is_wp_error($quote_result)) {
                            wp_send_json_error(array('message' => $quote_result->get_error_message()));
                        }
                    }

                    $calculation_mode = 'multi_field_iterative';
                }

                $per_unit_p50 = $component_sum_p50;
                $per_unit_p90 = $component_sum_p90;
                $per_record_p50 = $component_sum_p50;
                $per_record_p90 = $component_sum_p90;
            } elseif ($scenario === 'seo_tags') {
                $seo_title_field = null;
                $seo_description_field = null;
                foreach ($enabled_ai_fields as $field) {
                    if (!is_array($field)) {
                        continue;
                    }
                    $field_key = sanitize_key(isset($field['key']) ? (string) $field['key'] : '');
                    $field_target = $this->normalize_generation_target_field(
                        isset($field['target_field']) ? (string) $field['target_field'] : ''
                    );
                    if (
                        $seo_title_field === null
                        && ($field_target === 'seo_field:title' || $field_key === 'seo_title' || $field_key === 'title' || $field_key === 'meta_title')
                    ) {
                        $seo_title_field = $field;
                    }
                    if (
                        $seo_description_field === null
                        && ($field_target === 'seo_field:description' || $field_key === 'seo_description' || $field_key === 'description' || $field_key === 'meta_description' || $field_key === 'desc')
                    ) {
                        $seo_description_field = $field;
                    }
                }

                if ($seo_title_field === null) {
                    $seo_title_field = array(
                        'prompt' => $template_body_seo_title,
                        'length_option_id' => $length_option_id,
                    );
                }
                if ($seo_description_field === null) {
                    $seo_description_field = array(
                        'prompt' => $template_body_seo_description,
                        'length_option_id' => $length_option_id,
                    );
                }

                $seo_title_prompt = isset($seo_title_field['prompt']) ? trim((string) $seo_title_field['prompt']) : '';
                $seo_description_prompt = isset($seo_description_field['prompt']) ? trim((string) $seo_description_field['prompt']) : '';
                if ($seo_title_prompt === '' || $seo_description_prompt === '') {
                    wp_send_json_error(array('message' => __('Заполните шаблоны для SEO title и SEO description.', 'unicontent-ai-generator')));
                }

                $seo_title_length_option_id = isset($seo_title_field['length_option_id']) ? (int) $seo_title_field['length_option_id'] : 0;
                $seo_title_length_option_id = $this->resolve_length_option_id($seo_title_length_option_id, $text_length_options);
                if ($seo_title_length_option_id <= 0) {
                    $seo_title_length_option_id = $length_option_id;
                }
                $seo_description_length_option_id = isset($seo_description_field['length_option_id']) ? (int) $seo_description_field['length_option_id'] : 0;
                $seo_description_length_option_id = $this->resolve_length_option_id($seo_description_length_option_id, $text_length_options);
                if ($seo_description_length_option_id <= 0) {
                    $seo_description_length_option_id = $length_option_id;
                }

                $quote_result = $quote_component(
                    array(
                        'operation_type' => $this->resolve_operation_type_for_scenario($scenario, 'seo_tags'),
                        'model' => $model,
                        'prompt' => $seo_title_prompt,
                        'max_tokens' => $max_tokens,
                        'length_option_id' => $seo_title_length_option_id,
                        'vary_length' => $vary_length,
                    ),
                    'seo:title'
                );
                if (is_wp_error($quote_result)) {
                    wp_send_json_error(array('message' => $quote_result->get_error_message()));
                }

                $quote_result = $quote_component(
                    array(
                        'operation_type' => $this->resolve_operation_type_for_scenario($scenario, 'seo_tags'),
                        'model' => $model,
                        'prompt' => $seo_description_prompt,
                        'max_tokens' => $max_tokens,
                        'length_option_id' => $seo_description_length_option_id,
                        'vary_length' => $vary_length,
                    ),
                    'seo:description'
                );
                if (is_wp_error($quote_result)) {
                    wp_send_json_error(array('message' => $quote_result->get_error_message()));
                }

                $per_unit_p50 = $component_sum_p50;
                $per_unit_p90 = $component_sum_p90;
                $per_record_p50 = $component_sum_p50;
                $per_record_p90 = $component_sum_p90;
                $calculation_mode = 'seo_double_call';
            } else {
                if ($template_body === '') {
                    wp_send_json_error(array('message' => __('Шаблон пустой. Заполните текст.', 'unicontent-ai-generator')));
                }
                if ($length_option_id <= 0) {
                    wp_send_json_error(array('message' => __('Выберите диапазон длины текста.', 'unicontent-ai-generator')));
                }

                $quote_result = $quote_component(
                    array(
                        'operation_type' => $scenario_operation_type,
                        'model' => $model,
                        'prompt' => $template_body,
                        'max_tokens' => $max_tokens,
                        'length_option_id' => $length_option_id,
                        'vary_length' => $vary_length,
                    ),
                    'single_prompt'
                );
                if (is_wp_error($quote_result)) {
                    wp_send_json_error(array('message' => $quote_result->get_error_message()));
                }

                $per_unit_p50 = $component_sum_p50;
                $per_unit_p90 = $component_sum_p90;
                if ($scenario === 'comments' || $scenario === 'woo_reviews') {
                    $per_record_p50 = $component_sum_p50 * $items_per_post;
                    $per_record_p90 = $component_sum_p90 * $items_per_post;
                    $calculation_mode = 'per_record_with_items';
                } else {
                    $per_record_p50 = $component_sum_p50;
                    $per_record_p90 = $component_sum_p90;
                    $calculation_mode = 'single_prompt';
                }
            }

            $total_credits_p50 = $per_record_p50 * $planned_count;
            $total_credits_p90 = $per_record_p90 * $planned_count;

            wp_send_json_success(
                array(
                    'signature' => $signature,
                    'scenario' => $scenario,
                    'selection_mode' => $selection_mode,
                    'estimate' => array(
                        'credits_per_unit_p50' => round($per_unit_p50, 6),
                        'credits_per_unit_p90' => round($per_unit_p90, 6),
                        'credits_per_record_p50' => round($per_record_p50, 6),
                        'credits_per_record_p90' => round($per_record_p90, 6),
                        'total_credits_p50' => round($total_credits_p50, 6),
                        'total_credits_p90' => round($total_credits_p90, 6),
                        'planned_count' => $planned_count,
                        'items_per_post' => $items_per_post,
                        'components_count' => count($component_details),
                        'calculation_mode' => $calculation_mode,
                    ),
                    'components' => $component_details,
                )
            );
        }

        public function ajax_wizard_example() {
            $this->guard_ajax();

            if (!UCG_Settings::has_valid_api_key()) {
                wp_send_json_error(array('message' => __('Сначала добавьте и проверьте API ключ.', 'unicontent-ai-generator')));
            }

            $post_type = sanitize_key($this->get_request_string($_POST, 'post_type', ''));
            $scenario = $this->normalize_generation_scenario(
                $this->get_request_string($_POST, 'scenario', self::DEFAULT_GENERATION_SCENARIO),
                $post_type
            );
            if ($this->scenario_requires_product_post_type($scenario)) {
                $post_type = 'product';
            }
            $target_field = sanitize_text_field($this->get_request_string($_POST, 'target_field', ''));
            $selection_mode = sanitize_key($this->get_request_string($_POST, 'selection_mode', 'selected'));
            $model = $this->normalize_model_identifier($this->get_request_string($_POST, 'model', 'auto'));
            if ($model === '') {
                $model = 'auto';
            }
            $vary_length = !empty($_POST['vary_length']) ? 1 : 0;
            $length_option_id = $this->get_request_int($_POST, 'length_option_id', 0);
            $rating_min = $this->get_request_int($_POST, 'rating_min', 1);
            $rating_max = $this->get_request_int($_POST, 'rating_max', 5);

            $style_language = sanitize_key($this->get_request_string($_POST, 'style_language', (string) UCG_Settings::get_option('default_language', 'auto')));
            $style_tone = sanitize_key($this->get_request_string($_POST, 'style_tone', (string) UCG_Settings::get_option('default_tone', 'neutral')));

            $template_body = sanitize_textarea_field($this->get_request_string($_POST, 'template_body', ''));
            $template_body_seo_title = sanitize_textarea_field($this->get_request_string($_POST, 'template_body_seo_title', ''));
            $template_body_seo_description = sanitize_textarea_field($this->get_request_string($_POST, 'template_body_seo_description', ''));
            $ai_fields = $this->parse_generation_fields_from_request($this->get_request_string($_POST, 'ai_fields', '[]'), 'ai', $scenario);
            $static_fields = $this->parse_generation_fields_from_request($this->get_request_string($_POST, 'static_fields', '[]'), 'static', $scenario);

            if ($post_type === '' || !post_type_exists($post_type)) {
                wp_send_json_error(array('message' => __('Некорректный post type.', 'unicontent-ai-generator')));
            }
            if ($scenario === 'post_fields' && $post_type === 'product') {
                wp_send_json_error(array('message' => __('Для товаров используйте сценарий "Товары".', 'unicontent-ai-generator')));
            }
            if ($scenario === 'woo_reviews' && $post_type !== 'product') {
                wp_send_json_error(array('message' => __('Для сценария отзывов WooCommerce выберите тип записей: Товар (product).', 'unicontent-ai-generator')));
            }
            if ($scenario === 'comments' && !post_type_supports($post_type, 'comments')) {
                wp_send_json_error(array('message' => __('Выбранный тип записей не поддерживает комментарии.', 'unicontent-ai-generator')));
            }
            if ($selection_mode !== 'filtered' && $selection_mode !== 'selected' && $selection_mode !== 'create_new') {
                $selection_mode = 'selected';
            }
            $is_create_new_mode = $selection_mode === 'create_new' && $this->scenario_supports_create_new_mode($scenario);

            if ($scenario === 'comments' || $scenario === 'woo_reviews') {
                if ($target_field === '') {
                    $target_field = $scenario === 'comments' ? 'comment:publish' : 'woo_review:publish';
                }
            }

            if ($length_option_id <= 0 && !$this->scenario_supports_multi_fields($scenario)) {
                wp_send_json_error(array('message' => __('Выберите диапазон длины текста.', 'unicontent-ai-generator')));
            }

            if ($scenario === 'seo_tags') {
                if (trim($template_body_seo_title) === '' || trim($template_body_seo_description) === '') {
                    wp_send_json_error(array('message' => __('Заполните шаблоны для SEO title и SEO description.', 'unicontent-ai-generator')));
                }
            } elseif (!$this->scenario_supports_multi_fields($scenario)) {
                if (trim($template_body) === '') {
                    wp_send_json_error(array('message' => __('Текст шаблона не может быть пустым.', 'unicontent-ai-generator')));
                }
            }

            $create_topics = $this->normalize_create_topics($this->get_request_string($_POST, 'create_topics', ''), 1000);
            $preview_item_topic = '';
            if ($is_create_new_mode) {
                if (empty($create_topics)) {
                    wp_send_json_error(array('message' => __('Добавьте хотя бы одну тему для создания.', 'unicontent-ai-generator')));
                }
                $preview_item_topic = sanitize_text_field((string) $create_topics[0]);
            }

            $filters = $this->normalize_filters_from_request($this->get_request_string($_POST, 'filters', '[]'), $post_type);
            $post_id = 0;
            if ($is_create_new_mode) {
                $post_id = 0;
            } elseif ($selection_mode === 'filtered') {
                $ids = $this->query_filtered_post_ids($post_type, $filters, 1, 0);
                if (!empty($ids)) {
                    $post_id = (int) $ids[0];
                }
            } else {
                $selected_ids = $this->parse_ids_json($this->get_request_string($_POST, 'selected_ids', '[]'));
                $ids = $this->validate_post_ids_for_type($selected_ids, $post_type);
                if (!empty($ids)) {
                    $post_id = (int) $ids[0];
                }
            }

            if (!$is_create_new_mode && $post_id <= 0) {
                wp_send_json_error(array('message' => __('Не удалось выбрать запись для примера.', 'unicontent-ai-generator')));
            }

            $settings = UCG_Settings::get();
            $base_system_prompt = isset($settings['system_prompt']) ? (string) $settings['system_prompt'] : '';
            $max_tokens = isset($settings['max_tokens']) ? (int) $settings['max_tokens'] : 1500;

            $run_seed = function_exists('wp_generate_uuid4') ? wp_generate_uuid4() : (string) wp_rand(100000, 999999) . '-' . time();
            $generator = new UCG_Generator();
            $system_prompt = $generator->build_effective_system_prompt_for_preview(
                $base_system_prompt,
                $scenario,
                $style_language,
                $style_tone,
                'medium',
                $run_seed,
                1,
                $post_id,
                1
            );

            $api_client = new UCG_Api_Client();

            if ($this->scenario_supports_multi_fields($scenario)) {
                $normalized_ai_fields = $this->normalize_generation_fields($ai_fields, 'ai', $scenario);
                $normalized_static_fields = $this->normalize_generation_fields($static_fields, 'static', $scenario);

                $enabled_ai_fields = array();
                foreach ($normalized_ai_fields as $field) {
                    if (!is_array($field)) {
                        continue;
                    }
                    if (array_key_exists('enabled', $field) && empty($field['enabled'])) {
                        continue;
                    }
                    $field_prompt_template = isset($field['prompt']) ? trim((string) $field['prompt']) : '';
                    if ($field_prompt_template === '') {
                        continue;
                    }
                    $enabled_ai_fields[] = $field;
                }

                if (empty($enabled_ai_fields)) {
                    if ($this->has_enabled_static_fields_with_value($normalized_static_fields)) {
                        wp_send_json_success(
                            array(
                                'post_id' => $post_id,
                                'scenario' => $scenario,
                                'preview' => __('Выбраны только static-поля. Текстовый превью-ответ для них не формируется.', 'unicontent-ai-generator'),
                                'credits_spent' => 0.0,
                                'credits_remaining' => 0.0,
                            )
                        );
                    }
                    wp_send_json_error(array('message' => __('Выберите хотя бы одно AI-поле с непустым промптом.', 'unicontent-ai-generator')));
                }

                if ($is_create_new_mode) {
                    $prepared_text_fields = array();
                    $prepared_image_fields = array();
                    $fields_spec = array();
                    foreach ($enabled_ai_fields as $field) {
                        if (!is_array($field)) {
                            continue;
                        }
                        $field_key = sanitize_key(isset($field['key']) ? (string) $field['key'] : '');
                        if ($field_key === '') {
                            continue;
                        }
                        $field_label = sanitize_text_field(isset($field['label']) ? (string) $field['label'] : $field_key);
                        $field_prompt_template = isset($field['prompt']) ? (string) $field['prompt'] : '';
                        $field_target = $this->normalize_generation_target_field(
                            isset($field['target_field']) ? (string) $field['target_field'] : ''
                        );
                        $field_max_chars = isset($field['max_chars']) ? max(0, (int) $field['max_chars']) : 0;
                        $field_output_type = sanitize_key(isset($field['output_type']) ? (string) $field['output_type'] : '');
                        if ($field_output_type === '') {
                            $field_output_type = strpos($field_target, 'media:') === 0 ? 'image' : 'text';
                        }
                        if ($field_output_type !== 'image') {
                            $field_output_type = 'text';
                        }
                        $field_model = $this->normalize_model_identifier(isset($field['model']) ? (string) $field['model'] : 'auto');
                        if ($field_model === '') {
                            $field_model = 'auto';
                        }
                        $field_images_count = isset($field['images_count']) ? max(1, min(8, (int) $field['images_count'])) : 1;
                        $field_aspect_ratio = isset($field['aspect_ratio']) ? sanitize_text_field((string) $field['aspect_ratio']) : '';
                        if (!in_array($field_aspect_ratio, array('1:1', '2:3', '3:2', '3:4', '4:3', '4:5', '5:4', '9:16', '16:9', '21:9', '1:4', '4:1', '1:8', '8:1'), true)) {
                            $field_aspect_ratio = '';
                        }
                        $field_image_size = isset($field['image_size']) ? strtoupper(sanitize_text_field((string) $field['image_size'])) : '';
                        if (!in_array($field_image_size, array('0.5K', '1K', '2K', '4K'), true)) {
                            $field_image_size = '';
                        }
                        if ($field_target === 'seo_field:title' && $field_max_chars <= 0) {
                            $field_max_chars = 70;
                        } elseif ($field_target === 'seo_field:description' && $field_max_chars <= 0) {
                            $field_max_chars = 160;
                        }

                        $field_prompt = trim((string) $field_prompt_template);
                        $field_prompt = $this->inject_create_runtime_tokens_for_preview($field_prompt, $preview_item_topic, $preview_item_topic);
                        $field_prompt = $this->prepend_create_topic_context_to_prompt_for_preview($field_prompt, $preview_item_topic, $preview_item_topic);
                        if (trim($field_prompt) === '') {
                            continue;
                        }

                        if ($field_output_type === 'text') {
                            if ($field_target === 'seo_field:title') {
                                $field_prompt = $generator->build_prompt_for_single_seo_field_for_preview($field_prompt, 'title');
                            } elseif ($field_target === 'seo_field:description') {
                                $field_prompt = $generator->build_prompt_for_single_seo_field_for_preview($field_prompt, 'description');
                            }
                        }

                        $prepared_field = array(
                            'key' => $field_key,
                            'label' => $field_label,
                            'target_field' => $field_target,
                            'max_chars' => $field_max_chars,
                            'prompt' => $field_prompt,
                            'length_option_id' => isset($field['length_option_id']) ? max(0, (int) $field['length_option_id']) : 0,
                            'model' => $field_model,
                            'output_type' => $field_output_type,
                            'images_count' => $field_images_count,
                            'aspect_ratio' => $field_aspect_ratio,
                            'image_size' => $field_image_size,
                        );

                        if ($field_output_type === 'image') {
                            $prepared_image_fields[$field_key] = $prepared_field;
                            continue;
                        }

                        $prepared_text_fields[$field_key] = $prepared_field;
                        $fields_spec[] = array(
                            'key' => $field_key,
                            'label' => $field_label,
                            'max_chars' => $field_max_chars,
                            'hint' => $this->multi_field_hint_for_target_field_preview($field_key, $field_target),
                            'required' => true,
                        );
                    }

                    if (empty($prepared_text_fields) && empty($prepared_image_fields)) {
                        wp_send_json_error(array('message' => __('Выберите хотя бы одно AI-поле с непустым промптом.', 'unicontent-ai-generator')));
                    }

                    $preview_parts = array();
                    $preview_fields = array();
                    $generated_values_by_key = array();
                    $credits_spent_total = 0.0;
                    $credits_remaining = 0.0;

                    if (!empty($prepared_text_fields)) {
                        $multi_prompt = $this->build_multi_field_json_prompt_for_preview($preview_item_topic, $prepared_text_fields);
                        $response = $api_client->generate_multi_field(
                            $multi_prompt,
                            $system_prompt,
                            $fields_spec,
                            $model,
                            $max_tokens,
                            $this->resolve_operation_type_for_scenario($scenario, 'text')
                        );
                        if (is_wp_error($response)) {
                            wp_send_json_error(array('message' => $response->get_error_message()));
                        }

                        $response_fields = $this->extract_multi_field_response_fields_for_preview($response);
                        if (empty($response_fields)) {
                            $fallback_response = $this->generate_multi_field_preview_fallback_response(
                                $api_client,
                                $prepared_text_fields,
                                $system_prompt,
                                $max_tokens,
                                $model,
                                $this->resolve_operation_type_for_scenario($scenario, 'text')
                            );
                            if (!is_wp_error($fallback_response) && is_array($fallback_response)) {
                                $response_fields = $this->extract_multi_field_response_fields_for_preview($fallback_response);
                                if (!empty($response_fields)) {
                                    $response = array_merge($response, $fallback_response);
                                }
                            }
                        }
                        if (empty($response_fields) || !is_array($response_fields)) {
                            wp_send_json_error(array('message' => __('API вернул невалидный JSON для multi-field превью.', 'unicontent-ai-generator')));
                        }

                        foreach ($prepared_text_fields as $field_key => $field_meta) {
                            $raw_value = array_key_exists($field_key, $response_fields) ? $response_fields[$field_key] : '';
                            if (is_array($raw_value) || is_object($raw_value)) {
                                $preview_value = wp_json_encode($raw_value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                            } else {
                                $preview_value = (string) $raw_value;
                            }
                            $preview_value = trim((string) $preview_value);

                            $field_target = isset($field_meta['target_field']) ? (string) $field_meta['target_field'] : '';
                            $field_max_chars = isset($field_meta['max_chars']) ? max(0, (int) $field_meta['max_chars']) : 0;
                            if ($field_target === 'seo_field:title' || $field_target === 'seo_field:description') {
                                $preview_value = wp_strip_all_tags($preview_value);
                                $preview_value = preg_replace('/\s+/u', ' ', $preview_value);
                                $preview_value = trim((string) $preview_value, "\"'` ");
                            }
                            if ($field_max_chars > 0) {
                                if (function_exists('mb_strlen') && function_exists('mb_substr')) {
                                    if (mb_strlen($preview_value, 'UTF-8') > $field_max_chars) {
                                        $preview_value = trim(mb_substr($preview_value, 0, $field_max_chars, 'UTF-8'));
                                    }
                                } elseif (strlen($preview_value) > $field_max_chars) {
                                    $preview_value = trim(substr($preview_value, 0, $field_max_chars));
                                }
                            }

                            $field_label = isset($field_meta['label']) ? (string) $field_meta['label'] : $field_key;
                            $preview_parts[] = $field_label . ":\n" . $preview_value;
                            $preview_fields[] = array(
                                'key' => $field_key,
                                'label' => $field_label,
                                'value' => $preview_value,
                                'target_field' => $field_target,
                            );
                            $generated_values_by_key[$field_key] = $preview_value;
                        }

                        $credits_spent_total += isset($response['credits_spent']) ? (float) $response['credits_spent'] : 0.0;
                        if (isset($response['credits_remaining'])) {
                            $credits_remaining = (float) $response['credits_remaining'];
                        }
                    }

                    if (!empty($prepared_image_fields)) {
                        foreach ($prepared_image_fields as $field_key => $field_meta) {
                            if (!is_array($field_meta)) {
                                continue;
                            }
                            $image_prompt = isset($field_meta['prompt']) ? (string) $field_meta['prompt'] : '';
                            $image_prompt = $this->inject_generated_field_tokens_for_preview($image_prompt, $generated_values_by_key);
                            if (trim($image_prompt) === '') {
                                continue;
                            }

                            $image_model = $this->normalize_model_identifier(isset($field_meta['model']) ? (string) $field_meta['model'] : '');
                            if ($image_model === '') {
                                $image_model = $model;
                            }
                            if ($image_model === '') {
                                $image_model = 'auto';
                            }
                            $image_count = isset($field_meta['images_count']) ? max(1, min(8, (int) $field_meta['images_count'])) : 1;
                            $image_aspect_ratio = isset($field_meta['aspect_ratio']) ? sanitize_text_field((string) $field_meta['aspect_ratio']) : '';
                            $image_size = isset($field_meta['image_size']) ? strtoupper(sanitize_text_field((string) $field_meta['image_size'])) : '';

                            $response = $api_client->generate_image(
                                $image_prompt,
                                $system_prompt,
                                $image_model,
                                $image_count,
                                $image_aspect_ratio,
                                $image_size,
                                'image'
                            );
                            if (is_wp_error($response)) {
                                wp_send_json_error(array('message' => $response->get_error_message()));
                            }

                            $image_sources = $this->extract_image_sources_from_response_for_preview($response);
                            if (empty($image_sources)) {
                                wp_send_json_error(array('message' => __('API не вернул изображение для превью.', 'unicontent-ai-generator')));
                            }

                            $field_label = isset($field_meta['label']) ? (string) $field_meta['label'] : $field_key;
                            $preview_value = sprintf(
                                __('Сгенерировано изображений: %d', 'unicontent-ai-generator'),
                                count($image_sources)
                            );
                            $preview_parts[] = $field_label . ":\n" . $preview_value;
                            $preview_fields[] = array(
                                'key' => $field_key,
                                'label' => $field_label,
                                'value' => $preview_value,
                                'target_field' => isset($field_meta['target_field']) ? (string) $field_meta['target_field'] : '',
                                'images' => $image_sources,
                            );
                            $generated_values_by_key[$field_key] = (string) $image_sources[0];

                            $credits_spent_total += isset($response['credits_spent']) ? (float) $response['credits_spent'] : 0.0;
                            if (isset($response['credits_remaining'])) {
                                $credits_remaining = (float) $response['credits_remaining'];
                            }
                        }
                    }

                    $preview_text = implode("\n\n", $preview_parts);
                    if (empty($preview_fields)) {
                        wp_send_json_error(array('message' => __('Не удалось сформировать превью по выбранным полям.', 'unicontent-ai-generator')));
                    }

                    wp_send_json_success(
                        array(
                            'post_id' => $post_id,
                            'scenario' => $scenario,
                            'preview' => array(
                                'type' => 'multi_field',
                                'title' => __('Пример по полям', 'unicontent-ai-generator'),
                                'fields' => $preview_fields,
                                'text' => $preview_text,
                            ),
                            'preview_text' => $preview_text,
                            'credits_spent' => $credits_spent_total,
                            'credits_remaining' => $credits_remaining,
                        )
                    );
                }

                $primary_field = $enabled_ai_fields[0];
                $field_prompt_template = isset($primary_field['prompt']) ? (string) $primary_field['prompt'] : '';
                $field_prompt = UCG_Tokens::render_prompt_for_post($field_prompt_template, $post_id);
                if (trim($field_prompt) === '') {
                    wp_send_json_error(array('message' => __('Промпт поля пустой после подстановки переменных.', 'unicontent-ai-generator')));
                }

                $field_target = $this->normalize_generation_target_field(
                    isset($primary_field['target_field']) ? (string) $primary_field['target_field'] : ''
                );
                $field_max_chars = isset($primary_field['max_chars']) ? max(0, (int) $primary_field['max_chars']) : 0;
                $field_length_option_id = isset($primary_field['length_option_id']) ? (int) $primary_field['length_option_id'] : 0;
                if ($field_length_option_id <= 0) {
                    $field_length_option_id = $this->resolve_length_option_id($length_option_id);
                }
                $field_output_type = sanitize_key(isset($primary_field['output_type']) ? (string) $primary_field['output_type'] : '');
                if ($field_output_type === '') {
                    $field_output_type = strpos($field_target, 'media:') === 0 ? 'image' : 'text';
                }
                if ($field_output_type !== 'image') {
                    $field_output_type = 'text';
                }
                $field_model = $this->normalize_model_identifier(isset($primary_field['model']) ? (string) $primary_field['model'] : '');
                if ($field_model === '') {
                    $field_model = $model;
                }
                if ($field_model === '') {
                    $field_model = 'auto';
                }
                $field_images_count = isset($primary_field['images_count']) ? max(1, min(8, (int) $primary_field['images_count'])) : 1;
                $field_aspect_ratio = isset($primary_field['aspect_ratio']) ? sanitize_text_field((string) $primary_field['aspect_ratio']) : '';
                if (!in_array($field_aspect_ratio, array('1:1', '2:3', '3:2', '3:4', '4:3', '4:5', '5:4', '9:16', '16:9', '21:9', '1:4', '4:1', '1:8', '8:1'), true)) {
                    $field_aspect_ratio = '';
                }
                $field_image_size = isset($primary_field['image_size']) ? strtoupper(sanitize_text_field((string) $primary_field['image_size'])) : '';
                if (!in_array($field_image_size, array('0.5K', '1K', '2K', '4K'), true)) {
                    $field_image_size = '';
                }

                if ($field_output_type === 'text') {
                    if ($field_target === 'seo_field:title') {
                        $field_prompt = $generator->build_prompt_for_single_seo_field_for_preview($field_prompt, 'title');
                        if ($field_max_chars <= 0) {
                            $field_max_chars = 70;
                        }
                    } elseif ($field_target === 'seo_field:description') {
                        $field_prompt = $generator->build_prompt_for_single_seo_field_for_preview($field_prompt, 'description');
                        if ($field_max_chars <= 0) {
                            $field_max_chars = 160;
                        }
                    } else {
                        $field_prompt = $generator->build_prompt_for_comment_and_review_scenarios_for_preview($field_prompt, $scenario, $rating_min, $rating_max);
                    }
                }

                if ($field_output_type === 'image') {
                    $response = $api_client->generate_image(
                        $field_prompt,
                        $system_prompt,
                        $field_model,
                        $field_images_count,
                        $field_aspect_ratio,
                        $field_image_size,
                        'image'
                    );
                } else {
                    $response = $api_client->generate_text(
                        $field_prompt,
                        $system_prompt,
                        $max_tokens,
                        $field_length_option_id,
                        $vary_length,
                        $model,
                        $this->resolve_operation_type_for_scenario($scenario, 'text')
                    );
                }
                if (is_wp_error($response)) {
                    wp_send_json_error(array('message' => $response->get_error_message()));
                }

                if ($field_output_type === 'image') {
                    $image_sources = $this->extract_image_sources_from_response_for_preview($response);
                    if (empty($image_sources)) {
                        wp_send_json_error(array('message' => __('API не вернул изображение для превью.', 'unicontent-ai-generator')));
                    }
                    $preview_value = sprintf(
                        __('Сгенерировано изображений: %d', 'unicontent-ai-generator'),
                        count($image_sources)
                    );

                    wp_send_json_success(
                        array(
                            'post_id' => $post_id,
                            'scenario' => $scenario,
                            'preview' => array(
                                'type' => 'multi_field',
                                'title' => __('Пример по полям', 'unicontent-ai-generator'),
                                'fields' => array(
                                    array(
                                        'key' => isset($primary_field['key']) ? sanitize_key((string) $primary_field['key']) : 'field_1',
                                        'label' => isset($primary_field['label']) ? sanitize_text_field((string) $primary_field['label']) : __('Поле', 'unicontent-ai-generator'),
                                        'value' => $preview_value,
                                        'target_field' => $field_target,
                                        'images' => $image_sources,
                                    ),
                                ),
                                'text' => $preview_value,
                            ),
                            'preview_text' => $preview_value,
                            'credits_spent' => isset($response['credits_spent']) ? (float) $response['credits_spent'] : 0.0,
                            'credits_remaining' => isset($response['credits_remaining']) ? (float) $response['credits_remaining'] : 0.0,
                        )
                    );
                }

                $preview_text = isset($response['text']) ? (string) $response['text'] : '';
                if ($field_max_chars > 0) {
                    if (function_exists('mb_strlen') && function_exists('mb_substr')) {
                        if (mb_strlen($preview_text, 'UTF-8') > $field_max_chars) {
                            $preview_text = trim(mb_substr($preview_text, 0, $field_max_chars, 'UTF-8'));
                        }
                    } elseif (strlen($preview_text) > $field_max_chars) {
                        $preview_text = trim(substr($preview_text, 0, $field_max_chars));
                    }
                }

                wp_send_json_success(
                    array(
                        'post_id' => $post_id,
                        'scenario' => $scenario,
                        'preview' => array(
                            'field_key' => isset($primary_field['key']) ? sanitize_key((string) $primary_field['key']) : 'field_1',
                            'field_label' => isset($primary_field['label']) ? sanitize_text_field((string) $primary_field['label']) : __('Поле', 'unicontent-ai-generator'),
                            'text' => $preview_text,
                        ),
                        'credits_spent' => isset($response['credits_spent']) ? (float) $response['credits_spent'] : 0.0,
                        'credits_remaining' => isset($response['credits_remaining']) ? (float) $response['credits_remaining'] : 0.0,
                    )
                );
            }

            if ($scenario === 'seo_tags') {
                $seo_fields = $this->normalize_generation_fields($ai_fields, 'ai');
                $seo_title_field = null;
                $seo_description_field = null;
                foreach ($seo_fields as $field) {
                    if (!is_array($field)) {
                        continue;
                    }
                    if (array_key_exists('enabled', $field) && empty($field['enabled'])) {
                        continue;
                    }
                    $field_prompt_template = isset($field['prompt']) ? trim((string) $field['prompt']) : '';
                    if ($field_prompt_template === '') {
                        continue;
                    }
                    $field_key = sanitize_key(isset($field['key']) ? (string) $field['key'] : '');
                    $field_target = $this->normalize_generation_target_field(
                        isset($field['target_field']) ? (string) $field['target_field'] : ''
                    );
                    if (
                        $seo_title_field === null
                        && ($field_target === 'seo_field:title' || $field_key === 'seo_title' || $field_key === 'title' || $field_key === 'meta_title')
                    ) {
                        $seo_title_field = $field;
                    }
                    if (
                        $seo_description_field === null
                        && ($field_target === 'seo_field:description' || $field_key === 'seo_description' || $field_key === 'description' || $field_key === 'meta_description' || $field_key === 'desc')
                    ) {
                        $seo_description_field = $field;
                    }
                }

                if ($seo_title_field === null) {
                    $seo_title_field = array(
                        'prompt' => $template_body_seo_title,
                        'length_option_id' => $length_option_id,
                        'max_chars' => 70,
                    );
                }
                if ($seo_description_field === null) {
                    $seo_description_field = array(
                        'prompt' => $template_body_seo_description,
                        'length_option_id' => $length_option_id,
                        'max_chars' => 160,
                    );
                }

                $seo_title_prompt_template = isset($seo_title_field['prompt']) ? trim((string) $seo_title_field['prompt']) : '';
                $seo_description_prompt_template = isset($seo_description_field['prompt']) ? trim((string) $seo_description_field['prompt']) : '';
                if ($seo_title_prompt_template === '' || $seo_description_prompt_template === '') {
                    wp_send_json_error(array('message' => __('Заполните шаблоны для SEO title и SEO description.', 'unicontent-ai-generator')));
                }

                $seo_title_length_option_id = isset($seo_title_field['length_option_id']) ? (int) $seo_title_field['length_option_id'] : 0;
                if ($seo_title_length_option_id <= 0) {
                    $seo_title_length_option_id = $this->resolve_length_option_id($length_option_id);
                }
                $seo_description_length_option_id = isset($seo_description_field['length_option_id']) ? (int) $seo_description_field['length_option_id'] : 0;
                if ($seo_description_length_option_id <= 0) {
                    $seo_description_length_option_id = $this->resolve_length_option_id($length_option_id);
                }
                $seo_title_max_chars = isset($seo_title_field['max_chars']) ? max(0, (int) $seo_title_field['max_chars']) : 70;
                if ($seo_title_max_chars <= 0) {
                    $seo_title_max_chars = 70;
                }
                $seo_description_max_chars = isset($seo_description_field['max_chars']) ? max(0, (int) $seo_description_field['max_chars']) : 160;
                if ($seo_description_max_chars <= 0) {
                    $seo_description_max_chars = 160;
                }

                $seo_title_prompt = UCG_Tokens::render_prompt_for_post($seo_title_prompt_template, $post_id);
                $seo_description_prompt = UCG_Tokens::render_prompt_for_post($seo_description_prompt_template, $post_id);

                $seo_title_prompt = $generator->build_prompt_for_single_seo_field_for_preview($seo_title_prompt, 'title');
                $seo_description_prompt = $generator->build_prompt_for_single_seo_field_for_preview($seo_description_prompt, 'description');

                $r1 = $api_client->generate_text(
                    $seo_title_prompt,
                    $system_prompt,
                    $max_tokens,
                    $seo_title_length_option_id,
                    $vary_length,
                    $model,
                    $this->resolve_operation_type_for_scenario($scenario, 'seo_tags')
                );
                if (is_wp_error($r1)) {
                    wp_send_json_error(array('message' => $r1->get_error_message()));
                }
                $r2 = $api_client->generate_text(
                    $seo_description_prompt,
                    $system_prompt,
                    $max_tokens,
                    $seo_description_length_option_id,
                    $vary_length,
                    $model,
                    $this->resolve_operation_type_for_scenario($scenario, 'seo_tags')
                );
                if (is_wp_error($r2)) {
                    wp_send_json_error(array('message' => $r2->get_error_message()));
                }

                $title_text = isset($r1['text']) ? (string) $r1['text'] : '';
                $description_text = isset($r2['text']) ? (string) $r2['text'] : '';
                if (function_exists('mb_strlen') && function_exists('mb_substr')) {
                    if (mb_strlen($title_text, 'UTF-8') > $seo_title_max_chars) {
                        $title_text = trim(mb_substr($title_text, 0, $seo_title_max_chars, 'UTF-8'));
                    }
                    if (mb_strlen($description_text, 'UTF-8') > $seo_description_max_chars) {
                        $description_text = trim(mb_substr($description_text, 0, $seo_description_max_chars, 'UTF-8'));
                    }
                } else {
                    if (strlen($title_text) > $seo_title_max_chars) {
                        $title_text = trim(substr($title_text, 0, $seo_title_max_chars));
                    }
                    if (strlen($description_text) > $seo_description_max_chars) {
                        $description_text = trim(substr($description_text, 0, $seo_description_max_chars));
                    }
                }

                $credits_spent = (float) (isset($r1['credits_spent']) ? $r1['credits_spent'] : 0.0) + (float) (isset($r2['credits_spent']) ? $r2['credits_spent'] : 0.0);
                $credits_remaining = isset($r2['credits_remaining']) ? (float) $r2['credits_remaining'] : (float) (isset($r1['credits_remaining']) ? $r1['credits_remaining'] : 0.0);

                wp_send_json_success(
                    array(
                        'post_id' => $post_id,
                        'scenario' => $scenario,
                        'preview' => array(
                            'title' => $title_text,
                            'description' => $description_text,
                        ),
                        'credits_spent' => $credits_spent,
                        'credits_remaining' => $credits_remaining,
                    )
                );
            }

            $prompt = UCG_Tokens::render_prompt_for_post($template_body, $post_id);
            if (trim($prompt) === '') {
                wp_send_json_error(array('message' => __('Промпт пустой после подстановки переменных.', 'unicontent-ai-generator')));
            }

            if ($scenario === 'woo_reviews') {
                $rating_min = max(1, min(5, (int) $rating_min));
                $rating_max = max(1, min(5, (int) $rating_max));
                if ($rating_min > $rating_max) {
                    $tmp = $rating_min;
                    $rating_min = $rating_max;
                    $rating_max = $tmp;
                }
            }

            $prompt = $generator->build_prompt_for_comment_and_review_scenarios_for_preview($prompt, $scenario, $rating_min, $rating_max);

            $resp = $api_client->generate_text(
                $prompt,
                $system_prompt,
                $max_tokens,
                $length_option_id,
                $vary_length,
                $model,
                $this->resolve_operation_type_for_scenario($scenario, 'text')
            );
            if (is_wp_error($resp)) {
                wp_send_json_error(array('message' => $resp->get_error_message()));
            }

            wp_send_json_success(
                array(
                    'post_id' => $post_id,
                    'scenario' => $scenario,
                    'preview' => isset($resp['text']) ? (string) $resp['text'] : '',
                    'credits_spent' => isset($resp['credits_spent']) ? (float) $resp['credits_spent'] : 0.0,
                    'credits_remaining' => isset($resp['credits_remaining']) ? (float) $resp['credits_remaining'] : 0.0,
                )
            );
        }

        public function render_admin_notice() {
            $notice = sanitize_text_field($this->get_request_string($_GET, self::NOTICE_QUERY, ''));
            if ($notice === '') {
                return;
            }

            $type = sanitize_key($this->get_request_string($_GET, self::NOTICE_TYPE_QUERY, 'success'));
            $class = 'notice notice-success is-dismissible';
            if ($type === 'error') {
                $class = 'notice notice-error is-dismissible';
            } elseif ($type === 'warning') {
                $class = 'notice notice-warning is-dismissible';
            }

            echo '<div class="' . esc_attr($class) . '"><p>' . esc_html($notice) . '</p></div>';
        }

        public function status_label($status) {
            $status = sanitize_key((string) $status);
            $map = array(
                'queued' => __('В очереди', 'unicontent-ai-generator'),
                'running' => __('В работе', 'unicontent-ai-generator'),
                'completed' => __('Завершен', 'unicontent-ai-generator'),
                'failed' => __('Ошибка', 'unicontent-ai-generator'),
                'generated' => __('Сгенерировано', 'unicontent-ai-generator'),
                'approved' => __('Одобрено', 'unicontent-ai-generator'),
                'rejected' => __('Отклонено', 'unicontent-ai-generator'),
                'processing' => __('Обрабатывается', 'unicontent-ai-generator'),
                'publish' => __('Опубликовано', 'unicontent-ai-generator'),
                'draft' => __('Черновик', 'unicontent-ai-generator'),
                'private' => __('Приватный', 'unicontent-ai-generator'),
                'pending' => __('На утверждении', 'unicontent-ai-generator'),
            );

            if (isset($map[$status])) {
                return $map[$status];
            }

            return (string) $status;
        }

        protected function run_item_status_label($status) {
            $status = sanitize_key((string) $status);
            $map = array(
                'approved' => __('Применено', 'unicontent-ai-generator'),
                'generated' => __('Сгенерировано (ожидает проверки)', 'unicontent-ai-generator'),
                'queued' => __('В очереди', 'unicontent-ai-generator'),
                'running' => __('Обрабатывается', 'unicontent-ai-generator'),
                'failed' => __('Ошибка', 'unicontent-ai-generator'),
                'rejected' => __('Отклонено', 'unicontent-ai-generator'),
            );
            if (isset($map[$status])) {
                return $map[$status];
            }
            return $this->status_label($status);
        }

        protected function build_wizard_schema($post_type, $force_refresh_lengths = false, $scenario = self::DEFAULT_GENERATION_SCENARIO) {
            $post_type = sanitize_key((string) $post_type);
            if ($post_type === '' || !post_type_exists($post_type)) {
                $post_type = UCG_Tokens::get_default_post_type();
            }
            $scenario = $this->normalize_generation_scenario($scenario, $post_type);
            if ($this->scenario_requires_product_post_type($scenario) && post_type_exists('product')) {
                $post_type = 'product';
            } elseif ($scenario === 'post_fields' && $post_type === 'product' && post_type_exists('post')) {
                $post_type = 'post';
            }
            $scenario_options = $this->get_generation_scenario_options();

            $target_fields = $this->get_target_fields_for_scenario($post_type, $scenario);
            $templates = UCG_DB::get_templates($post_type, $scenario);
            $tokens = UCG_Tokens::get_prompt_tokens_for_post_type($post_type);
            $filter_fields = $this->get_filter_fields_for_post_type($post_type);
            $ai_field_presets = $this->get_ai_field_presets_for_post_type($post_type, $scenario);
            $static_field_presets = $this->get_static_field_presets_for_post_type($post_type, $scenario);
            $text_length_data = $this->get_text_length_options(!empty($force_refresh_lengths));
            $text_length_options = isset($text_length_data['options']) && is_array($text_length_data['options']) ? $text_length_data['options'] : array();
            $generation_models_data = $this->get_generation_models($scenario, !empty($force_refresh_lengths));
            $image_models_data = $this->get_generation_models('image_generation', !empty($force_refresh_lengths));

            $settings = UCG_Settings::get();

            return array(
                'scenario' => $scenario,
                'scenario_options' => $scenario_options,
                'post_type' => $post_type,
                'target_field_label' => $this->target_field_label_for_scenario($scenario),
                'target_fields' => $target_fields,
                'templates' => array_map(
                    function ($row) {
                        return array(
                            'id' => (int) $row['id'],
                            'name' => (string) $row['name'],
                            'post_type' => isset($row['post_type']) ? (string) $row['post_type'] : '',
                            'scenario' => isset($row['scenario']) ? sanitize_key((string) $row['scenario']) : 'field_update',
                            'is_default' => !empty($row['is_default']),
                        );
                    },
                    is_array($templates) ? $templates : array()
                ),
                'tokens' => $tokens,
                'ai_field_presets' => $ai_field_presets,
                'static_field_presets' => $static_field_presets,
                'text_length_options' => $text_length_options,
                'default_length_option_id' => isset($text_length_data['default_option_id']) ? (int) $text_length_data['default_option_id'] : 0,
                'vary_length_hint' => isset($text_length_data['hint']) ? (string) $text_length_data['hint'] : '',
                'generation_models' => isset($generation_models_data['models']) && is_array($generation_models_data['models'])
                    ? $generation_models_data['models']
                    : array(),
                'image_generation_models' => isset($image_models_data['models']) && is_array($image_models_data['models'])
                    ? $image_models_data['models']
                    : array(),
                'default_model' => isset($generation_models_data['default_model']) ? (string) $generation_models_data['default_model'] : 'auto',
                'generation_unit_label' => isset($generation_models_data['unit_label']) ? (string) $generation_models_data['unit_label'] : __('1 единица', 'unicontent-ai-generator'),
                'settings' => array(
                    'default_language' => isset($settings['default_language']) ? sanitize_key((string) $settings['default_language']) : 'auto',
                    'default_tone' => isset($settings['default_tone']) ? sanitize_key((string) $settings['default_tone']) : 'neutral',
                ),
                'filter_fields' => $filter_fields,
                'filter_operators' => array(
                    array('value' => 'is_empty', 'label' => __('пусто', 'unicontent-ai-generator')),
                    array('value' => 'not_empty', 'label' => __('не пусто', 'unicontent-ai-generator')),
                    array('value' => 'contains', 'label' => __('содержит', 'unicontent-ai-generator')),
                    array('value' => 'not_contains', 'label' => __('не содержит', 'unicontent-ai-generator')),
                    array('value' => 'equals', 'label' => __('равно', 'unicontent-ai-generator')),
                    array('value' => 'not_equals', 'label' => __('не равно', 'unicontent-ai-generator')),
                    array('value' => 'gt', 'label' => '>'),
                    array('value' => 'gte', 'label' => '>='),
                    array('value' => 'lt', 'label' => '<'),
                    array('value' => 'lte', 'label' => '<='),
                ),
            );
        }

        protected function normalize_generation_scenario($scenario, $post_type = '') {
            $scenario = sanitize_key((string) $scenario);
            $post_type = sanitize_key((string) $post_type);

            // Backward compatibility for legacy runs/templates created before scenario split.
            if ($scenario === 'multi_field') {
                $scenario = $post_type === 'product' ? 'product_fields' : 'post_fields';
            }

            if ($scenario === '') {
                return self::DEFAULT_GENERATION_SCENARIO;
            }

            $allowed = array();
            foreach ($this->get_generation_scenario_options() as $item) {
                if (!is_array($item) || empty($item['value']) || empty($item['is_available'])) {
                    continue;
                }
                $allowed[(string) $item['value']] = true;
            }

            if (!isset($allowed[$scenario])) {
                return self::DEFAULT_GENERATION_SCENARIO;
            }

            return $scenario;
        }

        protected function is_scenario_available($scenario) {
            $scenario = $this->normalize_generation_scenario($scenario);
            foreach ($this->get_generation_scenario_options() as $item) {
                if (!is_array($item) || empty($item['value'])) {
                    continue;
                }
                if ((string) $item['value'] !== $scenario) {
                    continue;
                }
                return !empty($item['is_available']);
            }
            return false;
        }

        protected function get_generation_scenario_options() {
            $seo_available = class_exists('UCG_Tokens') ? UCG_Tokens::has_supported_seo_plugin() : false;
            $woo_available = class_exists('UCG_Tokens') ? UCG_Tokens::has_woocommerce_support() : false;
            return array(
                array(
                    'value' => 'field_update',
                    'label' => __('Поля', 'unicontent-ai-generator'),
                    'icon' => 'dashicons-edit-page',
                    'description' => __('Генерация любого поля у любых записей и товаров.', 'unicontent-ai-generator'),
                    'is_available' => true,
                ),
                array(
                    'value' => 'seo_tags',
                    'label' => __('SEO-теги', 'unicontent-ai-generator'),
                    'icon' => 'dashicons-search',
                    'description' => __('Title и Description в мета-поля выбранного SEO-плагина.', 'unicontent-ai-generator'),
                    'is_available' => $seo_available,
                ),
                array(
                    'value' => 'post_fields',
                    'label' => __('Записи', 'unicontent-ai-generator'),
                    'icon' => 'dashicons-feedback',
                    'description' => __('Массовое создание любых постов', 'unicontent-ai-generator'),
                    'is_available' => true,
                ),
                array(
                    'value' => 'product_fields',
                    'label' => __('Товары', 'unicontent-ai-generator'),
                    'icon' => 'dashicons-cart',
                    'description' => __('Массовая генерация карточек товаров', 'unicontent-ai-generator'),
                    'is_available' => $woo_available,
                ),
                array(
                    'value' => 'image_generation',
                    'label' => __('Изображения', 'unicontent-ai-generator'),
                    'icon' => 'dashicons-format-image',
                    'description' => __('Генерация изображений для записей и товаров.', 'unicontent-ai-generator'),
                    'is_available' => true,
                ),
                array(
                    'value' => 'comments',
                    'label' => __('Комментарии', 'unicontent-ai-generator'),
                    'icon' => 'dashicons-admin-comments',
                    'description' => __('Генерация комментариев к выбранным записям.', 'unicontent-ai-generator'),
                    'is_available' => true,
                ),
                array(
                    'value' => 'woo_reviews',
                    'label' => __('Отзывы WooCommerce', 'unicontent-ai-generator'),
                    'icon' => 'dashicons-star-filled',
                    'description' => __('Генерация отзывов к товарам WooCommerce.', 'unicontent-ai-generator'),
                    'is_available' => $woo_available,
                ),
            );
        }

        protected function target_field_label_for_scenario($scenario) {
            $scenario = $this->normalize_generation_scenario($scenario);
            if ($scenario === 'seo_tags') {
                return __('SEO-плагин', 'unicontent-ai-generator');
            }
            if ($scenario === 'comments' || $scenario === 'woo_reviews') {
                return __('Режим публикации', 'unicontent-ai-generator');
            }
            return __('Целевое поле', 'unicontent-ai-generator');
        }

        protected function get_target_fields_for_scenario($post_type, $scenario) {
            $scenario = $this->normalize_generation_scenario($scenario);
            $post_type = sanitize_key((string) $post_type);

            if ($scenario === 'seo_tags') {
                $seo_profiles = class_exists('UCG_Tokens') ? UCG_Tokens::get_seo_profile_options(true, true) : array();
                $fields = array();
                foreach ($seo_profiles as $profile) {
                    if (!is_array($profile) || empty($profile['value'])) {
                        continue;
                    }
                    $profile_value = sanitize_key((string) $profile['value']);
                    if ($profile_value === '') {
                        continue;
                    }
                    $profile_label = isset($profile['label']) ? (string) $profile['label'] : $profile_value;
                    $profile_available = !array_key_exists('is_available', $profile) ? true : !empty($profile['is_available']);
                    if (!$profile_available && $profile_value !== 'auto') {
                        $profile_label .= ' (' . __('не активен', 'unicontent-ai-generator') . ')';
                    }
                    $fields[] = array(
                        'value' => 'seo:' . $profile_value,
                        'label' => $profile_label,
                        'disabled' => !$profile_available,
                    );
                }

                if (!empty($fields)) {
                    return $fields;
                }

                return array(
                    array(
                        'value' => 'seo:auto',
                        'label' => __('Авто (активный SEO плагин)', 'unicontent-ai-generator'),
                    ),
                );
            }

            if ($scenario === 'comments') {
                if (!post_type_supports($post_type, 'comments')) {
                    return array(
                        array(
                            'value' => 'comment:publish',
                            'label' => __('Комментарии не поддерживаются для этого типа записей.', 'unicontent-ai-generator'),
                            'disabled' => true,
                        ),
                    );
                }

                return array(
                    array(
                        'value' => 'comment:publish',
                        'label' => __('WordPress комментарий (1 на запись)', 'unicontent-ai-generator'),
                    ),
                );
            }

            if ($scenario === 'woo_reviews') {
                if ($post_type !== 'product') {
                    return array(
                        array(
                            'value' => 'woo_review:publish',
                            'label' => __('Для отзывов выберите тип записей: Товар (product).', 'unicontent-ai-generator'),
                            'disabled' => true,
                        ),
                    );
                }

                return array(
                    array(
                        'value' => 'woo_review:publish',
                        'label' => __('WooCommerce отзыв (1 на товар)', 'unicontent-ai-generator'),
                    ),
                );
            }

            if ($scenario === 'image_generation') {
                $is_product = $post_type === 'product';
                return array(
                    array(
                        'value' => $is_product ? 'media:product_images' : 'media:featured',
                        'label' => $is_product
                            ? __('Изображения товара (основное + галерея)', 'unicontent-ai-generator')
                            : __('Главное изображение записи', 'unicontent-ai-generator'),
                    ),
                );
            }

            return UCG_Tokens::get_target_fields_for_post_type($post_type);
        }

        protected function get_ai_field_presets_for_post_type($post_type, $scenario) {
            $post_type = sanitize_key((string) $post_type);
            $scenario = $this->normalize_generation_scenario($scenario, $post_type);
            if ($post_type === '' || !post_type_exists($post_type)) {
                $post_type = UCG_Tokens::get_default_post_type();
            }
            $seo_available = class_exists('UCG_Tokens') ? UCG_Tokens::has_supported_seo_plugin() : false;

            if ($scenario === 'seo_tags') {
                return $this->normalize_generation_fields(
                    array(
                        array(
                            'key' => 'seo_title',
                            'label' => __('SEO title', 'unicontent-ai-generator'),
                            'enabled' => true,
                            'target_field' => 'seo_field:title',
                            'length_option_id' => 0,
                            'max_chars' => 70,
                            'prompt' => $this->get_default_ai_field_prompt('seo_tags', 'seo_title', 'seo_field:title'),
                        ),
                        array(
                            'key' => 'seo_description',
                            'label' => __('SEO description', 'unicontent-ai-generator'),
                            'enabled' => true,
                            'target_field' => 'seo_field:description',
                            'length_option_id' => 0,
                            'max_chars' => 160,
                            'prompt' => $this->get_default_ai_field_prompt('seo_tags', 'seo_description', 'seo_field:description'),
                        ),
                    ),
                    'ai'
                );
            }

            if ($scenario !== 'post_fields' && $scenario !== 'product_fields' && $scenario !== 'image_generation') {
                return array();
            }

            $is_product_scenario = $scenario === 'product_fields' || ($scenario === 'image_generation' && $post_type === 'product');

            if ($scenario === 'image_generation') {
                $image_presets = array(
                    array(
                        'key' => 'featured_image',
                        'label' => $is_product_scenario
                            ? __('Основное изображение товара', 'unicontent-ai-generator')
                            : __('Главное изображение записи', 'unicontent-ai-generator'),
                        'enabled' => true,
                        'target_field' => $is_product_scenario ? 'media:product_images' : 'media:featured',
                        'length_option_id' => 0,
                        'max_chars' => 0,
                        'prompt' => $this->get_default_ai_field_prompt($scenario, 'featured_image', $is_product_scenario ? 'media:product_images' : 'media:featured'),
                        'output_type' => 'image',
                        'model' => 'auto',
                        'images_count' => $is_product_scenario ? 2 : 1,
                        'aspect_ratio' => '1:1',
                        'image_size' => '1K',
                    ),
                );
                return $this->normalize_generation_fields($image_presets, 'ai');
            }

            $presets = array(
                array(
                    'key' => 'post_title',
                    'label' => $is_product_scenario
                        ? __('Название товара', 'unicontent-ai-generator')
                        : __('Заголовок', 'unicontent-ai-generator'),
                    'enabled' => true,
                    'target_field' => 'post:post_title',
                    'length_option_id' => 0,
                    'max_chars' => 0,
                    'prompt' => $this->get_default_ai_field_prompt($scenario, 'post_title', 'post:post_title'),
                ),
                array(
                    'key' => 'post_content',
                    'label' => $is_product_scenario
                        ? __('Описание товара', 'unicontent-ai-generator')
                        : __('Содержание', 'unicontent-ai-generator'),
                    'enabled' => true,
                    'target_field' => 'post:post_content',
                    'length_option_id' => 0,
                    'max_chars' => 0,
                    'prompt' => $this->get_default_ai_field_prompt($scenario, 'post_content', 'post:post_content'),
                ),
                array(
                    'key' => 'post_excerpt',
                    'label' => $is_product_scenario
                        ? __('Краткое описание товара', 'unicontent-ai-generator')
                        : __('Краткое описание', 'unicontent-ai-generator'),
                    'enabled' => false,
                    'target_field' => 'post:post_excerpt',
                    'length_option_id' => 0,
                    'max_chars' => 0,
                    'prompt' => $this->get_default_ai_field_prompt($scenario, 'post_excerpt', 'post:post_excerpt'),
                ),
                array(
                    'key' => 'featured_image',
                    'label' => $is_product_scenario
                        ? __('Изображения товара', 'unicontent-ai-generator')
                        : __('Изображение записи', 'unicontent-ai-generator'),
                    'enabled' => false,
                    'target_field' => $is_product_scenario ? 'media:product_images' : 'media:featured',
                    'length_option_id' => 0,
                    'max_chars' => 0,
                    'prompt' => $this->get_default_ai_field_prompt($scenario, 'featured_image', $is_product_scenario ? 'media:product_images' : 'media:featured'),
                    'output_type' => 'image',
                    'model' => 'auto',
                    'images_count' => $is_product_scenario ? 2 : 1,
                    'aspect_ratio' => '1:1',
                    'image_size' => '1K',
                ),
            );

            if ($seo_available) {
                $presets[] = array(
                    'key' => 'seo_title',
                    'label' => __('SEO заголовок', 'unicontent-ai-generator'),
                    'enabled' => false,
                    'target_field' => 'seo_field:title',
                    'length_option_id' => 0,
                    'max_chars' => 70,
                    'prompt' => $this->get_default_ai_field_prompt($scenario, 'seo_title', 'seo_field:title'),
                );
                $presets[] = array(
                    'key' => 'seo_description',
                    'label' => __('SEO описание', 'unicontent-ai-generator'),
                    'enabled' => false,
                    'target_field' => 'seo_field:description',
                    'length_option_id' => 0,
                    'max_chars' => 160,
                    'prompt' => $this->get_default_ai_field_prompt($scenario, 'seo_description', 'seo_field:description'),
                );
            }

            return $this->normalize_generation_fields($presets, 'ai');
        }

        protected function get_default_ai_field_prompt($scenario, $field_key, $target_field = '') {
            $scenario = sanitize_key((string) $scenario);
            $field_key = sanitize_key((string) $field_key);
            $target_field = $this->normalize_generation_target_field((string) $target_field);

            if ($target_field === 'seo_field:title' || $field_key === 'seo_title') {
                return __('Сформулируй SEO title одной строкой, без markdown и без кавычек.', 'unicontent-ai-generator');
            }
            if ($target_field === 'seo_field:description' || $field_key === 'seo_description') {
                return __('Сформулируй SEO description одной строкой, без markdown и без кавычек.', 'unicontent-ai-generator');
            }

            if ($target_field === 'media:featured' || $target_field === 'media:product_images' || $target_field === 'media:gallery' || $field_key === 'featured_image') {
                if ($scenario === 'product_fields' || $scenario === 'image_generation') {
                    return __('Сгенерируй реалистичное продуктовое фото по теме {{item}}. Без текста и водяных знаков.', 'unicontent-ai-generator');
                }
                return __('Сгенерируй реалистичное изображение для материала на тему {{item}}. Без текста и водяных знаков.', 'unicontent-ai-generator');
            }

            if ($scenario === 'product_fields') {
                if ($field_key === 'post_title') {
                    return __('Придумай понятное и продающее название товара.', 'unicontent-ai-generator');
                }
                if ($field_key === 'post_content') {
                    return __('Напиши подробное описание товара: назначение, преимущества, характеристики и применение.', 'unicontent-ai-generator');
                }
                if ($field_key === 'post_excerpt') {
                    return __('Напиши краткое описание товара в 2-3 предложениях.', 'unicontent-ai-generator');
                }
            } else {
                if ($field_key === 'post_title') {
                    return __('Сформулируй выразительный заголовок материала.', 'unicontent-ai-generator');
                }
                if ($field_key === 'post_content') {
                    return __('Напиши подробный и структурированный текст материала с полезными деталями.', 'unicontent-ai-generator');
                }
                if ($field_key === 'post_excerpt') {
                    return __('Напиши краткое описание материала в 2-3 предложениях.', 'unicontent-ai-generator');
                }
            }

            return __('Сгенерируй текст для этого поля.', 'unicontent-ai-generator');
        }

        protected function get_static_field_presets_for_post_type($post_type, $scenario) {
            $post_type = sanitize_key((string) $post_type);
            $scenario = $this->normalize_generation_scenario($scenario, $post_type);
            if ($scenario !== 'post_fields' && $scenario !== 'product_fields' && $scenario !== 'image_generation') {
                return array();
            }
            if ($post_type === '' || !post_type_exists($post_type)) {
                $post_type = UCG_Tokens::get_default_post_type();
            }
            $is_product_context = $scenario === 'product_fields' || ($scenario === 'image_generation' && $post_type === 'product');
            if ($is_product_context && post_type_exists('product')) {
                $post_type = 'product';
            }

            $status_options = $this->get_static_post_status_options();
            $presets = array(
                array(
                    'key' => 'post_status',
                    'label' => __('Статус записи', 'unicontent-ai-generator'),
                    'enabled' => false,
                    'target_field' => 'post:post_status',
                    'value' => 'draft',
                    'input_type' => 'select',
                    'options' => $status_options,
                ),
            );

            if ($is_product_context) {
                if (taxonomy_exists('product_cat')) {
                    $presets[] = array(
                        'key' => 'product_category',
                        'label' => __('Категории товара', 'unicontent-ai-generator'),
                        'enabled' => false,
                        'target_field' => 'tax:product_cat',
                        'value' => array(),
                        'input_type' => 'taxonomy_checklist',
                        'options' => $this->get_static_taxonomy_term_tree('product_cat'),
                    );
                }
                if (taxonomy_exists('product_tag')) {
                    $presets[] = array(
                        'key' => 'product_tag',
                        'label' => __('Метки товара', 'unicontent-ai-generator'),
                        'enabled' => false,
                        'target_field' => 'tax:product_tag',
                        'value' => array(),
                        'input_type' => 'taxonomy_checklist',
                        'options' => $this->get_static_taxonomy_term_tree('product_tag'),
                    );
                }
                $presets[] = array(
                    'key' => 'stock_status',
                    'label' => __('Наличие', 'unicontent-ai-generator'),
                    'enabled' => false,
                    'target_field' => 'meta:_stock_status',
                    'value' => 'instock',
                    'input_type' => 'select',
                    'options' => array(
                        array('value' => 'instock', 'label' => __('В наличии', 'unicontent-ai-generator')),
                        array('value' => 'outofstock', 'label' => __('Нет в наличии', 'unicontent-ai-generator')),
                        array('value' => 'onbackorder', 'label' => __('Под заказ', 'unicontent-ai-generator')),
                    ),
                );
                $presets[] = array(
                    'key' => 'stock_quantity',
                    'label' => __('Остаток', 'unicontent-ai-generator'),
                    'enabled' => false,
                    'target_field' => 'meta:_stock',
                    'value' => '',
                    'input_type' => 'number',
                );
                $presets[] = array(
                    'key' => 'catalog_visibility',
                    'label' => __('Видимость в каталоге', 'unicontent-ai-generator'),
                    'enabled' => false,
                    'target_field' => 'meta:_visibility',
                    'value' => 'visible',
                    'input_type' => 'select',
                    'options' => array(
                        array('value' => 'visible', 'label' => __('Везде', 'unicontent-ai-generator')),
                        array('value' => 'catalog', 'label' => __('Только каталог', 'unicontent-ai-generator')),
                        array('value' => 'search', 'label' => __('Только поиск', 'unicontent-ai-generator')),
                        array('value' => 'hidden', 'label' => __('Скрыт', 'unicontent-ai-generator')),
                    ),
                );
            } else {
                if (is_object_in_taxonomy($post_type, 'category')) {
                    $presets[] = array(
                        'key' => 'post_category',
                        'label' => __('Рубрики', 'unicontent-ai-generator'),
                        'enabled' => false,
                        'target_field' => 'tax:category',
                        'value' => array(),
                        'input_type' => 'taxonomy_checklist',
                        'options' => $this->get_static_taxonomy_term_tree('category'),
                    );
                }
                if (is_object_in_taxonomy($post_type, 'post_tag')) {
                    $presets[] = array(
                        'key' => 'post_tag',
                        'label' => __('Метки', 'unicontent-ai-generator'),
                        'enabled' => false,
                        'target_field' => 'tax:post_tag',
                        'value' => array(),
                        'input_type' => 'taxonomy_checklist',
                        'options' => $this->get_static_taxonomy_term_tree('post_tag'),
                    );
                }

                $author_options = $this->get_static_post_author_options($post_type);
                if (!empty($author_options)) {
                    $default_author_id = get_current_user_id();
                    $has_default_author = false;
                    foreach ($author_options as $author_option) {
                        if (!is_array($author_option) || !isset($author_option['value'])) {
                            continue;
                        }
                        if ((int) $author_option['value'] === (int) $default_author_id) {
                            $has_default_author = true;
                            break;
                        }
                    }
                    if (!$has_default_author) {
                        $default_author_id = isset($author_options[0]['value']) ? (int) $author_options[0]['value'] : 0;
                    }

                    $presets[] = array(
                        'key' => 'post_author',
                        'label' => __('Автор', 'unicontent-ai-generator'),
                        'enabled' => false,
                        'target_field' => 'post:post_author',
                        'value' => $default_author_id > 0 ? (string) $default_author_id : '',
                        'input_type' => 'select',
                        'options' => $author_options,
                    );
                }

                $presets[] = array(
                    'key' => 'post_date',
                    'label' => __('Дата публикации', 'unicontent-ai-generator'),
                    'enabled' => false,
                    'target_field' => 'post:post_date',
                    'value' => '',
                    'input_type' => 'text',
                    'placeholder' => __('now или YYYY-MM-DD HH:MM', 'unicontent-ai-generator'),
                    'hint' => __('Оставьте пустым или выключите поле, чтобы не менять дату публикации.', 'unicontent-ai-generator'),
                );
            }

            $normalized_presets = $this->normalize_generation_fields($presets, 'static', $scenario);
            foreach ($normalized_presets as $index => $field) {
                if (!is_array($field)) {
                    continue;
                }
                $raw = isset($presets[$index]) && is_array($presets[$index]) ? $presets[$index] : array();
                $normalized_presets[$index]['input_type'] = isset($raw['input_type']) ? sanitize_key((string) $raw['input_type']) : 'text';
                $normalized_presets[$index]['options'] = isset($raw['options']) && is_array($raw['options']) ? $raw['options'] : array();
                $normalized_presets[$index]['placeholder'] = isset($raw['placeholder']) ? sanitize_text_field((string) $raw['placeholder']) : '';
                $normalized_presets[$index]['hint'] = isset($raw['hint']) ? sanitize_text_field((string) $raw['hint']) : '';
            }

            return $normalized_presets;
        }

        protected function get_static_post_status_options() {
            $statuses = get_post_stati(array('internal' => false), 'objects');
            if (!is_array($statuses)) {
                return array(
                    array('value' => 'draft', 'label' => __('Черновик', 'unicontent-ai-generator')),
                    array('value' => 'publish', 'label' => __('Опубликовано', 'unicontent-ai-generator')),
                    array('value' => 'pending', 'label' => __('На проверке', 'unicontent-ai-generator')),
                    array('value' => 'private', 'label' => __('Приватно', 'unicontent-ai-generator')),
                );
            }

            $order = array('draft' => 1, 'publish' => 2, 'pending' => 3, 'private' => 4);
            $items = array();
            foreach ($statuses as $status_key => $status_obj) {
                $status_key = sanitize_key((string) $status_key);
                if ($status_key === '') {
                    continue;
                }
                $label = is_object($status_obj) && !empty($status_obj->label)
                    ? (string) $status_obj->label
                    : $status_key;
                $items[] = array(
                    'value' => $status_key,
                    'label' => $label,
                    'order' => isset($order[$status_key]) ? (int) $order[$status_key] : 99,
                );
            }

            usort(
                $items,
                function ($left, $right) {
                    $left_order = isset($left['order']) ? (int) $left['order'] : 99;
                    $right_order = isset($right['order']) ? (int) $right['order'] : 99;
                    if ($left_order !== $right_order) {
                        return $left_order < $right_order ? -1 : 1;
                    }
                    return strcmp(
                        isset($left['label']) ? (string) $left['label'] : '',
                        isset($right['label']) ? (string) $right['label'] : ''
                    );
                }
            );

            $result = array();
            foreach ($items as $item) {
                if (empty($item['value'])) {
                    continue;
                }
                $result[] = array(
                    'value' => (string) $item['value'],
                    'label' => isset($item['label']) ? (string) $item['label'] : (string) $item['value'],
                );
            }

            return $result;
        }

        protected function get_static_post_author_options($post_type) {
            $post_type = sanitize_key((string) $post_type);
            if ($post_type === '' || !post_type_exists($post_type)) {
                return array();
            }

            $capability = 'edit_posts';
            $post_type_obj = get_post_type_object($post_type);
            if (is_object($post_type_obj) && isset($post_type_obj->cap) && is_object($post_type_obj->cap) && !empty($post_type_obj->cap->edit_posts)) {
                $capability = (string) $post_type_obj->cap->edit_posts;
            }

            $query = array(
                'fields' => array('ID', 'display_name', 'user_login'),
                'orderby' => 'display_name',
                'order' => 'ASC',
                'number' => 250,
                'who' => 'authors',
            );

            $users = get_users($query);
            if (!is_array($users) || empty($users)) {
                unset($query['who']);
                $users = get_users($query);
            }
            if (!is_array($users) || empty($users)) {
                return array();
            }

            $options = array();
            foreach ($users as $user) {
                if (!$user instanceof WP_User) {
                    continue;
                }
                $user_id = isset($user->ID) ? (int) $user->ID : 0;
                if ($user_id <= 0) {
                    continue;
                }
                if ($capability !== '' && !user_can($user_id, $capability)) {
                    continue;
                }
                $label = isset($user->display_name) ? (string) $user->display_name : '';
                if ($label === '') {
                    $label = isset($user->user_login) ? (string) $user->user_login : ('#' . $user_id);
                }
                $options[] = array(
                    'value' => (string) $user_id,
                    'label' => $label,
                );
            }

            return $options;
        }

        protected function get_static_taxonomy_term_options($taxonomy, $limit = 200) {
            $taxonomy = sanitize_key((string) $taxonomy);
            if ($taxonomy === '' || !taxonomy_exists($taxonomy)) {
                return array();
            }

            $limit = max(20, min(500, (int) $limit));
            $terms = get_terms(
                array(
                    'taxonomy' => $taxonomy,
                    'hide_empty' => false,
                    'orderby' => 'name',
                    'order' => 'ASC',
                    'number' => $limit,
                )
            );
            if (is_wp_error($terms) || !is_array($terms)) {
                return array();
            }

            $result = array();
            foreach ($terms as $term) {
                if (!$term instanceof WP_Term) {
                    continue;
                }
                $result[] = array(
                    'value' => (string) $term->term_id,
                    'label' => (string) $term->name,
                );
            }

            return $result;
        }

        protected function get_static_taxonomy_term_tree($taxonomy, $limit = 500) {
            $taxonomy = sanitize_key((string) $taxonomy);
            if ($taxonomy === '' || !taxonomy_exists($taxonomy)) {
                return array();
            }

            $limit = max(20, min(1000, (int) $limit));
            $terms = get_terms(
                array(
                    'taxonomy' => $taxonomy,
                    'hide_empty' => false,
                    'orderby' => 'name',
                    'order' => 'ASC',
                    'number' => $limit,
                )
            );
            if (is_wp_error($terms) || !is_array($terms)) {
                return array();
            }

            $nodes = array();
            $children_map = array();
            $root_ids = array();

            foreach ($terms as $term) {
                if (!$term instanceof WP_Term) {
                    continue;
                }
                $term_id = isset($term->term_id) ? (int) $term->term_id : 0;
                if ($term_id <= 0) {
                    continue;
                }
                $nodes[$term_id] = array(
                    'value' => (string) $term_id,
                    'label' => sanitize_text_field((string) $term->name),
                    'parent' => isset($term->parent) ? (int) $term->parent : 0,
                );
            }

            foreach ($nodes as $term_id => $node) {
                $parent_id = isset($node['parent']) ? (int) $node['parent'] : 0;
                if ($parent_id > 0 && isset($nodes[$parent_id])) {
                    if (!isset($children_map[$parent_id]) || !is_array($children_map[$parent_id])) {
                        $children_map[$parent_id] = array();
                    }
                    $children_map[$parent_id][] = (int) $term_id;
                } else {
                    $root_ids[] = (int) $term_id;
                }
            }

            $build_node = function ($term_id) use (&$build_node, $nodes, $children_map) {
                $term_id = (int) $term_id;
                if ($term_id <= 0 || !isset($nodes[$term_id]) || !is_array($nodes[$term_id])) {
                    return null;
                }

                $node = array(
                    'value' => isset($nodes[$term_id]['value']) ? (string) $nodes[$term_id]['value'] : (string) $term_id,
                    'label' => isset($nodes[$term_id]['label']) ? (string) $nodes[$term_id]['label'] : (string) $term_id,
                    'children' => array(),
                );

                $children_ids = isset($children_map[$term_id]) && is_array($children_map[$term_id])
                    ? $children_map[$term_id]
                    : array();
                foreach ($children_ids as $child_id) {
                    $child_node = $build_node((int) $child_id);
                    if (is_array($child_node)) {
                        $node['children'][] = $child_node;
                    }
                }

                return $node;
            };

            $result = array();
            foreach ($root_ids as $root_id) {
                $root_node = $build_node((int) $root_id);
                if (is_array($root_node)) {
                    $result[] = $root_node;
                }
            }

            return $result;
        }

        protected function normalize_template_field_target($target_field) {
            $target_field = (string) $target_field;
            $target_field = preg_replace('/[^a-zA-Z0-9:_-]/', '', $target_field);
            return (string) $target_field;
        }

        protected function remap_legacy_product_static_field($scenario, $field_key, $target_field) {
            $scenario = $this->normalize_generation_scenario((string) $scenario);
            $field_key = sanitize_key((string) $field_key);
            $target_field = $this->normalize_generation_target_field((string) $target_field);
            if ($scenario !== 'product_fields') {
                return array(
                    'key' => $field_key,
                    'target_field' => $target_field,
                );
            }

            if ($field_key === 'post_category') {
                $field_key = 'product_category';
            } elseif ($field_key === 'post_tag') {
                $field_key = 'product_tag';
            }

            if ($target_field === 'tax:category') {
                $target_field = 'tax:product_cat';
            } elseif ($target_field === 'tax:post_tag') {
                $target_field = 'tax:product_tag';
            }

            return array(
                'key' => $field_key,
                'target_field' => $target_field,
            );
        }

        protected function normalize_template_fields($raw_fields, $scenario) {
            $scenario = $this->normalize_generation_scenario($scenario);
            $raw_fields = is_array($raw_fields) ? $raw_fields : array();
            $normalized = array();
            $used_keys = array();

            foreach ($raw_fields as $raw_field) {
                if (!is_array($raw_field)) {
                    continue;
                }

                $key = sanitize_key(isset($raw_field['key']) ? (string) $raw_field['key'] : '');
                if ($key === '') {
                    $key = 'field_' . (count($normalized) + 1);
                }

                $target_field = $this->normalize_template_field_target(
                    isset($raw_field['target_field']) ? (string) $raw_field['target_field'] : ''
                );
                $legacy_product_field = $this->remap_legacy_product_static_field($scenario, $key, $target_field);
                $key = isset($legacy_product_field['key']) ? sanitize_key((string) $legacy_product_field['key']) : $key;
                if ($key === '') {
                    $key = 'field_' . (count($normalized) + 1);
                }
                $target_field = isset($legacy_product_field['target_field'])
                    ? $this->normalize_template_field_target((string) $legacy_product_field['target_field'])
                    : $target_field;

                $base_key = $key;
                $suffix = 2;
                while (isset($used_keys[$key])) {
                    $key = $base_key . '_' . $suffix;
                    $suffix++;
                }
                $used_keys[$key] = true;

                $label = sanitize_text_field(isset($raw_field['label']) ? (string) $raw_field['label'] : '');
                if ($label === '') {
                    $label = ucfirst(str_replace('_', ' ', $key));
                }

                $type = sanitize_key(isset($raw_field['type']) ? (string) $raw_field['type'] : 'ai');
                if ($type === '') {
                    $type = 'ai';
                }

                $prompt = sanitize_textarea_field(isset($raw_field['prompt']) ? (string) $raw_field['prompt'] : '');
                $enabled = !array_key_exists('enabled', $raw_field) || !empty($raw_field['enabled']);
                $length_option_id = isset($raw_field['length_option_id']) ? max(0, (int) $raw_field['length_option_id']) : 0;
                $max_chars = isset($raw_field['max_chars']) ? max(0, (int) $raw_field['max_chars']) : 0;
                $output_type = sanitize_key(isset($raw_field['output_type']) ? (string) $raw_field['output_type'] : '');
                if ($output_type === '') {
                    $output_type = strpos($target_field, 'media:') === 0 ? 'image' : 'text';
                }
                if ($output_type !== 'image') {
                    $output_type = 'text';
                }
                $field_model = $this->normalize_model_identifier(isset($raw_field['model']) ? (string) $raw_field['model'] : 'auto');
                if ($field_model === '') {
                    $field_model = 'auto';
                }
                $images_count = isset($raw_field['images_count']) ? max(1, min(8, (int) $raw_field['images_count'])) : 1;
                $aspect_ratio = isset($raw_field['aspect_ratio']) ? sanitize_text_field((string) $raw_field['aspect_ratio']) : '';
                if (!in_array($aspect_ratio, array('1:1', '2:3', '3:2', '3:4', '4:3', '4:5', '5:4', '9:16', '16:9', '21:9', '1:4', '4:1', '1:8', '8:1'), true)) {
                    $aspect_ratio = '';
                }
                $image_size = isset($raw_field['image_size']) ? strtoupper(sanitize_text_field((string) $raw_field['image_size'])) : '';
                if (!in_array($image_size, array('0.5K', '1K', '2K', '4K'), true)) {
                    $image_size = '';
                }

                if ($target_field === '' && $scenario === 'seo_tags') {
                    if ($key === 'seo_title' || $key === 'title' || $key === 'meta_title') {
                        $target_field = 'seo_field:title';
                    } elseif ($key === 'seo_description' || $key === 'description' || $key === 'meta_description' || $key === 'desc') {
                        $target_field = 'seo_field:description';
                    }
                }

                $normalized[] = array(
                    'key' => $key,
                    'label' => $label,
                    'type' => $type,
                    'enabled' => $enabled,
                    'length_option_id' => $length_option_id,
                    'max_chars' => $max_chars,
                    'target_field' => $target_field,
                    'output_type' => $output_type,
                    'model' => $field_model,
                    'images_count' => $images_count,
                    'aspect_ratio' => $aspect_ratio,
                    'image_size' => $image_size,
                    'prompt' => $prompt,
                );
            }

            return $normalized;
        }

        protected function build_template_fields_from_payload_inputs($scenario, $body, $seo_title_prompt, $seo_description_prompt, $prompt_blocks, $base_prompt) {
            $scenario = $this->normalize_generation_scenario($scenario);
            $body = sanitize_textarea_field((string) $body);
            $seo_title_prompt = sanitize_textarea_field((string) $seo_title_prompt);
            $seo_description_prompt = sanitize_textarea_field((string) $seo_description_prompt);
            $base_prompt = sanitize_textarea_field((string) $base_prompt);
            $normalized_blocks = $this->normalize_prompt_blocks($prompt_blocks, $scenario);

            $fields = array();
            if (!empty($normalized_blocks)) {
                foreach ($normalized_blocks as $index => $block) {
                    if (!is_array($block)) {
                        continue;
                    }
                    $block_key = sanitize_key(isset($block['id']) ? (string) $block['id'] : '');
                    if ($block_key === '') {
                        $block_key = 'field_' . ((int) $index + 1);
                    }
                    $block_label = sanitize_text_field(isset($block['label']) ? (string) $block['label'] : '');
                    if ($block_label === '') {
                        $block_label = ucfirst(str_replace('_', ' ', $block_key));
                    }
                    $raw_prompt = isset($block['prompt']) ? (string) $block['prompt'] : '';
                    $field_prompt = $this->compose_prompt_with_base($base_prompt, $raw_prompt);
                    $target_field = '';
                    $max_chars = 0;
                    if ($scenario === 'seo_tags') {
                        if ($block_key === 'seo_title' || $block_key === 'title' || $block_key === 'meta_title') {
                            $target_field = 'seo_field:title';
                            $max_chars = 70;
                        } elseif ($block_key === 'seo_description' || $block_key === 'description' || $block_key === 'meta_description' || $block_key === 'desc') {
                            $target_field = 'seo_field:description';
                            $max_chars = 160;
                        }
                    }
                    $fields[] = array(
                        'key' => $block_key,
                        'label' => $block_label,
                        'type' => 'ai',
                        'enabled' => true,
                        'length_option_id' => 0,
                        'max_chars' => $max_chars,
                        'target_field' => $target_field,
                        'prompt' => $field_prompt,
                    );
                }
            }

            if ($scenario === 'seo_tags') {
                $title_prompt = trim($seo_title_prompt);
                $description_prompt = trim($seo_description_prompt);

                if ($title_prompt === '') {
                    $title_prompt = trim($this->find_prompt_block_prompt_by_ids($normalized_blocks, array('seo_title', 'title', 'meta_title')));
                    if ($title_prompt !== '') {
                        $title_prompt = $this->compose_prompt_with_base($base_prompt, $title_prompt);
                    }
                }
                if ($description_prompt === '') {
                    $description_prompt = trim($this->find_prompt_block_prompt_by_ids($normalized_blocks, array('seo_description', 'description', 'meta_description', 'desc')));
                    if ($description_prompt !== '') {
                        $description_prompt = $this->compose_prompt_with_base($base_prompt, $description_prompt);
                    }
                }
                if ($title_prompt === '' && $body !== '') {
                    $title_prompt = $body;
                }
                if ($description_prompt === '' && $body !== '') {
                    $description_prompt = $body;
                }

                $fields = array(
                    array(
                        'key' => 'seo_title',
                        'label' => __('SEO title', 'unicontent-ai-generator'),
                        'type' => 'ai',
                        'enabled' => true,
                        'length_option_id' => 0,
                        'max_chars' => 70,
                        'target_field' => 'seo_field:title',
                        'prompt' => $title_prompt,
                    ),
                    array(
                        'key' => 'seo_description',
                        'label' => __('SEO description', 'unicontent-ai-generator'),
                        'type' => 'ai',
                        'enabled' => true,
                        'length_option_id' => 0,
                        'max_chars' => 160,
                        'target_field' => 'seo_field:description',
                        'prompt' => $description_prompt,
                    ),
                );
            } elseif (empty($fields) && $body !== '') {
                $fields[] = array(
                    'key' => 'main',
                    'label' => __('Основной промпт', 'unicontent-ai-generator'),
                    'type' => 'ai',
                    'enabled' => true,
                    'length_option_id' => 0,
                    'max_chars' => 0,
                    'target_field' => '',
                    'prompt' => $body,
                );
            }

            return $this->normalize_template_fields($fields, $scenario);
        }

        protected function derive_template_prompts_from_fields($scenario, $fields) {
            $scenario = $this->normalize_generation_scenario($scenario);
            $normalized_fields = $this->normalize_template_fields($fields, $scenario);

            $body = '';
            foreach ($normalized_fields as $field) {
                if (!is_array($field)) {
                    continue;
                }
                $prompt = isset($field['prompt']) ? trim((string) $field['prompt']) : '';
                if ($prompt !== '') {
                    $body = $prompt;
                    break;
                }
            }

            $seo_title_prompt = '';
            $seo_description_prompt = '';
            if ($scenario === 'seo_tags') {
                foreach ($normalized_fields as $field) {
                    if (!is_array($field)) {
                        continue;
                    }
                    $key = sanitize_key(isset($field['key']) ? (string) $field['key'] : '');
                    $target = $this->normalize_template_field_target(isset($field['target_field']) ? (string) $field['target_field'] : '');
                    $prompt = isset($field['prompt']) ? trim((string) $field['prompt']) : '';
                    if ($prompt === '') {
                        continue;
                    }
                    if ($seo_title_prompt === '' && ($key === 'seo_title' || $key === 'title' || $key === 'meta_title' || $target === 'seo_field:title')) {
                        $seo_title_prompt = $prompt;
                    }
                    if ($seo_description_prompt === '' && ($key === 'seo_description' || $key === 'description' || $key === 'meta_description' || $key === 'desc' || $target === 'seo_field:description')) {
                        $seo_description_prompt = $prompt;
                    }
                }

                if ($seo_title_prompt === '' && $body !== '') {
                    $seo_title_prompt = $body;
                }
                if ($seo_description_prompt === '' && $body !== '') {
                    $seo_description_prompt = $body;
                }
            }

            return array(
                'body' => $body,
                'seo_title_prompt' => $seo_title_prompt,
                'seo_description_prompt' => $seo_description_prompt,
                'fields' => $normalized_fields,
            );
        }

        protected function encode_template_payload($scenario, $body, $seo_title_prompt = '', $seo_description_prompt = '', $prompt_blocks = array(), $base_prompt = '', $fields_override = null) {
            $scenario = $this->normalize_generation_scenario($scenario);
            $body = sanitize_textarea_field((string) $body);
            $seo_title_prompt = sanitize_textarea_field((string) $seo_title_prompt);
            $seo_description_prompt = sanitize_textarea_field((string) $seo_description_prompt);
            $base_prompt = sanitize_textarea_field((string) $base_prompt);
            $normalized_blocks = $this->normalize_prompt_blocks($prompt_blocks, $scenario);
            $fields = array();
            if (is_array($fields_override)) {
                $fields = $this->normalize_template_fields($fields_override, $scenario);
            }
            if (empty($fields)) {
                $fields = $this->build_template_fields_from_payload_inputs(
                    $scenario,
                    $body,
                    $seo_title_prompt,
                    $seo_description_prompt,
                    $normalized_blocks,
                    $base_prompt
                );
            }
            $derived_from_fields = $this->derive_template_prompts_from_fields($scenario, $fields);
            if (trim($body) === '' && !empty($derived_from_fields['body'])) {
                $body = (string) $derived_from_fields['body'];
            }
            if (trim($seo_title_prompt) === '' && !empty($derived_from_fields['seo_title_prompt'])) {
                $seo_title_prompt = (string) $derived_from_fields['seo_title_prompt'];
            }
            if (trim($seo_description_prompt) === '' && !empty($derived_from_fields['seo_description_prompt'])) {
                $seo_description_prompt = (string) $derived_from_fields['seo_description_prompt'];
            }
            $payload = array(
                'version' => 3,
                'scenario' => $scenario,
                'fields' => $fields,
            );

            // Keep legacy keys in payload for compatibility with older clients.
            if ($body !== '') {
                $payload['body'] = $body;
            }
            if ($base_prompt !== '') {
                $payload['base_prompt'] = $base_prompt;
            }
            if (!empty($normalized_blocks)) {
                $payload['prompt_blocks'] = $normalized_blocks;
            }
            if ($seo_title_prompt !== '') {
                $payload['seo_title_prompt'] = $seo_title_prompt;
            }
            if ($seo_description_prompt !== '') {
                $payload['seo_description_prompt'] = $seo_description_prompt;
            }

            return wp_json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }

        protected function decode_template_payload($scenario, $raw_body) {
            $scenario = $this->normalize_generation_scenario($scenario);
            $raw_body = (string) $raw_body;
            $result = array(
                'body' => $raw_body,
                'seo_title_prompt' => '',
                'seo_description_prompt' => '',
                'base_prompt' => '',
                'prompt_blocks' => array(),
                'fields' => array(),
                'version' => 1,
                'needs_upgrade' => false,
                'upgraded_body' => '',
            );

            $decoded = json_decode($raw_body, true);
            if (is_array($decoded)) {
                $version = isset($decoded['version']) ? (int) $decoded['version'] : 1;
                if ($version > 0) {
                    $result['version'] = $version;
                }

                $result['body'] = isset($decoded['body']) ? (string) $decoded['body'] : '';
                $result['seo_title_prompt'] = isset($decoded['seo_title_prompt']) ? (string) $decoded['seo_title_prompt'] : '';
                $result['seo_description_prompt'] = isset($decoded['seo_description_prompt']) ? (string) $decoded['seo_description_prompt'] : '';
                $result['base_prompt'] = isset($decoded['base_prompt']) ? (string) $decoded['base_prompt'] : '';
                $result['prompt_blocks'] = isset($decoded['prompt_blocks']) && is_array($decoded['prompt_blocks']) ? $decoded['prompt_blocks'] : array();
                $result['fields'] = isset($decoded['fields']) && is_array($decoded['fields']) ? $decoded['fields'] : array();
            }

            $result['fields'] = $this->normalize_template_fields(isset($result['fields']) ? $result['fields'] : array(), $scenario);
            if (empty($result['fields'])) {
                $result['fields'] = $this->build_template_fields_from_payload_inputs(
                    $scenario,
                    isset($result['body']) ? (string) $result['body'] : '',
                    isset($result['seo_title_prompt']) ? (string) $result['seo_title_prompt'] : '',
                    isset($result['seo_description_prompt']) ? (string) $result['seo_description_prompt'] : '',
                    isset($result['prompt_blocks']) && is_array($result['prompt_blocks']) ? $result['prompt_blocks'] : array(),
                    isset($result['base_prompt']) ? (string) $result['base_prompt'] : ''
                );
                if (!empty($result['fields'])) {
                    $result['needs_upgrade'] = true;
                }
            }

            $result['prompt_blocks'] = $this->build_editor_prompt_blocks($scenario, $result);
            $derived = $this->derive_template_prompts_from_blocks($scenario, isset($result['base_prompt']) ? (string) $result['base_prompt'] : '', $result['prompt_blocks']);
            $derived_fields = $this->derive_template_prompts_from_fields($scenario, isset($result['fields']) ? $result['fields'] : array());

            if (trim((string) $result['body']) === '' && !empty($derived['body'])) {
                $result['body'] = (string) $derived['body'];
            }
            if (trim((string) $result['body']) === '' && !empty($derived_fields['body'])) {
                $result['body'] = (string) $derived_fields['body'];
            }
            if (trim((string) $result['seo_title_prompt']) === '' && !empty($derived['seo_title_prompt'])) {
                $result['seo_title_prompt'] = (string) $derived['seo_title_prompt'];
            }
            if (trim((string) $result['seo_title_prompt']) === '' && !empty($derived_fields['seo_title_prompt'])) {
                $result['seo_title_prompt'] = (string) $derived_fields['seo_title_prompt'];
            }
            if (trim((string) $result['seo_description_prompt']) === '' && !empty($derived['seo_description_prompt'])) {
                $result['seo_description_prompt'] = (string) $derived['seo_description_prompt'];
            }
            if (trim((string) $result['seo_description_prompt']) === '' && !empty($derived_fields['seo_description_prompt'])) {
                $result['seo_description_prompt'] = (string) $derived_fields['seo_description_prompt'];
            }

            $result['fields'] = $this->normalize_template_fields(isset($result['fields']) ? $result['fields'] : array(), $scenario);
            if ($result['needs_upgrade']) {
                $result['upgraded_body'] = $this->encode_template_payload(
                    $scenario,
                    isset($result['body']) ? (string) $result['body'] : '',
                    isset($result['seo_title_prompt']) ? (string) $result['seo_title_prompt'] : '',
                    isset($result['seo_description_prompt']) ? (string) $result['seo_description_prompt'] : '',
                    isset($result['prompt_blocks']) ? $result['prompt_blocks'] : array(),
                    isset($result['base_prompt']) ? (string) $result['base_prompt'] : ''
                );
            }

            return $result;
        }

        protected function maybe_upgrade_template_payload_to_v3($template, $scenario, $payload) {
            if (!is_array($template) || !is_array($payload)) {
                return;
            }
            $template_id = isset($template['id']) ? (int) $template['id'] : 0;
            if ($template_id <= 0) {
                return;
            }
            if (empty($payload['needs_upgrade'])) {
                return;
            }

            $upgraded_body = isset($payload['upgraded_body']) ? (string) $payload['upgraded_body'] : '';
            if (trim($upgraded_body) === '') {
                return;
            }

            $scenario = $this->normalize_generation_scenario($scenario);
            $name = isset($template['name']) ? (string) $template['name'] : '';
            $post_type = isset($template['post_type']) ? (string) $template['post_type'] : '';
            if ($name === '' || $post_type === '') {
                return;
            }

            UCG_DB::update_template(
                $template_id,
                $name,
                $post_type,
                $upgraded_body,
                !empty($template['is_default']) ? 1 : 0,
                isset($template['length_option_id']) ? (int) $template['length_option_id'] : 0,
                !empty($template['vary_length']) ? 1 : 0,
                $scenario
            );
        }

        protected function default_prompt_blocks_for_scenario($scenario) {
            $scenario = $this->normalize_generation_scenario($scenario);
            if ($scenario === 'seo_tags') {
                return array(
                    array(
                        'id' => 'seo_title',
                        'label' => __('SEO title', 'unicontent-ai-generator'),
                        'prompt' => '',
                    ),
                    array(
                        'id' => 'seo_description',
                        'label' => __('SEO description', 'unicontent-ai-generator'),
                        'prompt' => '',
                    ),
                );
            }

            return array(
                array(
                    'id' => 'main',
                    'label' => __('Основной промпт', 'unicontent-ai-generator'),
                    'prompt' => '',
                ),
            );
        }

        protected function build_editor_prompt_blocks($scenario, $payload) {
            $scenario = $this->normalize_generation_scenario($scenario);
            $payload = is_array($payload) ? $payload : array();
            $raw_fields = isset($payload['fields']) && is_array($payload['fields']) ? $payload['fields'] : array();
            $normalized_fields = $this->normalize_template_fields($raw_fields, $scenario);
            if (!empty($normalized_fields)) {
                $blocks = array();
                foreach ($normalized_fields as $field) {
                    if (!is_array($field)) {
                        continue;
                    }
                    $field_key = sanitize_key(isset($field['key']) ? (string) $field['key'] : '');
                    if ($field_key === '') {
                        $field_key = 'block_' . (count($blocks) + 1);
                    }
                    $field_label = sanitize_text_field(isset($field['label']) ? (string) $field['label'] : '');
                    if ($field_label === '') {
                        $field_label = ucfirst(str_replace('_', ' ', $field_key));
                    }
                    $field_prompt = sanitize_textarea_field(isset($field['prompt']) ? (string) $field['prompt'] : '');
                    $blocks[] = array(
                        'id' => $field_key,
                        'label' => $field_label,
                        'prompt' => $field_prompt,
                    );
                }
                if (!empty($blocks)) {
                    return $blocks;
                }
            }

            $raw_blocks = isset($payload['prompt_blocks']) && is_array($payload['prompt_blocks']) ? $payload['prompt_blocks'] : array();

            $blocks = array();
            $used = array();
            foreach ($raw_blocks as $raw_block) {
                if (!is_array($raw_block)) {
                    continue;
                }
                $block_id = sanitize_key(isset($raw_block['id']) ? (string) $raw_block['id'] : '');
                $block_label = sanitize_text_field(isset($raw_block['label']) ? (string) $raw_block['label'] : '');
                $block_prompt = sanitize_textarea_field(isset($raw_block['prompt']) ? (string) $raw_block['prompt'] : '');

                if ($block_id === '') {
                    $block_id = 'block_' . (count($blocks) + 1);
                }
                if (isset($used[$block_id])) {
                    $block_id = $block_id . '_' . (count($blocks) + 1);
                }
                $used[$block_id] = true;

                if ($block_label === '') {
                    $block_label = ucfirst(str_replace('_', ' ', $block_id));
                }

                $blocks[] = array(
                    'id' => $block_id,
                    'label' => $block_label,
                    'prompt' => $block_prompt,
                );
            }

            if (!empty($blocks)) {
                return $blocks;
            }

            $defaults = $this->default_prompt_blocks_for_scenario($scenario);
            if ($scenario === 'seo_tags') {
                $title_prompt = isset($payload['seo_title_prompt']) ? (string) $payload['seo_title_prompt'] : '';
                $description_prompt = isset($payload['seo_description_prompt']) ? (string) $payload['seo_description_prompt'] : '';
                $fallback_body = isset($payload['body']) ? (string) $payload['body'] : '';
                if ($title_prompt === '') {
                    $title_prompt = $fallback_body;
                }
                if ($description_prompt === '') {
                    $description_prompt = $fallback_body;
                }
                if (isset($defaults[0])) {
                    $defaults[0]['prompt'] = $title_prompt;
                }
                if (isset($defaults[1])) {
                    $defaults[1]['prompt'] = $description_prompt;
                }
            } elseif (isset($defaults[0])) {
                $defaults[0]['prompt'] = isset($payload['body']) ? (string) $payload['body'] : '';
            }

            return $defaults;
        }

        protected function parse_template_fields_from_request($request, $scenario) {
            $scenario = $this->normalize_generation_scenario($scenario);
            $request = is_array($request) ? $request : array();
            $fields = array();

            $raw_json = isset($request['template_fields_json']) ? wp_unslash((string) $request['template_fields_json']) : '';
            if (trim($raw_json) !== '') {
                $decoded = json_decode($raw_json, true);
                if (is_array($decoded)) {
                    $fields = $decoded;
                }
            }

            if (empty($fields)) {
                $raw_keys = isset($request['template_fields_key']) && is_array($request['template_fields_key']) ? $request['template_fields_key'] : array();
                $raw_labels = isset($request['template_fields_label']) && is_array($request['template_fields_label']) ? $request['template_fields_label'] : array();
                $raw_targets = isset($request['template_fields_target']) && is_array($request['template_fields_target']) ? $request['template_fields_target'] : array();
                $raw_prompts = isset($request['template_fields_prompt']) && is_array($request['template_fields_prompt']) ? $request['template_fields_prompt'] : array();
                $raw_lengths = isset($request['template_fields_length_option_id']) && is_array($request['template_fields_length_option_id']) ? $request['template_fields_length_option_id'] : array();
                $raw_max_chars = isset($request['template_fields_max_chars']) && is_array($request['template_fields_max_chars']) ? $request['template_fields_max_chars'] : array();
                $raw_enabled = isset($request['template_fields_enabled']) && is_array($request['template_fields_enabled']) ? $request['template_fields_enabled'] : array();
                $max_count = max(count($raw_keys), count($raw_labels), count($raw_prompts), count($raw_targets), count($raw_lengths), count($raw_max_chars));

                for ($i = 0; $i < $max_count; $i++) {
                    $enabled = false;
                    if (isset($raw_enabled[$i])) {
                        $enabled_value = wp_unslash((string) $raw_enabled[$i]);
                        $enabled = !in_array(strtolower($enabled_value), array('0', 'false', 'off', ''), true);
                    }
                    $fields[] = array(
                        'key' => isset($raw_keys[$i]) ? wp_unslash((string) $raw_keys[$i]) : '',
                        'label' => isset($raw_labels[$i]) ? wp_unslash((string) $raw_labels[$i]) : '',
                        'target_field' => isset($raw_targets[$i]) ? wp_unslash((string) $raw_targets[$i]) : '',
                        'prompt' => isset($raw_prompts[$i]) ? wp_unslash((string) $raw_prompts[$i]) : '',
                        'length_option_id' => isset($raw_lengths[$i]) ? (int) wp_unslash((string) $raw_lengths[$i]) : 0,
                        'max_chars' => isset($raw_max_chars[$i]) ? (int) wp_unslash((string) $raw_max_chars[$i]) : 0,
                        'enabled' => $enabled,
                        'type' => 'ai',
                    );
                }
            }

            $normalized = $this->normalize_template_fields($fields, $scenario);
            foreach ($normalized as &$field) {
                if (!is_array($field)) {
                    continue;
                }
                $target_field = isset($field['target_field']) ? $this->normalize_template_field_target((string) $field['target_field']) : '';
                if ($target_field === '' && $scenario === 'seo_tags') {
                    $key = isset($field['key']) ? sanitize_key((string) $field['key']) : '';
                    if ($key === 'seo_title' || $key === 'title' || $key === 'meta_title') {
                        $target_field = 'seo_field:title';
                    } elseif ($key === 'seo_description' || $key === 'description' || $key === 'meta_description' || $key === 'desc') {
                        $target_field = 'seo_field:description';
                    }
                }
                $field['target_field'] = $target_field;
                $field['type'] = 'ai';
                if ($scenario === 'seo_tags') {
                    if ($target_field === 'seo_field:title' && (!isset($field['max_chars']) || (int) $field['max_chars'] <= 0)) {
                        $field['max_chars'] = 70;
                    } elseif ($target_field === 'seo_field:description' && (!isset($field['max_chars']) || (int) $field['max_chars'] <= 0)) {
                        $field['max_chars'] = 160;
                    }
                }
            }
            unset($field);

            return $normalized;
        }

        protected function parse_prompt_blocks_from_request($request, $scenario) {
            $scenario = $this->normalize_generation_scenario($scenario);
            $request = is_array($request) ? $request : array();
            $raw_keys = isset($request['prompt_blocks_key']) && is_array($request['prompt_blocks_key']) ? $request['prompt_blocks_key'] : array();
            $raw_labels = isset($request['prompt_blocks_label']) && is_array($request['prompt_blocks_label']) ? $request['prompt_blocks_label'] : array();
            $raw_prompts = isset($request['prompt_blocks_prompt']) && is_array($request['prompt_blocks_prompt']) ? $request['prompt_blocks_prompt'] : array();
            $max_count = max(count($raw_keys), count($raw_labels), count($raw_prompts));
            $blocks = array();

            for ($i = 0; $i < $max_count; $i++) {
                $blocks[] = array(
                    'id' => isset($raw_keys[$i]) ? wp_unslash((string) $raw_keys[$i]) : '',
                    'label' => isset($raw_labels[$i]) ? wp_unslash((string) $raw_labels[$i]) : '',
                    'prompt' => isset($raw_prompts[$i]) ? wp_unslash((string) $raw_prompts[$i]) : '',
                );
            }

            return $this->normalize_prompt_blocks($blocks, $scenario);
        }

        protected function normalize_prompt_blocks($raw_blocks, $scenario) {
            $scenario = $this->normalize_generation_scenario($scenario);
            $raw_blocks = is_array($raw_blocks) ? $raw_blocks : array();
            $normalized = array();
            $used_ids = array();
            $index = 1;

            foreach ($raw_blocks as $raw_block) {
                if (!is_array($raw_block)) {
                    continue;
                }
                $block_id = sanitize_key(isset($raw_block['id']) ? (string) $raw_block['id'] : '');
                $block_label = sanitize_text_field(isset($raw_block['label']) ? (string) $raw_block['label'] : '');
                $block_prompt = sanitize_textarea_field(isset($raw_block['prompt']) ? (string) $raw_block['prompt'] : '');
                if (trim($block_prompt) === '') {
                    continue;
                }

                if ($block_id === '') {
                    $block_id = 'block_' . $index;
                }
                $base_block_id = $block_id;
                $suffix = 2;
                while (isset($used_ids[$block_id])) {
                    $block_id = $base_block_id . '_' . $suffix;
                    $suffix++;
                }
                $used_ids[$block_id] = true;

                if ($block_label === '') {
                    $block_label = ucfirst(str_replace('_', ' ', $block_id));
                }

                $normalized[] = array(
                    'id' => $block_id,
                    'label' => $block_label,
                    'prompt' => $block_prompt,
                );
                $index++;
            }

            return $normalized;
        }

        protected function derive_template_prompts_from_blocks($scenario, $base_prompt, $prompt_blocks) {
            $scenario = $this->normalize_generation_scenario($scenario);
            $base_prompt = sanitize_textarea_field((string) $base_prompt);
            $normalized_blocks = $this->normalize_prompt_blocks($prompt_blocks, $scenario);

            $first_prompt = '';
            if (!empty($normalized_blocks[0]['prompt'])) {
                $first_prompt = (string) $normalized_blocks[0]['prompt'];
            }

            $body = $this->compose_prompt_with_base($base_prompt, $first_prompt);
            if ($body === '' && $base_prompt !== '') {
                $body = $base_prompt;
            }

            $seo_title_prompt = '';
            $seo_description_prompt = '';
            if ($scenario === 'seo_tags') {
                $seo_title_raw = $this->find_prompt_block_prompt_by_ids($normalized_blocks, array('seo_title', 'title', 'meta_title'));
                $seo_description_raw = $this->find_prompt_block_prompt_by_ids($normalized_blocks, array('seo_description', 'description', 'meta_description', 'desc'));

                if ($seo_title_raw === '' && $first_prompt !== '') {
                    $seo_title_raw = $first_prompt;
                }
                if ($seo_description_raw === '') {
                    if (isset($normalized_blocks[1]['prompt']) && trim((string) $normalized_blocks[1]['prompt']) !== '') {
                        $seo_description_raw = (string) $normalized_blocks[1]['prompt'];
                    } elseif ($first_prompt !== '') {
                        $seo_description_raw = $first_prompt;
                    }
                }

                $seo_title_prompt = $this->compose_prompt_with_base($base_prompt, $seo_title_raw);
                $seo_description_prompt = $this->compose_prompt_with_base($base_prompt, $seo_description_raw);
                if ($body === '' && $seo_title_prompt !== '') {
                    $body = $seo_title_prompt;
                }
            }

            return array(
                'body' => $body,
                'seo_title_prompt' => $seo_title_prompt,
                'seo_description_prompt' => $seo_description_prompt,
                'prompt_blocks' => $normalized_blocks,
                'base_prompt' => $base_prompt,
            );
        }

        protected function compose_prompt_with_base($base_prompt, $prompt) {
            $base_prompt = trim((string) $base_prompt);
            $prompt = trim((string) $prompt);
            if ($base_prompt !== '' && $prompt !== '') {
                return $base_prompt . "\n\n" . $prompt;
            }
            if ($base_prompt !== '') {
                return $base_prompt;
            }
            return $prompt;
        }

        protected function find_prompt_block_prompt_by_ids($prompt_blocks, $preferred_ids) {
            $prompt_blocks = is_array($prompt_blocks) ? $prompt_blocks : array();
            $preferred_ids = is_array($preferred_ids) ? $preferred_ids : array();
            if (empty($prompt_blocks) || empty($preferred_ids)) {
                return '';
            }

            $preferred_map = array();
            foreach ($preferred_ids as $id) {
                $key = sanitize_key((string) $id);
                if ($key !== '') {
                    $preferred_map[$key] = true;
                }
            }

            foreach ($prompt_blocks as $block) {
                if (!is_array($block) || empty($block['id'])) {
                    continue;
                }
                $block_id = sanitize_key((string) $block['id']);
                if (!isset($preferred_map[$block_id])) {
                    continue;
                }
                $block_prompt = isset($block['prompt']) ? trim((string) $block['prompt']) : '';
                if ($block_prompt !== '') {
                    return $block_prompt;
                }
            }

            return '';
        }

        protected function get_filter_fields_for_post_type($post_type) {
            $post_type = sanitize_key((string) $post_type);
            $fields = array(
                array('value' => 'post_id', 'label' => __('ID записи', 'unicontent-ai-generator')),
                array('value' => 'post_status', 'label' => __('Статус записи', 'unicontent-ai-generator')),
                array('value' => 'post_title', 'label' => __('Заголовок (post_title)', 'unicontent-ai-generator')),
                array('value' => 'post_content', 'label' => __('Содержимое (post_content)', 'unicontent-ai-generator')),
                array('value' => 'post_excerpt', 'label' => __('Краткое описание (post_excerpt)', 'unicontent-ai-generator')),
            );

            $taxonomies = get_object_taxonomies($post_type, 'objects');
            if (is_array($taxonomies)) {
                foreach ($taxonomies as $taxonomy => $taxonomy_obj) {
                    if (!is_object($taxonomy_obj)) {
                        continue;
                    }
                    $taxonomy_name = sanitize_key((string) $taxonomy);
                    if ($taxonomy_name === '') {
                        continue;
                    }
                    $taxonomy_label = isset($taxonomy_obj->labels->singular_name) && (string) $taxonomy_obj->labels->singular_name !== ''
                        ? (string) $taxonomy_obj->labels->singular_name
                        : $taxonomy_name;
                    $fields[] = array(
                        'value' => 'tax:' . $taxonomy_name,
                        'label' => sprintf(__('Таксономия: %1$s (%2$s)', 'unicontent-ai-generator'), $taxonomy_label, $taxonomy_name),
                    );
                }
            }

            $target_fields = UCG_Tokens::get_target_fields_for_post_type($post_type);
            foreach ($target_fields as $field_item) {
                if (empty($field_item['value']) || strpos((string) $field_item['value'], 'post:') === 0) {
                    continue;
                }
                $fields[] = array(
                    'value' => (string) $field_item['value'],
                    'label' => __('Поле: ', 'unicontent-ai-generator') . (string) $field_item['label'],
                );
            }

            $seen = array();
            $result = array();
            foreach ($fields as $field) {
                $value = isset($field['value']) ? (string) $field['value'] : '';
                if ($value === '' || isset($seen[$value])) {
                    continue;
                }
                $seen[$value] = true;
                $result[] = array(
                    'value' => $value,
                    'label' => isset($field['label']) ? (string) $field['label'] : $value,
                );
            }
            return $result;
        }

        protected function get_header_balance_snapshot() {
            $cached = get_transient('ucg_balance_cache');
            if (is_array($cached) && array_key_exists('credits', $cached)) {
                return (float) $cached['credits'];
            }

            if (!UCG_Settings::has_valid_api_key()) {
                return null;
            }

            $client = new UCG_Api_Client();
            $result = $client->get_balance();
            if (is_wp_error($result)) {
                return null;
            }

            $ttl = (int) UCG_Settings::get_option('credits_cache_ttl', 60);
            $ttl = max(10, min(600, $ttl));
            set_transient('ucg_balance_cache', $result, $ttl);
            return isset($result['credits']) ? (float) $result['credits'] : 0.0;
        }

        protected function normalize_filters_from_request($json, $post_type) {
            $post_type = sanitize_key((string) $post_type);
            $json = (string) $json;
            $decoded = json_decode($json, true);
            if (!is_array($decoded)) {
                $decoded = array();
            }

            $allowed_fields = array();
            foreach ($this->get_filter_fields_for_post_type($post_type) as $field_item) {
                if (!empty($field_item['value'])) {
                    $allowed_fields[(string) $field_item['value']] = true;
                }
            }

            $allowed_operators = array('is_empty', 'not_empty', 'contains', 'not_contains', 'equals', 'not_equals', 'gt', 'gte', 'lt', 'lte');
            $allowed_taxonomy_operators = array('is_empty', 'not_empty', 'contains', 'not_contains', 'equals', 'not_equals');
            $result = array();

            foreach ($decoded as $row) {
                if (!is_array($row)) {
                    continue;
                }

                $field = isset($row['field']) ? sanitize_text_field((string) $row['field']) : '';
                $operator = isset($row['operator']) ? sanitize_key((string) $row['operator']) : '';
                $value = isset($row['value']) ? sanitize_text_field((string) $row['value']) : '';

                if ($field === '' || !isset($allowed_fields[$field])) {
                    continue;
                }
                if (!in_array($operator, $allowed_operators, true)) {
                    continue;
                }
                if (strpos($field, 'tax:') === 0 && !in_array($operator, $allowed_taxonomy_operators, true)) {
                    continue;
                }

                if (!in_array($operator, array('is_empty', 'not_empty'), true) && $value === '') {
                    continue;
                }

                $result[] = array(
                    'field' => $field,
                    'operator' => $operator,
                    'value' => $value,
                );
            }

            return $result;
        }

        protected function query_filtered_post_ids_count($post_type, $filters) {
            global $wpdb;

            $post_type = sanitize_key((string) $post_type);
            if ($post_type === '' || !post_type_exists($post_type)) {
                return 0;
            }

            $build = $this->build_filters_sql($post_type, $filters);
            $posts_table = $wpdb->posts;

            $sql = "SELECT COUNT(*) FROM {$posts_table} p WHERE " . implode(' AND ', $build['where']);
            $params = $build['params'];
            $prepared = $this->prepare_query($sql, $params);
            if ($prepared === '') {
                return 0;
            }

            return (int) $wpdb->get_var($prepared);
        }

        protected function query_filtered_post_ids($post_type, $filters, $limit = 20, $offset = 0) {
            global $wpdb;

            $post_type = sanitize_key((string) $post_type);
            if ($post_type === '' || !post_type_exists($post_type)) {
                return array();
            }

            $limit = max(1, min(100000, (int) $limit));
            $offset = max(0, (int) $offset);

            $build = $this->build_filters_sql($post_type, $filters);
            $posts_table = $wpdb->posts;
            $sql = "SELECT p.ID
                    FROM {$posts_table} p
                    WHERE " . implode(' AND ', $build['where']) . "
                    ORDER BY p.ID DESC
                    LIMIT %d OFFSET %d";

            $params = $build['params'];
            $params[] = $limit;
            $params[] = $offset;
            $prepared = $this->prepare_query($sql, $params);
            if ($prepared === '') {
                return array();
            }

            $ids = $wpdb->get_col($prepared);
            if (!is_array($ids)) {
                return array();
            }

            $ids = array_map('intval', $ids);
            $ids = array_values(array_unique(array_filter($ids)));
            return $ids;
        }

        protected function build_filters_sql($post_type, $filters) {
            global $wpdb;

            $post_type = sanitize_key((string) $post_type);
            $where = array(
                'p.post_type = %s',
                "p.post_status IN ('publish','draft','private','pending')",
            );
            $params = array($post_type);
            $meta_table = $wpdb->postmeta;

            if (!is_array($filters)) {
                $filters = array();
            }

            foreach ($filters as $filter) {
                if (!is_array($filter) || empty($filter['field']) || empty($filter['operator'])) {
                    continue;
                }

                $field = (string) $filter['field'];
                $operator = sanitize_key((string) $filter['operator']);
                $value = isset($filter['value']) ? (string) $filter['value'] : '';

                $is_tax_field = strpos($field, 'tax:') === 0;
                if ($is_tax_field) {
                    $taxonomy = sanitize_key(substr($field, 4));
                    if ($taxonomy === '' || !taxonomy_exists($taxonomy)) {
                        continue;
                    }

                    $term_relationships_table = $wpdb->term_relationships;
                    $term_taxonomy_table = $wpdb->term_taxonomy;
                    $terms_table = $wpdb->terms;

                    if ($operator === 'is_empty') {
                        $where[] = "NOT EXISTS (
                            SELECT 1
                            FROM {$term_relationships_table} tr
                            INNER JOIN {$term_taxonomy_table} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id
                            WHERE tr.object_id = p.ID
                              AND tt.taxonomy = %s
                        )";
                        $params[] = $taxonomy;
                        continue;
                    }

                    if ($operator === 'not_empty') {
                        $where[] = "EXISTS (
                            SELECT 1
                            FROM {$term_relationships_table} tr
                            INNER JOIN {$term_taxonomy_table} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id
                            WHERE tr.object_id = p.ID
                              AND tt.taxonomy = %s
                        )";
                        $params[] = $taxonomy;
                        continue;
                    }

                    $slug_value = sanitize_title($value);
                    if ($operator === 'contains') {
                        $where[] = "EXISTS (
                            SELECT 1
                            FROM {$term_relationships_table} tr
                            INNER JOIN {$term_taxonomy_table} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id
                            INNER JOIN {$terms_table} t ON t.term_id = tt.term_id
                            WHERE tr.object_id = p.ID
                              AND tt.taxonomy = %s
                              AND (t.name LIKE %s OR t.slug LIKE %s)
                        )";
                        $params[] = $taxonomy;
                        $params[] = '%' . $wpdb->esc_like($value) . '%';
                        $params[] = '%' . $wpdb->esc_like($value) . '%';
                        continue;
                    }

                    if ($operator === 'not_contains') {
                        $where[] = "NOT EXISTS (
                            SELECT 1
                            FROM {$term_relationships_table} tr
                            INNER JOIN {$term_taxonomy_table} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id
                            INNER JOIN {$terms_table} t ON t.term_id = tt.term_id
                            WHERE tr.object_id = p.ID
                              AND tt.taxonomy = %s
                              AND (t.name LIKE %s OR t.slug LIKE %s)
                        )";
                        $params[] = $taxonomy;
                        $params[] = '%' . $wpdb->esc_like($value) . '%';
                        $params[] = '%' . $wpdb->esc_like($value) . '%';
                        continue;
                    }

                    if ($operator === 'equals') {
                        $where[] = "EXISTS (
                            SELECT 1
                            FROM {$term_relationships_table} tr
                            INNER JOIN {$term_taxonomy_table} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id
                            INNER JOIN {$terms_table} t ON t.term_id = tt.term_id
                            WHERE tr.object_id = p.ID
                              AND tt.taxonomy = %s
                              AND (t.name = %s OR t.slug = %s)
                        )";
                        $params[] = $taxonomy;
                        $params[] = $value;
                        $params[] = $slug_value;
                        continue;
                    }

                    if ($operator === 'not_equals') {
                        $where[] = "NOT EXISTS (
                            SELECT 1
                            FROM {$term_relationships_table} tr
                            INNER JOIN {$term_taxonomy_table} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id
                            INNER JOIN {$terms_table} t ON t.term_id = tt.term_id
                            WHERE tr.object_id = p.ID
                              AND tt.taxonomy = %s
                              AND (t.name = %s OR t.slug = %s)
                        )";
                        $params[] = $taxonomy;
                        $params[] = $value;
                        $params[] = $slug_value;
                    }
                    continue;
                }

                $is_meta_field = strpos($field, 'meta:') === 0 || strpos($field, 'acf:') === 0;
                if ($is_meta_field) {
                    $meta_key = strpos($field, 'meta:') === 0 ? substr($field, 5) : substr($field, 4);
                    $meta_key = sanitize_text_field((string) $meta_key);
                    if ($meta_key === '') {
                        continue;
                    }

                    if ($operator === 'is_empty') {
                        $where[] = "NOT EXISTS (
                            SELECT 1 FROM {$meta_table} pm
                            WHERE pm.post_id = p.ID
                              AND pm.meta_key = %s
                              AND CHAR_LENGTH(TRIM(COALESCE(pm.meta_value, ''))) > 0
                        )";
                        $params[] = $meta_key;
                        continue;
                    }

                    if ($operator === 'not_empty') {
                        $where[] = "EXISTS (
                            SELECT 1 FROM {$meta_table} pm
                            WHERE pm.post_id = p.ID
                              AND pm.meta_key = %s
                              AND CHAR_LENGTH(TRIM(COALESCE(pm.meta_value, ''))) > 0
                        )";
                        $params[] = $meta_key;
                        continue;
                    }

                    if ($operator === 'contains') {
                        $where[] = "EXISTS (
                            SELECT 1 FROM {$meta_table} pm
                            WHERE pm.post_id = p.ID
                              AND pm.meta_key = %s
                              AND pm.meta_value LIKE %s
                        )";
                        $params[] = $meta_key;
                        $params[] = '%' . $wpdb->esc_like($value) . '%';
                        continue;
                    }

                    if ($operator === 'not_contains') {
                        $where[] = "NOT EXISTS (
                            SELECT 1 FROM {$meta_table} pm
                            WHERE pm.post_id = p.ID
                              AND pm.meta_key = %s
                              AND pm.meta_value LIKE %s
                        )";
                        $params[] = $meta_key;
                        $params[] = '%' . $wpdb->esc_like($value) . '%';
                        continue;
                    }

                    if ($operator === 'equals') {
                        $where[] = "EXISTS (
                            SELECT 1 FROM {$meta_table} pm
                            WHERE pm.post_id = p.ID
                              AND pm.meta_key = %s
                              AND pm.meta_value = %s
                        )";
                        $params[] = $meta_key;
                        $params[] = $value;
                        continue;
                    }

                    if ($operator === 'not_equals') {
                        $where[] = "NOT EXISTS (
                            SELECT 1 FROM {$meta_table} pm
                            WHERE pm.post_id = p.ID
                              AND pm.meta_key = %s
                              AND pm.meta_value = %s
                        )";
                        $params[] = $meta_key;
                        $params[] = $value;
                        continue;
                    }
                    continue;
                }

                if ($field === 'post_id') {
                    $number = (int) $value;
                    if ($operator === 'equals') {
                        $where[] = 'p.ID = %d';
                        $params[] = $number;
                    } elseif ($operator === 'not_equals') {
                        $where[] = 'p.ID <> %d';
                        $params[] = $number;
                    } elseif ($operator === 'gt') {
                        $where[] = 'p.ID > %d';
                        $params[] = $number;
                    } elseif ($operator === 'gte') {
                        $where[] = 'p.ID >= %d';
                        $params[] = $number;
                    } elseif ($operator === 'lt') {
                        $where[] = 'p.ID < %d';
                        $params[] = $number;
                    } elseif ($operator === 'lte') {
                        $where[] = 'p.ID <= %d';
                        $params[] = $number;
                    }
                    continue;
                }

                if ($field === 'post_status') {
                    if ($operator === 'equals') {
                        $where[] = 'p.post_status = %s';
                        $params[] = sanitize_key($value);
                    } elseif ($operator === 'not_equals') {
                        $where[] = 'p.post_status <> %s';
                        $params[] = sanitize_key($value);
                    }
                    continue;
                }

                $column = '';
                if ($field === 'post_title') {
                    $column = 'p.post_title';
                } elseif ($field === 'post_content') {
                    $column = 'p.post_content';
                } elseif ($field === 'post_excerpt') {
                    $column = 'p.post_excerpt';
                }

                if ($column === '') {
                    continue;
                }

                if ($operator === 'is_empty') {
                    $where[] = "CHAR_LENGTH(TRIM(COALESCE({$column}, ''))) = 0";
                    continue;
                }
                if ($operator === 'not_empty') {
                    $where[] = "CHAR_LENGTH(TRIM(COALESCE({$column}, ''))) > 0";
                    continue;
                }
                if ($operator === 'contains') {
                    $where[] = "{$column} LIKE %s";
                    $params[] = '%' . $wpdb->esc_like($value) . '%';
                    continue;
                }
                if ($operator === 'not_contains') {
                    $where[] = "({$column} IS NULL OR {$column} NOT LIKE %s)";
                    $params[] = '%' . $wpdb->esc_like($value) . '%';
                    continue;
                }
                if ($operator === 'equals') {
                    $where[] = "{$column} = %s";
                    $params[] = $value;
                    continue;
                }
                if ($operator === 'not_equals') {
                    $where[] = "({$column} IS NULL OR {$column} <> %s)";
                    $params[] = $value;
                }
            }

            return array(
                'where' => $where,
                'params' => $params,
            );
        }

        protected function prepare_query($sql, $params) {
            global $wpdb;
            if (!is_string($sql) || $sql === '') {
                return '';
            }
            if (!is_array($params) || empty($params)) {
                return $sql;
            }

            return $wpdb->prepare($sql, $params);
        }

        protected function map_posts_for_preview($ids) {
            $ids = is_array($ids) ? array_map('intval', $ids) : array();
            $ids = array_values(array_filter(array_unique($ids)));
            if (empty($ids)) {
                return array();
            }

            $result = array();
            foreach ($ids as $post_id) {
                $post = get_post($post_id);
                if (!$post instanceof WP_Post) {
                    continue;
                }
                $result[] = array(
                    'id' => (int) $post->ID,
                    'title' => (string) $post->post_title,
                    'status' => (string) $post->post_status,
                    'status_label' => $this->status_label((string) $post->post_status),
                    'date' => (string) $post->post_date,
                );
            }
            return $result;
        }

        protected function inject_create_runtime_tokens_for_preview($text, $item_topic, $item_context) {
            $text = (string) $text;
            $item_topic = (string) $item_topic;
            $item_context = (string) $item_context;
            $replacements = array(
                '{{item}}' => $item_topic,
                '{item}' => $item_topic,
                '{{context}}' => $item_context,
                '{context}' => $item_context,
            );
            $text = strtr($text, $replacements);
            $text = preg_replace_callback('/\{\{\s*item\s*\}\}|\{\s*item\s*\}/iu', function () use ($item_topic) {
                return $item_topic;
            }, (string) $text);
            $text = preg_replace_callback('/\{\{\s*context\s*\}\}|\{\s*context\s*\}/iu', function () use ($item_context) {
                return $item_context;
            }, (string) $text);
            return is_string($text) ? $text : '';
        }

        protected function inject_generated_field_tokens_for_preview($text, $generated_values_by_key) {
            $text = (string) $text;
            $generated_values_by_key = is_array($generated_values_by_key) ? $generated_values_by_key : array();
            if ($text === '' || empty($generated_values_by_key)) {
                return $text;
            }

            foreach ($generated_values_by_key as $field_key => $value) {
                $field_key = sanitize_key((string) $field_key);
                if ($field_key === '') {
                    continue;
                }

                if (is_array($value) || is_object($value)) {
                    $replacement = wp_json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                } elseif ($value === null) {
                    $replacement = '';
                } elseif (is_bool($value)) {
                    $replacement = $value ? '1' : '0';
                } else {
                    $replacement = (string) $value;
                }

                $token_pattern_double = '/\{\{\s*generated\s*:\s*' . preg_quote($field_key, '/') . '\s*\}\}/iu';
                $token_pattern_single = '/\{\s*generated\s*:\s*' . preg_quote($field_key, '/') . '\s*\}/iu';
                $text = preg_replace_callback($token_pattern_double, function () use ($replacement) {
                    return $replacement;
                }, $text);
                $text = preg_replace_callback($token_pattern_single, function () use ($replacement) {
                    return $replacement;
                }, $text);
            }

            return (string) $text;
        }

        protected function multi_field_hint_for_target_field_preview($field_key, $target_field) {
            $field_key = sanitize_key((string) $field_key);
            $target_field = $this->normalize_generation_target_field($target_field);
            if ($target_field === 'post:post_title') {
                return __('Заголовок, одна строка без markdown.', 'unicontent-ai-generator');
            }
            if ($target_field === 'post:post_content') {
                return __('Основной текст, связный и подробный.', 'unicontent-ai-generator');
            }
            if ($target_field === 'post:post_excerpt') {
                return __('Краткая выжимка из основного текста.', 'unicontent-ai-generator');
            }
            if ($target_field === 'seo_field:title') {
                return __('SEO title, одна строка.', 'unicontent-ai-generator');
            }
            if ($target_field === 'seo_field:description') {
                return __('SEO description, одна строка.', 'unicontent-ai-generator');
            }
            if ($field_key === 'post_title') {
                return __('Заголовок, одна строка.', 'unicontent-ai-generator');
            }
            if ($field_key === 'post_content') {
                return __('Основной текст.', 'unicontent-ai-generator');
            }
            if ($field_key === 'post_excerpt') {
                return __('Краткое описание.', 'unicontent-ai-generator');
            }
            return __('Верни строковое значение для этого поля.', 'unicontent-ai-generator');
        }

        protected function build_multi_field_json_prompt_for_preview($item_topic, $prepared_fields) {
            $item_topic = trim((string) $item_topic);
            $prepared_fields = is_array($prepared_fields) ? $prepared_fields : array();
            $parts = array();
            $parts[] = __('Тема элемента:', 'unicontent-ai-generator') . ' ' . $item_topic;
            $parts[] = __('Сгенерируй значения для всех полей и верни JSON с соответствующими ключами.', 'unicontent-ai-generator');
            $parts[] = __('Все поля должны относиться к одной и той же теме элемента. Не смешивай разные товары/темы.', 'unicontent-ai-generator');
            $parts[] = __('Критично: в каждом поле используй именно эту тему. Если тема не подходит, переформулируй, но не подменяй объект.', 'unicontent-ai-generator');
            $parts[] = __('Верни только JSON-объект без markdown и пояснений.', 'unicontent-ai-generator');
            foreach ($prepared_fields as $field_key => $field_meta) {
                if (!is_array($field_meta)) {
                    continue;
                }
                $label = isset($field_meta['label']) ? trim((string) $field_meta['label']) : (string) $field_key;
                $field_prompt = isset($field_meta['prompt']) ? trim((string) $field_meta['prompt']) : '';
                $field_max_chars = isset($field_meta['max_chars']) ? max(0, (int) $field_meta['max_chars']) : 0;
                $header = '[' . (string) $field_key . '] ' . $label;
                if ($field_max_chars > 0) {
                    $header .= ' (' . sprintf(__('макс. %d символов', 'unicontent-ai-generator'), $field_max_chars) . ')';
                }
                if ($field_prompt !== '') {
                    $parts[] = $header . "\n" . $field_prompt;
                } else {
                    $parts[] = $header;
                }
            }
            return implode("\n\n", $parts);
        }

        protected function extract_image_sources_from_response_for_preview($response) {
            $response = is_array($response) ? $response : array();
            if (empty($response)) {
                return array();
            }

            $sources = array();
            $collector = null;
            $collector = function ($value) use (&$collector, &$sources) {
                if (count($sources) >= 8) {
                    return;
                }
                if (is_string($value)) {
                    $candidate = trim($value);
                    if ($candidate === '') {
                        return;
                    }
                    if (preg_match('#^data:image/[a-zA-Z0-9.+-]+;base64,#', $candidate) || filter_var($candidate, FILTER_VALIDATE_URL)) {
                        $sources[] = $candidate;
                        return;
                    }
                    $decoded = json_decode($candidate, true);
                    if (is_array($decoded)) {
                        $collector($decoded);
                    }
                    return;
                }
                if (!is_array($value)) {
                    return;
                }
                if (isset($value['url'])) {
                    $collector($value['url']);
                }
                if (isset($value['image_url']) && is_array($value['image_url']) && isset($value['image_url']['url'])) {
                    $collector($value['image_url']['url']);
                }
                if (isset($value['imageUrl']) && is_array($value['imageUrl']) && isset($value['imageUrl']['url'])) {
                    $collector($value['imageUrl']['url']);
                }
                if (isset($value['images']) && is_array($value['images'])) {
                    foreach ($value['images'] as $item) {
                        $collector($item);
                    }
                }
                if (array_keys($value) === range(0, count($value) - 1)) {
                    foreach ($value as $item) {
                        $collector($item);
                    }
                }
            };

            $collector($response);

            if (empty($sources)) {
                return array();
            }
            $sources = array_values(array_unique(array_filter(array_map('strval', $sources))));
            if (count($sources) > 8) {
                $sources = array_slice($sources, 0, 8);
            }
            return $sources;
        }

        protected function prepend_create_topic_context_to_prompt_for_preview($prompt, $item_topic, $item_context) {
            $prompt = trim((string) $prompt);
            $item_topic = trim((string) $item_topic);
            $item_context = trim((string) $item_context);

            $parts = array();
            if ($item_topic !== '') {
                $parts[] = __('Тема элемента:', 'unicontent-ai-generator') . ' ' . $item_topic;
            }
            if ($item_context !== '' && $item_context !== $item_topic) {
                $parts[] = __('Контекст элемента:', 'unicontent-ai-generator') . ' ' . $item_context;
            }
            if ($prompt !== '') {
                $parts[] = $prompt;
            }

            return implode("\n\n", $parts);
        }

        protected function extract_multi_field_response_fields_for_preview($response) {
            if (!is_array($response)) {
                return array();
            }

            $fields = isset($response['fields']) && is_array($response['fields']) ? $response['fields'] : array();
            if (!empty($fields)) {
                return $fields;
            }

            $raw_text = isset($response['text']) ? (string) $response['text'] : '';
            if ($raw_text === '') {
                return array();
            }

            $decoded = json_decode($raw_text, true);
            if (is_array($decoded) && !empty($decoded)) {
                if (isset($decoded['fields']) && is_array($decoded['fields']) && !empty($decoded['fields'])) {
                    return $decoded['fields'];
                }
                return $decoded;
            }

            $decoded = $this->extract_json_object_from_text_for_preview($raw_text);
            if (!empty($decoded)) {
                if (isset($decoded['fields']) && is_array($decoded['fields']) && !empty($decoded['fields'])) {
                    return $decoded['fields'];
                }
                return $decoded;
            }

            return array();
        }

        protected function generate_multi_field_preview_fallback_response(
            UCG_Api_Client $api_client,
            $prepared_fields,
            $system_prompt,
            $max_tokens,
            $model,
            $operation_type = 'text'
        ) {
            $prepared_fields = is_array($prepared_fields) ? $prepared_fields : array();
            $system_prompt = (string) $system_prompt;
            $max_tokens = max(1, (int) $max_tokens);
            $model = $this->normalize_model_identifier((string) $model);
            if ($model === '') {
                $model = 'auto';
            }
            $operation_type = sanitize_key((string) $operation_type);
            if (!in_array($operation_type, array('text', 'seo_tags', 'long_text', 'image'), true)) {
                $operation_type = 'text';
            }

            if (empty($prepared_fields)) {
                return new WP_Error('ucg_multi_field_preview_fallback_empty', __('Нет полей для fallback-превью.', 'unicontent-ai-generator'));
            }

            $fields = array();
            $credits_spent = 0.0;
            $credits_remaining = 0.0;

            foreach ($prepared_fields as $field_key => $field_meta) {
                if (!is_array($field_meta)) {
                    continue;
                }
                $field_key = sanitize_key((string) $field_key);
                if ($field_key === '') {
                    continue;
                }

                $field_prompt = isset($field_meta['prompt']) ? trim((string) $field_meta['prompt']) : '';
                if ($field_prompt === '') {
                    continue;
                }
                $field_length_option_id = isset($field_meta['length_option_id']) ? max(0, (int) $field_meta['length_option_id']) : 0;

                $response = $api_client->generate_text(
                    $field_prompt,
                    $system_prompt,
                    $max_tokens,
                    $field_length_option_id,
                    false,
                    $model,
                    $operation_type
                );
                if (is_wp_error($response)) {
                    return $response;
                }

                $value = isset($response['text']) ? trim((string) $response['text']) : '';
                if ($value === '') {
                    return new WP_Error(
                        'ucg_multi_field_preview_fallback_empty_value',
                        sprintf(
                            __('Пустой результат fallback-превью для поля "%s".', 'unicontent-ai-generator'),
                            isset($field_meta['label']) ? (string) $field_meta['label'] : (string) $field_key
                        )
                    );
                }

                $fields[$field_key] = $value;
                $credits_spent += isset($response['credits_spent']) ? (float) $response['credits_spent'] : 0.0;
                if (isset($response['credits_remaining'])) {
                    $credits_remaining = (float) $response['credits_remaining'];
                }
            }

            if (empty($fields)) {
                return new WP_Error('ucg_multi_field_preview_fallback_empty', __('Fallback-превью не вернуло ни одного поля.', 'unicontent-ai-generator'));
            }

            return array(
                'fields' => $fields,
                'text' => wp_json_encode($fields, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'credits_spent' => $credits_spent,
                'credits_remaining' => $credits_remaining,
            );
        }

        protected function extract_json_object_from_text_for_preview($raw_text) {
            $text = trim((string) $raw_text);
            if ($text === '') {
                return array();
            }

            $decoded = $this->decode_json_object_candidate_for_preview($text);
            if (!empty($decoded)) {
                return $decoded;
            }

            if (preg_match('/```(?:json)?\s*({[\s\S]*?})\s*```/iu', $text, $match) && !empty($match[1])) {
                $decoded = $this->decode_json_object_candidate_for_preview((string) $match[1]);
                if (!empty($decoded)) {
                    return $decoded;
                }
            }

            $first_brace = strpos($text, '{');
            $last_brace = strrpos($text, '}');
            if ($first_brace !== false && $last_brace !== false && $last_brace > $first_brace) {
                $candidate = substr($text, (int) $first_brace, (int) ($last_brace - $first_brace + 1));
                $decoded = $this->decode_json_object_candidate_for_preview($candidate);
                if (!empty($decoded)) {
                    return $decoded;
                }
            }

            return array();
        }

        protected function decode_json_object_candidate_for_preview($candidate) {
            $candidate = trim((string) $candidate);
            if ($candidate === '') {
                return array();
            }
            $decoded = json_decode($candidate, true);
            if (!is_array($decoded) || empty($decoded)) {
                return array();
            }
            if (!$this->is_associative_array_for_preview($decoded)) {
                return array();
            }
            return $decoded;
        }

        protected function is_associative_array_for_preview($value) {
            if (!is_array($value)) {
                return false;
            }
            return array_keys($value) !== range(0, count($value) - 1);
        }

        protected function normalize_generation_target_field($target_field) {
            $target_field = (string) $target_field;
            $target_field = preg_replace('/[^a-zA-Z0-9:_-]/', '', $target_field);
            return (string) $target_field;
        }

        protected function resolve_generation_target_field_by_key($key) {
            $key = sanitize_key((string) $key);
            if ($key === '') {
                return '';
            }
            $map = array(
                'post_title' => 'post:post_title',
                'post_content' => 'post:post_content',
                'post_excerpt' => 'post:post_excerpt',
                'seo_title' => 'seo_field:title',
                'seo_description' => 'seo_field:description',
                'post_status' => 'post:post_status',
                'post_author' => 'post:post_author',
                'post_date' => 'post:post_date',
                'post_category' => 'tax:category',
                'post_tag' => 'tax:post_tag',
                'product_category' => 'tax:product_cat',
                'product_tag' => 'tax:product_tag',
                'stock_status' => 'meta:_stock_status',
                'stock_quantity' => 'meta:_stock',
                'catalog_visibility' => 'meta:_visibility',
                'featured_image' => 'media:featured',
                'product_images' => 'media:product_images',
                'product_gallery' => 'media:gallery',
            );
            if (isset($map[$key])) {
                return (string) $map[$key];
            }
            if (strpos($key, 'post_') === 0) {
                return 'post:' . $key;
            }
            if (strpos($key, 'meta_') === 0) {
                return 'meta:' . substr($key, 5);
            }
            if (strpos($key, 'tax_') === 0) {
                return 'tax:' . substr($key, 4);
            }
            return '';
        }

        protected function sanitize_generation_field_value($value) {
            if (is_array($value)) {
                $normalized = array();
                foreach ($value as $key => $item) {
                    if (is_string($key)) {
                        $normalized[sanitize_key($key)] = $this->sanitize_generation_field_value($item);
                    } else {
                        $normalized[] = $this->sanitize_generation_field_value($item);
                    }
                }
                return $normalized;
            }
            if (is_object($value)) {
                return $this->sanitize_generation_field_value((array) $value);
            }
            if (is_string($value)) {
                return sanitize_text_field($value);
            }
            if (is_bool($value) || is_int($value) || is_float($value) || $value === null) {
                return $value;
            }
            return '';
        }

        protected function normalize_generation_fields($fields, $type = 'ai', $scenario = '') {
            $fields = is_array($fields) ? $fields : array();
            $type = $type === 'static' ? 'static' : 'ai';
            $scenario = $this->normalize_generation_scenario((string) $scenario);
            $normalized = array();

            foreach ($fields as $index => $field) {
                if (!is_array($field)) {
                    continue;
                }
                $key = sanitize_key(isset($field['key']) ? (string) $field['key'] : ($type . '_' . ((int) $index + 1)));
                if ($key === '') {
                    $key = $type . '_' . ((int) $index + 1);
                }
                $target_field = $this->normalize_generation_target_field(
                    isset($field['target_field']) ? (string) $field['target_field'] : ''
                );
                if ($target_field === '') {
                    $target_field = $this->resolve_generation_target_field_by_key($key);
                }
                if ($type === 'static') {
                    $legacy_product_field = $this->remap_legacy_product_static_field($scenario, $key, $target_field);
                    $key = isset($legacy_product_field['key']) ? sanitize_key((string) $legacy_product_field['key']) : $key;
                    if ($key === '') {
                        $key = $type . '_' . ((int) $index + 1);
                    }
                    $target_field = isset($legacy_product_field['target_field'])
                        ? $this->normalize_generation_target_field((string) $legacy_product_field['target_field'])
                        : $target_field;
                }

                $row = array(
                    'key' => $key,
                    'label' => sanitize_text_field(isset($field['label']) ? (string) $field['label'] : $key),
                    'enabled' => !array_key_exists('enabled', $field) || !empty($field['enabled']),
                    'target_field' => $target_field,
                );

                if ($type === 'ai') {
                    $output_type = sanitize_key(isset($field['output_type']) ? (string) $field['output_type'] : '');
                    if ($output_type === '') {
                        $output_type = strpos($target_field, 'media:') === 0 ? 'image' : 'text';
                    }
                    if ($output_type !== 'image') {
                        $output_type = 'text';
                    }
                    $field_model = $this->normalize_model_identifier(isset($field['model']) ? (string) $field['model'] : 'auto');
                    if ($field_model === '') {
                        $field_model = 'auto';
                    }
                    $images_count = isset($field['images_count']) ? max(1, min(8, (int) $field['images_count'])) : 1;
                    $aspect_ratio = isset($field['aspect_ratio']) ? sanitize_text_field((string) $field['aspect_ratio']) : '';
                    if (!in_array($aspect_ratio, array('1:1', '2:3', '3:2', '3:4', '4:3', '4:5', '5:4', '9:16', '16:9', '21:9', '1:4', '4:1', '1:8', '8:1'), true)) {
                        $aspect_ratio = '';
                    }
                    $image_size = isset($field['image_size']) ? strtoupper(sanitize_text_field((string) $field['image_size'])) : '';
                    if (!in_array($image_size, array('0.5K', '1K', '2K', '4K'), true)) {
                        $image_size = '';
                    }

                    $row['type'] = 'ai';
                    $row['prompt'] = sanitize_textarea_field(
                        isset($field['prompt']) ? (string) $field['prompt'] : (isset($field['prompt_template']) ? (string) $field['prompt_template'] : '')
                    );
                    $row['length_option_id'] = isset($field['length_option_id']) ? max(0, (int) $field['length_option_id']) : 0;
                    $row['max_chars'] = isset($field['max_chars']) ? max(0, (int) $field['max_chars']) : 0;
                    $row['output_type'] = $output_type;
                    $row['model'] = $field_model;
                    $row['images_count'] = $images_count;
                    $row['aspect_ratio'] = $aspect_ratio;
                    $row['image_size'] = $image_size;
                } else {
                    $row['type'] = 'static';
                    $row['value'] = array_key_exists('value', $field)
                        ? $this->sanitize_generation_field_value($field['value'])
                        : '';
                }

                $normalized[] = $row;
            }

            return $normalized;
        }

        protected function parse_generation_fields_from_request($raw_json, $type = 'ai', $scenario = '') {
            $raw_json = (string) $raw_json;
            if ($raw_json === '') {
                return array();
            }
            $decoded = json_decode($raw_json, true);
            if (!is_array($decoded)) {
                return array();
            }
            return $this->normalize_generation_fields($decoded, $type, $scenario);
        }

        protected function has_enabled_generation_fields($fields) {
            $fields = is_array($fields) ? $fields : array();
            foreach ($fields as $field) {
                if (!is_array($field)) {
                    continue;
                }
                if (!array_key_exists('enabled', $field) || !empty($field['enabled'])) {
                    return true;
                }
            }
            return false;
        }

        protected function has_enabled_ai_fields_with_prompt($fields) {
            $fields = is_array($fields) ? $fields : array();
            foreach ($fields as $field) {
                if (!is_array($field)) {
                    continue;
                }
                if (array_key_exists('enabled', $field) && empty($field['enabled'])) {
                    continue;
                }
                $prompt = isset($field['prompt']) ? trim((string) $field['prompt']) : '';
                if ($prompt !== '') {
                    return true;
                }
            }
            return false;
        }

        protected function has_enabled_ai_fields_without_prompt($fields) {
            $fields = is_array($fields) ? $fields : array();
            foreach ($fields as $field) {
                if (!is_array($field)) {
                    continue;
                }
                if (array_key_exists('enabled', $field) && empty($field['enabled'])) {
                    continue;
                }
                $prompt = isset($field['prompt']) ? trim((string) $field['prompt']) : '';
                if ($prompt === '') {
                    return true;
                }
            }
            return false;
        }

        protected function generation_field_has_non_empty_value($value) {
            if (is_array($value)) {
                foreach ($value as $item) {
                    if ($this->generation_field_has_non_empty_value($item)) {
                        return true;
                    }
                }
                return false;
            }
            if (is_object($value)) {
                return $this->generation_field_has_non_empty_value((array) $value);
            }
            if ($value === null) {
                return false;
            }
            if (is_bool($value) || is_int($value) || is_float($value)) {
                return true;
            }
            return trim((string) $value) !== '';
        }

        protected function has_enabled_static_fields_with_value($fields) {
            $fields = is_array($fields) ? $fields : array();
            foreach ($fields as $field) {
                if (!is_array($field)) {
                    continue;
                }
                if (array_key_exists('enabled', $field) && empty($field['enabled'])) {
                    continue;
                }
                $value = array_key_exists('value', $field) ? $field['value'] : '';
                if ($this->generation_field_has_non_empty_value($value)) {
                    return true;
                }
            }
            return false;
        }

        protected function resolve_run_target_field_from_fields($ai_fields, $static_fields) {
            $ai_fields = is_array($ai_fields) ? $ai_fields : array();
            foreach ($ai_fields as $field) {
                if (!is_array($field)) {
                    continue;
                }
                if (array_key_exists('enabled', $field) && empty($field['enabled'])) {
                    continue;
                }
                $target = $this->normalize_generation_target_field(isset($field['target_field']) ? (string) $field['target_field'] : '');
                if ($target === 'seo_field:title' || $target === 'seo_field:description') {
                    return 'seo:auto';
                }
                if ($target !== '') {
                    return $target;
                }
            }

            $static_fields = is_array($static_fields) ? $static_fields : array();
            foreach ($static_fields as $field) {
                if (!is_array($field)) {
                    continue;
                }
                if (array_key_exists('enabled', $field) && empty($field['enabled'])) {
                    continue;
                }
                $target = $this->normalize_generation_target_field(isset($field['target_field']) ? (string) $field['target_field'] : '');
                if ($target === 'seo_field:title' || $target === 'seo_field:description') {
                    return 'seo:auto';
                }
                if ($target !== '') {
                    return $target;
                }
            }

            return '';
        }

        protected function decode_generated_fields_payload($raw_json) {
            $result = array(
                'ai_fields' => array(),
                'static_fields' => array(),
            );

            $decoded = json_decode((string) $raw_json, true);
            if (!is_array($decoded)) {
                return $result;
            }

            $raw_ai_fields = isset($decoded['ai_fields']) && is_array($decoded['ai_fields']) ? $decoded['ai_fields'] : array();
            foreach ($raw_ai_fields as $index => $field) {
                if (!is_array($field)) {
                    continue;
                }
                $key = sanitize_key(isset($field['key']) ? (string) $field['key'] : ('ai_' . ((int) $index + 1)));
                if ($key === '') {
                    $key = 'ai_' . ((int) $index + 1);
                }
                $target_field = $this->normalize_generation_target_field(
                    isset($field['target_field']) ? (string) $field['target_field'] : ''
                );
                if ($target_field === '') {
                    $target_field = $this->resolve_generation_target_field_by_key($key);
                }
                $result['ai_fields'][] = array(
                    'key' => $key,
                    'label' => sanitize_text_field(isset($field['label']) ? (string) $field['label'] : $key),
                    'status' => sanitize_key(isset($field['status']) ? (string) $field['status'] : 'generated'),
                    'target_field' => $target_field,
                    'generated_text' => isset($field['generated_text']) ? (string) $field['generated_text'] : '',
                    'prompt' => isset($field['prompt']) ? (string) $field['prompt'] : '',
                    'length_option_id' => isset($field['length_option_id']) ? max(0, (int) $field['length_option_id']) : 0,
                    'max_chars' => isset($field['max_chars']) ? max(0, (int) $field['max_chars']) : 0,
                );
            }

            $raw_static_fields = isset($decoded['static_fields']) && is_array($decoded['static_fields']) ? $decoded['static_fields'] : array();
            foreach ($raw_static_fields as $index => $field) {
                if (!is_array($field)) {
                    continue;
                }
                $key = sanitize_key(isset($field['key']) ? (string) $field['key'] : ('static_' . ((int) $index + 1)));
                if ($key === '') {
                    $key = 'static_' . ((int) $index + 1);
                }
                $target_field = $this->normalize_generation_target_field(
                    isset($field['target_field']) ? (string) $field['target_field'] : ''
                );
                if ($target_field === '') {
                    $target_field = $this->resolve_generation_target_field_by_key($key);
                }
                $result['static_fields'][] = array(
                    'key' => $key,
                    'label' => sanitize_text_field(isset($field['label']) ? (string) $field['label'] : $key),
                    'status' => sanitize_key(isset($field['status']) ? (string) $field['status'] : 'generated'),
                    'target_field' => $target_field,
                    'value' => array_key_exists('value', $field) ? $this->sanitize_generation_field_value($field['value']) : '',
                );
            }

            return $result;
        }

        protected function stringify_generation_field_value_for_write($value) {
            if (is_array($value) || is_object($value)) {
                return wp_json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            }
            if (is_bool($value)) {
                return $value ? '1' : '0';
            }
            if ($value === null) {
                return '';
            }
            return (string) $value;
        }

        protected function build_seo_payload_for_write($title, $description) {
            return wp_json_encode(
                array(
                    'title' => (string) $title,
                    'description' => (string) $description,
                    'focus_keyword' => '',
                ),
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
            );
        }

        protected function write_generated_fields_for_review_item($post_id, $run_target_field, $generated_fields, $write_context = array()) {
            $post_id = (int) $post_id;
            $run_target_field = $this->normalize_generation_target_field($run_target_field);
            $generated_fields = is_array($generated_fields) ? $generated_fields : array();
            $write_context = is_array($write_context) ? $write_context : array();
            if ($post_id <= 0) {
                return new WP_Error('ucg_invalid_post', __('Некорректная запись.', 'unicontent-ai-generator'));
            }

            $ai_fields = isset($generated_fields['ai_fields']) && is_array($generated_fields['ai_fields']) ? $generated_fields['ai_fields'] : array();
            $static_fields = isset($generated_fields['static_fields']) && is_array($generated_fields['static_fields']) ? $generated_fields['static_fields'] : array();

            $seo_title = '';
            $seo_description = '';

            foreach ($ai_fields as $field) {
                if (!is_array($field)) {
                    continue;
                }
                $status = sanitize_key(isset($field['status']) ? (string) $field['status'] : 'generated');
                if ($status !== '' && $status !== 'generated') {
                    continue;
                }
                $target_field = $this->normalize_generation_target_field(
                    isset($field['target_field']) ? (string) $field['target_field'] : ''
                );
                if ($target_field === '') {
                    continue;
                }
                $value = isset($field['generated_text']) ? (string) $field['generated_text'] : '';
                if ($target_field === 'seo_field:title') {
                    $seo_title = $value;
                    continue;
                }
                if ($target_field === 'seo_field:description') {
                    $seo_description = $value;
                    continue;
                }

                $write_result = UCG_Tokens::write_generated_value($post_id, $target_field, $value, $write_context);
                if (is_wp_error($write_result)) {
                    return $write_result;
                }
            }

            foreach ($static_fields as $field) {
                if (!is_array($field)) {
                    continue;
                }
                $status = sanitize_key(isset($field['status']) ? (string) $field['status'] : 'generated');
                if ($status !== '' && $status !== 'generated') {
                    continue;
                }
                $target_field = $this->normalize_generation_target_field(
                    isset($field['target_field']) ? (string) $field['target_field'] : ''
                );
                if ($target_field === '') {
                    continue;
                }
                $value = array_key_exists('value', $field) ? $field['value'] : '';
                if ($target_field === 'seo_field:title') {
                    $seo_title = $this->stringify_generation_field_value_for_write($value);
                    continue;
                }
                if ($target_field === 'seo_field:description') {
                    $seo_description = $this->stringify_generation_field_value_for_write($value);
                    continue;
                }

                $write_result = UCG_Tokens::write_generated_value($post_id, $target_field, $value, $write_context);
                if (is_wp_error($write_result)) {
                    return $write_result;
                }
            }

            if ($seo_title !== '' || $seo_description !== '') {
                $seo_target_field = strpos($run_target_field, 'seo:') === 0 ? $run_target_field : 'seo:auto';
                $seo_payload = $this->build_seo_payload_for_write($seo_title, $seo_description);
                $write_result = UCG_Tokens::write_generated_value($post_id, $seo_target_field, $seo_payload, $write_context);
                if (is_wp_error($write_result)) {
                    return $write_result;
                }
            }

            return true;
        }

        protected function process_single_review_field_action($action_token) {
            $action_token = trim((string) $action_token);
            if ($action_token === '') {
                return new WP_Error('ucg_review_field_action_missing', __('Не выбрано действие для поля.', 'unicontent-ai-generator'));
            }

            $parts = explode('|', $action_token);
            if (count($parts) < 4) {
                return new WP_Error('ucg_review_field_action_invalid', __('Некорректный формат действия поля.', 'unicontent-ai-generator'));
            }

            $action = sanitize_key((string) $parts[0]);
            $item_id = (int) $parts[1];
            $scope = sanitize_key((string) $parts[2]);
            $field_index = (int) $parts[3];

            if (!in_array($action, array('approve', 'reject'), true)) {
                return new WP_Error('ucg_review_field_action_invalid', __('Некорректное действие для поля.', 'unicontent-ai-generator'));
            }
            if ($item_id <= 0 || !in_array($scope, array('ai', 'static'), true) || $field_index < 0) {
                return new WP_Error('ucg_review_field_action_invalid', __('Некорректный идентификатор поля.', 'unicontent-ai-generator'));
            }
            if ($scope === 'static') {
                return new WP_Error('ucg_review_field_action_static_not_supported', __('Static-поля применяются только через одобрение/отклонение всей карточки.', 'unicontent-ai-generator'));
            }

            $item = UCG_DB::get_run_item_with_run($item_id);
            if (!$item) {
                return new WP_Error('ucg_review_item_not_found', __('Элемент не найден.', 'unicontent-ai-generator'));
            }
            if ((string) $item['status'] !== 'generated') {
                return new WP_Error('ucg_review_item_not_generated', __('Элемент уже обработан.', 'unicontent-ai-generator'));
            }

            $generated_fields = $this->decode_generated_fields_payload(
                isset($item['generated_fields_json']) ? (string) $item['generated_fields_json'] : ''
            );
            $bucket_key = $scope === 'static' ? 'static_fields' : 'ai_fields';
            $bucket = isset($generated_fields[$bucket_key]) && is_array($generated_fields[$bucket_key])
                ? $generated_fields[$bucket_key]
                : array();

            if (!array_key_exists($field_index, $bucket) || !is_array($bucket[$field_index])) {
                return new WP_Error('ucg_review_field_not_found', __('Поле не найдено в результате генерации.', 'unicontent-ai-generator'));
            }

            $field = $bucket[$field_index];
            $field_status = sanitize_key(isset($field['status']) ? (string) $field['status'] : 'generated');
            if ($field_status !== '' && $field_status !== 'generated') {
                return new WP_Error('ucg_review_field_already_processed', __('Поле уже обработано.', 'unicontent-ai-generator'));
            }

            $run_id = isset($item['run_id']) ? (int) $item['run_id'] : 0;
            if ($action === 'approve') {
                $write_context = $this->get_write_context_for_run($run_id);
                $single_payload = array(
                    'ai_fields' => array(),
                    'static_fields' => array(),
                );
                $single_payload[$bucket_key][] = $field;
                $write_result = $this->write_generated_fields_for_review_item(
                    (int) $item['post_id'],
                    (string) $item['target_field'],
                    $single_payload,
                    $write_context
                );
                if (is_wp_error($write_result)) {
                    UCG_DB::update_run_item(
                        $item_id,
                        array(
                            'status' => 'failed',
                            'error_message' => $write_result->get_error_message(),
                        )
                    );
                    if ($run_id > 0) {
                        UCG_DB::recalculate_run_counters($run_id);
                    }
                    return $write_result;
                }
            }

            $generated_fields[$bucket_key][$field_index]['status'] = $action === 'approve' ? 'approved' : 'rejected';
            $next_item_status = $this->resolve_review_item_status_from_generated_fields($generated_fields);
            $update_data = array(
                'generated_fields_json' => wp_json_encode($generated_fields, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'status' => $next_item_status,
                'error_message' => '',
            );
            if ($next_item_status !== 'generated') {
                $update_data['reviewed_at'] = current_time('mysql', true);
            }

            UCG_DB::update_run_item($item_id, $update_data);
            if ($run_id > 0) {
                UCG_DB::recalculate_run_counters($run_id);
            }

            $notice = $action === 'approve'
                ? __('Поле одобрено.', 'unicontent-ai-generator')
                : __('Поле отклонено.', 'unicontent-ai-generator');
            if ($next_item_status === 'generated') {
                $notice .= ' ' . __('Элемент остаётся в проверке: есть необработанные поля.', 'unicontent-ai-generator');
            }

            return array(
                'type' => 'success',
                'notice' => $notice,
                'item_status' => $next_item_status,
            );
        }

        protected function resolve_review_item_status_from_generated_fields($generated_fields) {
            $generated_fields = is_array($generated_fields) ? $generated_fields : array();
            $reviewable_total = 0;
            $approved_total = 0;
            $rejected_total = 0;
            $has_pending = false;

            foreach (array('ai_fields', 'static_fields') as $bucket_key) {
                $bucket = isset($generated_fields[$bucket_key]) && is_array($generated_fields[$bucket_key])
                    ? $generated_fields[$bucket_key]
                    : array();
                foreach ($bucket as $field) {
                    if (!is_array($field)) {
                        continue;
                    }
                    $status = sanitize_key(isset($field['status']) ? (string) $field['status'] : 'generated');
                    if ($status === '') {
                        $status = 'generated';
                    }
                    if ($status === 'skipped' || $status === 'disabled') {
                        continue;
                    }
                    if ($status === 'failed') {
                        return 'failed';
                    }
                    $reviewable_total++;
                    if ($status === 'generated' || $status === 'queued' || $status === 'processing') {
                        $has_pending = true;
                        continue;
                    }
                    if ($status === 'approved') {
                        $approved_total++;
                        continue;
                    }
                    if ($status === 'rejected') {
                        $rejected_total++;
                        continue;
                    }
                    $has_pending = true;
                }
            }

            if ($has_pending) {
                return 'generated';
            }
            if ($reviewable_total <= 0) {
                return 'approved';
            }
            if ($approved_total > 0) {
                return 'approved';
            }
            if ($rejected_total > 0) {
                return 'rejected';
            }

            return 'generated';
        }

        protected function mark_generated_fields_review_status($generated_fields, $status) {
            $generated_fields = is_array($generated_fields) ? $generated_fields : array();
            $status = sanitize_key((string) $status);
            if (!in_array($status, array('approved', 'rejected'), true)) {
                $status = 'approved';
            }

            foreach (array('ai_fields', 'static_fields') as $bucket_key) {
                if (!isset($generated_fields[$bucket_key]) || !is_array($generated_fields[$bucket_key])) {
                    continue;
                }
                foreach ($generated_fields[$bucket_key] as $index => $field) {
                    if (!is_array($field)) {
                        continue;
                    }
                    $field_status = sanitize_key(isset($field['status']) ? (string) $field['status'] : 'generated');
                    if ($field_status === '' || $field_status === 'generated') {
                        $generated_fields[$bucket_key][$index]['status'] = $status;
                    }
                }
            }

            return $generated_fields;
        }

        protected function create_posts_for_generation_run($post_type, $count, $run_id = 0, $topics = array()) {
            $post_type = sanitize_key((string) $post_type);
            $count = max(1, min(1000, (int) $count));
            $run_id = max(0, (int) $run_id);
            $topics = $this->normalize_create_topics($topics, $count);
            if ($post_type === '' || !post_type_exists($post_type)) {
                return new WP_Error('ucg_invalid_post_type', __('Некорректный тип записи для создания новых элементов.', 'unicontent-ai-generator'));
            }

            $created_post_ids = array();
            $author_id = get_current_user_id();
            for ($index = 1; $index <= $count; $index++) {
                $topic_value = isset($topics[$index - 1]) ? sanitize_text_field((string) $topics[$index - 1]) : '';
                $post_data = array(
                    'post_type' => $post_type,
                    'post_status' => 'draft',
                    'post_title' => $topic_value,
                    'post_content' => '',
                    'post_excerpt' => '',
                );
                if ($author_id > 0) {
                    $post_data['post_author'] = $author_id;
                }

                $created_post_id = wp_insert_post($post_data, true);
                if (is_wp_error($created_post_id) || (int) $created_post_id <= 0) {
                    $this->cleanup_posts_created_for_generation_run($created_post_ids);
                    $error_message = is_wp_error($created_post_id)
                        ? $created_post_id->get_error_message()
                        : __('Не удалось создать запись.', 'unicontent-ai-generator');
                    return new WP_Error(
                        'ucg_create_new_posts_failed',
                        sprintf(
                            __('Не удалось подготовить новые записи (%1$d из %2$d). %3$s', 'unicontent-ai-generator'),
                            count($created_post_ids),
                            $count,
                            $error_message
                        )
                    );
                }

                $created_post_ids[] = (int) $created_post_id;
            }

            return $created_post_ids;
        }

        protected function cleanup_posts_created_for_generation_run($post_ids) {
            if (!is_array($post_ids)) {
                return;
            }
            foreach ($post_ids as $post_id) {
                $post_id = (int) $post_id;
                if ($post_id <= 0) {
                    continue;
                }
                wp_delete_post($post_id, true);
            }
        }

        protected function normalize_create_topics($value, $limit = 1000) {
            $limit = max(1, min(1000, (int) $limit));

            $source = array();
            if (is_array($value)) {
                $source = $value;
            } else {
                $raw = (string) $value;
                if ($raw !== '') {
                    $parts = preg_split('/\r\n|\r|\n/', $raw);
                    if (is_array($parts)) {
                        $source = $parts;
                    }
                }
            }

            if (empty($source)) {
                return array();
            }

            $normalized = array();
            foreach ($source as $item) {
                $line = sanitize_text_field(trim((string) $item));
                if ($line === '') {
                    continue;
                }
                $normalized[] = $line;
                if (count($normalized) >= $limit) {
                    break;
                }
            }

            return $normalized;
        }

        protected function parse_ids_json($json) {
            $json = (string) $json;
            $decoded = json_decode($json, true);
            if (!is_array($decoded)) {
                return array();
            }
            $ids = array_map('intval', $decoded);
            $ids = array_values(array_filter(array_unique($ids)));
            return $ids;
        }

        protected function validate_post_ids_for_type($ids, $post_type) {
            global $wpdb;

            $post_type = sanitize_key((string) $post_type);
            $ids = is_array($ids) ? array_map('intval', $ids) : array();
            $ids = array_values(array_filter(array_unique($ids)));
            if ($post_type === '' || empty($ids)) {
                return array();
            }

            $posts_table = $wpdb->posts;
            $placeholders = implode(',', array_fill(0, count($ids), '%d'));
            $sql = "SELECT ID FROM {$posts_table}
                    WHERE post_type = %s
                      AND post_status IN ('publish','draft','private','pending')
                      AND ID IN ({$placeholders})";
            $params = array_merge(array($post_type), $ids);
            $prepared = $wpdb->prepare($sql, $params);
            $valid_ids = $wpdb->get_col($prepared);
            if (!is_array($valid_ids)) {
                return array();
            }
            $valid_ids = array_map('intval', $valid_ids);
            $valid_ids = array_values(array_filter(array_unique($valid_ids)));
            return $valid_ids;
        }

        protected function collect_filtered_post_ids($post_type, $status_filter, $search, $max = 50000) {
            $post_type = sanitize_key((string) $post_type);
            $status_filter = $this->normalize_status_filter((string) $status_filter);
            $max = max(1, min(100000, (int) $max));

            $query_args = array(
                'post_type' => $post_type,
                'post_status' => $this->resolve_post_statuses($status_filter),
                'posts_per_page' => $max,
                'fields' => 'ids',
                'orderby' => 'ID',
                'order' => 'DESC',
                'no_found_rows' => true,
                'update_post_meta_cache' => false,
                'update_post_term_cache' => false,
                'suppress_filters' => true,
            );

            $search = trim((string) $search);
            if ($search !== '') {
                $query_args['s'] = $search;
            }

            $query = new WP_Query($query_args);
            if (empty($query->posts) || !is_array($query->posts)) {
                return array();
            }

            $ids = array_map('intval', $query->posts);
            $ids = array_values(array_filter(array_unique($ids)));
            return $ids;
        }

        protected function normalize_status_filter($status_filter) {
            $status_filter = sanitize_key((string) $status_filter);
            $allowed = array('all', 'publish', 'draft', 'private', 'pending');
            if (!in_array($status_filter, $allowed, true)) {
                return 'publish';
            }
            return $status_filter;
        }

        protected function resolve_post_statuses($status_filter) {
            if ($status_filter === 'all') {
                return array('publish', 'draft', 'private', 'pending');
            }
            return array($status_filter);
        }

        protected function get_text_length_options($force_refresh = false) {
            static $in_memory_cache = null;

            $fallback = array(
                'options' => array(
                    array('id' => 1, 'name' => __('Короткое', 'unicontent-ai-generator'), 'max_chars' => 500, 'credits_cost' => 1),
                    array('id' => 2, 'name' => __('Стандартное', 'unicontent-ai-generator'), 'max_chars' => 1500, 'credits_cost' => 3),
                    array('id' => 3, 'name' => __('Расширенное', 'unicontent-ai-generator'), 'max_chars' => 3000, 'credits_cost' => 6),
                    array('id' => 4, 'name' => __('Большое', 'unicontent-ai-generator'), 'max_chars' => 5000, 'credits_cost' => 10),
                ),
                'default_option_id' => 2,
                'hint' => __('Текст будет генерироваться с небольшим разбросом длины внутри выбранного диапазона, чтобы результаты выглядели естественнее.', 'unicontent-ai-generator'),
            );

            if (!$force_refresh && is_array($in_memory_cache)) {
                return $in_memory_cache;
            }

            if (!UCG_Settings::has_valid_api_key()) {
                $in_memory_cache = $fallback;
                return $fallback;
            }

            $transient_key = 'ucg_text_length_options_cache_v2';
            if (!$force_refresh) {
                $cached = get_transient($transient_key);
                if (is_array($cached) && !empty($cached['options']) && is_array($cached['options'])) {
                    $in_memory_cache = $cached;
                    return $cached;
                }
            }

            $client = new UCG_Api_Client();
            $response = $client->get_text_length_options();
            if (is_wp_error($response)) {
                $in_memory_cache = $fallback;
                return $fallback;
            }

            $options = isset($response['options']) && is_array($response['options']) ? $response['options'] : array();
            $normalized = array();
            $default_option_id = isset($response['default_option_id']) ? (int) $response['default_option_id'] : 0;
            foreach ($options as $option) {
                if (!is_array($option)) {
                    continue;
                }
                $id = isset($option['id']) ? (int) $option['id'] : 0;
                $name = isset($option['name']) ? trim((string) $option['name']) : '';
                $max_chars = isset($option['max_chars']) ? (int) $option['max_chars'] : 0;
                $credits_cost = isset($option['credits_cost']) ? (float) $option['credits_cost'] : 0.0;

                if ($id <= 0 || $name === '' || $max_chars <= 0 || $credits_cost <= 0) {
                    continue;
                }

                $normalized[] = array(
                    'id' => $id,
                    'name' => $name,
                    'max_chars' => $max_chars,
                    'credits_cost' => $credits_cost,
                );
            }

            if (empty($normalized)) {
                $in_memory_cache = $fallback;
                return $fallback;
            }

            if ($default_option_id <= 0) {
                $default_option_id = (int) $normalized[0]['id'];
            }

            $result = array(
                'options' => $normalized,
                'default_option_id' => $default_option_id,
                'hint' => isset($response['vary_length_hint']) && trim((string) $response['vary_length_hint']) !== ''
                    ? (string) $response['vary_length_hint']
                    : $fallback['hint'],
            );
            set_transient($transient_key, $result, 60);
            $in_memory_cache = $result;
            return $result;
        }

        protected function get_generation_models($scenario = self::DEFAULT_GENERATION_SCENARIO, $force_refresh = false) {
            static $in_memory_cache = array();

            $scenario = $this->normalize_generation_scenario($scenario);
            $required_output_modality = $scenario === 'image_generation' ? 'image' : 'text';
            $fallback = array(
                'scenario' => $scenario,
                'unit_label' => $this->scenario_unit_label($scenario),
                'default_model' => 'auto',
                'models' => array(
                    array(
                        'id' => 'auto',
                        'name' => __('По умолчанию', 'unicontent-ai-generator'),
                        'provider' => '',
                        'resolved_model' => '',
                        'developer' => '',
                        'developer_slug' => '',
                        'architecture_modality' => 'text->' . $required_output_modality,
                        'input_modalities' => array('text'),
                        'output_modalities' => array($required_output_modality),
                        'supported_parameters' => array(),
                        'context_length' => 0,
                        'is_default' => true,
                        'multiplier' => 1.0,
                        'estimated_credits_by_length' => array(),
                    ),
                ),
            );

            $cache_key = $scenario;
            if (!$force_refresh && isset($in_memory_cache[$cache_key]) && is_array($in_memory_cache[$cache_key])) {
                return $in_memory_cache[$cache_key];
            }

            if (!UCG_Settings::has_valid_api_key()) {
                $in_memory_cache[$cache_key] = $fallback;
                return $fallback;
            }

            $transient_key = 'ucg_generation_models_cache_v2_' . $cache_key;
            if (!$force_refresh) {
                $cached = get_transient($transient_key);
                if (is_array($cached) && !empty($cached['models']) && is_array($cached['models'])) {
                    $in_memory_cache[$cache_key] = $cached;
                    return $cached;
                }
            }

            $client = new UCG_Api_Client();
            $response = $client->get_generation_models($scenario);
            if (is_wp_error($response)) {
                $in_memory_cache[$cache_key] = $fallback;
                return $fallback;
            }

            $models = isset($response['models']) && is_array($response['models']) ? $response['models'] : array();
            $normalized_models = array();
            foreach ($models as $item) {
                if (!is_array($item)) {
                    continue;
                }
                $id = $this->normalize_model_identifier(isset($item['id']) ? (string) $item['id'] : '');
                $name = isset($item['name']) ? trim((string) $item['name']) : '';
                if ($id === '' || $name === '') {
                    continue;
                }

                $estimated = isset($item['estimated_credits_by_length']) && is_array($item['estimated_credits_by_length'])
                    ? $item['estimated_credits_by_length']
                    : array();
                $estimated_normalized = array();
                foreach ($estimated as $length_id => $credits) {
                    $estimated_normalized[(string) $length_id] = max(0.0, (float) $credits);
                }

                $input_modalities = $this->normalize_model_modalities(
                    isset($item['input_modalities']) ? $item['input_modalities'] : array('text'),
                    array('text')
                );
                $output_modalities = $this->normalize_model_modalities(
                    isset($item['output_modalities']) ? $item['output_modalities'] : array('text'),
                    array('text')
                );
                $supported_parameters = $this->normalize_model_modalities(
                    isset($item['supported_parameters']) ? $item['supported_parameters'] : array(),
                    array()
                );
                $architecture_modality = isset($item['architecture_modality']) ? trim((string) $item['architecture_modality']) : '';
                if ($architecture_modality === '') {
                    $architecture_modality = implode('+', $input_modalities) . '->' . implode('+', $output_modalities);
                }

                $normalized_models[] = array(
                    'id' => $id,
                    'name' => $name,
                    'provider' => isset($item['provider']) ? (string) $item['provider'] : '',
                    'resolved_model' => isset($item['resolved_model']) ? (string) $item['resolved_model'] : '',
                    'developer' => isset($item['developer']) ? (string) $item['developer'] : '',
                    'developer_slug' => isset($item['developer_slug']) ? sanitize_key((string) $item['developer_slug']) : '',
                    'architecture_modality' => $architecture_modality,
                    'input_modalities' => $input_modalities,
                    'output_modalities' => $output_modalities,
                    'supported_parameters' => $supported_parameters,
                    'context_length' => isset($item['context_length']) ? max(0, (int) $item['context_length']) : 0,
                    'is_default' => !empty($item['is_default']),
                    'multiplier' => isset($item['multiplier']) ? max(0.1, (float) $item['multiplier']) : 1.0,
                    'estimated_credits_by_length' => $estimated_normalized,
                );
            }

            if (empty($normalized_models)) {
                $in_memory_cache[$cache_key] = $fallback;
                return $fallback;
            }

            $default_model = $this->normalize_model_identifier(isset($response['default_model']) ? (string) $response['default_model'] : 'auto');
            if ($default_model === '') {
                $default_model = 'auto';
            }

            $result = array(
                'scenario' => $scenario,
                'unit_label' => isset($response['unit_label']) && trim((string) $response['unit_label']) !== ''
                    ? (string) $response['unit_label']
                    : $this->scenario_unit_label($scenario),
                'default_model' => $default_model,
                'models' => $normalized_models,
            );

            set_transient($transient_key, $result, 60);
            $in_memory_cache[$cache_key] = $result;
            return $result;
        }

        protected function scenario_unit_label($scenario) {
            $scenario = $this->normalize_generation_scenario($scenario);
            $map = array(
                'field_update' => __('1 поле', 'unicontent-ai-generator'),
                'seo_tags' => __('1 SEO-пакет', 'unicontent-ai-generator'),
                'post_fields' => __('1 набор полей записи', 'unicontent-ai-generator'),
                'product_fields' => __('1 набор полей товара', 'unicontent-ai-generator'),
                'comments' => __('1 комментарий', 'unicontent-ai-generator'),
                'woo_reviews' => __('1 отзыв', 'unicontent-ai-generator'),
                'image_generation' => __('1 изображение', 'unicontent-ai-generator'),
            );

            if (isset($map[$scenario])) {
                return $map[$scenario];
            }
            return __('1 единица', 'unicontent-ai-generator');
        }

        protected function resolve_operation_type_for_scenario($scenario, $fallback = 'text') {
            $scenario = $this->normalize_generation_scenario($scenario);
            if ($scenario === 'seo_tags') {
                return 'seo_tags';
            }

            $fallback = sanitize_key((string) $fallback);
            if (!in_array($fallback, array('text', 'seo_tags', 'long_text', 'image'), true)) {
                $fallback = 'text';
            }
            return $fallback;
        }

        protected function clear_generation_model_caches() {
            foreach ($this->get_generation_scenario_options() as $scenario_item) {
                if (!is_array($scenario_item) || empty($scenario_item['value'])) {
                    continue;
                }
                $scenario_key = sanitize_key((string) $scenario_item['value']);
                if ($scenario_key === '') {
                    continue;
                }
                delete_transient('ucg_generation_models_cache_v2_' . $scenario_key);
            }
        }

        protected function get_prompt_library($force_refresh = false, $query_args = array()) {
            static $in_memory_cache = array();

            $fallback = array(
                'categories' => array(),
                'types' => array(),
                'prompts' => array(),
            );

            $query_args = is_array($query_args) ? $query_args : array();
            $normalized_args = array();
            if (!empty($query_args['category_slug'])) {
                $normalized_args['category_slug'] = sanitize_key((string) $query_args['category_slug']);
            }
            if (!empty($query_args['type_slug'])) {
                $normalized_args['type_slug'] = sanitize_key((string) $query_args['type_slug']);
            }
            if (!empty($query_args['search'])) {
                $normalized_args['search'] = sanitize_text_field((string) $query_args['search']);
            }
            if (!empty($query_args['limit'])) {
                $normalized_args['limit'] = max(1, min(1000, (int) $query_args['limit']));
            }

            $cache_key = md5(wp_json_encode($normalized_args));
            if (!$force_refresh && isset($in_memory_cache[$cache_key]) && is_array($in_memory_cache[$cache_key])) {
                $this->last_prompt_library_error = isset($in_memory_cache[$cache_key]['error']) ? (string) $in_memory_cache[$cache_key]['error'] : '';
                return $in_memory_cache[$cache_key];
            }

            if (!UCG_Settings::has_valid_api_key()) {
                $message = __('Сначала добавьте и проверьте API ключ.', 'unicontent-ai-generator');
                $result = $fallback;
                $result['error'] = $message;
                $in_memory_cache[$cache_key] = $result;
                $this->last_prompt_library_error = $message;
                return $result;
            }

            $transient_key = 'ucg_prompt_library_cache_v1_' . $cache_key;
            if (!$force_refresh) {
                $cached = get_transient($transient_key);
                if (is_array($cached)) {
                    $in_memory_cache[$cache_key] = wp_parse_args($cached, $fallback);
                    $this->last_prompt_library_error = isset($in_memory_cache[$cache_key]['error']) ? (string) $in_memory_cache[$cache_key]['error'] : '';
                    return $in_memory_cache[$cache_key];
                }
            }

            $client = new UCG_Api_Client();
            $request_args = array('limit' => 1000);
            if (!empty($normalized_args)) {
                $request_args = array_merge($request_args, $normalized_args);
            }
            $response = $client->get_prompt_library($request_args);
            if (is_wp_error($response)) {
                $message = (string) $response->get_error_message();
                $result = $fallback;
                $result['error'] = $message;
                $in_memory_cache[$cache_key] = $result;
                $this->last_prompt_library_error = $message;
                return $result;
            }

            $result = array(
                'categories' => isset($response['categories']) && is_array($response['categories']) ? $response['categories'] : array(),
                'types' => isset($response['types']) && is_array($response['types']) ? $response['types'] : array(),
                'prompts' => isset($response['prompts']) && is_array($response['prompts']) ? $response['prompts'] : array(),
                'error' => '',
            );

            set_transient($transient_key, $result, 60);
            $in_memory_cache[$cache_key] = $result;
            $this->last_prompt_library_error = '';
            return $result;
        }

        protected function get_last_prompt_library_error() {
            return (string) $this->last_prompt_library_error;
        }

        protected function get_ready_wordpress_prompts($force_refresh = false) {
            $library = $this->get_prompt_library(
                $force_refresh,
                array(
                    'category_slug' => 'wordpress',
                    'limit' => 1000,
                )
            );
            $prompts = isset($library['prompts']) && is_array($library['prompts']) ? $library['prompts'] : array();
            if (empty($prompts)) {
                return array();
            }

            $result = array();
            foreach ($prompts as $prompt) {
                if (!is_array($prompt)) {
                    continue;
                }

                $category = isset($prompt['category']) && is_array($prompt['category']) ? $prompt['category'] : array();
                $category_slug = isset($category['slug']) ? sanitize_key((string) $category['slug']) : '';
                if ($category_slug !== 'wordpress') {
                    continue;
                }

                $id = isset($prompt['id']) ? (int) $prompt['id'] : 0;
                $name = isset($prompt['name']) ? trim((string) $prompt['name']) : '';
                $slug = isset($prompt['slug']) ? sanitize_key((string) $prompt['slug']) : '';
                $summary = isset($prompt['summary']) ? trim((string) $prompt['summary']) : '';
                $body = isset($prompt['body']) ? (string) $prompt['body'] : '';

                if ($id <= 0 || $name === '' || trim($body) === '') {
                    continue;
                }

                $type = isset($prompt['type']) && is_array($prompt['type']) ? $prompt['type'] : array();
                $type_id = isset($type['id']) ? (int) $type['id'] : 0;
                $type_name = isset($type['name']) ? trim((string) $type['name']) : '';
                $type_slug = isset($type['slug']) ? sanitize_key((string) $type['slug']) : '';

                $result[] = array(
                    'id' => $id,
                    'name' => $name,
                    'slug' => $slug,
                    'summary' => $summary,
                    'body' => $body,
                    'category' => array(
                        'id' => isset($category['id']) ? (int) $category['id'] : 0,
                        'name' => isset($category['name']) ? (string) $category['name'] : '',
                        'slug' => $category_slug,
                    ),
                    'type' => array(
                        'id' => $type_id,
                        'name' => $type_name,
                        'slug' => $type_slug,
                    ),
                );
            }

            return $result;
        }

        protected function get_prompt_type_filters($prompts) {
            $prompts = is_array($prompts) ? $prompts : array();
            $result = array();
            $seen = array();

            foreach ($prompts as $prompt) {
                if (!is_array($prompt)) {
                    continue;
                }

                $type = isset($prompt['type']) && is_array($prompt['type']) ? $prompt['type'] : array();
                $type_slug = isset($type['slug']) ? sanitize_key((string) $type['slug']) : '';
                $type_name = isset($type['name']) ? trim((string) $type['name']) : '';
                if ($type_slug === '' || $type_name === '' || isset($seen[$type_slug])) {
                    continue;
                }

                $seen[$type_slug] = true;
                $result[] = array(
                    'slug' => $type_slug,
                    'name' => $type_name,
                );
            }

            usort(
                $result,
                function ($a, $b) {
                    return strcmp((string) $a['name'], (string) $b['name']);
                }
            );

            return $result;
        }

        protected function find_ready_prompt_by_id($prompt_id, $force_refresh = false) {
            $prompt_id = (int) $prompt_id;
            if ($prompt_id <= 0) {
                return null;
            }

            $prompts = $this->get_ready_wordpress_prompts($force_refresh);
            foreach ($prompts as $prompt) {
                if (!is_array($prompt)) {
                    continue;
                }
                if ((int) (isset($prompt['id']) ? $prompt['id'] : 0) === $prompt_id) {
                    return $prompt;
                }
            }

            return null;
        }

        protected function resolve_ready_template_scenario($prompt, $body) {
            $prompt = is_array($prompt) ? $prompt : array();
            $body = (string) $body;

            $decoded = json_decode($body, true);
            if (is_array($decoded)) {
                $scenario_from_payload = isset($decoded['scenario']) ? sanitize_key((string) $decoded['scenario']) : '';
                if ($scenario_from_payload !== '') {
                    return $this->normalize_generation_scenario($scenario_from_payload);
                }

                $fields = isset($decoded['fields']) && is_array($decoded['fields']) ? $decoded['fields'] : array();
                if (!empty($fields)) {
                    $has_seo_targets = false;
                    $has_non_seo_targets = false;
                    $has_product_targets = false;
                    foreach ($fields as $field) {
                        if (!is_array($field)) {
                            continue;
                        }
                        $target = $this->normalize_generation_target_field(
                            isset($field['target_field']) ? (string) $field['target_field'] : ''
                        );
                        if ($target === 'seo_field:title' || $target === 'seo_field:description') {
                            $has_seo_targets = true;
                            continue;
                        }
                        if (
                            $target === 'tax:product_cat'
                            || $target === 'tax:product_tag'
                            || $target === 'meta:_stock_status'
                            || $target === 'meta:_stock'
                            || $target === 'meta:_visibility'
                        ) {
                            $has_product_targets = true;
                        }
                        $has_non_seo_targets = true;
                    }
                    if ($has_seo_targets && !$has_non_seo_targets) {
                        return $this->normalize_generation_scenario('seo_tags');
                    }
                    if ($has_product_targets) {
                        return $this->normalize_generation_scenario('product_fields', 'product');
                    }
                    return $this->normalize_generation_scenario('post_fields');
                }
            }

            $type = isset($prompt['type']) && is_array($prompt['type']) ? $prompt['type'] : array();
            $type_slug = isset($type['slug']) ? sanitize_key((string) $type['slug']) : '';
            if ($type_slug !== '') {
                if (strpos($type_slug, 'seo') !== false) {
                    return $this->normalize_generation_scenario('seo_tags');
                }
                if (strpos($type_slug, 'multi') !== false) {
                    if (strpos($type_slug, 'product') !== false || strpos($type_slug, 'woo') !== false) {
                        return $this->normalize_generation_scenario('product_fields', 'product');
                    }
                    return $this->normalize_generation_scenario('post_fields');
                }
                if (strpos($type_slug, 'woo') !== false && strpos($type_slug, 'review') !== false) {
                    return $this->normalize_generation_scenario('woo_reviews');
                }
                if (strpos($type_slug, 'comment') !== false) {
                    return $this->normalize_generation_scenario('comments');
                }
            }

            return self::DEFAULT_GENERATION_SCENARIO;
        }

        protected function prepare_ready_template_body_for_install($scenario, $body) {
            $scenario = $this->normalize_generation_scenario($scenario);
            $body = (string) $body;
            $trimmed = trim($body);
            if ($trimmed === '') {
                return '';
            }

            $decoded = json_decode($body, true);
            if (is_array($decoded)) {
                $payload = $this->decode_template_payload($scenario, $body);
                if (!empty($payload['upgraded_body'])) {
                    return (string) $payload['upgraded_body'];
                }
                return $body;
            }

            if ($scenario === 'seo_tags') {
                return $this->encode_template_payload($scenario, '', $trimmed, $trimmed);
            }
            if ($this->scenario_supports_multi_fields($scenario)) {
                return $this->encode_template_payload($scenario, $trimmed, '', '');
            }

            return $trimmed;
        }

        protected function get_ready_template_installs() {
            $raw = get_option(self::READY_TEMPLATE_INSTALLS_OPTION, array());
            if (!is_array($raw)) {
                return array();
            }

            $result = array();
            foreach ($raw as $prompt_id => $item) {
                $normalized_prompt_id = (int) $prompt_id;
                if ($normalized_prompt_id <= 0 || !is_array($item)) {
                    continue;
                }

                $template_id = isset($item['template_id']) ? (int) $item['template_id'] : 0;
                if ($template_id <= 0) {
                    continue;
                }

                $result[(string) $normalized_prompt_id] = array(
                    'template_id' => $template_id,
                    'post_type' => isset($item['post_type']) ? sanitize_key((string) $item['post_type']) : '',
                    'prompt_slug' => isset($item['prompt_slug']) ? sanitize_key((string) $item['prompt_slug']) : '',
                    'updated_at' => isset($item['updated_at']) ? (string) $item['updated_at'] : '',
                );
            }

            return $result;
        }

        protected function save_ready_template_installs($installs) {
            $installs = is_array($installs) ? $installs : array();
            $normalized = array();

            foreach ($installs as $prompt_id => $item) {
                $normalized_prompt_id = (int) $prompt_id;
                if ($normalized_prompt_id <= 0 || !is_array($item)) {
                    continue;
                }

                $template_id = isset($item['template_id']) ? (int) $item['template_id'] : 0;
                if ($template_id <= 0) {
                    continue;
                }

                $normalized[(string) $normalized_prompt_id] = array(
                    'template_id' => $template_id,
                    'post_type' => isset($item['post_type']) ? sanitize_key((string) $item['post_type']) : '',
                    'prompt_slug' => isset($item['prompt_slug']) ? sanitize_key((string) $item['prompt_slug']) : '',
                    'updated_at' => isset($item['updated_at']) ? (string) $item['updated_at'] : '',
                );
            }

            update_option(self::READY_TEMPLATE_INSTALLS_OPTION, $normalized, false);
            return $normalized;
        }

        protected function get_ready_installed_templates_map() {
            $installs = $this->get_ready_template_installs();
            if (empty($installs)) {
                return array();
            }

            $result = array();
            $dirty = false;

            foreach ($installs as $prompt_key => $item) {
                $template_id = isset($item['template_id']) ? (int) $item['template_id'] : 0;
                if ($template_id <= 0) {
                    $dirty = true;
                    continue;
                }

                $template = UCG_DB::get_template($template_id);
                if (!$template) {
                    unset($installs[$prompt_key]);
                    $dirty = true;
                    continue;
                }

                $result[$prompt_key] = array(
                    'template_id' => $template_id,
                    'name' => isset($template['name']) ? (string) $template['name'] : '',
                    'post_type' => isset($template['post_type']) ? (string) $template['post_type'] : '',
                );
            }

            if ($dirty) {
                $this->save_ready_template_installs($installs);
            }

            return $result;
        }

        protected function resolve_length_option_id($candidate_id, $options = null) {
            $candidate_id = (int) $candidate_id;
            if (!is_array($options)) {
                $data = $this->get_text_length_options();
                $options = isset($data['options']) && is_array($data['options']) ? $data['options'] : array();
            }

            $first_id = 0;
            foreach ($options as $option) {
                if (!is_array($option)) {
                    continue;
                }
                $id = isset($option['id']) ? (int) $option['id'] : 0;
                if ($id <= 0) {
                    continue;
                }
                if ($first_id <= 0) {
                    $first_id = $id;
                }
                if ($candidate_id > 0 && $id === $candidate_id) {
                    return $id;
                }
            }

            if ($candidate_id > 0) {
                return 0;
            }
            return $first_id;
        }

        protected function normalize_model_identifier($value) {
            $value = trim((string) $value);
            if ($value === '') {
                return '';
            }

            $value = sanitize_text_field($value);
            $value = preg_replace('/[^A-Za-z0-9._:\\/-]/', '', (string) $value);
            if (!is_string($value)) {
                return '';
            }
            return trim($value);
        }

        protected function normalize_model_modalities($value, $fallback = array()) {
            $fallback_values = array();
            if (is_array($fallback)) {
                foreach ($fallback as $fallback_item) {
                    $fallback_value = sanitize_key((string) $fallback_item);
                    if ($fallback_value !== '' && !in_array($fallback_value, $fallback_values, true)) {
                        $fallback_values[] = $fallback_value;
                    }
                }
            }

            if (is_string($value)) {
                $value = explode(',', $value);
            }
            if (!is_array($value)) {
                return $fallback_values;
            }

            $result = array();
            foreach ($value as $item) {
                $item_value = strtolower(trim((string) $item));
                $item_value = preg_replace('/[^a-z0-9_\\-+]/', '', $item_value);
                if (!is_string($item_value) || $item_value === '' || in_array($item_value, $result, true)) {
                    continue;
                }
                $result[] = $item_value;
            }

            if (!empty($result)) {
                return $result;
            }
            return $fallback_values;
        }

        protected function scenario_supports_multi_fields($scenario) {
            $scenario = sanitize_key((string) $scenario);
            return $scenario === 'post_fields' || $scenario === 'product_fields' || $scenario === 'image_generation';
        }

        protected function scenario_supports_create_new_mode($scenario) {
            $scenario = sanitize_key((string) $scenario);
            return $scenario === 'post_fields' || $scenario === 'product_fields';
        }

        protected function scenario_requires_product_post_type($scenario) {
            $scenario = sanitize_key((string) $scenario);
            return $scenario === 'product_fields';
        }

        protected function scenario_supports_publish_date_range($scenario) {
            $scenario = sanitize_key((string) $scenario);
            return $scenario === 'comments' || $scenario === 'woo_reviews';
        }

        protected function get_write_context_for_run($run_id) {
            $run_id = (int) $run_id;
            if ($run_id <= 0) {
                return array();
            }

            $run = UCG_DB::get_run($run_id);
            if (!is_array($run)) {
                return array();
            }

            $options_json = isset($run['options_json']) ? (string) $run['options_json'] : '';
            if ($options_json === '') {
                return array();
            }

            $options = json_decode($options_json, true);
            if (!is_array($options)) {
                return array();
            }

            $scenario = isset($options['scenario']) ? sanitize_key((string) $options['scenario']) : self::DEFAULT_GENERATION_SCENARIO;
            if (!$this->scenario_supports_publish_date_range($scenario)) {
                return array();
            }

            $date_range = $this->normalize_publish_date_range(
                isset($options['publish_date_from']) ? (string) $options['publish_date_from'] : '',
                isset($options['publish_date_to']) ? (string) $options['publish_date_to'] : ''
            );
            if (is_wp_error($date_range)) {
                return array();
            }

            $date_from = isset($date_range['from']) ? (string) $date_range['from'] : '';
            $date_to = isset($date_range['to']) ? (string) $date_range['to'] : '';
            if ($date_from === '' || $date_to === '') {
                return array();
            }

            return array(
                'publish_date_from' => $date_from,
                'publish_date_to' => $date_to,
            );
        }

        protected function normalize_publish_date_range($raw_from, $raw_to) {
            $raw_from = trim((string) $raw_from);
            $raw_to = trim((string) $raw_to);

            $date_from = $this->normalize_publish_date_value($raw_from);
            $date_to = $this->normalize_publish_date_value($raw_to);

            if ($raw_from !== '' && $date_from === '') {
                return new WP_Error('ucg_invalid_publish_date_from', __('Некорректная дата "от". Используйте формат YYYY-MM-DD.', 'unicontent-ai-generator'));
            }
            if ($raw_to !== '' && $date_to === '') {
                return new WP_Error('ucg_invalid_publish_date_to', __('Некорректная дата "до". Используйте формат YYYY-MM-DD.', 'unicontent-ai-generator'));
            }

            if ($date_from !== '' && $date_to === '') {
                $date_to = $date_from;
            } elseif ($date_to !== '' && $date_from === '') {
                $date_from = $date_to;
            }

            if ($date_from !== '' && $date_to !== '' && strcmp($date_from, $date_to) > 0) {
                return new WP_Error('ucg_invalid_publish_date_range', __('Дата "от" не может быть больше даты "до".', 'unicontent-ai-generator'));
            }

            return array(
                'from' => $date_from,
                'to' => $date_to,
            );
        }

        protected function normalize_publish_date_value($value) {
            $value = trim((string) $value);
            if ($value === '') {
                return '';
            }

            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
                return '';
            }

            return $value;
        }

        protected function build_menu_counter_badge($count) {
            $count = max(0, (int) $count);
            if ($count <= 0) {
                return '';
            }

            $safe_count = min(999, $count);
            return ' <span class="awaiting-mod count-' . $safe_count . '"><span class="pending-count">' . $safe_count . '</span></span>';
        }

        protected function get_request_string($source, $key, $default = '') {
            if (!is_array($source) || !array_key_exists($key, $source)) {
                return (string) $default;
            }

            $value = wp_unslash($source[$key]);
            if (is_array($value) || is_object($value)) {
                return (string) $default;
            }

            return (string) $value;
        }

        protected function get_request_int($source, $key, $default = 0) {
            $value = $this->get_request_string($source, $key, (string) $default);
            return (int) $value;
        }

        protected function guard_admin_post($nonce_action) {
            if (!current_user_can('manage_options')) {
                wp_die(esc_html__('Доступ запрещен.', 'unicontent-ai-generator'));
            }

            check_admin_referer($nonce_action);
        }

        protected function guard_ajax() {
            check_ajax_referer('ucg_admin_nonce', 'nonce');
            if (!current_user_can('manage_options')) {
                wp_send_json_error(array('message' => __('Доступ запрещен.', 'unicontent-ai-generator')));
            }
        }

        protected function truncate_log_message($message, $max_length = 180) {
            $message = trim((string) $message);
            if ($message === '') {
                return '';
            }

            $message = preg_replace('/\s+/u', ' ', $message);
            if (!is_string($message)) {
                return '';
            }

            $max_length = max(40, (int) $max_length);
            if (function_exists('mb_strlen') && function_exists('mb_substr')) {
                if (mb_strlen($message, 'UTF-8') <= $max_length) {
                    return $message;
                }
                return mb_substr($message, 0, $max_length, 'UTF-8') . '…';
            }

            if (strlen($message) <= $max_length) {
                return $message;
            }

            return substr($message, 0, $max_length) . '…';
        }

        protected function build_logs_diagnostics_snapshot() {
            $settings = UCG_Settings::get();
            $masked_key = UCG_Settings::get_masked_api_key();

            $plugins = array();
            if (function_exists('get_plugins')) {
                $all = get_plugins();
                if (is_array($all)) {
                    foreach ($all as $file => $data) {
                        if (empty($data['Name'])) {
                            continue;
                        }
                        $plugins[] = array(
                            'file' => (string) $file,
                            'name' => isset($data['Name']) ? (string) $data['Name'] : '',
                            'version' => isset($data['Version']) ? (string) $data['Version'] : '',
                            'active' => is_plugin_active($file) ? 1 : 0,
                        );
                    }
                }
            }

            return array(
                'plugin_version' => defined('UCG_VERSION') ? (string) UCG_VERSION : '',
                'wp_version' => function_exists('get_bloginfo') ? (string) get_bloginfo('version') : '',
                'php_version' => function_exists('phpversion') ? (string) phpversion() : '',
                'site_url' => function_exists('site_url') ? (string) site_url() : '',
                'home_url' => function_exists('home_url') ? (string) home_url() : '',
                'timezone' => function_exists('wp_timezone_string') ? (string) wp_timezone_string() : '',
                'object_cache' => function_exists('wp_using_ext_object_cache') ? (wp_using_ext_object_cache() ? 1 : 0) : 0,
                'ucg_settings' => array(
                    'api_base_url' => isset($settings['api_base_url']) ? (string) $settings['api_base_url'] : '',
                    'api_key_masked' => $masked_key,
                    'api_key_verified' => !empty($settings['api_key_verified']) ? 1 : 0,
                    'request_timeout' => isset($settings['request_timeout']) ? (int) $settings['request_timeout'] : 0,
                    'batch_size' => isset($settings['batch_size']) ? (int) $settings['batch_size'] : 0,
                    'generation_mode' => isset($settings['generation_mode']) ? (string) $settings['generation_mode'] : '',
                    'max_tokens' => isset($settings['max_tokens']) ? (int) $settings['max_tokens'] : 0,
                    'credits_cache_ttl' => isset($settings['credits_cache_ttl']) ? (int) $settings['credits_cache_ttl'] : 0,
                    'logs_keep_latest' => isset($settings['logs_keep_latest']) ? (int) $settings['logs_keep_latest'] : 0,
                    'logs_keep_days' => isset($settings['logs_keep_days']) ? (int) $settings['logs_keep_days'] : 0,
                ),
                'plugins' => $plugins,
            );
        }

        protected function resolve_ready_templates_redirect_page($source) {
            $page_slug = sanitize_key($this->get_request_string($source, 'redirect_page', 'ucg-ready-templates'));
            if (!in_array($page_slug, array('ucg-ready-templates', 'ucg-templates'), true)) {
                $page_slug = 'ucg-ready-templates';
            }
            return $page_slug;
        }

        protected function redirect_ready_templates_notice($page_slug, $message, $type = 'success', $post_type = '') {
            $extra_args = array();
            if ($page_slug === 'ucg-ready-templates') {
                $post_type = sanitize_key((string) $post_type);
                if ($post_type !== '' && post_type_exists($post_type)) {
                    $extra_args['post_type'] = $post_type;
                }
            }
            $this->redirect_with_notice($page_slug, $message, $type, $extra_args);
        }

        protected function redirect_with_notice($page_slug, $message, $type = 'success', $extra_args = array()) {
            $query_args = array_merge(
                array(
                    'page' => $page_slug,
                    self::NOTICE_QUERY => (string) $message,
                    self::NOTICE_TYPE_QUERY => sanitize_key((string) $type),
                ),
                is_array($extra_args) ? $extra_args : array()
            );
            $url = add_query_arg($query_args, admin_url('admin.php'));
            wp_safe_redirect($url);
            exit;
        }
    }
}
