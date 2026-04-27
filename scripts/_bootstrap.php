<?php
/**
 * Shared DB bootstrap for CLI utility scripts.
 *
 * Loads live MySQL credentials from `admin/admin-secrets.local.php`
 * (gitignored, never commit) with `admin/admin-secrets.example.php`
 * as fallback. All other connection details and any API access tokens
 * MUST be read from the `site_settings` table.
 *
 * Usage:
 *   require_once __DIR__ . '/_bootstrap.php';
 *   $pdo = motorlink_script_pdo();
 */

if (!function_exists('motorlink_script_pdo')) {
    function motorlink_script_pdo(): PDO
    {
        $candidates = [
            __DIR__ . '/../admin/admin-secrets.local.php',
            __DIR__ . '/../admin/admin-secrets.example.php',
        ];

        $secrets = null;
        foreach ($candidates as $path) {
            if (is_file($path)) {
                $secrets = require $path;
                break;
            }
        }

        if (!is_array($secrets) || empty($secrets['MOTORLINK_DB_HOST'])) {
            fwrite(STDERR, "Missing admin/admin-secrets.local.php — cannot bootstrap DB.\n");
            exit(1);
        }

        $host = (string)$secrets['MOTORLINK_DB_HOST'];
        $name = (string)$secrets['MOTORLINK_DB_NAME'];
        $user = (string)$secrets['MOTORLINK_DB_USER'];
        $pass = (string)$secrets['MOTORLINK_DB_PASS'];

        return new PDO(
            "mysql:host={$host};dbname={$name};charset=utf8mb4",
            $user,
            $pass,
            [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ]
        );
    }
}
