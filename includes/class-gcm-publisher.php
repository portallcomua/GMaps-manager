    /**
     * Визначає акаунт, у якому створювати нову картку.
     *
     * Якщо location_group_id задано:
     * - нормалізує ID до accounts/{ID};
     * - перевіряє, що акаунт доступний центральному Google-акаунту;
     * - перевіряє тип LOCATION_GROUP;
     * - не переходить непомітно до іншого акаунта при помилці.
     *
     * Якщо групу не задано — використовує звичайний default_account().
     */
    private static function creation_account() {
        $group = trim(
            (string)GCM_Core::setting('location_group_id', '')
        );

        if ($group === '') {
            return GCM_Google::default_account();
        }

        if (preg_match('/^[0-9]+$/', $group)) {
            $group = 'accounts/' . $group;
        }

        if (!preg_match('~^accounts/[0-9]+$~', $group)) {
            return new WP_Error(
                'gcm_location_group_format',
                'Некоректний Location Group ID. Вкажіть числовий ID або accounts/{ID}.'
            );
        }

        $accounts = GCM_Google::accounts();

        if (is_wp_error($accounts)) {
            return $accounts;
        }

        foreach ((array)$accounts as $account) {
            if (($account['name'] ?? '') !== $group) {
                continue;
            }

            if (($account['type'] ?? '') !== 'LOCATION_GROUP') {
                return new WP_Error(
                    'gcm_location_group_type',
                    'Вказаний акаунт існує, але не є групою локацій (LOCATION_GROUP).'
                );
            }

            return $account['name'];
        }

        return new WP_Error(
            'gcm_location_group_not_found',
            'Групу локацій не знайдено серед акаунтів, доступних центральному Google-акаунту. Перевірте ID та права доступу.'
        );
    }
