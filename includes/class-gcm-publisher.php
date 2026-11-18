<?php
if (!defined('ABSPATH')) exit;

/**
 * Публікація та оновлення карток Google Business Profile.
 * Перевірка даних, попередній перегляд, фото, доступ клієнта.
 */
final class GCM_Publisher {

    public static function hours($case) {
        $h = json_decode((string)$case->biz_hours, true);
        return is_array($h) ? $h : array();
    }

    private static function address($case) {
        $a = array(
            'regionCode' => 'UA',
            'languageCode' => 'uk',
            'locality' => $case->biz_city,
            'addressLines' => array($case->biz_street)
        );

        if ($case->biz_postal !== '') {
            $a['postalCode'] = $case->biz_postal;
        }

        return $a;
    }

    public static function set_status($case_id, $status, $error = '', $extra = array()) {
        global $wpdb;

        $wpdb->update(
            GCM_Core::t('orders'),
            array_merge(
                array(
                    'status' => $status,
                    'gbp_error' => $error
                ),
                $extra
            ),
            array('id' => (int)$case_id)
        );
    }

    /** Перевірка обов'язкових полів нової картки. */
    public static function missing_for_create($case) {
        $missing = array();

        if (trim((string)$case->biz_name) === '') {
            $missing[] = 'назва бізнесу';
        }

        if (trim((string)$case->biz_category_id) === '') {
            $missing[] = 'категорія (оберіть зі списку підказок)';
        }

        if (trim((string)$case->biz_city) === '') {
            $missing[] = 'місто';
        }

        if (trim((string)$case->biz_street) === '') {
            $missing[] = 'вулиця й номер';
        }

        if (
            trim((string)$case->biz_phone) === '' &&
            trim((string)$case->biz_website) === ''
        ) {
            $missing[] = 'телефон або сайт';
        }

        return $missing;
    }

    /** Формування даних для оновлення існуючої картки. */
    public static function patch_payload($case, $location) {
        $fields = array();
        $mask = array();

        if ($case->biz_name !== '') {
            $fields['title'] = $case->biz_name;
            $mask[] = 'title';
        }

        if ($case->biz_website !== '') {
            $fields['websiteUri'] = $case->biz_website;
            $mask[] = 'websiteUri';
        }

        if (trim((string)$case->biz_description) !== '') {
            $fields['profile'] = array(
                'description' => $case->biz_description
            );
            $mask[] = 'profile';
        }

        $hours = self::hours($case);

        if ($hours) {
            $periods = GCM_Google::hours_periods($hours);

            if ($periods) {
                $fields['regularHours'] = array(
                    'periods' => $periods
                );
                $mask[] = 'regularHours';
            }
        }

        if (
            $case->biz_street !== '' &&
            $case->biz_city !== ''
        ) {
            $fields['storefrontAddress'] = self::address($case);
            $mask[] = 'storefrontAddress';
        }

        if (
            $case->biz_phone !== '' ||
            $case->biz_category_id !== ''
        ) {
            $existing = GCM_Google::get_location(
                $location,
                'phoneNumbers,categories'
            );

            if (is_wp_error($existing)) {
                return $existing;
            }

            if ($case->biz_phone !== '') {
                $phones = isset($existing['phoneNumbers']) &&
                    is_array($existing['phoneNumbers'])
                    ? $existing['phoneNumbers']
                    : array();

                $phones['primaryPhone'] = GCM_Google::normalize_phone(
                    $case->biz_phone
                );

                $fields['phoneNumbers'] = $phones;
                $mask[] = 'phoneNumbers';
            }

            if ($case->biz_category_id !== '') {
                $additional = array();

                foreach (
                    (array)($existing['categories']['additionalCategories'] ?? array())
                    as $category
                ) {
                    if (!empty($category['name'])) {
                        $additional[] = array(
                            'name' => $category['name']
                        );
                    }
                }

                $fields['categories'] = array(
                    'primaryCategory' => array(
                        'name' => $case->biz_category_id
                    ),
                    'additionalCategories' => $additional
                );

                $mask[] = 'categories';
            }
        }

        return array(
            'fields' => $fields,
            'mask' => $mask
        );
    }

