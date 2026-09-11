<?php
declare(strict_types=1);
require __DIR__ . '/../core/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate');
header('Pragma: no-cache');

if (!Database::installed()) json_response(['ok' => false, 'error' => 'CMS is not installed'], 503);
$user = Auth::user();
if (!$user) json_response(['ok' => false, 'error' => 'Unauthorized'], 401);

$pdo = Database::connection();
// Auth/CSRF data remains readable in $_SESSION after closing the backing
// session file. This prevents autosave/search requests from serializing.
if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
$action = trim((string)($_GET['action'] ?? $_POST['action'] ?? 'state'));

function api_payload(): array
{
    static $payload;
    if (is_array($payload)) return $payload;
    $type = strtolower((string)($_SERVER['CONTENT_TYPE'] ?? ''));
    if (str_contains($type, 'application/json')) {
        $decoded = json_decode((string)file_get_contents('php://input'), true);
        $payload = is_array($decoded) ? $decoded : [];
    } else {
        $payload = $_POST;
    }
    return $payload;
}

function api_verify_csrf(): void
{
    $payload = api_payload();
    $token = (string)($_SERVER['HTTP_X_CSRF_TOKEN'] ?? ($payload['_csrf'] ?? ''));
    $sessionToken = (string)($_SESSION['_csrf'] ?? '');
    if ($token === '' || $sessionToken === '' || !hash_equals($sessionToken, $token)) {
        json_response(['ok' => false, 'error' => 'Сессия устарела. Обновите страницу.'], 419);
    }
}

function nullable_id(mixed $value): ?int
{
    if ($value === null || $value === '') return null;
    $id = (int)$value;
    return $id > 0 ? $id : null;
}

function ensure_folder(PDO $pdo, ?int $id): void
{
    if ($id === null) return;
    $stmt = $pdo->prepare('SELECT 1 FROM folders WHERE id=? LIMIT 1');
    $stmt->execute([$id]);
    if (!$stmt->fetchColumn()) throw new RuntimeException('Папка не найдена.');
}

function sanitize_document_mode(mixed $mode): string
{
    return (string)$mode === 'multiple' ? 'multiple' : 'single';
}

