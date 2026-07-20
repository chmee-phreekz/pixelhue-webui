<?php
/**
 * README.php – zeigt eine Markdown-Datei aus demselben Ordner als
 * gestaltete HTML-Seite (Dark Theme).
 *
 * Eigenständig, ohne externe Bibliothek. Unterstützt die Elemente, die in
 * üblichen READMEs vorkommen: ATX-Überschriften (# .. ######), Absätze,
 * Codeblöcke (``` mit optionaler Sprache), Inline-Code, Fett/Kursiv, Links,
 * Tabellen (GitHub-Stil), Blockquotes, geordnete/ungeordnete Listen,
 * horizontale Linien. Aus den h2/h3-Überschriften wird ein Inhaltsverzeichnis
 * gebaut.
 *
 * Aufruf:  README.php                (zeigt README.md)
 *          README.php?doc=CHANGELOG  (zeigt CHANGELOG.md, gleicher Ordner)
 */

// --- Datei bestimmen ---------------------------------------------------

$requested = $_GET['doc'] ?? 'README';
// Nur ein einfacher Dateiname, keine Pfadwechsel - sonst ließe sich jede
// Datei des Servers auslesen.
if (!preg_match('/^[A-Za-z0-9_\-]+$/', $requested)) {
    http_response_code(400);
    exit('Ungültiger Dokumentname.');
}
$path = __DIR__ . '/' . $requested . '.md';

if (!is_file($path)) {
    http_response_code(404);
    $title = htmlspecialchars($requested);
    $markdownHtml = "<p>Datei <code>{$title}.md</code> wurde nicht gefunden.</p>";
    $docTitle = $requested;
    $toc = [];
} else {
    $md = file_get_contents($path);
    [$markdownHtml, $toc, $docTitle] = render_markdown($md);
}

// ======================================================================
//  Markdown -> HTML
// ======================================================================

