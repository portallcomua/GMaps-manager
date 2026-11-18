<?php
if (!defined('ABSPATH')) exit;

/**
 * Ядро: таблиці, роль, налаштування, медіа, облік оплат і комісій.
 */
final class GCM_Core {
    const ROLE = 'gmaps_manager';
    const CAP  = 'gcm_manage';
    const OPT  = 'gcm_settings';
    const MAX_MEDIA = 12;

    private static $instance = null;

    public static function instance() {
        if (self::$instance === null) self::$instance = new self();
        return self::$instance;
    }

    public static function t($key) {
        global $wpdb;
        return $wpdb->prefix . 'gcm_' . $key;
    }

    private function __construct() {
        add_action('init', array($this, 'register_role'));
        add_action('init', array($this, 'register_rewrite'));
        add_action('init', array($this, 'maybe_upgrade'), 20);
        add_filter('query_vars', array($this, 'query_vars'));
    }

    /* ---------- Установка, роль, маршрути ---------- */

    public static function activate() {
        $c = self::instance();
        $c->create_tables();
        $c->register_role();
        $c->register_rewrite();
        flush_rewrite_rules();
        update_option('gcm_rewrite_version', GCM_VERSION);
    }

    public function register_role() {
        $role = get_role(self::ROLE);
        if (!$role) add_role(self::ROLE, 'GMaps Manager', array('read' => true, self::CAP => true));
        elseif (!$role->has_cap(self::CAP)) $role->add_cap(self::CAP);
        $admin = get_role('administrator');
        if ($admin && !$admin->has_cap(self::CAP)) $admin->add_cap(self::CAP);
    }

    public function register_rewrite() {
        add_rewrite_rule('^gmaps/?$', 'index.php?gcm_dashboard=1', 'top');
        add_rewrite_rule('^gmaps-access/?$', 'index.php?gcm_access=1', 'top');
    }

    public function query_vars($vars) {
        $vars[] = 'gcm_dashboard';
        $vars[] = 'gcm_access';
        return $vars;
    }

    public function maybe_upgrade() {
        if (get_option('gcm_db_version') !== GCM_DB_VERSION) {
            $this->create_tables();
            $this->migrate_20();
        }
        if (get_option('gcm_rewrite_version') !== GCM_VERSION) {
            flush_rewrite_rules();
            update_option('gcm_rewrite_version', GCM_VERSION);
        }
    }

    private function create_tables() {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $charset = $wpdb->get_charset_collate();
        $c = self::t('clients'); $o = self::t('orders'); $p = self::t('payments'); $y = self::t('payouts'); $r = self::t('requests');

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
            KEY manager_id (manager_id),
            KEY email (email)
        ) $charset;");

