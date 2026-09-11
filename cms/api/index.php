<?php
declare(strict_types=1);
define('FEATHER_NO_SESSION', true);
require __DIR__.'/../core/bootstrap.php';

header('Access-Control-Allow-Origin: '.(cms_config('cors_origin')?:'*'));
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Accept, Authorization, X-MaterCMS-Token, X-Mater-Token, X-Feather-Token');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
if($_SERVER['REQUEST_METHOD']==='OPTIONS'){http_response_code(204);exit;}
if(!Database::installed()) json_response(['error'=>'CMS is not installed'],503);

$pdo=Database::connection();
$method=strtoupper((string)($_SERVER['REQUEST_METHOD']??'GET'));
$path=trim((string)($_GET['path']??''),'/');

function public_form_payload(): array
{
    $type=strtolower((string)($_SERVER['CONTENT_TYPE']??''));
    if(str_contains($type,'application/json')){
        $decoded=json_decode((string)file_get_contents('php://input'),true);
        return is_array($decoded)?$decoded:[];
    }
    return $_POST;
}

function public_form_files(mixed $spec): array
{
    if(!is_array($spec)||!array_key_exists('error',$spec)) return [];
    if(!is_array($spec['error'])) return [$spec];
    $files=[];
    foreach($spec['error'] as $i=>$error){
        $files[]=[
            'name'=>$spec['name'][$i]??'',
            'full_path'=>$spec['full_path'][$i]??'',
            'type'=>$spec['type'][$i]??'',
            'tmp_name'=>$spec['tmp_name'][$i]??'',
            'error'=>$error,
            'size'=>$spec['size'][$i]??0,
        ];
    }
    return $files;
}

function public_api_language_context(PDO $pdo, int $projectId): array
{
    $i18n = cms_i18n_settings($pdo, $projectId);
    $requested = strtolower(trim((string)($_GET['lang'] ?? $i18n['default_language'])));
    if (!$i18n['enabled'] || !in_array($requested, $i18n['languages'], true)) $requested = $i18n['default_language'];
    return ['i18n'=>$i18n,'requested'=>$requested];
}

function public_api_document_payload(PDO $pdo, array $doc, int $projectId, string $requestedLanguage): array
{
    $i18n = cms_i18n_settings($pdo, $projectId);
    if (!$i18n['enabled'] || !in_array($requestedLanguage, $i18n['languages'], true)) $requestedLanguage = $i18n['default_language'];
    $resolved = $i18n['default_language'];
    $fallback = false;
    $responseDoc = $doc;
    if ($i18n['enabled'] && $requestedLanguage !== $i18n['default_language']) {
        $stmt = $pdo->prepare('SELECT data_json FROM document_translations WHERE document_id=? AND language=? LIMIT 1');
        $stmt->execute([(int)$doc['id'],$requestedLanguage]);
        $translated = $stmt->fetchColumn();
        if (is_string($translated) && $translated !== '') {
            $responseDoc['data_json'] = $translated;
            $resolved = $requestedLanguage;
        } else {
            $fallback = true;
        }
    }
    return [
        'data'=>public_document_data($responseDoc),
        'requested'=>$requestedLanguage,
        'resolved'=>$resolved,
        'fallback'=>$fallback,
        'i18n'=>$i18n,
    ];
}

