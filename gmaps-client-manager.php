<?php
/**
 * Plugin Name: GMaps Client Manager
 * Description: Client intake and manager workflow for Google Business Profile services.
 * Version: 1.4.0
 * Author: UAserver
 * Text Domain: gmaps-client-manager
 */
if (!defined('ABSPATH')) exit;

final class GMaps_Client_Manager {
    const VERSION = '1.4.0';
    const DB_VERSION = '1.4';
    const ROLE = 'gmaps_manager';
    const OPT = 'gcm_settings';
    private static $instance = null;
    private $tables = array();

    public static function instance() {
        if (self::$instance === null) self::$instance = new self();
        return self::$instance;
    }

    private function __construct() {
        global $wpdb;
        $this->tables = array(
            'clients' => $wpdb->prefix . 'gcm_clients',
            'orders' => $wpdb->prefix . 'gcm_orders',
            'payments' => $wpdb->prefix . 'gcm_payments',
            'payouts' => $wpdb->prefix . 'gcm_payouts',
        );
        register_activation_hook(__FILE__, array(__CLASS__, 'activate'));
        add_action('init', array($this, 'register_role'));
        add_action('init', array($this, 'register_rewrite'));
        add_action('init', array($this, 'maybe_upgrade'));
        add_filter('query_vars', array($this, 'query_vars'));
        add_action('template_redirect', array($this, 'maybe_render_dashboard'));
        add_action('admin_menu', array($this, 'admin_menu'));
        add_action('admin_init', array($this, 'register_settings'));
        add_action('admin_post_gcm_action', array($this, 'handle_admin_action'));
        add_action('admin_post_gcm_google_admin_connect', array($this, 'start_google_admin_connect'));
        add_action('wp_ajax_gcm_lookup_email', array($this, 'ajax_lookup_email'));
        add_action('wp_ajax_gcm_check_place', array($this, 'ajax_check_place'));
        add_action('wp_ajax_nopriv_gcm_check_place', array($this, 'ajax_check_place'));
        add_action('admin_post_gcm_submit_request', array($this, 'submit_request'));
        add_action('admin_post_nopriv_gcm_submit_request', array($this, 'submit_request'));
        add_action('admin_post_gcm_google_callback', array($this, 'google_callback'));
        add_shortcode('gmaps_client_manager', array($this, 'shortcode'));
        add_shortcode('gmaps_manager_link', array($this, 'manager_link_shortcode'));
        add_shortcode('gmaps_request_form', array($this, 'request_form_shortcode'));
        add_action('wp_enqueue_scripts', array($this, 'assets'));
    }

    public static function activate() {
        self::instance()->create_tables();
        self::instance()->register_role();
        self::instance()->register_rewrite();
        flush_rewrite_rules();
    }

    public function register_role() {
        add_role(self::ROLE, 'GMaps Manager', array('read' => true));
    }

    public function register_rewrite() {
        add_rewrite_rule('^gmaps/?$', 'index.php?gcm_dashboard=1', 'top');
    }

    public function query_vars($vars) {
        $vars[] = 'gcm_dashboard';
        return $vars;
    }

    private function create_tables() {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $charset = $wpdb->get_charset_collate();
        $c = $this->tables['clients']; $o = $this->tables['orders'];
        $p = $this->tables['payments']; $y = $this->tables['payouts'];
        dbDelta("CREATE TABLE $c (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            manager_id bigint(20) unsigned NOT NULL,
            name varchar(190) NOT NULL,
            email varchar(190) NOT NULL DEFAULT '',
            phone varchar(80) NOT NULL DEFAULT '',
            google_email varchar(190) NOT NULL DEFAULT '',
            business_name varchar(190) NOT NULL DEFAULT '',
            notes text NULL,
            status varchar(30) NOT NULL DEFAULT 'active',
            created_at datetime NOT NULL,
            PRIMARY KEY  (id),
            KEY manager_id (manager_id)
        ) $charset;");
        dbDelta("CREATE TABLE $o (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            client_id bigint(20) unsigned NOT NULL,
            manager_id bigint(20) unsigned NOT NULL,
            service varchar(190) NOT NULL,
            amount decimal(12,2) NOT NULL DEFAULT 0,
            commission_rate decimal(5,2) NOT NULL DEFAULT 0,
            status varchar(30) NOT NULL DEFAULT 'new',
            notes text NULL,
            media longtext NULL,
            created_at datetime NOT NULL,
            PRIMARY KEY  (id),
            KEY manager_id (manager_id),
            KEY client_id (client_id)
        ) $charset;");
        dbDelta("CREATE TABLE $p (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            order_id bigint(20) unsigned NOT NULL,
            manager_id bigint(20) unsigned NOT NULL,
            amount decimal(12,2) NOT NULL DEFAULT 0,
            commission decimal(12,2) NOT NULL DEFAULT 0,
            method varchar(20) NOT NULL DEFAULT 'unknown',
            status varchar(30) NOT NULL DEFAULT 'pending',
            confirmed_by bigint(20) unsigned NOT NULL DEFAULT 0,
            confirmed_at datetime NULL,
            note varchar(255) NOT NULL DEFAULT '',
            created_at datetime NOT NULL,
            PRIMARY KEY  (id),
            KEY order_id (order_id),
            KEY manager_id (manager_id),
            KEY status (status)
        ) $charset;");
        dbDelta("CREATE TABLE $y (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            manager_id bigint(20) unsigned NOT NULL,
            amount decimal(12,2) NOT NULL DEFAULT 0,
            note varchar(255) NOT NULL DEFAULT '',
            paid_at datetime NOT NULL,
            created_by bigint(20) unsigned NOT NULL,
            PRIMARY KEY  (id),
            KEY manager_id (manager_id)
        ) $charset;");
        $r = $wpdb->prefix . 'gcm_requests';
        dbDelta("CREATE TABLE $r (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            customer_email varchar(190) NOT NULL,
            manager_email varchar(190) NOT NULL,
            manager_id bigint(20) unsigned NOT NULL DEFAULT 0,
            business_name varchar(190) NOT NULL,
            business_address varchar(255) NOT NULL DEFAULT '',
            maps_url text NULL,
            place_id varchar(190) NOT NULL DEFAULT '',
            request_type varchar(30) NOT NULL DEFAULT 'create',
            status varchar(30) NOT NULL DEFAULT 'new',
            last_error text NULL,
            notes text NULL,
            media longtext NULL,
            created_at datetime NOT NULL,
            PRIMARY KEY  (id),
            KEY manager_id (manager_id),
            KEY status (status)
        ) $charset;");
        update_option('gcm_db_version', self::DB_VERSION);
    }

    public function maybe_upgrade() {
        if (get_option('gcm_db_version') !== self::DB_VERSION) $this->create_tables();
    }

    public function assets() {
        if (get_query_var('gcm_dashboard') || is_singular()) {
            wp_register_style('gcm-inline', false, array(), self::VERSION);
            wp_enqueue_style('gcm-inline');
            wp_add_inline_style('gcm-inline', '.gcm-wrap{max-width:1100px;margin:25px auto;padding:20px;background:#fff;border:1px solid #dce3ea;border-radius:12px;color:#1d2939;font:15px/1.5 system-ui}.gcm-wrap h2{margin:0 0 16px}.gcm-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:12px}.gcm-box{border:1px solid #e2e8f0;border-radius:9px;padding:14px;margin:12px 0}.gcm-wrap input,.gcm-wrap select,.gcm-wrap textarea{width:100%;padding:9px;border:1px solid #cbd5e1;border-radius:6px;box-sizing:border-box}.gcm-wrap label{display:block;font-weight:600;margin:8px 0}.gcm-btn,.gcm-wrap button{display:inline-block;background:#1769aa;color:#fff;border:0;padding:9px 14px;border-radius:6px;cursor:pointer;text-decoration:none}.gcm-tabs{display:flex;flex-wrap:wrap;gap:8px;border-bottom:1px solid #e2e8f0;padding-bottom:10px}.gcm-tabs a{padding:8px 12px;color:#1769aa;text-decoration:none;border:1px solid #cbd5e1;border-radius:6px}.gcm-box[id]{scroll-margin-top:24px}.gcm-table{width:100%;border-collapse:collapse;margin:10px 0}.gcm-table th,.gcm-table td{padding:9px;border-bottom:1px solid #e5e7eb;text-align:left}.gcm-muted{color:#667085}.gcm-alert{padding:10px;background:#eff8ff;border-radius:6px;margin:10px 0}@media(max-width:650px){.gcm-table{display:block;overflow-x:auto}}');
        }
    }

