<?php

/**
 * Client für die Pixelhue REST-API (Node/Screen/Layer).
 * Basiert auf der offiziellen API-Doku: https://api.pixelhue.com/
 * Getestet gegen Doku-Version V2.0.1 (Geräte P10/P20/Q8/P80, Firmware >= 1.7.0).
 */
class PixelhueClient
{
    private array $cfg;
    private string $baseUrl;
    private string $unicoBaseUrl;
    private ?string $token = null;
    private ?string $sn = null;

    public function __construct(array $cfg)
    {
        $this->cfg = $cfg;
        $this->baseUrl = sprintf(
            'http://%s:%d/pixelhue',
            $cfg['device_ip'],
            $cfg['device_port']
        );
        // Schreibzugriffe auf Layer-Eigenschaften (window/opacity) laufen laut
        // Traffic-Mitschnitt über einen separaten Präfix "/unico/v1/...",
        // NICHT über "/pixelhue/v1/...". Die restliche Lese-API bleibt unter
        // /pixelhue/v1.
        $this->unicoBaseUrl = sprintf(
            'http://%s:%d/unico',
            $cfg['device_ip'],
            $cfg['device_port']
        );
    }

    // ---------------------------------------------------------------
    // Authentifizierung
    // ---------------------------------------------------------------

    /**
     * Öffentliche Node-Info abrufen (kein Token nötig).
     * Liefert u.a. die Seriennummer (sn), die für die lokale
     * JWT-Erzeugung benötigt wird.
     */
    public function getOpenDetail(): array
    {
        $res = $this->rawRequest('GET', '/v1/node/open-detail', [
            'nodeId' => $this->cfg['node_id'],
        ]);
        if (isset($res['data']['sn'])) {
            $this->sn = $res['data']['sn'];
        }
        return $res;
    }

    /**
     * Gültiges Token beschaffen (JWT lokal erzeugen oder Login-Endpoint nutzen).
     */
    private function getToken(): string
    {
        if ($this->token !== null) {
            return $this->token;
        }

        if ($this->cfg['auth_mode'] === 'login') {
            $res = $this->rawRequest('POST', '/v1/system/auth/login', [], [
                'username' => $this->cfg['username'],
                'password' => $this->cfg['password'],
            ]);
            if (empty($res['data']['token'])) {
                throw new RuntimeException('Login fehlgeschlagen: ' . ($res['message'] ?? 'unbekannter Fehler'));
            }
            $this->token = $res['data']['token'];
            return $this->token;
        }

        // auth_mode === 'jwt': Token lokal signieren
        if ($this->sn === null) {
            $this->getOpenDetail();
        }
        if ($this->sn === null) {
            throw new RuntimeException('Konnte Seriennummer (sn) nicht vom Gerät abrufen.');
        }

        $this->token = $this->buildJwt($this->sn);
        return $this->token;
    }

    /**
     * Minimaler HS256-JWT-Encoder (kein externes Package nötig).
     */
    private function buildJwt(string $sn): string
    {
        $now = time();
        $header = ['alg' => 'HS256', 'typ' => 'JWT'];
        $payload = [
            'SN'  => $sn,
            'iss' => $this->cfg['jwt_issuer'],
            'iat' => $now,
            'exp' => $now + (int) $this->cfg['jwt_ttl'],
        ];

        $segments = [
            $this->base64UrlEncode(json_encode($header)),
            $this->base64UrlEncode(json_encode($payload)),
        ];
        $signingInput = implode('.', $segments);
        $signature = hash_hmac('sha256', $signingInput, $this->cfg['jwt_secret'], true);
        $segments[] = $this->base64UrlEncode($signature);

        return implode('.', $segments);
    }

    private function base64UrlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    // ---------------------------------------------------------------
    // Node / Device
    // ---------------------------------------------------------------

    public function getNodeInfo(): array
    {
        return $this->request('GET', '/v1/node/detail', [
            'nodeId' => $this->cfg['node_id'],
        ]);
    }

