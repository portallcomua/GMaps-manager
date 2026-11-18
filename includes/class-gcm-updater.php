<?php

if (!defined('ABSPATH')) {
    exit;
}

class GCM_GitHub_Updater {

    const GITHUB_REPOSITORY = 'portallcomua/GMaps-manager';
    const GITHUB_API_URL = 'https://api.github.com/repos/portallcomua/GMaps-manager/releases/latest';
    const CACHE_KEY = 'gcm_github_latest_release';
    const CACHE_TIME = 21600;

    private static $instance = null;

    private $plugin_file = '';
    private $plugin_slug = '';

    public static function instance() {

        if (self::$instance === null) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    private function __construct() {

        $this->plugin_file = plugin_basename(GCM_FILE);
        $this->plugin_slug = dirname($this->plugin_file);

        add_filter(
            'pre_set_site_transient_update_plugins',
            array($this, 'check_for_update')
        );

        add_filter(
            'plugins_api',
            array($this, 'plugin_information'),
            20,
            3
        );

        add_filter(
            'upgrader_source_selection',
            array($this, 'rename_github_directory'),
            10,
            4
        );
    }

    /**
     * Отримання останнього опублікованого GitHub Release.
     */
    private function get_latest_release($force = false) {

        if (!$force) {

            $cached = get_site_transient(self::CACHE_KEY);

            if ($cached !== false) {
                return $cached;
            }
        }

        $response = wp_remote_get(
            self::GITHUB_API_URL,
            array(
                'timeout' => 15,
                'headers' => array(
                    'Accept' => 'application/vnd.github+json',
                    'User-Agent' => 'GMaps-Client-Manager-WordPress-Updater',
                    'X-GitHub-Api-Version' => '2022-11-28',
                ),
            )
        );

        if (is_wp_error($response)) {
            return false;
        }

        $status_code = wp_remote_retrieve_response_code($response);

        if ($status_code !== 200) {
            return false;
        }

        $body = wp_remote_retrieve_body($response);
        $release = json_decode($body, true);

        if (
            !is_array($release) ||
            empty($release['tag_name']) ||
            empty($release['zipball_url'])
        ) {
            return false;
        }

        $version = ltrim(
            sanitize_text_field($release['tag_name']),
            'vV'
        );

        if (!preg_match('/^[0-9]+(?:\.[0-9]+){1,3}(?:-[a-zA-Z0-9.-]+)?$/', $version)) {
            return false;
        }

        $data = array(
            'version' => $version,
            'tag' => sanitize_text_field($release['tag_name']),
            'name' => !empty($release['name'])
                ? sanitize_text_field($release['name'])
                : 'GMaps Client Manager ' . $version,
            'description' => !empty($release['body'])
                ? wp_kses_post($release['body'])
                : '',
            'url' => !empty($release['html_url'])
                ? esc_url_raw($release['html_url'])
                : '',
            'package' => esc_url_raw($release['zipball_url']),
            'published_at' => !empty($release['published_at'])
                ? sanitize_text_field($release['published_at'])
                : '',
        );

        set_site_transient(
            self::CACHE_KEY,
            $data,
            self::CACHE_TIME
        );

        return $data;
    }

    /**
     * Перевірка наявності нової версії.
     */
    public function check_for_update($transient) {

        if (!is_object($transient)) {
            return $transient;
        }

        if (empty($transient->checked)) {
            return $transient;
        }

        $release = $this->get_latest_release();

        if (!$release) {
            return $transient;
        }

        if (
            version_compare(
                $release['version'],
                GCM_VERSION,
                '>'
            )
        ) {

            $update = new stdClass();

            $update->id = 'https://github.com/' . self::GITHUB_REPOSITORY;
            $update->slug = 'GMaps-manager';
            $update->plugin = $this->plugin_file;
            $update->new_version = $release['version'];
            $update->url = $release['url'];
            $update->package = $release['package'];
            $update->tested = '';
            $update->requires = '';
            $update->requires_php = '7.4';

            $transient->response[$this->plugin_file] = $update;

        } else {

            if (isset($transient->response[$this->plugin_file])) {
                unset($transient->response[$this->plugin_file]);
            }

            $no_update = new stdClass();

            $no_update->id = 'https://github.com/' . self::GITHUB_REPOSITORY;
            $no_update->slug = 'GMaps-manager';
            $no_update->plugin = $this->plugin_file;
            $no_update->new_version = GCM_VERSION;
            $no_update->url = $release['url'];
            $no_update->package = '';

            $transient->no_update[$this->plugin_file] = $no_update;
        }

        return $transient;
    }

    /**
     * Інформація про плагін у вікні деталей оновлення.
     */
    public function plugin_information($result, $action, $args) {

        if ($action !== 'plugin_information') {
            return $result;
        }

        if (empty($args->slug) || $args->slug !== 'GMaps-manager') {
            return $result;
        }

        $release = $this->get_latest_release();

        if (!$release) {
            return $result;
        }

        $info = new stdClass();

        $info->name = 'GMaps Client Manager';
        $info->slug = 'GMaps-manager';
        $info->version = $release['version'];
        $info->author = 'UAserver';
        $info->homepage = 'https://github.com/' . self::GITHUB_REPOSITORY;
        $info->download_link = $release['package'];
        $info->requires = '';
        $info->tested = '';
        $info->requires_php = '7.4';
        $info->last_updated = $release['published_at'];

        $info->sections = array(
            'description' => 'Менеджер клієнтських карток Google Maps.',
            'changelog' => $release['description'],
        );

        return $info;
    }

    /**
     * GitHub розпаковує архів у папку на зразок:
     * portallcomua-GMaps-manager-xxxxxxxx
     *
     * Перейменовуємо її на фактичну папку встановленого плагіна.
     */
    public function rename_github_directory(
        $source,
        $remote_source,
        $upgrader,
        $hook_extra
    ) {

        if (is_wp_error($source)) {
            return $source;
        }

        if (empty($hook_extra['plugin'])) {
            return $source;
        }

        if ($hook_extra['plugin'] !== $this->plugin_file) {
            return $source;
        }

        if (empty($this->plugin_slug) || $this->plugin_slug === '.') {
            return new WP_Error(
                'gcm_update_folder_error',
                'Не вдалося визначити папку встановленого GMaps Client Manager.'
            );
        }

        global $wp_filesystem;

        if (!$wp_filesystem) {
            require_once ABSPATH . 'wp-admin/includes/file.php';

            WP_Filesystem();

            global $wp_filesystem;
        }

        if (!$wp_filesystem) {
            return new WP_Error(
                'gcm_update_filesystem_error',
                'Не вдалося отримати доступ до файлової системи WordPress.'
            );
        }

        $target = trailingslashit($remote_source) . $this->plugin_slug;

        if ($wp_filesystem->exists($target)) {
            return new WP_Error(
                'gcm_update_target_exists',
                'Папка для встановлення оновлення вже існує. Спробуйте оновити плагін повторно.'
            );
        }

        if (!$wp_filesystem->move($source, $target, true)) {
            return new WP_Error(
                'gcm_update_rename_failed',
                'Не вдалося підготувати папку плагіна для встановлення оновлення.'
            );
        }

        return trailingslashit($target);
    }
}