    /** Формування даних для створення нової картки. */
    public static function create_payload($case) {
        $body = array(
            'languageCode' => 'uk',
            'title' => $case->biz_name,
            'storefrontAddress' => self::address($case),
            'categories' => array(
                'primaryCategory' => array(
                    'name' => $case->biz_category_id
                )
            )
        );

        if ($case->biz_phone !== '') {
            $body['phoneNumbers'] = array(
                'primaryPhone' => GCM_Google::normalize_phone(
                    $case->biz_phone
                )
            );
        }

        if ($case->biz_website !== '') {
            $body['websiteUri'] = $case->biz_website;
        }

        if (trim((string)$case->biz_description) !== '') {
            $body['profile'] = array(
                'description' => $case->biz_description
            );
        }

        $periods = GCM_Google::hours_periods(self::hours($case));

        if ($periods) {
            $body['regularHours'] = array(
                'periods' => $periods
            );
        }

        return $body;
    }

    /* ---------- Допоміжні методи публікації ---------- */

    private static function fail($lines, $needs_access = false) {
        return array(
            'ok' => false,
            'lines' => (array)$lines,
            'needs_access' => $needs_access
        );
    }

    /**
     * Отримує Google email клієнта з таблиці клієнтів.
     */
    private static function client_google_email($case) {
        if (empty($case->client_id)) {
            return '';
        }

        $client = GCM_Core::get_client($case->client_id);

        if (!$client || empty($client->google_email)) {
            return '';
        }

        $email = sanitize_email($client->google_email);

        return is_email($email) ? $email : '';
    }

    /**
     * Запрошує Google email клієнта до керування карткою.
     *
     * Виконується тільки після фактичного створення картки
     * або коли картка вже існує та має ідентифікатор.
     *
     * Результат запрошення не підміняє результат публікації.
     */
    private static function invite_client($case, $account, $location, &$lines) {
        $email = self::client_google_email($case);

        if (!$email) {
            $lines[] = 'Google email клієнта не вказано. Запрошення до керування карткою не надсилалося.';
            return false;
        }

        $result = GCM_Google::invite_location_admin(
            $location,
            $email,
            'OWNER',
            $account
        );

        if (is_wp_error($result)) {
            $lines[] = 'Картку опубліковано, але Google не підтвердив запрошення клієнта (' .
                $email . '): ' . $result->get_error_message();

            self::set_status(
                $case->id,
                'created',
                'Картку створено. Запрошення клієнту не підтверджено: ' .
                $result->get_error_message()
            );

            return false;
        }

        $lines[] = 'Запит на запрошення клієнта (' . $email . ') як власника картки відправлено до Google.';
        $lines[] = 'Передача прав власника вважається завершеною лише після підтвердження Google та прийняття запрошення клієнтом.';

        return true;
    }

