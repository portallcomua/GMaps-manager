<?php
if (!defined('ABSPATH')) exit;

final class GCM_Admin {
    private static $instance = null;

    public static function instance() {
        if (self::$instance === null) self::$instance = new self();
        return self::$instance;
    }

    private function __construct() {
        add_action('admin_menu', array($this, 'menu'));
        add_action('admin_init', array($this, 'register_settings'));
        add_action('admin_post_gcm_action', array($this, 'handle'));
        add_action('admin_post_gcm_google_admin_connect', array($this, 'google_connect'));
        add_action('admin_post_gcm_google_callback', array($this, 'google_callback'));
    }

    public function menu() {
        add_menu_page('GMaps Manager', 'GMaps Manager', 'manage_options', 'gcm-dashboard', array($this, 'page'), 'dashicons-location-alt', 56);
    }

    public function register_settings() {
        register_setting('gcm_settings_group', GCM_Core::OPT, array('sanitize_callback' => array($this, 'sanitize_settings')));
        register_setting('gcm_settings_group', 'gcm_currency', array('sanitize_callback' => 'sanitize_text_field'));
    }

    public function sanitize_settings($in) {
        $in = is_array($in) ? $in : array();
        $old = GCM_Core::settings();

        $keep = function ($k) use ($in, $old) {
            $v = isset($in[$k]) ? sanitize_text_field($in[$k]) : '';

            return (
                $v === '' &&
                !empty($old[$k]) &&
                in_array($k, array('client_secret', 'places_api_key'), true) &&
                !isset($in[$k . '_clear'])
            ) ? $old[$k] : $v;
        };

        $privat = isset($in['privat_link'])
            ? esc_url_raw(trim($in['privat_link']))
            : '';

        if ($privat && stripos($privat, 'https://') !== 0) {
            $privat = '';
        }

        /*
         * location_group_id — ресурс Location Group у Business Profile.
         * Приймаємо як числовий ID, так і accounts/{ID}.
         */
        $location_group_id = trim(
            sanitize_text_field($in['location_group_id'] ?? '')
        );

        if ($location_group_id !== '') {
            if (preg_match('/^[0-9]+$/', $location_group_id)) {
                $location_group_id = 'accounts/' . $location_group_id;
            }

            if (!preg_match('/^accounts\/[0-9]+$/', $location_group_id)) {
                $location_group_id = '';
            }
        }

        return array(
            'places_api_key' => $keep('places_api_key'),
            'client_id' => sanitize_text_field($in['client_id'] ?? ''),
            'client_secret' => $keep('client_secret'),
            'privat_link' => $privat,
            'default_commission' => max(0, min(100, (float)($in['default_commission'] ?? 20))),
            'central_email' => sanitize_email($in['central_email'] ?? ''),
            'gbp_account' => sanitize_text_field($in['gbp_account'] ?? ''),
            'location_group_id' => $location_group_id,
            'max_file_mb' => max(1, min(500, (int)($in['max_file_mb'] ?? 64))),
        );
    }

    private function url($tab, $args = array()) {
        return add_query_arg(
            array_merge(
                array('page' => 'gcm-dashboard', 'tab' => $tab),
                $args
            ),
            admin_url('admin.php')
        );
    }

    private function form($do, $fields, $label, $class = 'button button-primary', $confirm = '') {
        $o = '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="display:inline-block;margin:2px">';
        $o .= wp_nonce_field('gcm_admin', '_wpnonce', true, false) . '<input type="hidden" name="action" value="gcm_action"><input type="hidden" name="gcm_do" value="' . esc_attr($do) . '">';

        foreach ($fields as $k => $v) {
            $o .= '<input type="hidden" name="' . esc_attr($k) . '" value="' . esc_attr($v) . '">';
        }

        $o .= '<button class="' . esc_attr($class) . '"' .
            ($confirm ? ' onclick="return confirm(\'' . esc_js($confirm) . '\')"' : '') .
            '>' . esc_html($label) . '</button></form>';

        return $o;
    }