function render_markdown(string $md): array
{
    // Zeilenenden normalisieren
    $md = str_replace(["\r\n", "\r"], "\n", $md);

    // 1) Codeblöcke zuerst herausnehmen und durch Platzhalter ersetzen,
    //    damit ihr Inhalt von keiner weiteren Regel angefasst wird.
    $codeBlocks = [];
    $md = preg_replace_callback('/```([^\n]*)\n(.*?)\n?```/s', function ($m) use (&$codeBlocks) {
        $lang = trim($m[1]);
        $code = htmlspecialchars($m[2], ENT_QUOTES);
        $langClass = $lang !== '' ? ' data-lang="' . htmlspecialchars($lang, ENT_QUOTES) . '"' : '';
        $codeBlocks[] = "<pre{$langClass}><code>{$code}</code></pre>";
        return "\x02CODE" . (count($codeBlocks) - 1) . "\x03";
    }, $md);

    $lines = explode("\n", $md);
    $html = [];
    $toc = [];
    $docTitle = 'Dokument';
    $slugCounts = [];

    $para = [];            // gesammelte Absatzzeilen
    $listType = null;      // 'ul' | 'ol' | null
    $listItems = [];
    $tableRows = [];       // gesammelte Tabellenzeilen
    $inQuote = false;
    $quoteLines = [];

    // --- Hilfsfunktionen zum Abschließen offener Blöcke ---

    $flushPara = function () use (&$para, &$html) {
        if ($para) {
            $html[] = '<p>' . inline_markdown(implode(' ', $para)) . '</p>';
            $para = [];
        }
    };
    $flushList = function () use (&$listType, &$listItems, &$html) {
        if ($listType) {
            $html[] = "<{$listType}>";
            foreach ($listItems as $it) {
                $html[] = '<li>' . inline_markdown($it) . '</li>';
            }
            $html[] = "</{$listType}>";
            $listType = null;
            $listItems = [];
        }
    };
    $flushTable = function () use (&$tableRows, &$html) {
        if (!$tableRows) {
            return;
        }
        // Erste Zeile = Kopf, zweite = Trennzeile (wird übersprungen)
        $header = $tableRows[0];
        $body = array_slice($tableRows, 2);
        $html[] = '<div class="table-wrap"><table>';
        $html[] = '<thead><tr>';
        foreach ($header as $c) {
            $html[] = '<th>' . inline_markdown($c) . '</th>';
        }
        $html[] = '</tr></thead><tbody>';
        foreach ($body as $row) {
            $html[] = '<tr>';
            foreach ($row as $c) {
                $html[] = '<td>' . inline_markdown($c) . '</td>';
            }
            $html[] = '</tr>';
        }
        $html[] = '</tbody></table></div>';
        $tableRows = [];
    };
    $flushQuote = function () use (&$inQuote, &$quoteLines, &$html) {
        if ($inQuote) {
            $html[] = '<blockquote>' . inline_markdown(implode(' ', $quoteLines)) . '</blockquote>';
            $inQuote = false;
            $quoteLines = [];
        }
    };

    $isTableRow = function (string $s): bool {
        return strlen($s) > 0 && $s[0] === '|';
    };
    $parseTableRow = function (string $s): array {
        $s = trim($s);
        $s = preg_replace('/^\|/', '', $s);
        $s = preg_replace('/\|$/', '', $s);
        return array_map('trim', explode('|', $s));
    };

    foreach ($lines as $line) {
        // Codeblock-Platzhalter: als eigener Block durchreichen
        if (preg_match('/^\x02CODE(\d+)\x03$/', trim($line), $m)) {
            $flushPara(); $flushList(); $flushTable(); $flushQuote();
            $html[] = "\x02CODE{$m[1]}\x03";
            continue;
        }

        // Tabelle (beginnt mit |)
        if ($isTableRow($line)) {
            $flushPara(); $flushList(); $flushQuote();
            $tableRows[] = $parseTableRow($line);
            continue;
        } elseif ($tableRows) {
            $flushTable();
        }

        // Leerzeile schließt Absatz, Liste, Quote
        if (trim($line) === '') {
            $flushPara(); $flushList(); $flushQuote();
            continue;
        }

        // Horizontale Linie
        if (preg_match('/^ {0,3}(-{3,}|\*{3,}|_{3,})\s*$/', $line)) {
            $flushPara(); $flushList(); $flushQuote();
            $html[] = '<hr>';
            continue;
        }

        // Überschrift
        if (preg_match('/^(#{1,6})\s+(.*)$/', $line, $m)) {
            $flushPara(); $flushList(); $flushQuote();
            $level = strlen($m[1]);
            $text = trim($m[2]);
            $slug = slugify($text, $slugCounts);
            if ($level === 1 && $docTitle === 'Dokument') {
                $docTitle = $text;
            }
            if ($level === 2 || $level === 3) {
                $toc[] = ['level' => $level, 'text' => $text, 'slug' => $slug];
            }
            $html[] = "<h{$level} id=\"{$slug}\">" . inline_markdown($text)
                    . " <a class=\"anchor\" href=\"#{$slug}\" aria-label=\"Link\">#</a></h{$level}>";
            continue;
        }

        // Blockquote
        if (preg_match('/^>\s?(.*)$/', $line, $m)) {
            $flushPara(); $flushList();
            $inQuote = true;
            $quoteLines[] = $m[1];
            continue;
        } elseif ($inQuote) {
            $flushQuote();
        }

        // Liste (ungeordnet)
        if (preg_match('/^\s*[-*+]\s+(.*)$/', $line, $m)) {
            $flushPara(); $flushQuote();
            if ($listType !== 'ul') { $flushList(); $listType = 'ul'; }
            $listItems[] = $m[1];
            continue;
        }
        // Liste (geordnet)
        if (preg_match('/^\s*\d+\.\s+(.*)$/', $line, $m)) {
            $flushPara(); $flushQuote();
            if ($listType !== 'ol') { $flushList(); $listType = 'ol'; }
            $listItems[] = $m[1];
            continue;
        }
        if ($listType) {
            $flushList();
        }

        // sonst: Absatztext
        $para[] = trim($line);
    }

    // offene Blöcke am Dateiende schließen
    $flushPara(); $flushList(); $flushTable(); $flushQuote();

    $out = implode("\n", $html);

    // 2) Codeblock-Platzhalter zurückersetzen
    $out = preg_replace_callback('/\x02CODE(\d+)\x03/', function ($m) use ($codeBlocks) {
        return $codeBlocks[(int) $m[1]] ?? '';
    }, $out);

    return [$out, $toc, $docTitle];
}

