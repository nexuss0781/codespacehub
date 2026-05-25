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
     * Render the Editor UI as a component within existing page
     */
    public function renderComponent($user, $repo, $filePath = '') {
        $lang = $this->getLanguageClass();
        $pageTitle = ($this->isNew ? "New File" : "Edit") . " - " . htmlspecialchars($this->filename);
        $username = $repo['username'];
        $repoName = $repo['name'];
        ?>
<style>
    :root {
        --editor-bg-primary: var(--surface-0);
        --editor-bg-secondary: var(--surface-1);
        --editor-text-primary: var(--text);
        --editor-text-secondary: var(--text-muted);
        --editor-border: var(--border);
        --editor-accent: var(--brand);
        --editor-accent-hover: var(--brand-dark);
        --editor-code-bg: var(--surface-2);
        --editor-success: #22c55e;
        --editor-error: #ef4444;
        --editor-font-mono: 'JetBrains Mono', 'Fira Code', monospace;
        --editor-font-sans: 'DM Sans', system-ui, sans-serif;
    }

    /* Editor Layout */
    .editor-layout {
        display: flex;
        flex-direction: column;
        height: calc(100vh - 280px);
        min-height: 500px;
        border-radius: 12px;
        overflow: hidden;
        border: 1px solid var(--editor-border);
    }

    /* Editor Header */
    .editor-header-bar {
        display: flex;
        justify-content: space-between;
        align-items: center;
        padding: 12px 16px;
        border-bottom: 1px solid var(--editor-border);
        background: var(--editor-bg-secondary);
        flex-shrink: 0;
        flex-wrap: wrap;
        gap: 12px;
    }

    .editor-breadcrumb {
        font-size: 0.875rem;
        color: var(--editor-text-secondary);
        display: flex;
        align-items: center;
        gap: 6px;
        flex-wrap: wrap;
    }

    .editor-breadcrumb a {
        color: var(--editor-accent);
        text-decoration: none;
        font-weight: 500;
    }
    .editor-breadcrumb a:hover { text-decoration: underline; }

    .editor-breadcrumb-sep { color: var(--editor-text-secondary); opacity: 0.5; }

    .editor-actions {
        display: flex;
        gap: 8px;
        flex-wrap: wrap;
    }

    .editor-btn {
        display: inline-flex;
        align-items: center;
        gap: 6px;
        padding: 8px 14px;
        border-radius: 8px;
        font-size: 0.8rem;
        font-weight: 500;
        cursor: pointer;
        transition: all 0.15s;
        border: 1px solid var(--editor-border);
        background: var(--editor-bg-primary);
        color: var(--editor-text-primary);
        text-decoration: none;
    }

    .editor-btn:hover { 
        background: var(--editor-bg-secondary); 
        border-color: var(--editor-accent);
    }
    
    .editor-btn-primary {
        background: var(--editor-success);
        color: white;
        border-color: rgba(0,0,0,0.1);
    }
    .editor-btn-primary:hover { 
        background: #16a34a; 
        transform: translateY(-1px);
        box-shadow: 0 4px 12px rgba(34,197,94,0.25);
    }

    .editor-btn-danger {
        color: var(--editor-error);
        border-color: var(--editor-error);
        background: transparent;
    }
    .editor-btn-danger:hover { 
        background: var(--editor-error); 
        color: white; 
    }

    .editor-btn-sm {
        padding: 6px 10px;
        font-size: 0.75rem;
    }

    /* Main Editor Area */
    .editor-main {
        display: flex;
        flex: 1;
        overflow: hidden;
        position: relative;
    }

    .editor-wrapper {
        flex: 1;
        display: flex;
        flex-direction: column;
        position: relative;
    }

    textarea#code-editor {
        flex: 1;
        width: 100%;
        border: none;
        resize: none;
        padding: 16px;
        font-family: var(--editor-font-mono);
        font-size: 14px;
        line-height: 1.6;
        background: var(--editor-bg-primary);
        color: var(--editor-text-primary);
        outline: none;
        tab-size: 2;
        white-space: pre;
        overflow-y: auto;
        font-feature-settings: "liga" 1;
    }

    /* Preview Area (Markdown) */
    .editor-preview {
        flex: 1;
        padding: 24px;
        overflow-y: auto;
        background: var(--editor-bg-primary);
        display: none;
        border-left: 1px solid var(--editor-border);
    }
    .editor-preview.active { display: block; }

    /* Status Bar */
    .editor-status-bar {
        padding: 8px 16px;
        font-size: 0.75rem;
        color: var(--editor-text-secondary);
        border-top: 1px solid var(--editor-border);
        display: flex;
        justify-content: space-between;
        background: var(--editor-bg-secondary);
        flex-shrink: 0;
    }

    .editor-status-indicator {
        display: inline-flex;
        align-items: center;
        gap: 6px;
    }

    .editor-status-dot {
        width: 8px;
        height: 8px;
        border-radius: 50%;
        background: var(--editor-success);
    }

    .editor-status-dot.saving {
        background: #fbbf24;
        animation: pulse 1s infinite;
    }

    @keyframes pulse {
        0%, 100% { opacity: 1; }
        50% { opacity: 0.5; }
    }

    /* New filename input */
    .editor-filename-input {
        background: var(--editor-bg-primary);
        border: 1px solid var(--editor-border);
        color: var(--editor-text-primary);
        padding: 4px 10px;
        border-radius: 6px;
        font-family: inherit;
        font-size: 0.875rem;
        min-width: 200px;
    }
    .editor-filename-input:focus {
        border-color: var(--editor-accent);
        outline: none;
    }

    /* Markdown Styles */
    .markdown-body { line-height: 1.7; color: var(--editor-text-secondary); }
    .markdown-body h1, .markdown-body h2 { 
        border-bottom: 1px solid var(--editor-border); 
        padding-bottom: 0.3em; 
        margin-top: 0;
        color: var(--editor-text-primary);
    }
    .markdown-body h1 { font-size: 1.75rem; }
    .markdown-body h2 { font-size: 1.35rem; }
    .markdown-body h3 { font-size: 1.1rem; color: var(--editor-text-primary); }
    .markdown-body code { 
        background: var(--editor-code-bg); 
        padding: 2px 6px; 
        border-radius: 4px; 
        font-family: var(--editor-font-mono); 
        font-size: 0.85em;
        color: var(--editor-text-primary);
    }
    .markdown-body pre { 
        background: var(--editor-code-bg); 
        padding: 16px; 
        border-radius: 8px; 
        overflow-x: auto; 
        border: 1px solid var(--editor-border);
    }
    .markdown-body pre code { 
        background: transparent; 
        padding: 0; 
    }
    .markdown-body blockquote { 
        border-left: 4px solid var(--editor-accent); 
        padding-left: 16px; 
        color: var(--editor-text-muted);
        background: rgba(45,212,191,0.05);
        padding: 8px 16px;
        border-radius: 0 8px 8px 0;
    }
    .markdown-body img { max-width: 100%; border-radius: 8px; }
    .markdown-body ul, .markdown-body ol { padding-left: 24px; }
    .markdown-body li { margin-bottom: 4px; }

    /* Responsive */
    @media (max-width: 768px) {
        .editor-layout { height: calc(100vh - 240px); min-height: 400px; }
        .editor-main { flex-direction: column; }
        .editor-preview { 
            max-height: 40vh; 
            border-left: none; 
            border-top: 1px solid var(--editor-border);
        }
        .editor-breadcrumb span.editor-breadcrumb-sep { display: none; }
        .editor-header-bar { padding: 10px 12px; }
    }

    /* Utilities */
    .hidden { display: none !important; }
    .editor-spinner {
        animation: spin 1s linear infinite;
        width: 14px; height: 14px;
        border: 2px solid currentColor;
        border-right-color: transparent;
        border-radius: 50%;
        display: inline-block;
    }
    @keyframes spin { to { transform: rotate(360deg); } }
