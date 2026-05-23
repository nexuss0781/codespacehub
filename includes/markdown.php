<?php

class Markdown {
    public static function render(string $text): string {
        // Cache by hash
        $hash = md5($text);
        $cache = CACHE_PATH . "/md_$hash.html";
        if (file_exists($cache)) return file_get_contents($cache);
        
        $html = self::parse($text);
        file_put_contents($cache, $html, LOCK_EX);
        return $html;
    }
    
    private static function parse(string $text): string {
        // Normalize line endings
        $text = str_replace("\r\n", "\n", $text);
        
        // Protect code blocks
        $codeBlocks = [];
        $text = preg_replace_callback('/```(\w*)\n(.*?)```/s', function($m) use (&$codeBlocks) {
            $lang = $m[1] ?: 'plaintext';
            $code = htmlspecialchars($m[2], ENT_QUOTES);
            $placeholder = "\x00CODE" . count($codeBlocks) . "\x00";
            $codeBlocks[$placeholder] = "<pre class=\"code-block\"><div class=\"code-header\"><span class=\"code-lang\">$lang</span><button class=\"copy-btn\" onclick=\"copyCode(this)\">Copy</button></div><code class=\"language-$lang\">$code</code></pre>";
            return $placeholder;
        }, $text);
        
        // Inline code
        $inlineCodes = [];
        $text = preg_replace_callback('/`([^`]+)`/', function($m) use (&$inlineCodes) {
            $placeholder = "\x00INLINE" . count($inlineCodes) . "\x00";
            $inlineCodes[$placeholder] = '<code class="inline-code">' . htmlspecialchars($m[1], ENT_QUOTES) . '</code>';
            return $placeholder;
        }, $text);
        
        // Headers
        $text = preg_replace('/^###### (.+)$/m', '<h6>$1</h6>', $text);
        $text = preg_replace('/^##### (.+)$/m', '<h5>$1</h5>', $text);
        $text = preg_replace('/^#### (.+)$/m', '<h4>$1</h4>', $text);
        $text = preg_replace('/^### (.+)$/m', '<h3>$1</h3>', $text);
        $text = preg_replace('/^## (.+)$/m', '<h2>$1</h2>', $text);
        $text = preg_replace('/^# (.+)$/m', '<h1>$1</h1>', $text);
        
        // Horizontal rules
        $text = preg_replace('/^---+$/m', '<hr>', $text);
        $text = preg_replace('/^\*\*\*+$/m', '<hr>', $text);
        
        // Blockquotes
        $text = preg_replace('/^> (.+)$/m', '<blockquote>$1</blockquote>', $text);
        
        // Bold & italic
        $text = preg_replace('/\*\*\*(.+?)\*\*\*/', '<strong><em>$1</em></strong>', $text);
        $text = preg_replace('/\*\*(.+?)\*\*/', '<strong>$1</strong>', $text);
        $text = preg_replace('/\*(.+?)\*/', '<em>$1</em>', $text);
        $text = preg_replace('/__(.+?)__/', '<strong>$1</strong>', $text);
        $text = preg_replace('/_(.+?)_/', '<em>$1</em>', $text);
        $text = preg_replace('/~~(.+?)~~/', '<del>$1</del>', $text);
        
        // Links & images
        $text = preg_replace('/!\[([^\]]*)\]\(([^)]+)\)/', '<img src="$2" alt="$1" class="md-img">', $text);
        $text = preg_replace('/\[([^\]]+)\]\(([^)]+)\)/', '<a href="$2" class="md-link" target="_blank" rel="noopener">$1</a>', $text);
        $text = preg_replace('/<(https?:\/\/[^>]+)>/', '<a href="$1" class="md-link" target="_blank" rel="noopener">$1</a>', $text);
        
        // Badges / shields (detect image in anchor)
        // Tables
        $text = self::parseTables($text);
        
        // Lists
        $text = self::parseLists($text);
        
        // Checkboxes
        $text = preg_replace('/\[ \]/', '<input type="checkbox" disabled>', $text);
        $text = preg_replace('/\[x\]/i', '<input type="checkbox" disabled checked>', $text);
        
        // Paragraphs
        $text = self::parseParagraphs($text);
        
        // Restore placeholders
        foreach ($codeBlocks as $k => $v) $text = str_replace($k, $v, $text);
        foreach ($inlineCodes as $k => $v) $text = str_replace($k, $v, $text);
        
        return $text;
    }
    
    private static function parseTables(string $text): string {
        return preg_replace_callback('/(\|.+\|\n)+/m', function($m) {
            $lines = array_filter(explode("\n", trim($m[0])));
            $rows = [];
            $isHead = true;
            foreach ($lines as $line) {
                if (preg_match('/^\|[\s\-\|:]+\|$/', $line)) { $isHead = false; continue; }
                $cells = array_slice(explode('|', $line), 1, -1);
                $tag = $isHead ? 'th' : 'td';
                $rowHtml = '<tr>' . implode('', array_map(fn($c) => "<$tag>" . trim($c) . "</$tag>", $cells)) . '</tr>';
                $rows[] = $rowHtml;
                if ($isHead) $isHead = false;
            }
            return '<div class="table-wrap"><table class="md-table"><tbody>' . implode('', $rows) . '</tbody></table></div>';
        }, $text);
    }
    
    private static function parseLists(string $text): string {
        // Unordered lists
        $text = preg_replace_callback('/(^[\*\-\+] .+(\n|$))+/m', function($m) {
            $items = preg_replace('/^[\*\-\+] (.+)$/m', '<li>$1</li>', trim($m[0]));
            return "<ul class=\"md-ul\">$items</ul>\n";
        }, $text);
        
        // Ordered lists
        $text = preg_replace_callback('/(^\d+\. .+(\n|$))+/m', function($m) {
            $items = preg_replace('/^\d+\. (.+)$/m', '<li>$1</li>', trim($m[0]));
            return "<ol class=\"md-ol\">$items</ol>\n";
        }, $text);
        
        return $text;
    }
    
    private static function parseParagraphs(string $text): string {
        $parts = preg_split('/\n{2,}/', $text);
        $out = [];
        foreach ($parts as $part) {
            $part = trim($part);
            if (!$part) continue;
            // Don't wrap block elements
            if (preg_match('/^<(h[1-6]|ul|ol|pre|blockquote|hr|div|table)/', $part)) {
                $out[] = $part;
            } else {
                $out[] = '<p>' . str_replace("\n", '<br>', $part) . '</p>';
            }
        }
        return implode("\n", $out);
    }
}
