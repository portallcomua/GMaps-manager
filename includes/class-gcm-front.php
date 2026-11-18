<?php
if (!defined('ABSPATH')) exit;

/**
 * Фронтенд: кабінет менеджера (/gmaps/), форма роботи, сторінка роботи,
 * публічна сторінка інструкції для клієнта (/gmaps-access/), реєстрація менеджера.
 */
final class GCM_Front {
    private static $instance = null;
    private $assets_done = false;

    public static function instance() {
        if (self::$instance === null) self::$instance = new self();
        return self::$instance;
    }

    private function __construct() {
        add_action('template_redirect', array($this, 'route'));
        add_shortcode('gmaps_client_manager', array($this, 'sc_dashboard'));
        add_shortcode('gmaps_manager_link', array($this, 'sc_link'));
        add_shortcode('gmaps_manager_register', array($this, 'sc_register'));
        add_shortcode('gmaps_request_form', array($this, 'sc_legacy_form'));
        add_action('admin_post_gcm_front', array($this, 'handle_front'));
        add_action('admin_post_nopriv_gcm_register', array($this, 'handle_register'));
        add_action('admin_post_gcm_register', array($this, 'handle_register'));
        add_action('admin_post_nopriv_gcm_client_done', array($this, 'handle_client_done'));
        add_action('admin_post_gcm_client_done', array($this, 'handle_client_done'));
        add_action('wp_ajax_gcm_lookup_client', array($this, 'ajax_lookup_client'));
        add_action('wp_ajax_gcm_search_place', array($this, 'ajax_search_place'));
        add_action('wp_ajax_gcm_categories', array($this, 'ajax_categories'));
        add_action('wp_ajax_gcm_preview_case', array($this, 'ajax_preview_case'));
        add_action('wp_ajax_gcm_validate_case', array($this, 'ajax_validate_case'));
    }

    /* ---------- Маршрути, ресурси, шорткоди ---------- */

    private function dash($args = array()) {
        return add_query_arg($args, home_url('/gmaps/'));
    }

    private function enqueue() {
        if ($this->assets_done) return;
        $this->assets_done = true;

        wp_enqueue_style('gcm', GCM_URL . 'assets/gcm.css', array(), GCM_VERSION);
        wp_enqueue_script('gcm', GCM_URL . 'assets/gcm.js', array(), GCM_VERSION, true);

        wp_localize_script('gcm', 'GCM', array(
            'ajax' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('gcm_ajax'),
            'privat' => GCM_Core::privat_link(),
            'currency' => GCM_Core::currency()
        ));
    }

    public function route() {
        if (get_query_var('gcm_dashboard')) {
            status_header(200);
            nocache_headers();
            $this->enqueue();
            get_header();
            echo $this->dashboard_html();
            get_footer();
            exit;
        }

        if (get_query_var('gcm_access')) {
            status_header(200);
            nocache_headers();
            header('X-Robots-Tag: noindex, nofollow');
            $this->enqueue();
            get_header();
            echo $this->access_page_html();
            get_footer();
            exit;
        }
    }

    public function sc_dashboard() {
        $this->enqueue();
        return $this->dashboard_html();
    }

    public function sc_register() {
        $this->enqueue();
        return $this->register_form_html();
    }

    public function sc_link() {
        $url = is_user_logged_in() ? $this->dash() : wp_login_url($this->dash());
        return '<a class="gcm-btn" href="' . esc_url($url) . '">Кабінет менеджера</a>';
    }

    public function sc_legacy_form() {
        $this->enqueue();
        return '<div class="gcm-wrap"><p>Заявки тепер приймають менеджери. Зверніться до свого менеджера або <a href="' . esc_url($this->dash()) . '">увійдіть до кабінету</a>.</p></div>';
    }

    private function notice_from_query() {
        if (empty($_GET['gcm_msg'])) return '';

        $type = (isset($_GET['gcm_t']) && $_GET['gcm_t'] === 'err')
            ? 'gcm-alert-err'
            : 'gcm-alert-ok';

        return '<div class="gcm-alert ' . $type . '">' .
            esc_html(sanitize_text_field(wp_unslash($_GET['gcm_msg']))) .
            '</div>';
    }

    private function back($args, $msg, $type = 'ok') {
        wp_safe_redirect(add_query_arg(
            array_merge($args, array(
                'gcm_msg' => rawurlencode(mb_substr($msg, 0, 600)),
                'gcm_t' => $type
            )),
            $this->dash()
        ));
        exit;
    }

    private function verify($action = 'gcm_front') {
        if (!GCM_Core::can_manage()) {
            wp_die('Доступ заборонено.');
        }

        if (
            empty($_POST['_wpnonce']) ||
            !wp_verify_nonce(
                sanitize_text_field(wp_unslash($_POST['_wpnonce'])),
                $action
            )
        ) {
            wp_die('Перевірка безпеки не пройдена. Оновіть сторінку й спробуйте ще раз.');
        }
    }

    private function limit($key, $max, $window) {
        $k = 'gcm_rl_' . md5($key);
        $n = (int)get_transient($k);

        if ($n >= $max) return false;

        set_transient($k, $n + 1, $window);
        return true;
    }

    private function ip() {
        return sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR'] ?? 'unknown'));
    }

    /* ---------- Реєстрація менеджера ---------- */

    private function register_form_html() {
        if (is_user_logged_in()) return '';

        $msg = '';

        if (!empty($_GET['gcm_reg_err'])) {
            $msg = '<div class="gcm-alert gcm-alert-err">' .
                esc_html(sanitize_text_field(wp_unslash($_GET['gcm_reg_err']))) .
                '</div>';
        }

        ob_start();
        ?>
        <div class="gcm-box">
          <h3>Реєстрація менеджера</h3>
          <?php echo $msg; ?>

          <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
            <input type="hidden" name="action" value="gcm_register">
            <?php wp_nonce_field('gcm_register', '_gcm_reg'); ?>

            <input type="text" name="website_url" value="" tabindex="-1" autocomplete="off" style="position:absolute;left:-9999px" aria-hidden="true">

            <div class="gcm-grid">
              <label>Ім’я<input required name="name" autocomplete="name"></label>
              <label>Email<input required type="email" name="email" autocomplete="email"></label>
              <label>Пароль (мін. 10 символів)<input required type="password" name="pass" minlength="10" autocomplete="new-password"></label>
            </div>

            <button>Зареєструватися</button>
            <p class="gcm-muted">Після реєстрації адміністратор призначить вам роль менеджера — ви отримаєте лист.</p>
          </form>
        </div>
        <?php
        return ob_get_clean();
    }

