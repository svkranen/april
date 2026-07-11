<?php

namespace App\Tests\Controller\App;

use App\Intelligence\Application\DocumentCheckResultProvider;
use App\Intelligence\Application\DocumentCheckResultView;
use App\Intelligence\Application\DocumentListProvider;
use App\Intelligence\Application\DocumentListRow;
use App\Intelligence\Application\ProcessTemplateProvider;
use App\Intelligence\Application\ProcessTemplateCheckResult;
use App\Intelligence\Application\VisibilityCheckResultProvider;
use App\Intelligence\Application\VisibilityCheckResultRecord;
use App\Intelligence\Domain\ProcessTemplate;
use DateTimeImmutable;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

class TemplateGraphTest extends AppWebTestCase
{
    private const STEP = '01 Rechnungen pruefen';

    public function testGraphPageRendersNeutralModelAndMermaidFallbackWithoutFindings(): void
    {
        $client = self::createAuthenticatedClient();

        // No DocumentListProvider fake: a 200 here proves no documents were read
        // (the real provider would hit a non-existent test DB).
        $client->request('GET', '/app/templates/ai-rechnungen/graph');

        self::assertResponseIsSuccessful();
        $html = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('flowchart TD', $html);
        self::assertStringContainsString('"schemaVersion":"1.0"', $html);
        self::assertStringContainsString('data-template-graph-renderer="process-graph"', $html);
        self::assertStringContainsString('data-process-graph-module-url="/vendor/process-graph/index.js"', $html);
        self::assertStringContainsString('n_01_Rechnungen_pruefen', $html);
        // Opt-in: every node is not_calculated and the activation link is offered
        // (and keeps the current renderer and layout direction).
        self::assertStringContainsString('class n_01_Rechnungen_pruefen not_calculated', $html);
        self::assertSelectorExists('a.pill-link[href="/app/templates/ai-rechnungen/graph?withFindings=1&renderer=process-graph&direction=TB&camera=auto"]');
    }

    public function testGraphPageAggregatesFindingsPerStepWithOptIn(): void
    {
        $client = self::createAuthenticatedClient();
        $this->fakeProviders(
            $client,
            [new DocumentListRow('doc-1', null, 1, 3, new DateTimeImmutable('2026-06-15T09:30:00+00:00'))],
            [$this->record(self::STEP, 'violation')],
            DocumentCheckResultView::fromResult(new ProcessTemplateCheckResult([], [], []))
        );

        $client->request('GET', '/app/templates/ai-rechnungen/graph?withFindings=1');

        self::assertResponseIsSuccessful();
        $html = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('class n_01_Rechnungen_pruefen critical', $html);
        self::assertStringContainsString('Kritisch', $html);
        self::assertStringContainsString('"state":"critical"', $html);
    }

    public function testMermaidRendererCanBeSelectedExplicitlyAndFallbackRemainsPresent(): void
    {
        $client = self::createAuthenticatedClient();
        $client->request('GET', '/app/templates/incident-management/graph?renderer=mermaid');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('[data-template-graph-renderer="mermaid"]');
        self::assertStringContainsString('flowchart TD', (string) $client->getResponse()->getContent());
        self::assertSelectorExists('[data-process-graph-model]');
    }

    public function testGraphPageDefaultsToVerticalDirection(): void
    {
        $client = self::createAuthenticatedClient();
        $client->request('GET', '/app/templates/ai-rechnungen/graph');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('[data-process-graph-direction="TB"]');
        // The vertical pill is marked active by default.
        self::assertSelectorTextContains('.header-actions[aria-label="Ausrichtung auswählen"] a.pill-link.is-active', 'Vertikal');
    }

    public function testGraphPageAcceptsDirectionLr(): void
    {
        $client = self::createAuthenticatedClient();
        $client->request('GET', '/app/templates/ai-rechnungen/graph?direction=LR');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('[data-process-graph-direction="LR"]');
        self::assertSelectorTextContains('.header-actions[aria-label="Ausrichtung auswählen"] a.pill-link.is-active', 'Horizontal');
    }

    public function testGraphPageAcceptsDirectionTbExplicitly(): void
    {
        $client = self::createAuthenticatedClient();
        $client->request('GET', '/app/templates/ai-rechnungen/graph?direction=TB');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('[data-process-graph-direction="TB"]');
    }

