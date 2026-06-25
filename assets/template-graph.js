/*
 * Browser rendering for the per-template Mermaid process graph.
 *
 * Scope: presentation only. The Mermaid source is produced server-side by the
 * PHP graph builder and is always available as a code block; this module just
 * renders an additional preview from it. It initialises Mermaid only when both
 * the source and the target element are present, reads the source as plain text
 * from the DOM (never as executable HTML) and degrades gracefully so a render
 * failure never breaks the page.
 */
import mermaid from 'mermaid';

const target = document.querySelector('[data-mermaid-graph-target="preview"]');
const source = document.querySelector('[data-mermaid-graph-source]');

if (target && source) {
    const code = (source.textContent || '').trim();

    if (code !== '') {
        // securityLevel 'strict' makes Mermaid sanitise the rendered SVG.
        mermaid.initialize({ startOnLoad: false, securityLevel: 'strict' });

        mermaid
            .render('template-graph-svg', code)
            .then(({ svg }) => {
                target.innerHTML = svg;
            })
            .catch((error) => {
                target.replaceChildren();
                const note = document.createElement('p');
                note.className = 'muted';
                note.textContent =
                    'Die grafische Vorschau konnte nicht erzeugt werden. Der Mermaid-Quelltext steht unten zur Verfügung.';
                target.append(note);
                // Keep the page usable; details only in the console.
                console.error('Mermaid rendering failed:', error);
            });
    }
}
