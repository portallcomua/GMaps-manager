<?php
if (!defined('ABSPATH')) exit;

/**
 * Попередній перегляд змін, перевірка без публікації, публікація в Google,
 * підключення (доступ) картки клієнта до центрального акаунта.
 */
final class GCM_Publisher {

    public static function hours($case) {
        $h = json_decode((string)$case->biz_hours, true);
        return is_array($h) ? $h : array();
    }

    private static function address($case) {
        $a = array('regionCode' => 'UA', 'languageCode' => 'uk', 'locality' => $case->biz_city, 'addressLines' => array($case->biz_street));
        if ($case->biz_postal !== '') $a['postalCode'] = $case->biz_postal;
        return $a;
    }

    public static function set_status($case_id, $status, $error = '', $extra = array()) {
        global $wpdb;
        $wpdb->update(GCM_Core::t('orders'), array_merge(array('status' => $status, 'gbp_error' => $error), $extra), array('id' => (int)$case_id));
    }

    /** Чого бракує для створення нової картки. */
    public static function missing_for_create($case) {
        $m = array();
        if ($case->biz_name === '') $m[] = 'назва бізнесу';
        if ($case->biz_category_id === '') $m[] = 'категорія (оберіть зі списку підказок)';
        if ($case->biz_city === '') $m[] = 'місто';
        if ($case->biz_street === '') $m[] = 'вулиця й номер';
        if ($case->biz_phone === '' && $case->biz_website === '') $m[] = 'телефон або сайт';
        return $m;
    }

    /** Поля для оновлення існуючої картки. */
    public static function patch_payload($case, $location) {
        $f = array(); $mask = array();
        if ($case->biz_name !== '') { $f['title'] = $case->biz_name; $mask[] = 'title'; }
        if ($case->biz_website !== '') { $f['websiteUri'] = $case->biz_website; $mask[] = 'websiteUri'; }
        if (trim((string)$case->biz_description) !== '') { $f['profile'] = array('description' => $case->biz_description); $mask[] = 'profile'; }
        $hours = self::hours($case);
        if ($hours) {
            $periods = GCM_Google::hours_periods($hours);
            if ($periods) { $f['regularHours'] = array('periods' => $periods); $mask[] = 'regularHours'; }
        }
        if ($case->biz_street !== '' && $case->biz_city !== '') { $f['storefrontAddress'] = self::address($case); $mask[] = 'storefrontAddress'; }
        if ($case->biz_phone !== '' || $case->biz_category_id !== '') {
            $ex = GCM_Google::get_location($location, 'phoneNumbers,categories');
            if (is_wp_error($ex)) return $ex;
            if ($case->biz_phone !== '') {
                $pn = isset($ex['phoneNumbers']) && is_array($ex['phoneNumbers']) ? $ex['phoneNumbers'] : array();
                $pn['primaryPhone'] = GCM_Google::normalize_phone($case->biz_phone);
                $f['phoneNumbers'] = $pn; $mask[] = 'phoneNumbers';
            }
            if ($case->biz_category_id !== '') {
                $add = array();
                foreach ((array)($ex['categories']['additionalCategories'] ?? array()) as $c) if (!empty($c['name'])) $add[] = array('name' => $c['name']);
                // Google вимагає передавати обидва поля категорій одночасно.
                $f['categories'] = array('primaryCategory' => array('name' => $case->biz_category_id), 'additionalCategories' => $add);
                $mask[] = 'categories';
            }
        }
        return array('fields' => $f, 'mask' => $mask);
    }

    public static function create_payload($case) {
        $b = array('languageCode' => 'uk', 'title' => $case->biz_name, 'storefrontAddress' => self::address($case),
            'categories' => array('primaryCategory' => array('name' => $case->biz_category_id)));
        if ($case->biz_phone !== '') $b['phoneNumbers'] = array('primaryPhone' => GCM_Google::normalize_phone($case->biz_phone));
        if ($case->biz_website !== '') $b['websiteUri'] = $case->biz_website;
        if (trim((string)$case->biz_description) !== '') $b['profile'] = array('description' => $case->biz_description);
        $periods = GCM_Google::hours_periods(self::hours($case));
        if ($periods) $b['regularHours'] = array('periods' => $periods);
        return $b;
    }

    /* ---------- Публікація / перевірка без публікації ---------- */

    private static function fail($lines, $needs_access = false) {
        return array('ok' => false, 'lines' => (array)$lines, 'needs_access' => $needs_access);
    }

