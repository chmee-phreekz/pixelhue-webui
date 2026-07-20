<?php

/**
 * Einfacher GET-Wrapper für externe Tools/Automationen (Lichtpulte,
 * Show-Control-Systeme, Makro-Player etc.), die nur simple HTTP-GET-
 * Requests auslösen können – kein JSON-Body, keine Header nötig.
 *
 * Alle Parameter werden per Query-String übergeben. Antwort ist JSON
 * (?format=text liefert stattdessen eine simple "OK"/"ERROR: ..."-Zeile
 * für Tools, die keine JSON-Antwort parsen können).
 *
 * Prozessor-Auswahl: ?processor=<alias> (siehe config.php). Ohne Angabe
 * wird der erste konfigurierte Prozessor verwendet.
 *
 * ----------------------------------------------------------------------
 * AKTIONEN
 * ----------------------------------------------------------------------
 *
 * (a) Screen leeren (alle Layer eines Screens löschen):
 *     wrapper.php?action=clear_screen&screenId=1
 *     wrapper.php?action=clear_screen&screenId=1&scene=pgm   (nur Program)
 *     ohne scene: BEIDE Szenen, da PGM und PVW dieselbe screenGuid haben
 *
 * (b) Layer anlegen (Bildinhalt einschalten):
 *     wrapper.php?action=create_layer
 *         &screenId=1
 *         &sourceId=2&sourceType=2      (welcher Input, siehe Hinweis unten)
 *         &x=0&y=0&width=1920&height=1080
 *         &opacity=100                  (optional, Default 100)
 *         &name=Kamera1                 (optional)
 *         &scene=pgm                    (optional: pgm = Default, pvw = Preview)
 *
 * Zusatzfunktionen (nutzen bereits vorhandene Client-Methoden):
 *
 *     wrapper.php?action=take&screenId=1          (PVW auf PGM schalten)
 *     wrapper.php?action=list_screens             (Screens inkl. Art: Screen/AUX/MVR)
 *     wrapper.php?action=pgm_edit&screenId=3&enable=1
 *
 * AUX: create_layer funktioniert dort genauso - layerIdObj.type und die
 * pgm-edit-Freischaltung werden automatisch aus dem Ziel-Screen abgeleitet:
 *     wrapper.php?action=create_layer&screenId=3&sourceId=2&x=0&y=0&width=1920&height=1080
 *
 * BKG (Hintergrund-Layer; existiert immer, wird nur ein-/ausgeschaltet):
 *     wrapper.php?action=list_pictures                        (pictureId finden)
 *     wrapper.php?action=bkg&screenId=1&scene=pgm             (Zustand lesen)
 *     wrapper.php?action=bkg_enable&screenId=1&scene=pgm&enable=1
 *     wrapper.php?action=bkg_source&screenId=1&scene=pgm&sourceId=3
 *     wrapper.php?action=bkg_window&screenId=1&scene=pgm&x=0&y=0&width=7680&height=1080
 *     wrapper.php?action=switch_layer&layerId=21233664&enable=0
 *     wrapper.php?action=delete_layer&layerId=21037056
 *     wrapper.php?action=set_window&layerId=21037056&x=0&y=0&width=1920&height=1080
 *     wrapper.php?action=set_opacity&layerId=21037056&opacity=50
 *     wrapper.php?action=select_layer&layerId=21037056&selected=1
 *
 * HINWEIS zu sourceId/sourceType: Diese Werte sind geräte-/quellenspezifisch
 * (z.B. welcher physische Eingang, welcher Medienplayer-Slot etc.) und in
 * der öffentlichen Doku nicht als Nachschlagetabelle vorhanden. Am
 * zuverlässigsten ermittelst du sie, indem du in PixelFlow einmal manuell
 * den gewünschten Input auf einen Layer legst und dabei den
 * PUT /unico/v1/layers/create bzw. .../source Request mitschneidest
 * (siehe README.md, Abschnitt "Traffic-Mitschnitt").
 */

