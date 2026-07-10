<?php

namespace App\Tests\Controller\App;

use App\Intelligence\Application\DocumentTimelineProvider;
use App\Intelligence\Application\ProcessDocumentUuidProvider;
use App\Intelligence\Application\ProcessKeyDocumentOverviewRow;
use App\Intelligence\Application\ProcessKeyOverviewProvider;
use App\Intelligence\Application\ProcessKeyOverviewRow;
use App\Intelligence\Application\ProcessTemplateProvider;
use App\Intelligence\Domain\ProcessEventRecord;
use App\Intelligence\Domain\ProcessTemplate;
use App\Intelligence\Domain\ProcessTemplateMatch;
use App\Intelligence\Domain\ProcessTemplateStep;
use App\Intelligence\Infrastructure\Process\InMemoryDocumentTimelineProvider;
use App\Intelligence\Infrastructure\Process\InMemoryProcessDocumentUuidProvider;
use App\Intelligence\Infrastructure\Template\YamlJourneyMatchWriter;
use App\Intelligence\Port\JourneyMatchStore;
use DateTimeImmutable;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\Yaml\Yaml;

final class JourneyMatchControllerTest extends AppWebTestCase
{
    private string $templateDirectory;

    protected function setUp(): void
    {
        parent::setUp();
        $this->templateDirectory = sys_get_temp_dir().'/april-journey-match-'.uniqid('', true);
        mkdir($this->templateDirectory, 0775, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->templateDirectory.'/*') ?: [] as $file) {
            unlink($file);
        }
        rmdir($this->templateDirectory);
        parent::tearDown();
    }

    public function testMatchingEditorShowsObservedKeysSortedAndPreselected(): void
    {
        $client = $this->clientWithFakes();

        $client->request('GET', '/app/templates/my-journey/matching');

        self::assertResponseIsSuccessful();
        $html = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('Matching – my-journey', $html);
        self::assertStringContainsString('gespeicherter Stand', $html);
        // saved match key proc_b is preselected, proc_a is not
        self::assertSelectorExists('input[type="checkbox"][name="keys[]"][value="proc_b"][checked]');
        self::assertSelectorExists('input[type="checkbox"][name="keys[]"][value="proc_a"]:not([checked])');
        // alphabetical order
        self::assertLessThan(strpos($html, 'value="proc_b"'), strpos($html, 'value="proc_a"'));
        // candidate preview for the saved match
        self::assertStringContainsString('uuid-b', $html);
        self::assertSelectorExists('a[href="/app/intelligence/documents/uuid-b?documentVersion=1"]');
    }

    public function testPreviewOverrideChangesCandidatesButNotTheYamlFile(): void
    {
        $client = $this->clientWithFakes();
        $yamlBefore = file_get_contents($this->templateDirectory.'/my-journey.yaml');

        $client->request('GET', '/app/templates/my-journey/matching?preview=1&keys%5B%5D=proc_a&extraKeys=unobserved_key');

        self::assertResponseIsSuccessful();
        $html = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('ungespeicherte Vorschau', $html);
        self::assertStringContainsString('uuid-a', $html);
        self::assertStringNotContainsString('uuid-b', $html);
        self::assertStringContainsString('nicht beobachtet', $html);
        self::assertStringContainsString('Diese Match-Auswahl speichern', $html);
        // preview must not touch the template file
        self::assertSame($yamlBefore, file_get_contents($this->templateDirectory.'/my-journey.yaml'));
    }

    public function testInvalidProcessKeyShowsBusinessErrorInsteadOf500(): void
    {
        $client = $this->clientWithFakes();

        $client->request('GET', '/app/templates/my-journey/matching?preview=1&extraKeys=foo%2Fbar');

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Ungueltige Process Keys', (string) $client->getResponse()->getContent());
    }

    public function testNonJourneyTemplateShowsFriendlyNotice(): void
    {
        $client = $this->clientWithFakes();

        $client->request('GET', '/app/templates/plain-process/matching');

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Kein Journey-Template', (string) $client->getResponse()->getContent());
    }

    public function testJourneyWithoutCandidatesShowsFriendlyMessage(): void
    {
        $client = $this->clientWithFakes();

        $client->request('GET', '/app/templates/my-journey/matching?preview=1&keys%5B%5D=proc_without_events');

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('derzeit keine', (string) $client->getResponse()->getContent());
    }

    public function testLegacyJourneyWithoutExplicitMatchShowsFallbackHint(): void
    {
        $client = $this->clientWithFakes();

        $client->request('GET', '/app/templates/legacy-journey/matching');

        self::assertResponseIsSuccessful();
        $html = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('kein expliziter Match (Legacy-Fallback)', $html);
        self::assertStringContainsString('legacy fallback', $html);
        // the effective fallback key and a plain-language warning are shown
        self::assertStringContainsString('Legacy-Fallback aktiv', $html);
        self::assertStringContainsString('ersten Pflicht-Prozessschritt', $html);
        self::assertStringContainsString('proc_b', $html);
    }

    public function testEmptyPreviewSelectionExplainsActiveLegacyFallback(): void
    {
        $client = $this->clientWithFakes();

        // my-journey has a saved match, but the user deselects everything:
        // the preview must explain that the legacy fallback now matches proc_b.
        $client->request('GET', '/app/templates/my-journey/matching?preview=1');

        self::assertResponseIsSuccessful();
        $html = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('Legacy-Fallback aktiv', $html);
        self::assertStringContainsString('kein expliziter Match ausgewählt', $html);
        self::assertStringContainsString('uuid-b', $html);
    }

    public function testUnmatchableJourneyExplainsWhyNoCandidatesArePossible(): void
    {
        $client = $this->clientWithFakes();

        $client->request('GET', '/app/templates/unmatchable-journey/matching?preview=1');

        self::assertResponseIsSuccessful();
        $html = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('Nicht matchbar', $html);
        self::assertStringContainsString('ohne Pflicht-Prozessschritt', $html);
    }

    public function testSavePersistsMatchToYamlAndRedirects(): void
    {
        $client = $this->clientWithFakes();

        $crawler = $client->request('GET', '/app/templates/my-journey/matching?preview=1&keys%5B%5D=proc_a');
        $token = $crawler->filter('input[name="_token"]')->attr('value');

        $client->request('POST', '/app/templates/my-journey/matching', [
            '_token' => $token,
            'keys' => ['proc_a'],
        ]);

        self::assertResponseRedirects('/app/templates/my-journey/matching?saved=1');
        $data = Yaml::parseFile($this->templateDirectory.'/my-journey.yaml');
        self::assertSame(['proc_a'], $data['match']['any_process']);
        self::assertSame('journey', $data['scope']);
    }

    public function testSaveEmptySelectionRemovesExplicitMatch(): void
    {
        $client = $this->clientWithFakes();

        $crawler = $client->request('GET', '/app/templates/my-journey/matching?preview=1');
        $token = $crawler->filter('input[name="_token"]')->attr('value');

        $client->request('POST', '/app/templates/my-journey/matching', ['_token' => $token]);

        self::assertResponseRedirects('/app/templates/my-journey/matching?saved=1');
        $data = Yaml::parseFile($this->templateDirectory.'/my-journey.yaml');
        self::assertArrayNotHasKey('match', $data);
    }

    public function testSaveWithInvalidCsrfTokenIsRejected(): void
    {
        $client = $this->clientWithFakes();
        $yamlBefore = file_get_contents($this->templateDirectory.'/my-journey.yaml');

        $client->request('POST', '/app/templates/my-journey/matching', [
            '_token' => 'wrong-token',
            'keys' => ['proc_a'],
        ]);

        self::assertResponseStatusCodeSame(400);
        self::assertStringContainsString('Sitzung ist abgelaufen', (string) $client->getResponse()->getContent());
        self::assertSame($yamlBefore, file_get_contents($this->templateDirectory.'/my-journey.yaml'));
    }

    public function testTemplateDetailPageOffersMatchingPillForJourneyTemplatesOnly(): void
    {
        $client = $this->clientWithFakes();

        $client->request('GET', '/app/templates/my-journey');
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('a[href="/app/templates/my-journey/matching"]');

        $client->request('GET', '/app/templates/plain-process');
        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('a[href="/app/templates/plain-process/matching"]');
    }

    private function clientWithFakes(): KernelBrowser
    {
        $client = self::createAuthenticatedClient();

        $this->writeJourneyYaml();

        $events = [
            $this->event(1, 'uuid-a', 'proc_a', '2026-06-01T09:00:00+00:00'),
            $this->event(2, 'uuid-b', 'proc_b', '2026-06-01T10:00:00+00:00'),
        ];

        $templates = [
            'my-journey' => new ProcessTemplate(
                'my-journey',
                scope: 'journey',
                match: new ProcessTemplateMatch(['proc_b']),
                steps: [
                    new ProcessTemplateStep('step_b', type: 'process', processKey: 'proc_b', required: true),
                ]
            ),
            'legacy-journey' => new ProcessTemplate(
                'legacy-journey',
                scope: 'journey',
                steps: [
                    new ProcessTemplateStep('step_b', type: 'process', processKey: 'proc_b', required: true),
                ]
            ),
            'unmatchable-journey' => new ProcessTemplate(
                'unmatchable-journey',
                scope: 'journey',
                steps: [
                    new ProcessTemplateStep('step_b', type: 'process', processKey: 'proc_b', required: false),
                ]
            ),
            'plain-process' => new ProcessTemplate('plain-process', scope: 'process'),
        ];

        $templateProvider = new class($templates) implements ProcessTemplateProvider {
            /** @param array<string, ProcessTemplate> $templates */
            public function __construct(private readonly array $templates)
            {
            }

            public function findByProcessKey(string $processKey): ?ProcessTemplate
            {
                return $this->templates[$processKey] ?? null;
            }
        };

        $overviewProvider = new class implements ProcessKeyOverviewProvider {
            public function processKeys(): array
            {
                return [
                    new ProcessKeyOverviewRow('proc_b', 1, 1),
                    new ProcessKeyOverviewRow('proc_a', 1, 1),
                ];
            }

            public function documentsForProcessKey(string $processKey, ?int $limit = null): array
            {
                return [];
            }
        };

        $container = static::getContainer();
        $container->set(ProcessTemplateProvider::class, $templateProvider);
        $container->set(ProcessKeyOverviewProvider::class, $overviewProvider);
        $container->set(ProcessDocumentUuidProvider::class, new InMemoryProcessDocumentUuidProvider($events));
        $container->set(DocumentTimelineProvider::class, new InMemoryDocumentTimelineProvider([], $events));
        $container->set(JourneyMatchStore::class, new YamlJourneyMatchWriter($this->templateDirectory));

        return $client;
    }

    private function writeJourneyYaml(): void
    {
        file_put_contents($this->templateDirectory.'/my-journey.yaml', <<<'YAML'
key: my-journey
version: 1.0
scope: journey
match:
  any_process:
    - proc_b
steps:
  - key: step_b
    type: process
    process_key: proc_b
    required: true
YAML);
    }

    private function event(int $id, string $documentUuid, string $processKey, string $occurredAt): ProcessEventRecord
    {
        $time = new DateTimeImmutable($occurredAt);

        return new ProcessEventRecord(
            $id,
            sprintf('evt-%d', $id),
            'test',
            $processKey,
            'start',
            'start',
            'doc-'.$documentUuid,
            $documentUuid,
            1,
            'user-1',
            $time,
            $time,
            '{}',
            '{}',
            1
        );
    }
}