    /**
     * Alle Screens (Program/Preview-Canvases, AUX-Ausgänge, Multiviewer) lesen.
     *
     * Endpunkt laut offizieller Doku, Kapitel 4.2.1 "Retrieve Screen
     * Information": GET /v1/screen/list-detail unter dem Prefix /pixelhue
     * (Kapitel 3.1: "Protocol call prefix: http://ip:port/pixelhue/+protocol url").
     *
     * WICHTIG: Frühere Versionen dieser Klasse haben hier /unico/ benutzt,
     * abgeleitet aus dem Wireshark-Mitschnitt. Der Mitschnitt zeigt aber
     * PixelFlow im Gespräch mit einem lokalen virtuellen Gerät
     * (127.0.0.1:53225, SN "virtual...") – /unico/ ist dort offenbar ein
     * PixelFlow-interner Pfad und nicht der dokumentierte Weg über Port 8088.
     * Die Layer-Abfrage (/pixelhue/v1/layers/list-detail) funktioniert
     * nachweislich, also nutzen wir denselben Prefix auch für Screens.
     *
     * Optionale Query-Parameter laut Doku: limit, page, screenId, presetId,
     * presetType, screenType. Werden hier bewusst weggelassen, da die
     * gleich aufgebaute Layer-Abfrage auch ohne sie vollständige Daten
     * liefert.
     */
    public function getScreens(): array
    {
        return $this->request('GET', '/v1/screen/list-detail');
    }

    /**
     * connectorType -> Klartext. Belegt durch Abgleich zweier Mitschnitte
     * (P10 real + P20 virtuell):
     *   6  = HDMI 2.0     8  = DP 1.2      13 = 12G-SDI
     *   24 = OPT (Faser)  4  = intern (AUX/MVR haben keinen echten Stecker)
     */
    public const CONNECTOR_TYPES = [
        4  => 'intern',
        6  => 'HDMI',
        8  => 'DP',
        13 => 'SDI',
        24 => 'OPT',
    ];

    /**
     * interfaceType -> Klartext (Rolle des Interfaces).
     */
    public const INTERFACE_TYPES = [
        2  => 'Input',
        4  => 'Output',
        8  => 'AUX',
        16 => 'MVR',
    ];

    /**
     * Alle Interfaces (Inputs, Outputs, AUX, MVR, OPT) lesen.
     * Endpunkt: GET /unico/v1/interface/list-detail
     *
     * WICHTIG: Die 'interfaceId' aus dieser Liste ist genau die 'sourceId',
     * die beim Anlegen eines Layers verwendet wird.
     *
     * Achtung: sourceId ist NICHT die Eingangsnummer und NICHT geräteweit
     * konstant! Kombi-Eingänge (DP+HDMI am selben physischen Eingang) belegen
     * je zwei IDs, und der Startoffset unterscheidet sich je Gerät:
     *   P20 (virtuell): Input 1 = DP 1 / HDMI 2, ... Input 9 (SDI) = 30
     *   P10 (real):     Input 1 = DP 9 / HDMI 10,  ... Input 5 (SDI) = 20
     * Deshalb IMMER aus dieser Liste auslesen, nie hartkodieren.
     *
     * Relevante Felder je Eintrag:
     *   interfaceId                              -> sourceId
     *   general.name                             -> z.B. "Input 1-HDMI 2.0"
     *   state                                    -> 1 = aktiver Port des
     *                                               Kombi-Eingangs, 0 = inaktiv
     *   auxiliaryInfo.online                     -> Signal anliegend
     *   auxiliaryInfo.group.name                 -> physischer Eingang ("Input 1")
     *   auxiliaryInfo.connectorInfo.interfaceType-> 2=Input, 4=Output, 8=AUX, 16=MVR
     *   auxiliaryInfo.connectorInfo.type         -> connectorType, siehe oben
     */
    public function getInterfaces(): array
    {
        return $this->unicoRequest('GET', '/v1/interface/list-detail');
    }

    /**
     * sourceId (= interfaceId) zu einem Interface-Namen finden.
     * Vergleich ist case-insensitive; erst exakt, dann als Teilstring.
     * Beispiel: "Input 9-12G-SDI" oder auch nur "Input 9" -> 30
     */
    public function findSourceIdByName(string $name): int
    {
        $res = $this->getInterfaces();
        $list = $res['data']['list'] ?? [];
        $needle = mb_strtolower(trim($name));

        foreach ($list as $if) {
            if (mb_strtolower($if['general']['name'] ?? '') === $needle) {
                return (int) $if['interfaceId'];
            }
        }
        foreach ($list as $if) {
            if ($needle !== '' && mb_strpos(mb_strtolower($if['general']['name'] ?? ''), $needle) !== false) {
                return (int) $if['interfaceId'];
            }
        }
        throw new RuntimeException("Kein Interface gefunden für Name: {$name}");
    }

    /**
     * sceneType (in layerIdObj) -> Szene innerhalb eines Screens.
     * Belegt durch Mitschnitt "pgm_pvw_differencs": Derselbe Screen (gleiche
     * screenGuid, gleiche attachScreenId) unterscheidet Program und Preview
     * ausschließlich über dieses Feld.
     *   2 = PGM (Program / Live-Ausgang)
     *   4 = PVW (Preview)
     */
    public const SCENE_PGM = 2;
    public const SCENE_PVW = 4;

