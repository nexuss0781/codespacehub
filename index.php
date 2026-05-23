<?php
require_once __DIR__ . '/includes/controllers.php';

// ─── ROUTER ──────────────────────────────────────────────────────────────────
$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$uri = rtrim($uri, '/') ?: '/';
$method = $_SERVER['REQUEST_METHOD'];
$parts = array_values(array_filter(explode('/', $uri)));

// API endpoints (JSON)
if (($parts[0] ?? '') === 'api') {
    header('Content-Type: application/json');
    header('X-Content-Type-Options: nosniff');
    
    $action = $parts[1] ?? '';
    
    switch ($action) {
        case 'register': echo json_encode($method === 'POST' ? handleRegister() : ['error' => 'POST only']); break;
        case 'login':    echo json_encode($method === 'POST' ? handleLogin() : ['error' => 'POST only']); break;
        case 'logout':   session_destroy(); echo json_encode(['success' => true]); break;
        
        case 'create-repo': echo json_encode($method === 'POST' ? handleCreateRepo() : ['error' => 'POST only']); break;
        case 'update-repo': echo json_encode($method === 'POST' ? handleUpdateRepo() : ['error' => 'POST only']); break;
        case 'delete-repo': echo json_encode($method === 'POST' ? handleDeleteRepo() : ['error' => 'POST only']); break;
        
        case 'upload':
            // /api/upload/username/reponame
            $u = $parts[2] ?? ''; $r = $parts[3] ?? '';
            echo json_encode($method === 'POST' ? handleUploadZip($u, $r) : ['error' => 'POST only']);
            break;
        
        case 'save-file':
            $u = $parts[2] ?? ''; $r = $parts[3] ?? '';
            echo json_encode($method === 'POST' ? handleSaveFile($u, $r) : ['error' => 'POST only']);
            break;
        
        case 'delete-file':
            $u = $parts[2] ?? ''; $r = $parts[3] ?? '';
            echo json_encode($method === 'POST' ? handleDeleteFile($u, $r) : ['error' => 'POST only']);
            break;
        
        case 'star':
            $repoId = (int)($parts[2] ?? 0);
            echo json_encode($method === 'POST' ? handleStar($repoId) : ['error' => 'POST only']);
            break;
        
        case 'share-repo':
            $repoId = (int)($parts[2] ?? 0);
            echo json_encode($method === 'POST' ? handleShareRepo($repoId) : ['error' => 'POST only']);
            break;
        
        case 'send-message':
            echo json_encode($method === 'POST' ? handleSendMessage() : ['error' => 'POST only']);
            break;
        
        case 'get-messages':
            $otherUser = $parts[2] ?? '';
            echo json_encode($method === 'GET' ? getMessages($otherUser) : ['error' => 'GET only']);
            break;
        
        case 'online-status':
            $userId = (int)($parts[2] ?? 0);
            echo json_encode(['status' => getUserOnlineStatus($userId)]);
            break;
        
        case 'ping':
            $user = auth();
            if ($user) updateUserOnlineStatus($user['id'], 'online');
            echo json_encode(['success' => true]);
            break;
        
        case 'raw':
            // /api/raw/username/reponame/path...
            $username = $parts[2] ?? ''; $repoName = $parts[3] ?? '';
            $filePath = implode('/', array_slice($parts, 4));
            $d = getFileData($username, $repoName, $filePath);
            if ($d && !$d['file']['is_binary']) {
                $ext = $d['file']['ext'];
                $mimes = ['js'=>'application/javascript','css'=>'text/css','html'=>'text/html','json'=>'application/json','svg'=>'image/svg+xml'];
                header('Content-Type: ' . ($mimes[$ext] ?? 'text/plain'));
                echo $d['file']['content'];
            } else {
                http_response_code(404); echo 'Not found';
            }
            break;
        
        default: http_response_code(404); echo json_encode(['error' => 'Not found']);
    }
    exit;
}

// Page routing
$page = 'home';
$pageData = [];

if (count($parts) === 0 || $uri === '/') {
    if (!empty($_GET['q'])) {
        $page = 'search';
        $pageData = ['user' => auth(), 'query' => $_GET['q'], 'results' => searchRepos($_GET['q'])];
    } else {
        $page = 'home';
        $pageData = getHomeData();
    }
} elseif (count($parts) === 1 && $parts[0] === 'new') {
    requireAuth();
    $page = 'new-repo';
    $pageData = ['user' => auth()];
} elseif (count($parts) === 1 && $parts[0] === 'messages') {
    requireAuth();
    $page = 'messages';
    $pageData = ['user' => auth(), 'users' => getAllUsers()];
} elseif (count($parts) === 2 && $parts[0] === 'chat') {
    requireAuth();
    $page = 'chat';
    $pageData = ['user' => auth(), 'otherUser' => $parts[1]];
} elseif (count($parts) === 1) {
    $page = 'profile';
    $pageData = getUserData($parts[0]);
    if (!$pageData) { http_response_code(404); $page = '404'; }
} elseif (count($parts) === 2) {
    $page = 'repo';
    $pageData = getRepoData($parts[0], $parts[1]);
    if (!$pageData) { http_response_code(404); $page = '404'; }
} elseif (count($parts) >= 3 && ($parts[2] === 'blob' || $parts[2] === 'tree')) {
    $username = $parts[0]; $repoName = $parts[1];
    $subPath = implode('/', array_slice($parts, 3));
    
    if ($parts[2] === 'blob') {
        $page = 'file';
        $pageData = getFileData($username, $repoName, $subPath);
        if (!$pageData) { http_response_code(404); $page = '404'; }
    } else {
        $page = 'repo';
        $pageData = getRepoData($username, $repoName, $subPath);
        if (!$pageData) { http_response_code(404); $page = '404'; }
    }
} elseif (count($parts) === 2 && $parts[0] === 'share') {
    $page = 'shared-repo';
    $pageData = ['token' => $parts[1]];
} else {
    $page = '404';
}

$user = auth();

// Output
ob_start();
?>
<!DOCTYPE html>
<html lang="en" class="dark">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?php
$title = APP_NAME;
if ($page === 'repo' && isset($pageData['repo'])) $title = $pageData['repo']['username'].'/'.$pageData['repo']['name'].' — '.APP_NAME;
elseif ($page === 'file' && isset($pageData['file'])) $title = $pageData['file']['name'].' — '.APP_NAME;
elseif ($page === 'profile' && isset($pageData['profile'])) $title = $pageData['profile']['username'].' — '.APP_NAME;
elseif ($page === 'search') $title = 'Search: '.($pageData['query'] ?? '').' — '.APP_NAME;
elseif ($page === 'new-repo') $title = 'New Repository — '.APP_NAME;
echo e($title);
?></title>
<script src="https://cdn.tailwindcss.com"></script>
<script>
tailwind.config = {
  darkMode: 'class',
  theme: {
    extend: {
      colors: {
        brand: { DEFAULT: '#2dd4bf', dark: '#14b8a6', light: '#5eead4' },
        surface: { 0: '#0a0e1a', 1: '#0f1629', 2: '#141d35', 3: '#1a2540', card: '#111827' },
        border: { DEFAULT: '#1e2d4a', light: '#263659' },
      },
      fontFamily: {
        sans: ['"DM Sans"', 'system-ui', 'sans-serif'],
        mono: ['"JetBrains Mono"', '"Fira Code"', 'monospace'],
      },
    }
  }
}
</script>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@300;400;500;600;700&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet">
<script src="https://unpkg.com/lucide@latest/dist/umd/lucide.min.js"></script>
<style>
:root {
  --brand: #2dd4bf;
  --brand-dark: #14b8a6;
  --surface-0: #0a0e1a;
  --surface-1: #0f1629;
  --surface-2: #141d35;
  --surface-3: #1a2540;
  --border: #1e2d4a;
  --border-light: #263659;
  --text: #e2e8f0;
  --text-muted: #64748b;
  --text-dim: #94a3b8;
}
* { box-sizing: border-box; margin: 0; padding: 0; }
body { background: var(--surface-0); color: var(--text); font-family: 'DM Sans', sans-serif; min-height: 100vh; }
::-webkit-scrollbar { width: 6px; height: 6px; }
::-webkit-scrollbar-track { background: var(--surface-1); }
::-webkit-scrollbar-thumb { background: var(--border-light); border-radius: 3px; }

/* Navbar */
.navbar { background: rgba(10,14,26,0.85); backdrop-filter: blur(20px); border-bottom: 1px solid var(--border); position: sticky; top: 0; z-index: 100; }
.nav-logo { font-size: 1.25rem; font-weight: 700; color: var(--brand); letter-spacing: -0.5px; display: flex; align-items: center; gap: 8px; text-decoration: none; }
.nav-logo svg { width: 22px; height: 22px; }

