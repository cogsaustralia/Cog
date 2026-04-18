<?php
declare(strict_types=1);

require_once __DIR__ . '/admin_paths.php';

function ops_now(): string { return date('Y-m-d H:i:s'); }
function ops_h($v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

/**
 * Walk up the directory tree from admin/includes/ looking for a .env file.
 * The .env lives one level above the web root, so from admin/includes/ we
 * walk: admin/includes/ → admin/ → ROOT/ → PARENT_OF_ROOT (where .env lives).
 * We search up to 8 levels so this works regardless of hosting depth.
 */
function ops_load_env_file(): void {
    static $loaded = false;
    if ($loaded) {
        return;
    }
    $loaded = true;

    $dir = __DIR__;
    for ($i = 0; $i < 8; $i++) {
        $candidate = $dir . DIRECTORY_SEPARATOR . '.env';
        if (is_file($candidate) && is_readable($candidate)) {
            $lines = file($candidate, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
            foreach ($lines as $line) {
                $line = trim($line);
                if ($line === '' || $line[0] === '#' || strpos($line, '=') === false) {
                    continue;
                }
                [$k, $v] = explode('=', $line, 2);
                $k = trim($k);
                $v = trim(trim($v), '"\'');
                if ($k === '') {
                    continue;
                }
                // Only set if not already defined (environment wins over .env file).
                if (getenv($k) === false && !isset($_ENV[$k])) {
                    $_ENV[$k] = $v;
                    putenv($k . '=' . $v);
                }
            }
            return;
        }
        $parent = dirname($dir);
        if ($parent === $dir) {
            break; // reached filesystem root, stop
        }
        $dir = $parent;
    }
}

function ops_env(string $key, string $default = ''): string {
    ops_load_env_file();
    $val = $_ENV[$key] ?? getenv($key);
    if ($val === false || $val === null || $val === '') {
        return $default;
    }
    return (string)$val;
}

function ops_config(): array {
    static $config = null;
    if (is_array($config)) {
        return $config;
    }

    ops_load_env_file();

    // Support both canonical names and legacy aliases used in older .env files.
    $dbName     = ops_env('DB_DATABASE', ops_env('DB_NAME'));
    $dbUser     = ops_env('DB_USERNAME', ops_env('DB_USER'));
    $dbPass     = ops_env('DB_PASSWORD', ops_env('DB_PASS'));
    $dbHost     = ops_env('DB_HOST', 'localhost');
    $dbPort     = (int)(ops_env('DB_PORT', '3306'));
    $dbCharset  = ops_env('DB_CHARSET', 'utf8mb4');
    $sessionName = ops_env('SESSION_COOKIE_NAME', ops_env('SESSION_NAME', 'cogs_admin_session'));
    $appEnv     = ops_env('APP_ENV', 'production');
    $appName    = ops_env('APP_NAME', 'COGS Admin');
    $scheme     = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host       = (string)($_SERVER['HTTP_HOST'] ?? 'localhost');
    $baseUrl    = ops_env('APP_URL', $scheme . '://' . $host);
    $sessionSecure = filter_var(
        ops_env('SESSION_COOKIE_SECURE', (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? '1' : '0'),
        FILTER_VALIDATE_BOOLEAN
    );

    $config = [
        'app' => [
            'name'           => $appName,
            'env'            => $appEnv,
            'base_url'       => $baseUrl,
            'session_name'   => $sessionName,
            'session_secret' => ops_env('SESSION_SECRET', 'change-this-session-secret'),
        ],
        'db' => [
            'host'     => $dbHost,
            'port'     => $dbPort,
            'database' => $dbName,
            'username' => $dbUser,
            'password' => $dbPass,
            'charset'  => $dbCharset,
        ],
        // Flat aliases kept for any legacy callers.
        'db_host'    => $dbHost,
        'db_port'    => $dbPort,
        'db_name'    => $dbName,
        'db_user'    => $dbUser,
        'db_pass'    => $dbPass,
        'db_charset' => $dbCharset,
        'session_name'           => $sessionName,
        'session_cookie_secure'  => $sessionSecure,
        'seed_admin_reset_password' => ops_env('SEED_ADMIN_RESET_PASSWORD', 'change-this-admin-bootstrap-password'),
        'auth' => [
            'admin_email'      => ops_env('ADMIN_EMAIL', 'admin@cogsaustralia.org'),
            'bootstrap_token'  => ops_env('ADMIN_BOOTSTRAP_TOKEN', ''),
            'totp_issuer'      => ops_env('ADMIN_TOTP_ISSUER', 'COGS Admin'),
        ],
        'timezone' => ops_env('APP_TIMEZONE', 'Australia/Sydney'),
    ];

    return $config;
}

function ops_db_connect(array $config): PDO {

    $db = $config['db'] ?? [
        'host' => $config['db_host'] ?? 'localhost',
        'port' => $config['db_port'] ?? 3306,
        'database' => $config['db_name'] ?? '',
        'username' => $config['db_user'] ?? '',
        'password' => $config['db_pass'] ?? '',
        'charset' => $config['db_charset'] ?? 'utf8mb4',
    ];
    if ((string)($db['database'] ?? '') === '') {
        throw new RuntimeException(
            'DB_DATABASE is not set. Ensure a .env file exists one directory above ' .
            'the web root and contains DB_DATABASE, DB_USERNAME, and DB_PASSWORD. ' .
            'See .env.example for the full template.'
        );
    }
    $dsn = sprintf(
        'mysql:host=%s;port=%s;dbname=%s;charset=%s',
        (string)($db['host'] ?? 'localhost'),
        (string)($db['port'] ?? 3306),
        (string)($db['database'] ?? ''),
        (string)($db['charset'] ?? 'utf8mb4')
    );
    return new PDO($dsn, (string)($db['username'] ?? ''), (string)($db['password'] ?? ''), [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
        PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci",
    ]);
}

function ops_db(): PDO {
    static $pdo = null;
    if ($pdo instanceof PDO) {
        // Ping to detect a lost connection (MariaDB wait_timeout on shared hosting).
        // If the server has gone away, reconnect transparently.
        try {
            $pdo->query('SELECT 1');
        } catch (Throwable $e) {
            $pdo = null;
        }
    }
    if (!($pdo instanceof PDO)) {
        $pdo = ops_db_connect(ops_config());
    }
    return $pdo;
}

function ops_admin_session_cookie_name(): string {
    static $name = null;
    if ($name !== null) return $name;
    $cfg = ops_config();
    $name = (string)($cfg['session_name'] ?? ($cfg['app']['session_name'] ?? 'cogs_admin_session'));
    if ($name === '') $name = 'cogs_admin_session';
    return $name;
}

function ops_start_admin_php_session(): void {
    if (session_status() === PHP_SESSION_ACTIVE) return;
    $cfg = ops_config();
    $sessionName = (string)($cfg['session_name'] ?? ($cfg['app']['session_name'] ?? 'cogs_admin_session'));
    if ($sessionName !== '') session_name($sessionName);
    session_start();
}

function ops_table_exists(PDO $pdo, string $table): bool {
    static $cache = [];
    $key = spl_object_hash($pdo) . ':' . $table;
    if (array_key_exists($key, $cache)) return $cache[$key];
    try {
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?');
        $stmt->execute([$table]);
        return $cache[$key] = ((int)$stmt->fetchColumn() > 0);
    } catch (Throwable $e) {
        return $cache[$key] = false;
    }
}
function ops_has_table(PDO $pdo, string $table): bool { return ops_table_exists($pdo, $table); }

function ops_columns_for(PDO $pdo, string $table): array {
    static $cache = [];
    $key = spl_object_hash($pdo) . ':' . $table;
    if (isset($cache[$key])) return $cache[$key];
    $cols = [];
    if (!ops_table_exists($pdo, $table)) return $cache[$key] = $cols;
    $stmt = $pdo->query("SHOW COLUMNS FROM `$table`");
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) $cols[$row['Field']] = $row;
    return $cache[$key] = $cols;
}
function ops_has_column(PDO $pdo, string $table, string $column): bool { return isset(ops_columns_for($pdo, $table)[$column]); }
function ops_has_col(PDO $pdo, string $table, string $column): bool { return ops_has_column($pdo, $table, $column); }

/**
 * Alias kept for backwards compatibility.
 * session-check.php and any older callers use this name.
 */
function ops_sync_admin_session_from_api(PDO $pdo): ?array {
    return ops_resolve_api_admin_session($pdo);
}

function ops_resolve_api_admin_session(PDO $pdo): ?array {
    $cookieName = ops_admin_session_cookie_name();
    $sessionId = (string)($_COOKIE[$cookieName] ?? '');
    if ($sessionId === '') return null;
    if (!ops_table_exists($pdo, 'sessions') || !ops_table_exists($pdo, 'admin_users')) return null;

    try {
        $stmt = $pdo->prepare("
            SELECT s.id, s.user_type, s.principal_id, s.subject_ref, s.expires_at,
                   au.username, au.email, au.display_name, au.role_name, au.two_factor_enabled, au.is_active
            FROM sessions s
            INNER JOIN admin_users au ON au.id = s.principal_id
            WHERE s.id = ?
              AND s.user_type = 'admin'
              AND s.expires_at > UTC_TIMESTAMP()
            LIMIT 1
        ");
        $stmt->execute([$sessionId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row || (int)($row['is_active'] ?? 0) !== 1) return null;
        return $row;
    } catch (Throwable $e) {
        return null;
    }
}

function ops_require_admin(?PDO $pdo = null, ?string $requiredPermission = null): void {
    ops_start_admin_php_session();
    $pdo = $pdo instanceof PDO ? $pdo : ops_db();

    if (!empty($_SESSION['admin_user']) && is_array($_SESSION['admin_user']) && !empty($_SESSION['admin_id'])) {
        if ($requiredPermission !== null && $requiredPermission !== '' && !ops_admin_can($pdo, $requiredPermission)) {
            http_response_code(403);
            echo '<p style="font-family:sans-serif;padding:40px;color:#c00">You do not have permission to access this admin page.</p>';
            exit;
        }
        return;
    }

    $apiAdmin = ops_resolve_api_admin_session($pdo);
    if ($apiAdmin) {
        $_SESSION['admin_user'] = $apiAdmin;
        $_SESSION['admin_id'] = (int)$apiAdmin['principal_id'];
        $_SESSION['admin_name'] = (string)($apiAdmin['display_name'] ?? 'Admin');
        $_SESSION['admin_email'] = (string)($apiAdmin['email'] ?? '');
        $_SESSION['admin_display_name'] = (string)($apiAdmin['display_name'] ?? 'Admin');
        $_SESSION['admin_role_name'] = (string)($apiAdmin['role_name'] ?? '');
        if ($requiredPermission !== null && $requiredPermission !== '' && !ops_admin_can($pdo, $requiredPermission)) {
            http_response_code(403);
            echo '<p style="font-family:sans-serif;padding:40px;color:#c00">You do not have permission to access this admin page.</p>';
            exit;
        }
        return;
    }

    header('Location: ' . admin_url('index.php'));
    if (session_status() === PHP_SESSION_ACTIVE) {
        $_SESSION = [];
        session_destroy();
    }
    exit;
}


function ops_current_admin_user_id(PDO $pdo): ?int {
    ops_start_admin_php_session();
    $sessionUserId = $_SESSION['admin_user']['principal_id'] ?? $_SESSION['admin_user']['id'] ?? null;
    if ($sessionUserId !== null && (int)$sessionUserId > 0) {
        return (int)$sessionUserId;
    }
    $user = ops_current_admin_user($pdo);
    if (is_array($user) && !empty($user['id'])) {
        return (int)$user['id'];
    }
    return null;
}

function ops_current_legacy_admin_id(PDO $pdo): ?int {
    ops_start_admin_php_session();
    if (!ops_has_table($pdo, 'admins')) {
        return null;
    }

    if (!empty($_SESSION['legacy_admin_id'])) {
        return (int)$_SESSION['legacy_admin_id'];
    }

    $email = (string)($_SESSION['admin_email'] ?? $_SESSION['admin_user']['email'] ?? $_SESSION['admin']['email'] ?? '');
    if ($email === '') {
        $user = ops_current_admin_user($pdo);
        $email = (string)($user['email'] ?? '');
    }
    if ($email === '') {
        return null;
    }

    try {
        $stmt = $pdo->prepare('SELECT id FROM admins WHERE email = ? LIMIT 1');
        $stmt->execute([$email]);
        $id = $stmt->fetchColumn();
        if ($id) {
            $_SESSION['legacy_admin_id'] = (int)$id;
            return (int)$id;
        }
    } catch (Throwable $e) {
        return null;
    }
    return null;
}

function ops_current_admin_roles(PDO $pdo): array {
    static $cache = [];
    $cacheKey = spl_object_hash($pdo) . ':' . (string)(ops_current_admin_user_id($pdo) ?? 0);
    if (isset($cache[$cacheKey])) {
        return $cache[$cacheKey];
    }

    $roles = [];
    $adminUserId = ops_current_admin_user_id($pdo);
    if ($adminUserId && ops_has_table($pdo, 'admin_user_roles') && ops_has_table($pdo, 'admin_roles')) {
        try {
            $stmt = $pdo->prepare("SELECT ar.role_key FROM admin_user_roles aur JOIN admin_roles ar ON ar.id = aur.role_id WHERE aur.admin_user_id = ? ORDER BY ar.id ASC");
            $stmt->execute([$adminUserId]);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $roleKey = trim((string)($row['role_key'] ?? ''));
                if ($roleKey !== '') {
                    $roles[] = $roleKey;
                }
            }
        } catch (Throwable $e) {
        }
    }

    if (!$roles) {
        $sessionRole = trim((string)($_SESSION['admin_role_name'] ?? $_SESSION['admin_user']['role_name'] ?? ''));
        if ($sessionRole !== '') {
            $roles[] = $sessionRole;
        }
        $user = ops_current_admin_user($pdo);
        $roleName = trim((string)($user['role_name'] ?? ''));
        if ($roleName !== '') {
            $roles[] = $roleName;
        }
    }

    $roles = array_values(array_unique(array_filter($roles)));
    return $cache[$cacheKey] = $roles;
}

function ops_admin_can(PDO $pdo, string $permissionKey): bool {
    $permissionKey = trim($permissionKey);
    if ($permissionKey === '') {
        return true;
    }

    $roles = ops_current_admin_roles($pdo);
    foreach ($roles as $role) {
        if (in_array($role, ['superadmin', 'super_admin'], true)) {
            return true;
        }
    }

    if (!ops_has_table($pdo, 'admin_user_roles') || !ops_has_table($pdo, 'admin_role_permissions')) {
        $user = ops_current_admin_user($pdo);
        $roleName = (string)($user['role_name'] ?? $_SESSION['admin_role_name'] ?? '');
        if (in_array($roleName, ['superadmin', 'super_admin'], true)) {
            return true;
        }
        return false;
    }

    $adminUserId = ops_current_admin_user_id($pdo);
    if (!$adminUserId) {
        return false;
    }

    try {
        $stmt = $pdo->prepare("SELECT 1 FROM admin_user_roles aur JOIN admin_role_permissions arp ON arp.role_id = aur.role_id WHERE aur.admin_user_id = ? AND arp.permission_key = ? LIMIT 1");
        $stmt->execute([$adminUserId, $permissionKey]);
        return (bool)$stmt->fetchColumn();
    } catch (Throwable $e) {
        return false;
    }
}

function ops_legacy_admin_bridge_enabled(PDO $pdo): bool {
    if (!ops_has_table($pdo, 'admin_settings')) {
        return false;
    }

    $legacyAuth = strtolower(trim(ops_setting_get($pdo, 'legacy_admin_auth_status', 'disabled')));
    $phaseBridge = strtolower(trim(ops_setting_get($pdo, 'phase1_admin_auth_bridge_status', 'disabled')));
    $executionBridge = strtolower(trim(ops_setting_get($pdo, 'execution_bridge_mode', 'disabled')));

    return !in_array($legacyAuth, ['disabled', 'off', '0', 'false'], true)
        || !in_array($phaseBridge, ['disabled', 'off', '0', 'false'], true)
        || !in_array($executionBridge, ['disabled', 'off', '0', 'false'], true);
}

function ops_bridge_dependency_counts(PDO $pdo): array {
    $out = [];
    try {
        $stmt = $pdo->query("SELECT table_name, column_name FROM information_schema.columns WHERE table_schema = DATABASE() AND column_name LIKE '%\_admin\_id' ESCAPE '\\' AND column_name NOT LIKE '%\_admin\_user\_id' ESCAPE '\\' ORDER BY table_name, column_name");
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        foreach ($rows as $row) {
            $table = (string)($row['table_name'] ?? '');
            $column = (string)($row['column_name'] ?? '');
            if ($table === '' || $column === '') {
                continue;
            }
            try {
                $sql = sprintf('SELECT COUNT(*) FROM `%s` WHERE `%s` IS NOT NULL AND `%s` <> 0', str_replace('`', '``', $table), str_replace('`', '``', $column), str_replace('`', '``', $column));
                $count = (int)$pdo->query($sql)->fetchColumn();
            } catch (Throwable $e) {
                $count = 0;
            }
            $out[] = ['table' => $table, 'column' => $column, 'count' => $count];
        }
    } catch (Throwable $e) {
        return [];
    }
    return $out;
}

function ops_admin_name(): string {
    ops_start_admin_php_session();
    return (string)($_SESSION['admin_display_name'] ?? $_SESSION['admin_name'] ?? $_SESSION['admin']['display_name'] ?? $_SESSION['admin_user']['display_name'] ?? 'Admin');
}

function ops_current_admin_id(PDO $pdo): ?int {
    ops_start_admin_php_session();
    if (!empty($_SESSION['admin_id'])) return (int)$_SESSION['admin_id'];
    $email = $_SESSION['admin_email'] ?? $_SESSION['admin_user']['email'] ?? $_SESSION['admin']['email'] ?? null;
    if (!$email) return null;
    foreach (['admins','admin_users'] as $table) {
        if (!ops_has_table($pdo, $table) || !ops_has_col($pdo, $table, 'email')) continue;
        try {
            $stmt = $pdo->prepare("SELECT id FROM `$table` WHERE email = ? LIMIT 1");
            $stmt->execute([$email]);
            $id = $stmt->fetchColumn();
            if ($id) return (int)$id;
        } catch (Throwable $e) {}
    }
    return null;
}



function ops_current_admin_user(PDO $pdo): ?array {
    ops_start_admin_php_session();
    if (!ops_has_table($pdo, 'admin_users')) return null;

    $adminId = (int)($_SESSION['admin_id'] ?? 0);
    $email = (string)($_SESSION['admin_email'] ?? $_SESSION['admin_user']['email'] ?? '');
    if ($adminId <= 0 && $email === '') return null;

    try {
        if ($adminId > 0) {
            $stmt = $pdo->prepare("SELECT id, username, email, display_name, role_name, two_factor_enabled, is_active, last_login_at, created_at, updated_at, password_hash FROM admin_users WHERE id = ? LIMIT 1");
            $stmt->execute([$adminId]);
        } else {
            $stmt = $pdo->prepare("SELECT id, username, email, display_name, role_name, two_factor_enabled, is_active, last_login_at, created_at, updated_at, password_hash FROM admin_users WHERE email = ? LIMIT 1");
            $stmt->execute([$email]);
        }
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    } catch (Throwable $e) {
        return null;
    }
}

function ops_record_admin_security_event(PDO $pdo, ?int $adminUserId, string $eventType, string $severity = 'info', ?array $details = null): void {
    if (!ops_has_table($pdo, 'admin_security_events')) return;
    $ip = (string)($_SERVER['REMOTE_ADDR'] ?? '');
    $ua = (string)($_SERVER['HTTP_USER_AGENT'] ?? '');
    try {
        $stmt = $pdo->prepare("INSERT INTO admin_security_events (admin_user_id, event_type, severity, ip_address, user_agent, details_json, created_at) VALUES (?, ?, ?, ?, ?, ?, NOW())");
        $stmt->execute([
            $adminUserId,
            $eventType,
            $severity,
            $ip !== '' ? $ip : null,
            $ua !== '' ? $ua : null,
            $details ? json_encode($details, JSON_UNESCAPED_SLASHES) : null,
        ]);
    } catch (Throwable $e) {
    }
}

function ops_auth_rate_limit_rows(PDO $pdo): array {
    if (!ops_has_table($pdo, 'auth_rate_limits')) return [];
    try {
        $stmt = $pdo->query("SELECT id, action, attempts, window_start, locked_until FROM auth_rate_limits WHERE action IN ('admin-login','login') ORDER BY COALESCE(locked_until, window_start) DESC, id DESC LIMIT 20");
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        return [];
    }
}

function ops_clear_auth_rate_limits(PDO $pdo): int {
    if (!ops_has_table($pdo, 'auth_rate_limits')) return 0;
    try {
        $stmt = $pdo->prepare("DELETE FROM auth_rate_limits WHERE action IN ('admin-login','login')");
        $stmt->execute();
        return (int)$stmt->rowCount();
    } catch (Throwable $e) {
        return 0;
    }
}

function ops_recent_admin_security_events(PDO $pdo, int $limit = 12): array {
    if (!ops_has_table($pdo, 'admin_security_events')) return [];
    $limit = max(1, min(50, $limit));
    try {
        $stmt = $pdo->prepare("SELECT ase.*, au.username, au.email, au.display_name FROM admin_security_events ase LEFT JOIN admin_users au ON au.id = ase.admin_user_id ORDER BY ase.id DESC LIMIT {$limit}");
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        return [];
    }
}
function ops_log_wallet_activity(PDO $pdo, ?int $memberId, ?int $tokenClassId, string $actionType, string $actorType = 'admin', ?int $actorId = null, ?array $payload = null): void {
    if (!ops_has_table($pdo, 'wallet_activity')) return;
    $stmt = $pdo->prepare("INSERT INTO wallet_activity (member_id, token_class_id, action_type, actor_type, actor_id, payload_json, created_at) VALUES (?, ?, ?, ?, ?, ?, NOW())");
    $stmt->execute([$memberId, $tokenClassId, $actionType, $actorType, $actorId, $payload ? json_encode($payload, JSON_UNESCAPED_SLASHES) : null]);
}

function ops_activity_rows(PDO $pdo): array {
    try {
        if (ops_has_table($pdo, 'wallet_activity')) return $pdo->query("SELECT * FROM wallet_activity ORDER BY id DESC LIMIT 50")->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {}
    return [];
}

function ops_evidence_review_types(): array {
    return ['identity','abn','landholder','zone','guardian','manual_override'];
}


function ops_label_settings(PDO $pdo): array {
    $defaults = [
        'public_label_partner' => 'Partner',
        'public_label_contribution' => 'partnership contribution',
        'internal_label_member' => 'Member',
        'internal_label_membership_fee' => '$4 membership fee',
    ];
    if (!ops_has_table($pdo, 'admin_settings')) return $defaults;
    $keys = array_keys($defaults);
    $placeholders = implode(',', array_fill(0, count($keys), '?'));
    try {
        $stmt = $pdo->prepare("SELECT setting_key, setting_value FROM admin_settings WHERE setting_key IN ($placeholders)");
        $stmt->execute($keys);
        foreach (($stmt->fetchAll(PDO::FETCH_ASSOC) ?: []) as $row) {
            $key = (string)($row['setting_key'] ?? '');
            if ($key !== '') {
                $defaults[$key] = (string)($row['setting_value'] ?? $defaults[$key] ?? '');
            }
        }
    } catch (Throwable $e) {
        return $defaults;
    }
    return $defaults;
}

function ops_member_acceptance_map(PDO $pdo, array $memberIds): array {
    $ids = array_values(array_unique(array_filter(array_map('intval', $memberIds), fn($v) => $v > 0)));
    if (!$ids) return [];
    $map = [];
    $place = implode(',', array_fill(0, count($ids), '?'));

    if (ops_has_table($pdo, 'partners') && ops_has_table($pdo, 'partner_entry_records')) {
        $hasEvidence = ops_has_table($pdo, 'evidence_vault_entries');
        $sql = "SELECT p.member_id, per.*" . ($hasEvidence ? ", eve.id AS evidence_entry_id, eve.entry_type AS evidence_entry_type, eve.payload_hash AS evidence_payload_hash" : "") . "
"
             . "FROM partners p
"
             . "JOIN partner_entry_records per ON per.partner_id = p.id
"
             . ($hasEvidence ? "LEFT JOIN evidence_vault_entries eve ON eve.id = per.evidence_vault_id
" : "")
             . "WHERE p.member_id IN ($place)
"
             . "ORDER BY p.member_id ASC, per.accepted_at DESC, per.id DESC";
        try {
            $stmt = $pdo->prepare($sql);
            $stmt->execute($ids);
            foreach (($stmt->fetchAll(PDO::FETCH_ASSOC) ?: []) as $row) {
                $mid = (int)($row['member_id'] ?? 0);
                if ($mid > 0 && !isset($map[$mid])) {
                    $map[$mid] = $row;
                }
            }
        } catch (Throwable $e) {
            // fall through to legacy fallback
        }
    }

    if (count($map) === count($ids)) return $map;

    if (ops_has_table($pdo, 'snft_memberships')) {
        $missing = array_values(array_diff($ids, array_keys($map)));
        if ($missing) {
            $place2 = implode(',', array_fill(0, count($missing), '?'));
            $sql2 = "SELECT id AS member_id, reservation_notice_version, reservation_notice_accepted_at, reservation_notice_accepted FROM snft_memberships WHERE id IN ($place2)";
            try {
                $stmt = $pdo->prepare($sql2);
                $stmt->execute($missing);
                foreach (($stmt->fetchAll(PDO::FETCH_ASSOC) ?: []) as $row) {
                    $mid = (int)($row['member_id'] ?? 0);
                    if ($mid > 0) {
                        $map[$mid] = [
                            'member_id' => $mid,
                            'accepted_version' => $row['reservation_notice_version'] ?? null,
                            'accepted_at' => $row['reservation_notice_accepted_at'] ?? null,
                            'checkbox_confirmed' => (int)($row['reservation_notice_accepted'] ?? 0),
                            'entry_channel' => 'legacy_wallet_record',
                            'jvpa_title' => null,
                            'agreement_hash' => null,
                            'acceptance_record_hash' => null,
                            'evidence_vault_id' => null,
                        ];
                    }
                }
            } catch (Throwable $e) {
                // ignore
            }
        }
    }

    return $map;
}

function ops_acceptance_status_tone(?array $acceptance): string {
    if (!$acceptance) return 'bad';
    $version = trim((string)($acceptance['accepted_version'] ?? ''));
    $acceptedAt = trim((string)($acceptance['accepted_at'] ?? ''));
    if ($version === '' || $acceptedAt === '') return 'bad';
    $hasEvidence = !empty($acceptance['evidence_vault_id']) || !empty($acceptance['evidence_entry_id']);
    $hasHash = trim((string)($acceptance['acceptance_record_hash'] ?? '')) !== '';
    $checkboxConfirmed = (int)($acceptance['checkbox_confirmed'] ?? 0) === 1;
    $titlePresent = trim((string)($acceptance['jvpa_title'] ?? '')) !== '';
    return ($hasEvidence && $hasHash && ($checkboxConfirmed || $titlePresent)) ? 'ok' : 'warn';
}

function ops_acceptance_status_label(?array $acceptance): string {
    return match (ops_acceptance_status_tone($acceptance)) {
        'ok' => 'Recorded',
        'warn' => 'Legacy / incomplete',
        default => 'Missing',
    };
}


if (!function_exists('ops_admin_help_assets_once')) {
    function ops_admin_help_assets_once(): void {
        static $printed = false;
        if ($printed) return;
        $printed = true;
        echo <<<'HTML'
<style>
.admin-help-btn{display:inline-flex;align-items:center;justify-content:center;width:20px;height:20px;border-radius:999px;border:1px solid rgba(212,178,92,.28);background:rgba(212,178,92,.08);color:#d4b25c;font-size:11px;font-weight:800;cursor:pointer;vertical-align:middle;margin-left:6px;line-height:1;box-shadow:none}
.admin-help-btn:hover{background:rgba(212,178,92,.16);border-color:rgba(212,178,92,.42)}
.admin-info-panel,.admin-workflow-panel,.admin-guide-panel,.admin-status-panel{background:linear-gradient(180deg,rgba(255,255,255,.04),rgba(255,255,255,.02));border:1px solid rgba(255,255,255,.08);border-radius:18px;padding:18px 20px}
.admin-info-panel + .admin-info-panel,.admin-info-panel + .admin-workflow-panel,.admin-workflow-panel + .admin-guide-panel{margin-top:16px}
.admin-info-kicker{display:inline-block;font-size:11px;letter-spacing:.08em;text-transform:uppercase;color:#9fb0c1;margin-bottom:8px}
.admin-info-title{display:flex;align-items:center;gap:8px;flex-wrap:wrap;margin:0 0 8px;font-size:1.2rem}
.admin-info-body{color:#9fb0c1;font-size:13px;line-height:1.7;margin:0 0 12px}
.admin-info-list{display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:10px;list-style:none;padding:0;margin:0}
.admin-info-list li,.admin-status-list li{background:rgba(255,255,255,.03);border:1px solid rgba(255,255,255,.06);border-radius:12px;padding:10px 12px;color:#eef2f7;font-size:12px;line-height:1.5}
.admin-workflow-steps{display:grid;grid-template-columns:repeat(auto-fit,minmax(170px,1fr));gap:10px;margin-top:10px}
.admin-workflow-step{position:relative;background:rgba(255,255,255,.03);border:1px solid rgba(255,255,255,.06);border-radius:14px;padding:12px 14px 12px 44px}
.admin-workflow-step .num{position:absolute;left:14px;top:12px;width:22px;height:22px;border-radius:999px;background:rgba(212,178,92,.16);border:1px solid rgba(212,178,92,.28);display:flex;align-items:center;justify-content:center;font-size:11px;font-weight:800;color:#d4b25c}
.admin-workflow-step strong{display:block;font-size:12px;margin-bottom:6px}
.admin-workflow-step span{display:block;font-size:12px;color:#9fb0c1;line-height:1.5}
.admin-workflow-step .admin-workflow-desc{display:block;font-size:12px;color:#9fb0c1;line-height:1.5}
.admin-status-list li .admin-status-desc{display:block;color:#9fb0c1;line-height:1.5}
.admin-guide-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(210px,1fr));gap:12px;margin-top:12px}
.admin-guide-item{background:rgba(255,255,255,.03);border:1px solid rgba(255,255,255,.06);border-radius:14px;padding:14px}
.admin-guide-item h3{margin:0 0 6px;font-size:13px}
.admin-guide-item p{margin:0;color:#9fb0c1;font-size:12px;line-height:1.6}
.admin-status-list{display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:10px;list-style:none;padding:0;margin:12px 0 0}
.admin-status-list li strong{display:block;margin-bottom:4px;font-size:12px}
.admin-help-modal{position:fixed;inset:0;background:rgba(7,10,14,.72);display:none;align-items:center;justify-content:center;padding:16px;z-index:9999}
.admin-help-modal.open{display:flex}
.admin-help-dialog{width:min(560px,100%);background:linear-gradient(180deg,#17212b,#1f2c38);border:1px solid rgba(255,255,255,.08);border-radius:22px;box-shadow:0 24px 70px rgba(0,0,0,.42);padding:22px 22px 18px}
.admin-help-dialog h3{margin:0 0 8px;font-size:1.15rem}
.admin-help-dialog p{margin:0;color:#9fb0c1;line-height:1.7;font-size:13px;white-space:pre-line}
.admin-help-close{margin-top:16px;display:inline-flex;align-items:center;justify-content:center;padding:9px 14px;border-radius:12px;border:1px solid rgba(255,255,255,.08);background:rgba(255,255,255,.04);color:#eef2f7;font-weight:700;cursor:pointer}
@media(max-width:700px){.admin-info-list,.admin-status-list,.admin-guide-grid,.admin-workflow-steps{grid-template-columns:1fr}}
</style>
<script>
(function(){
  function ensureModal(){
    var existing=document.getElementById("admin-help-modal");
    if(existing) return existing;
    var modal=document.createElement("div");
    modal.id="admin-help-modal";
    modal.className="admin-help-modal";
    modal.innerHTML='<div class="admin-help-dialog" role="dialog" aria-modal="true" aria-labelledby="admin-help-title"><h3 id="admin-help-title"></h3><p id="admin-help-body"></p><button type="button" class="admin-help-close">Close</button></div>';
    document.body.appendChild(modal);
    modal.addEventListener("click", function(e){ if(e.target===modal || e.target.classList.contains("admin-help-close")){ modal.classList.remove("open"); } });
    document.addEventListener("keydown", function(e){ if(e.key==="Escape"){ modal.classList.remove("open"); } });
    return modal;
  }
  function openHelp(btn){
    var modal=ensureModal();
    modal.querySelector("#admin-help-title").textContent=btn.getAttribute("data-help-title")||"Admin help";
    modal.querySelector("#admin-help-body").textContent=btn.getAttribute("data-help-body")||"";
    modal.classList.add("open");
  }
  document.addEventListener("click", function(e){
    var btn=e.target.closest(".admin-help-btn");
    if(!btn) return;
    e.preventDefault();
    openHelp(btn);
  });
})();
</script>
HTML;
    }
}

if (!function_exists('ops_admin_help_button')) {
    function ops_admin_help_button(string $title, string $body, string $label = '?'): string {
        return '<button type="button" class="admin-help-btn" data-help-title="' . ops_h($title) . '" data-help-body="' . ops_h($body) . '" aria-label="More information about ' . ops_h($title) . '">' . ops_h($label) . '</button>';
    }
}

if (!function_exists('ops_admin_info_panel')) {
    function ops_admin_info_panel(string $kicker, string $title, string $body, array $bullets = []): string {
        $html = '<section class="admin-info-panel">';
        if ($kicker !== '') {
            $html .= '<span class="admin-info-kicker">' . ops_h($kicker) . '</span>';
        }
        $html .= '<h2 class="admin-info-title">' . ops_h($title) . '</h2>';
        $html .= '<p class="admin-info-body">' . ops_h($body) . '</p>';
        if ($bullets) {
            $html .= '<ul class="admin-info-list">';
            foreach ($bullets as $bullet) {
                $html .= '<li>' . ops_h((string)$bullet) . '</li>';
            }
            $html .= '</ul>';
        }
        $html .= '</section>';
        return $html;
    }
}

if (!function_exists('ops_admin_workflow_panel')) {
    function ops_admin_workflow_panel(string $title, string $body, array $steps): string {
        $html = '<section class="admin-workflow-panel">';
        $html .= '<h2 class="admin-info-title">' . ops_h($title) . '</h2>';
        if ($body !== '') {
            $html .= '<p class="admin-info-body">' . ops_h($body) . '</p>';
        }
        $html .= '<div class="admin-workflow-steps">';
        $i = 1;
        foreach ($steps as $step) {
            $label = is_array($step) ? (string)($step['title'] ?? '') : (string)$step;
            $desc = is_array($step) ? (string)($step['body'] ?? '') : '';
            $html .= '<div class="admin-workflow-step"><span class="num">' . $i . '</span><strong>' . ops_h($label) . '</strong>';
            if ($desc !== '') {
                $html .= '<div class="admin-workflow-desc">' . ops_h($desc) . '</div>';
            }
            $html .= '</div>';
            $i++;
        }
        $html .= '</div></section>';
        return $html;
    }
}

if (!function_exists('ops_admin_guide_panel')) {
    function ops_admin_guide_panel(string $title, string $body, array $items): string {
        $html = '<section class="admin-guide-panel">';
        $html .= '<h2 class="admin-info-title">' . ops_h($title) . '</h2>';
        if ($body !== '') {
            $html .= '<p class="admin-info-body">' . ops_h($body) . '</p>';
        }
        $html .= '<div class="admin-guide-grid">';
        foreach ($items as $item) {
            $heading = (string)($item['title'] ?? '');
            $desc = (string)($item['body'] ?? '');
            $html .= '<div class="admin-guide-item"><h3>' . ops_h($heading) . '</h3><p>' . ops_h($desc) . '</p></div>';
        }
        $html .= '</div></section>';
        return $html;
    }
}

if (!function_exists('ops_admin_status_panel')) {
    function ops_admin_status_panel(string $title, string $body, array $items): string {
        $html = '<section class="admin-status-panel">';
        $html .= '<h2 class="admin-info-title">' . ops_h($title) . '</h2>';
        if ($body !== '') {
            $html .= '<p class="admin-info-body">' . ops_h($body) . '</p>';
        }
        if ($items) {
            $html .= '<ul class="admin-status-list">';
            foreach ($items as $item) {
                $label = (string)($item['label'] ?? '');
                $desc = (string)($item['body'] ?? '');
                $html .= '<li><strong>' . ops_h($label) . '</strong>' . ($desc !== '' ? '<div class="admin-status-desc">' . ops_h($desc) . '</div>' : '') . '</li>';
            }
            $html .= '</ul>';
        }
        $html .= '</section>';
        return $html;
    }
}


    if (!function_exists('ops_render_page')) {
        function ops_render_page(string $title, ...$args): void {
            require_once __DIR__ . '/admin_sidebar.php';

            $active = 'dashboard';
            $body = '';
            $flash = null;
            $flashType = 'ok';

            if (count($args) === 1) {
                $body = (string)$args[0];
            } elseif (count($args) >= 2) {
                $active = is_string($args[0]) ? $args[0] : 'dashboard';
                $body = (string)($args[1] ?? '');
                $flash = $args[2] ?? null;
                $flashType = (string)($args[3] ?? 'ok');
            }

            ?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<link rel="stylesheet" href="./assets/admin.css">
<title><?php echo ops_h($title); ?> | COG$ Admin</title>
<style>
.shell{display:grid;grid-template-columns:var(--sidebar-open,238px) minmax(0,1fr);min-height:100vh;width:100%;max-width:100%}
.sidebar{background:linear-gradient(180deg,#121a23,#16212b);border-right:1px solid var(--line);padding:24px 18px}
.brand{display:flex;gap:12px;align-items:center;margin-bottom:24px}
.brand img{width:44px;height:44px;border-radius:50%}
.brand strong{display:block}
.brand span{color:var(--muted);font-size:.9rem}
.side-section{margin-bottom:24px}
.side-label{font-size:.8rem;color:var(--muted);text-transform:uppercase;letter-spacing:.08em;margin:0 0 10px}
.nav{display:grid;gap:10px}
.nav a{display:block;text-decoration:none;color:var(--text);padding:12px 14px;border:1px solid var(--line);border-radius:14px;background:rgba(255,255,255,.03)}
.nav a.active{background:linear-gradient(180deg,#d4b25c,#b98b2f);color:#201507;border-color:rgba(212,178,92,.35);font-weight:800}
.main{padding:26px;min-width:0;max-width:100%}
.topbar{display:flex;justify-content:space-between;align-items:flex-start;gap:18px;margin-bottom:22px;flex-wrap:wrap}
.eyebrow{display:inline-block;font-size:.78rem;color:var(--muted);text-transform:uppercase;letter-spacing:.08em;margin-bottom:10px}
h1{margin:0 0 8px;font-size:2rem}
.muted{color:var(--muted)}
.button{display:inline-block;text-decoration:none;padding:.85rem 1rem;border-radius:14px;font-weight:800;border:1px solid rgba(212,178,92,.35);background:linear-gradient(180deg,#d4b25c,#b98b2f);color:#201507}
.button.secondary{background:rgba(255,255,255,.04);color:var(--text);border-color:var(--line)}
.card,.section,.panel{background:linear-gradient(180deg,var(--panel),var(--panel2));border:1px solid var(--line);border-radius:24px;padding:20px;box-shadow:0 18px 45px rgba(0,0,0,.22)}
.grid{display:grid;gap:18px}
.table-wrap{overflow-x:auto}
table{width:100%;border-collapse:collapse}
th,td{padding:10px 8px;border-bottom:1px dashed rgba(255,255,255,.08);text-align:left;vertical-align:top}
th{color:var(--muted);font-weight:600}
.msg,.okbox{padding:12px 14px;border-radius:14px;margin-bottom:16px;background:rgba(47,143,87,.12);border:1px solid rgba(47,143,87,.35);color:var(--ok)}
.err{padding:12px 14px;border-radius:14px;margin-bottom:16px;background:rgba(200,61,75,.12);border:1px solid rgba(200,61,75,.35);color:var(--bad)}
input,select,textarea,button{font:inherit}
button,.mini-btn{display:inline-block;background:#d4b25c;color:#201507;border:1px solid rgba(212,178,92,.35);padding:.8rem 1rem;border-radius:12px;font-weight:700;text-decoration:none;cursor:pointer}
.mini-btn.secondary,button.secondary{background:rgba(255,255,255,.04);color:var(--text);border-color:var(--line)}
@media (max-width:1100px){.shell{grid-template-columns:minmax(0,1fr)}.main{padding:18px;padding-top:58px}}
</style>
</head>
<body>
<div class="shell">
<?php admin_sidebar_render($active); ?>
<main class="main">
  <div class="topbar">
    <div>
      <span class="eyebrow">Admin shell</span>
      <h1><?php echo ops_h($title); ?></h1>
      <p class="muted">Aligned with the current dashboard shell.</p>
    </div>
    <div>
      <a class="button" href="<?php echo ops_h(admin_url('dashboard.php')); ?>">Dashboard</a>
      <a class="button secondary" href="<?php echo ops_h(admin_url('messages.php')); ?>">Communications</a>
    </div>
  </div>
  <?php if ($flash): ?>
    <div class="<?php echo $flashType === 'error' ? 'err' : 'msg'; ?>"><?php echo ops_h((string)$flash); ?></div>
  <?php endif; ?>
  <?php echo $body; ?>
</main>
</div>
</body>
</html><?php
        }
    }

// =============================================================================
// Query helpers — used by all admin pages that call ops_fetch_all / _one / _val
// =============================================================================

/**
 * Fetch all rows for a query.
 */
function ops_fetch_all(PDO $pdo, string $sql, array $params = []): array {
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

/**
 * Fetch a single row, or null if not found.
 */
function ops_fetch_one(PDO $pdo, string $sql, array $params = []): ?array {
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

/**
 * Fetch a single scalar value from the first column of the first row.
 */
function ops_fetch_val(PDO $pdo, string $sql, array $params = []) {
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $val = $stmt->fetchColumn();
    return $val !== false ? $val : null;
}

// =============================================================================
// Admin identity — zero-arg wrapper reads the session set by ops_require_admin()
// =============================================================================

/**
 * Return the current admin's ID from the PHP session, or null if not available.
 * No PDO arg — reads from $_SESSION which ops_require_admin() has already populated.
 */
function ops_admin_id(): ?int {
    ops_start_admin_php_session();
    $id = $_SESSION['admin_id']
        ?? $_SESSION['admin_user']['principal_id']
        ?? $_SESSION['admin']['id']
        ?? null;
    return $id !== null ? (int)$id : null;
}

// =============================================================================
// Settings helpers — read/write the admin_settings table
// =============================================================================

/**
 * The canonical set of settings keys with their default values.
 * These map to the fields shown on the Settings admin page.
 */
function ops_settings_defaults(): array {
    return [
        'manual_control_mode'                  => 'enabled',
        'default_chain_target'                 => 'besu-prep',
        'evidence_review_required_for_landholder' => '1',
        'evidence_review_required_for_zone'    => '1',
        'batch_minimum_items'                  => '1',
        'handoff_requires_reviewed_batch'      => '1',
        'email_sender_name'                    => 'COG$ of Australia Foundation',
        'email_sender_address'                 => 'members@cogsaustralia.org',
    ];
}

/**
 * Get a single setting value from admin_settings, falling back to $default.
 */
function ops_setting_get(PDO $pdo, string $key, string $default = ''): string {
    try {
        $row = ops_fetch_one($pdo, 'SELECT setting_value FROM admin_settings WHERE setting_key = ? LIMIT 1', [$key]);
        if ($row !== null && $row['setting_value'] !== null) {
            return (string)$row['setting_value'];
        }
    } catch (Throwable $e) {}
    return $default;
}

/**
 * Upsert a setting in admin_settings.
 */
function ops_setting_set(PDO $pdo, string $key, string $value, string $type = 'text', string $notes = ''): void {
    $adminId = ops_admin_id();
    $now     = ops_now();
    $pdo->prepare(
        'INSERT INTO admin_settings (setting_key, setting_value, setting_type, notes, updated_by_admin_id, created_at, updated_at)
         VALUES (?, ?, ?, ?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), setting_type = VALUES(setting_type),
             notes = VALUES(notes), updated_by_admin_id = VALUES(updated_by_admin_id), updated_at = VALUES(updated_at)'
    )->execute([$key, $value, $type, $notes ?: null, $adminId, $now, $now]);
}

// =============================================================================
// Exception helpers
// =============================================================================

function ops_exception_types(): array {
    return [
        'general', 'payment', 'identity', 'abn', 'duplicate', 'fraud_flag',
        'eligibility', 'approval', 'wallet', 'evidence', 'system', 'manual',
    ];
}

function ops_exception_severities(): array {
    return ['low', 'medium', 'high', 'critical'];
}

/**
 * Scan the live schema for system-detectable anomalies and return them as
 * exception payloads.  The results are deduped by the caller before inserting.
 */
function ops_collect_system_exceptions(PDO $pdo): array {
    $items = [];
    try {
        // Members with paid signup but wallet still 'invited'
        $rows = ops_fetch_all($pdo,
            "SELECT m.id, m.member_number, m.full_name
             FROM members m
             WHERE m.signup_payment_status = 'paid'
               AND m.wallet_status = 'invited'
             LIMIT 50");
        foreach ($rows as $r) {
            $items[] = [
                'exception_type' => 'wallet',
                'severity'       => 'medium',
                'member_id'      => (int)$r['id'],
                'summary'        => 'Payment marked paid but wallet still invited: ' . $r['member_number'],
                'details'        => 'Member ' . $r['full_name'] . ' has signup_payment_status=paid but wallet_status=invited.',
            ];
        }

        // Approved reservation lines with no payment recorded
        if (ops_has_table($pdo, 'member_reservation_lines')) {
            $rows = ops_fetch_all($pdo,
                "SELECT mrl.id, m.member_number, m.full_name, tc.class_code
                 FROM member_reservation_lines mrl
                 JOIN members m ON m.id = mrl.member_id
                 JOIN token_classes tc ON tc.id = mrl.token_class_id
                 WHERE mrl.approval_status = 'approved'
                   AND mrl.payment_status = 'pending'
                   AND tc.payment_required = 1
                   AND mrl.approved_at < DATE_SUB(UTC_TIMESTAMP(), INTERVAL 7 DAY)
                 LIMIT 50");
            foreach ($rows as $r) {
                $items[] = [
                    'exception_type' => 'payment',
                    'severity'       => 'low',
                    'member_id'      => null,
                    'summary'        => 'Approved line awaiting payment >7 days: ' . $r['member_number'] . ' / ' . $r['class_code'],
                    'details'        => null,
                ];
            }
        }
    } catch (Throwable $e) {
        // Do not crash the admin page if queries fail on schema drift.
    }
    return $items;
}

// =============================================================================
// Evidence review helpers
// =============================================================================

function ops_evidence_review_statuses(): array {
    return ['pending', 'in_review', 'approved', 'rejected', 'deferred'];
}

function ops_create_evidence_review(
    PDO $pdo,
    string $subjectType,
    int $subjectId,
    int $memberId,
    ?int $tokenClassId,
    string $reviewType,
    string $status,
    string $notes,
    string $docRef
): void {
    $now     = ops_now();
    $adminId = ops_admin_id();
    $pdo->prepare(
        'INSERT INTO evidence_reviews
             (subject_type, subject_id, member_id, token_class_id, review_type,
              review_status, document_reference, notes, reviewed_by_admin_id,
              created_at, updated_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    )->execute([
        $subjectType, $subjectId, $memberId, $tokenClassId ?: null,
        $reviewType, $status,
        $docRef !== '' ? $docRef : null,
        $notes  !== '' ? $notes  : null,
        $adminId,
        $now, $now,
    ]);
}

/**
 * After updating a review status, apply any downstream effects.
 * Currently logs a wallet activity event; extend as approval workflow grows.
 */
function ops_apply_review_outcome(PDO $pdo, array $review, string $newStatus): void {
    try {
        $memberId    = (int)($review['member_id']    ?? 0);
        $reviewType  = (string)($review['review_type'] ?? 'general');
        ops_log_wallet_activity(
            $pdo,
            $memberId ?: null,
            isset($review['token_class_id']) ? (int)$review['token_class_id'] : null,
            'evidence_review_' . $newStatus,
            'admin',
            ops_admin_id(),
            [
                'review_id'    => (int)$review['id'],
                'review_type'  => $reviewType,
                'new_status'   => $newStatus,
                'subject_type' => $review['subject_type'] ?? '',
                'subject_id'   => (int)($review['subject_id'] ?? 0),
            ]
        );
    } catch (Throwable $e) {
        // Non-fatal — outcome logging must never block the review update.
    }
}

/**
 * Build a short readable label for an evidence review row.
 */
function ops_review_subject_label(array $row): string {
    $parts = [];
    if (!empty($row['full_name']))   $parts[] = (string)$row['full_name'];
    if (!empty($row['member_number'])) $parts[] = (string)$row['member_number'];
    if (!empty($row['class_code']))  $parts[] = (string)$row['class_code'];
    if (!empty($row['display_name'])) $parts[] = (string)$row['display_name'];
    return $parts ? implode(' · ', $parts) : 'Subject #' . (int)($row['subject_id'] ?? 0);
}

// =============================================================================
// Chain handoff helpers
// =============================================================================

function ops_chain_handoff_statuses(): array {
    return ['prepared', 'under_review', 'signed_off', 'submitted', 'confirmed', 'failed', 'rejected'];
}

function ops_create_chain_handoff(PDO $pdo, int $batchId, string $chainTarget, string $notes): int {
    if ($batchId <= 0) {
        throw new RuntimeException('A valid mint batch is required to create a chain handoff.');
    }
    $code    = 'HANDOFF-' . strtoupper(bin2hex(random_bytes(5))) . '-' . date('Ymd');
    $now     = ops_now();
    $adminId = ops_admin_id();
    $pdo->prepare(
        'INSERT INTO chain_handoffs
             (mint_batch_id, handoff_code, chain_target, handoff_status,
              prepared_by_admin_id, notes, created_at, updated_at)
         VALUES (?, ?, ?, \'prepared\', ?, ?, ?, ?)'
    )->execute([$batchId, $code, $chainTarget ?: 'besu-prep', $adminId, $notes ?: null, $now, $now]);
    return (int)$pdo->lastInsertId();
}

function ops_update_chain_handoff(
    PDO $pdo,
    int $handoffId,
    string $status,
    string $txRef,
    string $attestationHash,
    string $notes
): void {
    $allowed = ops_chain_handoff_statuses();
    if (!in_array($status, $allowed, true)) {
        throw new RuntimeException('Invalid handoff status: ' . $status);
    }
    $adminId = ops_admin_id();
    $pdo->prepare(
        'UPDATE chain_handoffs
         SET handoff_status = ?, tx_reference = ?, attestation_hash = ?,
             notes = ?, reviewed_by_admin_id = ?, updated_at = ?
         WHERE id = ?'
    )->execute([
        $status,
        $txRef            !== '' ? $txRef            : null,
        $attestationHash  !== '' ? $attestationHash  : null,
        $notes            !== '' ? $notes            : null,
        $adminId,
        ops_now(),
        $handoffId,
    ]);
}

/**
 * Build the pre-blockchain payload for a batch — member + token details
 * for the signing and review step before chain submission.
 */
function ops_batch_payload(PDO $pdo, int $batchId): array {
    if (!ops_has_table($pdo, 'mint_batch_items') || !ops_has_table($pdo, 'mint_queue')) {
        return [];
    }
    try {
        $batch = ops_fetch_one($pdo,
            'SELECT * FROM mint_batches WHERE id = ? LIMIT 1', [$batchId]);
        if (!$batch) {
            return [];
        }
        $items = ops_fetch_all($pdo,
            'SELECT mbi.id item_id, mq.id queue_id, mq.member_id, mq.token_class_id,
                    mq.manual_signoff_lane, mq.queue_status, mq.evidence_reference,
                    m.member_number, m.full_name, m.email, m.member_type,
                    tc.class_code, tc.display_name, tc.unit_price_cents
             FROM mint_batch_items mbi
             JOIN mint_queue mq ON mq.id = mbi.queue_id
             JOIN members    m  ON m.id  = mq.member_id
             JOIN token_classes tc ON tc.id = mq.token_class_id
             WHERE mbi.batch_id = ?
             ORDER BY mbi.id',
            [$batchId]);
        return [
            'batch_id'     => (int)$batch['id'],
            'batch_code'   => $batch['batch_code'],
            'batch_label'  => $batch['batch_label'],
            'chain_target' => $batch['chain_target'],
            'batch_status' => $batch['batch_status'],
            'item_count'   => count($items),
            'items'        => $items,
            'generated_at' => ops_now(),
        ];
    } catch (Throwable $e) {
        return ['error' => $e->getMessage()];
    }
}

// =============================================================================
// Mint queue helpers
// =============================================================================

function ops_mint_queue_allowed_statuses(): array {
    return [
        'queued', 'ready_for_batch', 'ready_for_blockchain',
        'locked_manual', 'manual_hold', 'held_manual',
        'minted', 'minted_later', 'rejected',
    ];
}

function ops_create_mint_batch(PDO $pdo, string $label, string $chainTarget, string $notes, array $queueIds): int {
    $queueIds = array_filter(array_map('intval', $queueIds));
    if (empty($queueIds)) {
        throw new RuntimeException('Select at least one queue item to create a batch.');
    }
    $code    = 'BATCH-' . strtoupper(bin2hex(random_bytes(4))) . '-' . date('Ymd');
    $label   = $label !== '' ? $label : ('Batch ' . date('Y-m-d H:i'));
    $now     = ops_now();
    $adminId = ops_admin_id();
    $pdo->prepare(
        'INSERT INTO mint_batches
             (batch_code, batch_label, chain_target, batch_status,
              created_by_admin_id, notes, created_at, updated_at)
         VALUES (?, ?, ?, \'prepared\', ?, ?, ?, ?)'
    )->execute([$code, $label, $chainTarget ?: 'besu-prep', $adminId, $notes ?: null, $now, $now]);
    $batchId = (int)$pdo->lastInsertId();

    $stmt = $pdo->prepare(
        'INSERT INTO mint_batch_items (batch_id, queue_id, created_at) VALUES (?, ?, ?)'
    );
    $updQueue = $pdo->prepare(
        'UPDATE mint_queue SET batch_id = ?, updated_at = ? WHERE id = ?'
    );
    foreach ($queueIds as $qid) {
        $stmt->execute([$batchId, $qid, $now]);
        $updQueue->execute([$batchId, $now, $qid]);
    }
    return $batchId;
}

// =============================================================================
// Email template helpers
// =============================================================================

function ops_email_template_types(): array {
    return [
        'thank_you', 'access_setup', 'password_reset', 'payment_received',
        'approval_ready', 'approved', 'exception_notice', 'manual_review',
        'batch_prepared', 'handoff_notice', 'resend_access',
    ];
}

// =============================================================================
// Short-name aliases — used by page-level code across the admin.
// All wrapped in function_exists() so this file is safe to load multiple times
// and pages that define their own local versions won't fatal on redeclaration.
// =============================================================================

if (!function_exists('h')) {
    function h($v): string { return ops_h($v); }
}

if (!function_exists('rows')) {
    function rows(PDO $pdo, string $sql, array $params = []): array {
        return ops_fetch_all($pdo, $sql, $params);
    }
}

if (!function_exists('one')) {
    function one(PDO $pdo, string $sql, array $params = []): ?array {
        return ops_fetch_one($pdo, $sql, $params);
    }
}

if (!function_exists('q_rows')) {
    function q_rows(PDO $pdo, string $sql, array $params = []): array {
        return ops_fetch_all($pdo, $sql, $params);
    }
}

if (!function_exists('q_one')) {
    function q_one(PDO $pdo, string $sql, array $params = []): ?array {
        return ops_fetch_one($pdo, $sql, $params);
    }
}

if (!function_exists('has_table')) {
    function has_table(PDO $pdo, string $table): bool {
        return ops_table_exists($pdo, $table);
    }
}

if (!function_exists('has_col')) {
    function has_col(PDO $pdo, string $table, string $col): bool {
        return ops_has_col($pdo, $table, $col);
    }
}

if (!function_exists('now_sql')) {
    function now_sql(): string { return ops_now(); }
}

if (!function_exists('member_ref')) {
    function member_ref(array $r): string {
        return (($r['member_type'] ?? '') === 'business')
            ? (string)($r['abn'] ?? '')
            : (string)($r['member_number'] ?? '');
    }
}

// ─────────────────────────────────────────────────────────────────────────────
// CSRF Protection
// admin_csrf_token()  — generate or return the session token for this admin
// admin_csrf_verify() — validate POST token; exits 403 on mismatch
// ─────────────────────────────────────────────────────────────────────────────

function admin_csrf_token(): string {
    ops_start_admin_php_session();
    if (empty($_SESSION['_admin_csrf'])) {
        $_SESSION['_admin_csrf'] = bin2hex(random_bytes(24));
    }
    return (string)$_SESSION['_admin_csrf'];
}

function admin_csrf_verify(): void {
    ops_start_admin_php_session();
    $submitted = (string)($_POST['_csrf'] ?? '');
    $expected  = (string)($_SESSION['_admin_csrf'] ?? '');
    if ($expected === '' || !hash_equals($expected, $submitted)) {
        http_response_code(403);
        echo '<p style="font-family:sans-serif;padding:40px;color:#c00">Invalid or missing security token. Please go back and try again.</p>';
        exit;
    }
}



// =============================================================================
// Phase 1 compliance helpers — JVPA / KYC / payment / approval surface
// =============================================================================

if (!function_exists('ops_latest_partner_entry_record')) {
    function ops_latest_partner_entry_record(PDO $pdo, int $partnerId): ?array {
        if ($partnerId <= 0 || !ops_table_exists($pdo, 'partner_entry_records')) return null;
        try {
            $st = $pdo->prepare(
                "SELECT per.*
                 FROM partner_entry_records per
                 WHERE per.partner_id = ?
                 ORDER BY per.id DESC
                 LIMIT 1"
            );
            $st->execute([$partnerId]);
            $row = $st->fetch(PDO::FETCH_ASSOC);
            return $row ?: null;
        } catch (Throwable $e) {
            return null;
        }
    }
}

if (!function_exists('ops_latest_medicare_submission')) {
    function ops_latest_medicare_submission(PDO $pdo, int $memberId): ?array {
        if ($memberId <= 0 || !ops_table_exists($pdo, 'kyc_medicare_submissions')) return null;
        try {
            $st = $pdo->prepare(
                "SELECT s.*
                 FROM kyc_medicare_submissions s
                 WHERE s.member_id = ?
                 ORDER BY s.id DESC
                 LIMIT 1"
            );
            $st->execute([$memberId]);
            $row = $st->fetch(PDO::FETCH_ASSOC);
            return $row ?: null;
        } catch (Throwable $e) {
            return null;
        }
    }
}

if (!function_exists('ops_latest_approval_request_for_member')) {
    function ops_latest_approval_request_for_member(PDO $pdo, int $memberId): ?array {
        if ($memberId <= 0 || !ops_table_exists($pdo, 'approval_requests')) return null;
        try {
            $st = $pdo->prepare(
                "SELECT ar.*
                 FROM approval_requests ar
                 WHERE ar.member_id = ?
                 ORDER BY ar.id DESC
                 LIMIT 1"
            );
            $st->execute([$memberId]);
            $row = $st->fetch(PDO::FETCH_ASSOC);
            return $row ?: null;
        } catch (Throwable $e) {
            return null;
        }
    }
}

if (!function_exists('ops_partner_compliance_snapshot')) {
    function ops_partner_compliance_snapshot(PDO $pdo, int $memberId): array {
        $snapshot = [
            'member_id' => $memberId,
            'partner_id' => $memberId,
            'jvpa' => [
                'status' => 'missing',
                'label' => 'Missing',
                'accepted_version' => null,
                'accepted_at' => null,
                'jvpa_title' => null,
                'acceptance_hash' => null,
                'evidence_vault_id' => null,
                'evidence_linked' => false,
                'verified' => false,
            ],
            'kyc' => [
                'status' => 'not_submitted',
                'label' => 'Not submitted',
                'submission_id' => null,
                'verified_at' => null,
                'rejected_reason' => null,
            ],
            'payment' => [
                'status' => null,
                'label' => 'Unknown',
            ],
            'approval' => [
                'status' => null,
                'label' => 'Unknown',
                'approval_request_id' => null,
            ],
        ];

        $per = ops_latest_partner_entry_record($pdo, $memberId);
        if ($per) {
            $acceptedVersion = trim((string)($per['accepted_version'] ?? ''));
            $acceptedAt = (string)($per['accepted_at'] ?? '');
            $evidenceId = (int)($per['evidence_vault_id'] ?? 0);
            $acceptanceHash = trim((string)($per['acceptance_record_hash'] ?? ''));
            $verified = ($acceptedVersion !== '' && $evidenceId > 0 && $acceptanceHash !== '');
            $status = $verified ? 'verified' : (($acceptedVersion !== '' || !empty($per['checkbox_confirmed'])) ? 'accepted_incomplete' : 'missing');
            $snapshot['jvpa'] = [
                'status' => $status,
                'label' => $verified ? 'Recorded' : (($status === 'accepted_incomplete') ? 'Accepted, evidence incomplete' : 'Missing'),
                'accepted_version' => $acceptedVersion !== '' ? $acceptedVersion : null,
                'accepted_at' => $acceptedAt !== '' ? $acceptedAt : null,
                'jvpa_title' => trim((string)($per['jvpa_title'] ?? '')) ?: null,
                'acceptance_hash' => $acceptanceHash !== '' ? $acceptanceHash : null,
                'evidence_vault_id' => $evidenceId > 0 ? $evidenceId : null,
                'evidence_linked' => $evidenceId > 0,
                'verified' => $verified,
            ];
        }

        $sub = ops_latest_medicare_submission($pdo, $memberId);
        if ($sub) {
            $status = (string)($sub['status'] ?? 'pending');
            $labels = [
                'pending' => 'Pending admin review',
                'under_review' => 'Under review',
                'verified' => 'Verified',
                'rejected' => 'Rejected',
            ];
            $snapshot['kyc'] = [
                'status' => $status,
                'label' => $labels[$status] ?? ucwords(str_replace('_', ' ', $status)),
                'submission_id' => (int)($sub['id'] ?? 0) ?: null,
                'verified_at' => !empty($sub['verified_at']) ? (string)$sub['verified_at'] : null,
                'rejected_reason' => !empty($sub['rejection_reason']) ? (string)$sub['rejection_reason'] : null,
            ];
        } elseif (ops_table_exists($pdo, 'snft_memberships')) {
            try {
                $st = $pdo->prepare("SELECT kyc_status, kyc_verified_at, kyc_submission_id FROM snft_memberships WHERE id = ? LIMIT 1");
                $st->execute([$memberId]);
                $snft = $st->fetch(PDO::FETCH_ASSOC) ?: [];
                $status = (string)($snft['kyc_status'] ?? '');
                if ($status !== '') {
                    $labels = [
                        'pending' => 'Pending admin review',
                        'under_review' => 'Under review',
                        'verified' => 'Verified',
                        'rejected' => 'Rejected',
                        'none' => 'Not submitted',
                    ];
                    $snapshot['kyc'] = [
                        'status' => $status,
                        'label' => $labels[$status] ?? ucwords(str_replace('_', ' ', $status)),
                        'submission_id' => !empty($snft['kyc_submission_id']) ? (int)$snft['kyc_submission_id'] : null,
                        'verified_at' => !empty($snft['kyc_verified_at']) ? (string)$snft['kyc_verified_at'] : null,
                        'rejected_reason' => null,
                    ];
                }
            } catch (Throwable $e) {}
        }

        if (ops_table_exists($pdo, 'payments')) {
            try {
                $pay = [];

                // Prefer the most recent active signup/manual payment because later cancelled
                // adjustment intents should not override a completed entry contribution state.
                $st = $pdo->prepare(
                    "SELECT payment_status, payment_type, external_reference, notes, received_at, created_at
                     FROM payments
                     WHERE member_id = ?
                       AND payment_status IN ('paid','pending')
                       AND payment_type IN ('signup','manual')
                     ORDER BY COALESCE(received_at, created_at) DESC, id DESC
                     LIMIT 1"
                );
                $st->execute([$memberId]);
                $pay = $st->fetch(PDO::FETCH_ASSOC) ?: [];

                // Fallback to the latest non-cancelled payment of any type.
                if (!$pay) {
                    $st = $pdo->prepare(
                        "SELECT payment_status, payment_type, external_reference, notes, received_at, created_at
                         FROM payments
                         WHERE member_id = ?
                           AND payment_status <> 'cancelled'
                         ORDER BY COALESCE(received_at, created_at) DESC, id DESC
                         LIMIT 1"
                    );
                    $st->execute([$memberId]);
                    $pay = $st->fetch(PDO::FETCH_ASSOC) ?: [];
                }

                // Final fallback only if nothing else exists.
                if (!$pay) {
                    $st = $pdo->prepare(
                        "SELECT payment_status, payment_type, external_reference, notes, received_at, created_at
                         FROM payments
                         WHERE member_id = ?
                         ORDER BY COALESCE(received_at, created_at) DESC, id DESC
                         LIMIT 1"
                    );
                    $st->execute([$memberId]);
                    $pay = $st->fetch(PDO::FETCH_ASSOC) ?: [];
                }

                if ($pay) {
                    $status = (string)($pay['payment_status'] ?? '');
                    $labels = [
                        'paid' => 'Paid',
                        'pending' => 'Pending',
                        'failed' => 'Failed',
                        'cancelled' => 'Cancelled',
                        'refunded' => 'Refunded',
                    ];
                    $snapshot['payment'] = [
                        'status' => $status,
                        'label' => $labels[$status] ?? ucwords(str_replace('_', ' ', $status)),
                    ];
                }
            } catch (Throwable $e) {}
        }

        $approval = ops_latest_approval_request_for_member($pdo, $memberId);
        if ($approval) {
            $status = (string)($approval['request_status'] ?? 'pending');
            $labels = [
                'pending' => 'Pending approval',
                'approved' => 'Approved',
                'rejected' => 'Rejected',
            ];
            $snapshot['approval'] = [
                'status' => $status,
                'label' => $labels[$status] ?? ucwords(str_replace('_', ' ', $status)),
                'approval_request_id' => (int)($approval['id'] ?? 0) ?: null,
            ];
        }

        return $snapshot;
    }
}

if (!function_exists('ops_asset_backing_class_codes')) {
    function ops_asset_backing_class_codes(): array {
        return [
            'ASX_INVESTMENT_COG' => 'asx_trade',
            'RWA_COG' => 'rwa_valuation',
        ];
    }
}

if (!function_exists('ops_asset_backing_token_mode')) {
    function ops_asset_backing_token_mode(PDO $pdo, int $tokenClassId): ?string {
        if ($tokenClassId <= 0 || !ops_has_table($pdo, 'token_classes')) return null;
        $code = (string)ops_fetch_val($pdo, 'SELECT class_code FROM token_classes WHERE id = ? LIMIT 1', [$tokenClassId]);
        $map = ops_asset_backing_class_codes();
        return $map[$code] ?? null;
    }
}

if (!function_exists('ops_asset_backing_status_for_approval')) {
    function ops_asset_backing_status_for_approval(PDO $pdo, int $approvalRequestId): array {
        $base = [
            'required' => false,
            'mode' => null,
            'token_class_code' => '',
            'approved_units' => 0.0,
            'allocated_reserved_units' => 0.0,
            'allocated_mint_ready_units' => 0.0,
            'allocated_minted_units' => 0.0,
            'remaining_units' => 0.0,
            'is_fully_backed' => false,
            'gate_status' => 'not_required',
        ];
        if ($approvalRequestId <= 0 || !ops_has_table($pdo, 'approval_requests')) return $base;
        $row = ops_fetch_one($pdo, "SELECT ar.id, ar.member_id, ar.token_class_id, ar.requested_units, tc.class_code
            FROM approval_requests ar
            LEFT JOIN token_classes tc ON tc.id = ar.token_class_id
            WHERE ar.id = ? LIMIT 1", [$approvalRequestId]);
        if (!$row) return $base;
        $mode = ops_asset_backing_token_mode($pdo, (int)$row['token_class_id']);
        $base['mode'] = $mode;
        $base['token_class_code'] = (string)($row['class_code'] ?? '');
        if ($mode === null) return $base;
        $base['required'] = true;

        $approvedUnits = 0.0;
        if (ops_has_table($pdo, 'member_reservation_lines')) {
            $approvedUnits = (float)ops_fetch_val($pdo, 'SELECT COALESCE(approved_units,0) FROM member_reservation_lines WHERE member_id = ? AND token_class_id = ? LIMIT 1', [(int)$row['member_id'], (int)$row['token_class_id']]);
        }
        if ($approvedUnits <= 0) $approvedUnits = (float)($row['requested_units'] ?? 0);
        $base['approved_units'] = $approvedUnits;

        if (ops_has_table($pdo, 'stewardship_backing_allocations')) {
            $allocs = ops_fetch_one($pdo, "SELECT
                COALESCE(SUM(CASE WHEN allocation_status='reserved' THEN allocated_units ELSE 0 END),0) AS reserved_units,
                COALESCE(SUM(CASE WHEN allocation_status='mint_ready' THEN allocated_units ELSE 0 END),0) AS mint_ready_units,
                COALESCE(SUM(CASE WHEN allocation_status='minted' THEN allocated_units ELSE 0 END),0) AS minted_units
                FROM stewardship_backing_allocations WHERE approval_request_id = ?", [$approvalRequestId]) ?: [];
            $base['allocated_reserved_units'] = (float)($allocs['reserved_units'] ?? 0);
            $base['allocated_mint_ready_units'] = (float)($allocs['mint_ready_units'] ?? 0);
            $base['allocated_minted_units'] = (float)($allocs['minted_units'] ?? 0);
        }
        $covered = $base['allocated_reserved_units'] + $base['allocated_mint_ready_units'] + $base['allocated_minted_units'];
        $base['remaining_units'] = max(0.0, $approvedUnits - $covered);
        $base['is_fully_backed'] = $approvedUnits > 0 && $base['remaining_units'] <= 0.000001;
        $base['gate_status'] = $base['is_fully_backed'] ? 'fully_backed' : 'awaiting_asset_backing';
        return $base;
    }
}

if (!function_exists('ops_asset_backing_sync_approval_state')) {
    function ops_asset_backing_sync_approval_state(PDO $pdo, int $approvalRequestId): void {
        if ($approvalRequestId <= 0 || !ops_has_table($pdo, 'approval_requests')) return;
        $status = ops_asset_backing_status_for_approval($pdo, $approvalRequestId);
        if (!$status['required']) return;
        $mintStatus = $status['is_fully_backed'] ? 'prepared' : 'awaiting_asset_backing';
        $pdo->prepare('UPDATE approval_requests SET mint_status = ?, updated_at = NOW() WHERE id = ?')->execute([$mintStatus, $approvalRequestId]);
        if (ops_has_table($pdo, 'mint_queue')) {
            $queueStatus = $status['is_fully_backed'] ? 'prepared' : 'awaiting_asset_backing';
            $pdo->prepare('UPDATE mint_queue SET queue_status = ?, updated_at = NOW() WHERE approval_request_id = ?')->execute([$queueStatus, $approvalRequestId]);
        }
    }
}

if (!function_exists('ops_asset_backing_attach_to_execution_request')) {
    function ops_asset_backing_attach_to_execution_request(PDO $pdo, int $approvalRequestId, int $executionRequestId): array {
        $status = ops_asset_backing_status_for_approval($pdo, $approvalRequestId);
        if (!$status['required']) return ['required' => false, 'attached_ids' => []];
        if (!$status['is_fully_backed']) {
            throw new RuntimeException('This stewardship class cannot move into execution until enough live asset backing has been allocated.');
        }
        if (!ops_has_table($pdo, 'stewardship_backing_allocations')) return ['required' => true, 'attached_ids' => []];
        $ids = ops_fetch_all($pdo, "SELECT id FROM stewardship_backing_allocations WHERE approval_request_id = ? AND allocation_status IN ('reserved','mint_ready') ORDER BY id ASC", [$approvalRequestId]);
        $idList = array_map(fn($r) => (int)$r['id'], $ids);
        if ($idList) {
            $place = implode(',', array_fill(0, count($idList), '?'));
            $params = array_merge([$executionRequestId], $idList);
            $pdo->prepare("UPDATE stewardship_backing_allocations SET execution_request_id = ?, allocation_status = 'mint_ready', updated_at = NOW() WHERE id IN ($place)")->execute($params);
        }
        ops_asset_backing_sync_approval_state($pdo, $approvalRequestId);
        return ['required' => true, 'attached_ids' => $idList];
    }
}

if (!function_exists('ops_asset_backing_upsert_position_for_allocation')) {
    function ops_asset_backing_upsert_position_for_allocation(PDO $pdo, array $alloc): ?int {
        if (!ops_has_table($pdo, 'stewardship_positions')) return null;
        $partnerId = (int)($alloc['partner_id'] ?? 0);
        $tokenClassId = (int)($alloc['token_class_id'] ?? 0);
        if ($partnerId <= 0 || $tokenClassId <= 0) return null;
        $positionType = (($alloc['backing_source_type'] ?? '') === 'rwa_valuation') ? 'rwa_stewardship' : 'asx_stewardship';
        $resourceId = null;
        if ($positionType === 'rwa_stewardship' && !empty($alloc['resource_valuation_record_id']) && ops_has_table($pdo, 'resource_valuation_records')) {
            $resourceId = (int)ops_fetch_val($pdo, 'SELECT resource_id FROM resource_valuation_records WHERE id = ? LIMIT 1', [(int)$alloc['resource_valuation_record_id']]);
        }
        $existing = $resourceId
            ? ops_fetch_one($pdo, 'SELECT * FROM stewardship_positions WHERE partner_id = ? AND token_class_id = ? AND resource_id = ? AND position_type = ? LIMIT 1', [$partnerId, $tokenClassId, $resourceId, $positionType])
            : ops_fetch_one($pdo, 'SELECT * FROM stewardship_positions WHERE partner_id = ? AND token_class_id = ? AND position_type = ? AND (resource_id IS NULL OR resource_id = 0) LIMIT 1', [$partnerId, $tokenClassId, $positionType]);
        if ($existing) {
            $newUnits = (float)($existing['units_held'] ?? 0) + (float)($alloc['allocated_units'] ?? 0);
            $pdo->prepare('UPDATE stewardship_positions SET units_held = ?, status = \'active\', updated_at = NOW() WHERE id = ?')->execute([$newUnits, (int)$existing['id']]);
            return (int)$existing['id'];
        }
        $positionKey = strtoupper(($positionType === 'rwa_stewardship' ? 'RWA' : 'ASX') . '-' . $partnerId . '-' . $tokenClassId . '-' . ($resourceId ?: 'GEN'));
        $pdo->prepare('INSERT INTO stewardship_positions (partner_id, token_class_id, resource_id, position_key, position_type, units_held, status, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, \'active\', NOW(), NOW())')
            ->execute([$partnerId, $tokenClassId, $resourceId ?: null, $positionKey, $positionType, (float)($alloc['allocated_units'] ?? 0)]);
        return (int)$pdo->lastInsertId();
    }
}

if (!function_exists('ops_asset_backing_mark_minted_for_batch')) {
    function ops_asset_backing_mark_minted_for_batch(PDO $pdo, int $executionBatchId): void {
        if ($executionBatchId <= 0 || !ops_has_table($pdo, 'execution_batch_items') || !ops_has_table($pdo, 'stewardship_backing_allocations')) return;
        $allocs = ops_fetch_all($pdo, "SELECT sba.*
            FROM stewardship_backing_allocations sba
            INNER JOIN execution_batch_items ebi ON ebi.execution_request_id = sba.execution_request_id
            WHERE ebi.execution_batch_id = ?
              AND sba.allocation_status IN ('reserved','mint_ready')", [$executionBatchId]);
        foreach ($allocs as $alloc) {
            $positionId = ops_asset_backing_upsert_position_for_allocation($pdo, $alloc);
            $pdo->prepare('UPDATE stewardship_backing_allocations SET allocation_status = \'minted\', stewardship_position_id = COALESCE(?, stewardship_position_id), updated_at = NOW() WHERE id = ?')
                ->execute([$positionId, (int)$alloc['id']]);
            if (!empty($alloc['approval_request_id'])) {
                ops_asset_backing_sync_approval_state($pdo, (int)$alloc['approval_request_id']);
            }
        }
    }
}

// =============================================================================
// ASX TRADE DOCUMENT VAULT
// PDF evidence pipeline: upload → SHA-256 hash → evidence vault → chain attestation
// =============================================================================

if (!function_exists('ops_asx_doc_storage_path')) {
    function ops_asx_doc_storage_path(): string {
        // Stored outside web root access — protected by .htaccess deny-all
        $base = rtrim(dirname(dirname(__DIR__)), '/') . '/_private/asx_docs';
        if (!is_dir($base)) {
            mkdir($base, 0750, true);
        }
        return $base . '/';
    }
}

if (!function_exists('ops_asx_doc_ref')) {
    function ops_asx_doc_ref(): string {
        return 'ASXDOC-' . date('YmdHis') . '-' . strtoupper(substr(bin2hex(random_bytes(4)), 0, 8));
    }
}

if (!function_exists('ops_store_asx_trade_document')) {
    /**
     * Full upload pipeline for an ASX trade PDF.
     * Call after validating $_FILES['trade_document'].
     *
     * Returns the new asx_trade_documents.id on success.
     * Throws RuntimeException on any failure.
     *
     * @param PDO    $pdo
     * @param int    $holdingId     asx_holdings.id
     * @param int    $adminUserId   admin_users.id
     * @param array  $file          $_FILES['trade_document'] element
     * @param string $docType       enum value from asx_trade_documents.document_type
     * @param int    $tradeId       asx_trades.id — 0 = covers whole holding
     * @param bool   $isLegacySeed  true = standalone chain attestation (never minted)
     * @param string $notes
     */
    function ops_store_asx_trade_document(
        PDO    $pdo,
        int    $holdingId,
        int    $adminUserId,
        array  $file,
        string $docType       = 'broker_confirmation',
        int    $tradeId       = 0,
        bool   $isLegacySeed  = false,
        string $notes         = ''
    ): int {
        // ── Validate ──────────────────────────────────────────────────────────
        if (empty($file['tmp_name']) || !is_uploaded_file($file['tmp_name'])) {
            throw new RuntimeException('No valid uploaded file received.');
        }
        $allowedMime = ['application/pdf', 'application/x-pdf'];
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $detectedMime = finfo_file($finfo, $file['tmp_name']);
        finfo_close($finfo);
        if (!in_array($detectedMime, $allowedMime, true)) {
            throw new RuntimeException('Only PDF files are accepted for ASX trade documents.');
        }
        $maxBytes = 20 * 1024 * 1024; // 20 MB
        if ((int)$file['size'] > $maxBytes) {
            throw new RuntimeException('PDF file exceeds the 20 MB size limit.');
        }

        // ── Hash the file before moving it ────────────────────────────────────
        $sha256 = hash_file('sha256', $file['tmp_name']);
        if (!$sha256) throw new RuntimeException('Could not generate SHA-256 hash of uploaded file.');

        // ── Duplicate check ───────────────────────────────────────────────────
        $existing = ops_fetch_val($pdo, 'SELECT id FROM asx_trade_documents WHERE sha256_hash = ? LIMIT 1', [$sha256]);
        if ($existing) {
            throw new RuntimeException('This exact file has already been uploaded (duplicate SHA-256 detected). Document ID: ' . (int)$existing);
        }

        // ── Store file ────────────────────────────────────────────────────────
        $docRef = ops_asx_doc_ref();
        $storedName = $docRef . '.pdf';
        $storagePath = ops_asx_doc_storage_path();
        if (!move_uploaded_file($file['tmp_name'], $storagePath . $storedName)) {
            throw new RuntimeException('Failed to store uploaded PDF. Check server permissions on _private/asx_docs/.');
        }

        // ── Insert document record ────────────────────────────────────────────
        $validDocTypes = ['broker_confirmation','chess_statement','ig_statement','asx_announcement','valuation','other'];
        if (!in_array($docType, $validDocTypes, true)) $docType = 'other';

        $pdo->prepare(
            'INSERT INTO asx_trade_documents
                (document_ref, holding_id, trade_id, document_type, original_filename,
                 stored_filename, file_size_bytes, mime_type, sha256_hash,
                 attestation_status, uploaded_by_admin_user_id, notes, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, \'uploaded\', ?, ?, NOW(), NOW())'
        )->execute([
            $docRef,
            $holdingId,
            $tradeId > 0 ? $tradeId : null,
            $docType,
            basename((string)($file['name'] ?? 'document.pdf')),
            $storedName,
            (int)$file['size'],
            $detectedMime,
            $sha256,
            $adminUserId ?: null,
            $notes !== '' ? $notes : null,
        ]);
        $docId = (int)$pdo->lastInsertId();

        // ── Anchor in evidence vault ──────────────────────────────────────────
        $holdingRow = ops_fetch_one($pdo, 'SELECT ticker, company_name FROM asx_holdings WHERE id = ? LIMIT 1', [$holdingId]);
        $tradeLabel = $tradeId > 0 ? ' lot #' . $tradeId : ' (holding-level)';
        $summary = 'ASX trade document for ' . ($holdingRow['ticker'] ?? 'UNKNOWN') . $tradeLabel
            . ' — ' . $docType . ' — ' . basename((string)($file['name'] ?? ''))
            . ($isLegacySeed ? ' [LEGACY SEED / TRUST FORMING PROPERTY]' : '');

        $pdo->prepare(
            'INSERT INTO evidence_vault_entries
                (entry_type, subject_type, subject_id, subject_ref, payload_hash,
                 payload_summary, source_system, created_by_type, created_by_id, created_at)
             VALUES (\'asx_trade_document\', \'asx_holding\', ?, ?, ?, ?, \'admin_upload\', \'admin\', ?, NOW())'
        )->execute([
            $holdingId,
            $holdingRow['ticker'] ?? ('holding_' . $holdingId),
            $sha256,
            $summary,
            $adminUserId ?: null,
        ]);
        $vaultId = (int)$pdo->lastInsertId();

        $pdo->prepare(
            'UPDATE asx_trade_documents SET evidence_vault_id = ?, attestation_status = \'vault_anchored\', updated_at = NOW() WHERE id = ?'
        )->execute([$vaultId, $docId]);

        // ── Standalone chain handoff for legacy seed (trust forming property) ─
        if ($isLegacySeed && ops_has_table($pdo, 'chain_handoffs')) {
            $handoffCode = 'LEGACY-SEED-' . $docRef;
            $exportPayload = json_encode([
                'type'            => 'legacy_seed_trust_property',
                'document_ref'    => $docRef,
                'holding_ticker'  => $holdingRow['ticker'] ?? '',
                'sha256_hash'     => $sha256,
                'evidence_vault_id' => $vaultId,
                'note'            => 'Initial trust forming property — never minted into tokens. Permanent on-chain evidence record.',
                'created_at'      => date('c'),
            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

            $pdo->prepare(
                'INSERT INTO chain_handoffs
                    (mint_batch_id, handoff_code, chain_target, ledger_target,
                     handoff_status, export_hash, export_payload_json,
                     prepared_by_admin_id, notes, created_at, updated_at)
                 VALUES (0, ?, \'besu-prep\', \'phase1-parallel\', \'prepared\', ?, ?, ?, ?, NOW(), NOW())'
            )->execute([
                $handoffCode,
                $sha256,
                $exportPayload,
                $adminUserId ?: null,
                'Standalone attestation for legacy seed trust property document.',
            ]);
            $handoffId = (int)$pdo->lastInsertId();

            $pdo->prepare(
                'UPDATE asx_trade_documents SET chain_handoff_id = ?, attestation_status = \'chain_pending\', updated_at = NOW() WHERE id = ?'
            )->execute([$handoffId, $docId]);
        }

        return $docId;
    }
}

if (!function_exists('ops_get_asx_trade_document_hashes')) {
    /**
     * Returns an array of document hash records for a given set of asx_trades.id values.
     * Used by the execution pipeline to embed document hashes in payload_json.
     *
     * @param  PDO   $pdo
     * @param  int[] $tradeIds
     * @return array  [['document_ref'=>..., 'sha256_hash'=>..., 'evidence_vault_id'=>..., 'document_type'=>...], ...]
     */
    function ops_get_asx_trade_document_hashes(PDO $pdo, array $tradeIds): array {
        if (empty($tradeIds) || !ops_has_table($pdo, 'asx_trade_documents')) return [];
        $tradeIds = array_map('intval', $tradeIds);

        // Collect holding IDs for holding-level docs that cover these trades
        $holdingIds = array_unique(array_filter(array_map('intval', array_column(
            ops_fetch_all($pdo, 'SELECT DISTINCT holding_id FROM asx_trades WHERE id IN (' . implode(',', $tradeIds) . ')'),
            'holding_id'
        ))));

        if (empty($holdingIds)) return [];

        $tradePlaceholders   = implode(',', array_fill(0, count($tradeIds), '?'));
        $holdingPlaceholders = implode(',', array_fill(0, count($holdingIds), '?'));

        // Trade-specific docs + holding-level docs (trade_id IS NULL) for these holdings
        $params = array_merge($tradeIds, $holdingIds);
        $rows = ops_fetch_all($pdo,
            "SELECT document_ref, sha256_hash, evidence_vault_id, document_type, original_filename
               FROM asx_trade_documents
              WHERE attestation_status IN ('vault_anchored','chain_pending','chain_anchored')
                AND (
                      (trade_id IN ($tradePlaceholders))
                   OR (trade_id IS NULL AND holding_id IN ($holdingPlaceholders))
                    )
              ORDER BY created_at ASC",
            $params
        );

        return array_map(fn($r) => [
            'document_ref'      => (string)$r['document_ref'],
            'sha256_hash'       => (string)$r['sha256_hash'],
            'evidence_vault_id' => (int)($r['evidence_vault_id'] ?? 0),
            'document_type'     => (string)$r['document_type'],
            'original_filename' => (string)$r['original_filename'],
        ], $rows);
    }
}

if (!function_exists('ops_get_asx_documents_for_holding')) {
    /**
     * Returns all documents for a holding, for Admin display.
     */
    function ops_get_asx_documents_for_holding(PDO $pdo, int $holdingId): array {
        if (!ops_has_table($pdo, 'asx_trade_documents')) return [];
        return ops_fetch_all($pdo,
            'SELECT d.*, t.trade_ref, t.trade_date, t.units AS trade_units
               FROM asx_trade_documents d
               LEFT JOIN asx_trades t ON t.id = d.trade_id
              WHERE d.holding_id = ?
              ORDER BY d.created_at DESC',
            [$holdingId]
        );
    }
}

if (!function_exists('ops_member_kyc_map')) {
    /**
     * Returns a map of members.id → KYC display data for a batch of member IDs.
     * Used by members.php to display KYC status in the member detail panel.
     * Joins members → snft_memberships via member_number, then fetches
     * submission detail from kyc_medicare_submissions where available.
     *
     * @param  PDO   $pdo
     * @param  int[] $memberIds  Array of members.id values
     * @return array<int, array>  Keyed by members.id
     */
    function ops_member_kyc_map(PDO $pdo, array $memberIds): array
    {
        $memberIds = array_values(array_filter(array_map('intval', $memberIds)));
        if (empty($memberIds)) return [];
        if (!ops_has_table($pdo, 'snft_memberships')) return [];

        $statusLabels = [
            'pending'      => 'Pending admin review',
            'under_review' => 'Under review',
            'verified'     => 'Verified',
            'rejected'     => 'Verification rejected',
            'none'         => 'Not submitted',
        ];
        $statusTones = [
            'verified'     => 'ok',
            'pending'      => 'warn',
            'under_review' => 'warn',
            'rejected'     => 'bad',
            'none'         => 'bad',
        ];

        $result = [];

        try {
            // Join members → snft_memberships via member_number to get KYC fields
            $ph   = implode(',', array_fill(0, count($memberIds), '?'));
            $rows = $pdo->prepare("
                SELECT m.id          AS member_id,
                       sm.kyc_status,
                       sm.kyc_method,
                       sm.kyc_verified_at,
                       sm.kyc_submission_id
                FROM members m
                JOIN snft_memberships sm ON sm.member_number = m.member_number
                WHERE m.id IN ($ph)
            ");
            $rows->execute($memberIds);
            $snftRows = $rows->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $e) {
            return [];
        }

        // Batch-fetch submission detail for all linked submission IDs
        $submissionIds = array_filter(array_column($snftRows, 'kyc_submission_id'));
        $submissions   = [];
        if (!empty($submissionIds) && ops_has_table($pdo, 'kyc_medicare_submissions')) {
            try {
                $sph  = implode(',', array_fill(0, count($submissionIds), '?'));
                $ssmt = $pdo->prepare("
                    SELECT id, status, verified_at,
                           (SELECT action FROM kyc_review_log
                            WHERE submission_id = kyc_medicare_submissions.id
                            ORDER BY created_at DESC LIMIT 1) AS latest_review_action,
                           (SELECT created_at FROM kyc_review_log
                            WHERE submission_id = kyc_medicare_submissions.id
                            ORDER BY created_at DESC LIMIT 1) AS latest_review_at
                    FROM kyc_medicare_submissions
                    WHERE id IN ($sph)
                ");
                $ssmt->execute(array_values($submissionIds));
                foreach ($ssmt->fetchAll(PDO::FETCH_ASSOC) as $sub) {
                    $submissions[(int)$sub['id']] = $sub;
                }
            } catch (Throwable $e) {}
        }

        foreach ($snftRows as $snft) {
            $memberId    = (int)$snft['member_id'];
            $status      = (string)($snft['kyc_status'] ?? '');
            $subId       = !empty($snft['kyc_submission_id']) ? (int)$snft['kyc_submission_id'] : null;
            $sub         = $subId ? ($submissions[$subId] ?? null) : null;

            $result[$memberId] = [
                'status'               => $status ?: 'none',
                'status_label'         => $statusLabels[$status] ?? ($status ? ucwords(str_replace('_', ' ', $status)) : 'Not submitted'),
                'status_tone'          => $statusTones[$status]  ?? 'bad',
                'submission_id'        => $subId,
                'submission_status'    => $sub ? (string)$sub['status'] : null,
                'kyc_method'           => !empty($snft['kyc_method'])       ? (string)$snft['kyc_method']       : null,
                'kyc_verified_at'      => !empty($snft['kyc_verified_at'])  ? (string)$snft['kyc_verified_at']  : null,
                'submission_verified_at' => ($sub && !empty($sub['verified_at'])) ? (string)$sub['verified_at'] : null,
                'latest_review_action' => $sub ? ($sub['latest_review_action'] ?? null) : null,
                'latest_review_at'     => $sub ? ($sub['latest_review_at']     ?? null) : null,
            ];
        }

        return $result;
    }
}