    /**
     * Основна публікація.
     *
     * $validate = true — лише перевірка без внесення змін.
     */
    public static function publish($case, $validate = false) {
        global $wpdb;

        $lines = array();

        if (!$case) {
            return self::fail('Роботу не знайдено.');
        }

        if ($case->request_type === 'reviews') {
            return array(
                'ok' => true,
                'lines' => array(
                    'Для цієї послуги публікація в Google не потрібна.'
                )
            );
        }

        $location_data = null;

        /*
         * Якщо ідентифікатори картки вже збережені,
         * використовуємо їх без повторного пошуку.
         */
        if (
            $case->gbp_location !== '' &&
            $case->gbp_account !== ''
        ) {
            $location_data = array(
                'account' => $case->gbp_account,
                'location' => $case->gbp_location
            );
        } elseif ($case->request_type === 'edit') {
            if ($case->place_id === '') {
                return self::fail('Не вибрано картку на Google.');
            }

            $location_data = GCM_Google::find_location(
                $case->place_id
            );

            if (is_wp_error($location_data)) {
                if (!$validate) {
                    self::set_status(
                        $case->id,
                        'needs_access',
                        $location_data->get_error_message()
                    );
                }

                return self::fail(
                    $location_data->get_error_message(),
                    true
                );
            }
        }

        /*
         * Оновлення вже існуючої картки.
         */
        if ($location_data) {
            if (!$validate) {
                $wpdb->update(
                    GCM_Core::t('orders'),
                    array(
                        'gbp_account' => $location_data['account'],
                        'gbp_location' => $location_data['location']
                    ),
                    array('id' => $case->id)
                );
            }

            $payload = self::patch_payload(
                $case,
                $location_data['location']
            );

            if (is_wp_error($payload)) {
                if (!$validate) {
                    self::set_status(
                        $case->id,
                        'error',
                        $payload->get_error_message()
                    );
                }

                return self::fail($payload->get_error_message());
            }

            if (!empty($payload['mask'])) {
                $result = GCM_Google::patch_location(
                    $location_data['location'],
                    $payload['fields'],
                    $payload['mask'],
                    $validate
                );

                if (is_wp_error($result)) {
                    if (!$validate) {
                        self::set_status(
                            $case->id,
                            'error',
                            $result->get_error_message()
                        );
                    }

                    return self::fail($result->get_error_message());
                }

                $lines[] = $validate
                    ? 'Google прийняв би ці зміни: ' . implode(', ', $payload['mask']) . '.'
                    : 'Оновлено поля: ' . implode(', ', $payload['mask']) . '.';
            } else {
                $lines[] = 'Немає текстових змін для відправки.';
            }

            $final_status = $case->request_type === 'create'
                ? 'created'
                : 'published';

        } else {
            /*
             * Створення нової картки.
             */
            $missing = self::missing_for_create($case);

            if ($missing) {
                return self::fail(
                    'Для створення картки бракує: ' .
                    implode(', ', $missing) . '.'
                );
            }

            $account = GCM_Google::default_account();

            if (is_wp_error($account)) {
                if (!$validate) {
                    self::set_status(
                        $case->id,
                        'error',
                        $account->get_error_message()
                    );
                }

                return self::fail($account->get_error_message());
            }

            $body = self::create_payload($case);

            $request_id = 'gcm-' . $case->id . '-' .
                substr(md5(wp_json_encode($body)), 0, 12);

            $result = GCM_Google::create_location(
                $account,
                $body,
                $validate,
                $request_id
            );

            if (is_wp_error($result)) {
                if (!$validate) {
                    self::set_status(
                        $case->id,
                        'error',
                        $result->get_error_message()
                    );
                }

                return self::fail($result->get_error_message());
            }

            if ($validate) {
                return array(
                    'ok' => true,
                    'lines' => array(
                        'Google прийняв би створення нової картки.'
                    )
                );
            }

            if (empty($result['name'])) {
                self::set_status(
                    $case->id,
                    'error',
                    'Google не повернув ідентифікатор нової картки.'
                );

                return self::fail(
                    'Google не повернув ідентифікатор нової картки.'
                );
            }

            $location_data = array(
                'account' => $account,
                'location' => $result['name']
            );

            $wpdb->update(
                GCM_Core::t('orders'),
                array(
                    'gbp_account' => $account,
                    'gbp_location' => $result['name']
                ),
                array('id' => $case->id)
            );

            $lines[] = 'Нову картку створено в Google.';
            $final_status = 'created';
        }

        if ($validate) {
            return array(
                'ok' => true,
                'lines' => $lines
            );
        }

        /*
         * Додавання фотографій.
         */
        self::push_photos(
            $case,
            $location_data['account'],
            $location_data['location'],
            $lines
        );

        /*
         * Запрошення клієнта.
         *
         * Автоматично запрошуємо тільки для нової картки.
         * Для існуючої картки не змінюємо власників без окремої дії.
         */
        if ($final_status === 'created') {
            self::invite_client(
                $case,
                $location_data['account'],
                $location_data['location'],
                $lines
            );
        }

        self::set_status(
            $case->id,
            $final_status,
            '',
            array(
                'published_at' => current_time('mysql')
            )
        );

        if ($final_status === 'created') {
            $lines[] = 'Для появи картки в Google Maps може знадобитися верифікація Google.';
            $lines[] = 'Створення картки не означає автоматичного проходження верифікації.';
        }

        return array(
            'ok' => true,
            'lines' => $lines
        );
    }