    public const SCENE_TYPES = [
        self::SCENE_PGM => 'PGM',
        self::SCENE_PVW => 'PVW',
    ];

    /**
     * TAKE: Preview auf Program schalten (mit Überblendeffekt).
     * Endpunkt laut Doku Kap. 4.1.3: PUT /v1/screen/take
     *
     * Body laut Doku:
     *   [{"screenId","screenGuid","effectSelect","direction",
     *     "switchEffect":{"time","type"},"swapEnable","screenName"}]
     *
     * Bewusst werden nur screenId + screenGuid gesendet: Die optionalen Felder
     * (switchEffect.time/type, effectSelect, direction, swapEnable) haben
     * unbekannte Semantik, und ein mitgesendetes switchEffect.time = 0 könnte
     * die am Gerät eingestellte Überblendzeit überschreiben - TAKE würde dann
     * wie CUT wirken. Ohne diese Felder nutzt das Gerät seine eigene
     * Konfiguration. Über $opts lassen sie sich bei Bedarf ergänzen.
     *
     * Hinweis: Für den harten Schnitt ohne Blende gibt es PUT /v1/screen/cut
     * (Doku 4.1.4) - hier nicht implementiert.
     */
    public function takeScreen(int $screenId, array $opts = []): array
    {
        $screen = $this->getScreenByScreenId($screenId);
        $entry = [
            'screenId'   => $screenId,
            'screenGuid' => $screen['guid'] ?? '',
        ];
        foreach (['effectSelect', 'direction', 'swapEnable', 'switchEffect', 'screenName'] as $k) {
            if (array_key_exists($k, $opts)) {
                $entry[$k] = $opts[$k];
            }
        }
        return $this->unicoRequest('PUT', '/v1/screen/take', [$entry]);
    }

    /**
     * sourceType beim Layer-Anlegen:
     *   2 = physischer Input (Interface)
     *   5 = Bild aus dem Gerätespeicher (Gallery/BKG)
     * Beide haben EIGENE, voneinander unabhängige ID-Räume: sourceId 3 kann
     * gleichzeitig ein Interface und ein Bild meinen - erst sourceType macht
     * es eindeutig.
     */
    public const SOURCE_INPUT = 2;
    public const SOURCE_IMAGE = 5;

    /**
     * Bilder im Gerätespeicher lesen.
     * Endpunkt laut Doku Kap. 7.1: GET /v1/picture/list
     *
     * Je Eintrag u.a.:
     *   pictureId              -> sourceId für sourceType 5
     *   general.name/width/height/size
     *   isUsed                 -> Bild liegt bereits auf einem Layer
     *   path[]                 -> {type, url, relativePath}, Voll- und
     *                             Thumbnail-Variante
     */
    public function getPictures(): array
    {
        return $this->unicoRequest('GET', '/v1/picture/list');
    }

