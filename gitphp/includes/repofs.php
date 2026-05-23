<?php
require_once __DIR__ . '/config.php';

class RepoFS {
    private string $repoPath;
    
    public function __construct(string $username, string $repoName) {
        $this->repoPath = REPOS_PATH . "/$username/$repoName";
    }
    
    public function init(): void {
        if (!is_dir($this->repoPath)) {
            mkdir($this->repoPath, 0755, true);
        }
    }
    
    public function exists(): bool {
        return is_dir($this->repoPath);
    }
    
    public function getPath(string $subPath = ''): string {
        $path = $this->repoPath;
        if ($subPath) $path .= '/' . ltrim($subPath, '/');
        return realpath($path) ?: $path;
    }
    
    public function listDir(string $subPath = ''): array {
        $dirPath = $this->repoPath;
        if ($subPath) $dirPath .= '/' . ltrim($subPath, '/');
        
        if (!is_dir($dirPath)) return [];
        
        $items = [];
        $entries = scandir($dirPath);
        
        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') continue;
            $fullPath = "$dirPath/$entry";
            $relPath = $subPath ? "$subPath/$entry" : $entry;
            
            if (shouldIgnore($entry)) continue;
            
            $items[] = [
                'name'     => $entry,
                'path'     => $relPath,
                'type'     => is_dir($fullPath) ? 'dir' : 'file',
                'size'     => is_file($fullPath) ? filesize($fullPath) : 0,
                'modified' => filemtime($fullPath),
                'ext'      => is_file($fullPath) ? strtolower(pathinfo($entry, PATHINFO_EXTENSION)) : '',
            ];
        }
        
        // Dirs first, then files, alphabetically
        usort($items, fn($a, $b) => 
            $a['type'] !== $b['type'] 
                ? ($a['type'] === 'dir' ? -1 : 1)
                : strcasecmp($a['name'], $b['name'])
        );
        