header('Content-Type: application/json; charset=utf-8');

require __DIR__ . '/PixelhueClient.php';
require __DIR__ . '/processors.php';
require __DIR__ . '/error_handling.php';

$rootCfg = require __DIR__ . '/config.php';
$action = $_GET['action'] ?? '';
$format = $_GET['format'] ?? 'json';

// Fehler-HTTP-Code: standardmäßig 200, damit die Meldung nicht von einer
// Server-Fehlerseite ersetzt wird (siehe config.php).
$errorHttpCode = (int) ($rootCfg['error_http_code'] ?? 200);
pixelhue_install_fatal_handler($format === 'text' ? 'text' : 'json', $errorHttpCode);

function wrapper_ok($data = null): void
{
    global $format;
    if ($format === 'text') {
        header('Content-Type: text/plain; charset=utf-8');
        echo 'OK';
        exit;
    }
    echo json_encode(['ok' => true, 'data' => $data]);
    exit;
}

function wrapper_fail(string $message): void
{
    global $format, $errorHttpCode;
    http_response_code($errorHttpCode);
    if ($format === 'text') {
        header('Content-Type: text/plain; charset=utf-8');
        echo 'ERROR: ' . $message;
        exit;
    }
    echo json_encode(['ok' => false, 'error' => $message]);
    exit;
}

function reqInt(string $key): int
{
    if (!isset($_GET[$key]) || $_GET[$key] === '') {
        wrapper_fail("Parameter '{$key}' fehlt");
    }
    return (int) $_GET[$key];
}

/**
 * Szene aus dem Query-String: scene=pgm|pvw oder sceneType=2|4.
 * $default wird zurückgegeben, wenn nichts angegeben ist.
 */
function reqScene(?int $default = null): ?int
{
    $scene = strtolower($_GET['scene'] ?? '');
    if ($scene === 'pvw' || $scene === 'preview') {
        return PixelhueClient::SCENE_PVW;
    }
    if ($scene === 'pgm' || $scene === 'program') {
        return PixelhueClient::SCENE_PGM;
    }
    if (isset($_GET['sceneType'])) {
        return (int) $_GET['sceneType'];
    }
    if ($scene !== '') {
        wrapper_fail("Unbekannter Wert für 'scene': {$scene} (erlaubt: pgm, pvw)");
    }
    return $default;
}

