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
    $repoType = $_POST['repo_type'] ?? 'code';
    
    if (!preg_match('/^[a-zA-Z0-9_.-]{1,100}$/', $name))
        return ['error' => 'Invalid repo name (alphanumeric, -, _, .)'];
    
    try {
        $stmt = db()->prepare('INSERT INTO repositories (user_id, name, description, is_private, repo_type) VALUES (?,?,?,?,?)');
        $stmt->execute([$user['id'], $name, $desc, $private, $repoType]);
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

// ─── MESSAGING & SHARING ──────────────────────────────────────────────────────

function handleSendMessage(): array {
    $user = requireAuth();
    $receiverUsername = $_POST['receiver'] ?? '';
    $message = trim($_POST['message'] ?? '');
    
    if (empty($receiverUsername) || empty($message)) {
        return ['error' => 'Invalid message'];
    }
    
    $stmt = db()->prepare('SELECT id FROM users WHERE username = ?');
    $stmt->execute([$receiverUsername]);
    $receiver = $stmt->fetch();
    
    if (!$receiver) {
        return ['error' => 'User not found'];
    }
    
    $stmt = db()->prepare('INSERT INTO messages (sender_id, receiver_id, message) VALUES (?,?,?)');
    $stmt->execute([$user['id'], $receiver['id'], $message]);
    
    return ['success' => true];
}

function getMessages(string $otherUsername): array {
    $user = requireAuth();
    
    $stmt = db()->prepare('SELECT id FROM users WHERE username = ?');
    $stmt->execute([$otherUsername]);
    $other = $stmt->fetch();
    
    if (!$other) return [];
    
    $stmt = db()->prepare("
        SELECT m.*, u.username as sender_name
        FROM messages m
        JOIN users u ON u.id = m.sender_id
        WHERE (m.sender_id = ? AND m.receiver_id = ?) OR (m.sender_id = ? AND m.receiver_id = ?)
        ORDER BY m.created_at ASC
    ");
    $stmt->execute([$user['id'], $other['id'], $other['id'], $user['id']]);
    $messages = $stmt->fetchAll();
    
    // Mark as read
    $stmt = db()->prepare("UPDATE messages SET is_read = 1 WHERE sender_id = ? AND receiver_id = ? AND is_read = 0");
    $stmt->execute([$other['id'], $user['id']]);
    
    return $messages;
}

function getUserOnlineStatus(int $userId): string {
    $stmt = db()->prepare('SELECT online_status, last_seen FROM users WHERE id = ?');
    $stmt->execute([$userId]);
    $user = $stmt->fetch();
    
    if (!$user) return 'offline';
    
    // Consider online if seen in last 5 minutes
    if ($user['online_status'] === 'online' && (time() - $user['last_seen']) < 300) {
        return 'online';
    }
    return 'offline';
}

function updateUserOnlineStatus(int $userId, string $status): void {
    $stmt = db()->prepare('UPDATE users SET online_status = ?, last_seen = ? WHERE id = ?');
    $stmt->execute([$status, time(), $userId]);
}

function handleShareRepo(int $repoId): array {
    $user = requireAuth();
    
    $stmt = db()->prepare('SELECT 1 FROM repositories WHERE id = ? AND user_id = ?');
    $stmt->execute([$repoId, $user['id']]);
    if (!$stmt->fetch()) {
        return ['error' => 'Unauthorized'];
    }
    
    $shareToken = bin2hex(random_bytes(16));
    $expiresAt = time() + (7 * 24 * 3600); // 7 days
    
    $stmt = db()->prepare('INSERT INTO shares (repo_id, shared_with, share_token, expires_at) VALUES (?, ?, ?, ?)');
    $stmt->execute([$repoId, 'anyone', $shareToken, $expiresAt]);
    
    return ['success' => true, 'share_url' => '/share/' . $shareToken];
}

function getSharedRepos(): array {
    $user = auth();
    if (!$user) return [];
    
    $stmt = db()->prepare("
        SELECT s.*, r.name as repo_name, u.username as owner_username
        FROM shares s
        JOIN repositories r ON r.id = s.repo_id
        JOIN users u ON u.id = r.user_id
        WHERE s.shared_with = ? OR s.share_token IN (SELECT share_token FROM shares WHERE shared_with = ?)
        ORDER BY s.created_at DESC
    ");
    $stmt->execute([$user['username'], $user['username']]);
    return $stmt->fetchAll();
}

function handleUpdateRepo(): array {
    $user = requireAuth();
    $repoId = (int)($_POST['repo_id'] ?? 0);
    $name = trim($_POST['name'] ?? '');
    $desc = trim($_POST['description'] ?? '');
    $private = isset($_POST['is_private']) ? 1 : 0;
    $repoType = $_POST['repo_type'] ?? 'code';
    
    if (!$repoId || !preg_match('/^[a-zA-Z0-9_.-]{1,100}$/', $name)) {
        return ['error' => 'Invalid input'];
    }
    
    $stmt = db()->prepare('SELECT 1 FROM repositories WHERE id = ? AND user_id = ?');
    $stmt->execute([$repoId, $user['id']]);
    if (!$stmt->fetch()) {
        return ['error' => 'Unauthorized'];
    }
    
    $stmt = db()->prepare('UPDATE repositories SET name = ?, description = ?, is_private = ?, repo_type = ?, updated_at = ? WHERE id = ?');
    $stmt->execute([$name, $desc, $private, $repoType, time(), $repoId]);
    
    clearCache();
    return ['success' => true];
}

function handleDeleteRepo(): array {
    $user = requireAuth();
    $repoId = (int)($_POST['repo_id'] ?? 0);
    
    if (!$repoId) return ['error' => 'Invalid repo ID'];
    
    $stmt = db()->prepare('SELECT name FROM repositories WHERE id = ? AND user_id = ?');
    $stmt->execute([$repoId, $user['id']]);
    $repo = $stmt->fetch();
    
    if (!$repo) return ['error' => 'Unauthorized'];
    
    // Delete repo directory
    $fs = new RepoFS($user['username'], $repo['name']);
    $repoPath = REPOS_PATH . '/' . $user['username'] . '/' . $repo['name'];
    if (is_dir($repoPath)) {
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($repoPath, RecursiveDirectoryIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if ($file->isDir()) rmdir($file->getPathname());
            else unlink($file->getPathname());
        }
        rmdir($repoPath);
    }
    
    // Delete from DB
    $stmt = db()->prepare('DELETE FROM repositories WHERE id = ?');
    $stmt->execute([$repoId]);
    
    clearCache();
    return ['success' => true];
}

function getUnreadMessageCount(): int {
    $user = auth();
    if (!$user) return 0;
    
    $stmt = db()->prepare('SELECT COUNT(*) FROM messages WHERE receiver_id = ? AND is_read = 0');
    $stmt->execute([$user['id']]);
    return (int)$stmt->fetchColumn();
}

function getAllUsers(): array {
    $stmt = db()->prepare('SELECT id, username, avatar, bio, online_status, last_seen FROM users ORDER BY username');
    $stmt->execute();
    return $stmt->fetchAll();
}
