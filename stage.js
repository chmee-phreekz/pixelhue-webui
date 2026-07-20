/**
 * Bildregie – Pixelhue P10/P20
 *
 * Nutzt dieselbe api.php wie die technische Ansicht. Alles, was hier über
 * stage.js hinausgeht, ist Bedienung, keine neue Geräte-Funktion:
 * Tastatur, Fangen mit Hilfslinien, Presets, Meldungen als Toasts.
 */

const API = 'api.php';
const PROC_KEY = 'pixelhue-selected-processor';
const SCREEN_KEY = 'pixelhue-selected-screen';
const SCENE_KEY = 'pixelhue-selected-scene';

// PGM und PVW sind zwei Szenen DESSELBEN Screens (gleiche screenGuid),
// unterschieden nur durch layerIdObj.sceneType.
const SCENE_PGM = 2;
const SCENE_PVW = 4;
const SCENE_NAME = { 2: 'PGM', 4: 'PVW' };

// sourceType: Inputs und Bilder haben getrennte ID-Räume.
const SRC_INPUT = 2;
const SRC_IMAGE = 5;

const SCREEN_PLAIN = 2;          // 2 = Screen, 4 = AUX, 8 = MVR

const DROP_SIZE_FACTOR = 0.5;    // Inputs: halbe Screen-Kantenlänge
const SNAP_PX = 8;               // Fangweite in Bildschirmpixeln
const NUDGE = 1;                 // Pfeiltaste
const NUDGE_BIG = 10;            // Shift + Pfeiltaste

const $ = (id) => document.getElementById(id);

let currentProcessor = null;
let currentScreenId = null;
let currentScene = SCENE_PGM;
let screensData = [];
let inputsData = [];
let picturesData = [];
let layersData = [];
let bkgState = null;
let selectedLayerId = null;
let scale = 1;                   // Canvas-Pixel je Geräte-Pixel
let portFilter = null;           // aktiver Anschluss-Filter, null = alle

const currentScreen = () =>
    screensData.find((s) => Number(s.screenId) === Number(currentScreenId)) || null;

const isPlainScreen = () => {
    const s = currentScreen();
    return !s || Number(s.type) === SCREEN_PLAIN;
};

const clamp = (v, min, max) => Math.max(min, Math.min(max, Math.round(v)));

const esc = (s) => String(s ?? '').replace(/[&<>"]/g, (c) =>
    ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]));

// ---------------------------------------------------------------
// Meldungen
// ---------------------------------------------------------------

function toast(body, kind = 'err', title = null) {
    const el = document.createElement('div');
    el.className = `toast toast--${kind}`;
    el.innerHTML = `<div class="toast-title">${esc(title ?? (kind === 'err' ? 'Fehlgeschlagen' : 'Erledigt'))}</div>`
        + `<div class="toast-body">${esc(body)}</div>`;
    $('toasts').appendChild(el);
    setTimeout(() => el.remove(), kind === 'err' ? 9000 : 2600);
}

// ---------------------------------------------------------------
// API
// ---------------------------------------------------------------

async function parseResponse(res) {
    const text = await res.text();
    let json;
    try {
        json = JSON.parse(text);
    } catch (e) {
        // Kein JSON: Server-Fehlerseite oder PHP-Fatal - Rohtext zeigen,
        // sonst steht hier nur "Unexpected end of JSON input".
        throw new Error(`Keine JSON-Antwort (HTTP ${res.status}): `
            + (text.trim().slice(0, 300) || '(leere Antwort)'));
    }
    if (!json.ok) throw new Error(json.error || 'Unbekannter Fehler');
    return json;
}

async function apiGet(action, params = {}) {
    const qs = new URLSearchParams({ action, processor: currentProcessor ?? '', ...params });
    return parseResponse(await fetch(`${API}?${qs}`));
}

async function apiPost(action, body) {
    const qs = new URLSearchParams({ action, processor: currentProcessor ?? '' });
    return parseResponse(await fetch(`${API}?${qs}`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(body),
    }));
}

// ---------------------------------------------------------------
// Laden
// ---------------------------------------------------------------

async function loadProcessors() {
    const res = await apiGet('processors');
    const aliases = res.data || [];
    const stored = localStorage.getItem(PROC_KEY);
    currentProcessor = (stored && aliases.includes(stored)) ? stored : (res.default || aliases[0]);

    $('processor-select').innerHTML = aliases
        .map((a) => `<option ${a === currentProcessor ? 'selected' : ''}>${esc(a)}</option>`)
        .join('');
}