    public static function publish($case, $validate = false) {
        global $wpdb;
        $lines = array();
        if ($case->request_type === 'reviews') return array('ok' => true, 'lines' => array('Для цієї послуги публікація в Google не потрібна.'));

        $loc = null;
        if ($case->gbp_location !== '' && $case->gbp_account !== '') $loc = array('account' => $case->gbp_account, 'location' => $case->gbp_location);
        elseif ($case->request_type === 'edit') {
            if ($case->place_id === '') return self::fail('Не вибрано картку на Google.');
            $loc = GCM_Google::find_location($case->place_id);
            if (is_wp_error($loc)) {
                if (!$validate) self::set_status($case->id, 'needs_access', $loc->get_error_message());
                return self::fail($loc->get_error_message(), true);
            }
        }

        if ($loc) {   // редагування існуючої (або вже створеної раніше) картки
            if (!$validate) $wpdb->update(GCM_Core::t('orders'), array('gbp_account' => $loc['account'], 'gbp_location' => $loc['location']), array('id' => $case->id));
            $pl = self::patch_payload($case, $loc['location']);
            if (is_wp_error($pl)) { if (!$validate) self::set_status($case->id, 'error', $pl->get_error_message()); return self::fail($pl->get_error_message()); }
            if ($pl['mask']) {
                $r = GCM_Google::patch_location($loc['location'], $pl['fields'], $pl['mask'], $validate);
                if (is_wp_error($r)) { if (!$validate) self::set_status($case->id, 'error', $r->get_error_message()); return self::fail($r->get_error_message()); }
                $lines[] = $validate ? 'Google прийняв би ці зміни: ' . implode(', ', $pl['mask']) . '.' : 'Оновлено поля: ' . implode(', ', $pl['mask']) . '.';
            } else {
                $lines[] = 'Немає текстових змін для відправки.';
            }
            $final = $case->request_type === 'create' ? 'created' : 'published';
        } else {      // нова картка
            $missing = self::missing_for_create($case);
            if ($missing) return self::fail('Для створення картки бракує: ' . implode(', ', $missing) . '.');
            $account = GCM_Google::default_account();
            if (is_wp_error($account)) { if (!$validate) self::set_status($case->id, 'error', $account->get_error_message()); return self::fail($account->get_error_message()); }
            $body = self::create_payload($case);
            $r = GCM_Google::create_location($account, $body, $validate, 'gcm-' . $case->id . '-' . substr(md5(wp_json_encode($body)), 0, 12));
            if (is_wp_error($r)) { if (!$validate) self::set_status($case->id, 'error', $r->get_error_message()); return self::fail($r->get_error_message()); }
            if ($validate) return array('ok' => true, 'lines' => array('Google прийняв би створення нової картки.'));
            if (empty($r['name'])) return self::fail('Google не повернув ідентифікатор нової картки.');
            $loc = array('account' => $account, 'location' => $r['name']);
            $wpdb->update(GCM_Core::t('orders'), array('gbp_account' => $account, 'gbp_location' => $r['name']), array('id' => $case->id));
            $lines[] = 'Нову картку створено в Google.';
            $final = 'created';
        }

        if ($validate) return array('ok' => true, 'lines' => $lines);

        self::push_photos($case, $loc['account'], $loc['location'], $lines);
        self::set_status($case->id, $final, '', array('published_at' => current_time('mysql')));
        if ($final === 'created') $lines[] = 'Щоб картка з’явилася на Google Maps, її потрібно верифікувати (див. підказку нижче).';
        return array('ok' => true, 'lines' => $lines);
    }

    private static function push_photos($case, $account, $location, &$lines) {
        global $wpdb;
        $pushed = array_map('intval', (array)json_decode((string)$case->media_pushed, true));
        $ok = 0; $videos = 0;
        foreach (GCM_Core::media_items($case->media) as $m) {
            if (in_array($m['id'], $pushed, true)) continue;
            if ($m['video']) { $videos++; continue; }
            $r = GCM_Google::add_photo($account, $location, $m['url'], $case->photo_category ?: 'ADDITIONAL');
            if (is_wp_error($r)) { $lines[] = 'Фото не додано: ' . $r->get_error_message() . ' (Google має мати змогу відкрити файл за публічним посиланням — на локальному сервері це неможливо.)'; break; }
            $pushed[] = $m['id']; $ok++;
        }
        if ($ok) $lines[] = 'Додано фото: ' . $ok . '.';
        if ($videos) $lines[] = 'Відео (' . $videos . ') збережено в роботі; офіційний API Google приймає лише фото — додайте відео вручну в Business Profile.';
        $wpdb->update(GCM_Core::t('orders'), array('media_pushed' => wp_json_encode(array_values(array_unique($pushed)))), array('id' => $case->id));
    }

