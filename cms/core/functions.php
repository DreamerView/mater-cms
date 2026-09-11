<?php
function e(?string $value): string { return htmlspecialchars((string)$value, ENT_QUOTES|ENT_SUBSTITUTE, 'UTF-8'); }

function cms_config(?string $key=null): mixed
{
    static $config;
    $config ??= require __DIR__ . '/../config.php';
    return $key===null ? $config : ($config[$key] ?? null);
}


function cms_language_catalog(): array
{
    static $languages;
    if ($languages === null) {
        $loaded = require __DIR__ . '/languages.php';
        $languages = is_array($loaded) ? $loaded : [];
    }
    return $languages;
}

function cms_i18n_settings(PDO $pdo, ?int $projectId = null): array
{
    $defaults = ['enabled' => false, 'default_language' => 'ru', 'languages' => ['ru']];
    try {
        if ($projectId !== null && $projectId > 0) {
            $keyColumn = Database::quoteIdentifier($pdo, 'key');
            $stmt = $pdo->prepare("SELECT value_json FROM project_settings WHERE project_id=? AND $keyColumn=? LIMIT 1");
            $stmt->execute([$projectId, 'i18n']);
        } else {
            $keyColumn = Database::quoteIdentifier($pdo, 'key');
            $stmt = $pdo->prepare("SELECT value_json FROM cms_settings WHERE $keyColumn=? LIMIT 1");
            $stmt->execute(['i18n']);
        }
        $raw = $stmt->fetchColumn();
        $value = is_string($raw) ? json_decode($raw, true) : null;
        if (!is_array($value)) $value = $defaults;
    } catch (Throwable) {
        return $defaults;
    }
    $catalog = cms_language_catalog();
    $selected = [];
    foreach ((array)($value['languages'] ?? []) as $code) {
        $code = strtolower(trim((string)$code));
        if (isset($catalog[$code])) $selected[$code] = true;
    }
    if (!$selected) $selected['ru'] = true;
    $languages = array_keys($selected);
    $default = strtolower(trim((string)($value['default_language'] ?? 'ru')));
    if (!in_array($default, $languages, true)) $default = $languages[0];
    return [
        'enabled' => filter_var($value['enabled'] ?? false, FILTER_VALIDATE_BOOLEAN),
        'default_language' => $default,
        'languages' => $languages,
    ];
}