    public function handle_register() {
        $back = wp_get_referer() ?: $this->dash();

        $fail = function ($m) use ($back) {
            wp_safe_redirect(add_query_arg(
                'gcm_reg_err',
                rawurlencode($m),
                remove_query_arg('gcm_reg_err', $back)
            ));
            exit;
        };

        if (
            empty($_POST['_gcm_reg']) ||
            !wp_verify_nonce(
                sanitize_text_field(wp_unslash($_POST['_gcm_reg'])),
                'gcm_register'
            )
        ) {
            $fail('Перевірка безпеки не пройдена. Оновіть сторінку.');
        }

        if (!empty($_POST['website_url'])) {
            $fail('Не вдалося зареєструватися.');
        }

        if (!$this->limit('reg' . $this->ip(), 5, HOUR_IN_SECONDS)) {
            $fail('Забагато спроб. Спробуйте пізніше.');
        }

        $name = sanitize_text_field(wp_unslash($_POST['name'] ?? ''));
        $email = sanitize_email(wp_unslash($_POST['email'] ?? ''));
        $pass = (string)wp_unslash($_POST['pass'] ?? '');

        if ($name === '' || !is_email($email)) {
            $fail('Вкажіть ім’я та коректний email.');
        }

        if (strlen($pass) < 10) {
            $fail('Пароль має містити щонайменше 10 символів.');
        }

        if (email_exists($email)) {
            $fail('Цей email уже зареєстровано. Увійдіть або скористайтеся відновленням пароля.');
        }

        $login = sanitize_user(strstr($email, '@', true), true) ?: 'manager';
        $base = $login;
        $i = 1;

        while (username_exists($login)) {
            $login = $base . (++$i);
        }

        $uid = wp_insert_user(array(
            'user_login' => $login,
            'user_email' => $email,
            'user_pass' => $pass,
            'display_name' => $name,
            'first_name' => $name,
            'role' => 'subscriber'
        ));

        if (is_wp_error($uid)) {
            $fail('Не вдалося створити акаунт.');
        }

        update_user_meta($uid, 'gcm_pending', '1');

        wp_mail(
            get_option('admin_email'),
            'Новий менеджер очікує на роль',
            $name . ' (' . $email . ") зареєструвався.\nПризначте роль: " .
            admin_url('admin.php?page=gcm-dashboard&tab=managers')
        );

        wp_set_current_user($uid);
        wp_set_auth_cookie($uid, true);
        wp_safe_redirect($this->dash());
        exit;
    }

    /* ---------- Кабінет ---------- */

    private function tabs_nav($tab) {
        $items = array(
            'work' => 'Нова робота',
            'cases' => 'Мої роботи',
            'money' => 'Гроші та комісія',
            'clients' => 'Клієнти'
        );

        $o = '<nav class="gcm-tabs" aria-label="Розділи кабінету">';

        foreach ($items as $k => $l) {
            $o .= '<a class="' . ($tab === $k ? 'is-active' : '') .
                '" href="' . esc_url($this->dash(array('gcm_tab' => $k))) .
                '">' . esc_html($l) . '</a>';
        }

        return $o . '</nav>';
    }

    private function dashboard_html() {
        if (!is_user_logged_in()) {
            return '<div class="gcm-wrap"><h2>Кабінет менеджера</h2><p>Увійдіть, щоб працювати з клієнтами.</p><a class="gcm-btn" href="' .
                esc_url(wp_login_url($this->dash())) .
                '">Увійти</a>' . $this->register_form_html() . '</div>';
        }

        if (!GCM_Core::can_manage()) {
            return '<div class="gcm-wrap"><h2>Кабінет менеджера</h2><p class="gcm-alert">Ваш акаунт очікує підтвердження адміністратором. Коли роль буде призначено, ви отримаєте лист — оновіть цю сторінку.</p><p><a href="' .
                esc_url(wp_logout_url($this->dash())) .
                '">Вийти</a></p></div>';
        }

        // За замовчуванням відкриваємо саме форму нової роботи.
        // Явно переданий gcm_tab зберігає вибір користувача.
        $tab = isset($_GET['gcm_tab'])
            ? sanitize_key(wp_unslash($_GET['gcm_tab']))
            : 'work';

        $u = wp_get_current_user();

        ob_start();

        echo '<div class="gcm-wrap"><div class="gcm-head"><h2>Кабінет менеджера</h2><span class="gcm-muted">' .
            esc_html($u->display_name) .
            ' · <a href="' . esc_url(wp_logout_url($this->dash())) .
            '">Вийти</a></span></div>';

        echo $this->tabs_nav($tab === 'case' ? 'cases' : $tab);
        echo $this->notice_from_query();

        if ($tab === 'work') {
            echo $this->work_form_html();
        } elseif ($tab === 'case') {
            echo $this->case_html(absint($_GET['id'] ?? 0));
        } elseif ($tab === 'money') {
            echo $this->money_html();
        } elseif ($tab === 'clients') {
            echo $this->clients_html();
        } else {
            echo $this->cases_html();
        }

        echo '</div>';

        return ob_get_clean();
    }

    private function my_where() {
        global $wpdb;

        return GCM_Core::is_admin_user()
            ? '1=1'
            : $wpdb->prepare('o.manager_id=%d', get_current_user_id());
    }

    private function cases_html() {
        global $wpdb;

        $o = GCM_Core::t('orders');
        $c = GCM_Core::t('clients');

        $rows = $wpdb->get_results(
            "SELECT o.*, c.name client_name, c.email client_email
            FROM $o o
            LEFT JOIN $c c ON c.id=o.client_id
            WHERE " . $this->my_where() . "
            ORDER BY o.id DESC LIMIT 200"
        );

        ob_start();

        echo '<div class="gcm-box"><div class="gcm-row"><h3>Мої роботи</h3><a class="gcm-btn" href="' .
            esc_url($this->dash(array('gcm_tab' => 'work'))) .
            '">+ Нова робота</a></div>';

        echo '<div class="gcm-scroll"><table class="gcm-table"><thead><tr><th>#</th><th>Клієнт</th><th>Бізнес</th><th>Послуга</th><th>Сума</th><th>Статус</th><th></th></tr></thead><tbody>';

        foreach ((array)$rows as $r) {
            echo '<tr><td>' . (int)$r->id .
                '</td><td>' . esc_html($r->client_name) .
                '<br><span class="gcm-muted">' . esc_html($r->client_email) .
                '</span></td><td>' . esc_html($r->biz_name) .
                '</td><td>' . esc_html(GCM_Core::type_label($r->request_type)) .
                '</td><td>' . GCM_Core::money($r->amount) .
                '</td><td><span class="gcm-badge">' . esc_html(GCM_Core::status_label($r->status)) .
                '</span></td><td><a class="gcm-btn gcm-btn-sm" href="' .
                esc_url($this->dash(array('gcm_tab' => 'case', 'id' => $r->id))) .
                '">Відкрити</a></td></tr>';
        }

        if (!$rows) {
            echo '<tr><td colspan="7">Робіт поки немає. Натисніть «Нова робота».</td></tr>';
        }

        echo '</tbody></table></div></div>';

        return ob_get_clean();
    }

    private function clients_html() {
        global $wpdb;

        $cl = GCM_Core::t('clients');
        $o = GCM_Core::t('orders');

        $where = GCM_Core::is_admin_user()
            ? '1=1'
            : $wpdb->prepare('c.manager_id=%d', get_current_user_id());

        $rows = $wpdb->get_results(
            "SELECT c.*, (SELECT COUNT(*) FROM $o WHERE client_id=c.id) cnt
            FROM $cl c
            WHERE $where
            ORDER BY c.id DESC LIMIT 300"
        );

        ob_start();

        echo '<div class="gcm-box"><h3>Мої клієнти</h3><div class="gcm-scroll"><table class="gcm-table"><thead><tr><th>Клієнт</th><th>Контакти</th><th>Робіт</th><th></th></tr></thead><tbody>';

        foreach ((array)$rows as $r) {
            echo '<tr><td>' . esc_html($r->name) .
                '</td><td>' . esc_html($r->email . ' ' . $r->phone) .
                '</td><td>' . (int)$r->cnt .
                '</td><td><a class="gcm-btn gcm-btn-sm" href="' .
                esc_url($this->dash(array('gcm_tab' => 'work', 'email' => $r->email))) .
                '">Нова робота</a></td></tr>';
        }

        if (!$rows) {
            echo '<tr><td colspan="4">Клієнтів поки немає.</td></tr>';
        }

        echo '</tbody></table></div></div>';

        return ob_get_clean();
    }

