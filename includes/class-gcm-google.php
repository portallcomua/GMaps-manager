<?php
if (!defined('ABSPATH')) exit;

/**
 * Усі виклики до Google: Places API (New) і Business Profile APIs.
 * Менеджери не потребують ключів — усе йде через ключі й акаунт адміністратора.
 */
final class GCM_Google {
    const BI  = 'https://mybusinessbusinessinformation.googleapis.com/v1/';
    const AM  = 'https://mybusinessaccountmanagement.googleapis.com/v1/';
    const V4  = 'https://mybusiness.googleapis.com/v4/';
    const PLC = 'https://places.googleapis.com/v1/';

    public static function settings() {
        return get_option(GCM_Core::OPT, array());
    }

    /* ---------- Токени ---------- */

    public static function redirect_uri() {
        return admin_url('admin-post.php?action=gcm_google_callback');
    }

    public static function is_connected() {
        $t = get_option('gcm_google_admin_token', array());
        return !empty($t['refresh_token']);
    }

    public static function access_token() {
        $token = get_option('gcm_google_admin_token', array());

        if (
            !empty($token['access_token']) &&
            !empty($token['expires_at']) &&
            (int)$token['expires_at'] > time() + 60
        ) {
            return $token['access_token'];
        }

        if (empty($token['refresh_token'])) {
            return new WP_Error(
                'gcm_google_auth',
                'Центральний Google-акаунт ще не підключено (налаштування плагіна).'
            );
        }

        $s = self::settings();

        $response = wp_remote_post(
            'https://oauth2.googleapis.com/token',
            array(
                'timeout' => 20,
                'body' => array(
                    'client_id' => $s['client_id'] ?? '',
                    'client_secret' => $s['client_secret'] ?? '',
                    'refresh_token' => $token['refresh_token'],
                    'grant_type' => 'refresh_token',
                )
            )
        );

        if (is_wp_error($response)) {
            return new WP_Error(
                'gcm_google_refresh',
                'Не вдалося оновити доступ Google. Перепідключіть акаунт адміністратора.'
            );
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);

        if (empty($body['access_token'])) {
            return new WP_Error(
                'gcm_google_refresh',
                'Google відхилив оновлення доступу. Перепідключіть акаунт адміністратора.'
            );
        }

        $token['access_token'] = $body['access_token'];
        $token['expires_at'] = time() + absint($body['expires_in'] ?? 3600);

        update_option('gcm_google_admin_token', $token, false);

        return $token['access_token'];
    }

    /* ---------- Низькорівневий запит до Business Profile ---------- */

    private static function request($method, $url, $body = null) {
        $token = self::access_token();

        if (is_wp_error($token)) {
            return $token;
        }

        $args = array(
            'method' => $method,
            'timeout' => 30,
            'headers' => array(
                'Authorization' => 'Bearer ' . $token,
                'Content-Type' => 'application/json'
            )
        );

        if ($body !== null) {
            $args['body'] = wp_json_encode($body);
        }

        $response = wp_remote_request($url, $args);

        if (is_wp_error($response)) {
            return new WP_Error(
                'gcm_http',
                'Не вдалося з’єднатися з Google. Спробуйте ще раз.'
            );
        }

        $code = (int)wp_remote_retrieve_response_code($response);
        $json = json_decode(wp_remote_retrieve_body($response), true);

        if ($code >= 300) {
            return new WP_Error(
                'gcm_google',
                self::explain($code, $json),
                array('http' => $code, 'response' => $json)
            );
        }

        return is_array($json) ? $json : array();
    }