function public_api_folder_tree(PDO $pdo, array $project, array $folder, string $requestedLanguage, array $settings, int $depth = 0): array
{
    if ($depth > 64) return ['type'=>'folder','name'=>(string)($folder['name']??'…'),'slug'=>(string)($folder['slug']??''),'folders'=>[],'sections'=>[],'data_sets'=>[],'forms'=>[]];
    $projectId = (int)$project['id'];
    $folderId = (int)$folder['id'];
    $folderPath = ProjectAccess::folderApiPath($pdo, $folderId, $project);
    $apiPath = public_api_path((string)$project['slug'].'/'.$folderPath);

    $tree = [
        'type'=>'folder',
        'name'=>(string)$folder['name'],
        'slug'=>(string)$folder['slug'],
        'path'=>$apiPath,
        'url'=>absolute_url($apiPath),
        'folders'=>[],
        'sections'=>[],
        'data_sets'=>[],
        'forms'=>[],
    ];

    // Folder endpoints always expose the complete enabled structure recursively.
    // Payload weight is controlled only by the project response mode.
    $stmt = $pdo->prepare('SELECT * FROM folders WHERE parent_id=? AND api_enabled=1 ORDER BY sort_order,name,id');
    $stmt->execute([$folderId]);
    foreach ($stmt->fetchAll() as $child) {
        if ((string)($child['slug'] ?? '') === feather_trash_slug()) continue;
        $tree['folders'][] = public_api_folder_tree($pdo, $project, $child, $requestedLanguage, $settings, $depth + 1);
    }

    $stmt = $pdo->prepare('SELECT * FROM documents WHERE folder_id=? AND api_enabled=1 ORDER BY name,id');
    $stmt->execute([$folderId]);
    foreach ($stmt->fetchAll() as $doc) {
        $localized = null;
        if (!empty($settings['include_data'])) $localized = public_api_document_payload($pdo, $doc, $projectId, $requestedLanguage);
        $docPath = public_api_path((string)$project['slug'].'/'.ProjectAccess::documentApiPath($pdo, $doc, $project));
        $section = [
            'type'=>'section',
            'name'=>(string)$doc['name'],
            'slug'=>(string)$doc['slug'],
            'mode'=>(($doc['mode'] ?? 'single') === 'multiple' ? 'multiple' : 'single'),
            'path'=>$docPath,
            'url'=>absolute_url($docPath),
        ];
        if (!empty($settings['include_data']) && is_array($localized)) $section['data'] = $localized['data'];
        $tree['sections'][] = $section;
    }

    $links=$pdo->prepare("SELECT cl.resource_type,cl.resource_id,d.name AS data_name,d.slug AS data_slug,d.mode AS data_mode,d.api_enabled AS data_api_enabled,d.schema_json AS data_schema,d.data_json AS data_json,f.name AS form_name,f.slug AS form_slug,f.api_enabled AS form_api_enabled,f.schema_json AS form_schema,f.success_message AS form_success FROM content_links cl LEFT JOIN data_sets d ON cl.resource_type='data' AND d.id=cl.resource_id AND d.project_id=cl.project_id LEFT JOIN forms f ON cl.resource_type='form' AND f.id=cl.resource_id AND f.project_id=cl.project_id WHERE cl.project_id=? AND cl.folder_id=? ORDER BY cl.sort_order,cl.id");
    $links->execute([$projectId,$folderId]);
    foreach($links->fetchAll() as $link){
        if((string)$link['resource_type']==='data' && $link['data_name']!==null && (bool)$link['data_api_enabled']){
            $entry=['type'=>'data','name'=>(string)$link['data_name'],'slug'=>(string)$link['data_slug'],'mode'=>sanitize_data_mode($link['data_mode']??'single'),'url'=>public_api_absolute_url((string)$project['slug'].'/data/'.(string)$link['data_slug'])];
            if(!empty($settings['include_data'])){$row=['id'=>(int)$link['resource_id'],'project_id'=>$projectId,'mode'=>$link['data_mode'],'schema_json'=>$link['data_schema'],'data_json'=>$link['data_json']];$entry['data']=public_data_set_data($pdo,$row,$projectId);}
            $tree['data_sets'][]=$entry;
        }elseif((string)$link['resource_type']==='form' && $link['form_name']!==null && (bool)$link['form_api_enabled']){
            $entry=['type'=>'form','name'=>(string)$link['form_name'],'slug'=>(string)$link['form_slug'],'method'=>'POST','url'=>public_api_absolute_url((string)$project['slug'].'/forms/'.(string)$link['form_slug'])];
            if(!empty($settings['include_data'])){$entry['schema']=sanitize_form_schema(decode_json($link['form_schema']??'[]'));$entry['success_message']=(string)$link['form_success'];}
            $tree['forms'][]=$entry;
        }
    }

    return $tree;
}

function public_api_folder_counts(array $tree): array
{
    $folders = 1;
    $sections = count(is_array($tree['sections'] ?? null) ? $tree['sections'] : []);
    $dataSets = count(is_array($tree['data_sets'] ?? null) ? $tree['data_sets'] : []);
    $forms = count(is_array($tree['forms'] ?? null) ? $tree['forms'] : []);
    foreach (($tree['folders'] ?? []) as $child) {
        $count = public_api_folder_counts($child);
        $folders += $count['folders'];
        $sections += $count['sections'];
        $dataSets += $count['data_sets'] ?? 0;
        $forms += $count['forms'] ?? 0;
    }
    return ['folders'=>$folders,'sections'=>$sections,'data_sets'=>$dataSets,'forms'=>$forms];
}