try {
    $cfg = pixelhue_resolve_processor_config($rootCfg, $_GET['processor'] ?? null);
    $client = new PixelhueClient($cfg);

    switch ($action) {

        case 'clear_screen': {
            $screenId = reqInt('screenId');

            // Ohne scene werden BEIDE Szenen geleert - PGM und PVW teilen sich
            // die screenGuid. scene=pgm|pvw begrenzt es auf eine Szene.
            wrapper_ok($client->clearScreen($screenId, reqScene(null)));
            break;
        }

        case 'list_inputs': {
            $res = $client->getInterfaces();
            $out = [];
            foreach ($res['data']['list'] ?? [] as $if) {
                $aux = $if['auxiliaryInfo'] ?? [];
                $ci  = $aux['connectorInfo'] ?? [];
                if ((int) ($ci['interfaceType'] ?? 0) !== 2) {
                    continue;
                }
                $conn = $ci['type'] ?? null;
                $out[] = [
                    'sourceId'  => $if['interfaceId'] ?? null,
                    'name'      => $if['general']['name'] ?? null,
                    'group'     => $aux['group']['name'] ?? null,
                    'connector' => PixelhueClient::CONNECTOR_TYPES[$conn] ?? ('Typ ' . $conn),
                    'active'    => $if['state'] ?? null,   // 1 = aktiver Port des Kombi-Eingangs
                    'online'    => $aux['online'] ?? null,
                ];
            }
            wrapper_ok($out);
            break;
        }

        case 'create_layer': {
            $screenId = reqInt('screenId');

            // Quelle: sourceId (= interfaceId) ist der vorgesehene Weg -
            // programmatisch eindeutig. Die gültigen IDs liefert list_inputs
            // bzw. api.php?action=inputs; sie sind geräteabhängig.
            // sourceName bleibt als bequeme Alternative erhalten.
            if (isset($_GET['sourceId']) && $_GET['sourceId'] !== '') {
                $sourceId = (int) $_GET['sourceId'];
            } elseif (isset($_GET['sourceName']) && $_GET['sourceName'] !== '') {
                $sourceId = $client->findSourceIdByName($_GET['sourceName']);
            } else {
                wrapper_fail("Parameter 'sourceId' fehlt (Alternative: sourceName). "
                    . "Gültige IDs zeigt action=list_inputs.");
            }
            // sourceType 2 = physischer Input (per Mitschnitt belegt)
            $sourceType = isset($_GET['sourceType']) ? (int) $_GET['sourceType'] : 2;

            // Zielszene: scene=pgm (Default) oder scene=pvw. Alternativ direkt
            // sceneType=2|4. PGM und PVW liegen im selben Screen.
            $opts = [];
            $sc = reqScene(null);
            if ($sc !== null) {
                $opts['sceneType'] = $sc;
            }

            $x      = reqInt('x');
            $y      = reqInt('y');
            $width  = reqInt('width');
            $height = reqInt('height');
            $opacity = isset($_GET['opacity']) ? (int) $_GET['opacity'] : 100;
            $name    = $_GET['name'] ?? '';

            $result = $client->createLayer(
                $screenId,
                $sourceId,
                $sourceType,
                $x,
                $y,
                $width,
                $height,
                $name,
                $opacity,
                $opts
            );
            wrapper_ok($result);
            break;
        }

        case 'list_screens': {
            // Screens inkl. Art: 2 = Screen, 4 = AUX, 8 = MVR
            $res = $client->getScreens();
            $out = [];
            foreach ($res['data']['list'] ?? [] as $sc) {
                $t = $sc['screenIdObj']['type'] ?? null;
                $w = $sc['mosaic']['window'] ?? [];
                $out[] = [
                    'screenId' => $sc['screenId'] ?? null,
                    'name'     => $sc['general']['name'] ?? null,
                    'type'     => $t,
                    'typeName' => PixelhueClient::SCREEN_TYPES[$t] ?? ('Typ ' . $t),
                    'width'    => $w['width'] ?? null,
                    'height'   => $w['height'] ?? null,
                    'pgmEdit'  => $sc['pgmEdit'] ?? null,
                ];
            }
            wrapper_ok($out);
            break;
        }

        case 'pgm_edit': {
            // Screen zum Bearbeiten freischalten. Bei AUX/MVR nötig, bevor
            // create_layer greift - create_layer erledigt das selbst.
            $screenId = reqInt('screenId');
            wrapper_ok($client->setPgmEdit($screenId, ($_GET['enable'] ?? '1') !== '0'));
            break;
        }

        case 'list_pictures': {
            // Bilder im Gerätespeicher - liefert die pictureId, die als
            // sourceId (mit sourceType=5) verwendet wird.
            $res = $client->getPictures();
            $out = [];
            foreach ($res['data']['list'] ?? [] as $pic) {
                $out[] = [
                    'sourceId'   => $pic['pictureId'] ?? null,
                    'sourceType' => PixelhueClient::SOURCE_IMAGE,
                    'name'       => $pic['general']['name'] ?? null,
                    'width'      => $pic['general']['width'] ?? null,
                    'height'     => $pic['general']['height'] ?? null,
                    'isUsed'     => $pic['isUsed'] ?? null,
                ];
            }
            wrapper_ok($out);
            break;
        }

        case 'bkg': {
            // Zustand des BKG-Layers lesen
            $screenId = reqInt('screenId');
            wrapper_ok($client->getBkgLayer($screenId, reqScene(PixelhueClient::SCENE_PGM)));
            break;
        }

        case 'bkg_enable': {
            // BKG ein-/ausschalten: &enable=1 oder &enable=0
            $screenId = reqInt('screenId');
            if (!isset($_GET['enable'])) wrapper_fail("Parameter 'enable' fehlt (0 oder 1)");
            $bkg = $client->getBkgLayer($screenId, reqScene(PixelhueClient::SCENE_PGM));
            if (!$bkg) wrapper_fail('Kein BKG-Layer für diesen Screen/diese Szene gefunden');
            wrapper_ok($client->setLayerEnable((int) $bkg['layerId'], $_GET['enable'] !== '0'));
            break;
        }

        case 'bkg_source': {
            // Bild auf den BKG legen. sourceId = pictureId aus list_pictures.
            // Der BKG wird dabei eingeschaltet (Reihenfolge wie in PixelFlow:
            // erst switch, dann source) - mit &enable=0 unterbleibt das.
            $screenId = reqInt('screenId');
            $sourceId = reqInt('sourceId');
            $sourceType = isset($_GET['sourceType'])
                ? (int) $_GET['sourceType']
                : PixelhueClient::SOURCE_IMAGE;

            $bkg = $client->getBkgLayer($screenId, reqScene(PixelhueClient::SCENE_PGM));
            if (!$bkg) wrapper_fail('Kein BKG-Layer für diesen Screen/diese Szene gefunden');
            $layerId = (int) $bkg['layerId'];

            $done = [];
            if (($_GET['enable'] ?? '1') !== '0') {
                $client->setLayerEnable($layerId, true);
                $done[] = 'switch';
            }
            $client->setLayerSource($layerId, $sourceId, $sourceType);
            $done[] = 'source';
            wrapper_ok(['layerId' => $layerId, 'applied' => $done]);
            break;
        }

        case 'bkg_window': {
            // Größe/Position des BKG setzen
            $screenId = reqInt('screenId');
            $bkg = $client->getBkgLayer($screenId, reqScene(PixelhueClient::SCENE_PGM));
            if (!$bkg) wrapper_fail('Kein BKG-Layer für diesen Screen/diese Szene gefunden');
            wrapper_ok($client->setLayerWindow((int) $bkg['layerId'],
                reqInt('x'), reqInt('y'), reqInt('width'), reqInt('height')));
            break;
        }

        case 'switch_layer': {
            // Layer ein-/ausschalten (belegt für BKG; bei normalen Layern
            // nicht verifiziert - dort löschen/neu anlegen).
            $layerId = reqInt('layerId');
            if (!isset($_GET['enable'])) wrapper_fail("Parameter 'enable' fehlt (0 oder 1)");
            wrapper_ok($client->setLayerEnable($layerId, $_GET['enable'] !== '0'));
            break;
        }

        case 'take': {
            // PVW auf PGM schalten (Doku 4.1.3). Nutzt die am Gerät
            // eingestellte Überblendzeit.
            $screenId = reqInt('screenId');
            wrapper_ok($client->takeScreen($screenId));
            break;
        }

        case 'delete_layer': {
            $layerId = reqInt('layerId');
            wrapper_ok($client->deleteLayer($layerId));
            break;
        }

        case 'set_window': {
            $layerId = reqInt('layerId');
            $x = reqInt('x');
            $y = reqInt('y');
            $width = reqInt('width');
            $height = reqInt('height');
            wrapper_ok($client->setLayerWindow($layerId, $x, $y, $width, $height));
            break;
        }

        case 'set_opacity': {
            $layerId = reqInt('layerId');
            $opacity = reqInt('opacity');
            wrapper_ok($client->setLayerOpacity($layerId, $opacity));
            break;
        }

        case 'select_layer': {
            $layerId = reqInt('layerId');
            $selected = !empty($_GET['selected']) && $_GET['selected'] !== '0';
            wrapper_ok($client->selectLayer($layerId, $selected));
            break;
        }

        default:
            wrapper_fail('Unbekannte action: ' . $action);
    }
} catch (Throwable $e) {
    wrapper_fail($e->getMessage());
}
