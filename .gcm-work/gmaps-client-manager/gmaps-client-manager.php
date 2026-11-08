<?php
/**
 * Plugin Name: GMaps Client Manager
 * Description: CRM for Google Business Profile managers: clients, orders, payments, commissions and payouts.
 * Version: 1.2.0
 * Author: UAserver
 * Text Domain: gmaps-client-manager
 */
if (!defined('ABSPATH')) exit;

final class GMaps_Client_Manager {
    const VERSION = '1.2.0';
    const DB_VERSION = '1.2';
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
        add_action('wp_ajax_gcm_lookup_email', array($this, 'ajax_lookup_email'));
        add_action('admin_post_nopriv_gcm_google_callback', array($this, 'google_callback'));
        add_action('admin_post_gcm_google_callback', array($this, 'google_callback'));
        add_shortcode('gmaps_client_manager', array($this, 'shortcode'));
        add_shortcode('gmaps_manager_link', array($this, 'manager_link_shortcode'));
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
    private function is_manager() {
        $u = wp_get_current_user();
        return is_user_logged_in() && (current_user_can('manage_options') || in_array(self::ROLE, (array)$u->roles, true));
    }
    private function is_admin_user() { return current_user_can('manage_options'); }
    private function notice($msg, $type='info') { return '<div class="gcm-alert">'.esc_html($msg).'</div>'; }
    private function redirect_dashboard($msg='') {
        $url = home_url('/gmaps/');
        if ($msg) $url = add_query_arg('gcm_msg', rawurlencode($msg), $url);
        wp_safe_redirect($url); exit;
    }
    private function verify_front() {
        if (!is_user_logged_in() || !$this->is_manager()) wp_die('Доступ заборонено.');
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

    public function manager_link_shortcode() {
        $url = is_user_logged_in() ? home_url('/gmaps/') : wp_login_url(home_url('/gmaps/'));
        return '<a class="gcm-btn" href="'.esc_url($url).'">Кабінет менеджера</a>';
    }

    private function dashboard_html() {
        if (!is_user_logged_in()) {
            $login = wp_login_url(home_url('/gmaps/'));
            return '<div class="gcm-wrap"><h2>GMaps Client Manager</h2><p>Увійдіть, щоб відкрити кабінет менеджера.</p><a class="gcm-btn" href="'.esc_url($login).'">Увійти</a></div>';
        }
        if (!$this->is_manager()) return '<div class="gcm-wrap">Ваш обліковий запис не має ролі GMaps Manager.</div>';
        global $wpdb;
        $uid = get_current_user_id(); $c=$this->tables['clients']; $o=$this->tables['orders']; $p=$this->tables['payments'];
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
                        <a href="#gcm-order">Нове замовлення</a><a href="#gcm-clients">Клієнти</a><a href="#gcm-orders">Замовлення</a>
                    </nav>
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
          <div class="gcm-box"><h3>Google Business Profile</h3>
            <p>Google-пошта клієнта зберігається в картці клієнта. Для підключення Google OAuth адміністратор має налаштувати центральні Client ID та Client Secret у параметрах плагіна. Пароль Google клієнта або менеджера не запитується.</p>
            <?php $settings=get_option(self::OPT,array()); if(!empty($settings['client_id'])): ?>
              <a class="gcm-btn" href="<?php echo esc_url($this->google_auth_url($uid)); ?>">Підключити Google-акаунт менеджера</a>
            <?php else: ?><p class="gcm-muted">Підключення ще не налаштоване адміністратором.</p><?php endif; ?>
          </div>
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
        if (!is_user_logged_in() || !$this->is_manager()) wp_send_json_error(array('message'=>'Доступ заборонено.'),403);
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
        return array('client_id'=>sanitize_text_field($in['client_id'] ?? ''),'client_secret'=>sanitize_text_field($in['client_secret'] ?? ''),'redirect_uri'=>esc_url_raw($in['redirect_uri'] ?? $this->google_redirect_uri()));
    }
    private function google_redirect_uri() { return admin_url('admin-post.php?action=gcm_google_callback'); }
    public function settings_page() {
        $s=get_option(self::OPT,array()); ?>
        <div class="wrap"><h1>GMaps Client Manager — налаштування</h1><form method="post" action="options.php"><?php settings_fields('gcm_settings_group'); ?>
        <table class="form-table"><tr><th>Google OAuth Client ID</th><td><input class="regular-text" name="<?php echo esc_attr(self::OPT); ?>[client_id]" value="<?php echo esc_attr($s['client_id'] ?? ''); ?>"></td></tr>
        <tr><th>Google OAuth Client Secret</th><td><input class="regular-text" type="password" name="<?php echo esc_attr(self::OPT); ?>[client_secret]" value="<?php echo esc_attr($s['client_secret'] ?? ''); ?>" autocomplete="new-password"></td></tr>
        <tr><th>Redirect URI</th><td><input class="regular-text" readonly value="<?php echo esc_attr($this->google_redirect_uri()); ?>"><p class="description">Додайте цю адресу в Authorized redirect URIs у Google Cloud Console.</p></td></tr>
        <tr><th>Валюта</th><td><input name="gcm_currency" value="<?php echo esc_attr(get_option('gcm_currency','грн')); ?>"></td></tr></table><?php submit_button(); ?></form>
        <p><strong>Потрібно:</strong> увімкнути Google Business Profile API та налаштувати OAuth consent screen у Google Cloud. Ключі зберігаються лише в налаштуваннях сайту.</p></div><?php
    }

    public function admin_page() {
        global $wpdb; $orders=$wpdb->get_results("SELECT o.*,c.name client_name,u.display_name manager_name FROM {$this->tables['orders']} o LEFT JOIN {$this->tables['clients']} c ON c.id=o.client_id LEFT JOIN {$wpdb->users} u ON u.ID=o.manager_id ORDER BY o.id DESC");
        $payments=$wpdb->get_results("SELECT p.*,o.service,u.display_name manager_name FROM {$this->tables['payments']} p LEFT JOIN {$this->tables['orders']} o ON o.id=p.order_id LEFT JOIN {$wpdb->users} u ON u.ID=p.manager_id ORDER BY p.id DESC");
        $managers=get_users(array('role'=>self::ROLE)); ?>
        <div class="wrap"><h1>GMaps Client Manager</h1>
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
        if ($do==='add_payment') {
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

    private function google_auth_url($uid) {
        $s=get_option(self::OPT,array());
        $redirect=$s['redirect_uri'] ?? $this->google_redirect_uri();
        $state=wp_create_nonce('gcm_google_'.$uid);
        set_transient('gcm_google_state_'.$uid,$state,10*MINUTE_IN_SECONDS);
        return add_query_arg(array('client_id'=>$s['client_id'],'redirect_uri'=>$redirect,'response_type'=>'code','scope'=>'https://www.googleapis.com/auth/business.manage','access_type'=>'offline','prompt'=>'consent','state'=>$uid.'.'.$state), 'https://accounts.google.com/o/oauth2/v2/auth');
    }

    public function google_callback() {
        $state=sanitize_text_field(wp_unslash($_GET['state'] ?? ''));
        $parts=explode('.',$state,2); $uid=absint($parts[0] ?? 0); $nonce=$parts[1] ?? '';
        if (!$uid || !$nonce || get_transient('gcm_google_state_'.$uid)!==$nonce || !wp_verify_nonce($nonce,'gcm_google_'.$uid)) wp_die('Недійсний стан OAuth.');
        delete_transient('gcm_google_state_'.$uid);
        if (!empty($_GET['error'])) wp_die('Google не завершив авторизацію: '.esc_html(sanitize_text_field(wp_unslash($_GET['error']))));
        $code=sanitize_text_field(wp_unslash($_GET['code'] ?? ''));
        $s=get_option(self::OPT,array());
        if (!$code || empty($s['client_id']) || empty($s['client_secret'])) wp_die('OAuth не налаштований.');
        $response=wp_remote_post('https://oauth2.googleapis.com/token',array('timeout'=>20,'body'=>array('code'=>$code,'client_id'=>$s['client_id'],'client_secret'=>$s['client_secret'],'redirect_uri'=>$s['redirect_uri'] ?? $this->google_redirect_uri(),'grant_type'=>'authorization_code')));
        if (is_wp_error($response)) wp_die(esc_html($response->get_error_message()));
        $body=json_decode(wp_remote_retrieve_body($response),true);
        if (empty($body['access_token'])) wp_die('Google не повернув access token. Перевірте налаштування OAuth.');
        update_user_meta($uid,'gcm_google_token',$body);
        wp_safe_redirect(home_url('/gmaps/?gcm_msg='.rawurlencode('Google-акаунт менеджера підключено.'))); exit;
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
