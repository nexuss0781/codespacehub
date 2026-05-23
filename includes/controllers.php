<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/repofs.php';
require_once __DIR__ . '/markdown.php';

// ─── AUTH ────────────────────────────────────────────────────────────────────

function handleRegister(): array {
    $username = trim($_POST['username'] ?? '');
    $email    = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    
    if (!preg_match('/^[a-zA-Z0-9_-]{3,30}$/', $username))
        return ['error' => 'Username must be 3-30 alphanumeric chars'];
    if (!filter_var($email, FILTER_VALIDATE_EMAIL))
        return ['error' => 'Invalid email'];
    if (strlen($password) < 6)
        return ['error' => 'Password must be at least 6 characters'];
    
    try {
        $stmt = db()->prepare('INSERT INTO users (username, email, password) VALUES (?,?,?)');
        $stmt->execute([$username, $email, password_hash($password, PASSWORD_BCRYPT)]);
        $id = db()->lastInsertId();
        $_SESSION['user_id'] = $id;
        return ['success' => true];
    } catch (PDOException $e) {
        return ['error' => 'Username or email already taken'];
    }
}

function handleLogin(): array {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    
    $stmt = db()->prepare('SELECT * FROM users WHERE username = ? OR email = ?');
    $stmt->execute([$username, $username]);
    $user = $stmt->fetch();
    
    if (!$user || !password_verify($password, $user['password']))
        return ['error' => 'Invalid credentials'];
    
    $_SESSION['user_id'] = $user['id'];
    return ['success' => true];
}

// ─── REPOS ───────────────────────────────────────────────────────────────────

function handleCreateRepo(): array {
    $user = requireAuth();
    $name = trim($_POST['name'] ?? '');
    $desc = trim($_POST['description'] ?? '');
    $private = isset($_POST['is_private']) ? 1 : 0;
    
    if (!preg_match('/^[a-zA-Z0-9_.-]{1,100}$/', $name))
        return ['error' => 'Invalid repo name (alphanumeric, -, _, .)'];
    
    try {
        $stmt = db()->prepare('INSERT INTO repositories (user_id, name, description, is_private) VALUES (?,?,?,?)');
        $stmt->execute([$user['id'], $name, $desc, $private]);
        $repoId = db()->lastInsertId();
        
        $fs = new RepoFS($user['username'], $name);
        $fs->init();
        
        // Create default README
        $readme = "# $name\n\n" . ($desc ?: 'A new repository.') . "\n\n## Getting Started\n\nUpload your project files to get started.\n";
        $fs->saveFile('README.md', $readme);
        
        return ['success' => true, 'redirect' => "/{$user['username']}/$name"];
    } catch (PDOException $e) {
        return ['error' => 'Repository name already exists'];
    }
}

function handleUploadZip(string $username, string $repoName): array {
    $user = requireAuth();
    if ($user['username'] !== $username) return ['error' => 'Unauthorized'];
    
    if (!isset($_FILES['zipfile']) || $_FILES['zipfile']['error'] !== UPLOAD_ERR_OK)
        return ['error' => 'Upload failed: ' . ($_FILES['zipfile']['error'] ?? 'no file')];
    
    $file = $_FILES['zipfile'];
    if ($file['size'] > MAX_FILE_SIZE)
        return ['error' => 'File too large (max 100MB)'];
    
    $mime = mime_content_type($file['tmp_name']);
    if (!in_array($mime, ['application/zip', 'application/x-zip-compressed', 'application/octet-stream']))
        return ['error' => 'Only ZIP files accepted'];
    
    $tmpPath = UPLOADS_PATH . '/' . uniqid('upload_') . '.zip';
    if (!move_uploaded_file($file['tmp_name'], $tmpPath))
        return ['error' => 'Failed to save upload'];
    
    $fs = new RepoFS($username, $repoName);
    $result = $fs->extractZip($tmpPath);
    unlink($tmpPath);
    
    if (isset($result['error'])) return $result;
    
    // Update repo timestamp
    db()->prepare('UPDATE repositories SET updated_at = ? WHERE name = ? AND user_id = (SELECT id FROM users WHERE username = ?)')
        ->execute([time(), $repoName, $username]);
    
    clearCache();
    return ['success' => true, 'extracted' => $result['extracted'], 'skipped' => $result['skipped']];
}

function handleSaveFile(string $username, string $repoName): array {
    $user = requireAuth();
    if ($user['username'] !== $username) return ['error' => 'Unauthorized'];
    
    $filePath = $_POST['file_path'] ?? '';
    $content  = $_POST['content'] ?? '';
    
    if (empty($filePath)) return ['error' => 'No file path'];
    
    $fs = new RepoFS($username, $repoName);
    if (!$fs->saveFile($filePath, $content)) return ['error' => 'Save failed'];
    
    db()->prepare('UPDATE repositories SET updated_at = ? WHERE name = ? AND user_id = ?')
        ->execute([time(), $repoName, $user['id']]);
    clearCache();
    
    return ['success' => true];
}

function handleDeleteFile(string $username, string $repoName): array {
    $user = requireAuth();
    if ($user['username'] !== $username) return ['error' => 'Unauthorized'];
    
    $filePath = $_POST['file_path'] ?? '';
    $fs = new RepoFS($username, $repoName);
    if (!$fs->deleteFile($filePath)) return ['error' => 'Delete failed'];
    
    clearCache();
    return ['success' => true];
}