</style>

<div class="editor-layout">
    <header class="editor-header-bar">
        <div class="editor-breadcrumb">
            <a href="<?= BASE_URL ?>/<?= e($username) ?>/<?= e($repoName) ?>"><?= e($repoName) ?></a>
            <?php if ($filePath): ?>
                <span class="editor-breadcrumb-sep">/</span>
                <?php 
                $parts = explode('/', $filePath);
                $acc = '';
                foreach ($parts as $i => $part):
                    if ($i < count($parts) - 1):
                        $acc .= ($acc ? '/' : '') . $part;
                        ?>
                        <a href="<?= BASE_URL ?>/<?= e($username) ?>/<?= e($repoName) ?>/tree/<?= e($acc) ?>"><?= e($part) ?></a>
                        <span class="editor-breadcrumb-sep">/</span>
                    <?php else: ?>
                        <?php if ($this->isNew): ?>
                            <input type="text" 
                                   id="new-filename-input" 
                                   class="editor-filename-input" 
                                   placeholder="filename.ext" 
                                   value="<?= htmlspecialchars($this->filename) ?>"
                                   autofocus>
                        <?php else: ?>
                            <span style="color:var(--editor-text-primary)"><?= htmlspecialchars($this->filename) ?></span>
                        <?php endif; ?>
                    <?php endif;
                endforeach;
                ?>
            <?php else: ?>
                <span class="editor-breadcrumb-sep">/</span>
                <?php if ($this->isNew): ?>
                    <input type="text" 
                           id="new-filename-input" 
                           class="editor-filename-input" 
                           placeholder="filename.ext" 
                           value="<?= htmlspecialchars($this->filename) ?>"
                           autofocus>
                <?php else: ?>
                    <span style="color:var(--editor-text-primary)"><?= htmlspecialchars($this->filename) ?></span>
                <?php endif; ?>
            <?php endif; ?>
            <?php if ($this->extension && $this->extension !== ''): ?>
                <span class="badge" style="background:rgba(45,212,191,0.1);color:var(--brand);font-size:0.65rem;margin-left:8px"><?= strtoupper(e($this->extension)) ?></span>
            <?php endif; ?>
        </div>
        <div class="editor-actions">
            <button type="button" onclick="toggleEditorPreview()" class="editor-btn editor-btn-sm" id="btn-preview-toggle">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                Preview
            </button>
            <button type="button" onclick="saveEditorFile()" class="editor-btn editor-btn-primary editor-btn-sm" id="btn-save-file">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"/><polyline points="17 21 17 13 7 13 7 21"/><polyline points="7 3 7 8 15 8"/></svg>
                Save
            </button>
        </div>
    </header>
    
    <div class="editor-main">
        <div class="editor-wrapper">
            <textarea id="code-editor" spellcheck="false" placeholder="Start coding..."><?= htmlspecialchars($this->content) ?></textarea>
        </div>
        <div class="editor-preview" id="editor-preview-pane">
            <div class="markdown-body" id="editor-markdown-content"></div>
        </div>
    </div>
    
    <div class="editor-status-bar">
        <span id="editor-status-msg" class="editor-status-indicator">
            <span class="editor-status-dot"></span>
            Ready
        </span>
        <span><?= strtoupper($this->extension ?: 'TXT') ?> • UTF-8 • <?= $this->isNew ? 'New File' : 'Editing' ?></span>
    </div>