    public function page() {
        if (!current_user_can('manage_options')) return;

        $tab = sanitize_key($_GET['tab'] ?? 'cases');

        $tabs = array(
            'cases' => 'Роботи',
            'payments' => 'Оплати та розрахунки',
            'managers' => 'Менеджери',
            'settings' => 'Налаштування'
        );

        echo '<div class="wrap"><h1>GMaps Client Manager</h1>';

        if (!empty($_GET['gcm_notice'])) {
            echo '<div class="notice notice-info is-dismissible"><p>' .
                esc_html(sanitize_text_field(wp_unslash($_GET['gcm_notice']))) .
                '</p></div>';
        }

        echo '<h2 class="nav-tab-wrapper">';

        foreach ($tabs as $k => $l) {
            echo '<a class="nav-tab ' .
                ($tab === $k ? 'nav-tab-active' : '') .
                '" href="' . esc_url($this->url($k)) . '">' .
                esc_html($l) . '</a>';
        }

        echo '</h2>';

        if ($tab === 'payments') {
            $this->tab_payments();
        } elseif ($tab === 'managers') {
            $this->tab_managers();
        } elseif ($tab === 'settings') {
            $this->tab_settings();
        } else {
            $this->tab_cases();
        }

        echo '</div>';
    }

    /* ---------- Вкладка: Роботи ---------- */

    private function tab_cases() {
        global $wpdb;

        $o = GCM_Core::t('orders');
        $c = GCM_Core::t('clients');

        $rows = $wpdb->get_results(
            "SELECT o.*, c.name client_name, c.email client_email,
                u.display_name manager_name
            FROM $o o
            LEFT JOIN $c c ON c.id=o.client_id
            LEFT JOIN {$wpdb->users} u ON u.ID=o.manager_id
            ORDER BY o.id DESC
            LIMIT 200"
        );

        echo '<p>Роботи менеджерів. Відкрийте роботу, щоб переглянути зміни, перевірити доступ чи опублікувати за менеджера.</p>';

        echo '<table class="widefat striped"><thead><tr><th>#</th><th>Клієнт</th><th>Бізнес</th><th>Послуга</th><th>Менеджер</th><th>Сума</th><th>Статус</th><th></th></tr></thead><tbody>';

        foreach ((array)$rows as $r) {
            $open = add_query_arg(
                array('gcm_tab' => 'case', 'id' => $r->id),
                home_url('/gmaps/')
            );

            echo '<tr><td>' . (int)$r->id .
                '</td><td>' . esc_html($r->client_name . ' ' . $r->client_email) .
                '</td><td>' . esc_html($r->biz_name) .
                '</td><td>' . esc_html(GCM_Core::type_label($r->request_type)) .
                '</td><td>' . esc_html($r->manager_name) .
                '</td><td>' . esc_html(GCM_Core::money($r->amount)) .
                '</td><td>' . esc_html(GCM_Core::status_label($r->status)) .
                ($r->gbp_error ? '<br><span class="description">' . esc_html(mb_substr($r->gbp_error, 0, 160)) . '</span>' : '') .
                '</td><td><a class="button" href="' . esc_url($open) .
                '" target="_blank" rel="noopener">Відкрити</a></td></tr>';
        }

        if (!$rows) {
            echo '<tr><td colspan="8">Робіт поки немає.</td></tr>';
        }

        echo '</tbody></table>';

        $legacy = $wpdb->get_results(
            "SELECT * FROM " . GCM_Core::t('requests') . " ORDER BY id DESC LIMIT 50"
        );

        if ($legacy) {
            echo '<h2 style="margin-top:28px">Архів заявок із публічної форми (версія 1.4)</h2><table class="widefat striped"><thead><tr><th>#</th><th>Клієнт</th><th>Бізнес</th><th>Менеджер</th><th>Статус</th><th>Створено</th></tr></thead><tbody>';

            foreach ($legacy as $r) {
                echo '<tr><td>' . (int)$r->id .
                    '</td><td>' . esc_html($r->customer_email) .
                    '</td><td>' . esc_html($r->business_name . ' ' . $r->business_address) .
                    '</td><td>' . esc_html($r->manager_email) .
                    '</td><td>' . esc_html($r->status) .
                    '</td><td>' . esc_html($r->created_at) .
                    '</td></tr>';
            }

            echo '</tbody></table>';
        }
    }