    public function testInvalidDirectionFallsBackToVertical(): void
    {
        $client = self::createAuthenticatedClient();
        $client->request('GET', '/app/templates/ai-rechnungen/graph?direction=diagonal');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('[data-process-graph-direction="TB"]');
    }

    public function testDirectionToggleBuildsUrlsPreservingRendererAndFindings(): void
    {
        $client = self::createAuthenticatedClient();
        $this->fakeProviders(
            $client,
            [new DocumentListRow('doc-1', null, 1, 3, new DateTimeImmutable('2026-06-15T09:30:00+00:00'))],
            [$this->record(self::STEP, 'violation')],
            DocumentCheckResultView::fromResult(new ProcessTemplateCheckResult([], [], []))
        );

        $client->request('GET', '/app/templates/ai-rechnungen/graph?withFindings=1&renderer=mermaid&direction=LR');

        self::assertResponseIsSuccessful();
        // The direction toggle keeps renderer, findings and camera selection …
        self::assertSelectorExists('a.pill-link[href="/app/templates/ai-rechnungen/graph?withFindings=1&renderer=mermaid&direction=TB&camera=auto"]');
        self::assertSelectorExists('a.pill-link.is-active[href="/app/templates/ai-rechnungen/graph?withFindings=1&renderer=mermaid&direction=LR&camera=auto"]');
        // … and the renderer toggle keeps the chosen direction.
        self::assertSelectorExists('a.pill-link[href="/app/templates/ai-rechnungen/graph?withFindings=1&renderer=process-graph&direction=LR&camera=auto"]');
        // Mermaid stays usable as-is with the direction parameter present.
        self::assertStringContainsString('flowchart TD', (string) $client->getResponse()->getContent());
    }

    public function testTemplateGraphAssetPassesDirectionPresetAndHighlightToEngine(): void
    {
        // Smoke test on the AssetMapper entrypoint: the browser call must
        // forward the validated direction with the balanced preset and enable
        // the generic connected-path hover highlight.
        $asset = (string) file_get_contents(__DIR__ . '/../../../assets/template-graph.js');
        self::assertStringContainsString("['LR', 'TB'].includes(raw) ? raw : 'TB'", $asset);
        self::assertStringContainsString('processTarget.dataset.processGraphDirection', $asset);
        self::assertStringContainsString("preset: 'balanced'", $asset);
        self::assertStringContainsString("highlightMode: 'connected'", $asset);
    }

    public function testGraphPageDefaultsToAutoCamera(): void
    {
        $client = self::createAuthenticatedClient();
        $client->request('GET', '/app/templates/ai-rechnungen/graph');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('[data-process-graph-camera="auto"]');
        self::assertSelectorTextContains('.header-actions[aria-label="Ansicht auswählen"] a.pill-link.is-active', 'Auto');
    }

    public function testGraphPageAcceptsCameraModes(): void
    {
        $client = self::createAuthenticatedClient();
        foreach (['natural', 'comfortable', 'overview'] as $camera) {
            $client->request('GET', '/app/templates/ai-rechnungen/graph?camera=' . $camera);
            self::assertResponseIsSuccessful();
            self::assertSelectorExists(sprintf('[data-process-graph-camera="%s"]', $camera));
        }
    }

    public function testInvalidCameraFallsBackToAuto(): void
    {
        $client = self::createAuthenticatedClient();
        $client->request('GET', '/app/templates/ai-rechnungen/graph?camera=cinematic');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('[data-process-graph-camera="auto"]');
    }

    public function testCameraToggleBuildsUrlsPreservingOtherParameters(): void
    {
        $client = self::createAuthenticatedClient();
        $client->request('GET', '/app/templates/ai-rechnungen/graph?renderer=process-graph&direction=LR&camera=overview');

        self::assertResponseIsSuccessful();
        // Camera pills keep direction/renderer/findings; the active pill reflects the choice.
        self::assertSelectorExists('a.pill-link.is-active[href="/app/templates/ai-rechnungen/graph?withFindings=0&renderer=process-graph&direction=LR&camera=overview"]');
        self::assertSelectorExists('a.pill-link[href="/app/templates/ai-rechnungen/graph?withFindings=0&renderer=process-graph&direction=LR&camera=natural"]');
        // Direction toggle keeps the camera choice.
        self::assertSelectorExists('a.pill-link[href="/app/templates/ai-rechnungen/graph?withFindings=0&renderer=process-graph&direction=TB&camera=overview"]');
    }