function sanitize_schema(array $raw): array
{
    $allowedTypes = [
        'text','textarea','number','boolean','date','datetime','link','email','phone','color',
        'image','video','audio','file'
    ];
    $uploadTypes = upload_field_types();
    $schema = [];
    $seen = [];
    foreach ($raw as $field) {
        if (!is_array($field)) continue;
        $label = trim((string)($field['label'] ?? ''));
        $key = trim((string)($field['key'] ?? ''));
        $type = (string)($field['type'] ?? 'text');
        if ($label === '') continue;
        if ($key === '') $key = slugify($label);
        $key = preg_replace('/[^a-z0-9_\-]/', '', mb_strtolower($key)) ?: slugify($label);
        if (isset($seen[$key])) continue;
        $seen[$key] = true;
        if (!in_array($type, $allowedTypes, true)) $type = 'text';
        $multiple = in_array($type, $uploadTypes, true) && filter_var($field['multiple'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $schema[] = ['key' => $key, 'label' => $label, 'type' => $type, 'multiple' => $multiple];
    }
    return $schema;
}

function sanitize_data_schema(array $raw): array
{
    $allowedTypes = ['text','textarea','number','boolean','date','datetime','select','image','video','audio','file','relation'];
    $uploadTypes = upload_field_types();
    $schema = [];
    $seen = [];
    foreach ($raw as $field) {
        if (!is_array($field)) continue;
        $label = trim((string)($field['label'] ?? ''));
        $key = trim((string)($field['key'] ?? ''));
        $type = (string)($field['type'] ?? 'text');
        if ($label === '') continue;
        if ($key === '') $key = slugify($label);
        $key = preg_replace('/[^a-z0-9_\-]/', '', mb_strtolower($key)) ?: slugify($label);
        if ($key === '' || isset($seen[$key])) continue;
        $seen[$key] = true;
        if (!in_array($type, $allowedTypes, true)) $type = 'text';
        $multiple = in_array($type, $uploadTypes, true) && filter_var($field['multiple'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $options = [];
        if ($type === 'select') {
            $rawOptions = $field['options'] ?? [];
            if (is_string($rawOptions)) $rawOptions = preg_split('/
?
|,/', $rawOptions) ?: [];
            if (is_array($rawOptions)) {
                foreach ($rawOptions as $option) {
                    $option = trim((string)$option);
                    if ($option !== '') $options[] = $option;
                }
            }
            $options = array_values(array_unique($options));
        }
        $sourceDataSetId = $type === 'relation' ? max(0, (int)($field['source_data_set_id'] ?? 0)) : 0;
        $relationMultiple = $type === 'relation' && filter_var($field['relation_multiple'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $displayField = $type === 'relation'
            ? (preg_replace('/[^a-z0-9_\-]/', '', mb_strtolower(trim((string)($field['display_field'] ?? '')))) ?: '')
            : '';
        $schema[] = [
            'key'=>$key,
            'label'=>$label,
            'type'=>$type,
            'multiple'=>$multiple,
            'options'=>$options,
            'source_data_set_id'=>$sourceDataSetId ?: null,
            'relation_multiple'=>$relationMultiple,
            'display_field'=>$displayField,
        ];
    }
    return $schema;
}

function sanitize_data_mode(mixed $mode): string
{
    return (string)$mode === 'multiple' ? 'multiple' : 'single';
}

function unique_data_slug(PDO $pdo, int $projectId, string $seed, ?int $ignoreId = null): string
{
    $base = slugify($seed);
    $slug = $base;
    $i = 2;
    while (true) {
        $sql = 'SELECT id FROM data_sets WHERE project_id=? AND slug=?';
        $args = [$projectId, $slug];
        if ($ignoreId !== null) { $sql .= ' AND id<>?'; $args[] = $ignoreId; }
        $sql .= ' LIMIT 1';
        $stmt = $pdo->prepare($sql); $stmt->execute($args);
        if (!$stmt->fetchColumn()) return $slug;
        $slug = $base . '-' . $i++;
    }
}


function data_items_with_stable_ids(array $items): array
{
    $items = array_values(array_filter($items, 'is_array'));
    $used = [];
    $maxId = 0;
    foreach ($items as $item) {
        $id = (int)($item['_id'] ?? 0);
        if ($id > 0 && !isset($used[$id])) { $used[$id] = true; $maxId = max($maxId, $id); }
    }
    $seen = [];
    foreach ($items as &$item) {
        $id = (int)($item['_id'] ?? 0);
        if ($id <= 0 || isset($seen[$id])) {
            do { $maxId++; } while (isset($used[$maxId]) || isset($seen[$maxId]));
            $id = $maxId;
        }
        $item['_id'] = $id;
        $seen[$id] = true;
    }
    unset($item);
    return $items;
}

function ensure_data_set_ids(PDO $pdo, array $row): array
{
    if (sanitize_data_mode($row['mode'] ?? 'single') !== 'multiple') return $row;
    $decoded = decode_json($row['data_json'] ?? '[]');
    $before = json_encode($decoded, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
    $items = data_items_with_stable_ids(is_array($decoded) ? $decoded : []);
    $after = json_encode($items, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
    if ($after !== $before && is_string($after)) {
        $pdo->prepare('UPDATE data_sets SET data_json=? WHERE id=?')->execute([$after, (int)$row['id']]);
        $row['data_json'] = $after;
    }
    return $row;
}

function relation_text_fields(array $schema): array
{
    $out = [];
    foreach ($schema as $field) {
        if (!is_array($field)) continue;
        if (!in_array((string)($field['type'] ?? ''), ['text','textarea','select'], true)) continue;
        $key = (string)($field['key'] ?? '');
        if ($key === '') continue;
        $out[] = ['key'=>$key, 'label'=>(string)($field['label'] ?? $key)];
    }
    return $out;
}

function relation_record_label(array $schema, array $item, string $displayField = ''): string
{
    if ($displayField !== '' && trim((string)($item[$displayField] ?? '')) !== '') return trim((string)$item[$displayField]);
    foreach (['name','title'] as $preferred) {
        if (trim((string)($item[$preferred] ?? '')) !== '') return trim((string)$item[$preferred]);
    }
    foreach ($schema as $field) {
        if (!is_array($field) || !in_array((string)($field['type'] ?? ''), ['text','textarea','select'], true)) continue;
        $key = (string)($field['key'] ?? '');
        if ($key !== '' && trim((string)($item[$key] ?? '')) !== '') return trim((string)$item[$key]);
    }
    return 'Запись #' . (int)($item['_id'] ?? 0);
}

function validate_data_relation_schema(PDO $pdo, int $projectId, int $currentSetId, array $schema): array
{
    foreach ($schema as &$field) {
        if (($field['type'] ?? '') !== 'relation') continue;
        $sourceId = (int)($field['source_data_set_id'] ?? 0);
        if ($sourceId <= 0) continue; // Allow an unfinished field while the user is configuring it.
        if ($sourceId === $currentSetId) throw new RuntimeException('Связь должна вести в другой набор данных.');
        $stmt = $pdo->prepare('SELECT id,mode,schema_json FROM data_sets WHERE id=? AND project_id=? LIMIT 1');
        $stmt->execute([$sourceId, $projectId]);
        $source = $stmt->fetch();
        if (!$source || sanitize_data_mode($source['mode'] ?? '') !== 'multiple') {
            throw new RuntimeException('Источник связи должен быть данными типа Multiple.');
        }
        $display = (string)($field['display_field'] ?? '');
        if ($display !== '') {
            $allowed = array_column(relation_text_fields(sanitize_data_schema(decode_json($source['schema_json'] ?? '[]'))), 'key');
            if (!in_array($display, $allowed, true)) $field['display_field'] = '';
        }
    }
    unset($field);
    return $schema;
}

function data_relation_context(PDO $pdo, int $projectId, int $currentSetId, array $schema): array
{
    $context = [];
    foreach ($schema as $field) {
        if (($field['type'] ?? '') !== 'relation') continue;
        $key = (string)($field['key'] ?? '');
        $sourceId = (int)($field['source_data_set_id'] ?? 0);
        if ($key === '' || $sourceId <= 0 || $sourceId === $currentSetId) { $context[$key] = []; continue; }
        $stmt = $pdo->prepare('SELECT * FROM data_sets WHERE id=? AND project_id=? AND mode=? LIMIT 1');
        $stmt->execute([$sourceId, $projectId, 'multiple']);
        $source = $stmt->fetch();
        if (!$source) { $context[$key] = []; continue; }
        $source = ensure_data_set_ids($pdo, $source);
        $items = decode_json($source['data_json'] ?? '[]');
        $ids = [];
        foreach ($items as $item) if (is_array($item) && (int)($item['_id'] ?? 0) > 0) $ids[(int)$item['_id']] = true;
        $context[$key] = $ids;
    }
    return $context;
}

function normalize_relation_value(mixed $value, bool $multiple, array $allowedIds): mixed
{
    $ids = is_array($value) ? $value : (($value === null || $value === '') ? [] : [$value]);
    $clean = [];
    foreach ($ids as $id) {
        $id = (int)$id;
        if ($id > 0 && isset($allowedIds[$id])) $clean[$id] = $id;
    }
    $clean = array_values($clean);
    return $multiple ? $clean : ($clean[0] ?? null);
}

function data_set_relation_dependencies(PDO $pdo, int $projectId, int $targetSetId): array
{
    $stmt = $pdo->prepare('SELECT id,name,schema_json FROM data_sets WHERE project_id=? AND id<>? ORDER BY name');
    $stmt->execute([$projectId, $targetSetId]);
    $dependencies = [];
    foreach ($stmt->fetchAll() as $row) {
        $schema = sanitize_data_schema(decode_json($row['schema_json'] ?? '[]'));
        foreach ($schema as $field) {
            if (($field['type'] ?? '') === 'relation' && (int)($field['source_data_set_id'] ?? 0) === $targetSetId) {
                $dependencies[] = [
                    'data_set_id'=>(int)$row['id'],
                    'data_set_name'=>(string)$row['name'],
                    'field_key'=>(string)$field['key'],
                    'field_label'=>(string)$field['label'],
                ];
            }
        }
    }
    return $dependencies;
}

function remove_data_record_references(PDO $pdo, int $projectId, int $sourceSetId, array $removedIds): int
{
    $removed = [];
    foreach ($removedIds as $id) { $id=(int)$id; if($id>0)$removed[$id]=true; }
    if (!$removed) return 0;
    $stmt = $pdo->prepare('SELECT id,mode,schema_json,data_json FROM data_sets WHERE project_id=? AND id<>?');
    $stmt->execute([$projectId, $sourceSetId]);
    $changedSets = 0;
    foreach ($stmt->fetchAll() as $row) {
        $schema = sanitize_data_schema(decode_json($row['schema_json'] ?? '[]'));
        $relationFields = array_values(array_filter($schema, static fn($f) => ($f['type'] ?? '') === 'relation' && (int)($f['source_data_set_id'] ?? 0) === $sourceSetId));
        if (!$relationFields) continue;
        $mode = sanitize_data_mode($row['mode'] ?? 'single');
        $data = decode_json($row['data_json'] ?? ($mode==='multiple'?'[]':'{}'));
        $items = $mode === 'multiple' ? (is_array($data) ? $data : []) : [is_array($data) ? $data : []];
        $changed = false;
        foreach ($items as &$item) {
            if (!is_array($item)) continue;
            foreach ($relationFields as $field) {
                $key = (string)$field['key'];
                if (!array_key_exists($key, $item)) continue;
                if (!empty($field['relation_multiple'])) {
                    $old = is_array($item[$key]) ? $item[$key] : (($item[$key] ?? null) ? [$item[$key]] : []);
                    $new = array_values(array_filter(array_map('intval',$old), static fn($id)=>$id>0 && !isset($removed[$id])));
                    if ($new !== $old) { $item[$key] = $new; $changed = true; }
                } else {
                    $id = (int)($item[$key] ?? 0);
                    if ($id > 0 && isset($removed[$id])) { $item[$key] = null; $changed = true; }
                }
            }
        }
        unset($item);
        if ($changed) {
            $newData = $mode === 'multiple' ? array_values($items) : ($items[0] ?? []);
            $json = json_encode($newData, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
            $pdo->prepare('UPDATE data_sets SET data_json=?,updated_at=CURRENT_TIMESTAMP WHERE id=?')->execute([$json, (int)$row['id']]);
            $changedSets++;
        }
    }
    return $changedSets;
}

function admin_relation_records(PDO $pdo, array $project, int $sourceId, string $displayField = ''): array
{
    $stmt = $pdo->prepare('SELECT * FROM data_sets WHERE id=? AND project_id=? AND mode=? LIMIT 1');
    $stmt->execute([$sourceId, (int)$project['id'], 'multiple']);
    $row = $stmt->fetch();
    if (!$row) throw new RuntimeException('Источник связи не найден.');
    $row = ensure_data_set_ids($pdo, $row);
    $schema = sanitize_data_schema(decode_json($row['schema_json'] ?? '[]'));
    $allowedDisplay = array_column(relation_text_fields($schema), 'key');
    if ($displayField !== '' && !in_array($displayField, $allowedDisplay, true)) $displayField = '';
    $records = [];
    foreach (decode_json($row['data_json'] ?? '[]') as $item) {
        if (!is_array($item)) continue;
        $id = (int)($item['_id'] ?? 0);
        if ($id <= 0) continue;
        $records[] = ['id'=>$id, 'label'=>relation_record_label($schema,$item,$displayField)];
    }
    return [
        'id'=>(int)$row['id'],
        'name'=>(string)$row['name'],
        'display_fields'=>relation_text_fields($schema),
        'records'=>$records,
    ];
}

function admin_data_sets(PDO $pdo, array $project): array
{
    $stmt = $pdo->prepare('SELECT id,name,slug,mode,api_enabled,schema_json,data_json,created_at,updated_at FROM data_sets WHERE project_id=? ORDER BY updated_at DESC,name');
    $stmt->execute([(int)$project['id']]);
    $rows = [];
    foreach ($stmt->fetchAll() as $row) {
        $mode = sanitize_data_mode($row['mode'] ?? 'single');
        $data = decode_json($row['data_json']);
        $rows[] = [
            'id'=>(int)$row['id'],
            'name'=>(string)$row['name'],
            'slug'=>(string)$row['slug'],
            'mode'=>$mode,
            'api_enabled'=>(bool)$row['api_enabled'],
            'item_count'=>$mode==='multiple' && array_is_list($data) ? count($data) : null,
            'display_fields'=>$mode==='multiple' ? relation_text_fields(sanitize_data_schema(decode_json($row['schema_json'] ?? '[]'))) : [],
            'created_at'=>(string)$row['created_at'],
            'updated_at'=>(string)$row['updated_at'],
            'endpoint'=>public_api_absolute_url((string)$project['slug'].'/data/'.(string)$row['slug']),
        ];
    }
    return $rows;
}

function admin_data_set(PDO $pdo, int $id, array $project): array
{
    $stmt = $pdo->prepare('SELECT * FROM data_sets WHERE id=? AND project_id=? LIMIT 1');
    $stmt->execute([$id,(int)$project['id']]);
    $row = $stmt->fetch();
    if (!$row) throw new RuntimeException('Набор данных не найден.');
    $row = ensure_data_set_ids($pdo, $row);
    $mode = sanitize_data_mode($row['mode'] ?? 'single');
    $data = decode_json($row['data_json']);
    if ($mode === 'multiple') $data = array_is_list($data) ? array_values(array_filter($data,'is_array')) : [];
    $publicData = public_data_set_data($pdo, $row, (int)$project['id']);
    $apiSample = $mode === 'multiple'
        ? (is_array($publicData) && isset($publicData[0]) ? [$publicData[0]] : [])
        : $publicData;
    return [
        'id'=>(int)$row['id'],
        'name'=>(string)$row['name'],
        'slug'=>(string)$row['slug'],
        'mode'=>$mode,
        'api_enabled'=>(bool)$row['api_enabled'],
        'schema'=>sanitize_data_schema(decode_json($row['schema_json'])),
        'data'=>$mode==='multiple' ? $data : (object)$data,
        'api_sample'=>$apiSample,
        'created_at'=>(string)$row['created_at'],
        'updated_at'=>(string)$row['updated_at'],
        'endpoint'=>public_api_absolute_url((string)$project['slug'].'/data/'.(string)$row['slug']),
    ];
}

function admin_document(PDO $pdo, int $id, array $project): array
{
    $stmt = $pdo->prepare('SELECT * FROM documents WHERE id=? LIMIT 1');
    $stmt->execute([$id]);
    $doc = $stmt->fetch();
    if (!$doc || !ProjectAccess::containsDocument($pdo, $project, $id)) throw new RuntimeException('Раздел не найден.');

    $mode = sanitize_document_mode($doc['mode'] ?? 'single');
    $data = decode_json($doc['data_json']);
    if ($mode === 'multiple') $data = array_is_list($data) ? array_values(array_filter($data, 'is_array')) : [];
    $publicData = public_document_data($doc);
    $apiSample = $mode === 'multiple'
        ? (is_array($publicData) && isset($publicData[0]) ? [$publicData[0]] : [])
        : $publicData;

    $i18n = cms_i18n_settings($pdo, (int)$project['id']);
    $translations = [];
    $stmt = $pdo->prepare('SELECT language,data_json,updated_at FROM document_translations WHERE document_id=? ORDER BY language');
    $stmt->execute([$id]);
    foreach ($stmt->fetchAll() as $translation) {
        $language = strtolower((string)$translation['language']);
        if (!in_array($language, $i18n['languages'], true) || $language === $i18n['default_language']) continue;
        $translatedData = decode_json($translation['data_json']);
        if ($mode === 'multiple') $translatedData = array_is_list($translatedData) ? array_values(array_filter($translatedData, 'is_array')) : [];
        $translations[$language] = $mode === 'multiple' ? $translatedData : (object)$translatedData;
    }

    $versionCounts = [];
    $stmt = $pdo->prepare('SELECT language,COUNT(*) AS count FROM revisions WHERE document_id=? GROUP BY language');
    $stmt->execute([$id]);
    foreach ($stmt->fetchAll() as $row) $versionCounts[(string)$row['language']] = (int)$row['count'];

    return [
        'id' => (int)$doc['id'],
        'folder_id' => ((int)($doc['folder_id'] ?? 0) === ProjectAccess::rootFolderId($project)) ? null : ($doc['folder_id'] === null ? null : (int)$doc['folder_id']),
        'name' => $doc['name'],
        'slug' => $doc['slug'],
        'mode' => $mode,
        'api_enabled' => (bool)($doc['api_enabled'] ?? true),
        'api_tree_visible' => (bool)($doc['api_tree_visible'] ?? true),
        'schema' => decode_json($doc['schema_json']),
        'data' => $mode === 'multiple' ? $data : (object)$data,
        'api_sample' => $apiSample,
        'translations' => $translations,
        'i18n' => $i18n,
        'updated_at' => $doc['updated_at'],
        'version_count' => (int)($versionCounts[''] ?? 0),
        'version_counts' => $versionCounts,
        'api_path' => public_api_path((string)$project['slug'].'/'.ProjectAccess::documentApiPath($pdo, $doc, $project)),
        'api_relative' => public_api_path((string)$project['slug'].'/'.ProjectAccess::documentApiPath($pdo, $doc, $project)),
    ];
}


function admin_folder_api_tree(PDO $pdo, array $project, int $folderId, ?array $settings = null, int $depth = 0): array
{
    if($depth>64) return ['type'=>'folder','name'=>'…','slug'=>'','folders'=>[],'sections'=>[],'data_sets'=>[],'forms'=>[]];
    $stmt = $pdo->prepare('SELECT * FROM folders WHERE id=? LIMIT 1');
    $stmt->execute([$folderId]);
    $folder = $stmt->fetch();
    if (!$folder || !ProjectAccess::containsFolder($pdo,$project,$folderId)) throw new RuntimeException('Папка не найдена.');
    $settings ??= folder_api_tree_settings($folder);
    $folderPath = ProjectAccess::folderApiPath($pdo,$folderId,$project);
    $tree = [
        'type'=>'folder',
        'name'=>(string)$folder['name'],
        'slug'=>(string)$folder['slug'],
        'path'=>public_api_path((string)$project['slug'].'/'.$folderPath),
        'url'=>public_api_absolute_url((string)$project['slug'].'/'.$folderPath),
        'folders'=>[],
        'sections'=>[],
        'data_sets'=>[],
        'forms'=>[],
    ];

    $children=$pdo->prepare('SELECT id,slug FROM folders WHERE parent_id=? AND api_enabled=1 ORDER BY sort_order,name,id');
    $children->execute([$folderId]);
    foreach($children->fetchAll() as $child){
        if((string)($child['slug'] ?? '')===feather_trash_slug()) continue;
        $tree['folders'][]=admin_folder_api_tree($pdo,$project,(int)$child['id'],$settings,$depth+1);
    }
    $docs=$pdo->prepare('SELECT * FROM documents WHERE folder_id=? AND api_enabled=1 ORDER BY name,id');
    $docs->execute([$folderId]);
    foreach($docs->fetchAll() as $doc){
        $docPath=public_api_path((string)$project['slug'].'/'.ProjectAccess::documentApiPath($pdo,$doc,$project));
        $section=[
            'type'=>'section',
            'name'=>(string)$doc['name'],
            'slug'=>(string)$doc['slug'],
            'mode'=>sanitize_document_mode($doc['mode']??'single'),
            'path'=>$docPath,
            'url'=>absolute_url($docPath),
        ];
        if(!empty($settings['include_data'])) $section['data']=public_document_data($doc);
        $tree['sections'][]=$section;
    }
    $links=$pdo->prepare("SELECT cl.resource_type,cl.resource_id,d.name AS data_name,d.slug AS data_slug,d.mode AS data_mode,d.api_enabled AS data_api_enabled,d.schema_json AS data_schema,d.data_json AS data_json,f.name AS form_name,f.slug AS form_slug,f.api_enabled AS form_api_enabled,f.schema_json AS form_schema,f.success_message AS form_success FROM content_links cl LEFT JOIN data_sets d ON cl.resource_type='data' AND d.id=cl.resource_id AND d.project_id=cl.project_id LEFT JOIN forms f ON cl.resource_type='form' AND f.id=cl.resource_id AND f.project_id=cl.project_id WHERE cl.project_id=? AND cl.folder_id=? ORDER BY cl.sort_order,cl.id");
    $links->execute([(int)$project['id'],$folderId]);
    foreach($links->fetchAll() as $link){
        if((string)$link['resource_type']==='data' && $link['data_name']!==null && (bool)$link['data_api_enabled']){
            $entry=['type'=>'data','name'=>(string)$link['data_name'],'slug'=>(string)$link['data_slug'],'mode'=>sanitize_data_mode($link['data_mode']??'single'),'url'=>public_api_absolute_url((string)$project['slug'].'/data/'.(string)$link['data_slug'])];
            if(!empty($settings['include_data'])){$row=['id'=>(int)$link['resource_id'],'project_id'=>(int)$project['id'],'mode'=>$link['data_mode'],'schema_json'=>$link['data_schema'],'data_json'=>$link['data_json']];$entry['data']=public_data_set_data($pdo,$row,(int)$project['id']);}
            $tree['data_sets'][]=$entry;
        }elseif((string)$link['resource_type']==='form' && $link['form_name']!==null && (bool)$link['form_api_enabled']){
            $entry=['type'=>'form','name'=>(string)$link['form_name'],'slug'=>(string)$link['form_slug'],'method'=>'POST','url'=>public_api_absolute_url((string)$project['slug'].'/forms/'.(string)$link['form_slug'])];
            if(!empty($settings['include_data'])){$entry['schema']=sanitize_form_schema(decode_json($link['form_schema']??'[]'));$entry['success_message']=(string)$link['form_success'];}
            $tree['forms'][]=$entry;
        }
    }

    return $tree;
}

function admin_folder_api_branches(PDO $pdo, array $project, int $folderId): array
{
    if(!ProjectAccess::containsFolder($pdo,$project,$folderId)) throw new RuntimeException('Папка не найдена.');
    $branches=[];
    $stmt=$pdo->prepare('SELECT id,name,slug,api_enabled,api_tree_visible FROM folders WHERE parent_id=? ORDER BY sort_order,name,id');
    $stmt->execute([$folderId]);
    foreach($stmt->fetchAll() as $row){
        if((string)($row['slug']??'')===feather_trash_slug()) continue;
        $branches[]=[
            'type'=>'folder','id'=>(int)$row['id'],'name'=>(string)$row['name'],'slug'=>(string)$row['slug'],
            'api_enabled'=>(bool)($row['api_enabled']??true),'visible'=>(bool)($row['api_tree_visible']??true),
        ];
    }
    $stmt=$pdo->prepare('SELECT id,name,slug,api_enabled,api_tree_visible FROM documents WHERE folder_id=? ORDER BY name,id');
    $stmt->execute([$folderId]);
    foreach($stmt->fetchAll() as $row){
        $branches[]=[
            'type'=>'document','id'=>(int)$row['id'],'name'=>(string)$row['name'],'slug'=>(string)$row['slug'],
            'api_enabled'=>(bool)($row['api_enabled']??true),'visible'=>(bool)($row['api_tree_visible']??true),
        ];
    }
    return $branches;
}


function admin_content_links(PDO $pdo, array $project, array $user): array
{
    $projectId = (int)$project['id'];
    $rootId = ProjectAccess::rootFolderId($project);
    $stmt = $pdo->prepare("SELECT cl.id,cl.folder_id,cl.resource_type,cl.resource_id,cl.sort_order,cl.created_at,
        d.name AS data_name,d.slug AS data_slug,d.mode AS data_mode,d.api_enabled AS data_api_enabled,d.data_json AS data_json,d.updated_at AS data_updated_at,
        f.name AS form_name,f.slug AS form_slug,f.api_enabled AS form_api_enabled,f.updated_at AS form_updated_at
        FROM content_links cl
        LEFT JOIN data_sets d ON cl.resource_type='data' AND d.id=cl.resource_id AND d.project_id=cl.project_id
        LEFT JOIN forms f ON cl.resource_type='form' AND f.id=cl.resource_id AND f.project_id=cl.project_id
        WHERE cl.project_id=? ORDER BY cl.folder_id,cl.sort_order,cl.id");
    $stmt->execute([$projectId]);
    $rows = [];
    $stale = [];
    foreach ($stmt->fetchAll() as $row) {
        $type = (string)$row['resource_type'];
        if ($type === 'data') {
            if ($row['data_name'] === null) { $stale[] = (int)$row['id']; continue; }
            if (!ProjectAccess::can($pdo,$user,$projectId,'data.view')) continue;
            $mode = sanitize_data_mode($row['data_mode'] ?? 'single');
            $data = decode_json($row['data_json'] ?? '[]');
            $rows[] = [
                'id'=>(int)$row['id'],'folder_id'=>(int)$row['folder_id']===$rootId?null:(int)$row['folder_id'],
                'resource_type'=>'data','resource_id'=>(int)$row['resource_id'],'name'=>(string)$row['data_name'],
                'slug'=>(string)$row['data_slug'],'mode'=>$mode,'api_enabled'=>(bool)$row['data_api_enabled'],
                'item_count'=>$mode==='multiple' && array_is_list($data) ? count($data) : null,
                'updated_at'=>(string)$row['data_updated_at'],'created_at'=>(string)$row['created_at'],
                'endpoint'=>public_api_absolute_url((string)$project['slug'].'/data/'.(string)$row['data_slug']),
            ];
        } elseif ($type === 'form') {
            if ($row['form_name'] === null) { $stale[] = (int)$row['id']; continue; }
            if (!ProjectAccess::can($pdo,$user,$projectId,'forms.view')) continue;
            $rows[] = [
                'id'=>(int)$row['id'],'folder_id'=>(int)$row['folder_id']===$rootId?null:(int)$row['folder_id'],
                'resource_type'=>'form','resource_id'=>(int)$row['resource_id'],'name'=>(string)$row['form_name'],
                'slug'=>(string)$row['form_slug'],'api_enabled'=>(bool)$row['form_api_enabled'],
                'updated_at'=>(string)$row['form_updated_at'],'created_at'=>(string)$row['created_at'],
                'endpoint'=>public_api_absolute_url((string)$project['slug'].'/forms/'.(string)$row['form_slug']),
            ];
        } else $stale[] = (int)$row['id'];
    }
    if ($stale) {
        $placeholders = implode(',',array_fill(0,count($stale),'?'));
        $pdo->prepare("DELETE FROM content_links WHERE id IN ($placeholders)")->execute($stale);
    }
    return $rows;
}

function admin_state(PDO $pdo, array $user, array $project): array
{
    ProjectAccess::requirePermission($pdo, $user, (int)$project['id'], 'content.view');
    $ids = feather_visible_folder_ids($pdo, $project);
    $rootId = ProjectAccess::rootFolderId($project);
    $folders = [];
    $documents = [];
    if ($ids) {
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $pdo->prepare("SELECT id,parent_id,name,slug,sort_order,api_enabled,api_tree_visible,api_tree_depth,api_tree_include_data,api_tree_cache_ttl,created_at,updated_at FROM folders WHERE id IN ($placeholders) AND id<>? ORDER BY sort_order,name");
        $stmt->execute(array_merge($ids, [$rootId]));
        $folders = $stmt->fetchAll();
        $stmt = $pdo->prepare("SELECT id,folder_id,name,slug,mode,api_enabled,api_tree_visible,data_json,created_at,updated_at FROM documents WHERE folder_id IN ($placeholders) ORDER BY name");
        $stmt->execute($ids);
        $documents = $stmt->fetchAll();
    }

    foreach ($folders as &$folder) {
        $folder['id'] = (int)$folder['id'];
        $parent = $folder['parent_id'] === null ? null : (int)$folder['parent_id'];
        $folder['parent_id'] = $parent === $rootId ? null : $parent;
        $folder['sort_order'] = (int)$folder['sort_order'];
        $folder['api_enabled'] = (bool)($folder['api_enabled'] ?? true);
        $folder['api_tree_visible'] = (bool)($folder['api_tree_visible'] ?? true);
        $folder['api_tree_depth'] = max(0,min(10,(int)($folder['api_tree_depth'] ?? 0)));
        $folder['api_tree_include_data'] = (bool)($folder['api_tree_include_data'] ?? true);
        $folder['api_tree_cache_ttl'] = max(0,min(3600,(int)($folder['api_tree_cache_ttl'] ?? 60)));
        $folderApiPath = ProjectAccess::folderApiPath($pdo, (int)$folder['id'], $project);
        $folder['endpoint'] = public_api_absolute_url((string)$project['slug'].'/'.$folderApiPath);
    }
    unset($folder);

    foreach ($documents as &$doc) {
        $doc['id'] = (int)$doc['id'];
        $folderId = $doc['folder_id'] === null ? null : (int)$doc['folder_id'];
        $doc['folder_id'] = $folderId === $rootId ? null : $folderId;
        $doc['mode'] = sanitize_document_mode($doc['mode'] ?? 'single');
        $doc['api_enabled'] = (bool)($doc['api_enabled'] ?? true);
        $doc['api_tree_visible'] = (bool)($doc['api_tree_visible'] ?? true);
        $data = decode_json($doc['data_json']);
        $doc['item_count'] = $doc['mode'] === 'multiple' && array_is_list($data) ? count($data) : null;
        unset($doc['data_json']);
    }
    unset($doc);

    return [
        'ok' => true,
        'user' => $user,
        'projects' => ProjectAccess::listProjects($pdo, $user),
        'current_project' => $project,
        'permissions' => ProjectAccess::permissions($pdo, $user, (int)$project['id']),
        'folders' => $folders,
        'documents' => $documents,
        'content_links' => admin_content_links($pdo,$project,$user),
        'csrf' => csrf_token(),
        'base' => cms_base_path(),
        'public_api' => public_api_path((string)$project['slug']),
        'public_api_absolute' => public_api_absolute_url((string)$project['slug']),
        'project_api_namespace' => (string)$project['slug'],
        'api_access' => project_api_access($pdo, (int)$project['id']),
        'api_status' => project_api_status($pdo, (int)$project['id']),
        'api_response' => project_api_response($pdo, (int)$project['id']),
        'i18n' => cms_i18n_settings($pdo, (int)$project['id']),
        'trash_count' => (int)(function() use ($pdo,$project) { $stmt=$pdo->prepare('SELECT COUNT(*) FROM content_trash WHERE project_id=?'); $stmt->execute([(int)$project['id']]); return $stmt->fetchColumn(); })(),
    ];
}


function normalize_saved_value(string $type, mixed $value): mixed
{
    if ($type === 'boolean') return filter_var($value, FILTER_VALIDATE_BOOLEAN);
    if ($type === 'number') {
        $raw = trim((string)($value ?? ''));
        return $raw === '' ? null : (float)$raw;
    }
    return $value === null ? '' : (string)$value;
}

function normalize_upload_value(mixed $value, bool $multiple): mixed
{
    if ($multiple) {
        $items = is_array($value) ? $value : (($value === null || $value === '') ? [] : [$value]);
        $clean = [];
        foreach ($items as $item) {
            if (!is_string($item)) continue;
            $item = trim($item);
            if ($item !== '') $clean[] = $item;
        }
        return array_values(array_unique($clean));
    }

    if (is_array($value)) {
        foreach ($value as $item) {
            if (is_string($item) && trim($item) !== '') return trim($item);
        }
        return '';
    }
    return $value === null ? '' : (string)$value;
}

function normalized_uploaded_files(mixed $spec): array
{
    if (!is_array($spec) || !array_key_exists('error', $spec)) return [];
    if (!is_array($spec['error'])) return [$spec];

    $files = [];
    foreach ($spec['error'] as $index => $error) {
        $files[] = [
            'name' => $spec['name'][$index] ?? '',
            'full_path' => $spec['full_path'][$index] ?? '',
            'type' => $spec['type'][$index] ?? '',
            'tmp_name' => $spec['tmp_name'][$index] ?? '',
            'error' => $error,
            'size' => $spec['size'][$index] ?? 0,
        ];
    }
    return $files;
}

function save_item_values(array $schema, array $values, string $uploadPrefix = '', array &$newUploads = [], array $relationContext = []): array
{
    $data = [];
    foreach ($schema as $field) {
        $key = (string)$field['key'];
        $type = (string)$field['type'];
        $isUpload = in_array($type, upload_field_types(), true);
        $multiple = $isUpload && filter_var($field['multiple'] ?? false, FILTER_VALIDATE_BOOLEAN);
        if ($type === 'relation') {
            $value = normalize_relation_value(
                $values[$key] ?? null,
                filter_var($field['relation_multiple'] ?? false, FILTER_VALIDATE_BOOLEAN),
                $relationContext[$key] ?? []
            );
        } else {
            $value = $isUpload
                ? normalize_upload_value($values[$key] ?? null, $multiple)
                : normalize_saved_value($type, $values[$key] ?? null);
        }

        if ($isUpload) {
            $fileKey = $uploadPrefix === '' ? 'upload_' . $key : 'upload_' . $uploadPrefix . '__' . $key;
            $files = normalized_uploaded_files($_FILES[$fileKey] ?? null);
            $uploadedPaths = [];
            foreach ($files as $file) {
                if (($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) continue;
                $newPath = upload_asset($file, $type);
                if ($newPath === '') continue;
                $uploadedPaths[] = $newPath;
                $uploadedName = upload_filename_from_value($newPath);
                if ($uploadedName !== null) $newUploads[] = $uploadedName;
                if (!$multiple) break;
            }

            if ($multiple && $uploadedPaths) {
                $existing = is_array($value) ? $value : [];
                $value = array_values(array_unique(array_merge($existing, $uploadedPaths)));
            } elseif (!$multiple && $uploadedPaths) {
                $value = $uploadedPaths[0];
            }
        }
        $data[$key] = $value;
    }
    return $data;
}

function canonical_json(string $json): string
{
    $decoded = json_decode($json, true);
    if (!is_array($decoded)) $decoded = [];
    return json_encode($decoded, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

function revision_language_key(string $language): string
{
    $language = strtolower(trim($language));
    return preg_match('/^[a-z]{2}$/', $language) ? $language : '';
}

function append_revision_if_new(PDO $pdo, int $documentId, string $schemaJson, string $dataJson, string $language = ''): bool
{
    $language = revision_language_key($language);
    $stmt = $pdo->prepare('SELECT schema_json,data_json FROM revisions WHERE document_id=? AND language=? ORDER BY id DESC LIMIT 1');
    $stmt->execute([$documentId, $language]);
    $latest = $stmt->fetch();
    if ($latest && canonical_json((string)$latest['schema_json']) === canonical_json($schemaJson)
        && canonical_json((string)$latest['data_json']) === canonical_json($dataJson)) {
        return false;
    }
    $stmt = $pdo->prepare('INSERT INTO revisions(document_id,language,schema_json,data_json) VALUES(?,?,?,?)');
    $stmt->execute([$documentId, $language, $schemaJson, $dataJson]);
    return true;
}

function record_saved_revision(PDO $pdo, int $documentId, string $schemaJson, string $dataJson, string $language = ''): bool
{
    $language = revision_language_key($language);
    $stmt = $pdo->prepare("SELECT id,schema_json,data_json,created_at FROM revisions WHERE document_id=? AND language=? ORDER BY id DESC LIMIT 2");
    $stmt->execute([$documentId, $language]);
    $rows = $stmt->fetchAll();
    $latest = $rows[0] ?? null;
    if ($latest && canonical_json((string)$latest['schema_json']) === canonical_json($schemaJson)
        && canonical_json((string)$latest['data_json']) === canonical_json($dataJson)) {
        return false;
    }

    $groupSeconds = max(5, (int)(cms_config('revision_group_seconds') ?? 30));
    $latestTimestamp = $latest ? strtotime((string)($latest['created_at'] ?? '')) : false;
    $age = $latestTimestamp === false ? null : max(0, time() - $latestTimestamp);
    if (count($rows) >= 2 && $age !== null && $age >= 0 && $age <= $groupSeconds) {
        $update = $pdo->prepare('UPDATE revisions SET schema_json=?,data_json=?,created_at=CURRENT_TIMESTAMP WHERE id=?');
        $update->execute([$schemaJson, $dataJson, (int)$latest['id']]);
        return true;
    }

    $insert = $pdo->prepare('INSERT INTO revisions(document_id,language,schema_json,data_json) VALUES(?,?,?,?)');
    $insert->execute([$documentId, $language, $schemaJson, $dataJson]);
    return true;
}

function trim_revisions(PDO $pdo, int $documentId, string $language = ''): array
{
    $language = revision_language_key($language);
    $limit = max(2, (int)(cms_config('revision_limit') ?? 20));
    $stmt = $pdo->prepare('SELECT id,data_json FROM revisions WHERE document_id=? AND language=? ORDER BY id DESC LIMIT -1 OFFSET ?');
    $stmt->bindValue(1, $documentId, PDO::PARAM_INT);
    $stmt->bindValue(2, $language, PDO::PARAM_STR);
    $stmt->bindValue(3, $limit, PDO::PARAM_INT);
    $stmt->execute();
    $old = $stmt->fetchAll();
    if (!$old) return [];

    $files = [];
    $ids = [];
    foreach ($old as $row) {
        $ids[] = (int)$row['id'];
        foreach (collect_upload_filenames(decode_json($row['data_json'] ?? '')) as $name) $files[$name] = true;
    }
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $delete = $pdo->prepare("DELETE FROM revisions WHERE id IN ($placeholders)");
    $delete->execute($ids);
    return array_keys($files);
}

function admin_versions(PDO $pdo, int $documentId, string $language = ''): array
{
    $language = revision_language_key($language);
    $stmt = $pdo->prepare('SELECT id,language,schema_json,data_json,created_at FROM revisions WHERE document_id=? AND language=? ORDER BY id DESC');
    $stmt->execute([$documentId, $language]);
    $rows = [];
    foreach ($stmt->fetchAll() as $index => $row) {
        $schema = decode_json($row['schema_json']);
        $data = decode_json($row['data_json']);
        $rows[] = [
            'id' => (int)$row['id'],
            'language' => (string)$row['language'],
            'created_at' => (string)$row['created_at'],
            'field_count' => count($schema),
            'item_count' => array_is_list($data) ? count($data) : null,
            'latest' => $index === 0,
        ];
    }
    return $rows;
}


function unique_form_slug(PDO $pdo, string $name, ?int $ignoreId = null): string
{
    $base = slugify($name);
    $slug = $base;
    $i = 2;
    while (true) {
        $sql = 'SELECT id FROM forms WHERE slug=?';
        $args = [$slug];
        if ($ignoreId !== null) { $sql .= ' AND id<>?'; $args[] = $ignoreId; }
        $sql .= ' LIMIT 1';
        $stmt = $pdo->prepare($sql);
        $stmt->execute($args);
        if (!$stmt->fetchColumn()) return $slug;
        $slug = $base . '-' . $i++;
    }
}

function admin_forms(PDO $pdo, array $project): array
{
    $stmt = $pdo->prepare("SELECT f.id,f.name,f.slug,f.api_enabled,f.created_at,f.updated_at,COUNT(s.id) AS submission_count,SUM(CASE WHEN s.status='new' THEN 1 ELSE 0 END) AS new_count FROM forms f LEFT JOIN form_submissions s ON s.form_id=f.id WHERE f.project_id=? GROUP BY f.id ORDER BY f.updated_at DESC,f.name");
    $stmt->execute([(int)$project['id']]);
    $rows = $stmt->fetchAll();
    foreach ($rows as &$row) {
        $row['id'] = (int)$row['id'];
        $row['submission_count'] = (int)$row['submission_count'];
        $row['new_count'] = (int)($row['new_count'] ?? 0);
        $row['api_enabled'] = (bool)($row['api_enabled'] ?? true);
        $row['endpoint'] = public_api_absolute_url((string)$project['slug'].'/forms/'.(string)$row['slug']);
    }
    unset($row);
    return $rows;
}

function admin_form(PDO $pdo, int $id, array $project): array
{
    $stmt = $pdo->prepare("SELECT f.*,COUNT(s.id) AS submission_count,SUM(CASE WHEN s.status='new' THEN 1 ELSE 0 END) AS new_count FROM forms f LEFT JOIN form_submissions s ON s.form_id=f.id WHERE f.id=? AND f.project_id=? GROUP BY f.id LIMIT 1");
    $stmt->execute([$id,(int)$project['id']]);
    $row = $stmt->fetch();
    if (!$row) throw new RuntimeException('Форма не найдена.');
    return [
        'id' => (int)$row['id'],
        'name' => (string)$row['name'],
        'slug' => (string)$row['slug'],
        'api_enabled' => (bool)($row['api_enabled'] ?? true),
        'schema' => sanitize_form_schema(decode_json($row['schema_json'])),
        'success_message' => (string)$row['success_message'],
        'submission_count' => (int)$row['submission_count'],
        'new_count' => (int)($row['new_count'] ?? 0),
        'created_at' => (string)$row['created_at'],
        'updated_at' => (string)$row['updated_at'],
        'endpoint' => public_api_absolute_url((string)$project['slug'].'/forms/'.(string)$row['slug']),
    ];
}

function admin_form_submissions(PDO $pdo, int $formId): array
{
    $stmt = $pdo->prepare('SELECT id,form_id,data_json,status,created_at FROM form_submissions WHERE form_id=? ORDER BY id DESC LIMIT 250');
    $stmt->execute([$formId]);
    $rows = [];
    foreach ($stmt->fetchAll() as $row) {
        $data = decode_json($row['data_json']);
        $preview = '';
        foreach ($data as $value) {
            if (is_string($value) && trim($value) !== '' && upload_filename_from_value($value) === null) { $preview = trim($value); break; }
        }
        $rows[] = [
            'id' => (int)$row['id'],
            'form_id' => (int)$row['form_id'],
            'data' => $data,
            'preview' => mb_substr($preview, 0, 120),
            'status' => $row['status'] === 'read' ? 'read' : 'new',
            'created_at' => (string)$row['created_at'],
        ];
    }
    return $rows;
}


function admin_users(PDO $pdo): array
{
    $rows = $pdo->query("SELECT id,name,email,system_role,created_at FROM users ORDER BY name,email")->fetchAll();
    foreach ($rows as &$row) $row['id'] = (int)$row['id'];
    unset($row);
    return $rows;
}

function admin_project_members(PDO $pdo, array $project): array
{
    $stmt = $pdo->prepare("SELECT u.id,u.name,u.email,u.system_role,pu.role,pu.permissions_json,pu.created_at FROM project_users pu JOIN users u ON u.id=pu.user_id WHERE pu.project_id=? ORDER BY CASE pu.role WHEN 'owner' THEN 0 WHEN 'admin' THEN 1 WHEN 'editor' THEN 2 ELSE 3 END,u.name");
    $stmt->execute([(int)$project['id']]);
    $rows = [];
    foreach ($stmt->fetchAll() as $row) {
        $role = (string)$row['role'];
        $rows[] = [
            'id'=>(int)$row['id'],'name'=>(string)$row['name'],'email'=>(string)$row['email'],'system_role'=>(string)$row['system_role'],
            'role'=>$role,'permissions'=>ProjectAccess::permissionsFromRow($role,(string)$row['permissions_json']),'created_at'=>(string)$row['created_at'],
        ];
    }
    return $rows;
}

function list_upload_files_for_project(PDO $pdo, array $project, array $user): array
{
    $docIds = [];
    $ids = ProjectAccess::folderIds($pdo, $project, true);
    if ($ids) {
        $ph = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $pdo->prepare("SELECT id FROM documents WHERE folder_id IN ($ph)");
        $stmt->execute($ids);
        $docIds = array_fill_keys(array_map('intval', array_column($stmt->fetchAll(),'id')), true);
    }
    $stmt = $pdo->prepare('SELECT id FROM forms WHERE project_id=?');
    $stmt->execute([(int)$project['id']]);
    $formIds = array_fill_keys(array_map('intval', array_column($stmt->fetchAll(),'id')), true);
    $stmt = $pdo->prepare('SELECT id FROM data_sets WHERE project_id=?');
    $stmt->execute([(int)$project['id']]);
    $dataSetIds = array_fill_keys(array_map('intval', array_column($stmt->fetchAll(),'id')), true);
    $out = [];
    foreach (list_upload_files($pdo) as $file) {
        $refs = array_values(array_filter((array)($file['references'] ?? []), static function(array $ref) use ($docIds,$formIds,$dataSetIds): bool {
            if (!empty($ref['document_id']) && isset($docIds[(int)$ref['document_id']])) return true;
            if (!empty($ref['form_id']) && isset($formIds[(int)$ref['form_id']])) return true;
            if (!empty($ref['data_set_id']) && isset($dataSetIds[(int)$ref['data_set_id']])) return true;
            return false;
        }));
        if (!$refs) {
            if (!(ProjectAccess::isSystemOwner($user) && (int)($file['reference_count'] ?? 0) === 0)) continue;
            $file['references'] = [];
            $file['usage_count'] = 0; $file['history_usage_count'] = 0; $file['form_usage_count'] = 0; $file['reference_count'] = 0;
            $out[] = $file;
            continue;
        }
        $file['references'] = $refs;
        $primary = $refs[0];
        $file['document_id'] = $primary['document_id'] ?? null;
        $file['document_name'] = $primary['document_name'] ?? null;
        $file['form_id'] = $primary['form_id'] ?? null;
        $file['form_name'] = $primary['form_name'] ?? null;
        $file['submission_id'] = $primary['submission_id'] ?? null;
        $file['data_set_id'] = $primary['data_set_id'] ?? null;
        $file['data_set_name'] = $primary['data_set_name'] ?? null;
        $out[] = $file;
    }
    return $out;
}


function global_search_text(mixed $value): string
{
    if (is_array($value)) {
        $parts = [];
        array_walk_recursive($value, static function ($item) use (&$parts): void {
            if (is_scalar($item) && !is_bool($item)) $parts[] = (string)$item;
        });
        return implode(' ', $parts);
    }
    if (is_scalar($value) && !is_bool($value)) return (string)$value;
    return '';
}

function global_search_match(string $haystack, string $query): bool
{
    return $query !== '' && mb_stripos($haystack, $query, 0, 'UTF-8') !== false;
}

function admin_global_search(PDO $pdo, array $user, array $project, string $query): array
{
    $query = trim($query);
    if (mb_strlen($query) < 2) return [];

    $results = [];
    $push = static function (array $item) use (&$results): void {
        $key = ($item['type'] ?? '') . ':' . ($item['id'] ?? ($item['key'] ?? ''));
        foreach ($results as $existing) {
            $existingKey = ($existing['type'] ?? '') . ':' . ($existing['id'] ?? ($existing['key'] ?? ''));
            if ($existingKey === $key) return;
        }
        if (count($results) < 60) $results[] = $item;
    };

    $projectId = (int)$project['id'];

    if (ProjectAccess::can($pdo, $user, $projectId, 'content.view')) {
        $ids = feather_visible_folder_ids($pdo, $project);
        $rootId = ProjectAccess::rootFolderId($project);
        if ($ids) {
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $stmt = $pdo->prepare("SELECT id,parent_id,name FROM folders WHERE id IN ($placeholders) AND id<>? ORDER BY name");
            $stmt->execute(array_merge($ids, [$rootId]));
            foreach ($stmt->fetchAll() as $row) {
                if (global_search_match((string)$row['name'], $query)) {
                    $push(['type'=>'folder','category'=>'Папки','id'=>(int)$row['id'],'title'=>(string)$row['name'],'subtitle'=>'Папка контента','icon'=>'bi-folder-fill']);
                }
            }

            $stmt = $pdo->prepare("SELECT id,folder_id,name,data_json FROM documents WHERE folder_id IN ($placeholders) ORDER BY name");
            $stmt->execute($ids);
            $documentNames = [];
            foreach ($stmt->fetchAll() as $row) {
                $documentId = (int)$row['id'];
                $documentNames[$documentId] = (string)$row['name'];
                $nameMatch = global_search_match((string)$row['name'], $query);
                $contentMatch = !$nameMatch && global_search_match(global_search_text(decode_json((string)$row['data_json'])), $query);
                if ($nameMatch || $contentMatch) {
                    $push(['type'=>'document','category'=>'Разделы','id'=>$documentId,'title'=>(string)$row['name'],'subtitle'=>$contentMatch ? 'Совпадение внутри контента' : 'Раздел сайта','icon'=>'bi-file-earmark-text-fill']);
                }
            }

            // Search translated document values as well. The result still opens the
            // document itself; language selection remains inside the editor.
            if ($documentNames) {
                $docPlaceholders = implode(',', array_fill(0, count($documentNames), '?'));
                $translated = $pdo->prepare("SELECT document_id,language,data_json FROM document_translations WHERE document_id IN ($docPlaceholders)");
                $translated->execute(array_keys($documentNames));
                foreach ($translated->fetchAll() as $row) {
                    if (!global_search_match(global_search_text(decode_json((string)$row['data_json'])), $query)) continue;
                    $documentId = (int)$row['document_id'];
                    $push(['type'=>'document','category'=>'Разделы','id'=>$documentId,'title'=>$documentNames[$documentId] ?? ('Раздел #'.$documentId),'subtitle'=>'Совпадение в переводе · '.strtoupper((string)$row['language']),'icon'=>'bi-translate']);
                }
            }
        }
    }

    if (ProjectAccess::can($pdo, $user, $projectId, 'data.view')) {
        $stmt = $pdo->prepare('SELECT id,name,slug,mode,data_json FROM data_sets WHERE project_id=? ORDER BY name');
        $stmt->execute([$projectId]);
        foreach ($stmt->fetchAll() as $row) {
            $nameMatch = global_search_match((string)$row['name'] . ' ' . (string)$row['slug'], $query);
            $contentMatch = !$nameMatch && global_search_match(global_search_text(decode_json((string)$row['data_json'])), $query);
            if ($nameMatch || $contentMatch) {
                $push(['type'=>'data','category'=>'Данные','id'=>(int)$row['id'],'title'=>(string)$row['name'],'subtitle'=>($row['mode']==='multiple'?'Multiple':'Single') . ($contentMatch?' · совпадение в записи':''),'icon'=>'bi-database-fill']);
            }
        }
    }

    if (ProjectAccess::can($pdo, $user, $projectId, 'forms.view')) {
        $stmt = $pdo->prepare('SELECT id,name,slug,schema_json FROM forms WHERE project_id=? ORDER BY name');
        $stmt->execute([$projectId]);
        $forms = $stmt->fetchAll();
        $formNames = [];
        foreach ($forms as $row) {
            $formNames[(int)$row['id']] = (string)$row['name'];
            if (global_search_match((string)$row['name'] . ' ' . (string)$row['slug'] . ' ' . global_search_text(decode_json((string)$row['schema_json'])), $query)) {
                $push(['type'=>'form','category'=>'Формы','id'=>(int)$row['id'],'title'=>(string)$row['name'],'subtitle'=>'Форма','icon'=>'bi-ui-checks-grid']);
            }
        }
        if ($formNames) {
            $placeholders = implode(',', array_fill(0, count($formNames), '?'));
            $stmt = $pdo->prepare("SELECT id,form_id,data_json FROM form_submissions WHERE form_id IN ($placeholders) ORDER BY id DESC LIMIT 300");
            $stmt->execute(array_keys($formNames));
            foreach ($stmt->fetchAll() as $row) {
                if (global_search_match(global_search_text(decode_json((string)$row['data_json'])), $query)) {
                    $formId = (int)$row['form_id'];
                    $push(['type'=>'form','category'=>'Формы','id'=>$formId,'title'=>$formNames[$formId] ?? 'Форма','subtitle'=>'Совпадение во входящей заявке','icon'=>'bi-inbox-fill']);
                }
            }
        }
    }

    if (ProjectAccess::can($pdo, $user, $projectId, 'files.view')) {
        foreach (list_upload_files_for_project($pdo, $project, $user) as $file) {
            $haystack = implode(' ', array_filter([
                $file['name'] ?? '', $file['mime'] ?? '', $file['extension'] ?? '',
                $file['document_name'] ?? '', $file['data_set_name'] ?? '', $file['form_name'] ?? ''
            ]));
            if (!global_search_match($haystack, $query)) continue;
            $push([
                'type'=>'file','category'=>'Файлы','key'=>(string)($file['name'] ?? ''),
                'title'=>(string)($file['name'] ?? 'Файл'),
                'subtitle'=>(string)($file['document_name'] ?? $file['data_set_name'] ?? $file['form_name'] ?? 'Файл проекта'),
                'icon'=>($file['kind'] ?? '') === 'image' ? 'bi-image-fill' : (($file['kind'] ?? '') === 'video' ? 'bi-camera-video-fill' : (($file['kind'] ?? '') === 'audio' ? 'bi-music-note-beamed' : 'bi-file-earmark-fill')),
                'file'=>$file,
            ]);
        }
    }

    if (ProjectAccess::isSystemOwner($user)) {
        foreach (ProjectAccess::listProjects($pdo, $user) as $row) {
            if (global_search_match((string)$row['name'] . ' ' . (string)$row['slug'], $query)) {
                $push(['type'=>'project','category'=>'Настройки','id'=>(int)$row['id'],'title'=>(string)$row['name'],'subtitle'=>'Проект','icon'=>'bi-layers-fill']);
            }
        }
        foreach (admin_users($pdo) as $row) {
            if (global_search_match((string)$row['name'] . ' ' . (string)$row['email'], $query)) {
                $push(['type'=>'user','category'=>'Настройки','id'=>(int)$row['id'],'title'=>(string)$row['name'],'subtitle'=>(string)$row['email'],'icon'=>'bi-person-fill']);
            }
        }
    }

    return $results;
}


function clipboard_accessible_project(PDO $pdo, array $user, int $projectId): array
{
    foreach (ProjectAccess::listProjects($pdo, $user) as $candidate) {
        if ((int)($candidate['id'] ?? 0) === $projectId) return $candidate;
    }
    throw new RuntimeException('Исходный проект недоступен.');
}

function clipboard_sibling_name_exists(PDO $pdo, string $type, int $parentId, string $name, ?int $ignoreId = null): bool
{
    $table = $type === 'folder' ? 'folders' : 'documents';
    $parentColumn = $type === 'folder' ? 'parent_id' : 'folder_id';
    $sql = "SELECT id FROM {$table} WHERE {$parentColumn}=? AND name=?";
    $args = [$parentId, $name];
    if ($ignoreId !== null) {
        $sql .= ' AND id<>?';
        $args[] = $ignoreId;
    }
    $sql .= ' LIMIT 1';
    $stmt = $pdo->prepare($sql);
    $stmt->execute($args);
    return (bool)$stmt->fetchColumn();
}

function clipboard_unique_name(PDO $pdo, string $type, int $parentId, string $name, bool $copy, ?int $ignoreId = null): string
{
    $name = trim($name);
    if ($name === '') $name = $type === 'folder' ? 'Новая папка' : 'Новый раздел';
    if (!clipboard_sibling_name_exists($pdo, $type, $parentId, $name, $ignoreId)) return $name;

    if ($copy) {
        $base = preg_replace('/\s+—\s+копия(?:\s+\(\d+\))?$/u', '', $name) ?: $name;
        $candidate = $base . ' — копия';
        if (!clipboard_sibling_name_exists($pdo, $type, $parentId, $candidate, $ignoreId)) return $candidate;
        for ($i = 2; $i < 10000; $i++) {
            $candidate = $base . ' — копия (' . $i . ')';
            if (!clipboard_sibling_name_exists($pdo, $type, $parentId, $candidate, $ignoreId)) return $candidate;
        }
    } else {
        for ($i = 2; $i < 10000; $i++) {
            $candidate = $name . ' (' . $i . ')';
            if (!clipboard_sibling_name_exists($pdo, $type, $parentId, $candidate, $ignoreId)) return $candidate;
        }
    }

    throw new RuntimeException('Не удалось подобрать свободное имя.');
}

function clipboard_folder_contains(PDO $pdo, int $ancestorId, int $candidateId): bool
{
    $current = $candidateId;
    $guard = 0;
    while ($current > 0 && $guard++ < 500) {
        if ($current === $ancestorId) return true;
        $stmt = $pdo->prepare('SELECT parent_id FROM folders WHERE id=? LIMIT 1');
        $stmt->execute([$current]);
        $parent = $stmt->fetchColumn();
        if ($parent === false || $parent === null) return false;
        $current = (int)$parent;
    }
    return false;
}

function clipboard_copy_document(PDO $pdo, int $documentId, int $destinationFolderId, bool $copyName = true): int
{
    $stmt = $pdo->prepare('SELECT id,name,mode,api_enabled,api_tree_visible,schema_json,data_json FROM documents WHERE id=? LIMIT 1');
    $stmt->execute([$documentId]);
    $source = $stmt->fetch();
    if (!$source) throw new RuntimeException('Раздел не найден.');

    $name = clipboard_unique_name($pdo, 'document', $destinationFolderId, (string)$source['name'], $copyName);
    $slug = unique_slug($pdo, 'documents', $name, $destinationFolderId);
    $insert = $pdo->prepare('INSERT INTO documents(folder_id,name,slug,mode,api_enabled,api_tree_visible,schema_json,data_json) VALUES(?,?,?,?,?,?,?,?)');
    $insert->execute([
        $destinationFolderId,
        $name,
        $slug,
        sanitize_document_mode($source['mode'] ?? 'single'),
        (int)($source['api_enabled'] ?? 1),
        (int)($source['api_tree_visible'] ?? 1),
        (string)$source['schema_json'],
        (string)$source['data_json'],
    ]);
    $newId = Database::lastInsertId($pdo);

    $translations = $pdo->prepare('SELECT language,data_json FROM document_translations WHERE document_id=? ORDER BY language');
    $translations->execute([$documentId]);
    $translationRows = $translations->fetchAll();
    foreach ($translationRows as $translation) {
        $pdo->prepare('INSERT INTO document_translations(document_id,language,data_json) VALUES(?,?,?)')
            ->execute([$newId, (string)$translation['language'], (string)$translation['data_json']]);
    }

    // A copied document starts with a clean version history containing its current state.
    append_revision_if_new($pdo, $newId, (string)$source['schema_json'], (string)$source['data_json']);
    foreach ($translationRows as $translation) {
        append_revision_if_new(
            $pdo,
            $newId,
            (string)$source['schema_json'],
            (string)$translation['data_json'],
            (string)$translation['language']
        );
    }

    return $newId;
}

function clipboard_copy_folder_tree(PDO $pdo, int $folderId, int $destinationFolderId, bool $copyTopName = true): int
{
    $stmt = $pdo->prepare('SELECT id,name,sort_order,api_enabled,api_tree_visible,api_tree_depth,api_tree_include_data,api_tree_cache_ttl FROM folders WHERE id=? LIMIT 1');
    $stmt->execute([$folderId]);
    $source = $stmt->fetch();
    if (!$source) throw new RuntimeException('Папка не найдена.');

    $name = clipboard_unique_name($pdo, 'folder', $destinationFolderId, (string)$source['name'], $copyTopName);
    $slug = unique_slug($pdo, 'folders', $name, $destinationFolderId);
    $pdo->prepare('INSERT INTO folders(parent_id,name,slug,sort_order,api_enabled,api_tree_visible,api_tree_depth,api_tree_include_data,api_tree_cache_ttl) VALUES(?,?,?,?,?,?,?,?,?)')
        ->execute([$destinationFolderId, $name, $slug, (int)($source['sort_order'] ?? 100), (int)($source['api_enabled'] ?? 1), (int)($source['api_tree_visible'] ?? 1), (int)($source['api_tree_depth'] ?? 0), (int)($source['api_tree_include_data'] ?? 1), (int)($source['api_tree_cache_ttl'] ?? 60)]);
    $newFolderId = Database::lastInsertId($pdo);

    $linkStmt=$pdo->prepare('SELECT project_id,resource_type,resource_id,sort_order FROM content_links WHERE folder_id=? ORDER BY sort_order,id');
    $linkStmt->execute([$folderId]);
    foreach($linkStmt->fetchAll() as $link){
        Database::insertIgnore($pdo,'content_links',[
            'project_id'=>(int)$link['project_id'],'folder_id'=>$newFolderId,'resource_type'=>(string)$link['resource_type'],
            'resource_id'=>(int)$link['resource_id'],'sort_order'=>(int)($link['sort_order']??100),
        ]);
    }

    $docs = $pdo->prepare('SELECT id FROM documents WHERE folder_id=? ORDER BY id');
    $docs->execute([$folderId]);
    foreach ($docs->fetchAll() as $doc) {
        clipboard_copy_document($pdo, (int)$doc['id'], $newFolderId, false);
    }

    $children = $pdo->prepare('SELECT id FROM folders WHERE parent_id=? ORDER BY sort_order,name,id');
    $children->execute([$folderId]);
    foreach ($children->fetchAll() as $child) {
        clipboard_copy_folder_tree($pdo, (int)$child['id'], $newFolderId, false);
    }

    return $newFolderId;
}


$initialPayload = $method === 'POST' ? api_payload() : [];
$requestedProjectId = $_GET['project_id'] ?? ($initialPayload['project_id'] ?? null);
$project = ProjectAccess::currentProject($pdo, $user, $requestedProjectId);

try {
    if ($method === 'GET') {
        if ($action === 'state') json_response(admin_state($pdo, $user, $project));
        if ($action === 'forms') { ProjectAccess::requirePermission($pdo,$user,(int)$project['id'],'forms.view'); json_response(['ok' => true, 'forms' => admin_forms($pdo,$project)]); }
        if ($action === 'data_sets') { ProjectAccess::requirePermission($pdo,$user,(int)$project['id'],'data.view'); json_response(['ok'=>true,'data_sets'=>admin_data_sets($pdo,$project)]); }
        if ($action === 'content_link_options') {
            ProjectAccess::requirePermission($pdo,$user,(int)$project['id'],'content.edit');
            $type = (string)($_GET['type'] ?? '');
            if (!in_array($type,['data','form'],true)) throw new RuntimeException('Неизвестный тип ресурса.');
            $folderId = (int)($_GET['folder_id'] ?? 0);
            $folderId = $folderId > 0 ? $folderId : ProjectAccess::rootFolderId($project);
            if (!ProjectAccess::containsFolder($pdo,$project,$folderId)) throw new RuntimeException('Папка не найдена.');
            if ($type === 'data') {
                ProjectAccess::requirePermission($pdo,$user,(int)$project['id'],'data.view');
                $items = admin_data_sets($pdo,$project);
            } else {
                ProjectAccess::requirePermission($pdo,$user,(int)$project['id'],'forms.view');
                $items = admin_forms($pdo,$project);
            }
            $stmt=$pdo->prepare('SELECT resource_id FROM content_links WHERE project_id=? AND folder_id=? AND resource_type=? ORDER BY id');
            $stmt->execute([(int)$project['id'],$folderId,$type]);
            $selected=array_map('intval',$stmt->fetchAll(PDO::FETCH_COLUMN));
            json_response(['ok'=>true,'type'=>$type,'items'=>$items,'selected'=>$selected]);
        }
        if ($action === 'data_set') { $id=(int)($_GET['id']??0); ProjectAccess::requirePermission($pdo,$user,(int)$project['id'],'data.view'); json_response(['ok'=>true,'data_set'=>admin_data_set($pdo,$id,$project)]); }
        if ($action === 'data_relation_records') { $sourceId=(int)($_GET['source_id']??0); $displayField=trim((string)($_GET['display_field']??'')); ProjectAccess::requirePermission($pdo,$user,(int)$project['id'],'data.view'); json_response(['ok'=>true,'source'=>admin_relation_records($pdo,$project,$sourceId,$displayField)]); }
        if ($action === 'data_set_dependencies') { $id=(int)($_GET['id']??0); ProjectAccess::requirePermission($pdo,$user,(int)$project['id'],'data.view'); json_response(['ok'=>true,'dependencies'=>data_set_relation_dependencies($pdo,(int)$project['id'],$id)]); }
        if ($action === 'global_search') { json_response(['ok'=>true,'results'=>admin_global_search($pdo,$user,$project,(string)($_GET['q']??''))]); }
        if ($action === 'settings') { ProjectAccess::requirePermission($pdo,$user,(int)$project['id'],'settings.view'); json_response(['ok' => true, 'i18n' => cms_i18n_settings($pdo,(int)$project['id']), 'api_access' => project_api_access($pdo,(int)$project['id']), 'api_status' => project_api_status($pdo,(int)$project['id']), 'api_response' => project_api_response($pdo,(int)$project['id']), 'languages' => cms_language_catalog(), 'database' => Database::publicInfo(), 'database_availability' => Database::driverAvailability()]); }
        if ($action === 'form') {
            $id = (int)($_GET['id'] ?? 0);
            ProjectAccess::requirePermission($pdo,$user,(int)$project['id'],'forms.view'); json_response(['ok' => true, 'form' => admin_form($pdo, $id, $project)]);
        }
        if ($action === 'form_submissions') {
            $id = (int)($_GET['form_id'] ?? 0);
            $check=$pdo->prepare('SELECT 1 FROM forms WHERE id=? AND project_id=?'); $check->execute([$id,(int)$project['id']]); if(!$check->fetchColumn()) throw new RuntimeException('Форма не найдена.'); ProjectAccess::requirePermission($pdo,$user,(int)$project['id'],'forms.view'); json_response(['ok' => true, 'submissions' => admin_form_submissions($pdo, $id)]);
        }
        if ($action === 'folder_api_preview') {
            $id = (int)($_GET['id'] ?? 0);
            ProjectAccess::requirePermission($pdo,$user,(int)$project['id'],'content.view');
            $stmt=$pdo->prepare('SELECT * FROM folders WHERE id=? LIMIT 1');$stmt->execute([$id]);$folder=$stmt->fetch();
            if(!$folder || !ProjectAccess::containsFolder($pdo,$project,$id)) throw new RuntimeException('Папка не найдена.');
            $settings=folder_api_tree_settings($folder, true);
            json_response([
                'ok'=>true,
                'tree'=>admin_folder_api_tree($pdo,$project,$id,$settings),
                'settings'=>$settings,
                'cache_backend'=>api_tree_apcu_available() ? 'APCu + file fallback' : 'File cache',
            ]);
        }
        if ($action === 'document') {
            $id = (int)($_GET['id'] ?? 0);
            ProjectAccess::requirePermission($pdo,$user,(int)$project['id'],'content.view'); json_response(['ok' => true, 'document' => admin_document($pdo, $id, $project)]);
        }
        if ($action === 'files') { ProjectAccess::requirePermission($pdo,$user,(int)$project['id'],'files.view'); json_response(['ok' => true, 'files' => list_upload_files_for_project($pdo,$project,$user)]); }
        if ($action === 'versions') {
            $id = (int)($_GET['id'] ?? 0);
            if (!ProjectAccess::containsDocument($pdo,$project,$id)) throw new RuntimeException('Раздел не найден.');
            ProjectAccess::requirePermission($pdo,$user,(int)$project['id'],'content.view');
            $language = revision_language_key((string)($_GET['language'] ?? ''));
            json_response(['ok' => true, 'versions' => admin_versions($pdo, $id, $language)]);
        }
        if ($action === 'projects') json_response(['ok'=>true,'projects'=>ProjectAccess::listProjects($pdo,$user),'current_project'=>$project]);
        if ($action === 'project_members') { ProjectAccess::requirePermission($pdo,$user,(int)$project['id'],'members.view'); $available=[]; if(ProjectAccess::can($pdo,$user,(int)$project['id'],'members.manage')) $available=$pdo->query("SELECT id,name,email,system_role FROM users ORDER BY name,email")->fetchAll(); foreach($available as &$au){$au['id']=(int)$au['id'];} unset($au); json_response(['ok'=>true,'members'=>admin_project_members($pdo,$project),'available_users'=>$available]); }
        if ($action === 'users') { if(!ProjectAccess::isSystemOwner($user)) throw new RuntimeException('Управление пользователями доступно владельцу CMS.'); json_response(['ok'=>true,'users'=>admin_users($pdo)]); }
        json_response(['ok' => false, 'error' => 'Unknown action'], 404);
    }

    if ($method !== 'POST') json_response(['ok' => false, 'error' => 'Method not allowed'], 405);
    api_verify_csrf();
    $payload = api_payload();
    // Any admin POST may affect content, translations, structure or API switches.
    // Invalidate prepared folder trees up front so no successful write can leave stale JSON.
    try { bump_all_project_api_tree_revisions($pdo); } catch (Throwable) {}

    if ($action === 'logout') {
        Auth::logout();
        json_response(['ok' => true]);
    }

    if ($action === 'switch_database') {
        if (!ProjectAccess::isSystemOwner($user)) throw new RuntimeException('Менять системную базу данных может только владелец MaterCMS.');
        $target = Database::configurationFromInput($payload);
        $info = Database::migrateToConfiguration($target);
        json_response([
            'ok'=>true,
            'message'=>'База данных подключена. Все данные MaterCMS перенесены и проверены.',
            'database'=>$info,
            'database_availability'=>Database::driverAvailability(),
        ]);
    }

    if ($action === 'switch_project') {
        $target = ProjectAccess::currentProject($pdo,$user,(int)($payload['project_id'] ?? 0));
        json_response(['ok'=>true,'message'=>'Проект открыт.','state'=>admin_state($pdo,$user,$target)]);
    }

    if ($action === 'create_project') {
        if (!ProjectAccess::isSystemOwner($user)) throw new RuntimeException('Создавать проекты может владелец CMS.');
        $name = trim((string)($payload['name'] ?? ''));
        if ($name === '') throw new RuntimeException('Введите название проекта.');
        $slug = ProjectAccess::uniqueProjectSlug($pdo,$name);
        $pdo->beginTransaction();
        try {
            $pdo->prepare('INSERT INTO projects(name,slug,created_by) VALUES(?,?,?)')->execute([$name,$slug,(int)$user['id']]);
            $id = Database::lastInsertId($pdo);
            $pdo->prepare('INSERT INTO folders(parent_id,name,slug,sort_order) VALUES(NULL,?,?,0)')->execute([$name,'__project_'.$id.'__']);
            $rootId = Database::lastInsertId($pdo);
            $pdo->prepare('UPDATE projects SET root_folder_id=? WHERE id=?')->execute([$rootId,$id]);
            $pdo->prepare("INSERT INTO project_users(project_id,user_id,role,permissions_json) VALUES(?,?, 'owner','{}')")->execute([$id,(int)$user['id']]);
            $default = ['enabled'=>false,'default_language'=>'ru','languages'=>['ru']];
            Database::upsert($pdo, 'project_settings', ['project_id'=>$id,'key'=>'i18n'], ['value_json'=>json_encode($default,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)]);
            Database::upsert($pdo, 'project_settings', ['project_id'=>$id,'key'=>'api_access'], ['value_json'=>json_encode(['mode'=>'public','token_hash'=>'','token_prefix'=>'','created_at'=>null],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)]);
            Database::upsert($pdo, 'project_settings', ['project_id'=>$id,'key'=>'api_response'], ['value_json'=>json_encode(['full_response'=>false,'updated_at'=>null],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)]);
            Database::upsert($pdo, 'project_settings', ['project_id'=>$id,'key'=>'api_status'], ['value_json'=>json_encode(['enabled'=>true,'updated_at'=>null],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)]);
            Database::upsert($pdo, 'project_settings', ['project_id'=>$id,'key'=>'api_tree_revision'], ['value_json'=>json_encode(['token'=>'1'],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)]);
            $pdo->commit();
        } catch(Throwable $e) { if($pdo->inTransaction()) $pdo->rollBack(); throw $e; }
        $target = ProjectAccess::currentProject($pdo,$user,$id);
        json_response(['ok'=>true,'message'=>'Проект создан.','state'=>admin_state($pdo,$user,$target)]);
    }

    if ($action === 'rename_project') {
        ProjectAccess::requirePermission($pdo,$user,(int)$project['id'],'project.edit');
        $name = trim((string)($payload['name'] ?? ''));
        if ($name === '') throw new RuntimeException('Введите название проекта.');
        // Keep the public API namespace stable after a project is connected to a frontend.
        $pdo->prepare('UPDATE projects SET name=?,updated_at=CURRENT_TIMESTAMP WHERE id=?')->execute([$name,(int)$project['id']]);
        $pdo->prepare('UPDATE folders SET name=? WHERE id=?')->execute([$name,ProjectAccess::rootFolderId($project)]);
        $target = ProjectAccess::currentProject($pdo,$user,(int)$project['id']);
        json_response(['ok'=>true,'message'=>'Проект переименован.','state'=>admin_state($pdo,$user,$target)]);
    }

    if ($action === 'delete_project') {
        if (!ProjectAccess::isSystemOwner($user)) throw new RuntimeException('Удалять проекты может владелец CMS.');
        $count = (int)$pdo->query('SELECT COUNT(*) FROM projects')->fetchColumn();
        if ($count <= 1) throw new RuntimeException('Нельзя удалить единственный проект.');
        $candidateFiles = array_keys(referenced_upload_filenames($pdo,true));
        $rootId = ProjectAccess::rootFolderId($project);
        $pdo->beginTransaction();
        try {
            $pdo->prepare('DELETE FROM forms WHERE project_id=?')->execute([(int)$project['id']]);
            $pdo->prepare('DELETE FROM project_settings WHERE project_id=?')->execute([(int)$project['id']]);
            $pdo->prepare('DELETE FROM project_users WHERE project_id=?')->execute([(int)$project['id']]);
            $pdo->prepare('DELETE FROM projects WHERE id=?')->execute([(int)$project['id']]);
            $pdo->prepare('DELETE FROM folders WHERE id=?')->execute([$rootId]);
            $pdo->commit();
        } catch(Throwable $e) { if($pdo->inTransaction()) $pdo->rollBack(); throw $e; }
        cleanup_orphan_uploads($pdo,$candidateFiles);
        unset($_SESSION['project_id']);
        $next = ProjectAccess::currentProject($pdo,$user,null);
        json_response(['ok'=>true,'message'=>'Проект удалён.','state'=>admin_state($pdo,$user,$next)]);
    }

    if ($action === 'create_user') {
        if (!ProjectAccess::isSystemOwner($user)) throw new RuntimeException('Создавать пользователей может владелец CMS.');
        $name = trim((string)($payload['name'] ?? ''));
        $email = mb_strtolower(trim((string)($payload['email'] ?? '')));
        $password = (string)($payload['password'] ?? '');
        if ($name === '') throw new RuntimeException('Введите имя пользователя.');
        if (!filter_var($email,FILTER_VALIDATE_EMAIL)) throw new RuntimeException('Укажите корректный email.');
        if (strlen($password) < 8) throw new RuntimeException('Пароль должен содержать минимум 8 символов.');
        try {
            $stmt=$pdo->prepare('INSERT INTO users(name,email,password_hash,system_role) VALUES(?,?,?,?)');
            $stmt->execute([$name,$email,password_hash($password,PASSWORD_DEFAULT),'user']);
            $id=Database::lastInsertId($pdo);
        } catch(PDOException $e) {
            throw new RuntimeException('Пользователь с таким email уже существует.');
        }
        json_response(['ok'=>true,'id'=>$id,'message'=>'Пользователь создан. Назначьте ему проект и роль в разделе «Проекты».','users'=>admin_users($pdo)]);
    }

    if ($action === 'update_user') {
        if (!ProjectAccess::isSystemOwner($user)) throw new RuntimeException('Редактировать пользователей может владелец CMS.');
        $id=(int)($payload['id']??0);
        $stmt=$pdo->prepare('SELECT * FROM users WHERE id=? LIMIT 1'); $stmt->execute([$id]); $target=$stmt->fetch();
        if(!$target) throw new RuntimeException('Пользователь не найден.');
        $name=trim((string)($payload['name']??$target['name']));
        $email=mb_strtolower(trim((string)($payload['email']??$target['email'])));
        $password=(string)($payload['password']??'');
        if($name===''||!filter_var($email,FILTER_VALIDATE_EMAIL)) throw new RuntimeException('Проверьте имя и email.');
        try {
            if($password!=='') {
                if(strlen($password)<8) throw new RuntimeException('Пароль должен содержать минимум 8 символов.');
                $pdo->prepare('UPDATE users SET name=?,email=?,password_hash=? WHERE id=?')->execute([$name,$email,password_hash($password,PASSWORD_DEFAULT),$id]);
            } else {
                $pdo->prepare('UPDATE users SET name=?,email=? WHERE id=?')->execute([$name,$email,$id]);
            }
        } catch(PDOException $e) {
            throw new RuntimeException('Пользователь с таким email уже существует.');
        }
        json_response(['ok'=>true,'message'=>'Пользователь обновлён. Проектные роли и права не изменены.','users'=>admin_users($pdo)]);
    }

    if ($action === 'delete_user') {
        if (!ProjectAccess::isSystemOwner($user)) throw new RuntimeException('Удалять пользователей может владелец CMS.');
        $id=(int)($payload['id']??0);
        if($id===(int)$user['id']) throw new RuntimeException('Нельзя удалить собственную учётную запись.');
        $stmt=$pdo->prepare("SELECT system_role FROM users WHERE id=? LIMIT 1"); $stmt->execute([$id]); $role=$stmt->fetchColumn();
        if($role===false) throw new RuntimeException('Пользователь не найден.');
        if($role==='owner') throw new RuntimeException('Нельзя удалить владельца CMS.');
        $pdo->prepare('DELETE FROM users WHERE id=?')->execute([$id]);
        json_response(['ok'=>true,'message'=>'Пользователь удалён.','users'=>admin_users($pdo)]);
    }

    if ($action === 'save_project_member') {
        ProjectAccess::requirePermission($pdo,$user,(int)$project['id'],'members.manage');
        $userId=(int)($payload['user_id']??0);
        $role=(string)($payload['role']??'viewer');
        $currentMembership=$pdo->prepare('SELECT role FROM project_users WHERE project_id=? AND user_id=? LIMIT 1');
        $currentMembership->execute([(int)$project['id'],$userId]);
        $currentRole=$currentMembership->fetchColumn();
        if($currentRole==='owner' && !ProjectAccess::isSystemOwner($user)) throw new RuntimeException('Изменять права владельца может только владелец CMS.');
        if(!in_array($role,['owner','admin','editor','viewer'],true)) $role='viewer';
        if($role==='owner' && !ProjectAccess::isSystemOwner($user)) throw new RuntimeException('Назначить владельца может только владелец CMS.');
        $exists=$pdo->prepare('SELECT 1 FROM users WHERE id=?'); $exists->execute([$userId]); if(!$exists->fetchColumn()) throw new RuntimeException('Пользователь не найден.');
        $raw=$payload['permissions']??[]; if(is_string($raw)) $raw=json_decode($raw,true); if(!is_array($raw)) $raw=[];
        $defaults=ProjectAccess::roleDefaults($role); $overrides=[];
        foreach(ProjectAccess::PERMISSIONS as $permission){ if(array_key_exists($permission,$raw) && (bool)$raw[$permission] !== (bool)($defaults[$permission]??false)) $overrides[$permission]=(bool)$raw[$permission]; }
        Database::upsert($pdo, 'project_users',
            ['project_id'=>(int)$project['id'],'user_id'=>$userId],
            ['role'=>$role,'permissions_json'=>json_encode($overrides,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)]
        );
        json_response(['ok'=>true,'message'=>'Права сохранены.','members'=>admin_project_members($pdo,$project)]);
    }

    if ($action === 'remove_project_member') {
        ProjectAccess::requirePermission($pdo,$user,(int)$project['id'],'members.manage');
        $userId=(int)($payload['user_id']??0);
        $membership=$pdo->prepare('SELECT role FROM project_users WHERE project_id=? AND user_id=? LIMIT 1');
        $membership->execute([(int)$project['id'],$userId]);
        $targetRole=$membership->fetchColumn();
        if($targetRole==='owner' && !ProjectAccess::isSystemOwner($user)) throw new RuntimeException('Удалить владельца из проекта может только владелец CMS.');
        if($userId===(int)$user['id'] && ($project['role']??'')==='owner') throw new RuntimeException('Владелец не может удалить себя из проекта.');
        $pdo->prepare('DELETE FROM project_users WHERE project_id=? AND user_id=?')->execute([(int)$project['id'],$userId]);
        json_response(['ok'=>true,'message'=>'Пользователь удалён из проекта.','members'=>admin_project_members($pdo,$project)]);
    }

    if ($action === 'set_api_access') {
        ProjectAccess::requirePermission($pdo,$user,(int)$project['id'],'settings.edit');
        $mode = (string)($payload['mode'] ?? 'public') === 'private' ? 'private' : 'public';
        $current = project_api_access_record($pdo, (int)$project['id']);
        $token = null;
        if ($mode === 'private') {
            if ($current['mode'] === 'private' && $current['token_hash'] !== '') {
                $access = project_api_access($pdo, (int)$project['id']);
            } else {
                $saved = save_project_api_access($pdo, (int)$project['id'], 'private');
                $access = $saved['access'];
                $token = $saved['token'];
            }
        } else {
            $saved = save_project_api_access($pdo, (int)$project['id'], 'public');
            $access = $saved['access'];
        }
        json_response([
            'ok' => true,
            'message' => $mode === 'private' ? 'Приватный API включён.' : 'API проекта теперь публичный.',
            'api_access' => $access,
            'token' => $token,
            'state' => admin_state($pdo, $user, $project),
        ]);
    }

    if ($action === 'set_project_api') {
        ProjectAccess::requirePermission($pdo,$user,(int)$project['id'],'settings.edit');
        $enabled = filter_var($payload['enabled'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $status = save_project_api_status($pdo, (int)$project['id'], $enabled);
        json_response([
            'ok'=>true,
            'message'=>$enabled ? 'API проекта включён.' : 'API проекта временно выключен.',
            'api_status'=>$status,
            'state'=>admin_state($pdo,$user,$project),
        ]);
    }

    if ($action === 'set_folder_api') {
        ProjectAccess::requirePermission($pdo,$user,(int)$project['id'],'content.edit');
        $id=(int)($payload['id']??0); $enabled=filter_var($payload['enabled']??false,FILTER_VALIDATE_BOOLEAN)?1:0;
        if ($id<=0 || !ProjectAccess::containsFolder($pdo,$project,$id)) throw new RuntimeException('Папка не найдена.');
        $stmt=$pdo->prepare('UPDATE folders SET api_enabled=?,updated_at=CURRENT_TIMESTAMP WHERE id=?');
        $stmt->execute([$enabled,$id]);
        json_response(['ok'=>true,'message'=>$enabled?'API папки включён.':'API папки выключен.','state'=>admin_state($pdo,$user,$project)]);
    }


    if ($action === 'save_folder_api_tree') {
        ProjectAccess::requirePermission($pdo,$user,(int)$project['id'],'content.edit');
        $id=(int)($payload['id']??0);
        if($id<=0 || !ProjectAccess::containsFolder($pdo,$project,$id)) throw new RuntimeException('Папка не найдена.');
        $ttl=max(0,min(3600,(int)($payload['cache_ttl']??60)));
        $pdo->prepare('UPDATE folders SET api_tree_cache_ttl=?,updated_at=CURRENT_TIMESTAMP WHERE id=?')->execute([$ttl,$id]);
        $stmt=$pdo->prepare('SELECT * FROM folders WHERE id=? LIMIT 1');$stmt->execute([$id]);$folder=$stmt->fetch();
        $settings=folder_api_tree_settings($folder?:[], true);
        json_response([
            'ok'=>true,'message'=>'Кеширование API папки сохранено.','settings'=>$settings,
            'tree'=>admin_folder_api_tree($pdo,$project,$id,$settings),
            'state'=>admin_state($pdo,$user,$project),
        ]);
    }

    if ($action === 'set_document_api') {
        ProjectAccess::requirePermission($pdo,$user,(int)$project['id'],'content.edit');
        $id=(int)($payload['id']??0); $enabled=filter_var($payload['enabled']??false,FILTER_VALIDATE_BOOLEAN)?1:0;
        if ($id<=0 || !ProjectAccess::containsDocument($pdo,$project,$id)) throw new RuntimeException('Раздел не найден.');
        $stmt=$pdo->prepare('UPDATE documents SET api_enabled=?,updated_at=CURRENT_TIMESTAMP WHERE id=?');
        $stmt->execute([$enabled,$id]);
        json_response(['ok'=>true,'message'=>$enabled?'API раздела включён.':'API раздела выключен.','document'=>admin_document($pdo,$id,$project),'state'=>admin_state($pdo,$user,$project)]);
    }

    if ($action === 'set_form_api') {
        ProjectAccess::requirePermission($pdo,$user,(int)$project['id'],'forms.edit');
        $id=(int)($payload['id']??0); $enabled=filter_var($payload['enabled']??false,FILTER_VALIDATE_BOOLEAN)?1:0;
        $check=$pdo->prepare('SELECT 1 FROM forms WHERE id=? AND project_id=? LIMIT 1');
        $check->execute([$id,(int)$project['id']]);
        if(!$check->fetchColumn()) throw new RuntimeException('Форма не найдена.');
        $stmt=$pdo->prepare('UPDATE forms SET api_enabled=?,updated_at=CURRENT_TIMESTAMP WHERE id=? AND project_id=?');
        $stmt->execute([$enabled,$id,(int)$project['id']]);
        json_response(['ok'=>true,'message'=>$enabled?'API формы включён.':'API формы выключен.','form'=>admin_form($pdo,$id,$project),'forms'=>admin_forms($pdo,$project)]);
    }

    if ($action === 'set_api_response') {
        ProjectAccess::requirePermission($pdo,$user,(int)$project['id'],'settings.edit');
        $fullResponse = filter_var($payload['full_response'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $response = save_project_api_response($pdo, (int)$project['id'], $fullResponse);
        json_response([
            'ok' => true,
            'message' => $fullResponse
                ? 'Полный ответ API включён.'
                : 'API возвращает только данные.',
            'api_response' => $response,
            'state' => admin_state($pdo, $user, $project),
        ]);
    }

    if ($action === 'regenerate_api_token') {
        ProjectAccess::requirePermission($pdo,$user,(int)$project['id'],'settings.edit');
        $saved = save_project_api_access($pdo, (int)$project['id'], 'private');
        json_response([
            'ok' => true,
            'message' => 'Новый секретный токен создан. Старый токен больше не работает.',
            'api_access' => $saved['access'],
            'token' => $saved['token'],
            'state' => admin_state($pdo, $user, $project),
        ]);
    }

    if ($action === 'save_settings') {
        $raw = $payload['i18n'] ?? $payload;
        if (is_string($raw)) $raw = json_decode($raw, true);
        if (!is_array($raw)) $raw = [];
        ProjectAccess::requirePermission($pdo,$user,(int)$project['id'],'settings.edit');
        $i18n = save_cms_i18n_settings($pdo, $raw, $project);
        json_response(['ok' => true, 'message' => 'Настройки сохранены.', 'i18n' => $i18n, 'state' => admin_state($pdo, $user, $project)]);
    }


    if ($action === 'delete_content_link') {
        ProjectAccess::requirePermission($pdo,$user,(int)$project['id'],'content.edit');
        $id=(int)($payload['id']??0);
        if($id<=0) throw new RuntimeException('Связь не найдена.');
        $stmt=$pdo->prepare("SELECT cl.id,cl.folder_id,cl.resource_type,cl.resource_id,
            d.name AS data_name,f.name AS form_name
            FROM content_links cl
            LEFT JOIN data_sets d ON cl.resource_type='data' AND d.id=cl.resource_id AND d.project_id=cl.project_id
            LEFT JOIN forms f ON cl.resource_type='form' AND f.id=cl.resource_id AND f.project_id=cl.project_id
            WHERE cl.id=? AND cl.project_id=? LIMIT 1");
        $stmt->execute([$id,(int)$project['id']]);
        $row=$stmt->fetch();
        if(!$row) throw new RuntimeException('Связь не найдена.');
        $name=(string)($row['resource_type']==='form' ? ($row['form_name']??'Форма') : ($row['data_name']??'Данные'));
        $rootId=ProjectAccess::rootFolderId($project);
        $folderId=(int)$row['folder_id']===$rootId?null:(int)$row['folder_id'];
        $pdo->prepare('DELETE FROM content_links WHERE id=? AND project_id=?')->execute([$id,(int)$project['id']]);
        json_response([
            'ok'=>true,
            'message'=>'Связь убрана из папки.',
            'link'=>[
                'id'=>$id,
                'folder_id'=>$folderId,
                'resource_type'=>(string)$row['resource_type'],
                'resource_id'=>(int)$row['resource_id'],
                'name'=>$name,
            ],
            'state'=>admin_state($pdo,$user,$project),
        ]);
    }

    if ($action === 'restore_content_links') {
        ProjectAccess::requirePermission($pdo,$user,(int)$project['id'],'content.edit');
        $items=is_array($payload['links']??null)?$payload['links']:[];
        if(!$items) throw new RuntimeException('Нет связей для восстановления.');
        $projectId=(int)$project['id'];
        $rootId=ProjectAccess::rootFolderId($project);
        $restored=[];
        foreach($items as $item){
            if(!is_array($item)) continue;
            $type=(string)($item['resource_type']??'');
            $resourceId=(int)($item['resource_id']??0);
            $folderUiId=nullable_id($item['folder_id']??null);
            if(!in_array($type,['data','form'],true)||$resourceId<=0) continue;
            if(!ProjectAccess::containsFolder($pdo,$project,$folderUiId)) $folderUiId=null;
            $folderId=ProjectAccess::actualFolderId($project,$folderUiId);
            $sourceTable=$type==='data'?'data_sets':'forms';
            $q=$pdo->prepare("SELECT name FROM {$sourceTable} WHERE id=? AND project_id=? LIMIT 1");
            $q->execute([$resourceId,$projectId]);
            $name=$q->fetchColumn();
            if($name===false) continue;

            $existing=$pdo->prepare('SELECT id FROM content_links WHERE project_id=? AND folder_id=? AND resource_type=? AND resource_id=? LIMIT 1');
            $existing->execute([$projectId,$folderId,$type,$resourceId]);
            $linkId=(int)($existing->fetchColumn()?:0);
            if(!$linkId){
                Database::insertIgnore($pdo,'content_links',[
                    'project_id'=>$projectId,
                    'folder_id'=>$folderId,
                    'resource_type'=>$type,
                    'resource_id'=>$resourceId,
                    'sort_order'=>100,
                ]);
                $existing->execute([$projectId,$folderId,$type,$resourceId]);
                $linkId=(int)($existing->fetchColumn()?:0);
            }
            if($linkId){
                $restored[]=[
                    'id'=>$linkId,
                    'folder_id'=>$folderId===$rootId?null:$folderId,
                    'resource_type'=>$type,
                    'resource_id'=>$resourceId,
                    'name'=>(string)$name,
                ];
            }
        }
        json_response([
            'ok'=>true,
            'message'=>count($restored)===1?'Связь восстановлена.':'Восстановлено связей: '.count($restored).'.',
            'links'=>$restored,
            'state'=>admin_state($pdo,$user,$project),
        ]);
    }

    if ($action === 'save_content_links') {
        ProjectAccess::requirePermission($pdo,$user,(int)$project['id'],'content.edit');
        $type=(string)($payload['type']??'');
        if(!in_array($type,['data','form'],true)) throw new RuntimeException('Неизвестный тип ресурса.');
        $folderId=(int)($payload['folder_id']??0);
        $folderId=$folderId>0?$folderId:ProjectAccess::rootFolderId($project);
        if(!ProjectAccess::containsFolder($pdo,$project,$folderId)) throw new RuntimeException('Папка не найдена.');
        $ids=array_values(array_unique(array_filter(array_map('intval',is_array($payload['resource_ids']??null)?$payload['resource_ids']:[]),fn($id)=>$id>0)));
        if($type==='data'){
            ProjectAccess::requirePermission($pdo,$user,(int)$project['id'],'data.view');
            if($ids){$ph=implode(',',array_fill(0,count($ids),'?'));$q=$pdo->prepare("SELECT id FROM data_sets WHERE project_id=? AND id IN ($ph)");$q->execute(array_merge([(int)$project['id']],$ids));$valid=array_map('intval',$q->fetchAll(PDO::FETCH_COLUMN));}
            else $valid=[];
        }else{
            ProjectAccess::requirePermission($pdo,$user,(int)$project['id'],'forms.view');
            if($ids){$ph=implode(',',array_fill(0,count($ids),'?'));$q=$pdo->prepare("SELECT id FROM forms WHERE project_id=? AND id IN ($ph)");$q->execute(array_merge([(int)$project['id']],$ids));$valid=array_map('intval',$q->fetchAll(PDO::FETCH_COLUMN));}
            else $valid=[];
        }
        sort($valid);$expected=$ids;sort($expected);
        if($valid!==$expected) throw new RuntimeException('Один из выбранных ресурсов больше недоступен.');
        $pdo->beginTransaction();
        try{
            $pdo->prepare('DELETE FROM content_links WHERE project_id=? AND folder_id=? AND resource_type=?')->execute([(int)$project['id'],$folderId,$type]);
            foreach($ids as $index=>$resourceId){
                Database::insertIgnore($pdo,'content_links',[
                    'project_id'=>(int)$project['id'],'folder_id'=>$folderId,'resource_type'=>$type,
                    'resource_id'=>$resourceId,'sort_order'=>100+$index,
                ]);
            }
            $pdo->commit();
        }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
        json_response(['ok'=>true,'message'=>$type==='data'?'Связи с данными обновлены.':'Связи с формами обновлены.','state'=>admin_state($pdo,$user,$project)]);
    }

    if ($action === 'create_data_set') {
        ProjectAccess::requirePermission($pdo,$user,(int)$project['id'],'data.edit');
        $name = trim((string)($payload['name'] ?? ''));
        if ($name === '') throw new RuntimeException('Введите название данных.');
        $requestedSlug = trim((string)($payload['slug'] ?? ''));
        $slug = unique_data_slug($pdo,(int)$project['id'],$requestedSlug !== '' ? $requestedSlug : $name);
        $mode = sanitize_data_mode($payload['mode'] ?? 'single');
        $dataJson = $mode === 'multiple' ? '[]' : '{}';
        $stmt = $pdo->prepare('INSERT INTO data_sets(project_id,name,slug,mode,api_enabled,schema_json,data_json) VALUES(?,?,?,?,1,?,?)');
        $stmt->execute([(int)$project['id'],$name,$slug,$mode,'[]',$dataJson]);
        $id = Database::lastInsertId($pdo);
        json_response(['ok'=>true,'data_set'=>admin_data_set($pdo,$id,$project),'data_sets'=>admin_data_sets($pdo,$project)],201);
    }

    if ($action === 'save_data_set') {
        ProjectAccess::requirePermission($pdo,$user,(int)$project['id'],'data.edit');
        $id = (int)($payload['id'] ?? 0);
        $stmt = $pdo->prepare('SELECT * FROM data_sets WHERE id=? AND project_id=? LIMIT 1');
        $stmt->execute([$id,(int)$project['id']]);
        $row = $stmt->fetch();
        if (!$row) throw new RuntimeException('Набор данных не найден.');
        $row = ensure_data_set_ids($pdo, $row);

        $schemaRaw = $payload['schema'] ?? $payload['schema_json'] ?? [];
        if (is_string($schemaRaw)) $schemaRaw = json_decode($schemaRaw,true) ?: [];
        $schema = sanitize_data_schema(is_array($schemaRaw) ? $schemaRaw : []);
        $schema = validate_data_relation_schema($pdo, (int)$project['id'], $id, $schema);
        $relationContext = data_relation_context($pdo, (int)$project['id'], $id, $schema);

        $valuesRaw = $payload['values'] ?? $payload['values_json'] ?? [];
        if (is_string($valuesRaw)) $valuesRaw = json_decode($valuesRaw,true) ?: [];
        if (!is_array($valuesRaw)) $valuesRaw = [];
        $mode = sanitize_data_mode($row['mode'] ?? 'single');
        $oldData = decode_json($row['data_json'] ?? ($mode==='multiple' ? '[]' : '{}'));
        $oldFiles = collect_upload_filenames($oldData);
        $newUploads = [];
        $removedIds = [];

        if ($mode === 'multiple') {
            $oldItems = data_items_with_stable_ids(is_array($oldData) ? $oldData : []);
            $oldUidToId = [];
            $oldIds = [];
            $maxId = 0;
            foreach ($oldItems as $oldItem) {
                $oldId = (int)($oldItem['_id'] ?? 0);
                if ($oldId > 0) { $oldIds[$oldId] = true; $maxId = max($maxId, $oldId); }
                $oldUid = preg_replace('/[^A-Za-z0-9_-]/','',(string)($oldItem['_uid'] ?? ''));
                if ($oldUid !== '' && $oldId > 0) $oldUidToId[$oldUid] = $oldId;
            }

            $saved = [];
            $newIds = [];
            $seenUids = [];
            foreach (array_values($valuesRaw) as $index => $item) {
                if (!is_array($item)) continue;
                $uid = preg_replace('/[^A-Za-z0-9_-]/','',(string)($item['_uid'] ?? '')) ?: ('row'.$index);
                if (isset($seenUids[$uid])) $uid .= '_' . $index;
                $seenUids[$uid] = true;
                $recordId = (int)($oldUidToId[$uid] ?? 0);
                $incomingId = (int)($item['_id'] ?? 0);
                if ($recordId <= 0 && $incomingId > 0 && isset($oldIds[$incomingId]) && !isset($newIds[$incomingId])) $recordId = $incomingId;
                if ($recordId <= 0) {
                    do { $maxId++; } while (isset($oldIds[$maxId]) || isset($newIds[$maxId]));
                    $recordId = $maxId;
                }
                $item = save_item_values($schema,$item,$uid,$newUploads,$relationContext);
                $item['_uid'] = $uid;
                $item['_id'] = $recordId;
                $newIds[$recordId] = true;
                $saved[] = $item;
            }
            $data = $saved;
            $removedIds = array_values(array_diff(array_keys($oldIds), array_keys($newIds)));
        } else {
            $data = save_item_values($schema,$valuesRaw,'',$newUploads,$relationContext);
        }

        $schemaJson = json_encode($schema,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
        $dataJson = json_encode($data,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
        $stmt = $pdo->prepare('UPDATE data_sets SET schema_json=?,data_json=?,updated_at=CURRENT_TIMESTAMP WHERE id=? AND project_id=?');
        $stmt->execute([$schemaJson,$dataJson,$id,(int)$project['id']]);

        $relationsCleaned = $removedIds ? remove_data_record_references($pdo, (int)$project['id'], $id, $removedIds) : 0;
        $newFiles = collect_upload_filenames($data);
        cleanup_orphan_uploads($pdo,array_values(array_diff($oldFiles,$newFiles)));
        json_response(['ok'=>true,'data_set'=>admin_data_set($pdo,$id,$project),'data_sets'=>admin_data_sets($pdo,$project),'removed_relation_ids'=>$removedIds,'relations_cleaned'=>$relationsCleaned]);
    }

    if ($action === 'set_data_api') {
        ProjectAccess::requirePermission($pdo,$user,(int)$project['id'],'data.edit');
        $id=(int)($payload['id']??0); $enabled=filter_var($payload['enabled']??false,FILTER_VALIDATE_BOOLEAN)?1:0;
        $check=$pdo->prepare('SELECT 1 FROM data_sets WHERE id=? AND project_id=? LIMIT 1');
        $check->execute([$id,(int)$project['id']]);
        if(!$check->fetchColumn()) throw new RuntimeException('Набор данных не найден.');
        $stmt=$pdo->prepare('UPDATE data_sets SET api_enabled=?,updated_at=CURRENT_TIMESTAMP WHERE id=? AND project_id=?');
        $stmt->execute([$enabled,$id,(int)$project['id']]);
        json_response(['ok'=>true,'data_set'=>admin_data_set($pdo,$id,$project),'data_sets'=>admin_data_sets($pdo,$project)]);
    }

    if ($action === 'delete_data_set') {
        ProjectAccess::requirePermission($pdo,$user,(int)$project['id'],'data.edit');
        $id=(int)($payload['id']??0);
        $dependencies = data_set_relation_dependencies($pdo,(int)$project['id'],$id);
        if ($dependencies) throw new RuntimeException('Этот набор используется в других данных. Сначала удалите поля «Связь», которые на него ссылаются.');
        $stmt=$pdo->prepare('SELECT data_json FROM data_sets WHERE id=? AND project_id=? LIMIT 1');$stmt->execute([$id,(int)$project['id']]);$json=$stmt->fetchColumn();
        if($json===false) throw new RuntimeException('Набор данных не найден.');
        $files=collect_upload_filenames(decode_json((string)$json));
        $pdo->prepare("DELETE FROM content_links WHERE project_id=? AND resource_type='data' AND resource_id=?")->execute([(int)$project['id'],$id]);
        $pdo->prepare('DELETE FROM data_sets WHERE id=? AND project_id=?')->execute([$id,(int)$project['id']]);
        cleanup_orphan_uploads($pdo,$files);
        json_response(['ok'=>true,'data_sets'=>admin_data_sets($pdo,$project)]);
    }

    if ($action === 'create_form') {
        $name = trim((string)($payload['name'] ?? ''));
        if ($name === '') throw new RuntimeException('Введите название формы.');
        $slug = unique_form_slug($pdo, $name);
        $schema = [];
        ProjectAccess::requirePermission($pdo,$user,(int)$project['id'],'forms.edit');
        $stmt = $pdo->prepare('INSERT INTO forms(project_id,name,slug,api_enabled,schema_json) VALUES(?,?,?,1,?)');
        $stmt->execute([(int)$project['id'], $name, $slug, json_encode($schema, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)]);
        $id = Database::lastInsertId($pdo);
        json_response(['ok'=>true,'id'=>$id,'message'=>'Форма создана.','forms'=>admin_forms($pdo,$project)]);
    }

    if ($action === 'save_form') {
        ProjectAccess::requirePermission($pdo,$user,(int)$project['id'],'forms.edit');
        $id = (int)($payload['id'] ?? 0);
        $stmt = $pdo->prepare('SELECT * FROM forms WHERE id=? AND project_id=? LIMIT 1');
        $stmt->execute([$id,(int)$project['id']]);
        $form = $stmt->fetch();
        if (!$form) throw new RuntimeException('Форма не найдена.');
        $name = trim((string)($payload['name'] ?? $form['name']));
        if ($name === '') $name = (string)$form['name'];
        $rawSchema = $payload['schema'] ?? [];
        if (is_string($rawSchema)) $rawSchema = json_decode($rawSchema, true);
        if (!is_array($rawSchema)) $rawSchema = [];
        $schema = sanitize_form_schema($rawSchema);
        $success = trim((string)($payload['success_message'] ?? $form['success_message']));
        if ($success === '') $success = 'Спасибо! Мы получили вашу заявку.';
        // Keep the public endpoint stable after the form is connected to a website.
        $slug = (string)$form['slug'];
        $stmt = $pdo->prepare('UPDATE forms SET name=?,schema_json=?,success_message=?,updated_at=CURRENT_TIMESTAMP WHERE id=? AND project_id=?');
        $stmt->execute([$name,json_encode($schema, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),$success,$id,(int)$project['id']]);
        json_response(['ok'=>true,'message'=>'Форма сохранена.','form'=>admin_form($pdo,$id,$project),'forms'=>admin_forms($pdo,$project)]);
    }

    if ($action === 'mark_submission') {
        ProjectAccess::requirePermission($pdo,$user,(int)$project['id'],'forms.view');
        $id = (int)($payload['id'] ?? 0);
        $status = (string)($payload['status'] ?? 'read') === 'new' ? 'new' : 'read';
        $stmt = $pdo->prepare('UPDATE form_submissions SET status=? WHERE id=? AND form_id IN (SELECT id FROM forms WHERE project_id=?)');
        $stmt->execute([$status,$id,(int)$project['id']]);
        json_response(['ok'=>true]);
    }

    if ($action === 'delete_submission') {
        ProjectAccess::requirePermission($pdo,$user,(int)$project['id'],'forms.edit');
        $id = (int)($payload['id'] ?? 0);
        $stmt = $pdo->prepare('SELECT s.data_json,s.form_id FROM form_submissions s JOIN forms f ON f.id=s.form_id WHERE s.id=? AND f.project_id=? LIMIT 1');
        $stmt->execute([$id,(int)$project['id']]);
        $row = $stmt->fetch();
        if (!$row) throw new RuntimeException('Заявка не найдена.');
        $candidateFiles = collect_upload_filenames(decode_json($row['data_json'] ?? ''));
        $pdo->prepare('DELETE FROM form_submissions WHERE id=?')->execute([$id]);
        cleanup_orphan_uploads($pdo, $candidateFiles);
        json_response(['ok'=>true,'message'=>'Заявка удалена.','form_id'=>(int)$row['form_id']]);
    }

    if ($action === 'delete_form') {
        ProjectAccess::requirePermission($pdo,$user,(int)$project['id'],'forms.edit');
        $id = (int)($payload['id'] ?? 0);
        $check=$pdo->prepare('SELECT 1 FROM forms WHERE id=? AND project_id=?'); $check->execute([$id,(int)$project['id']]); if(!$check->fetchColumn()) throw new RuntimeException('Форма не найдена.');
        $stmt = $pdo->prepare('SELECT data_json FROM form_submissions WHERE form_id=?');
        $stmt->execute([$id]);
        $candidateFiles = [];
        foreach ($stmt->fetchAll() as $row) $candidateFiles = array_merge($candidateFiles, collect_upload_filenames(decode_json($row['data_json'] ?? '')));
        $pdo->prepare("DELETE FROM content_links WHERE project_id=? AND resource_type='form' AND resource_id=?")->execute([(int)$project['id'],$id]);
        $stmt = $pdo->prepare('DELETE FROM forms WHERE id=? AND project_id=?');
        $stmt->execute([$id,(int)$project['id']]);
        if ($stmt->rowCount() < 1) throw new RuntimeException('Форма не найдена.');
        cleanup_orphan_uploads($pdo, $candidateFiles);
        json_response(['ok'=>true,'message'=>'Форма удалена.','forms'=>admin_forms($pdo,$project)]);
    }

    if ($action === 'cleanup_files') {
        ProjectAccess::requirePermission($pdo,$user,(int)$project['id'],'files.manage');
        $count = cleanup_all_orphan_uploads($pdo);
        json_response([
            'ok' => true,
            'message' => $count > 0 ? ('Удалено неиспользуемых файлов: ' . $count . '.') : 'Неиспользуемых файлов нет.',
            'files' => list_upload_files_for_project($pdo,$project,$user),
        ]);
    }


    if ($action === 'trash_content') {
        ProjectAccess::requirePermission($pdo,$user,(int)$project['id'],'content.edit');
        $type=(string)($payload['item_type']??'');
        $itemId=(int)($payload['item_id']??0);
        if(!in_array($type,['folder','document'],true)||$itemId<=0) throw new RuntimeException('Объект не найден.');
        $projectId=(int)$project['id'];
        $rootId=ProjectAccess::rootFolderId($project);
        $trashRoot=feather_trash_root_id($pdo,$project,true);
        if(!$trashRoot) throw new RuntimeException('Не удалось открыть корзину.');

        $pdo->beginTransaction();
        try{
            if($type==='folder'){
                if(!ProjectAccess::containsFolder($pdo,$project,$itemId)||$itemId===$rootId||feather_folder_is_in_trash($pdo,$project,$itemId)) throw new RuntimeException('Папка не найдена.');
                $stmt=$pdo->prepare('SELECT parent_id,name FROM folders WHERE id=? LIMIT 1');$stmt->execute([$itemId]);$row=$stmt->fetch();
                if(!$row) throw new RuntimeException('Папка не найдена.');
                $originalParent=$row['parent_id']===null?null:(int)$row['parent_id'];
                $name=(string)$row['name'];
                $slug=unique_slug($pdo,'folders',$name,$trashRoot,$itemId);
                $pdo->prepare('UPDATE folders SET parent_id=?,slug=?,updated_at=CURRENT_TIMESTAMP WHERE id=?')->execute([$trashRoot,$slug,$itemId]);
            }else{
                if(!ProjectAccess::containsDocument($pdo,$project,$itemId)) throw new RuntimeException('Раздел не найден.');
                $stmt=$pdo->prepare('SELECT folder_id,name FROM documents WHERE id=? LIMIT 1');$stmt->execute([$itemId]);$row=$stmt->fetch();
                if(!$row||feather_folder_is_in_trash($pdo,$project,$row['folder_id']===null?null:(int)$row['folder_id'])) throw new RuntimeException('Раздел не найден.');
                $originalParent=$row['folder_id']===null?null:(int)$row['folder_id'];
                $name=(string)$row['name'];
                $slug=unique_slug($pdo,'documents',$name,$trashRoot,$itemId);
                $pdo->prepare('UPDATE documents SET folder_id=?,slug=?,updated_at=CURRENT_TIMESTAMP WHERE id=?')->execute([$trashRoot,$slug,$itemId]);
            }
            Database::upsert($pdo,'content_trash',[
                'project_id'=>$projectId,'item_type'=>$type,'item_id'=>$itemId,
            ],[
                'original_parent_id'=>$originalParent,'original_name'=>$name,'deleted_by'=>(int)$user['id'],'deleted_at'=>date('Y-m-d H:i:s'),
            ]);
            $stmt=$pdo->prepare('SELECT id FROM content_trash WHERE project_id=? AND item_type=? AND item_id=? LIMIT 1');
            $stmt->execute([$projectId,$type,$itemId]);$trashId=(int)$stmt->fetchColumn();
            $pdo->commit();
            json_response(['ok'=>true,'trash_id'=>$trashId,'message'=>'Перемещено в корзину: «'.$name.'».','state'=>admin_state($pdo,$user,$project)]);
        }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
    }

    if ($action === 'trash_list') {
        ProjectAccess::requirePermission($pdo,$user,(int)$project['id'],'content.view');
        $projectId=(int)$project['id'];
        $stmt=$pdo->prepare('SELECT * FROM content_trash WHERE project_id=? ORDER BY deleted_at DESC,id DESC');
        $stmt->execute([$projectId]);$items=[];$stale=[];
        foreach($stmt->fetchAll() as $row){
            $type=(string)$row['item_type'];$itemId=(int)$row['item_id'];$exists=false;
            if($type==='folder'){$q=$pdo->prepare('SELECT name FROM folders WHERE id=? LIMIT 1');$q->execute([$itemId]);$current=$q->fetchColumn();$exists=$current!==false;}
            else{$q=$pdo->prepare('SELECT name FROM documents WHERE id=? LIMIT 1');$q->execute([$itemId]);$current=$q->fetchColumn();$exists=$current!==false;}
            if(!$exists){$stale[]=(int)$row['id'];continue;}
            $originalParent=$row['original_parent_id']===null?null:(int)$row['original_parent_id'];
            $path='Мой контент';
            if($originalParent&&$originalParent!==ProjectAccess::rootFolderId($project)){
                $parts=folder_path($pdo,$originalParent);$names=[];
                foreach($parts as $part){if((int)$part['id']===ProjectAccess::rootFolderId($project))continue;if((string)$part['slug']===feather_trash_slug())continue;$names[]=(string)$part['name'];}
                if($names)$path.=' / '.implode(' / ',$names);
            }
            $items[]=['id'=>(int)$row['id'],'type'=>$type,'item_id'=>$itemId,'name'=>(string)$row['original_name'],'current_name'=>(string)$current,'deleted_at'=>(string)$row['deleted_at'],'original_parent_id'=>$originalParent,'original_path'=>$path];
        }
        if($stale){$ph=implode(',',array_fill(0,count($stale),'?'));$pdo->prepare("DELETE FROM content_trash WHERE id IN ($ph)")->execute($stale);}
        json_response(['ok'=>true,'items'=>$items]);
    }

    if ($action === 'restore_trash') {
        ProjectAccess::requirePermission($pdo,$user,(int)$project['id'],'content.edit');
        $ids=array_values(array_unique(array_filter(array_map('intval',(array)($payload['trash_ids']??[])),static fn($id)=>$id>0)));
        if(!$ids) throw new RuntimeException('Выберите объекты для восстановления.');
        $projectId=(int)$project['id'];$rootId=ProjectAccess::rootFolderId($project);$restored=[];
        $pdo->beginTransaction();
        try{
            foreach($ids as $trashId){
                $stmt=$pdo->prepare('SELECT * FROM content_trash WHERE id=? AND project_id=? LIMIT 1');$stmt->execute([$trashId,$projectId]);$meta=$stmt->fetch();if(!$meta)continue;
                $type=(string)$meta['item_type'];$itemId=(int)$meta['item_id'];$originalName=(string)$meta['original_name'];
                $dest=$meta['original_parent_id']===null?$rootId:(int)$meta['original_parent_id'];
                if(!ProjectAccess::containsFolder($pdo,$project,$dest)||feather_folder_is_in_trash($pdo,$project,$dest))$dest=$rootId;
                if($type==='folder'){
                    $q=$pdo->prepare('SELECT id FROM folders WHERE id=? LIMIT 1');$q->execute([$itemId]);if(!$q->fetchColumn()){$pdo->prepare('DELETE FROM content_trash WHERE id=?')->execute([$trashId]);continue;}
                    if(clipboard_folder_contains($pdo,$itemId,$dest))$dest=$rootId;
                    $name=clipboard_unique_name($pdo,'folder',$dest,$originalName,false,$itemId);$slug=unique_slug($pdo,'folders',$name,$dest,$itemId);
                    $pdo->prepare('UPDATE folders SET parent_id=?,name=?,slug=?,updated_at=CURRENT_TIMESTAMP WHERE id=?')->execute([$dest,$name,$slug,$itemId]);
                }else{
                    $q=$pdo->prepare('SELECT id FROM documents WHERE id=? LIMIT 1');$q->execute([$itemId]);if(!$q->fetchColumn()){$pdo->prepare('DELETE FROM content_trash WHERE id=?')->execute([$trashId]);continue;}
                    $name=clipboard_unique_name($pdo,'document',$dest,$originalName,false,$itemId);$slug=unique_slug($pdo,'documents',$name,$dest,$itemId);
                    $pdo->prepare('UPDATE documents SET folder_id=?,name=?,slug=?,updated_at=CURRENT_TIMESTAMP WHERE id=?')->execute([$dest,$name,$slug,$itemId]);
                }
                $pdo->prepare('DELETE FROM content_trash WHERE id=?')->execute([$trashId]);
                $restored[]=['type'=>$type,'id'=>$itemId,'name'=>$name];
            }
            $pdo->commit();
            json_response(['ok'=>true,'restored'=>$restored,'message'=>count($restored)===1?'Объект восстановлен.':'Восстановлено объектов: '.count($restored).'.','state'=>admin_state($pdo,$user,$project)]);
        }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
    }

    if ($action === 'delete_trash' || $action === 'empty_trash') {
        ProjectAccess::requirePermission($pdo,$user,(int)$project['id'],'content.edit');
        $projectId=(int)$project['id'];$trashRoot=feather_trash_root_id($pdo,$project,false);
        $ids=$action==='empty_trash'?[]:array_values(array_unique(array_filter(array_map('intval',(array)($payload['trash_ids']??[])),static fn($id)=>$id>0)));
        if($action==='delete_trash'&&!$ids) throw new RuntimeException('Выберите объекты для удаления.');
        $candidateFiles=array_keys(referenced_upload_filenames($pdo,true));
        $pdo->beginTransaction();
        try{
            if($action==='empty_trash'){
                if($trashRoot){$pdo->prepare('DELETE FROM documents WHERE folder_id=?')->execute([$trashRoot]);$pdo->prepare('DELETE FROM folders WHERE parent_id=?')->execute([$trashRoot]);}
                $pdo->prepare('DELETE FROM content_trash WHERE project_id=?')->execute([$projectId]);
            }else{
                foreach($ids as $trashId){
                    $stmt=$pdo->prepare('SELECT item_type,item_id FROM content_trash WHERE id=? AND project_id=? LIMIT 1');$stmt->execute([$trashId,$projectId]);$meta=$stmt->fetch();if(!$meta)continue;
                    if((string)$meta['item_type']==='folder')$pdo->prepare('DELETE FROM folders WHERE id=?')->execute([(int)$meta['item_id']]);
                    else $pdo->prepare('DELETE FROM documents WHERE id=?')->execute([(int)$meta['item_id']]);
                    $pdo->prepare('DELETE FROM content_trash WHERE id=?')->execute([$trashId]);
                }
                // Remove metadata whose item disappeared through a cascading folder delete.
                $stmt=$pdo->prepare('SELECT id,item_type,item_id FROM content_trash WHERE project_id=?');$stmt->execute([$projectId]);
                foreach($stmt->fetchAll() as $meta){$q=$pdo->prepare((string)$meta['item_type']==='folder'?'SELECT 1 FROM folders WHERE id=?':'SELECT 1 FROM documents WHERE id=?');$q->execute([(int)$meta['item_id']]);if(!$q->fetchColumn())$pdo->prepare('DELETE FROM content_trash WHERE id=?')->execute([(int)$meta['id']]);}
            }
            $pdo->commit();cleanup_orphan_uploads($pdo,$candidateFiles);
            json_response(['ok'=>true,'message'=>$action==='empty_trash'?'Корзина очищена.':'Удалено навсегда.','state'=>admin_state($pdo,$user,$project)]);
        }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
    }


    if ($action === 'paste_content') {
        ProjectAccess::requirePermission($pdo, $user, (int)$project['id'], 'content.edit');

        $operation = (string)($payload['operation'] ?? 'copy') === 'cut' ? 'cut' : 'copy';
        $type = (string)($payload['item_type'] ?? '');
        $itemId = (int)($payload['item_id'] ?? 0);
        $sourceProjectId = (int)($payload['source_project_id'] ?? (int)$project['id']);
        $destinationUiId = nullable_id($payload['destination_folder_id'] ?? null);

        if (!in_array($type, ['folder', 'document', 'resource-link'], true) || $itemId <= 0) {
            throw new RuntimeException('Буфер обмена повреждён. Скопируйте объект ещё раз.');
        }
        if (!ProjectAccess::containsFolder($pdo, $project, $destinationUiId)) {
            throw new RuntimeException('Папка назначения не найдена.');
        }
        if ($type === 'resource-link' && $sourceProjectId !== (int)$project['id']) {
            throw new RuntimeException('Связанные Данные и Формы можно копировать только внутри их проекта.');
        }

        $sourceProject = clipboard_accessible_project($pdo, $user, $sourceProjectId);
        ProjectAccess::requirePermission(
            $pdo,
            $user,
            (int)$sourceProject['id'],
            $operation === 'cut' ? 'content.edit' : 'content.view'
        );
        $destinationFolderId = ProjectAccess::actualFolderId($project, $destinationUiId);

        $pdo->beginTransaction();
        try {
            $resultId = 0;
            $resultName = '';
            $noop = false;

            if ($type === 'folder') {
                if (!ProjectAccess::containsFolder($pdo, $sourceProject, $itemId)
                    || $itemId === ProjectAccess::rootFolderId($sourceProject)) {
                    throw new RuntimeException('Исходная папка не найдена.');
                }
                if (clipboard_folder_contains($pdo, $itemId, $destinationFolderId)) {
                    throw new RuntimeException('Нельзя вставить папку внутрь самой себя или её вложенной папки.');
                }

                $sourceStmt = $pdo->prepare('SELECT parent_id,name FROM folders WHERE id=? LIMIT 1');
                $sourceStmt->execute([$itemId]);
                $source = $sourceStmt->fetch();
                if (!$source) throw new RuntimeException('Исходная папка не найдена.');

                if ($operation === 'copy') {
                    $resultId = clipboard_copy_folder_tree($pdo, $itemId, $destinationFolderId, true);
                } else {
                    $sourceParentId = $source['parent_id'] === null ? 0 : (int)$source['parent_id'];
                    if ($sourceParentId === $destinationFolderId) {
                        $resultId = $itemId;
                        $resultName = (string)$source['name'];
                        $noop = true;
                    } else {
                        $resultName = clipboard_unique_name($pdo, 'folder', $destinationFolderId, (string)$source['name'], false, $itemId);
                        $slug = unique_slug($pdo, 'folders', $resultName, $destinationFolderId, $itemId);
                        $pdo->prepare('UPDATE folders SET parent_id=?,name=?,slug=?,updated_at=CURRENT_TIMESTAMP WHERE id=?')
                            ->execute([$destinationFolderId, $resultName, $slug, $itemId]);
                        $resultId = $itemId;
                    }
                }

                if ($resultName === '') {
                    $nameStmt = $pdo->prepare('SELECT name FROM folders WHERE id=? LIMIT 1');
                    $nameStmt->execute([$resultId]);
                    $resultName = (string)($nameStmt->fetchColumn() ?: 'Папка');
                }
            } elseif ($type === 'document') {
                if (!ProjectAccess::containsDocument($pdo, $sourceProject, $itemId)) {
                    throw new RuntimeException('Исходный раздел не найден.');
                }
                $sourceStmt = $pdo->prepare('SELECT folder_id,name FROM documents WHERE id=? LIMIT 1');
                $sourceStmt->execute([$itemId]);
                $source = $sourceStmt->fetch();
                if (!$source) throw new RuntimeException('Исходный раздел не найден.');

                if ($operation === 'copy') {
                    $resultId = clipboard_copy_document($pdo, $itemId, $destinationFolderId, true);
                } else {
                    $sourceFolderId = $source['folder_id'] === null ? 0 : (int)$source['folder_id'];
                    if ($sourceFolderId === $destinationFolderId) {
                        $resultId = $itemId;
                        $resultName = (string)$source['name'];
                        $noop = true;
                    } else {
                        $resultName = clipboard_unique_name($pdo, 'document', $destinationFolderId, (string)$source['name'], false, $itemId);
                        $slug = unique_slug($pdo, 'documents', $resultName, $destinationFolderId, $itemId);
                        $pdo->prepare('UPDATE documents SET folder_id=?,name=?,slug=?,updated_at=CURRENT_TIMESTAMP WHERE id=?')
                            ->execute([$destinationFolderId, $resultName, $slug, $itemId]);
                        $resultId = $itemId;
                    }
                }

                if ($resultName === '') {
                    $nameStmt = $pdo->prepare('SELECT name FROM documents WHERE id=? LIMIT 1');
                    $nameStmt->execute([$resultId]);
                    $resultName = (string)($nameStmt->fetchColumn() ?: 'Раздел');
                }
            } else {
                $sourceStmt=$pdo->prepare("SELECT cl.id,cl.folder_id,cl.resource_type,cl.resource_id,
                    d.name AS data_name,f.name AS form_name
                    FROM content_links cl
                    LEFT JOIN data_sets d ON cl.resource_type='data' AND d.id=cl.resource_id AND d.project_id=cl.project_id
                    LEFT JOIN forms f ON cl.resource_type='form' AND f.id=cl.resource_id AND f.project_id=cl.project_id
                    WHERE cl.id=? AND cl.project_id=? LIMIT 1");
                $sourceStmt->execute([$itemId,(int)$sourceProject['id']]);
                $source=$sourceStmt->fetch();
                if(!$source) throw new RuntimeException('Связанный ресурс не найден.');

                $resourceType=(string)$source['resource_type'];
                $resourceId=(int)$source['resource_id'];
                $resultName=(string)($resourceType==='form' ? ($source['form_name']??'Форма') : ($source['data_name']??'Данные'));
                $sourceFolderId=(int)$source['folder_id'];

                if($operation==='copy'){
                    $existing=$pdo->prepare('SELECT id FROM content_links WHERE project_id=? AND folder_id=? AND resource_type=? AND resource_id=? LIMIT 1');
                    $existing->execute([(int)$project['id'],$destinationFolderId,$resourceType,$resourceId]);
                    $resultId=(int)($existing->fetchColumn()?:0);
                    if($resultId){
                        $noop=true;
                    }else{
                        Database::insertIgnore($pdo,'content_links',[
                            'project_id'=>(int)$project['id'],
                            'folder_id'=>$destinationFolderId,
                            'resource_type'=>$resourceType,
                            'resource_id'=>$resourceId,
                            'sort_order'=>100,
                        ]);
                        $existing->execute([(int)$project['id'],$destinationFolderId,$resourceType,$resourceId]);
                        $resultId=(int)($existing->fetchColumn()?:0);
                        if(!$resultId) throw new RuntimeException('Не удалось создать связь.');
                    }
                }else{
                    if($sourceFolderId===$destinationFolderId){
                        $resultId=$itemId;
                        $noop=true;
                    }else{
                        $existing=$pdo->prepare('SELECT id FROM content_links WHERE project_id=? AND folder_id=? AND resource_type=? AND resource_id=? AND id<>? LIMIT 1');
                        $existing->execute([(int)$project['id'],$destinationFolderId,$resourceType,$resourceId,$itemId]);
                        if($existing->fetchColumn()) throw new RuntimeException('Этот ресурс уже связан с папкой назначения.');
                        $pdo->prepare('UPDATE content_links SET folder_id=? WHERE id=? AND project_id=?')
                            ->execute([$destinationFolderId,$itemId,(int)$project['id']]);
                        $resultId=$itemId;
                    }
                }
            }

            $pdo->commit();

            $verb = $operation === 'copy' ? 'Скопировано' : ($noop ? 'Уже находится здесь' : 'Перемещено');
            json_response([
                'ok' => true,
                'message' => $noop ? ('«' . $resultName . '» уже находится в этой папке.') : ($verb . ': «' . $resultName . '».'),
                'noop' => $noop,
                'result' => ['type' => $type, 'id' => $resultId, 'name' => $resultName],
                'state' => admin_state($pdo, $user, $project),
            ]);
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
    }

    if ($action === 'create_folder') {
        ProjectAccess::requirePermission($pdo,$user,(int)$project['id'],'content.edit');
        $name = trim((string)($payload['name'] ?? ''));
        $parentId = nullable_id($payload['parent_id'] ?? null);
        if ($name === '') throw new RuntimeException('Введите название папки.');
        if (!ProjectAccess::containsFolder($pdo,$project,$parentId)) throw new RuntimeException('Папка не найдена.');
        $parentId = ProjectAccess::actualFolderId($project,$parentId);
        $slug = unique_slug($pdo, 'folders', $name, $parentId);
        $stmt = $pdo->prepare('INSERT INTO folders(parent_id,name,slug,sort_order,api_enabled) VALUES(?,?,?,100,1)');
        $stmt->execute([$parentId, $name, $slug]);
        $id = Database::lastInsertId($pdo);
        json_response(['ok' => true, 'id' => $id, 'message' => 'Папка создана.', 'state' => admin_state($pdo, $user, $project)]);
    }

    if ($action === 'create_document') {
        ProjectAccess::requirePermission($pdo,$user,(int)$project['id'],'content.edit');
        $name = trim((string)($payload['name'] ?? ''));
        $folderId = nullable_id($payload['folder_id'] ?? null);
        if (!ProjectAccess::containsFolder($pdo,$project,$folderId)) throw new RuntimeException('Папка не найдена.');
        $folderId = ProjectAccess::actualFolderId($project,$folderId);
        $mode = sanitize_document_mode($payload['mode'] ?? 'single');
        if ($name === '') throw new RuntimeException('Введите название раздела.');
        ensure_folder($pdo, $folderId);
        $slug = unique_slug($pdo, 'documents', $name, $folderId);
        $initialData = $mode === 'multiple' ? '[]' : '{}';
        $stmt = $pdo->prepare('INSERT INTO documents(folder_id,name,slug,mode,api_enabled,schema_json,data_json) VALUES(?,?,?,?,1,?,?)');
        $stmt->execute([$folderId, $name, $slug, $mode, '[]', $initialData]);
        $id = Database::lastInsertId($pdo);
        append_revision_if_new($pdo, $id, '[]', $initialData);
        json_response(['ok' => true, 'id' => $id, 'message' => $mode === 'multiple' ? 'Раздел с несколькими записями создан.' : 'Одиночный раздел создан.', 'state' => admin_state($pdo, $user, $project)]);
    }

    if ($action === 'rename_folder') {
        ProjectAccess::requirePermission($pdo,$user,(int)$project['id'],'content.edit');
        $id = (int)($payload['id'] ?? 0);
        if (!ProjectAccess::containsFolder($pdo,$project,$id) || $id===ProjectAccess::rootFolderId($project)) throw new RuntimeException('Папка не найдена.');
        $name = trim((string)($payload['name'] ?? ''));
        $stmt = $pdo->prepare('SELECT parent_id FROM folders WHERE id=? LIMIT 1');
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        if (!$row || $name === '') throw new RuntimeException('Папка не найдена.');
        $parentId = $row['parent_id'] === null ? null : (int)$row['parent_id'];
        $slug = unique_slug($pdo, 'folders', $name, $parentId, $id);
        $stmt = $pdo->prepare('UPDATE folders SET name=?,slug=?,updated_at=CURRENT_TIMESTAMP WHERE id=?');
        $stmt->execute([$name, $slug, $id]);
        json_response(['ok' => true, 'message' => 'Папка переименована.', 'state' => admin_state($pdo, $user, $project)]);
    }

    if ($action === 'rename_document') {
        ProjectAccess::requirePermission($pdo,$user,(int)$project['id'],'content.edit');
        $id = (int)($payload['id'] ?? 0);
        if (!ProjectAccess::containsDocument($pdo,$project,$id)) throw new RuntimeException('Раздел не найден.');
        $name = trim((string)($payload['name'] ?? ''));
        $stmt = $pdo->prepare('SELECT folder_id FROM documents WHERE id=? LIMIT 1');
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        if (!$row || $name === '') throw new RuntimeException('Раздел не найден.');
        $folderId = $row['folder_id'] === null ? null : (int)$row['folder_id'];
        $slug = unique_slug($pdo, 'documents', $name, $folderId, $id);
        $stmt = $pdo->prepare('UPDATE documents SET name=?,slug=?,updated_at=CURRENT_TIMESTAMP WHERE id=?');
        $stmt->execute([$name, $slug, $id]);
        json_response(['ok' => true, 'message' => 'Раздел переименован.', 'state' => admin_state($pdo, $user, $project)]);
    }

    if ($action === 'delete_folder') {
        ProjectAccess::requirePermission($pdo,$user,(int)$project['id'],'content.edit');
        $id = (int)($payload['id'] ?? 0);
        if (!ProjectAccess::containsFolder($pdo,$project,$id) || $id===ProjectAccess::rootFolderId($project)) throw new RuntimeException('Папка не найдена.');
        $stmt = $pdo->prepare('SELECT parent_id FROM folders WHERE id=? LIMIT 1');
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        if (!$row) throw new RuntimeException('Папка не найдена.');
        $parentId = $row['parent_id'] === null ? null : (int)$row['parent_id'];
        if ($parentId === ProjectAccess::rootFolderId($project)) $parentId = null;
        $candidateFiles = array_keys(referenced_upload_filenames($pdo, true));
        $stmt = $pdo->prepare('DELETE FROM folders WHERE id=?');
        $stmt->execute([$id]);
        cleanup_orphan_uploads($pdo, $candidateFiles);
        json_response(['ok' => true, 'parent_id' => $parentId, 'message' => 'Папка удалена.', 'state' => admin_state($pdo, $user, $project)]);
    }

    if ($action === 'delete_document') {
        ProjectAccess::requirePermission($pdo,$user,(int)$project['id'],'content.edit');
        $id = (int)($payload['id'] ?? 0);
        if (!ProjectAccess::containsDocument($pdo,$project,$id)) throw new RuntimeException('Раздел не найден.');
        $stmt = $pdo->prepare('SELECT folder_id FROM documents WHERE id=? LIMIT 1');
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        if (!$row) throw new RuntimeException('Раздел не найден.');
        $folderId = $row['folder_id'] === null ? null : (int)$row['folder_id'];
        if ($folderId === ProjectAccess::rootFolderId($project)) $folderId = null;
        $candidateFiles = document_all_upload_filenames($pdo, $id);
        $stmt = $pdo->prepare('DELETE FROM documents WHERE id=?');
        $stmt->execute([$id]);
        cleanup_orphan_uploads($pdo, $candidateFiles);
        json_response(['ok' => true, 'folder_id' => $folderId, 'message' => 'Раздел удалён.', 'state' => admin_state($pdo, $user, $project)]);
    }

    if ($action === 'save_document') {
        ProjectAccess::requirePermission($pdo,$user,(int)$project['id'],'content.edit');
        $id = (int)($payload['id'] ?? 0);
        if (!ProjectAccess::containsDocument($pdo,$project,$id)) throw new RuntimeException('Раздел не найден.');
        $stmt = $pdo->prepare('SELECT * FROM documents WHERE id=? LIMIT 1');
        $stmt->execute([$id]);
        $doc = $stmt->fetch();
        if (!$doc) throw new RuntimeException('Раздел не найден.');
        $mode = sanitize_document_mode($doc['mode'] ?? 'single');

        $i18n = cms_i18n_settings($pdo, (int)$project['id']);
        $requestedLanguage = strtolower(trim((string)($payload['language'] ?? $i18n['default_language'])));
        if (!$i18n['enabled'] || !in_array($requestedLanguage, $i18n['languages'], true)) {
            $requestedLanguage = $i18n['default_language'];
        }
        $isTranslation = $i18n['enabled'] && $requestedLanguage !== $i18n['default_language'];
        $revisionLanguage = $isTranslation ? revision_language_key($requestedLanguage) : '';

        $rawSchema = $payload['schema'] ?? $payload['schema_json'] ?? [];
        if (is_string($rawSchema)) $rawSchema = json_decode($rawSchema, true);
        if (!is_array($rawSchema)) throw new RuntimeException('Не удалось прочитать поля.');
        // Structure is shared by all languages. It can only be changed while editing the default language.
        $schema = $isTranslation ? sanitize_schema(decode_json($doc['schema_json'])) : sanitize_schema($rawSchema);

        $rawValues = $payload['values'] ?? $payload['values_json'] ?? [];
        if (is_string($rawValues)) $rawValues = json_decode($rawValues, true);
        if (!is_array($rawValues)) $rawValues = [];

        $currentDataJson = (string)$doc['data_json'];
        if ($isTranslation) {
            $stmt = $pdo->prepare('SELECT data_json FROM document_translations WHERE document_id=? AND language=? LIMIT 1');
            $stmt->execute([$id, $requestedLanguage]);
            $translationJson = $stmt->fetchColumn();
            if (is_string($translationJson) && $translationJson !== '') $currentDataJson = $translationJson;
        }

        $candidateFiles = collect_upload_filenames(decode_json($currentDataJson));
        $newUploads = [];
        $trimmedFiles = [];
        $pdo->beginTransaction();
        try {
            if ($mode === 'multiple') {
                $data = [];
                foreach ($rawValues as $index => $item) {
                    if (!is_array($item)) continue;
                    $uid = preg_replace('/[^a-zA-Z0-9_-]/', '', (string)($item['_uid'] ?? '')) ?: 'item' . $index;
                    unset($item['_uid']);
                    $saved = save_item_values($schema, $item, $uid, $newUploads);
                    $saved['_uid'] = $uid;
                    $data[] = $saved;
                }
            } else {
                $data = save_item_values($schema, $rawValues, '', $newUploads);
            }

            $schemaJson = json_encode($schema, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $dataJson = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $changed = canonical_json($currentDataJson) !== canonical_json($dataJson)
                || (!$isTranslation && canonical_json((string)$doc['schema_json']) !== canonical_json($schemaJson));

            if ($changed) {
                append_revision_if_new($pdo, $id, (string)$doc['schema_json'], $currentDataJson, $revisionLanguage);
                if ($isTranslation) {
                    Database::upsert($pdo, 'document_translations',
                        ['document_id'=>$id,'language'=>$requestedLanguage],
                        ['data_json'=>$dataJson,'updated_at'=>gmdate('Y-m-d H:i:s')]
                    );
                    $pdo->prepare('UPDATE documents SET updated_at=CURRENT_TIMESTAMP WHERE id=?')->execute([$id]);
                    record_saved_revision($pdo, $id, (string)$doc['schema_json'], $dataJson, $revisionLanguage);
                } else {
                    $stmt = $pdo->prepare('UPDATE documents SET schema_json=?,data_json=?,updated_at=CURRENT_TIMESTAMP WHERE id=?');
                    $stmt->execute([$schemaJson, $dataJson, $id]);
                    record_saved_revision($pdo, $id, $schemaJson, $dataJson, '');
                }
                $trimmedFiles = trim_revisions($pdo, $id, $revisionLanguage);
            }
            $pdo->commit();
        } catch (Throwable $saveError) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            foreach ($newUploads as $uploadedName) unlink_upload_file($uploadedName);
            throw $saveError;
        }

        cleanup_orphan_uploads($pdo, array_merge($candidateFiles, $trimmedFiles));
        $document = admin_document($pdo, $id, $project);
        $activeData = $requestedLanguage === $i18n['default_language']
            ? $document['data']
            : ($document['translations'][$requestedLanguage] ?? $document['data']);
        json_response([
            'ok' => true,
            'changed' => $changed ?? false,
            'message' => 'Автосохранено.',
            'saved_language' => $requestedLanguage,
            'active_data' => $activeData,
            'document' => $document,
            'state' => admin_state($pdo, $user, $project),
        ]);
    }

    if ($action === 'restore_revision') {
        ProjectAccess::requirePermission($pdo,$user,(int)$project['id'],'content.edit');
        $documentId = (int)($payload['document_id'] ?? 0);
        if (!ProjectAccess::containsDocument($pdo,$project,$documentId)) throw new RuntimeException('Раздел не найден.');
        $revisionId = (int)($payload['revision_id'] ?? 0);
        $stmt = $pdo->prepare('SELECT * FROM documents WHERE id=? LIMIT 1');
        $stmt->execute([$documentId]);
        $doc = $stmt->fetch();
        if (!$doc) throw new RuntimeException('Раздел не найден.');
        $stmt = $pdo->prepare('SELECT * FROM revisions WHERE id=? AND document_id=? LIMIT 1');
        $stmt->execute([$revisionId, $documentId]);
        $revision = $stmt->fetch();
        if (!$revision) throw new RuntimeException('Версия не найдена.');

        $language = revision_language_key((string)($revision['language'] ?? ''));
        if ($language === '') {
            $currentDataJson = (string)$doc['data_json'];
        } else {
            $stmt = $pdo->prepare('SELECT data_json FROM document_translations WHERE document_id=? AND language=? LIMIT 1');
            $stmt->execute([$documentId, $language]);
            $current = $stmt->fetchColumn();
            $currentDataJson = is_string($current) && $current !== '' ? $current : (string)$doc['data_json'];
        }

        $candidateFiles = collect_upload_filenames(decode_json($currentDataJson));
        $pdo->beginTransaction();
        try {
            append_revision_if_new($pdo, $documentId, (string)$doc['schema_json'], $currentDataJson, $language);
            if ($language === '') {
                $stmt = $pdo->prepare('UPDATE documents SET schema_json=?,data_json=?,updated_at=CURRENT_TIMESTAMP WHERE id=?');
                $stmt->execute([(string)$revision['schema_json'], (string)$revision['data_json'], $documentId]);
            } else {
                Database::upsert($pdo, 'document_translations',
                    ['document_id'=>$documentId,'language'=>$language],
                    ['data_json'=>(string)$revision['data_json'],'updated_at'=>gmdate('Y-m-d H:i:s')]
                );
                $pdo->prepare('UPDATE documents SET updated_at=CURRENT_TIMESTAMP WHERE id=?')->execute([$documentId]);
            }
            append_revision_if_new($pdo, $documentId, (string)$revision['schema_json'], (string)$revision['data_json'], $language);
            $trimmedFiles = trim_revisions($pdo, $documentId, $language);
            $pdo->commit();
        } catch (Throwable $restoreError) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $restoreError;
        }
        cleanup_orphan_uploads($pdo, array_merge($candidateFiles, $trimmedFiles ?? []));
        $document = admin_document($pdo, $documentId, $project);
        json_response([
            'ok' => true,
            'message' => 'Версия восстановлена.',
            'language' => $language,
            'document' => $document,
            'versions' => admin_versions($pdo, $documentId, $language),
            'state' => admin_state($pdo, $user, $project),
        ]);
    }

    json_response(['ok' => false, 'error' => 'Unknown action'], 404);
} catch (Throwable $e) {
    json_response(['ok' => false, 'error' => $e->getMessage()], 422);
}