    /* ---------- Вкладка: Оплати ---------- */

    private function tab_payments() {
        global $wpdb;

        $pa = GCM_Core::t('payments');
        $o = GCM_Core::t('orders');
        $c = GCM_Core::t('clients');

        $sql = "SELECT p.*, o.service, c.name client_name,
            u.display_name manager_name
            FROM $pa p
            LEFT JOIN $o o ON o.id=p.order_id
            LEFT JOIN $c c ON c.id=o.client_id
            LEFT JOIN {$wpdb->users} u ON u.ID=p.manager_id";

        $pending = $wpdb->get_results(
            $sql . " WHERE p.status='pending' ORDER BY p.id DESC"
        );

        $unsettled = $wpdb->get_results(
            $sql . " WHERE p.status='confirmed' AND p.settled_at IS NULL ORDER BY p.id ASC"
        );

        $history = $wpdb->get_results(
            $sql . " WHERE p.status='confirmed' AND p.settled_at IS NOT NULL ORDER BY p.settled_at DESC LIMIT 50"
        );

        echo '<h2>1. Підтвердіть надходження коштів</h2><p class="description">Картка — коли побачили гроші на Приват Конверті. Готівка — коли менеджер повідомив про отримання.</p>';
        echo '<table class="widefat striped"><thead><tr><th>#</th><th>Клієнт / послуга</th><th>Менеджер</th><th>Сума</th><th>Спосіб</th><th>Комісія</th><th>Примітка</th><th></th></tr></thead><tbody>';

        foreach ((array)$pending as $p) {
            echo '<tr><td>' . (int)$p->id .
                '</td><td>' . esc_html($p->client_name . ' / ' . $p->service) .
                '</td><td>' . esc_html($p->manager_name) .
                '</td><td>' . esc_html(GCM_Core::money($p->amount)) .
                '</td><td>' . esc_html(GCM_Core::method_label($p->method)) .
                '</td><td>' . esc_html(GCM_Core::money($p->commission)) .
                '</td><td>' . esc_html($p->note) .
                '</td><td>' . $this->form('confirm_payment', array('payment_id' => $p->id), 'Підтвердити') .
                '</td></tr>';
        }

        if (!$pending) {
            echo '<tr><td colspan="8">Немає оплат, що чекають підтвердження.</td></tr>';
        }

        echo '</tbody></table>';

        echo '<h2 style="margin-top:28px">2. Розрахунки з менеджерами</h2><p class="description">Картка: гроші в вас — виплатіть менеджеру комісію. Готівка: гроші в менеджера — він передає вам суму мінус комісія.</p>';
        echo '<table class="widefat striped"><thead><tr><th>#</th><th>Клієнт / послуга</th><th>Менеджер</th><th>Сума</th><th>Спосіб</th><th>Що зробити</th><th></th></tr></thead><tbody>';

        foreach ((array)$unsettled as $p) {
            $cash = ($p->method === 'cash');

            $what = $cash
                ? 'Отримати від менеджера ' . GCM_Core::money($p->amount - $p->commission)
                : 'Виплатити менеджеру ' . GCM_Core::money($p->commission);

            echo '<tr><td>' . (int)$p->id .
                '</td><td>' . esc_html($p->client_name . ' / ' . $p->service) .
                '</td><td>' . esc_html($p->manager_name) .
                '</td><td>' . esc_html(GCM_Core::money($p->amount)) .
                '</td><td>' . esc_html(GCM_Core::method_label($p->method)) .
                '</td><td>' . esc_html($what) .
                '</td><td>' . $this->form('settle_payment', array('payment_id' => $p->id), $cash ? 'Гроші отримано' : 'Комісію виплачено') .
                '</td></tr>';
        }

        if (!$unsettled) {
            echo '<tr><td colspan="7">Усе розраховано.</td></tr>';
        }

        echo '</tbody></table>';

        echo '<h2 style="margin-top:28px">Підсумок по менеджерах</h2><table class="widefat striped"><thead><tr><th>Менеджер</th><th>Підтверджено оплат</th><th>Комісія нарахована</th><th>Вам виплатити йому</th><th>Він має передати вам</th></tr></thead><tbody>';

        foreach (get_users(array('role' => GCM_Core::ROLE)) as $m) {
            $b = GCM_Core::balances($m->ID);

            echo '<tr><td>' . esc_html($m->display_name) .
                '</td><td>' . esc_html(GCM_Core::money($b['confirmed_total'])) .
                '</td><td>' . esc_html(GCM_Core::money($b['earned'])) .
                '</td><td>' . esc_html(GCM_Core::money($b['to_manager'])) .
                '</td><td>' . esc_html(GCM_Core::money($b['to_admin'])) .
                '</td></tr>';
        }

        echo '</tbody></table>';

        if ($history) {
            echo '<h2 style="margin-top:28px">Остані розрахунки</h2><table class="widefat striped"><tbody>';

            foreach ($history as $p) {
                echo '<tr><td>' . esc_html(mysql2date('d.m.Y', $p->settled_at)) .
                    '</td><td>' . esc_html($p->manager_name) .
                    '</td><td>' . esc_html($p->client_name . ' / ' . $p->service) .
                    '</td><td>' . esc_html(GCM_Core::money($p->amount)) .
                    '</td><td>' . esc_html(GCM_Core::method_label($p->method)) .
                    '</td></tr>';
            }

            echo '</tbody></table>';
        }
    }