    /* ---------- Форма роботи ---------- */

    private function v($case, $f, $d = '') {
        return $case && isset($case->$f) ? $case->$f : $d;
    }

    private function work_form_html() {
        $case = null;

        if (!empty($_GET['id'])) {
            $case = GCM_Core::get_case(absint($_GET['id']));

            if (!GCM_Core::user_owns_case($case)) {
                return '<div class="gcm-alert gcm-alert-err">Роботу не знайдено.</div>';
            }
        }

        $kind = $case
            ? ($case->request_type === 'reviews' ? 'reviews' : 'map')
            : 'map';

        $client = $case ? GCM_Core::get_client($case->client_id) : null;
        $hours = $case ? GCM_Publisher::hours($case) : array();
        $media = $case ? GCM_Core::media_items($case->media) : array();
        $email = $case ? '' : sanitize_email(wp_unslash($_GET['email'] ?? ''));
        $rt = $case ? $case->request_type : '';

        ob_start();
        ?>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" enctype="multipart/form-data" id="gcm-work" data-kind="<?php echo esc_attr($kind); ?>" data-edit="<?php echo $case ? '1' : '0'; ?>">
          <input type="hidden" name="action" value="gcm_front">
          <input type="hidden" name="gcm_do" value="save_case">
          <?php wp_nonce_field('gcm_front'); ?>
          <input type="hidden" name="case_id" value="<?php echo $case ? (int)$case->id : 0; ?>">

          <?php if (!$case): ?>
          <div class="gcm-box">
            <h3>1. Що робимо</h3>
            <label class="gcm-radio"><input type="radio" name="kind" value="map" checked> Картка Google Maps — створення або редагування</label>
            <label class="gcm-radio"><input type="radio" name="kind" value="reviews"> Збір відгуків (посилання й тексти для справжніх клієнтів)</label>
          </div>

          <div class="gcm-box">
            <h3>2. Клієнт</h3>
            <div class="gcm-grid">
              <label>Email клієнта<input required type="email" name="client_email" id="gcm-email" value="<?php echo esc_attr($email); ?>" autocomplete="off"></label>
              <label>Ім’я / назва клієнта<input required name="client_name" id="gcm-cname"></label>
              <label>Телефон<input name="client_phone" id="gcm-cphone" type="tel"></label>
            </div>
            <p id="gcm-client-result" class="gcm-muted" aria-live="polite">Введіть email — перевірю, чи вже є такий клієнт і чи є в нього робота.</p>
          </div>
          <?php else: ?>
          <div class="gcm-box">
            <h3>Клієнт</h3>
            <p><strong><?php echo esc_html($client ? $client->name : ''); ?></strong> <span class="gcm-muted"><?php echo esc_html($client ? $client->email : ''); ?></span></p>
          </div>
          <?php endif; ?>

          <div class="gcm-box">
            <h3><?php echo $case ? '' : '3. '; ?>Картка на Google</h3>
            <p class="gcm-muted">Google не вміє шукати за email — введіть назву й місто бізнесу або вставте посилання з Google Maps (підійде й коротке з телефона).</p>
            <div class="gcm-inline">
              <input type="text" id="gcm-q" placeholder="Напр.: Кав’ярня «Лаванда», Львів" value="<?php echo esc_attr($this->v($case, 'biz_name')); ?>">
              <button type="button" id="gcm-find">Знайти</button>
            </div>
            <div id="gcm-cands" class="gcm-cands"></div>

            <input type="hidden" name="place_id" id="gcm-place-id" value="<?php echo esc_attr($this->v($case, 'place_id')); ?>">
            <input type="hidden" name="maps_url" id="gcm-maps-url" value="<?php echo esc_attr($this->v($case, 'maps_url')); ?>">
            <input type="hidden" name="request_type" id="gcm-rt" value="<?php echo esc_attr($rt); ?>">

            <p id="gcm-place-status" class="gcm-alert" <?php echo $rt ? '' : 'hidden'; ?>>
              <?php
              echo $rt === 'edit'
                  ? 'Вибрано існуючу картку. Заявка — на редагування.'
                  : ($rt === 'create'
                      ? 'Картки немає — буде створена нова.'
                      : ($rt === 'reviews' ? 'Картку вибрано.' : ''));
              ?>
            </p>
          </div>

          <div class="gcm-box gcm-map-only">
            <h3><?php echo $case ? '' : '4. '; ?>Що вказати на картці</h3>
            <p class="gcm-muted">Для редагування заповнюйте <strong>лише те, що треба змінити</strong> — порожні поля не чіпатимуться.</p>

            <div class="gcm-grid">
              <label>Назва бізнесу<input name="biz_name" value="<?php echo esc_attr($this->v($case, 'biz_name')); ?>" maxlength="190"></label>
              <label>Телефон<input name="biz_phone" type="tel" value="<?php echo esc_attr($this->v($case, 'biz_phone')); ?>"></label>
              <label>Сайт<input name="biz_website" type="url" placeholder="https://" value="<?php echo esc_attr($this->v($case, 'biz_website')); ?>"></label>
              <label>Категорія<input id="gcm-cat" name="biz_category" list="gcm-cat-list" autocomplete="off" placeholder="Почніть вводити, напр. «Кав’ярня»" value="<?php echo esc_attr($this->v($case, 'biz_category')); ?>"></label>
              <input type="hidden" name="biz_category_id" id="gcm-cat-id" value="<?php echo esc_attr($this->v($case, 'biz_category_id')); ?>">
              <datalist id="gcm-cat-list"></datalist>
              <label>Місто<input name="biz_city" value="<?php echo esc_attr($this->v($case, 'biz_city')); ?>"></label>
              <label>Вулиця й номер<input name="biz_street" value="<?php echo esc_attr($this->v($case, 'biz_street')); ?>"></label>
              <label>Поштовий індекс<input name="biz_postal" inputmode="numeric" value="<?php echo esc_attr($this->v($case, 'biz_postal')); ?>"></label>
            </div>

            <label>Опис (до 750 символів) <span id="gcm-desc-count" class="gcm-muted"></span>
              <textarea name="biz_description" id="gcm-desc" rows="4" maxlength="750"><?php echo esc_textarea($this->v($case, 'biz_description')); ?></textarea>
            </label>

            <label class="gcm-radio">
              <input type="checkbox" name="hours_on" value="1" id="gcm-hours-on" <?php checked(!empty($hours)); ?>>
              Вказати / змінити години роботи
            </label>

            <div id="gcm-hours" class="gcm-hours" <?php echo $hours ? '' : 'hidden'; ?>>
              <?php foreach (GCM_Core::day_names() as $i => $n):
                  $h = $hours[$i] ?? array(
                      'off' => $i >= 6 ? 1 : 0,
                      'open' => '09:00',
                      'close' => '18:00'
                  );
              ?>
              <div class="gcm-hrow">
                <span><?php echo esc_html($n); ?></span>
                <input type="time" name="hours_open[<?php echo $i; ?>]" value="<?php echo esc_attr($h['open'] ?? '09:00'); ?>">
                —
                <input type="time" name="hours_close[<?php echo $i; ?>]" value="<?php echo esc_attr($h['close'] ?? '18:00'); ?>">
                <label class="gcm-radio"><input type="checkbox" name="hours_off[<?php echo $i; ?>]" value="1" <?php checked(!empty($h['off'])); ?>> вихідний</label>
              </div>
              <?php endforeach; ?>
            </div>
          </div>

          <div class="gcm-box gcm-map-only">
            <h3><?php echo $case ? '' : '5. '; ?>Фото та відео</h3>

            <?php if ($media): ?>
              <p class="gcm-muted">Уже додано (познач, щоб видалити):</p>
              <div class="gcm-thumbs">
                <?php foreach ($media as $m): ?>
                  <label class="gcm-thumb">
                    <?php
                    echo $m['video']
                        ? '<video src="' . esc_url($m['url']) . '" muted playsinline preload="metadata"></video>'
                        : '<img src="' . esc_url($m['url']) . '" alt="">';
                    ?>
                    <input type="checkbox" name="remove_media[]" value="<?php echo (int)$m['id']; ?>"> видалити
                  </label>
                <?php endforeach; ?>
              </div>
            <?php endif; ?>

            <div class="gcm-grid">
              <label>Зробити фото<input type="file" name="gcm_photos[]" accept="image/*" capture="environment" multiple class="gcm-file"></label>
              <label>Зняти відео<input type="file" name="gcm_videos[]" accept="video/*" capture="environment" multiple class="gcm-file"></label>
              <label>Вибрати з пристрою<input type="file" name="gcm_media[]" accept="image/*,video/*" multiple class="gcm-file"></label>

              <label>Тип фото на картці
                <select name="photo_category">
                  <?php
                  foreach (array(
                      'ADDITIONAL' => 'Додаткові',
                      'EXTERIOR' => 'Зовні',
                      'INTERIOR' => 'Інтер’єр',
                      'PRODUCT' => 'Продукція',
                      'AT_WORK' => 'У роботі',
                      'COVER' => 'Обкладинка',
                      'PROFILE' => 'Профільне'
                  ) as $k => $l) {
                      echo '<option value="' . $k . '"' .
                          selected($this->v($case, 'photo_category', 'ADDITIONAL'), $k, false) .
                          '>' . esc_html($l) . '</option>';
                  }
                  ?>
                </select>
              </label>
            </div>

            <div id="gcm-previews" class="gcm-thumbs"></div>

            <p class="gcm-muted">
              До <?php echo (int)GCM_Core::MAX_MEDIA; ?> файлів,
              до <?php echo (int)GCM_Core::setting('max_file_mb', 64); ?> МБ кожен.
              Фото публікуються на картці; відео зберігаються в роботі
              (Google API відео не приймає).
            </p>
          </div>

          <?php if (!$case): $privat = GCM_Core::privat_link(); ?>
          <div class="gcm-box">
            <h3>6. Оплата</h3>
            <div class="gcm-grid">
              <label>Сума, <?php echo esc_html(GCM_Core::currency()); ?><input required type="number" step="0.01" min="0" name="amount" id="gcm-amount"></label>
              <label>Спосіб оплати
                <select name="method" id="gcm-method">
                  <option value="cash">Готівка (отримую я)</option>
                  <option value="card">Картка / Приват Конверт</option>
                  <option value="later">Ще не оплачено</option>
                </select>
              </label>
            </div>

            <div id="gcm-card-box" class="gcm-alert" hidden>
              <?php if ($privat): ?>
                Покажіть клієнту це посилання для оплати карткою:<br>
                <a href="<?php echo esc_url($privat); ?>" target="_blank" rel="noopener" class="gcm-btn gcm-btn-sm">Відкрити Приват Конверт</a>
                <button type="button" class="gcm-btn gcm-btn-sm" data-copy="<?php echo esc_attr($privat); ?>">Копіювати посилання</button>
              <?php else: ?>
                Адміністратор ще не додав посилання на Приват Конверт.
              <?php endif; ?>
              <br><span class="gcm-muted">Оплата стане «підтвердженою» після перевірки адміністратором.</span>
            </div>
          </div>
          <?php endif; ?>

          <div class="gcm-box">
            <label>Примітки<textarea name="notes" rows="2"><?php echo esc_textarea($this->v($case, 'notes')); ?></textarea></label>
          </div>

          <button class="gcm-btn gcm-btn-lg"><?php echo $case ? 'Зберегти зміни' : 'Зберегти й переглянути зміни'; ?></button>
        </form>
        <?php
        return ob_get_clean();
    }

