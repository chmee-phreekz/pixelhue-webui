<?php

/**
 * Kleiner JSON-API-Controller für das Web-UI.
 * Jeder Aufruf akzeptiert optional ?processor=<alias> (siehe config.php).
 * Ohne Angabe wird der erste konfigurierte Prozessor verwendet.
 *
 * Aufrufe: api.php?action=processors
 *          api.php?action=layers
 *          api.php?action=screens
 *          api.php?action=node
 *          api.php?action=layer_window  (POST: layerId, x, y, width, height)
 *          api.php?action=layer_opacity (POST: layerId, opacity)
 *          api.php?action=layer_select  (POST: layerId, selected)
 *          api.php?action=layer_delete  (POST: layerId) – Ersatz für "disable"
 *          api.php?action=layer_create  (POST: screenId, sourceId, x, y, width,
 *                                        height, [sceneType 2=PGM|4=PVW, name, opacity])
 *          api.php?action=screen_take   (POST: screenId) – PVW auf PGM schalten
 */

header('Content-Type: application/json; charset=utf-8');

require __DIR__ . '/PixelhueClient.php';
require __DIR__ . '/processors.php';
require __DIR__ . '/error_handling.php';

$rootCfg = require __DIR__ . '/config.php';

// Fehler-HTTP-Code: standardmäßig 200, damit der Body (und damit die
// Meldung) nicht von einer Server-Fehlerseite überschrieben wird.
$errorHttpCode = (int) ($rootCfg['error_http_code'] ?? 200);
pixelhue_install_fatal_handler('json', $errorHttpCode);

$action = $_GET['action'] ?? '';
$method = $_SERVER['REQUEST_METHOD'];

function readJsonBody(): array
{
    $raw = file_get_contents('php://input');
    $data = json_decode($raw, true);
    return is_array($data) ? $data : [];
}

/**
 * Fehlerantwort. Der HTTP-Code kommt aus config.php ('error_http_code');
 * entscheidend ist immer das "ok"-Feld im Body, nicht der HTTP-Status.
 */
function fail(string $message): void
{
    global $errorHttpCode;
    http_response_code($errorHttpCode);
    echo json_encode(['ok' => false, 'error' => $message]);
    exit;
}