function public_form_descriptor(array $form): array
{
    $schema=sanitize_form_schema(decode_json($form['schema_json']??'[]'));
    return [
        'id'=>(int)$form['id'],
        'name'=>(string)$form['name'],
        'slug'=>(string)$form['slug'],
        'fields'=>$schema,
        'success_message'=>(string)$form['success_message'],
        'endpoint'=>public_api_absolute_url('forms/'.(string)$form['slug']),
    ];
}

// Projects are the first namespace of the public API.
$allProjects=$pdo->query('SELECT * FROM projects ORDER BY id')->fetchAll();
if(!$allProjects) json_response(['error'=>'No projects'],404);
foreach($allProjects as &$p){$p['id']=(int)$p['id'];$p['root_folder_id']=(int)$p['root_folder_id'];} unset($p);

if($path===''){
    $projects=[];
    $requestToken=request_project_api_token();
    foreach($allProjects as $p){
        $status=project_api_status($pdo,(int)$p['id']);
        if(!$status['enabled']) continue;
        $access=project_api_access($pdo,(int)$p['id']);
        if($access['mode']==='private' && !project_api_token_valid($pdo,(int)$p['id'],$requestToken)) continue;
        $projectUrl=public_api_absolute_url((string)$p['slug']);
        $projects[]=[
            'id'=>(int)$p['id'],
            'name'=>(string)$p['name'],
            'slug'=>(string)$p['slug'],
            'access'=>$access['mode'],
            'api_enabled'=>true,
            'response_mode'=>project_api_response($pdo,(int)$p['id'])['mode'],
            'url'=>$projectUrl,
            'content_url'=>$projectUrl,
            'forms_url'=>public_api_absolute_url((string)$p['slug'].'/forms'),
            'data_url'=>public_api_absolute_url((string)$p['slug'].'/data'),
        ];
    }
    json_response([
        'name'=>cms_config('name'),
        'api'=>'MaterCMS API',
        'version'=>1,
        'url'=>public_api_absolute_url(),
        'projects'=>$projects,
    ]);
}

$segments=array_values(array_filter(explode('/',$path),fn($x)=>$x!==''));
$first=(string)($segments[0]??'');
$project=null;
foreach($allProjects as $candidate){if((string)$candidate['slug']===$first){$project=$candidate;array_shift($segments);break;}}

// Backwards compatibility for existing sites: old /api/home/hero still points to the first project.
$legacy=false;
if(!$project){$project=$allProjects[0];$segments=array_values(array_filter(explode('/',$path),fn($x)=>$x!==''));$legacy=true;}
$projectPath=implode('/',$segments);
$projectId=(int)$project['id'];
$projectRoot=(int)$project['root_folder_id'];
$projectApiUrl=public_api_absolute_url((string)$project['slug']);
header('X-MaterCMS-Project: '.(string)$project['slug']);
require_project_api_enabled($pdo,$project);
require_project_api_access($pdo,$project);
$projectAccess=project_api_access($pdo,$projectId);
$projectResponse=project_api_response($pdo,$projectId);
$fullResponse=request_project_api_full_response($pdo,$projectId);
header('X-MaterCMS-API-Access: '.$projectAccess['mode']);
header('X-MaterCMS-Response-Mode: '.($fullResponse?'full':'data'));