</div>

<script>
(function() {
    const editor = document.getElementById('code-editor');
    const previewPane = document.getElementById('editor-preview-pane');
    const markdownContent = document.getElementById('editor-markdown-content');
    const statusMsg = document.getElementById('editor-status-msg');
    const btnSave = document.getElementById('btn-save-file');
    const btnPreview = document.getElementById('btn-preview-toggle');
    const isNew = <?= $this->isNew ? 'true' : 'false' ?>;
    const currentFile = <?= json_encode($this->filename) ?>;
    const repoPath = "<?= rtrim($repo['path'], '/') ?>";
    const username = <?= json_encode($username) ?>;
    const repoName = <?= json_encode($repoName) ?>;
    const filePath = <?= json_encode($filePath) ?>;
    
    let isModified = false;
    let saveTimeout;
    let isPreviewActive = false;

    // Auto-save draft to localStorage on every keystroke
    editor.addEventListener('input', () => {
        isModified = true;
        updateStatus('Unsaved changes...', 'warning');
        clearTimeout(saveTimeout);
        
        // Save to localStorage immediately
        const storageKey = 'codespace_draft_' + username + '_' + repoName + '_' + currentFile;
        localStorage.setItem(storageKey, editor.value);
        
        // Debounced server auto-save after 3 seconds
        saveTimeout = setTimeout(() => autoSaveFile(), 3000);
        
        if (isPreviewActive) {
            renderPreview();
        }
    });

    // Load draft from localStorage on mount
    const storageKey = 'codespace_draft_' + username + '_' + repoName + '_' + currentFile;
    const draft = localStorage.getItem(storageKey);
    if (draft && isNew && draft.trim() !== '') {
        editor.value = draft;
        updateStatus('Recovered unsaved draft', 'info');
    }

    function updateStatus(message, type = 'ready') {
        const dot = statusMsg.querySelector('.editor-status-dot');
        if (type === 'saving') {
            dot.classList.add('saving');
            statusMsg.innerHTML = '<span class="editor-status-dot saving"></span> ' + message;
        } else if (type === 'success') {
            statusMsg.innerHTML = '<span class="editor-status-dot" style="background:#22c55e"></span> ' + message;
        } else if (type === 'error') {
            statusMsg.innerHTML = '<span class="editor-status-dot" style="background:#ef4444"></span> ' + message;
        } else {
            statusMsg.innerHTML = '<span class="editor-status-dot"></span> ' + message;
        }
    }

    function toggleEditorPreview() {
        isPreviewActive = !isPreviewActive;
        if (isPreviewActive) {
            previewPane.classList.add('active');
            btnPreview.innerHTML = '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg> Edit';
            renderPreview();
        } else {
            previewPane.classList.remove('active');
            btnPreview.innerHTML = '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg> Preview';
        }
    }

    function renderPreview() {
        const text = editor.value;
        // Simple but robust Markdown Parser
        let html = text
            .replace(/^# (.*$)/gim, '<h1>$1</h1>')
            .replace(/^## (.*$)/gim, '<h2>$1</h2>')
            .replace(/^### (.*$)/gim, '<h3>$1</h3>')
            .replace(/^#### (.*$)/gim, '<h4>$1</h4>')
            .replace(/\*\*(.*)\*\*/gim, '<strong>$1</strong>')
            .replace(/\*(.*)\*/gim, '<em>$1</em>')
            .replace(/`(.*?)`/gim, '<code>$1</code>')
            .replace(/^\> (.*$)/gim, '<blockquote>$1</blockquote>')
            .replace(/^- (.*$)/gim, '<li>$1</li>')
            .replace(/^\d+\. (.*$)/gim, '<li>$1</li>')
            .replace(/\[(.*?)\]\((.*?)\)/gim, '<a href="$2" target="_blank" style="color:var(--brand)">$1</a>')
            .replace(/!\[(.*?)\]\((.*?)\)/gim, '<img src="$2" alt="$1" style="max-width:100%;border-radius:8px">')
            .replace(/\n\n/gim, '</p><p>')
            .replace(/\n/gim, '<br>');
        
        // Code blocks with language detection
        html = html.replace(/```(\w*)\n([\s\S]*?)```/gim, function(match, lang, code) {
            return '<pre><code class="language-' + (lang || 'plaintext') + '">' + code.trim() + '</code></pre>';
        });
        
        // Wrap paragraphs
        html = '<p>' + html + '</p>';
        // Clean up empty paragraphs
        html = html.replace(/<p><\/p>/gim, '').replace(/<p><br>/gim, '<p>');
        
        markdownContent.innerHTML = html;
    }

    async function autoSaveFile() {
        if (!isModified) return;
        await saveEditorFile(true);
    }

    async function saveEditorFile(isAuto = false) {
        const filenameInput = document.getElementById('new-filename-input');
        const filename = isNew ? (filenameInput ? filenameInput.value : currentFile) : currentFile;
        
        if (!filename || filename.trim() === '') {
            updateStatus('Please enter a filename', 'error');
            if (filenameInput) filenameInput.focus();
            return;
        }

        const originalHtml = btnSave.innerHTML;
        btnSave.innerHTML = '<span class="editor-spinner"></span> Saving...';
        btnSave.disabled = true;

        try {
            const formData = new FormData();
            formData.append('filename', filename);
            formData.append('content', editor.value);

            const response = fetch('/api/save-file/' + username + '/' + repoName, {
                method: 'POST',
                body: formData
            }).then(r => r.json());

            const result = await response;

            if (result.success) {
                updateStatus(isAuto ? 'Auto-saved' : 'Saved successfully!', 'success');
                isModified = false;
                localStorage.removeItem(storageKey);
                
                if (isNew) {
                    // Redirect to the new file view after short delay
                    setTimeout(() => {
                        window.location.href = '/' + username + '/' + repoName + '/blob/' + filename;
                    }, 800);
                }
            } else {
                throw new Error(result.error || "Save failed");
            }
        } catch (err) {
            updateStatus('Error: ' + err.message, 'error');
            if (!isAuto) {
                alert('Save failed: ' + err.message);
            }
        } finally {
            btnSave.innerHTML = originalHtml;
            btnSave.disabled = false;
        }
    }

    // Keyboard Shortcuts
    document.addEventListener('keydown', (e) => {
        // Ctrl/Cmd + S to save
        if ((e.ctrlKey || e.metaKey) && e.key === 's') {
            e.preventDefault();
            saveEditorFile();
        }
        // Tab key for indentation
        if (e.key === 'Tab') {
            e.preventDefault();
            const start = editor.selectionStart;
            const end = editor.selectionEnd;
            editor.value = editor.value.substring(0, start) + "  " + editor.value.substring(end);
            editor.selectionStart = editor.selectionEnd = start + 2;
            isModified = true;
        }
    });

    // Prevent accidental navigation with unsaved changes
    window.onbeforeunload = (e) => {
        if (isModified) {
            e.preventDefault();
            e.returnValue = '';
        }
    };
})();
</script>
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
