<?php

/**
 * Returns the importmap for this application.
 *
 * - "path" is a path inside the asset mapper system. Use the
 *     "debug:asset-map" command to see the full list of paths.
 *
 * - "entrypoint" (JavaScript only) set to true for any module that will
 *     be used as an "entrypoint" (and passed to the importmap() Twig function).
 *
 * The "importmap:require" command can be used to add new entries to this file.
 */
return [
    'app' => [
        'path' => './assets/app.js',
        'entrypoint' => true,
    ],
    'template-graph' => [
        'path' => './assets/template-graph.js',
        'entrypoint' => true,
    ],
    '@mermaid-js/parser' => [
        'version' => '1.2.0',
    ],
    'mermaid' => [
        'version' => '11.16.0',
    ],
    '@mermaid-js/parser/dist/chunks/mermaid-parser.core/info-DKCQHKI2.mjs' => [
        'version' => '1.2.0',
    ],
    '@mermaid-js/parser/dist/chunks/mermaid-parser.core/packet-7NZHBO7P.mjs' => [
        'version' => '1.2.0',
    ],
    '@mermaid-js/parser/dist/chunks/mermaid-parser.core/pie-RZYD4A2V.mjs' => [
        'version' => '1.2.0',
    ],
    '@mermaid-js/parser/dist/chunks/mermaid-parser.core/treeView-QDETBFTQ.mjs' => [
        'version' => '1.2.0',
    ],
    '@mermaid-js/parser/dist/chunks/mermaid-parser.core/architecture-TIHT7OUA.mjs' => [
        'version' => '1.2.0',
    ],
    '@mermaid-js/parser/dist/chunks/mermaid-parser.core/gitGraph-TEB2WS4Q.mjs' => [
        'version' => '1.2.0',
    ],
    '@mermaid-js/parser/dist/chunks/mermaid-parser.core/eventmodeling-45OFAUF4.mjs' => [
        'version' => '1.2.0',
    ],
    '@mermaid-js/parser/dist/chunks/mermaid-parser.core/radar-I7S5WNFK.mjs' => [
        'version' => '1.2.0',
    ],
    '@mermaid-js/parser/dist/chunks/mermaid-parser.core/railroad-3IZDKUUU.mjs' => [
        'version' => '1.2.0',
    ],
    '@mermaid-js/parser/dist/chunks/mermaid-parser.core/railroad-ebnf-EBAXGLYW.mjs' => [
        'version' => '1.2.0',
    ],
    '@mermaid-js/parser/dist/chunks/mermaid-parser.core/railroad-abnf-AHOZXSZD.mjs' => [
        'version' => '1.2.0',
    ],
    '@mermaid-js/parser/dist/chunks/mermaid-parser.core/railroad-peg-LSFZ7HO6.mjs' => [
        'version' => '1.2.0',
    ],
    '@mermaid-js/parser/dist/chunks/mermaid-parser.core/treemap-6X3UGDF4.mjs' => [
        'version' => '1.2.0',
    ],
    '@mermaid-js/parser/dist/chunks/mermaid-parser.core/wardley-OPB4EBWU.mjs' => [
        'version' => '1.2.0',
    ],
    '@mermaid-js/parser/dist/chunks/mermaid-parser.core/cynefin-VYW2F7L2.mjs' => [
        'version' => '1.2.0',
    ],
    'dayjs' => [
        'version' => '1.11.21',
    ],
    'khroma' => [
        'version' => '2.1.0',
    ],
    'dompurify' => [
        'version' => '3.4.11',
    ],
    'd3' => [
        'version' => '7.9.0',
    ],
    '@braintree/sanitize-url' => [
        'version' => '7.1.2',
    ],
    'es-toolkit/compat' => [
        'version' => '1.48.1',
    ],
    '@iconify/utils' => [
        'version' => '3.1.3',
    ],
    'marked' => [
        'version' => '16.4.2',
    ],
    'ts-dedent' => [
        'version' => '2.3.0',
    ],
    'roughjs' => [
        'version' => '4.6.6',
    ],
    'stylis' => [
        'version' => '4.4.0',
    ],
    'katex' => [
        'version' => '0.16.47',
    ],
    'mermaid/dist/chunks/mermaid.core/dagre-VKFMJZFB.mjs' => [
        'version' => '11.16.0',
    ],
    'mermaid/dist/chunks/mermaid.core/swimlanes-5IMT3BWC.mjs' => [
        'version' => '11.16.0',
    ],
    'mermaid/dist/chunks/mermaid.core/cose-bilkent-JH36ORCC.mjs' => [
        'version' => '11.16.0',
    ],
    'mermaid/dist/chunks/mermaid.core/c4Diagram-LMCZKHZV.mjs' => [
        'version' => '11.16.0',
    ],
    'mermaid/dist/chunks/mermaid.core/flowDiagram-23GEKE2U.mjs' => [
        'version' => '11.16.0',
    ],
    'mermaid/dist/chunks/mermaid.core/swimlanesDiagram-G3AALYLV.mjs' => [
        'version' => '11.16.0',
    ],
    'mermaid/dist/chunks/mermaid.core/erDiagram-Q63AITRT.mjs' => [
        'version' => '11.16.0',
    ],
    'mermaid/dist/chunks/mermaid.core/gitGraphDiagram-IHSO6WYX.mjs' => [
        'version' => '11.16.0',
    ],
    'mermaid/dist/chunks/mermaid.core/ganttDiagram-NO4QXBWP.mjs' => [
        'version' => '11.16.0',
    ],
    'mermaid/dist/chunks/mermaid.core/infoDiagram-FWYZ7A6U.mjs' => [
        'version' => '11.16.0',
    ],
    'mermaid/dist/chunks/mermaid.core/pieDiagram-ENE6RG2P.mjs' => [
        'version' => '11.16.0',
    ],
    'mermaid/dist/chunks/mermaid.core/quadrantDiagram-ABIIQ3AL.mjs' => [
        'version' => '11.16.0',
    ],
    'mermaid/dist/chunks/mermaid.core/xychartDiagram-FW5EYKEG.mjs' => [
        'version' => '11.16.0',
    ],
    'mermaid/dist/chunks/mermaid.core/requirementDiagram-TGXJPOKE.mjs' => [
        'version' => '11.16.0',
    ],
    'mermaid/dist/chunks/mermaid.core/sequenceDiagram-DBY2YBRQ.mjs' => [
        'version' => '11.16.0',
    ],
    'mermaid/dist/chunks/mermaid.core/classDiagram-OUVF2IWQ.mjs' => [
        'version' => '11.16.0',
    ],
    'mermaid/dist/chunks/mermaid.core/classDiagram-v2-EOCWNBFH.mjs' => [
        'version' => '11.16.0',
    ],
    'mermaid/dist/chunks/mermaid.core/stateDiagram-2N3HPSRC.mjs' => [
        'version' => '11.16.0',
    ],
    'mermaid/dist/chunks/mermaid.core/stateDiagram-v2-6OUMAXLB.mjs' => [
        'version' => '11.16.0',
    ],
    'mermaid/dist/chunks/mermaid.core/journeyDiagram-5HDEW3XC.mjs' => [
        'version' => '11.16.0',
    ],
    'mermaid/dist/chunks/mermaid.core/timeline-definition-FHXFAJF6.mjs' => [
        'version' => '11.16.0',
    ],
    'mermaid/dist/chunks/mermaid.core/mindmap-definition-LN4V7U3C.mjs' => [
        'version' => '11.16.0',
    ],
    'mermaid/dist/chunks/mermaid.core/kanban-definition-HUTT4EX6.mjs' => [
        'version' => '11.16.0',
    ],
    'mermaid/dist/chunks/mermaid.core/sankeyDiagram-HTMAVEWB.mjs' => [
        'version' => '11.16.0',
    ],
    'mermaid/dist/chunks/mermaid.core/diagram-NH7WQ7WH.mjs' => [
        'version' => '11.16.0',
    ],
    'mermaid/dist/chunks/mermaid.core/diagram-WEI45ONY.mjs' => [
        'version' => '11.16.0',
    ],
    'mermaid/dist/chunks/mermaid.core/blockDiagram-677ZJIJ3.mjs' => [
        'version' => '11.16.0',
    ],
    'mermaid/dist/chunks/mermaid.core/diagram-OA4YK3LP.mjs' => [
        'version' => '11.16.0',
    ],
    'mermaid/dist/chunks/mermaid.core/architectureDiagram-ZJ3FMSHR.mjs' => [
        'version' => '11.16.0',
    ],
    'mermaid/dist/chunks/mermaid.core/diagram-FQU43EPY.mjs' => [
        'version' => '11.16.0',
    ],
    'mermaid/dist/chunks/mermaid.core/ishikawaDiagram-FXEZZL3T.mjs' => [
        'version' => '11.16.0',
    ],
    'mermaid/dist/chunks/mermaid.core/vennDiagram-L72KCM5P.mjs' => [
        'version' => '11.16.0',
    ],
    'mermaid/dist/chunks/mermaid.core/diagram-G47NLZAW.mjs' => [
        'version' => '11.16.0',
    ],
    'mermaid/dist/chunks/mermaid.core/wardleyDiagram-EHGQE667.mjs' => [
        'version' => '11.16.0',
    ],
    'mermaid/dist/chunks/mermaid.core/cynefinDiagram-TSTJHNR4.mjs' => [
        'version' => '11.16.0',
    ],
    'mermaid/dist/chunks/mermaid.core/railroadDiagram-RFXS5EU6.mjs' => [
        'version' => '11.16.0',
    ],
    'mermaid/dist/chunks/mermaid.core/ebnfDiagram-CCIWWBDH.mjs' => [
        'version' => '11.16.0',
    ],
    'mermaid/dist/chunks/mermaid.core/abnfDiagram-VRR7QNED.mjs' => [
        'version' => '11.16.0',
    ],
    'mermaid/dist/chunks/mermaid.core/pegDiagram-2B236MQR.mjs' => [
        'version' => '11.16.0',
    ],
    'd3-array' => [
        'version' => '3.2.4',
    ],
    'd3-axis' => [
        'version' => '3.0.0',
    ],
    'd3-brush' => [
        'version' => '3.0.0',
    ],
    'd3-chord' => [
        'version' => '3.0.1',
    ],
    'd3-color' => [
        'version' => '3.1.0',
    ],
    'd3-contour' => [
        'version' => '4.0.2',
    ],
    'd3-delaunay' => [
        'version' => '6.0.4',
    ],
    'd3-dispatch' => [
        'version' => '3.0.1',
    ],
    'd3-drag' => [
        'version' => '3.0.0',
    ],
    'd3-dsv' => [
        'version' => '3.0.1',
    ],
    'd3-ease' => [
        'version' => '3.0.1',
    ],
    'd3-fetch' => [
        'version' => '3.0.1',
    ],
    'd3-force' => [
        'version' => '3.0.0',
    ],
    'd3-format' => [
        'version' => '3.1.0',
    ],
    'd3-geo' => [
        'version' => '3.1.1',
    ],
    'd3-hierarchy' => [
        'version' => '3.1.2',
    ],
    'd3-interpolate' => [
        'version' => '3.0.1',
    ],
    'd3-path' => [
        'version' => '3.1.0',
    ],
    'd3-polygon' => [
        'version' => '3.0.1',
    ],
    'd3-quadtree' => [
        'version' => '3.0.1',
    ],
    'd3-random' => [
        'version' => '3.0.1',
    ],
    'd3-scale' => [
        'version' => '4.0.2',
    ],
    'd3-scale-chromatic' => [
        'version' => '3.1.0',
    ],
    'd3-selection' => [
        'version' => '3.0.0',
    ],
    'd3-shape' => [
        'version' => '3.2.0',
    ],
    'd3-time' => [
        'version' => '3.1.0',
    ],
    'd3-time-format' => [
        'version' => '4.1.0',
    ],
    'd3-timer' => [
        'version' => '3.0.1',
    ],
    'd3-transition' => [
        'version' => '3.0.1',
    ],
    'd3-zoom' => [
        'version' => '3.0.0',
    ],
    'dagre-d3-es/src/graphlib/index.js' => [
        'version' => '7.0.14',
    ],
    'dagre-d3-es/src/graphlib/json.js' => [
        'version' => '7.0.14',
    ],
    'dagre-d3-es/src/dagre/index.js' => [
        'version' => '7.0.14',
    ],
    'mermaid/dist/chunks/mermaid.core/sizeCapture-X5ZJPWSS.mjs' => [
        'version' => '11.16.0',
    ],
    'cytoscape' => [
        'version' => '3.34.0',
    ],
    'cytoscape-cose-bilkent' => [
        'version' => '4.1.0',
    ],
    'dayjs/plugin/isoWeek.js' => [
        'version' => '1.11.21',
    ],
    'dayjs/plugin/customParseFormat.js' => [
        'version' => '1.11.21',
    ],
    'dayjs/plugin/advancedFormat.js' => [
        'version' => '1.11.21',
    ],
    'dayjs/plugin/duration.js' => [
        'version' => '1.11.21',
    ],
    'uuid' => [
        'version' => '14.0.1',
    ],
    'd3-sankey' => [
        'version' => '0.12.3',
    ],
    'cytoscape-fcose' => [
        'version' => '2.2.0',
    ],
    '@upsetjs/venn.js' => [
        'version' => '2.0.0',
    ],
    'internmap' => [
        'version' => '2.0.3',
    ],
    'delaunator' => [
        'version' => '5.0.0',
    ],
    'lodash-es' => [
        'version' => '4.17.21',
    ],
    'cose-base' => [
        'version' => '2.2.0',
    ],
    'robust-predicates' => [
        'version' => '3.0.0',
    ],
    'layout-base' => [
        'version' => '2.0.1',
    ],
];