    private function money($n) { return number_format((float)$n, 2, '.', ' ') . ' ' . esc_html(get_option('gcm_currency', 'грн')); }
    private function has_manager_role() {
        $u = wp_get_current_user();
        return is_user_logged_in() && (current_user_can('manage_options') || in_array(self::ROLE, (array)$u->roles, true));
    }
    private function is_manager() {
        $u = wp_get_current_user();
        if (!is_user_logged_in()) return false;
        if ($this->has_manager_role()) return true;
        global $wpdb;
        return (bool)$wpdb->get_var($wpdb->prepare("SELECT id FROM {$wpdb->prefix}gcm_requests WHERE manager_email=%s LIMIT 1", $u->user_email));
    }
    private function is_admin_user() { return current_user_can('manage_options'); }
    private function notice($msg, $type='info') { return '<div class="gcm-alert">'.esc_html($msg).'</div>'; }
    private function redirect_dashboard($msg='') {
        $url = home_url('/gmaps/');
        if ($msg) $url = add_query_arg('gcm_msg', rawurlencode($msg), $url);
        wp_safe_redirect($url); exit;
    }
    private function verify_front() {
        if (!$this->has_manager_role()) wp_die('Доступ заборонено.');
        if (empty($_POST['_wpnonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['_wpnonce'])), 'gcm_front')) wp_die('Перевірка безпеки не пройдена.');
    }

    public function maybe_render_dashboard() {
        if (!get_query_var('gcm_dashboard')) return;
        status_header(200);
        nocache_headers();
        get_header();
        echo $this->dashboard_html();
        get_footer();
        exit;
    }

    public function shortcode() {
        return $this->dashboard_html();
    }

    private function request_only_dashboard_html($requests) {
        ob_start(); ?>
        <div class="gcm-wrap">
          <h2>Ваші заявки GMaps</h2>
          <p class="gcm-muted">Увійшли як <?php echo esc_html(wp_get_current_user()->user_email); ?> · <a href="<?php echo esc_url(wp_logout_url(home_url('/gmaps/'))); ?>">Вийти</a></p>
          <table class="gcm-table"><thead><tr><th>Бізнес</th><th>Тип</th><th>Карта</th><th>Коментар</th><th>Матеріали</th><th>Статус</th></tr></thead><tbody>
          <?php if($requests) foreach($requests as $request): ?><tr><td><?php echo esc_html($request->business_name.' '.$request->business_address); ?></td><td><?php echo esc_html($request->request_type==='edit'?'Редагування':'Створення'); ?></td><td><?php if($request->maps_url): ?><a href="<?php echo esc_url($request->maps_url); ?>" target="_blank" rel="noopener">Відкрити карту</a><?php endif; ?></td><td><?php echo esc_html($request->notes); ?></td><td><?php echo $this->request_attachments($request->media); ?></td><td><?php echo esc_html($this->request_status_label($request->status)); ?></td></tr><?php endforeach; ?>
          <?php if(!$requests) echo '<tr><td colspan="6">Заявок поки немає.</td></tr>'; ?></tbody></table>
        </div>
        <?php return ob_get_clean();
    }

    public function manager_link_shortcode() {
        $url = is_user_logged_in() ? home_url('/gmaps/') : wp_login_url(home_url('/gmaps/'));
        return '<a class="gcm-btn" href="'.esc_url($url).'">Кабінет менеджера</a>';
    }

    public function request_form_shortcode() {
        $message = isset($_GET['gcm_request']) ? sanitize_key(wp_unslash($_GET['gcm_request'])) : '';
        if ($message === 'sent') return '<div class="gcm-wrap"><p class="gcm-alert">Заявку надіслано. Менеджер зв’яжеться з вами після перевірки.</p></div>';
        $ajax_url = admin_url('admin-ajax.php');
        $nonce = wp_create_nonce('gcm_place_check');
        ob_start(); ?>
        <div class="gcm-wrap">
          <h2>Заявка на створення або редагування Google Maps</h2>
          <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" enctype="multipart/form-data" id="gcm-request-form">
            <input type="hidden" name="action" value="gcm_submit_request">
            <?php wp_nonce_field('gcm_submit_request'); ?>
            <div class="gcm-grid">
              <label>Ваш email<input type="email" name="customer_email" required></label>
              <label>Email менеджера<input type="email" name="manager_email" required></label>
              <label>Назва бізнесу<input type="text" name="business_name" id="gcm-business-name"></label>
              <label>Адреса бізнесу<input type="text" name="business_address" id="gcm-business-address"></label>
            </div>
            <label>Посилання на Google Maps або Place ID<input type="text" name="maps_url" id="gcm-maps-url" placeholder="Необов’язково, якщо вказали назву й адресу"></label>
            <button type="button" id="gcm-check-place">Перевірити карту</button>
            <p class="gcm-muted" id="gcm-place-result" aria-live="polite">Якщо ви вже подавали заявку з цього email, перевіримо збережену карту. Для нової заявки вкажіть також назву/адресу або посилання на Google Maps.</p>
            <input type="hidden" name="place_checked" id="gcm-place-checked" value="0">
            <input type="hidden" name="place_id" id="gcm-place-id" value="">
            <input type="hidden" name="request_type" id="gcm-request-type" value="">
                        <div class="gcm-grid">
                            <label>Зробити фото<input type="file" name="gcm_photos[]" accept="image/*" capture="environment" multiple></label>
                            <label>Зняти відео<input type="file" name="gcm_videos[]" accept="video/*" capture="environment" multiple></label>
                            <label>Вибрати фото/відео з пристрою<input type="file" name="gcm_media[]" accept="image/*,video/*" multiple></label>
                        </div>
            <label>Коментар<textarea name="notes" rows="3"></textarea></label>
            <button type="submit" id="gcm-submit-request" disabled>Надіслати заявку</button>
          </form>
        </div>
        <script>
        (function(){
          var form=document.getElementById('gcm-request-form'),button=document.getElementById('gcm-check-place'),result=document.getElementById('gcm-place-result');
          var checked=document.getElementById('gcm-place-checked'),placeId=document.getElementById('gcm-place-id'),type=document.getElementById('gcm-request-type'),submit=document.getElementById('gcm-submit-request');
          function resetCheck(){checked.value='0';placeId.value='';type.value='';submit.disabled=true;}
          form.addEventListener('input',resetCheck);
          button.addEventListener('click',function(){
            var data=new FormData();data.append('action','gcm_check_place');data.append('_ajax_nonce','<?php echo esc_js($nonce); ?>');
            data.append('email',form.elements.customer_email.value);data.append('name',form.elements.business_name.value);
            data.append('address',form.elements.business_address.value);data.append('maps_url',form.elements.maps_url.value);
            result.textContent='Перевіряю Google Maps…';button.disabled=true;
            fetch('<?php echo esc_url($ajax_url); ?>',{method:'POST',credentials:'same-origin',body:data}).then(function(response){return response.json();}).then(function(response){
              if(!response.success)throw new Error(response.data&&response.data.message?response.data.message:'Не вдалося перевірити карту.');
              checked.value='1';submit.disabled=false;
              if(response.data.found){placeId.value=response.data.place_id;type.value='edit';result.textContent='Знайдено: '+response.data.name+(response.data.address?', '+response.data.address:'')+'. Заявка буде на редагування.';}
              else{type.value='create';result.textContent='Картку не знайдено. Заявка буде на створення нової сторінки.';}
            }).catch(function(error){result.textContent=error.message;resetCheck();}).finally(function(){button.disabled=false;});
          });
          form.addEventListener('submit',function(event){if(checked.value!=='1'){event.preventDefault();result.textContent='Спочатку перевірте карту.';}});
        })();
        </script>
        <?php return ob_get_clean();
    }

    public function ajax_check_place() {
        check_ajax_referer('gcm_place_check');
        $email = sanitize_email(wp_unslash($_POST['email'] ?? ''));
        if (!is_email($email)) wp_send_json_error(array('message'=>'Вкажіть коректний email.'), 400);
        $client_ip = sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR'] ?? 'unknown'));
        $limit_key = 'gcm_place_limit_' . md5($client_ip);
        $request_count = (int)get_transient($limit_key);
        if ($request_count >= 20) wp_send_json_error(array('message'=>'Забагато перевірок. Спробуйте пізніше.'), 429);
        set_transient($limit_key, $request_count + 1, 10 * MINUTE_IN_SECONDS);
        $name = sanitize_text_field(wp_unslash($_POST['name'] ?? ''));
        $address = sanitize_text_field(wp_unslash($_POST['address'] ?? ''));
        $maps_url = sanitize_text_field(wp_unslash($_POST['maps_url'] ?? ''));
        $query = $maps_url ?: trim($name . ' ' . $address);
        if (!$query) {
            global $wpdb;
            $query = (string)$wpdb->get_var($wpdb->prepare("SELECT place_id FROM {$wpdb->prefix}gcm_requests WHERE customer_email=%s AND place_id<>'' ORDER BY id DESC LIMIT 1", $email));
            if (!$query) wp_send_json_error(array('message'=>'Для першої заявки введіть назву й адресу бізнесу або посилання на Google Maps.'), 400);
        }
        $place = $this->lookup_google_place($query);
        if (is_wp_error($place)) wp_send_json_error(array('message'=>$place->get_error_message()), 502);
        if (!$place) wp_send_json_success(array('found'=>false));
        wp_send_json_success(array(
            'found'=>true,
            'place_id'=>sanitize_text_field($place['id'] ?? ''),
            'name'=>sanitize_text_field($place['displayName']['text'] ?? $name),
            'address'=>sanitize_text_field($place['formattedAddress'] ?? $address),
        ));
    }

    private function lookup_google_place($query) {
        $settings = get_option(self::OPT, array());
        $api_key = trim($settings['places_api_key'] ?? '');
        if (!$api_key) return new WP_Error('gcm_places_key', 'Адміністратор ще не додав Google Places API key.');
        $place_id = '';
        if (preg_match('/(?:query_place_id|place_id)=([^&]+)/i', $query, $match) || preg_match('/!1s(ChIJ[A-Za-z0-9_-]+)/', $query, $match)) {
            $place_id = sanitize_text_field(rawurldecode($match[1]));
        } elseif (preg_match('/^ChIJ[A-Za-z0-9_-]+$/', $query)) {
            $place_id = $query;
        }
        $headers = array('X-Goog-Api-Key'=>$api_key, 'X-Goog-FieldMask'=>'id,displayName,formattedAddress,googleMapsUri');
        if ($place_id) {
            $response = wp_remote_get('https://places.googleapis.com/v1/places/' . rawurlencode($place_id), array('timeout'=>15, 'headers'=>$headers));
        } else {
            $search = $query;
            if (filter_var($query, FILTER_VALIDATE_URL)) {
                $parts = wp_parse_url($query);
                parse_str($parts['query'] ?? '', $query_args);
                $search = sanitize_text_field($query_args['query'] ?? $query_args['q'] ?? '');
                if (!$search && !empty($parts['path']) && preg_match('~/maps/place/([^/@]+)~', $parts['path'], $match)) $search = str_replace('+', ' ', rawurldecode($match[1]));
                if (!$search) return new WP_Error('gcm_maps_link', 'Не вдалося прочитати місце з посилання. Введіть назву та адресу бізнесу.');
            }
            $response = wp_remote_post('https://places.googleapis.com/v1/places:searchText', array(
                'timeout'=>15,
                'headers'=>array_merge($headers, array('Content-Type'=>'application/json')),
                'body'=>wp_json_encode(array('textQuery'=>$search, 'languageCode'=>'uk')),
            ));
        }
        if (is_wp_error($response)) return new WP_Error('gcm_places_request', 'Google Maps не відповів. Спробуйте ще раз.');
        $code = wp_remote_retrieve_response_code($response);
        $body = json_decode(wp_remote_retrieve_body($response), true);
        if ($code < 200 || $code >= 300) return new WP_Error('gcm_places_api', 'Google Places API відхилив запит. Перевірте API key, Places API та billing у Google Cloud.');
        if ($place_id) return !empty($body['id']) ? $body : null;
        return !empty($body['places'][0]) ? $body['places'][0] : null;
    }

    private function google_admin_access_token() {
        $token = get_option('gcm_google_admin_token', array());
        if (!empty($token['access_token']) && !empty($token['expires_at']) && (int)$token['expires_at'] > time() + 60) return $token['access_token'];
        if (empty($token['refresh_token'])) return new WP_Error('gcm_google_auth', 'Спочатку підключіть центральний Google-акаунт у налаштуваннях плагіна.');
        $settings = get_option(self::OPT, array());
        $response = wp_remote_post('https://oauth2.googleapis.com/token', array('timeout'=>20, 'body'=>array(
            'client_id'=>$settings['client_id'] ?? '',
            'client_secret'=>$settings['client_secret'] ?? '',
            'refresh_token'=>$token['refresh_token'],
            'grant_type'=>'refresh_token',
        )));
        if (is_wp_error($response)) return new WP_Error('gcm_google_refresh', 'Не вдалося оновити Google-доступ. Перепідключіть акаунт адміністратора.');
        $body = json_decode(wp_remote_retrieve_body($response), true);
        if (empty($body['access_token'])) return new WP_Error('gcm_google_refresh', 'Google відхилив оновлення доступу. Перепідключіть акаунт адміністратора.');
        $token['access_token'] = $body['access_token'];
        $token['expires_at'] = time() + absint($body['expires_in'] ?? 3600);
        update_option('gcm_google_admin_token', $token, false);
        return $token['access_token'];
    }

    private function google_location_id_for_place($place_id, $access_token) {
        $accounts_url = 'https://mybusinessaccountmanagement.googleapis.com/v1/accounts';
        do {
            $accounts_response = wp_remote_get($accounts_url, array('timeout'=>20, 'headers'=>array('Authorization'=>'Bearer ' . $access_token)));
            if (is_wp_error($accounts_response)) return new WP_Error('gcm_accounts', 'Не вдалося отримати список Google Business Profile акаунтів.');
            $accounts_body = json_decode(wp_remote_retrieve_body($accounts_response), true);
            if (wp_remote_retrieve_response_code($accounts_response) >= 300) return new WP_Error('gcm_accounts', $accounts_body['error']['message'] ?? 'Google не повернув список акаунтів.');
            foreach (($accounts_body['accounts'] ?? array()) as $account) {
                if (empty($account['name'])) continue;
                $locations_url = 'https://mybusinessbusinessinformation.googleapis.com/v1/' . $account['name'] . '/locations';
                $locations_url = add_query_arg(array('readMask'=>'name,metadata.placeId', 'pageSize'=>100), $locations_url);
                do {
                    $locations_response = wp_remote_get($locations_url, array('timeout'=>20, 'headers'=>array('Authorization'=>'Bearer ' . $access_token)));
                    if (is_wp_error($locations_response)) break;
                    $locations_body = json_decode(wp_remote_retrieve_body($locations_response), true);
                    if (wp_remote_retrieve_response_code($locations_response) >= 300) break;
                    foreach (($locations_body['locations'] ?? array()) as $location) {
                        if (($location['metadata']['placeId'] ?? '') === $place_id && !empty($location['name'])) {
                            $parts = explode('/', $location['name']);
                            return end($parts);
                        }
                    }
                    $locations_url = !empty($locations_body['nextPageToken']) ? add_query_arg('pageToken', $locations_body['nextPageToken'], $locations_url) : '';
                } while ($locations_url);
            }
            $accounts_url = !empty($accounts_body['nextPageToken']) ? add_query_arg('pageToken', $accounts_body['nextPageToken'], 'https://mybusinessaccountmanagement.googleapis.com/v1/accounts') : '';
        } while ($accounts_url);
        return new WP_Error('gcm_location_not_managed', 'Цю карту не знайдено серед локацій центрального Google-акаунта. Перевірте, що акаунт має до неї доступ.');
    }

    private function send_google_location_invitation($place_id, $manager_email) {
        $access_token = $this->google_admin_access_token();
        if (is_wp_error($access_token)) return $access_token;
        $location_id = $this->google_location_id_for_place($place_id, $access_token);
        if (is_wp_error($location_id)) return $location_id;
        $url = 'https://mybusinessaccountmanagement.googleapis.com/v1/locations/' . rawurlencode($location_id) . '/admins';
        $response = wp_remote_post($url, array(
            'timeout'=>20,
            'headers'=>array('Authorization'=>'Bearer ' . $access_token, 'Content-Type'=>'application/json'),
            'body'=>wp_json_encode(array('admin'=>$manager_email, 'role'=>'MANAGER')),
        ));
        if (is_wp_error($response)) return new WP_Error('gcm_invitation', 'Не вдалося надіслати запрошення Google.');
        $body = json_decode(wp_remote_retrieve_body($response), true);
        if (wp_remote_retrieve_response_code($response) >= 300) return new WP_Error('gcm_invitation', $body['error']['message'] ?? 'Google відхилив запрошення.');
        return true;
    }

    public function submit_request() {
        if (empty($_POST['_wpnonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['_wpnonce'])), 'gcm_submit_request')) wp_die('Перевірка безпеки не пройдена.');
        global $wpdb;
        $customer_email = sanitize_email(wp_unslash($_POST['customer_email'] ?? ''));
        $manager_email = sanitize_email(wp_unslash($_POST['manager_email'] ?? ''));
        $business_name = sanitize_text_field(wp_unslash($_POST['business_name'] ?? ''));
        $business_address = sanitize_text_field(wp_unslash($_POST['business_address'] ?? ''));
        $maps_url = sanitize_text_field(wp_unslash($_POST['maps_url'] ?? ''));
        if (!is_email($customer_email) || !is_email($manager_email) || empty($_POST['place_checked'])) wp_die('Перевірте email та спочатку виконайте пошук карти.');
        $submitted_place_id = sanitize_text_field(wp_unslash($_POST['place_id'] ?? ''));
        $place_query = $submitted_place_id ?: ($maps_url ?: trim($business_name . ' ' . $business_address));
        if (!$place_query) {
            $place_query = (string)$wpdb->get_var($wpdb->prepare("SELECT place_id FROM {$wpdb->prefix}gcm_requests WHERE customer_email=%s AND place_id<>'' ORDER BY id DESC LIMIT 1", $customer_email));
        }
        $place = $this->lookup_google_place($place_query);
        if (is_wp_error($place)) wp_die(esc_html($place->get_error_message()));
        if (!$business_name && $place) $business_name = sanitize_text_field($place['displayName']['text'] ?? '');
        if (!$business_name) wp_die('Для нової карти введіть назву бізнесу.');
        $request_type = $place ? 'edit' : 'create';
        $client_ip = sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR'] ?? 'unknown'));
        $limit_key = 'gcm_submit_limit_' . md5($client_ip);
        $request_count = (int)get_transient($limit_key);
        if ($request_count >= 5) wp_die('Забагато заявок. Спробуйте пізніше.');
        set_transient($limit_key, $request_count + 1, HOUR_IN_SECONDS);
        $media_ids = $this->upload_order_media(array('gcm_photos', 'gcm_videos', 'gcm_media'));
        if (is_wp_error($media_ids)) wp_die(esc_html($media_ids->get_error_message()));
        $manager = get_user_by('email', $manager_email);
        $manager_id = ($manager && (in_array(self::ROLE, (array)$manager->roles, true) || user_can($manager, 'manage_options'))) ? (int)$manager->ID : 0;
        $inserted = $wpdb->insert($wpdb->prefix . 'gcm_requests', array(
            'customer_email'=>$customer_email,
            'manager_email'=>$manager_email,
            'manager_id'=>$manager_id,
            'business_name'=>$business_name,
            'business_address'=>$business_address,
            'maps_url'=>$place['googleMapsUri'] ?? $maps_url,
            'place_id'=>$place['id'] ?? '',
            'request_type'=>$request_type,
            'status'=>$request_type === 'edit' ? 'pending_review' : 'needs_creation',
            'notes'=>sanitize_textarea_field(wp_unslash($_POST['notes'] ?? '')),
            'media'=>wp_json_encode($media_ids),
            'created_at'=>current_time('mysql'),
        ));
        if (!$inserted) {
            $this->discard_order_media($media_ids);
            wp_die('Не вдалося зберегти заявку. Спробуйте ще раз.');
        }
        wp_mail($manager_email, 'Нова заявка GMaps', "Клієнт: {$customer_email}\nБізнес: {$business_name}\nТип: " . ($request_type === 'edit' ? 'редагування' : 'створення') . "\nПерегляньте заявку у кабінеті GMaps Manager.");
        wp_mail(get_option('admin_email'), 'Нова заявка GMaps', "Клієнт: {$customer_email}\nМенеджер: {$manager_email}\nБізнес: {$business_name}\nТип: " . ($request_type === 'edit' ? 'редагування' : 'створення') . "\nПеревірте заявку в GMaps Manager.");
        $redirect = wp_get_referer() ?: home_url('/');
        wp_safe_redirect(add_query_arg('gcm_request', 'sent', $redirect));
        exit;
    }

    private function dashboard_html() {
        if (!is_user_logged_in()) {
            $login = wp_login_url(home_url('/gmaps/'));
            return '<div class="gcm-wrap"><h2>GMaps Client Manager</h2><p>Увійдіть, щоб відкрити кабінет менеджера.</p><a class="gcm-btn" href="'.esc_url($login).'">Увійти</a></div>';
        }
        if (!$this->is_manager()) return '<div class="gcm-wrap">Ваш обліковий запис не має ролі GMaps Manager.</div>';
        global $wpdb;
        $uid = get_current_user_id();
        $requests = $wpdb->get_results($wpdb->prepare("SELECT * FROM {$wpdb->prefix}gcm_requests WHERE manager_id=%d OR manager_email=%s ORDER BY id DESC", $uid, wp_get_current_user()->user_email));
        if (!$this->has_manager_role()) return $this->request_only_dashboard_html($requests);
        $c=$this->tables['clients']; $o=$this->tables['orders']; $p=$this->tables['payments'];
        $clients = $wpdb->get_results($wpdb->prepare("SELECT * FROM $c WHERE manager_id=%d ORDER BY id DESC", $uid));
        $orders = $wpdb->get_results($wpdb->prepare("SELECT o.*,c.name client_name FROM $o o LEFT JOIN $c c ON c.id=o.client_id WHERE o.manager_id=%d ORDER BY o.id DESC", $uid));
            $payments = $wpdb->get_results($wpdb->prepare("SELECT p.*,o.service,c.name client_name FROM {$this->tables['payments']} p LEFT JOIN {$this->tables['orders']} o ON o.id=p.order_id LEFT JOIN $c c ON c.id=o.client_id WHERE p.manager_id=%d ORDER BY p.id DESC", $uid));
        $earned = (float)$wpdb->get_var($wpdb->prepare("SELECT COALESCE(SUM(commission),0) FROM $p WHERE manager_id=%d AND status='confirmed'", $uid));
        $paid = (float)$wpdb->get_var($wpdb->prepare("SELECT COALESCE(SUM(amount),0) FROM {$this->tables['payouts']} WHERE manager_id=%d", $uid));
        $msg = isset($_GET['gcm_msg']) ? sanitize_text_field(wp_unslash($_GET['gcm_msg'])) : '';
        $settings=get_option(self::OPT,array());
        $payment_target=$settings['payment_target'] ?? '';
        ob_start(); ?>
        <div class="gcm-wrap">
          <h2>Кабінет менеджера</h2>
          <p class="gcm-muted">Вітаємо, <?php echo esc_html(wp_get_current_user()->display_name); ?> · <a href="<?php echo esc_url(wp_logout_url(home_url('/gmaps/'))); ?>">Вийти</a></p>
          <?php if($msg) echo $this->notice($msg); ?>
                    <nav class="gcm-tabs" aria-label="Розділи кабінету">
                                                <a href="#gcm-requests">Мої заявки</a><a href="#gcm-order">Нове замовлення</a><a href="#gcm-clients">Клієнти</a><a href="#gcm-orders">Замовлення</a>
                    </nav>
                    <div class="gcm-box" id="gcm-requests"><h3>Мої заявки клієнтів</h3><table class="gcm-table"><thead><tr><th>Клієнт</th><th>Бізнес</th><th>Тип</th><th>Карта</th><th>Коментар</th><th>Матеріали</th><th>Статус</th></tr></thead><tbody>
                        <?php if($requests) foreach($requests as $request): ?><tr><td><?php echo esc_html($request->customer_email); ?></td><td><?php echo esc_html($request->business_name.' '.$request->business_address); ?></td><td><?php echo esc_html($request->request_type==='edit'?'Редагування':'Створення'); ?></td><td><?php if($request->maps_url): ?><a href="<?php echo esc_url($request->maps_url); ?>" target="_blank" rel="noopener">Відкрити карту</a><?php endif; ?></td><td><?php echo esc_html($request->notes); ?></td><td><?php echo $this->request_attachments($request->media); ?></td><td><?php echo esc_html($this->request_status_label($request->status)); ?></td></tr><?php endforeach; ?>
                        <?php if(!$requests) echo '<tr><td colspan="7">Заявок поки немає.</td></tr>'; ?></tbody></table></div>
          <div class="gcm-grid">
            <div class="gcm-box"><strong>Клієнтів</strong><h2><?php echo count($clients); ?></h2></div>
            <div class="gcm-box"><strong>Підтверджені комісії</strong><h2><?php echo $this->money($earned); ?></h2></div>
            <div class="gcm-box"><strong>Виплачено</strong><h2><?php echo $this->money($paid); ?></h2></div>
            <div class="gcm-box"><strong>До виплати</strong><h2><?php echo $this->money(max(0,$earned-$paid)); ?></h2></div>
          </div>
            <div class="gcm-box" id="gcm-client-form"><h3>Додати клієнта</h3>
            <form method="post"><?php wp_nonce_field('gcm_front'); ?><input type="hidden" name="gcm_front_action" value="add_client">
              <div class="gcm-grid">
                <label>Ім’я / назва клієнта<input required name="name"></label>
                <label>Email клієнта<input type="email" name="email" id="gcm-email-check" data-endpoint="<?php echo esc_url(admin_url('admin-ajax.php')); ?>" data-nonce="<?php echo esc_attr(wp_create_nonce('gcm_email_check')); ?>"><span id="gcm-email-result" class="gcm-muted" aria-live="polite"></span></label>
                <label>Телефон<input name="phone"></label>
                <label>Google-пошта клієнта<input type="email" name="google_email"></label>
                <label>Назва бізнесу<input name="business_name"></label>
              </div><label>Нотатки<textarea name="notes" rows="2"></textarea></label><button>Зберегти клієнта</button>
              <script>
              (function(){var email=document.getElementById('gcm-email-check'),result=document.getElementById('gcm-email-result'),timer;if(!email)return;email.addEventListener('input',function(){clearTimeout(timer);result.textContent='';if(!email.value)return;timer=setTimeout(function(){var data=new FormData();data.append('action','gcm_lookup_email');data.append('email',email.value);data.append('_ajax_nonce',email.dataset.nonce);fetch(email.dataset.endpoint,{method:'POST',credentials:'same-origin',body:data}).then(function(response){return response.json();}).then(function(response){if(response.success&&response.data.exists){result.textContent='Цей email уже є в CRM: '+response.data.name+'. ';var link=document.createElement('a');link.href='#gcm-clients';link.textContent='Перейти до клієнтів';result.appendChild(link);}else if(response.success){result.textContent='У CRM такого email ще немає.';}}).catch(function(){result.textContent='Не вдалося перевірити email.';});},350);});})();
              </script>
            </form>
          </div>
            <div class="gcm-box" id="gcm-clients"><h3>Мої клієнти</h3>
            <table class="gcm-table"><thead><tr><th>Клієнт</th><th>Контакти</th><th>Google-пошта</th><th>Бізнес</th></tr></thead><tbody>
            <?php if($clients) foreach($clients as $cl): ?><tr><td><?php echo esc_html($cl->name); ?></td><td><?php echo esc_html($cl->email.' '.$cl->phone); ?></td><td><?php echo esc_html($cl->google_email); ?></td><td><?php echo esc_html($cl->business_name); ?></td></tr><?php endforeach; ?>
            <?php if(!$clients) echo '<tr><td colspan="4">Клієнтів поки немає.</td></tr>'; ?></tbody></table>
          </div>
                        <div class="gcm-box" id="gcm-order"><h3>Нове замовлення на Google Maps</h3>
                              <p class="gcm-muted">Оберіть клієнта, додайте послугу та фото чи відео об’єкта. <a href="#gcm-client-form">Додати нового клієнта</a></p>
                            <form method="post" enctype="multipart/form-data"><?php wp_nonce_field('gcm_front'); ?><input type="hidden" name="gcm_front_action" value="add_order">
              <div class="gcm-grid"><label>Клієнт<select name="client_id" required><option value="">Оберіть клієнта</option><?php foreach($clients as $cl) echo '<option value="'.(int)$cl->id.'">'.esc_html($cl->name).'</option>'; ?></select></label>
                                <label>Послуга<input required name="service" value="Створення картки Google Maps"></label>
                                <label>Сума замовлення<input required type="number" step="0.01" min="0.01" name="amount"></label>
              <label>Комісія менеджера, %<input required type="number" step="0.01" min="0" max="100" name="commission_rate" value="20"></label></div>
                                <div class="gcm-grid">
                                    <label>Зробити фото об’єкта<input type="file" name="gcm_photos[]" accept="image/*" capture="environment" multiple></label>
                                    <label>Зняти відео об’єкта<input type="file" name="gcm_videos[]" accept="video/*" capture="environment" multiple></label>
                                    <label>Вибрати фото/відео з пристрою<input type="file" name="gcm_media[]" accept="image/*,video/*" multiple></label>
                                </div>
                                <p class="gcm-muted">До 8 файлів за раз. Доступний розмір визначається налаштуваннями WordPress на сервері.</p>
                                <label>Примітки<textarea name="notes" rows="2"></textarea></label><button <?php disabled(!$clients); ?>>Створити замовлення</button>
            </form>
          </div>
                        <div class="gcm-box" id="gcm-orders"><h3>Мої замовлення</h3><table class="gcm-table"><thead><tr><th>#</th><th>Клієнт</th><th>Послуга</th><th>Сума</th><th>Комісія</th><th>Статус</th><th>Медіа</th></tr></thead><tbody>
                            <div class="gcm-box"><h3>Зафіксувати оплату клієнта</h3>
                                <form method="post"><?php wp_nonce_field('gcm_front'); ?><input type="hidden" name="gcm_front_action" value="report_payment">
                                    <div class="gcm-grid"><label>Замовлення<select name="order_id" required><option value="">Оберіть замовлення</option><?php foreach($orders as $ord) echo '<option value="'.(int)$ord->id.'">#'.(int)$ord->id.' — '.esc_html($ord->client_name.' / '.$ord->service).' ('.$this->money($ord->amount).')</option>'; ?></select></label>
                                    <label>Отримано<input required type="number" step="0.01" min="0.01" name="amount"></label>
                                    <label>Спосіб оплати<select name="method" required><option value="cash">Готівка менеджеру</option><option value="card">Картка / Приват Конверт</option></select></label></div>
                                    <label>Примітка<input name="note" maxlength="255"></label><button <?php disabled(!$orders); ?>>Зафіксувати оплату</button>
                                </form>
                            </div>
                            <div class="gcm-box"><h3>Оплати моїх клієнтів</h3><table class="gcm-table"><thead><tr><th>Клієнт / замовлення</th><th>Сума</th><th>Спосіб</th><th>Комісія</th><th>Статус</th><th>Примітка</th></tr></thead><tbody>
                                <?php if($payments) foreach($payments as $payment): ?><tr><td><?php echo esc_html($payment->client_name.' / '.$payment->service); ?></td><td><?php echo $this->money($payment->amount); ?></td><td><?php echo esc_html($this->payment_method_label($payment->method)); ?></td><td><?php echo $this->money($payment->commission); ?></td><td><?php echo esc_html($payment->status==='pending'?'Очікує підтвердження':'Підтверджено'); ?></td><td><?php echo esc_html($payment->note); ?></td></tr><?php endforeach; ?>
                                <?php if(!$payments) echo '<tr><td colspan="6">Оплат поки немає.</td></tr>'; ?></tbody></table></div>
                        <?php if($orders) foreach($orders as $ord): ?><tr><td><?php echo (int)$ord->id; ?></td><td><?php echo esc_html($ord->client_name); ?></td><td><?php echo esc_html($ord->service); ?></td><td><?php echo $this->money($ord->amount); ?></td><td><?php echo esc_html($ord->commission_rate); ?>%</td><td><?php echo esc_html($ord->status); ?></td><td><?php $media_ids=json_decode($ord->media, true); if(is_array($media_ids)) foreach($media_ids as $index=>$media_id): $media_url=wp_get_attachment_url(absint($media_id)); if($media_url): ?><a href="<?php echo esc_url($media_url); ?>" target="_blank" rel="noopener">Файл <?php echo (int)($index+1); ?></a><?php endif; endforeach; ?></td></tr><?php endforeach; ?>
                        <?php if(!$orders) echo '<tr><td colspan="7">Замовлень поки немає.</td></tr>'; ?></tbody></table></div>
                    <div class="gcm-box"><h3>Доступ до Google</h3><p class="gcm-muted">Google-доступ налаштовує адміністратор один раз. Менеджерам окремо підключати Google-акаунт не потрібно.</p></div>
        </div>
        <?php
        return ob_get_clean();
    }

    public function handle_front_post() {
        if (empty($_POST['gcm_front_action'])) return;
        $this->verify_front();
        global $wpdb; $uid=get_current_user_id(); $action=sanitize_key($_POST['gcm_front_action']);
        if ($action==='add_client') {
            $email=sanitize_email(wp_unslash($_POST['email'] ?? ''));
            if ($email && $wpdb->get_var($wpdb->prepare("SELECT id FROM {$this->tables['clients']} WHERE manager_id=%d AND email=%s LIMIT 1",$uid,$email))) $this->redirect_dashboard('Клієнт із цим email уже є у вашому кабінеті.');
            $wpdb->insert($this->tables['clients'], array(
                'manager_id'=>$uid,'name'=>sanitize_text_field(wp_unslash($_POST['name'])),
                'email'=>$email,
                'phone'=>sanitize_text_field(wp_unslash($_POST['phone'] ?? '')),
                'google_email'=>sanitize_email(wp_unslash($_POST['google_email'] ?? '')),
                'business_name'=>sanitize_text_field(wp_unslash($_POST['business_name'] ?? '')),
                'notes'=>sanitize_textarea_field(wp_unslash($_POST['notes'] ?? '')),
                'created_at'=>current_time('mysql')
            ));
            $this->redirect_dashboard('Клієнта додано.');
        }
        if ($action==='add_order') {
            $client_id=absint($_POST['client_id']);
            $owns=$wpdb->get_var($wpdb->prepare("SELECT id FROM {$this->tables['clients']} WHERE id=%d AND manager_id=%d",$client_id,$uid));
            if (!$owns) $this->redirect_dashboard('Клієнта не знайдено.');
            $amount=(float)($_POST['amount'] ?? 0);
            $service=sanitize_text_field(wp_unslash($_POST['service'] ?? ''));
            if ($amount<=0 || $service==='') $this->redirect_dashboard('Вкажіть послугу та суму більше нуля.');
            $rate=max(0,min(100,(float)$_POST['commission_rate']));
            $media_ids=$this->upload_order_media(array('gcm_photos','gcm_videos','gcm_media'));
            if (is_wp_error($media_ids)) $this->redirect_dashboard($media_ids->get_error_message());
            $inserted=$wpdb->insert($this->tables['orders'],array('client_id'=>$client_id,'manager_id'=>$uid,'service'=>$service,'amount'=>$amount,'commission_rate'=>$rate,'status'=>'new','notes'=>sanitize_textarea_field(wp_unslash($_POST['notes'] ?? '')),'media'=>wp_json_encode($media_ids),'created_at'=>current_time('mysql')));
            if (!$inserted) {
                $this->discard_order_media($media_ids);
                $this->redirect_dashboard('Не вдалося зберегти замовлення. Спробуйте ще раз.');
            }
            $this->redirect_dashboard('Замовлення створено. Очікує оплату.');
        }
    }

    public function ajax_lookup_email() {
        if (!$this->has_manager_role()) wp_send_json_error(array('message'=>'Доступ заборонено.'),403);
        check_ajax_referer('gcm_email_check');
        $email=sanitize_email(wp_unslash($_POST['email'] ?? ''));
        if (!$email || !is_email($email)) wp_send_json_error(array('message'=>'Вкажіть коректний email.'),400);
        global $wpdb;
        $client=$wpdb->get_row($wpdb->prepare("SELECT id,name FROM {$this->tables['clients']} WHERE manager_id=%d AND email=%s LIMIT 1",get_current_user_id(),$email));
        wp_send_json_success(array('exists'=>(bool)$client,'name'=>$client?$client->name:''));
    }

    private function payment_method_label($method) {
        if ($method==='cash') return 'Готівка';
        if ($method==='card') return 'Картка / Приват Конверт';
        return 'Не вказано';
    }

    private function request_status_label($status) {
        $labels = array(
            'pending_review'=>'Очікує перевірки',
            'needs_creation'=>'Потрібно створити карту',
            'invite_failed'=>'Запрошення не надіслано',
            'invited'=>'Запрошення надіслано, очікує прийняття',
        );
        return $labels[$status] ?? 'Нова заявка';
    }

    private function request_attachments($json) {
        $ids = json_decode($json, true);
        if (!is_array($ids)) return '';
        $links = array();
        foreach ($ids as $index => $id) {
            $url = wp_get_attachment_url(absint($id));
            if ($url) $links[] = '<a href="' . esc_url($url) . '" target="_blank" rel="noopener">Файл ' . (int)($index + 1) . '</a>';
        }
        return implode('<br>', $links);
    }

    private function upload_order_media($field_names) {
        require_once ABSPATH.'wp-admin/includes/file.php';
        $allowed=array('jpg'=>'image/jpeg','jpeg'=>'image/jpeg','png'=>'image/png','webp'=>'image/webp','mp4'=>'video/mp4','webm'=>'video/webm','mov'=>'video/quicktime');
        $media_ids=array();
        foreach($field_names as $field_name) {
            if (empty($_FILES[$field_name]['name']) || !is_array($_FILES[$field_name]['name'])) continue;
            $files=$_FILES[$field_name];
            foreach($files['name'] as $index=>$name) {
                if ($files['error'][$index]===UPLOAD_ERR_NO_FILE) continue;
                if (count($media_ids)>=8) {
                    $this->discard_order_media($media_ids);
                    return new WP_Error('gcm_media_limit','Можна додати не більше 8 файлів до одного замовлення.');
                }
                if ($files['error'][$index]!==UPLOAD_ERR_OK) {
                    $this->discard_order_media($media_ids);
                    return new WP_Error('gcm_upload','Не вдалося завантажити файл. Перевірте його розмір та спробуйте ще раз.');
                }
                $file=array('name'=>sanitize_file_name($name),'type'=>$files['type'][$index],'tmp_name'=>$files['tmp_name'][$index],'error'=>$files['error'][$index],'size'=>$files['size'][$index]);
                $checked=wp_check_filetype_and_ext($file['tmp_name'],$file['name'],$allowed);
                if (empty($checked['type']) || !in_array($checked['type'],array_values($allowed),true)) {
                    $this->discard_order_media($media_ids);
                    return new WP_Error('gcm_file_type','Дозволені фото JPG, PNG, WebP та відео MP4, WebM або MOV.');
                }
                $upload=wp_handle_upload($file,array('test_form'=>false,'mimes'=>$allowed));
                if (!empty($upload['error'])) {
                    $this->discard_order_media($media_ids);
                    return new WP_Error('gcm_upload',sanitize_text_field($upload['error']));
                }
                $attachment_id=wp_insert_attachment(array('post_mime_type'=>$upload['type'],'post_title'=>sanitize_file_name(pathinfo($file['name'],PATHINFO_FILENAME)),'post_status'=>'inherit'),$upload['file']);
                if (is_wp_error($attachment_id) || !$attachment_id) {
                    wp_delete_file($upload['file']);
                    $this->discard_order_media($media_ids);
                    return new WP_Error('gcm_media_save','Не вдалося зберегти медіафайл.');
                }
                require_once ABSPATH.'wp-admin/includes/image.php';
                $metadata=wp_generate_attachment_metadata($attachment_id,$upload['file']);
                if ($metadata) wp_update_attachment_metadata($attachment_id,$metadata);
                $media_ids[]=(int)$attachment_id;
            }
        }
        return $media_ids;
    }

    private function discard_order_media($media_ids) {
        foreach($media_ids as $media_id) wp_delete_attachment($media_id,true);
    }

    public function admin_menu() {
        add_menu_page('GMaps Client Manager','GMaps Manager','manage_options','gcm-dashboard',array($this,'admin_page'),'dashicons-location-alt',56);
        add_submenu_page('gcm-dashboard','Налаштування','Налаштування','manage_options','gcm-settings',array($this,'settings_page'));
    }

    public function register_settings() {
        register_setting('gcm_settings_group', self::OPT, array($this,'sanitize_settings'));
        register_setting('gcm_settings_group', 'gcm_currency', array('sanitize_callback'=>'sanitize_text_field'));
    }
    public function sanitize_settings($in) {
        return array('client_id'=>sanitize_text_field($in['client_id'] ?? ''),'client_secret'=>sanitize_text_field($in['client_secret'] ?? ''),'redirect_uri'=>esc_url_raw($in['redirect_uri'] ?? $this->google_redirect_uri()),'places_api_key'=>sanitize_text_field($in['places_api_key'] ?? ''));
    }
    private function google_redirect_uri() { return admin_url('admin-post.php?action=gcm_google_callback'); }
    public function settings_page() {
        $s=get_option(self::OPT,array()); ?>
        <div class="wrap"><h1>GMaps Client Manager — налаштування</h1><form method="post" action="options.php"><?php settings_fields('gcm_settings_group'); ?>
        <table class="form-table"><tr><th>Google Places API key</th><td><input class="regular-text" type="password" autocomplete="new-password" name="<?php echo esc_attr(self::OPT); ?>[places_api_key]" value="<?php echo esc_attr($s['places_api_key'] ?? ''); ?>"><p class="description">Використовується сервером для пошуку Google Maps. Увімкніть Places API та billing у Google Cloud.</p></td></tr>
        <tr><th>Google OAuth Client ID</th><td><input class="regular-text" name="<?php echo esc_attr(self::OPT); ?>[client_id]" value="<?php echo esc_attr($s['client_id'] ?? ''); ?>"></td></tr>
        <tr><th>Google OAuth Client Secret</th><td><input class="regular-text" type="password" name="<?php echo esc_attr(self::OPT); ?>[client_secret]" value="<?php echo esc_attr($s['client_secret'] ?? ''); ?>" autocomplete="new-password"></td></tr>
        <tr><th>Redirect URI</th><td><input class="regular-text" readonly value="<?php echo esc_attr($this->google_redirect_uri()); ?>"><p class="description">Додайте цю адресу в Authorized redirect URIs у Google Cloud Console.</p></td></tr>
        <tr><th>Валюта</th><td><input name="gcm_currency" value="<?php echo esc_attr(get_option('gcm_currency','грн')); ?>"></td></tr></table><?php submit_button(); ?></form>
        <h2>Центральний Google Business Profile</h2>
        <p>Підключіть один Google-акаунт адміністратора, який має доступ до потрібних карт. Менеджерам окрема Google-авторизація не потрібна.</p>
        <?php $token=get_option('gcm_google_admin_token',array()); if(!empty($token['refresh_token'])): ?><p><strong>Google-акаунт підключено.</strong></p><?php else: ?><p>Google-акаунт ще не підключено.</p><?php endif; ?>
        <a class="button button-primary" href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=gcm_google_admin_connect'),'gcm_google_admin_connect')); ?>">Підключити Google-акаунт адміністратора</a>
        <p><strong>Потрібно:</strong> увімкнути Google Business Profile API та налаштувати OAuth consent screen у Google Cloud. Ключі зберігаються лише в налаштуваннях сайту.</p></div><?php
    }

    public function admin_page() {
        global $wpdb; $orders=$wpdb->get_results("SELECT o.*,c.name client_name,u.display_name manager_name FROM {$this->tables['orders']} o LEFT JOIN {$this->tables['clients']} c ON c.id=o.client_id LEFT JOIN {$wpdb->users} u ON u.ID=o.manager_id ORDER BY o.id DESC");
        $payments=$wpdb->get_results("SELECT p.*,o.service,u.display_name manager_name FROM {$this->tables['payments']} p LEFT JOIN {$this->tables['orders']} o ON o.id=p.order_id LEFT JOIN {$wpdb->users} u ON u.ID=p.manager_id ORDER BY p.id DESC");
        $requests=$wpdb->get_results("SELECT * FROM {$wpdb->prefix}gcm_requests ORDER BY id DESC LIMIT 100");
        $managers=get_users(array('role'=>self::ROLE)); ?>
        <div class="wrap"><h1>GMaps Client Manager</h1>
        <h2>Заявки клієнтів</h2>
        <table class="widefat striped"><thead><tr><th>#</th><th>Клієнт</th><th>Бізнес</th><th>Тип</th><th>Менеджер</th><th>Карта</th><th>Коментар</th><th>Матеріали</th><th>Статус</th><th>Дія</th><th>Створено</th></tr></thead><tbody>
        <?php if($requests) foreach($requests as $request): ?><tr><td><?php echo (int)$request->id; ?></td><td><?php echo esc_html($request->customer_email); ?></td><td><?php echo esc_html($request->business_name.' '.$request->business_address); ?></td><td><?php echo esc_html($request->request_type==='edit'?'Редагування':'Створення'); ?></td><td><?php echo esc_html($request->manager_email); ?></td><td><?php if($request->maps_url): ?><a href="<?php echo esc_url($request->maps_url); ?>" target="_blank" rel="noopener">Відкрити</a><?php endif; ?></td><td><?php echo esc_html($request->notes); ?></td><td><?php echo $this->request_attachments($request->media); ?></td><td><?php echo esc_html($this->request_status_label($request->status)); ?><?php if($request->last_error): ?><br><span class="description"><?php echo esc_html($request->last_error); ?></span><?php endif; ?></td><td><?php if($request->request_type==='edit' && $request->status!=='invited'): ?><form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>"><?php wp_nonce_field('gcm_admin'); ?><input type="hidden" name="action" value="gcm_action"><input type="hidden" name="gcm_do" value="invite_request"><input type="hidden" name="request_id" value="<?php echo (int)$request->id; ?>"><button class="button button-primary">Надіслати запрошення</button></form><?php elseif($request->request_type==='create'): ?>Створити профіль у Google Business Profile<?php else: ?>Запрошення надіслано<?php endif; ?></td><td><?php echo esc_html($request->created_at); ?></td></tr><?php endforeach; ?>
        <?php if(!$requests) echo '<tr><td colspan="11">Заявок поки немає.</td></tr>'; ?></tbody></table>
        <h2>Менеджери</h2><p>Призначайте роль <code>GMaps Manager</code> користувачам у стандартному профілі WordPress.</p>
        <table class="widefat striped"><thead><tr><th>Менеджер</th><th>Email</th><th>Баланс комісії</th></tr></thead><tbody>
        <?php foreach($managers as $m): $earned=(float)$wpdb->get_var($wpdb->prepare("SELECT COALESCE(SUM(commission),0) FROM {$this->tables['payments']} WHERE manager_id=%d AND status='confirmed'",$m->ID)); $paid=(float)$wpdb->get_var($wpdb->prepare("SELECT COALESCE(SUM(amount),0) FROM {$this->tables['payouts']} WHERE manager_id=%d",$m->ID)); ?>
        <tr><td><?php echo esc_html($m->display_name); ?></td><td><?php echo esc_html($m->user_email); ?></td><td><?php echo esc_html($this->money(max(0,$earned-$paid))); ?></td></tr><?php endforeach; ?>
        </tbody></table>
        <h2 style="margin-top:28px">Замовлення та додавання платежу</h2>
        <table class="widefat striped"><thead><tr><th>#</th><th>Клієнт</th><th>Менеджер</th><th>Послуга</th><th>Сума</th><th>Ставка</th><th>Дія</th></tr></thead><tbody>
        <?php foreach($orders as $o): ?><tr><td><?php echo (int)$o->id; ?></td><td><?php echo esc_html($o->client_name); ?></td><td><?php echo esc_html($o->manager_name); ?></td><td><?php echo esc_html($o->service); ?></td><td><?php echo esc_html($this->money($o->amount)); ?></td><td><?php echo esc_html($o->commission_rate); ?>%</td><td>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:flex;gap:6px;align-items:center"><?php wp_nonce_field('gcm_admin'); ?><input type="hidden" name="action" value="gcm_action"><input type="hidden" name="gcm_do" value="add_payment"><input type="hidden" name="order_id" value="<?php echo (int)$o->id; ?>"><input type="number" name="amount" step=".01" min=".01" max="<?php echo esc_attr($o->amount); ?>" placeholder="Сума платежу" required style="width:120px"><input name="note" placeholder="Примітка" style="width:150px"><button class="button button-primary">Додати оплату</button></form></td></tr><?php endforeach; ?>
        </tbody></table>
        <h2 style="margin-top:28px">Платежі — підтвердження надходження коштів</h2>
        <table class="widefat striped"><thead><tr><th>#</th><th>Замовлення</th><th>Менеджер</th><th>Сума</th><th>Комісія</th><th>Статус</th><th>Дія</th></tr></thead><tbody>
        <?php foreach($payments as $p): ?><tr><td><?php echo (int)$p->id; ?></td><td><?php echo esc_html($p->service); ?></td><td><?php echo esc_html($p->manager_name); ?></td><td><?php echo esc_html($this->money($p->amount)); ?></td><td><?php echo esc_html($this->money($p->commission)); ?></td><td><?php echo esc_html($p->status); ?></td><td><?php if($p->status==='pending'): ?><form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>"><?php wp_nonce_field('gcm_admin'); ?><input type="hidden" name="action" value="gcm_action"><input type="hidden" name="gcm_do" value="confirm_payment"><input type="hidden" name="payment_id" value="<?php echo (int)$p->id; ?>"><button class="button button-primary">Підтвердити оплату</button></form><?php else: ?>Підтверджено<?php endif; ?></td></tr><?php endforeach; ?>
        </tbody></table>
        <h2 style="margin-top:28px">Виплата менеджеру</h2>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="gcm-box"><?php wp_nonce_field('gcm_admin'); ?><input type="hidden" name="action" value="gcm_action"><input type="hidden" name="gcm_do" value="payout">
        <select name="manager_id" required><option value="">Оберіть менеджера</option><?php foreach($managers as $m) echo '<option value="'.(int)$m->ID.'">'.esc_html($m->display_name).'</option>'; ?></select>
        <input type="number" name="amount" step=".01" min=".01" required placeholder="Сума виплати"><input name="note" placeholder="Примітка / реквізити"><button class="button button-primary">Зафіксувати виплату</button></form>
        </div><?php
    }

    public function handle_admin_action() {
        if (!current_user_can('manage_options')) wp_die('Доступ заборонено.');
        check_admin_referer('gcm_admin');
        global $wpdb; $do=sanitize_key($_POST['gcm_do'] ?? '');
        if ($do==='invite_request') {
            $request_id = absint($_POST['request_id'] ?? 0);
            $request = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}gcm_requests WHERE id=%d", $request_id));
            if (!$request || $request->request_type !== 'edit') wp_die('Заявку на редагування не знайдено.');
            $invitation = $this->send_google_location_invitation($request->place_id, $request->manager_email);
            if (is_wp_error($invitation)) {
                $wpdb->update($wpdb->prefix . 'gcm_requests', array('status'=>'invite_failed', 'last_error'=>$invitation->get_error_message()), array('id'=>$request_id));
            } else {
                $wpdb->update($wpdb->prefix . 'gcm_requests', array('status'=>'invited', 'last_error'=>''), array('id'=>$request_id));
            }
            wp_safe_redirect(admin_url('admin.php?page=gcm-dashboard'));
            exit;
        } elseif ($do==='add_payment') {
            $oid=absint($_POST['order_id']); $order=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->tables['orders']} WHERE id=%d",$oid));
            if ($order) {
                $amount=max(0,(float)$_POST['amount']);
                $already=(float)$wpdb->get_var($wpdb->prepare("SELECT COALESCE(SUM(amount),0) FROM {$this->tables['payments']} WHERE order_id=%d",$oid));
                $remaining=max(0,(float)$order->amount-$already);
                if ($amount>0 && $amount <= $remaining + 0.0001) {
                    $wpdb->insert($this->tables['payments'],array('order_id'=>$oid,'manager_id'=>$order->manager_id,'amount'=>$amount,'commission'=>round($amount*(float)$order->commission_rate/100,2),'status'=>'pending','note'=>sanitize_text_field(wp_unslash($_POST['note'] ?? '')),'created_at'=>current_time('mysql')));
                }
            }
        } elseif ($do==='confirm_payment') {
            $pid=absint($_POST['payment_id']);
            $wpdb->update($this->tables['payments'],array('status'=>'confirmed','confirmed_by'=>get_current_user_id(),'confirmed_at'=>current_time('mysql')),array('id'=>$pid,'status'=>'pending'));
        } elseif ($do==='payout') {
            $mid=absint($_POST['manager_id']); $amount=max(0,(float)$_POST['amount']);
            $earned=(float)$wpdb->get_var($wpdb->prepare("SELECT COALESCE(SUM(commission),0) FROM {$this->tables['payments']} WHERE manager_id=%d AND status='confirmed'",$mid));
            $paid=(float)$wpdb->get_var($wpdb->prepare("SELECT COALESCE(SUM(amount),0) FROM {$this->tables['payouts']} WHERE manager_id=%d",$mid));
            if ($mid && $amount>0 && $amount <= $earned-$paid+0.0001) $wpdb->insert($this->tables['payouts'],array('manager_id'=>$mid,'amount'=>$amount,'note'=>sanitize_text_field(wp_unslash($_POST['note'] ?? '')),'paid_at'=>current_time('mysql'),'created_by'=>get_current_user_id()));
        }
        wp_safe_redirect(admin_url('admin.php?page=gcm-dashboard')); exit;
    }

    public function start_google_admin_connect() {
        if (!current_user_can('manage_options')) wp_die('Доступ заборонено.');
        check_admin_referer('gcm_google_admin_connect');
        $settings = get_option(self::OPT, array());
        if (empty($settings['client_id']) || empty($settings['client_secret'])) wp_die('Спочатку введіть Google OAuth Client ID та Client Secret у налаштуваннях.');
        $uid = get_current_user_id();
        $state = wp_generate_password(32, false, false);
        set_transient('gcm_google_admin_state_' . $uid, $state, 10 * MINUTE_IN_SECONDS);
        $url = add_query_arg(array(
            'client_id'=>$settings['client_id'],
            'redirect_uri'=>$this->google_redirect_uri(),
            'response_type'=>'code',
            'scope'=>'https://www.googleapis.com/auth/business.manage',
            'access_type'=>'offline',
            'prompt'=>'consent',
            'state'=>$uid . '.' . $state,
        ), 'https://accounts.google.com/o/oauth2/v2/auth');
        wp_redirect($url);
        exit;
    }

    public function google_callback() {
        if (!current_user_can('manage_options')) wp_die('Лише адміністратор може підключити центральний Google-акаунт.');
        $state = sanitize_text_field(wp_unslash($_GET['state'] ?? ''));
        $parts = explode('.', $state, 2);
        $uid = absint($parts[0] ?? 0);
        $nonce = $parts[1] ?? '';
        if ($uid !== get_current_user_id() || !$nonce || get_transient('gcm_google_admin_state_' . $uid) !== $nonce) wp_die('Недійсний стан OAuth.');
        delete_transient('gcm_google_admin_state_' . $uid);
        if (!empty($_GET['error'])) wp_die('Google не завершив авторизацію: ' . esc_html(sanitize_text_field(wp_unslash($_GET['error']))));
        $code = sanitize_text_field(wp_unslash($_GET['code'] ?? ''));
        $settings = get_option(self::OPT, array());
        if (!$code || empty($settings['client_id']) || empty($settings['client_secret'])) wp_die('OAuth не налаштований.');
        $response = wp_remote_post('https://oauth2.googleapis.com/token', array('timeout'=>20, 'body'=>array(
            'code'=>$code,
            'client_id'=>$settings['client_id'],
            'client_secret'=>$settings['client_secret'],
            'redirect_uri'=>$this->google_redirect_uri(),
            'grant_type'=>'authorization_code',
        )));
        if (is_wp_error($response)) wp_die(esc_html($response->get_error_message()));
        $body = json_decode(wp_remote_retrieve_body($response), true);
        if (empty($body['access_token']) || empty($body['refresh_token'])) wp_die('Google не повернув refresh token. Повторіть підключення та дозвольте офлайн-доступ.');
        $body['expires_at'] = time() + absint($body['expires_in'] ?? 3600);
        update_option('gcm_google_admin_token', $body, false);
        wp_safe_redirect(admin_url('admin.php?page=gcm-settings&gcm_google=connected'));
        exit;
    }

}
GMaps_Client_Manager::instance();

/** Process frontend forms before output. */
add_action('template_redirect', function() {
    if (!empty($_POST['gcm_front_action'])) GMaps_Client_Manager::instance()->handle_front_post();
}, 1);

/** Ensure rewrite rules are refreshed on first run after activation/update. */
add_action('admin_init', function() {
    if (get_option('gcm_rewrite_version') !== GMaps_Client_Manager::VERSION) {
        flush_rewrite_rules();
        update_option('gcm_rewrite_version', GMaps_Client_Manager::VERSION);
    }
});
