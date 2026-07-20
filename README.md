# Pixelhue P10 / P20 – Steuerung

PHP-Backend mit zwei Weboberflächen und einer GET-Schnittstelle zum Auslesen
und Steuern von Layern auf Pixelhue-Videoprozessoren (P10 und P20, getestet
mit Firmware V1.8.0).

Die Erkenntnisse über die Geräte-API stammen aus Wireshark-Mitschnitten der
Original-Software „PixelFlow" und wurden an zwei Geräten (P20 virtuell, P10
real) gegengeprüft.

---

## Inhalt

- [Dateien](#dateien)
- [Installation](#installation)
- [Konzepte](#konzepte)
- [Oberflächen](#oberflächen)
- [wrapper.php – GET-Schnittstelle](#wrapperphp--die-get-schnittstelle)
- [Beispiele](#beispiele)
- [api.php – JSON-Schnittstelle](#apiphp--die-json-schnittstelle)
- [Fehlerbehandlung](#fehlerbehandlung)
- [API-Referenz des Geräts](#api-referenz-des-geräts)

---

## Dateien

| Datei | Zweck |
| --- | --- |
| `config.php` | Prozessorliste, Zugangsdaten, Grundeinstellungen |
| `processors.php` | löst Prozessor-Alias zu vollständiger Konfiguration auf |
| `error_handling.php` | fängt PHP-Fatals ab, damit Fehler sichtbar werden |
| `PixelhueClient.php` | der eigentliche API-Client |
| `api.php` | JSON-Schnittstelle für die Weboberflächen |
| `wrapper.php` | GET-Schnittstelle für externe Tools (Lichtpult, Show-Control) |
| `index.html` · `style.css` · `app.js` | technische Ansicht (Tabellen) |
| `stage.html` · `stage.css` · `stage.js` | Bildregie, neue Oberfläche |

Die drei Oberflächen sind untereinander verlinkt und nutzen alle dieselbe
`api.php`.

---

## Installation

1. Ordner in ein PHP-fähiges Web-Verzeichnis legen (PHP 7.4+ mit cURL).
2. In `config.php` die Prozessoren eintragen (Alias, IP, Port) und die
   Zugangsdaten prüfen.
3. Seite im Browser öffnen: `index.html` (technisch) oder `stage2.html`
   (Bildregie).

### config.php

```php
'processors' => [
    ['alias' => 'P10-Entrance',     'device_ip' => '10.40.41.226', 'device_port' => 8088],
    ['alias' => 'P20-Main', 'device_ip' => '10.40.41.20',  'device_port' => 8088],
],
'defaults' => [
    'auth_mode' => 'login',
    'username'  => 'admin',
    'password'  => 'MTIzNDU2',   // base64('123456') – siehe unten
    // ...
],
```

**Das Passwort steht base64-kodiert.** PixelFlow überträgt es so, und genau
so gehört es in die Konfiguration – nicht im Klartext. `MTIzNDU2` ist
base64 für das Werkspasswort `123456`. Bei geändertem Gerätepasswort neu
kodieren:

```bash
echo -n 'meinPasswort' | base64
```

> **Sicherheit:** `config.php` enthält Zugangsdaten. Sie sollte nicht direkt
> abrufbar sein – am besten außerhalb des Web-Roots ablegen oder per
> Server-Regel schützen.

---

## Konzepte

Ein paar Eigenheiten der Pixelhue-API, ohne die die Parameter nicht
zusammenpassen.

### Screens, AUX und MVR

Das Gerät kennt drei Arten von Ausgabezielen, unterschieden durch
`screenIdObj.type`:

| type | Art | Besonderheit |
| --- | --- | --- |
| 2 | **Screen** | normaler Ausgang mit Program und Preview |
| 4 | **AUX** | Auxiliary-Weg, muss vor dem ersten Layer freigeschaltet werden |
| 8 | **MVR** | Multiviewer |

Alle drei haben eine eigene `screenId`. Diese ID ist geräteabhängig (am P20
liegen die AUX-Wege auf 3–6, am P10 auf 2–3) – also immer über
`list_screens` ermitteln, nie fest verdrahten.

### PGM und PVW sind zwei Szenen desselben Screens

Ein Screen (type 2) hat zwei Szenen: **PGM** (Program, der Live-Ausgang) und
**PVW** (Preview). Beide teilen sich `screenId` und `screenGuid` –
unterschieden werden sie nur durch `sceneType`:

| sceneType | Szene |
| --- | --- |
| 2 | PGM (Live) |
| 4 | PVW (Preview) |

Mit **TAKE** wird die Preview auf Program geschaltet. AUX und MVR kennen
diese Trennung nicht – dort gibt es nur die eine Szene.

### Quellen: Eingänge und Bilder

Eine Layer-Quelle wird über zwei Felder eindeutig bestimmt:

| sourceType | Quelle | sourceId ist… |
| --- | --- | --- |
| 2 | physischer Eingang | die `interfaceId` |
| 5 | Bild aus dem Gerätespeicher | die `pictureId` |

**Die beiden ID-Räume sind unabhängig.** `sourceId=5` kann mit
`sourceType=2` ein Interface und mit `sourceType=5` ein Bild meinen – erst
beide Felder zusammen sind eindeutig.

`sourceId` ist **nicht** die Eingangsnummer. Ein physischer Eingang belegt
oft zwei Interfaces (DP und HDMI am selben Anschluss), deshalb laufen die IDs
auseinander. Der Anschlusstyp steckt in `connectorType`:

| connectorType | Anschluss |
| --- | --- |
| 6 | HDMI |
| 8 | DP |
| 13 | SDI |
| 24 | OPT (Faser) |

Bei einem Kombi-Eingang (DP + HDMI) kann nur ein Port aktiv sein; welcher,
zeigt das Feld `state` (1 = aktiv). `list_inputs` liefert IDs, Anschluss und
Aktiv-Status – nie hartkodieren.

### Layer

Layer werden mit `create_layer` angelegt, per `delete_layer` entfernt und mit
`set_window` / `set_opacity` verändert. Position und Größe stehen zusammen im
Fenster (`x`, `y`, `width`, `height`), die Deckkraft (0–100) hat einen
eigenen Endpunkt.

Beim Anlegen leitet der Client den korrekten internen Layer-Typ automatisch
aus dem Ziel-Screen ab und schaltet AUX/MVR bei Bedarf frei – man gibt nur
`screenId`, Quelle und Fenster an.

### Hintergrund (BKG)

Der Hintergrund ist ein Sonderfall: Es ist **kein** normaler Layer, den man
anlegt oder löscht. Jeder Screen hat je Szene einen **festen BKG-Layer**, der
immer existiert. Man schaltet ihn ein und weist ihm eine Quelle zu (meist ein
Bild). Dafür gibt es eigene Wrapper-Befehle (`bkg_*`).

---

## Oberflächen

### index.html – technische Ansicht

Tabellen für Screens (mit Typ-Badge Screen/AUX/MVR), Inputs (sourceId,
Anschluss, aktiver Port, Signalstatus) und Layer (mit Szene, Position, Größe,
Deckkraft, editierbar). Dazu ein paar Schnellaktionen, die direkt `wrapper.php`
aufrufen.

### stage.html – Bildregie

Grafische Oberfläche: Quellen links, Screen-Canvas in der Mitte, Layerliste
rechts. Quelle auf den Canvas ziehen legt einen Layer an; Layer lassen sich
ziehen, in der Größe ändern und über die Liste bearbeiten. (alt)

`stage.html` ist die neuere Fassung mit PGM/PVW-Tally-Rahmen,
Tastatursteuerung (Pfeiltasten, Entf, P/V), Fangen an Kanten mit Hilfslinien,
Anschlussfilter für die Quellen und einem Feld, das die aktuelle Szene als
fertige Wrapper-Befehle ausgibt. Beide nutzen dieselbe `api.php`.

---

## wrapper.php – die GET-Schnittstelle

Für externe Tools (Lichtpult, Show-Control, Makros, Browser). Alles per
GET-Parameter, alles über IDs. Der Prozessor wird per `&processor=<alias>`
gewählt; ohne Angabe gilt der erste aus `config.php`.

Mit `&format=text` antwortet der Wrapper `OK` bzw. `ERROR: …` statt JSON –
praktisch für Tools ohne JSON-Parser.

### Lesen

| Action | Parameter | liefert |
| --- | --- | --- |
| `list_screens` | – | Screens mit Art (Screen/AUX/MVR) und Größe |
| `list_inputs` | – | Eingänge mit sourceId, Anschluss, aktivem Port |
| `list_pictures` | – | Bilder mit sourceId (= pictureId) und Auflösung |
| `bkg` | `screenId`, `scene` | Zustand des Hintergrund-Layers |

### Layer

| Action | Pflicht | Optional |
| --- | --- | --- |
| `create_layer` | `screenId`, `sourceId`, `x`, `y`, `width`, `height` | `sourceType` (2), `scene` (pgm), `opacity` (100), `name` |
| `set_window` | `layerId`, `x`, `y`, `width`, `height` | – |
| `set_opacity` | `layerId`, `opacity` | – |
| `delete_layer` | `layerId` | – |
| `select_layer` | `layerId`, `selected` | – |
| `switch_layer` | `layerId`, `enable` | – |

### Screen

| Action | Pflicht | Optional | Wirkung |
| --- | --- | --- | --- |
| `clear_screen` | `screenId` | `scene` | alle Layer löschen; **ohne `scene` beide Szenen** |
| `take` | `screenId` | – | Preview auf Program schalten |
| `pgm_edit` | `screenId` | `enable` (1) | Screen zum Bearbeiten freischalten (AUX/MVR) |

### Hintergrund

| Action | Pflicht | Optional |
| --- | --- | --- |
| `bkg_enable` | `screenId`, `enable` | `scene` |
| `bkg_source` | `screenId`, `sourceId` | `scene`, `sourceType` (5), `enable` (1) |
| `bkg_window` | `screenId`, `x`, `y`, `width`, `height` | `scene` |

`scene` ist überall `pgm` (Standard) oder `pvw`. Bei `bkg_source` wird der
Hintergrund automatisch eingeschaltet; mit `&enable=0` unterbleibt das.

---

## Beispiele

Basis-URL in den Beispielen: `http://<server>/pixelhue/wrapper.php`.
Prozessor-Alias hier `P10-Entrance` (URL-kodiert wenn nötig)

### Quellen und Screens auflisten

```
# Welche Eingänge gibt es, welche IDs, welcher Port ist aktiv?
…/wrapper.php?action=list_inputs

# Welche Screens/AUX-Wege gibt es?
…/wrapper.php?action=list_screens

# Welche Bilder liegen im Gerät (mit pictureId)?
…/wrapper.php?action=list_pictures
```

### Einen Eingang auf einen Screen legen

```
# Input mit sourceId 10, formatfüllend (Screen ist 1920×1080)
…/wrapper.php?action=create_layer&screenId=1&sourceId=10&x=0&y=0&width=1920&height=1080
```

### Zwei Eingänge nebeneinander, jeweils halbes Bild

```
…/wrapper.php?action=clear_screen&screenId=1&scene=pgm
…/wrapper.php?action=create_layer&screenId=1&sourceId=10&x=0&y=0&width=960&height=1080
…/wrapper.php?action=create_layer&screenId=1&sourceId=20&x=960&y=0&width=960&height=1080
```

### Ein Bild als Layer (sourceType 5 ist hier Pflicht)

```
…/wrapper.php?action=create_layer&screenId=1&sourceId=5&sourceType=5&x=0&y=0&width=1920&height=1080
```

### In die Preview bauen, dann live schalten

```
…/wrapper.php?action=clear_screen&screenId=1&scene=pvw
…/wrapper.php?action=create_layer&screenId=1&scene=pvw&sourceId=10&x=0&y=0&width=1920&height=1080
…/wrapper.php?action=take&screenId=1
```

### Position, Größe und Deckkraft eines Layers ändern

```
…/wrapper.php?action=set_window&layerId=21037056&x=100&y=100&width=1280&height=720
…/wrapper.php?action=set_opacity&layerId=21037056&opacity=50
…/wrapper.php?action=delete_layer&layerId=21037056
```

### Hintergrundbild setzen

```
# Bild mit pictureId 5 als Hintergrund von PGM (schaltet BKG automatisch ein)
…/wrapper.php?action=bkg_source&screenId=1&scene=pgm&sourceId=5

# Hintergrund wieder ausschalten
…/wrapper.php?action=bkg_enable&screenId=1&scene=pgm&enable=0
```

### Ein Signal auf einen AUX-Weg legen

Funktioniert wie bei einem normalen Screen – nur mit der AUX-`screenId` (aus
`list_screens`). Freischaltung und interner Layer-Typ ergeben sich
automatisch:

```
…/wrapper.php?action=create_layer&screenId=3&sourceId=10&x=0&y=0&width=1920&height=1080
```

### Als curl mit Text-Antwort

```bash
BASE="http://<server>/pixelhue/wrapper.php"
P="processor=P10-Entrance&format=text"

curl -s "$BASE?action=clear_screen&screenId=1&scene=pgm&$P"
curl -s "$BASE?action=create_layer&screenId=1&sourceId=10&x=0&y=0&width=1920&height=1080&$P"
curl -s "$BASE?action=take&screenId=1&$P"
```

> **Tipp:** In `stage.html` erzeugt das Feld „Wrapper-Befehle" die komplette
> Befehlsfolge, um die gerade sichtbare Szene nachzubauen – fertig zum
> Kopieren.

---

## api.php – die JSON-Schnittstelle

Wird von den Weboberflächen genutzt und antwortet mit
`{"ok":true,…}` / `{"ok":false,"error":…}`. Actions: `processors`,
`selftest`, `node`, `inputs`, `pictures`, `picture_file`, `screens`,
`layers`, `layer_create`, `layer_window`, `layer_opacity`, `layer_delete`,
`layer_select`, `layer_switch`, `screen_clear`, `screen_take`,
`screen_pgm_edit`, `bkg`, `bkg_set`.

`selftest` prüft, ob der eingestellte Auth-Modus auf beiden API-Routen
funktioniert – der schnellste Weg, eine fehlerhafte Konfiguration zu finden.

---

## Fehlerbehandlung

Bei einem Fehler antworten `api.php` und `wrapper.php` standardmäßig mit
**HTTP 200** und der Meldung im Body:

```json
{"ok": false, "error": "Gerät meldet Fehler (code 1001) bei PUT /v1/layers/create: …"}
```

Grund: Manche Server und Proxys ersetzen den Body von 5xx-Antworten durch
eine eigene Fehlerseite – die eigentliche Meldung ginge dann verloren.
Ausschlaggebend ist das Feld `ok`, nicht der HTTP-Status. Wer es
HTTP-korrekt will (und dessen Server 5xx-Bodies durchreicht), stellt in
`config.php` um:

```php
'error_http_code' => 500,   // Standard: 200
```

`error_handling.php` fängt zusätzlich PHP-Fatals ab, sodass auch ein
Parse- oder Fatal-Error eine lesbare Meldung liefert statt einer leeren
Seite. Ergänzend protokolliert `pixelhue.log` (neben `config.php`) jeden
Geräte-Request mit HTTP-Status und, bei API-Fehlern, `code` und `message`.

---

## API-Referenz des Geräts

Für alle, die tiefer eingreifen wollen. Diese Werte sind durch
Wireshark-Mitschnitte belegt.

### Authentifizierung

`auth_mode => 'login'`: Der Token wird per
`POST /pixelhue/v1/system/auth/login` vom Gerät geholt und gilt für alle
Routen. `token_header_prefix` bleibt leer (kein „Bearer").

### Routen und Endpunkte

Lesen läuft über `/pixelhue/v1/…`, Schreiben über `/unico/v1/…`. Die
wichtigsten Endpunkte:

| Methode | Pfad | Zweck |
| --- | --- | --- |
| GET | `/unico/v1/interface/list-detail` | Eingänge |
| GET | `/unico/v1/picture/list` | Bilder |
| GET | `/pixelhue/v1/screen/list-detail` | Screens |
| GET | `/pixelhue/v1/layers/list-detail` | Layer |
| PUT | `/unico/v1/layers/create` | Layer anlegen |
| DELETE | `/unico/v1/layers` | Layer löschen |
| PUT | `/unico/v1/layers/window` | Position/Größe |
| PUT | `/unico/v1/layers/opacity` | Deckkraft (eigener Pfad!) |
| PUT | `/unico/v1/layers/switch` | Layer ein/aus (BKG) |
| PUT | `/unico/v1/layers/source` | Quelle eines Layers wechseln |
| PUT | `/unico/v1/screen/take` | Preview auf Program |
| PUT | `/unico/v1/screen/pgm-edit` | Screen freischalten (AUX/MVR) |

Schreibende `/unico/`-Requests tragen einen `Sn:`-Header mit der
Geräte-Seriennummer.

### Feld-Referenz

| Feld | Werte |
| --- | --- |
| `screenIdObj.type` | 2 = Screen, 4 = AUX, 8 = MVR |
| `layerIdObj.type` | folgt dem Screen-Typ (2/4/8); 16 = BKG |
| `layerIdObj.sceneType` | 2 = PGM, 4 = PVW |
| `sourceType` | 2 = Eingang, 5 = Bild |
| `connectorType` | 6 = HDMI, 8 = DP, 13 = SDI, 24 = OPT |
| `interfaceType` | 2 = Input, 4 = Output, 8 = AUX, 16 = MVR |
| `state` (Interface) | 1 = aktiver Port eines Kombi-Eingangs |
| `pgmEdit` (Screen) | 1 = bearbeitbar; AUX/MVR starten auf 0 |

### Bilder und Thumbnails

`picture/list` liefert in `path[]` **Dateisystempfade**, keine URLs – der
HTTP-Pfad ist der Teil ab `picture/`. Nicht jedes Bild hat eine eigene
Thumbnail-Datei; ist keine vorhanden, wird sie aus dem Vollbildnamen
abgeleitet (`<base>_thumb.jpg`). Das Vollbild ist oft ein unkomprimiertes
`.bmp` und als Vorschau ungeeignet. Bilddateien werden ohne Authentifizierung
ausgeliefert, hier aber über `api.php?action=picture_file&pictureId=…` (mit
`&thumb=1` für die Vorschau) geproxyt.

### Nicht implementiert

Vom Gerät unterstützt, hier aber (noch) nicht umgesetzt:
`screen/cut` (harter Schnitt ohne Blende), `layers/aspect-ratio`
(Seitenverhältnis sperren) und das Ändern der Z-Order.