function handleStar(int $repoId): array {
    $user = requireAuth();
    $stmt = db()->prepare('SELECT 1 FROM stars WHERE user_id=? AND repo_id=?');
    $stmt->execute([$user['id'], $repoId]);
    
    if ($stmt->fetch()) {
        db()->prepare('DELETE FROM stars WHERE user_id=? AND repo_id=?')->execute([$user['id'], $repoId]);
        db()->prepare('UPDATE repositories SET stars=stars-1 WHERE id=?')->execute([$repoId]);
        return ['starred' => false];
    } else {
        db()->prepare('INSERT INTO stars VALUES (?,?)')->execute([$user['id'], $repoId]);
        db()->prepare('UPDATE repositories SET stars=stars+1 WHERE id=?')->execute([$repoId]);
        return ['starred' => true];
    }
}

// ─── PAGE DATA ───────────────────────────────────────────────────────────────

function getHomeData(): array {
    $user = auth();
    $stmt = db()->prepare("
        SELECT r.*, u.username, u.avatar
        FROM repositories r
        JOIN users u ON u.id = r.user_id
        WHERE r.is_private = 0
        ORDER BY r.updated_at DESC
        LIMIT 20
    ");
    $stmt->execute();
    $repos = $stmt->fetchAll();
    
    $myRepos = [];
    if ($user) {
        $stmt2 = db()->prepare("SELECT * FROM repositories WHERE user_id = ? ORDER BY updated_at DESC LIMIT 10");
        $stmt2->execute([$user['id']]);
        $myRepos = $stmt2->fetchAll();
    }
    
    return compact('repos', 'myRepos', 'user');
}

function getRepoData(string $username, string $repoName, string $subPath = ''): ?array {
    $user = auth();
    
    $stmt = db()->prepare("
        SELECT r.*, u.username, u.avatar, u.bio
        FROM repositories r
        JOIN users u ON u.id = r.user_id
        WHERE u.username = ? AND r.name = ?
    ");
    $stmt->execute([$username, $repoName]);
    $repo = $stmt->fetch();
    
    if (!$repo) return null;
    if ($repo['is_private'] && (!$user || $user['id'] != $repo['user_id'])) return null;
    
    $fs = new RepoFS($username, $repoName);
    $files = $fs->listDir($subPath);
    $readme = !$subPath ? $fs->getReadme() : null;
    $readmeHtml = $readme ? Markdown::render($readme) : null;
    $stats = $fs->getStats();
    
    $isOwner = $user && $user['id'] == $repo['user_id'];
    $isStarred = false;
    if ($user) {
        $stmt2 = db()->prepare('SELECT 1 FROM stars WHERE user_id=? AND repo_id=?');
        $stmt2->execute([$user['id'], $repo['id']]);
        $isStarred = (bool)$stmt2->fetch();
    }
    
    return compact('repo', 'files', 'readme', 'readmeHtml', 'stats', 'isOwner', 'isStarred', 'subPath', 'user');
}

function getFileData(string $username, string $repoName, string $filePath): ?array {
    $user = auth();
    $stmt = db()->prepare("SELECT r.*, u.username FROM repositories r JOIN users u ON u.id=r.user_id WHERE u.username=? AND r.name=?");
    $stmt->execute([$username, $repoName]);
    $repo = $stmt->fetch();
    if (!$repo) return null;
    if ($repo['is_private'] && (!$user || $user['id'] != $repo['user_id'])) return null;
    
    $fs = new RepoFS($username, $repoName);
    $file = $fs->getFile($filePath);
    if (!$file) return null;
    
    $isOwner = $user && $user['id'] == $repo['user_id'];
    return compact('repo', 'file', 'isOwner', 'user', 'filePath');
}

function getUserData(string $username): ?array {
    $user = auth();
    $stmt = db()->prepare('SELECT * FROM users WHERE username = ?');
    $stmt->execute([$username]);
    $profile = $stmt->fetch();
    if (!$profile) return null;
    
    $isOwner = $user && $user['id'] == $profile['id'];
    $wherePrivate = $isOwner ? '' : 'AND r.is_private = 0';
    
    $stmt2 = db()->prepare("SELECT r.*, u.username FROM repositories r JOIN users u ON u.id=r.user_id WHERE r.user_id=? $wherePrivate ORDER BY r.updated_at DESC");
    $stmt2->execute([$profile['id']]);
    $repos = $stmt2->fetchAll();
    
    return compact('profile', 'repos', 'isOwner', 'user');
}

function searchRepos(string $q): array {
    $q = trim($q);
    if (strlen($q) < 2) return [];
    $user = auth();
    $like = '%' . $q . '%';
    if ($user) {
        $stmt = db()->prepare("
            SELECT r.*, u.username FROM repositories r
            JOIN users u ON u.id = r.user_id
            WHERE (r.is_private = 0 OR r.user_id = ?)
              AND (r.name LIKE ? OR r.description LIKE ? OR u.username LIKE ?)
            ORDER BY r.stars DESC, r.updated_at DESC LIMIT 30
        ");
        $stmt->execute([$user['id'], $like, $like, $like]);
    } else {
        $stmt = db()->prepare("
            SELECT r.*, u.username FROM repositories r
            JOIN users u ON u.id = r.user_id
            WHERE r.is_private = 0
              AND (r.name LIKE ? OR r.description LIKE ? OR u.username LIKE ?)
            ORDER BY r.stars DESC, r.updated_at DESC LIMIT 30
        ");
        $stmt->execute([$like, $like, $like]);
    }
    return $stmt->fetchAll();
}