        return $items;
    }
    
    public function getFile(string $filePath): ?array {
        $fullPath = $this->repoPath . '/' . ltrim($filePath, '/');
        
        // Security: prevent path traversal
        $real = realpath($fullPath);
        $repoReal = realpath($this->repoPath);
        if (!$real || !str_starts_with($real, $repoReal)) return null;
        
        if (!is_file($real)) return null;
        
        $size = filesize($real);
        $ext = strtolower(pathinfo($real, PATHINFO_EXTENSION));
        $isBinary = $this->isBinary($real);
        
        return [
            'name'      => basename($real),
            'path'      => $filePath,
            'size'      => $size,
            'ext'       => $ext,
            'is_binary' => $isBinary,
            'content'   => $isBinary ? null : ($size < 1048576 ? file_get_contents($real) : null),
            'modified'  => filemtime($real),
            'language'  => $this->detectLanguage($ext),
        ];
    }
    
    public function saveFile(string $filePath, string $content): bool {
        $fullPath = $this->repoPath . '/' . ltrim($filePath, '/');
        $dir = dirname($fullPath);
        if (!is_dir($dir)) mkdir($dir, 0755, true);
        return file_put_contents($fullPath, $content, LOCK_EX) !== false;
    }
    
    public function deleteFile(string $filePath): bool {
        $fullPath = $this->repoPath . '/' . ltrim($filePath, '/');
        $real = realpath($fullPath);
        $repoReal = realpath($this->repoPath);
        if (!$real || !str_starts_with($real, $repoReal)) return false;
        return is_file($real) ? unlink($real) : $this->deleteDir($real);
    }
    
    private function deleteDir(string $dir): bool {
        foreach (scandir($dir) as $item) {
            if ($item === '.' || $item === '..') continue;
            $path = "$dir/$item";
            is_dir($path) ? $this->deleteDir($path) : unlink($path);
        }
        return rmdir($dir);
    }
    
    public function extractZip(string $zipPath): array {
        $zip = new ZipArchive();
        if ($zip->open($zipPath) !== true) return ['error' => 'Cannot open ZIP file'];
        
        $extracted = 0;
        $skipped = 0;
        $totalSize = 0;
        
        // Check total size first
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $stat = $zip->statIndex($i);
            $totalSize += $stat['size'];
        }
        
        if ($totalSize > MAX_FILE_SIZE) {
            $zip->close();
            return ['error' => 'ZIP contents exceed 100MB limit'];
        }
        
        // Strip top-level folder if all files are inside one
        $topFolder = $this->detectTopFolder($zip);
        
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $stat = $zip->statIndex($i);
            $name = $stat['name'];
            
            // Remove top folder prefix
            if ($topFolder && str_starts_with($name, $topFolder)) {
                $name = substr($name, strlen($topFolder));
            }
            
            if (empty($name)) continue;
            
            // Check ignore patterns
            $parts = explode('/', $name);
            $ignore = false;
            foreach ($parts as $part) {
                if ($part && shouldIgnore($part)) { $ignore = true; break; }
            }
            
            if ($ignore) { $skipped++; continue; }
            
            $destPath = $this->repoPath . '/' . $name;
            
            if (str_ends_with($name, '/')) {
                if (!is_dir($destPath)) mkdir($destPath, 0755, true);
                continue;
            }
            
            $dir = dirname($destPath);
            if (!is_dir($dir)) mkdir($dir, 0755, true);
            
            $content = $zip->getFromIndex($i);
            if ($content !== false) {
                file_put_contents($destPath, $content, LOCK_EX);
                $extracted++;
            }
        }
        
        $zip->close();
        return ['extracted' => $extracted, 'skipped' => $skipped, 'total_size' => $totalSize];
    }
    
    private function detectTopFolder(ZipArchive $zip): string {
        $prefix = null;
        for ($i = 0; $i < min($zip->numFiles, 20); $i++) {
            $stat = $zip->statIndex($i);
            $parts = explode('/', $stat['name']);
            if ($prefix === null) $prefix = $parts[0];
            elseif ($parts[0] !== $prefix) return '';
        }
        return $prefix ? $prefix . '/' : '';
    }
    
    public function getReadme(): ?string {
        foreach (['README.md', 'readme.md', 'README.MD', 'Readme.md'] as $name) {
            $path = $this->repoPath . '/' . $name;
            if (file_exists($path)) return file_get_contents($path);
        }
        return null;
    }
    
    public function getStats(): array {
        return cache("repo_stats_{$this->repoPath}", function() {
            return $this->calcStats($this->repoPath);
        }, 60);
    }
    
    private function calcStats(string $dir, int $depth = 0): array {
        $files = 0; $dirs = 0; $size = 0; $langs = [];
        if ($depth > 10) return compact('files', 'dirs', 'size', 'langs');
        
        foreach (scandir($dir) as $entry) {
            if ($entry === '.' || $entry === '..') continue;
            if (shouldIgnore($entry)) continue;
            $path = "$dir/$entry";
            if (is_dir($path)) {
                $dirs++;
                $sub = $this->calcStats($path, $depth + 1);
                $files += $sub['files']; $size += $sub['size'];
                foreach ($sub['langs'] as $l => $c) $langs[$l] = ($langs[$l] ?? 0) + $c;
            } else {
                $files++;
                $s = filesize($path);
                $size += $s;
                $ext = strtolower(pathinfo($entry, PATHINFO_EXTENSION));
                $lang = $this->detectLanguage($ext);
                if ($lang !== 'unknown') $langs[$lang] = ($langs[$lang] ?? 0) + $s;
            }
        }
        return compact('files', 'dirs', 'size', 'langs');
    }
    
    private function isBinary(string $path): bool {
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mime = $finfo->file($path);
        return !str_starts_with($mime, 'text/') && !in_array($mime, [
            'application/json', 'application/xml', 'application/javascript',
            'application/x-httpd-php', 'application/x-sh', 'image/svg+xml'
        ]);
    }
    
    public function detectLanguage(string $ext): string {
        return match($ext) {
            'php'  => 'PHP',
            'js','jsx','mjs' => 'JavaScript',
            'ts','tsx' => 'TypeScript',
            'py'   => 'Python',
            'rb'   => 'Ruby',
            'go'   => 'Go',
            'rs'   => 'Rust',
            'java' => 'Java',
            'c','h'=> 'C',
            'cpp','cc','cxx' => 'C++',
            'cs'   => 'C#',
            'html','htm' => 'HTML',
            'css'  => 'CSS',
            'scss','sass' => 'SCSS',
            'sql'  => 'SQL',
            'sh','bash' => 'Shell',
            'md','markdown' => 'Markdown',
            'json' => 'JSON',
            'yaml','yml' => 'YAML',
            'xml'  => 'XML',
            'toml' => 'TOML',
            'vue'  => 'Vue',
            'svelte' => 'Svelte',
            'dart' => 'Dart',
            'kt'   => 'Kotlin',
            'swift' => 'Swift',
            default => 'unknown'
        };
    }
}