    /**
     * Bilddatei vom Gerät holen (Thumbnail oder Vollbild).
     *
     * Die Bilder liegen unter /picture/bkg/<guid>_<id>[_thumb].png und werden
     * laut Mitschnitt OHNE Authorization ausgeliefert - der Browser könnte sie
     * theoretisch direkt laden. Wir holen sie trotzdem über PHP, weil der
     * Browser den Prozessor nicht zwingend erreicht (der PHP-Server ist die
     * Brücke) und weil so keine Mixed-Content-/Origin-Fragen entstehen.
     *
     * @return array{content: string, contentType: string}
     */
    public function fetchPicture(string $relativePath): array
    {
        // Nur Bildpfade des Geräts zulassen - sonst wäre das hier ein offener
        // Proxy, über den sich beliebige Geräte-URLs abrufen ließen.
        if (!preg_match('#^/?picture/[A-Za-z0-9_\-]+/[A-Za-z0-9_\-.]+\.(png|jpg|jpeg|bmp)$#i', $relativePath)) {
            throw new RuntimeException("Unzulässiger Bildpfad: {$relativePath}");
        }

        $url = sprintf('http://%s:%d/%s',
            $this->cfg['device_ip'], $this->cfg['device_port'], ltrim($relativePath, '/'));

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => $this->cfg['timeout'],
            CURLOPT_CONNECTTIMEOUT => $this->cfg['timeout'],
        ]);
        $body = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $ctype = curl_getinfo($ch, CURLINFO_CONTENT_TYPE) ?: 'image/png';
        $err = curl_error($ch);
        curl_close($ch);

        $this->log("GET {$url} -> HTTP {$code}" . ($err ? " (curl: {$err})" : ''));

        if ($err) {
            throw new RuntimeException("Bild konnte nicht geladen werden: {$err}");
        }
        if ($code !== 200 || $body === false || $body === '') {
            throw new RuntimeException("Bild nicht verfügbar (HTTP {$code}): {$relativePath}");
        }
        return ['content' => $body, 'contentType' => $ctype];
    }

    /**
     * Wandelt den Pfad aus picture/list in den HTTP-Pfad des Geräts.
     *
     * picture/list liefert DATEISYSTEM-Pfade, keine URLs:
     *   /userdata/applicationsdata/userver/resource/a/picture/bkg/<guid>_1.bmp
     * Ausgeliefert wird die Datei aber unter:
     *   http://<ip>:8088/picture/bkg/<guid>_1.bmp
     * Der HTTP-Pfad ist also der Teil ab "picture/".
     */
    public static function devicePathToHttp(?string $path): ?string
    {
        if (!$path) {
            return null;
        }
        $pos = strpos($path, 'picture/');
        return $pos === false ? null : '/' . substr($path, $pos);
    }

    /**
     * Kandidaten für die Bilddatei eines Eintrags aus picture/list.
     *
     * Beobachtet: Nur manche Bilder haben in path[] eine eigene
     * Thumbnail-Datei (<base>_thumb.jpg). Fehlt sie dort, wird sie aus dem
     * Vollbildnamen abgeleitet und probiert - denn das Vollbild ist oft ein
     * unkomprimiertes .bmp (ein 7040x2520-BMP sind ~53 MB) und als Vorschau
     * völlig ungeeignet.
     *
     * @return string[] HTTP-Pfade, in Reihenfolge des Vorrangs
     */
    public static function pictureFileCandidates(array $picture, bool $thumb): array
    {
        $full = null;
        $thumbPath = null;
        foreach ($picture['path'] ?? [] as $p) {
            $http = self::devicePathToHttp($p['relativePath'] ?? ($p['url'] ?? null));
            if (!$http) {
                continue;
            }
            if (stripos($http, '_thumb') !== false) {
                $thumbPath = $http;
            } elseif ($full === null) {
                $full = $http;
            }
        }

        if (!$thumb) {
            return array_values(array_filter([$full, $thumbPath]));
        }

        $cands = [];
        if ($thumbPath) {
            $cands[] = $thumbPath;
        }
        if ($full) {
            // <base>.bmp -> <base>_thumb.jpg / _thumb.png
            $base = preg_replace('/\.[^.\/]+$/', '', $full);
            $cands[] = $base . '_thumb.jpg';
            $cands[] = $base . '_thumb.png';
            $cands[] = $full;   // letzter Ausweg
        }
        return array_values(array_unique($cands));
    }

    /**
     * Bilddatei eines Bildes aus dem Gerätespeicher holen.
     * Probiert die Kandidaten der Reihe nach, der erste Treffer gewinnt.
     *
     * @return array{content: string, contentType: string, path: string}
     */
    public function getPictureFile(int $pictureId, bool $thumb = true): array
    {
        $res = $this->getPictures();
        $pic = null;
        foreach ($res['data']['list'] ?? [] as $p) {
            if ((int) ($p['pictureId'] ?? -1) === $pictureId) {
                $pic = $p;
                break;
            }
        }
        if ($pic === null) {
            throw new RuntimeException("Bild mit pictureId={$pictureId} nicht gefunden.");
        }

        $cands = self::pictureFileCandidates($pic, $thumb);
        if (empty($cands)) {
            throw new RuntimeException("Bild {$pictureId} hat keine verwertbaren Pfade.");
        }

        $last = '';
        foreach ($cands as $path) {
            try {
                $img = $this->fetchPicture($path);
                $img['path'] = $path;
                return $img;
            } catch (Throwable $e) {
                $last = $e->getMessage();
            }
        }
        throw new RuntimeException("Keine Bilddatei abrufbar für pictureId={$pictureId}. Zuletzt: {$last}");
    }

    /**
     * screenIdObj.type -> Art des Screens (per Mitschnitt belegt):
     *   2 = normaler Screen (Program/Preview-Canvas)
     *   4 = AUX-Ausgang
     *   8 = MVR (Multiviewer)
     */
    public const SCREEN_NORMAL = 2;
    public const SCREEN_AUX    = 4;
    public const SCREEN_MVR    = 8;

    public const SCREEN_TYPES = [
        self::SCREEN_NORMAL => 'Screen',
        self::SCREEN_AUX    => 'AUX',
        self::SCREEN_MVR    => 'MVR',
    ];

    /**
     * PGM eines Screens zum Bearbeiten freischalten.
     * Endpunkt: PUT /unico/v1/screen/pgm-edit
     * Body: [{"screenId":3,"screenGuid":"…","pgmEdit":1}]
     *
     * Per Mitschnitt: AUX-Screens haben pgmEdit=0 und nehmen so kein
     * layers/create an. PixelFlow schaltet sie erst frei, bevor es einen
     * Layer anlegt. Normale Screens stehen bereits auf pgmEdit=1.
     */
    public function setPgmEdit(int $screenId, bool $enable = true): array
    {
        $screen = $this->getScreenByScreenId($screenId);
        $body = [[
            'screenId'   => $screenId,
            'screenGuid' => $screen['guid'] ?? '',
            'pgmEdit'    => $enable ? 1 : 0,
        ]];
        return $this->unicoRequest('PUT', '/v1/screen/pgm-edit', $body);
    }

    /**
     * layerIdObj.type -> Art des Layers (per Mitschnitt belegt):
     *   2  = Layer auf einem normalen Screen
     *   4  = Layer auf einem AUX-Ausgang
     *   8  = Layer auf dem MVR
     *   16 = BKG-Layer (Hintergrund) - existiert IMMER, genau einer je Szene,
     *        wird nicht angelegt/gelöscht, sondern ein- bzw. ausgeschaltet
     *
     * REGEL: layerIdObj.type entspricht dem screenIdObj.type des Ziel-Screens
     * (2/4/8). Nur der BKG fällt mit 16 aus dem Schema.
     * Gegenprobe aus dem AUX-Mitschnitt: 14 Layer mit type=8 hängen an
     * attachScreenId=7 - dem MVR (screenIdObj.type=8).
     */
    public const LAYER_NORMAL = 2;
    public const LAYER_BKG    = 16;

    /**
     * Layer ein-/ausschalten.
     * Endpunkt: PUT /unico/v1/layers/switch
     * Body: [{"sn":"…","layerId":N,"enable":0|1}]
     *
     * Per Mitschnitt belegt für den BKG-Layer ("BKG enablen"). Ob es bei
     * normalen Layern (type 2) genauso greift, ist NICHT verifiziert - dort
     * wird in allen Mitschnitten stattdessen gelöscht und neu angelegt.
     */
    public function setLayerEnable(int $layerId, bool $enable): array
    {
        $body = [[
            'sn'      => $this->getSerialNumber(),
            'layerId' => $layerId,
            'enable'  => $enable ? 1 : 0,
        ]];
        return $this->unicoRequest('PUT', '/v1/layers/switch', $body);
    }

    /**
     * Quelle eines bestehenden Layers wechseln.
     * Endpunkt: PUT /unico/v1/layers/source
     * Body: [{"sn":"…","layerId":N,"source":{"general":{"sourceId":X,"sourceType":Y}}}]
     *
     * sourceType: 2 = Input, 5 = Bild aus dem Gerätespeicher.
     */
    public function setLayerSource(int $layerId, int $sourceId, int $sourceType = self::SOURCE_INPUT): array
    {
        $body = [[
            'sn'      => $this->getSerialNumber(),
            'layerId' => $layerId,
            'source'  => ['general' => ['sourceId' => $sourceId, 'sourceType' => $sourceType]],
        ]];
        return $this->unicoRequest('PUT', '/v1/layers/source', $body);
    }

    /**
     * Den BKG-Layer einer Szene finden (layerIdObj.type = 16).
     * Er existiert immer - je Screen und Szene genau einer.
     */
    public function getBkgLayer(int $screenId, int $sceneType = self::SCENE_PGM): ?array
    {
        $screen = $this->getScreenByScreenId($screenId);
        $guid = $screen['guid'] ?? null;

        foreach ($this->getLayers()['data']['list'] ?? [] as $layer) {
            $o = $layer['layerIdObj'] ?? [];
            if ((int) ($o['type'] ?? 0) !== self::LAYER_BKG) {
                continue;
            }
            if ($guid !== null && ($o['screenGuid'] ?? null) !== $guid) {
                continue;
            }
            if ((int) ($o['sceneType'] ?? -1) !== $sceneType) {
                continue;
            }
            return $layer;
        }
        return null;
    }

    /**
     * Diagnose: harmloser Lesezugriff über die /unico/-Route. Damit lässt
     * sich prüfen, ob der aktuelle Token dort überhaupt akzeptiert wird,
     * ohne etwas am Gerät zu verändern.
     */
    public function probeUnicoRead(): array
    {
        return $this->unicoRequest('GET', '/v1/screen/list-detail');
    }

    /**
     * Rohen Screen-Datensatz zu einer top-level screenId finden (für internen
     * Gebrauch, z.B. um die screenGuid für Layer-Operationen zu ermitteln).
     */
    public function getScreenByScreenId(int $screenId): array
    {
        $res = $this->getScreens();
        // Antwortstruktur laut Doku 4.2.1: data.list[]
        $raw = $res['data'] ?? [];
        $list = (isset($raw['list']) && is_array($raw['list'])) ? $raw['list'] : [];
        foreach ($list as $screen) {
            if ((int) ($screen['screenId'] ?? -1) === $screenId) {
                return $screen;
            }
        }
        throw new RuntimeException("Screen mit screenId={$screenId} nicht gefunden.");
    }

    // ---------------------------------------------------------------
    // Layer (Kapitel 5 der API-Doku)
    // ---------------------------------------------------------------

    /**
     * Alle Layer inkl. Position/Größe/Transparenz/Enable-Status abrufen.
     * Optional per layerId filtern.
     */
    public function getLayers(?int $layerId = null): array
    {
        $query = [];
        if ($layerId !== null) {
            $query['layerId'] = $layerId;
        }
        return $this->request('GET', '/v1/layers/list-detail', $query);
    }

    /**
     * Layer selektieren (Auswahlrahmen im Multiviewer), NICHT enable/disable.
     */
    public function selectLayer(int $layerId, bool $selected): array
    {
        return $this->request('PUT', '/v1/layers/select', [], [
            [
                'layerId'  => $layerId,
                'selected' => $selected ? 1 : 0,
            ],
        ]);
    }

    /**
     * Layer-Transparenz setzen.
     * Endpunkt: PUT /unico/v1/layers/opacity  (EIGENER Pfad, nicht /window!)
     * Body: [{"layerId": <id>, "opacity": <0-100>}]
     *
     * Per Traffic-Mitschnitt belegt. Achtung: Hier wird - anders als bei
     * /layers/window - KEINE Seriennummer im Body erwartet. Ein Aufruf mit
     * diesem Body gegen /layers/window quittiert das Geraet mit
     * code 8210 "manage param invalid".
     */
    public function setLayerOpacity(int $layerId, int $opacityPercent): array
    {
        $body = [[
            'layerId' => $layerId,
            'opacity' => max(0, min(100, $opacityPercent)),
        ]];
        return $this->unicoRequest('PUT', '/v1/layers/opacity', $body);
    }

    /**
     * Layer-Position/-Größe setzen.
     * Endpunkt: PUT /unico/v1/layers/window
     * Body: [{"layerId": <id>, "sn": "<Geräte-SN>", "window": {"width","height","x","y"}}]
     * Achtung: hier wird zusätzlich die Geräte-Seriennummer im Body erwartet.
     */
    public function setLayerWindow(int $layerId, int $x, int $y, int $width, int $height): array
    {
        $body = [[
            'layerId' => $layerId,
            'sn'      => $this->getSerialNumber(),
            'window'  => ['width' => $width, 'height' => $height, 'x' => $x, 'y' => $y],
        ]];
        return $this->unicoRequest('PUT', '/v1/layers/window', $body);
    }

    /**
     * Layer löschen. Laut Traffic-Mitschnitt gibt es KEIN eigenes
     * enable/disable-Attribut für Layer – ein Layer existiert oder
     * existiert nicht. "Ausschalten" = Löschen, "Einschalten" = Neu
     * anlegen (siehe createLayer()).
     * Endpunkt: DELETE /unico/v1/layers
     * Body: [{"layerId": <id>, "sn": "<SN>", "id": <id>}]
     */
    public function deleteLayer(int $layerId): array
    {
        $body = [[
            'layerId' => $layerId,
            'sn'      => $this->getSerialNumber(),
            'id'      => $layerId,
        ]];
        return $this->unicoRequest('DELETE', '/v1/layers', $body);
    }

    /**
     * Neuen Layer auf einem Screen anlegen (= "Einschalten" eines Bildinhalts).
     * Endpunkt: PUT /unico/v1/layers/create
     *
     * Die Zuordnung zum Ziel-Screen läuft über dessen screenGuid (der
     * zuverlässigste Schlüssel laut Traffic-Mitschnitt), NICHT über die
     * einfache screenId. $screenId hier ist die top-level screenId, wie
     * sie auch im UI angezeigt wird – die Methode löst intern die passende
     * screenGuid auf.
     *
     * $opts['sceneType'] wählt die Zielszene: 2 = PGM (Default), 4 = PVW.
     * Beide liegen im selben Screen und teilen sich screenGuid und
     * attachScreenId - nur sceneType unterscheidet sie (per Mitschnitt belegt).
     *
     * layerIdObj.type wird automatisch aus dem Ziel-Screen abgeleitet
     * (2 = Screen, 4 = AUX, 8 = MVR); AUX/MVR werden bei Bedarf zuvor per
     * pgm-edit freigeschaltet. Über $opts['type'] / $opts['attachScreenType']
     * lässt sich das überschreiben.
     */
    public function createLayer(
        int $screenId,
        int $sourceId,
        int $sourceType,
        int $x,
        int $y,
        int $width,
        int $height,
        string $name = '',
        int $opacity = 100,
        array $opts = []
    ): array {
        $screen = $this->getScreenByScreenId($screenId);
        $screenGuid = $screen['guid'] ?? null;
        if (!$screenGuid) {
            throw new RuntimeException("Konnte screenGuid für screenId={$screenId} nicht ermitteln.");
        }

        // attachScreenId ist die top-level screenId - NICHT screenIdObj.id.
        // Bestätigt für normale Screens (1) und AUX (3,4,5,6).
        $attachScreenId = $opts['attachScreenId'] ?? $screenId;

        // layerIdObj.type richtet sich nach der Art des Ziel-Screens:
        // 2 = normaler Screen, 4 = AUX, 8 = MVR. Ein fest verdrahtetes 2 war
        // der Grund, warum AUX-Screens kein layers/create angenommen haben.
        $screenType = (int) ($screen['screenIdObj']['type'] ?? self::SCREEN_NORMAL);
        $layerType = (int) ($opts['type'] ?? $screenType);

        // AUX/MVR stehen auf pgmEdit=0 und lehnen das Anlegen ab. PixelFlow
        // schaltet sie vorher frei - das machen wir genauso. Normale Screens
        // sind bereits freigeschaltet und bleiben unangetastet.
        if ($screenType !== self::SCREEN_NORMAL && (int) ($screen['pgmEdit'] ?? 0) !== 1) {
            $this->setPgmEdit($screenId, true);
            $this->log("pgmEdit für screenId={$screenId} (type {$screenType}) aktiviert");
        }

        $body = [
            'layerIdObj' => [
                'type'             => $layerType,
                'sceneType'        => $opts['sceneType'] ?? self::SCENE_PGM,
                'attachScreenId'   => $attachScreenId,
                'attachScreenType' => $opts['attachScreenType'] ?? 0,
                'screenGuid'       => $screenGuid,
            ],
            'namePrefix' => 'Layer ',
            'general'    => ['name' => $name],
            'source'     => [
                'general' => ['sourceId' => $sourceId, 'sourceType' => $sourceType],
                'primary' => ['sourceId' => $sourceId, 'sourceType' => $sourceType],
            ],
            'window' => ['width' => $width, 'height' => $height, 'x' => $x, 'y' => $y],
            'sn'     => $this->getSerialNumber(),
            'locked' => 0,
        ];

        $createRes = $this->unicoRequest('PUT', '/v1/layers/create', $body);

        // Opacity ist nicht Teil des Create-Bodys (per Mitschnitt) und muss
        // separat über /v1/layers/opacity gesetzt werden.
        //
        // ACHTUNG: Die Antwort ist {"code":0,"data":[{"layerId":...,...}]} -
        // data ist ein ARRAY, kein Objekt. Ein früherer Zugriff auf
        // $createRes['data']['layerId'] lief deshalb immer ins Leere, wodurch
        // ein gewünschtes opacity != 100 stillschweigend ignoriert wurde.
        $newLayerId = $createRes['data'][0]['layerId'] ?? null;

        if ($opacity !== 100 && $newLayerId !== null) {
            $this->setLayerOpacity((int) $newLayerId, $opacity);
        }

        return $createRes;
    }

    /**
     * Alle Layer eines Screens löschen ("Screen leeren").
     *
     * ACHTUNG: PGM und PVW teilen sich dieselbe screenGuid. Ohne $sceneType
     * werden deshalb die Layer BEIDER Szenen gelöscht. Mit $sceneType
     * (2 = PGM, 4 = PVW) bleibt die jeweils andere Szene unberührt.
     */
    public function clearScreen(int $screenId, ?int $sceneType = null): array
    {
        $screen = $this->getScreenByScreenId($screenId);
        $screenGuid = $screen['guid'] ?? null;
        if (!$screenGuid) {
            throw new RuntimeException("Konnte screenGuid für screenId={$screenId} nicht ermitteln.");
        }

        $layersRes = $this->getLayers();
        $allLayers = $layersRes['data']['list'] ?? [];

        $deleted = [];
        $failed = [];
        foreach ($allLayers as $layer) {
            $idObj = $layer['layerIdObj'] ?? [];
            if (($idObj['screenGuid'] ?? null) !== $screenGuid) {
                continue;
            }
            if ($sceneType !== null && (int) ($idObj['sceneType'] ?? -1) !== $sceneType) {
                continue;
            }
            $layerId = $layer['layerId'] ?? null;
            if ($layerId === null) {
                continue;
            }
            try {
                $this->deleteLayer((int) $layerId);
                $deleted[] = $layerId;
            } catch (Throwable $e) {
                $failed[] = ['layerId' => $layerId, 'error' => $e->getMessage()];
            }
        }

        return [
            'deleted' => $deleted,
            'failed'  => $failed,
            'scene'   => $sceneType === null ? 'alle' : (self::SCENE_TYPES[$sceneType] ?? $sceneType),
        ];
    }

    /**
     * Seriennummer des Geräts (wird für einige /unico/v1 Schreibzugriffe
     * im Body benötigt). Wird beim ersten Zugriff automatisch geladen.
     */
    private function getSerialNumber(): string
    {
        if ($this->sn === null) {
            $this->getOpenDetail();
        }
        if ($this->sn === null) {
            throw new RuntimeException('Konnte Seriennummer (sn) nicht vom Gerät abrufen.');
        }
        return $this->sn;
    }

    // ---------------------------------------------------------------
    // HTTP-Kern
    // ---------------------------------------------------------------

    /**
     * Request MIT Authorization-Header gegen /pixelhue/v1/...
     */
    private function request(string $method, string $path, array $query = [], ?array $body = null): array
    {
        $headers = ['Authorization: ' . $this->authHeaderValue()];
        return $this->rawRequest($method, $path, $query, $body, $headers, $this->baseUrl);
    }

    /**
     * Request MIT Authorization-Header gegen /unico/v1/... (Layer-Schreibzugriffe,
     * Screen-Liste etc.)
     */
    private function unicoRequest(string $method, string $path, ?array $body = null, array $query = []): array
    {
        // PixelFlow schickt bei /unico/-Requests laut Mitschnitt zusätzlich
        // einen "Sn"-Header mit der Geräte-Seriennummer. Ob er zwingend ist,
        // ist nicht belegt - schaden kann er nicht, deshalb senden wir ihn mit.
        $headers = [
            'Authorization: ' . $this->authHeaderValue(),
            'Sn: ' . $this->getSerialNumber(),
        ];
        return $this->rawRequest($method, $path, $query, $body, $headers, $this->unicoBaseUrl);
    }

    private function authHeaderValue(): string
    {
        return ($this->cfg['token_header_prefix'] ?? '') . $this->getToken();
    }

    /**
     * Request OHNE (zwingenden) Authorization-Header.
     */
    private function rawRequest(string $method, string $path, array $query = [], ?array $body = null, array $extraHeaders = [], ?string $baseUrlOverride = null): array
    {
        $url = ($baseUrlOverride ?? $this->baseUrl) . $path;
        if (!empty($query)) {
            $url .= '?' . http_build_query($query);
        }

        $headers = array_merge(['Content-Type: application/json'], $extraHeaders);

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_TIMEOUT        => $this->cfg['timeout'],
            CURLOPT_CONNECTTIMEOUT => $this->cfg['timeout'],
        ]);

        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
        }

        $raw = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        $this->log("{$method} {$url} -> HTTP {$httpCode}" . ($curlError ? " (curl error: {$curlError})" : ''));

        if ($curlError) {
            throw new RuntimeException("Verbindung zum Pixelhue-Gerät fehlgeschlagen: {$curlError}");
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            throw new RuntimeException("Ungültige Antwort vom Gerät (HTTP {$httpCode}): " . substr((string) $raw, 0, 300));
        }

        // Laut Doku (Kapitel 4 "Error Code"): code 0 = Erfolg, alles andere
        // = Fehler. Ohne diese Prüfung rutschen Fehlerantworten (z.B. ein
        // falscher Pfad oder ungültiger Token) unbemerkt durch und landen im
        // UI als "leere Liste" statt als lesbare Fehlermeldung.
        if (array_key_exists('code', $decoded) && (int) $decoded['code'] !== 0) {
            $msg = $decoded['message'] ?? 'unbekannter Fehler';
            $this->log("API-Fehler bei {$method} {$url}: code={$decoded['code']} message={$msg}");
            throw new RuntimeException(
                "Gerät meldet Fehler (code {$decoded['code']}) bei {$method} {$path}: {$msg}"
            );
        }

        return $decoded;
    }

    private function log(string $line): void
    {
        if (empty($this->cfg['log_file'])) {
            return;
        }
        @file_put_contents(
            $this->cfg['log_file'],
            '[' . date('Y-m-d H:i:s') . "] {$line}\n",
            FILE_APPEND
        );
    }
}