    /**
     * Завантаження фото до Google Business Profile.
     * Відео залишаються у медіафайлах WordPress.
     */
    private static function push_photos($case, $account, $location, &$lines) {
        global $wpdb;

        $pushed = array_map(
            'intval',
            (array)json_decode((string)$case->media_pushed, true)
        );

        $ok = 0;
        $videos = 0;

        foreach (GCM_Core::media_items($case->media) as $media) {
            if (in_array($media['id'], $pushed, true)) {
                continue;
            }

            if ($media['video']) {
                $videos++;
                continue;
            }

            $result = GCM_Google::add_photo(
                $account,
                $location,
                $media['url'],
                $case->photo_category ?: 'ADDITIONAL'
            );

            if (is_wp_error($result)) {
                $lines[] = 'Фото не додано: ' .
                    $result->get_error_message() .
                    ' Google повинен мати змогу відкрити файл за публічним посиланням.';

                break;
            }

            $pushed[] = $media['id'];
            $ok++;
        }

        if ($ok) {
            $lines[] = 'Додано фото: ' . $ok . '.';
        }

        if ($videos) {
            $lines[] = 'Відео (' . $videos . ') збережено в роботі. Додавання відео до картки виконується вручну в Business Profile.';
        }

        $wpdb->update(
            GCM_Core::t('orders'),
            array(
                'media_pushed' => wp_json_encode(
                    array_values(array_unique($pushed))
                )
            ),
            array('id' => $case->id)
        );
    }

    /* ---------- Попередній перегляд ---------- */

    private static function norm($string) {
        return mb_strtolower(
            preg_replace(
                '/[^\p{L}\p{N}]+/u',
                '',
                (string)$string
            )
        );
    }

    private static function row(
        $label,
        $before,
        $after,
        $always_changed = false,
        $html_before = false,
        $html_after = false
    ) {
        if ($after === '' || $after === null) {
            return '';
        }

        $changed = $always_changed ||
            self::norm(wp_strip_all_tags((string)$before)) !==
            self::norm(wp_strip_all_tags((string)$after));

        $before_html = ($before === '' || $before === null)
            ? '<span class="gcm-muted">—</span>'
            : ($html_before ? $before : esc_html($before));

        $after_html = $html_after
            ? $after
            : esc_html($after);

        return '<tr class="' . ($changed ? 'gcm-changed' : 'gcm-same') . '">' .
            '<th>' . esc_html($label) . '</th>' .
            '<td>' . $before_html . '</td>' .
            '<td>' . $after_html . '</td>' .
            '<td>' .
            ($changed
                ? '<span class="gcm-badge gcm-badge-warn">змінюється</span>'
                : '<span class="gcm-badge">без змін</span>') .
            '</td></tr>';
    }

