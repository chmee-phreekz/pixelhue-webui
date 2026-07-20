const API = 'api.php';
const WRAPPER = 'wrapper.php';
const STORAGE_KEY = 'pixelhue-selected-processor';

// --- Schnellaktionen ---
// sourceIds sind GERÄTEABHÄNGIG. Die gültigen Werte für dein Gerät stehen
// in der Inputs-Tabelle oben (Spalte sourceId).
// Beispiel P10: 10 = Input 1-HDMI 2.0, 20 = Input 5-12G-SDI
// Beispiel P20: 2  = Input 1-HDMI 2.0, 30 = Input 9-12G-SDI
const QA_SCREEN_ID = 1;
const QA_SOURCE_A  = 10;
const QA_SOURCE_B  = 20;

// Zuletzt geladene Daten - die Schnellaktionen leiten daraus Größe und
// Layer-ID ab, statt feste Werte zu verwenden.
let screensData = [];
let layersData = [];
let inputsData = [];

const layerBody = document.getElementById('layer-body');
const layerCountEl = document.getElementById('layer-count');
const screenBody = document.getElementById('screen-body');
const screenCountEl = document.getElementById('screen-count');
const errorBanner = document.getElementById('error-banner');
const nodeStatus = document.getElementById('node-status');
const refreshBtn = document.getElementById('refresh-btn');
const processorSelect = document.getElementById('processor-select');

let currentProcessor = null;

function showError(msg) {
    errorBanner.textContent = msg;
    errorBanner.hidden = false;
}

function clearError() {
    errorBanner.hidden = true;
    errorBanner.textContent = '';
}

function badge(on) {
    return on
        ? '<span class="badge-on">● an</span>'
        : '<span class="badge-off">○ aus</span>';
}

// Antwort robust auswerten: Kommt kein JSON zurück (z.B. eine
// Server-Fehlerseite oder ein PHP-Fatal), wird der Rohtext angezeigt
// statt eines nichtssagenden "Unexpected end of JSON input".
async function parseResponse(res) {
    const text = await res.text();
    let json;
    try {
        json = JSON.parse(text);
    } catch (e) {
        const snippet = text.trim().slice(0, 300) || '(leere Antwort)';
        throw new Error(`Keine JSON-Antwort (HTTP ${res.status}): ${snippet}`);
    }
    if (!json.ok) throw new Error(json.error || 'Unbekannter Fehler');
    return json;
}

async function apiGet(action, params = {}) {
    const qs = new URLSearchParams({ action, processor: currentProcessor ?? '', ...params }).toString();
    const res = await fetch(`${API}?${qs}`);
    return parseResponse(res);
}

async function apiPost(action, body) {
    const qs = new URLSearchParams({ action, processor: currentProcessor ?? '' }).toString();
    const res = await fetch(`${API}?${qs}`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(body),
    });
    return parseResponse(res);
}

async function loadProcessors() {
    try {
        const res = await apiGet('processors');
        const aliases = res.data || [];
        const stored = localStorage.getItem(STORAGE_KEY);
        // Initial ist immer der erste der Liste ausgewählt, außer der
        // Nutzer hat in dieser Browser-Session bereits einen anderen gewählt.
        currentProcessor = (stored && aliases.includes(stored)) ? stored : (res.default || aliases[0] || null);

        processorSelect.innerHTML = '';
        aliases.forEach((alias) => {
            const opt = document.createElement('option');
            opt.value = alias;
            opt.textContent = alias;
            if (alias === currentProcessor) opt.selected = true;
            processorSelect.appendChild(opt);
        });
    } catch (e) {
        showError('Prozessorliste konnte nicht geladen werden: ' + e.message);
    }
}

processorSelect.addEventListener('change', () => {
    currentProcessor = processorSelect.value;
    localStorage.setItem(STORAGE_KEY, currentProcessor);
    loadAll();
});

async function loadNodeStatus() {
    try {
        const res = await apiGet('node');
        const d = res.data.data || {};
        nodeStatus.textContent = `Verbunden – SN ${d.sn ?? '?'} · Firmware ${d.version ?? '?'}`;
    } catch (e) {
        nodeStatus.textContent = 'Node-Info nicht verfügbar';
    }
}

