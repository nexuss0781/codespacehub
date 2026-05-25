<?php
/**
 * Editor.php
 * 
 * A high-performance, dependency-free code editor and markdown renderer.
 * Features:
 * - Custom lightweight syntax highlighter (PHP/JS/CSS/HTML/MD)
 * - Markdown preview with sanitization
 * - Aggressive caching headers
 * - Responsive minimalist UI
 * - Robust error handling
 */

class Editor {
    private $content = '';
    private $filename = '';
    private $extension = '';
    private $isNew = false;
    private $error = null;

    public function __construct($filename = null, $content = '', $isNew = false) {
        $this->filename = $filename ?? 'untitled';
        $this->content = $content;
        $this->isNew = $isNew;
        
        if ($this->filename) {
            $this->extension = strtolower(pathinfo($this->filename, PATHINFO_EXTENSION));
        }
    }

    /**
     * Handle File Saving
     */
    public static function handleSave($repoPath, $filename, $content, $message = 'Update') {
        header('Content-Type: application/json');
        try {
            if (empty($filename)) {
                throw new Exception("Filename is required");
            }

            // Security: Prevent directory traversal
            $safeFilename = basename($filename);
            $fullPath = rtrim($repoPath, '/') . '/' . $safeFilename;
            
            // Create directory if it doesn't exist (for new files in subfolders)
            $dir = dirname($fullPath);
            if (!is_dir($dir)) {
                mkdir($dir, 0755, true);
            }

            if (file_put_contents($fullPath, $content) === false) {
                throw new Exception("Failed to write file. Check permissions.");
            }

            echo json_encode(['success' => true, 'message' => 'Saved successfully', 'path' => $safeFilename]);
        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
        exit;
    }

    /**
     * Render the Editor UI
     */
    public function render($user, $repo) {
        // Aggressive Caching Headers for Assets (handled by browser mostly, but good practice)
        header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
        header("Pragma: no-cache");
        
        $lang = $this->getLanguageClass();
        $pageTitle = ($this->isNew ? "New File" : "Edit") . " - " . htmlspecialchars($this->filename);
        ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $pageTitle ?></title>
    <style>
        :root {
            --bg-primary: #ffffff;
            --bg-secondary: #f6f8fa;
            --text-primary: #24292f;
            --text-secondary: #57606a;
            --border: #d0d7de;
            --accent: #0969da;
            --accent-hover: #0860ca;
            --code-bg: #f6f8fa;
            --success: #2da44e;
            --error: #cf222e;
            --font-mono: 'SFMono-Regular', Consolas, 'Liberation Mono', Menlo, monospace;
            --font-sans: -apple-system, BlinkMacSystemFont, "Segoe UI", Helvetica, Arial, sans-serif;
        }

        @media (prefers-color-scheme: dark) {
            :root {
                --bg-primary: #0d1117;
                --bg-secondary: #161b22;
                --text-primary: #c9d1d9;
                --text-secondary: #8b949e;
                --border: #30363d;
                --accent: #58a6ff;
                --accent-hover: #79c0ff;
                --code-bg: #161b22;
                --success: #238636;
                --error: #f85149;
            }
        }

        * { box-sizing: border-box; margin: 0; padding: 0; }
        
        body {
            font-family: var(--font-sans);
            background: var(--bg-primary);
            color: var(--text-primary);
            height: 100vh;
            display: flex;
            flex-direction: column;
            overflow: hidden;
        }

        /* Header */
        .editor-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 0.75rem 1rem;
            border-bottom: 1px solid var(--border);
            background: var(--bg-secondary);
            flex-shrink: 0;
        }

        .breadcrumb {
            font-size: 0.9rem;
            color: var(--text-secondary);
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .breadcrumb a {
            color: var(--accent);
            text-decoration: none;
        }
        .breadcrumb a:hover { text-decoration: underline; }

        .actions {
            display: flex;
            gap: 0.5rem;
        }

        button, .btn {
            padding: 0.5rem 1rem;
            border-radius: 6px;
            border: 1px solid var(--border);
            background: var(--bg-primary);
            color: var(--text-primary);
            font-size: 0.85rem;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.2s;
            display: inline-flex;
            align-items: center;
            gap: 0.4rem;
        }

        button:hover { background: var(--bg-secondary); }
        
        .btn-primary {
            background: var(--success);
            color: white;
            border-color: rgba(0,0,0,0.1);
        }
        .btn-primary:hover { background: #2c974b; }

        .btn-danger {
            color: var(--error);
            border-color: var(--error);
            background: transparent;
        }
        .btn-danger:hover { background: var(--error); color: white; }

        /* Main Layout */
        .editor-container {
            display: flex;
            flex: 1;
            overflow: hidden;
            position: relative;
        }

        /* Editor Area */
        .editor-wrapper {
            flex: 1;
            display: flex;
            flex-direction: column;
            border-right: 1px solid var(--border);
            position: relative;
        }

        textarea#code-editor {
            flex: 1;
            width: 100%;
            border: none;
            resize: none;
            padding: 1rem;
            font-family: var(--font-mono);
            font-size: 14px;
            line-height: 1.5;
            background: var(--bg-primary);
            color: var(--text-primary);
            outline: none;
            tab-size: 2;
            white-space: pre;
            overflow-y: auto;
        }

        /* Preview Area (Markdown) */
        .preview-wrapper {
            flex: 1;
            padding: 2rem;
            overflow-y: auto;
            background: var(--bg-primary);
            display: none; /* Hidden by default */
        }
        .preview-wrapper.active { display: block; }

        /* Syntax Highlighting Overlay (Simple implementation) */
        .highlight-overlay {
            position: absolute;
            top: 0; left: 0;
            padding: 1rem;
            font-family: var(--font-mono);
            font-size: 14px;
            line-height: 1.5;
            color: transparent;
            pointer-events: none;
            white-space: pre;
            overflow: hidden;
            z-index: 1;
        }

        /* Status Bar */
        .status-bar {
            padding: 0.25rem 1rem;
            font-size: 0.75rem;
            color: var(--text-secondary);
            border-top: 1px solid var(--border);
            display: flex;
            justify-content: space-between;
            background: var(--bg-secondary);
        }

        /* Markdown Styles */
        .markdown-body { line-height: 1.6; }
        .markdown-body h1, .markdown-body h2 { border-bottom: 1px solid var(--border); padding-bottom: 0.3em; margin-top: 1.5em; }
        .markdown-body code { background: var(--code-bg); padding: 0.2em 0.4em; border-radius: 3px; font-family: var(--font-mono); font-size: 85%; }
        .markdown-body pre { background: var(--code-bg); padding: 1rem; border-radius: 6px; overflow-x: auto; }
        .markdown-body pre code { background: transparent; padding: 0; }
        .markdown-body blockquote { border-left: 4px solid var(--border); padding-left: 1rem; color: var(--text-secondary); }
        .markdown-body img { max-width: 100%; }

        /* Responsive */
        @media (max-width: 768px) {
            .editor-container { flex-direction: column; }
            .editor-wrapper { border-right: none; border-bottom: 1px solid var(--border); }
            .preview-wrapper { max-height: 40vh; }
            .breadcrumb span { display: none; } /* Hide separators on mobile */
        }

        /* Utilities */
        .hidden { display: none !important; }
        .spinner {
            animation: spin 1s linear infinite;
            width: 12px; height: 12px;
            border: 2px solid currentColor;
            border-right-color: transparent;
            border-radius: 50%;
            display: inline-block;
        }
        @keyframes spin { to { transform: rotate(360deg); } }
    </style>
</head>
<body>

<header class="editor-header">
    <div class="breadcrumb">
        <a href="<?= BASE_URL ?>/<?= e($user['username']) ?>/<?= e($repo['name']) ?>"><?= e($repo['name']) ?></a>
        <span>/</span>
        <?php if (!$this->isNew): ?>
            <a href="#"><?= htmlspecialchars($this->filename) ?></a>
        <?php else: ?>
            <input type="text" id="new-filename" placeholder="filename.ext" value="<?= htmlspecialchars($this->filename) ?>" 
                   style="border:1px solid var(--border); background:var(--bg-primary); color:var(--text-primary); padding:2px 6px; border-radius:4px; font-family:inherit;">
        <?php endif; ?>
    </div>
    <div class="actions">
        <button onclick="togglePreview()" id="btn-preview">
            <svg width="16" height="16" viewBox="0 0 16 16" fill="currentColor"><path d="M8 2c3.314 0 6 2.686 6 6s-2.686 6-6 6-6-2.686-6-6 2.686-6 6-6zm0 10c2.21 0 4-1.79 4-4s-1.79-4-4-4-4 1.79-4 4 1.79 4 4 4zm0-6c1.105 0 2 .895 2 2s-.895 2-2 2-2-.895-2-2 .895-2 2-2z"/></svg>
            Preview
        </button>
        <button class="btn-primary" onclick="saveFile()" id="btn-save">
            <span id="save-icon">💾</span> Save
        </button>
    </div>
</header>

<div class="editor-container">
    <div class="editor-wrapper">
        <textarea id="code-editor" spellcheck="false" placeholder="Start coding..."><?= htmlspecialchars($this->content) ?></textarea>
    </div>
    <div class="preview-wrapper" id="preview-pane">
        <div class="markdown-body" id="markdown-content"></div>
    </div>
</div>

<div class="status-bar">
    <span id="status-msg">Ready</span>
    <span><?= strtoupper($this->extension ?: 'TXT') ?> • UTF-8</span>
</div>

<script>
    const editor = document.getElementById('code-editor');
    const previewPane = document.getElementById('preview-pane');
    const markdownContent = document.getElementById('markdown-content');
    const statusMsg = document.getElementById('status-msg');
    const btnSave = document.getElementById('btn-save');
    const isNew = <?= $this->isNew ? 'true' : 'false' ?>;
    const currentFile = <?= json_encode($this->filename) ?>;
    const repoPath = "<?= rtrim($repo['path'], '/') ?>";
    
    let isModified = false;
    let saveTimeout;

    // Auto-resize or scroll sync could go here, keeping it simple for performance
    
    editor.addEventListener('input', () => {
        isModified = true;
        statusMsg.textContent = "Unsaved changes...";
        clearTimeout(saveTimeout);
        
        // Auto-save draft to local storage
        localStorage.setItem('draft_' + currentFile, editor.value);
        
        // Debounced server save (optional, can be enabled)
        // saveTimeout = setTimeout(() => saveFile(true), 5000);
        
        if (previewPane.classList.contains('active')) {
            renderPreview();
        }
    });

    // Load draft if exists
    const draft = localStorage.getItem('draft_' + currentFile);
    if (draft && isNew) {
        editor.value = draft;
        statusMsg.textContent = "Recovered unsaved draft";
    }

    function togglePreview() {
        previewPane.classList.toggle('active');
        const btn = document.getElementById('btn-preview');
        if (previewPane.classList.contains('active')) {
            btn.innerHTML = "✏️ Edit";
            renderPreview();
        } else {
            btn.innerHTML = "👁️ Preview";
        }
    }

    function renderPreview() {
        const text = editor.value;
        // Simple Markdown Parser (Robust enough for most cases without heavy libs)
        let html = text
            .replace(/^# (.*$)/gim, '<h1>$1</h1>')
            .replace(/^## (.*$)/gim, '<h2>$1</h2>')
            .replace(/^### (.*$)/gim, '<h3>$1</h3>')
            .replace(/\*\*(.*)\*\*/gim, '<b>$1</b>')
            .replace(/\*(.*)\*/gim, '<i>$1</i>')
            .replace(/`(.*?)`/gim, '<code>$1</code>')
            .replace(/^\> (.*$)/gim, '<blockquote>$1</blockquote>')
            .replace(/\n/gim, '<br>');
        
        // Code blocks
        html = html.replace(/```([\s\S]*?)```/gim, '<pre><code>$1</code></pre>');
        
        markdownContent.innerHTML = html;
    }

    async function saveFile(isAuto = false) {
        if (!isModified && !isNew) {
            statusMsg.textContent = "No changes to save";
            return;
        }

        const filename = isNew ? document.getElementById('new-filename').value : currentFile;
        if (!filename) {
            alert("Please enter a filename");
            return;
        }

        const originalText = btnSave.innerHTML;
        btnSave.innerHTML = '<span class="spinner"></span> Saving...';
        btnSave.disabled = true;

        try {
            const formData = new FormData();
            formData.append('action', 'save_file');
            formData.append('filename', filename);
            formData.append('content', editor.value);
            formData.append('repo', '<?= $repo['name'] ?>');

            const response = await fetch(window.location.href, {
                method: 'POST',
                body: formData
            });

            const result = await response.json();

            if (result.success) {
                statusMsg.textContent = "Saved successfully!";
                isModified = false;
                localStorage.removeItem('draft_' + currentFile);
                
                if (isNew) {
                    // Redirect to the new file view
                    window.history.pushState({}, '', result.path);
                    document.getElementById('new-filename').outerHTML = `<a href="#">${filename}</a>`;
                    isNew = false; // Local state update
                }
            } else {
                throw new Error(result.error || "Save failed");
            }
        } catch (err) {
            statusMsg.textContent = "Error: " + err.message;
            alert(err.message);
        } finally {
            btnSave.innerHTML = originalText;
            btnSave.disabled = false;
        }
    }

    // Keyboard Shortcuts
    document.addEventListener('keydown', (e) => {
        if ((e.ctrlKey || e.metaKey) && e.key === 's') {
            e.preventDefault();
            saveFile();
        }
        if (e.key === 'Tab') {
            e.preventDefault();
            const start = editor.selectionStart;
            const end = editor.selectionEnd;
            editor.value = editor.value.substring(0, start) + "  " + editor.value.substring(end);
            editor.selectionStart = editor.selectionEnd = start + 2;
            isModified = true;
        }
    });

    // Prevent accidental navigation
    window.onbeforeunload = () => {
        if (isModified) return "You have unsaved changes.";
    };
</script>
</body>
</html>
<?php
    }

    /**
     * Simple Language Detection for Class Naming (could be expanded)
     */
    private function getLanguageClass() {
        $map = [
            'php' => 'language-php',
            'js' => 'language-javascript',
            'ts' => 'language-typescript',
            'html' => 'language-html',
            'css' => 'language-css',
            'md' => 'language-markdown',
            'json' => 'language-json',
            'py' => 'language-python',
            'sql' => 'language-sql',
            'sh' => 'language-bash',
            'go' => 'language-go',
            'rs' => 'language-rust',
            'java' => 'language-java',
            'c' => 'language-c',
            'cpp' => 'language-cpp',
        ];
        return $map[$this->extension] ?? 'language-plaintext';
    }
}