    public function testTemplateGraphAssetPassesCameraAndViewportToEngine(): void
    {
        $asset = (string) file_get_contents(__DIR__ . '/../../../assets/template-graph.js');
        self::assertStringContainsString("['auto', 'natural', 'comfortable', 'overview'].includes(raw) ? raw : 'auto'", $asset);
        self::assertStringContainsString('processTarget.dataset.processGraphCamera', $asset);
        self::assertStringContainsString('cameraMode:', $asset);
        self::assertStringContainsString('minInitialScale: 0.35', $asset);
        self::assertStringContainsString('wheelSensitivity: 0.7', $asset);
        self::assertStringNotContainsString("initialView: 'fit'", $asset);
    }

    public function testJourneyGraphRendersProcessStepsAndMatchMetadata(): void
    {
        $client = self::createAuthenticatedClient();
        static::getContainer()->set(ProcessTemplateProvider::class, new class implements ProcessTemplateProvider {
            public function findByProcessKey(string $processKey): ?ProcessTemplate
            {
                if ($processKey !== 'journey-demo') {
                    return null;
                }

                return \App\Intelligence\Domain\ProcessTemplateArrayFactory::fromArray([
                    'key' => 'journey-demo',
                    'scope' => 'journey',
                    'match' => ['any_process' => ['main-process']],
                    'steps' => [
                        ['key' => 'entry', 'type' => 'process', 'process_key' => 'entry-process', 'required' => false],
                        ['key' => 'main', 'type' => 'process', 'process_key' => 'main-process', 'required' => true],
                    ],
                    'transitions' => [['from' => 'entry', 'to' => 'main']],
                ]);
            }
        });

        $client->request('GET', '/app/templates/journey-demo/graph');

        self::assertResponseIsSuccessful();
        $html = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('"graphType":"journey"', $html);
        self::assertStringContainsString('"type":"subprocess"', $html);
        self::assertStringContainsString('"anyProcess":["main-process"]', $html);
        self::assertStringContainsString('"optional":true', $html);
    }

    public function testUnknownTemplateReturns404(): void
    {
        $client = self::createAuthenticatedClient();
        $client->request('GET', '/app/templates/does-not-exist-xyz/graph');

        self::assertResponseStatusCodeSame(404);
    }

    public function testDetailPageLinksToGraph(): void
    {
        $client = self::createAuthenticatedClient();
        $client->request('GET', '/app/templates/ai-rechnungen');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('a.pill-link[href="/app/templates/ai-rechnungen/graph"]');
    }

    /**
     * @param array<int, DocumentListRow> $rows
     * @param array<int, VisibilityCheckResultRecord> $records
     */
    private function fakeProviders(KernelBrowser $client, array $rows, array $records, DocumentCheckResultView $check): void
    {
        $documents = new class($rows) implements DocumentListProvider {
            /** @param array<int, DocumentListRow> $rows */
            public function __construct(private readonly array $rows)
            {
            }

            public function documentsForProcess(string $processKey, ?int $limit = null): array
            {
                return $this->rows;
            }
        };
        $visibility = new class($records) implements VisibilityCheckResultProvider {
            /** @param array<int, VisibilityCheckResultRecord> $records */
            public function __construct(private readonly array $records)
            {
            }

            public function findByDocument(string $documentUuid, ?string $processKey = null): array
            {
                return $this->records;
            }
        };
        $checkProvider = new class($check) implements DocumentCheckResultProvider {
            public function __construct(private readonly DocumentCheckResultView $view)
            {
            }

            public function forDocument(ProcessTemplate $template, string $documentUuid): DocumentCheckResultView
            {
                return $this->view;
            }
        };

        $container = static::getContainer();
        $container->set(DocumentListProvider::class, $documents);
        $container->set(VisibilityCheckResultProvider::class, $visibility);
        $container->set(DocumentCheckResultProvider::class, $checkProvider);
    }

    private function record(string $stepKey, string $status): VisibilityCheckResultRecord
    {
        return new VisibilityCheckResultRecord(
            1, 'doc-1', 1, 'ai-rechnungen', 'amagno', $stepKey, 'after', 'route', 'profile',
            'external_today', 'amagno_magnet_documents', '1009', 'hidden', 'visible', $status, 'forbidden_visibility',
            new DateTimeImmutable('2026-06-15T10:00:00+00:00'), 1, true, 1, null
        );
    }
}