async function loadNode() {
    const box = $('node-status');
    try {
        const res = await apiGet('node');
        const d = res.data.data || {};
        $('node-text').textContent = `${d.sn ?? '?'} · ${d.version ?? '?'}`;
        box.className = 'status is-ok';
    } catch (e) {
        $('node-text').textContent = 'nicht erreichbar';
        box.className = 'status is-down';
    }
}

async function loadScreens() {
    const res = await apiGet('screens');
    // Ohne Canvas-Größe taugt ein Screen nicht als Bühne (unkonfiguriert).
    screensData = (res.data || []).filter((s) => s.window && s.window.width && s.window.height);

    const sel = $('screen-select');
    if (screensData.length === 0) {
        sel.innerHTML = '<option>kein konfigurierter Screen</option>';
        currentScreenId = null;
        return;
    }
    const stored = Number(localStorage.getItem(SCREEN_KEY));
    const ids = screensData.map((s) => Number(s.screenId));
    currentScreenId = ids.includes(stored) ? stored : ids[0];

    sel.innerHTML = screensData.map((s) =>
        `<option value="${s.screenId}" ${Number(s.screenId) === currentScreenId ? 'selected' : ''}>`
        + `${esc(s.typeName)} · ${esc(s.name)}</option>`).join('');
}

async function loadInputs() {
    const res = await apiGet('inputs');
    inputsData = res.data || [];
}

async function loadPictures() {
    try {
        const res = await apiGet('pictures');
        picturesData = res.data || [];
    } catch (e) {
        picturesData = [];   // Bilder sind kein Muss
        toast(e.message, 'err', 'Bilder nicht geladen');
    }
}

async function loadLayers() {
    const res = await apiGet('layers');
    const screen = currentScreen();
    // BKG (type 16) ist kein normaler Layer: eigene Leiste, nicht löschbar.
    layersData = screen
        ? (res.data || []).filter((l) =>
            Number(l.attachScreenId) === Number(screen.screenId)
            && Number(l.sceneType) === Number(currentScene)
            && !l.isBkg)
        : [];
    $('layer-count').textContent = layersData.length;
    renderStage();
    renderLayers();
    renderCommands();
}

async function loadBkg() {
    const screen = currentScreen();
    if (!screen || !isPlainScreen()) { bkgState = null; return; }
    try {
        const res = await apiGet('bkg', { screenId: screen.screenId, sceneType: currentScene });
        bkgState = res.data;
    } catch (e) {
        bkgState = null;
        toast(e.message, 'err', 'Hintergrund');
    }
    renderBkg();
}

async function loadAll() {
    try {
        await loadNode();
        await loadScreens();
        applyScreenUi();
        await loadInputs();
        await loadPictures();
        renderTypeFilter();
        renderPalette();
        await loadLayers();
        await loadBkg();
    } catch (e) {
        toast(e.message);
    }
}

// ---------------------------------------------------------------
// Quellen
// ---------------------------------------------------------------

// Kürzt "Input 5-12G-SDI" auf "Input 5" - der physische Eingang, so wie er
// auf der Gerätefront steht. Der Anschlusstyp steht ohnehin als Badge daneben.
function shortInputName(name) {
    const m = String(name ?? '').match(/^\s*(Input\s*\d+)/i);
    return m ? m[1] : name;
}

function paletteItems() {
    const q = $('source-filter').value.trim().toLowerCase();
    const onlyActive = $('only-active').checked;

    // Kombi-Eingänge: nur der Port mit state=1 ist durchgeschaltet.
    let inputs = onlyActive ? inputsData.filter((i) => i.active !== 0) : inputsData;
    if (portFilter && portFilter !== 'BKG') inputs = inputs.filter((i) => i.connector === portFilter);
    if (q) inputs = inputs.filter((i) => `${i.name} ${i.sourceId}`.toLowerCase().includes(q));

    let pics = portFilter && portFilter !== 'BKG' ? [] : picturesData;
    if (q) pics = pics.filter((p) => `${p.name} ${p.sourceId}`.toLowerCase().includes(q));

    return { inputs, pics };
}

// Segmentierter Filter: ein Knopf je vorhandenem Anschluss, mit Zähler.
function renderTypeFilter() {
    const box = $('type-filter');
    const counts = {};
    inputsData.forEach((i) => { counts[i.connector] = (counts[i.connector] || 0) + 1; });
    if (picturesData.length) counts.BKG = picturesData.length;

    const order = ['HDMI', 'DP', 'SDI', 'OPT', 'BKG'];
    const present = order.filter((p) => counts[p]);
    // Unter zwei Typen lohnt kein Filter.
    if (present.length < 2) { box.innerHTML = ''; return; }

    box.innerHTML = present.map((p) =>
        `<button class="seg" data-port="${p}" aria-pressed="${portFilter === p}">`
        + `${p === 'BKG' ? 'Bilder' : p}<span class="seg-n">${counts[p]}</span></button>`).join('');

    box.querySelectorAll('.seg').forEach((b) => b.addEventListener('click', () => {
        // Zweiter Klick auf denselben Typ hebt den Filter wieder auf.
        portFilter = portFilter === b.dataset.port ? null : b.dataset.port;
        renderTypeFilter();
        renderPalette();
    }));
}