        dbDelta("CREATE TABLE $o (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            client_id bigint(20) unsigned NOT NULL,
            manager_id bigint(20) unsigned NOT NULL,
            service varchar(190) NOT NULL,
            request_type varchar(20) NOT NULL DEFAULT 'edit',
            amount decimal(12,2) NOT NULL DEFAULT 0,
            commission_rate decimal(5,2) NOT NULL DEFAULT 0,
            status varchar(30) NOT NULL DEFAULT 'draft',
            notes text NULL,
            media longtext NULL,
            place_id varchar(190) NOT NULL DEFAULT '',
            maps_url text NULL,
            biz_name varchar(190) NOT NULL DEFAULT '',
            biz_street varchar(190) NOT NULL DEFAULT '',
            biz_city varchar(120) NOT NULL DEFAULT '',
            biz_postal varchar(20) NOT NULL DEFAULT '',
            biz_phone varchar(60) NOT NULL DEFAULT '',
            biz_website varchar(255) NOT NULL DEFAULT '',
            biz_description text NULL,
            biz_category varchar(190) NOT NULL DEFAULT '',
            biz_category_id varchar(190) NOT NULL DEFAULT '',
            biz_hours longtext NULL,
            photo_category varchar(20) NOT NULL DEFAULT 'ADDITIONAL',
            gbp_account varchar(80) NOT NULL DEFAULT '',
            gbp_location varchar(80) NOT NULL DEFAULT '',
            gbp_error text NULL,
            media_pushed longtext NULL,
            access_token varchar(40) NOT NULL DEFAULT '',
            access_state varchar(20) NOT NULL DEFAULT '',
            published_at datetime NULL,
            created_at datetime NOT NULL,
            PRIMARY KEY  (id),
            KEY manager_id (manager_id),
            KEY client_id (client_id),
            KEY access_token (access_token)
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
            settled_at datetime NULL,
            settled_by bigint(20) unsigned NOT NULL DEFAULT 0,
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
            payment_id bigint(20) unsigned NOT NULL DEFAULT 0,
            direction varchar(5) NOT NULL DEFAULT 'out',
            amount decimal(12,2) NOT NULL DEFAULT 0,
            note varchar(255) NOT NULL DEFAULT '',
            paid_at datetime NOT NULL,
            created_by bigint(20) unsigned NOT NULL,
            PRIMARY KEY  (id),
            KEY manager_id (manager_id),
            KEY payment_id (payment_id)
        ) $charset;");

        // Архів заявок з версії 1.4 (публічна форма прибрана, дані зберігаються).
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