try {
    $processorAlias = $_GET['processor'] ?? null;
    $cfg = pixelhue_resolve_processor_config($rootCfg, $processorAlias);
    $client = new PixelhueClient($cfg);

    switch ($action) {

        case 'processors':
            $aliases = pixelhue_list_processor_aliases($rootCfg);
            echo json_encode([
                'ok'      => true,
                'data'    => $aliases,
                'default' => $aliases[0] ?? null,
                'current' => $cfg['alias'] ?? ($aliases[0] ?? null),
            ]);
            break;

        case 'selftest': {
            // Prüft für jeden Auth-Modus, ob (a) die /pixelhue/-Leseroute und
            // (b) die /unico/-Route den Token akzeptieren. Ergebnis zeigt,
            // welcher Modus für Schreibzugriffe (Layer anlegen/löschen) taugt.
            $report = [];
            foreach (['jwt', 'login'] as $mode) {
                $testCfg = array_merge($cfg, ['auth_mode' => $mode]);
                $row = ['auth_mode' => $mode];

                try {
                    $testClient = new PixelhueClient($testCfg);
                    $res = $testClient->getLayers();
                    $row['pixelhue_read'] = 'OK (' . count($res['data']['list'] ?? []) . ' Layer)';
                } catch (Throwable $e) {
                    $row['pixelhue_read'] = 'FEHLER: ' . $e->getMessage();
                }

                try {
                    $testClient = new PixelhueClient($testCfg);
                    $testClient->probeUnicoRead();
                    $row['unico_read'] = 'OK';
                } catch (Throwable $e) {
                    $row['unico_read'] = 'FEHLER: ' . $e->getMessage();
                }

                $report[] = $row;
            }

            echo json_encode([
                'ok'      => true,
                'hinweis' => 'Der Modus, bei dem BEIDE Zeilen OK sind, gehört in config.php unter auth_mode. '
                           . 'Fuer auth_mode=login muessen username/password in config.php echt sein.',
                'aktuell' => $cfg['auth_mode'] ?? null,
                'data'    => $report,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
            break;
        }

        case 'inputs': {
            // sourceId für create_layer = interfaceId aus dieser Liste.
            $res = $client->getInterfaces();
            $list = $res['data']['list'] ?? [];
            $onlyInputs = ($_GET['all'] ?? '') !== '1';

            $out = [];
            foreach ($list as $if) {
                $aux  = $if['auxiliaryInfo'] ?? [];
                $ci   = $aux['connectorInfo'] ?? [];
                $type = $ci['interfaceType'] ?? null;
                if ($onlyInputs && (int) $type !== 2) {
                    continue;
                }
                $conn = $ci['type'] ?? null;
                $out[] = [
                    'sourceId'      => $if['interfaceId'] ?? null,  // = interfaceId!
                    'name'          => $if['general']['name'] ?? null,
                    'group'         => $aux['group']['name'] ?? null,   // physischer Eingang
                    'connectorType' => $conn,
                    'connector'     => PixelhueClient::CONNECTOR_TYPES[$conn] ?? ('Typ ' . $conn),
                    // state = 1: aktiver Port eines Kombi-Eingangs (DP+HDMI teilen
                    // sich einen physischen Eingang, nur einer kann aktiv sein).
                    'active'        => $if['state'] ?? null,
                    'online'        => $aux['online'] ?? null,
                    'type'          => $type,
                    'typeName'      => PixelhueClient::INTERFACE_TYPES[$type] ?? ('Typ ' . $type),
                    'sourceType'    => 2, // beim Layer-Anlegen für Inputs immer 2
                ];
            }
            echo json_encode([
                'ok'      => true,
                'count'   => count($out),
                'hinweis' => 'sourceId = interfaceId (geraeteabhaengig, nie hartkodieren). '
                           . 'Fuer create_layer: sourceId + sourceType=2. '
                           . 'active=1 = aktiver Port eines Kombi-Eingangs. ?all=1 zeigt auch Outputs/AUX/MVR.',
                'data'    => $out,
            ]);
            break;
        }

        case 'pictures': {
            // Bilder im Gerätespeicher. sourceId-Raum ist unabhängig von den
            // Interfaces - erst sourceType=5 macht die ID eindeutig.
            $res = $client->getPictures();
            $data = $res['data'] ?? [];
            $procQs = urlencode((string) ($cfg['alias'] ?? ''));

            $out = [];
            foreach ($data['list'] ?? [] as $pic) {
                $id = $pic['pictureId'] ?? null;
                $out[] = [
                    'sourceId'   => $id,
                    'sourceType' => PixelhueClient::SOURCE_IMAGE,
                    'name'       => $pic['general']['name'] ?? ('Bild ' . $id),
                    'width'      => $pic['general']['width'] ?? null,
                    'height'     => $pic['general']['height'] ?? null,
                    'size'       => $pic['general']['size'] ?? null,
                    'isUsed'     => $pic['isUsed'] ?? null,
                    // Über PHP geproxyt, damit der Browser den Prozessor nicht
                    // selbst erreichen muss. Adressiert wird über pictureId -
                    // welche Datei das ist (Thumb vs. Vollbild, .jpg/.png/.bmp),
                    // entscheidet der Client anhand von picture/list.
                    'thumbUrl'   => 'api.php?action=picture_file&thumb=1&processor='
                                    . $procQs . '&pictureId=' . (int) $id,
                    'fullUrl'    => 'api.php?action=picture_file&processor='
                                    . $procQs . '&pictureId=' . (int) $id,
                ];
            }
            echo json_encode([
                'ok'            => true,
                'count'         => count($out),
                'usedCapacity'  => $data['usedCapacity'] ?? null,
                'totalCapacity' => $data['totalCapacity'] ?? null,
                'data'          => $out,
            ]);
            break;
        }

        case 'picture_file': {
            // Liefert die Bilddatei binär aus. Kein JSON!
            if (!isset($_GET['pictureId'])) fail('pictureId fehlt');
            $img = $client->getPictureFile((int) $_GET['pictureId'], ($_GET['thumb'] ?? '') === '1');
            header_remove('Content-Type');
            header('Content-Type: ' . $img['contentType']);
            header('Cache-Control: private, max-age=300');
            echo $img['content'];
            exit;
        }

        case 'node':
            echo json_encode(['ok' => true, 'data' => $client->getNodeInfo()]);
            break;

        case 'screens':
            $res = $client->getScreens();
            // Antwortstruktur laut Doku 4.2.1: data.list[] plus count/page/
            // totalCount/totalPage - identisch aufgebaut wie /layers/list-detail.
            $raw = $res['data'] ?? [];
            $list = (isset($raw['list']) && is_array($raw['list'])) ? $raw['list'] : [];
            $simplified = array_map(function ($screen) {
                // Outputs eines Screens: je Ausgang Name, Interface-ID,
                // Online-Status und die tatsächliche Auflösung (pos.width/height).
                $outputs = array_map(function ($out) {
                    return [
                        'interfaceId'     => $out['interfaceId'] ?? null,
                        'interfaceOnline' => $out['interfaceOnline'] ?? null,
                        'name'            => $out['name'] ?? null,
                        'width'           => $out['pos']['width'] ?? null,
                        'height'          => $out['pos']['height'] ?? null,
                        'x'               => $out['pos']['x'] ?? null,
                        'y'               => $out['pos']['y'] ?? null,
                    ];
                }, $screen['mosaic']['outputs'] ?? []);

                $stype = $screen['screenIdObj']['type'] ?? null;
                return [
                    'screenId' => $screen['screenId'] ?? null,
                    'id'       => $screen['screenIdObj']['id'] ?? null,
                    // 2 = normaler Screen, 4 = AUX, 8 = MVR
                    'type'     => $stype,
                    'typeName' => PixelhueClient::SCREEN_TYPES[$stype] ?? ('Typ ' . $stype),
                    'isAux'    => (int) $stype === PixelhueClient::SCREEN_AUX,
                    // pgmEdit=0: Screen nimmt kein layers/create an, muss erst
                    // per pgm-edit freigeschaltet werden (createLayer macht das
                    // für AUX/MVR automatisch)
                    'pgmEdit'  => $screen['pgmEdit'] ?? null,
                    'name'     => $screen['general']['name'] ?? ('Screen ' . ($screen['screenId'] ?? '?')),
                    'guid'     => $screen['guid'] ?? null,
                    'enable'   => $screen['enable'] ?? null,
                    'order'    => $screen['order'] ?? null,
                    // Gesamt-Canvas des Screens
                    'window'   => $screen['mosaic']['window'] ?? null,
                    'splice'   => $screen['mosaic']['splice'] ?? null,
                    'outputs'  => $outputs,
                ];
            }, $list);
            echo json_encode(['ok' => true, 'count' => count($simplified), 'data' => $simplified]);
            break;

        case 'layers':
            $layerId = isset($_GET['layerId']) ? (int) $_GET['layerId'] : null;
            $res = $client->getLayers($layerId);
            $list = $res['data']['list'] ?? [];

            // Für das UI auf die relevanten Felder reduzieren.
            // HINWEIS: Layer haben laut Traffic-Mitschnitt KEIN eigenes
            // "enable"-Attribut zum Schreiben – ein Layer existiert oder
            // wird gelöscht. Ein evtl. vorhandenes "enable"-Feld in der
            // Rohantwort wird deshalb bewusst nicht mehr ausgewertet.
            $simplified = array_map(function ($layer) {
                return [
                    'layerId'        => $layer['layerId'] ?? null,
                    'name'           => $layer['general']['name'] ?? ('Layer ' . ($layer['layerId'] ?? '?')),
                    'selected'       => $layer['selected'] ?? null,
                    'locked'         => $layer['locked'] ?? null,
                    'zorder'         => $layer['zorder'] ?? null,
                    'opacity'        => $layer['opacity'] ?? null,
                    'window'         => $layer['window'] ?? null,
                    'sourceName'     => $layer['source']['general']['sourceName'] ?? null,
                    // sourceId (= interfaceId) wird gebraucht, um den Layer per
                    // create_layer wieder anzulegen.
                    'sourceId'       => $layer['source']['general']['sourceId'] ?? null,
                    'sourceType'     => $layer['source']['general']['sourceType'] ?? ($layer['source']['primary']['sourceType'] ?? null),
                    'attachScreenId' => $layer['layerIdObj']['attachScreenId'] ?? null,
                    // layerIdObj.type: 2 = normaler Layer, 16 = BKG (Hintergrund),
                    // 8 = Layer auf AUX/MVR
                    'type'           => $layer['layerIdObj']['type'] ?? null,
                    'isBkg'          => ((int) ($layer['layerIdObj']['type'] ?? 0)) === PixelhueClient::LAYER_BKG,
                    // enable existiert doch: gesetzt wird es über layers/switch
                    'enable'         => $layer['enable'] ?? null,
                    // sceneType 2 = PGM (Live), 4 = PVW (Preview). PGM und PVW
                    // sind derselbe Screen, nur zwei Szenen darin.
                    'sceneType'      => $layer['layerIdObj']['sceneType'] ?? null,
                    'scene'          => PixelhueClient::SCENE_TYPES[$layer['layerIdObj']['sceneType'] ?? -1] ?? null,
                ];
            }, $list);

            echo json_encode([
                'ok'    => true,
                'count' => count($simplified),
                'data'  => $simplified,
            ]);
            break;

        case 'layer_create':
            if ($method !== 'POST') fail('POST erforderlich');
            $body = readJsonBody();
            foreach (['screenId', 'sourceId', 'x', 'y', 'width', 'height'] as $f) {
                if (!isset($body[$f])) fail("Feld '{$f}' fehlt");
            }
            $opts = [];
            if (isset($body['sceneType'])) {
                $opts['sceneType'] = (int) $body['sceneType'];   // 2 = PGM, 4 = PVW
            }
            $res = $client->createLayer(
                (int) $body['screenId'],
                (int) $body['sourceId'],
                (int) ($body['sourceType'] ?? 2),
                (int) $body['x'],
                (int) $body['y'],
                (int) $body['width'],
                (int) $body['height'],
                (string) ($body['name'] ?? ''),
                (int) ($body['opacity'] ?? 100),
                $opts
            );
            echo json_encode(['ok' => true, 'result' => $res]);
            break;

        case 'screen_clear':
            if ($method !== 'POST') fail('POST erforderlich');
            $body = readJsonBody();
            if (!isset($body['screenId'])) fail('screenId fehlt');
            // sceneType optional: ohne Angabe werden beide Szenen geleert
            $res = $client->clearScreen(
                (int) $body['screenId'],
                isset($body['sceneType']) ? (int) $body['sceneType'] : null
            );
            echo json_encode(['ok' => true, 'result' => $res]);
            break;

        case 'bkg': {
            // Zustand des BKG-Layers einer Szene lesen
            if (!isset($_GET['screenId'])) fail('screenId fehlt');
            $sceneType = isset($_GET['sceneType']) ? (int) $_GET['sceneType'] : PixelhueClient::SCENE_PGM;
            $bkg = $client->getBkgLayer((int) $_GET['screenId'], $sceneType);
            if ($bkg === null) {
                echo json_encode(['ok' => true, 'data' => null]);
                break;
            }
            echo json_encode(['ok' => true, 'data' => [
                'layerId'    => $bkg['layerId'] ?? null,
                'enable'     => $bkg['enable'] ?? null,
                'window'     => $bkg['window'] ?? null,
                'sourceId'   => $bkg['source']['general']['sourceId'] ?? null,
                'sourceType' => $bkg['source']['general']['sourceType'] ?? null,
                'sourceName' => $bkg['source']['general']['sourceName'] ?? null,
            ]]);
            break;
        }

        case 'bkg_set': {
            // BKG ein-/ausschalten und/oder Bild zuweisen.
            if ($method !== 'POST') fail('POST erforderlich');
            $body = readJsonBody();
            if (!isset($body['screenId'])) fail('screenId fehlt');
            $sceneType = isset($body['sceneType']) ? (int) $body['sceneType'] : PixelhueClient::SCENE_PGM;
            $bkg = $client->getBkgLayer((int) $body['screenId'], $sceneType);
            if ($bkg === null) fail('Kein BKG-Layer für diesen Screen/diese Szene gefunden');
            $layerId = (int) $bkg['layerId'];

            $done = [];
            // Reihenfolge wie im Mitschnitt: erst einschalten, dann Quelle setzen.
            if (isset($body['enable'])) {
                $client->setLayerEnable($layerId, (bool) $body['enable']);
                $done[] = 'switch';
            }
            if (isset($body['sourceId'])) {
                $client->setLayerSource(
                    $layerId,
                    (int) $body['sourceId'],
                    (int) ($body['sourceType'] ?? PixelhueClient::SOURCE_IMAGE)
                );
                $done[] = 'source';
            }
            if (isset($body['x'], $body['y'], $body['width'], $body['height'])) {
                $client->setLayerWindow($layerId, (int) $body['x'], (int) $body['y'],
                    (int) $body['width'], (int) $body['height']);
                $done[] = 'window';
            }
            echo json_encode(['ok' => true, 'layerId' => $layerId, 'applied' => $done]);
            break;
        }

        case 'layer_switch':
            if ($method !== 'POST') fail('POST erforderlich');
            $body = readJsonBody();
            if (!isset($body['layerId'])) fail('layerId fehlt');
            $res = $client->setLayerEnable((int) $body['layerId'], !empty($body['enable']));
            echo json_encode(['ok' => true, 'result' => $res]);
            break;

        case 'screen_pgm_edit':
            if ($method !== 'POST') fail('POST erforderlich');
            $body = readJsonBody();
            if (!isset($body['screenId'])) fail('screenId fehlt');
            $res = $client->setPgmEdit((int) $body['screenId'], !isset($body['enable']) || !empty($body['enable']));
            echo json_encode(['ok' => true, 'result' => $res]);
            break;

        case 'screen_take':
            if ($method !== 'POST') fail('POST erforderlich');
            $body = readJsonBody();
            if (!isset($body['screenId'])) fail('screenId fehlt');
            $res = $client->takeScreen((int) $body['screenId']);
            echo json_encode(['ok' => true, 'result' => $res]);
            break;

        case 'layer_delete':
            if ($method !== 'POST' && $method !== 'DELETE') fail('POST oder DELETE erforderlich');
            $body = readJsonBody();
            if (!isset($body['layerId'])) fail('layerId fehlt');
            $res = $client->deleteLayer((int) $body['layerId']);
            echo json_encode(['ok' => true, 'result' => $res]);
            break;

        case 'layer_window':
            if ($method !== 'POST') fail('POST erforderlich');
            $body = readJsonBody();
            foreach (['layerId', 'x', 'y', 'width', 'height'] as $f) {
                if (!isset($body[$f])) fail("Feld '{$f}' fehlt");
            }
            $res = $client->setLayerWindow(
                (int) $body['layerId'],
                (int) $body['x'],
                (int) $body['y'],
                (int) $body['width'],
                (int) $body['height']
            );
            echo json_encode(['ok' => true, 'result' => $res]);
            break;

        case 'layer_opacity':
            if ($method !== 'POST') fail('POST erforderlich');
            $body = readJsonBody();
            if (!isset($body['layerId'], $body['opacity'])) fail('layerId oder opacity fehlt');
            $res = $client->setLayerOpacity((int) $body['layerId'], (int) $body['opacity']);
            echo json_encode(['ok' => true, 'result' => $res]);
            break;

        case 'layer_select':
            if ($method !== 'POST') fail('POST erforderlich');
            $body = readJsonBody();
            if (!isset($body['layerId'])) fail('layerId fehlt');
            $res = $client->selectLayer((int) $body['layerId'], !empty($body['selected']));
            echo json_encode(['ok' => true, 'result' => $res]);
            break;

        default:
            fail('Unbekannte action: ' . $action);
    }
} catch (Throwable $e) {
    fail($e->getMessage());
}