function renderPalette() {
    const box = $('palette');
    const { inputs, pics } = paletteItems();
    $('source-count').textContent = inputs.length + pics.length;
    box.innerHTML = '';

    if (inputs.length === 0 && pics.length === 0) {
        box.innerHTML = '<p class="hint">Keine Quelle passt zu Suche und Filter.</p>';
        return;
    }

    if (inputs.length) {
        box.insertAdjacentHTML('beforeend', '<div class="pal-group">Eingänge</div>');
        inputs.forEach((i) => box.appendChild(inputRow(i)));
    }

    if (pics.length) {
        box.insertAdjacentHTML('beforeend', '<div class="pal-group">Bilder im Gerät</div>');
        const grid = document.createElement('div');
        grid.className = 'pics';
        pics.forEach((p) => grid.appendChild(picTile(p)));
        box.appendChild(grid);
    }
}

function makeDraggable(el, payload) {
    el.draggable = true;
    el.addEventListener('dragstart', (ev) => {
        // sourceId allein reicht nicht - Inputs und Bilder haben getrennte
        // ID-Räume, erst sourceType macht die Quelle eindeutig.
        ev.dataTransfer.setData('text/plain', JSON.stringify(payload));
        ev.dataTransfer.effectAllowed = 'copy';
        el.classList.add('is-dragging');
    });
    el.addEventListener('dragend', () => el.classList.remove('is-dragging'));
    // Schnellweg: Doppelklick legt formatfüllend an
    el.addEventListener('dblclick', () => createFromPayload(payload, null, true));
}

// Eingang: eine kompakte Zeile. ID · Kurzname · Anschluss · Warnung.
function inputRow(i) {
    const el = document.createElement('div');
    el.className = 'src' + (i.active === 0 ? ' is-inactive' : '');
    el.dataset.port = i.connector ?? '';
    el.title = i.active === 0
        ? `${i.name} – nicht der aktive Port dieses Eingangs`
        : (i.online ? i.name : `${i.name} – kein Signal`);
    el.innerHTML = `
        <span class="src-id">${esc(i.sourceId)}</span>
        <span class="src-name">${esc(shortInputName(i.name))}</span>
        <span class="src-port">${esc(i.connector)}</span>
        ${i.online ? '' : '<span class="src-warn" title="kein Signal">∅</span>'}`;
    makeDraggable(el, { sourceId: i.sourceId, sourceType: SRC_INPUT });
    return el;
}

// Bild: Kachel mit Vorschau. Mehrere passen nebeneinander.
function picTile(p) {
    const el = document.createElement('div');
    el.className = 'pic';
    el.title = `${p.name} · ${p.width}×${p.height} · ID ${p.sourceId}`;
    el.innerHTML = `
        ${p.thumbUrl ? `<img class="pic-thumb" src="${esc(p.thumbUrl)}" alt="" loading="lazy" draggable="false">`
                     : '<div class="pic-thumb"></div>'}
        <span class="pic-name">${esc(p.name)}</span>
        <span class="pic-meta"><span>${esc(p.sourceId)}</span><span>${p.width}×${p.height}</span></span>`;
    makeDraggable(el, { sourceId: p.sourceId, sourceType: SRC_IMAGE, width: p.width, height: p.height });
    return el;
}

// ---------------------------------------------------------------
// Bühne
// ---------------------------------------------------------------

function applyScreenUi() {
    const screen = currentScreen();
    const plain = isPlainScreen();

    // AUX und MVR kennen keine PGM/PVW-Trennung und keinen BKG-Layer.
    $('scene-switch').hidden = !plain;
    $('bkg-bar').hidden = !plain;
    if (!plain) currentScene = SCENE_PGM;

    const name = SCENE_NAME[currentScene];
    document.querySelectorAll('.scene-key').forEach((b) =>
        b.setAttribute('aria-checked', String(Number(b.dataset.scene) === currentScene)));

    $('screen-type').textContent = screen?.typeName ?? '–';
    $('screen-type').dataset.type = screen?.typeName ?? '';
    $('screen-name').textContent = screen ? screen.name : 'kein Screen';

    const tally = $('tally');
    tally.dataset.scene = currentScene === SCENE_PGM ? 'pgm' : 'pvw';
    // Bei AUX gibt es kein Preview - "Live" wäre dort trotzdem richtig.
    $('tally-word').textContent = currentScene === SCENE_PGM ? 'Live' : 'Preview';
    $('cmd-scene').textContent = name;
}

