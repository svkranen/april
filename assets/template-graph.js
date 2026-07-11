import './styles/template-graph.css';

const processTarget = document.querySelector('[data-template-graph-target]');
const legacyTarget = document.querySelector('[data-mermaid-graph-target="preview"]');
const target = processTarget || legacyTarget;
const source = document.querySelector('[data-mermaid-graph-source]');
const modelSource = document.querySelector('[data-process-graph-model]');
const status = document.querySelector('[data-template-graph-status]');

const setStatus = (message) => {
    if (status) status.textContent = message;
};

const renderMermaid = async (fallback = false) => {
    const code = (source?.textContent || '').trim();
    if (!target || code === '') return;

    const { default: mermaid } = await import('mermaid');
    mermaid.initialize({ startOnLoad: false, securityLevel: 'strict' });
    const { svg } = await mermaid.render('template-graph-svg', code);
    target.innerHTML = svg;
    if (fallback) {
        setStatus('Process Graph ist nicht verfügbar. Die Mermaid-Fallbackdarstellung wird angezeigt.');
    }
};

const navigationUrl = (element) => {
    const raw = element?.data?.navigation?.url;
    if (typeof raw !== 'string' || raw.trim() === '') return null;
    const url = new URL(raw, window.location.origin);

    return url.origin === window.location.origin ? url.href : null;
};

// Layout direction from the server-rendered toggle; only LR/TB are valid,
// anything else falls back to the vertical default.
const layoutDirection = (raw) => (['LR', 'TB'].includes(raw) ? raw : 'TB');

// Camera strategy from the server-rendered toggle; the engine decides the
// concrete framing for 'auto'. Invalid values fall back to 'auto'.
const cameraMode = (raw) => (['auto', 'natural', 'comfortable', 'overview'].includes(raw) ? raw : 'auto');

const renderProcessGraph = async () => {
    if (!processTarget || !modelSource) return false;
    const moduleUrl = processTarget.dataset.processGraphModuleUrl;
    if (!moduleUrl) throw new Error('Process Graph module URL is not configured.');

    const model = JSON.parse(modelSource.textContent || '{}');
    const engine = await import(moduleUrl);
    if (typeof engine.renderProcessGraph !== 'function') {
        throw new Error('Process Graph module does not export renderProcessGraph().');
    }
    engine.renderProcessGraph(processTarget, model, {
        layout: {
            direction: layoutDirection(processTarget.dataset.processGraphDirection),
            preset: 'balanced',
        },
        // The camera strategy frames the initial view like a photographer:
        // readability first, full overview only when structure demands it.
        viewport: {
            cameraMode: cameraMode(processTarget.dataset.processGraphCamera),
            fitPadding: 24,
            minInitialScale: 0.35,
            maxInitialScale: 1,
            minScale: 0.25,
            maxScale: 1.75,
            centerSmallGraphs: true,
            wheelSensitivity: 0.7,
        },
        // Hovering a node highlights its direct neighbourhood; everything
        // else is dimmed slightly. Generic engine feature, no APRIL semantics.
        interaction: {
            highlightMode: 'connected',
            dimUnrelated: true,
        },
        render: {
            title: `APRIL: ${model.metadata?.template?.key || 'process graph'}`,
            description: 'Server-side APRIL template model rendered by process-graph.',
        },
        onNodeClick: (node) => {
            const url = navigationUrl(node);
            if (url) window.location.assign(url);
        },
        onEdgeClick: (edge) => {
            const url = navigationUrl(edge);
            if (url) window.location.assign(url);
        },
    });
    setStatus('Process Graph Renderer aktiv. Knoten mit hinterlegter Navigation sind anklickbar.');

    return true;
};

if (target && source) {
    const requestedRenderer = processTarget?.dataset.templateGraphRenderer || 'mermaid';
    const render = async () => {
        if (requestedRenderer === 'process-graph') {
            try {
                if (await renderProcessGraph()) return;
            } catch (error) {
                console.error('Process Graph rendering failed:', error);
                try {
                    await renderMermaid(true);
                    return;
                } catch (fallbackError) {
                    console.error('Mermaid fallback rendering failed:', fallbackError);
                }
            }
        } else {
            try {
                await renderMermaid(false);
                return;
            } catch (error) {
                console.error('Mermaid rendering failed:', error);
            }
        }

        target.replaceChildren();
        const note = document.createElement('p');
        note.className = 'muted';
        note.textContent = 'Die grafische Vorschau konnte nicht erzeugt werden. Das neutrale Graph-Modell und der Mermaid-Quelltext stehen weiterhin zur Verfügung.';
        target.append(note);
        setStatus('Kein Browser-Renderer verfügbar.');
    };

    void render();
}