    private static function explain($code, $json) {
        $msg = is_array($json)
            ? ($json['error']['message'] ?? '')
            : '';

        $status = is_array($json)
            ? ($json['error']['status'] ?? '')
            : '';

        $out = $msg ? $msg : 'Google повернув помилку ' . $code . '.';

        if ($code === 429 || stripos($msg, 'quota') !== false) {
            $out .= ' (Схоже, квота Business Profile API дорівнює 0 або вичерпана: подайте запит на доступ до Google Business Profile API.)';
        } elseif ($code === 403 && stripos($msg, 'has not been used') !== false) {
            $out .= ' (Увімкніть цей API у Google Cloud.)';
        } elseif ($code === 403 || $status === 'PERMISSION_DENIED') {
            $out .= ' (Центральний акаунт не має потрібного доступу до цієї картки або операції.)';
        }

        return $out;
    }

    /* ---------- Places API (New) ---------- */

    private static function places($method, $path, $mask, $body = null) {
        $key = trim(self::settings()['places_api_key'] ?? '');

        if (!$key) {
            return new WP_Error(
                'gcm_places_key',
                'Адміністратор ще не додав Google Places API key.'
            );
        }

        $args = array(
            'method' => $method,
            'timeout' => 15,
            'headers' => array(
                'X-Goog-Api-Key' => $key,
                'X-Goog-FieldMask' => $mask
            )
        );

        if ($body !== null) {
            $args['headers']['Content-Type'] = 'application/json';
            $args['body'] = wp_json_encode($body);
        }

        $response = wp_remote_request(self::PLC . $path, $args);

        if (is_wp_error($response)) {
            return new WP_Error(
                'gcm_places',
                'Google Maps не відповів. Спробуйте ще раз.'
            );
        }

        $code = (int)wp_remote_retrieve_response_code($response);
        $json = json_decode(wp_remote_retrieve_body($response), true);

        if ($code === 404) {
            return null;
        }

        if ($code >= 300) {
            return new WP_Error(
                'gcm_places',
                'Google Places API відхилив запит. Перевірте ключ, Places API (New) та billing у Google Cloud. ' .
                ($json['error']['message'] ?? '')
            );
        }

        return is_array($json) ? $json : array();
    }

    /** До 5 кандидатів: id, name, address, url */
    public static function search_places($text, $bias = null) {
        $body = array(
            'textQuery' => $text,
            'languageCode' => 'uk',
            'pageSize' => 5
        );

        if (is_array($bias) && isset($bias['lat'], $bias['lng'])) {
            $body['locationBias'] = array(
                'circle' => array(
                    'center' => array(
                        'latitude' => (float)$bias['lat'],
                        'longitude' => (float)$bias['lng']
                    ),
                    'radius' => 500.0
                )
            );
        }

        $res = self::places(
            'POST',
            'places:searchText',
            'places.id,places.displayName,places.formattedAddress,places.googleMapsUri',
            $body
        );

        if (is_wp_error($res)) {
            return $res;
        }

        $out = array();

        foreach (($res['places'] ?? array()) as $p) {
            if (empty($p['id'])) {
                continue;
            }

            $out[] = array(
                'id' => $p['id'],
                'name' => $p['displayName']['text'] ?? '',
                'address' => $p['formattedAddress'] ?? '',
                'url' => $p['googleMapsUri'] ?? ''
            );
        }

        return $out;
    }

    public static function place_details($place_id) {
        $ck = 'gcm_pd_' . md5($place_id);
        $c = get_transient($ck);

        if (is_array($c)) {
            return $c;
        }

        $mask = 'id,displayName,formattedAddress,googleMapsUri,internationalPhoneNumber,nationalPhoneNumber,websiteUri,regularOpeningHours,primaryTypeDisplayName';

        $res = self::places(
            'GET',
            'places/' . rawurlencode($place_id) . '?languageCode=uk',
            $mask
        );

        if (is_wp_error($res) || $res === null) {
            return $res;
        }

        set_transient($ck, $res, 5 * MINUTE_IN_SECONDS);

        return $res;
    }