function renderStage() {
    const canvas = $('canvas');
    const screen = currentScreen();
    canvas.querySelectorAll('.layer').forEach((e) => e.remove());

    if (!screen) {
        canvas.style.height = '240px';
        $('canvas-empty').textContent = 'Kein Screen mit Canvas-Größe vorhanden';
        $('canvas-empty').hidden = false;
        $('stage-meta').textContent = '';
        return;
    }

    const w = screen.window.width, h = screen.window.height;
    scale = canvas.clientWidth / w;
    canvas.style.height = `${Math.round(h * scale)}px`;
    $('stage-meta').textContent = `${w}×${h} px · ${(scale * 100).toFixed(1)} %`;

    $('canvas-empty').hidden = layersData.length > 0;
    $('canvas-empty').textContent = `Zieh eine Quelle hierher – Layer landet in ${SCENE_NAME[currentScene]}`;

    // Kleinste zorder zuerst: obenliegende Layer bleiben oben.
    [...layersData]
        .sort((a, b) => (a.zorder ?? 0) - (b.zorder ?? 0))
        .forEach((l) => canvas.appendChild(layerBox(l)));
}

const portOfLayer = (l) => {
    if (Number(l.sourceType) === SRC_IMAGE) return 'BKG';
    const inp = inputsData.find((i) => Number(i.sourceId) === Number(l.sourceId));
    return inp?.connector ?? '';
};

function layerBox(layer) {
    const w = layer.window || { x: 0, y: 0, width: 0, height: 0 };
    const el = document.createElement('div');
    el.className = 'layer' + (Number(selectedLayerId) === Number(layer.layerId) ? ' is-selected' : '');
    el.dataset.layerId = layer.layerId;
    el.dataset.port = portOfLayer(layer);
    Object.assign(el.style, {
        left: `${w.x * scale}px`,
        top: `${w.y * scale}px`,
        width: `${w.width * scale}px`,
        height: `${w.height * scale}px`,
        // Deckkraft sichtbar, aber nie ganz weg - sonst ist der Layer bei 0
        // nicht mehr greifbar.
        opacity: String(0.3 + 0.7 * ((layer.opacity ?? 100) / 100)),
    });
    el.innerHTML = `<div class="layer-tag">${esc(layer.sourceName ?? layer.name ?? layer.layerId)}</div>
        <div class="layer-dims">${w.width}×${w.height} · ${w.x},${w.y}</div>
        <div class="grip" title="Größe ändern"></div>`;

    el.addEventListener('pointerdown', (ev) => {
        selectLayer(layer.layerId);
        startDrag(ev, el, layer, ev.target.classList.contains('grip') ? 'resize' : 'move');
    });
    return el;
}

function selectLayer(id) {
    selectedLayerId = id;
    document.querySelectorAll('.layer').forEach((e) =>
        e.classList.toggle('is-selected', Number(e.dataset.layerId) === Number(id)));
    document.querySelectorAll('.lrow').forEach((r) =>
        r.classList.toggle('is-selected', Number(r.dataset.layerId) === Number(id)));
}

// --- Fangen: Kanten und Mitten von Canvas und Nachbarlayern ----------

function snapTargets(exceptId) {
    const s = currentScreen();
    const v = [0, Math.round(s.window.width / 2), s.window.width];
    const h = [0, Math.round(s.window.height / 2), s.window.height];
    layersData.forEach((l) => {
        if (Number(l.layerId) === Number(exceptId)) return;
        const w = l.window || {};
        v.push(w.x, w.x + Math.round(w.width / 2), w.x + w.width);
        h.push(w.y, w.y + Math.round(w.height / 2), w.y + w.height);
    });
    return { v, h };
}

function snap(value, targets, tol) {
    let best = null, dist = tol;
    targets.forEach((t) => {
        const d = Math.abs(value - t);
        if (d <= dist) { dist = d; best = t; }
    });
    return best;
}

function showGuides(vs, hs) {
    $('guides').innerHTML = vs.map((x) => `<div class="guide guide--v" style="left:${x * scale}px"></div>`)
        .concat(hs.map((y) => `<div class="guide guide--h" style="top:${y * scale}px"></div>`)).join('');
}

const clearGuides = () => ($('guides').innerHTML = '');

/**
 * Verschieben/Größe ändern. Gesendet wird erst beim Loslassen - sonst
 * löst jede Mausbewegung einen Request aus.
 */
