<?php
/**
 * Konfiguration für die Pixelhue Anbindung.
 * Unterstützt mehrere Prozessoren im Netzwerk über eine Alias-Liste.
 */
return [

    // HTTP-Statuscode für Fehlerantworten von api.php und wrapper.php.
    //
    // 200 (Default): Fehler kommen mit HTTP 200 und der Meldung im Body an.
    //      Nötig, wenn der Webserver/Proxy den Body von 5xx-Antworten durch
    //      eine eigene Fehlerseite ersetzt - dann sieht man sonst nur ein
    //      nacktes "500" ohne Text. Die Meldung steht in beiden Fällen in
    //      {"ok":false,"error":"..."}, das UI wertet 'ok' aus, nicht den
    //      HTTP-Code.
    // 500: HTTP-korrekter, aber nur sinnvoll, wenn der Server 5xx-Bodies
    //      unverändert durchreicht.
    'error_http_code' => 200,

    // Liste aller ansprechbaren Prozessoren im Netzwerk.
    // 'alias' ist der Schlüssel, über den UI und wrapper.php den
    // Prozessor auswählen (?processor=<alias>). Der ERSTE Eintrag ist
    // der initial ausgewählte Prozessor im UI.
    'processors' => [
        [
            'alias'       => 'P20-Main',
            'device_ip'   => '192.168.1.80',
            'device_port' => 8088,
        ],
        [
            'alias'       => 'P20-Backup',
            'device_ip'   => '192.168.1.82',
            'device_port' => 8088,
        ],
        [
            'alias'       => 'P10-Entrance',
            'device_ip'   => '192.168.1.83',
            'device_port' => 8088,
        ],
        [
            'alias'       => 'Q8-Showroom',
            'device_ip'   => '192.168.1.84',
            'device_port' => 8088,
        ],
		[
            'alias'       => 'P10-1stFloor',
            'device_ip'   => '192.168.1.85',
            'device_port' => 8088,
        ]
    ],

    // Gemeinsame Einstellungen, gelten für ALLE Prozessoren oben, sofern
    // nicht einzeln überschrieben (siehe Kommentar unten).
    'defaults' => [
        'node_id' => 1,
        'timeout' => 5,

        // Authentifizierung
        //
        // WICHTIG (belegt durch Decodieren des echten PixelFlow-Tokens aus
        // dem Wireshark-Mitschnitt):
        //   - Der selbst gebaute Token ('jwt') hat den Payload
        //     {"SN":...,"iss":"gin-jwt-demo","iat":...,"exp":...}
        //   - PixelFlows echter Token hat {"admin":true,"exp":...,"name":...}
        //     und ist NICHT mit "whatasecret" signiert (Secret unbekannt).
        // Folge: 'jwt' wird von den /pixelhue/-Leserouten akzeptiert, von den
        // /unico/-Routen (Layer anlegen/loeschen/verschieben) aber abgelehnt
        // ("token is not correct").
        //
        // Fuer Schreibzugriffe daher 'login' verwenden: Der Token kommt dann
        // vom Geraet selbst und sollte auf beiden Routen gelten. Dafuer muessen
        // username/password unten echt sein - sonst schlaegt JEDER Request fehl.
        //
        // Welcher Modus bei dir funktioniert, zeigt: api.php?action=selftest
        'auth_mode' => 'login',

        'token_header_prefix' => '',

        'jwt_secret' => 'whatasecret',
        'jwt_issuer' => 'gin-jwt-demo',
        'jwt_ttl'    => 100,

        'username' => 'admin',
        'password' => 'MTIzNDU2',

        'log_file' => __DIR__ . '/pixelhue.log',
    ],

    // Beispiel für Overrides pro Prozessor (falls z.B. ein Gerät andere
    // Zugangsdaten braucht): einfach im jeweiligen 'processors'-Eintrag
    // oben zusätzlich einen der Schlüssel aus 'defaults' angeben, z.B.
    // 'username' => 'anderer-user'. Werte aus dem Prozessor-Eintrag haben
    // immer Vorrang vor 'defaults'.
];