if($projectPath===''){
    $folderIds=feather_visible_folder_ids($pdo,$project);
    $docs=[];
    if($folderIds){
        $ph=implode(',',array_fill(0,count($folderIds),'?'));
        $stmt=$pdo->prepare("SELECT * FROM documents WHERE folder_id IN ($ph) AND api_enabled=1 ORDER BY name");
        $stmt->execute($folderIds);$docs=$stmt->fetchAll();
    }
    $endpoints=[];
    foreach($docs as $doc){
        $p=ProjectAccess::documentApiPath($pdo,$doc,$project);
        $pathValue=public_api_path((string)$project['slug'].'/'.$p);
        $endpoints[]=['name'=>$doc['name'],'mode'=>(($doc['mode']??'single')==='multiple'?'multiple':'single'),'returns'=>(($doc['mode']??'single')==='multiple'?'array':'object'),'path'=>$pathValue,'url'=>absolute_url($pathValue)];
    }
    $folderEndpoints=[];
    if($folderIds){
        $phFolders=implode(',',array_fill(0,count($folderIds),'?'));
        $stmt=$pdo->prepare("SELECT id,name,slug,parent_id FROM folders WHERE id IN ($phFolders) AND id<>? AND api_enabled=1 ORDER BY sort_order,name,id");
        $stmt->execute(array_merge($folderIds,[$projectRoot]));
        foreach($stmt->fetchAll() as $folder){
            $folderPath=ProjectAccess::folderApiPath($pdo,(int)$folder['id'],$project);
            $pathValue=public_api_path((string)$project['slug'].'/'.$folderPath);
            $folderEndpoints[]=['name'=>$folder['name'],'type'=>'folder-tree','method'=>'GET','path'=>$pathValue,'url'=>absolute_url($pathValue)];
        }
    }
    $forms=[];
    $stmt=$pdo->prepare('SELECT id,name,slug FROM forms WHERE project_id=? AND api_enabled=1 ORDER BY name');$stmt->execute([$projectId]);
    foreach($stmt->fetchAll() as $form){
        $pathValue=public_api_path((string)$project['slug'].'/forms/'.(string)$form['slug']);
        $forms[]=['name'=>$form['name'],'method'=>'POST','path'=>$pathValue,'url'=>absolute_url($pathValue)];
    }
    $dataSets=[];
    $stmt=$pdo->prepare('SELECT id,name,slug,mode FROM data_sets WHERE project_id=? AND api_enabled=1 ORDER BY name');$stmt->execute([$projectId]);
    foreach($stmt->fetchAll() as $set){
        $pathValue=public_api_path((string)$project['slug'].'/data/'.(string)$set['slug']);
        $dataSets[]=['name'=>$set['name'],'mode'=>(($set['mode']??'single')==='multiple'?'multiple':'single'),'returns'=>(($set['mode']??'single')==='multiple'?'array':'object'),'method'=>'GET','path'=>$pathValue,'url'=>absolute_url($pathValue)];
    }
    json_response([
        'project'=>[
            'id'=>$projectId,
            'name'=>(string)$project['name'],
            'slug'=>(string)$project['slug'],
            'url'=>$projectApiUrl,
            'access'=>$projectAccess['mode'],
            'api_enabled'=>true,
            'response_mode'=>$projectResponse['mode'],
        ],
        'folders'=>$folderEndpoints,
        'endpoints'=>$endpoints,
        'forms'=>$forms,
        'data'=>$dataSets,
        'i18n'=>cms_i18n_settings($pdo,$projectId),
    ]);
}

if(str_starts_with($projectPath,'data/')){
    if($method!=='GET') json_response(['error'=>'Method not allowed'],405);
    $slug=trim(substr($projectPath,5),'/');
    if($slug===''||str_contains($slug,'/')) json_response(['error'=>'Data not found'],404);
    $stmt=$pdo->prepare('SELECT * FROM data_sets WHERE project_id=? AND slug=? AND api_enabled=1 LIMIT 1');
    $stmt->execute([$projectId,$slug]);
    $set=$stmt->fetch();
    if(!$set) json_response(['error'=>'Data not found'],404);
    $data=public_data_set_data($pdo,$set,$projectId);
    $pathValue=public_api_path((string)$project['slug'].'/data/'.(string)$set['slug']);
    if($fullResponse) json_response(['data'=>$data,'meta'=>['project'=>['name'=>$project['name'],'slug'=>$project['slug']],'name'=>$set['name'],'slug'=>$set['slug'],'mode'=>(($set['mode']??'single')==='multiple'?'multiple':'single'),'returns'=>(($set['mode']??'single')==='multiple'?'array':'object'),'updated_at'=>$set['updated_at'],'path'=>$pathValue,'url'=>absolute_url($pathValue)]]);
    json_response($data);
}