    /** Що ввів менеджер: Place ID, довге або коротке посилання Google Maps, або текст. */
    public static function resolve_input($input) {
        $input = trim((string)$input);

        if ($input === '') {
            return new WP_Error(
                'gcm_empty',
                'Введіть назву й місто бізнесу або посилання на Google Maps.'
            );
        }

        if (preg_match('/^ChIJ[A-Za-z0-9_-]{10,}$/', $input)) {
            return array('place_id' => $input);
        }

        if (!preg_match('~^https?://~i', $input)) {
            return array('query' => $input);
        }

        $host = strtolower((string)wp_parse_url($input, PHP_URL_HOST));
        $short = in_array($host, array('maps.app.goo.gl', 'goo.gl'), true);
        $google = (bool)preg_match('/(^|\.)google\.[a-z.]{2,}$/', $host);

        if (!$short && !$google) {
            return new WP_Error(
                'gcm_link',
                'Підтримуються лише посилання Google Maps.'
            );
        }

        $final = $input;

        if ($short) {
            $r = wp_safe_remote_get(
                $input,
                array('timeout' => 10, 'redirection' => 5)
            );

            if (
                !is_wp_error($r) &&
                isset($r['http_response']) &&
                is_object($r['http_response']) &&
                method_exists($r['http_response'], 'get_response_object')
            ) {
                $obj = $r['http_response']->get_response_object();

                if (is_object($obj) && !empty($obj->url)) {
                    $final = $obj->url;
                }
            }

            $fh = strtolower((string)wp_parse_url($final, PHP_URL_HOST));

            if (!preg_match('/(^|\.)google\.[a-z.]{2,}$/', $fh)) {
                return new WP_Error(
                    'gcm_link',
                    'Не вдалося відкрити коротке посилання. Скопіюйте повне посилання з браузера або введіть назву.'
                );
            }
        }

        return self::parse_maps_url($final);
    }

    public static function parse_maps_url($url) {
        $parts = wp_parse_url($url);

        if (!is_array($parts)) {
            return new WP_Error('gcm_link', 'Некоректне посилання Google Maps.');
        }

        parse_str($parts['query'] ?? '', $q);

        foreach (array('query_place_id', 'place_id') as $k) {
            if (
                !empty($q[$k]) &&
                preg_match('/^ChIJ[A-Za-z0-9_-]+$/', $q[$k])
            ) {
                return array('place_id' => $q[$k]);
            }
        }

        $out = array();
        $path = rawurldecode($parts['path'] ?? '');
        $text = '';

        if (preg_match('~/maps/place/([^/@]+)~', $path, $m)) {
            $text = trim(str_replace('+', ' ', $m[1]));
        }

        if (!$text) {
            $text = trim((string)($q['query'] ?? $q['q'] ?? ''));
        }

        if (
            preg_match('~@(-?\d+\.\d+),(-?\d+\.\d+)~', $path, $m) ||
            preg_match('~!3d(-?\d+\.\d+)!4d(-?\d+\.\d+)~', $url, $m)
        ) {
            $out['bias'] = array(
                'lat' => (float)$m[1],
                'lng' => (float)$m[2]
            );
        }

        if (!$text) {
            return new WP_Error(
                'gcm_link',
                'Не вдалося прочитати місце з посилання. Введіть назву та місто бізнесу.'
            );
        }

        $out['query'] = $text;

        return $out;
    }

    /** Єдина точка: що ввів менеджер -> список кандидатів. */
    public static function find_candidates($input) {
        $r = self::resolve_input($input);

        if (is_wp_error($r)) {
            return $r;
        }

        if (!empty($r['place_id'])) {
            $d = self::place_details($r['place_id']);

            if (is_wp_error($d)) {
                return $d;
            }

            if (!$d) {
                return array();
            }

            return array(
                array(
                    'id' => $d['id'],
                    'name' => $d['displayName']['text'] ?? '',
                    'address' => $d['formattedAddress'] ?? '',
                    'url' => $d['googleMapsUri'] ?? ''
                )
            );
        }

        return self::search_places(
            $r['query'],
            $r['bias'] ?? null
        );
    }

    /* ---------- Business Profile: акаунти, картки ---------- */