/* Buttons */
.btn { display: inline-flex; align-items: center; gap: 6px; padding: 8px 16px; border-radius: 8px; font-size: 0.875rem; font-weight: 500; cursor: pointer; transition: all 0.15s; border: none; text-decoration: none; }
.btn-primary { background: var(--brand); color: #0a0e1a; }
.btn-primary:hover { background: var(--brand-dark); transform: translateY(-1px); box-shadow: 0 4px 12px rgba(45,212,191,0.25); }
.btn-secondary { background: var(--surface-2); color: var(--text-dim); border: 1px solid var(--border); }
.btn-secondary:hover { border-color: var(--brand); color: var(--brand); background: rgba(45,212,191,0.08); }
.btn-ghost { background: transparent; color: var(--text-muted); }
.btn-ghost:hover { color: var(--text); background: var(--surface-2); }
.btn-danger { background: rgba(239,68,68,0.15); color: #f87171; border: 1px solid rgba(239,68,68,0.3); }
.btn-danger:hover { background: rgba(239,68,68,0.25); }
.btn-sm { padding: 5px 10px; font-size: 0.8rem; border-radius: 6px; }

/* Cards */
.card { background: var(--surface-1); border: 1px solid var(--border); border-radius: 12px; overflow: hidden; transition: border-color 0.15s; }
.card:hover { border-color: var(--border-light); }

/* Input */
.input { background: var(--surface-2); border: 1px solid var(--border); color: var(--text); padding: 10px 14px; border-radius: 8px; font-size: 0.875rem; width: 100%; font-family: inherit; transition: border-color 0.15s; outline: none; }
.input:focus { border-color: var(--brand); box-shadow: 0 0 0 3px rgba(45,212,191,0.1); }
.input::placeholder { color: var(--text-muted); }

/* Badge */
.badge { display: inline-flex; align-items: center; gap: 4px; padding: 2px 8px; border-radius: 20px; font-size: 0.7rem; font-weight: 600; letter-spacing: 0.5px; text-transform: uppercase; }
.badge-private { background: rgba(251,191,36,0.15); color: #fbbf24; border: 1px solid rgba(251,191,36,0.3); }
.badge-public { background: rgba(45,212,191,0.1); color: var(--brand); border: 1px solid rgba(45,212,191,0.2); }

/* Language dot */
.lang-dot { width: 10px; height: 10px; border-radius: 50%; display: inline-block; }

/* File browser */
.file-row { display: flex; align-items: center; gap: 10px; padding: 10px 16px; border-bottom: 1px solid var(--border); transition: background 0.1s; cursor: pointer; text-decoration: none; color: inherit; }
.file-row:last-child { border-bottom: none; }
.file-row:hover { background: rgba(45,212,191,0.04); }
.file-name { flex: 1; font-size: 0.875rem; color: var(--text-dim); }
.file-name:hover { color: var(--brand); }
.file-size { font-size: 0.75rem; color: var(--text-muted); width: 80px; text-align: right; }
.file-date { font-size: 0.75rem; color: var(--text-muted); width: 100px; text-align: right; }

/* Code editor */
#editor-wrap { position: relative; }
#code-editor { width: 100%; min-height: 400px; background: #0d1117; color: #e6edf3; border: none; padding: 16px; font-family: 'JetBrains Mono', monospace; font-size: 0.82rem; line-height: 1.7; resize: vertical; outline: none; border-radius: 0 0 12px 12px; tab-size: 2; }
.code-view { background: #0d1117; color: #e6edf3; padding: 20px; font-family: 'JetBrains Mono', monospace; font-size: 0.82rem; line-height: 1.7; border-radius: 0 0 12px 12px; overflow-x: auto; counter-reset: line; }
.code-line { display: flex; min-height: 1.4em; }
.line-num { color: #3d444d; min-width: 52px; padding-right: 16px; text-align: right; user-select: none; border-right: 1px solid #21262d; margin-right: 16px; }
.line-content { flex: 1; white-space: pre; }

/* README */
.readme-body { padding: 24px; color: var(--text-dim); line-height: 1.8; }
.readme-body h1 { font-size: 1.75rem; font-weight: 700; color: var(--text); border-bottom: 1px solid var(--border); padding-bottom: 12px; margin-bottom: 20px; }
.readme-body h2 { font-size: 1.35rem; font-weight: 600; color: var(--text); border-bottom: 1px solid var(--border); padding-bottom: 8px; margin: 24px 0 16px; }
.readme-body h3 { font-size: 1.1rem; font-weight: 600; color: var(--text); margin: 20px 0 12px; }
.readme-body h4,h5,h6 { font-weight: 600; color: var(--text); margin: 16px 0 8px; }
.readme-body p { margin-bottom: 16px; }
.readme-body a.md-link { color: var(--brand); text-decoration: none; }
.readme-body a.md-link:hover { text-decoration: underline; }
.readme-body .code-block { background: #0d1117; border: 1px solid #21262d; border-radius: 8px; margin: 16px 0; overflow: hidden; }
.readme-body .code-header { background: #161b22; padding: 8px 16px; display: flex; justify-content: space-between; align-items: center; border-bottom: 1px solid #21262d; }
.readme-body .code-lang { font-size: 0.75rem; color: #8b949e; font-family: 'JetBrains Mono', monospace; }
.readme-body .copy-btn { font-size: 0.75rem; padding: 3px 10px; background: transparent; border: 1px solid #30363d; color: #8b949e; border-radius: 6px; cursor: pointer; }
.readme-body .copy-btn:hover { background: #21262d; color: var(--text); }
.readme-body code.language-plaintext, .readme-body code { display: block; padding: 16px; font-family: 'JetBrains Mono', monospace; font-size: 0.82rem; color: #e6edf3; overflow-x: auto; }
.readme-body .inline-code { display: inline; background: rgba(110,118,129,0.15); color: #e6edf3; padding: 2px 6px; border-radius: 4px; font-family: 'JetBrains Mono', monospace; font-size: 0.85em; }
.readme-body blockquote { border-left: 3px solid var(--brand); padding: 8px 16px; margin: 16px 0; color: var(--text-muted); background: rgba(45,212,191,0.05); border-radius: 0 8px 8px 0; }
.readme-body ul.md-ul { list-style: disc; padding-left: 24px; margin-bottom: 16px; }
.readme-body ol.md-ol { list-style: decimal; padding-left: 24px; margin-bottom: 16px; }
.readme-body li { margin-bottom: 4px; }
.readme-body .md-img { max-width: 100%; border-radius: 8px; }
.readme-body hr { border: none; border-top: 1px solid var(--border); margin: 24px 0; }
.readme-body .table-wrap { overflow-x: auto; margin: 16px 0; }
.readme-body .md-table { width: 100%; border-collapse: collapse; }
.readme-body .md-table th, .readme-body .md-table td { border: 1px solid var(--border); padding: 8px 12px; font-size: 0.875rem; }
.readme-body .md-table th { background: var(--surface-2); font-weight: 600; }

/* Modal */
.modal-overlay { position: fixed; inset: 0; background: rgba(0,0,0,0.7); backdrop-filter: blur(4px); z-index: 200; display: flex; align-items: center; justify-content: center; opacity: 0; pointer-events: none; transition: opacity 0.2s; }
.modal-overlay.open { opacity: 1; pointer-events: all; }
.modal { background: var(--surface-1); border: 1px solid var(--border); border-radius: 16px; width: 100%; max-width: 500px; padding: 28px; transform: scale(0.95); transition: transform 0.2s; box-shadow: 0 25px 50px rgba(0,0,0,0.5); }
.modal-overlay.open .modal { transform: scale(1); }

/* Upload zone */
.upload-zone { border: 2px dashed var(--border); border-radius: 12px; padding: 40px; text-align: center; cursor: pointer; transition: all 0.2s; }
.upload-zone:hover, .upload-zone.dragover { border-color: var(--brand); background: rgba(45,212,191,0.05); }

/* Lang bar */
.lang-bar { height: 6px; border-radius: 3px; overflow: hidden; display: flex; }

/* Animations */
@keyframes fadeIn { from { opacity: 0; transform: translateY(8px); } to { opacity: 1; transform: translateY(0); } }
@keyframes pulse { 0%,100% { opacity: 1; } 50% { opacity: 0.5; } }
.fade-in { animation: fadeIn 0.3s ease forwards; }

/* Toast */
#toast { position: fixed; bottom: 24px; right: 24px; z-index: 999; display: flex; flex-direction: column; gap: 8px; }
.toast-item { background: var(--surface-2); border: 1px solid var(--border); border-radius: 10px; padding: 12px 18px; font-size: 0.875rem; display: flex; align-items: center; gap: 10px; box-shadow: 0 8px 24px rgba(0,0,0,0.4); animation: fadeIn 0.25s ease; min-width: 220px; }
.toast-item.success { border-color: rgba(45,212,191,0.4); }
.toast-item.error { border-color: rgba(239,68,68,0.4); }

/* Breadcrumb */
.breadcrumb { display: flex; align-items: center; gap: 6px; font-size: 0.9rem; flex-wrap: wrap; }
.breadcrumb a { color: var(--brand); text-decoration: none; font-weight: 500; }
.breadcrumb a:hover { text-decoration: underline; }
.breadcrumb span { color: var(--text-muted); }

/* Syntax highlight colors */
.kw { color: #ff7b72; } .str { color: #a5d6ff; } .num { color: #79c0ff; }
.com { color: #8b949e; font-style: italic; } .fn { color: #d2a8ff; }
.var { color: #ffa657; } .op { color: #79c0ff; } .tag { color: #7ee787; }

/* Stats */
.stat-card { background: var(--surface-2); border: 1px solid var(--border); border-radius: 10px; padding: 16px 20px; }

/* Responsive */
@media (max-width: 768px) {
  .file-size, .file-date { display: none; }
  .hide-mobile { display: none; }
}
</style>
</head>
<body>

<!-- NAVBAR -->
<nav class="navbar">
  <div class="max-w-7xl mx-auto px-4 py-3 flex items-center gap-4">
    <a href="/" class="nav-logo">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
        <path d="M9 19c-5 1.5-5-2.5-7-3m14 6v-3.87a3.37 3.37 0 0 0-.94-2.61c3.14-.35 6.44-1.54 6.44-7A5.44 5.44 0 0 0 20 4.77 5.07 5.07 0 0 0 19.91 1S18.73.65 16 2.48a13.38 13.38 0 0 0-7 0C6.27.65 5.09 1 5.09 1A5.07 5.07 0 0 0 5 4.77a5.44 5.44 0 0 0-1.5 3.78c0 5.42 3.3 6.61 6.44 7A3.37 3.37 0 0 0 9 18.13V22"/>
      </svg>
      GitPHP
    </a>
    
    <div class="flex-1 max-w-xs hide-mobile">
      <form action="/" method="get" style="margin:0">
        <input class="input text-sm" name="q" placeholder="Search repositories..." id="global-search" autocomplete="off" value="<?= isset($_GET['q']) ? e($_GET['q']) : '' ?>">
      </form>
    </div>
    
    <div class="ml-auto flex items-center gap-2">
      <?php if ($user): ?>
        <a href="/messages" class="btn btn-ghost btn-sm relative" title="Messages">
          <i data-lucide="message-circle" style="width:16px;height:16px"></i>
          <?php $unread = getUnreadMessageCount(); if ($unread > 0): ?>
            <span class="absolute -top-1 -right-1 w-4 h-4 rounded-full text-xs flex items-center justify-center" style="background:#ef4444;color:white;font-size:10px"><?= $unread ?></span>
          <?php endif; ?>
        </a>
        <a href="/new" class="btn btn-primary btn-sm">
          <i data-lucide="plus" style="width:14px;height:14px"></i> New
        </a>
        <a href="/<?= e($user['username']) ?>" class="btn btn-ghost btn-sm">
          <i data-lucide="user" style="width:14px;height:14px"></i>
          <span class="hide-mobile"><?= e($user['username']) ?></span>
        </a>
        <button onclick="apiPost('/api/logout').then(()=>location.href='/')" class="btn btn-ghost btn-sm">
          <i data-lucide="log-out" style="width:14px;height:14px"></i>
        </button>
      <?php else: ?>
        <button onclick="openModal('login-modal')" class="btn btn-ghost btn-sm">Sign in</button>
        <button onclick="openModal('register-modal')" class="btn btn-primary btn-sm">Sign up</button>
      <?php endif; ?>
    </div>
  </div>
</nav>

<!-- MAIN CONTENT -->
<main class="max-w-7xl mx-auto px-4 py-8">
<?php

switch ($page) {
    case 'home': renderHome($pageData); break;
    case 'search': renderSearch($pageData); break;
    case 'new-repo': renderNewRepo($pageData); break;
    case 'repo': renderRepo($pageData); break;
    case 'file': renderFile($pageData); break;
    case 'profile': renderProfile($pageData); break;
    case 'messages': renderMessages($pageData); break;
    case 'chat': renderChat($pageData); break;
    default: render404(); break;
}

function renderHome(array $d): void { ?>
<div class="fade-in">
  <?php if (!$d['user']): ?>
  <!-- Hero -->
  <div class="text-center py-16 mb-12">
    <div class="inline-flex items-center gap-2 px-4 py-2 rounded-full mb-6" style="background:rgba(45,212,191,0.1);border:1px solid rgba(45,212,191,0.2)">
      <i data-lucide="git-branch-plus" style="width:14px;height:14px;color:var(--brand)"></i>
      <span style="font-size:0.8rem;color:var(--brand);font-weight:600">Self-hosted code platform</span>
    </div>
    <h1 class="text-4xl md:text-6xl font-bold mb-4" style="letter-spacing:-1px">
      Where code <span style="color:var(--brand)">lives</span>.
    </h1>
    <p class="text-xl mb-8" style="color:var(--text-muted);max-width:500px;margin-left:auto;margin-right:auto">
      Host your repositories, collaborate, and build together. Private or public, always fast.
    </p>
    <div class="flex gap-3 justify-center">
      <button onclick="openModal('register-modal')" class="btn btn-primary" style="padding:12px 28px;font-size:1rem">
        Get started free
      </button>
      <button onclick="openModal('login-modal')" class="btn btn-secondary" style="padding:12px 28px;font-size:1rem">
        Sign in
      </button>
    </div>
  </div>
  <?php else: ?>
  <div class="grid grid-cols-1 lg:grid-cols-3 gap-6 mb-8">
    <div class="lg:col-span-2">
      <h2 class="font-semibold mb-4 flex items-center gap-2" style="color:var(--text-dim)">
        <i data-lucide="layout-grid" style="width:16px;height:16px"></i> Your repositories
      </h2>
      <?php if ($d['myRepos']): ?>
        <div class="card">
          <?php foreach ($d['myRepos'] as $repo): ?>
            <a href="/<?= e($repo['username'] ?? '') ?>/<?= e($repo['name']) ?>" class="file-row">
              <i data-lucide="<?= $repo['is_private'] ? 'lock' : 'book-open' ?>" style="width:15px;height:15px;color:var(--brand);flex-shrink:0"></i>
              <span class="file-name font-medium"><?= e($repo['name']) ?></span>
              <?php if ($repo['is_private']): ?>
                <span class="badge badge-private">Private</span>
              <?php endif; ?>
              <span class="file-date"><?= timeAgo($repo['updated_at']) ?></span>
            </a>
          <?php endforeach; ?>
        </div>
      <?php else: ?>
        <div class="card p-8 text-center" style="color:var(--text-muted)">
          <i data-lucide="inbox" style="width:32px;height:32px;margin:0 auto 12px;opacity:0.4"></i>
          <p>No repositories yet.</p>
          <a href="/new" class="btn btn-primary btn-sm mt-4">Create first repo</a>
        </div>
      <?php endif; ?>
    </div>
    <div>
      <h2 class="font-semibold mb-4 flex items-center gap-2" style="color:var(--text-dim)">
        <i data-lucide="trending-up" style="width:16px;height:16px"></i> Quick actions
      </h2>
      <div class="flex flex-col gap-3">
        <a href="/new" class="btn btn-primary justify-start">
          <i data-lucide="plus-circle" style="width:16px;height:16px"></i> New repository
        </a>
        <a href="/<?= e($d['user']['username']) ?>" class="btn btn-secondary justify-start">
          <i data-lucide="user" style="width:16px;height:16px"></i> Your profile
        </a>
      </div>
    </div>
  </div>
  <?php endif; ?>
  
  <!-- Public repos -->
  <h2 class="font-semibold mb-4 flex items-center gap-2" style="color:var(--text-dim)">
    <i data-lucide="globe" style="width:16px;height:16px"></i> Explore public repositories
  </h2>
  <?php if ($d['repos']): ?>
  <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-4">
    <?php foreach ($d['repos'] as $r): ?>
    <a href="/<?= e($r['username']) ?>/<?= e($r['name']) ?>" class="card p-5 block hover:border-brand/40 transition-all" style="text-decoration:none">
      <div class="flex items-start justify-between mb-2">
        <div class="flex items-center gap-2">
          <div class="w-7 h-7 rounded-full flex items-center justify-center text-xs font-bold" style="background:var(--surface-3);color:var(--brand)">
            <?= strtoupper(substr($r['username'], 0, 1)) ?>
          </div>
          <span style="font-size:0.8rem;color:var(--text-muted)"><?= e($r['username']) ?></span>
        </div>
        <span class="badge badge-public">Public</span>
      </div>
      <h3 class="font-semibold mb-1" style="color:var(--text)"><?= e($r['name']) ?></h3>
      <?php if ($r['description']): ?>
        <p style="font-size:0.82rem;color:var(--text-muted);margin-bottom:12px;line-height:1.5"><?= e(substr($r['description'], 0, 100)) ?></p>
      <?php endif; ?>
      <div class="flex items-center gap-3 mt-3" style="font-size:0.78rem;color:var(--text-muted)">
        <span class="flex items-center gap-1"><i data-lucide="star" style="width:12px;height:12px"></i><?= $r['stars'] ?></span>
        <span><?= timeAgo($r['updated_at']) ?></span>
      </div>
    </a>
    <?php endforeach; ?>
  </div>
  <?php else: ?>
  <div class="card p-12 text-center" style="color:var(--text-muted)">
    <i data-lucide="package" style="width:48px;height:48px;margin:0 auto 12px;opacity:0.3"></i>
    <p class="text-lg">No public repositories yet</p>
    <p class="text-sm mt-2">Be the first to create one!</p>
  </div>
  <?php endif; ?>
</div>
<?php }

function renderNewRepo(array $d): void { ?>
<div class="max-w-2xl mx-auto fade-in">
  <div class="mb-8">
    <h1 class="text-2xl font-bold mb-1">Create a new repository</h1>
    <p style="color:var(--text-muted)">A repository contains all your project's files and history.</p>
  </div>
  
  <div class="card p-6">
    <form id="new-repo-form">
      <div class="mb-5">
        <label class="block text-sm font-medium mb-2">Repository name *</label>
        <input class="input" name="name" id="repo-name" placeholder="my-awesome-project" required pattern="[a-zA-Z0-9_.\-]+" maxlength="100">
        <p class="text-xs mt-1" style="color:var(--text-muted)">Only letters, numbers, hyphens, underscores, and dots.</p>
      </div>
      
      <div class="mb-5">
        <label class="block text-sm font-medium mb-2">Description <span style="color:var(--text-muted)">(optional)</span></label>
        <input class="input" name="description" placeholder="A short description of your repository">
      </div>
      
      <div class="mb-6 p-4 rounded-xl" style="background:var(--surface-2);border:1px solid var(--border)">
        <p class="text-sm font-medium mb-3">Visibility</p>
        <label class="flex items-center gap-3 cursor-pointer mb-3">
          <input type="radio" name="visibility" value="public" checked class="accent-brand" style="accent-color:var(--brand)">
          <div>
            <div class="flex items-center gap-2 text-sm font-medium">
              <i data-lucide="book-open" style="width:16px;height:16px;color:var(--brand)"></i> Public
            </div>
            <p class="text-xs mt-0.5" style="color:var(--text-muted)">Anyone can see this repository.</p>
          </div>
        </label>
        <label class="flex items-center gap-3 cursor-pointer">
          <input type="radio" name="visibility" value="private" class="accent-brand" style="accent-color:var(--brand)">
          <div>
            <div class="flex items-center gap-2 text-sm font-medium">
              <i data-lucide="lock" style="width:16px;height:16px;color:#fbbf24"></i> Private
            </div>
            <p class="text-xs mt-0.5" style="color:var(--text-muted)">Only you can see this repository.</p>
          </div>
        </label>
      </div>
      
      <button type="submit" class="btn btn-primary w-full justify-center" style="padding:12px">
        <i data-lucide="plus-circle" style="width:16px;height:16px"></i>
        Create repository
      </button>
    </form>
  </div>
</div>
<script>
document.getElementById('new-repo-form').addEventListener('submit', async function(e) {
  e.preventDefault();
  const fd = new FormData(this);
  fd.set('is_private', fd.get('visibility') === 'private' ? '1' : '');
  const res = await apiPost('/api/create-repo', fd);
  if (res.success) location.href = res.redirect;
  else toast(res.error, 'error');
});
</script>
<?php }

function renderRepo(array $d): void {
    $repo = $d['repo'];
    $username = $repo['username'];
    $repoName = $repo['name'];
    $subPath = $d['subPath'];
    $stats = $d['stats'];
?>
<div class="fade-in">
  <!-- Header -->
  <div class="flex flex-wrap items-start gap-4 mb-6">
    <div class="flex-1">
      <div class="breadcrumb mb-2">
        <a href="/<?= e($username) ?>"><?= e($username) ?></a>
        <span>/</span>
        <a href="/<?= e($username) ?>/<?= e($repoName) ?>" style="font-weight:700;color:var(--text)"><?= e($repoName) ?></a>
        <?php if ($subPath): 
          $parts = explode('/', $subPath); $acc = '';
          foreach ($parts as $i => $part): $acc .= ($acc ? '/' : '') . $part; ?>
          <span>/</span>
          <?php if ($i < count($parts)-1): ?>
            <a href="/<?= e($username) ?>/<?= e($repoName) ?>/tree/<?= e($acc) ?>"><?= e($part) ?></a>
          <?php else: ?>
            <span style="color:var(--text)"><?= e($part) ?></span>
          <?php endif; ?>
        <?php endforeach; endif; ?>
      </div>
      <div class="flex items-center gap-2 flex-wrap">
        <span class="badge <?= $repo['is_private'] ? 'badge-private' : 'badge-public' ?>">
          <i data-lucide="<?= $repo['is_private'] ? 'lock' : 'globe' ?>" style="width:9px;height:9px"></i>
          <?= $repo['is_private'] ? 'Private' : 'Public' ?>
        </span>
        <?php if ($repo['description']): ?>
          <span style="color:var(--text-muted);font-size:0.875rem"><?= e($repo['description']) ?></span>
        <?php endif; ?>
      </div>
    </div>
    
    <div class="flex items-center gap-2">
      <?php if (!$_SESSION['user_id'] ?? true || ($d['user'] && $d['user']['id'] != $repo['user_id'])): ?>
      <button onclick="toggleStar(<?= $repo['id'] ?>)" class="btn btn-secondary btn-sm" id="star-btn">
        <i data-lucide="star" style="width:14px;height:14px;<?= $d['isStarred'] ? 'fill:var(--brand);color:var(--brand)' : '' ?>" id="star-icon"></i>
        <span id="star-count"><?= $repo['stars'] ?></span>
      </button>
      <?php endif; ?>
      
      <?php if ($d['isOwner']): ?>
      <button onclick="openUploadModal()" class="btn btn-secondary btn-sm">
        <i data-lucide="upload-cloud" style="width:14px;height:14px"></i> Upload ZIP
      </button>
      <button onclick="openNewFileModal()" class="btn btn-primary btn-sm">
        <i data-lucide="file-plus" style="width:14px;height:14px"></i> New file
      </button>
      <?php endif; ?>
    </div>
  </div>
  
  <!-- Stats row -->
  <div class="grid grid-cols-3 md:grid-cols-4 gap-3 mb-6">
    <div class="stat-card">
      <div style="font-size:1.25rem;font-weight:700;color:var(--brand)"><?= number_format($stats['files']) ?></div>
      <div style="font-size:0.75rem;color:var(--text-muted)">Files</div>
    </div>
    <div class="stat-card">
      <div style="font-size:1.25rem;font-weight:700;color:var(--brand)"><?= number_format($stats['dirs']) ?></div>
      <div style="font-size:0.75rem;color:var(--text-muted)">Folders</div>
    </div>
    <div class="stat-card">
      <div style="font-size:1.25rem;font-weight:700;color:var(--brand)"><?= formatSize($stats['size']) ?></div>
      <div style="font-size:0.75rem;color:var(--text-muted)">Size</div>
    </div>
    <div class="stat-card hide-mobile">
      <div style="font-size:1.25rem;font-weight:700;color:var(--brand)"><?= $repo['stars'] ?></div>
      <div style="font-size:0.75rem;color:var(--text-muted)">Stars</div>
    </div>
  </div>
  
  <?php if (!empty($stats['langs'])): ?>
  <!-- Language bar -->
  <div class="mb-6">
    <?php
    $total = array_sum($stats['langs']);
    $langColors = ['PHP'=>'#777bb3','JavaScript'=>'#f1e05a','TypeScript'=>'#3178c6','Python'=>'#3572a5','HTML'=>'#e34c26','CSS'=>'#563d7c','Go'=>'#00add8','Rust'=>'#dea584','Java'=>'#b07219','C'=>'#555','C++'=>'#f34b7d','Ruby'=>'#701516','Shell'=>'#89e051','Swift'=>'#fa7343','Kotlin'=>'#a97bff','Markdown'=>'#083fa1','JSON'=>'#292929','Vue'=>'#42b883','SCSS'=>'#c6538c','SQL'=>'#e38c00'];
    arsort($stats['langs']);
    ?>
    <div class="lang-bar mb-2" style="border-radius:6px;overflow:hidden">
      <?php foreach (array_slice($stats['langs'], 0, 8, true) as $lang => $size): 
        $pct = round($size/$total*100, 1);
        $color = $langColors[$lang] ?? '#64748b';
      ?>
        <div style="width:<?= $pct ?>%;background:<?= $color ?>;height:8px" title="<?= e($lang) ?>: <?= $pct ?>%"></div>
      <?php endforeach; ?>
    </div>
    <div class="flex flex-wrap gap-3">
      <?php foreach (array_slice($stats['langs'], 0, 8, true) as $lang => $size): 
        $pct = round($size/$total*100, 1);
        $color = $langColors[$lang] ?? '#64748b';
      ?>
        <span class="flex items-center gap-1.5" style="font-size:0.78rem;color:var(--text-muted)">
          <span class="lang-dot" style="background:<?= $color ?>"></span>
          <?= e($lang) ?> <span style="opacity:0.6"><?= $pct ?>%</span>
        </span>
      <?php endforeach; ?>
    </div>
  </div>
  <?php endif; ?>
  
  <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
    <div class="lg:col-span-2">
      <!-- File browser -->
      <div class="card mb-6">
        <div class="flex items-center gap-3 px-4 py-3 border-b" style="border-color:var(--border)">
          <i data-lucide="folder-open" style="width:15px;height:15px;color:var(--brand)"></i>
          <span class="text-sm font-medium"><?= $subPath ?: 'root' ?></span>
          <span class="ml-auto text-xs" style="color:var(--text-muted)"><?= count($d['files']) ?> items</span>
        </div>
        
        <?php if ($subPath): ?>
        <a href="/<?= e($username) ?>/<?= e($repoName) ?><?= dirname($subPath) !== '.' ? '/tree/' . dirname($subPath) : '' ?>" class="file-row">
          <i data-lucide="corner-left-up" style="width:15px;height:15px;color:var(--text-muted);flex-shrink:0"></i>
          <span class="file-name" style="color:var(--text-muted)">..</span>
        </a>
        <?php endif; ?>
        
        <?php if (empty($d['files'])): ?>
        <div class="p-12 text-center" style="color:var(--text-muted)">
          <i data-lucide="inbox" style="width:32px;height:32px;margin:0 auto 12px;opacity:0.3"></i>
          <p>This directory is empty</p>
          <?php if ($d['isOwner']): ?>
          <button onclick="openUploadModal()" class="btn btn-secondary btn-sm mt-4">Upload files</button>
          <?php endif; ?>
        </div>
        <?php endif; ?>
        
        <?php foreach ($d['files'] as $f): 
          $href = $f['type'] === 'dir' 
            ? "/{$username}/{$repoName}/tree/{$f['path']}"
            : "/{$username}/{$repoName}/blob/{$f['path']}";
        ?>
        <a href="<?= e($href) ?>" class="file-row">
          <?php if ($f['type'] === 'dir'): ?>
            <i data-lucide="folder" style="width:16px;height:16px;color:#fbbf24;flex-shrink:0"></i>
          <?php else: ?>
            <i data-lucide="file-text" style="width:16px;height:16px;color:var(--text-muted);flex-shrink:0"></i>
          <?php endif; ?>
          <span class="file-name"><?= e($f['name']) ?></span>
          <?php if ($f['ext']): ?>
            <span class="badge" style="background:rgba(100,116,139,0.1);color:var(--text-muted);font-size:0.65rem"><?= e($f['ext']) ?></span>
          <?php endif; ?>
          <span class="file-size"><?= $f['type'] === 'file' ? formatSize($f['size']) : '' ?></span>
          <span class="file-date"><?= timeAgo($f['modified']) ?></span>
        </a>
        <?php endforeach; ?>
      </div>
      
      <!-- README -->
      <?php if ($d['readmeHtml']): ?>
      <div class="card">
        <div class="px-4 py-3 border-b flex items-center gap-2" style="border-color:var(--border)">
          <i data-lucide="book-open" style="width:15px;height:15px;color:var(--brand)"></i>
          <span class="text-sm font-medium">README.md</span>
        </div>
        <div class="readme-body"><?= $d['readmeHtml'] ?></div>
      </div>
      <?php endif; ?>
    </div>
    
    <!-- Sidebar -->
    <div>
      <div class="card p-4 mb-4">
        <h3 class="text-sm font-semibold mb-3 flex items-center gap-2" style="color:var(--text-dim)">
          <i data-lucide="info" style="width:14px;height:14px"></i> About
        </h3>
        <?php if ($repo['description']): ?>
          <p class="text-sm mb-3" style="color:var(--text-muted)"><?= e($repo['description']) ?></p>
        <?php endif; ?>
        <div class="space-y-2 text-sm" style="color:var(--text-muted)">
          <div class="flex items-center gap-2">
            <i data-lucide="calendar" style="width:13px;height:13px"></i>
            Created <?= timeAgo($repo['created_at']) ?>
          </div>
          <div class="flex items-center gap-2">
            <i data-lucide="refresh-cw" style="width:13px;height:13px"></i>
            Updated <?= timeAgo($repo['updated_at']) ?>
          </div>
        </div>
      </div>
      
      <?php if ($d['isOwner']): ?>
      <div class="card p-4">
        <h3 class="text-sm font-semibold mb-3 flex items-center gap-2" style="color:var(--text-dim)">
          <i data-lucide="settings" style="width:14px;height:14px"></i> Actions
        </h3>
        <div class="flex flex-col gap-2">
          <button onclick="openUploadModal()" class="btn btn-secondary btn-sm justify-start w-full">
            <i data-lucide="upload-cloud" style="width:14px;height:14px"></i> Upload ZIP project
          </button>
          <button onclick="openNewFileModal()" class="btn btn-secondary btn-sm justify-start w-full">
            <i data-lucide="file-plus" style="width:14px;height:14px"></i> Create new file
          </button>
        </div>
      </div>
      <?php endif; ?>
    </div>
  </div>
</div>

<!-- Upload Modal -->
<div class="modal-overlay" id="upload-modal">
  <div class="modal">
    <div class="flex items-center justify-between mb-5">
      <h2 class="text-lg font-semibold">Upload ZIP project</h2>
      <button onclick="closeModal('upload-modal')" class="btn btn-ghost btn-sm"><i data-lucide="x" style="width:16px;height:16px"></i></button>
    </div>
    <div class="upload-zone mb-4" id="drop-zone" onclick="document.getElementById('zip-input').click()">
      <i data-lucide="upload-cloud" style="width:32px;height:32px;margin:0 auto 12px;color:var(--brand)"></i>
      <p class="font-medium mb-1">Drop your ZIP here or click to browse</p>
      <p class="text-sm" style="color:var(--text-muted)">Max 100MB • .gitignore patterns auto-applied</p>
      <input type="file" id="zip-input" accept=".zip" class="hidden" onchange="startUpload(this.files[0])">
    </div>
    <div id="upload-progress" class="hidden">
      <div class="flex items-center justify-between mb-2 text-sm">
        <span>Uploading...</span>
        <span id="upload-pct">0%</span>
      </div>
      <div class="w-full rounded-full overflow-hidden" style="background:var(--surface-2);height:6px">
        <div id="progress-bar" style="width:0%;height:100%;background:var(--brand);transition:width 0.3s;border-radius:3px"></div>
      </div>
    </div>
    <p class="text-xs mt-3" style="color:var(--text-muted)">
      <i data-lucide="shield" style="width:11px;height:11px;display:inline;margin-right:3px"></i>
      build/, dist/, node_modules/, .git/ and other ignored paths are automatically skipped.
    </p>
  </div>
</div>

<!-- New File Modal -->
<div class="modal-overlay" id="new-file-modal">
  <div class="modal">
    <div class="flex items-center justify-between mb-5">
      <h2 class="text-lg font-semibold">Create new file</h2>
      <button onclick="closeModal('new-file-modal')" class="btn btn-ghost btn-sm"><i data-lucide="x" style="width:16px;height:16px"></i></button>
    </div>
    <div class="mb-4">
      <label class="block text-sm font-medium mb-2">File path</label>
      <input class="input" id="new-file-path" placeholder="e.g. src/main.js or README.md">
      <p class="text-xs mt-1" style="color:var(--text-muted)">Use slashes for subdirectories: src/components/Button.jsx</p>
    </div>
    <div class="flex gap-3">
      <button onclick="createNewFile()" class="btn btn-primary flex-1 justify-center">
        <i data-lucide="file-plus" style="width:14px;height:14px"></i> Create
      </button>
      <button onclick="closeModal('new-file-modal')" class="btn btn-secondary">Cancel</button>
    </div>
  </div>
</div>

<script>
const REPO_USER = '<?= e($username) ?>';
const REPO_NAME = '<?= e($repoName) ?>';

function openUploadModal() { openModal('upload-modal'); }
function openNewFileModal() { openModal('new-file-modal'); }

// Drag & drop
const dropZone = document.getElementById('drop-zone');
if (dropZone) {
  dropZone.addEventListener('dragover', e => { e.preventDefault(); dropZone.classList.add('dragover'); });
  dropZone.addEventListener('dragleave', () => dropZone.classList.remove('dragover'));
  dropZone.addEventListener('drop', e => {
    e.preventDefault(); dropZone.classList.remove('dragover');
    const f = e.dataTransfer.files[0];
    if (f) startUpload(f);
  });
}

function startUpload(file) {
  if (!file) return;
  if (!file.name.endsWith('.zip')) { toast('Only ZIP files are accepted', 'error'); return; }
  if (file.size > 100*1024*1024) { toast('File exceeds 100MB limit', 'error'); return; }
  
  const prog = document.getElementById('upload-progress');
  const bar = document.getElementById('progress-bar');
  const pct = document.getElementById('upload-pct');
  prog.classList.remove('hidden');
  
  const xhr = new XMLHttpRequest();
  xhr.upload.addEventListener('progress', e => {
    if (e.lengthComputable) {
      const p = Math.round(e.loaded/e.total*100);
      bar.style.width = p + '%';
      pct.textContent = p + '%';
    }
  });
  xhr.addEventListener('load', () => {
    const res = JSON.parse(xhr.responseText);
    closeModal('upload-modal');
    if (res.success) {
      toast(`✓ Extracted ${res.extracted} files (${res.skipped} skipped)`, 'success');
      setTimeout(() => location.reload(), 1200);
    } else {
      toast(res.error || 'Upload failed', 'error');
    }
    prog.classList.add('hidden');
    bar.style.width = '0%';
  });
  xhr.open('POST', `/api/upload/${REPO_USER}/${REPO_NAME}`);
  const fd = new FormData();
  fd.append('zipfile', file);
  xhr.send(fd);
}

function createNewFile() {
  const path = document.getElementById('new-file-path').value.trim();
  if (!path) { toast('Enter a file path', 'error'); return; }
  closeModal('new-file-modal');
  window.location.href = `/${REPO_USER}/${REPO_NAME}/blob/${path}?new=1`;
}

async function toggleStar(repoId) {
  if (!<?= json_encode((bool)$d['user']) ?>) { openModal('login-modal'); return; }
  const res = await apiPost('/api/star/' + repoId);
  const icon = document.getElementById('star-icon');
  const count = document.getElementById('star-count');
  if (res.starred) {
    icon.style.fill = 'var(--brand)'; icon.style.color = 'var(--brand)';
    count.textContent = parseInt(count.textContent) + 1;
  } else {
    icon.style.fill = ''; icon.style.color = '';
    count.textContent = Math.max(0, parseInt(count.textContent) - 1);
  }
}
</script>
<?php }

function renderFile(array $d): void {
    $repo = $d['repo'];
    $file = $d['file'];
    $username = $repo['username'];
    $repoName = $repo['name'];
    $filePath = $d['filePath'];
    $dirPath = dirname($filePath) !== '.' ? dirname($filePath) : '';
    $isNew = isset($_GET['new']);
    $isEditing = isset($_GET['edit']) || $isNew;
?>
<div class="fade-in">
  <!-- Header -->
  <div class="flex flex-wrap items-center gap-4 mb-6">
    <div class="breadcrumb flex-1">
      <a href="/<?= e($username) ?>"><?= e($username) ?></a>
      <span>/</span>
      <a href="/<?= e($username) ?>/<?= e($repoName) ?>"><?= e($repoName) ?></a>
      <?php 
        $parts = explode('/', $filePath); $acc = '';
        foreach ($parts as $i => $part): $acc .= ($acc ? '/' : '') . $part; ?>
        <span>/</span>
        <?php if ($i < count($parts)-1): ?>
          <a href="/<?= e($username) ?>/<?= e($repoName) ?>/tree/<?= e($acc) ?>"><?= e($part) ?></a>
        <?php else: ?>
          <span style="color:var(--text)"><?= e($part) ?></span>
        <?php endif; ?>
      <?php endforeach; ?>
    </div>
    
    <div class="flex gap-2">
      <?php if ($d['isOwner'] && !$file['is_binary']): ?>
        <?php if ($isEditing): ?>
          <button onclick="saveFile()" class="btn btn-primary btn-sm">
            <i data-lucide="save" style="width:14px;height:14px"></i> Save
          </button>
          <?php if (!$isNew): ?>
          <a href="/<?= e($username) ?>/<?= e($repoName) ?>/blob/<?= e($filePath) ?>" class="btn btn-secondary btn-sm">Cancel</a>
          <?php endif; ?>
        <?php else: ?>
          <a href="/<?= e($username) ?>/<?= e($repoName) ?>/blob/<?= e($filePath) ?>?edit=1" class="btn btn-secondary btn-sm">
            <i data-lucide="pencil" style="width:14px;height:14px"></i> Edit
          </a>
        <?php endif; ?>
        <?php if (!$isNew): ?>
        <button onclick="deleteFile()" class="btn btn-danger btn-sm">
          <i data-lucide="trash-2" style="width:14px;height:14px"></i>
        </button>
        <?php endif; ?>
      <?php endif; ?>
      <?php if (!$file['is_binary'] && !$isEditing): ?>
      <a href="/api/raw/<?= e($username) ?>/<?= e($repoName) ?>/<?= e($filePath) ?>" target="_blank" class="btn btn-ghost btn-sm">
        <i data-lucide="external-link" style="width:14px;height:14px"></i> Raw
      </a>
      <?php endif; ?>
    </div>
  </div>
  
  <!-- File card -->
  <div class="card">
    <!-- File meta bar -->
    <div class="px-4 py-3 border-b flex items-center gap-3 flex-wrap" style="border-color:var(--border)">
      <i data-lucide="file-code" style="width:15px;height:15px;color:var(--brand)"></i>
      <span class="text-sm font-medium"><?= e($file['name']) ?></span>
      <?php if ($file['language'] !== 'unknown'): ?>
        <span class="badge" style="background:rgba(45,212,191,0.1);color:var(--brand)"><?= e($file['language']) ?></span>
      <?php endif; ?>
      <?php if (!$isNew): ?>
      <span class="ml-auto text-xs" style="color:var(--text-muted)">
        <?= formatSize($file['size']) ?> • <?= timeAgo($file['modified']) ?>
      </span>
      <?php endif; ?>
    </div>
    
    <?php if ($file['is_binary']): ?>
      <div class="p-12 text-center" style="color:var(--text-muted)">
        <i data-lucide="file" style="width:32px;height:32px;margin:0 auto 12px;opacity:0.4"></i>
        <p>Binary file — <?= formatSize($file['size']) ?></p>
      </div>
    <?php elseif ($file['content'] === null && !$isNew): ?>
      <div class="p-12 text-center" style="color:var(--text-muted)">
        <p>File too large to display (<?= formatSize($file['size']) ?>)</p>
      </div>
    <?php elseif ($isEditing): ?>
      <!-- Editor -->
      <div id="editor-wrap">
        <div style="background:#161b22;padding:8px 16px;display:flex;align-items:center;justify-content:space-between;border-bottom:1px solid #21262d">
          <div class="flex items-center gap-3">
            <span style="font-size:0.75rem;color:#8b949e">Editing: <?= e($file['name']) ?></span>
            <select id="editor-theme" onchange="changeTheme(this.value)" style="background:var(--surface-2);border:1px solid var(--border);color:var(--text-dim);padding:3px 8px;border-radius:6px;font-size:0.75rem">
              <option value="dark">Dark</option>
              <option value="light">Light</option>
            </select>
          </div>
          <div class="flex items-center gap-2 text-xs" style="color:#8b949e">
            <kbd style="background:#21262d;padding:1px 5px;border-radius:3px;font-size:0.7rem">Tab</kbd> = indent
            <kbd style="background:#21262d;padding:1px 5px;border-radius:3px;font-size:0.7rem">Ctrl+S</kbd> = save
          </div>
        </div>
        <textarea id="code-editor" spellcheck="false" autocomplete="off" autocorrect="off" autocapitalize="off"><?= isset($file['content']) ? e($file['content']) : '' ?></textarea>
      </div>
    <?php elseif ($file['ext'] === 'md'): ?>
      <div class="readme-body"><?= Markdown::render($file['content']) ?></div>
    <?php else: ?>
      <!-- Syntax-highlighted view -->
      <div class="code-view" id="code-view">
        <?php
        $lines = explode("\n", $file['content']);
        foreach ($lines as $i => $line):
          $lineHtml = e($line);
        ?>
        <div class="code-line">
          <span class="line-num"><?= $i+1 ?></span>
          <span class="line-content"><?= $lineHtml ?></span>
        </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </div>
</div>

<script>
const FILE_PATH = '<?= e(addslashes($filePath)) ?>';
const REPO_USER = '<?= e($username) ?>';
const REPO_NAME = '<?= e($repoName) ?>';

const editor = document.getElementById('code-editor');
if (editor) {
  // Tab key support
  editor.addEventListener('keydown', function(e) {
    if (e.key === 'Tab') {
      e.preventDefault();
      const s = this.selectionStart, end = this.selectionEnd;
      this.value = this.value.substring(0, s) + '  ' + this.value.substring(end);
      this.selectionStart = this.selectionEnd = s + 2;
    }
    if ((e.ctrlKey || e.metaKey) && e.key === 's') { e.preventDefault(); saveFile(); }
  });
  // Auto-resize
  editor.addEventListener('input', autoResize);
  autoResize.call(editor);
  editor.focus();
}

function autoResize() {
  this.style.height = 'auto';
  this.style.height = Math.max(400, this.scrollHeight) + 'px';
}

function changeTheme(t) {
  const ed = document.getElementById('code-editor');
  if (t === 'light') { ed.style.background='#fff'; ed.style.color='#24292f'; }
  else { ed.style.background='#0d1117'; ed.style.color='#e6edf3'; }
}

async function saveFile() {
  const content = editor ? editor.value : '';
  const res = await apiPost(`/api/save-file/${REPO_USER}/${REPO_NAME}`, {file_path: FILE_PATH, content});
  if (res.success) {
    toast('File saved!', 'success');
    setTimeout(() => location.href = `/${REPO_USER}/${REPO_NAME}/blob/${FILE_PATH}`, 800);
  } else {
    toast(res.error || 'Save failed', 'error');
  }
}

async function deleteFile() {
  if (!confirm('Delete this file permanently?')) return;
  const res = await apiPost(`/api/delete-file/${REPO_USER}/${REPO_NAME}`, {file_path: FILE_PATH});
  if (res.success) {
    toast('File deleted', 'success');
    const dir = FILE_PATH.includes('/') ? FILE_PATH.split('/').slice(0,-1).join('/') : '';
    setTimeout(() => location.href = `/${REPO_USER}/${REPO_NAME}${dir ? '/tree/'+dir : ''}`, 800);
  } else toast(res.error || 'Delete failed', 'error');
}

function copyCode(btn) {
  const code = btn.closest('.code-block').querySelector('code').textContent;
  navigator.clipboard.writeText(code).then(() => { btn.textContent = 'Copied!'; setTimeout(() => btn.textContent = 'Copy', 2000); });
}
</script>
<?php }

function renderProfile(array $d): void {
    $p = $d['profile'];
    $langColors = ['PHP'=>'#777bb3','JavaScript'=>'#f1e05a','TypeScript'=>'#3178c6','Python'=>'#3572a5','HTML'=>'#e34c26','CSS'=>'#563d7c','Go'=>'#00add8','Rust'=>'#dea584','Java'=>'#b07219'];
?>
<div class="fade-in">
  <div class="grid grid-cols-1 lg:grid-cols-4 gap-8">
    <!-- Profile sidebar -->
    <div>
      <div class="w-24 h-24 rounded-full flex items-center justify-center text-3xl font-bold mb-4" style="background:linear-gradient(135deg,var(--brand),#6366f1);color:#0a0e1a">
        <?= strtoupper(substr($p['username'], 0, 1)) ?>
      </div>
      <h1 class="text-xl font-bold"><?= e($p['username']) ?></h1>
      <?php if ($p['bio']): ?>
        <p class="mt-2 text-sm" style="color:var(--text-muted)"><?= e($p['bio']) ?></p>
      <?php endif; ?>
      <p class="mt-3 text-xs" style="color:var(--text-muted)">
        <i data-lucide="calendar" style="width:12px;height:12px;display:inline;margin-right:4px"></i>
        Joined <?= timeAgo($p['created_at']) ?>
      </p>
      <p class="mt-2 text-sm font-medium" style="color:var(--brand)"><?= count($d['repos']) ?> repositories</p>
    </div>
    
    <!-- Repos -->
    <div class="lg:col-span-3">
      <div class="flex items-center justify-between mb-4">
        <h2 class="font-semibold flex items-center gap-2" style="color:var(--text-dim)">
          <i data-lucide="book" style="width:16px;height:16px"></i> Repositories
        </h2>
        <?php if ($d['isOwner']): ?>
          <a href="/new" class="btn btn-primary btn-sm">
            <i data-lucide="plus" style="width:14px;height:14px"></i> New
          </a>
        <?php endif; ?>
      </div>
      
      <?php if (empty($d['repos'])): ?>
      <div class="card p-10 text-center" style="color:var(--text-muted)">
        <i data-lucide="inbox" style="width:32px;height:32px;margin:0 auto 12px;opacity:0.3"></i>
        <p>No repositories yet</p>
      </div>
      <?php else: ?>
      <div class="flex flex-col gap-3">
        <?php foreach ($d['repos'] as $r): ?>
        <a href="/<?= e($r['username']) ?>/<?= e($r['name']) ?>" class="card p-5 block" style="text-decoration:none">
          <div class="flex items-start justify-between">
            <div class="flex-1">
              <div class="flex items-center gap-2 mb-1">
                <h3 class="font-semibold" style="color:var(--brand)"><?= e($r['name']) ?></h3>
                <span class="badge <?= $r['is_private'] ? 'badge-private' : 'badge-public' ?>"><?= $r['is_private'] ? 'Private' : 'Public' ?></span>
              </div>
              <?php if ($r['description']): ?>
                <p class="text-sm mb-2" style="color:var(--text-muted)"><?= e(substr($r['description'], 0, 120)) ?></p>
              <?php endif; ?>
              <div class="flex items-center gap-4 text-xs" style="color:var(--text-muted)">
                <span class="flex items-center gap-1"><i data-lucide="star" style="width:11px;height:11px"></i><?= $r['stars'] ?></span>
                <span>Updated <?= timeAgo($r['updated_at']) ?></span>
              </div>
            </div>
          </div>
        </a>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>
    </div>
  </div>
</div>
<?php }

function renderSearch(array $d): void {
    $q = $d['query'];
    $results = $d['results'];
?>
<div class="fade-in">
  <div class="mb-6">
    <h1 class="text-xl font-bold mb-1 flex items-center gap-2">
      <i data-lucide="search" style="width:20px;height:20px;color:var(--brand)"></i>
      Search results for <span style="color:var(--brand)">"<?= e($q) ?>"</span>
    </h1>
    <p style="color:var(--text-muted);font-size:0.875rem"><?= count($results) ?> repositor<?= count($results) === 1 ? 'y' : 'ies' ?> found</p>
  </div>
  
  <!-- Search bar -->
  <div class="mb-6 max-w-lg">
    <form action="/" method="get">
      <div class="flex gap-2">
        <input class="input flex-1" name="q" value="<?= e($q) ?>" placeholder="Search repositories..." autofocus>
        <button type="submit" class="btn btn-primary">
          <i data-lucide="search" style="width:14px;height:14px"></i> Search
        </button>
      </div>
    </form>
  </div>

  <?php if (empty($results)): ?>
  <div class="card p-12 text-center" style="color:var(--text-muted)">
    <i data-lucide="search-x" style="width:40px;height:40px;margin:0 auto 12px;opacity:0.3"></i>
    <p class="text-lg font-medium mb-1">No repositories found</p>
    <p class="text-sm">Try different keywords or check your spelling</p>
    <a href="/" class="btn btn-secondary btn-sm mt-4">Browse all repos</a>
  </div>
  <?php else: ?>
  <div class="flex flex-col gap-3">
    <?php foreach ($results as $r): ?>
    <a href="/<?= e($r['username']) ?>/<?= e($r['name']) ?>" class="card p-5 block" style="text-decoration:none">
      <div class="flex items-start justify-between gap-4">
        <div class="flex-1 min-w-0">
          <div class="flex items-center gap-2 mb-1 flex-wrap">
            <span style="font-size:0.85rem;color:var(--text-muted)"><?= e($r['username']) ?></span>
            <span style="color:var(--text-muted)">/</span>
            <span class="font-semibold" style="color:var(--brand)"><?= e($r['name']) ?></span>
            <span class="badge <?= $r['is_private'] ? 'badge-private' : 'badge-public' ?>"><?= $r['is_private'] ? 'Private' : 'Public' ?></span>
          </div>
          <?php if ($r['description']): ?>
            <p class="text-sm mb-2" style="color:var(--text-muted)"><?= e(substr($r['description'], 0, 150)) ?></p>
          <?php endif; ?>
          <div class="flex items-center gap-4 text-xs" style="color:var(--text-muted)">
            <span class="flex items-center gap-1"><i data-lucide="star" style="width:11px;height:11px"></i><?= $r['stars'] ?></span>
            <span>Updated <?= timeAgo($r['updated_at']) ?></span>
          </div>
        </div>
      </div>
    </a>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>
</div>
<?php }

function render404(): void { ?>
<div class="text-center py-24 fade-in">
  <div class="text-8xl font-bold mb-4" style="color:var(--brand);opacity:0.3">404</div>
  <h1 class="text-2xl font-bold mb-2">Page not found</h1>
  <p style="color:var(--text-muted)" class="mb-6">This repository or page doesn't exist, or you don't have access.</p>
  <a href="/" class="btn btn-primary">Go home</a>
</div>
<?php } ?>
</main>

<?php
// Messages page render function
function renderMessages(array $d): void { ?>
<div class="fade-in">
  <div class="mb-6 flex items-center justify-between">
    <h1 class="text-2xl font-bold flex items-center gap-2">
      <i data-lucide="message-circle" style="width:24px;height:24px;color:var(--brand)"></i>
      Messages
    </h1>
  </div>
  
  <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
    <!-- Users list -->
    <div class="card">
      <div class="px-4 py-3 border-b flex items-center gap-2" style="border-color:var(--border)">
        <i data-lucide="users" style="width:15px;height:15px;color:var(--brand)"></i>
        <span class="text-sm font-medium">All Users</span>
      </div>
      <?php foreach ($d['users'] as $u): 
        if ($u['id'] == $d['user']['id']) continue;
      ?>
      <a href="/chat/<?= e($u['username']) ?>" class="file-row">
        <div class="w-8 h-8 rounded-full flex items-center justify-center text-xs font-bold" style="background:var(--surface-3);color:var(--brand)">
          <?= strtoupper(substr($u['username'], 0, 1)) ?>
        </div>
        <div class="flex-1">
          <div class="flex items-center gap-2">
            <span class="file-name font-medium"><?= e($u['username']) ?></span>
            <span class="w-2 h-2 rounded-full" style="background:<?= $u['online_status'] === 'online' ? '#22c55e' : '#64748b' ?>"></span>
          </div>
          <?php if ($u['bio']): ?>
            <p class="text-xs" style="color:var(--text-muted)"><?= e(substr($u['bio'], 0, 40)) ?>...</p>
          <?php endif; ?>
        </div>
      </a>
      <?php endforeach; ?>
    </div>
    
    <!-- Chat preview -->
    <div class="lg:col-span-2 card p-8 text-center" style="color:var(--text-muted)">
      <i data-lucide="message-square" style="width:48px;height:48px;margin:0 auto 16px;opacity:0.3"></i>
      <p class="text-lg">Select a user to start chatting</p>
      <p class="text-sm mt-2">Real-time messaging with online status</p>
    </div>
  </div>
</div>
<?php }

// Chat page render function
function renderChat(array $d): void {
  $otherUser = $d['otherUser'];
  $messages = getMessages($otherUser);
?>
<div class="fade-in">
  <div class="mb-4 flex items-center gap-3">
    <a href="/messages" class="btn btn-ghost btn-sm">
      <i data-lucide="arrow-left" style="width:16px;height:16px"></i>
    </a>
    <div class="flex items-center gap-3">
      <div class="w-10 h-10 rounded-full flex items-center justify-center text-sm font-bold" style="background:var(--surface-3);color:var(--brand)">
        <?= strtoupper(substr($otherUser, 0, 1)) ?>
      </div>
      <div>
        <h2 class="font-semibold"><?= e($otherUser) ?></h2>
        <span class="text-xs" style="color:var(--text-muted)" id="user-status">Checking status...</span>
      </div>
    </div>
  </div>
  
  <div class="card" style="height:calc(100vh - 250px);display:flex;flex-direction:column">
    <!-- Messages area -->
    <div id="messages-container" class="flex-1 overflow-y-auto p-4 space-y-3">
      <?php foreach ($messages as $msg): 
        $isMe = $msg['sender_name'] === $d['user']['username'];
      ?>
      <div class="flex <?= $isMe ? 'justify-end' : 'justify-start' ?>">
        <div class="max-w-[70%] px-4 py-2 rounded-xl" style="background:<?= $isMe ? 'var(--brand)' : 'var(--surface-2)' ?>;color:<?= $isMe ? '#0a0e1a' : 'var(--text)' ?>">
          <p class="text-sm"><?= e($msg['message']) ?></p>
          <p class="text-xs mt-1" style="opacity:0.7"><?= timeAgo($msg['created_at']) ?></p>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
    
    <!-- Input area -->
    <div class="border-t p-4" style="border-color:var(--border)">
      <form id="chat-form" class="flex gap-2">
        <input type="text" id="message-input" class="input flex-1" placeholder="Type a message..." autocomplete="off">
        <button type="submit" class="btn btn-primary">
          <i data-lucide="send" style="width:16px;height:16px"></i>
        </button>
      </form>
    </div>
  </div>
</div>

<script>
const OTHER_USER = '<?= e($otherUser) ?>';
const CURRENT_USER = '<?= e($d['user']['username']) ?>';
const msgContainer = document.getElementById('messages-container');

// Scroll to bottom
msgContainer.scrollTop = msgContainer.scrollHeight;

// Check user status
async function checkStatus() {
  fetch('/api/online-status/' + OTHER_USER)
    .then(r => r.json())
    .then(d => {
      const statusEl = document.getElementById('user-status');
      statusEl.textContent = d.status === 'online' ? 'Online' : 'Offline';
      statusEl.style.color = d.status === 'online' ? '#22c55e' : 'var(--text-muted)';
    });
}
checkStatus();
setInterval(checkStatus, 30000);

// Send message
document.getElementById('chat-form').addEventListener('submit', async function(e) {
  e.preventDefault();
  const input = document.getElementById('message-input');
  const msg = input.value.trim();
  if (!msg) return;
  
  await apiPost('/api/send-message', {receiver: OTHER_USER, message: msg});
  input.value = '';
  loadMessages();
});

// Load messages
async function loadMessages() {
  const res = await fetch('/api/get-messages/' + OTHER_USER);
  const msgs = await res.json();
  msgContainer.innerHTML = '';
  msgs.forEach(m => {
    const isMe = m.sender_name === CURRENT_USER;
    const div = document.createElement('div');
    div.className = 'flex ' + (isMe ? 'justify-end' : 'justify-start');
    div.innerHTML = `<div class="max-w-[70%] px-4 py-2 rounded-xl" style="background:${isMe ? 'var(--brand)' : 'var(--surface-2)'};color:${isMe ? '#0a0e1a' : 'var(--text)'}">
      <p class="text-sm">${escapeHtml(m.message)}</p>
      <p class="text-xs mt-1" style="opacity:0.7">${timeAgoStr(m.created_at)}</p>
    </div>`;
    msgContainer.appendChild(div);
  });
  msgContainer.scrollTop = msgContainer.scrollHeight;
}

function escapeHtml(text) {
  const div = document.createElement('div');
  div.textContent = text;
  return div.innerHTML;
}

function timeAgoStr(ts) {
  const diff = Math.floor(Date.now()/1000 - ts);
  if (diff < 60) return 'just now';
  if (diff < 3600) return Math.floor(diff/60) + 'm ago';
  if (diff < 86400) return Math.floor(diff/3600) + 'h ago';
  return Math.floor(diff/86400) + 'd ago';
}

// Auto-refresh messages
setInterval(loadMessages, 5000);

// Ping to stay online
setInterval(() => fetch('/api/ping'), 30000);
</script>
<?php }
?>

<!-- Auth Modals -->
<div class="modal-overlay" id="login-modal">
  <div class="modal">
    <div class="flex items-center justify-between mb-5">
      <h2 class="text-lg font-semibold">Sign in to GitPHP</h2>
      <button onclick="closeModal('login-modal')" class="btn btn-ghost btn-sm"><i data-lucide="x" style="width:16px;height:16px"></i></button>
    </div>
    <form id="login-form">
      <div class="mb-4">
        <label class="block text-sm font-medium mb-1.5">Username or email</label>
        <input class="input" name="username" required placeholder="Enter username or email">
      </div>
      <div class="mb-5">
        <label class="block text-sm font-medium mb-1.5">Password</label>
        <input class="input" name="password" type="password" required placeholder="Enter password">
      </div>
      <button type="submit" class="btn btn-primary w-full justify-center" style="padding:10px">Sign in</button>
      <p class="text-center text-sm mt-3" style="color:var(--text-muted)">
        No account? <button type="button" onclick="closeModal('login-modal');openModal('register-modal')" style="color:var(--brand);background:none;border:none;cursor:pointer;font-size:inherit">Sign up</button>
      </p>
    </form>
  </div>
</div>

<div class="modal-overlay" id="register-modal">
  <div class="modal">
    <div class="flex items-center justify-between mb-5">
      <h2 class="text-lg font-semibold">Create your account</h2>
      <button onclick="closeModal('register-modal')" class="btn btn-ghost btn-sm"><i data-lucide="x" style="width:16px;height:16px"></i></button>
    </div>
    <form id="register-form">
      <div class="mb-4">
        <label class="block text-sm font-medium mb-1.5">Username</label>
        <input class="input" name="username" required placeholder="Choose a username" pattern="[a-zA-Z0-9_\-]{3,30}">
      </div>
      <div class="mb-4">
        <label class="block text-sm font-medium mb-1.5">Email</label>
        <input class="input" name="email" type="email" required placeholder="you@example.com">
      </div>
      <div class="mb-5">
        <label class="block text-sm font-medium mb-1.5">Password</label>
        <input class="input" name="password" type="password" required placeholder="At least 6 characters" minlength="6">
      </div>
      <button type="submit" class="btn btn-primary w-full justify-center" style="padding:10px">Create account</button>
      <p class="text-center text-sm mt-3" style="color:var(--text-muted)">
        Have an account? <button type="button" onclick="closeModal('register-modal');openModal('login-modal')" style="color:var(--brand);background:none;border:none;cursor:pointer;font-size:inherit">Sign in</button>
      </p>
    </form>
  </div>
</div>

<!-- Toast container -->
<div id="toast"></div>

<script>
// ─── Core utilities ───────────────────────────────────────────────────────────
function toast(msg, type = 'success') {
  const el = document.createElement('div');
  el.className = 'toast-item ' + type;
  const icon = type === 'success' ? 'check-circle' : 'alert-circle';
  const color = type === 'success' ? 'var(--brand)' : '#f87171';
  el.innerHTML = `<svg width="16" height="16" stroke="${color}" fill="none" viewBox="0 0 24 24" stroke-width="2"><use href="#${icon}"/></svg>${msg}`;
  document.getElementById('toast').appendChild(el);
  setTimeout(() => el.style.opacity='0', 3000);
  setTimeout(() => el.remove(), 3300);
}

async function apiPost(url, data = {}) {
  let body;
  if (data instanceof FormData) {
    body = data;
  } else {
    const fd = new FormData();
    for (const [k,v] of Object.entries(data)) fd.append(k, v);
    body = fd;
  }
  const res = await fetch(url, { method: 'POST', body });
  return res.json();
}

function openModal(id) { document.getElementById(id)?.classList.add('open'); }
function closeModal(id) { document.getElementById(id)?.classList.remove('open'); }

// Close on backdrop click
document.querySelectorAll('.modal-overlay').forEach(el => {
  el.addEventListener('click', e => { if (e.target === el) closeModal(el.id); });
});

// Close on Escape
document.addEventListener('keydown', e => {
  if (e.key === 'Escape') document.querySelectorAll('.modal-overlay.open').forEach(m => m.classList.remove('open'));
});

// Auth forms
document.getElementById('login-form')?.addEventListener('submit', async function(e) {
  e.preventDefault();
  const fd = new FormData(this);
  const res = await apiPost('/api/login', Object.fromEntries(fd));
  if (res.success) location.reload();
  else toast(res.error, 'error');
});

document.getElementById('register-form')?.addEventListener('submit', async function(e) {
  e.preventDefault();
  const fd = new FormData(this);
  const res = await apiPost('/api/register', Object.fromEntries(fd));
  if (res.success) location.reload();
  else toast(res.error, 'error');
});

// Init Lucide icons
document.addEventListener('DOMContentLoaded', () => {
  if (window.lucide) lucide.createIcons();
});
window.lucide?.createIcons();
</script>
</body>
</html>
<?php
$html = ob_get_clean();

// HTTP caching headers
header('Content-Type: text/html; charset=UTF-8');
header('X-Content-Type-Options: nosniff');
if ($page === 'home' || $page === 'repo') {
    header('Cache-Control: public, max-age=30, stale-while-revalidate=60');
} else {
    header('Cache-Control: private, no-cache');
}

// Gzip if supported
if (function_exists('gzencode') && str_contains($_SERVER['HTTP_ACCEPT_ENCODING'] ?? '', 'gzip')) {
    header('Content-Encoding: gzip');
    echo gzencode($html, 6);
} else {
    echo $html;
}