// Einzelwert aus dem Screen-Canvas (mosaic.window): width bzw. height.
function formatWindowDim(win, key) {
    if (!win || win[key] === null || win[key] === undefined) return '<span class="ro">–</span>';
    return `<span class="res">${win[key]}</span>`;
}

// Outputs eines Screens: Online-Punkt, Name und eigene Auflösung (pos.width/height).
function formatOutputs(outputs) {
    if (!outputs || outputs.length === 0) return '<span class="ro">–</span>';
    return outputs.map((o) => {
        const dot = o.interfaceOnline
            ? '<span class="badge-on">●</span>'
            : '<span class="badge-off">○</span>';
        const res = (o.width || o.height)
            ? `<span class="res">${o.width}×${o.height}</span>`
            : '';
        return `<div class="output-row">${dot} <span class="ro-name">${o.name ?? '?'}</span> ${res}</div>`;
    }).join('');
}

async function loadScreens() {
    screenBody.innerHTML = '<tr><td colspan="8">Lade Screens…</td></tr>';
    try {
        const res = await apiGet('screens');
        screensData = res.data || [];
        screenCountEl.textContent = res.count;
        screenBody.innerHTML = '';
        if (res.count === 0) {
            screenBody.innerHTML = '<tr><td colspan="8">Keine Screens gefunden</td></tr>';
            return;
        }
        res.data.forEach((s) => {
            const tr = document.createElement('tr');
            tr.innerHTML = `
                <td class="ro">${s.screenId ?? '-'}</td>
                <td class="ro-name">${s.name ?? ''}</td>
                <td><span class="stype stype-${s.typeName}">${s.typeName ?? s.type}</span></td>
                <td>${formatWindowDim(s.window, 'width')}</td>
                <td>${formatWindowDim(s.window, 'height')}</td>
                <td>${formatOutputs(s.outputs)}</td>
                <td>${badge(!!s.enable)}</td>
                <td class="ro">${s.order ?? '-'}</td>
            `;
            screenBody.appendChild(tr);
        });
    } catch (e) {
        screenBody.innerHTML = '<tr><td colspan="8">Fehler beim Laden</td></tr>';
        showError(e.message);
    }
}

function renderRow(layer) {
    const tr = document.createElement('tr');
    tr.dataset.layerId = layer.layerId;

    const win = layer.window || { x: 0, y: 0, width: 0, height: 0 };
    const lockIcon = layer.locked ? '<span class="lock-icon">🔒</span>' : '<span class="lock-icon">🔓</span>';

    // sceneType 2 = PGM (Live), 4 = PVW (Preview)
    const scene = layer.scene
        ? `<span class="scene-badge scene-${layer.scene}">${layer.scene}</span>`
        : '<span class="ro">?</span>';

    tr.innerHTML = `
        <td class="ro">${layer.layerId}</td>
        <td>${scene}</td>
        <td class="ro-name">${layer.name ?? ''}</td>
        <td class="ro">${layer.sourceName ?? '-'}</td>
        <td>${lockIcon}</td>
        <td><input type="number" class="win-x" value="${win.x}"></td>
        <td><input type="number" class="win-y" value="${win.y}"></td>
        <td><input type="number" class="win-w" value="${win.width}"></td>
        <td><input type="number" class="win-h" value="${win.height}"></td>
        <td><input type="number" class="opacity" min="0" max="100" value="${layer.opacity ?? 100}"></td>
        <td class="ro">${layer.zorder ?? '-'}</td>
        <td><button class="apply-btn">Übernehmen</button></td>
        <td><button class="delete-btn danger-btn">Löschen</button></td>
    `;

    // Layer löschen (= "Ausschalten", da es kein Enable-Attribut gibt)
    tr.querySelector('.delete-btn').addEventListener('click', async () => {
        const ok = confirm(`Layer "${layer.name ?? layer.layerId}" (ID ${layer.layerId}) wirklich löschen? Das kann nicht rückgängig gemacht werden.`);
        if (!ok) return;
        try {
            await apiPost('layer_delete', { layerId: layer.layerId });
            clearError();
            tr.remove();
        } catch (err) {
            showError(`Layer ${layer.layerId}: ${err.message}`);
        }
    });

    // Position/Größe/Transparenz über "Übernehmen"
    tr.querySelector('.apply-btn').addEventListener('click', async () => {
        const x = parseInt(tr.querySelector('.win-x').value, 10);
        const y = parseInt(tr.querySelector('.win-y').value, 10);
        const w = parseInt(tr.querySelector('.win-w').value, 10);
        const h = parseInt(tr.querySelector('.win-h').value, 10);
        const opacity = parseInt(tr.querySelector('.opacity').value, 10);

        try {
            await apiPost('layer_window', { layerId: layer.layerId, x, y, width: w, height: h });
            await apiPost('layer_opacity', { layerId: layer.layerId, opacity });
            clearError();
        } catch (err) {
            showError(`Layer ${layer.layerId}: ${err.message}`);
        }
    });

    return tr;
}

