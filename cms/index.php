<?php
declare(strict_types=1);
require __DIR__ . '/core/bootstrap.php';

if (isset($_GET['logout'])) {
    Auth::logout();
}

$installFlash = $_SESSION['_matercms_install_flash'] ?? null;
unset($_SESSION['_matercms_install_flash']);
$installError = is_array($installFlash) ? (string)($installFlash['error'] ?? '') : null;
$installError = $installError !== '' ? $installError : null;
$installOld = is_array($installFlash) && is_array($installFlash['old'] ?? null) ? $installFlash['old'] : [];
$loginError = isset($_SESSION['_matercms_login_error']) ? (string)$_SESSION['_matercms_login_error'] : null;
unset($_SESSION['_matercms_login_error']);

function matercms_redirect_get(): never
{
    $requestUri = (string)($_SERVER['REQUEST_URI'] ?? '/');
    $path = (string)(parse_url($requestUri, PHP_URL_PATH) ?: '/');
    header('Location: ' . $path, true, 303);
    exit;
}

function matercms_install_old(array $source): array
{
    $allowed = ['db_driver','db_input_mode','db_host','db_port','db_name','db_user','db_sslmode','name','email'];
    $old = [];
    foreach ($allowed as $key) {
        if (isset($source[$key]) && is_scalar($source[$key])) $old[$key] = (string)$source[$key];
    }
    return $old;
}

function matercms_install_flash(string $message, array $old = []): never
{
    $_SESSION['_matercms_install_flash'] = ['error'=>$message,'old'=>$old];
    matercms_redirect_get();
}