if(str_starts_with($projectPath,'forms/')){
    $slug=trim(substr($projectPath,6),'/');
    if($slug===''||str_contains($slug,'/')) json_response(['error'=>'Form not found'],404);
    $stmt=$pdo->prepare('SELECT * FROM forms WHERE slug=? AND project_id=? AND api_enabled=1 LIMIT 1');
    $stmt->execute([$slug,$projectId]);
    $form=$stmt->fetch();
    if(!$form) json_response(['error'=>'Form not found'],404);

    if($method==='GET'){
        $descriptor=public_form_descriptor($form);
        $descriptor['project']=['id'=>$projectId,'name'=>(string)$project['name'],'slug'=>(string)$project['slug']];
        $descriptor['method']='POST';
        $descriptor['response_mode']=$projectResponse['mode'];
        $descriptor['endpoint']=public_api_absolute_url((string)$project['slug'].'/forms/'.(string)$form['slug']);
        json_response($descriptor);
    }
    if($method!=='POST') json_response(['error'=>'Method not allowed'],405);

    $payload=public_form_payload();
    if(trim((string)($payload['_website']??''))!=='') json_response(['ok'=>true,'message'=>(string)$form['success_message']]);

    $schema=sanitize_form_schema(decode_json($form['schema_json']??'[]'));
    $data=[];$newUploads=[];
    try{
        foreach($schema as $field){
            $key=(string)$field['key'];$type=(string)$field['type'];$required=(bool)($field['required']??false);
            if($type==='file'){
                $saved=[];
                foreach(public_form_files($_FILES[$key]??null) as $file){
                    if(($file['error']??UPLOAD_ERR_NO_FILE)===UPLOAD_ERR_NO_FILE) continue;
                    $formLimit=max(1,(int)(cms_config('form_upload_max_mb')??20))*1024*1024;
                    if((int)($file['size']??0)>$formLimit) throw new RuntimeException('Файл «'.$field['label'].'» слишком большой.');
                    $pathValue=upload_asset($file,'file');
                    if($pathValue!==''){$saved[]=$pathValue;$filename=upload_filename_from_value($pathValue);if($filename!==null)$newUploads[]=$filename;}
                    if(empty($field['multiple'])) break;
                }
                if($required&&!$saved) throw new RuntimeException('Заполните поле «'.$field['label'].'».');
                $data[$key]=!empty($field['multiple'])?$saved:($saved[0]??'');continue;
            }
            $raw=$payload[$key]??null;
            if($type==='checkbox'){$value=filter_var($raw,FILTER_VALIDATE_BOOLEAN);if($required&&!$value)throw new RuntimeException('Подтвердите поле «'.$field['label'].'».');$data[$key]=$value;continue;}
            $value=is_array($raw)?'':trim((string)($raw??''));
            if($required&&$value==='')throw new RuntimeException('Заполните поле «'.$field['label'].'».');
            if($type==='email'&&$value!==''&&!filter_var($value,FILTER_VALIDATE_EMAIL))throw new RuntimeException('Проверьте email в поле «'.$field['label'].'».');
            if($type==='number')$data[$key]=$value===''?null:(float)$value;
            elseif($type==='select'){$options=is_array($field['options']??null)?$field['options']:[];if($value!==''&&$options&&!in_array($value,$options,true))throw new RuntimeException('Недопустимое значение поля «'.$field['label'].'».');$data[$key]=$value;}
            else $data[$key]=$value;
        }
        $stmt=$pdo->prepare('INSERT INTO form_submissions(form_id,data_json,status) VALUES(?,?,?)');
        $stmt->execute([(int)$form['id'],json_encode($data,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),'new']);
        $submissionId=Database::lastInsertId($pdo);
        if(!$fullResponse) json_response([
            'ok'=>true,
            'message'=>(string)$form['success_message'],
        ],201);
        json_response([
            'ok'=>true,
            'message'=>(string)$form['success_message'],
            'submission_id'=>$submissionId,
            'project'=>(string)$project['slug'],
            'form'=>(string)$form['slug'],
        ],201);
    }catch(Throwable $e){foreach($newUploads as $name)unlink_upload_file($name);json_response(['ok'=>false,'error'=>$e->getMessage()],422);}
}

if($method!=='GET') json_response(['error'=>'Method not allowed'],405);
if(!$segments) json_response(['error'=>'Not found'],404);

$languageContext = public_api_language_context($pdo, $projectId);
$requestedLanguage = $languageContext['requested'];