function startDrag(ev, el, layer, mode) {
    ev.preventDefault();
    const screen = currentScreen();
    if (!screen) return;

    const orig = { ...(layer.window || {}) };
    const win = { ...orig };
    const startX = ev.clientX, startY = ev.clientY;
    const tol = SNAP_PX / scale;
    const targets = snapTargets(layer.layerId);

    el.classList.add('is-dragging');
    el.setPointerCapture(ev.pointerId);

    const onMove = (e) => {
        const dx = Math.round((e.clientX - startX) / scale);
        const dy = Math.round((e.clientY - startY) / scale);
        const gv = [], gh = [];

        if (mode === 'move') {
            win.x = clamp(orig.x + dx, 0, screen.window.width - win.width);
            win.y = clamp(orig.y + dy, 0, screen.window.height - win.height);
            if (!e.altKey) {   // Alt hält das Fangen an
                for (const edge of [win.x, win.x + win.width / 2, win.x + win.width]) {
                    const hit = snap(edge, targets.v, tol);
                    if (hit !== null) { win.x = clamp(win.x + (hit - edge), 0, screen.window.width - win.width); gv.push(hit); break; }
                }
                for (const edge of [win.y, win.y + win.height / 2, win.y + win.height]) {
                    const hit = snap(edge, targets.h, tol);
                    if (hit !== null) { win.y = clamp(win.y + (hit - edge), 0, screen.window.height - win.height); gh.push(hit); break; }
                }
            }
        } else {
            win.width = clamp(orig.width + dx, 16, screen.window.width - win.x);
            win.height = clamp(orig.height + dy, 16, screen.window.height - win.y);
            if (!e.altKey) {
                const hx = snap(win.x + win.width, targets.v, tol);
                if (hx !== null) { win.width = clamp(hx - win.x, 16, screen.window.width - win.x); gv.push(hx); }
                const hy = snap(win.y + win.height, targets.h, tol);
                if (hy !== null) { win.height = clamp(hy - win.y, 16, screen.window.height - win.y); gh.push(hy); }
            }
        }
        paint(el, win);
        showGuides(gv, gh);
    };

    const onUp = async () => {
        el.removeEventListener('pointermove', onMove);
        el.removeEventListener('pointerup', onUp);
        el.classList.remove('is-dragging');
        clearGuides();
        if (['x', 'y', 'width', 'height'].every((k) => win[k] === orig[k])) return;
        await pushWindow(layer.layerId, win, el);
    };

    el.addEventListener('pointermove', onMove);
    el.addEventListener('pointerup', onUp);
}

function paint(el, w) {
    el.style.left = `${w.x * scale}px`;
    el.style.top = `${w.y * scale}px`;
    el.style.width = `${w.width * scale}px`;
    el.style.height = `${w.height * scale}px`;
    el.querySelector('.layer-dims').textContent = `${w.width}×${w.height} · ${w.x},${w.y}`;
}

async function pushWindow(layerId, win, el = null) {
    el?.classList.add('is-busy');
    try {
        await apiPost('layer_window', { layerId, x: win.x, y: win.y, width: win.width, height: win.height });
        await loadLayers();
    } catch (e) {
        toast(`Layer ${layerId}: ${e.message}`);
        await loadLayers();   // Anzeige auf Gerätestand zurückholen
    }
}

// --- Ablegen ---------------------------------------------------------

async function createFromPayload(payload, at, fill = false) {
    const screen = currentScreen();
    if (!screen) { toast('Kein Screen gewählt.'); return; }
    const sw = screen.window.width, sh = screen.window.height;
    let width, height;

    if (fill) {
        width = sw; height = sh;
    } else if (payload.sourceType === SRC_IMAGE && payload.width && payload.height) {
        // Bilder in nativer Auflösung ablegen (so macht es PixelFlow auch),
        // heruntergerechnet, falls größer als der Screen.
        const fit = Math.min(1, sw / payload.width, sh / payload.height);
        width = Math.round(payload.width * fit);
        height = Math.round(payload.height * fit);
    } else {
        width = Math.round(sw * DROP_SIZE_FACTOR);
        height = Math.round(sh * DROP_SIZE_FACTOR);
    }

    const x = at ? clamp(at.x - width / 2, 0, sw - width) : Math.round((sw - width) / 2);
    const y = at ? clamp(at.y - height / 2, 0, sh - height) : Math.round((sh - height) / 2);

    try {
        await apiPost('layer_create', {
            screenId: screen.screenId,
            sourceId: payload.sourceId,
            sourceType: payload.sourceType,
            x, y, width, height,
            sceneType: currentScene,
        });
        await loadLayers();
    } catch (e) {
        toast(`Quelle ${payload.sourceId} (Typ ${payload.sourceType}): ${e.message}`, 'err', 'Layer anlegen');
    }
}