    /* ---------- Вкладка: Менеджери ---------- */

    private function tab_managers() {
        global $wpdb;

        $pending = get_users(array(
            'meta_key' => 'gcm_pending',
            'meta_value' => '1'
        ));

        $pending = array_filter($pending, function ($u) {
            return !in_array(GCM_Core::ROLE, (array)$u->roles, true);
        });

        echo '<h2>Нові реєстрації — очікують на роль</h2>';
        echo '<p class="description">Менеджер реєструється сам (шорткод <code>[gmaps_manager_register]</code> або стандартна реєстрація WordPress). Тут ви одним кліком призначаєте йому роль Gmaps manager.</p>';
        echo '<table class="widefat striped"><thead><tr><th>Ім’я</th><th>Email</th><th>Зареєстровано</th><th></th></tr></thead><tbody>';

        foreach ($pending as $u) {
            echo '<tr><td>' . esc_html($u->display_name) .
                '</td><td>' . esc_html($u->user_email) .
                '</td><td>' . esc_html($u->user_registered) .
                '</td><td>' . $this->form('assign_role', array('user_id' => $u->ID), 'Призначити менеджером') .
                '</td></tr>';
        }

        if (!$pending) {
            echo '<tr><td colspan="4">Немає нових реєстрацій.</td></tr>';
        }

        echo '</tbody></table>';

        echo '<h2 style="margin-top:28px">Активні менеджери</h2><p class="description">Загальний відсоток комісії задається в Налаштуваннях. Тут можна вказати особистий відсоток (порожньо = загальний).</p>';
        echo '<table class="widefat striped"><thead><tr><th>Менеджер</th><th>Email</th><th>Відсоток</th><th>Клієнтів</th><th></th></tr></thead><tbody>';

        foreach (get_users(array('role' => GCM_Core::ROLE)) as $m) {
            $own = get_user_meta($m->ID, 'gcm_commission_rate', true);

            $cnt = (int)$wpdb->get_var(
                $wpdb->prepare(
                    "SELECT COUNT(*) FROM " . GCM_Core::t('clients') . " WHERE manager_id=%d",
                    $m->ID
                )
            );

            echo '<tr><td>' . esc_html($m->display_name) .
                '</td><td>' . esc_html($m->user_email) .
                '</td><td>';

            echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="display:inline-flex;gap:6px">' .
                wp_nonce_field('gcm_admin', '_wpnonce', true, false) .
                '<input type="hidden" name="action" value="gcm_action">' .
                '<input type="hidden" name="gcm_do" value="set_rate">' .
                '<input type="hidden" name="user_id" value="' . (int)$m->ID . '">' .
                '<input type="number" step="0.01" min="0" max="100" name="rate" value="' .
                esc_attr($own) . '" placeholder="' .
                esc_attr(GCM_Core::setting('default_commission', 20)) .
                '" style="width:90px"> % <button class="button">Зберегти</button></form>';

            echo '</td><td>' . $cnt .
                '</td><td>' . $this->form('remove_role', array('user_id' => $m->ID), 'Забрати роль', 'button', 'Забрати роль менеджера?') .
                '</td></tr>';
        }

        echo '</tbody></table>';

        $clients = $wpdb->get_results(
            "SELECT c.*, u.display_name manager_name
            FROM " . GCM_Core::t('clients') . " c
            LEFT JOIN {$wpdb->users} u ON u.ID=c.manager_id
            ORDER BY c.id DESC
            LIMIT 200"
        );

        $managers = get_users(array('role' => GCM_Core::ROLE));

        echo '<h2 style="margin-top:28px">Клієнти — передати іншому менеджеру</h2><table class="widefat striped"><thead><tr><th>Клієнт</th><th>Email</th><th>Зараз веде</th><th>Передати</th></tr></thead><tbody>';

        foreach ((array)$clients as $cl) {
            echo '<tr><td>' . esc_html($cl->name) .
                '</td><td>' . esc_html($cl->email) .
                '</td><td>' . esc_html($cl->manager_name) .
                '</td><td><form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">' .
                wp_nonce_field('gcm_admin', '_wpnonce', true, false) .
                '<input type="hidden" name="action" value="gcm_action">' .
                '<input type="hidden" name="gcm_do" value="reassign_client">' .
                '<input type="hidden" name="client_id" value="' . (int)$cl->id . '">' .
                '<select name="manager_id">';

            foreach ($managers as $m) {
                echo '<option value="' . (int)$m->ID . '"' .
                    selected((int)$cl->manager_id, (int)$m->ID, false) .
                    '>' . esc_html($m->display_name) . '</option>';
            }

            echo '</select> <button class="button">Передати</button></form></td></tr>';
        }

        if (!$clients) {
            echo '<tr><td colspan="4">Клієнтів поки немає.</td></tr>';
        }

        echo '</tbody></table>';
    }

