<?php
declare(strict_types=1);

if (!function_exists('admin_base_path')) {
    function admin_base_path(): string {
        $script = (string)($_SERVER['SCRIPT_NAME'] ?? '/admin/index.php');
        $script = str_replace('\\', '/', $script);
        $dir = str_replace('\\', '/', dirname($script));
        if ($dir === '/' || $dir === '.' || $dir === '\\') {
            return '/admin';
        }
        return rtrim($dir, '/');
    }
}

if (!function_exists('site_root_path')) {
    function site_root_path(): string {
        $base = admin_base_path();
        $root = preg_replace('#/admin$#', '', $base) ?? '';
        return rtrim($root, '/');
    }
}

if (!function_exists('admin_url')) {
    function admin_url(string $path = ''): string {
        $path = ltrim($path, '/');
        $base = rtrim(admin_base_path(), '/');
        return $base . ($path !== '' ? '/' . $path : '');
    }
}

if (!function_exists('site_url')) {
    function site_url(string $path = ''): string {
        $path = ltrim($path, '/');
        $root = site_root_path();
        if ($root === '') {
            return '/' . $path;
        }
        return $root . ($path !== '' ? '/' . $path : '');
    }
}