    public static function accounts() {
        $out = array();
        $url = self::AM . 'accounts?pageSize=20';

        for ($i = 0; $i < 5 && $url; $i++) {
            $r = self::request('GET', $url);

            if (is_wp_error($r)) {
                return $r;
            }

            foreach (($r['accounts'] ?? array()) as $a) {
                $out[] = $a;
            }

            $url = !empty($r['nextPageToken'])
                ? self::AM . 'accounts?pageSize=20&pageToken=' . rawurlencode($r['nextPageToken'])
                : '';
        }

        return $out;
    }

    public static function default_account() {
        $set = trim(self::settings()['gbp_account'] ?? '');

        if ($set) {
            return preg_match('~^accounts/~', $set)
                ? $set
                : 'accounts/' . $set;
        }

        $acc = self::accounts();

        if (is_wp_error($acc)) {
            return $acc;
        }

        foreach ($acc as $a) {
            if (($a['type'] ?? '') === 'PERSONAL' && !empty($a['name'])) {
                return $a['name'];
            }
        }

        foreach ($acc as $a) {
            if (!empty($a['name'])) {
                return $a['name'];
            }
        }

        return new WP_Error(
            'gcm_no_account',
            'У центральному Google-акаунті не знайдено Business Profile акаунтів.'
        );
    }

    /** Шукає картку за Place ID серед акаунтів, до яких має доступ центральний акаунт. */
    public static function find_location($place_id) {
        $accounts = self::accounts();

        if (is_wp_error($accounts)) {
            return $accounts;
        }

        foreach ($accounts as $a) {
            if (empty($a['name'])) {
                continue;
            }

            $filter = 'metadata.place_id="' . $place_id . '"';

            $url = self::BI . $a['name'] .
                '/locations?readMask=' .
                rawurlencode('name,title') .
                '&pageSize=10&filter=' .
                rawurlencode($filter);

            $r = self::request('GET', $url);

            if (is_wp_error($r)) {
                continue;
            }

            foreach (($r['locations'] ?? array()) as $loc) {
                if (!empty($loc['name'])) {
                    return array(
                        'account' => $a['name'],
                        'location' => $loc['name'],
                        'title' => $loc['title'] ?? ''
                    );
                }
            }
        }

        return new WP_Error(
            'gcm_no_access',
            'Центральний Google-акаунт поки не має доступу до цієї картки.'
        );
    }

    public static function get_location($location, $mask) {
        return self::request(
            'GET',
            self::BI . $location . '?readMask=' . rawurlencode($mask)
        );
    }

    public static function patch_location($location, $fields, $mask, $validate_only = false) {
        $url = self::BI . $location .
            '?updateMask=' . rawurlencode(implode(',', $mask)) .
            '&languageCode=uk' .
            ($validate_only ? '&validateOnly=true' : '');

        return self::request('PATCH', $url, $fields);
    }

    public static function create_location($account, $body, $validate_only = false, $request_id = '') {
        $url = self::BI . $account .
            '/locations?validateOnly=' .
            ($validate_only ? 'true' : 'false');

        if ($request_id) {
            $url .= '&requestId=' . rawurlencode(substr($request_id, 0, 50));
        }

        return self::request('POST', $url, $body);
    }

    public static function add_photo($account, $location, $source_url, $category = 'ADDITIONAL') {
        return self::request(
            'POST',
            self::V4 . $account . '/' . $location . '/media',
            array(
                'mediaFormat' => 'PHOTO',
                'locationAssociation' => array('category' => $category),
                'sourceUrl' => $source_url
            )
        );
    }