function install_matercms(string $name, string $email, string $password): int
{
    $pdo = Database::connection();
    // DDL is intentionally created before the transaction: MySQL commits DDL
    // implicitly, while SQLite/PostgreSQL can keep using the same installation flow.
    Database::createSchema($pdo);

    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare('INSERT INTO users(name,email,password_hash,system_role) VALUES(?,?,?,?)');
        $stmt->execute([$name ?: 'Администратор', $email, password_hash($password, PASSWORD_DEFAULT), 'owner']);
        $userId = Database::lastInsertId($pdo);

        $pdo->prepare("INSERT INTO projects(name,slug,created_by) VALUES(?,?,?)")->execute(['Мой проект','main',$userId]);
        $projectId = Database::lastInsertId($pdo);
        $rootSlug = '__project_' . $projectId . '__';
        $pdo->prepare("INSERT INTO folders(parent_id,name,slug,sort_order) VALUES(NULL,?,?,0)")->execute(['Мой проект',$rootSlug]);
        $rootId = Database::lastInsertId($pdo);
        $pdo->prepare('UPDATE projects SET root_folder_id=? WHERE id=?')->execute([$rootId,$projectId]);
        Database::upsert($pdo, 'project_users', ['project_id'=>$projectId,'user_id'=>$userId], ['role'=>'owner','permissions_json'=>'{}']);

        $defaultI18n = json_encode(['enabled'=>false,'default_language'=>'ru','languages'=>['ru']], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
        Database::upsert($pdo, 'cms_settings', ['key'=>'i18n'], ['value_json'=>$defaultI18n]);
        Database::upsert($pdo, 'project_settings', ['project_id'=>$projectId,'key'=>'i18n'], ['value_json'=>$defaultI18n]);
        Database::upsert($pdo, 'project_settings', ['project_id'=>$projectId,'key'=>'api_access'], ['value_json'=>json_encode(['mode'=>'public','token_hash'=>'','token_prefix'=>'','created_at'=>null], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)]);
        Database::upsert($pdo, 'project_settings', ['project_id'=>$projectId,'key'=>'api_response'], ['value_json'=>json_encode(['full_response'=>false,'updated_at'=>null], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)]);
        Database::upsert($pdo, 'project_settings', ['project_id'=>$projectId,'key'=>'api_status'], ['value_json'=>json_encode(['enabled'=>true,'updated_at'=>null], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)]);
        Database::upsert($pdo, 'project_settings', ['project_id'=>$projectId,'key'=>'api_tree_revision'], ['value_json'=>json_encode(['token'=>'1'], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)]);

        $folderInsert = $pdo->prepare('INSERT INTO folders(parent_id,name,slug,sort_order) VALUES(?,?,?,?)');
        foreach ([['Главная','home',10],['Портфолио','portfolio',20],['Сайт','settings',30]] as [$folderName,$folderSlug,$sort]) {
            $folderInsert->execute([$rootId,$folderName,$folderSlug,$sort]);
        }
        $stmt = $pdo->prepare("SELECT id FROM folders WHERE parent_id=? AND slug=? LIMIT 1");
        $stmt->execute([$rootId,'home']);
        $homeId = (int)$stmt->fetchColumn();
        $stmt->execute([$rootId,'settings']);
        $settingsId = (int)$stmt->fetchColumn();

        $schema = json_encode([
            ['key'=>'title','label'=>'Заголовок','type'=>'text'],
            ['key'=>'description','label'=>'Описание','type'=>'textarea'],
            ['key'=>'button_text','label'=>'Текст кнопки','type'=>'text'],
            ['key'=>'button_url','label'=>'Ссылка кнопки','type'=>'link'],
            ['key'=>'image','label'=>'Изображение','type'=>'image'],
        ], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
        $data = json_encode([
            'title'=>'Создаём цифровые продукты с человеческим лицом',
            'description'=>'Помогаем бизнесу расти с помощью дизайна, технологий и смысла.',
            'button_text'=>'Смотреть проекты',
            'button_url'=>'/projects',
            'image'=>''
        ], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);

        $stmt = $pdo->prepare('INSERT INTO documents(folder_id,name,slug,mode,schema_json,data_json) VALUES(?,?,?,?,?,?)');
        $stmt->execute([$homeId,'Главный экран','hero','single',$schema,$data]);

        $seoSchema = json_encode([
            ['key'=>'title','label'=>'SEO заголовок','type'=>'text'],
            ['key'=>'description','label'=>'SEO описание','type'=>'textarea']
        ], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
        $stmt->execute([$homeId,'SEO','seo','single',$seoSchema,'{}']);

        $siteSchema = json_encode([
            ['key'=>'site_name','label'=>'Название сайта','type'=>'text'],
            ['key'=>'email','label'=>'Email','type'=>'text'],
            ['key'=>'phone','label'=>'Телефон','type'=>'text']
        ], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
        $stmt->execute([$settingsId,'Сайт','site','single',$siteSchema,'{}']);

        $pdo->exec('INSERT INTO revisions(document_id,schema_json,data_json) SELECT id,schema_json,data_json FROM documents');
        $pdo->commit();
        return $userId;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}

if (!Database::installed()) {
    $databaseAvailability = Database::driverAvailability();
    $environmentReport = feather_environment_report();

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $action = (string)($_POST['action'] ?? '');

        if ($action === 'configure_database') {
            verify_csrf();
            try {
                $databaseConfig = Database::configurationFromInput($_POST);
                Database::testConfiguration($databaseConfig);
                Database::saveConfiguration($databaseConfig);
                matercms_redirect_get();
            } catch (Throwable $e) {
                matercms_install_flash('Не удалось подключиться к базе: ' . $e->getMessage(), matercms_install_old($_POST));
            }
        }

        if ($action === 'reset_database_config') {
            verify_csrf();
            try {
                $currentDb = Database::configuredDatabase();
                if (Database::hasConfiguration() && ($currentDb['driver'] ?? '') === 'sqlite' && !Database::installed()) {
                    $path = (string)($currentDb['path'] ?? '');
                    if ($path !== '' && is_file($path)) @unlink($path);
                }
                Database::resetConfiguration();
                matercms_redirect_get();
            } catch (Throwable $e) {
                matercms_install_flash('Не удалось изменить конфигурацию: ' . $e->getMessage());
            }
        }

        if ($action === 'install') {
            verify_csrf();
            $name = trim((string)($_POST['name'] ?? 'Администратор'));
            $email = mb_strtolower(trim((string)($_POST['email'] ?? '')));
            $password = (string)($_POST['password'] ?? '');
            $old = matercms_install_old($_POST);

            if (!Database::hasConfiguration() && !Database::hasLegacySqlite()) {
                matercms_install_flash('Сначала выберите и проверьте базу данных.', $old);
            }
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                matercms_install_flash('Укажите корректный email.', $old);
            }
            if (strlen($password) < 8) {
                matercms_install_flash('Пароль должен содержать минимум 8 символов.', $old);
            }

            try {
                $userId = install_matercms($name, $email, $password);
                session_regenerate_id(true);
                $_SESSION['user_id'] = $userId;
                matercms_redirect_get();
            } catch (Throwable $e) {
                matercms_install_flash('Не удалось установить CMS: ' . $e->getMessage(), $old);
            }
        }

        // Unknown POSTs must not leave the browser on a POST response.
        matercms_redirect_get();
    }

    if (!Database::installed()) {
        $databaseSelectionSaved = Database::hasConfiguration() || Database::hasLegacySqlite();
        $databaseInfo = $databaseSelectionSaved ? Database::publicInfo() : null;
        $databaseConfigured = $databaseSelectionSaved && !empty($databaseInfo['connected']);
        if ($databaseSelectionSaved && !$databaseConfigured && !$installError) {
            $installError = 'Сохранённая база сейчас недоступна. Проверьте соединение или выберите другую СУБД.';
        }
        $selectedDriver = (string)($installOld['db_driver'] ?? ($databaseInfo['driver'] ?? 'sqlite'));
        if (!isset($databaseAvailability[$selectedDriver]) || empty($databaseAvailability[$selectedDriver]['available'])) {
            foreach ($databaseAvailability as $candidate => $meta) {
                if (!empty($meta['available'])) { $selectedDriver = $candidate; break; }
            }
        }
        ?><!doctype html>
        <html lang="ru">
        <head>
          <meta charset="utf-8">
          <meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover"><meta name="color-scheme" content="light dark"><script>(()=>{try{const m=localStorage.getItem('matercms-theme') || localStorage.getItem('mater-theme') || localStorage.getItem('feather-theme') || 'system';const d=m==='dark'||(m==='system'&&matchMedia('(prefers-color-scheme: dark)').matches);document.documentElement.dataset.theme=d?'dark':'light';document.documentElement.dataset.themeMode=m;document.querySelector('meta[name=\"theme-color\"]')?.setAttribute('content',d?'#0f1115':'#f5f6f8');}catch(e){}})();</script>
          <meta name="theme-color" content="#f5f6f8">
          <title>Установка MaterCMS</title>
          <link rel="icon" type="image/png" sizes="64x64" href="<?=e(asset_url('assets/branding/matercms-icon-light-64.png'))?>"><link rel="apple-touch-icon" href="<?=e(asset_url('assets/branding/matercms-icon-light-192.png'))?>"><link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
          <link rel="stylesheet" href="<?=e(asset_url('assets/admin.css'))?>"><script src="<?=e(asset_url('assets/ui.js'))?>"></script>
        </head>
        <body class="installer-body">
          <main class="installer-shell <?= $databaseConfigured ? 'admin-step' : 'database-step' ?>">
            <aside class="installer-side">
              <div class="auth-brand auth-brand-wordmark"><span class="brand-wordmark"><img class="logo-light" src="<?=e(asset_url('assets/branding/matercms-wordmark-light.webp'))?>" alt="MaterCMS"><img class="logo-dark" src="<?=e(asset_url('assets/branding/matercms-wordmark-dark.webp'))?>" alt="MaterCMS"></span><div class="sr-only-brand"><strong>MaterCMS</strong><small>Контент без лишнего</small></div></div>
              <div class="installer-side-copy"><span class="auth-kicker"><i class="bi bi-stars"></i> Первый запуск</span>
                <div class="installer-preflight <?= $environmentReport['ready'] ? 'ready' : 'warning' ?>">
                  <span><i class="bi <?= $environmentReport['ready'] ? 'bi-check-circle' : 'bi-exclamation-triangle' ?>"></i></span>
                  <div><strong><?= $environmentReport['ready'] ? 'Сервер готов' : 'Нужна настройка сервера' ?></strong><small><?= $environmentReport['ready'] ? 'MaterCMS проверил обязательные зависимости.' : 'Проверьте PHP 8.1+, PDO-драйвер и права на cms/data и cms/uploads.' ?></small></div>
                </div><?php if(!$databaseConfigured): ?><h2>Где хранить данные?</h2><p>SQLite работает локально без настройки. Для серверной базы подключите MySQL или PostgreSQL.</p><?php else: ?><h2>Последний шаг.</h2><p>База уже подключена. Создайте владельца MaterCMS и переходите к работе.</p><?php endif; ?></div>
              <div class="installer-progress">
                <div class="installer-progress-item active"><span>01</span><div><b>База данных</b><small><?= $databaseConfigured ? 'Подключена' : 'Выберите хранилище' ?></small></div><i class="bi <?= $databaseConfigured ? 'bi-check-circle' : 'bi-circle-fill' ?>"></i></div>
                <div class="installer-progress-item <?= $databaseConfigured ? 'active' : '' ?>"><span>02</span><div><b>Администратор</b><small><?= $databaseConfigured ? 'Создайте владельца' : 'Следующий шаг' ?></small></div><i class="bi <?= $databaseConfigured ? 'bi-circle-fill' : 'bi-circle' ?>"></i></div>
              </div>
              <small class="installer-side-foot"><i class="bi bi-shield-check"></i> Конфигурация хранится локально в MaterCMS</small>
            </aside>
            <section class="installer-main">
              <header class="installer-main-head">
                <span class="installer-step-badge"><?= $databaseConfigured ? '02 / 02' : '01 / 02' ?></span>
                <small>ПЕРВЫЙ ЗАПУСК</small>
                <h1><?php if($databaseConfigured): ?>Создайте администратора<?php else: ?><span class="installer-title-desktop">Выберите базу данных</span><span class="installer-title-compact">Где хранить данные?</span><?php endif; ?></h1>
                <p><?= $databaseConfigured ? 'База подключена. Осталось создать владельца MaterCMS.' : 'SQLite — без настройки. MySQL и PostgreSQL можно подключить готовым URL или отдельными полями.' ?></p>
              </header>

              <?php if($installError):?><div class="installer-alert"><i class="bi bi-exclamation-triangle"></i><div><b>Не получилось</b><p><?=e($installError)?></p></div></div><?php endif;?>

              <?php if(!$databaseConfigured): ?>
              <form method="post" class="installer-form" id="databaseSetupForm">
                <?=csrf_field()?><input type="hidden" name="action" value="configure_database">
                <div class="database-choice-grid">
                  <?php foreach($databaseAvailability as $driver => $meta): ?>
                    <label class="database-choice-card <?=empty($meta['available'])?'disabled':''?>">
                      <input type="radio" name="db_driver" value="<?=e($driver)?>" <?= $selectedDriver===$driver?'checked':'' ?> <?=empty($meta['available'])?'disabled':''?>>
                      <span class="database-choice-icon <?=e($driver)?>"><i class="bi <?= $driver==='sqlite'?'bi-database':($driver==='mysql'?'bi-hdd-stack':'bi-boxes') ?>"></i></span>
                      <span class="database-choice-copy">
                        <span class="database-choice-title"><b><?=e($meta['label'])?></b><?php if(!empty($meta['available'])):?><em><i class="bi bi-check-circle"></i> Доступно</em><?php else:?><em class="missing"><i class="bi bi-x-circle"></i> Нет <?=e($meta['extension'])?></em><?php endif;?></span>
                        <small><?=e($meta['description'])?></small>
                      </span>
                      <i class="bi bi-check-circle database-choice-check"></i>
                    </label>
                  <?php endforeach; ?>
                </div>

                <div class="database-config-panel" data-server-db>
                  <div class="database-config-head">
                    <div><small>ПОДКЛЮЧЕНИЕ</small><h2>MySQL / PostgreSQL</h2><p>Можно заполнить обычные поля или вставить единый URL подключения.</p></div>
                    <div class="database-input-mode">
                      <label><input type="radio" name="db_input_mode" value="url" <?=($installOld['db_input_mode']??'url')!=='fields'?'checked':''?>><span>URL</span></label>
                      <label><input type="radio" name="db_input_mode" value="fields" <?=($installOld['db_input_mode']??'')==='fields'?'checked':''?>><span>Поля</span></label>
                    </div>
                  </div>
                  <div class="db-mode-fields" data-db-mode="fields">
                    <div class="installer-field-grid">
                      <label>Хост<input name="db_host" value="<?=e($installOld['db_host']??'127.0.0.1')?>" autocomplete="off"></label>
                      <label>Порт<input name="db_port" inputmode="numeric" value="<?=e($installOld['db_port']??'')?>" placeholder="Автоматически"></label>
                      <label class="span-2">База данных<input name="db_name" value="<?=e($installOld['db_name']??'')?>" autocomplete="off" placeholder="matercms"></label>
                      <label>Пользователь<input name="db_user" value="<?=e($installOld['db_user']??'')?>" autocomplete="username"></label>
                      <label>Пароль<div class="password-control"><input data-password-input type="password" name="db_password" autocomplete="new-password"><button class="password-toggle" data-password-toggle type="button" aria-label="Показать пароль" aria-pressed="false" title="Показать пароль"><i class="bi bi-eye"></i></button></div></label>
                    </div>
                    <label class="pgsql-ssl-field">SSL PostgreSQL<select name="db_sslmode"><option value="prefer">Prefer</option><option value="require">Require</option><option value="disable">Disable</option></select></label>
                  </div>
                  <div class="db-mode-url" data-db-mode="url">
                    <label>URL подключения<input name="db_url" value="" autocomplete="off" placeholder="mysql://user:password@host:3306/database"></label>
                    <small>Примеры: <code>mysql://user:pass@host:3306/db</code> или <code>postgresql://user:pass@host:5432/db?sslmode=require</code></small>
                  </div>
                </div>

                <div class="installer-sqlite-note" data-sqlite-note>
                  <span><i class="bi bi-lightning-charge"></i></span>
                  <div><b>SQLite не требует настройки</b><p>MaterCMS создаст <code>cms/data/matercms.sqlite</code>. Никаких host, логина или отдельного сервера.</p></div>
                </div>

                <button class="button primary installer-primary" type="submit"><i class="bi bi-plug"></i> Проверить и продолжить</button>
              </form>
            <?php else: ?>
              <section class="database-connected-card">
                <span class="database-connected-icon"><i class="bi bi-check2-circle"></i></span>
                <div class="database-connected-copy"><small>БАЗА ПОДКЛЮЧЕНА</small><h2><?=e((string)($databaseInfo['label']??'Database'))?></h2><p><?php if(($databaseInfo['driver']??'')==='sqlite'):?><?=e((string)($databaseInfo['database']??'matercms.sqlite'))?><?php else:?><?=e((string)($databaseInfo['host']??''))?>:<?=e((string)($databaseInfo['port']??''))?> / <?=e((string)($databaseInfo['database']??''))?><?php endif;?></p></div>
                <span class="database-connected-status"><i class="bi bi-circle-fill"></i> Соединение работает</span>
              </section>

              <form method="post" class="installer-admin-form">
                <?=csrf_field()?><input type="hidden" name="action" value="install">
                <div class="installer-field-grid">
                  <label class="span-2">Ваше имя<input name="name" required value="<?=e($installOld['name']??'Администратор')?>" autocomplete="name"></label>
                  <label class="span-2">Email<input type="email" name="email" required value="<?=e($installOld['email']??'')?>" autocomplete="email"></label>
                  <label class="span-2">Пароль<div class="password-control"><input data-password-input type="password" name="password" minlength="8" required placeholder="Минимум 8 символов" autocomplete="new-password"><button class="password-toggle" data-password-toggle type="button" aria-label="Показать пароль" aria-pressed="false" title="Показать пароль"><i class="bi bi-eye"></i></button></div></label>
                </div>
                <button class="button primary installer-primary" type="submit"><i class="bi bi-stars"></i> Установить MaterCMS</button>
              </form>
              <form method="post" class="installer-change-db"><?=csrf_field()?><input type="hidden" name="action" value="reset_database_config"><button type="submit" class="button ghost"><i class="bi bi-arrow-left"></i> Выбрать другую базу</button></form>
            <?php endif; ?>

              <footer class="installer-footer"><span><i class="bi bi-shield-check"></i> <code>cms/data/database.php</code> защищён от публичного доступа</span><span>PHP 8.1+ · PDO</span></footer>
            </section>
          </main>
          <script>
          (()=>{
            const form=document.getElementById('databaseSetupForm'); if(!form)return;
            const driverInputs=[...form.querySelectorAll('input[name="db_driver"]')];
            const modeInputs=[...form.querySelectorAll('input[name="db_input_mode"]')];
            const server=form.querySelector('[data-server-db]'); const sqlite=form.querySelector('[data-sqlite-note]');
            const sync=()=>{const driver=form.querySelector('input[name="db_driver"]:checked')?.value||'sqlite'; const mode=form.querySelector('input[name="db_input_mode"]:checked')?.value||'url'; server.hidden=driver==='sqlite'; sqlite.hidden=driver!=='sqlite'; form.querySelectorAll('[data-db-mode]').forEach(el=>el.hidden=el.dataset.dbMode!==mode); const port=form.querySelector('[name="db_port"]'); if(port&&!port.value)port.placeholder=driver==='mysql'?'3306':'5432'; const url=form.querySelector('[name="db_url"]'); if(url)url.placeholder=driver==='mysql'?'mysql://user:password@host:3306/database':'postgresql://user:password@host:5432/database?sslmode=require'; form.querySelector('.pgsql-ssl-field')?.toggleAttribute('hidden',driver!=='pgsql');};
            [...driverInputs,...modeInputs].forEach(input=>input.addEventListener('change',sync)); sync();
          })();
          </script>
        </body></html><?php
        exit;
    }
}

$user = Auth::user();
if (!$user) {
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'login') {
        verify_csrf();
        if (Auth::attempt((string)($_POST['email'] ?? ''), (string)($_POST['password'] ?? ''))) {
            matercms_redirect_get();
        }
        $_SESSION['_matercms_login_error'] = 'Неверный email или пароль.';
        matercms_redirect_get();
    }

    if (!$user) {
        ?><!doctype html>
        <html lang="ru"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover"><meta name="color-scheme" content="light dark"><meta name="theme-color" content="#f5f6f8"><script>(()=>{try{const m=localStorage.getItem('matercms-theme') || localStorage.getItem('mater-theme') || localStorage.getItem('feather-theme') || 'system';const d=m==='dark'||(m==='system'&&matchMedia('(prefers-color-scheme: dark)').matches);document.documentElement.dataset.theme=d?'dark':'light';document.documentElement.dataset.themeMode=m;document.querySelector('meta[name=\"theme-color\"]')?.setAttribute('content',d?'#0f1115':'#f5f6f8');}catch(e){}})();</script><title>Вход — MaterCMS</title><link rel="icon" type="image/png" sizes="64x64" href="<?=e(asset_url('assets/branding/matercms-icon-light-64.png'))?>"><link rel="apple-touch-icon" href="<?=e(asset_url('assets/branding/matercms-icon-light-192.png'))?>"><link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css"><link rel="stylesheet" href="<?=e(asset_url('assets/admin.css'))?>"><script src="<?=e(asset_url('assets/ui.js'))?>"></script></head>
        <body class="auth-body">
          <main class="auth-shell">
            <section class="auth-product-panel">
              <div class="auth-brand auth-brand-wordmark"><span class="brand-wordmark"><img class="logo-light" src="<?=e(asset_url('assets/branding/matercms-wordmark-light.webp'))?>" alt="MaterCMS"><img class="logo-dark" src="<?=e(asset_url('assets/branding/matercms-wordmark-dark.webp'))?>" alt="MaterCMS"></span><div class="sr-only-brand"><strong>MaterCMS</strong><small>Контент без лишнего</small></div></div>
              <div class="auth-product-copy">
                <span class="auth-kicker"><i class="bi bi-stars"></i> Headless CMS</span>
                <h1>Контент должен быть простым.</h1>
                <p>Папки, разделы, данные, формы и API — без ощущения тяжёлой административной панели.</p>
              </div>
              <div class="auth-feature-list">
                <span><i class="bi bi-cloud-check"></i><b>Автосохранение</b></span>
                <span><i class="bi bi-braces"></i><b>Read-only API</b></span>
                <span><i class="bi bi-lightning-charge"></i><b>Быстрый интерфейс</b></span>
              </div>
              <small class="auth-product-foot">MaterCMS · Ваш контент остаётся вашим</small>
            </section>
            <section class="auth-login-panel">
              <div class="auth-mobile-brand auth-brand-wordmark"><span class="brand-wordmark"><img class="logo-light" src="<?=e(asset_url('assets/branding/matercms-wordmark-light.webp'))?>" alt="MaterCMS"><img class="logo-dark" src="<?=e(asset_url('assets/branding/matercms-wordmark-dark.webp'))?>" alt="MaterCMS"></span><div class="sr-only-brand"><strong>MaterCMS</strong><small>Контент без лишнего</small></div></div>
              <div class="auth-login-head"><small>ВХОД В MATERCMS</small><h2>С возвращением</h2><p>Продолжите работу с вашим контентом.</p></div>
              <?php if($loginError):?><div class="auth-error"><i class="bi bi-exclamation-circle"></i><span><?=e($loginError)?></span></div><?php endif;?>
              <form method="post" class="auth-form"><?=csrf_field()?><input type="hidden" name="action" value="login">
                <label><span>Email</span><div class="auth-input-wrap"><i class="bi bi-envelope"></i><input type="email" name="email" required autofocus autocomplete="email" placeholder="name@example.com"></div></label>
                <label><span>Пароль</span><div class="auth-input-wrap auth-input-password"><i class="bi bi-lock"></i><input data-password-input type="password" name="password" required autocomplete="current-password" placeholder="Введите пароль"><button class="password-toggle" data-password-toggle type="button" aria-label="Показать пароль" aria-pressed="false" title="Показать пароль"><i class="bi bi-eye"></i></button></div></label>
                <button class="button primary wide auth-submit" type="submit"><span>Войти</span><i class="bi bi-arrow-right"></i></button>
              </form>
              <div class="auth-login-note"><i class="bi bi-shield-check"></i><span>Сессия защищена. Данные авторизации не передаются в публичный API.</span></div>
            </section>
          </main>
        </body></html><?php
        exit;
    }
}

$config = [
    'base' => cms_base_path(),
    'adminApi' => url('api/admin.php'),
    'publicApi' => public_api_path(),
    'csrf' => csrf_token(),
    'user' => $user,
    'autosaveDelay' => (int)(cms_config('autosave_delay_ms') ?? 1100),
    'uploadMaxMb' => (int)(cms_config('upload_max_mb') ?? 100),
    'revisionLimit' => (int)(cms_config('revision_limit') ?? 20),
    'database' => Database::publicInfo(),
];
?><!doctype html>
<html lang="ru">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
  <meta name="theme-color" content="#f5f6f8">
  <meta name="color-scheme" content="light dark">
  <script>
  (()=>{try{
    const mode=localStorage.getItem('matercms-theme') || localStorage.getItem('mater-theme') || localStorage.getItem('feather-theme') || 'system';
    const dark=mode==='dark'||(mode==='system'&&window.matchMedia('(prefers-color-scheme: dark)').matches);
    document.documentElement.dataset.theme=dark?'dark':'light';
    document.documentElement.dataset.themeMode=mode;
    document.querySelector('meta[name=\"theme-color\"]')?.setAttribute('content',dark?'#0f1115':'#f5f6f8');
  }catch(e){}})();
  </script>
  <title>MaterCMS</title>
  <link rel="icon" type="image/png" sizes="64x64" href="<?=e(asset_url('assets/branding/matercms-icon-light-64.png'))?>">
  <link rel="apple-touch-icon" href="<?=e(asset_url('assets/branding/matercms-icon-light-192.png'))?>">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
  <link rel="stylesheet" href="<?=e(asset_url('assets/admin.css'))?>"><script src="<?=e(asset_url('assets/ui.js'))?>"></script>
</head>
<body>
<div id="featherApp" v-cloak>
  <div class="app-shell">
    <header class="topbar">
      <div class="topbar-left">
        <button class="icon-button" type="button" @click="drawer='nav'" aria-label="Открыть меню">
          <i class="bi bi-list"></i>
        </button>
        <button class="brand" type="button" @click="goRoot">
          <span class="brand-mark" aria-hidden="true"></span>
          <span class="brand-copy"><strong>MaterCMS</strong><small>Контент без лишнего</small></span>
        </button>
      </div>

      <div class="global-search-shell" @click.stop>
        <label class="global-search" :class="{focused: searchFocused}">
          <i class="bi bi-search search-bi"></i>
          <input v-model="globalSearchQuery" @focus="openGlobalSearch" @keydown.esc="closeGlobalSearch" type="search" placeholder="Поиск по MaterCMS">
          <button v-if="globalSearchQuery" type="button" class="search-clear" @click="clearGlobalSearch" aria-label="Очистить поиск"><i class="bi bi-x-lg"></i></button>
          <span class="global-search-shortcut">⌘ K</span>
        </label>
        <div v-if="globalSearchOpen" class="global-search-results">
          <div v-if="globalSearchLoading" class="global-search-loading"><span class="spinner"></span><span>Ищем по всему проекту…</span></div>
          <template v-else-if="globalSearchGroups.length">
            <section v-for="group in globalSearchGroups" :key="group.name" class="global-search-group">
              <small>{{ group.name }}</small>
              <button v-for="item in group.items" :key="item.type + ':' + (item.id || item.key)" type="button" class="global-search-result" @mousedown.prevent="openGlobalSearchResult(item)">
                <span class="global-search-result-icon"><i class="bi" :class="item.icon"></i></span>
                <span><strong>{{ item.title }}</strong><small>{{ item.subtitle }}</small></span>
                <i class="bi bi-arrow-return-left global-search-open-icon"></i>
              </button>
            </section>
          </template>
          <div v-else-if="globalSearchQuery.trim().length >= 2" class="global-search-empty"><i class="bi bi-search"></i><strong>Ничего не найдено</strong><span>Попробуйте другой запрос.</span></div>
          <div v-else class="global-search-hint"><i class="bi bi-stars"></i><div><strong>Один поиск для всего MaterCMS</strong><span>Папки, разделы, данные, формы, файлы, проекты и пользователи.</span></div></div>
        </div>
      </div>

      <div class="topbar-right">
        <button class="avatar-button" type="button" @click.stop="toggleUserMenu">
          <span class="avatar">{{ initials }}</span><span class="user-name">{{ state.user?.name || 'Администратор' }}</span><i class="bi bi-chevron-down chevron"></i>
        </button>
        <div v-if="userMenu" class="popover user-popover" @click.stop>
          <strong>{{ state.user?.name }}</strong><small>{{ state.user?.email }}</small>
          <button type="button" @click="logout">Выйти</button>
        </div>
      </div>
    </header>
    <main class="workspace">
      <section v-if="loading" class="smart-route-skeleton skeleton-explorer initial-skeleton" aria-label="Загрузка MaterCMS">
        <div class="skeleton-breadcrumbs"><i></i><i></i><i></i></div>
        <div class="skeleton-title-block"><i class="skeleton-title"></i><i class="skeleton-copy"></i></div>
        <div class="skeleton-toolbar"><i v-for="n in 7" :key="'initial-tool-'+n"></i></div>
        <div class="skeleton-card-grid"><article v-for="n in 8" :key="'initial-card-'+n"><i class="skeleton-icon"></i><span><b></b><small></small></span></article></div>
      </section>

      <section v-else-if="pageNavigating" class="smart-route-skeleton" :class="'skeleton-'+routeSkeletonKind" aria-label="Загрузка страницы">
        <div class="skeleton-breadcrumbs"><i></i><i></i><i></i></div>
        <template v-if="routeSkeletonKind==='editor'">
          <div class="skeleton-editor-head"><div><i class="skeleton-title"></i><i class="skeleton-copy"></i></div><i class="skeleton-pill"></i></div>
          <div class="skeleton-editor-layout"><section class="skeleton-editor-main"><i class="skeleton-input wide"></i><i class="skeleton-input"></i><i class="skeleton-input tall"></i><i class="skeleton-input"></i></section><aside class="skeleton-editor-side"><article v-for="n in 3" :key="'editor-side-'+n"><i></i><b></b><small></small></article></aside></div>
        </template>
        <template v-else-if="routeSkeletonKind==='settings'">
          <div class="skeleton-hero-row"><i class="skeleton-hero-icon"></i><span><b></b><small></small></span></div>
          <div class="skeleton-settings-stack"><article v-for="n in 4" :key="'setting-'+n"><i class="skeleton-setting-icon"></i><span><b></b><small></small></span><em></em></article></div>
        </template>
        <template v-else-if="routeSkeletonKind==='management'">
          <div class="skeleton-hero-row management"><i class="skeleton-hero-icon"></i><span><b></b><small></small></span><em></em></div>
          <div class="skeleton-summary-grid"><article v-for="n in 3" :key="'summary-'+n"><i></i><span><b></b><small></small></span></article></div>
          <div class="skeleton-card-grid management-grid"><article v-for="n in 6" :key="'management-'+n"><i class="skeleton-icon"></i><span><b></b><small></small></span></article></div>
        </template>
        <template v-else>
          <div class="skeleton-title-block"><i class="skeleton-title"></i><i class="skeleton-copy"></i></div>
          <div class="skeleton-toolbar"><i v-for="n in 7" :key="'tool-'+n"></i></div>
          <div class="skeleton-card-grid"><article v-for="n in 8" :key="'card-'+n"><i class="skeleton-icon"></i><span><b></b><small></small></span></article></div>
        </template>
      </section>

      <div v-else class="route-stage" :key="routeViewKey" @contextmenu="openPageContext">
        <section v-if="route.kind==='settings-projects'" class="explorer-view projects-view settings-collection-page">
          <div class="breadcrumbs"><button type="button" @click="goRoot">MaterCMS</button><span>›</span><button type="button" @click="openSettings">Настройки</button><span>›</span><b>Проекты</b></div>

          <div class="settings-page-hero projects-page-hero">
            <div class="settings-page-hero-icon"><i class="bi bi-layers"></i></div>
            <div class="settings-page-hero-copy"><h1>Проекты</h1><p>Рабочие пространства MaterCMS. Выберите проект и управляйте его участниками, ролями, правами и системными параметрами в одном месте.</p></div>
            <button v-if="isSystemOwner" class="button primary settings-page-hero-action" type="button" @click="openCreateProject"><i class="bi bi-plus-lg"></i> Новый проект</button>
          </div>

          <div class="settings-summary-grid project-summary-grid">
            <article><span><i class="bi bi-collection"></i></span><div><small>ВСЕГО</small><strong>{{ projects.length }}</strong><p>{{ plural(projects.length,'проект','проекта','проектов') }}</p></div></article>
            <article><span><i class="bi bi-check-circle"></i></span><div><small>СЕЙЧАС ВЫБРАН</small><strong class="summary-name">{{ currentProject?.name || '—' }}</strong><p>/{{ currentProject?.slug || '' }}</p></div></article>
            <article><span><i class="bi" :class="state.api_access?.mode==='private' ? 'bi-shield-lock' : 'bi-globe2'"></i></span><div><small>API ТЕКУЩЕГО</small><strong>{{ state.api_access?.mode==='private' ? 'Приватный' : 'Публичный' }}</strong><p>Настраивается отдельно для проекта</p></div></article>
          </div>

          <div v-if="projectLoading" class="files-loading"><span class="spinner"></span><p>Загружаем проекты…</p></div>
          <div v-else class="project-product-grid">
            <article v-for="project in projects" :key="project.id" class="project-product-card" :class="{active:Number(project.id)===Number(currentProject?.id)}" @contextmenu.prevent.stop="openObjectContext($event,'project',project)">
              <div class="project-product-card-head">
                <span class="project-product-icon"><i class="bi bi-layers"></i></span>
                <div class="project-product-badges">
                  <span class="project-api-pill" :class="project.api_access?.mode || 'public'"><i class="bi" :class="project.api_access?.mode==='private' ? 'bi-lock' : 'bi-globe2'"></i>{{ project.api_access?.mode==='private' ? 'Private' : 'Public' }}</span>
                  <span v-if="Number(project.id)===Number(currentProject?.id)" class="active-project-pill"><i class="bi bi-check2"></i> Текущий</span>
                </div>
              </div>
              <div class="project-product-copy"><h3>{{ project.name }}</h3><code>/{{ project.slug }}</code><p><i class="bi bi-person-badge"></i> {{ {owner:'Владелец',admin:'Администратор',editor:'Редактор',viewer:'Просмотр'}[project.role] || project.role }}</p></div>
              <button v-if="Number(project.id)!==Number(currentProject?.id)" class="project-open-button" type="button" @click="switchProject(project)"><span>Выбрать проект</span><i class="bi bi-arrow-right"></i></button>
              <div v-else class="project-current-button"><i class="bi bi-check-circle"></i><span>Выбран</span></div>
            </article>
          </div>

          <section v-if="currentProject" class="current-project-console">
            <div class="current-project-console-head">
              <div><small>ТЕКУЩИЙ ПРОЕКТ</small><h2>{{ currentProject.name }}</h2><p>Название, API-адрес, участники, роли и системные действия выбранного рабочего пространства.</p></div>
              <div class="section-actions"><button v-if="can('project.edit')" class="button soft" type="button" @click="renameProject(currentProject)"><i class="bi bi-pencil-square"></i> Переименовать</button><button v-if="isSystemOwner && projects.length>1" class="button danger-button" type="button" @click="deleteCurrentProject"><i class="bi bi-trash3"></i> Удалить проект</button></div>
            </div>
            <div class="current-project-api-line"><span><i class="bi bi-braces"></i> API проекта</span><code>{{ absoluteApiRoot }}</code><button type="button" @click="copy(absoluteApiRoot)"><i class="bi bi-copy"></i></button></div>

            <section v-if="can('members.view')" class="project-access-console">
              <div class="project-access-head">
                <div><small>ДОСТУП И РОЛИ</small><h3>Участники проекта</h3><p>Выберите проект и настройте, кто может работать внутри него. Роли и точные права теперь управляются только здесь.</p></div>
                <div class="project-access-toolbar">
                  <label class="project-access-select"><span>Проект</span><select :value="currentProject?.id" @change="selectProjectForAccess"><option v-for="project in projects" :key="project.id" :value="project.id">{{ project.name }}</option></select></label>
                  <button v-if="can('members.manage')" class="button soft" type="button" @click="openMemberEditor(null)"><i class="bi bi-person-plus"></i> Добавить участника</button>
                </div>
              </div>
              <div v-if="projectMembers.length" class="project-member-grid project-access-member-grid">
                <article v-for="member in projectMembers" :key="member.id" class="project-member-card project-access-member-card" @contextmenu.prevent.stop="openObjectContext($event,'member',member)">
                  <span class="member-avatar product-avatar">{{ (member.name || 'U').slice(0,1).toUpperCase() }}</span>
                  <div><strong>{{ member.name }}</strong><small>{{ member.email }}</small></div>
                  <span class="role-pill">{{ {owner:'Владелец',admin:'Администратор',editor:'Редактор',viewer:'Просмотр'}[member.role] }}</span>
                  <button v-if="can('members.manage')" class="icon-button subtle" type="button" @click="openMemberEditor(member)" title="Настроить роль и права"><i class="bi bi-sliders2"></i></button>
                </article>
              </div>
              <div v-else class="project-access-empty"><i class="bi bi-people"></i><div><strong>Дополнительных участников пока нет</strong><span>Добавьте пользователя и назначьте ему роль для этого проекта.</span></div></div>
            </section>
            <section v-else class="project-access-locked"><i class="bi bi-shield-lock"></i><div><strong>Управление участниками недоступно</strong><span>Ваша роль в этом проекте не позволяет просматривать его команду.</span></div></section>
          </section>
        </section>

        <section v-else-if="route.kind==='settings-team'" class="explorer-view users-view settings-collection-page settings-team-management-page">
          <div class="breadcrumbs"><button type="button" @click="goRoot">MaterCMS</button><span>›</span><button type="button" @click="openSettings">Настройки</button><span>›</span><b>Команда</b></div>

          <div class="settings-page-hero users-page-hero">
            <div class="settings-page-hero-icon users"><i class="bi bi-people"></i></div>
            <div class="settings-page-hero-copy"><h1>Команда</h1><p>Учётные записи MaterCMS. Здесь только создание, редактирование и удаление пользователей — доступ к проектам, роли и права находятся в разделе «Проекты».</p></div>
            <button v-if="isSystemOwner" class="button primary settings-page-hero-action" type="button" @click="openCreateUser"><i class="bi bi-person-plus"></i> Новый пользователь</button>
          </div>

          <template v-if="isSystemOwner">
            <div class="settings-summary-grid users-summary-grid team-directory-summary">
              <article><span><i class="bi bi-people"></i></span><div><small>ВСЕГО</small><strong>{{ users.length }}</strong><p>{{ plural(users.length,'пользователь','пользователя','пользователей') }}</p></div></article>
              <article><span><i class="bi bi-shield-check"></i></span><div><small>ВЛАДЕЛЬЦЫ CMS</small><strong>{{ users.filter(account=>account.system_role==='owner').length }}</strong><p>защищённые учётные записи</p></div></article>
              <article><span><i class="bi bi-person-gear"></i></span><div><small>ОБЫЧНЫЕ</small><strong>{{ users.filter(account=>account.system_role!=='owner').length }}</strong><p>можно редактировать и удалять</p></div></article>
            </div>

            <section class="team-accounts-surface team-directory-surface">
              <div class="team-accounts-head"><div><small>ПОЛЬЗОВАТЕЛИ MATER</small><h2>Учётные записи</h2><p>Проектные назначения здесь намеренно не редактируются. Откройте «Проекты», выберите проект и настройте участников и роли там.</p></div></div>
              <div v-if="usersLoading" class="files-loading"><span class="spinner"></span><p>Загружаем пользователей…</p></div>
              <div v-else class="user-product-grid team-directory-grid">
                <article v-for="account in users" :key="account.id" class="user-product-card team-directory-card" @contextmenu.prevent.stop="openObjectContext($event,'user',account)">
                  <div class="user-product-head">
                    <span class="member-avatar user-product-avatar">{{ (account.name || 'U').slice(0,1).toUpperCase() }}</span>
                    <div class="user-product-identity"><strong>{{ account.name }}</strong><small>{{ account.email }}</small></div>
                    <span v-if="account.system_role==='owner'" class="role-pill owner"><i class="bi bi-shield-check"></i> Владелец CMS</span>
                  </div>
                  <div class="team-directory-meta"><span><i class="bi bi-person-vcard"></i>{{ account.system_role==='owner' ? 'Системный владелец' : 'Пользователь MaterCMS' }}</span><span v-if="account.created_at"><i class="bi bi-calendar3"></i>{{ formatDate(account.created_at) }}</span></div>
                  <div class="user-product-actions team-directory-actions">
                    <button class="button soft" type="button" @click="editUser(account)"><i class="bi bi-pencil-square"></i> Редактировать</button>
                    <button v-if="account.system_role!=='owner' && Number(account.id)!==Number(state.user?.id)" class="button user-delete-button" type="button" @click="deleteUser(account)"><i class="bi bi-trash3"></i> Удалить</button>
                    <span v-else class="protected-user-note"><i class="bi bi-shield-check"></i> Защищённая учётная запись</span>
                  </div>
                </article>
              </div>
            </section>
          </template>
          <div v-else class="empty-state"><span><i class="bi bi-shield-lock"></i></span><h3>Раздел доступен владельцу CMS</h3><p>Управление учётными записями выполняет системный владелец. Участниками проекта можно управлять в разделе «Проекты».</p></div>
        </section>
        <section v-else-if="route.kind==='files'" class="explorer-view files-library-view">
          <div class="breadcrumbs" aria-label="Навигация">
            <button type="button" @click="goRoot">Мой контент</button><span>›</span><b>Файлы</b>
          </div>

          <div class="page-head">
            <div>
              <small class="eyebrow">ХРАНИЛИЩЕ</small>
              <h1>Файлы</h1>
              <p>Все файлы, загруженные через MaterCMS. Нажмите на файл, чтобы открыть раздел или форму, где он используется.</p>
            </div>
            <div class="page-actions">
              <button v-if="can('files.manage') && mediaFiles.some(file => Number(file.reference_count||0)===0)" class="button ghost cleanup-files-button" type="button" @click="cleanupFiles"><i class="bi bi-trash3"></i> Очистить неиспользуемые</button>
              <div class="view-switch" role="group" aria-label="Вид">
                <button :class="{active:viewMode==='grid'}" @click="setView('grid')" type="button" title="Сетка"><i class="bi bi-grid"></i></button>
                <button :class="{active:viewMode==='list'}" @click="setView('list')" type="button" title="Список"><i class="bi bi-list-ul"></i></button>
              </div>
            </div>
          </div>

          <div class="file-type-toolbar" role="tablist" aria-label="Тип файлов">
            <button v-for="item in fileTypeFilters" :key="item.key" type="button" class="file-type-chip" :class="{active:fileTypeFilter===item.key}" @click="setFileTypeFilter(item.key)">
              <i class="bi" :class="item.icon"></i><span>{{ item.label }}</span><b>{{ fileTypeCount(item.key) }}</b>
            </button>
          </div>

          <div v-if="filesLoading" class="files-loading"><span class="spinner"></span><p>Загружаем файлы…</p></div>
          <div v-else-if="filteredMediaFiles.length" class="files media-files" :class="viewMode">
            <article v-for="file in filteredMediaFiles" :key="file.name" class="file-card media-file-card" :class="{unused:Number(file.reference_count||0)===0,'history-only':file.history_only}" @contextmenu.prevent.stop="openObjectContext($event,'media',file)">
              <button class="card-open" type="button" @click.stop="openFileMenu($event,file)" :aria-label="'Действия с файлом ' + file.name"></button>
              <div class="media-thumb" :class="'kind-'+file.kind">
                <img v-if="file.kind==='image'" :src="file.url" :alt="file.name" loading="lazy">
                <video v-else-if="file.kind==='video'" :src="file.url" muted playsinline preload="metadata"></video>
                <span v-else-if="file.kind==='audio'" class="audio-file-icon"><i class="bi bi-music-note-beamed"></i></span>
                <span v-else class="generic-file-icon">{{ fileKindLabel(file) }}</span>
              </div>
              <div class="file-copy">
                <strong :title="file.name">{{ file.name }}</strong>
                <small>{{ fileKindLabel(file) }} · {{ formatFileSize(file.size) }} · {{ formatDate(file.modified_at) }}</small>
              </div>
              <span class="file-usage" :class="{unused:Number(file.reference_count||0)===0,history:file.history_only}">
                {{ file.usage_count ? (file.usage_count===1 ? (file.document_name || file.data_set_name || file.form_name || 'Используется') : 'Используется в ' + file.usage_count + ' местах') : (file.history_only ? 'Только в версиях' : 'Не используется') }}
              </span>
            </article>
          </div>

          <div v-else class="empty-state">
            <div class="empty-illustration"><span></span><i></i></div>
            <h2>{{ search || fileTypeFilter!=='all' ? 'Ничего не найдено' : 'Файлов пока нет' }}</h2>
            <p>{{ search || fileTypeFilter!=='all' ? 'Попробуйте изменить поиск или выбрать другой тип файлов.' : 'Изображения, видео, аудио и документы появятся здесь автоматически.' }}</p>
          </div>
        </section>

        <section v-else-if="route.kind==='data'" class="explorer-view data-view">
          <div class="breadcrumbs"><button type="button" @click="goRoot">MaterCMS</button><span>›</span><b>Данные</b></div>
          <div class="page-head">
            <div><small class="eyebrow">ГЛОБАЛЬНО ДЛЯ ПРОЕКТА</small><h1>Данные</h1><p>Храните товары, услуги, сотрудников, контакты и другую информацию в одном месте и используйте её на разных страницах сайта.</p></div>
            <div class="page-actions"><button v-if="can('data.edit')" class="button primary" type="button" @click="openCreateData"><i class="bi bi-plus-lg"></i> Создать данные</button></div>
          </div>
          <div v-if="dataLoading" class="files-loading"><span class="spinner"></span><p>Загружаем данные…</p></div>
          <div v-else-if="filteredDataSets.length" class="data-grid">
            <article v-for="item in filteredDataSets" :key="item.id" class="data-card" @contextmenu.prevent.stop="openObjectContext($event,'data',item)">
              <button class="card-open" type="button" @click="openDataSet(item.id)" :aria-label="'Открыть ' + item.name"></button>
              <div class="data-card-icon" :class="item.mode"><i class="bi" :class="item.mode==='multiple' ? 'bi-list-ul' : 'bi-braces'"></i></div>
              <div class="data-card-copy"><strong>{{ item.name }}</strong><small><span class="data-mode-pill">{{ item.mode==='multiple' ? 'Multiple' : 'Single' }}</span><template v-if="item.mode==='multiple'"> · {{ item.item_count }} {{ plural(item.item_count,'запись','записи','записей') }}</template></small><code>/data/{{ item.slug }}</code></div>
              <span class="data-api-badge" :class="{off:!item.api_enabled}"><i class="bi" :class="item.api_enabled ? 'bi-broadcast-pin' : 'bi-slash-circle'"></i>{{ item.api_enabled ? 'API' : 'API выкл.' }}</span>
              <i class="bi bi-chevron-right form-card-arrow"></i>
            </article>
          </div>
          <div v-else class="empty-state"><div class="empty-illustration"><span></span><i></i></div><h2>{{ search ? 'Ничего не найдено' : 'Данных пока нет' }}</h2><p>{{ search ? 'Попробуйте изменить запрос.' : 'Создайте глобальный набор — например Контакты, Товары или FAQ.' }}</p><button v-if="!search && can('data.edit')" class="button primary" type="button" @click="openCreateData"><i class="bi bi-plus-lg"></i> Создать данные</button></div>
        </section>

        <section v-else-if="route.kind==='data-set' && dataSet" class="editor-view data-editor-view">
          <div class="breadcrumbs"><button type="button" @click="openData">Данные</button><span>›</span><b>{{ dataSet.name }}</b></div>
          <div class="editor-head">
            <button class="back-button" type="button" @click="openData"><i class="bi bi-arrow-left"></i></button>
            <div class="data-editor-badge"><i class="bi bi-database"></i></div>
            <div class="editor-title"><small>{{ dataSet.mode==='multiple' ? 'MULTIPLE' : 'SINGLE' }}</small><h1>{{ dataSet.name }}</h1><p>{{ dataSet.mode==='multiple' ? 'Глобальный список объектов, доступный всему проекту.' : 'Один глобальный объект, доступный всему проекту.' }}</p></div>
            <div class="editor-actions"><button class="button ghost" type="button" @click="drawer='data-api'"><i class="bi bi-braces"></i> API</button><button v-if="can('data.edit')" class="button soft" type="button" @click="drawer='data-fields'"><i class="bi bi-sliders2"></i> Поля</button><button v-if="dataSet.mode==='multiple' && can('data.edit')" class="button primary" type="button" @click="createMultipleRecord('data')"><i class="bi bi-plus-lg"></i> Создать</button><button v-if="can('data.edit')" class="button soft danger-soft" type="button" @click="deleteCurrentDataSet"><i class="bi bi-trash3"></i></button><div class="autosave-pill" :class="dataSaveState"><i></i><span>{{ dataAutosaveText }}</span></div></div>
          </div>

          <div v-if="dataSet.mode==='multiple'" class="multiple-records-view">
            <div class="records-browser-toolbar">
              <div class="records-browser-count"><span>{{ dataSet.data?.length || 0 }}</span><div><strong>Записи</strong><small>Нажмите на запись, чтобы выбрать действие</small></div></div>
              <div class="view-switch record-view-switch" aria-label="Вид записей">
                <button type="button" :class="{active:dataRecordViewMode==='grid'}" @click="setRecordView('data','grid')" title="Сетка"><i class="bi bi-grid"></i></button>
                <button type="button" :class="{active:dataRecordViewMode==='list'}" @click="setRecordView('data','list')" title="Список"><i class="bi bi-list-ul"></i></button>
              </div>
            </div>
            <div v-if="dataSet.schema.length===0" class="record-browser-empty"><div class="no-fields-icon"><i class="bi bi-sliders2"></i></div><h2>Сначала добавьте поля</h2><p>Создайте структуру записи, а затем добавляйте объекты.</p><button v-if="can('data.edit')" class="button primary" type="button" @click="drawer='data-fields'">Добавить поля</button></div>
            <div v-else-if="!dataSet.data?.length" class="record-browser-empty"><div class="no-fields-icon"><i class="bi bi-plus-lg"></i></div><h2>Записей пока нет</h2><p>Создайте первую запись — она появится здесь как карточка.</p><button v-if="can('data.edit')" class="button primary" type="button" @click="createMultipleRecord('data')"><i class="bi bi-plus-lg"></i> Создать запись</button></div>
            <div v-else class="record-browser" :class="dataRecordViewMode">
              <article v-for="(item,index) in dataSet.data" :key="item._uid" class="record-browser-card" @click="openRecordMenu('data',item,index)" @contextmenu.prevent.stop="openRecordContext($event,'data',item,index)">
                <div class="record-browser-cover" :class="{empty:!recordCover('data',item)}"><img v-if="recordCover('data',item)" :src="recordCover('data',item)" alt=""><i v-else class="bi bi-database"></i></div>
                <div class="record-browser-copy"><strong>{{ dataItemTitle(item,index) }}</strong><small>{{ recordCardSubtitle('data',item,index) }}</small></div>
                <span class="record-browser-index">{{ index+1 }}</span><i class="bi bi-three-dots record-browser-more"></i>
              </article>
            </div>
          </div>

          <div v-else class="editor-grid">
            <div class="content-editor-shell">
              <aside v-if="dataSet.mode==='multiple'" class="records-panel">
                <div class="records-head"><div><small>ЗАПИСИ</small><b>{{ dataSet.data?.length || 0 }}</b></div><button v-if="can('data.edit')" class="record-add" type="button" @click="addDataItem" title="Добавить запись"><i class="bi bi-plus-lg"></i></button></div>
                <div v-if="dataSet.data?.length" class="records-list"><div v-for="(item,index) in dataSet.data" :key="item._uid" class="record-row" :class="{active:dataActiveItemUid===item._uid}"><button class="record-open" type="button" @click="dataActiveItemUid=item._uid"><span>{{ index+1 }}</span><div><strong>{{ dataItemTitle(item,index) }}</strong><small>Запись {{ index+1 }}</small></div></button><button v-if="can('data.edit')" class="record-delete" type="button" @click="deleteDataItem(item._uid)" title="Удалить запись"><i class="bi bi-trash3"></i></button></div></div>
                <div v-else class="records-empty"><p>Пока нет записей</p><button v-if="can('data.edit')" type="button" @click="addDataItem">＋ Добавить первую</button></div>
              </aside>
              <div class="editor-card" :class="{'readonly-panel':!can('data.edit')}">
                <div v-if="dataSet.schema.length===0" class="no-fields"><div class="no-fields-icon"><i class="bi bi-plus-lg"></i></div><h2>Добавьте первое поле</h2><p>Например название, цена, описание или изображение.</p><button v-if="can('data.edit')" class="button primary" type="button" @click="drawer='data-fields'">Добавить поля</button></div>
                <div v-else-if="dataSet.mode==='multiple' && !dataActiveData" class="no-fields"><div class="no-fields-icon"><i class="bi bi-plus-lg"></i></div><h2>Добавьте запись</h2><p>Структура уже готова. Создайте первый объект.</p><button v-if="can('data.edit')" class="button primary" type="button" @click="addDataItem"><i class="bi bi-plus-lg"></i> Новая запись</button></div>
                <template v-else>
                  <div v-for="field in dataSet.schema" :key="field.key" class="field-row">
                    <div class="field-label"><label>{{ field.label }}</label><small>{{ dataTypeNames[field.type] || field.type }}<template v-if="field.multiple || (field.type==='relation' && field.relation_multiple)"> · несколько</template></small></div>
                    <textarea v-if="field.type==='textarea'" v-model="dataActiveData[field.key]" rows="5" @input="markDataDirty"></textarea>
                    <label v-else-if="field.type==='boolean'" class="toggle-field"><input type="checkbox" v-model="dataActiveData[field.key]" @change="markDataDirty"><span></span><b>{{ dataActiveData[field.key] ? 'Включено' : 'Выключено' }}</b></label>
                    <input v-else-if="field.type==='number'" type="number" v-model.number="dataActiveData[field.key]" @input="markDataDirty">
                    <input v-else-if="field.type==='date'" type="date" v-model="dataActiveData[field.key]" @input="markDataDirty">
                    <input v-else-if="field.type==='datetime'" type="datetime-local" v-model="dataActiveData[field.key]" @input="markDataDirty">
                    <select v-else-if="field.type==='select'" v-model="dataActiveData[field.key]" @change="markDataDirty"><option value="">— Не выбрано —</option><option v-for="option in field.options" :key="option" :value="option">{{ option }}</option></select>
                    <div v-else-if="field.type==='relation'" class="relation-editor-field">
                      <button class="relation-open-button" type="button" :disabled="!field.source_data_set_id" @click="openRelationPicker(field,dataSet.mode==='multiple' ? dataActiveItemUid : null)">
                        <span class="relation-open-icon"><i class="bi bi-link-45deg"></i></span>
                        <span class="relation-open-copy">
                          <strong v-if="field.relation_multiple">{{ relationSelectedIds(field,dataSet.mode==='multiple' ? dataActiveItemUid : null).length ? 'Выбрано: '+relationSelectedIds(field,dataSet.mode==='multiple' ? dataActiveItemUid : null).length : 'Выбрать записи' }}</strong>
                          <strong v-else>{{ relationSelectedIds(field,dataSet.mode==='multiple' ? dataActiveItemUid : null).length ? relationLabel(field,relationSelectedIds(field,dataSet.mode==='multiple' ? dataActiveItemUid : null)[0]) : 'Выбрать запись' }}</strong>
                          <small>{{ relationSource(field)?.name || 'Источник не выбран' }}</small>
                        </span><i class="bi bi-chevron-down"></i>
                      </button>
                      <div v-if="field.relation_multiple && relationSelectedIds(field,dataSet.mode==='multiple' ? dataActiveItemUid : null).length" class="relation-selected-list">
                        <span v-for="id in relationSelectedIds(field,dataSet.mode==='multiple' ? dataActiveItemUid : null)" :key="id" class="relation-chip">{{ relationLabel(field,id) }}<button v-if="can('data.edit')" type="button" @click="removeRelationRecord(field,id,dataSet.mode==='multiple' ? dataActiveItemUid : null)" aria-label="Убрать"><i class="bi bi-x"></i></button></span>
                      </div>
                    </div>
                    <div v-else-if="isUploadType(field.type)" class="asset-field" :class="['asset-'+field.type, {'is-multiple':field.multiple}]">
                      <div v-if="dataAssetValues(field,dataSet.mode==='multiple' ? dataActiveItemUid : null).length" class="asset-gallery" :class="{'single-asset':!field.multiple}"><div v-for="asset in dataAssetValues(field,dataSet.mode==='multiple' ? dataActiveItemUid : null)" :key="asset" class="asset-preview" :class="'preview-'+field.type"><img v-if="field.type==='image'" :src="asset" :alt="assetName(asset)"><video v-else-if="field.type==='video'" :src="asset" controls playsinline preload="metadata"></video><audio v-else-if="field.type==='audio'" :src="asset" controls preload="metadata"></audio><div v-else class="document-preview"><i class="bi bi-file-earmark-arrow-down"></i><strong>{{ assetName(asset) }}</strong><a :href="asset" target="_blank" rel="noopener" @click.stop><i class="bi bi-box-arrow-up-right"></i> Открыть</a></div><button class="asset-remove" type="button" @click="removeDataAsset(field,asset,dataSet.mode==='multiple' ? dataActiveItemUid : null)"><i class="bi bi-x-lg"></i></button></div></div>
                      <label class="upload-zone" :class="{compact:dataAssetValues(field,dataSet.mode==='multiple' ? dataActiveItemUid : null).length}"><input type="file" :accept="fileAccept(field.type)" :multiple="field.multiple===true" @change="chooseDataAsset(field,$event,dataSet.mode==='multiple' ? dataActiveItemUid : null)"><span class="upload-symbol"><i class="bi bi-cloud-arrow-up"></i></span><strong>{{ uploadActionLabel(field,dataAssetValues(field,dataSet.mode==='multiple' ? dataActiveItemUid : null).length>0) }}</strong><small>{{ uploadHint(field.type) }} · до {{ <?=json_encode((int)(cms_config('upload_max_mb') ?? 100))?> }} МБ</small></label>
                    </div>
                    <input v-else type="text" v-model="dataActiveData[field.key]" @input="markDataDirty">
                  </div>
                </template>
              </div>
            </div>
            <aside class="editor-info">
              <div class="info-card"><small>API</small><div class="data-api-switch-row"><div><strong>{{ dataSet.api_enabled ? 'Включён' : 'Выключен' }}</strong><p>Read-only endpoint этого набора.</p></div><label v-if="can('data.edit')" class="settings-switch"><input type="checkbox" :checked="dataSet.api_enabled" @change="setDataApiEnabled($event.target.checked)"><span></span></label></div><code class="endpoint-full-inline">{{ dataEndpoint }}</code><button type="button" class="copy-link" @click="copy(dataEndpoint)"><i class="bi bi-copy"></i> Копировать адрес</button></div>
              <div class="info-card"><small>ОТВЕТ API</small><div class="return-type"><b>{{ dataSet.mode==='multiple' ? '[ ]' : '{ }' }}</b><span>{{ dataSet.mode==='multiple' ? 'Массив объектов' : 'Один объект' }}</span></div><p v-if="dataSet.mode==='multiple'">Сейчас {{ dataSet.data?.length || 0 }} {{ plural(dataSet.data?.length || 0,'запись','записи','записей') }}.</p></div>
              <div class="info-card"><small>АВТОСОХРАНЕНИЕ</small><div class="status-line" :class="dataSaveState"><i></i><span>{{ dataAutosaveText }}</span></div><p>Изменения сохраняются автоматически после короткой паузы.</p></div>
            </aside>
          </div>
        </section>

        <section v-else-if="route.kind==='forms'" class="explorer-view forms-view">
          <div class="breadcrumbs"><button type="button" @click="goRoot">Мой контент</button><span>›</span><b>Формы</b></div>
          <div class="page-head">
            <div><small class="eyebrow">ЗАЯВКИ</small><h1>Формы</h1><p>Создавайте формы и принимайте обращения с любого сайта прямо в MaterCMS.</p></div>
            <div class="page-actions"><button v-if="can('forms.edit')" class="button primary" type="button" @click="openCreateForm"><i class="bi bi-plus-lg"></i> Новая форма</button></div>
          </div>
          <div v-if="formsLoading" class="files-loading"><span class="spinner"></span><p>Загружаем формы…</p></div>
          <div v-else-if="filteredForms.length" class="forms-grid">
            <article v-for="item in filteredForms" :key="item.id" class="form-card" @contextmenu.prevent.stop="openObjectContext($event,'form',item)">
              <button class="card-open" type="button" @click="openForm(item.id)" :aria-label="'Открыть форму ' + item.name"></button>
              <div class="form-card-icon"><i class="bi bi-ui-checks-grid"></i></div>
              <div class="form-card-copy"><strong>{{ item.name }}</strong><small>{{ item.submission_count }} {{ plural(item.submission_count,'заявка','заявки','заявок') }}</small></div>
              <span v-if="!item.api_enabled" class="form-api-off-badge"><i class="bi bi-slash-circle"></i> API выкл.</span>
              <span v-if="item.new_count" class="form-new-badge">{{ item.new_count }} новых</span>
              <i class="bi bi-chevron-right form-card-arrow"></i>
            </article>
          </div>
          <div v-else class="empty-state"><div class="empty-illustration"><span></span><i></i></div><h2>{{ search ? 'Ничего не найдено' : 'Форм пока нет' }}</h2><p>{{ search ? 'Попробуйте изменить запрос.' : 'Создайте первую форму и подключите её к сайту за пару минут.' }}</p><button v-if="!search && can('forms.edit')" class="button primary" type="button" @click="openCreateForm"><i class="bi bi-plus-lg"></i> Новая форма</button></div>
        </section>

        <section v-else-if="route.kind==='form' && form" class="form-editor-view">
          <div class="breadcrumbs"><button type="button" @click="openForms">Формы</button><span>›</span><b>{{ form.name }}</b></div>
          <div class="editor-head form-editor-head">
            <button class="back-button" type="button" @click="openForms"><i class="bi bi-arrow-left"></i></button>
            <div class="form-editor-badge"><i class="bi bi-ui-checks-grid"></i></div>
            <div class="editor-title"><small>ФОРМА</small><h1>{{ form.name }}</h1><p>{{ form.submission_count }} {{ plural(form.submission_count,'заявка','заявки','заявок') }}<template v-if="form.new_count"> · {{ form.new_count }} новых</template></p></div>
            <div class="editor-actions"><button class="button ghost" type="button" @click="drawer='form-api'"><i class="bi bi-plug"></i> Подключить</button><button v-if="can('forms.edit')" class="button soft danger-soft" type="button" @click="deleteCurrentForm"><i class="bi bi-trash3"></i></button><div class="autosave-pill" :class="formSaveState"><i></i><span>{{ formAutosaveText }}</span></div></div>
          </div>

          <div class="form-tabs form-tabs-with-view"><div class="form-tabs-main"><button type="button" :class="{active:formTab==='submissions'}" @click="formTab='submissions'"><i class="bi bi-inbox"></i> Заявки <span>{{ form.submission_count }}</span></button><button type="button" :class="{active:formTab==='fields'}" @click="formTab='fields'"><i class="bi bi-sliders2"></i> Поля <span>{{ form.schema.length }}</span></button></div><div v-if="formTab==='submissions'" class="view-switch compact-view-switch" role="group" aria-label="Вид заявок"><button :class="{active:submissionViewMode==='grid'}" @click="setSubmissionView('grid')" type="button" title="Сетка"><i class="bi bi-grid"></i></button><button :class="{active:submissionViewMode==='list'}" @click="setSubmissionView('list')" type="button" title="Список"><i class="bi bi-list-ul"></i></button></div></div>

          <div v-if="formTab==='submissions'" class="form-submissions-full">
            <div class="submissions-card full-width-submissions">
              <div v-if="filteredFormSubmissions.length" class="submission-list" :class="submissionViewMode">
                <button v-for="submission in filteredFormSubmissions" :key="submission.id" class="submission-row" :class="[{unread:submission.status==='new'}, submissionViewMode]" type="button" @click="openSubmission(submission)" @contextmenu.prevent.stop="openSubmissionContext($event,submission)">
                  <span class="submission-state"></span><div class="submission-copy"><strong>{{ submission.preview || 'Заявка #' + submission.id }}</strong><small>{{ formatDateTime(submission.created_at) }}</small></div><span v-if="submission.status==='new'" class="new-pill">Новая</span><i class="bi bi-chevron-right"></i>
                </button>
              </div>
              <div v-else class="form-empty"><i class="bi bi-inbox"></i><h2>{{ search ? 'Ничего не найдено' : 'Заявок пока нет' }}</h2><p>{{ search ? 'Попробуйте другой запрос.' : 'Подключите endpoint к сайту — новые обращения появятся здесь автоматически.' }}</p><button v-if="!search" class="button primary" type="button" @click="drawer='form-api'"><i class="bi bi-plug"></i> Подключить форму</button></div>
            </div>
          </div>

          <div v-else class="form-builder-layout" :class="{'readonly-panel':!can('forms.edit')}">
            <div class="form-settings-card">
              <div class="form-basic-settings"><label>Название формы<input v-model="form.name" @input="markFormDirty" type="text"></label><label>Сообщение после отправки<textarea v-model="form.success_message" @input="markFormDirty" rows="3"></textarea></label></div>
              <div class="form-fields-head"><div><small>ПОЛЯ ФОРМЫ</small><h2>Что заполняет клиент</h2></div><button class="button soft" type="button" @click="addFormField"><i class="bi bi-plus-lg"></i> Поле</button></div>
              <div class="form-fields-list">
                <div v-for="(field,index) in form.schema" :key="field._uid" class="form-field-card">
                  <div class="form-field-top"><span class="form-field-number">{{ index+1 }}</span><input class="form-field-label-input" v-model="field.label" @input="formFieldLabelChanged(field,index)" placeholder="Название поля"><button type="button" class="remove-field" @click="removeFormField(index)"><i class="bi bi-trash3"></i></button></div>
                  <div class="form-field-controls"><label>Тип<select v-model="field.type" @change="formFieldTypeChanged(field)"><option v-for="(label,type) in formTypeNames" :key="type" :value="type">{{ label }}</option></select></label><label v-if="field.type!=='checkbox' && field.type!=='file'">Подсказка<input v-model="field.placeholder" @input="markFormDirty" placeholder="Необязательно"></label></div>
                  <label v-if="field.type==='select'" class="form-options-label">Варианты ответа<textarea v-model="field.optionsText" @input="formOptionsChanged(field)" rows="3" placeholder="Например:
Консультация
Разработка
Другое"></textarea></label>
                  <div class="form-field-flags"><label><input type="checkbox" v-model="field.required" @change="markFormDirty"><span>Обязательное поле</span></label><label v-if="field.type==='file'"><input type="checkbox" v-model="field.multiple" @change="markFormDirty"><span>Несколько файлов</span></label><code>{{ field.key }}</code></div>
                </div>
              </div>
              <button class="add-field-button" type="button" @click="addFormField"><i class="bi bi-plus-lg"></i> Добавить поле</button>
            </div>
          </div>
        </section>

        <section v-else-if="route.kind==='settings'" class="settings-view explorer-view settings-hub-page">
          <div class="breadcrumbs"><button type="button" @click="goRoot">MaterCMS</button><span>›</span><b>Настройки</b></div>

          <div class="settings-hub-head">
            <div>
              <h1>Настройки</h1>
              <p>Выберите раздел. Каждая группа настроек открывается на отдельной странице.</p>
            </div>
            <div class="settings-project-chip"><span class="settings-project-dot"></span><div><small>Текущий проект</small><strong>{{ currentProject?.name || 'Проект' }}</strong></div><i class="bi bi-layers"></i></div>
          </div>

          <div class="settings-hub-white-grid">
            <button class="settings-white-card" type="button" @click="openApiSettings">
              <span class="settings-white-icon api"><i class="bi bi-braces"></i></span>
              <span class="settings-white-copy"><small>API ПРОЕКТА</small><strong>Доступ к API</strong><p>{{ state.api_access.mode==='private' ? 'Приватный · требуется токен' : 'Публичный · без авторизации' }}</p></span>
              <i class="bi bi-arrow-right settings-white-arrow"></i>
            </button>

            <button class="settings-white-card" type="button" @click="openLanguageSettings">
              <span class="settings-white-icon language"><i class="bi bi-translate"></i></span>
              <span class="settings-white-copy"><small>ЛОКАЛИЗАЦИЯ</small><strong>Языки проекта</strong><p>{{ state.i18n.enabled ? selectedLanguages.length + ' ' + plural(selectedLanguages.length,'язык','языка','языков') : 'Один основной язык' }}</p></span>
              <i class="bi bi-arrow-right settings-white-arrow"></i>
            </button>

            <button class="settings-white-card" type="button" @click="openProjects">
              <span class="settings-white-icon projects"><i class="bi bi-layers"></i></span>
              <span class="settings-white-copy"><small>УПРАВЛЕНИЕ</small><strong>Проекты</strong><p>Рабочие пространства, участники, роли и права доступа.</p></span>
              <i class="bi bi-arrow-right settings-white-arrow"></i>
            </button>

            <button v-if="isSystemOwner" class="settings-white-card" type="button" @click="openTeamSettings">
              <span class="settings-white-icon team"><i class="bi bi-people"></i></span>
              <span class="settings-white-copy"><small>УЧЁТНЫЕ ЗАПИСИ</small><strong>Команда</strong><p>Создание, редактирование и удаление пользователей MaterCMS.</p></span>
              <i class="bi bi-arrow-right settings-white-arrow"></i>
            </button>

            <button class="settings-white-card" type="button" @click="openDatabaseSettings">
              <span class="settings-white-icon database"><i class="bi bi-database"></i></span>
              <span class="settings-white-copy"><small>СИСТЕМА</small><strong>База данных</strong><p>{{ databaseInfo.label || 'База данных' }} · {{ databaseInfo.connected ? 'подключено' : 'ошибка соединения' }}</p></span>
              <i class="bi bi-arrow-right settings-white-arrow"></i>
            </button>

            <button class="settings-white-card" type="button" @click="openAbout">
              <span class="settings-white-icon about"><i class="bi bi-info-circle"></i></span>
              <span class="settings-white-copy"><small>MATERCMS</small><strong>О продукте</strong><p>Автор, версия, документация и история всех релизов.</p></span>
              <i class="bi bi-arrow-right settings-white-arrow"></i>
            </button>
          </div>

          <section class="settings-theme-card">
            <div class="settings-theme-copy">
              <span class="settings-theme-icon"><i class="bi bi-circle-half"></i></span>
              <div><small>ВНЕШНИЙ ВИД</small><strong>Тема MaterCMS</strong><p>Системная следует настройкам устройства. Выбор сохраняется в этом браузере.</p></div>
            </div>
            <div class="theme-choice" role="radiogroup" aria-label="Тема интерфейса">
              <button type="button" :class="{active:themeMode==='system'}" @click="setThemeMode('system')"><i class="bi bi-circle-half"></i><span>Системная</span></button>
              <button type="button" :class="{active:themeMode==='light'}" @click="setThemeMode('light')"><i class="bi bi-sun"></i><span>Светлая</span></button>
              <button type="button" :class="{active:themeMode==='dark'}" @click="setThemeMode('dark')"><i class="bi bi-moon-stars"></i><span>Тёмная</span></button>
            </div>
          </section>
        </section>

        <section v-else-if="route.kind==='settings-api'" class="explorer-view settings-detail-page">
          <div class="breadcrumbs"><button type="button" @click="goRoot">MaterCMS</button><span>›</span><button type="button" @click="openSettings">Настройки</button><span>›</span><b>Доступ к API</b></div>
          <div class="settings-detail-head">
            <div class="settings-detail-title">
              <span class="settings-detail-icon api"><i class="bi bi-braces"></i></span>
              <div><h1>Доступ к API</h1><p>Публичный режим для открытого контента или приватный Bearer Token для server-to-server интеграций.</p></div>
            </div>
            <button class="button ghost settings-back-button" type="button" @click="openSettings"><i class="bi bi-arrow-left"></i> К настройкам</button>
          </div>
          <section class="settings-clean-section">
            <div class="settings-clean-section-head"><div><small>API ПРОЕКТА</small><h2>Доступ к API</h2><p>Включайте API целиком, а затем настраивайте доступ и отдельные endpoint внутри проекта.</p></div><span class="api-access-status" :class="apiProjectEnabled ? 'public' : 'disabled'"><i class="bi" :class="apiProjectEnabled ? 'bi-broadcast-pin' : 'bi-slash-circle'"></i>{{ apiProjectEnabled ? 'API включён' : 'API выключен' }}</span></div>
            <section class="settings-surface api-master-status-card" :class="{disabled:!apiProjectEnabled}">
              <div><span class="settings-card-icon"><i class="bi" :class="apiProjectEnabled ? 'bi-power' : 'bi-power'"></i></span><div><small>ГЛАВНЫЙ ПЕРЕКЛЮЧАТЕЛЬ</small><strong>{{ apiProjectEnabled ? 'API проекта работает' : 'API проекта временно отключён' }}</strong><p>Когда выключено, все внешние endpoint проекта отвечают 404. Настройки отдельных папок, разделов, данных и форм сохраняются и снова начинают работать после включения.</p></div></div>
              <label class="settings-switch"><input type="checkbox" :checked="apiProjectEnabled" :disabled="!can('settings.edit') || busy" @change="setProjectApiEnabled($event.target.checked)"><span></span></label>
            </section>
            <section class="settings-surface api-access-settings-card" :class="{'readonly-panel':!can('settings.edit') || !apiProjectEnabled}">
              <div class="api-access-mode-grid clean-api-mode-grid">
                <button type="button" class="api-access-mode" :class="{active:state.api_access.mode==='public'}" :disabled="!can('settings.edit') || busy" @click="setApiAccessMode('public')"><span class="api-access-mode-icon public"><i class="bi bi-globe2"></i></span><span><b>Публичный API</b><small>Подходит для сайта, каталога, блога и другого открытого контента.</small></span><i class="bi" :class="state.api_access.mode==='public' ? 'bi-check-circle' : 'bi-circle'"></i></button>
                <button type="button" class="api-access-mode" :class="{active:state.api_access.mode==='private'}" :disabled="!can('settings.edit') || busy" @click="setApiAccessMode('private')"><span class="api-access-mode-icon private"><i class="bi bi-shield-lock"></i></span><span><b>Приватный API</b><small>Для server-to-server интеграций и закрытых данных.</small></span><i class="bi" :class="state.api_access.mode==='private' ? 'bi-check-circle' : 'bi-circle'"></i></button>
              </div>
              <div v-if="state.api_access.mode==='private'" class="private-api-details clean-private-api">
                <div class="private-token-summary"><div><small>СЕКРЕТНЫЙ ТОКЕН</small><code>{{ state.api_access.token_prefix || 'fth_live_' }}••••••••••••••••</code><p>Полный секрет показывается только один раз.<template v-if="state.api_access.created_at"> Создан {{ formatDateTime(state.api_access.created_at) }}.</template></p></div><span class="token-safe-icon"><i class="bi bi-key"></i></span></div>
                <div class="private-api-actions"><button v-if="can('settings.edit')" class="button soft" type="button" :disabled="busy" @click="regenerateApiToken"><i class="bi bi-arrow-repeat"></i> Перевыпустить токен</button><button class="button ghost" type="button" @click="drawer='api'"><i class="bi bi-code-slash"></i> Примеры подключения</button></div>
                <div class="secret-warning"><i class="bi bi-exclamation-triangle"></i><p><b>Не храните токен во frontend.</b> Используйте backend или environment variables.</p></div>
              </div>
              <div v-else class="public-api-details clean-public-api"><i class="bi bi-check2-circle"></i><div><b>Готово для frontend</b><p>Endpoint-ы текущего проекта можно использовать напрямую через <code>fetch()</code>.</p></div><button class="button ghost" type="button" @click="drawer='api'"><i class="bi bi-code-slash"></i> Открыть API</button></div>
            </section>
            <section class="settings-surface api-response-settings-card" :class="{'readonly-panel':!can('settings.edit')}">
              <div class="api-response-settings-copy"><span class="api-response-settings-icon"><i class="bi bi-braces-asterisk"></i></span><div><small>ФОРМАТ ОТВЕТА</small><strong>Полный ответ API</strong><p>Выключено — разделы и Данные возвращают чистый JSON, а Folder API возвращает всю структуру без содержимого разделов. Включено — MaterCMS добавляет <code>data + meta</code>, а Folder API также включает полное содержимое всех разделов. Для форм полный режим возвращает ID заявки и служебные идентификаторы.</p></div></div>
              <div class="api-response-settings-control"><span><b>{{ apiResponseModeLabel }}</b><small>{{ apiFullResponse ? 'data + meta и расширенный POST-ответ' : 'чистые данные без meta' }}</small></span><label class="settings-switch api-response-switch"><input type="checkbox" :checked="apiFullResponse" :disabled="!can('settings.edit') || apiResponseSaving" @change="setApiFullResponse($event.target.checked)"><span></span></label></div>
              <div class="api-response-settings-hint"><i class="bi bi-info-circle"></i><p>Настройка хранится на сервере отдельно для каждого проекта и применяется к ответам контента, данных и отправки форм. Корневой endpoint проекта остаётся картой API. Для совместимости конкретный запрос можно переопределить через <code>?meta=1</code> или <code>?meta=0</code>.</p></div>
            </section>
          </section>
        </section>

        <section v-else-if="route.kind==='settings-languages'" class="explorer-view settings-detail-page">
          <div class="breadcrumbs"><button type="button" @click="goRoot">MaterCMS</button><span>›</span><button type="button" @click="openSettings">Настройки</button><span>›</span><b>Языки проекта</b></div>
          <div class="settings-detail-head">
            <div class="settings-detail-title">
              <span class="settings-detail-icon language"><i class="bi bi-translate"></i></span>
              <div><h1>Языки проекта</h1><p>Настройте основной язык и мультиязычный контент текущего проекта.</p></div>
            </div>
            <button class="button ghost settings-back-button" type="button" @click="openSettings"><i class="bi bi-arrow-left"></i> К настройкам</button>
          </div>
          <section class="settings-clean-section">
            <div class="settings-clean-section-head"><div><small>ЛОКАЛИЗАЦИЯ</small><h2>Языки проекта</h2><p>Включайте мультиязычность только когда она действительно нужна.</p></div></div>
            <div v-if="settingsLoading" class="files-loading"><span class="spinner"></span><p>Загружаем настройки…</p></div>
            <div v-else class="settings-clean-stack" :class="{'readonly-panel':!can('settings.edit')}">
              <section class="settings-surface settings-toggle-surface">
                <div class="settings-row"><div class="settings-row-copy"><span class="settings-icon clean-language-icon"><i class="bi bi-translate"></i></span><div><strong>Мультиязычный контент</strong><p>При выключенном режиме API остаётся максимально простым.</p></div></div><label class="settings-switch"><input type="checkbox" :checked="state.i18n.enabled" @change="setI18nEnabled($event.target.checked)"><span></span></label></div>
              </section>
              <template v-if="state.i18n.enabled">
                <section class="settings-surface">
                  <div class="settings-section-head"><div><small>ОСНОВНОЙ ЯЗЫК</small><h3>Язык по умолчанию</h3><p>Используется в API, когда язык не указан явно.</p></div></div>
                  <div class="selected-language-grid clean-selected-language-grid"><button v-for="lang in selectedLanguages" :key="lang.code" type="button" class="selected-language-card" :class="{active:state.i18n.default_language===lang.code}" @click="setDefaultLanguage(lang.code)"><span class="language-code">{{ lang.code.toUpperCase() }}</span><span><strong>{{ lang.name }}</strong><small>{{ lang.native }}</small></span><i class="bi" :class="state.i18n.default_language===lang.code ? 'bi-check-circle' : 'bi-circle'"></i></button></div>
                </section>
                <section class="settings-surface language-library-card clean-language-library">
                  <div class="settings-section-head language-head"><div><small>ДОБАВИТЬ ЯЗЫК</small><h3>Доступные языки</h3><p>{{ Object.keys(languageCatalog).length }} языков ISO 639-1.</p></div><label class="language-search"><i class="bi bi-search"></i><input v-model="languageSearch" type="search" placeholder="Найти язык…"></label></div>
                  <div class="language-grid"><button v-for="lang in filteredLanguages" :key="lang.code" type="button" class="language-option" :class="{selected:languageSelected(lang.code)}" @click="toggleLanguage(lang.code)"><span class="language-code">{{ lang.code.toUpperCase() }}</span><span class="language-name"><strong>{{ lang.name }}</strong><small>{{ lang.native }}</small></span><i class="bi" :class="languageSelected(lang.code) ? 'bi-check-circle' : 'bi-plus-circle'"></i></button></div>
                </section>
              </template>
              <section v-else class="settings-surface i18n-disabled-card clean-i18n-disabled"><span><i class="bi bi-globe2"></i></span><div><strong>Один язык — меньше сложности</strong><p>Мультиязычность можно включить позже без изменения структуры проекта.</p></div></section>
            </div>
          </section>
        </section>

        <section v-else-if="route.kind==='database'" class="explorer-view database-settings-page settings-detail-page settings-database-page">
          <div class="breadcrumbs"><button type="button" @click="goRoot">MaterCMS</button><span>›</span><button type="button" @click="openSettings">Настройки</button><span>›</span><b>База данных</b></div>

          <div class="settings-detail-head settings-database-head">
            <div class="settings-detail-title">
              <span class="settings-detail-icon database"><i class="bi bi-database-gear"></i></span>
              <div><h1>База данных</h1><p>Состояние подключения, параметры текущей СУБД и безопасный перенос MaterCMS между SQLite, MySQL и PostgreSQL.</p></div>
            </div>
            <button class="button ghost settings-back-button" type="button" @click="openSettings"><i class="bi bi-arrow-left"></i> К настройкам</button>
          </div>

          <div v-if="settingsLoading" class="files-loading"><span class="spinner"></span><p>Проверяем базу данных…</p></div>
          <template v-else>
            <section class="database-page-status" :class="{error:!databaseInfo.connected}">
              <div class="database-page-status-main">
                <span class="database-large-icon"><i class="bi bi-database-check"></i></span>
                <div><small>ТЕКУЩАЯ СУБД</small><h2>{{ databaseInfo.label || 'База данных' }}</h2><p>{{ databaseInfo.storage }}</p></div>
                <span class="database-connection-pill" :class="{ok:databaseInfo.connected}"><i class="bi bi-circle-fill"></i>{{ databaseInfo.connected ? 'Соединение активно' : 'Нет соединения' }}</span>
              </div>
              <div class="database-detail-grid database-page-detail-grid">
                <div><small>Драйвер PHP</small><strong>{{ databaseInfo.extension || 'PDO' }}</strong></div>
                <div><small>База</small><strong>{{ databaseInfo.database || '—' }}</strong></div>
                <div v-if="databaseInfo.host"><small>Сервер</small><strong>{{ databaseInfo.host }}<template v-if="databaseInfo.port">:{{ databaseInfo.port }}</template></strong></div>
                <div><small>Версия</small><strong>{{ databaseInfo.version || 'Определяется сервером' }}</strong></div>
              </div>
            </section>

            <div class="database-page-grid">
              <section class="database-page-card">
                <span class="database-page-card-icon secure"><i class="bi bi-shield-check"></i></span>
                <div><small>БЕЗОПАСНОСТЬ</small><h3>Секреты остаются на сервере</h3><p>Пароль и полный URL подключения не отправляются в Vue. Конфигурация хранится в закрытом <code>cms/data/database.php</code>.</p></div>
              </section>
              <section class="database-page-card">
                <span class="database-page-card-icon migrate"><i class="bi bi-arrow-left-right"></i></span>
                <div><small>ПЕРЕНОС</small><h3>Смена СУБД с проверкой</h3><p>MaterCMS сначала переносит данные и сверяет результат. Только после этого новая база становится активной.</p></div>
              </section>
            </div>

            <section v-if="isSystemOwner" class="database-page-action-card">
              <div><small>СМЕНА БАЗЫ</small><h2>SQLite, MySQL или PostgreSQL</h2><p>Для MySQL и PostgreSQL сначала предлагается URL подключения. Если удобнее — переключитесь на отдельные поля.</p></div>
              <button class="button primary" type="button" @click="openDatabaseSwitcher"><i class="bi bi-arrow-left-right"></i> Сменить базу данных</button>
            </section>
          </template>
        </section>

        <section v-else-if="route.kind==='about'" class="explorer-view product-about-page">
          <div class="breadcrumbs"><button type="button" @click="goRoot">MaterCMS</button><span>›</span><b>О продукте</b></div>

          <div v-if="productAbout" class="product-about-shell product-about-v2">
            <section class="product-about-hero product-about-hero-v2">
              <div class="product-about-hero-main">
                <div class="product-about-brand product-about-brand-v2">
                  <span class="product-about-logo-wrap">
                    <img class="logo-light" src="<?=e(asset_url('assets/branding/matercms-wordmark-light.webp'))?>" alt="MaterCMS">
                    <img class="logo-dark" src="<?=e(asset_url('assets/branding/matercms-wordmark-dark.webp'))?>" alt="MaterCMS">
                  </span>
                  <span class="product-open-source-badge"><i class="bi bi-github"></i> Public repository</span>
                </div>
                <div class="product-about-hero-copy product-about-hero-copy-v2">
                  <span class="eyebrow">HEADLESS CMS · PRODUCT HUB</span>
                  <h1>Контент как источник истины.</h1>
                  <p>{{ productAbout.product?.description }}</p>
                  <div class="product-stack-pills"><span v-for="item in productAbout.product?.stack || []" :key="item">{{ item }}</span></div>
                </div>
                <div class="product-about-hero-actions product-about-hero-actions-v2">
                  <a class="button primary" href="https://github.com/DreamerView/mater-cms" target="_blank" rel="noopener"><i class="bi bi-github"></i> GitHub</a>
                  <button class="button soft" type="button" @click="openProductDocument('readme')"><i class="bi bi-book"></i> README</button>
                  <button class="button soft" type="button" @click="openProductDocument('documentation')"><i class="bi bi-journal-code"></i> Документация</button>
                </div>
              </div>
              <aside class="product-build-panel">
                <div class="product-build-panel-head"><span><small>ТЕКУЩАЯ СБОРКА</small><strong>MaterCMS {{ productAbout.product?.version || '—' }}</strong></span><i class="bi bi-box-seam"></i></div>
                <div class="product-build-grid">
                  <div><small>Версия</small><strong>{{ productAbout.product?.version || '—' }}</strong></div>
                  <div><small>Релизов</small><strong>{{ productAbout.build?.release_count || 0 }}</strong></div>
                  <div><small>Runtime</small><strong>PHP {{ productAbout.build?.php || '—' }}</strong></div>
                  <div><small>Database</small><strong>{{ productAbout.build?.database || 'Database' }}</strong></div>
                </div>
                <div class="product-build-routing"><i class="bi bi-braces"></i><span><small>API routing</small><b>{{ productAbout.build?.api_route_mode || 'auto' }}</b></span></div>
              </aside>
            </section>

            <section class="product-principles-grid">
              <article v-for="item in productAbout.principles || []" :key="item.title">
                <span><i class="bi" :class="item.icon"></i></span>
                <div><strong>{{ item.title }}</strong><p>{{ item.description }}</p></div>
              </article>
            </section>

            <section class="product-author-section-v2">
              <div class="product-author-profile-v2 product-author-profile-photo-v3">
                <div class="product-author-profile-main-v3">
                  <figure class="product-author-photo-v3">
                    <img
                      src="<?=e(asset_url('assets/branding/temirkhan-rustemov-256.webp'))?>"
                      srcset="<?=e(asset_url('assets/branding/temirkhan-rustemov-256.webp'))?> 256w, <?=e(asset_url('assets/branding/temirkhan-rustemov-512.webp'))?> 512w"
                      sizes="(max-width: 720px) 92px, 148px"
                      width="256"
                      height="256"
                      loading="lazy"
                      decoding="async"
                      alt="Temirkhan Rustemov — автор MaterCMS">
                  </figure>
                  <div class="product-author-profile-copy-v3">
                    <div class="product-author-identity-v2 product-author-identity-photo-v3">
                      <div><small>АВТОР И РАЗРАБОТЧИК</small><h2>{{ productAbout.author?.name }}</h2><p>{{ productAbout.author?.role }}</p></div>
                    </div>
                    <p class="product-author-lead">{{ productAbout.author?.description }}</p>
                    <p class="product-author-bio">{{ productAbout.author?.bio }}</p>
                    <div class="product-author-links-v2">
                      <a v-if="productAbout.author?.github" :href="productAbout.author.github" target="_blank" rel="noopener"><i class="bi bi-github"></i><span><small>GitHub</small><b>{{ productAbout.author.github_handle }}</b></span><i class="bi bi-arrow-up-right"></i></a>
                    </div>
                  </div>
                </div>
              </div>
              <div class="product-author-responsibilities">
                <small>ЗОНЫ ОТВЕТСТВЕННОСТИ</small>
                <div class="product-author-responsibility-list"><span v-for="item in productAbout.author?.responsibilities || []" :key="item"><i class="bi bi-check2"></i>{{ item }}</span></div>
              </div>
              <div class="product-author-philosophy">
                <i class="bi bi-quote"></i>
                <p>MaterCMS не пытается быть конструктором дизайна. Его задача — дать проекту чистую структуру данных, удобное редактирование и API, которому frontend может доверять.</p>
              </div>
            </section>

            <section class="product-repositories-section">
              <div class="product-section-head product-section-head-v2"><div><small>GITHUB</small><h2>Репозитории</h2><p>Исходный код MaterCMS, история разработки и место для issues.</p></div><a class="product-section-link" href="https://github.com/DreamerView" target="_blank" rel="noopener">DreamerView на GitHub <i class="bi bi-arrow-up-right"></i></a></div>
              <div class="product-repository-grid">
                <article v-for="repo in productAbout.repositories || []" :key="repo.full_name" class="product-repository-card">
                  <div class="product-repository-top"><span class="product-repository-icon"><i class="bi bi-github"></i></span><div><small>{{ repo.provider }} · {{ repo.visibility }}</small><h3>{{ repo.full_name }}</h3></div><span class="product-repository-branch"><i class="bi bi-git"></i>{{ repo.branch }}</span></div>
                  <p>{{ repo.description }}</p>
                  <div class="product-repository-topics"><span v-for="topic in repo.topics || []" :key="topic">{{ topic }}</span></div>
                  <div class="product-repository-actions">
                    <a :href="repo.url" target="_blank" rel="noopener"><i class="bi bi-code-slash"></i> Код</a>
                    <a :href="repo.issues_url" target="_blank" rel="noopener"><i class="bi bi-record-circle"></i> Issues</a>
                    <a :href="repo.commits_url" target="_blank" rel="noopener"><i class="bi bi-clock-history"></i> Commits</a>
                  </div>
                </article>
              </div>
            </section>

            <div class="product-about-secondary-grid">
              <section class="product-commit-card product-commit-card-v2">
                <div class="product-section-kicker"><i class="bi bi-git"></i><span><small>ТЕКУЩАЯ СБОРКА</small><strong>Git commit message</strong></span></div>
                <code>{{ productAbout.build?.current_commit || '—' }}</code>
                <button class="copy-link" type="button" @click="copy(productAbout.build?.current_commit || '')"><i class="bi bi-copy"></i> Копировать commit</button>
              </section>

              <section class="product-documents-section product-documents-section-v2">
                <div class="product-section-head"><div><small>ИСТОЧНИК ИСТИНЫ</small><h2>Документация</h2><p>Файлы текущей сборки открываются прямо в MaterCMS.</p></div></div>
                <div class="product-document-grid product-document-grid-v2">
                  <button v-for="doc in productAbout.documents || []" :key="doc.key" class="product-document-card product-document-card-v2" :class="{unavailable:doc.available===false}" type="button" :disabled="doc.available===false" @click="doc.available!==false && openProductDocument(doc.key)">
                    <span class="product-document-icon"><i class="bi" :class="doc.icon"></i></span>
                    <span class="product-document-copy"><small>{{ doc.name }}</small><strong>{{ doc.title }}</strong><em v-if="doc.available!==false">{{ formatFileSize(doc.bytes) }}</em><em v-else>Недоступен</em></span>
                    <i class="bi bi-chevron-right product-document-arrow"></i>
                  </button>
                </div>
              </section>
            </div>

            <section class="product-releases-section product-releases-section-v2">
              <div class="product-release-head"><div><small>VERSION.md</small><h2>История MaterCMS</h2><p>Все версии автоматически подтягиваются из VERSION.md этой сборки.</p></div><label class="product-release-search"><i class="bi bi-search"></i><input v-model="aboutReleaseQuery" type="search" placeholder="Версия или изменение…"></label></div>
              <div v-if="filteredProductReleases.length" class="product-release-list">
                <details v-for="(release,index) in filteredProductReleases" :key="release.version" class="product-release-card" :open="index===0 && !aboutReleaseQuery">
                  <summary>
                    <span class="product-release-marker"></span>
                    <span class="product-release-version">v{{ release.version }}</span>
                    <span class="product-release-summary"><strong>{{ release.title }}</strong><small>{{ formatDate(release.date) }} · {{ release.change_count }} {{ plural(release.change_count,'изменение','изменения','изменений') }}</small></span>
                    <i class="bi bi-chevron-down"></i>
                  </summary>
                  <div class="product-release-body">
                    <section v-for="section in release.sections" :key="section.title"><h3>{{ section.title }}</h3><ul><li v-for="(item,itemIndex) in section.items" :key="itemIndex"><span v-html="renderMarkdownInline(item)"></span></li></ul></section>
                  </div>
                </details>
              </div>
              <div v-else class="product-release-empty"><i class="bi bi-search"></i><strong>Версия не найдена</strong><p>Попробуйте изменить поисковый запрос.</p></div>
            </section>
          </div>
        </section>

        <section v-else-if="route.kind==='folder'" class="explorer-view feather-content-browser" @click.self="clearContentSelection" @dragover="contentDragOver($event,route.folderId)" @drop="dropContent($event,route.folderId)">
          <div class="breadcrumbs content-breadcrumbs" aria-label="Навигация">
            <button type="button" @click="goRoot">Мой контент</button>
            <template v-for="item in breadcrumbs" :key="item.id">
              <span>›</span><button type="button" @click="openFolder(item.id)">{{ item.name }}</button>
            </template>
          </div>

          <div class="page-head content-page-head">
            <div>
              <h1>{{ currentFolder?.name || 'Мой контент' }}</h1>
              <p>{{ currentFolder ? 'Папка с разделами вашего сайта.' : 'Организуйте контент проекта в папках и разделах — привычные действия работают с клавиатуры и мыши.' }}</p>
            </div>
          </div>

          <div class="content-command-bar" role="toolbar" aria-label="Управление контентом">
            <div class="content-command-state" :class="{active:contentSelectionCount}">
              <span><i class="bi" :class="contentSelectionCount ? 'bi-check2' : 'bi-cursor'"></i></span>
              <div>
                <strong v-if="contentSelectionCount">{{ contentSelectionCount }} {{ plural(contentSelectionCount,'выбран','выбрано','выбрано') }}</strong>
                <strong v-else>Ничего не выбрано</strong>
                <small v-if="contentSelectionCount">Действия применятся к выбранным объектам</small>
                <small v-else-if="contentClipboardCount">В буфере: {{ contentClipboardCount }} {{ plural(contentClipboardCount,'объект','объекта','объектов') }}</small>
                <small v-else>Выберите папку или раздел</small>
              </div>
            </div>

            <div class="content-command-groups">
              <div class="content-command-group" aria-label="История действий">
                <button type="button" :disabled="!explorerHistory.length || clipboardBusy" @click="undoExplorer" title="Отменить · Ctrl+Z"><i class="bi bi-arrow-counterclockwise"></i><span>Undo</span></button>
                <button type="button" :disabled="!explorerRedo.length || clipboardBusy" @click="redoExplorerAction" title="Повторить · Ctrl+Y"><i class="bi bi-arrow-clockwise"></i><span>Redo</span></button>
              </div>

              <div class="content-command-group" aria-label="Действия с объектами">
                <button type="button" :disabled="!contentSelectionCount" @click="setContentClipboard(null,null,'copy')" title="Копировать · Ctrl+C"><i class="bi bi-copy"></i><span>Копировать</span></button>
                <button type="button" :disabled="!can('content.edit') || !contentClipboardCount || clipboardBusy" @click="pasteContent(route.folderId)" title="Вставить · Ctrl+V"><i class="bi bi-clipboard-check"></i><span>Вставить</span><b v-if="contentClipboardCount">{{ contentClipboardCount }}</b></button>
                <button type="button" :disabled="!can('content.edit') || !contentSelectionCount || clipboardBusy" @click="duplicateSelection" title="Создать копию · Ctrl+D"><i class="bi bi-files"></i><span>Копия</span></button>
                <button type="button" :disabled="!can('content.edit') || contentSelectionCount!==1 || selectedSingleContent?.type==='resource-link'" @click="renameSelectedContent" title="Переименовать · F2"><i class="bi bi-pencil"></i><span>Переименовать</span></button>
                <button type="button" :disabled="contentSelectionCount!==1" @click="openContentProperties()" title="Свойства · Alt+Enter"><i class="bi bi-info-circle"></i><span>Свойства</span></button>
                <button class="danger" type="button" :disabled="!can('content.edit') || !contentSelectionCount" @click="trashSelectionItems(false)" title="Удалить · Delete"><i class="bi bi-trash3"></i><span>Удалить</span></button>
              </div>

              <div class="content-command-group content-command-utilities" aria-label="Вид и создание">
                <div class="content-sort-menu-shell">
                  <button class="content-sort-trigger" type="button" @click.stop="contentSortMenu=!contentSortMenu" :aria-expanded="contentSortMenu ? 'true' : 'false'" title="Сортировка">
                    <i class="bi bi-arrow-down-up"></i><span>{{ contentSort.by==='name' ? 'По имени' : (contentSort.by==='type' ? 'По типу' : 'По дате') }}</span><i class="bi bi-chevron-down"></i>
                  </button>
                  <div v-if="contentSortMenu" class="content-sort-dropdown" @click.stop>
                    <small>СОРТИРОВАТЬ ПО</small>
                    <button type="button" :class="{active:contentSort.by==='name'}" @click="setContentSort('name',contentSort.direction)"><i class="bi bi-fonts"></i><span>Имени</span><i v-if="contentSort.by==='name'" class="bi bi-check2"></i></button>
                    <button type="button" :class="{active:contentSort.by==='type'}" @click="setContentSort('type',contentSort.direction)"><i class="bi bi-grid-1x2"></i><span>Типу</span><i v-if="contentSort.by==='type'" class="bi bi-check2"></i></button>
                    <button type="button" :class="{active:contentSort.by==='updated'}" @click="setContentSort('updated',contentSort.direction)"><i class="bi bi-clock"></i><span>Дате изменения</span><i v-if="contentSort.by==='updated'" class="bi bi-check2"></i></button>
                    <div class="content-sort-direction">
                      <button type="button" :class="{active:contentSort.direction==='asc'}" @click="setContentSort(contentSort.by,'asc')"><i class="bi bi-sort-up"></i> По возрастанию</button>
                      <button type="button" :class="{active:contentSort.direction==='desc'}" @click="setContentSort(contentSort.by,'desc')"><i class="bi bi-sort-down"></i> По убыванию</button>
                    </div>
                  </div>
                </div>

                <div class="view-switch" role="group" aria-label="Вид">
                  <button :class="{active:viewMode==='grid'}" @click="setView('grid')" type="button" title="Сетка"><i class="bi bi-grid"></i></button>
                  <button :class="{active:viewMode==='list'}" @click="setView('list')" type="button" title="Список"><i class="bi bi-list-ul"></i></button>
                </div>

                <button class="content-trash-action" type="button" @click="openTrash" title="Корзина"><i class="bi bi-trash3"></i><span v-if="state.trash_count">{{ state.trash_count }}</span></button>
                <button type="button" :disabled="!contentSelectionCount" @click="clearContentSelection" title="Снять выделение"><i class="bi bi-x-lg"></i><span>Снять</span></button>
                <button v-if="can('content.edit')" class="button primary create-main-button" type="button" @click.stop="openCreate($event)"><i class="bi bi-plus-lg"></i> Создать <i class="bi bi-chevron-down create-chevron"></i></button>
              </div>
            </div>
          </div>

          <div v-if="filteredFolders.length || filteredDocuments.length || filteredContentLinks.length" class="files content-files" :class="[viewMode,{'drag-over-root':contentDrag.active && contentDrag.overFolderId===route.folderId}]" @click.self="clearContentSelection" @dragover="contentDragOver($event,route.folderId)" @drop="dropContent($event,route.folderId)">
            <article v-for="folder in filteredFolders" :key="'f'+folder.id" :data-content-key="'folder:'+folder.id" class="file-card folder-card" :class="{selected:isContentSelected('folder',folder),'drag-target':contentDrag.active && contentDrag.overFolderId===folder.id}" draggable="true" @dragstart="startContentDrag($event,'folder',folder)" @dragend="endContentDrag" @dragover.stop="contentDragOver($event,folder.id)" @dragleave="contentDragLeave" @drop.stop="dropContent($event,folder.id)" @dblclick="openFolder(folder.id)" @contextmenu.prevent.stop="openObjectContext($event,'folder',folder)">
              <button class="card-open" type="button" @click="activateContentItem($event,'folder',folder)" :aria-label="'Выбрать ' + folder.name + '. Двойной клик — открыть'"></button>
              <span class="file-select-mark"><i class="bi bi-check2"></i></span>
              <div class="file-icon folder"><i class="bi bi-folder2"></i></div>
              <div class="file-copy"><strong>{{ folder.name }}</strong><small>{{ childCount(folder.id) }} {{ plural(childCount(folder.id),'элемент','элемента','элементов') }}</small></div>
              <span v-if="!folder.api_enabled" class="content-api-off-badge"><i class="bi bi-slash-circle"></i> API выкл.</span>
              <button class="more-button" type="button" @click.stop="openMenu($event,'folder',folder)" aria-label="Действия"><i class="bi bi-three-dots"></i></button>
            </article>

            <article v-for="doc in filteredDocuments" :key="'d'+doc.id" :data-content-key="'document:'+doc.id" class="file-card document-card" :class="{selected:isContentSelected('document',doc)}" draggable="true" @dragstart="startContentDrag($event,'document',doc)" @dragend="endContentDrag" @dblclick="openDocument(doc.id)" @contextmenu.prevent.stop="openObjectContext($event,'document',doc)">
              <button class="card-open" type="button" @click="activateContentItem($event,'document',doc)" :aria-label="'Выбрать ' + doc.name + '. Двойной клик — открыть'"></button>
              <span class="file-select-mark"><i class="bi bi-check2"></i></span>
              <div class="file-icon document"><i class="bi bi-file-earmark-text"></i></div>
              <div class="file-copy"><strong>{{ doc.name }}</strong><small>{{ doc.mode==='multiple' ? (doc.item_count + ' ' + plural(doc.item_count,'запись','записи','записей')) : 'Одиночный' }} · {{ formatDate(doc.updated_at) }}</small></div>
              <span v-if="!doc.api_enabled" class="content-api-off-badge"><i class="bi bi-slash-circle"></i> API выкл.</span>
              <button class="more-button" type="button" @click.stop="openMenu($event,'document',doc)" aria-label="Действия"><i class="bi bi-three-dots"></i></button>
            </article>

            <article v-for="link in filteredContentLinks" :key="'l'+link.id" :data-content-key="'resource-link:'+link.id" class="file-card resource-link-card" :class="['resource-'+link.resource_type,{selected:isContentSelected('resource-link',link)}]" draggable="true" @dragstart="startContentDrag($event,'resource-link',link)" @dragend="endContentDrag" @dblclick="openLinkedResource(link)" @contextmenu.prevent.stop="openObjectContext($event,'resource-link',link)">
              <button class="card-open" type="button" @click="activateContentItem($event,'resource-link',link)" :aria-label="'Выбрать ' + link.name + '. Двойной клик — открыть источник'"></button>
              <span class="file-select-mark"><i class="bi bi-check2"></i></span>
              <div class="file-icon resource-link-icon" :class="link.resource_type"><i class="bi" :class="link.resource_type==='form' ? 'bi-ui-checks-grid' : 'bi-database'"></i></div>
              <div class="file-copy"><strong>{{ link.name }}</strong><small><span class="resource-link-kind">{{ link.resource_type==='form' ? 'Форма' : 'Данные' }}</span><template v-if="link.resource_type==='data'"> · {{ link.mode==='multiple' ? ((link.item_count || 0) + ' ' + plural(link.item_count || 0,'запись','записи','записей')) : 'Один объект' }}</template><template v-else> · источник связан</template></small></div>
              <span class="resource-link-badge"><i class="bi bi-link-45deg"></i> Связано</span>
              <span v-if="!link.api_enabled" class="content-api-off-badge"><i class="bi bi-slash-circle"></i> API выкл.</span>
              <button class="more-button" type="button" @click.stop="openMenu($event,'resource-link',link)" aria-label="Действия"><i class="bi bi-three-dots"></i></button>
            </article>
          </div>

          <div v-else class="empty-state content-empty-state" @dragover="contentDragOver($event,route.folderId)" @drop="dropContent($event,route.folderId)">
            <div class="empty-illustration"><span></span><i></i></div>
            <h2>{{ search ? 'Ничего не найдено' : 'Здесь пока пусто' }}</h2>
            <p>{{ search ? 'Попробуйте изменить запрос.' : 'Создайте папку или первый раздел.' }}</p>
            <button v-if="!search && can('content.edit')" class="button primary create-main-button" type="button" @click.stop="openCreate($event)"><i class="bi bi-plus-lg"></i> Создать <i class="bi bi-chevron-down create-chevron"></i></button>
          </div>

          <div class="content-browser-meta">
            <span>{{ contentVisibleCount }} {{ plural(contentVisibleCount,'элемент','элемента','элементов') }}</span>
            <span v-if="contentClipboardCount" class="clipboard-state"><i class="bi bi-copy"></i>{{ contentClipboardCount }} {{ plural(contentClipboardCount,'объект','объекта','объектов') }} в буфере</span>
          </div>
        </section>
        <section v-else-if="document" class="editor-view">
          <div class="breadcrumbs">
            <button type="button" @click="goRoot">Мой контент</button>
            <template v-for="item in documentBreadcrumbs" :key="item.id">
              <span>›</span><button type="button" @click="openFolder(item.id)">{{ item.name }}</button>
            </template>
            <span>›</span><b>{{ document.name }}</b>
          </div>

          <div class="editor-head">
            <button class="back-button" type="button" @click="backFromDocument"><i class="bi bi-arrow-left"></i></button>
            <div class="document-badge"><span></span></div>
            <div class="editor-title"><small>{{ document.mode==='multiple' ? 'МНОГО ЗАПИСЕЙ' : 'ОДИНОЧНЫЙ' }}</small><h1>{{ document.name }}</h1><p>{{ document.mode==='multiple' ? 'Добавляйте сколько угодно записей — API вернёт массив.' : 'Один набор полей — API вернёт объект.' }}</p></div>
            <div class="editor-actions">
              <button class="button ghost" type="button" @click="drawer='api'"><i class="bi bi-braces"></i> API</button>
              <button v-if="can('content.edit')" class="button soft" type="button" :disabled="!isDefaultEditorLanguage" @click="isDefaultEditorLanguage && (drawer='fields')" :title="isDefaultEditorLanguage ? 'Поля раздела' : 'Структура редактируется на основном языке'"><i class="bi bi-sliders2"></i> Поля</button>
              <button v-if="document.mode==='multiple' && can('content.edit')" class="button primary" type="button" @click="createMultipleRecord('document')"><i class="bi bi-plus-lg"></i> Создать</button>
              <button class="button soft versions-button" type="button" @click="openVersions"><i class="bi bi-clock-history"></i> Версии <span v-if="currentVersionCount">{{ currentVersionCount }}</span></button>
              <div class="autosave-pill" :class="saveState"><i></i><span>{{ autosaveText }}</span></div>
            </div>
          </div>

          <div v-if="document.i18n?.enabled" class="document-language-bar">
            <div class="language-bar-label"><i class="bi bi-translate"></i><span>Язык</span></div>
            <div class="language-bar-scroll">
              <button v-for="lang in documentLanguages" :key="lang.code" type="button" class="language-chip" :class="{active:editorLanguage===lang.code}" @click="switchEditorLanguage(lang.code)">
                <span>{{ lang.code.toUpperCase() }}</span><b>{{ lang.name }}</b><i v-if="lang.code===document.i18n.default_language" class="bi bi-star" title="Язык по умолчанию"></i>
              </button>
            </div>
          </div>

          <div v-if="document.mode==='multiple'" class="multiple-records-view">
            <div class="records-browser-toolbar">
              <div class="records-browser-count"><span>{{ activeDocumentData?.length || 0 }}</span><div><strong>Записи</strong><small>Нажмите на запись, чтобы выбрать действие</small></div></div>
              <div class="view-switch record-view-switch" aria-label="Вид записей">
                <button type="button" :class="{active:documentRecordViewMode==='grid'}" @click="setRecordView('document','grid')" title="Сетка"><i class="bi bi-grid"></i></button>
                <button type="button" :class="{active:documentRecordViewMode==='list'}" @click="setRecordView('document','list')" title="Список"><i class="bi bi-list-ul"></i></button>
              </div>
            </div>
            <div v-if="document.schema.length===0" class="record-browser-empty"><div class="no-fields-icon"><i class="bi bi-sliders2"></i></div><h2>Сначала добавьте поля</h2><p>{{ isDefaultEditorLanguage ? 'Создайте структуру записи, а затем добавляйте объекты.' : 'Структура раздела создаётся на основном языке.' }}</p><button v-if="can('content.edit')" class="button primary" type="button" @click="isDefaultEditorLanguage ? (drawer='fields') : switchEditorLanguage(document.i18n.default_language)">{{ isDefaultEditorLanguage ? 'Добавить поля' : 'Перейти на основной язык' }}</button></div>
            <div v-else-if="!activeDocumentData?.length" class="record-browser-empty"><div class="no-fields-icon"><i class="bi bi-plus-lg"></i></div><h2>Записей пока нет</h2><p>Создайте первую запись — она появится здесь как карточка.</p><button v-if="can('content.edit')" class="button primary" type="button" @click="createMultipleRecord('document')"><i class="bi bi-plus-lg"></i> Создать запись</button></div>
            <div v-else class="record-browser" :class="documentRecordViewMode">
              <article v-for="(item,index) in activeDocumentData" :key="item._uid" class="record-browser-card" @click="openRecordMenu('document',item,index)" @contextmenu.prevent.stop="openRecordContext($event,'document',item,index)">
                <div class="record-browser-cover" :class="{empty:!recordCover('document',item)}"><img v-if="recordCover('document',item)" :src="recordCover('document',item)" alt=""><i v-else class="bi bi-file-earmark-text"></i></div>
                <div class="record-browser-copy"><strong>{{ itemTitle(item,index) }}</strong><small>{{ recordCardSubtitle('document',item,index) }}</small></div>
                <span class="record-browser-index">{{ index+1 }}</span><i class="bi bi-three-dots record-browser-more"></i>
              </article>
            </div>
          </div>

          <div v-else class="editor-grid">
            <div class="content-editor-shell">
              <aside v-if="document.mode==='multiple'" class="records-panel">
                <div class="records-head">
                  <div><small>ЗАПИСИ</small><b>{{ activeDocumentData?.length || 0 }}</b></div>
                  <button v-if="can('content.edit')" class="record-add" type="button" @click="addItem" title="Добавить запись"><i class="bi bi-plus-lg"></i></button>
                </div>
                <div v-if="activeDocumentData?.length" class="records-list">
                  <div v-for="(item,index) in activeDocumentData" :key="item._uid" class="record-row" :class="{active:activeItemUid===item._uid}">
                    <button class="record-open" type="button" @click="activeItemUid=item._uid">
                      <span>{{ index+1 }}</span><div><strong>{{ itemTitle(item,index) }}</strong><small>Запись {{ index+1 }}</small></div>
                    </button>
                    <button v-if="can('content.edit')" class="record-delete" type="button" @click="deleteItem(item._uid)" title="Удалить запись"><i class="bi bi-trash3"></i></button>
                  </div>
                </div>
                <div v-else class="records-empty"><p>Пока нет записей</p><button type="button" @click="addItem">＋ Добавить первую</button></div>
              </aside>

              <div class="editor-card" :class="{'readonly-panel':!can('content.edit')}">
                <div v-if="document.schema.length===0" class="no-fields">
                  <div class="no-fields-icon"><i class="bi bi-plus-lg"></i></div><h2>Добавьте первое поле</h2><p>{{ isDefaultEditorLanguage ? 'Например заголовок, описание или изображение.' : 'Структура раздела создаётся на основном языке.' }}</p><button v-if="can('content.edit')" class="button primary" type="button" @click="isDefaultEditorLanguage ? (drawer='fields') : switchEditorLanguage(document.i18n.default_language)">{{ isDefaultEditorLanguage ? 'Добавить поля' : 'Перейти на основной язык' }}</button>
                </div>
                <div v-else-if="document.mode==='multiple' && !activeData" class="no-fields">
                  <div class="no-fields-icon"><i class="bi bi-plus-lg"></i></div><h2>Добавьте запись</h2><p>Поля уже готовы. Теперь создайте первую запись.</p><button v-if="can('content.edit')" class="button primary" type="button" @click="addItem"><i class="bi bi-plus-lg"></i> Новая запись</button>
                </div>

                <template v-else>
                  <div v-for="field in document.schema" :key="field.key" class="field-row">
                    <div class="field-label"><label :for="'field-'+(activeItemUid || 'single')+'-'+field.key">{{ field.label }}</label><small>{{ typeNames[field.type] || field.type }}<template v-if="field.multiple"> · несколько</template></small></div>

                    <textarea v-if="field.type==='textarea'" :id="'field-'+(activeItemUid || 'single')+'-'+field.key" v-model="activeData[field.key]" rows="5" @input="markDirty"></textarea>
                    <label v-else-if="field.type==='boolean'" class="toggle-field"><input type="checkbox" v-model="activeData[field.key]" @change="markDirty"><span></span><b>{{ activeData[field.key] ? 'Включено' : 'Выключено' }}</b></label>
                    <input v-else-if="field.type==='number'" :id="'field-'+(activeItemUid || 'single')+'-'+field.key" type="number" v-model.number="activeData[field.key]" @input="markDirty">
                    <input v-else-if="field.type==='date'" :id="'field-'+(activeItemUid || 'single')+'-'+field.key" type="date" v-model="activeData[field.key]" @input="markDirty">
                    <input v-else-if="field.type==='datetime'" :id="'field-'+(activeItemUid || 'single')+'-'+field.key" type="datetime-local" v-model="activeData[field.key]" @input="markDirty">
                    <input v-else-if="field.type==='link'" :id="'field-'+(activeItemUid || 'single')+'-'+field.key" type="url" v-model="activeData[field.key]" @input="markDirty" placeholder="https:// или /page">
                    <input v-else-if="field.type==='email'" :id="'field-'+(activeItemUid || 'single')+'-'+field.key" type="email" v-model="activeData[field.key]" @input="markDirty" placeholder="name@example.com">
                    <input v-else-if="field.type==='phone'" :id="'field-'+(activeItemUid || 'single')+'-'+field.key" type="tel" v-model="activeData[field.key]" @input="markDirty" placeholder="+7 700 000 00 00">
                    <div v-else-if="field.type==='color'" class="color-field"><input :id="'field-'+(activeItemUid || 'single')+'-'+field.key" type="color" v-model="activeData[field.key]" @input="markDirty"><input type="text" v-model="activeData[field.key]" @input="markDirty" placeholder="#000000"></div>

                    <div v-else-if="isUploadType(field.type)" class="asset-field" :class="['asset-'+field.type, {'is-multiple':field.multiple}]">
                      <div v-if="assetValues(field, document.mode==='multiple' ? activeItemUid : null).length" class="asset-gallery" :class="{'single-asset':!field.multiple}">
                        <div v-for="asset in assetValues(field, document.mode==='multiple' ? activeItemUid : null)" :key="asset" class="asset-preview" :class="'preview-'+field.type">
                          <img v-if="field.type==='image'" :src="asset" :alt="assetName(asset)">
                          <video v-else-if="field.type==='video'" :src="asset" controls playsinline preload="metadata"></video>
                          <audio v-else-if="field.type==='audio'" :src="asset" controls preload="metadata"></audio>
                          <div v-else class="document-preview"><i class="bi bi-file-earmark-arrow-down"></i><strong>{{ assetName(asset) }}</strong><a :href="asset" target="_blank" rel="noopener" @click.stop><i class="bi bi-box-arrow-up-right"></i> Открыть</a></div>
                          <button class="asset-remove" type="button" @click="removeAsset(field,asset, document.mode==='multiple' ? activeItemUid : null)" title="Убрать файл"><i class="bi bi-x-lg"></i></button>
                        </div>
                      </div>
                      <label class="upload-zone" :class="{compact:assetValues(field, document.mode==='multiple' ? activeItemUid : null).length}">
                        <input type="file" :accept="fileAccept(field.type)" :multiple="field.multiple===true" @change="chooseAsset(field,$event, document.mode==='multiple' ? activeItemUid : null)">
                        <span class="upload-symbol"><i class="bi bi-cloud-arrow-up"></i></span>
                        <strong>{{ uploadActionLabel(field, assetValues(field, document.mode==='multiple' ? activeItemUid : null).length > 0) }}</strong>
                        <small>{{ uploadHint(field.type) }} · до {{ <?=json_encode((int)(cms_config('upload_max_mb') ?? 100))?> }} МБ на файл<template v-if="field.multiple"> · можно несколько</template></small>
                      </label>
                    </div>
                    <input v-else :id="'field-'+(activeItemUid || 'single')+'-'+field.key" type="text" v-model="activeData[field.key]" @input="markDirty">
                  </div>
                </template>
              </div>
            </div>

            <aside class="editor-info">
              <div class="info-card"><small>API ENDPOINT</small><code class="endpoint-full-inline">{{ absoluteApiUrl }}</code><button type="button" class="copy-link" @click="copy(absoluteApiUrl)"><i class="bi bi-copy"></i> Копировать адрес</button></div>
              <div class="info-card"><small>ОТВЕТ API</small><div class="return-type"><b>{{ document.mode==='multiple' ? '[ ]' : '{ }' }}</b><span>{{ document.mode==='multiple' ? 'Массив объектов' : 'Один объект' }}</span></div><p v-if="document.mode==='multiple'">Сейчас {{ activeDocumentData?.length || 0 }} {{ plural(activeDocumentData?.length || 0,'запись','записи','записей') }}.</p></div>
              <div class="info-card"><small>АВТОСОХРАНЕНИЕ</small><div class="status-line" :class="saveState"><i></i><span>{{ autosaveText }}</span></div><p>Кнопки сохранения нет — MaterCMS сам сохраняет изменения после короткой паузы.</p><button type="button" class="copy-link" @click="openVersions">История версий · {{ currentVersionCount }}</button></div>
            </aside>
          </div>
        </section>
      </div>
    </main>
  </div>

  <div v-if="drawer" class="drawer-backdrop" @click="drawer=null"></div>
  <aside v-if="drawer==='nav'" class="drawer left-drawer">
    <div class="drawer-head"><div class="auth-brand compact"><span class="brand-mark" aria-hidden="true"></span><div><strong>MaterCMS</strong><small>Контент</small></div></div><button type="button" class="close-button" @click="drawer=null"><i class="bi bi-x-lg"></i></button></div>
    <nav class="folder-nav">
      <button class="nav-home" :class="{active:route.kind==='folder' && route.folderId===null}" type="button" @click="openFolder(null); drawer=null"><i class="bi bi-house-door home-symbol"></i><b>Мой контент</b></button>
      <button v-if="can('data.view')" class="nav-home" :class="{active:route.kind==='data' || route.kind==='data-set'}" type="button" @click="openData"><i class="bi bi-database files-symbol"></i><b>Данные</b></button>
      <button v-if="can('files.view')" class="nav-home" :class="{active:route.kind==='files'}" type="button" @click="openFiles"><i class="bi bi-folder2-open files-symbol"></i><b>Файлы</b></button>
      <button v-if="can('forms.view')" class="nav-home" :class="{active:route.kind==='forms' || route.kind==='form'}" type="button" @click="openForms"><i class="bi bi-ui-checks-grid files-symbol"></i><b>Формы</b></button>
      <button v-if="can('settings.view')" class="nav-home" :class="{active:['settings','settings-api','settings-languages','settings-projects','settings-team','database'].includes(route.kind)}" type="button" @click="openSettings"><i class="bi bi-gear files-symbol"></i><b>Настройки</b></button>
      <button class="nav-home nav-about" :class="{active:route.kind==='about'}" type="button" @click="openAbout"><i class="bi bi-info-circle files-symbol"></i><b>О продукте</b></button>
      <button class="nav-home nav-trash" type="button" @click="openTrash"><i class="bi bi-trash3 files-symbol"></i><b>Корзина</b><small v-if="state.trash_count">{{ state.trash_count }}</small></button>
    </nav>
  </aside>

  <aside v-if="drawer==='trash'" class="drawer right-drawer content-trash-drawer">
    <div class="drawer-head"><div><small class="eyebrow">МОЙ КОНТЕНТ</small><h2><i class="bi bi-trash3"></i> Корзина</h2></div><button type="button" class="close-button" @click="drawer=null"><i class="bi bi-x-lg"></i></button></div>
    <div v-if="trashItems.length" class="trash-selection-bar">
      <label class="trash-select-all">
        <input type="checkbox" :checked="allTrashSelected" @change="toggleAllTrash">
        <span class="trash-checkbox-ui"><i class="bi bi-check2"></i></span>
        <span><b>Выбрать всё</b><small v-if="trashSelectionCount">Выбрано: {{ trashSelectionCount }}</small><small v-else>{{ trashItems.length }} {{ plural(trashItems.length,'объект','объекта','объектов') }}</small></span>
      </label>
    </div>
    <div class="trash-toolbar"><button class="button soft" type="button" :disabled="!trashSelectionCount" @click="restoreTrash"><i class="bi bi-arrow-counterclockwise"></i> Восстановить<span v-if="trashSelectionCount"> · {{ trashSelectionCount }}</span></button><button class="button ghost" type="button" :disabled="!trashSelectionCount" @click="deleteTrashForever"><i class="bi bi-trash3"></i> Удалить выбранное<span v-if="trashSelectionCount"> · {{ trashSelectionCount }}</span></button><button class="button ghost trash-empty-button" type="button" :disabled="!trashItems.length" @click="emptyTrash"><i class="bi bi-x-octagon"></i> Очистить всё</button></div>
    <div v-if="trashLoading" class="files-loading"><span class="spinner"></span><p>Открываем корзину…</p></div>
    <div v-else-if="trashItems.length" class="trash-list">
      <article v-for="item in trashItems" :key="item.id" class="trash-row" :class="{selected:isTrashSelected(item)}" @dblclick="restoreTrash([item.id])">
        <label class="trash-check" @click.stop @dblclick.stop>
          <input type="checkbox" :checked="isTrashSelected(item)" @change="toggleTrashSelection(item)" :aria-label="'Выбрать '+item.name">
          <span class="trash-checkbox-ui"><i class="bi bi-check2"></i></span>
        </label>
        <button class="trash-row-main" type="button" @click="toggleTrashSelection(item)">
          <span class="trash-icon"><i class="bi" :class="item.type==='folder'?'bi-folder2':'bi-file-earmark-text'"></i></span><span class="trash-copy"><strong>{{ item.name }}</strong><small>{{ item.type==='folder'?'Папка':'Раздел' }} · {{ item.original_path }}</small><em>Удалено {{ formatDateTime(item.deleted_at) }}</em></span>
        </button>
      </article>
    </div>
    <div v-else class="trash-empty-state"><i class="bi bi-trash"></i><h3>Корзина пуста</h3><p>Удалённые папки и разделы будут появляться здесь и их можно будет восстановить.</p></div>
    <div class="trash-hint"><i class="bi bi-info-circle"></i><p>Отметьте нужные элементы checkbox и восстановите либо удалите навсегда только выбранные.</p></div>
  </aside>

  <aside v-if="drawer==='api'" class="drawer right-drawer api-drawer">
    <div class="drawer-head"><div><small class="eyebrow">ДЛЯ РАЗРАБОТЧИКА</small><h2><i class="bi bi-braces"></i> API</h2></div><button type="button" class="close-button" @click="drawer=null"><i class="bi bi-x-lg"></i></button></div>
    <div class="drawer-body api-panel">
      <div class="api-overview-stack">
        <div class="api-intro"><span><i class="bi bi-lightning-charge"></i></span><div><h3>Готовый endpoint</h3><p>MaterCMS уже собрал полный адрес с доменом, папкой CMS, проектом и путём раздела. Ничего дописывать вручную не нужно.</p></div></div>
        <div v-if="currentProject" class="api-project-context"><i class="bi bi-boxes"></i><div><small>ПРОЕКТ</small><b>{{ currentProject.name }}</b><code>/api/{{ currentProject.slug }}/</code></div></div>
        <div class="api-resource-state-card" :class="{disabled: document ? !document.api_enabled : (currentFolder ? !currentFolder.api_enabled : !apiProjectEnabled)}">
          <span><i class="bi" :class="(document ? document.api_enabled : (currentFolder ? currentFolder.api_enabled : apiProjectEnabled)) ? 'bi-broadcast-pin' : 'bi-slash-circle'"></i></span>
          <div>
            <small>{{ document ? 'API РАЗДЕЛА' : (currentFolder ? 'API ПАПКИ' : 'API ПРОЕКТА') }}</small>
            <b>{{ (document ? document.api_enabled : (currentFolder ? currentFolder.api_enabled : apiProjectEnabled)) ? 'Endpoint включён' : 'Endpoint выключен' }}</b>
            <p v-if="!apiProjectEnabled && (document || currentFolder)">API проекта выключен глобально. Локальная настройка объекта сохранена, но внешние запросы пока недоступны.</p>
            <p v-else>{{ (document ? document.api_enabled : (currentFolder ? currentFolder.api_enabled : apiProjectEnabled)) ? 'Endpoint доступен с учётом режима доступа проекта.' : 'URL сохранён, но внешний запрос к этому endpoint временно вернёт 404.' }}</p>
          </div>
          <label v-if="document" class="mini-switch-control"><input type="checkbox" :checked="document.api_enabled" :disabled="!can('content.edit') || busy" @change="setDocumentApiEnabled($event.target.checked)"><span></span></label>
          <label v-else-if="currentFolder" class="mini-switch-control"><input type="checkbox" :checked="currentFolder.api_enabled" :disabled="!can('content.edit') || busy" @change="setFolderApiEnabled($event.target.checked)"><span></span></label>
          <label v-else class="mini-switch-control"><input type="checkbox" :checked="apiProjectEnabled" :disabled="!can('settings.edit') || busy" @change="setProjectApiEnabled($event.target.checked)"><span></span></label>
        </div>
        <div class="api-security-card" :class="state.api_access.mode">
          <span><i class="bi" :class="state.api_access.mode==='private' ? 'bi-shield-lock' : 'bi-globe2'"></i></span>
          <div v-if="state.api_access.mode==='private'"><small>ДОСТУП · PRIVATE</small><b>Требуется секретный токен</b><p>Передавайте его сервер-сервер: <code>Authorization: Bearer $MATERCMS_API_TOKEN</code></p></div>
          <div v-else><small>ДОСТУП · PUBLIC</small><b>Без авторизации</b><p>Endpoint можно вызывать напрямую из браузера, приложения или backend.</p></div>
        </div>
        <div class="api-response-config">
          <span class="api-response-config-icon"><i class="bi bi-braces-asterisk"></i></span>
          <div class="api-response-config-copy"><small>ФОРМАТ ОТВЕТА</small><b>{{ apiResponseModeLabel }}</b><p>{{ currentFolder ? (apiFullResponse ? 'Папка вернёт всё дерево с содержимым разделов + meta.' : 'Папка вернёт всё дерево и информацию об объектах, но без содержимого разделов.') : (apiFullResponse ? 'GET возвращает data + meta. Формы возвращают расширенный результат.' : 'GET возвращает только данные. Формы возвращают компактный результат.') }}</p></div>
          <label class="mini-switch-control" :title="can('settings.edit') ? 'Полный ответ API' : 'Нет права изменять настройки'"><input type="checkbox" :checked="apiFullResponse" :disabled="!can('settings.edit') || apiResponseSaving" @change="setApiFullResponse($event.target.checked)"><span></span></label>
        </div>
      </div>

      <template v-if="document">
        <div class="api-section-head"><label>Текущий раздел</label><span class="api-type-pill">{{ document.mode==='multiple' ? 'Array' : 'Object' }}</span></div>
        <div class="endpoint endpoint-large"><b>GET</b><code>{{ absoluteApiUrl }}</code><button @click="copy(absoluteApiUrl)" type="button" title="Копировать"><i class="bi bi-copy"></i></button></div>
        <div class="api-return"><i class="bi" :class="document.mode==='multiple' ? 'bi-list-ul' : 'bi-braces'"></i><div><b>{{ document.mode==='multiple' ? 'Массив объектов' : 'Один объект' }}</b><span>{{ apiFullResponse ? 'Ответ обёрнут в data и дополнен meta.' : (document.mode==='multiple' ? 'Подходит для проектов, отзывов, команды и других списков.' : 'Подходит для главного экрана, контактов, SEO и настроек.') }}</span></div></div>
        <details class="api-response-spoiler"><summary><span><i class="bi bi-code-square"></i><span><b>Какой ответ вернёт API</b><small>JSON-пример · закрыт по умолчанию</small></span></span><span class="api-response-spoiler-actions"><button type="button" class="spoiler-copy" @click.stop="copy(documentResponseJson)"><i class="bi bi-copy"></i> Копировать</button><i class="bi bi-chevron-down"></i></span></summary><div class="api-response-spoiler-body"><pre><code>{{ documentResponseJson }}</code></pre></div></details>
        <div v-if="document.i18n?.enabled" class="api-language-note"><i class="bi bi-translate"></i><div><b>Язык: {{ editorLanguage.toUpperCase() }}</b><span>Для других языков MaterCMS автоматически добавляет <code>?lang=xx</code>. Если перевода ещё нет, API вернёт основной язык.</span></div></div>
      </template>

      <template v-else-if="currentFolder">
        <div class="api-section-head"><label>Текущая папка</label><span class="api-type-pill">Tree</span></div>
        <div class="endpoint endpoint-large"><b>GET</b><code>{{ folderEndpoint }}</code><button @click="copy(folderEndpoint)" type="button" title="Копировать"><i class="bi bi-copy"></i></button></div>
        <div class="api-return"><i class="bi bi-diagram-3"></i><div><b>Рекурсивное дерево контента</b><span>{{ apiFullResponse ? 'Полный режим возвращает всё дерево вместе с содержимым каждого раздела.' : 'Режим «Только данные» возвращает всё дерево и описание объектов, но без содержимого разделов.' }}</span></div></div>

        <div class="api-folder-cache-config">
          <span class="api-folder-cache-icon"><i class="bi bi-lightning-charge"></i></span>
          <div class="api-folder-cache-copy"><small>КЕШ ПАПКИ</small><b>Кеш готового JSON</b><p>{{ folderApiSettings.cache_ttl > 0 ? folderApiSettings.cache_backend + ' · TTL ' + folderApiSettings.cache_ttl + ' сек.' : 'Кеш выключен — дерево собирается при каждом запросе.' }}</p></div>
          <select v-model.number="folderApiSettings.cache_ttl" :disabled="!can('content.edit') || folderApiSettingsSaving" @change="saveFolderApiTreeSettings"><option :value="0">Выключен</option><option :value="30">30 секунд</option><option :value="60">1 минута</option><option :value="300">5 минут</option><option :value="900">15 минут</option><option :value="3600">1 час</option></select>
        </div>

        <details class="api-response-spoiler"><summary><span><i class="bi bi-code-square"></i><span><b>Какой ответ вернёт API</b><small>{{ folderApiPreviewLoading ? 'Собираем дерево…' : (apiFullResponse ? 'Полное дерево + содержимое · закрыт по умолчанию' : 'Всё дерево без содержимого · закрыт по умолчанию') }}</small></span></span><span class="api-response-spoiler-actions"><button type="button" class="spoiler-copy" @click.stop="copy(folderResponseJson)"><i class="bi bi-copy"></i> Копировать</button><i class="bi bi-chevron-down"></i></span></summary><div class="api-response-spoiler-body"><pre><code>{{ folderResponseJson }}</code></pre></div></details>
      </template>

      <div class="api-section-head root-head"><label>API текущего проекта</label></div>
      <div class="endpoint"><b>GET</b><code>{{ absoluteApiRoot }}</code><button @click="copy(absoluteApiRoot)" type="button" title="Копировать"><i class="bi bi-copy"></i></button></div>
      <details v-if="!document && !currentFolder" class="api-response-spoiler"><summary><span><i class="bi bi-code-square"></i><span><b>Какой ответ вернёт API</b><small>Карта endpoint проекта · закрыта по умолчанию</small></span></span><span class="api-response-spoiler-actions"><button type="button" class="spoiler-copy" @click.stop="copy(currentApiResponseJson)"><i class="bi bi-copy"></i> Копировать</button><i class="bi bi-chevron-down"></i></span></summary><div class="api-response-spoiler-body"><pre><code>{{ currentApiResponseJson }}</code></pre></div></details>

      <div class="api-code-card">
        <div class="api-code-head">
          <div><small>ПРИМЕР ЗАПРОСА</small><strong>Можно вставить в проект</strong></div>
          <button type="button" class="code-copy" @click="copy(apiExampleCode)"><i class="bi bi-copy"></i> Копировать</button>
        </div>
        <div class="api-language-tabs" role="tablist">
          <button type="button" :class="{active:apiLanguage==='javascript'}" @click="apiLanguage='javascript'"><i class="bi bi-filetype-js"></i> JavaScript</button>
          <button type="button" :class="{active:apiLanguage==='php'}" @click="apiLanguage='php'"><i class="bi bi-filetype-php"></i> PHP</button>
          <button type="button" :class="{active:apiLanguage==='python'}" @click="apiLanguage='python'"><i class="bi bi-filetype-py"></i> Python</button>
          <button type="button" :class="{active:apiLanguage==='curl'}" @click="apiLanguage='curl'"><i class="bi bi-terminal"></i> cURL</button>
        </div>
        <pre class="api-code"><code>{{ apiExampleCode }}</code></pre>
      </div>

      <section class="api-ai-card">
        <div class="api-ai-card-head"><span><i class="bi bi-stars"></i></span><div><small>AI PROMPT</small><strong>Подключить API с помощью ChatGPT</strong><p>MaterCMS передаст endpoint, правила авторизации и JSON-пример текущего ответа, чтобы ИИ сразу видел реальные поля. Секретный токен в prompt не попадает.</p></div></div>
        <div class="api-ai-actions"><button class="button soft" type="button" @click="copy(apiAiPrompt)"><i class="bi bi-copy"></i> Копировать prompt</button><a class="button primary" :href="chatGptPromptUrl(apiAiPrompt)" target="_blank" rel="noopener noreferrer"><i class="bi bi-box-arrow-up-right"></i> Открыть в ChatGPT</a></div>
        <div class="api-ai-json-note"><i class="bi bi-braces"></i><span>JSON-пример автоматически добавляется в prompt</span></div>
      </section>

      <div v-if="state.api_access.mode==='private'" class="api-tip private-tip"><i class="bi bi-shield-exclamation"></i><p><b>Секрет нельзя хранить в браузерном JavaScript, Vue/React bundle, HTML или мобильном приложении без защищённого backend.</b> В примерах используется переменная окружения <code>MATERCMS_API_TOKEN</code>. Если хостинг не передаёт заголовок Authorization, MaterCMS также понимает <code>X-MaterCMS-Token</code>.</p></div>
      <div v-else class="api-tip"><i class="bi bi-info-circle"></i><p>API проекта публичный и работает на чтение через <b>GET</b>. Для CORS можно изменить <code>cors_origin</code> в <code>config.php</code>.</p></div>
    </div>
  </aside>

  <aside v-if="drawer==='data-api' && dataSet" class="drawer right-drawer api-drawer data-api-drawer">
    <div class="drawer-head">
      <div><small class="eyebrow">READ-ONLY API</small><h2><i class="bi bi-database"></i> {{ dataSet.name }}</h2></div>
      <button type="button" class="close-button" @click="drawer=null" aria-label="Закрыть"><i class="bi bi-x-lg"></i></button>
    </div>
    <div class="drawer-body api-panel">
      <div class="api-overview-stack">
        <div class="api-intro">
          <span><i class="bi bi-braces-asterisk"></i></span>
          <div><h3>Готовый API данных</h3><p>MaterCMS уже сформировал полный адрес набора. API работает только на чтение — изменять записи снаружи нельзя.</p></div>
        </div>
        <div class="data-api-state-card" :class="dataSet.api_enabled ? 'enabled' : 'disabled'">
          <span><i class="bi" :class="dataSet.api_enabled ? 'bi-broadcast-pin' : 'bi-slash-circle'"></i></span>
          <div><small>СОСТОЯНИЕ НАБОРА</small><b>{{ dataSet.api_enabled ? 'API включён' : 'API выключен' }}</b><p v-if="!apiProjectEnabled">API проекта выключен глобально. Настройка набора сохранена.</p><p v-else>{{ dataSet.api_enabled ? 'Внешние приложения могут получать этот набор через GET.' : 'Endpoint сохранён, но запросы к этому набору временно отключены.' }}</p></div>
          <label class="mini-switch-control"><input type="checkbox" :checked="dataSet.api_enabled" :disabled="!can('data.edit') || busy" @change="setDataApiEnabled($event.target.checked)"><span></span></label>
        </div>
        <div class="api-security-card" :class="state.api_access.mode">
          <span><i class="bi" :class="state.api_access.mode==='private' ? 'bi-shield-lock' : 'bi-globe2'"></i></span>
          <div v-if="state.api_access.mode==='private'"><small>ДОСТУП ПРОЕКТА · PRIVATE</small><b>Нужен секретный токен</b><p>Готовые примеры ниже уже добавляют <code>Authorization: Bearer</code>.</p></div>
          <div v-else><small>ДОСТУП ПРОЕКТА · PUBLIC</small><b>Без авторизации</b><p>Endpoint можно читать напрямую из frontend или backend.</p></div>
        </div>
        <div class="api-response-config">
          <span class="api-response-config-icon"><i class="bi bi-braces-asterisk"></i></span><div class="api-response-config-copy"><small>ФОРМАТ ОТВЕТА</small><b>{{ apiResponseModeLabel }}</b><p>{{ apiFullResponse ? 'Набор вернётся как { data, meta }.' : 'Набор вернётся как чистый объект или массив.' }}</p></div><label class="mini-switch-control"><input type="checkbox" :checked="apiFullResponse" :disabled="!can('settings.edit') || apiResponseSaving" @change="setApiFullResponse($event.target.checked)"><span></span></label>
        </div>
      </div>

      <div class="api-section-head">
        <label>Endpoint набора</label>
        <span class="api-type-pill">{{ dataSet.mode==='multiple' ? 'Array' : 'Object' }}</span>
      </div>
      <div class="endpoint endpoint-large">
        <b>GET</b>
        <code>{{ dataEndpoint }}</code>
        <button type="button" @click="copy(dataEndpoint)" title="Копировать"><i class="bi bi-copy"></i></button>
      </div>

      <div class="api-return">
        <i class="bi" :class="dataSet.mode==='multiple' ? 'bi-list-ul' : 'bi-braces'"></i>
        <div><b>{{ dataSet.mode==='multiple' ? 'Массив объектов' : 'Один объект' }}</b><span>{{ apiFullResponse ? 'Ответ обёрнут в data и дополнен meta.' : (dataSet.mode==='multiple' ? ('Сейчас ' + (Array.isArray(dataSet.data) ? dataSet.data.length : 0) + ' записей.') : 'API возвращает один объект с полями набора.') }}</span></div>
      </div>
      <details class="api-response-spoiler"><summary><span><i class="bi bi-code-square"></i><span><b>Какой ответ вернёт API</b><small>JSON-пример · закрыт по умолчанию</small></span></span><span class="api-response-spoiler-actions"><button type="button" class="spoiler-copy" @click.stop="copy(dataResponseJson)"><i class="bi bi-copy"></i> Копировать</button><i class="bi bi-chevron-down"></i></span></summary><div class="api-response-spoiler-body"><pre><code>{{ dataResponseJson }}</code></pre></div></details>

      <div class="api-code-card">
        <div class="api-code-head">
          <div><small>ПРИМЕР ЗАПРОСА</small><strong>Готовый код для подключения</strong></div>
          <button type="button" class="code-copy" @click="copy(dataApiExampleCode)"><i class="bi bi-copy"></i> Копировать</button>
        </div>
        <div class="api-language-tabs" role="tablist">
          <button type="button" :class="{active:apiLanguage==='javascript'}" @click="apiLanguage='javascript'"><i class="bi bi-filetype-js"></i> JavaScript</button>
          <button type="button" :class="{active:apiLanguage==='php'}" @click="apiLanguage='php'"><i class="bi bi-filetype-php"></i> PHP</button>
          <button type="button" :class="{active:apiLanguage==='python'}" @click="apiLanguage='python'"><i class="bi bi-filetype-py"></i> Python</button>
          <button type="button" :class="{active:apiLanguage==='curl'}" @click="apiLanguage='curl'"><i class="bi bi-terminal"></i> cURL</button>
        </div>
        <pre class="api-code"><code>{{ dataApiExampleCode }}</code></pre>
      </div>

      <section class="api-ai-card">
        <div class="api-ai-card-head"><span><i class="bi bi-stars"></i></span><div><small>AI PROMPT</small><strong>Подключить «{{ dataSet.name }}» через ChatGPT</strong><p>Готовый запрос содержит read-only endpoint и JSON-пример записи с реальной структурой полей и связей.</p></div></div>
        <div class="api-ai-actions"><button class="button soft" type="button" @click="copy(dataAiPrompt)"><i class="bi bi-copy"></i> Копировать prompt</button><a class="button primary" :href="chatGptPromptUrl(dataAiPrompt)" target="_blank" rel="noopener noreferrer"><i class="bi bi-box-arrow-up-right"></i> Открыть в ChatGPT</a></div>
        <div class="api-ai-json-note"><i class="bi bi-braces"></i><span>JSON-пример автоматически добавляется в prompt</span></div>
      </section>

      <div v-if="state.api_access.mode==='private'" class="api-tip private-tip"><i class="bi bi-shield-exclamation"></i><p><b>Не вставляйте секретный токен в браузерный JavaScript.</b> Для приватного проекта вызывайте MaterCMS с вашего backend/serverless и храните токен в переменной окружения <code>MATERCMS_API_TOKEN</code>.</p></div>
      <div v-else class="api-tip"><i class="bi bi-info-circle"></i><p>Этот endpoint доступен только для чтения. Создание, изменение и удаление данных выполняются только через интерфейс MaterCMS.</p></div>
    </div>
  </aside>

  <aside v-if="drawer==='form-api' && form" class="drawer right-drawer api-drawer form-api-drawer">
    <div class="drawer-head"><div><small class="eyebrow">ПОДКЛЮЧЕНИЕ</small><h2><i class="bi bi-plug"></i> Форма на сайте</h2></div><button type="button" class="close-button" @click="drawer=null"><i class="bi bi-x-lg"></i></button></div>
    <div class="drawer-body api-panel">
      <div class="api-overview-stack">
        <div class="api-intro"><span><i class="bi bi-send"></i></span><div><h3>Принимайте заявки</h3><p>Отправляйте <b>POST</b> на этот адрес. MaterCMS проверит поля, сохранит вложения и положит заявку во входящие.</p></div></div>
        <div class="api-resource-state-card" :class="{disabled:!form.api_enabled}">
          <span><i class="bi" :class="form.api_enabled ? 'bi-broadcast-pin' : 'bi-slash-circle'"></i></span>
          <div><small>API ФОРМЫ</small><b>{{ form.api_enabled ? 'Приём заявок включён' : 'Приём заявок выключен' }}</b><p v-if="!apiProjectEnabled">API проекта выключен глобально. Настройка формы сохранена.</p><p v-else>{{ form.api_enabled ? 'GET-описание и POST формы доступны внешним приложениям.' : 'Endpoint формы сохранён, но GET и POST временно возвращают 404.' }}</p></div>
          <label class="mini-switch-control"><input type="checkbox" :checked="form.api_enabled" :disabled="!can('forms.edit') || busy" @change="setFormApiEnabled($event.target.checked)"><span></span></label>
        </div>
        <div class="api-security-card" :class="state.api_access.mode">
          <span><i class="bi" :class="state.api_access.mode==='private' ? 'bi-shield-lock' : 'bi-globe2'"></i></span>
          <div v-if="state.api_access.mode==='private'"><small>ПРИВАТНАЯ ФОРМА</small><b>POST тоже требует Bearer-токен</b><p>Обычный HTML <code>&lt;form&gt;</code> не умеет безопасно хранить секрет. Отправляйте форму через свой backend/proxy.</p></div>
          <div v-else><small>ПУБЛИЧНАЯ ФОРМА</small><b>Можно подключить напрямую</b><p>HTML или JavaScript может отправлять данные сразу в MaterCMS без токена.</p></div>
        </div>
        <div class="api-response-config">
          <span class="api-response-config-icon"><i class="bi bi-braces-asterisk"></i></span><div class="api-response-config-copy"><small>ФОРМАТ ОТВЕТА</small><b>{{ apiResponseModeLabel }}</b><p>{{ apiFullResponse ? 'POST вернёт ID заявки, проект и slug формы.' : 'POST вернёт только ok и сообщение об успехе.' }}</p></div><label class="mini-switch-control"><input type="checkbox" :checked="apiFullResponse" :disabled="!can('settings.edit') || apiResponseSaving" @change="setApiFullResponse($event.target.checked)"><span></span></label>
        </div>
      </div>
      <div class="api-section-head"><label>Endpoint формы</label><span class="api-type-pill post-pill">POST</span></div>
      <div class="endpoint endpoint-large post-endpoint"><b>POST</b><code>{{ form.endpoint }}</code><button @click="copy(form.endpoint)" type="button" title="Копировать"><i class="bi bi-copy"></i></button></div>
      <details class="api-response-spoiler"><summary><span><i class="bi bi-code-square"></i><span><b>Какой ответ вернёт API</b><small>Успешный POST · закрыт по умолчанию</small></span></span><span class="api-response-spoiler-actions"><button type="button" class="spoiler-copy" @click.stop="copy(formResponseJson)"><i class="bi bi-copy"></i> Копировать</button><i class="bi bi-chevron-down"></i></span></summary><div class="api-response-spoiler-body"><pre><code>{{ formResponseJson }}</code></pre></div></details>
      <div class="api-code-card">
        <div class="api-code-head"><div><small>ПРИМЕР ПОДКЛЮЧЕНИЯ</small><strong>Готовый код</strong></div><button type="button" class="code-copy" @click="copy(formExampleCode)"><i class="bi bi-copy"></i> Копировать</button></div>
        <div class="api-language-tabs" role="tablist">
          <button type="button" :class="{active:apiLanguage==='javascript'}" @click="apiLanguage='javascript'"><i class="bi bi-filetype-js"></i> JavaScript</button>
          <button type="button" :class="{active:apiLanguage==='html'}" @click="apiLanguage='html'"><i class="bi bi-filetype-html"></i> HTML</button>
          <button type="button" :class="{active:apiLanguage==='php'}" @click="apiLanguage='php'"><i class="bi bi-filetype-php"></i> PHP</button>
          <button type="button" :class="{active:apiLanguage==='python'}" @click="apiLanguage='python'"><i class="bi bi-filetype-py"></i> Python</button>
          <button type="button" :class="{active:apiLanguage==='curl'}" @click="apiLanguage='curl'"><i class="bi bi-terminal"></i> cURL</button>
        </div>
        <pre class="api-code"><code>{{ formExampleCode }}</code></pre>
      </div>
      <section class="api-ai-card">
        <div class="api-ai-card-head"><span><i class="bi bi-stars"></i></span><div><small>AI PROMPT</small><strong>Подключить форму через ChatGPT</strong><p>Prompt содержит endpoint, метод POST и JSON-пример данных формы, но никогда не содержит сам приватный токен.</p></div></div>
        <div class="api-ai-actions"><button class="button soft" type="button" @click="copy(formAiPrompt)"><i class="bi bi-copy"></i> Копировать prompt</button><a class="button primary" :href="chatGptPromptUrl(formAiPrompt)" target="_blank" rel="noopener noreferrer"><i class="bi bi-box-arrow-up-right"></i> Открыть в ChatGPT</a></div>
        <div class="api-ai-json-note"><i class="bi bi-braces"></i><span>JSON-пример автоматически добавляется в prompt</span></div>
      </section>

      <div v-if="state.api_access.mode==='private'" class="api-tip private-tip"><i class="bi bi-key"></i><p>Храните <code>MATERCMS_API_TOKEN</code> только на сервере. Для приватного проекта вкладка HTML показывает форму, которая отправляет данные на ваш серверный proxy. PHP/Python/JavaScript(server)/cURL примеры уже добавляют Bearer-токен.</p></div>
      <div v-else class="api-tip"><i class="bi bi-shield-check"></i><p>Для простой защиты от ботов HTML-пример уже содержит скрытое поле <code>_website</code>. Вложения отправляйте как <code>multipart/form-data</code>. Лимит одного вложения — {{ <?=json_encode((int)(cms_config('form_upload_max_mb') ?? 20))?> }} МБ.</p></div>
    </div>
  </aside>

  <aside v-if="drawer==='submission' && selectedSubmission && form" class="drawer right-drawer submission-drawer">
    <div class="drawer-head"><div><small class="eyebrow">ЗАЯВКА #{{ selectedSubmission.id }}</small><h2>Входящее обращение</h2></div><button type="button" class="close-button" @click="drawer=null"><i class="bi bi-x-lg"></i></button></div>
    <div class="drawer-body submission-panel">
      <div class="submission-meta"><span><i class="bi bi-calendar3"></i> {{ formatDateTime(selectedSubmission.created_at) }}</span><span class="read-pill"><i class="bi bi-check2"></i> Прочитана</span></div>
      <div class="submission-fields">
        <div v-for="(value,key) in selectedSubmission.data" :key="key" class="submission-field">
          <small>{{ submissionFieldLabel(key) }}</small>
          <template v-if="submissionFieldType(key)==='file'">
            <div class="submission-files"><template v-for="asset in (Array.isArray(value)?value:[value])" :key="asset"><a v-if="asset" :href="absoluteAsset(asset)" target="_blank" rel="noopener"><i class="bi bi-paperclip"></i><span>{{ assetName(asset) }}</span><i class="bi bi-box-arrow-up-right"></i></a></template><span v-if="!value || (Array.isArray(value)&&!value.length)">—</span></div>
          </template>
          <strong v-else-if="submissionFieldType(key)==='checkbox'">{{ value ? 'Да' : 'Нет' }}</strong>
          <strong v-else>{{ value === '' || value === null ? '—' : value }}</strong>
        </div>
      </div>
    </div>
    <div v-if="can('forms.edit')" class="drawer-footer"><button class="button danger-button wide" type="button" @click="deleteSubmission(selectedSubmission)"><i class="bi bi-trash3"></i> Удалить заявку</button></div>
  </aside>

  <aside v-if="drawer==='member'" class="drawer right-drawer member-drawer">
    <div class="drawer-head"><div><small class="eyebrow">ДОСТУП К ПРОЕКТУ</small><h2>{{ selectedProjectMember ? 'Права пользователя' : 'Добавить участника' }}</h2></div><button type="button" class="close-button" @click="drawer=null"><i class="bi bi-x-lg"></i></button></div>
    <div class="drawer-body member-editor">
      <label v-if="!selectedProjectMember" class="field-label">Пользователь<select v-model.number="projectDialog.user_id"><option :value="null" disabled>Выберите пользователя</option><option v-for="account in availableUsers.filter(u=>!projectMembers.some(m=>Number(m.id)===Number(u.id)))" :key="account.id" :value="account.id">{{ account.name }} · {{ account.email }}</option></select></label>
      <div v-else class="member-editor-person"><span class="member-avatar large">{{ (selectedProjectMember.name || 'U').slice(0,1).toUpperCase() }}</span><div><strong>{{ selectedProjectMember.name }}</strong><small>{{ selectedProjectMember.email }}</small></div></div>
      <label class="field-label">Роль<select v-model="projectDialog.role" @change="memberRoleChanged"><option value="viewer">Только просмотр</option><option value="editor">Редактор</option><option value="admin">Администратор проекта</option><option v-if="isSystemOwner" value="owner">Владелец</option></select></label>
      <div class="permission-list"><div class="permission-head"><h3>Точные права</h3><p>Можно изменить права независимо от выбранной роли.</p></div>
        <label><span><b>Контент</b><small>Редактировать папки, разделы и поля</small></span><input type="checkbox" v-model="projectDialog.permissions['content.edit']"></label>
        <label><span><b>Данные</b><small>Создавать и редактировать глобальные данные проекта</small></span><input type="checkbox" v-model="projectDialog.permissions['data.edit']"></label>
        <label><span><b>Формы</b><small>Создавать формы и работать с заявками</small></span><input type="checkbox" v-model="projectDialog.permissions['forms.edit']"></label>
        <label><span><b>Файлы</b><small>Загрузка и очистка файлов</small></span><input type="checkbox" v-model="projectDialog.permissions['files.manage']"></label>
        <label><span><b>Настройки</b><small>Языки и настройки проекта</small></span><input type="checkbox" v-model="projectDialog.permissions['settings.edit']"></label>
        <label><span><b>Участники</b><small>Назначать людей и менять права</small></span><input type="checkbox" v-model="projectDialog.permissions['members.manage']"></label>
        <label><span><b>Проект</b><small>Переименовывать проект</small></span><input type="checkbox" v-model="projectDialog.permissions['project.edit']"></label>
      </div>
    </div>
    <div class="drawer-footer member-footer"><button v-if="selectedProjectMember && selectedProjectMember.role!=='owner'" class="button ghost danger-text" type="button" @click="removeProjectMember(selectedProjectMember);drawer=null"><i class="bi bi-person-dash"></i> Убрать</button><button class="button primary" type="button" :disabled="!projectDialog.user_id" @click="saveProjectMember"><i class="bi bi-check2"></i> Сохранить права</button></div>
  </aside>

  <aside v-if="drawer==='versions' && document" class="drawer right-drawer versions-drawer">
    <div class="drawer-head"><div><small class="eyebrow">ИСТОРИЯ</small><h2>Версии</h2></div><button type="button" class="close-button" @click="drawer=null"><i class="bi bi-x-lg"></i></button></div>
    <div class="drawer-body versions-panel">
      <div class="versions-intro"><span><i class="bi bi-clock-history"></i></span><div><strong>Автоматическая история</strong><p>Версия создаётся после завершённого автосохранения, а не на каждую введённую букву.</p></div></div>
      <div v-if="versionsLoading" class="versions-loading"><span class="spinner"></span><p>Загружаем историю…</p></div>
      <div v-else-if="versions.length" class="versions-list">
        <button v-for="(version,index) in versions" :key="version.id" class="version-row" type="button" :disabled="restoring" @click="restoreVersion(version)">
          <span class="version-dot"></span>
          <span class="version-copy"><strong>{{ index===0 ? 'Последняя версия' : formatDateTime(version.created_at) }}</strong><small>{{ version.field_count }} {{ plural(version.field_count,'поле','поля','полей') }}<template v-if="version.item_count!==null"> · {{ version.item_count }} {{ plural(version.item_count,'запись','записи','записей') }}</template></small></span>
          <span class="version-action">Восстановить</span>
        </button>
      </div>
      <div v-else class="versions-empty"><span><i class="bi bi-clock-history"></i></span><h3>История пока пустая</h3><p>Она появится после первого изменения.</p></div>
      <p class="versions-retention">MaterCMS хранит последние {{ <?=json_encode((int)(cms_config('revision_limit') ?? 20))?> }} версий для каждого языка. Файлы старых версий автоматически очищаются, когда больше нигде не нужны.</p>
    </div>
  </aside>

  <aside v-if="drawer==='data-fields' && dataSet && can('data.edit')" class="drawer right-drawer fields-drawer">
    <div class="drawer-head"><div><small class="eyebrow">СТРУКТУРА</small><h2>Поля данных</h2></div><button type="button" class="close-button" @click="drawer=null"><i class="bi bi-x-lg"></i></button></div>
    <div class="drawer-body"><p class="drawer-help">Поля определяют структуру объекта или каждой записи в списке.</p><div class="field-builder">
      <div v-for="(field,index) in dataSet.schema" :key="field._uid || field.key" class="field-builder-row"><span class="drag-handle">⋮⋮</span><div class="field-builder-main"><input v-model="field.label" @input="dataFieldLabelChanged(field)" placeholder="Название поля"><div class="field-meta"><select v-model="field.type" @change="dataFieldTypeChanged(field)"><option v-for="(label,type) in dataTypeNames" :key="type" :value="type">{{ label }}</option></select></div><label v-if="field.type==='select'" class="form-options-label">Варианты<textarea v-model="field.optionsText" @input="dataFieldOptionsChanged(field)" rows="3" placeholder="Новый\nПопулярный\nАрхив"></textarea></label><div v-if="field.type==='relation'" class="relation-field-settings"><p class="relation-help"><i class="bi bi-link-45deg"></i><span><b>Связь</b><small>Позволяет выбирать записи из других данных.</small></span></p><label class="form-options-label">Slug<input :value="field.key" @change="dataFieldKeyChanged(field,$event)" placeholder="category"><small class="form-hint">Ключ поля в API. После изменения MaterCMS перенесёт текущее значение.</small></label><label class="form-options-label">Источник<select v-model.number="field.source_data_set_id" @change="dataRelationSourceChanged(field)"><option :value="null">— Выберите данные —</option><option v-for="source in relationSourceDataSets" :key="source.id" :value="source.id">{{ source.name }}</option></select><small v-if="!relationSourceDataSets.length" class="form-hint">Сначала создайте другой набор данных типа Multiple.</small></label><div v-if="field.source_data_set_id" class="relation-mode-settings"><span>Выбор</span><label><input type="radio" :name="'relation-mode-'+field._uid" :checked="field.relation_multiple!==true" @change="field.relation_multiple=false;dataRelationModeChanged(field)"> Одна запись</label><label><input type="radio" :name="'relation-mode-'+field._uid" :checked="field.relation_multiple===true" @change="field.relation_multiple=true;dataRelationModeChanged(field)"> Несколько записей</label></div><label v-if="field.source_data_set_id && relationDisplayFields(field).length" class="form-options-label">Показывать по полю<select v-model="field.display_field" @change="dataRelationDisplayChanged(field)"><option value="">Автоматически</option><option v-for="option in relationDisplayFields(field)" :key="option.key" :value="option.key">{{ option.label }}</option></select><small class="form-hint">MaterCMS сам использует name, title или первое текстовое поле.</small></label></div><label v-if="isUploadType(field.type)" class="field-multiple-toggle"><input type="checkbox" :checked="field.multiple===true" @change="toggleDataFieldMultiple(field,$event)"><span class="mini-switch"></span><span><b>Несколько файлов</b><small>Поле вернёт массив URL</small></span></label><code class="field-key-preview">{{ field.key }}</code></div><button class="remove-field" type="button" @click="removeDataField(index)" title="Удалить поле"><i class="bi bi-trash3"></i></button></div>
    </div><button class="add-field-button" type="button" @click="addDataField"><i class="bi bi-plus-lg"></i> Добавить поле</button></div><div class="drawer-footer"><button class="button primary wide" type="button" @click="drawer=null">Готово</button></div>
  </aside>

  <aside v-if="drawer==='fields' && document && can('content.edit')" class="drawer right-drawer fields-drawer">
    <div class="drawer-head"><div><small class="eyebrow">СТРУКТУРА</small><h2>Поля раздела</h2></div><button type="button" class="close-button" @click="drawer=null"><i class="bi bi-x-lg"></i></button></div>
    <div class="drawer-body">
      <p class="drawer-help">Пользователь видит только эти понятные поля. JSON формируется автоматически.</p>
      <div class="field-builder">
        <div v-for="(field,index) in document.schema" :key="field._uid || field.key" class="field-builder-row">
          <span class="drag-handle">⋮⋮</span>
          <div class="field-builder-main"><input v-model="field.label" @input="fieldLabelChanged(field,index)" placeholder="Название поля"><div class="field-meta"><select v-model="field.type" @change="fieldTypeChanged(field)"><option v-for="(label,type) in typeNames" :value="type" :key="type">{{ label }}</option></select></div><label v-if="isUploadType(field.type)" class="field-multiple-toggle"><input type="checkbox" :checked="field.multiple===true" @change="toggleFieldMultiple(field,$event)"><span class="mini-switch"></span><span><b>Несколько файлов</b><small>Поле вернёт массив URL</small></span></label></div>
          <button class="remove-field" type="button" @click="removeField(index)" title="Удалить поле"><i class="bi bi-trash3"></i></button>
        </div>
      </div>
      <button class="add-field-button" type="button" @click="addField"><i class="bi bi-plus-lg"></i> Добавить поле</button>
    </div>
    <div class="drawer-footer"><button class="button primary wide" type="button" @click="drawer=null">Готово</button></div>
  </aside>

  <div v-if="createMenu" class="create-dropdown content-create-dropdown" :style="createMenuStyle" @click.stop>
    <button type="button" @click="chooseCreate('folder')"><span class="dropdown-kind folder"><i class="bi bi-folder-plus"></i></span><span><strong>Папка</strong><small>Сгруппировать контент</small></span></button>
    <button type="button" @click="chooseCreate('document')"><span class="dropdown-kind document"><i class="bi bi-file-earmark-plus"></i></span><span><strong>Раздел</strong><small>Поля и API</small></span></button>
    <div class="create-dropdown-separator"></div>
    <button v-if="can('data.view')" type="button" @click="chooseCreate('data')"><span class="dropdown-kind data"><i class="bi bi-database-add"></i></span><span><strong>Данные</strong><small>Связать уже созданные данные</small></span></button>
    <button v-if="can('forms.view')" type="button" @click="chooseCreate('form')"><span class="dropdown-kind form"><i class="bi bi-ui-checks-grid"></i></span><span><strong>Форма</strong><small>Добавить существующую форму</small></span></button>
  </div>

  <div v-if="selectedFile" class="drive-preview" @mousedown.self="closeFilePreview">
    <header class="drive-preview-topbar">
      <div class="drive-preview-title">
        <button class="drive-preview-close" type="button" @click="closeFilePreview" aria-label="Закрыть"><i class="bi bi-x-lg"></i></button>
        <span class="drive-preview-kind"><i class="bi" :class="fileKindIcon(selectedFile)"></i></span>
        <div><strong>{{ selectedFile.name }}</strong><small>{{ fileKindLabel(selectedFile) }} · {{ formatFileSize(selectedFile.size) }}</small></div>
      </div>
      <div class="drive-preview-actions">
        <a :href="selectedFile.url" target="_blank" rel="noopener" title="Открыть оригинал"><i class="bi bi-box-arrow-up-right"></i><span>Открыть</span></a>
        <button v-if="selectedFile.document_id || selectedFile.data_set_id || selectedFile.form_id" type="button" @click="openFileUsage(selectedFile); closeFilePreview()"><i class="bi bi-arrow-up-right-square"></i><span>{{ fileUsageLabel(selectedFile) }}</span></button>
      </div>
    </header>

    <button v-if="previewHasPrevious" class="drive-preview-nav prev" type="button" @click.stop="stepFilePreview(-1)" aria-label="Предыдущий файл"><i class="bi bi-chevron-left"></i></button>
    <button v-if="previewHasNext" class="drive-preview-nav next" type="button" @click.stop="stepFilePreview(1)" aria-label="Следующий файл"><i class="bi bi-chevron-right"></i></button>

    <main class="drive-preview-stage" @mousedown.self="closeFilePreview">
      <img v-if="selectedFile.kind==='image'" :src="selectedFile.url" :alt="selectedFile.name" draggable="false">
      <video v-else-if="selectedFile.kind==='video'" :src="selectedFile.url" controls playsinline preload="metadata"></video>
      <div v-else-if="selectedFile.kind==='audio'" class="drive-preview-audio">
        <span><i class="bi bi-music-note-beamed"></i></span>
        <div><strong>{{ selectedFile.name }}</strong><small>{{ selectedFile.mime || 'Аудио' }}</small></div>
        <audio :src="selectedFile.url" controls preload="metadata"></audio>
      </div>
      <iframe v-else-if="selectedFile.mime==='application/pdf'" :src="selectedFile.url" :title="selectedFile.name"></iframe>
      <div v-else class="drive-preview-file">
        <span><i class="bi" :class="fileKindIcon(selectedFile)"></i></span>
        <strong>{{ selectedFile.name }}</strong>
        <small>{{ selectedFile.mime || fileKindLabel(selectedFile) }}</small>
        <a :href="selectedFile.url" target="_blank" rel="noopener"><i class="bi bi-box-arrow-up-right"></i> Открыть файл</a>
      </div>
    </main>

    <footer class="drive-preview-footer">
      <span>{{ formatDate(selectedFile.modified_at) }}</span>
      <i></i>
      <span>{{ previewFileIndex + 1 }} / {{ filteredMediaFiles.length }}</span>
    </footer>
  </div>

  <div v-if="recordDialog.open && recordTarget" class="modal-backdrop record-modal-backdrop" @mousedown.self="closeRecordDialog">
    <section v-if="recordDialog.mode==='menu'" class="modal-card record-action-modal" role="dialog" aria-modal="true">
      <div class="record-action-symbol"><i class="bi" :class="recordDialog.kind==='data' ? 'bi-database' : 'bi-file-earmark-text'"></i></div>
      <div class="record-action-copy"><small class="eyebrow">ЗАПИСЬ</small><h2>{{ recordDialogTitle }}</h2><p>Что вы хотите сделать с этой записью?</p></div>
      <div class="record-action-list">
        <button type="button" @click="chooseRecordAction('read')"><span><i class="bi bi-eye"></i></span><div><strong>Читаемый вид</strong><small>Посмотреть данные без полей ввода</small></div><i class="bi bi-chevron-right"></i></button>
        <button v-if="recordCanEdit" type="button" @click="chooseRecordAction('edit')"><span><i class="bi bi-pencil-square"></i></span><div><strong>Редактировать</strong><small>Изменить содержимое записи</small></div><i class="bi bi-chevron-right"></i></button>
        <button v-if="recordCanEdit" class="danger" type="button" @click="chooseRecordAction('delete')"><span><i class="bi bi-trash3"></i></span><div><strong>Удалить</strong><small>Удаление потребует подтверждения</small></div><i class="bi bi-chevron-right"></i></button>
      </div>
      <div class="modal-actions"><button class="button ghost wide" type="button" @click="closeRecordDialog">Закрыть</button></div>
    </section>

    <section v-else class="modal-card record-editor-modal" role="dialog" aria-modal="true">
      <div class="modal-head record-detail-head"><div><small class="eyebrow">{{ recordDialog.mode==='read' ? 'ЧИТАЕМЫЙ ВИД' : 'РЕДАКТИРОВАНИЕ' }}</small><h2>{{ recordDialogTitle }}</h2><p>{{ recordDialog.mode==='edit' ? 'Изменения сохраняются автоматически.' : 'Все поля записи в удобном виде.' }}</p></div><button type="button" class="close-button" @click="closeRecordDialog"><i class="bi bi-x-lg"></i></button></div>

      <div v-if="recordDialog.mode==='read'" class="readable-record-view">
        <div v-if="!recordSchema.length" class="readable-empty">В этой записи пока нет полей.</div>
        <div v-for="field in recordSchema" :key="field.key" class="readable-field">
          <div class="readable-label"><strong>{{ field.label }}</strong><small>{{ (recordDialog.kind==='data' ? dataTypeNames[field.type] : typeNames[field.type]) || field.type }}</small></div>
          <div v-if="isUploadType(field.type)" class="readable-assets">
            <template v-if="recordAssetValues(field).length">
              <div v-for="asset in recordAssetValues(field)" :key="asset" class="readable-asset">
                <img v-if="field.type==='image'" :src="asset" :alt="assetName(asset)">
                <video v-else-if="field.type==='video'" :src="asset" controls playsinline preload="metadata"></video>
                <audio v-else-if="field.type==='audio'" :src="asset" controls preload="metadata"></audio>
                <a v-else :href="asset" target="_blank" rel="noopener"><i class="bi bi-file-earmark"></i><span>{{ assetName(asset) }}</span><i class="bi bi-box-arrow-up-right"></i></a>
              </div>
            </template><span v-else class="readable-value empty">—</span>
          </div>
          <div v-else-if="field.type==='relation' && recordDialog.kind==='data'" class="readable-relation-chips">
            <template v-if="recordFieldValue(field,recordTarget[field.key])!=='—'"><span v-for="label in recordFieldValue(field,recordTarget[field.key]).split(', ')" :key="label"><i class="bi bi-link-45deg"></i>{{ label }}</span></template><span v-else class="readable-value empty">—</span>
          </div>
          <div v-else class="readable-value" :class="{empty:recordFieldValue(field,recordTarget[field.key])==='—'}">{{ recordFieldValue(field,recordTarget[field.key]) }}</div>
        </div>
      </div>

      <div v-else class="record-edit-fields" :class="{'readonly-panel':!recordCanEdit}">
        <template v-if="recordDialog.kind==='document'">
          <div v-for="field in recordSchema" :key="field.key" class="field-row">
            <div class="field-label"><label>{{ field.label }}</label><small>{{ typeNames[field.type] || field.type }}<template v-if="field.multiple"> · несколько</template></small></div>
            <textarea v-if="field.type==='textarea'" v-model="recordTarget[field.key]" rows="5" @input="markDirty"></textarea>
            <label v-else-if="field.type==='boolean'" class="toggle-field"><input type="checkbox" v-model="recordTarget[field.key]" @change="markDirty"><span></span><b>{{ recordTarget[field.key] ? 'Включено' : 'Выключено' }}</b></label>
            <input v-else-if="field.type==='number'" type="number" v-model.number="recordTarget[field.key]" @input="markDirty">
            <input v-else-if="field.type==='date'" type="date" v-model="recordTarget[field.key]" @input="markDirty">
            <input v-else-if="field.type==='datetime'" type="datetime-local" v-model="recordTarget[field.key]" @input="markDirty">
            <input v-else-if="field.type==='link'" type="url" v-model="recordTarget[field.key]" @input="markDirty" placeholder="https:// или /page">
            <input v-else-if="field.type==='email'" type="email" v-model="recordTarget[field.key]" @input="markDirty" placeholder="name@example.com">
            <input v-else-if="field.type==='phone'" type="tel" v-model="recordTarget[field.key]" @input="markDirty" placeholder="+7 700 000 00 00">
            <div v-else-if="field.type==='color'" class="color-field"><input type="color" v-model="recordTarget[field.key]" @input="markDirty"><input type="text" v-model="recordTarget[field.key]" @input="markDirty" placeholder="#000000"></div>
            <div v-else-if="isUploadType(field.type)" class="asset-field" :class="['asset-'+field.type, {'is-multiple':field.multiple}]">
              <div v-if="assetValues(field,recordDialog.uid).length" class="asset-gallery" :class="{'single-asset':!field.multiple}"><div v-for="asset in assetValues(field,recordDialog.uid)" :key="asset" class="asset-preview" :class="'preview-'+field.type"><img v-if="field.type==='image'" :src="asset" :alt="assetName(asset)"><video v-else-if="field.type==='video'" :src="asset" controls playsinline preload="metadata"></video><audio v-else-if="field.type==='audio'" :src="asset" controls preload="metadata"></audio><div v-else class="document-preview"><i class="bi bi-file-earmark-arrow-down"></i><strong>{{ assetName(asset) }}</strong><a :href="asset" target="_blank" rel="noopener" @click.stop>Открыть</a></div><button class="asset-remove" type="button" @click="removeAsset(field,asset,recordDialog.uid)"><i class="bi bi-x-lg"></i></button></div></div>
              <label class="upload-zone" :class="{compact:assetValues(field,recordDialog.uid).length}"><input type="file" :accept="fileAccept(field.type)" :multiple="field.multiple===true" @change="chooseAsset(field,$event,recordDialog.uid)"><span class="upload-symbol"><i class="bi bi-cloud-arrow-up"></i></span><strong>{{ uploadActionLabel(field,assetValues(field,recordDialog.uid).length>0) }}</strong><small>{{ uploadHint(field.type) }}</small></label>
            </div>
            <input v-else type="text" v-model="recordTarget[field.key]" @input="markDirty">
          </div>
        </template>

        <template v-else>
          <div v-for="field in recordSchema" :key="field.key" class="field-row">
            <div class="field-label"><label>{{ field.label }}</label><small>{{ dataTypeNames[field.type] || field.type }}<template v-if="field.multiple || (field.type==='relation' && field.relation_multiple)"> · несколько</template></small></div>
            <textarea v-if="field.type==='textarea'" v-model="recordTarget[field.key]" rows="5" @input="markDataDirty"></textarea>
            <label v-else-if="field.type==='boolean'" class="toggle-field"><input type="checkbox" v-model="recordTarget[field.key]" @change="markDataDirty"><span></span><b>{{ recordTarget[field.key] ? 'Включено' : 'Выключено' }}</b></label>
            <input v-else-if="field.type==='number'" type="number" v-model.number="recordTarget[field.key]" @input="markDataDirty">
            <input v-else-if="field.type==='date'" type="date" v-model="recordTarget[field.key]" @input="markDataDirty">
            <input v-else-if="field.type==='datetime'" type="datetime-local" v-model="recordTarget[field.key]" @input="markDataDirty">
            <select v-else-if="field.type==='select'" v-model="recordTarget[field.key]" @change="markDataDirty"><option value="">— Не выбрано —</option><option v-for="option in field.options" :key="option" :value="option">{{ option }}</option></select>
            <div v-else-if="field.type==='relation'" class="relation-editor-field"><button class="relation-open-button" type="button" :disabled="!field.source_data_set_id" @click="openRelationPicker(field,recordDialog.uid)"><span class="relation-open-icon"><i class="bi bi-link-45deg"></i></span><span class="relation-open-copy"><strong v-if="field.relation_multiple">{{ relationSelectedIds(field,recordDialog.uid).length ? 'Выбрано: '+relationSelectedIds(field,recordDialog.uid).length : 'Выбрать записи' }}</strong><strong v-else>{{ relationSelectedIds(field,recordDialog.uid).length ? relationLabel(field,relationSelectedIds(field,recordDialog.uid)[0]) : 'Выбрать запись' }}</strong><small>{{ relationSource(field)?.name || 'Источник не выбран' }}</small></span><i class="bi bi-chevron-down"></i></button><div v-if="field.relation_multiple && relationSelectedIds(field,recordDialog.uid).length" class="relation-selected-list"><span v-for="id in relationSelectedIds(field,recordDialog.uid)" :key="id" class="relation-chip">{{ relationLabel(field,id) }}<button type="button" @click="removeRelationRecord(field,id,recordDialog.uid)"><i class="bi bi-x"></i></button></span></div></div>
            <div v-else-if="isUploadType(field.type)" class="asset-field" :class="['asset-'+field.type, {'is-multiple':field.multiple}]"><div v-if="dataAssetValues(field,recordDialog.uid).length" class="asset-gallery" :class="{'single-asset':!field.multiple}"><div v-for="asset in dataAssetValues(field,recordDialog.uid)" :key="asset" class="asset-preview" :class="'preview-'+field.type"><img v-if="field.type==='image'" :src="asset" :alt="assetName(asset)"><video v-else-if="field.type==='video'" :src="asset" controls playsinline preload="metadata"></video><audio v-else-if="field.type==='audio'" :src="asset" controls preload="metadata"></audio><div v-else class="document-preview"><i class="bi bi-file-earmark-arrow-down"></i><strong>{{ assetName(asset) }}</strong><a :href="asset" target="_blank" rel="noopener" @click.stop>Открыть</a></div><button class="asset-remove" type="button" @click="removeDataAsset(field,asset,recordDialog.uid)"><i class="bi bi-x-lg"></i></button></div></div><label class="upload-zone" :class="{compact:dataAssetValues(field,recordDialog.uid).length}"><input type="file" :accept="fileAccept(field.type)" :multiple="field.multiple===true" @change="chooseDataAsset(field,$event,recordDialog.uid)"><span class="upload-symbol"><i class="bi bi-cloud-arrow-up"></i></span><strong>{{ uploadActionLabel(field,dataAssetValues(field,recordDialog.uid).length>0) }}</strong><small>{{ uploadHint(field.type) }}</small></label></div>
            <input v-else type="text" v-model="recordTarget[field.key]" @input="markDataDirty">
          </div>
        </template>
      </div>

      <div class="modal-actions record-editor-actions"><button v-if="recordDialog.mode==='read' && recordCanEdit" class="button soft" type="button" @click="recordDialog.mode='edit'"><i class="bi bi-pencil-square"></i> Редактировать</button><button class="button primary" type="button" @click="closeRecordDialog">Готово</button></div>
    </section>
  </div>

  <div v-if="aboutDocumentLoading || aboutDocument" class="modal-backdrop product-document-modal-backdrop" @mousedown.self="closeProductDocument">
    <section class="modal-card product-document-modal" role="dialog" aria-modal="true" :aria-label="aboutDocument?.title || 'Документация MaterCMS'">
      <header class="product-document-modal-head">
        <div class="product-document-modal-title">
          <span class="product-document-modal-icon"><i class="bi bi-file-earmark-text"></i></span>
          <div><small>ДОКУМЕНТАЦИЯ ПРОДУКТА <template v-if="aboutDocument?.name">· {{ aboutDocument.name }}</template></small><h2>{{ aboutDocument?.title || 'Загрузка документа…' }}</h2></div>
        </div>
        <button class="close-button" type="button" @click="closeProductDocument" aria-label="Закрыть документ"><i class="bi bi-x-lg"></i></button>
      </header>
      <div class="product-document-modal-body">
        <div v-if="aboutDocumentLoading" class="product-document-loading product-document-modal-loading"><i v-for="n in 12" :key="'doc-modal-line-'+n"></i></div>
        <article v-else class="product-markdown product-markdown-modal" v-html="renderMarkdown(aboutDocument?.content || '')"></article>
      </div>
      <footer v-if="aboutDocument" class="product-document-modal-footer"><span><i class="bi bi-file-earmark-code"></i>{{ aboutDocument.name }}</span><span v-if="aboutDocument.updated_at"><i class="bi bi-clock"></i>{{ formatDate(aboutDocument.updated_at) }}</span></footer>
    </section>
  </div>

  <div v-if="relationPicker.open && relationPickerField" class="modal-backdrop relation-picker-backdrop" @mousedown.self="closeRelationPicker">
    <section class="modal-card relation-picker-modal" role="dialog" aria-modal="true">
      <div class="modal-head"><div><small class="eyebrow">СВЯЗЬ</small><h2>{{ relationPickerField.label }}</h2><p class="relation-picker-subtitle">{{ relationSource(relationPickerField)?.name }} · {{ relationPickerField.relation_multiple ? 'можно выбрать несколько' : 'одна запись' }}</p></div><button type="button" class="close-button" @click="closeRelationPicker"><i class="bi bi-x-lg"></i></button></div>
      <div class="relation-picker-search"><i class="bi bi-search"></i><input v-model="relationPicker.search" autofocus placeholder="Найти запись..."></div>
      <div v-if="relationPicker.loading" class="relation-picker-loading"><span class="spinner"></span><p>Загружаем записи…</p></div>
      <div v-else-if="relationPickerRecords.length" class="relation-picker-list">
        <button v-for="record in relationPickerRecords" :key="record.id" type="button" class="relation-picker-row" :class="{selected:relationIsSelected(record.id)}" @click="chooseRelationRecord(record.id)"><span class="relation-picker-check"><i class="bi" :class="relationIsSelected(record.id) ? 'bi-check2' : 'bi-link-45deg'"></i></span><span><strong>{{ record.label }}</strong><small v-if="record.label==='Запись #'+record.id">Нет текстового названия</small></span><i v-if="!relationPickerField.relation_multiple" class="bi bi-chevron-right"></i></button>
      </div>
      <div v-else class="relation-picker-empty"><i class="bi bi-search"></i><h3>Ничего не найдено</h3><p>Измените запрос или добавьте записи в источник данных.</p></div>
      <div v-if="relationPickerField.relation_multiple" class="modal-actions relation-picker-actions"><span>{{ relationSelectedIds(relationPickerField,relationPicker.itemUid).length }} выбрано</span><button class="button primary" type="button" @click="closeRelationPicker">Готово</button></div>
    </section>
  </div>

  <div v-if="modal" class="modal-backdrop" :class="{'user-access-backdrop':modal==='create-user' || modal==='edit-user','mobile-fullscreen-backdrop':modal==='database-switch' || modal==='create-user' || modal==='edit-user'}" @mousedown.self="(modal==='api-token' || (modal==='database-switch' && databaseSwitching)) ? null : (modal=null)">
    <section class="modal-card" :class="{'database-switch-modal':modal==='database-switch','user-access-modal':modal==='create-user' || modal==='edit-user','mobile-fullscreen-modal':modal==='database-switch' || modal==='create-user' || modal==='edit-user','content-properties-card':modal==='content-properties'}">
      <div class="modal-head"><div><small class="eyebrow">{{ modalEyebrow }}</small><h2>{{ modalTitle }}</h2></div><button type="button" class="close-button" :disabled="modal==='database-switch' && databaseSwitching" @click="modal==='api-token' ? closeApiSecret() : ((modal==='database-switch' && databaseSwitching) ? null : (modal=null))"><i class="bi bi-x-lg"></i></button></div>
      <div v-if="modal==='api-token'" class="api-secret-modal-body">
        <div class="secret-created-icon"><i class="bi bi-shield-lock"></i></div>
        <div class="secret-created-copy"><h3>Токен создан</h3><p>Скопируйте его сейчас и сохраните как секрет на сервере. После закрытия MaterCMS больше не сможет показать полный токен — только перевыпустить новый.</p></div>
        <div class="secret-token-box"><code>{{ apiSecret }}</code><button class="button primary" type="button" @click="copy(apiSecret)"><i class="bi bi-copy"></i> Копировать</button></div>
        <div class="secret-env-example"><small>РЕКОМЕНДУЕМ</small><code>MATERCMS_API_TOKEN={{ apiSecret }}</code><button type="button" @click="copy('MATERCMS_API_TOKEN='+apiSecret)" title="Копировать"><i class="bi bi-copy"></i></button></div>
        <div class="secret-modal-warning"><i class="bi bi-eye-slash"></i><p><b>Не коммитьте секрет в Git и не вставляйте его в frontend.</b> Используйте <code>.env</code>, секреты хостинга, serverless environment variables или защищённое хранилище.</p></div>
        <div class="modal-actions"><button class="button primary wide" type="button" @click="closeApiSecret"><i class="bi bi-check2"></i> Я сохранил токен</button></div>
      </div>
      <form v-if="modal==='database-switch'" @submit.prevent="changeDatabase" class="database-switch-form">
        <div class="database-switch-intro"><span><i class="bi bi-database-gear"></i></span><div><strong>Перенос без потери данных</strong><p>Новая база должна быть пустой. MaterCMS создаст схему, перенесёт все таблицы, сверит количество записей и только затем переключит конфиг.</p></div></div>
        <div class="database-switch-grid">
          <button v-for="(meta,key) in databaseAvailability" :key="key" type="button" class="database-switch-driver" :class="{active:databaseDialog.driver===key,disabled:!meta.available}" :disabled="!meta.available" @click="selectDatabaseDriver(key)">
            <span class="database-switch-driver-icon"><i class="bi" :class="key==='sqlite' ? 'bi-database' : key==='mysql' ? 'bi-hdd-stack' : 'bi-boxes'"></i></span>
            <span><strong>{{ meta.label }}</strong><small>{{ meta.available ? meta.extension + ' доступен' : 'Нет ' + meta.extension }}</small></span>
            <i class="bi" :class="databaseDialog.driver===key ? 'bi-check-circle' : 'bi-circle'"></i>
          </button>
        </div>
        <template v-if="databaseDialog.driver!=='sqlite'">
          <div class="database-switch-mode">
            <button type="button" :class="{active:databaseDialog.input_mode==='url'}" @click="databaseDialog.input_mode='url'"><i class="bi bi-link-45deg"></i> URL</button>
            <button type="button" :class="{active:databaseDialog.input_mode==='fields'}" @click="databaseDialog.input_mode='fields'"><i class="bi bi-ui-checks-grid"></i> Поля</button>
          </div>
          <div v-if="databaseDialog.input_mode==='url'" class="database-switch-url">
            <label>URL подключения<input v-model.trim="databaseDialog.url" autocomplete="off" :placeholder="databaseDialog.driver==='mysql' ? 'mysql://user:password@host:3306/database' : 'postgresql://user:password@host:5432/database?sslmode=require'"></label>
            <small>Удобный вариант для Railway, Render, Neon, Supabase и других облачных баз.</small>
          </div>
          <div v-else class="database-switch-fields installer-field-grid">
            <label>Хост<input v-model.trim="databaseDialog.host" autocomplete="off"></label>
            <label>Порт<input v-model.trim="databaseDialog.port" inputmode="numeric" :placeholder="databaseDialog.driver==='mysql' ? '3306' : '5432'"></label>
            <label class="span-2">База данных<input v-model.trim="databaseDialog.database" placeholder="matercms"></label>
            <label>Пользователь<input v-model="databaseDialog.username" autocomplete="username"></label>
            <label>Пароль<div class="password-control"><input data-password-input v-model="databaseDialog.password" type="password" autocomplete="new-password"><button class="password-toggle" data-password-toggle type="button" aria-label="Показать пароль" aria-pressed="false" title="Показать пароль"><i class="bi bi-eye"></i></button></div></label>
            <label v-if="databaseDialog.driver==='pgsql'" class="span-2">SSL PostgreSQL<select v-model="databaseDialog.sslmode"><option value="prefer">Prefer</option><option value="require">Require</option><option value="disable">Disable</option></select></label>
          </div>
        </template>
        <div v-else class="database-switch-sqlite"><i class="bi bi-lightning-charge"></i><div><strong>SQLite без дополнительной настройки</strong><p>MaterCMS использует локальный файл базы в закрытом каталоге <code>cms/data</code>.</p></div></div>
        <div class="database-switch-warning"><i class="bi bi-exclamation-triangle"></i><p>Не закрывайте вкладку во время переноса. Исходная база не изменяется и остаётся резервной копией после успешного переключения.</p></div>
        <div class="modal-actions"><button class="button ghost" type="button" @click="modal=null" :disabled="databaseSwitching">Отмена</button><button class="button primary" type="submit" :disabled="databaseSwitching || !databaseCanSubmit"><span v-if="databaseSwitching" class="spinner tiny"></span><i v-else class="bi bi-arrow-left-right"></i>{{ databaseSwitching ? 'Переносим…' : 'Проверить и перенести' }}</button></div>
      </form>
      <form v-else-if="modal==='create-folder'" @submit.prevent="createFolder" class="modal-form"><label>Название папки<input v-model.trim="dialog.name" autofocus placeholder="Например, Главная"></label><div class="modal-actions"><button class="button ghost" type="button" @click="modal=null">Отмена</button><button class="button primary" :disabled="busy" type="submit">Создать</button></div></form>
      <form v-else-if="modal==='create-document'" @submit.prevent="createDocument" class="modal-form create-document-form">
        <label>Название раздела<input v-model.trim="dialog.name" autofocus placeholder="Например, Главный экран"></label>
        <div class="mode-picker-block">
          <span class="mode-picker-label">Как хранить контент?</span>
          <div class="mode-picker">
            <button type="button" class="mode-option" :class="{active:dialog.mode==='single'}" @click="dialog.mode='single'">
              <span class="mode-symbol object">{ }</span><div><strong>Одиночный</strong><small>Один набор полей</small></div><i></i>
            </button>
            <button type="button" class="mode-option" :class="{active:dialog.mode==='multiple'}" @click="dialog.mode='multiple'">
              <span class="mode-symbol array">[ ]</span><div><strong>Много</strong><small>Несколько одинаковых записей</small></div><i></i>
            </button>
          </div>
          <div class="mode-preview"><span>API вернёт</span><code>{{ dialog.mode==='multiple' ? '[ { ... }, { ... } ]' : '{ ... }' }}</code></div>
        </div>
        <div class="modal-actions"><button class="button ghost" type="button" @click="modal=null">Отмена</button><button class="button primary" :disabled="busy" type="submit">Создать</button></div>
      </form>
      <form v-else-if="modal==='create-data'" @submit.prevent="createDataSet" class="modal-form create-document-form">
        <label>Название<input v-model.trim="dataDialog.name" autofocus placeholder="Например, Товары"></label>
        <label>Slug<input v-model.trim="dataDialog.slug" placeholder="products"><small class="form-hint">Используется в API. Если оставить пустым, MaterCMS создаст автоматически.</small></label>
        <div class="mode-picker-block"><span class="mode-picker-label">Тип данных</span><div class="mode-picker">
          <button type="button" class="mode-option" :class="{active:dataDialog.mode==='single'}" @click="dataDialog.mode='single'"><span class="mode-symbol object">{ }</span><div><strong>Single</strong><small>Один объект</small></div><i></i></button>
          <button type="button" class="mode-option" :class="{active:dataDialog.mode==='multiple'}" @click="dataDialog.mode='multiple'"><span class="mode-symbol array">[ ]</span><div><strong>Multiple</strong><small>Список объектов</small></div><i></i></button>
        </div><div class="mode-preview"><span>API вернёт</span><code>{{ dataDialog.mode==='multiple' ? '[ { ... }, { ... } ]' : '{ ... }' }}</code></div></div>
        <div class="modal-actions"><button class="button ghost" type="button" @click="modal=null">Отмена</button><button class="button primary" :disabled="busy" type="submit">Создать данные</button></div>
      </form>
      <form v-else-if="modal==='create-form'" @submit.prevent="createForm" class="modal-form"><label>Название формы<input v-model.trim="dialog.name" autofocus placeholder="Например, Обратная связь"></label><div class="modal-actions"><button class="button ghost" type="button" @click="modal=null">Отмена</button><button class="button primary" :disabled="busy" type="submit">Создать форму</button></div></form>
      <form v-else-if="modal==='create-project'" @submit.prevent="createProject" class="modal-form"><label>Название проекта<input v-model.trim="projectDialog.name" autofocus placeholder="Например, Корпоративный сайт"></label><p class="form-hint">Проект получит отдельный контент, данные, формы, файлы, языки и API.</p><div class="modal-actions"><button class="button ghost" type="button" @click="modal=null">Отмена</button><button class="button primary" :disabled="busy" type="submit">Создать проект</button></div></form>
      <form v-else-if="modal==='rename-project'" @submit.prevent="saveProjectName" class="modal-form"><label>Название проекта<input v-model.trim="projectDialog.name" autofocus></label><div class="modal-actions"><button class="button ghost" type="button" @click="modal=null">Отмена</button><button class="button primary" :disabled="busy" type="submit">Сохранить</button></div></form>
      <form v-else-if="modal==='create-user' || modal==='edit-user'" @submit.prevent="saveUser" class="modal-form user-access-form user-identity-form">
        <div class="user-access-fields">
          <label>Имя<input v-model.trim="userDialog.name" autofocus type="text" placeholder="Имя пользователя"></label>
          <label>Email<input v-model.trim="userDialog.email" type="email" placeholder="name@example.com"></label>
          <label class="span-2">{{ userDialog.id ? 'Новый пароль (необязательно)' : 'Пароль' }}<div class="password-control"><input data-password-input v-model="userDialog.password" type="password" :required="!userDialog.id" minlength="8" placeholder="Минимум 8 символов"><button class="password-toggle" data-password-toggle type="button" aria-label="Показать пароль" aria-pressed="false" title="Показать пароль"><i class="bi bi-eye"></i></button></div></label>
        </div>
        <div class="user-project-management-note"><span><i class="bi bi-layers"></i></span><div><strong>Доступ настраивается в проектах</strong><p>После создания пользователя откройте «Настройки → Проекты», выберите нужный проект и назначьте роль и права.</p></div></div>
        <div class="modal-actions user-access-modal-actions"><button class="button ghost" type="button" @click="modal=null">Отмена</button><button class="button primary" :disabled="busy" type="submit">{{ userDialog.id ? 'Сохранить' : 'Создать пользователя' }}</button></div>
      </form>
      <div v-else-if="modal==='link-resource'" class="content-link-picker">
        <div class="content-link-intro"><span :class="contentLinkDialog.type"><i class="bi" :class="contentLinkDialog.type==='form' ? 'bi-ui-checks-grid' : 'bi-database'"></i></span><div><strong>{{ contentLinkDialog.type==='form' ? 'Формы' : 'Данные' }}</strong><p>Выберите уже созданные ресурсы. MaterCMS добавит только ссылку — источник истины останется в {{ contentLinkDialog.type==='form' ? '«Формах»' : '«Данных»' }}.</p></div></div>
        <div v-if="contentLinkDialog.loading" class="content-link-loading"><span class="spinner"></span><p>Загружаем ресурсы…</p></div>
        <div v-else-if="!contentLinkDialog.items.length" class="content-link-empty"><i class="bi" :class="contentLinkDialog.type==='form' ? 'bi-ui-checks-grid' : 'bi-database'"></i><div><strong>Пока нечего добавлять</strong><p>Сначала создайте {{ contentLinkDialog.type==='form' ? 'форму' : 'данные' }} в соответствующем разделе MaterCMS.</p></div></div>
        <div v-else class="content-link-list">
          <label v-for="item in contentLinkDialog.items" :key="item.id" class="content-link-option" :class="{active:contentLinkDialog.selected.includes(Number(item.id))}">
            <input type="checkbox" :checked="contentLinkDialog.selected.includes(Number(item.id))" @change="toggleContentLinkSelection(item.id)">
            <span class="content-link-check"><i class="bi bi-check2"></i></span>
            <span class="content-link-option-icon" :class="contentLinkDialog.type"><i class="bi" :class="contentLinkDialog.type==='form' ? 'bi-ui-checks-grid' : 'bi-database'"></i></span>
            <span class="content-link-option-copy"><strong>{{ item.name }}</strong><small v-if="contentLinkDialog.type==='data'">{{ item.mode==='multiple' ? 'Multiple · ' + (item.item_count || 0) + ' записей' : 'Single · один объект' }}</small><small v-else>{{ item.submission_count || 0 }} {{ plural(item.submission_count || 0,'заявка','заявки','заявок') }}</small></span>
            <span v-if="item.api_enabled===false" class="content-link-api-state">API выкл.</span>
          </label>
        </div>
        <div class="content-link-note"><i class="bi bi-arrow-repeat"></i><p>Это не копия. Название, содержимое, API и изменения всегда берутся из исходного ресурса.</p></div>
        <div class="modal-actions"><button class="button ghost" type="button" @click="modal=null" :disabled="contentLinkDialog.saving">Отмена</button><button class="button primary" type="button" @click="saveContentLinks" :disabled="contentLinkDialog.loading || contentLinkDialog.saving"><span v-if="contentLinkDialog.saving" class="spinner tiny"></span><i v-else class="bi bi-link-45deg"></i>{{ contentLinkDialog.saving ? 'Сохраняем…' : 'Сохранить' }}</button></div>
      </div>
      <div v-else-if="modal==='content-properties' && contentProperties" class="content-properties-modal">
        <div class="content-properties-hero"><span :class="contentProperties.type"><i class="bi" :class="contentProperties.type==='folder'?'bi-folder2':'bi-file-earmark-text'"></i></span><div><strong>{{ contentProperties.name }}</strong><small>{{ contentProperties.typeLabel }}</small></div></div>
        <dl class="content-properties-grid"><div><dt>Расположение</dt><dd>{{ contentProperties.path }}</dd></div><div><dt>Содержимое</dt><dd>{{ contentProperties.details }}</dd></div><div><dt>Создано</dt><dd>{{ formatDateTime(contentProperties.created_at) }}</dd></div><div><dt>Изменено</dt><dd>{{ formatDateTime(contentProperties.updated_at || contentProperties.created_at) }}</dd></div><div v-if="contentProperties.type==='document' || contentProperties.type==='resource-link'"><dt>Slug</dt><dd><code>{{ contentProperties.slug }}</code></dd></div><div v-if="contentProperties.type==='document'"><dt>Режим</dt><dd>{{ contentProperties.mode==='multiple'?'Multiple / массив':'Single / объект' }}</dd></div><div v-if="contentProperties.type==='resource-link'"><dt>Источник</dt><dd>{{ contentProperties.resource_type==='form' ? 'Формы' : 'Данные' }} · #{{ contentProperties.resource_id }}</dd></div><div v-if="contentProperties.type==='resource-link'"><dt>API</dt><dd>{{ contentProperties.api_enabled ? 'Включён' : 'Выключен' }}</dd></div></dl>
        <div class="modal-actions"><button class="button soft" type="button" @click="copy(contentProperties.path)"><i class="bi bi-copy"></i> Копировать путь</button><button class="button primary" type="button" @click="modal=null">Готово</button></div>
      </div>
      <form v-else-if="modal==='rename'" @submit.prevent="renameCurrent" class="modal-form"><label>Новое название<input v-model.trim="dialog.name" autofocus></label><div class="modal-actions"><button class="button ghost" type="button" @click="modal=null">Отмена</button><button class="button primary" :disabled="busy" type="submit">Переименовать</button></div></form>
    </section>
  </div>

  <div v-if="confirmDialog.open" class="modal-backdrop confirm-backdrop" @mousedown.self="closeConfirm(false)">
    <section class="modal-card confirm-modal" :class="'tone-'+confirmDialog.tone" role="dialog" aria-modal="true">
      <div class="confirm-icon"><i class="bi" :class="confirmDialog.icon"></i></div>
      <div class="confirm-copy"><small class="eyebrow">ПОДТВЕРЖДЕНИЕ</small><h2>{{ confirmDialog.title }}</h2><p>{{ confirmDialog.message }}</p></div>
      <div class="modal-actions confirm-actions">
        <button class="button ghost" type="button" @click="closeConfirm(false)">{{ confirmDialog.cancelLabel }}</button>
        <button class="button confirm-primary" :class="confirmDialog.tone==='primary' ? 'primary' : 'danger-button'" type="button" @click="closeConfirm(true)"><i class="bi" :class="confirmDialog.tone==='primary' ? (confirmDialog.confirmLabel==='Понятно' ? 'bi-check2' : 'bi-arrow-counterclockwise') : 'bi-trash3'"></i> {{ confirmDialog.confirmLabel }}</button>
      </div>
    </section>
  </div>

  <div v-if="contextMenu" class="context-menu smart-context-menu" :style="contextMenuStyle" @click.stop @contextmenu.prevent.stop role="menu">
    <div class="smart-context-head">
      <small>{{ smartContextMenuEyebrow }}</small>
      <strong>{{ smartContextMenuTitle }}</strong>
    </div>
    <div class="smart-context-actions">
      <template v-for="action in smartContextActions" :key="action.id">
        <div v-if="action.separatorBefore" class="context-separator"></div>
        <button type="button" :class="{danger:action.danger}" :disabled="action.disabled" @click="runContextAction(action)" role="menuitem">
          <i class="bi" :class="action.icon"></i><span>{{ action.label }}</span><kbd v-if="action.shortcut" class="context-shortcut">{{ action.shortcut }}</kbd><i v-else-if="!action.disabled" class="bi bi-chevron-right context-action-chevron"></i>
        </button>
      </template>
    </div>
  </div>

  <div class="toast-stack"><div v-for="toast in toasts" :key="toast.id" class="toast" :class="toast.type">{{ toast.message }}</div></div>
</div>
<script>window.__MATERCMS_CONFIG__ = <?=json_encode($config, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)?>;</script>
<script type="module" src="<?=e(asset_url('assets/app.js'))?>"></script>
</body>
</html>
