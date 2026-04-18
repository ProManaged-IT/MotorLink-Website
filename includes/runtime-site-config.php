<?php

if (function_exists('motorlink_get_site_runtime_config')) {
    return;
}

function motorlink_normalize_site_url($url, $fallback = '') {
    $candidate = trim((string)$url);
    if ($candidate === '') {
        $candidate = trim((string)$fallback);
    }

    if ($candidate === '') {
        return '';
    }

    if (!preg_match('#^https?://#i', $candidate)) {
        $candidate = 'https://' . ltrim($candidate, '/');
    }

    return rtrim($candidate, '/');
}

function motorlink_get_runtime_origin_fallback($runtimeBaseUrl = '') {
    $base = trim((string)$runtimeBaseUrl);
    if ($base !== '') {
        return rtrim($base, '/');
    }

    $host = $_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? '';
    if ($host === '') {
        return 'http://127.0.0.1:8000';
    }

    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || ((int)($_SERVER['SERVER_PORT'] ?? 0) === 443)
        || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https');

    return ($https ? 'https' : 'http') . '://' . $host;
}

function motorlink_get_default_site_runtime_config($runtimeBaseUrl = '') {
    $siteUrl = motorlink_normalize_site_url($runtimeBaseUrl, 'http://127.0.0.1:8000');

    return [
        'site_name' => 'MotorLink',
        'site_short_name' => 'MotorLink',
        'site_tagline' => 'Your trusted vehicle marketplace',
        'site_description' => 'MotorLink helps people buy, sell, hire, and manage vehicles in one place.',
        'site_url' => $siteUrl,
        'country_name' => '',
        'country_code' => '',
        'country_demonym' => '',
        'locale' => 'en',
        'currency_code' => 'LOCAL',
        'currency_symbol' => 'LOCAL',
        'market_scope_label' => 'nationwide',
        'fuel_price_country_slug' => '',
        'geo_region' => '',
        'geo_placename' => '',
        'geo_position' => '',
        'icbm' => '',
        'contact_email' => 'info@example.com',
        'contact_support_email' => 'support@example.com',
        'admin_contact_email' => 'admin@example.com',
        'smtp_from_name' => 'MotorLink'
    ];
}

function motorlink_get_site_runtime_config_keys($includePrivate = false) {
    $keys = [
        'site_name',
        'site_short_name',
        'site_tagline',
        'site_description',
        'site_url',
        'country_name',
        'country_code',
        'country_demonym',
        'locale',
        'currency_code',
        'currency_symbol',
        'market_scope_label',
        'fuel_price_country_slug',
        'geo_region',
        'geo_placename',
        'geo_position',
        'icbm',
        'contact_email',
        'contact_support_email',
        'smtp_from_name'
    ];

    if ($includePrivate) {
        $keys[] = 'admin_contact_email';
    }

    return $keys;
}