function wireDropzone() {
    const canvas = $('canvas');
    canvas.addEventListener('dragover', (ev) => {
        ev.preventDefault();
        ev.dataTransfer.dropEffect = 'copy';
        canvas.classList.add('is-over');
    });
    canvas.addEventListener('dragleave', () => canvas.classList.remove('is-over'));
    canvas.addEventListener('drop', (ev) => {
        ev.preventDefault();
        canvas.classList.remove('is-over');
        let payload;
        try { payload = JSON.parse(ev.dataTransfer.getData('text/plain')); } catch (e) { return; }
        const r = canvas.getBoundingClientRect();
        createFromPayload(payload, { x: (ev.clientX - r.left) / scale, y: (ev.clientY - r.top) / scale });
    });
}

// ---------------------------------------------------------------
// Layer-Liste
// ---------------------------------------------------------------

function renderLayers() {
    const box = $('layers');
    box.innerHTML = '';
    if (layersData.length === 0) {
        box.innerHTML = `<p class="hint">${isPlainScreen()
            ? `${SCENE_NAME[currentScene]} ist leer. Zieh eine Quelle auf den Screen.`
            : 'Dieser Weg ist leer. Zieh eine Quelle auf den Screen.'}</p>`;
        return;
    }
    layersData.forEach((l) => box.appendChild(layerRow(l)));
}

function layerRow(l) {
    const w = l.window || { x: 0, y: 0, width: 0, height: 0 };
    const row = document.createElement('div');
    row.className = 'lrow' + (Number(selectedLayerId) === Number(l.layerId) ? ' is-selected' : '');
    row.dataset.layerId = l.layerId;
    row.dataset.port = portOfLayer(l);

    row.innerHTML = `
        <div class="lrow-head">
            <span class="lrow-name">${esc(l.sourceName ?? l.name ?? '–')}</span>
            <span class="lrow-id">#${esc(l.layerId)}</span>
            <span class="presets">
                <button class="icon-btn" data-fit="full" title="Screen füllen">Voll</button>
                <button class="icon-btn" data-fit="center" title="Mittig setzen">Mitte</button>
            </span>
            <button class="icon-btn icon-btn--danger" data-del title="Layer löschen">Löschen</button>
        </div>
        <div class="lrow-fields">
            <label class="fld"><span class="fld-label">X</span><input type="number" data-f="x" value="${w.x}"></label>
            <label class="fld"><span class="fld-label">Y</span><input type="number" data-f="y" value="${w.y}"></label>
            <label class="fld"><span class="fld-label">Breite</span><input type="number" data-f="width" value="${w.width}"></label>
            <label class="fld"><span class="fld-label">Höhe</span><input type="number" data-f="height" value="${w.height}"></label>
        </div>
        <div class="lrow-opacity">
            <span class="fld-label">Deckkraft</span>
            <input type="range" min="0" max="100" value="${l.opacity ?? 100}">
            <span class="op-val">${l.opacity ?? 100} %</span>
        </div>`;

    row.querySelector('.lrow-head').addEventListener('click', () => selectLayer(l.layerId));

    const read = () => {
        const o = {};
        row.querySelectorAll('[data-f]').forEach((i) => (o[i.dataset.f] = Number(i.value)));
        return o;
    };
    row.querySelectorAll('[data-f]').forEach((i) =>
        i.addEventListener('change', () => pushWindow(l.layerId, read())));

    row.querySelectorAll('[data-fit]').forEach((b) => b.addEventListener('click', (ev) => {
        ev.stopPropagation();
        const s = currentScreen();
        if (!s) return;
        const cur = read();
        pushWindow(l.layerId, b.dataset.fit === 'full'
            ? { x: 0, y: 0, width: s.window.width, height: s.window.height }
            : { ...cur, x: Math.round((s.window.width - cur.width) / 2),
                        y: Math.round((s.window.height - cur.height) / 2) });
    }));

    const rng = row.querySelector('input[type="range"]');
    const val = row.querySelector('.op-val');
    rng.addEventListener('input', () => (val.textContent = `${rng.value} %`));
    rng.addEventListener('change', async () => {
        try {
            await apiPost('layer_opacity', { layerId: l.layerId, opacity: Number(rng.value) });
            await loadLayers();
        } catch (e) { toast(`Layer ${l.layerId}: ${e.message}`); }
    });

    row.querySelector('[data-del]').addEventListener('click', (ev) => {
        ev.stopPropagation();
        deleteLayer(l.layerId, l.sourceName);
    });
    return row;
}