    public static function search_categories($term) {
        $term = trim($term);

        if ($term === '') {
            return array();
        }

        $ck = 'gcm_cat_' . md5(mb_strtolower($term));
        $c = get_transient($ck);

        if (is_array($c)) {
            return $c;
        }

        $url = self::BI . 'categories?regionCode=UA&languageCode=uk&view=BASIC&pageSize=10&filter=' .
            rawurlencode('displayName=' . $term);

        $r = self::request('GET', $url);

        if (is_wp_error($r)) {
            return $r;
        }

        $out = array();

        foreach (($r['categories'] ?? array()) as $cat) {
            if (!empty($cat['name'])) {
                $out[] = array(
                    'id' => $cat['name'],
                    'name' => $cat['displayName'] ?? $cat['name']
                );
            }
        }

        set_transient($ck, $out, DAY_IN_SECONDS);

        return $out;
    }

    /* ---------- Керування доступом до картки ---------- */

    /**
     * Додає адміністратора до локації.
     *
     * Google сам перевіряє доступність ролі та можливість запрошення.
     * Метод не повідомляє про успіх, якщо Google повернув помилку.
     */
    public static function invite_location_admin($location, $email, $role = 'OWNER') {
        $location = trim((string)$location);
        $email = sanitize_email($email);
        $role = strtoupper(sanitize_key($role));

        if (!preg_match('~^locations/[^/]+$~', $location)) {
            return new WP_Error('gcm_location', 'Некоректний ідентифікатор картки Google.');
        }

        if (!is_email($email)) {
            return new WP_Error('gcm_admin_email', 'Вкажіть коректний email Google-акаунта клієнта.');
        }

        if (!in_array($role, array('OWNER', 'MANAGER', 'SITE_MANAGER'), true)) {
            return new WP_Error('gcm_admin_role', 'Некоректна роль адміністратора Google.');
        }

        $url = self::AM . $location . '/admins';

        return self::request(
            'POST',
            $url,
            array(
                'adminName' => $email,
                'role' => $role
            )
        );
    }

    /**
     * Отримує список адміністраторів картки.
     * Використовується для перевірки фактичного доступу клієнта.
     */
    public static function location_admins($location) {
        $location = trim((string)$location);

        if (!preg_match('~^locations/[^/]+$~', $location)) {
            return new WP_Error('gcm_location', 'Некоректний ідентифікатор картки Google.');
        }

        return self::request(
            'GET',
            self::AM . $location . '/admins'
        );
    }

    /**
     * Перевіряє, чи є email серед адміністраторів локації.
     * Повертає true/false або WP_Error.
     */
    public static function is_location_admin($location, $email) {
        $email = strtolower(trim(sanitize_email($email)));

        if (!is_email($email)) {
            return new WP_Error('gcm_admin_email', 'Некоректний email клієнта.');
        }

        $result = self::location_admins($location);

        if (is_wp_error($result)) {
            return $result;
        }

        foreach (($result['admins'] ?? array()) as $admin) {
            $name = strtolower(trim((string)($admin['adminName'] ?? '')));

            if ($name === $email) {
                return true;
            }
        }

        return false;
    }

    /**
     * Передає локацію в інший Business Profile акаунт.
     *
     * Google може відхилити передачу через роль, права,
     * обмеження нового власника або стан локації.
     */
    public static function transfer_location($location, $destination_account) {
        $location = trim((string)$location);
        $destination_account = trim((string)$destination_account);

        if (!preg_match('~^locations/[^/]+$~', $location)) {
            return new WP_Error('gcm_location', 'Некоректний ідентифікатор картки Google.');
        }

        if (!preg_match('~^accounts/[^/]+$~', $destination_account)) {
            return new WP_Error('gcm_account', 'Некоректний цільовий Business Profile акаунт.');
        }

        return self::request(
            'POST',
            self::AM . $location . ':transfer',
            array(
                'destinationAccount' => $destination_account
            )
        );
    }

    /* ---------- Запрошення: клієнт додав центральний акаунт менеджером ---------- */

    public static function pending_invitations() {
        $accounts = self::accounts();

        if (is_wp_error($accounts)) {
            return $accounts;
        }

        $out = array();

        foreach ($accounts as $a) {
            if (empty($a['name'])) {
                continue;
            }

            $r = self::request(
                'GET',
                self::AM . $a['name'] . '/invitations'
            );

            if (is_wp_error($r)) {
                continue;
            }

            foreach (($r['invitations'] ?? array()) as $inv) {
                if (!empty($inv['name'])) {
                    $out[] = $inv;
                }
            }
        }

        return $out;
    }