function motorlink_get_site_runtime_config(PDO $db, array $options = []) {
    static $cache = [];

    $includePrivate = !empty($options['include_private']);
    $runtimeBaseUrl = trim((string)($options['runtime_base_url'] ?? ''));
    $cacheKey = ($includePrivate ? '1' : '0') . '|' . $runtimeBaseUrl;

    if (isset($cache[$cacheKey])) {
        return $cache[$cacheKey];
    }

    $defaults = motorlink_get_default_site_runtime_config($runtimeBaseUrl ?: motorlink_get_runtime_origin_fallback($runtimeBaseUrl));
    $settings = $defaults;

    $siteSettingKeys = motorlink_get_site_runtime_config_keys($includePrivate);
    $siteSettingPlaceholders = implode(',', array_fill(0, count($siteSettingKeys), '?'));

    try {
        $stmt = $db->prepare("SELECT setting_key, setting_value FROM site_settings WHERE setting_key IN ($siteSettingPlaceholders)");
        $stmt->execute($siteSettingKeys);

        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $key = (string)($row['setting_key'] ?? '');
            $value = trim((string)($row['setting_value'] ?? ''));
            if ($key !== '' && array_key_exists($key, $settings) && $value !== '') {
                $settings[$key] = $value;
            }
        }
    } catch (Exception $e) {
        error_log('runtime-site-config site_settings load warning: ' . $e->getMessage());
    }

    $settingsTableMap = [
        'general_siteName' => 'site_name',
        'general_siteShortName' => 'site_short_name',
        'general_siteTagline' => 'site_tagline',
        'general_siteDescription' => 'site_description',
        'general_siteUrl' => 'site_url',
        'general_countryName' => 'country_name',
        'general_countryCode' => 'country_code',
        'general_countryDemonym' => 'country_demonym',
        'general_locale' => 'locale',
        'general_currencyCode' => 'currency_code',
        'general_currencySymbol' => 'currency_symbol',
        'general_marketScopeLabel' => 'market_scope_label',
        'general_supportEmail' => 'contact_support_email',
        'general_contactEmail' => 'contact_email',
        'general_adminEmail' => 'admin_contact_email',
        'email_emailFromName' => 'smtp_from_name'
    ];

    try {
        $legacyKeys = array_keys($settingsTableMap);
        $legacyPlaceholders = implode(',', array_fill(0, count($legacyKeys), '?'));
        $stmt = $db->prepare("SELECT setting_key, setting_value FROM settings WHERE setting_key IN ($legacyPlaceholders)");
        $stmt->execute($legacyKeys);

        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $sourceKey = (string)($row['setting_key'] ?? '');
            $targetKey = $settingsTableMap[$sourceKey] ?? null;
            $value = trim((string)($row['setting_value'] ?? ''));
            if ($targetKey && array_key_exists($targetKey, $settings) && $value !== '') {
                $settings[$targetKey] = $value;
            }
        }
    } catch (Exception $e) {
        error_log('runtime-site-config settings fallback warning: ' . $e->getMessage());
    }

    $settings['site_url'] = motorlink_normalize_site_url(
        $settings['site_url'] ?? '',
        $runtimeBaseUrl ?: $defaults['site_url']
    );

    if (($settings['site_short_name'] ?? '') === '') {
        $settings['site_short_name'] = $settings['site_name'];
    }

    if (($settings['contact_email'] ?? '') === '') {
        $settings['contact_email'] = $settings['contact_support_email'];
    }

    if (($settings['contact_support_email'] ?? '') === '') {
        $settings['contact_support_email'] = $settings['contact_email'];
    }

    if (($settings['smtp_from_name'] ?? '') === '') {
        $settings['smtp_from_name'] = $settings['site_name'];
    }

    $settings['site_host'] = '';
    if (!empty($settings['site_url'])) {
        $parsedHost = parse_url($settings['site_url'], PHP_URL_HOST);
        if (is_string($parsedHost) && $parsedHost !== '') {
            $settings['site_host'] = $parsedHost;
        }
    }

    $locale = trim((string)($settings['locale'] ?? 'en'));
    $settings['locale'] = $locale !== '' ? $locale : 'en';
    $settings['locale_underscore'] = str_replace('-', '_', $settings['locale']);

    $countryName = trim((string)($settings['country_name'] ?? ''));
    $settings['country_possessive'] = $countryName === ''
        ? ''
        : (preg_match('/s$/i', $countryName) ? $countryName . "'" : $countryName . "'s");

    $settings['currency_label'] = trim((string)($settings['currency_symbol'] ?? '')) !== ''
        ? trim((string)$settings['currency_symbol'])
        : trim((string)($settings['currency_code'] ?? ''));

    $cache[$cacheKey] = $settings;
    return $settings;
}

function motorlink_get_public_site_runtime_config(PDO $db, array $options = []) {
    $config = motorlink_get_site_runtime_config($db, $options);
    unset($config['admin_contact_email']);
    return $config;
}

function motorlink_upsert_site_settings(PDO $db, array $definitions) {
    if (empty($definitions)) {
        return;
    }

    $stmt = $db->prepare("INSERT INTO site_settings (setting_key, setting_value, setting_group, setting_type, description, is_public)
                          VALUES (?, ?, ?, ?, ?, ?)
                          ON DUPLICATE KEY UPDATE
                          setting_value = VALUES(setting_value),
                          setting_group = VALUES(setting_group),
                          setting_type = VALUES(setting_type),
                          description = VALUES(description),
                          is_public = VALUES(is_public)");

    foreach ($definitions as $key => $meta) {
        $stmt->execute([
            $key,
            (string)($meta['value'] ?? ''),
            (string)($meta['group'] ?? 'general'),
            (string)($meta['type'] ?? 'string'),
            (string)($meta['description'] ?? ''),
            !empty($meta['is_public']) ? 1 : 0
        ]);
    }
}