function save_cms_i18n_settings(PDO $pdo, array $value, ?array $project = null): array
{
    $projectId = $project ? (int)$project['id'] : null;
    $previous = cms_i18n_settings($pdo, $projectId);
    $catalog = cms_language_catalog();
    $selected = [];
    foreach ((array)($value['languages'] ?? []) as $code) {
        $code = strtolower(trim((string)$code));
        if (isset($catalog[$code])) $selected[$code] = true;
    }
    if (!$selected) $selected['ru'] = true;
    $languages = array_keys($selected);
    $default = strtolower(trim((string)($value['default_language'] ?? '')));
    if (!in_array($default, $languages, true)) $default = $languages[0];
    $normalized = [
        'enabled' => filter_var($value['enabled'] ?? false, FILTER_VALIDATE_BOOLEAN),
        'default_language' => $default,
        'languages' => $languages,
    ];

    $ownsTransaction = !$pdo->inTransaction();
    if ($ownsTransaction) $pdo->beginTransaction();
    try {
        $oldDefault = (string)($previous['default_language'] ?? 'ru');
        if ($oldDefault !== $default) {
            if ($project) {
                $ids = ProjectAccess::folderIds($pdo, $project, true);
                if ($ids) {
                    $placeholders = implode(',', array_fill(0, count($ids), '?'));
                    $stmt = $pdo->prepare("SELECT id,data_json FROM documents WHERE folder_id IN ($placeholders)");
                    $stmt->execute($ids);
                    $documents = $stmt->fetchAll();
                } else $documents = [];
            } else {
                $documents = $pdo->query('SELECT id,data_json FROM documents')->fetchAll();
            }
            $documentIds = [];
            foreach ($documents as $doc) {
                $documentId = (int)$doc['id'];
                $documentIds[] = $documentId;
                $oldBase = (string)$doc['data_json'];
                $stmt = $pdo->prepare('SELECT data_json FROM document_translations WHERE document_id=? AND language=? LIMIT 1');
                $stmt->execute([$documentId, $default]);
                $newBase = $stmt->fetchColumn();
                if (!is_string($newBase) || $newBase === '') $newBase = $oldBase;

                Database::upsert($pdo, 'document_translations',
                    ['document_id'=>$documentId,'language'=>$oldDefault],
                    ['data_json'=>$oldBase,'updated_at'=>gmdate('Y-m-d H:i:s')]
                );
                $pdo->prepare('UPDATE documents SET data_json=?,updated_at=CURRENT_TIMESTAMP WHERE id=?')->execute([$newBase, $documentId]);
                $pdo->prepare('DELETE FROM document_translations WHERE document_id=? AND language=?')->execute([$documentId, $default]);
            }

            if ($documentIds) {
                $ph = implode(',', array_fill(0, count($documentIds), '?'));
                $tmp = '__default__';
                $stmt = $pdo->prepare("UPDATE revisions SET language=? WHERE language=? AND document_id IN ($ph)");
                $stmt->execute(array_merge([$tmp, ''], $documentIds));
                $stmt = $pdo->prepare("UPDATE revisions SET language=? WHERE language=? AND document_id IN ($ph)");
                $stmt->execute(array_merge(['', $default], $documentIds));
                $stmt = $pdo->prepare("UPDATE revisions SET language=? WHERE language=? AND document_id IN ($ph)");
                $stmt->execute(array_merge([$oldDefault, $tmp], $documentIds));
            }
        }

        if ($projectId !== null && $projectId > 0) {
            Database::upsert($pdo, 'project_settings',
                ['project_id'=>$projectId,'key'=>'i18n'],
                ['value_json'=>json_encode($normalized, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)]
            );
        } else {
            Database::upsert($pdo, 'cms_settings',
                ['key'=>'i18n'],
                ['value_json'=>json_encode($normalized, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)]
            );
        }
        if ($ownsTransaction) $pdo->commit();
    } catch (Throwable $e) {
        if ($ownsTransaction && $pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
    return $normalized;
}


function project_api_access_record(PDO $pdo, int $projectId): array
{
    $defaults = [
        'mode' => 'public',
        'token_hash' => '',
        'token_prefix' => '',
        'created_at' => null,
    ];
    try {
        $keyColumn = Database::quoteIdentifier($pdo, 'key');
            $stmt = $pdo->prepare("SELECT value_json FROM project_settings WHERE project_id=? AND $keyColumn=? LIMIT 1");
        $stmt->execute([$projectId, 'api_access']);
        $raw = $stmt->fetchColumn();
        $value = is_string($raw) ? json_decode($raw, true) : null;
        if (!is_array($value)) $value = $defaults;
    } catch (Throwable) {
        return $defaults;
    }
    $mode = (string)($value['mode'] ?? 'public') === 'private' ? 'private' : 'public';
    return [
        'mode' => $mode,
        'token_hash' => $mode === 'private' ? trim((string)($value['token_hash'] ?? '')) : '',
        'token_prefix' => $mode === 'private' ? trim((string)($value['token_prefix'] ?? '')) : '',
        'created_at' => $value['created_at'] ?? null,
    ];
}

function project_api_access(PDO $pdo, int $projectId): array
{
    $record = project_api_access_record($pdo, $projectId);
    return [
        'mode' => $record['mode'],
        'private' => $record['mode'] === 'private',
        'token_configured' => $record['mode'] === 'private' && $record['token_hash'] !== '',
        'token_prefix' => $record['token_prefix'],
        'created_at' => $record['created_at'],
        'auth_header' => $record['mode'] === 'private' ? 'Authorization: Bearer <MATERCMS_API_TOKEN>' : null,
    ];
}

function project_api_status_record(PDO $pdo, int $projectId): array
{
    $defaults = ['enabled'=>true,'updated_at'=>null];
    try {
        $keyColumn = Database::quoteIdentifier($pdo, 'key');
        $stmt = $pdo->prepare("SELECT value_json FROM project_settings WHERE project_id=? AND $keyColumn=? LIMIT 1");
        $stmt->execute([$projectId, 'api_status']);
        $raw = $stmt->fetchColumn();
        $value = is_string($raw) ? json_decode($raw, true) : null;
        if (!is_array($value)) $value = $defaults;
    } catch (Throwable) {
        return $defaults;
    }
    return [
        'enabled'=>filter_var($value['enabled'] ?? true, FILTER_VALIDATE_BOOLEAN),
        'updated_at'=>$value['updated_at'] ?? null,
    ];
}

function project_api_status(PDO $pdo, int $projectId): array
{
    $record = project_api_status_record($pdo, $projectId);
    return [
        'enabled'=>(bool)$record['enabled'],
        'state'=>$record['enabled'] ? 'enabled' : 'disabled',
        'updated_at'=>$record['updated_at'],
    ];
}

function save_project_api_status(PDO $pdo, int $projectId, bool $enabled): array
{
    $record = ['enabled'=>$enabled,'updated_at'=>gmdate('c')];
    Database::upsert(
        $pdo,
        'project_settings',
        ['project_id'=>$projectId,'key'=>'api_status'],
        ['value_json'=>json_encode($record, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)]
    );
    return project_api_status($pdo, $projectId);
}

function require_project_api_enabled(PDO $pdo, array $project): void
{
    if (project_api_status($pdo, (int)$project['id'])['enabled']) return;
    json_response([
        'error'=>'Not found',
        'code'=>'PROJECT_API_DISABLED',
        'message'=>'API этого проекта временно выключен.',
    ], 404);
}

function project_api_response_record(PDO $pdo, int $projectId): array
{
    $defaults = [
        'full_response' => false,
        'updated_at' => null,
    ];
    try {
        $keyColumn = Database::quoteIdentifier($pdo, 'key');
        $stmt = $pdo->prepare("SELECT value_json FROM project_settings WHERE project_id=? AND $keyColumn=? LIMIT 1");
        $stmt->execute([$projectId, 'api_response']);
        $raw = $stmt->fetchColumn();
        $value = is_string($raw) ? json_decode($raw, true) : null;
        if (!is_array($value)) $value = $defaults;
    } catch (Throwable) {
        return $defaults;
    }
    return [
        'full_response' => filter_var($value['full_response'] ?? false, FILTER_VALIDATE_BOOLEAN),
        'updated_at' => $value['updated_at'] ?? null,
    ];
}

function project_api_response(PDO $pdo, int $projectId): array
{
    $record = project_api_response_record($pdo, $projectId);
    return [
        'mode' => $record['full_response'] ? 'full' : 'data',
        'full_response' => (bool)$record['full_response'],
        'updated_at' => $record['updated_at'],
    ];
}

function save_project_api_response(PDO $pdo, int $projectId, bool $fullResponse): array
{
    $record = [
        'full_response' => $fullResponse,
        'updated_at' => gmdate('c'),
    ];
    Database::upsert(
        $pdo,
        'project_settings',
        ['project_id'=>$projectId,'key'=>'api_response'],
        ['value_json'=>json_encode($record, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)]
    );
    return project_api_response($pdo, $projectId);
}

function request_project_api_full_response(PDO $pdo, int $projectId): bool
{
    $full = project_api_response($pdo, $projectId)['full_response'];
    if (!array_key_exists('meta', $_GET)) return (bool)$full;

    $override = strtolower(trim((string)($_GET['meta'] ?? '')));
    if (in_array($override, ['1','true','yes','full'], true)) return true;
    if (in_array($override, ['0','false','no','data','compact'], true)) return false;
    return (bool)$full;
}


/**
 * Folder Tree API keeps only one per-folder option: cache TTL.
 * The project response mode decides payload weight:
 * compact => recursive structure without section data,
 * full    => recursive structure with complete section data + meta.
 */
function folder_api_tree_settings(array $folder, bool $includeData = false): array
{
    $ttl = max(0, min(3600, (int)($folder['api_tree_cache_ttl'] ?? 60)));
    return [
        'include_data' => $includeData,
        'cache_ttl' => $ttl,
        'cache_enabled' => $ttl > 0,
    ];
}

function project_api_tree_revision(PDO $pdo, int $projectId): string
{
    try {
        $keyColumn = Database::quoteIdentifier($pdo, 'key');
        $stmt = $pdo->prepare("SELECT value_json FROM project_settings WHERE project_id=? AND $keyColumn=? LIMIT 1");
        $stmt->execute([$projectId, 'api_tree_revision']);
        $raw = $stmt->fetchColumn();
        $value = is_string($raw) ? json_decode($raw, true) : null;
        $token = trim((string)($value['token'] ?? ''));
        return $token !== '' ? $token : '1';
    } catch (Throwable) {
        return '1';
    }
}

/**
 * Invalidate all prepared folder-tree JSON after any admin mutation. This is
 * intentionally broad: writes are comparatively rare, while it guarantees a
 * tree never serves stale content after move/copy/translation/API changes.
 */
function bump_all_project_api_tree_revisions(PDO $pdo): void
{
    if (!Database::tableExists($pdo, 'projects') || !Database::tableExists($pdo, 'project_settings')) return;
    $projectIds = $pdo->query('SELECT id FROM projects ORDER BY id')->fetchAll(PDO::FETCH_COLUMN);
    foreach ($projectIds as $projectId) {
        try {
            $token = bin2hex(random_bytes(10));
        } catch (Throwable) {
            $token = str_replace('.', '', uniqid('', true));
        }
        Database::upsert(
            $pdo,
            'project_settings',
            ['project_id'=>(int)$projectId,'key'=>'api_tree_revision'],
            ['value_json'=>json_encode(['token'=>$token,'updated_at'=>gmdate('c')], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)]
        );
    }

    // File fallback cache is cheap to clear on writes and this prevents stale
    // cache files from accumulating forever. APCu entries expire by their TTL.
    $dir = api_tree_cache_directory();
    if (is_dir($dir)) {
        foreach (glob($dir . '/*.json') ?: [] as $file) @unlink($file);
    }
}

function api_tree_cache_directory(): string
{
    // Keep cached private JSON outside the web root whenever possible. This is
    // important on nginx where cms/data/.htaccess is not evaluated.
    $base = rtrim((string)sys_get_temp_dir(), DIRECTORY_SEPARATOR);
    $namespace = substr(hash('sha256', dirname(__DIR__)), 0, 16);
    return $base . DIRECTORY_SEPARATOR . 'matercms-api-tree-' . $namespace;
}

function api_tree_apcu_available(): bool
{
    if (!function_exists('apcu_fetch') || !function_exists('apcu_store')) return false;
    $enabled = ini_get('apc.enabled');
    return $enabled === false || filter_var($enabled, FILTER_VALIDATE_BOOLEAN);
}

function api_tree_cache_get(string $key): ?array
{
    $hash = hash('sha256', $key);
    if (api_tree_apcu_available()) {
        $ok = false;
        $cached = apcu_fetch('feather_api_tree_' . $hash, $ok);
        if ($ok && is_array($cached)) return $cached;
    }

    $path = api_tree_cache_directory() . '/' . $hash . '.json';
    if (!is_file($path)) return null;
    $raw = @file_get_contents($path);
    $decoded = is_string($raw) ? json_decode($raw, true) : null;
    if (!is_array($decoded) || (int)($decoded['expires_at'] ?? 0) < time() || !is_array($decoded['payload'] ?? null)) {
        @unlink($path);
        return null;
    }
    return $decoded['payload'];
}

function api_tree_cache_put(string $key, array $payload, int $ttl): void
{
    $ttl = max(1, min(3600, $ttl));
    $hash = hash('sha256', $key);
    if (api_tree_apcu_available()) @apcu_store('feather_api_tree_' . $hash, $payload, $ttl);

    $dir = api_tree_cache_directory();
    if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) return;
    $path = $dir . '/' . $hash . '.json';
    $tmp = $path . '.' . bin2hex(random_bytes(4)) . '.tmp';
    $json = json_encode(['expires_at'=>time()+$ttl,'payload'=>$payload], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
    if (!is_string($json) || @file_put_contents($tmp, $json, LOCK_EX) === false) return;
    @chmod($tmp, 0640);
    if (!@rename($tmp, $path)) @unlink($tmp);
}

function api_tree_cache_key(PDO $pdo, array $project, array $folder, string $language, array $settings): string
{
    return implode('|', [
        'v3',
        (string)$project['id'],
        (string)$folder['id'],
        $language,
        project_api_tree_revision($pdo, (int)$project['id']),
        !empty($settings['include_data']) ? 'full-content' : 'structure-only',
        public_api_route_mode(),
        request_origin(),
        cms_base_path(),
    ]);
}

function feather_api_token(): string
{
    return 'fth_live_' . rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
}

function save_project_api_access(PDO $pdo, int $projectId, string $mode, ?string $token = null): array
{
    $mode = $mode === 'private' ? 'private' : 'public';
    if ($mode === 'public') {
        $record = ['mode' => 'public', 'token_hash' => '', 'token_prefix' => '', 'created_at' => null];
    } else {
        $token = $token ?: feather_api_token();
        $record = [
            'mode' => 'private',
            'token_hash' => hash('sha256', $token),
            'token_prefix' => substr($token, 0, 18),
            'created_at' => gmdate('c'),
        ];
    }
    Database::upsert($pdo, 'project_settings',
        ['project_id'=>$projectId,'key'=>'api_access'],
        ['value_json'=>json_encode($record, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)]
    );
    return ['access' => project_api_access($pdo, $projectId), 'token' => $mode === 'private' ? $token : null];
}

function request_project_api_token(): string
{
    $token = trim((string)($_SERVER['HTTP_X_MATER_TOKEN'] ?? $_SERVER['HTTP_X_FEATHER_TOKEN'] ?? ''));
    if ($token !== '') return $token;

    $authorization = trim((string)($_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? ''));
    if ($authorization === '' && function_exists('getallheaders')) {
        $headers = getallheaders();
        if (is_array($headers)) {
            foreach ($headers as $name => $value) {
                if (strcasecmp((string)$name, 'Authorization') === 0) {
                    $authorization = trim((string)$value);
                    break;
                }
                if ((strcasecmp((string)$name, 'X-MaterCMS-Token') === 0 || strcasecmp((string)$name, 'X-Mater-Token') === 0 || strcasecmp((string)$name, 'X-Feather-Token') === 0) && $token === '') {
                    $token = trim((string)$value);
                }
            }
        }
    }
    if ($token !== '') return $token;
    if (preg_match('/^Bearer\s+(.+)$/i', $authorization, $matches)) return trim((string)$matches[1]);
    return '';
}

function project_api_token_valid(PDO $pdo, int $projectId, ?string $token = null): bool
{
    $record = project_api_access_record($pdo, $projectId);
    if ($record['mode'] !== 'private' || $record['token_hash'] === '') return false;
    $token = trim((string)($token ?? request_project_api_token()));
    if ($token === '') return false;
    return hash_equals((string)$record['token_hash'], hash('sha256', $token));
}

function require_project_api_access(PDO $pdo, array $project): void
{
    $record = project_api_access_record($pdo, (int)$project['id']);
    if ($record['mode'] !== 'private') return;
    if (project_api_token_valid($pdo, (int)$project['id'])) return;

    header('WWW-Authenticate: Bearer realm="MaterCMS", charset="UTF-8"');
    json_response([
        'error' => 'Unauthorized',
        'code' => 'PROJECT_API_TOKEN_REQUIRED',
        'message' => 'API этого проекта приватный. Передайте секретный токен в заголовке Authorization: Bearer <token>.',
    ], 401);
}


function cms_base_path(): string
{
    static $base;
    if ($base !== null) return $base;

    // REQUEST_URI is more reliable than SCRIPT_NAME behind Apache/FastCGI rewrites.
    $requestPath = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?: '';
    $requestPath = str_replace('\\', '/', $requestPath);
    foreach (['/admin/','/api/'] as $needle) {
        $pos = strpos($requestPath, $needle);
        if ($pos !== false) return $base = rtrim(substr($requestPath, 0, $pos), '/');
    }
    foreach (['/admin','/api'] as $suffix) {
        if (str_ends_with(rtrim($requestPath, '/'), $suffix)) {
            return $base = rtrim(substr(rtrim($requestPath, '/'), 0, -strlen($suffix)), '/');
        }
    }

    $script = str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? '');
    foreach (['/admin/','/api/'] as $needle) {
        $pos = strpos($script, $needle);
        if ($pos !== false) return $base = rtrim(substr($script, 0, $pos), '/');
    }

    $candidate = $requestPath !== '' ? $requestPath : $script;
    if (str_ends_with($candidate, '/index.php')) $candidate = substr($candidate, 0, -10);
    elseif (!str_ends_with($candidate, '/')) $candidate = dirname($candidate);
    return $base = rtrim($candidate, '/.');
}

function redirect_cms(string $suffix=''): never
{
    $location = rtrim(cms_base_path(), '/') . '/';
    if ($suffix !== '') {
        $location .= str_starts_with($suffix, '?') ? $suffix : ltrim($suffix, '/');
    }
    header('Location: ' . $location);
    exit;
}
function url(string $path=''): string { return cms_base_path() . '/' . ltrim($path,'/'); }

/**
 * Public API routing mode.
 *
 * Apache can consume cms/.htaccess and therefore supports clean rewritten
 * URLs such as /cms/api/main/home/hero. Nginx ignores .htaccess, so the same
 * URL would be handled by nginx itself and normally return 404 unless the
 * host has a custom rewrite rule. In auto mode MaterCMS uses a direct
 * api/index.php?path=... route on nginx so the public API works out of the box.
 *
 * config.php may optionally set api_route_mode to: auto | pretty | direct.
 */
function public_api_route_mode(): string
{
    $configured = strtolower(trim((string)(cms_config('api_route_mode') ?? 'auto')));
    if (in_array($configured, ['pretty','direct'], true)) return $configured;

    $software = strtolower((string)($_SERVER['SERVER_SOFTWARE'] ?? ''));
    // Only Apache/LiteSpeed are assumed to consume .htaccess rewrite rules.
    // Nginx, Caddy, PHP's built-in server and unknown servers use the direct
    // query route so MaterCMS never depends on host-level rewrite config.
    if (str_contains($software, 'apache') || str_contains($software, 'litespeed')) return 'pretty';
    return 'direct';
}

function public_api_path(string $path=''): string
{
    $clean = trim($path, '/');
    $segments = $clean === '' ? [] : array_values(array_filter(explode('/', $clean), static fn($part) => $part !== ''));
    $encoded = implode('/', array_map('rawurlencode', $segments));

    if (public_api_route_mode() === 'direct') {
        $base = url('api/index.php');
        return $encoded === '' ? $base : $base . '?path=' . $encoded;
    }

    return url('api/' . ($encoded === '' ? '' : $encoded));
}

function public_api_absolute_url(string $path=''): string
{
    return absolute_url(public_api_path($path));
}
function request_origin(): string
{
    $forwardedProto = strtolower(trim((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')));
    $scheme = ($forwardedProto === 'https' || (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')) ? 'https' : 'http';
    $host = trim((string)($_SERVER['HTTP_HOST'] ?? 'localhost'));
    if (!preg_match('/^[A-Za-z0-9.\-:\[\]]+$/', $host)) $host = 'localhost';
    return $scheme . '://' . $host;
}
function absolute_url(string $path=''): string
{
    if (preg_match('~^https?://~i', $path)) return $path;
    $resolved = str_starts_with($path, '/') ? $path : url($path);
    return rtrim(request_origin(), '/') . '/' . ltrim($resolved, '/');
}
function asset_url(string $path): string { $clean=ltrim($path,'/'); $file=__DIR__.'/../'.$clean; $v=is_file($file)?(string)filemtime($file):'1'; return url($clean).'?v='.$v; }
function redirect(string $path): never { header('Location: ' . (str_starts_with($path,'http')?$path:url($path))); exit; }

function csrf_token(): string
{
    if (empty($_SESSION['_csrf'])) $_SESSION['_csrf'] = bin2hex(random_bytes(24));
    return $_SESSION['_csrf'];
}
function csrf_field(): string { return '<input type="hidden" name="_csrf" value="'.e(csrf_token()).'">'; }
function verify_csrf(): void
{
    $token = (string)($_POST['_csrf'] ?? '');
    if (!$token || !hash_equals($_SESSION['_csrf'] ?? '', $token)) { http_response_code(419); exit('CSRF token mismatch'); }
}

function slugify(string $value): string
{
    $value = trim(mb_strtolower($value));
    $map=['а'=>'a','б'=>'b','в'=>'v','г'=>'g','д'=>'d','е'=>'e','ё'=>'e','ж'=>'zh','з'=>'z','и'=>'i','й'=>'y','к'=>'k','л'=>'l','м'=>'m','н'=>'n','о'=>'o','п'=>'p','р'=>'r','с'=>'s','т'=>'t','у'=>'u','ф'=>'f','х'=>'h','ц'=>'c','ч'=>'ch','ш'=>'sh','щ'=>'sch','ъ'=>'','ы'=>'y','ь'=>'','э'=>'e','ю'=>'yu','я'=>'ya'];
    $value = strtr($value,$map);
    $value = preg_replace('/[^a-z0-9]+/u','-',$value) ?: '';
    return trim($value,'-') ?: 'item';
}

function unique_slug(PDO $pdo, string $table, string $name, ?int $parentId=null, ?int $ignoreId=null): string
{
    $base = slugify($name); $slug=$base; $i=2;
    while (true) {
        if ($table === 'folders') {
            $sql='SELECT id FROM folders WHERE slug=? AND '.($parentId===null?'parent_id IS NULL':'parent_id=?');
            $args=[$slug]; if ($parentId!==null) $args[]=$parentId;
        } else {
            $sql='SELECT id FROM documents WHERE slug=? AND '.($parentId===null?'folder_id IS NULL':'folder_id=?');
            $args=[$slug]; if ($parentId!==null) $args[]=$parentId;
        }
        if ($ignoreId!==null) { $sql.=' AND id<>?'; $args[]=$ignoreId; }
        $sql.=' LIMIT 1';
        $stmt=$pdo->prepare($sql); $stmt->execute($args);
        if (!$stmt->fetchColumn()) return $slug;
        $slug=$base.'-'.$i++;
    }
}


function feather_trash_slug(): string { return '__feather_trash__'; }

function feather_trash_root_id(PDO $pdo, array $project, bool $create=false): ?int
{
    $rootId=(int)($project['root_folder_id']??0);
    if($rootId<=0) return null;
    $stmt=$pdo->prepare('SELECT id FROM folders WHERE parent_id=? AND slug=? LIMIT 1');
    $stmt->execute([$rootId,feather_trash_slug()]);
    $id=$stmt->fetchColumn();
    if($id) return (int)$id;
    if(!$create) return null;
    $stmt=$pdo->prepare('INSERT INTO folders(parent_id,name,slug,sort_order) VALUES(?,?,?,?)');
    $stmt->execute([$rootId,'Корзина MaterCMS',feather_trash_slug(),2147483000]);
    return Database::lastInsertId($pdo);
}

function feather_trash_folder_ids(PDO $pdo, array $project, bool $create=false): array
{
    $trashRoot=feather_trash_root_id($pdo,$project,$create);
    if(!$trashRoot) return [];
    $ids=[];$queue=[$trashRoot];$guard=0;
    while($queue && $guard++<10000){
        $id=(int)array_shift($queue);
        if($id<=0||isset($ids[$id])) continue;
        $ids[$id]=true;
        $stmt=$pdo->prepare('SELECT id FROM folders WHERE parent_id=?');
        $stmt->execute([$id]);
        foreach($stmt->fetchAll(PDO::FETCH_COLUMN) as $child) $queue[]=(int)$child;
    }
    return array_map('intval',array_keys($ids));
}

function feather_visible_folder_ids(PDO $pdo, array $project): array
{
    $all=ProjectAccess::folderIds($pdo,$project,true);
    $trash=array_flip(feather_trash_folder_ids($pdo,$project,false));
    return array_values(array_filter($all,static fn($id)=>!isset($trash[(int)$id])));
}

function feather_folder_is_in_trash(PDO $pdo, array $project, ?int $folderId): bool
{
    if(!$folderId) return false;
    return in_array((int)$folderId,feather_trash_folder_ids($pdo,$project,false),true);
}


function feather_environment_report(): array
{
    $dataDir = __DIR__ . '/../data';
    $uploadDir = __DIR__ . '/../uploads';
    $drivers = class_exists(PDO::class) ? PDO::getAvailableDrivers() : [];
    $checks = [
        ['key'=>'php','label'=>'PHP 8.1+','ok'=>PHP_VERSION_ID >= 80100,'value'=>PHP_VERSION],
        ['key'=>'pdo','label'=>'PDO','ok'=>class_exists(PDO::class),'value'=>class_exists(PDO::class)?'Доступен':'Не установлен'],
        ['key'=>'driver','label'=>'PDO драйвер БД','ok'=>count($drivers)>0,'value'=>$drivers ? implode(', ', $drivers) : 'Нет драйверов'],
        ['key'=>'json','label'=>'JSON','ok'=>function_exists('json_encode'),'value'=>function_exists('json_encode')?'Доступен':'Нет'],
        ['key'=>'fileinfo','label'=>'Fileinfo','ok'=>function_exists('finfo_open'),'value'=>function_exists('finfo_open')?'Доступен':'Нет'],
        ['key'=>'data','label'=>'cms/data доступен для записи','ok'=>is_dir($dataDir)?is_writable($dataDir):is_writable(dirname($dataDir)),'value'=>$dataDir],
        ['key'=>'uploads','label'=>'cms/uploads доступен для записи','ok'=>is_dir($uploadDir)?is_writable($uploadDir):is_writable(dirname($uploadDir)),'value'=>$uploadDir],
    ];
    $optional = [
        ['key'=>'mbstring','label'=>'mbstring','ok'=>extension_loaded('mbstring'),'value'=>extension_loaded('mbstring')?'Оптимально':'Используется fallback'],
        ['key'=>'opcache','label'=>'OPcache','ok'=>extension_loaded('Zend OPcache'),'value'=>extension_loaded('Zend OPcache')?'Включён':'Необязательно'],
        ['key'=>'apcu','label'=>'APCu','ok'=>extension_loaded('apcu'),'value'=>extension_loaded('apcu')?'Доступен':'File cache fallback'],
    ];
    return [
        'ready'=>!array_filter($checks, static fn(array $check): bool => !$check['ok']),
        'checks'=>$checks,
        'optional'=>$optional,
        'php_os'=>PHP_OS_FAMILY,
    ];
}

function flash(string $type,string $message): void { $_SESSION['_flash'][]=['type'=>$type,'message'=>$message]; }
function pull_flashes(): array { $x=$_SESSION['_flash']??[]; unset($_SESSION['_flash']); return $x; }

function decode_json(?string $json): array { $x=json_decode((string)$json,true); return is_array($x)?$x:[]; }
function json_response(mixed $data,int $status=200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    $flags = JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_INVALID_UTF8_SUBSTITUTE;
    if ((string)($_GET['pretty'] ?? '') === '1') $flags |= JSON_PRETTY_PRINT;
    $json = json_encode($data, $flags);
    if ($json === false) {
        http_response_code(500);
        $json = '{"error":"JSON encode failed"}';
    }
    echo $json;
    exit;
}

function folder_path(PDO $pdo, ?int $folderId): array
{
    $parts=[]; $guard=0;
    while ($folderId && $guard++<50) {
        $stmt=$pdo->prepare('SELECT id,parent_id,name,slug FROM folders WHERE id=? LIMIT 1');
        $stmt->execute([$folderId]); $f=$stmt->fetch(); if(!$f) break;
        array_unshift($parts,$f); $folderId=$f['parent_id'] ? (int)$f['parent_id'] : null;
    }
    return $parts;
}

function document_api_path(PDO $pdo, array $doc): string
{
    $segments=array_map(fn($f)=>$f['slug'], folder_path($pdo, $doc['folder_id'] ? (int)$doc['folder_id'] : null));
    $segments[]=$doc['slug'];
    return implode('/',$segments);
}

function public_document_item(array $schema, array $values): array
{
    $out=[];
    foreach($schema as $field){
        $key=(string)($field['key']??''); if($key==='') continue;
        $type=$field['type']??'text'; $value=$values[$key]??null;
        if($type==='number' && $value!==null && $value!=='') $value=(float)$value;
        if($type==='boolean') $value=(bool)$value;
        if(in_array($type, upload_field_types(), true)) {
            if(is_array($value)) $value=array_values(array_map(static fn($item)=>is_string($item)&&$item!==''?absolute_url($item):$item,$value));
            elseif(is_string($value) && $value!=='') $value=absolute_url($value);
        }
        $out[$key]=$value;
    }
    return $out;
}

function public_document_data(array $doc): mixed
{
    $schema=decode_json($doc['schema_json']);
    $values=decode_json($doc['data_json']);
    $mode=(string)($doc['mode']??'single');

    if($mode==='multiple'){
        $out=[];
        foreach($values as $item){
            if(!is_array($item)) continue;
            $out[]=public_document_item($schema,$item);
        }
        return $out;
    }

    return (object)public_document_item($schema,$values);
}


function public_data_relation_target(PDO $pdo, int $projectId, int $sourceSetId): ?array
{
    static $cache = [];
    $key = $projectId . ':' . $sourceSetId;
    if (array_key_exists($key, $cache)) return $cache[$key];
    $stmt = $pdo->prepare("SELECT id,project_id,name,slug,mode,schema_json,data_json FROM data_sets WHERE id=? AND project_id=? AND mode='multiple' LIMIT 1");
    $stmt->execute([$sourceSetId, $projectId]);
    $row = $stmt->fetch();
    if (!$row) return $cache[$key] = null;
    $schema = decode_json($row['schema_json'] ?? '[]');
    $items = decode_json($row['data_json'] ?? '[]');
    $records = [];
    foreach ($items as $item) {
        if (!is_array($item)) continue;
        $id = (int)($item['_id'] ?? 0);
        if ($id > 0) $records[$id] = $item;
    }
    return $cache[$key] = ['row'=>$row,'schema'=>is_array($schema)?$schema:[],'records'=>$records];
}

function public_data_item(PDO $pdo, int $projectId, array $schema, array $values, int $relationDepth = 1): array
{
    $out = [];
    $recordId = (int)($values['_id'] ?? 0);
    if ($recordId > 0) $out['id'] = $recordId;

    foreach ($schema as $field) {
        if (!is_array($field)) continue;
        $key = (string)($field['key'] ?? '');
        if ($key === '') continue;
        $type = (string)($field['type'] ?? 'text');
        $value = $values[$key] ?? null;

        if ($type === 'relation') {
            $multiple = filter_var($field['relation_multiple'] ?? false, FILTER_VALIDATE_BOOLEAN);
            $sourceId = (int)($field['source_data_set_id'] ?? 0);
            $ids = is_array($value) ? $value : (($value === null || $value === '') ? [] : [$value]);
            $ids = array_values(array_unique(array_filter(array_map('intval',$ids), static fn($id)=>$id>0)));
            if ($relationDepth <= 0 || $sourceId <= 0) {
                $out[$key] = $multiple ? $ids : ($ids[0] ?? null);
                continue;
            }
            $source = public_data_relation_target($pdo, $projectId, $sourceId);
            if (!$source) {
                $out[$key] = $multiple ? [] : null;
                continue;
            }
            $resolved = [];
            foreach ($ids as $id) {
                $record = $source['records'][$id] ?? null;
                if (!is_array($record)) continue;
                $resolved[] = public_data_item($pdo, $projectId, $source['schema'], $record, $relationDepth - 1);
            }
            $out[$key] = $multiple ? $resolved : ($resolved[0] ?? null);
            continue;
        }

        if ($type === 'number' && $value !== null && $value !== '') $value = (float)$value;
        if ($type === 'boolean') $value = (bool)$value;
        if (in_array($type, upload_field_types(), true)) {
            if (is_array($value)) $value = array_values(array_map(static fn($item)=>is_string($item)&&$item!==''?absolute_url($item):$item,$value));
            elseif (is_string($value) && $value !== '') $value = absolute_url($value);
        }
        $out[$key] = $value;
    }
    return $out;
}

function public_data_set_data(PDO $pdo, array $set, int $projectId): mixed
{
    $schema = decode_json($set['schema_json'] ?? '[]');
    if (!is_array($schema)) $schema = [];
    $values = decode_json($set['data_json'] ?? (($set['mode'] ?? 'single') === 'multiple' ? '[]' : '{}'));
    if (($set['mode'] ?? 'single') === 'multiple') {
        $out = [];
        foreach ($values as $item) if (is_array($item)) $out[] = public_data_item($pdo, $projectId, $schema, $item, 1);
        return $out;
    }
    return (object)public_data_item($pdo, $projectId, $schema, is_array($values) ? $values : [], 1);
}

function upload_field_types(): array
{
    return ['image', 'video', 'audio', 'file'];
}

function upload_accept_for_type(string $type): string
{
    return match ($type) {
        'image' => 'image/jpeg,image/png,image/webp,image/gif,image/avif',
        'video' => 'video/mp4,video/webm,video/quicktime,video/x-matroska',
        'audio' => 'audio/mpeg,audio/wav,audio/x-wav,audio/ogg,audio/mp4,audio/aac,audio/flac',
        default => '.pdf,.txt,.md,.csv,.json,.xml,.doc,.docx,.xls,.xlsx,.ppt,.pptx,.odt,.ods,.odp,.zip,.rar,.7z,.gz,.tar,.fig,.sketch,.psd',
    };
}

function upload_kind_from_mime(string $mime): string
{
    if (str_starts_with($mime, 'image/')) return 'image';
    if (str_starts_with($mime, 'video/')) return 'video';
    if (str_starts_with($mime, 'audio/')) return 'audio';
    return 'file';
}

function upload_extension_allowed(string $type, string $extension, string $mime): bool
{
    $extension = mb_strtolower($extension);
    $denied = [
        'php','php3','php4','php5','php7','php8','phtml','pht','phar','cgi','pl','py','sh','bash','zsh',
        'htaccess','html','htm','xhtml','shtml','js','mjs','cjs','svg','exe','msi','bat','cmd','com','scr','dll',
    ];
    if ($extension === '' || in_array($extension, $denied, true)) return false;

    $byType = [
        'image' => ['jpg','jpeg','png','webp','gif','avif'],
        'video' => ['mp4','webm','mov','m4v','mkv'],
        'audio' => ['mp3','wav','ogg','oga','m4a','aac','flac'],
    ];
    if (isset($byType[$type])) {
        return in_array($extension, $byType[$type], true);
    }

    // Generic files are intentionally broad, but executable/web-script formats are denied above.
    // Also reject MIME types that would execute as active same-origin web content.
    if (in_array($mime, ['text/html','application/xhtml+xml','image/svg+xml','application/javascript','text/javascript'], true)) {
        return false;
    }
    return true;
}

function upload_asset(array $file, string $type = 'file'): string
{
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) return '';
    if (($file['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
        $code = (int)($file['error'] ?? UPLOAD_ERR_OK);
        $message = match ($code) {
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'Файл превышает лимит загрузки сервера.',
            UPLOAD_ERR_PARTIAL => 'Файл загрузился не полностью. Попробуйте ещё раз.',
            default => 'Не удалось загрузить файл.',
        };
        throw new RuntimeException($message);
    }

    $max = max(1, (int)(cms_config('upload_max_mb') ?? 100)) * 1024 * 1024;
    if ((int)($file['size'] ?? 0) > $max) {
        throw new RuntimeException('Файл слишком большой. Лимит MaterCMS: ' . (int)(cms_config('upload_max_mb') ?? 100) . ' МБ.');
    }

    $original = trim((string)($file['name'] ?? 'file'));
    $extension = mb_strtolower((string)pathinfo($original, PATHINFO_EXTENSION));
    $mime = 'application/octet-stream';
    try { $mime = (new finfo(FILEINFO_MIME_TYPE))->file((string)$file['tmp_name']) ?: $mime; } catch (Throwable) {}

    $type = in_array($type, upload_field_types(), true) ? $type : 'file';
    $detectedKind = upload_kind_from_mime($mime);
    if ($type !== 'file' && $detectedKind !== $type) {
        // Some servers report MP4 audio as video/mp4 or generic binary. Extension is used as a safe fallback below.
        $extensionFallback = [
            'image' => ['jpg','jpeg','png','webp','gif','avif'],
            'video' => ['mp4','webm','mov','m4v','mkv'],
            'audio' => ['mp3','wav','ogg','oga','m4a','aac','flac'],
        ];
        if (!in_array($extension, $extensionFallback[$type] ?? [], true)) {
            throw new RuntimeException('Выбранный файл не соответствует типу поля «' . match($type){'image'=>'Изображение','video'=>'Видео','audio'=>'Аудио',default=>'Файл'} . '».');
        }
    }

    if (!upload_extension_allowed($type, $extension, $mime)) {
        throw new RuntimeException('Этот формат файла не разрешён из соображений безопасности.');
    }

    $base = pathinfo($original, PATHINFO_FILENAME);
    $slug = slugify($base);
    if ($slug === 'item') $slug = $type === 'file' ? 'file' : $type;
    $name = $slug . '-' . bin2hex(random_bytes(5)) . '.' . $extension;
    $target = uploads_directory() . '/' . $name;
    if (!is_dir(uploads_directory())) mkdir(uploads_directory(), 0775, true);
    if (!move_uploaded_file((string)$file['tmp_name'], $target)) throw new RuntimeException('Не удалось сохранить файл на диск.');
    return url('uploads/' . $name);
}

// Backwards-compatible alias used by older integrations.
function upload_image(array $file): string
{
    return upload_asset($file, 'image');
}

function upload_filename_from_value(mixed $value): ?string
{
    if (!is_string($value) || $value === '') return null;
    $path = parse_url($value, PHP_URL_PATH);
    if (!is_string($path) || !str_contains($path, '/uploads/')) return null;
    $name = basename($path);
    if ($name === '' || $name === '.' || $name === '..') return null;
    if (!preg_match('/^[A-Za-z0-9._-]+$/', $name)) return null;
    return $name;
}

function collect_upload_filenames(mixed $value): array
{
    $found = [];
    $walk = function (mixed $node) use (&$walk, &$found): void {
        if (is_array($node)) {
            foreach ($node as $child) $walk($child);
            return;
        }
        $name = upload_filename_from_value($node);
        if ($name !== null) $found[$name] = true;
    };
    $walk($value);
    return array_keys($found);
}

function uploads_directory(): string
{
    return dirname(__DIR__) . '/uploads';
}

function unlink_upload_file(string $filename): void
{
    if ($filename === '' || basename($filename) !== $filename || !preg_match('/^[A-Za-z0-9._-]+$/', $filename)) return;
    $path = uploads_directory() . '/' . $filename;
    if (is_file($path)) @unlink($path);
}

function current_upload_references(PDO $pdo): array
{
    $references = [];
    $docs = $pdo->query('SELECT id,folder_id,name,data_json FROM documents ORDER BY name')->fetchAll();
    foreach ($docs as $doc) {
        foreach (collect_upload_filenames(decode_json($doc['data_json'] ?? '')) as $name) {
            $references[$name] ??= [];
            $references[$name]['doc:' . (int)$doc['id'] . ':default'] = [
                'document_id' => (int)$doc['id'],
                'document_name' => (string)$doc['name'],
                'folder_id' => $doc['folder_id'] === null ? null : (int)$doc['folder_id'],
                'source' => 'current',
                'language' => '',
            ];
        }
    }
    try {
        $translations = $pdo->query('SELECT t.document_id,t.language,t.data_json,d.folder_id,d.name AS document_name FROM document_translations t JOIN documents d ON d.id=t.document_id')->fetchAll();
        foreach ($translations as $row) {
            foreach (collect_upload_filenames(decode_json($row['data_json'] ?? '')) as $name) {
                $references[$name] ??= [];
                $references[$name]['doc:' . (int)$row['document_id'] . ':' . (string)$row['language']] = [
                    'document_id' => (int)$row['document_id'],
                    'document_name' => (string)$row['document_name'],
                    'folder_id' => $row['folder_id'] === null ? null : (int)$row['folder_id'],
                    'source' => 'current',
                    'language' => (string)$row['language'],
                ];
            }
        }
    } catch (Throwable) {}
    return $references;
}

function data_upload_references(PDO $pdo): array
{
    $references = [];
    try {
        $rows = $pdo->query('SELECT id,project_id,name,data_json FROM data_sets ORDER BY name')->fetchAll();
    } catch (Throwable) {
        return [];
    }
    foreach ($rows as $row) {
        foreach (collect_upload_filenames(decode_json($row['data_json'] ?? '')) as $name) {
            $references[$name] ??= [];
            $references[$name]['data:' . (int)$row['id']] = [
                'document_id' => null,
                'document_name' => null,
                'folder_id' => null,
                'form_id' => null,
                'form_name' => null,
                'submission_id' => null,
                'data_set_id' => (int)$row['id'],
                'data_set_name' => (string)$row['name'],
                'project_id' => (int)$row['project_id'],
                'source' => 'data',
            ];
        }
    }
    return $references;
}

function revision_upload_references(PDO $pdo): array
{
    $references = [];
    $rows = $pdo->query('SELECT r.document_id,r.data_json,d.folder_id,d.name AS document_name FROM revisions r JOIN documents d ON d.id=r.document_id ORDER BY r.id DESC')->fetchAll();
    foreach ($rows as $row) {
        foreach (collect_upload_filenames(decode_json($row['data_json'] ?? '')) as $name) {
            $references[$name] ??= [];
            $docId = (int)$row['document_id'];
            $references[$name][$docId] = [
                'document_id' => $docId,
                'document_name' => (string)$row['document_name'],
                'folder_id' => $row['folder_id'] === null ? null : (int)$row['folder_id'],
                'source' => 'history',
            ];
        }
    }
    return $references;
}

function referenced_upload_filenames(PDO $pdo, bool $includeRevisions = true): array
{
    $used = [];
    foreach (current_upload_references($pdo) as $name => $_refs) $used[$name] = true;
    if ($includeRevisions) {
        foreach (revision_upload_references($pdo) as $name => $_refs) $used[$name] = true;
    }
    foreach (form_submission_upload_references($pdo) as $name => $_refs) $used[$name] = true;
    foreach (data_upload_references($pdo) as $name => $_refs) $used[$name] = true;
    return $used;
}

function cleanup_orphan_uploads(PDO $pdo, array $candidates): void
{
    if (!$candidates) return;
    $used = referenced_upload_filenames($pdo, true);
    foreach (array_unique($candidates) as $name) {
        if (!is_string($name) || isset($used[$name])) continue;
        unlink_upload_file($name);
    }
}

function cleanup_all_orphan_uploads(PDO $pdo): int
{
    $dir = uploads_directory();
    if (!is_dir($dir)) return 0;
    $used = referenced_upload_filenames($pdo, true);
    $count = 0;
    foreach (scandir($dir) ?: [] as $name) {
        if ($name === '.' || $name === '..' || str_starts_with($name, '.')) continue;
        if (!is_file($dir . '/' . $name) || isset($used[$name])) continue;
        unlink_upload_file($name);
        $count++;
    }
    return $count;
}

function document_all_upload_filenames(PDO $pdo, int $documentId): array
{
    $found = [];
    $stmt = $pdo->prepare('SELECT data_json FROM documents WHERE id=?');
    $stmt->execute([$documentId]);
    if ($row = $stmt->fetch()) {
        foreach (collect_upload_filenames(decode_json($row['data_json'] ?? '')) as $name) $found[$name] = true;
    }
    $stmt = $pdo->prepare('SELECT data_json FROM document_translations WHERE document_id=?');
    $stmt->execute([$documentId]);
    foreach ($stmt->fetchAll() as $row) {
        foreach (collect_upload_filenames(decode_json($row['data_json'] ?? '')) as $name) $found[$name] = true;
    }
    $stmt = $pdo->prepare('SELECT data_json FROM revisions WHERE document_id=?');
    $stmt->execute([$documentId]);
    foreach ($stmt->fetchAll() as $row) {
        foreach (collect_upload_filenames(decode_json($row['data_json'] ?? '')) as $name) $found[$name] = true;
    }
    return array_keys($found);
}

function list_upload_files(PDO $pdo): array
{
    $current = current_upload_references($pdo);
    $history = revision_upload_references($pdo);
    $formRefsMap = form_submission_upload_references($pdo);
    $dataRefsMap = data_upload_references($pdo);
    $dir = uploads_directory();
    if (!is_dir($dir)) return [];

    $items = [];
    foreach (scandir($dir) ?: [] as $name) {
        if ($name === '.' || $name === '..' || str_starts_with($name, '.')) continue;
        $path = $dir . '/' . $name;
        if (!is_file($path)) continue;

        $currentRefs = array_values($current[$name] ?? []);
        $historyRefs = array_values($history[$name] ?? []);
        $formRefs = array_values($formRefsMap[$name] ?? []);
        $dataRefs = array_values($dataRefsMap[$name] ?? []);
        $allRefsMap = [];
        foreach (array_merge($currentRefs, $historyRefs) as $ref) {
            $allRefsMap['doc:' . (int)$ref['document_id']] = $ref;
        }
        foreach ($formRefs as $ref) {
            $allRefsMap['form:' . (int)$ref['form_id'] . ':' . (int)$ref['submission_id']] = $ref;
        }
        foreach ($dataRefs as $ref) {
            $allRefsMap['data:' . (int)$ref['data_set_id']] = $ref;
        }
        $allRefs = array_values($allRefsMap);

        $mime = 'application/octet-stream';
        try { $mime = (new finfo(FILEINFO_MIME_TYPE))->file($path) ?: $mime; } catch (Throwable) {}
        $kind = upload_kind_from_mime($mime);
        $extension = mb_strtolower((string)pathinfo($name, PATHINFO_EXTENSION));
        if ($kind === 'file') {
            if (in_array($extension, ['mp4','webm','mov','m4v','mkv'], true)) $kind = 'video';
            elseif (in_array($extension, ['mp3','wav','ogg','oga','m4a','aac','flac'], true)) $kind = 'audio';
        }

        $primary = $currentRefs[0] ?? $dataRefs[0] ?? $formRefs[0] ?? $historyRefs[0] ?? null;
        $items[] = [
            'name' => $name,
            'url' => url('uploads/' . rawurlencode($name)),
            'size' => (int)(filesize($path) ?: 0),
            'modified_at' => date('c', (int)(filemtime($path) ?: time())),
            'mime' => $mime,
            'extension' => $extension,
            'kind' => $kind,
            'is_image' => $kind === 'image',
            'is_video' => $kind === 'video',
            'is_audio' => $kind === 'audio',
            'usage_count' => count($currentRefs) + count($formRefs) + count($dataRefs),
            'history_usage_count' => count($historyRefs),
            'form_usage_count' => count($formRefs),
            'data_usage_count' => count($dataRefs),
            'reference_count' => count($allRefs),
            'history_only' => count($currentRefs) === 0 && count($formRefs) === 0 && count($dataRefs) === 0 && count($historyRefs) > 0,
            'document_id' => $primary['document_id'] ?? null,
            'document_name' => $primary['document_name'] ?? null,
            'form_id' => $primary['form_id'] ?? null,
            'form_name' => $primary['form_name'] ?? null,
            'submission_id' => $primary['submission_id'] ?? null,
            'data_set_id' => $primary['data_set_id'] ?? null,
            'data_set_name' => $primary['data_set_name'] ?? null,
            'references' => $allRefs,
        ];
    }

    usort($items, static fn(array $a, array $b): int => strcmp((string)$b['modified_at'], (string)$a['modified_at']));
    return $items;
}

function form_field_types(): array
{
    return ['text','textarea','email','phone','number','select','checkbox','date','file'];
}

function sanitize_form_schema(array $raw): array
{
    $allowed = form_field_types();
    $schema = [];
    $seen = [];
    foreach ($raw as $field) {
        if (!is_array($field)) continue;
        $label = trim((string)($field['label'] ?? ''));
        if ($label === '') continue;
        $key = trim((string)($field['key'] ?? ''));
        if ($key === '') $key = slugify($label);
        $key = preg_replace('/[^a-z0-9_\-]/', '', mb_strtolower($key)) ?: slugify($label);
        if ($key === '' || isset($seen[$key])) continue;
        $seen[$key] = true;
        $type = (string)($field['type'] ?? 'text');
        if (!in_array($type, $allowed, true)) $type = 'text';
        $options = [];
        if ($type === 'select') {
            $rawOptions = $field['options'] ?? [];
            if (is_string($rawOptions)) $rawOptions = preg_split('/\r?\n|,/', $rawOptions) ?: [];
            if (is_array($rawOptions)) {
                foreach ($rawOptions as $option) {
                    $option = trim((string)$option);
                    if ($option !== '') $options[] = $option;
                }
            }
            $options = array_values(array_unique($options));
        }
        $schema[] = [
            'key' => $key,
            'label' => $label,
            'type' => $type,
            'required' => filter_var($field['required'] ?? false, FILTER_VALIDATE_BOOLEAN),
            'placeholder' => trim((string)($field['placeholder'] ?? '')),
            'options' => $options,
            'multiple' => $type === 'file' && filter_var($field['multiple'] ?? false, FILTER_VALIDATE_BOOLEAN),
        ];
    }
    return $schema;
}

function form_submission_upload_references(PDO $pdo): array
{
    $references = [];
    try {
        $rows = $pdo->query('SELECT s.id AS submission_id,s.form_id,s.data_json,f.name AS form_name FROM form_submissions s JOIN forms f ON f.id=s.form_id ORDER BY s.id DESC')->fetchAll();
    } catch (Throwable) {
        return [];
    }
    foreach ($rows as $row) {
        foreach (collect_upload_filenames(decode_json($row['data_json'] ?? '')) as $name) {
            $references[$name] ??= [];
            $key = 'form:' . (int)$row['form_id'] . ':' . (int)$row['submission_id'];
            $references[$name][$key] = [
                'document_id' => null,
                'document_name' => null,
                'folder_id' => null,
                'form_id' => (int)$row['form_id'],
                'form_name' => (string)$row['form_name'],
                'submission_id' => (int)$row['submission_id'],
                'source' => 'form',
            ];
        }
    }
    return $references;
}