    private function save_case() {
        $this->verify();

        global $wpdb;

        $uid = get_current_user_id();
        $user = wp_get_current_user();
        $oT = GCM_Core::t('orders');
        $cT = GCM_Core::t('clients');

        $case = null;
        $case_id = absint($_POST['case_id'] ?? 0);

        if ($case_id) {
            $case = GCM_Core::get_case($case_id);
            if (!GCM_Core::user_owns_case($case)) {
                wp_die('Доступ заборонено.');
            }
        }

        $fail = function ($m) use ($case_id) {
            $this->back(
                array('gcm_tab' => 'work', 'id' => $case_id ?: null),
                $m,
                'err'
            );
        };

        $kind = $case
            ? ($case->request_type === 'reviews' ? 'reviews' : 'map')
            : ((sanitize_key($_POST['kind'] ?? 'map') === 'reviews') ? 'reviews' : 'map');

        // Клієнт
        if ($case) {
            $client = GCM_Core::get_client($case->client_id);
        } else {
            $email = sanitize_email(wp_unslash($_POST['client_email'] ?? ''));
            $cname = sanitize_text_field(wp_unslash($_POST['client_name'] ?? ''));

            if (!is_email($email) || $cname === '') {
                $fail('Вкажіть email та ім’я клієнта.');
            }

            $client = GCM_Core::client_by_email($email);

            if ($client && (int)$client->manager_id !== $uid && !GCM_Core::is_admin_user()) {
                $fail('Цей клієнт уже закріплений за іншим менеджером. Зверніться до адміністратора.');
            }

            if (!$client) {
                $wpdb->insert($cT, array(
                    'manager_id' => $uid,
                    'name' => $cname,
                    'email' => $email,
                    'phone' => sanitize_text_field(wp_unslash($_POST['client_phone'] ?? '')),
                    'created_at' => current_time('mysql')
                ));

                $client = GCM_Core::get_client($wpdb->insert_id);
            }
        }

        if (!$client) {
            $fail('Клієнта не знайдено.');
        }

        // Картка
        $place_id = sanitize_text_field(wp_unslash($_POST['place_id'] ?? ''));

        if (!preg_match('/^ChIJ[A-Za-z0-9_-]{10,}$/', $place_id)) {
            $place_id = '';
        }

        $maps_url = esc_url_raw(wp_unslash($_POST['maps_url'] ?? ''));

        if (
            $maps_url &&
            !preg_match('/(^|\.)google\.[a-z.]{2,}$|^maps\.app\.goo\.gl$/', (string)wp_parse_url($maps_url, PHP_URL_HOST))
        ) {
            $maps_url = '';
        }

        $rt = sanitize_key($_POST['request_type'] ?? '');

        if ($kind === 'reviews') {
            if (!$place_id) {
                $fail('Для збору відгуків оберіть картку клієнта зі списку.');
            }
            $rt = 'reviews';
        } else {
            if (!in_array($rt, array('edit', 'create'), true)) {
                $fail('Спочатку знайдіть картку на Google: оберіть зі списку або «Картки немає».');
            }

            if ($rt === 'edit' && !$place_id) {
                $fail('Оберіть картку зі списку результатів.');
            }

            if ($rt === 'create') {
                $place_id = '';
                $maps_url = '';
            }
        }

        // Поля картки
        $cat_id = sanitize_text_field(wp_unslash($_POST['biz_category_id'] ?? ''));

        if (!preg_match('~^categories/gcid:[A-Za-z0-9_]+$~', $cat_id)) {
            $cat_id = '';
        }

        $data = array(
            'request_type' => $rt,
            'place_id' => $place_id,
            'maps_url' => $maps_url,
            'notes' => sanitize_textarea_field(wp_unslash($_POST['notes'] ?? ''))
        );

        if ($kind === 'map') {
            $web = esc_url_raw(trim((string)wp_unslash($_POST['biz_website'] ?? '')));
            $photo_cat = sanitize_text_field(wp_unslash($_POST['photo_category'] ?? 'ADDITIONAL'));

            $data += array(
                'biz_name' => sanitize_text_field(wp_unslash($_POST['biz_name'] ?? '')),
                'biz_street' => sanitize_text_field(wp_unslash($_POST['biz_street'] ?? '')),
                'biz_city' => sanitize_text_field(wp_unslash($_POST['biz_city'] ?? '')),
                'biz_postal' => preg_replace('/[^0-9A-Za-z -]/', '', (string)wp_unslash($_POST['biz_postal'] ?? '')),
                'biz_phone' => sanitize_text_field(wp_unslash($_POST['biz_phone'] ?? '')),
                'biz_website' => $web,
                'biz_description' => mb_substr(sanitize_textarea_field(wp_unslash($_POST['biz_description'] ?? '')), 0, 750),
                'biz_category' => $cat_id ? sanitize_text_field(wp_unslash($_POST['biz_category'] ?? '')) : '',
                'biz_category_id' => $cat_id,
                'biz_hours' => wp_json_encode(GCM_Core::hours_from_post($_POST)),
                'photo_category' => in_array($photo_cat, array('ADDITIONAL', 'EXTERIOR', 'INTERIOR', 'PRODUCT', 'AT_WORK', 'COVER', 'PROFILE'), true)
                    ? $photo_cat
                    : 'ADDITIONAL'
            );
        }

        // Медіа
        $existing = $case ? GCM_Core::media_ids($case->media) : array();

        if ($case && !empty($_POST['remove_media']) && is_array($_POST['remove_media'])) {
            $rm = array_intersect(array_map('absint', $_POST['remove_media']), $existing);
            GCM_Core::discard($rm);
            $existing = array_values(array_diff($existing, $rm));
        }

        $new = ($kind === 'map')
            ? GCM_Core::upload_media(array('gcm_photos', 'gcm_videos', 'gcm_media'), count($existing))
            : array();

        if (is_wp_error($new)) {
            $fail($new->get_error_message());
        }

        $data['media'] = wp_json_encode(array_values(array_merge($existing, $new)));

        if ($case) {
            if ($kind === 'reviews') {
                $data['status'] = 'done';
            } else {
                $data['status'] = 'draft';
            }

            if ($place_id !== $case->place_id) {
                $data += array(
                    'gbp_account' => '',
                    'gbp_location' => '',
                    'media_pushed' => '',
                    'access_state' => ''
                );
            }

            $wpdb->update($oT, $data, array('id' => $case->id));

            $this->back(
                array('gcm_tab' => 'case', 'id' => $case->id),
                'Зміни збережено. Перегляньте їх і опублікуйте.'
            );
        }

        // Нова робота + оплата
        $amount = round((float)str_replace(',', '.', (string)wp_unslash($_POST['amount'] ?? '0')), 2);
        $method = sanitize_key($_POST['method'] ?? 'cash');

        if (!in_array($method, array('cash', 'card', 'later'), true)) {
            $method = 'cash';
        }

        if ($amount < 0) {
            $fail('Сума не може бути від’ємною.');
        }

        if ($method !== 'later' && $amount <= 0) {
            $fail('Вкажіть суму оплати або оберіть «Ще не оплачено».');
        }

        $mine = in_array(GCM_Core::ROLE, (array)$user->roles, true);

        $labels = array(
            'edit' => 'Редагування картки Google Maps',
            'create' => 'Створення картки Google Maps',
            'reviews' => 'Збір відгуків'
        );

        $ok = $wpdb->insert($oT, array_merge($data, array(
            'client_id' => $client->id,
            'manager_id' => $uid,
            'service' => $labels[$rt],
            'amount' => $amount,
            'commission_rate' => $mine ? GCM_Core::commission_rate($uid) : 0,
            'status' => $kind === 'reviews' ? 'done' : 'draft',
            'created_at' => current_time('mysql')
        )));

        if (!$ok) {
            GCM_Core::discard($new);
            $fail('Не вдалося зберегти роботу. Спробуйте ще раз.');
        }

        $order = GCM_Core::get_case($wpdb->insert_id);
        $msg = 'Роботу створено.';

        if ($method !== 'later' && $amount > 0) {
            $pid = GCM_Core::add_payment($order, $amount, $method, '');

            $msg .= is_wp_error($pid)
                ? ' Але оплату не збережено: ' . $pid->get_error_message()
                : ' Оплату записано — чекає підтвердження адміністратора.';
        }

        $this->back(
            array('gcm_tab' => 'case', 'id' => $order->id),
            $msg . ($kind === 'map' ? ' Перегляньте зміни перед публікацією.' : '')
        );
    }

