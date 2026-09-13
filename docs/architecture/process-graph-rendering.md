# Process Graph rendering

APRIL supports two browser renderers on the template graph page:

- `process-graph` is requested by default;
- Mermaid remains the explicit and automatic fallback.

Tested integration baseline:

- process-graph commit: `5fefe8eb440f1cccc126e5b0e69e4a9762b23965`
- npm package: `process-graph-engine@0.1.0`
- expected artifact name: `process-graph-engine-0.1.0.tgz`

The layout-direction toggle requires the `direction`/`preset` layout options
(process-graph `>= 0.2.0`); the hover path highlighting requires the
`interaction` API (`> 0.2.0`; update the baseline above when that release is
cut). Older engine builds ignore unknown options gracefully, so the page still
renders — left-to-right and without highlighting respectively.

Before staging, build the artifact from that exact commit and record the resulting
SHA-256. The package version alone is not sufficient proof of source identity.

Use `?renderer=process-graph` or `?renderer=mermaid`. The findings switch preserves
the selected renderer. A missing or invalid engine module never removes the
server-rendered status table, neutral JSON model, or Mermaid source.

## Layout direction (LR/TB)

The graph page offers a visible orientation toggle ("⇢ Horizontal" /
"⇣ Vertikal") next to the renderer toggle:

- Query parameter: `?direction=LR` or `?direction=TB`.
- **Default: `TB`** (vertical) with the engine's `balanced` spacing preset —
  large processes grow downwards instead of forcing horizontal scrolling.
- Invalid values fall back to `TB` server-side; the client validates again and
  also falls back to `TB`.
- All three toggles (renderer, direction, findings) preserve each other's
  selection, so URLs stay shareable, e.g.
  `?renderer=process-graph&direction=TB&withFindings=1`.
- The direction only affects the process-graph renderer. Mermaid keeps its
  built-in top-down orientation (`flowchart TD`) regardless of the parameter.

The controller passes the validated value to Twig
(`data-process-graph-direction`), and `assets/template-graph.js` forwards it as
`layout: { direction, preset: 'balanced' }` to `renderProcessGraph()`. No engine
or adapter change was required for this — it is a pure host configuration.

## Camera strategy

