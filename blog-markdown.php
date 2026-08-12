<?php
/**
 * blog-markdown.php — Parser Markdown premium pour les articles Assokit
 * 
 * USAGE dans blog-article.php :
 *   require_once __DIR__ . '/blog-markdown.php';
 *   echo render_blog_markdown_css();   // une fois dans le <head>
 *   echo render_blog_markdown($article['content']);
 * 
 * Supporte :
 *   - Headers (# ## ### #### ##### ######)
 *   - Tableaux GFM avec alignement (| col | col | + |---|:---:|---:|)
 *   - Listes à puces (-, *, +) et numérotées (1.)
 *   - Gras (**), italique (*), code inline (`)
 *   - Code blocs (```)
 *   - Blockquotes (>)
 *   - Liens [texte](url), images ![alt](src)
 *   - Hr (---)
 */

if (!function_exists('render_blog_markdown')) {

function render_blog_markdown(string $md): string
{
    $blocks = [];
    
    // Helper pour stocker un bloc HTML et retourner son placeholder
    $store = function(string $html) use (&$blocks): string {
        $idx = count($blocks);
        $blocks[$idx] = $html;
        return "\n\x01BMBLOCK_{$idx}\x01\n";
    };
    
    // 1. Normaliser fins de ligne
    $md = str_replace(["\r\n", "\r"], "\n", $md);
    
    // 2. Code blocks ``` ... ```
    $md = preg_replace_callback('/```(\w*)\n(.*?)\n```/s', function($m) use ($store) {
        $lang = $m[1] ? ' class="language-' . htmlspecialchars($m[1], ENT_QUOTES, 'UTF-8') . '"' : '';
        $code = htmlspecialchars($m[2], ENT_QUOTES, 'UTF-8');
        return $store('<pre class="bm-code"><code' . $lang . '>' . $code . '</code></pre>');
    }, $md);
    
    // 3. Tableaux GFM
    $md = preg_replace_callback(
        '/(?:^\|.*\|\s*\n)+^\|[\s\-:|]+\|\s*\n(?:^\|.*\|\s*\n?)+/m',
        function($m) use ($store) {
            return $store(_bm_render_table($m[0]));
        },
        $md
    );
    
    // 4. Headers (single-line, OK)
    $md = preg_replace('/^######\s+(.+)$/m', '<h6 class="bm-h6">$1</h6>', $md);
    $md = preg_replace('/^#####\s+(.+)$/m',  '<h5 class="bm-h5">$1</h5>', $md);
    $md = preg_replace('/^####\s+(.+)$/m',   '<h4 class="bm-h4">$1</h4>', $md);
    $md = preg_replace('/^###\s+(.+)$/m',    '<h3 class="bm-h3">$1</h3>', $md);
    $md = preg_replace('/^##\s+(.+)$/m',     '<h2 class="bm-h2">$1</h2>', $md);
    // Le H1 appartient au titre de la page (hero de blog-article.php). Un « # » dans le
    // corps devient donc un H2 pour éviter deux H1 sur la même page (SEO : structure Hn unique).
    $md = preg_replace('/^#\s+(.+)$/m',      '<h2 class="bm-h2">$1</h2>', $md);
    
    // 5. Hr
    $md = preg_replace('/^[\s]*[-\*]{3,}[\s]*$/m', '<hr class="bm-hr">', $md);
    
    // 6. Blockquotes
    $md = preg_replace_callback('/(?:^>\s?.*(?:\n|$))+/m', function($m) use ($store) {
        $content = preg_replace('/^>\s?/m', '', rtrim($m[0]));
        return $store('<blockquote class="bm-quote"><p>' . _bm_inline($content) . '</p></blockquote>');
    }, $md);
    
    // 7. Listes ordonnées
    $md = preg_replace_callback('/(?:^[ ]*\d+\.\s+.+(?:\n|$))+/m', function($m) use ($store) {
        $items = preg_replace_callback('/^[ ]*\d+\.\s+(.+)$/m', function($mm) {
            return '<li>' . _bm_inline(trim($mm[1])) . '</li>';
        }, rtrim($m[0]));
        return $store('<ol class="bm-ol">' . $items . '</ol>');
    }, $md);
    
    // 8. Listes à puces
    $md = preg_replace_callback('/(?:^[ ]*[-\*\+]\s+.+(?:\n|$))+/m', function($m) use ($store) {
        $items = preg_replace_callback('/^[ ]*[-\*\+]\s+(.+)$/m', function($mm) {
            return '<li>' . _bm_inline(trim($mm[1])) . '</li>';
        }, rtrim($m[0]));
        return $store('<ul class="bm-ul">' . $items . '</ul>');
    }, $md);
    
    // 9. Wrap en paragraphes : split par double saut, traiter ligne par ligne
    $output = [];
    $blocks_text = preg_split('/\n{2,}/', $md);
    
    foreach ($blocks_text as $block) {
        $block = trim($block);
        if ($block === '') continue;
        
        // Bloc qui est entièrement un placeholder ou un header → tel quel
        if (preg_match('/^(?:\x01BMBLOCK_\d+\x01\s*)+$/', $block) ||
            preg_match('/^<(h[1-6]|hr)\b/', $block)) {
            $output[] = $block;
            continue;
        }
        
        // Sinon : c'est probablement un paragraphe, mais peut contenir des placeholders
        // qui doivent être extraits en blocs séparés
        $lines = explode("\n", $block);
        $current = [];
        
        foreach ($lines as $line) {
            $trim = trim($line);
            if ($trim === '') {
                if ($current) {
                    $output[] = '<p class="bm-p">' . _bm_inline(implode(' ', $current)) . '</p>';
                    $current = [];
                }
            } elseif (preg_match('/^\x01BMBLOCK_\d+\x01$/', $trim) ||
                      preg_match('/^<(h[1-6]|hr)\b/', $trim)) {
                if ($current) {
                    $output[] = '<p class="bm-p">' . _bm_inline(implode(' ', $current)) . '</p>';
                    $current = [];
                }
                $output[] = $trim;
            } else {
                $current[] = $line;
            }
        }
        if ($current) {
            $output[] = '<p class="bm-p">' . _bm_inline(implode(' ', $current)) . '</p>';
        }
    }
    
    $html = implode("\n\n", $output);
    
    // 10. Réinjecter les blocs
    foreach ($blocks as $idx => $block_html) {
        $html = str_replace("\x01BMBLOCK_{$idx}\x01", $block_html, $html);
    }
    
    return $html;
}

function _bm_inline(string $text): string
{
    // Images AVANT liens
    $text = preg_replace_callback('/!\[([^\]]*)\]\(([^)]+)\)/', function($m) {
        $alt = htmlspecialchars($m[1], ENT_QUOTES, 'UTF-8');
        $src = htmlspecialchars($m[2], ENT_QUOTES, 'UTF-8');
        return '<img src="' . $src . '" alt="' . $alt . '" class="bm-img" loading="lazy">';
    }, $text);
    
    // Liens
    $text = preg_replace_callback('/\[([^\]]+)\]\(([^)]+)\)/', function($m) {
        $label = $m[1];
        $url = htmlspecialchars($m[2], ENT_QUOTES, 'UTF-8');
        $host = $_SERVER['HTTP_HOST'] ?? 'assokit.fr';
        $is_external = (preg_match('#^https?://#', $url) && strpos($url, $host) === false);
        $attr = $is_external ? ' target="_blank" rel="noopener"' : '';
        return '<a href="' . $url . '" class="bm-link"' . $attr . '>' . $label . '</a>';
    }, $text);
    
    // Code inline
    $text = preg_replace('/`([^`\n]+)`/', '<code class="bm-inline-code">$1</code>', $text);
    
    // Gras
    $text = preg_replace('/\*\*([^\*\n]+)\*\*/', '<strong>$1</strong>', $text);
    $text = preg_replace('/__([^_\n]+)__/', '<strong>$1</strong>', $text);
    
    // Italique
    $text = preg_replace('/(?<![*\w])\*([^\*\n]+)\*(?!\w)/', '<em>$1</em>', $text);
    $text = preg_replace('/(?<![_\w])_([^_\n]+)_(?!\w)/', '<em>$1</em>', $text);
    
    return $text;
}

function _bm_render_table(string $block): string
{
    $lines = array_values(array_filter(array_map('trim', explode("\n", $block)), fn($l) => $l !== ''));
    if (count($lines) < 2) return $block;
    
    // Trouver le séparateur
    $sep_idx = -1;
    foreach ($lines as $i => $line) {
        if (preg_match('/^\|?[\s\-:|]+\|?$/', $line) && strpos($line, '-') !== false) {
            $sep_idx = $i;
            break;
        }
    }
    if ($sep_idx < 1) return $block;
    
    $headers = _bm_split_row($lines[$sep_idx - 1]);
    $aligns = _bm_parse_alignments($lines[$sep_idx]);
    $body_lines = array_slice($lines, $sep_idx + 1);
    
    $html  = '<div class="bm-table-wrap"><table class="bm-table">';
    $html .= '<thead><tr>';
    foreach ($headers as $i => $h) {
        $align = $aligns[$i] ?? 'left';
        $html .= '<th style="text-align:' . $align . '">' . _bm_inline(trim($h)) . '</th>';
    }
    $html .= '</tr></thead><tbody>';
    
    foreach ($body_lines as $row) {
        if (preg_match('/^[\s\-:|]+$/', $row)) continue;
        $cells = _bm_split_row($row);
        $html .= '<tr>';
        foreach ($cells as $i => $c) {
            $align = $aligns[$i] ?? 'left';
            $label = htmlspecialchars(trim($headers[$i] ?? ''), ENT_QUOTES, 'UTF-8');
            $html .= '<td style="text-align:' . $align . '" data-label="' . $label . '">' . _bm_inline(trim($c)) . '</td>';
        }
        $html .= '</tr>';
    }
    $html .= '</tbody></table></div>';
    return $html;
}

function _bm_split_row(string $row): array
{
    $row = trim($row);
    $row = preg_replace('/^\|/', '', $row);
    $row = preg_replace('/\|$/', '', $row);
    return array_map('trim', explode('|', $row));
}

function _bm_parse_alignments(string $sep_line): array
{
    $cells = _bm_split_row($sep_line);
    $aligns = [];
    foreach ($cells as $cell) {
        $cell = trim($cell);
        $left  = (substr($cell, 0, 1)  === ':');
        $right = (substr($cell, -1)    === ':');
        if ($left && $right)      $aligns[] = 'center';
        elseif ($right)           $aligns[] = 'right';
        else                       $aligns[] = 'left';
    }
    return $aligns;
}

}