async function loadLayers() {
    layerBody.innerHTML = '<tr><td colspan="13">Lade Layer…</td></tr>';
    try {
        const res = await apiGet('layers');
        clearError();
        layersData = res.data || [];
        layerCountEl.textContent = res.count;
        layerBody.innerHTML = '';
        if (res.count === 0) {
            layerBody.innerHTML = '<tr><td colspan="13">Keine Layer vorhanden</td></tr>';
            return;
        }
        res.data.forEach((layer) => layerBody.appendChild(renderRow(layer)));
    } catch (e) {
        layerBody.innerHTML = '<tr><td colspan="13">Fehler beim Laden</td></tr>';
        showError(e.message);
    }
}

refreshBtn.addEventListener('click', () => loadAll());

async function loadInputs() {
    const body = document.getElementById('input-body');
    body.innerHTML = '<tr><td colspan="6">Lade Inputs…</td></tr>';
    try {
        const res = await apiGet('inputs');
        inputsData = res.data || [];
        document.getElementById('input-count').textContent = res.count;
        body.innerHTML = '';
        if (res.count === 0) {
            body.innerHTML = '<tr><td colspan="6">Keine Inputs gefunden</td></tr>';
            return;
        }
        res.data.forEach((i) => {
            const tr = document.createElement('tr');
            // Inaktive Ports eines Kombi-Eingangs ausgrauen
            if (i.active === 0) tr.className = 'is-inactive';
            tr.innerHTML = `
                <td><span class="res">${i.sourceId}</span></td>
                <td class="ro-name">${i.name ?? ''}</td>
                <td class="ro">${i.group ?? '-'}</td>
                <td><span class="port-badge port-${i.connector}">${i.connector ?? '?'}</span></td>
                <td>${i.active ? '<span class="badge-on">● aktiv</span>' : '<span class="badge-off">○</span>'}</td>
                <td>${badge(!!i.online)}</td>
            `;
            body.appendChild(tr);
        });
        updateQuickLabels();
    } catch (e) {
        body.innerHTML = '<tr><td colspan="6">Fehler beim Laden</td></tr>';
        showError(e.message);
    }
}

// Buttonbeschriftung um den echten Interface-Namen ergänzen
function updateQuickLabels() {
    const label = (id) => {
        const i = inputsData.find((x) => Number(x.sourceId) === Number(id));
        return i ? `${id} · ${i.name}` : `${id} · (nicht in Inputs-Liste!)`;
    };
    document.getElementById('qa-in1').textContent = `2 · ${label(QA_SOURCE_A)} in Screengröße`;
    document.getElementById('qa-in9').textContent = `3 · ${label(QA_SOURCE_B)} in Screengröße`;
}

// ---------------------------------------------------------------
// Schnellaktionen (rufen wrapper.php auf, wie ein externes Tool)
// ---------------------------------------------------------------

async function wrapperCall(params) {
    const qs = new URLSearchParams({ processor: currentProcessor ?? '', ...params }).toString();
    const url = `${WRAPPER}?${qs}`;
    const res = await fetch(url);
    const text = await res.text();
    let json;
    try {
        json = JSON.parse(text);
    } catch (e) {
        const snippet = text.trim().slice(0, 300) || '(leere Antwort)';
        throw new Error(`Keine JSON-Antwort (HTTP ${res.status}): ${snippet}`);
    }
    if (!json.ok) throw new Error(json.error || 'Unbekannter Fehler');
    return { json, url };
}