async function deleteLayer(layerId, name = '') {
    try {
        await apiPost('layer_delete', { layerId });
        if (Number(selectedLayerId) === Number(layerId)) selectedLayerId = null;
        await loadLayers();
        toast(`${name || 'Layer'} entfernt`, 'ok');
    } catch (e) {
        toast(`Layer ${layerId}: ${e.message}`);
    }
}

// ---------------------------------------------------------------
// Hintergrund
// ---------------------------------------------------------------

function renderBkg() {
    const bar = $('bkg-bar');
    const sel = $('bkg-source');
    sel.innerHTML = '<option value="">– kein Bild –</option>'
        + picturesData.map((p) =>
            `<option value="${p.sourceId}">${esc(p.name)} (${p.width}×${p.height})</option>`).join('');

    if (!bkgState) {
        bar.classList.add('is-off');
        $('bkg-enable').checked = false;
        $('bkg-meta').textContent = isPlainScreen() ? 'kein BKG-Layer gefunden' : '';
        return;
    }
    $('bkg-enable').checked = !!bkgState.enable;
    bar.classList.toggle('is-off', !bkgState.enable);
    if (bkgState.sourceId) sel.value = String(bkgState.sourceId);
    const w = bkgState.window || {};
    $('bkg-meta').textContent = w.width ? `${w.width}×${w.height} · ${w.x},${w.y}` : '';
}

async function bkgApply(patch) {
    const screen = currentScreen();
    if (!screen) return;
    try {
        await apiPost('bkg_set', { screenId: screen.screenId, sceneType: currentScene, ...patch });
        await loadLayers();
        await loadBkg();
    } catch (e) {
        toast(e.message, 'err', 'Hintergrund');
        await loadBkg();
    }
}

// ---------------------------------------------------------------
// Wrapper-Befehle
// ---------------------------------------------------------------

function wrapperUrl(params) {
    const dir = location.pathname.replace(/[^/]*$/, '');
    const usp = new URLSearchParams();
    Object.entries(params).forEach(([k, v]) => {
        if (v !== undefined && v !== null && v !== '') usp.append(k, v);
    });
    return `${location.origin}${dir}wrapper.php?${usp}`;
}

/**
 * Befehlsfolge, die die aktuelle Szene reproduziert: erst leeren, dann je
 * Layer ein create_layer. Sortiert nach zorder, damit die Stapelung beim
 * Nachbauen wieder stimmt (sie ergibt sich aus der Anlagereihenfolge).
 */
function buildCommands() {
    const screen = currentScreen();
    if (!screen) return [];
    const scene = SCENE_NAME[currentScene].toLowerCase();
    const proc = currentProcessor ?? '';
    const plain = isPlainScreen();

    const cmds = [{
        step: `Alle Layer in ${plain ? SCENE_NAME[currentScene] : esc(screen.name)} löschen`,
        cls: 'cmd--clear',
        url: wrapperUrl({ action: 'clear_screen', screenId: screen.screenId,
                          scene: plain ? scene : '', processor: proc }),
    }];

    [...layersData].sort((a, b) => (a.zorder ?? 0) - (b.zorder ?? 0)).forEach((l) => {
        const w = l.window || {};
        cmds.push({
            step: `${l.sourceName ?? 'Layer'} anlegen`,
            cls: '',
            url: wrapperUrl({
                action: 'create_layer',
                screenId: screen.screenId,
                scene: plain ? scene : '',
                sourceId: l.sourceId ?? '',
                // Ohne sourceType nimmt der Wrapper 2 (Input) an - Bilder
                // brauchen die 5 zwingend.
                sourceType: l.sourceType ?? SRC_INPUT,
                x: w.x, y: w.y, width: w.width, height: w.height,
                // create_layer zieht die Deckkraft selbst nach - kein
                // zweiter Befehl nötig.
                opacity: l.opacity ?? 100,
                processor: proc,
            }),
        });
    });
    return cmds;
}

function renderCommands() {
    const box = $('cmd-list');
    const cmds = buildCommands();
    if (cmds.length <= 1) {
        box.innerHTML = '<p class="hint">Keine Layer – nichts nachzubauen.</p>';
        return;
    }
    box.innerHTML = cmds.map((c, i) =>
        `<div class="cmd ${c.cls}"><span class="cmd-step">${i + 1}. ${esc(c.step)}</span>${esc(c.url)}</div>`
    ).join('');
}

// ---------------------------------------------------------------
// Tastatur
// ---------------------------------------------------------------

const inField = (t) => /^(INPUT|SELECT|TEXTAREA)$/.test(t.tagName);

