<?php
declare(strict_types=1);
$config = require __DIR__ . '/../config.php';
date_default_timezone_set($config['timezone'] ?? 'UTC');

// MaterCMS keeps mbstring optional. UTF-8 aware functions are used when the
// extension exists; these fallbacks prevent a fatal error on minimal hosting.
if (!function_exists('mb_strtolower')) { function mb_strtolower(string $value, ?string $encoding=null): string { return strtolower($value); } }
if (!function_exists('mb_strlen')) { function mb_strlen(string $value, ?string $encoding=null): int { return strlen($value); } }
if (!function_exists('mb_substr')) { function mb_substr(string $value, int $start, ?int $length=null, ?string $encoding=null): string { return $length === null ? substr($value,$start) : substr($value,$start,$length); } }
if (!function_exists('mb_stripos')) { function mb_stripos(string $haystack, string $needle, int $offset=0, ?string $encoding=null): int|false { return stripos($haystack,$needle,$offset); } }

// Public API requests do not need a PHP session. Avoiding session_start() there
// removes file-session locks and lets concurrent frontend requests run freely.
if (!defined('FEATHER_NO_SESSION') || FEATHER_NO_SESSION !== true) {
    if (session_status() !== PHP_SESSION_ACTIVE) {
        $secure = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
        session_set_cookie_params([
            'httponly'=>true,
            'samesite'=>'Lax',
            'secure'=>$secure,
            'path'=>'/',
        ]);
        session_start();
    }
}

require_once __DIR__.'/Database.php';
require_once __DIR__.'/functions.php';
require_once __DIR__.'/Auth.php';
require_once __DIR__.'/ProjectAccess.php';
