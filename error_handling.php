<?php

/**
 * Sorgt dafür, dass Fehler beim Aufrufer ANKOMMEN.
 *
 * Hintergrund: Viele Webserver/Proxys ersetzen den Body von 5xx-Antworten
 * durch eine eigene Fehlerseite. Die eigentliche Fehlermeldung geht dabei
 * verloren und man sieht nur ein nacktes "500". Deshalb antworten api.php
 * und wrapper.php standardmäßig mit HTTP 200 und packen den Fehler in den
 * Body (siehe 'error_http_code' in config.php).
 *
 * Zusätzlich fängt install_fatal_handler() PHP-Fatals (Parse Error, Fatal
 * Error, ...) ab. Ohne das liefert PHP bei display_errors=Off eine leere
 * Seite - der Aufrufer sieht dann gar nichts.
 */

function pixelhue_install_fatal_handler(string $mode = 'json', int $httpCode = 200): void
{
    // Keine HTML-Fehlerausgabe: die würde die JSON-Antwort zerschießen.
    // Gemeldet wird trotzdem alles - wir geben es unten selbst kontrolliert aus.
    ini_set('display_errors', '0');
    error_reporting(E_ALL);

    register_shutdown_function(function () use ($mode, $httpCode) {
        $err = error_get_last();
        if ($err === null) {
            return;
        }
        $fatalTypes = [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR];
        if (!in_array($err['type'], $fatalTypes, true)) {
            return;
        }

        $msg = sprintf(
            'PHP Fatal: %s in %s:%d',
            $err['message'],
            basename($err['file']),
            $err['line']
        );

        if (!headers_sent()) {
            http_response_code($httpCode);
            header('Content-Type: ' . ($mode === 'text' ? 'text/plain' : 'application/json') . '; charset=utf-8');
        }

        echo $mode === 'text'
            ? 'ERROR: ' . $msg
            : json_encode(['ok' => false, 'error' => $msg]);
    });
}