/**
 * Inline-Formatierung: Code, Fett, Kursiv, Links. Inline-Code wird zuerst
 * geschützt, damit ** oder _ darin nicht als Formatierung gelten.
 */
function inline_markdown(string $s): string
{
    $codes = [];
    $s = preg_replace_callback('/`([^`]+)`/', function ($m) use (&$codes) {
        $codes[] = '<code>' . htmlspecialchars($m[1], ENT_QUOTES) . '</code>';
        return "\x04" . (count($codes) - 1) . "\x04";
    }, $s);

    // Rest maskieren (der Code ist bereits ausgelagert)
    $s = htmlspecialchars($s, ENT_QUOTES);

    // Links [Text](url)
    $s = preg_replace_callback('/\[([^\]]+)\]\(([^)\s]+)\)/', function ($m) {
        $url = $m[2];
        // interne Anker und http(s) erlauben
        if (!preg_match('#^(https?://|#|/|\.)#', $url)) {
            $url = '#';
        }
        $ext = str_starts_with($url, 'http') ? ' target="_blank" rel="noopener"' : '';
        return '<a href="' . htmlspecialchars($url, ENT_QUOTES) . "\"{$ext}>{$m[1]}</a>";
    }, $s);

    // Fett, dann kursiv
    $s = preg_replace('/\*\*([^*]+)\*\*/', '<strong>$1</strong>', $s);
    $s = preg_replace('/(?<![\w*])\*([^*\n]+)\*(?![\w*])/', '<em>$1</em>', $s);

    // Inline-Code zurück
    $s = preg_replace_callback('/\x04(\d+)\x04/', function ($m) use ($codes) {
        return $codes[(int) $m[1]] ?? '';
    }, $s);

    return $s;
}

function slugify(string $text, array &$counts): string
{
    // Formatierung entfernen
    $text = preg_replace('/[`*_]/', '', $text);
    $text = strtolower(trim($text));
    // Umlaute
    $text = strtr($text, ['ä' => 'ae', 'ö' => 'oe', 'ü' => 'ue', 'ß' => 'ss']);
    $text = preg_replace('/[^a-z0-9]+/', '-', $text);
    $text = trim($text, '-');
    if ($text === '') {
        $text = 'abschnitt';
    }
    // Eindeutigkeit sichern
    if (isset($counts[$text])) {
        $counts[$text]++;
        $text .= '-' . $counts[$text];
    } else {
        $counts[$text] = 0;
    }
    return $text;
}

$safeTitle = htmlspecialchars($docTitle, ENT_QUOTES);
?><!DOCTYPE html>
<html lang="de">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= $safeTitle ?></title>
<style>
:root {
    --bg:        #0d1017;
    --bg-soft:   #141924;
    --bg-code:   #0a0d13;
    --panel:     #161c28;
    --border:    #262f40;
    --border-soft:#1e2634;
    --text:      #e6e9f0;
    --text-dim:  #99a3b5;
    --text-faint:#5f6b80;
    --accent:    #4c8dff;
    --accent-2:  #a97bff;
    --green:     #2fd08a;
    --radius:    9px;
    --mono: 'SF Mono', 'JetBrains Mono', 'Fira Code', ui-monospace, Consolas, monospace;
    --sans: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif;
}

* { box-sizing: border-box; }

html { scroll-behavior: smooth; }