    public static function preview_html($case) {
        $media = GCM_Core::media_items($case->media);

        $photos = 0;
        $videos = 0;

        foreach ($media as $item) {
            if ($item['video']) {
                $videos++;
            } else {
                $photos++;
            }
        }

        $hours = self::hours($case);

        $after_hours = $hours
            ? implode(
                '<br>',
                array_map('esc_html', GCM_Core::hours_lines($hours))
            )
            : '';

        ob_start();

        if ($case->request_type === 'reviews') {
            echo '<p>Послуга «Збір відгуків» нічого не змінює на картці. Нижче на сторінці роботи — посилання й готові тексти для клієнта.</p>';
            return ob_get_clean();
        }

        if ($case->request_type === 'edit') {
            $details = $case->place_id
                ? GCM_Google::place_details($case->place_id)
                : null;

            if (is_wp_error($details)) {
                echo '<p class="gcm-alert gcm-alert-err">' .
                    esc_html($details->get_error_message()) .
                    '</p>';

                $details = null;
            }

            $details = is_array($details)
                ? $details
                : array();

            $before_address = $details['formattedAddress'] ?? '';

            $after_address = trim(
                $case->biz_street .
                ($case->biz_city !== '' ? ', ' . $case->biz_city : '') .
                ($case->biz_postal !== '' ? ', ' . $case->biz_postal : ''),
                ' ,'
            );

            $before_hours = !empty($details['regularOpeningHours']['weekdayDescriptions'])
                ? implode(
                    '<br>',
                    array_map(
                        'esc_html',
                        $details['regularOpeningHours']['weekdayDescriptions']
                    )
                )
                : '';

            $rows = '';

            $rows .= self::row(
                'Назва',
                $details['displayName']['text'] ?? '',
                $case->biz_name
            );

            $rows .= self::row(
                'Категорія',
                $details['primaryTypeDisplayName']['text'] ?? '',
                $case->biz_category,
                true
            );

            $rows .= self::row(
                'Адреса',
                $before_address,
                $after_address,
                true
            );

            $rows .= self::row(
                'Телефон',
                $details['nationalPhoneNumber'] ??
                    ($details['internationalPhoneNumber'] ?? ''),
                $case->biz_phone
            );

            $rows .= self::row(
                'Сайт',
                preg_replace(
                    '~^https?://|/$~i',
                    '',
                    (string)($details['websiteUri'] ?? '')
                ),
                preg_replace(
                    '~^https?://|/$~i',
                    '',
                    $case->biz_website
                )
            );

            $rows .= self::row(
                'Години роботи',
                $before_hours,
                $after_hours,
                true,
                true,
                true
            );

            $rows .= self::row(
                'Опис',
                '',
                (string)$case->biz_description,
                true
            );

            echo '<p class="gcm-muted">Зліва — те, що клієнти бачать на Google зараз, справа — що буде після публікації.</p>';

            if ($rows) {
                echo '<div class="gcm-scroll"><table class="gcm-table gcm-diff"><thead><tr><th></th><th>Зараз на Google</th><th>Стане</th><th></th></tr></thead><tbody>' .
                    $rows .
                    '</tbody></table></div>';
            } else {
                echo '<p class="gcm-alert">Текстових змін немає — буде оновлено лише медіа.</p>';
            }

            if (
                $case->biz_street !== '' ||
                $case->biz_name !== ''
            ) {
                echo '<p class="gcm-alert">Зміна назви чи адреси може відправити картку на додаткову перевірку Google.</p>';
            }

        } else {
            $missing = self::missing_for_create($case);

            echo '<div class="gcm-card-mock">';
            echo '<h3>' . esc_html($case->biz_name ?: 'Назва бізнесу') . '</h3>';
            echo '<p class="gcm-muted">' . esc_html($case->biz_category ?: 'Категорія') . '</p>';

            echo '<p>' . esc_html(
                trim(
                    $case->biz_street . ', ' .
                    $case->biz_city . ' ' .
                    $case->biz_postal,
                    ' ,'
                ) ?: 'Адреса'
            ) . '</p>';

            if ($case->biz_phone) {
                echo '<p>' . esc_html($case->biz_phone) . '</p>';
            }

            if ($case->biz_website) {
                echo '<p>' . esc_html($case->biz_website) . '</p>';
            }

            if ($after_hours) {
                echo '<p>' . $after_hours . '</p>';
            }

            if (trim((string)$case->biz_description) !== '') {
                echo '<p>' . esc_html($case->biz_description) . '</p>';
            }

            echo '</div>';

            if ($missing) {
                echo '<p class="gcm-alert gcm-alert-err">Для публікації ще бракує: ' .
                    esc_html(implode(', ', $missing)) .
                    '.</p>';
            } else {
                echo '<p class="gcm-alert gcm-alert-ok">Усі обов’язкові поля заповнені.</p>';
            }
        }

        echo '<p><strong>Медіа:</strong> фото — ' .
            (int)$photos .
            ', відео — ' .
            (int)$videos .
            '.' .
            ($videos ? ' Відео потрібно додати вручну в Business Profile.' : '') .
            '</p>';

        if ($media) {
            echo '<div class="gcm-thumbs">';

            foreach ($media as $item) {
                if ($item['video']) {
                    echo '<video src="' . esc_url($item['url']) . '" muted playsinline preload="metadata"></video>';
                } else {
                    echo '<img src="' . esc_url($item['url']) . '" alt="" loading="lazy">';
                }
            }

            echo '</div>';
        }

        return ob_get_clean();
    }