    public static function accept_invitation($name) {
        if (!preg_match('~^accounts/[^/]+/invitations/[^/]+$~', $name)) {
            return new WP_Error('gcm_inv', 'Некоректне запрошення.');
        }

        return self::request(
            'POST',
            self::AM . $name . ':accept',
            new stdClass()
        );
    }

    public static function invitation_title($inv) {
        return $inv['targetLocation']['locationName'] ??
            ($inv['targetAccount']['accountName'] ?? '');
    }

    /* ---------- Допоміжні перетворення ---------- */

    public static function normalize_phone($raw) {
        $raw = trim((string)$raw);

        if ($raw === '') {
            return '';
        }

        $plus = strpos($raw, '+') === 0;
        $d = preg_replace('/\D+/', '', $raw);

        if ($d === '') {
            return '';
        }

        if ($plus) {
            return '+' . $d;
        }

        if (strlen($d) === 10 && $d[0] === '0') {
            return '+38' . $d;
        }

        if (strlen($d) === 12 && strpos($d, '380') === 0) {
            return '+' . $d;
        }

        return '+' . $d;
    }

    public static function hours_periods($hours) {
        $days = array(
            1 => 'MONDAY',
            2 => 'TUESDAY',
            3 => 'WEDNESDAY',
            4 => 'THURSDAY',
            5 => 'FRIDAY',
            6 => 'SATURDAY',
            7 => 'SUNDAY'
        );

        $periods = array();

        foreach ($days as $i => $name) {
            $h = $hours[$i] ?? array();

            if (
                empty($h) ||
                !empty($h['off']) ||
                empty($h['open']) ||
                empty($h['close'])
            ) {
                continue;
            }

            $o = self::time_obj($h['open']);
            $c = self::time_obj($h['close']);

            if (!$o || !$c) {
                continue;
            }

            $periods[] = array(
                'openDay' => $name,
                'openTime' => $o,
                'closeDay' => $name,
                'closeTime' => $c
            );
        }

        return $periods;
    }

    private static function time_obj($s) {
        if (!preg_match('/^(\d{1,2}):(\d{2})$/', trim($s), $m)) {
            return null;
        }

        $h = (int)$m[1];
        $min = (int)$m[2];

        if ($h > 24 || $min > 59 || ($h === 24 && $min > 0)) {
            return null;
        }

        return array(
            'hours' => $h,
            'minutes' => $min
        );
    }

    /* ---------- Діагностика налаштувань ---------- */

    /** Повертає список [назва кроку, текст помилки або null]. */
    public static function diagnose() {
        $steps = array();
        $s = self::settings();

        $steps[] = array(
            'Places API key вказано',
            !empty($s['places_api_key']) ? null : 'Ключ не вказано'
        );

        if (!empty($s['places_api_key'])) {
            $r = self::search_places('Київ');

            $steps[] = array(
                'Пошук у Google Places',
                is_wp_error($r) ? $r->get_error_message() : null
            );
        }

        $steps[] = array(
            'Центральний Google-акаунт підключено',
            self::is_connected() ? null : 'Не підключено'
        );

        if (self::is_connected()) {
            $acc = self::accounts();

            if (is_wp_error($acc)) {
                $steps[] = array(
                    'Business Profile: список акаунтів',
                    $acc->get_error_message()
                );
            } else {
                $steps[] = array(
                    'Business Profile: знайдено акаунтів — ' . count($acc),
                    $acc ? null : 'Порожньо: акаунт без Business Profile'
                );

                $c = self::search_categories('Кав');

                $steps[] = array(
                    'Business Profile: довідник категорій',
                    is_wp_error($c) ? $c->get_error_message() : null
                );
            }
        }

        return $steps;
    }
}
