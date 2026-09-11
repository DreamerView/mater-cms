<?php
final class Database
{
    private const SCHEMA_VERSION = '2026.09.10.20';
    private static ?PDO $pdo = null;
    private static ?array $runtimeConfig = null;
    private static bool $migrationChecked = false;

    public static function configurationPath(): string
    {
        return __DIR__ . '/../data/database.php';
    }

    public static function legacySqlitePath(): string
    {
        $config = require __DIR__ . '/../config.php';
        return (string)($config['db_path'] ?? (__DIR__ . '/../data/matercms.sqlite'));
    }

    public static function hasConfiguration(): bool
    {
        return is_file(self::configurationPath());
    }

    public static function hasLegacySqlite(): bool
    {
        return is_file(self::legacySqlitePath());
    }

    public static function driverAvailability(): array
    {
        $available = class_exists(PDO::class) ? PDO::getAvailableDrivers() : [];
        $has = static fn(string $driver): bool => in_array($driver, $available, true);
        return [
            'sqlite' => [
                'driver' => 'sqlite',
                'label' => 'SQLite',
                'available' => $has('sqlite'),
                'extension' => 'pdo_sqlite',
                'description' => 'Без сервера и настроек. Лучший вариант для небольших и средних сайтов.',
            ],
            'mysql' => [
                'driver' => 'mysql',
                'label' => 'MySQL',
                'available' => $has('mysql'),
                'extension' => 'pdo_mysql',
                'description' => 'Для обычного хостинга, больших проектов и внешней базы данных.',
            ],
            'pgsql' => [
                'driver' => 'pgsql',
                'label' => 'PostgreSQL',
                'available' => $has('pgsql'),
                'extension' => 'pdo_pgsql',
                'description' => 'Надёжная серверная СУБД для проектов с PostgreSQL-инфраструктурой.',
            ],
        ];
    }

    public static function configuredDatabase(): array
    {
        if (self::$runtimeConfig !== null) return self::$runtimeConfig;

        $path = self::configurationPath();
        if (is_file($path)) {
            $loaded = require $path;
            if (is_array($loaded) && isset($loaded['driver'])) {
                return self::$runtimeConfig = self::normalizeConfiguration($loaded);
            }
        }

        // Backward compatibility: installations created before multi-database support
        // keep using the existing SQLite file without any manual migration.
        return self::$runtimeConfig = [
            'driver' => 'sqlite',
            'path' => self::legacySqlitePath(),
            'source' => 'legacy',
        ];
    }

    public static function normalizeConfiguration(array $database): array
    {
        $driver = strtolower((string)($database['driver'] ?? 'sqlite'));
        if ($driver === 'postgres' || $driver === 'postgresql') $driver = 'pgsql';
        if (!in_array($driver, ['sqlite', 'mysql', 'pgsql'], true)) $driver = 'sqlite';

        if ($driver === 'sqlite') {
            return [
                'driver' => 'sqlite',
                'path' => (string)($database['path'] ?? self::legacySqlitePath()),
                'source' => (string)($database['source'] ?? 'config'),
            ];
        }

        $portDefault = $driver === 'mysql' ? 3306 : 5432;
        return [
            'driver' => $driver,
            'host' => trim((string)($database['host'] ?? '127.0.0.1')) ?: '127.0.0.1',
            'port' => max(1, min(65535, (int)($database['port'] ?? $portDefault))),
            'database' => trim((string)($database['database'] ?? '')),
            'username' => (string)($database['username'] ?? ''),
            'password' => (string)($database['password'] ?? ''),
            'charset' => $driver === 'mysql' ? (string)($database['charset'] ?? 'utf8mb4') : 'UTF8',
            'sslmode' => $driver === 'pgsql' ? (string)($database['sslmode'] ?? 'prefer') : '',
            'source' => (string)($database['source'] ?? 'config'),
        ];
    }

    public static function configurationFromInput(array $input): array
    {
        $driver = strtolower(trim((string)($input['db_driver'] ?? 'sqlite')));
        if ($driver === 'postgres' || $driver === 'postgresql') $driver = 'pgsql';
        if (!in_array($driver, ['sqlite', 'mysql', 'pgsql'], true)) {
            throw new InvalidArgumentException('Неизвестный тип базы данных.');
        }

        $availability = self::driverAvailability();
        if (empty($availability[$driver]['available'])) {
            $extension = (string)($availability[$driver]['extension'] ?? 'PDO');
            throw new RuntimeException("На сервере не доступно расширение {$extension}.");
        }

        if ($driver === 'sqlite') {
            $path = self::legacySqlitePath();
            // When returning from MySQL/PostgreSQL to SQLite, an older SQLite
            // source file can still exist as a backup. Never overwrite/merge it:
            // create a fresh target file and keep the old database untouched.
            try {
                $current = self::configuredDatabase();
                if (($current['driver'] ?? 'sqlite') !== 'sqlite' && is_file($path)) {
                    $dir = dirname($path);
                    $base = pathinfo($path, PATHINFO_FILENAME) ?: 'matercms';
                    $path = $dir . '/' . $base . '-migrated-' . date('Ymd-His') . '.sqlite';
                }
            } catch (Throwable) {}
            return self::normalizeConfiguration([
                'driver' => 'sqlite',
                'path' => $path,
                'source' => 'config',
            ]);
        }

        $mode = (string)($input['db_input_mode'] ?? 'url') === 'fields' ? 'fields' : 'url';
        if ($mode === 'url') {
            $url = trim((string)($input['db_url'] ?? ''));
            if ($url === '') throw new InvalidArgumentException('Укажите URL подключения.');
            $parts = parse_url($url);
            if ($parts === false || empty($parts['scheme']) || empty($parts['host'])) {
                throw new InvalidArgumentException('Не удалось разобрать URL подключения.');
            }
            $scheme = strtolower((string)$parts['scheme']);
            $urlDriver = in_array($scheme, ['postgres', 'postgresql', 'pgsql'], true) ? 'pgsql' : ($scheme === 'mysql' ? 'mysql' : '');
            if ($urlDriver !== $driver) throw new InvalidArgumentException('URL не соответствует выбранной СУБД.');
            $query = [];
            if (!empty($parts['query'])) parse_str((string)$parts['query'], $query);
            $database = ltrim((string)($parts['path'] ?? ''), '/');
            if ($database === '') throw new InvalidArgumentException('В URL не указано имя базы данных.');
            return self::normalizeConfiguration([
                'driver' => $driver,
                'host' => (string)$parts['host'],
                'port' => (int)($parts['port'] ?? ($driver === 'mysql' ? 3306 : 5432)),
                'database' => rawurldecode($database),
                'username' => rawurldecode((string)($parts['user'] ?? '')),
                'password' => rawurldecode((string)($parts['pass'] ?? '')),
                'charset' => (string)($query['charset'] ?? 'utf8mb4'),
                'sslmode' => (string)($query['sslmode'] ?? 'prefer'),
                'source' => 'config',
            ]);
        }

        $database = trim((string)($input['db_name'] ?? ''));
        $username = (string)($input['db_user'] ?? '');
        if ($database === '') throw new InvalidArgumentException('Укажите имя базы данных.');
        if ($username === '') throw new InvalidArgumentException('Укажите пользователя базы данных.');

        $defaultPort = $driver === 'mysql' ? 3306 : 5432;
        $portInput = trim((string)($input['db_port'] ?? ''));
        return self::normalizeConfiguration([
            'driver' => $driver,
            'host' => trim((string)($input['db_host'] ?? '127.0.0.1')) ?: '127.0.0.1',
            'port' => $portInput === '' ? $defaultPort : (int)$portInput,
            'database' => $database,
            'username' => $username,
            'password' => (string)($input['db_password'] ?? ''),
            'charset' => 'utf8mb4',
            'sslmode' => (string)($input['db_sslmode'] ?? 'prefer'),
            'source' => 'config',
        ]);
    }

