<?php
final class ProjectAccess
{
    public const PERMISSIONS = [
        'content.view', 'content.edit',
        'data.view', 'data.edit',
        'forms.view', 'forms.edit',
        'files.view', 'files.manage',
        'settings.view', 'settings.edit',
        'members.view', 'members.manage',
        'project.edit',
    ];

    public static function roleDefaults(string $role): array
    {
        $all = array_fill_keys(self::PERMISSIONS, true);
        return match ($role) {
            'owner' => $all,
            'admin' => array_merge($all, ['project.edit' => true]),
            'editor' => [
                'content.view' => true, 'content.edit' => true,
                'data.view' => true, 'data.edit' => true,
                'forms.view' => true, 'forms.edit' => true,
                'files.view' => true, 'files.manage' => true,
                'settings.view' => true, 'settings.edit' => false,
                'members.view' => false, 'members.manage' => false,
                'project.edit' => false,
            ],
            default => [
                'content.view' => true, 'content.edit' => false,
                'data.view' => true, 'data.edit' => false,
                'forms.view' => true, 'forms.edit' => false,
                'files.view' => true, 'files.manage' => false,
                'settings.view' => true, 'settings.edit' => false,
                'members.view' => false, 'members.manage' => false,
                'project.edit' => false,
            ],
        };
    }

    public static function isSystemOwner(array $user): bool
    {
        return ($user['system_role'] ?? 'user') === 'owner';
    }

    public static function listProjects(PDO $pdo, array $user): array
    {
        if (self::isSystemOwner($user)) {
            $rows = $pdo->query("SELECT p.*, 'owner' AS member_role, '{}' AS permissions_json FROM projects p ORDER BY p.updated_at DESC,p.name")->fetchAll();
        } else {
            $stmt = $pdo->prepare("SELECT p.*,pu.role AS member_role,pu.permissions_json FROM projects p JOIN project_users pu ON pu.project_id=p.id WHERE pu.user_id=? ORDER BY p.updated_at DESC,p.name");
            $stmt->execute([(int)$user['id']]);
            $rows = $stmt->fetchAll();
        }

        foreach ($rows as &$row) {
            $row['id'] = (int)$row['id'];
            $row['root_folder_id'] = (int)$row['root_folder_id'];
            $role = self::isSystemOwner($user) ? 'owner' : (string)($row['member_role'] ?? 'viewer');
            $row['role'] = $role;
            $row['permissions'] = self::permissionsFromRow($role, (string)($row['permissions_json'] ?? '{}'));
            $row['api_access'] = project_api_access($pdo, (int)$row['id']);
            unset($row['member_role'], $row['permissions_json']);
        }
        unset($row);
        return $rows;
    }

    public static function currentProject(PDO $pdo, array $user, mixed $requestedId = null): array
    {
        $projects = self::listProjects($pdo, $user);
        if (!$projects) throw new RuntimeException('У вас нет доступных проектов.');
        $requested = (int)($requestedId ?: ($_SESSION['project_id'] ?? 0));
        foreach ($projects as $project) {
            if ($requested > 0 && (int)$project['id'] === $requested) {
                $_SESSION['project_id'] = (int)$project['id'];
                return $project;
            }
        }
        $_SESSION['project_id'] = (int)$projects[0]['id'];
        return $projects[0];
    }

    public static function permissions(PDO $pdo, array $user, int $projectId): array
    {
        if (self::isSystemOwner($user)) return self::roleDefaults('owner');
        $stmt = $pdo->prepare('SELECT role,permissions_json FROM project_users WHERE project_id=? AND user_id=? LIMIT 1');
        $stmt->execute([$projectId, (int)$user['id']]);
        $row = $stmt->fetch();
        if (!$row) return array_fill_keys(self::PERMISSIONS, false);
        return self::permissionsFromRow((string)$row['role'], (string)$row['permissions_json']);
    }

    public static function can(PDO $pdo, array $user, int $projectId, string $permission): bool
    {
        return (bool)(self::permissions($pdo, $user, $projectId)[$permission] ?? false);
    }

