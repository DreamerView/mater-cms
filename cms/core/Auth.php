<?php
final class Auth
{
    public static function user(): ?array
    {
        if (empty($_SESSION['user_id']) || !Database::installed()) return null;
        $stmt = Database::connection()->prepare('SELECT id,name,email,system_role,created_at FROM users WHERE id=? LIMIT 1');
        $stmt->execute([(int)$_SESSION['user_id']]);
        return $stmt->fetch() ?: null;
    }

    public static function attempt(string $email, string $password): bool
    {
        $stmt = Database::connection()->prepare('SELECT * FROM users WHERE email=? LIMIT 1');
        $stmt->execute([mb_strtolower(trim($email))]);
        $user = $stmt->fetch();
        if (!$user || !password_verify($password, $user['password_hash'])) return false;
        session_regenerate_id(true);
        $_SESSION['user_id'] = (int)$user['id'];
        return true;
    }

    public static function require(): array
    {
        $user = self::user();
        if (!$user) redirect_cms();
        return $user;
    }

    public static function logout(): void
    {
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $p = session_get_cookie_params();
            setcookie(session_name(), '', time()-42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
        }
        session_destroy();
    }
}