    /* ---------- Вкладка: Налаштування ---------- */

    private function tab_settings() {
        $s = GCM_Core::settings();
        $opt = GCM_Core::OPT;

        $has = function ($k) use ($s) {
            return !empty($s[$k]);
        };

        echo '<form method="post" action="options.php">';
        settings_fields('gcm_settings_group');

        echo '<h2>Оплата і комісія</h2><table class="form-table">';

        echo '<tr><th>Посилання на Приват Конверт</th><td><input class="large-text" type="url" placeholder="https://..." name="' . esc_attr($opt) . '[privat_link]" value="' . esc_attr($s['privat_link'] ?? '') . '"><p class="description">Менеджер показує це посилання клієнту, коли той платить карткою. Тільки https.</p></td></tr>';

        echo '<tr><th>Комісія менеджера за замовчуванням, %</th><td><input type="number" step="0.01" min="0" max="100" name="' . esc_attr($opt) . '[default_commission]" value="' . esc_attr($s['default_commission'] ?? 20) . '"><p class="description">Фіксується в кожній роботі в момент створення. Особистий відсоток — на вкладці «Менеджери».</p></td></tr>';

        echo '<tr><th>Валюта</th><td><input name="gcm_currency" value="' . esc_attr(GCM_Core::currency()) . '"></td></tr></table>';

        echo '<h2>Google</h2><table class="form-table">';

        echo '<tr><th>Places API key</th><td><input class="regular-text" type="password" autocomplete="new-password" name="' . esc_attr($opt) . '[places_api_key]" placeholder="' . ($has('places_api_key') ? '•••••• збережено (залиште порожнім, щоб не змінювати)' : '') . '"><p class="description">Увімкніть <strong>Places API (New)</strong> і billing. Обмежте ключ у Google Cloud.</p></td></tr>';

        echo '<tr><th>OAuth Client ID</th><td><input class="regular-text" name="' . esc_attr($opt) . '[client_id]" value="' . esc_attr($s['client_id'] ?? '') . '"></td></tr>';

        echo '<tr><th>OAuth Client Secret</th><td><input class="regular-text" type="password" autocomplete="new-password" name="' . esc_attr($opt) . '[client_secret]" placeholder="' . ($has('client_secret') ? '•••••• збережено' : '') . '"></td></tr>';

        echo '<tr><th>Redirect URI</th><td><input class="regular-text" readonly value="' . esc_attr(GCM_Google::redirect_uri()) . '"><p class="description">Додайте в Authorized redirect URIs у Google Cloud.</p></td></tr>';

        echo '<tr><th>Google-пошта центрального акаунта</th><td><input class="regular-text" type="email" name="' . esc_attr($opt) . '[central_email]" value="' . esc_attr($s['central_email'] ?? '') . '"><p class="description">Саме її клієнт додає менеджером у своєму Business Profile. Показується клієнту на сторінці інструкції.</p></td></tr>';

        echo '<tr><th>Business Profile акаунт (необов’язково)</th><td><input class="regular-text" name="' . esc_attr($opt) . '[gbp_account]" placeholder="accounts/1234567890" value="' . esc_attr($s['gbp_account'] ?? '') . '"><p class="description">Резервний акаунт для створення нових карток, якщо поле групи нижче порожнє.</p></td></tr>';

        echo '<tr><th>Location Group ID</th><td><input class="regular-text" name="' . esc_attr($opt) . '[location_group_id]" placeholder="accounts/1234567890 або 1234567890" value="' . esc_attr($s['location_group_id'] ?? '') . '"><p class="description">Нові картки створюватимуться в цій групі локацій. Вкажіть ID Location Group, до якої має доступ підключений центральний Google-акаунт. Це ресурс акаунта Google Business Profile, а не Place ID.</p></td></tr>';

        echo '<tr><th>Ліміт розміру файлу, МБ</th><td><input type="number" min="1" max="500" name="' . esc_attr($opt) . '[max_file_mb]" value="' . esc_attr($s['max_file_mb'] ?? 64) . '"></td></tr>';

        echo '</table>';
        submit_button();
        echo '</form>';

        echo '<h2>Центральний Google-акаунт</h2>';

        echo '<p>' .
            (GCM_Google::is_connected() ? '<strong>Підключено.</strong>' : 'Ще не підключено.') .
            ' Підключіть один акаунт, який керуватиме картками клієнтів. Менеджерам Google не потрібен.</p>';

        echo '<a class="button button-primary" href="' .
            esc_url(wp_nonce_url(admin_url('admin-post.php?action=gcm_google_admin_connect'), 'gcm_google_admin_connect')) .
            '">Підключити / перепідключити Google-акаунт</a>';

        echo '<h2 style="margin-top:28px">Діагностика</h2><p class="description">Перевіряє ключі, підключення й доступ до Business Profile API. Запустіть після налаштування.</p>';

        echo $this->form('diagnose', array(), 'Перевірити налаштування', 'button');

        $diag = get_transient('gcm_diag_' . get_current_user_id());

        if ($diag) {
            echo '<table class="widefat striped" style="max-width:800px;margin-top:10px"><tbody>';

            foreach ($diag as $st) {
                echo '<tr><td>' . ($st[1] === null ? '✅' : '❌') .
                    '</td><td>' . esc_html($st[0]) .
                    ($st[1] !== null ? '<br><span class="description">' . esc_html($st[1]) . '</span>' : '') .
                    '</td></tr>';
            }

            echo '</tbody></table>';
        }

        if (!empty($_GET['gcm_inv'])) {
            $inv = GCM_Google::pending_invitations();

            echo '<h3>Очікують прийняття</h3>';

            if (is_wp_error($inv)) {
                echo '<p>' . esc_html($inv->get_error_message()) . '</p>';
            } elseif (!$inv) {
                echo '<p>Запрошень немає.</p>';
            } else {
                echo '<table class="widefat striped" style="max-width:800px"><tbody>';

                foreach ($inv as $i) {
                    echo '<tr><td>' . esc_html(GCM_Google::invitation_title($i)) .
                        '</td><td>' . $this->form('accept_invitation', array('inv' => $i['name']), 'Прийняти') .
                        '</td></tr>';
                }

                echo '</tbody></table>';
            }
        } else {
            echo '<p><a href="' .
                esc_url($this->url('settings', array('gcm_inv' => 1))) .
                '">Показати запрошення, що очікують прийняття</a>. Перевіряйте назву картки перед ручним прийняттям запрошення.</p>';
        }
    }