The graph page offers a view toggle ("Auto / Natürlich / Komfort /
Übersicht") next to the orientation toggle:

- Query parameter: `?camera=auto|natural|comfortable|overview`.
- **Default: `auto`** — the engine chooses from the actual diagram size and
  structure: small journeys render at natural size (centered, never
  enlarged), medium processes like the incident demo fill the width and stay
  readable (the flow axis may scroll), very large processes start as a full
  overview.
- Invalid values fall back to `auto` (server- and client-side).
- All toggles (renderer, direction, camera, findings) preserve each other's
  selection; URLs stay shareable.

`assets/template-graph.js` forwards the choice as part of the viewport
configuration:

```js
viewport: {
    cameraMode, fitPadding: 24,
    minInitialScale: 0.35, maxInitialScale: 1,
    minScale: 0.25, maxScale: 1.75,
    centerSmallGraphs: true, wheelSensitivity: 0.7,
}
```

(`initialView: 'fit'` is superseded by `cameraMode`.) The
`.process-graph-preview` container now has a fixed camera viewport
(`height: 70vh`, `overflow: hidden`) — the engine pans/zooms inside it
instead of native scrollbars. Requires an engine build with the camera
strategy (`cameraMode`); older builds ignore the option and fall back to
their fit behavior.

## Path highlighting

Hovering (or keyboard-focusing) a node highlights its directly connected
neighbourhood — incoming/outgoing edges and adjacent nodes — while the rest of
the diagram is dimmed slightly. This uses the engine's generic
`interaction: { highlightMode: 'connected', dimUnrelated: true }` option; the
engine only works on node/edge ids and adjacency, no APRIL semantics are
involved. Critical/deviation colors stay recognizable (highlighting changes
stroke weight and opacity, never colors). Escape or leaving the diagram
restores the initial appearance.

Other modes (`upstream`, `downstream`, `path`) and the programmatic handle API
(`handle.highlightNode(...)`, `handle.clearHighlight()`) are available for a
future inspector/tooltip iteration; hover callbacks (`onNodeEnter`,
`onNodeFocus`, …) are the intended basis for tooltips, document lists or
finding details — APRIL decides later what to build on top.

## Architecture and contract

APRIL owns all domain interpretation:

```text
ProcessTemplate + TemplateGraphFindings
  -> ProcessTemplateGraphFactory
  -> TemplateGraphModelBuilder
  -> TemplateGraphModel JSON v1.0
  -> process-graph renderProcessGraph()
```

Mermaid is built independently from the same template graph. Mermaid text is not
the integration model.

The JSON boundary contains `schemaVersion`, `graphType`, `direction`, `nodes`,
`edges`, `metadata`, and optional presentation-ready `overlay` data. Nodes carry
required/optional/conditional flags, journey `processKey`, finding count, state,
and a server-generated navigation URL. Edges carry stable IDs, style, optional
status, attributed findings, and optional navigation. `match.any_process` is
metadata, not a synthetic node.

APRIL maps its semantics to neutral engine vocabulary:

| APRIL | process-graph |
| --- | --- |
| start/end | `start` / `end` |
| normal process step | `activity` |
| journey `type: process` | `subprocess` |
| decision point | `decision` |
| parallel split/join | `parallel` |
| OK | `satisfied` |
| warning/technical | `warning` |
| deviation/critical | `deviation` / `critical` |

The browser only delegates server-provided same-origin navigation. It calculates
no findings, durations, flow counts, required state, or journey status.

`UNEXPECTED_PROCESS` remains a critical journey finding. For KPI findings, APRIL
may add observed-only step nodes and edges server-side using the same
`critical`/`deviation` vocabulary; their identities come directly from the
reconstructed measurements. The renderer remains unaware of process semantics.

## Package installation

`process-graph` is an independent npm ESM package with no runtime dependencies.
APRIL does not require Node in production. The engine repository builds and packs
the complete multi-file `dist/`; the APRIL release serves it from a same-origin
directory.

Local development:

```bash
cd /srv/projects/process-graph
npm ci
npm test
npm run typecheck
npm run lint
npm run build

cd /srv/projects/april
mkdir -p public/vendor
ln -s /srv/projects/process-graph/dist public/vendor/process-graph
```

The development symlink is local and must not be committed. Remove it to verify the
automatic Mermaid fallback.

Production uses an immutable `npm pack` artifact:

```bash
cd <PROCESS_GRAPH_CHECKOUT>
npm ci
npm test && npm run typecheck && npm run lint && npm run build
npm pack

cd <NEW_APRIL_RELEASE>
install -d -m 0755 public/vendor/process-graph
tar -xzf <CHECKSUMMED_PROCESS_GRAPH_TARBALL> -C <TEMP_DIRECTORY>
cp -a <TEMP_DIRECTORY>/package/dist/. public/vendor/process-graph/
```

Record the package version, Git commit, tarball SHA-256, and installed file list.
Never copy `src/` into APRIL. The default module URL is
`/vendor/process-graph/index.js`; override it with `PROCESS_GRAPH_MODULE_URL` when
the deployment uses another same-origin location. No CDN is used.

After installation run `asset-map:compile`, cache warmup, container/Twig/YAML lint,
and both renderer URLs. APRIL currently defines no custom CSP. If staging adds a
proxy CSP, its `script-src` must allow the configured same-origin module URL; no
`unsafe-inline` addition is required by this integration.

## Rendering and known limits

The engine provides deterministic layered layout, collision-free node bounding
boxes, orthogonal routing, outside-routed back edges, bounded flow widths, and
line-jump arcs at eligible crossings. Jumps close to bends or arrowheads may be
suppressed. Global crossing elimination, swimlane/group layout, self-loops, and
persistent manual positions are not supported.

APRIL currently supplies finding counts and states on the graph page. Existing
duration/heatmap domain types are not wired into this page until a reliable report
is explicitly requested; no metrics are invented.

## Staging checklist

1. Record tested APRIL commit.
2. Record tested process-graph commit, package version, artifact and checksum.
3. Build a new APRIL release without changing `current`.
4. Install the complete process-graph `dist/` artifact under the configured URL.
5. Compile AssetMapper/importmap assets.
6. Warm the release-local production cache.
7. Check `incident-management` with `renderer=process-graph`.
8. Check approved real process templates, including decisions and parallel groups.
9. Check an approved journey template with optional and required process steps.
10. Check `renderer=mermaid` and simulate a missing engine artifact to prove fallback.
11. Check browser console, Symfony log and webserver log for module/render errors.
12. Roll back by atomically switching to the previous APRIL release and restarting
    the confirmed runtime; the old release retains its own renderer artifact.
