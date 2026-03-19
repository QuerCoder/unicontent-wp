<?php
if (!defined('ABSPATH')) exit;

/**
 * Auto-updater: проверяет обновления через unicontent.net
 * Fallback на GitHub если сервер недоступен.
 */
if (!class_exists('UCG_Updater')) {
    class UCG_Updater {

        private string $plugin_file;
        private string $plugin_slug;
        private string $version;
        private string $version_url = 'https://unicontent.net/download/plugin/wordpress/version';
        private string $github_api  = 'https://api.github.com/repos/QuerCoder/unicontent-wp/releases/latest';

        public function __construct(string $plugin_file, string $version) {
            $this->plugin_file = $plugin_file;
            $this->plugin_slug = plugin_basename($plugin_file);
            $this->version     = $version;

            add_filter('pre_set_site_transient_update_plugins', [$this, 'check_update']);
            add_filter('plugins_api', [$this, 'plugin_info'], 20, 3);
            add_filter('upgrader_post_install', [$this, 'after_install'], 10, 3);
        }

        /**
         * Получить инфо о последней версии (сначала с нашего сервера, потом GitHub)
         */
        private function get_remote_info(): ?object {
            // Пробуем наш сервер
            $response = wp_remote_get($this->version_url, [
                'timeout' => 10,
                'headers' => ['Accept' => 'application/json'],
            ]);

            if (!is_wp_error($response) && wp_remote_retrieve_response_code($response) === 200) {
                $data = json_decode(wp_remote_retrieve_body($response));
                if (!empty($data->version)) {
                    $data->download_url = 'https://unicontent.net/download/plugin/wordpress';
                    return $data;
                }
            }

            // Fallback: GitHub API
            $response = wp_remote_get($this->github_api, [
                'timeout' => 10,
                'headers' => ['Accept' => 'application/vnd.github+json'],
            ]);

            if (is_wp_error($response) || wp_remote_retrieve_response_code($response) !== 200) {
                return null;
            }

            $data = json_decode(wp_remote_retrieve_body($response));
            if (empty($data->tag_name)) {
                return null;
            }

            $zip_url = null;
            foreach ($data->assets ?? [] as $asset) {
                if (str_ends_with($asset->name, '.zip')) {
                    $zip_url = $asset->browser_download_url;
                    break;
                }
            }

            return (object)[
                'version'      => ltrim($data->tag_name, 'v'),
                'download_url' => $zip_url ?? $data->zipball_url,
                'details_url'  => $data->html_url,
            ];
        }

        /**
         * Хук: проверить наличие обновления
         */
        public function check_update($transient) {
            if (empty($transient->checked)) {
                return $transient;
            }

            if (isset($_GET['force-check'])) {
                delete_transient('ucg_update_info');
            }

            $remote = get_transient('ucg_update_info');
            if ($remote === false) {
                $remote = $this->get_remote_info();
                set_transient('ucg_update_info', $remote, HOUR_IN_SECONDS);
            }

            if ($remote && version_compare($this->version, $remote->version, '<')) {
                $transient->response[$this->plugin_slug] = (object)[
                    'slug'        => dirname($this->plugin_slug),
                    'plugin'      => $this->plugin_slug,
                    'new_version' => $remote->version,
                    'url'         => $remote->details_url ?? 'https://unicontent.net',
                    'package'     => $remote->download_url,
                ];
            } else {
                unset($transient->response[$this->plugin_slug]);
            }

            return $transient;
        }

        /**
         * Хук: инфо о плагине в модальном окне WP
         */
        public function plugin_info($res, $action, $args) {
            if ($action !== 'plugin_information') {
                return $res;
            }
            if ($args->slug !== dirname($this->plugin_slug)) {
                return $res;
            }

            $remote = $this->get_remote_info();
            if (!$remote) {
                return $res;
            }

            return (object)[
                'name'          => 'UniContent AI Content Generator',
                'slug'          => dirname($this->plugin_slug),
                'version'       => $remote->version,
                'author'        => '<a href="https://unicontent.net">UNICONTENT</a>',
                'homepage'      => 'https://unicontent.net',
                'download_link' => $remote->download_url,
                'sections'      => [
                    'description' => 'AI-генератор текстов для WordPress и WooCommerce.',
                    'changelog'   => 'Смотрите <a href="https://github.com/QuerCoder/unicontent-wp/releases">GitHub Releases</a>.',
                ],
            ];
        }

        /**
         * Хук: переименовать папку после установки
         */
        public function after_install($response, $hook_extra, $result) {
            global $wp_filesystem;
            $plugin_folder = WP_PLUGIN_DIR . DIRECTORY_SEPARATOR . 'unicontent-ai-generator';
            $wp_filesystem->move($result['destination'], $plugin_folder);
            $result['destination'] = $plugin_folder;
            delete_transient('ucg_update_info');
            activate_plugin('unicontent-ai-generator/unicontent-ai-generator.php');
            return $result;
        }
    }
}
