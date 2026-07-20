<?php
/**
 * wrapper_test.php – schnelles Testtool für wrapper.php
 *
 * Führt eine Reihe von GET-Queries gegen den Wrapper aus und prüft je
 * Antwort auf OK oder ERROR. Am Ende eine Zusammenfassung.
 *
 * Aufruf (Kommandozeile):
 *     php wrapper_test.php
 *
 * Aufruf im Browser geht auch – die Ausgabe ist dann einfach als <pre>
 * lesbar (Farben werden im Browser nicht dargestellt).
 *
 * Anpassen: die drei Variablen unter "Konfiguration" und das $queries-Array.
 */

// ============================ Konfiguration ============================

// Basis-URL zum Wrapper. Nur Host/Pfad bis wrapper.php - ohne ?action=...
$WRAPPER = 'http://[Server-ip]/[path]/wrapper.php';

// Prozessor-Alias aus config.php. Leer lassen, um den ersten aus der Liste
// zu verwenden, oder pro Query in der Querystring selbst setzen.
$PROCESSOR = 'P10-Entrance';

// Pause zwischen zwei Aufrufen in Mikrosekunden (1000000 = 1 Sekunde).
// 0 = keine Pause. Nützlich, damit das Gerät zwischen Befehlen nachkommt.
$USLEEP = 10000;   // 0,01 s

// Timeout je Request in Sekunden.
$TIMEOUT = 10;

// Die auszuführenden Queries. Jeweils der Teil NACH "wrapper.php?".
// action=... ist Pflicht; processor/format werden automatisch ergänzt,
// falls nicht angegeben.
$queries = [
    'action=clear_screen&screenId=1&scene=pgm',
    'action=create_layer&screenId=1&sourceType=5&sourceId=1&x=0&y=0&width=2880&height=1620',
    'action=create_layer&screenId=1&sourceId=29&x=200&y=200&width=960&height=1080',
    'action=create_layer&screenId=1&sourceId=31&x=1160&y=200&width=960&height=1080',
];

// ======================================================================
//  Ab hier nichts mehr anzupassen
// ======================================================================

$isCli = (PHP_SAPI === 'cli');

// Farben nur im Terminal
function c(string $text, string $color, bool $cli): string
{
    if (!$cli) {
        return $text;
    }
    $codes = ['green' => 32, 'red' => 31, 'yellow' => 33, 'gray' => 90, 'bold' => 1, 'cyan' => 36];
    $code = $codes[$color] ?? 0;
    return "\033[{$code}m{$text}\033[0m";
}

if (!$isCli) {
    header('Content-Type: text/plain; charset=utf-8');
    echo "<pre style=\"background:#0d1017;color:#e6e9f0;padding:16px;font-size:13px;line-height:1.5\">";
}

/**
 * Query absetzen und auswerten.
 *
 * @return array{ok: bool, status: int, detail: string, ms: float, url: string}
 */
function run_query(string $wrapperBase, string $query, string $processor, int $timeout): array
{
    // processor ergänzen, falls nicht in der Query enthalten
    if ($processor !== '' && !preg_match('/(^|&)processor=/', $query)) {
        $query .= '&processor=' . rawurlencode($processor);
    }
    $url = $wrapperBase . '?' . $query;

    $t0 = microtime(true);
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => $timeout,
        CURLOPT_CONNECTTIMEOUT => $timeout,
        CURLOPT_FOLLOWLOCATION => false,
    ]);
    $body = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr = curl_error($ch);
    curl_close($ch);
    $ms = (microtime(true) - $t0) * 1000;

    // Verbindungsfehler (kein HTTP zustande gekommen)
    if ($body === false || $curlErr !== '') {
        return ['ok' => false, 'status' => 0, 'detail' => "Verbindungsfehler: {$curlErr}", 'ms' => $ms, 'url' => $url];
    }

    // 1) JSON auswerten (Standardformat)
    $json = json_decode($body, true);
    if (is_array($json) && array_key_exists('ok', $json)) {
        return [
            'ok'     => (bool) $json['ok'],
            'status' => $status,
            'detail' => $json['ok']
                ? summarize_success($json)
                : ($json['error'] ?? 'unbekannter Fehler'),
            'ms'     => $ms,
            'url'    => $url,
        ];
    }

    // 2) format=text: "OK" oder "ERROR: ..."
    $trim = trim($body);
    if ($trim === 'OK') {
        return ['ok' => true, 'status' => $status, 'detail' => 'OK', 'ms' => $ms, 'url' => $url];
    }
    if (stripos($trim, 'ERROR:') === 0) {
        return ['ok' => false, 'status' => $status, 'detail' => substr($trim, 6), 'ms' => $ms, 'url' => $url];
    }

    // 3) Weder JSON noch bekanntes Textformat - z.B. Server-Fehlerseite
    $snippet = preg_replace('/\s+/', ' ', substr($trim, 0, 160));
    return [
        'ok'     => false,
        'status' => $status,
        'detail' => "unerwartete Antwort (HTTP {$status}): {$snippet}",
        'ms'     => $ms,
        'url'    => $url,
    ];
}