    /* ---------- Попередній перегляд ---------- */

    private static function norm($s) { return mb_strtolower(preg_replace('/[^\p{L}\p{N}]+/u', '', (string)$s)); }

    private static function row($label, $before, $after, $always_changed = false, $html_before = false, $html_after = false) {
        if ($after === '' || $after === null) return '';
        $changed = $always_changed || self::norm(wp_strip_all_tags((string)$before)) !== self::norm(wp_strip_all_tags((string)$after));
        $b = $before === '' || $before === null ? '<span class="gcm-muted">—</span>' : ($html_before ? $before : esc_html($before));
        $a = $html_after ? $after : esc_html($after);
        return '<tr class="' . ($changed ? 'gcm-changed' : 'gcm-same') . '"><th>' . esc_html($label) . '</th><td>' . $b . '</td><td>' . $a . '</td><td>' . ($changed ? '<span class="gcm-badge gcm-badge-warn">змінюється</span>' : '<span class="gcm-badge">без змін</span>') . '</td></tr>';
    }

    public static function preview_html($case) {
        $media = GCM_Core::media_items($case->media);
        $photos = 0; $videos = 0;
        foreach ($media as $m) { if ($m['video']) $videos++; else $photos++; }
        $hours = self::hours($case);
        $after_hours = $hours ? implode('<br>', array_map('esc_html', GCM_Core::hours_lines($hours))) : '';
        ob_start();

        if ($case->request_type === 'reviews') {
            echo '<p>Послуга «Збір відгуків» нічого не змінює на картці. Нижче на сторінці роботи — посилання й готові тексти для клієнта.</p>';
            return ob_get_clean();
        }

        if ($case->request_type === 'edit') {
            $d = $case->place_id ? GCM_Google::place_details($case->place_id) : null;
            if (is_wp_error($d)) { echo '<p class="gcm-alert gcm-alert-err">' . esc_html($d->get_error_message()) . '</p>'; $d = null; }
            $d = is_array($d) ? $d : array();
            $before_addr = $d['formattedAddress'] ?? '';
            $after_addr = trim($case->biz_street . ($case->biz_city !== '' ? ', ' . $case->biz_city : '') . ($case->biz_postal !== '' ? ', ' . $case->biz_postal : ''), ' ,');
            $before_hours = !empty($d['regularOpeningHours']['weekdayDescriptions']) ? implode('<br>', array_map('esc_html', $d['regularOpeningHours']['weekdayDescriptions'])) : '';
            $rows = '';
            $rows .= self::row('Назва', $d['displayName']['text'] ?? '', $case->biz_name);
            $rows .= self::row('Категорія', $d['primaryTypeDisplayName']['text'] ?? '', $case->biz_category, true);
            $rows .= self::row('Адреса', $before_addr, $after_addr, true);
            $rows .= self::row('Телефон', $d['nationalPhoneNumber'] ?? ($d['internationalPhoneNumber'] ?? ''), $case->biz_phone);
            $rows .= self::row('Сайт', preg_replace('~^https?://|/$~i', '', (string)($d['websiteUri'] ?? '')), preg_replace('~^https?://|/$~i', '', $case->biz_website));
            $rows .= self::row('Години роботи', $before_hours, $after_hours, true, true, true);
            $rows .= self::row('Опис', '', (string)$case->biz_description, true);
            echo '<p class="gcm-muted">Зліва — те, що клієнти бачать на Google зараз, справа — що буде після публікації.</p>';
            if ($rows) echo '<div class="gcm-scroll"><table class="gcm-table gcm-diff"><thead><tr><th></th><th>Зараз на Google</th><th>Стане</th><th></th></tr></thead><tbody>' . $rows . '</tbody></table></div>';
            else echo '<p class="gcm-alert">Текстових змін немає — буде оновлено лише медіа.</p>';
            if ($case->biz_street !== '' || $case->biz_name !== '') echo '<p class="gcm-alert">Зміна назви чи адреси може тимчасово відправити картку на перевірку Google, а адреси — і на повторну верифікацію.</p>';
        } else {
            $missing = self::missing_for_create($case);
            echo '<div class="gcm-card-mock"><h3>' . esc_html($case->biz_name ?: 'Назва бізнесу') . '</h3>';
            echo '<p class="gcm-muted">' . esc_html($case->biz_category ?: 'Категорія') . '</p>';
            echo '<p>' . esc_html(trim($case->biz_street . ', ' . $case->biz_city . ' ' . $case->biz_postal, ' ,') ?: 'Адреса') . '</p>';
            if ($case->biz_phone) echo '<p>' . esc_html($case->biz_phone) . '</p>';
            if ($case->biz_website) echo '<p>' . esc_html($case->biz_website) . '</p>';
            if ($after_hours) echo '<p>' . $after_hours . '</p>';
            if (trim((string)$case->biz_description) !== '') echo '<p>' . esc_html($case->biz_description) . '</p>';
            echo '</div>';
            if ($missing) echo '<p class="gcm-alert gcm-alert-err">Для публікації ще бракує: ' . esc_html(implode(', ', $missing)) . '.</p>';
            else echo '<p class="gcm-alert gcm-alert-ok">Усі обов’язкові поля заповнені.</p>';
        }

        echo '<p><strong>Медіа:</strong> фото — ' . (int)$photos . ', відео — ' . (int)$videos . '.' . ($videos ? ' Відео Google API не приймає, його треба додати вручну.' : '') . '</p>';
        if ($media) {
            echo '<div class="gcm-thumbs">';
            foreach ($media as $m) echo $m['video'] ? '<video src="' . esc_url($m['url']) . '" muted playsinline preload="metadata"></video>' : '<img src="' . esc_url($m['url']) . '" alt="" loading="lazy">';
            echo '</div>';
        }
        return ob_get_clean();
    }

