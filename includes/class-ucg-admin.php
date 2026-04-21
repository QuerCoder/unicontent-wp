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
            add_action('wp_ajax_ucg_wizard_schema', array($this, 'ajax_wizard_schema'));
            add_action('wp_ajax_ucg_wizard_preview', array($this, 'ajax_wizard_preview'));
            add_action('wp_ajax_ucg_wizard_load_template', array($this, 'ajax_wizard_load_template'));
            add_action('wp_ajax_ucg_wizard_create_run', array($this, 'ajax_wizard_create_run'));
            add_action('wp_ajax_ucg_run_status', array($this, 'ajax_run_status'));
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
            wp_enqueue_style('ucg-tom-select', UCG_PLUGIN_URL . 'assets/vendor/tom-select/tom-select.css', array(), UCG_VERSION);
            wp_enqueue_script('ucg-tom-select', UCG_PLUGIN_URL . 'assets/vendor/tom-select/tom-select.complete.min.js', array(), UCG_VERSION, true);
            wp_enqueue_style('ucg-admin', UCG_PLUGIN_URL . 'assets/admin.css', array(), UCG_VERSION);
            wp_enqueue_script('ucg-admin', UCG_PLUGIN_URL . 'assets/admin.js', $script_deps, UCG_VERSION, true);

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

            $edit_template_id = $this->get_request_int($_GET, 'edit', 0);
            $editing_template = $edit_template_id > 0 ? UCG_DB::get_template($edit_template_id) : null;
            if ($editing_template && !empty($editing_template['post_type'])) {
                $selected_post_type = sanitize_key((string) $editing_template['post_type']);
            }
            $template_scenario_options = $this->get_generation_scenario_options();
            $editing_template_scenario = self::DEFAULT_GENERATION_SCENARIO;
            if ($editing_template && !empty($editing_template['scenario'])) {
                $editing_template_scenario = $this->normalize_generation_scenario((string) $editing_template['scenario']);
            }
            $editing_template_payload = $this->decode_template_payload(
                $editing_template_scenario,
                $editing_template && isset($editing_template['body']) ? (string) $editing_template['body'] : ''
            );
            $editing_base_prompt = isset($editing_template_payload['base_prompt']) ? (string) $editing_template_payload['base_prompt'] : '';
            $editing_prompt_blocks = $this->build_editor_prompt_blocks($editing_template_scenario, $editing_template_payload);

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
                $this->get_request_string($_GET, 'scenario', self::DEFAULT_GENERATION_SCENARIO)
            );

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

        public function handle_save_template() {
            $this->guard_admin_post('ucg_save_template');

            $template_id = $this->get_request_int($_POST, 'template_id', 0);
            $name = sanitize_text_field($this->get_request_string($_POST, 'name', ''));
            $post_type = sanitize_key($this->get_request_string($_POST, 'post_type', ''));
            $scenario = $this->normalize_generation_scenario(
                $this->get_request_string($_POST, 'scenario', self::DEFAULT_GENERATION_SCENARIO)
            );
            $base_prompt = sanitize_textarea_field($this->get_request_string($_POST, 'base_prompt', ''));
            $prompt_blocks = $this->parse_prompt_blocks_from_request($_POST, $scenario);
            $derived_prompts = $this->derive_template_prompts_from_blocks($scenario, $base_prompt, $prompt_blocks);
            $body = isset($derived_prompts['body']) ? (string) $derived_prompts['body'] : '';
            $seo_title_prompt = isset($derived_prompts['seo_title_prompt']) ? (string) $derived_prompts['seo_title_prompt'] : '';
            $seo_description_prompt = isset($derived_prompts['seo_description_prompt']) ? (string) $derived_prompts['seo_description_prompt'] : '';
            $is_default = !empty($_POST['is_default']) ? 1 : 0;

            if ($name === '' || $post_type === '' || !post_type_exists($post_type)) {
                $this->redirect_with_notice('ucg-templates', __('Заполните название и post type.', 'unicontent-ai-generator'), 'error');
            }

            if ($scenario === 'seo_tags') {
                if (count($prompt_blocks) < 2 || trim($seo_title_prompt) === '' || trim($seo_description_prompt) === '') {
                    $this->redirect_with_notice('ucg-templates', __('Для SEO шаблона нужны блоки title и description.', 'unicontent-ai-generator'), 'error');
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
                $base_prompt
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
                        $body,
                        !empty($existing_template['is_default']) ? 1 : 0,
                        0,
                        0
                    );
                    if ($updated) {
                        $template_id = $installed_template_id;
                    }
                }
            }

            if ($template_id <= 0) {
                $template_id = UCG_DB::create_template($name, $post_type, $body, 0, 0, 0);
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

            $action = sanitize_key($this->get_request_string($_POST, 'bulk_action', ''));
            if (!in_array($action, array('approve', 'reject'), true)) {
                $this->redirect_with_notice('ucg-review', __('Выберите действие: одобрить или отклонить.', 'unicontent-ai-generator'), 'error');
            }

            $raw_ids = isset($_POST['item_ids']) ? (array) $_POST['item_ids'] : array();
            $item_ids = array();
            foreach ($raw_ids as $raw_id) {
                $item_id = (int) $raw_id;
                if ($item_id > 0) {
                    $item_ids[] = $item_id;
                }
            }
            $item_ids = array_values(array_unique($item_ids));

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
                    $write_result = UCG_Tokens::write_generated_value(
                        (int) $item['post_id'],
                        (string) $item['target_field'],
                        (string) $item['generated_text'],
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
                        $failed++;
                        continue;
                    }

                    UCG_DB::update_run_item(
                        $item_id,
                        array(
                            'status' => 'approved',
                            'reviewed_at' => current_time('mysql', true),
                            'error_message' => '',
                        )
                    );
                    $success++;
                    continue;
                }

                UCG_DB::update_run_item(
                    $item_id,
                    array(
                        'status' => 'rejected',
                        'reviewed_at' => current_time('mysql', true),
                    )
                );
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
                    'status_label' => $this->status_label(isset($row['status']) ? (string) $row['status'] : ''),
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
                    ),
                    'logs' => $logs,
                    'is_finished' => $is_finished,
                    'review_url' => admin_url('admin.php?page=ucg-review&run_id=' . $run_id),
                    'runs_url' => admin_url('admin.php?page=ucg-runs'),
                )
            );
        }

        public function ajax_wizard_schema() {
            $this->guard_ajax();

            if (!UCG_Settings::has_valid_api_key()) {
                wp_send_json_error(array('message' => __('Сначала добавьте и проверьте API ключ.', 'unicontent-ai-generator')));
            }

            $post_type = sanitize_key($this->get_request_string($_POST, 'post_type', UCG_Tokens::get_default_post_type()));
            if ($post_type === '' || !post_type_exists($post_type)) {
                $post_type = UCG_Tokens::get_default_post_type();
            }
            $scenario = $this->normalize_generation_scenario(
                $this->get_request_string($_POST, 'scenario', self::DEFAULT_GENERATION_SCENARIO)
            );

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
                $this->get_request_string($_POST, 'scenario', self::DEFAULT_GENERATION_SCENARIO)
            );

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
            $template_scenario = isset($template['scenario']) ? sanitize_key((string) $template['scenario']) : '';
            if ($template_scenario === '') {
                $template_scenario = $requested_scenario;
            }
            if ($requested_scenario !== '' && $template_scenario !== $requested_scenario) {
                wp_send_json_error(array('message' => __('Шаблон относится к другому сценарию генерации.', 'unicontent-ai-generator')));
            }
            $template_payload = $this->decode_template_payload($template_scenario, isset($template['body']) ? (string) $template['body'] : '');

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
                $this->get_request_string($_POST, 'scenario', self::DEFAULT_GENERATION_SCENARIO)
            );
            $target_field = sanitize_text_field($this->get_request_string($_POST, 'target_field', ''));
            $items_per_post = $this->get_request_int($_POST, 'items_per_post', 1);
            $template_id = $this->get_request_int($_POST, 'template_id', 0);
            $template_name = sanitize_text_field($this->get_request_string($_POST, 'template_name', ''));
            $template_body = sanitize_textarea_field($this->get_request_string($_POST, 'template_body', ''));
            $template_body_seo_title = sanitize_textarea_field($this->get_request_string($_POST, 'template_body_seo_title', ''));
            $template_body_seo_description = sanitize_textarea_field($this->get_request_string($_POST, 'template_body_seo_description', ''));
            $selection_mode = sanitize_key($this->get_request_string($_POST, 'selection_mode', 'selected'));
            $model = sanitize_key($this->get_request_string($_POST, 'model', 'auto'));
            if ($model === '') {
                $model = 'auto';
            }
            $save_template = !empty($_POST['save_template']) ? 1 : 0;
            $vary_length = !empty($_POST['vary_length']) ? 1 : 0;
            $publish_date_from = '';
            $publish_date_to = '';

            if ($post_type === '' || !post_type_exists($post_type)) {
                wp_send_json_error(array('message' => __('Некорректный post type.', 'unicontent-ai-generator')));
            }
            if (!$this->is_scenario_available($scenario)) {
                wp_send_json_error(array('message' => __('Выбранный сценарий пока недоступен.', 'unicontent-ai-generator')));
            }
            if ($scenario === 'woo_reviews' && $post_type !== 'product') {
                wp_send_json_error(array('message' => __('Для сценария отзывов WooCommerce выберите тип записей: Товар (product).', 'unicontent-ai-generator')));
            }
            if ($scenario === 'comments' && !post_type_supports($post_type, 'comments')) {
                wp_send_json_error(array('message' => __('Выбранный тип записей не поддерживает комментарии.', 'unicontent-ai-generator')));
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
            if (!empty($schema['generation_models']) && is_array($schema['generation_models'])) {
                foreach ($schema['generation_models'] as $model_item) {
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

            if (!isset($allowed_target_fields[$target_field])) {
                wp_send_json_error(array('message' => __('Выберите корректное целевое поле.', 'unicontent-ai-generator')));
            }

            $active_template_id = $template_id;
            if ($template_id > 0) {
                $template = UCG_DB::get_template($template_id);
                if (!$template) {
                    wp_send_json_error(array('message' => __('Шаблон не найден.', 'unicontent-ai-generator')));
                }
                $template_scenario = isset($template['scenario']) ? sanitize_key((string) $template['scenario']) : '';
                if ($template_scenario === '') {
                    $template_scenario = $scenario;
                }
                if ($template_scenario !== $scenario) {
                    wp_send_json_error(array('message' => __('Шаблон относится к другому сценарию генерации.', 'unicontent-ai-generator')));
                }
                $template_payload = $this->decode_template_payload(
                    $template_scenario,
                    isset($template['body']) ? (string) $template['body'] : ''
                );

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
                        wp_send_json_error(array('message' => __('Заполните шаблоны для SEO title и SEO description.', 'unicontent-ai-generator')));
                    }
                } elseif (trim($template_body) === '') {
                    wp_send_json_error(array('message' => __('Текст шаблона не может быть пустым.', 'unicontent-ai-generator')));
                }

                if ($save_template) {
                    $encoded_template_body = $this->encode_template_payload(
                        $scenario,
                        $template_body,
                        $template_body_seo_title,
                        $template_body_seo_description
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
                        wp_send_json_error(array('message' => __('Заполните шаблоны для SEO title и SEO description.', 'unicontent-ai-generator')));
                    }
                } elseif (trim($template_body) === '') {
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
                        $template_body_seo_description
                    );
                    $created_template_id = UCG_DB::create_template($template_name, $post_type, $encoded_template_body, 0, 0, 0, $scenario);
                    if ($created_template_id <= 0) {
                        wp_send_json_error(array('message' => __('Не удалось сохранить новый шаблон.', 'unicontent-ai-generator')));
                    }
                    $active_template_id = $created_template_id;
                }
            }

            if ($length_option_id <= 0) {
                wp_send_json_error(array('message' => __('Выберите диапазон длины текста.', 'unicontent-ai-generator')));
            }

            $filters = $this->normalize_filters_from_request($this->get_request_string($_POST, 'filters', '[]'), $post_type);
            $post_ids = array();

            if ($selection_mode === 'filtered') {
                $post_ids = $this->query_filtered_post_ids($post_type, $filters, 50000, 0);
            } else {
                $selected_ids = $this->parse_ids_json($this->get_request_string($_POST, 'selected_ids', '[]'));
                $post_ids = $this->validate_post_ids_for_type($selected_ids, $post_type);
            }

            if (empty($post_ids)) {
                wp_send_json_error(array('message' => __('Не выбраны записи для генерации.', 'unicontent-ai-generator')));
            }

            $options = array(
                'scenario' => $scenario,
                'model' => $model,
                'scope' => $selection_mode === 'filtered' ? 'filtered' : 'selected',
                'filters' => $filters,
                'template_body' => $scenario === 'seo_tags' ? '' : $template_body,
                'seo_title_prompt' => $scenario === 'seo_tags' ? $template_body_seo_title : '',
                'seo_description_prompt' => $scenario === 'seo_tags' ? $template_body_seo_description : '',
                'length_option_id' => $length_option_id,
                'vary_length' => $vary_length,
                'publish_date_from' => $publish_date_from,
                'publish_date_to' => $publish_date_to,
                'items_per_post' => $items_per_post,
            );

            $run_id = UCG_DB::create_run($post_type, $target_field, $active_template_id, get_current_user_id(), $options);
            if ($run_id <= 0) {
                wp_send_json_error(array('message' => __('Не удалось создать запуск.', 'unicontent-ai-generator')));
            }

            $added_items = UCG_DB::add_run_items($run_id, $post_ids, $items_per_post);
            if ($added_items <= 0) {
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

        protected function build_wizard_schema($post_type, $force_refresh_lengths = false, $scenario = self::DEFAULT_GENERATION_SCENARIO) {
            $post_type = sanitize_key((string) $post_type);
            if ($post_type === '' || !post_type_exists($post_type)) {
                $post_type = UCG_Tokens::get_default_post_type();
            }
            $scenario = $this->normalize_generation_scenario($scenario);
            $scenario_options = $this->get_generation_scenario_options();

            $target_fields = $this->get_target_fields_for_scenario($post_type, $scenario);
            $templates = UCG_DB::get_templates($post_type, $scenario);
            $tokens = UCG_Tokens::get_prompt_tokens_for_post_type($post_type);
            $filter_fields = $this->get_filter_fields_for_post_type($post_type);
            $text_length_data = $this->get_text_length_options(!empty($force_refresh_lengths));
            $text_length_options = isset($text_length_data['options']) && is_array($text_length_data['options']) ? $text_length_data['options'] : array();
            $generation_models_data = $this->get_generation_models($scenario, !empty($force_refresh_lengths));

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
                'text_length_options' => $text_length_options,
                'default_length_option_id' => isset($text_length_data['default_option_id']) ? (int) $text_length_data['default_option_id'] : 0,
                'vary_length_hint' => isset($text_length_data['hint']) ? (string) $text_length_data['hint'] : '',
                'generation_models' => isset($generation_models_data['models']) && is_array($generation_models_data['models'])
                    ? $generation_models_data['models']
                    : array(),
                'default_model' => isset($generation_models_data['default_model']) ? (string) $generation_models_data['default_model'] : 'auto',
                'generation_unit_label' => isset($generation_models_data['unit_label']) ? (string) $generation_models_data['unit_label'] : __('1 единица', 'unicontent-ai-generator'),
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

        protected function normalize_generation_scenario($scenario) {
            $scenario = sanitize_key((string) $scenario);
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
                    'description' => __('Обновление одного выбранного поля записи.', 'unicontent-ai-generator'),
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

            return UCG_Tokens::get_target_fields_for_post_type($post_type);
        }

        protected function encode_template_payload($scenario, $body, $seo_title_prompt = '', $seo_description_prompt = '', $prompt_blocks = array(), $base_prompt = '') {
            $scenario = $this->normalize_generation_scenario($scenario);
            $body = (string) $body;
            $seo_title_prompt = (string) $seo_title_prompt;
            $seo_description_prompt = (string) $seo_description_prompt;
            $base_prompt = (string) $base_prompt;
            $normalized_blocks = $this->normalize_prompt_blocks($prompt_blocks, $scenario);

            if ($scenario !== 'seo_tags' && empty($normalized_blocks) && trim($base_prompt) === '') {
                return $body;
            }

            $payload = array(
                'version' => 2,
                'scenario' => $scenario,
                'body' => $body,
                'base_prompt' => $base_prompt,
                'prompt_blocks' => $normalized_blocks,
            );

            if ($scenario === 'seo_tags') {
                $payload['seo_title_prompt'] = $seo_title_prompt;
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
            );

            $decoded = json_decode($raw_body, true);
            if (is_array($decoded)) {
                $result['body'] = isset($decoded['body']) ? (string) $decoded['body'] : '';
                $result['seo_title_prompt'] = isset($decoded['seo_title_prompt']) ? (string) $decoded['seo_title_prompt'] : '';
                $result['seo_description_prompt'] = isset($decoded['seo_description_prompt']) ? (string) $decoded['seo_description_prompt'] : '';
                $result['base_prompt'] = isset($decoded['base_prompt']) ? (string) $decoded['base_prompt'] : '';
                $result['prompt_blocks'] = isset($decoded['prompt_blocks']) && is_array($decoded['prompt_blocks'])
                    ? $decoded['prompt_blocks']
                    : array();
            }

            $result['prompt_blocks'] = $this->build_editor_prompt_blocks($scenario, $result);
            $derived = $this->derive_template_prompts_from_blocks($scenario, isset($result['base_prompt']) ? (string) $result['base_prompt'] : '', $result['prompt_blocks']);

            if (trim((string) $result['body']) === '' && !empty($derived['body'])) {
                $result['body'] = (string) $derived['body'];
            }
            if (trim((string) $result['seo_title_prompt']) === '' && !empty($derived['seo_title_prompt'])) {
                $result['seo_title_prompt'] = (string) $derived['seo_title_prompt'];
            }
            if (trim((string) $result['seo_description_prompt']) === '' && !empty($derived['seo_description_prompt'])) {
                $result['seo_description_prompt'] = (string) $derived['seo_description_prompt'];
            }

            return $result;
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

            $transient_key = 'ucg_generation_models_cache_v1_' . $cache_key;
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
                $id = isset($item['id']) ? sanitize_key((string) $item['id']) : '';
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

                $normalized_models[] = array(
                    'id' => $id,
                    'name' => $name,
                    'provider' => isset($item['provider']) ? (string) $item['provider'] : '',
                    'resolved_model' => isset($item['resolved_model']) ? (string) $item['resolved_model'] : '',
                    'is_default' => !empty($item['is_default']),
                    'multiplier' => isset($item['multiplier']) ? max(0.1, (float) $item['multiplier']) : 1.0,
                    'estimated_credits_by_length' => $estimated_normalized,
                );
            }

            if (empty($normalized_models)) {
                $in_memory_cache[$cache_key] = $fallback;
                return $fallback;
            }

            $default_model = isset($response['default_model']) ? sanitize_key((string) $response['default_model']) : 'auto';
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
                'comments' => __('1 комментарий', 'unicontent-ai-generator'),
                'woo_reviews' => __('1 отзыв', 'unicontent-ai-generator'),
            );

            if (isset($map[$scenario])) {
                return $map[$scenario];
            }
            return __('1 единица', 'unicontent-ai-generator');
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
                delete_transient('ucg_generation_models_cache_v1_' . $scenario_key);
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
                return rtrim(mb_substr($message, 0, $max_length - 1, 'UTF-8')) . '…';
            }

            if (strlen($message) <= $max_length) {
                return $message;
            }
            return rtrim(substr($message, 0, $max_length - 1)) . '…';
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