document.addEventListener('keydown', (ev) => {
    if (inField(ev.target)) return;

    if (ev.key === 'Escape') { selectLayer(null); return; }
    if (ev.key.toLowerCase() === 'r') { loadAll(); return; }
    if (ev.key.toLowerCase() === 'p' && isPlainScreen()) { switchScene(SCENE_PGM); return; }
    if (ev.key.toLowerCase() === 'v' && isPlainScreen()) { switchScene(SCENE_PVW); return; }

    const layer = layersData.find((l) => Number(l.layerId) === Number(selectedLayerId));
    if (!layer) return;

    if (ev.key === 'Delete' || ev.key === 'Backspace') {
        ev.preventDefault();
        deleteLayer(layer.layerId, layer.sourceName);
        return;
    }

    const step = ev.shiftKey ? NUDGE_BIG : NUDGE;
    const d = { ArrowLeft: [-step, 0], ArrowRight: [step, 0], ArrowUp: [0, -step], ArrowDown: [0, step] }[ev.key];
    if (!d) return;
    ev.preventDefault();

    const s = currentScreen();
    const w = layer.window;
    pushWindow(layer.layerId, {
        ...w,
        x: clamp(w.x + d[0], 0, s.window.width - w.width),
        y: clamp(w.y + d[1], 0, s.window.height - w.height),
    });
});

// ---------------------------------------------------------------
// Bedienelemente
// ---------------------------------------------------------------

function switchScene(scene) {
    currentScene = scene;
    localStorage.setItem(SCENE_KEY, String(scene));
    selectedLayerId = null;
    applyScreenUi();
    loadLayers().then(loadBkg);
}

document.querySelectorAll('.scene-key').forEach((b) =>
    b.addEventListener('click', () => switchScene(Number(b.dataset.scene))));

$('processor-select').addEventListener('change', (e) => {
    currentProcessor = e.target.value;
    localStorage.setItem(PROC_KEY, currentProcessor);
    selectedLayerId = null;
    portFilter = null;   // anderes Gerät, andere Anschlüsse
    loadAll();
});

$('screen-select').addEventListener('change', (e) => {
    currentScreenId = Number(e.target.value);
    localStorage.setItem(SCREEN_KEY, String(currentScreenId));
    selectedLayerId = null;
    // Titel, Szenenwahl und BKG hängen alle am Screen - alles mitziehen.
    applyScreenUi();
    renderPalette();
    loadLayers().then(loadBkg);
});

$('source-filter').addEventListener('input', renderPalette);
$('only-active').addEventListener('change', renderPalette);
$('refresh-btn').addEventListener('click', loadAll);

$('bkg-enable').addEventListener('change', (e) => bkgApply({ enable: e.target.checked ? 1 : 0 }));
$('bkg-source').addEventListener('change', (e) => {
    if (!e.target.value) return;
    // Bild zuweisen und dabei einschalten - Reihenfolge wie in PixelFlow.
    bkgApply({ sourceId: Number(e.target.value), sourceType: SRC_IMAGE, enable: 1 });
});

$('take-btn').addEventListener('click', async () => {
    const screen = currentScreen();
    if (!screen) { toast('Kein Screen gewählt.'); return; }
    const btn = $('take-btn');
    btn.disabled = true;
    try {
        await apiPost('screen_take', { screenId: screen.screenId });
        btn.classList.add('is-done');
        setTimeout(() => btn.classList.remove('is-done'), 900);
        await loadLayers();   // PGM hat sich geändert
    } catch (e) {
        toast(e.message, 'err', 'Take');
    } finally {
        btn.disabled = false;
    }
});

$('cmd-copy').addEventListener('click', async (ev) => {
    ev.preventDefault();
    const text = buildCommands().map((c) => c.url).join('\n');
    try {
        await navigator.clipboard.writeText(text);
    } catch (e) {
        // Clipboard-API gibt es nur bei HTTPS oder localhost - dieser Server
        // läuft über http://, deshalb der Umweg.
        const ta = document.createElement('textarea');
        ta.value = text;
        ta.style.cssText = 'position:fixed;opacity:0';
        document.body.appendChild(ta);
        ta.select();
        document.execCommand('copy');
        ta.remove();
    }
    toast(`${buildCommands().length} Befehle kopiert`, 'ok', 'Zwischenablage');
});

let resizeTimer;
window.addEventListener('resize', () => {
    clearTimeout(resizeTimer);
    resizeTimer = setTimeout(renderStage, 150);
});

(async function init() {
    const stored = Number(localStorage.getItem(SCENE_KEY));
    if (stored === SCENE_PGM || stored === SCENE_PVW) currentScene = stored;
    applyScreenUi();
    wireDropzone();
    try {
        await loadProcessors();
    } catch (e) {
        toast(e.message, 'err', 'Prozessorliste');
    }
    await loadAll();
})();