// First try the complete content path as a folder. A folder endpoint returns the
// full enabled subtree so a frontend can render a whole content branch with one GET.
$folderParent = $projectRoot;
$resolvedFolder = null;
$folderResolved = true;
foreach($segments as $folderSlug){
    if($folderSlug===feather_trash_slug()){ $folderResolved=false; break; }
    // Parent folders are path namespaces. Their own API switch controls only
    // their aggregate endpoint; an enabled nested folder can still have its
    // own direct endpoint even when an ancestor aggregate is disabled.
    $stmt=$pdo->prepare('SELECT * FROM folders WHERE parent_id=? AND slug=? LIMIT 1');
    $stmt->execute([$folderParent,$folderSlug]);
    $folder=$stmt->fetch();
    if(!$folder){ $folderResolved=false; break; }
    $resolvedFolder=$folder;
    $folderParent=(int)$folder['id'];
}
if($folderResolved && $resolvedFolder && (bool)($resolvedFolder['api_enabled'] ?? true)){
    $treeSettings = folder_api_tree_settings($resolvedFolder, $fullResponse);
    $cacheStatus = 'BYPASS';
    $package = null;
    $cacheKey = '';
    if ($treeSettings['cache_enabled']) {
        $cacheKey = api_tree_cache_key($pdo,$project,$resolvedFolder,$requestedLanguage,$treeSettings);
        $package = api_tree_cache_get($cacheKey);
        if (is_array($package) && is_array($package['tree'] ?? null) && is_array($package['counts'] ?? null)) {
            $cacheStatus = 'HIT';
        } else {
            $package = null;
        }
    }
    if (!$package) {
        $tree = public_api_folder_tree($pdo,$project,$resolvedFolder,$requestedLanguage,$treeSettings);
        $counts = public_api_folder_counts($tree);
        $package = ['tree'=>$tree,'counts'=>$counts];
        if ($treeSettings['cache_enabled']) {
            api_tree_cache_put($cacheKey,$package,(int)$treeSettings['cache_ttl']);
            $cacheStatus = 'MISS';
        }
    }
    $tree = $package['tree'];
    $counts = $package['counts'];
    header('Content-Language: '.$requestedLanguage);
    header('X-MaterCMS-Tree-Cache: '.$cacheStatus);
    header('X-MaterCMS-Tree-Content: '.($fullResponse ? 'FULL' : 'STRUCTURE'));
    if($fullResponse){
        json_response([
            'data'=>$tree,
            'meta'=>[
                'project'=>['name'=>$project['name'],'slug'=>$project['slug']],
                'type'=>'folder-tree',
                'name'=>(string)$resolvedFolder['name'],
                'slug'=>(string)$resolvedFolder['slug'],
                'recursive'=>true,
                'cache'=>[
                    'enabled'=>(bool)$treeSettings['cache_enabled'],
                    'ttl'=>(int)$treeSettings['cache_ttl'],
                    'status'=>strtolower($cacheStatus),
                ],
                'folders'=>$counts['folders'],
                'sections'=>$counts['sections'],
                'data_sets'=>$counts['data_sets'] ?? 0,
                'forms'=>$counts['forms'] ?? 0,
                'response_mode'=>'full',
                'i18n'=>$languageContext['i18n'],
                'language'=>$requestedLanguage,
                'path'=>$tree['path'],
                'url'=>$tree['url'],
            ],
        ]);
    }
    json_response($tree);
}

$docSlug=array_pop($segments);
$parentId=$projectRoot;
foreach($segments as $folderSlug){
    if($folderSlug===feather_trash_slug()) json_response(['error'=>'Not found'],404);
    $stmt=$pdo->prepare('SELECT id FROM folders WHERE parent_id=? AND slug=? LIMIT 1');
    $stmt->execute([$parentId,$folderSlug]);
    $id=$stmt->fetchColumn();if(!$id)json_response(['error'=>'Not found'],404);$parentId=(int)$id;
}
$stmt=$pdo->prepare('SELECT * FROM documents WHERE folder_id=? AND slug=? AND api_enabled=1 LIMIT 1');
$stmt->execute([$parentId,$docSlug]);
$doc=$stmt->fetch();if(!$doc)json_response(['error'=>'Not found'],404);

$localized = public_api_document_payload($pdo,$doc,$projectId,$requestedLanguage);
header('Content-Language: '.$localized['resolved']);
$data=$localized['data'];
$apiPath=public_api_path((string)$project['slug'].'/'.ProjectAccess::documentApiPath($pdo,$doc,$project));
if($fullResponse)json_response(['data'=>$data,'meta'=>['project'=>['name'=>$project['name'],'slug'=>$project['slug']],'name'=>$doc['name'],'mode'=>(($doc['mode']??'single')==='multiple'?'multiple':'single'),'returns'=>(($doc['mode']??'single')==='multiple'?'array':'object'),'updated_at'=>$doc['updated_at'],'path'=>$apiPath,'url'=>absolute_url($apiPath),'i18n'=>$localized['i18n'],'language'=>['requested'=>$localized['requested'],'resolved'=>$localized['resolved'],'fallback'=>$localized['fallback']]]]);
json_response($data);