    public static function saveConfiguration(array $database): void
    {
        $database = self::normalizeConfiguration($database);
        $dir = dirname(self::configurationPath());
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new RuntimeException('Не удалось создать каталог data.');
        }
        $payload = "<?php\n// Generated by MaterCMS installer. Keep this file outside public access.\nreturn "
            . var_export(array_diff_key($database, ['source' => true]), true)
            . ";\n";
        $tmp = self::configurationPath() . '.tmp';
        if (file_put_contents($tmp, $payload, LOCK_EX) === false) {
            throw new RuntimeException('Не удалось записать конфигурацию базы данных. Проверьте права на cms/data.');
        }
        @chmod($tmp, 0640);
        if (!@rename($tmp, self::configurationPath())) {
            @unlink($tmp);
            throw new RuntimeException('Не удалось сохранить конфигурацию базы данных.');
        }
        self::$runtimeConfig = $database;
        self::$pdo = null;
    }

    public static function resetConfiguration(): void
    {
        if (is_file(self::configurationPath())) @unlink(self::configurationPath());
        self::$runtimeConfig = null;
        self::$pdo = null;
    }

    public static function testConfiguration(array $database): array
    {
        $pdo = self::connect(self::normalizeConfiguration($database));
        $driver = (string)$pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        $version = '';
        try { $version = (string)$pdo->getAttribute(PDO::ATTR_SERVER_VERSION); } catch (Throwable) {}
        $pdo = null;
        return ['ok' => true, 'driver' => $driver, 'version' => $version];
    }


    /**
     * Switch MaterCMS to another database without losing CMS data.
     *
     * The target database must either be the current database (credential/config
     * refresh) or an empty database. We create the MaterCMS schema first, copy all
     * rows while preserving IDs, verify the copied row counts, and only then
     * replace cms/data/database.php. If anything fails, the current configuration
     * remains untouched.
     */
    public static function migrateToConfiguration(array $database): array
    {
        $targetConfig = self::normalizeConfiguration($database);
        $targetSqliteExisted = ($targetConfig['driver'] ?? '') === 'sqlite'
            && is_file((string)($targetConfig['path'] ?? ''));
        try {
            self::testConfiguration($targetConfig);
        } catch (Throwable $e) {
            if (($targetConfig['driver'] ?? '') === 'sqlite' && !$targetSqliteExisted) {
                $path = (string)($targetConfig['path'] ?? '');
                if ($path !== '' && is_file($path)) @unlink($path);
            }
            throw $e;
        }

        $sourceConfig = self::configuredDatabase();
        if (self::sameDatabaseIdentity($sourceConfig, $targetConfig)) {
            self::saveConfiguration($targetConfig);
            return self::publicInfo();
        }

        $source = self::connection();
        $target = self::connect($targetConfig);
        self::createSchema($target);

        $tables = self::migrationTables();

        // Never merge two MaterCMS installations. The destination must be empty.
        foreach ($tables as $table => $columns) {
            if (!self::tableExists($target, $table)) continue;
            $count = (int)$target->query('SELECT COUNT(*) FROM ' . self::quoteIdentifier($target, $table))->fetchColumn();
            if ($count > 0) {
                throw new RuntimeException('Целевая база уже содержит данные MaterCMS. Выберите пустую базу данных.');
            }
        }

        $target->beginTransaction();
        try {
            foreach ($tables as $table => $columns) {
                if (!self::tableExists($source, $table)) continue;

                $tableQSource = self::quoteIdentifier($source, $table);
                $tableQTarget = self::quoteIdentifier($target, $table);
                $quotedSource = array_map(static fn(string $column): string => self::quoteIdentifier($source, $column), $columns);
                $quotedTarget = array_map(static fn(string $column): string => self::quoteIdentifier($target, $column), $columns);

                $order = in_array('id', $columns, true) ? ' ORDER BY ' . self::quoteIdentifier($source, 'id') : '';
                $rows = $source->query('SELECT ' . implode(',', $quotedSource) . ' FROM ' . $tableQSource . $order)->fetchAll(PDO::FETCH_ASSOC);
                if (!$rows) continue;

                $placeholders = implode(',', array_fill(0, count($columns), '?'));
                $insert = $target->prepare(
                    'INSERT INTO ' . $tableQTarget . ' (' . implode(',', $quotedTarget) . ') VALUES (' . $placeholders . ')'
                );

                foreach ($rows as $row) {
                    $values = [];
                    foreach ($columns as $column) $values[] = $row[$column] ?? null;
                    $insert->execute($values);
                }
            }

            // Verify every table before making the new database active.
            foreach ($tables as $table => $columns) {
                if (!self::tableExists($source, $table) || !self::tableExists($target, $table)) continue;
                $sourceCount = (int)$source->query('SELECT COUNT(*) FROM ' . self::quoteIdentifier($source, $table))->fetchColumn();
                $targetCount = (int)$target->query('SELECT COUNT(*) FROM ' . self::quoteIdentifier($target, $table))->fetchColumn();
                if ($sourceCount !== $targetCount) {
                    throw new RuntimeException("Не удалось проверить перенос таблицы {$table}.");
                }
            }

            if (self::driver($target) === 'pgsql') {
                foreach ($tables as $table => $columns) {
                    if (!in_array('id', $columns, true)) continue;
                    $tableQ = self::quoteIdentifier($target, $table);
                    $max = (int)$target->query('SELECT COALESCE(MAX(id),0) FROM ' . $tableQ)->fetchColumn();
                    $seqStmt = $target->prepare("SELECT pg_get_serial_sequence(?, 'id')");
                    $seqStmt->execute([$table]);
                    $sequence = (string)($seqStmt->fetchColumn() ?: '');
                    if ($sequence !== '') {
                        $set = $target->prepare('SELECT setval(CAST(? AS regclass), ?, ?)');
                        $set->execute([$sequence, max(1, $max), $max > 0]);
                    }
                }
            }

            $target->commit();
        } catch (Throwable $e) {
            if ($target->inTransaction()) $target->rollBack();
            if (($targetConfig['driver'] ?? '') === 'sqlite' && !$targetSqliteExisted) {
                $path = (string)($targetConfig['path'] ?? '');
                $target = null;
                if ($path !== '' && is_file($path)) @unlink($path);
            }
            throw $e;
        }

        // The config is swapped atomically only after a verified copy.
        self::saveConfiguration($targetConfig);
        return self::publicInfo();
    }

    private static function sameDatabaseIdentity(array $left, array $right): bool
    {
        $left = self::normalizeConfiguration($left);
        $right = self::normalizeConfiguration($right);
        if ($left['driver'] !== $right['driver']) return false;
        if ($left['driver'] === 'sqlite') {
            $leftPath = realpath((string)$left['path']);
            $rightPath = realpath((string)$right['path']);
            if ($leftPath !== false && $rightPath !== false) return $leftPath === $rightPath;
            return (string)$left['path'] === (string)$right['path'];
        }
        return mb_strtolower((string)$left['host']) === mb_strtolower((string)$right['host'])
            && (int)$left['port'] === (int)$right['port']
            && (string)$left['database'] === (string)$right['database'];
    }

    private static function migrationTables(): array
    {
        return [
            'users' => ['id','name','email','password_hash','system_role','created_at'],
            'folders' => ['id','parent_id','name','slug','sort_order','api_enabled','api_tree_visible','api_tree_depth','api_tree_include_data','api_tree_cache_ttl','created_at','updated_at'],
            'documents' => ['id','folder_id','name','slug','mode','api_enabled','api_tree_visible','schema_json','data_json','created_at','updated_at'],
            'revisions' => ['id','document_id','language','schema_json','data_json','created_at'],
            'document_translations' => ['document_id','language','data_json','updated_at'],
            'forms' => ['id','project_id','name','slug','api_enabled','schema_json','success_message','created_at','updated_at'],
            'form_submissions' => ['id','form_id','data_json','status','created_at'],
            'cms_settings' => ['key','value_json'],
            'projects' => ['id','name','slug','root_folder_id','created_by','created_at','updated_at'],
            'project_users' => ['project_id','user_id','role','permissions_json','created_at'],
            'project_settings' => ['project_id','key','value_json'],
            'data_sets' => ['id','project_id','name','slug','mode','api_enabled','schema_json','data_json','created_at','updated_at'],
            'content_links' => ['id','project_id','folder_id','resource_type','resource_id','sort_order','created_at'],
            'content_trash' => ['id','project_id','item_type','item_id','original_parent_id','original_name','deleted_by','deleted_at'],
        ];
    }

    public static function connection(): PDO
    {
        if (self::$pdo instanceof PDO) return self::$pdo;
        self::$pdo = self::connect(self::configuredDatabase());
        self::ensureSchemaCurrent(self::$pdo);
        return self::$pdo;
    }

    /**
     * Run expensive introspection/migrations only when the code schema version
     * changes. Normal requests pay one small settings lookup instead of dozens
     * of PRAGMA/information_schema queries and ALTER checks.
     */
    private static function ensureSchemaCurrent(PDO $pdo): void
    {
        if (self::$migrationChecked) return;
        self::$migrationChecked = true;
        if (!self::tableExists($pdo, 'users')) return;

        $current = '';
        try {
            if (self::tableExists($pdo, 'cms_settings')) {
                $key = self::quoteIdentifier($pdo, 'key');
                $stmt = $pdo->prepare("SELECT value_json FROM cms_settings WHERE $key=? LIMIT 1");
                $stmt->execute(['schema_version']);
                $raw = $stmt->fetchColumn();
                $decoded = is_string($raw) ? json_decode($raw, true) : null;
                if (is_array($decoded)) $current = (string)($decoded['version'] ?? '');
            }
        } catch (Throwable) {
            $current = '';
        }

        if ($current === self::SCHEMA_VERSION) return;
        self::migrate($pdo);
        if (self::tableExists($pdo, 'cms_settings')) {
            self::upsert($pdo, 'cms_settings', ['key'=>'schema_version'], [
                'value_json'=>json_encode(['version'=>self::SCHEMA_VERSION,'updated_at'=>gmdate('c')], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)
            ]);
        }
    }

    private static function connect(array $database): PDO
    {
        $driver = (string)$database['driver'];
        $options = [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ];

        if ($driver === 'sqlite') {
            $path = (string)$database['path'];
            $dir = dirname($path);
            if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) throw new RuntimeException('Не удалось создать каталог SQLite.');
            $pdo = new PDO('sqlite:' . $path, null, null, $options);
            $pdo->exec('PRAGMA foreign_keys = ON');
            $pdo->exec('PRAGMA journal_mode = WAL');
            $pdo->exec('PRAGMA synchronous = NORMAL');
            $pdo->exec('PRAGMA busy_timeout = 5000');
            return $pdo;
        }

        if ($driver === 'mysql') {
            $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=%s', $database['host'], $database['port'], $database['database'], $database['charset'] ?: 'utf8mb4');
            $pdo = new PDO($dsn, (string)$database['username'], (string)$database['password'], $options);
            $pdo->exec("SET time_zone = '+00:00'");
            return $pdo;
        }

        $dsn = sprintf('pgsql:host=%s;port=%d;dbname=%s', $database['host'], $database['port'], $database['database']);
        if (!empty($database['sslmode'])) $dsn .= ';sslmode=' . preg_replace('/[^a-z-]/i', '', (string)$database['sslmode']);
        $pdo = new PDO($dsn, (string)$database['username'], (string)$database['password'], $options);
        $pdo->exec("SET TIME ZONE 'UTC'");
        $pdo->exec("SET client_encoding TO 'UTF8'");
        return $pdo;
    }

    public static function driver(?PDO $pdo = null): string
    {
        $pdo ??= self::connection();
        return (string)$pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
    }

    public static function lastInsertId(PDO $pdo): int
    {
        if (self::driver($pdo) === 'pgsql') {
            return (int)$pdo->query('SELECT LASTVAL()')->fetchColumn();
        }
        return (int)$pdo->lastInsertId();
    }

    public static function tableExists(PDO $pdo, string $table): bool
    {
        $driver = self::driver($pdo);
        if ($driver === 'sqlite') {
            $stmt = $pdo->prepare("SELECT name FROM sqlite_master WHERE type='table' AND name=? LIMIT 1");
            $stmt->execute([$table]);
            return (bool)$stmt->fetchColumn();
        }
        if ($driver === 'mysql') {
            $stmt = $pdo->prepare('SELECT 1 FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=? LIMIT 1');
            $stmt->execute([$table]);
            return (bool)$stmt->fetchColumn();
        }
        $stmt = $pdo->prepare('SELECT 1 FROM information_schema.tables WHERE table_schema=current_schema() AND table_name=? LIMIT 1');
        $stmt->execute([$table]);
        return (bool)$stmt->fetchColumn();
    }

    public static function columnExists(PDO $pdo, string $table, string $column): bool
    {
        $driver = self::driver($pdo);
        if ($driver === 'sqlite') {
            $rows = $pdo->query('PRAGMA table_info(' . self::quoteIdentifier($pdo, $table) . ')')->fetchAll();
            foreach ($rows as $row) if ((string)($row['name'] ?? '') === $column) return true;
            return false;
        }
        if ($driver === 'mysql') {
            $stmt = $pdo->prepare('SELECT 1 FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name=? AND column_name=? LIMIT 1');
        } else {
            $stmt = $pdo->prepare('SELECT 1 FROM information_schema.columns WHERE table_schema=current_schema() AND table_name=? AND column_name=? LIMIT 1');
        }
        $stmt->execute([$table, $column]);
        return (bool)$stmt->fetchColumn();
    }

    public static function quoteIdentifier(PDO $pdo, string $identifier): string
    {
        if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $identifier)) throw new InvalidArgumentException('Invalid identifier');
        return self::driver($pdo) === 'mysql' ? '`' . $identifier . '`' : '"' . $identifier . '"';
    }

    public static function upsert(PDO $pdo, string $table, array $keys, array $values): void
    {
        $data = array_merge($keys, $values);
        $columns = array_keys($data);
        $quotedColumns = array_map(static fn($column) => self::quoteIdentifier($pdo, (string)$column), $columns);
        $quotedKeys = array_map(static fn($column) => self::quoteIdentifier($pdo, (string)$column), array_keys($keys));
        $driver = self::driver($pdo);
        $tableQ = self::quoteIdentifier($pdo, $table);
        $placeholders = implode(',', array_fill(0, count($columns), '?'));

        if ($driver === 'mysql') {
            $updates = [];
            foreach (array_keys($values) as $column) {
                $q = self::quoteIdentifier($pdo, (string)$column);
                $updates[] = "$q=VALUES($q)";
            }
            $sql = 'INSERT INTO ' . $tableQ . ' (' . implode(',', $quotedColumns) . ') VALUES (' . $placeholders . ')';
            if ($updates) $sql .= ' ON DUPLICATE KEY UPDATE ' . implode(',', $updates);
        } else {
            $updates = [];
            foreach (array_keys($values) as $column) {
                $q = self::quoteIdentifier($pdo, (string)$column);
                $updates[] = "$q=excluded.$q";
            }
            $sql = 'INSERT INTO ' . $tableQ . ' (' . implode(',', $quotedColumns) . ') VALUES (' . $placeholders . ')';
            $sql .= ' ON CONFLICT (' . implode(',', $quotedKeys) . ') ' . ($updates ? 'DO UPDATE SET ' . implode(',', $updates) : 'DO NOTHING');
        }
        $stmt = $pdo->prepare($sql);
        $stmt->execute(array_values($data));
    }

    public static function insertIgnore(PDO $pdo, string $table, array $data): void
    {
        if (!$data) return;

        $columns = array_keys($data);
        $quotedColumns = array_map(static fn($column) => self::quoteIdentifier($pdo, (string)$column), $columns);
        $placeholders = implode(',', array_fill(0, count($columns), '?'));
        $tableQ = self::quoteIdentifier($pdo, $table);
        $driver = self::driver($pdo);

        if ($driver === 'mysql') {
            $sql = 'INSERT IGNORE INTO ' . $tableQ . ' (' . implode(',', $quotedColumns) . ') VALUES (' . $placeholders . ')';
        } elseif ($driver === 'sqlite') {
            $sql = 'INSERT OR IGNORE INTO ' . $tableQ . ' (' . implode(',', $quotedColumns) . ') VALUES (' . $placeholders . ')';
        } else {
            // PostgreSQL can omit a conflict target entirely. This means any
            // matching PRIMARY KEY / UNIQUE constraint safely turns the insert
            // into a no-op without having to guess the constraint columns.
            $sql = 'INSERT INTO ' . $tableQ . ' (' . implode(',', $quotedColumns) . ') VALUES (' . $placeholders . ') ON CONFLICT DO NOTHING';
        }

        $stmt = $pdo->prepare($sql);
        $stmt->execute(array_values($data));
    }

    public static function createSchema(PDO $pdo): void
    {
        $driver = self::driver($pdo);
        $id = $driver === 'sqlite' ? 'INTEGER PRIMARY KEY AUTOINCREMENT' : ($driver === 'mysql' ? 'BIGINT PRIMARY KEY AUTO_INCREMENT' : 'BIGSERIAL PRIMARY KEY');
        $ref = $driver === 'sqlite' ? 'INTEGER' : 'BIGINT';
        $name = $driver === 'mysql' ? 'VARCHAR(255)' : 'VARCHAR(255)';
        $slug = $driver === 'mysql' ? 'VARCHAR(190)' : 'VARCHAR(190)';
        $email = 'VARCHAR(254)';
        $small = 'VARCHAR(64)';
        $json = $driver === 'mysql' ? 'LONGTEXT' : 'TEXT';
        $timestamp = $driver === 'sqlite' ? 'TEXT' : 'TIMESTAMP';
        $bool = $driver === 'pgsql' ? 'SMALLINT' : 'INTEGER';
        $engine = $driver === 'mysql' ? ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci' : '';
        $key = self::quoteIdentifier($pdo, 'key');

        $statements = [
            "CREATE TABLE IF NOT EXISTS users (id $id, name $name NOT NULL, email $email NOT NULL UNIQUE, password_hash VARCHAR(255) NOT NULL, system_role $small NOT NULL DEFAULT 'user', created_at $timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP)$engine",
            "CREATE TABLE IF NOT EXISTS folders (id $id, parent_id $ref NULL, name $name NOT NULL, slug $slug NOT NULL, sort_order INTEGER NOT NULL DEFAULT 0, api_enabled $bool NOT NULL DEFAULT 1, api_tree_visible $bool NOT NULL DEFAULT 1, api_tree_depth INTEGER NOT NULL DEFAULT 0, api_tree_include_data $bool NOT NULL DEFAULT 1, api_tree_cache_ttl INTEGER NOT NULL DEFAULT 60, created_at $timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP, updated_at $timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP, FOREIGN KEY(parent_id) REFERENCES folders(id) ON DELETE CASCADE, UNIQUE(parent_id,slug))$engine",
            "CREATE TABLE IF NOT EXISTS documents (id $id, folder_id $ref NULL, name $name NOT NULL, slug $slug NOT NULL, mode $small NOT NULL DEFAULT 'single', api_enabled $bool NOT NULL DEFAULT 1, api_tree_visible $bool NOT NULL DEFAULT 1, schema_json $json NOT NULL, data_json $json NOT NULL, created_at $timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP, updated_at $timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP, FOREIGN KEY(folder_id) REFERENCES folders(id) ON DELETE CASCADE, UNIQUE(folder_id,slug))$engine",
            "CREATE TABLE IF NOT EXISTS revisions (id $id, document_id $ref NOT NULL, language VARCHAR(16) NOT NULL DEFAULT '', schema_json $json NOT NULL, data_json $json NOT NULL, created_at $timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP, FOREIGN KEY(document_id) REFERENCES documents(id) ON DELETE CASCADE)$engine",
            "CREATE TABLE IF NOT EXISTS document_translations (document_id $ref NOT NULL, language VARCHAR(16) NOT NULL, data_json $json NOT NULL, updated_at $timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP, PRIMARY KEY(document_id,language), FOREIGN KEY(document_id) REFERENCES documents(id) ON DELETE CASCADE)$engine",
            "CREATE TABLE IF NOT EXISTS forms (id $id, project_id $ref NULL, name $name NOT NULL, slug $slug NOT NULL UNIQUE, api_enabled $bool NOT NULL DEFAULT 1, schema_json $json NOT NULL, success_message VARCHAR(1000) NOT NULL DEFAULT 'Спасибо! Мы получили вашу заявку.', created_at $timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP, updated_at $timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP)$engine",
            "CREATE TABLE IF NOT EXISTS form_submissions (id $id, form_id $ref NOT NULL, data_json $json NOT NULL, status $small NOT NULL DEFAULT 'new', created_at $timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP, FOREIGN KEY(form_id) REFERENCES forms(id) ON DELETE CASCADE)$engine",
            "CREATE TABLE IF NOT EXISTS cms_settings ($key VARCHAR(190) PRIMARY KEY, value_json $json NOT NULL)$engine",
            "CREATE TABLE IF NOT EXISTS projects (id $id, name $name NOT NULL, slug $slug NOT NULL UNIQUE, root_folder_id $ref NULL UNIQUE, created_by $ref NULL, created_at $timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP, updated_at $timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP, FOREIGN KEY(root_folder_id) REFERENCES folders(id) ON DELETE SET NULL, FOREIGN KEY(created_by) REFERENCES users(id) ON DELETE SET NULL)$engine",
            "CREATE TABLE IF NOT EXISTS project_users (project_id $ref NOT NULL, user_id $ref NOT NULL, role $small NOT NULL DEFAULT 'viewer', permissions_json $json NOT NULL, created_at $timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP, PRIMARY KEY(project_id,user_id), FOREIGN KEY(project_id) REFERENCES projects(id) ON DELETE CASCADE, FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE)$engine",
            "CREATE TABLE IF NOT EXISTS project_settings (project_id $ref NOT NULL, $key VARCHAR(190) NOT NULL, value_json $json NOT NULL, PRIMARY KEY(project_id,$key), FOREIGN KEY(project_id) REFERENCES projects(id) ON DELETE CASCADE)$engine",
            "CREATE TABLE IF NOT EXISTS data_sets (id $id, project_id $ref NOT NULL, name $name NOT NULL, slug $slug NOT NULL, mode $small NOT NULL DEFAULT 'single', api_enabled $bool NOT NULL DEFAULT 1, schema_json $json NOT NULL, data_json $json NOT NULL, created_at $timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP, updated_at $timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP, UNIQUE(project_id,slug), FOREIGN KEY(project_id) REFERENCES projects(id) ON DELETE CASCADE)$engine",
            "CREATE TABLE IF NOT EXISTS content_links (id $id, project_id $ref NOT NULL, folder_id $ref NOT NULL, resource_type $small NOT NULL, resource_id $ref NOT NULL, sort_order INTEGER NOT NULL DEFAULT 100, created_at $timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP, UNIQUE(project_id,folder_id,resource_type,resource_id), FOREIGN KEY(project_id) REFERENCES projects(id) ON DELETE CASCADE, FOREIGN KEY(folder_id) REFERENCES folders(id) ON DELETE CASCADE)$engine",
            "CREATE TABLE IF NOT EXISTS content_trash (id $id, project_id $ref NOT NULL, item_type $small NOT NULL, item_id $ref NOT NULL, original_parent_id $ref NULL, original_name $name NOT NULL, deleted_by $ref NULL, deleted_at $timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP, UNIQUE(project_id,item_type,item_id), FOREIGN KEY(project_id) REFERENCES projects(id) ON DELETE CASCADE, FOREIGN KEY(deleted_by) REFERENCES users(id) ON DELETE SET NULL)$engine",
        ];
        foreach ($statements as $sql) $pdo->exec($sql);

        self::createIndex($pdo, 'idx_revisions_document_language_created', 'revisions', ['document_id', 'language', 'id']);
        self::createIndex($pdo, 'idx_revisions_document_created', 'revisions', ['document_id', 'id']);
        self::createIndex($pdo, 'idx_form_submissions_form_created', 'form_submissions', ['form_id', 'id']);
        self::createIndex($pdo, 'idx_form_submissions_status', 'form_submissions', ['status']);
        self::createIndex($pdo, 'idx_forms_project', 'forms', ['project_id', 'updated_at']);
        self::createIndex($pdo, 'idx_data_sets_project', 'data_sets', ['project_id', 'updated_at']);
        self::createIndex($pdo, 'idx_content_links_folder', 'content_links', ['project_id','folder_id','sort_order']);
        self::createIndex($pdo, 'idx_content_links_resource', 'content_links', ['project_id','resource_type','resource_id']);
        self::createIndex($pdo, 'idx_content_trash_project_deleted', 'content_trash', ['project_id', 'deleted_at']);
        self::createIndex($pdo, 'idx_folders_parent_sort', 'folders', ['parent_id','sort_order','id']);
        self::createIndex($pdo, 'idx_documents_folder', 'documents', ['folder_id','id']);
        self::createIndex($pdo, 'idx_project_users_user', 'project_users', ['user_id','project_id']);
    }

    private static function indexExists(PDO $pdo, string $table, string $index): bool
    {
        $driver = self::driver($pdo);
        if ($driver === 'sqlite') {
            $rows = $pdo->query('PRAGMA index_list(' . self::quoteIdentifier($pdo, $table) . ')')->fetchAll();
            foreach ($rows as $row) if ((string)($row['name'] ?? '') === $index) return true;
            return false;
        }
        if ($driver === 'mysql') {
            $stmt = $pdo->prepare('SELECT 1 FROM information_schema.statistics WHERE table_schema=DATABASE() AND table_name=? AND index_name=? LIMIT 1');
            $stmt->execute([$table, $index]);
            return (bool)$stmt->fetchColumn();
        }
        $stmt = $pdo->prepare('SELECT 1 FROM pg_indexes WHERE schemaname=current_schema() AND tablename=? AND indexname=? LIMIT 1');
        $stmt->execute([$table, $index]);
        return (bool)$stmt->fetchColumn();
    }

    private static function createIndex(PDO $pdo, string $index, string $table, array $columns): void
    {
        if (self::indexExists($pdo, $table, $index)) return;
        $columnSql = implode(',', array_map(static fn($column) => self::quoteIdentifier($pdo, (string)$column), $columns));
        $pdo->exec('CREATE INDEX ' . self::quoteIdentifier($pdo, $index) . ' ON ' . self::quoteIdentifier($pdo, $table) . '(' . $columnSql . ')');
    }

    private static function migrate(PDO $pdo): void
    {
        if (!self::tableExists($pdo, 'users')) return;

        // Create all currently supported tables first. CREATE IF NOT EXISTS is safe
        // for old SQLite installs and gives MySQL/PostgreSQL the same baseline.
        self::createSchema($pdo);

        if (self::tableExists($pdo, 'documents') && !self::columnExists($pdo, 'documents', 'mode')) {
            $pdo->exec("ALTER TABLE documents ADD COLUMN mode VARCHAR(64) NOT NULL DEFAULT 'single'");
        }
        if (self::tableExists($pdo, 'revisions') && !self::columnExists($pdo, 'revisions', 'language')) {
            $pdo->exec("ALTER TABLE revisions ADD COLUMN language VARCHAR(16) NOT NULL DEFAULT ''");
        }
        if (!self::columnExists($pdo, 'users', 'system_role')) {
            $pdo->exec("ALTER TABLE users ADD COLUMN system_role VARCHAR(64) NOT NULL DEFAULT 'user'");
        }
        if (self::tableExists($pdo, 'forms') && !self::columnExists($pdo, 'forms', 'project_id')) {
            $ref = self::driver($pdo) === 'sqlite' ? 'INTEGER' : 'BIGINT';
            $pdo->exec("ALTER TABLE forms ADD COLUMN project_id $ref NULL");
        }

        $apiBool = self::driver($pdo) === 'pgsql' ? 'SMALLINT' : 'INTEGER';
        if (self::tableExists($pdo, 'folders') && !self::columnExists($pdo, 'folders', 'api_enabled')) {
            $pdo->exec("ALTER TABLE folders ADD COLUMN api_enabled $apiBool NOT NULL DEFAULT 1");
        }
        if (self::tableExists($pdo, 'documents') && !self::columnExists($pdo, 'documents', 'api_enabled')) {
            $pdo->exec("ALTER TABLE documents ADD COLUMN api_enabled $apiBool NOT NULL DEFAULT 1");
        }
        if (self::tableExists($pdo, 'forms') && !self::columnExists($pdo, 'forms', 'api_enabled')) {
            $pdo->exec("ALTER TABLE forms ADD COLUMN api_enabled $apiBool NOT NULL DEFAULT 1");
        }

        // Folder Tree API controls. api_tree_visible only controls inclusion in a
        // parent aggregate tree; the resource's own api_enabled switch remains
        // independent. A depth of 0 means unlimited recursion. cache_ttl=0
        // disables server-side tree caching.
        if (self::tableExists($pdo, 'folders') && !self::columnExists($pdo, 'folders', 'api_tree_visible')) {
            $pdo->exec("ALTER TABLE folders ADD COLUMN api_tree_visible $apiBool NOT NULL DEFAULT 1");
        }
        if (self::tableExists($pdo, 'folders') && !self::columnExists($pdo, 'folders', 'api_tree_depth')) {
            $pdo->exec("ALTER TABLE folders ADD COLUMN api_tree_depth INTEGER NOT NULL DEFAULT 0");
        }
        if (self::tableExists($pdo, 'folders') && !self::columnExists($pdo, 'folders', 'api_tree_include_data')) {
            $pdo->exec("ALTER TABLE folders ADD COLUMN api_tree_include_data $apiBool NOT NULL DEFAULT 1");
        }
        if (self::tableExists($pdo, 'folders') && !self::columnExists($pdo, 'folders', 'api_tree_cache_ttl')) {
            $pdo->exec("ALTER TABLE folders ADD COLUMN api_tree_cache_ttl INTEGER NOT NULL DEFAULT 60");
        }
        if (self::tableExists($pdo, 'documents') && !self::columnExists($pdo, 'documents', 'api_tree_visible')) {
            $pdo->exec("ALTER TABLE documents ADD COLUMN api_tree_visible $apiBool NOT NULL DEFAULT 1");
        }

        // Multiple Data records need durable numeric ids for Relation fields.
        if (self::tableExists($pdo, 'data_sets')) {
            $dataRows = $pdo->query("SELECT id,data_json FROM data_sets WHERE mode='multiple'")->fetchAll();
            foreach ($dataRows as $dataRow) {
                $items = json_decode((string)($dataRow['data_json'] ?? '[]'), true);
                if (!is_array($items) || !array_is_list($items)) continue;
                $used = [];
                $maxId = 0;
                foreach ($items as $item) {
                    if (!is_array($item)) continue;
                    $itemId = (int)($item['_id'] ?? 0);
                    if ($itemId > 0 && !isset($used[$itemId])) { $used[$itemId] = true; $maxId = max($maxId, $itemId); }
                }
                $changed = false;
                $seen = [];
                foreach ($items as &$item) {
                    if (!is_array($item)) $item = [];
                    $itemId = (int)($item['_id'] ?? 0);
                    if ($itemId <= 0 || isset($seen[$itemId])) {
                        do { $maxId++; } while (isset($used[$maxId]) || isset($seen[$maxId]));
                        $itemId = $maxId;
                        $item['_id'] = $itemId;
                        $changed = true;
                    }
                    $seen[$itemId] = true;
                }
                unset($item);
                if ($changed) {
                    $json = json_encode($items, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                    if (is_string($json)) $pdo->prepare('UPDATE data_sets SET data_json=? WHERE id=?')->execute([$json, (int)$dataRow['id']]);
                }
            }
        }

        if (self::tableExists($pdo, 'cms_settings')) {
            $key = self::quoteIdentifier($pdo, 'key');
            $stmt = $pdo->prepare("SELECT 1 FROM cms_settings WHERE $key=? LIMIT 1");
            $stmt->execute(['i18n']);
            if (!$stmt->fetchColumn()) {
                $json = json_encode(['enabled'=>false,'default_language'=>'ru','languages'=>['ru']], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
                self::upsert($pdo, 'cms_settings', ['key'=>'i18n'], ['value_json'=>$json]);
            }
        }

        $ownerExists = (bool)$pdo->query("SELECT 1 FROM users WHERE system_role='owner' LIMIT 1")->fetchColumn();
        if (!$ownerExists) {
            $firstUser = $pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn();
            if ($firstUser) $pdo->prepare("UPDATE users SET system_role='owner' WHERE id=?")->execute([(int)$firstUser]);
        }

        if (!self::tableExists($pdo, 'projects')) return;
        $projectCount = (int)$pdo->query('SELECT COUNT(*) FROM projects')->fetchColumn();
        if ($projectCount === 0) {
            $ownerId = (int)($pdo->query("SELECT id FROM users WHERE system_role='owner' ORDER BY id LIMIT 1")->fetchColumn() ?: $pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn());
            $pdo->prepare("INSERT INTO projects(name,slug,created_by) VALUES(?,?,?)")->execute(['Основной проект','main',$ownerId ?: null]);
            $projectId = self::lastInsertId($pdo);
            $rootSlug = '__project_' . $projectId . '__';
            $pdo->prepare("INSERT INTO folders(parent_id,name,slug,sort_order) VALUES(NULL,?,?,0)")->execute(['Основной проект',$rootSlug]);
            $rootId = self::lastInsertId($pdo);
            $pdo->prepare('UPDATE projects SET root_folder_id=? WHERE id=?')->execute([$rootId,$projectId]);
            $pdo->prepare('UPDATE folders SET parent_id=? WHERE parent_id IS NULL AND id<>?')->execute([$rootId,$rootId]);
            $pdo->prepare('UPDATE documents SET folder_id=? WHERE folder_id IS NULL')->execute([$rootId]);
            $pdo->prepare('UPDATE forms SET project_id=? WHERE project_id IS NULL')->execute([$projectId]);
            if ($ownerId > 0) self::upsert($pdo, 'project_users', ['project_id'=>$projectId,'user_id'=>$ownerId], ['role'=>'owner','permissions_json'=>'{}']);

            $key = self::quoteIdentifier($pdo, 'key');
            $stmt = $pdo->prepare("SELECT value_json FROM cms_settings WHERE $key=? LIMIT 1");
            $stmt->execute(['i18n']);
            $legacyI18n = $stmt->fetchColumn();
            if (!is_string($legacyI18n) || $legacyI18n === '') $legacyI18n = json_encode(['enabled'=>false,'default_language'=>'ru','languages'=>['ru']], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
            self::upsert($pdo, 'project_settings', ['project_id'=>$projectId,'key'=>'i18n'], ['value_json'=>$legacyI18n]);
            self::upsert($pdo, 'project_settings', ['project_id'=>$projectId,'key'=>'api_access'], ['value_json'=>json_encode(['mode'=>'public','token_hash'=>'','token_prefix'=>'','created_at'=>null], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)]);
            self::upsert($pdo, 'project_settings', ['project_id'=>$projectId,'key'=>'api_response'], ['value_json'=>json_encode(['full_response'=>false,'updated_at'=>null], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)]);
            self::upsert($pdo, 'project_settings', ['project_id'=>$projectId,'key'=>'api_status'], ['value_json'=>json_encode(['enabled'=>true,'updated_at'=>null], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)]);
            self::upsert($pdo, 'project_settings', ['project_id'=>$projectId,'key'=>'api_tree_revision'], ['value_json'=>json_encode(['token'=>'1'], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)]);
        }

        $apiDefault = json_encode(['mode'=>'public','token_hash'=>'','token_prefix'=>'','created_at'=>null], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
        $apiResponseDefault = json_encode(['full_response'=>false,'updated_at'=>null], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
        $apiStatusDefault = json_encode(['enabled'=>true,'updated_at'=>null], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
        $apiTreeRevisionDefault = json_encode(['token'=>'1'], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
        $projectIds = $pdo->query('SELECT id FROM projects ORDER BY id')->fetchAll(PDO::FETCH_COLUMN);
        $key = self::quoteIdentifier($pdo, 'key');
        $check = $pdo->prepare("SELECT 1 FROM project_settings WHERE project_id=? AND $key=? LIMIT 1");
        foreach ($projectIds as $pid) {
            $check->execute([(int)$pid, 'api_access']);
            if (!$check->fetchColumn()) self::upsert($pdo, 'project_settings', ['project_id'=>(int)$pid,'key'=>'api_access'], ['value_json'=>$apiDefault]);
            $check->execute([(int)$pid, 'api_response']);
            if (!$check->fetchColumn()) self::upsert($pdo, 'project_settings', ['project_id'=>(int)$pid,'key'=>'api_response'], ['value_json'=>$apiResponseDefault]);
            $check->execute([(int)$pid, 'api_status']);
            if (!$check->fetchColumn()) self::upsert($pdo, 'project_settings', ['project_id'=>(int)$pid,'key'=>'api_status'], ['value_json'=>$apiStatusDefault]);
            $check->execute([(int)$pid, 'api_tree_revision']);
            if (!$check->fetchColumn()) self::upsert($pdo, 'project_settings', ['project_id'=>(int)$pid,'key'=>'api_tree_revision'], ['value_json'=>$apiTreeRevisionDefault]);
        }
        $firstProject = $pdo->query('SELECT id FROM projects ORDER BY id LIMIT 1')->fetchColumn();
        if ($firstProject) $pdo->prepare('UPDATE forms SET project_id=? WHERE project_id IS NULL')->execute([(int)$firstProject]);
    }

    public static function publicInfo(bool $connect = true): array
    {
        $database = self::configuredDatabase();
        $driver = (string)$database['driver'];
        $labels = ['sqlite'=>'SQLite','mysql'=>'MySQL','pgsql'=>'PostgreSQL'];
        $info = [
            'driver' => $driver,
            'label' => $labels[$driver] ?? strtoupper($driver),
            'configured' => self::hasConfiguration() || ($driver === 'sqlite' && self::hasLegacySqlite()),
            'connected' => false,
            'extension' => self::driverAvailability()[$driver]['extension'] ?? 'PDO',
            'version' => '',
            'host' => $driver === 'sqlite' ? null : ($database['host'] ?? null),
            'port' => $driver === 'sqlite' ? null : ($database['port'] ?? null),
            'database' => $driver === 'sqlite' ? basename((string)($database['path'] ?? 'matercms.sqlite')) : ($database['database'] ?? null),
            'storage' => $driver === 'sqlite' ? 'Локальный файл SQLite' : 'Серверная база данных',
        ];
        if (!$connect) return $info;
        try {
            $pdo = self::connection();
            $info['connected'] = true;
            try { $info['version'] = (string)$pdo->getAttribute(PDO::ATTR_SERVER_VERSION); } catch (Throwable) {}
            if ($driver === 'sqlite' && $info['version'] === '') {
                try { $info['version'] = (string)$pdo->query('SELECT sqlite_version()')->fetchColumn(); } catch (Throwable) {}
            }
        } catch (Throwable $e) {
            $info['error'] = $e->getMessage();
        }
        return $info;
    }

    public static function installed(): bool
    {
        // With no explicit config and no legacy SQLite file there is nothing to check.
        if (!self::hasConfiguration() && !self::hasLegacySqlite()) return false;
        try {
            return self::tableExists(self::connection(), 'users');
        } catch (Throwable) {
            return false;
        }
    }
}