    /* ---------- Підключення картки клієнта (доступ) ---------- */

    public static function ensure_token($case) {
        global $wpdb;
        if ($case->access_token) return $case->access_token;
        $tok = wp_generate_password(32, false, false);
        $wpdb->update(GCM_Core::t('orders'), array('access_token' => $tok), array('id' => $case->id));
        return $tok;
    }

    public static function access_link($case) {
        return add_query_arg('t', self::ensure_token($case), home_url('/gmaps-access/'));
    }

    public static function check_access($case) {
        global $wpdb;
        if ($case->place_id === '') return new WP_Error('gcm_no_place', 'У роботі не вказано картку Google.');
        $loc = GCM_Google::find_location($case->place_id);
        if (is_wp_error($loc)) return $loc;
        $wpdb->update(GCM_Core::t('orders'), array('gbp_account' => $loc['account'], 'gbp_location' => $loc['location'], 'access_state' => 'ok', 'status' => ($case->status === 'needs_access' ? 'draft' : $case->status), 'gbp_error' => ''), array('id' => $case->id));
        return $loc;
    }

    /** Приймає лише ті запрошення, назва картки в яких схожа на бізнес цієї роботи. */
    public static function auto_accept($case) {
        $inv = GCM_Google::pending_invitations();
        if (is_wp_error($inv) || !$inv) return 0;
        $want = self::norm($case->biz_name);
        if ($want === '') return 0;
        $accepted = 0;
        foreach ($inv as $i) {
            $title = self::norm(GCM_Google::invitation_title($i));
            if ($title === '') continue;
            similar_text($want, $title, $pct);
            if ($pct >= 80 && !is_wp_error(GCM_Google::accept_invitation($i['name']))) $accepted++;
        }
        return $accepted;
    }

    public static function send_access_email($case, $client) {
        if (!$client || !is_email($client->email)) return false;
        $central = GCM_Core::setting('central_email', '');
        $body = "Вітаємо!\n\nЩоб ми могли оновити вашу картку «" . $case->biz_name . "» на Google Maps, додайте нашого спеціаліста до керування карткою — це займе 1–2 хвилини.\n\n"
            . "Відкрийте інструкцію: " . self::access_link($case) . "\n\n"
            . ($central ? "Google-пошта для додавання: " . $central . "\n\n" : '')
            . "Ми не просимо ваш пароль і не отримуємо права власника.\n";
        return wp_mail($client->email, 'Додайте менеджера до вашої картки Google Maps', $body);
    }

    /* ---------- Відгуки (послуга «Збір відгуків») ---------- */

    public static function review_link($place_id) {
        return $place_id ? 'https://search.google.com/local/writereview?placeid=' . rawurlencode($place_id) : '';
    }
}