function qaShow(text, state) {
    const el = document.getElementById('qa-result');
    el.hidden = false;
    el.className = 'qa-result' + (state ? ' is-' + state : '');
    el.textContent = text;
}

async function runQuick(label, fn) {
    document.querySelectorAll('.actions button').forEach((b) => (b.disabled = true));
    try {
        qaShow(`${label} …`, 'busy');
        const info = await fn();
        qaShow(`${label} – OK\n${info ?? ''}`.trim(), null);
        await loadAll();
    } catch (e) {
        qaShow(`${label} – Fehler:\n${e.message}`, 'error');
    } finally {
        document.querySelectorAll('.actions button').forEach((b) => (b.disabled = false));
    }
}

// Canvas-Größe eines Screens aus den geladenen Screen-Daten holen
function screenSize(screenId) {
    const s = screensData.find((x) => Number(x.screenId) === Number(screenId));
    if (!s) throw new Error(`Screen ${screenId} nicht gefunden. Geladene Screens: `
        + (screensData.map((x) => x.screenId).join(', ') || 'keine'));
    const w = s.window || {};
    if (!w.width || !w.height) {
        throw new Error(`Screen ${screenId} ("${s.name}") hat keine Canvas-Größe `
            + `(mosaic.window = ${w.width ?? '?'}×${w.height ?? '?'}). Ist der Screen konfiguriert?`);
    }
    return w;
}

async function qaCreateFullscreen(sourceId) {
    const win = screenSize(QA_SCREEN_ID);
    const known = inputsData.find((x) => Number(x.sourceId) === Number(sourceId));
    if (!known) {
        throw new Error(`sourceId ${sourceId} ist auf diesem Gerät kein Input. `
            + `Verfügbar: ${inputsData.map((x) => x.sourceId).join(', ') || 'keine'} `
            + `(Konstanten QA_SOURCE_A / QA_SOURCE_B in app.js anpassen)`);
    }
    const { url } = await wrapperCall({
        action: 'create_layer',
        screenId: QA_SCREEN_ID,
        sourceId,
        x: 0,
        y: 0,
        width: win.width,
        height: win.height,
    });
    return `${known.name} (sourceId ${sourceId}) → ${win.width}×${win.height} `
        + `auf Screen ${QA_SCREEN_ID}\n${url}`;
}

function wireQuickActions() {
    document.getElementById('qa-clear').addEventListener('click', () => {
        if (!confirm(`Wirklich ALLE Layer auf Screen ${QA_SCREEN_ID} löschen?`)) return;
        runQuick(`Alle Layer auf Screen ${QA_SCREEN_ID} löschen`, async () => {
            const { json, url } = await wrapperCall({ action: 'clear_screen', screenId: QA_SCREEN_ID });
            const d = json.data || {};
            const del = (d.deleted || []).length;
            const fail = (d.failed || []).length;
            return `gelöscht: ${del}${fail ? `, fehlgeschlagen: ${fail}` : ''}\n${url}`;
        });
    });

    document.getElementById('qa-in1').addEventListener('click', () =>
        runQuick(`sourceId ${QA_SOURCE_A} in Screengröße anlegen`, () => qaCreateFullscreen(QA_SOURCE_A)));

    document.getElementById('qa-in9').addEventListener('click', () =>
        runQuick(`sourceId ${QA_SOURCE_B} in Screengröße anlegen`, () => qaCreateFullscreen(QA_SOURCE_B)));

    document.getElementById('qa-op50').addEventListener('click', () =>
        runQuick('Deckkraft des ersten Layers auf 50 setzen', async () => {
            if (layersData.length === 0) throw new Error('Es ist kein Layer vorhanden.');
            const layer = layersData[0];
            const { url } = await wrapperCall({
                action: 'set_opacity',
                layerId: layer.layerId,
                opacity: 50,
            });
            return `Layer ${layer.layerId} ("${layer.name}") → Deckkraft 50\n${url}`;
        }));
}

async function loadAll() {
    await loadNodeStatus();
    await loadInputs();
    await loadScreens();
    await loadLayers();
}

(async function init() {
    wireQuickActions();
    await loadProcessors();
    await loadAll();
})();