    public static function requirePermission(PDO $pdo, array $user, int $projectId, string $permission): void
    {
        if (!self::can($pdo, $user, $projectId, $permission)) {
            throw new RuntimeException('Недостаточно прав для этого действия.');
        }
    }

    public static function permissionsFromRow(string $role, string $json): array
    {
        $base = self::roleDefaults($role);
        $overrides = json_decode($json, true);
        if (!is_array($overrides)) $overrides = [];
        foreach (self::PERMISSIONS as $permission) {
            if (array_key_exists($permission, $overrides)) $base[$permission] = (bool)$overrides[$permission];
        }
        return $base;
    }

    public static function rootFolderId(array $project): int
    {
        return (int)$project['root_folder_id'];
    }

    public static function folderIds(PDO $pdo, array $project, bool $includeRoot = true): array
    {
        $root = self::rootFolderId($project);
        $stmt = $pdo->prepare("WITH RECURSIVE tree(id) AS (SELECT id FROM folders WHERE id=? UNION ALL SELECT f.id FROM folders f JOIN tree t ON f.parent_id=t.id) SELECT id FROM tree");
        $stmt->execute([$root]);
        $ids = array_map('intval', array_column($stmt->fetchAll(), 'id'));
        if (!$includeRoot) $ids = array_values(array_filter($ids, static fn(int $id): bool => $id !== $root));
        return $ids;
    }

    public static function containsFolder(PDO $pdo, array $project, ?int $folderId): bool
    {
        if ($folderId === null) return true; // UI root maps to project root.
        return in_array($folderId, self::folderIds($pdo, $project, true), true);
    }

    public static function actualFolderId(array $project, ?int $uiFolderId): int
    {
        return $uiFolderId ?: self::rootFolderId($project);
    }

    public static function containsDocument(PDO $pdo, array $project, int $documentId): bool
    {
        $stmt = $pdo->prepare('SELECT folder_id FROM documents WHERE id=? LIMIT 1');
        $stmt->execute([$documentId]);
        $folderId = $stmt->fetchColumn();
        if ($folderId === false) return false;
        return self::containsFolder($pdo, $project, $folderId === null ? null : (int)$folderId);
    }

    public static function projectBySlug(PDO $pdo, string $slug): ?array
    {
        $stmt = $pdo->prepare('SELECT * FROM projects WHERE slug=? LIMIT 1');
        $stmt->execute([$slug]);
        $row = $stmt->fetch();
        if (!$row) return null;
        $row['id'] = (int)$row['id'];
        $row['root_folder_id'] = (int)$row['root_folder_id'];
        return $row;
    }

    public static function folderApiPath(PDO $pdo, int $folderId, array $project): string
    {
        $root = self::rootFolderId($project);
        if ($folderId === $root) return '';
        $segments = [];
        foreach (folder_path($pdo, $folderId) as $folder) {
            if ((int)$folder['id'] === $root) continue;
            $segments[] = (string)$folder['slug'];
        }
        return implode('/', $segments);
    }

    public static function documentApiPath(PDO $pdo, array $doc, array $project): string
    {
        $root = self::rootFolderId($project);
        $segments = [];
        foreach (folder_path($pdo, $doc['folder_id'] ? (int)$doc['folder_id'] : null) as $folder) {
            if ((int)$folder['id'] === $root) continue;
            $segments[] = (string)$folder['slug'];
        }
        $segments[] = (string)$doc['slug'];
        return implode('/', $segments);
    }

    public static function uniqueProjectSlug(PDO $pdo, string $name, ?int $ignoreId = null): string
    {
        $base = slugify($name); $slug = $base; $i = 2;
        while (true) {
            $sql = 'SELECT id FROM projects WHERE slug=?'; $args = [$slug];
            if ($ignoreId !== null) { $sql .= ' AND id<>?'; $args[] = $ignoreId; }
            $sql .= ' LIMIT 1';
            $stmt = $pdo->prepare($sql); $stmt->execute($args);
            if (!$stmt->fetchColumn()) return $slug;
            $slug = $base . '-' . $i++;
        }
    }
}