        update_option('gcm_db_version', GCM_DB_VERSION);
    }

    /** Старі виплати (1.x) розподіляються по підтверджених оплатах від найстарішої. */
    private function migrate_20() {
        global $wpdb;
        if (get_option('gcm_mig20')) return;
        $po = self::t('payouts'); $pa = self::t('payments');
        $managers = $wpdb->get_col("SELECT DISTINCT manager_id FROM $po WHERE payment_id=0");
        foreach ((array)$managers as $m) {
            $remaining = (float)$wpdb->get_var($wpdb->prepare("SELECT COALESCE(SUM(amount),0) FROM $po WHERE manager_id=%d AND payment_id=0", $m));
            $rows = $wpdb->get_results($wpdb->prepare("SELECT id,commission FROM $pa WHERE manager_id=%d AND status='confirmed' AND settled_at IS NULL ORDER BY id ASC", $m));
            foreach ((array)$rows as $row) {
                if ($remaining + 0.0001 < (float)$row->commission) break;
                $wpdb->update($pa, array('settled_at' => current_time('mysql')), array('id' => $row->id));
                $remaining -= (float)$row->commission;
            }
        }
        update_option('gcm_mig20', 1);
    }

    /* ---------- Налаштування ---------- */

    public static function settings() { return (array)get_option(self::OPT, array()); }
    public static function setting($key, $default = '') {
        $s = self::settings();
        return (isset($s[$key]) && $s[$key] !== '') ? $s[$key] : $default;
    }

    public static function currency() { return get_option('gcm_currency', 'грн'); }

    public static function money($n) {
        return number_format((float)$n, 2, '.', ' ') . ' ' . esc_html(self::currency());
    }

    public static function privat_link() {
        $u = trim((string)self::setting('privat_link', ''));
        return (stripos($u, 'https://') === 0) ? esc_url($u) : '';
    }

    /** Відсоток менеджера: особистий (якщо заданий адміном) або загальний. */
    public static function commission_rate($user_id) {
        $own = get_user_meta($user_id, 'gcm_commission_rate', true);
        if ($own !== '' && $own !== false && is_numeric($own)) return max(0, min(100, (float)$own));
        return max(0, min(100, (float)self::setting('default_commission', 20)));
    }

    public static function can_manage() {
        return is_user_logged_in() && current_user_can(self::CAP);
    }

    public static function is_admin_user() { return current_user_can('manage_options'); }

    /* ---------- Роботи, клієнти ---------- */

    public static function get_case($id) {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare("SELECT * FROM " . self::t('orders') . " WHERE id=%d", absint($id)));
    }

    public static function user_owns_case($case, $uid = 0) {
        if (!$case) return false;
        $uid = $uid ?: get_current_user_id();
        return self::is_admin_user() || (int)$case->manager_id === (int)$uid;
    }

    public static function get_client($id) {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare("SELECT * FROM " . self::t('clients') . " WHERE id=%d", absint($id)));
    }

    public static function client_by_email($email) {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare("SELECT * FROM " . self::t('clients') . " WHERE email=%s LIMIT 1", $email));
    }

    public static function status_label($status) {
        $l = array(
            'new' => 'Чернетка', 'draft' => 'Чернетка', 'needs_access' => 'Потрібен доступ до картки',
            'published' => 'Опубліковано', 'created' => 'Створено — потрібна верифікація Google',
            'error' => 'Помилка публікації', 'done' => 'Виконано',
        );
        return $l[$status] ?? $status;
    }

    public static function type_label($t) {
        return array('edit' => 'Редагування картки', 'create' => 'Створення картки', 'reviews' => 'Збір відгуків')[$t] ?? $t;
    }

    public static function method_label($m) {
        return array('cash' => 'Готівка менеджеру', 'card' => 'Картка / Приват Конверт', 'later' => 'Ще не оплачено')[$m] ?? 'Картка / Приват Конверт';
    }

    /* ---------- Оплати та комісії ---------- */

    public static function order_reserved($order_id) {
        global $wpdb;
        return (float)$wpdb->get_var($wpdb->prepare("SELECT COALESCE(SUM(amount),0) FROM " . self::t('payments') . " WHERE order_id=%d AND status IN ('pending','confirmed')", $order_id));
    }

    /** Створює оплату. Повертає id або WP_Error. */
    public static function add_payment($order, $amount, $method, $note = '', $status = 'pending') {
        global $wpdb;
        $amount = round((float)$amount, 2);
        if ($amount <= 0) return new WP_Error('gcm_pay', 'Сума оплати має бути більшою за нуль.');
        if (!in_array($method, array('cash', 'card'), true)) return new WP_Error('gcm_pay', 'Оберіть спосіб оплати: готівка або картка.');
        $remaining = round((float)$order->amount - self::order_reserved($order->id), 2);
        if ($amount > $remaining + 0.0001) return new WP_Error('gcm_pay', 'Сума оплати більша за залишок по замовленню (' . self::money(max(0, $remaining)) . ').');
        $ok = $wpdb->insert(self::t('payments'), array(
            'order_id' => $order->id, 'manager_id' => $order->manager_id, 'amount' => $amount,
            'commission' => round($amount * (float)$order->commission_rate / 100, 2), 'method' => $method,
            'status' => $status, 'note' => mb_substr(sanitize_text_field($note), 0, 255), 'created_at' => current_time('mysql'),
            'confirmed_by' => $status === 'confirmed' ? get_current_user_id() : 0,
            'confirmed_at' => $status === 'confirmed' ? current_time('mysql') : null,
        ));
        return $ok ? (int)$wpdb->insert_id : new WP_Error('gcm_pay', 'Не вдалося зберегти оплату.');
    }

    public static function confirm_payment($pid) {
        global $wpdb;
        return (bool)$wpdb->update(self::t('payments'), array('status' => 'confirmed', 'confirmed_by' => get_current_user_id(), 'confirmed_at' => current_time('mysql')), array('id' => absint($pid), 'status' => 'pending'));
    }

    /**
     * Розрахунок по оплаті.
     * Картка: гроші в адміна -> адмін виплачує менеджеру комісію.
     * Готівка: гроші в менеджера -> менеджер передає адміну (сума − комісія).
     */
    public static function settle_payment($pid) {
        global $wpdb;
        $pa = self::t('payments');
        $p = $wpdb->get_row($wpdb->prepare("SELECT * FROM $pa WHERE id=%d AND status='confirmed' AND settled_at IS NULL", absint($pid)));
        if (!$p) return false;
        $cash = ($p->method === 'cash');
        $amount = $cash ? round((float)$p->amount - (float)$p->commission, 2) : (float)$p->commission;
        $wpdb->update($pa, array('settled_at' => current_time('mysql'), 'settled_by' => get_current_user_id()), array('id' => $p->id));
        $wpdb->insert(self::t('payouts'), array(
            'manager_id' => $p->manager_id, 'payment_id' => $p->id, 'direction' => $cash ? 'in' : 'out', 'amount' => $amount,
            'note' => $cash ? 'Менеджер передав готівку адміністратору' : 'Виплата комісії менеджеру',
            'paid_at' => current_time('mysql'), 'created_by' => get_current_user_id(),
        ));
        return true;
    }

    public static function balances($uid) {
        global $wpdb;
        $b = array('confirmed_total' => 0.0, 'earned' => 0.0, 'pending_total' => 0.0, 'pending_comm' => 0.0, 'to_manager' => 0.0, 'to_admin' => 0.0, 'paid_out' => 0.0);
        $rows = $wpdb->get_results($wpdb->prepare("SELECT method,status,amount,commission,settled_at FROM " . self::t('payments') . " WHERE manager_id=%d", $uid));
        foreach ((array)$rows as $r) {
            if ($r->status === 'confirmed') {
                $b['confirmed_total'] += (float)$r->amount;
                $b['earned'] += (float)$r->commission;
                if ($r->settled_at) { if ($r->method !== 'cash') $b['paid_out'] += (float)$r->commission; }
                elseif ($r->method === 'cash') $b['to_admin'] += (float)$r->amount - (float)$r->commission;
                else $b['to_manager'] += (float)$r->commission;
            } elseif ($r->status === 'pending') {
                $b['pending_total'] += (float)$r->amount;
                $b['pending_comm'] += (float)$r->commission;
            }
        }
        return $b;
    }

    /** Людський опис стану розрахунку по оплаті (для кабінету менеджера). */
    public static function settlement_text($p) {
        if ($p->status === 'pending') return 'Очікує підтвердження адміністратора';
        if ($p->status !== 'confirmed') return $p->status;
        $cash = ($p->method === 'cash');
        if ($p->settled_at) return $cash ? 'Розраховано з адміністратором' : 'Комісію виплачено ' . mysql2date('d.m.Y', $p->settled_at);
        if ($cash) return 'Комісію утримано; передайте адміністратору ' . self::money((float)$p->amount - (float)$p->commission);
        return 'Комісію ще не виплачено';
    }

    /* ---------- Медіа ---------- */

    public static function media_ids($json) {
        $ids = json_decode((string)$json, true);
        return is_array($ids) ? array_values(array_filter(array_map('absint', $ids))) : array();
    }

    public static function media_items($json) {
        $out = array();
        foreach (self::media_ids($json) as $id) {
            $url = wp_get_attachment_url($id);
            if (!$url) continue;
            $mime = (string)get_post_mime_type($id);
            $out[] = array('id' => $id, 'url' => $url, 'video' => strpos($mime, 'video/') === 0);
        }
        return $out;
    }

    /** Завантаження фото/відео з форми. Повертає масив id вкладень або WP_Error. */
    public static function upload_media($field_names, $already = 0) {
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';
        $allowed = array('jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp', 'mp4' => 'video/mp4', 'webm' => 'video/webm', 'mov' => 'video/quicktime');
        $max_bytes = max(1, (int)self::setting('max_file_mb', 64)) * 1024 * 1024;
        $ids = array();
        foreach ($field_names as $field) {
            if (empty($_FILES[$field]['name']) || !is_array($_FILES[$field]['name'])) continue;
            $f = $_FILES[$field];
            foreach ($f['name'] as $i => $name) {
                if ($f['error'][$i] === UPLOAD_ERR_NO_FILE) continue;
                if (count($ids) + $already >= self::MAX_MEDIA) { self::discard($ids); return new WP_Error('gcm_media_limit', 'Можна додати не більше ' . self::MAX_MEDIA . ' файлів до однієї роботи.'); }
                if ($f['error'][$i] !== UPLOAD_ERR_OK) { self::discard($ids); return new WP_Error('gcm_upload', 'Не вдалося завантажити файл «' . sanitize_file_name($name) . '». Перевірте розмір (ліміт сервера: ' . size_format(wp_max_upload_size()) . ').'); }
                if ($f['size'][$i] > $max_bytes) { self::discard($ids); return new WP_Error('gcm_upload', 'Файл «' . sanitize_file_name($name) . '» більший за ' . (int)($max_bytes / 1048576) . ' МБ.'); }
                $file = array('name' => sanitize_file_name($name), 'type' => $f['type'][$i], 'tmp_name' => $f['tmp_name'][$i], 'error' => $f['error'][$i], 'size' => $f['size'][$i]);
                $chk = wp_check_filetype_and_ext($file['tmp_name'], $file['name'], $allowed);
                if (empty($chk['type']) || !in_array($chk['type'], array_values($allowed), true)) { self::discard($ids); return new WP_Error('gcm_file_type', 'Дозволені фото JPG, PNG, WebP та відео MP4, WebM, MOV.'); }
                $up = wp_handle_upload($file, array('test_form' => false, 'mimes' => $allowed));
                if (!empty($up['error'])) { self::discard($ids); return new WP_Error('gcm_upload', sanitize_text_field($up['error'])); }
                $aid = wp_insert_attachment(array('post_mime_type' => $up['type'], 'post_title' => sanitize_file_name(pathinfo($file['name'], PATHINFO_FILENAME)), 'post_status' => 'inherit'), $up['file']);
                if (is_wp_error($aid) || !$aid) { wp_delete_file($up['file']); self::discard($ids); return new WP_Error('gcm_media_save', 'Не вдалося зберегти медіафайл.'); }
                if (strpos($up['type'], 'image/') === 0) {
                    $meta = wp_generate_attachment_metadata($aid, $up['file']);
                    if ($meta) wp_update_attachment_metadata($aid, $meta);
                }
                $ids[] = (int)$aid;
            }
        }
        return $ids;
    }

    public static function discard($ids) {
        foreach ((array)$ids as $id) wp_delete_attachment((int)$id, true);
    }

    /* ---------- Години роботи ---------- */

    public static function hours_from_post($src) {
        $out = array();
        if (empty($src['hours_on'])) return $out;
        for ($i = 1; $i <= 7; $i++) {
            $off = !empty($src['hours_off'][$i]);
            $open = sanitize_text_field(wp_unslash($src['hours_open'][$i] ?? ''));
            $close = sanitize_text_field(wp_unslash($src['hours_close'][$i] ?? ''));
            if (!preg_match('/^\d{1,2}:\d{2}$/', $open)) $open = '';
            if (!preg_match('/^\d{1,2}:\d{2}$/', $close)) $close = '';
            $out[$i] = array('off' => $off ? 1 : 0, 'open' => $open, 'close' => $close);
        }
        return $out;
    }

    public static function day_names() {
        return array(1 => 'Понеділок', 2 => 'Вівторок', 3 => 'Середа', 4 => 'Четвер', 5 => 'П’ятниця', 6 => 'Субота', 7 => 'Неділя');
    }

    public static function hours_lines($hours) {
        $lines = array(); $names = self::day_names();
        foreach ($names as $i => $n) {
            $h = $hours[$i] ?? null;
            if (!$h) continue;
            $lines[] = $n . ': ' . (!empty($h['off']) ? 'вихідний' : (($h['open'] && $h['close']) ? $h['open'] . '–' . $h['close'] : '—'));
        }
        return $lines;
    }
}