body {
    margin: 0;
    background:
        radial-gradient(120% 60% at 50% -5%, #171d2b 0%, transparent 55%),
        var(--bg);
    color: var(--text);
    font-family: var(--sans);
    font-size: 16px;
    line-height: 1.65;
    -webkit-font-smoothing: antialiased;
}

.layout {
    max-width: 1180px;
    margin: 0 auto;
    padding: 0 24px;
    display: grid;
    grid-template-columns: 250px minmax(0, 1fr);
    gap: 40px;
    align-items: start;
}

/* --- Inhaltsverzeichnis --- */

.toc {
    position: sticky;
    top: 0;
    max-height: 100vh;
    overflow-y: auto;
    padding: 32px 0;
    font-size: 14px;
}

.toc-title {
    text-transform: uppercase;
    letter-spacing: 0.08em;
    font-size: 11px;
    color: var(--text-faint);
    margin: 0 0 12px 4px;
    font-weight: 700;
}

.toc a {
    display: block;
    padding: 4px 10px;
    color: var(--text-dim);
    text-decoration: none;
    border-left: 2px solid transparent;
    border-radius: 0 4px 4px 0;
}

.toc a:hover { color: var(--text); background: var(--bg-soft); }
.toc a.lvl-3 { padding-left: 22px; font-size: 13px; color: var(--text-faint); }
.toc a.active { color: var(--accent); border-left-color: var(--accent); background: rgba(76,141,255,0.07); }

/* --- Inhalt --- */

.content {
    padding: 40px 0 120px;
    min-width: 0;
}

.content h1, .content h2, .content h3, .content h4 {
    line-height: 1.3;
    font-weight: 650;
    scroll-margin-top: 20px;
}

.content h1 {
    font-size: 30px;
    margin: 0 0 8px;
    padding-bottom: 18px;
    border-bottom: 1px solid var(--border);
}

.content h2 {
    font-size: 22px;
    margin: 44px 0 14px;
    padding-bottom: 8px;
    border-bottom: 1px solid var(--border-soft);
}

.content h3 { font-size: 17px; margin: 30px 0 10px; color: #d3d9e6; }
.content h4 { font-size: 15px; margin: 22px 0 8px; color: var(--text-dim); }

.content p { margin: 0 0 14px; }

.content a { color: var(--accent); text-decoration: none; }
.content a:hover { text-decoration: underline; }

/* Anker-# neben Überschriften, nur bei Hover sichtbar */
.anchor {
    color: var(--text-faint) !important;
    text-decoration: none !important;
    font-weight: 400;
    opacity: 0;
    margin-left: 6px;
    font-size: 0.8em;
}
h2:hover .anchor, h3:hover .anchor { opacity: 1; }

/* Inline-Code */
.content code {
    font-family: var(--mono);
    font-size: 0.86em;
    background: var(--bg-soft);
    border: 1px solid var(--border-soft);
    padding: 1px 6px;
    border-radius: 5px;
    color: #b9c4da;
    word-break: break-word;
}

/* Codeblock */
.content pre {
    position: relative;
    background: var(--bg-code);
    border: 1px solid var(--border);
    border-radius: var(--radius);
    padding: 16px 18px;
    overflow-x: auto;
    margin: 0 0 18px;
}

.content pre code {
    background: none;
    border: 0;
    padding: 0;
    color: #cfd6e6;
    font-size: 13.5px;
    line-height: 1.6;
    white-space: pre;
}

/* Sprach-Etikett oben rechts am Codeblock */
.content pre[data-lang]::before {
    content: attr(data-lang);
    position: absolute;
    top: 0;
    right: 0;
    font-family: var(--mono);
    font-size: 10px;
    letter-spacing: 0.05em;
    text-transform: uppercase;
    color: var(--text-faint);
    background: var(--bg-soft);
    padding: 2px 9px;
    border-radius: 0 var(--radius) 0 var(--radius);
    border-left: 1px solid var(--border);
    border-bottom: 1px solid var(--border);
}

/* Tabellen */
.table-wrap { overflow-x: auto; margin: 0 0 18px; }

.content table {
    border-collapse: collapse;
    width: 100%;
    font-size: 14px;
    background: var(--panel);
    border: 1px solid var(--border);
    border-radius: var(--radius);
    overflow: hidden;
}

.content th, .content td {
    text-align: left;
    padding: 9px 14px;
    border-bottom: 1px solid var(--border-soft);
    vertical-align: top;
}

.content th {
    background: var(--bg-soft);
    font-weight: 600;
    color: var(--text);
    white-space: nowrap;
}

.content tbody tr:last-child td { border-bottom: 0; }
.content tbody tr:hover { background: rgba(255,255,255,0.02); }
.content td code { white-space: nowrap; }

/* Listen */
.content ul, .content ol { margin: 0 0 16px; padding-left: 26px; }
.content li { margin: 4px 0; }
.content li::marker { color: var(--accent); }

/* Blockquote */
.content blockquote {
    margin: 0 0 18px;
    padding: 10px 16px;
    background: rgba(76,141,255,0.06);
    border-left: 3px solid var(--accent);
    border-radius: 0 var(--radius) var(--radius) 0;
    color: var(--text-dim);
}
.content blockquote strong { color: var(--text); }

.content hr { border: 0; border-top: 1px solid var(--border); margin: 34px 0; }

/* Kopfleiste über dem Dokument */
.docbar {
    display: flex;
    align-items: center;
    gap: 10px;
    font-size: 13px;
    color: var(--text-faint);
    margin-bottom: 26px;
    padding-top: 24px;
}
.docbar .dot { width: 7px; height: 7px; border-radius: 50%; background: var(--green); box-shadow: 0 0 8px var(--green); }
.docbar code { font-family: var(--mono); }

/* Nach-oben-Knopf */
.top {
    position: fixed;
    right: 22px;
    bottom: 22px;
    width: 40px; height: 40px;
    display: flex; align-items: center; justify-content: center;
    background: var(--panel);
    border: 1px solid var(--border);
    border-radius: 50%;
    color: var(--text-dim);
    text-decoration: none;
    font-size: 18px;
    opacity: 0;
    pointer-events: none;
    transition: opacity 0.2s;
}
.top.show { opacity: 1; pointer-events: auto; }
.top:hover { color: var(--text); border-color: var(--accent); }

/* Scrollbar */
::-webkit-scrollbar { width: 11px; height: 11px; }
::-webkit-scrollbar-thumb { background: var(--border); border-radius: 6px; border: 2px solid var(--bg); }
::-webkit-scrollbar-thumb:hover { background: #33405a; }

/* Schmal: Inhaltsverzeichnis nach oben, einspaltig */
@media (max-width: 900px) {
    .layout { grid-template-columns: 1fr; gap: 0; }
    .toc {
        position: static;
        max-height: none;
        padding: 24px 0 0;
        border-bottom: 1px solid var(--border);
        margin-bottom: 8px;
    }
    .content { padding-top: 24px; }
}
</style>
</head>
<body>
<div class="layout">

    <nav class="toc">
        <p class="toc-title"><?= $safeTitle ?></p>
        <?php foreach ($toc as $item): ?>
            <a class="lvl-<?= $item['level'] ?>" href="#<?= $item['slug'] ?>"><?= htmlspecialchars($item['text']) ?></a>
        <?php endforeach; ?>
    </nav>

    <main class="content">
        <div class="docbar">
            <span class="dot" aria-hidden="true"></span>
            <span>Dokumentation</span>
            <span>·</span>
            <code><?= htmlspecialchars($requested) ?>.md</code>
        </div>
        <?= $markdownHtml ?>
    </main>

</div>

<a href="#" class="top" id="topBtn" aria-label="Nach oben">↑</a>

<script>
// Aktiven TOC-Eintrag beim Scrollen markieren: die zuletzt oberhalb der
// Sichtlinie liegende Überschrift gewinnt. Robuster als ein schmales
// IntersectionObserver-Fenster, durch das bei großen Abständen zwischen
// zwei Überschriften keine markiert würde.
const links = [...document.querySelectorAll('.toc a')];
const map = new Map(links.map(a => [a.getAttribute('href').slice(1), a]));
const targets = [...map.keys()].map(id => document.getElementById(id)).filter(Boolean);

function syncToc() {
    const line = 120;   // Sichtlinie unter der Kopfleiste
    let current = targets[0];
    for (const t of targets) {
        if (t.getBoundingClientRect().top <= line) current = t;
        else break;
    }
    links.forEach(a => a.classList.remove('active'));
    if (current) {
        const a = map.get(current.id);
        if (a) {
            a.classList.add('active');
            // aktiven Eintrag im TOC sichtbar halten
            a.scrollIntoView({ block: 'nearest' });
        }
    }
}

let ticking = false;
addEventListener('scroll', () => {
    if (!ticking) {
        requestAnimationFrame(() => { syncToc(); ticking = false; });
        ticking = true;
    }
}, { passive: true });
syncToc();

// Nach-oben-Knopf
const topBtn = document.getElementById('topBtn');
addEventListener('scroll', () => topBtn.classList.toggle('show', scrollY > 500));
</script>
</body>
</html>