if (!function_exists('render_blog_markdown_css')) {
function render_blog_markdown_css(): string
{
    return <<<'CSS'
<style id="bm-styles">
:root {
  --bm-text: #1a1a2e;
  --bm-text-soft: #4a5568;
  --bm-bg: #ffffff;
  --bm-bg-soft: #f7fafc;
  --bm-bg-code: #1a202c;
  --bm-border: #e2e8f0;
  --bm-border-soft: #edf2f7;
  --bm-accent: #10b981;
  --bm-accent-dark: #059669;
  --bm-accent-soft: #d1fae5;
  --bm-radius: 12px;
}
.bm-h1 { font-size: 2.25rem; font-weight: 800; line-height: 1.2; margin: 2.5rem 0 1.25rem; color: var(--bm-text); letter-spacing: -0.02em; }
.bm-h2 { font-size: 1.75rem; font-weight: 700; line-height: 1.3; margin: 2.25rem 0 1rem; color: var(--bm-text); letter-spacing: -0.01em; }
.bm-h3 { font-size: 1.375rem; font-weight: 700; line-height: 1.4; margin: 1.75rem 0 .75rem; color: var(--bm-text); }
.bm-h4 { font-size: 1.125rem; font-weight: 600; margin: 1.5rem 0 .5rem; color: var(--bm-text); }
.bm-h5, .bm-h6 { font-size: 1rem; font-weight: 600; margin: 1.25rem 0 .5rem; color: var(--bm-text); }
.bm-p { font-size: 1.0625rem; line-height: 1.75; color: var(--bm-text-soft); margin: 0 0 1.25rem; }
.bm-ul, .bm-ol { margin: 1rem 0 1.5rem; padding-left: 1.5rem; color: var(--bm-text-soft); line-height: 1.75; font-size: 1.0625rem; }
.bm-ul li, .bm-ol li { margin-bottom: .4rem; }
.bm-ul li::marker, .bm-ol li::marker { color: var(--bm-accent); }
.bm-ol li::marker { font-weight: 600; }
.bm-link { color: var(--bm-accent); text-decoration: underline; text-decoration-thickness: 1.5px; text-underline-offset: 3px; transition: color .15s; }
.bm-link:hover { color: var(--bm-accent-dark); }
.bm-inline-code { font-family: ui-monospace, "SF Mono", Menlo, Monaco, Consolas, monospace; font-size: .9em; background: var(--bm-bg-soft); color: #d6336c; padding: 2px 6px; border-radius: 4px; border: 1px solid var(--bm-border); }
.bm-code { background: var(--bm-bg-code); color: #e2e8f0; padding: 1.25rem 1.5rem; border-radius: var(--bm-radius); overflow-x: auto; margin: 1.5rem 0; font-family: ui-monospace, "SF Mono", Menlo, Monaco, Consolas, monospace; font-size: .9rem; line-height: 1.6; }
.bm-code code { background: none; color: inherit; padding: 0; border: 0; }
.bm-quote { margin: 1.5rem 0; padding: 1rem 1.5rem; border-left: 4px solid var(--bm-accent); background: var(--bm-accent-soft); border-radius: 0 var(--bm-radius) var(--bm-radius) 0; color: var(--bm-text); font-style: italic; font-size: 1.05rem; }
.bm-quote p { margin: 0; }
.bm-hr { border: 0; height: 1px; background: linear-gradient(90deg, transparent, var(--bm-border), transparent); margin: 2.5rem 0; }
.bm-img { max-width: 100%; height: auto; border-radius: var(--bm-radius); margin: 1.5rem 0; box-shadow: 0 4px 12px rgba(0,0,0,.05); }

/* TABLEAUX PREMIUM */
.bm-table-wrap { margin: 1.75rem 0; border-radius: var(--bm-radius); border: 1px solid var(--bm-border); background: var(--bm-bg); box-shadow: 0 1px 3px rgba(0,0,0,.04); overflow: hidden; }
.bm-table { width: 100%; border-collapse: collapse; font-size: .95rem; line-height: 1.5; }
.bm-table thead { background: linear-gradient(135deg, var(--bm-accent) 0%, var(--bm-accent-dark) 100%); color: #fff; }
.bm-table th { padding: .9rem 1rem; font-weight: 600; font-size: .8rem; letter-spacing: .03em; text-transform: uppercase; }
.bm-table tbody tr { border-top: 1px solid var(--bm-border-soft); transition: background .15s; }
.bm-table tbody tr:nth-child(even) { background: var(--bm-bg-soft); }
.bm-table tbody tr:hover { background: var(--bm-accent-soft); }
.bm-table td { padding: .85rem 1rem; color: var(--bm-text-soft); vertical-align: top; }
.bm-table strong { color: var(--bm-text); }

/* MOBILE */
@media (max-width: 640px) {
  .bm-h1 { font-size: 1.75rem; }
  .bm-h2 { font-size: 1.4rem; }
  .bm-h3 { font-size: 1.2rem; }
  .bm-p, .bm-ul, .bm-ol { font-size: 1rem; }
  .bm-table-wrap { border: 0; box-shadow: none; background: transparent; overflow: visible; }
  .bm-table thead { display: none; }
  .bm-table tbody tr { display: block; margin-bottom: 1rem; border: 1px solid var(--bm-border); border-radius: var(--bm-radius); background: var(--bm-bg); overflow: hidden; box-shadow: 0 1px 3px rgba(0,0,0,.04); }
  .bm-table tbody tr:nth-child(even) { background: var(--bm-bg); }
  .bm-table tbody tr:hover { background: var(--bm-bg); }
  .bm-table td { display: flex; justify-content: space-between; align-items: flex-start; gap: 1rem; padding: .65rem 1rem; text-align: right !important; border-bottom: 1px solid var(--bm-border-soft); }
  .bm-table td:last-child { border-bottom: 0; }
  .bm-table td::before { content: attr(data-label); font-weight: 600; color: var(--bm-text); font-size: .8rem; text-transform: uppercase; letter-spacing: .03em; text-align: left; flex-shrink: 0; max-width: 45%; }
}

.bm-p:last-child, .bm-ul:last-child, .bm-ol:last-child { margin-bottom: 0; }
</style>
CSS;
}
}