    /* ---------- Дії ---------- */

    public function handle() {
        if (!current_user_can('manage_options')) {
            wp_die('Доступ заборонено.');
        }

        check_admin_referer('gcm_admin');

        global $wpdb;

        $do = sanitize_key($_POST['gcm_do'] ?? '');
        $tab = 'cases';
        $notice = '';

        if ($do === 'confirm_payment') {
            $tab = 'payments';
            $notice = GCM_Core::confirm_payment(absint($_POST['payment_id'] ?? 0))
                ? 'Оплату підтверджено.'
                : 'Оплату не знайдено або вже підтверджено.';

        } elseif ($do === 'settle_payment') {
            $tab = 'payments';
            $notice = GCM_Core::settle_payment(absint($_POST['payment_id'] ?? 0))
                ? 'Розрахунок зафіксовано.'
                : 'Оплату не знайдено або вже розраховано.';

        } elseif ($do === 'assign_role') {
            $tab = 'managers';
            $u = get_user_by('id', absint($_POST['user_id'] ?? 0));

            if ($u && !user_can($u, 'manage_options')) {
                $u->set_role(GCM_Core::ROLE);
                delete_user_meta($u->ID, 'gcm_pending');

                $notice = 'Роль призначено: ' . $u->user_email;

                wp_mail(
                    $u->user_email,
                    'Вам надано доступ до кабінету менеджера',
                    "Адміністратор призначив вам роль менеджера.\nКабінет: " . home_url('/gmaps/')
                );
            }

        } elseif ($do === 'remove_role') {
            $tab = 'managers';
            $u = get_user_by('id', absint($_POST['user_id'] ?? 0));

            if ($u && in_array(GCM_Core::ROLE, (array)$u->roles, true)) {
                $u->remove_role(GCM_Core::ROLE);
                $notice = 'Роль забрано.';
            }

        } elseif ($do === 'set_rate') {
            $tab = 'managers';
            $uid = absint($_POST['user_id'] ?? 0);
            $rate = trim((string)wp_unslash($_POST['rate'] ?? ''));

            if ($rate === '') {
                delete_user_meta($uid, 'gcm_commission_rate');
            } else {
                update_user_meta(
                    $uid,
                    'gcm_commission_rate',
                    max(0, min(100, (float)$rate))
                );
            }

            $notice = 'Відсоток збережено (діє для нових робіт).';

        } elseif ($do === 'reassign_client') {
            $tab = 'managers';

            $cid = absint($_POST['client_id'] ?? 0);
            $mid = absint($_POST['manager_id'] ?? 0);
            $m = get_user_by('id', $mid);

            if (
                $cid &&
                $m &&
                in_array(GCM_Core::ROLE, (array)$m->roles, true)
            ) {
                $wpdb->update(
                    GCM_Core::t('clients'),
                    array('manager_id' => $mid),
                    array('id' => $cid)
                );

                $wpdb->update(
                    GCM_Core::t('orders'),
                    array('manager_id' => $mid),
                    array('client_id' => $cid)
                );

                $notice = 'Клієнта передано. Попередні оплати залишаються за попереднім менеджером.';
            }

        } elseif ($do === 'diagnose') {
            $tab = 'settings';

            set_transient(
                'gcm_diag_' . get_current_user_id(),
                GCM_Google::diagnose(),
                10 * MINUTE_IN_SECONDS
            );

        } elseif ($do === 'accept_invitation') {
            $tab = 'settings';

            $r = GCM_Google::accept_invitation(
                sanitize_text_field(wp_unslash($_POST['inv'] ?? ''))
            );

            $notice = is_wp_error($r)
                ? $r->get_error_message()
                : 'Запрошення прийнято.';
        }

        wp_safe_redirect(
            add_query_arg(
                array(
                    'page' => 'gcm-dashboard',
                    'tab' => $tab,
                    'gcm_notice' => rawurlencode($notice)
                ),
                admin_url('admin.php')
            )
        );

        exit;
    }

