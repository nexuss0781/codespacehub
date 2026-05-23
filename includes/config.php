<?php
define('APP_NAME', 'GitPHP');
define('APP_VERSION', '1.0.0');
define('BASE_PATH', dirname(__DIR__));
define('REPOS_PATH', BASE_PATH . '/repos');
define('UPLOADS_PATH', BASE_PATH . '/uploads');
define('CACHE_PATH', BASE_PATH . '/cache');
define('MAX_FILE_SIZE', 100 * 1024 * 1024); // 100MB
define('DB_PATH', BASE_PATH . '/gitphp.db');

// Files/dirs to ignore (like .gitignore)
define('IGNORE_PATTERNS', [
    'build/', 'dist/', 'node_modules/', '.git/', '__pycache__/',
    'vendor/', '.env', '*.log', '*.tmp', '.DS_Store', 'Thumbs.db',
    '*.pyc', '*.pyo', '.idea/', '.vscode/', '*.swp', 'coverage/',
    '.next/', '.nuxt/', 'out/', '.cache/', 'tmp/', 'temp/'
]);

session_start();

function db(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        $pdo = new PDO('sqlite:' . DB_PATH);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $pdo->exec('PRAGMA journal_mode=WAL');
        $pdo->exec('PRAGMA synchronous=NORMAL');
        $pdo->exec('PRAGMA cache_size=10000');
        initDB($pdo);
    }
    return $pdo;
}

function initDB(PDO $pdo): void {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS users (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            username TEXT UNIQUE NOT NULL,
            email TEXT UNIQUE NOT NULL,
            password TEXT NOT NULL,
            avatar TEXT DEFAULT '',
            bio TEXT DEFAULT '',
            created_at INTEGER DEFAULT (strftime('%s','now'))
        );
        CREATE TABLE IF NOT EXISTS repositories (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER NOT NULL,
            name TEXT NOT NULL,
            description TEXT DEFAULT '',
            is_private INTEGER DEFAULT 0,
            default_branch TEXT DEFAULT 'main',
            stars INTEGER DEFAULT 0,
            forks INTEGER DEFAULT 0,
            created_at INTEGER DEFAULT (strftime('%s','now')),
            updated_at INTEGER DEFAULT (strftime('%s','now')),
            FOREIGN KEY(user_id) REFERENCES users(id),
            UNIQUE(user_id, name)
        );
        CREATE TABLE IF NOT EXISTS stars (
            user_id INTEGER, repo_id INTEGER,
            PRIMARY KEY(user_id, repo_id)
        );
        CREATE TABLE IF NOT EXISTS issues (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            repo_id INTEGER NOT NULL,
            user_id INTEGER NOT NULL,
            title TEXT NOT NULL,
            body TEXT DEFAULT '',
            state TEXT DEFAULT 'open',
            created_at INTEGER DEFAULT (strftime('%s','now'))
        );
        CREATE INDEX IF NOT EXISTS idx_repos_user ON repositories(user_id);
        CREATE INDEX IF NOT EXISTS idx_repos_updated ON repositories(updated_at DESC);
    ");
}

function auth(): ?array {
    if (!isset($_SESSION['user_id'])) return null;
    static $user = null;
    if ($user === null) {
        $stmt = db()->prepare('SELECT * FROM users WHERE id = ?');
        $stmt->execute([$_SESSION['user_id']]);
        $user = $stmt->fetch() ?: null;
    }
    return $user;
}

function requireAuth(): array {
    $user = auth();
    if (!$user) { redirect('/?page=login'); }
    return $user;
}

function redirect(string $url): void {
    header("Location: $url"); exit;
}

function e(string $s): string {
    return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
}

function timeAgo(int $ts): string {
    $diff = time() - $ts;
    if ($diff < 60) return 'just now';
    if ($diff < 3600) return floor($diff/60) . 'm ago';
    if ($diff < 86400) return floor($diff/3600) . 'h ago';
    if ($diff < 2592000) return floor($diff/86400) . 'd ago';
    return date('M j, Y', $ts);
}

function formatSize(int $bytes): string {
    if ($bytes < 1024) return $bytes . 'B';
    if ($bytes < 1048576) return round($bytes/1024, 1) . 'KB';
    return round($bytes/1048576, 1) . 'MB';
}

function shouldIgnore(string $path): bool {
    $name = basename($path);
    foreach (IGNORE_PATTERNS as $pattern) {
        if (str_ends_with($pattern, '/')) {
            if (str_contains($path, rtrim($pattern, '/'))) return true;
        } elseif (str_starts_with($pattern, '*.')) {
            $ext = substr($pattern, 1);
            if (str_ends_with($name, $ext)) return true;
        } elseif ($name === $pattern) {
            return true;
        }
    }
    return false;
}

function cache(string $key, callable $fn, int $ttl = 300): mixed {
    $file = CACHE_PATH . '/' . md5($key) . '.cache';
    if (file_exists($file) && (time() - filemtime($file)) < $ttl) {
        return unserialize(file_get_contents($file));
    }
    $data = $fn();
    file_put_contents($file, serialize($data), LOCK_EX);
    return $data;
}

function clearCache(string $prefix = ''): void {
    foreach (glob(CACHE_PATH . '/*.cache') as $f) {
        unlink($f);
    }
}