/** Bei Erfolg eine knappe Info aus der Antwort ziehen (Anzahl etc.). */
function summarize_success(array $json): string
{
    $data = $json['data'] ?? null;
    if (is_array($data)) {
        if (array_is_list($data)) {
            return 'OK · ' . count($data) . ' Einträge';
        }
        if (isset($data['deleted'])) {
            $n = is_array($data['deleted']) ? count($data['deleted']) : $data['deleted'];
            return "OK · {$n} gelöscht";
        }
        if (isset($data['layerId'])) {
            return 'OK · layerId ' . $data['layerId'];
        }
    }
    return 'OK';
}

// array_is_list gibt es erst ab PHP 8.1 - Fallback für ältere Versionen
if (!function_exists('array_is_list')) {
    function array_is_list(array $a): bool
    {
        return $a === [] || array_keys($a) === range(0, count($a) - 1);
    }
}

// ============================ Ausführung ==============================

$total = count($queries);
$pass = 0;
$fail = 0;
$results = [];

echo c("Wrapper-Test", 'bold', $isCli) . "\n";
echo c(str_repeat('─', 60), 'gray', $isCli) . "\n";
echo "Ziel:      {$WRAPPER}\n";
echo "Prozessor: " . ($PROCESSOR !== '' ? $PROCESSOR : '(erster aus config.php)') . "\n";
echo "Pause:     " . number_format($USLEEP / 1000, 0) . " ms · Queries: {$total}\n";
echo c(str_repeat('─', 60), 'gray', $isCli) . "\n\n";

foreach ($queries as $i => $query) {
    $n = $i + 1;
    $r = run_query($WRAPPER, $query, $PROCESSOR, $TIMEOUT);
    $results[] = [$query, $r];

    if ($r['ok']) {
        $pass++;
        $mark = c('  OK  ', 'green', $isCli);
    } else {
        $fail++;
        $mark = c('FAIL  ', 'red', $isCli);
    }

    $num = c(sprintf('%2d/%d', $n, $total), 'gray', $isCli);
    $ms = c(sprintf('%5.0f ms', $r['ms']), 'gray', $isCli);

    echo "{$num} [{$mark}] {$ms}  " . c($query, 'cyan', $isCli) . "\n";
    echo "         " . ($r['ok'] ? c($r['detail'], 'gray', $isCli) : c($r['detail'], 'yellow', $isCli)) . "\n";

    // Pause nur zwischen Aufrufen, nicht nach dem letzten
    if ($USLEEP > 0 && $n < $total) {
        usleep($USLEEP);
    }
}

echo "\n" . c(str_repeat('─', 60), 'gray', $isCli) . "\n";
$summary = sprintf('%d von %d OK', $pass, $total);
if ($fail === 0) {
    echo c("✓ {$summary} – alle bestanden", 'green', $isCli) . "\n";
} else {
    echo c("✗ {$summary} · {$fail} fehlgeschlagen", 'red', $isCli) . "\n";
    echo "\nFehlgeschlagen:\n";
    foreach ($results as [$query, $r]) {
        if (!$r['ok']) {
            echo c('  · ', 'red', $isCli) . $query . "\n";
            echo "      " . c($r['detail'], 'yellow', $isCli) . "\n";
        }
    }
}

if (!$isCli) {
    echo "</pre>";
}

// Exit-Code für Skripte/CI: 0 = alles OK, 1 = mindestens ein Fehler
if ($isCli) {
    exit($fail === 0 ? 0 : 1);
}