    public function google_connect() {
        if (!current_user_can('manage_options')) {
            wp_die('Доступ заборонено.');
        }

        check_admin_referer('gcm_google_admin_connect');

        $s = GCM_Core::settings();

        if (empty($s['client_id']) || empty($s['client_secret'])) {
            wp_die('Спочатку введіть Google OAuth Client ID та Client Secret у налаштуваннях.');
        }

        $uid = get_current_user_id();
        $state = wp_generate_password(32, false, false);

        set_transient(
            'gcm_google_admin_state_' . $uid,
            $state,
            10 * MINUTE_IN_SECONDS
        );

        $url = add_query_arg(
            array(
                'client_id' => $s['client_id'],
                'redirect_uri' => GCM_Google::redirect_uri(),
                'response_type' => 'code',
                'scope' => 'https://www.googleapis.com/auth/business.manage',
                'access_type' => 'offline',
                'prompt' => 'consent',
                'state' => $uid . '.' . $state,
            ),
            'https://accounts.google.com/o/oauth2/v2/auth'
        );

        wp_redirect($url);
        exit;
    }

    public function google_callback() {
        if (!current_user_can('manage_options')) {
            wp_die('Лише адміністратор може підключити центральний Google-акаунт.');
        }

        $parts = explode(
            '.',
            sanitize_text_field(wp_unslash($_GET['state'] ?? '')),
            2
        );

        $uid = absint($parts[0] ?? 0);
        $nonce = $parts[1] ?? '';

        if (
            $uid !== get_current_user_id() ||
            !$nonce ||
            !hash_equals(
                (string)get_transient('gcm_google_admin_state_' . $uid),
                $nonce
            )
        ) {
            wp_die('Недійсний стан OAuth.');
        }

        delete_transient('gcm_google_admin_state_' . $uid);

        if (!empty($_GET['error'])) {
            wp_die(
                'Google не завершив авторизацію: ' .
                esc_html(sanitize_text_field(wp_unslash($_GET['error'])))
            );
        }

        $code = sanitize_text_field(wp_unslash($_GET['code'] ?? ''));
        $s = GCM_Core::settings();

        if (!$code || empty($s['client_id']) || empty($s['client_secret'])) {
            wp_die('OAuth не налаштований.');
        }

        $response = wp_remote_post(
            'https://oauth2.googleapis.com/token',
            array(
                'timeout' => 20,
                'body' => array(
                    'code' => $code,
                    'client_id' => $s['client_id'],
                    'client_secret' => $s['client_secret'],
                    'redirect_uri' => GCM_Google::redirect_uri(),
                    'grant_type' => 'authorization_code',
                )
            )
        );

        if (is_wp_error($response)) {
            wp_die(esc_html($response->get_error_message()));
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);

        if (empty($body['access_token']) || empty($body['refresh_token'])) {
            wp_die('Google не повернув refresh token. Повторіть підключення й дозвольте офлайн-доступ.');
        }

        $body['expires_at'] = time() + absint($body['expires_in'] ?? 3600);

        update_option('gcm_google_admin_token', $body, false);

        wp_safe_redirect(
            add_query_arg(
                array(
                    'page' => 'gcm-dashboard',
                    'tab' => 'settings',
                    'gcm_notice' => rawurlencode('Google-акаунт підключено.')
                ),
                admin_url('admin.php')
            )
        );

        exit;
    }
}