    /* ---------- Доступ до картки клієнта ---------- */

    public static function ensure_token($case) {
        global $wpdb;

        if (!empty($case->access_token)) {
            return $case->access_token;
        }

        $token = wp_generate_password(32, false, false);

        $wpdb->update(
            GCM_Core::t('orders'),
            array('access_token' => $token),
            array('id' => $case->id)
        );

        return $token;
    }

    public static function access_link($case) {
        return add_query_arg(
            't',
            self::ensure_token($case),
            home_url('/gmaps-access/')
        );
    }

    /**
     * Перевіряє, чи центральний акаунт має доступ до картки.
     */
    public static function check_access($case) {
        global $wpdb;

        if ($case->place_id === '') {
            return new WP_Error(
                'gcm_no_place',
                'У роботі не вказано картку Google.'
            );
        }

        $location = GCM_Google::find_location($case->place_id);

        if (is_wp_error($location)) {
            return $location;
        }

        $wpdb->update(
            GCM_Core::t('orders'),
            array(
                'gbp_account' => $location['account'],
                'gbp_location' => $location['location'],
                'access_state' => 'ok',
                'status' => ($case->status === 'needs_access')
                    ? 'draft'
                    : $case->status,
                'gbp_error' => ''
            ),
            array('id' => $case->id)
        );

        return $location;
    }

    /**
     * Приймає лише запрошення, назва картки в яких схожа
     * на назву бізнесу цієї роботи.
     */
    public static function auto_accept($case) {
        $invitations = GCM_Google::pending_invitations();

        if (is_wp_error($invitations) || !$invitations) {
            return 0;
        }

        $wanted = self::norm($case->biz_name);

        if ($wanted === '') {
            return 0;
        }

        $accepted = 0;

        foreach ($invitations as $invitation) {
            $title = self::norm(
                GCM_Google::invitation_title($invitation)
            );

            if ($title === '') {
                continue;
            }

            similar_text($wanted, $title, $percent);

            if (
                $percent >= 80 &&
                !is_wp_error(
                    GCM_Google::accept_invitation($invitation['name'])
                )
            ) {
                $accepted++;
            }
        }

        return $accepted;
    }

    /**
     * Надсилає клієнту інструкцію з надання доступу.
     */
    public static function send_access_email($case, $client) {
        if (!$client || !is_email($client->email)) {
            return false;
        }

        $central = GCM_Core::setting('central_email', '');

        $body = "Вітаємо!\n\n";
        $body .= "Щоб ми могли оновити вашу картку «" .
            $case->biz_name .
            "» на Google Maps, додайте наш Google-акаунт до керування карткою.\n\n";

        $body .= "Відкрийте інструкцію: " .
            self::access_link($case) .
            "\n\n";

        if ($central) {
            $body .= "Google-пошта для додавання: " .
                $central .
                "\n\n";
        }

        $body .= "Ми не просимо ваш пароль Google.\n";

        return wp_mail(
            $client->email,
            'Додайте менеджера до вашої картки Google Maps',
            $body
        );
    }

    /* ---------- Послуга «Збір відгуків» ---------- */

    public static function review_link($place_id) {
        return $place_id
            ? 'https://search.google.com/local/writereview?placeid=' .
                rawurlencode($place_id)
            : '';
    }
}