    /* ---------- Сторінка роботи ---------- */

    private function case_html($id) {
        global $wpdb;

        $case = GCM_Core::get_case($id);

        if (!GCM_Core::user_owns_case($case)) {
            return '<div class="gcm-alert gcm-alert-err">Роботу не знайдено.</div>';
        }

        $client = GCM_Core::get_client($case->client_id);
        $nonce = wp_nonce_field('gcm_front', '_wpnonce', true, false);
        $act = esc_url(admin_url('admin-post.php'));
        $is_map = ($case->request_type !== 'reviews');
        $title = $case->biz_name ?: ($client ? $client->name : 'Робота #' . $case->id);

        $btn = function ($do, $label, $cls = 'gcm-btn', $extra = '') use ($nonce, $act, $case) {
            return '<form method="post" action="' . $act . '" class="gcm-inline-form">' .
                $nonce .
                '<input type="hidden" name="action" value="gcm_front">' .
                '<input type="hidden" name="gcm_do" value="' . esc_attr($do) . '">' .
                '<input type="hidden" name="case_id" value="' . (int)$case->id . '">' .
                '<button class="' . esc_attr($cls) . '" ' . $extra . '>' .
                esc_html($label) .
                '</button></form>';
        };

        ob_start();

        echo '<div class="gcm-box"><div class="gcm-row"><div><h3 style="margin:0">' .
            esc_html($title) .
            '</h3><span class="gcm-muted">' .
            esc_html(GCM_Core::type_label($case->request_type)) .
            ' · ' .
            esc_html($client ? $client->name . ' ' . $client->email : '') .
            '</span></div>';

        echo '<div><span class="gcm-badge">' .
            esc_html(GCM_Core::status_label($case->status)) .
            '</span> <a class="gcm-btn gcm-btn-sm" href="' .
            esc_url($this->dash(array('gcm_tab' => 'work', 'id' => $case->id))) .
            '">Редагувати</a></div></div>';

        if ($case->gbp_error) {
            echo '<p class="gcm-alert gcm-alert-err">' . esc_html($case->gbp_error) . '</p>';
        }

        if ($case->maps_url) {
            echo '<p><a href="' . esc_url($case->maps_url) . '" target="_blank" rel="noopener">Відкрити картку на Google Maps ↗</a></p>';
        }

        echo '</div>';

        // Доступ до вже існуючої картки
        if ($case->request_type === 'edit') {
            echo '<div class="gcm-box"><h3>Доступ до картки</h3>';

            if ($case->gbp_location !== '') {
                echo '<p class="gcm-alert gcm-alert-ok">✓ Доступ є — можна публікувати зміни.</p>';
            } else {
                $link = GCM_Publisher::access_link($case);

                echo '<p>Щоб змінювати картку, клієнт має додати нашу Google-пошту менеджером у своєму Business Profile. Надішліть клієнту посилання з покроковою інструкцією — це 1–2 хвилини.</p>';

                echo '<div class="gcm-inline"><input readonly value="' . esc_attr($link) . '" onclick="this.select()"><button type="button" data-copy="' . esc_attr($link) . '">Копіювати</button></div>';

                echo '<p class="gcm-actions">' .
                    ($client && is_email($client->email)
                        ? $btn('request_access', 'Надіслати клієнту на email', 'gcm-btn')
                        : '') .
                    '<button type="button" class="gcm-btn" id="gcm-share" data-url="' .
                    esc_attr($link) .
                    '" data-title="Додайте менеджера до картки Google Maps" hidden>Поділитися (месенджер/SMS)</button> ' .
                    $btn('check_access', 'Перевірити доступ', 'gcm-btn gcm-btn-alt') .
                    '</p>';

                if ($case->access_state === 'client_done') {
                    echo '<p class="gcm-alert">Клієнт повідомив, що додав доступ. Натисніть «Перевірити доступ» — інколи Google оновлюється з затримкою в кілька хвилин.</p>';
                }

                if (!GCM_Core::setting('central_email')) {
                    echo '<p class="gcm-alert gcm-alert-err">Адміністратор не вказав Google-пошту центрального акаунта — клієнт не зможе її побачити в інструкції.</p>';
                }
            }

            echo '</div>';
        }

        // Попередній перегляд, перевірка, публікація
        if ($is_map) {
            echo '<div class="gcm-box"><h3>Попередній перегляд змін</h3>';

            echo '<p class="gcm-actions">' .
                '<button type="button" class="gcm-btn" id="gcm-preview-btn" data-case="' . (int)$case->id . '">Показати, що зміниться</button> ' .
                '<button type="button" class="gcm-btn gcm-btn-alt" id="gcm-validate-btn" data-case="' . (int)$case->id . '">Перевірити в Google без публікації</button>' .
                '</p>';

            echo '<div id="gcm-preview-out" aria-live="polite"></div></div>';

            echo '<div class="gcm-box"><h3>Публікація</h3><p class="gcm-muted">Зміни вносяться через центральний акаунт — ваші Google-ключі не потрібні.</p>';

            echo $btn(
                'publish',
                $case->request_type === 'create' && !$case->gbp_location
                    ? 'Створити картку в Google'
                    : 'Опублікувати зміни',
                'gcm-btn gcm-btn-lg',
                'onclick="return confirm(\'Відправити зміни в Google?\')"'
            );

            if ($case->published_at) {
                echo '<p class="gcm-muted">Остання публікація: ' .
                    esc_html(mysql2date('d.m.Y H:i', $case->published_at)) .
                    '</p>';
            }

            echo '</div>';

            if ($case->status === 'created') {
                echo '<div class="gcm-box"><h3>Верифікація нової картки</h3><p>Google показує нову картку на Maps лише після верифікації (дзвінок, SMS, email, відео або листівка). Увійдіть у <a href="https://business.google.com/" target="_blank" rel="noopener">business.google.com</a> під центральним акаунтом, відкрийте цю картку й пройдіть «Верифікувати». Код/дзвінок буде на контактах бізнесу — підтримуйте зв’язок із клієнтом.</p></div>';
            }
        }

        // Відгуки — законний збір
        if ($case->place_id) {
            $rl = GCM_Publisher::review_link($case->place_id);
            $name = $case->biz_name ?: ($client ? $client->name : '');

            echo '<div class="gcm-box"><h3>Збір відгуків від справжніх клієнтів</h3><p>Прямe посилання, яке відкриває форму відгуку на картці:</p>';

            echo '<div class="gcm-inline"><input readonly value="' . esc_attr($rl) . '" onclick="this.select()"><button type="button" data-copy="' . esc_attr($rl) . '">Копіювати</button></div>';

            $sms = 'Дякуємо, що обрали «' . $name . '»! Будемо вдячні за чесний відгук: ' . $rl;

            $mail = "Вітаємо!\n\nДякуємо, що скористалися послугами «" .
                $name .
                "». Нам важлива ваша думка — залиште, будь ласка, короткий відгук на Google Maps (це 1 хвилина):\n" .
                $rl .
                "\n\nДякуємо!";

            echo '<label>Текст для SMS / месенджера<textarea readonly rows="3" onclick="this.select()">' .
                esc_textarea($sms) .
                '</textarea></label><button type="button" class="gcm-btn gcm-btn-sm" data-copy="' .
                esc_attr($sms) .
                '">Копіювати SMS</button>';

            echo '<label>Текст для email<textarea readonly rows="5" onclick="this.select()">' .
                esc_textarea($mail) .
                '</textarea></label><button type="button" class="gcm-btn gcm-btn-sm" data-copy="' .
                esc_attr($mail) .
                '">Копіювати лист</button>';

            echo '<p class="gcm-muted">Відгуки можуть залишати лише реальні клієнти. Плагін не створює відгуки від імені інших людей — це порушує правила Google і закон про рекламу, а картку можуть заблокувати.</p></div>';
        }

        // Медіа
        $media = GCM_Core::media_items($case->media);

        if ($media) {
            echo '<div class="gcm-box"><h3>Матеріали</h3><div class="gcm-thumbs">';

            foreach ($media as $m) {
                echo $m['video']
                    ? '<a href="' . esc_url($m['url']) . '" target="_blank" rel="noopener"><video src="' . esc_url($m['url']) . '" muted playsinline preload="metadata"></video></a>'
                    : '<a href="' . esc_url($m['url']) . '" target="_blank" rel="noopener"><img src="' . esc_url($m['url']) . '" alt="" loading="lazy"></a>';
            }

            echo '</div></div>';
        }

        // Оплати
        $pays = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM " . GCM_Core::t('payments') . " WHERE order_id=%d ORDER BY id DESC",
            $case->id
        ));

        $remaining = max(0, round((float)$case->amount - GCM_Core::order_reserved($case->id), 2));

        echo '<div class="gcm-box"><h3>Оплата</h3><p>Сума: <strong>' .
            GCM_Core::money($case->amount) .
            '</strong> · залишок: <strong>' .
            GCM_Core::money($remaining) .
            '</strong> · ваш відсоток: ' .
            esc_html(rtrim(rtrim(number_format((float)$case->commission_rate, 2, '.', ''), '0'), '.')) .
            '%</p>';

        echo '<div class="gcm-scroll"><table class="gcm-table"><thead><tr><th>Дата</th><th>Сума</th><th>Спосіб</th><th>Комісія</th><th>Стан</th></tr></thead><tbody>';

        foreach ((array)$pays as $p) {
            echo '<tr><td>' . esc_html(mysql2date('d.m.Y', $p->created_at)) .
                '</td><td>' . GCM_Core::money($p->amount) .
                '</td><td>' . esc_html(GCM_Core::method_label($p->method)) .
                '</td><td>' . GCM_Core::money($p->commission) .
                '</td><td>' . esc_html(GCM_Core::settlement_text($p)) .
                '</td></tr>';
        }

        if (!$pays) {
            echo '<tr><td colspan="5">Оплат ще немає.</td></tr>';
        }

        echo '</tbody></table></div>';

        if ($remaining > 0) {
            $privat = GCM_Core::privat_link();

            echo '<form method="post" action="' . $act . '" id="gcm-payform">' .
                $nonce .
                '<input type="hidden" name="action" value="gcm_front">' .
                '<input type="hidden" name="gcm_do" value="report_payment">' .
                '<input type="hidden" name="case_id" value="' . (int)$case->id . '">' .
                '<h4>Зафіксувати оплату</h4><div class="gcm-grid">';

            echo '<label>Отримано<input required type="number" step="0.01" min="0.01" max="' .
                esc_attr($remaining) .
                '" name="amount" value="' . esc_attr($remaining) . '"></label>';

            echo '<label>Спосіб<select name="method"><option value="cash">Готівка (отримую я)</option><option value="card">Картка / Приват Конверт</option></select></label>';

            echo '<label>Примітка<input name="note" maxlength="255"></label></div>';

            echo '<div id="gcm-card-box" class="gcm-alert" hidden>' .
                ($privat
                    ? 'Посилання для оплати карткою: <a href="' . esc_url($privat) . '" target="_blank" rel="noopener" class="gcm-btn gcm-btn-sm">Приват Конверт</a> <button type="button" class="gcm-btn gcm-btn-sm" data-copy="' . esc_attr($privat) . '">Копіювати</button>'
                    : 'Адміністратор ще не додав посилання на Приват Конверт.') .
                '</div>';

            echo '<button class="gcm-btn">Зафіксувати оплату</button></form>';
        }

        echo '</div>';

        return ob_get_clean();
    }

    /* ---------- Гроші та комісія ---------- */

    private function money_html() {
        global $wpdb;

        $uid = get_current_user_id();
        $b = GCM_Core::balances($uid);

        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT p.*, o.service, o.biz_name, c.name client_name
            FROM " . GCM_Core::t('payments') . " p
            LEFT JOIN " . GCM_Core::t('orders') . " o ON o.id=p.order_id
            LEFT JOIN " . GCM_Core::t('clients') . " c ON c.id=o.client_id
            WHERE p.manager_id=%d
            ORDER BY p.id DESC LIMIT 300",
            $uid
        ));

        ob_start();

        echo '<div class="gcm-grid gcm-stats">';

        $stat = function ($l, $v, $hint = '') {
            return '<div class="gcm-box"><span class="gcm-muted">' .
                esc_html($l) .
                '</span><h2>' .
                GCM_Core::money($v) .
                '</h2>' .
                ($hint ? '<span class="gcm-muted">' . esc_html($hint) . '</span>' : '') .
                '</div>';
        };

        echo $stat('Оплати клієнтів (підтверджені)', $b['confirmed_total']);
        echo $stat('Ваша комісія нарахована', $b['earned']);
        echo $stat('Очікує підтвердження', $b['pending_comm'], 'комісія з оплат, які ще не підтвердив адмін');
        echo $stat('Вам має виплатити адмін', $b['to_manager'], 'комісія з оплат карткою');
        echo $stat('Ви маєте передати адміну', $b['to_admin'], 'готівка мінус ваша комісія');
        echo $stat('Уже виплачено вам', $b['paid_out']);

        echo '</div>';

        echo '<div class="gcm-box"><h3>Оплати та комісії</h3><div class="gcm-scroll"><table class="gcm-table"><thead><tr><th>Дата</th><th>Клієнт / послуга</th><th>Сума</th><th>Спосіб</th><th>Комісія</th><th>Розрахунок</th></tr></thead><tbody>';

        foreach ((array)$rows as $p) {
            echo '<tr><td>' . esc_html(mysql2date('d.m.Y', $p->created_at)) .
                '</td><td>' . esc_html($p->client_name . ' / ' . ($p->biz_name ?: $p->service)) .
                '</td><td>' . GCM_Core::money($p->amount) .
                '</td><td>' . esc_html(GCM_Core::method_label($p->method)) .
                '</td><td>' . GCM_Core::money($p->commission) .
                '</td><td>' . esc_html(GCM_Core::settlement_text($p)) .
                '</td></tr>';
        }

        if (!$rows) {
            echo '<tr><td colspan="6">Оплат поки немає.</td></tr>';
        }

        echo '</tbody></table></div></div>';

        return ob_get_clean();
    }

    /* ---------- Обробка POST ---------- */

    public function handle_front() {
        $do = sanitize_key($_POST['gcm_do'] ?? '');

        if ($do === 'save_case') {
            $this->save_case();
        }

        $this->verify();

        $case = GCM_Core::get_case(absint($_POST['case_id'] ?? 0));

        if (!GCM_Core::user_owns_case($case)) {
            wp_die('Доступ заборонено.');
        }

        $to = array('gcm_tab' => 'case', 'id' => $case->id);
        $client = GCM_Core::get_client($case->client_id);

        if ($do === 'report_payment') {
            $r = GCM_Core::add_payment(
                $case,
                (float)str_replace(',', '.', (string)wp_unslash($_POST['amount'] ?? '0')),
                sanitize_key($_POST['method'] ?? ''),
                wp_unslash($_POST['note'] ?? '')
            );

            if (is_wp_error($r)) {
                $this->back($to, $r->get_error_message(), 'err');
            }

            $this->back($to, 'Оплату записано. Вона з’явиться в «Гроші та комісія» і чекає підтвердження адміністратора.');
        }

        if ($do === 'publish') {
            if (!$this->limit('pub' . $case->id, 3, MINUTE_IN_SECONDS)) {
                $this->back($to, 'Зачекайте хвилину перед наступною спробою.', 'err');
            }

            $res = GCM_Publisher::publish($case, false);

            $this->back(
                $to,
                implode(' ', $res['lines']),
                $res['ok'] ? 'ok' : 'err'
            );
        }

        if ($do === 'request_access') {
            $sent = GCM_Publisher::send_access_email($case, $client);

            $this->back(
                $to,
                $sent
                    ? 'Інструкцію надіслано клієнту на ' . $client->email . '.'
                    : 'Не вдалося надіслати лист. Скопіюйте посилання й надішліть клієнту самостійно.',
                $sent ? 'ok' : 'err'
            );
        }

        if ($do === 'check_access') {
            if (!$this->limit('chk' . $case->id, 6, 10 * MINUTE_IN_SECONDS)) {
                $this->back($to, 'Забагато перевірок. Спробуйте за кілька хвилин.', 'err');
            }

            $accepted = GCM_Publisher::auto_accept($case);
            $loc = GCM_Publisher::check_access($case);

            if (is_wp_error($loc)) {
                $this->back(
                    $to,
                    ($accepted ? 'Запрошення прийнято, але доступ ще не оновився — спробуйте за кілька хвилин. ' : '') .
                    $loc->get_error_message(),
                    'err'
                );
            }

            $this->back(
                $to,
                'Доступ є: ' . ($loc['title'] ?: 'картка підключена') . '. Можна публікувати.'
            );
        }

        wp_die('Невідома дія.');
    }

    /* ---------- AJAX ---------- */

    private function ajax_guard() {
        if (!GCM_Core::can_manage()) {
            wp_send_json_error(array('message' => 'Доступ заборонено.'), 403);
        }

        check_ajax_referer('gcm_ajax', 'nonce');
    }

    public function ajax_lookup_client() {
        $this->ajax_guard();

        $email = sanitize_email(wp_unslash($_POST['email'] ?? ''));

        if (!is_email($email)) {
            wp_send_json_success(array('state' => 'invalid'));
        }

        $cl = GCM_Core::client_by_email($email);

        if (!$cl) {
            wp_send_json_success(array('state' => 'new'));
        }

        if ((int)$cl->manager_id !== get_current_user_id() && !GCM_Core::is_admin_user()) {
            wp_send_json_success(array('state' => 'other'));
        }

        global $wpdb;

        $cases = $wpdb->get_results($wpdb->prepare(
            "SELECT id,biz_name,place_id,maps_url,request_type,status
            FROM " . GCM_Core::t('orders') . "
            WHERE client_id=%d
            ORDER BY id DESC LIMIT 5",
            $cl->id
        ));

        $out = array();

        foreach ((array)$cases as $c) {
            $out[] = array(
                'id' => (int)$c->id,
                'biz' => $c->biz_name,
                'place_id' => $c->place_id,
                'type' => GCM_Core::type_label($c->request_type),
                'status' => GCM_Core::status_label($c->status),
                'url' => $this->dash(array('gcm_tab' => 'case', 'id' => $c->id))
            );
        }

        wp_send_json_success(array(
            'state' => 'mine',
            'name' => $cl->name,
            'phone' => $cl->phone,
            'cases' => $out
        ));
    }

    public function ajax_search_place() {
        $this->ajax_guard();

        if (!$this->limit('sp' . get_current_user_id(), 60, 10 * MINUTE_IN_SECONDS)) {
            wp_send_json_error(array('message' => 'Забагато пошуків. Зачекайте кілька хвилин.'), 429);
        }

        $r = GCM_Google::find_candidates(
            sanitize_text_field(wp_unslash($_POST['q'] ?? ''))
        );

        if (is_wp_error($r)) {
            wp_send_json_error(array('message' => $r->get_error_message()));
        }

        wp_send_json_success(array('places' => $r));
    }

    public function ajax_categories() {
        $this->ajax_guard();

        $r = GCM_Google::search_categories(
            sanitize_text_field(wp_unslash($_POST['term'] ?? ''))
        );

        if (is_wp_error($r)) {
            wp_send_json_error(array('message' => $r->get_error_message()));
        }

        wp_send_json_success(array('items' => $r));
    }

    public function ajax_preview_case() {
        $this->ajax_guard();

        $case = GCM_Core::get_case(absint($_POST['case_id'] ?? 0));

        if (!GCM_Core::user_owns_case($case)) {
            wp_send_json_error(array('message' => 'Роботу не знайдено.'), 403);
        }

        wp_send_json_success(array(
            'html' => GCM_Publisher::preview_html($case)
        ));
    }

    public function ajax_validate_case() {
        $this->ajax_guard();

        $case = GCM_Core::get_case(absint($_POST['case_id'] ?? 0));

        if (!GCM_Core::user_owns_case($case)) {
            wp_send_json_error(array('message' => 'Роботу не знайдено.'), 403);
        }

        if (!$this->limit('val' . $case->id, 10, 10 * MINUTE_IN_SECONDS)) {
            wp_send_json_error(array('message' => 'Забагато перевірок. Зачекайте кілька хвилин.'), 429);
        }

        $res = GCM_Publisher::publish($case, true);

        wp_send_json_success(array(
            'ok' => $res['ok'],
            'lines' => $res['lines']
        ));
    }

    /* ---------- Публічна сторінка для клієнта ---------- */

    private function access_case() {
        global $wpdb;

        $t = sanitize_text_field(wp_unslash($_GET['t'] ?? $_POST['t'] ?? ''));

        if (!preg_match('/^[A-Za-z0-9]{32}$/', $t)) {
            return null;
        }

        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM " . GCM_Core::t('orders') . " WHERE access_token=%s LIMIT 1",
            $t
        ));
    }

    private function access_page_html() {
        $case = $this->access_case();

        if (!$case) {
            return '<div class="gcm-wrap"><h2>Посилання недійсне</h2><p>Попросіть менеджера надіслати нове посилання.</p></div>';
        }

        $central = GCM_Core::setting('central_email', '');

        $done = (
            $case->access_state === 'client_done' ||
            $case->access_state === 'ok' ||
            !empty($_GET['done'])
        );

        ob_start();
        ?>
        <div class="gcm-wrap gcm-access">
          <h2>Додайте менеджера до картки «<?php echo esc_html($case->biz_name ?: 'вашого бізнесу'); ?>»</h2>

          <p>Це потрібно, щоб ми могли оновити вашу картку на Google Maps. Ми не просимо пароль і не отримуємо права власника — лише роль «Менеджер», яку ви можете скасувати будь-коли.</p>

          <?php if ($done): ?>
            <p class="gcm-alert gcm-alert-ok">Дякуємо! Ми отримали вашу відповідь і скоро перевіримо доступ. Якщо потрібно, ми зв’яжемося з вами.</p>
          <?php endif; ?>

          <ol class="gcm-steps">
            <li>
              Відкрийте
              <a class="gcm-btn gcm-btn-sm" href="https://business.google.com/" target="_blank" rel="noopener">Google Business Profile ↗</a>
              і увійдіть у Google-акаунт, який керує картою.
            </li>

            <li>
              Оберіть свій бізнес → <strong>Налаштування профілю бізнесу</strong> →
              <strong>Користувачі й доступ</strong> (People and access).
            </li>

            <li>
              Натисніть <strong>Додати</strong> (Add) і вкажіть цю пошту:

              <?php if ($central): ?>
                <div class="gcm-inline">
                  <input readonly value="<?php echo esc_attr($central); ?>" onclick="this.select()">
                  <button type="button" data-copy="<?php echo esc_attr($central); ?>">Копіювати</button>
                </div>
              <?php else: ?>
                <em>(менеджер повідомить пошту окремо)</em>
              <?php endif; ?>
            </li>

            <li>
              Роль: <strong>Менеджер</strong> (Manager). Натисніть «Запросити».
            </li>
          </ol>

          <?php if (!$done): ?>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
              <input type="hidden" name="action" value="gcm_client_done">
              <input type="hidden" name="t" value="<?php echo esc_attr($case->access_token); ?>">
              <button class="gcm-btn gcm-btn-lg">Я надав(ла) доступ</button>
            </form>
          <?php endif; ?>

          <p class="gcm-muted">Не вдається? Просто відповідайте на лист менеджера — допоможемо.</p>
        </div>
        <?php

        return ob_get_clean();
    }

    public function handle_client_done() {
        $case = $this->access_case();
        $back = home_url('/gmaps-access/');

        if (!$case || !$this->limit('cd' . $this->ip(), 20, HOUR_IN_SECONDS)) {
            wp_safe_redirect($back);
            exit;
        }

        if ($case && $case->access_state !== 'ok') {
            global $wpdb;

            $wpdb->update(
                GCM_Core::t('orders'),
                array('access_state' => 'client_done'),
                array('id' => $case->id)
            );

            $mgr = get_user_by('id', $case->manager_id);
            $link = $this->dash(array('gcm_tab' => 'case', 'id' => $case->id));

            if ($mgr) {
                wp_mail(
                    $mgr->user_email,
                    'Клієнт додав доступ до картки',
                    'Клієнт повідомив, що додав доступ до картки «' .
                    $case->biz_name .
                    "».\nПеревірте доступ: " .
                    $link
                );
            }

            GCM_Publisher::auto_accept($case);
            GCM_Publisher::check_access($case);
        }

        wp_safe_redirect(add_query_arg(
            array(
                't' => $case ? $case->access_token : '',
                'done' => 1
            ),
            $back
        ));

        exit;
    }
}
