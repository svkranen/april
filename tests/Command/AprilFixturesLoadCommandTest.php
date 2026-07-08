<?php

namespace App\Tests\Command;

use App\Command\AprilFixturesLoadCommand;
use App\Intelligence\Application\ContextSnapshotService;
use App\Intelligence\Application\EventReceiver;
use App\Intelligence\Application\ProcessInstanceManager;
use App\Intelligence\Application\ProcessTemplateCheckService;
use App\Intelligence\Application\TemplateContextProviderResolver;
use App\Intelligence\Connector\Amagno\AmagnoContextProviderFactory;
use App\Intelligence\Connector\Amagno\AmagnoDocumentGateway;
use App\Intelligence\Connector\Amagno\AmagnoFieldMapFactory;
use App\Intelligence\Connector\Amagno\AmagnoTagDefinitionResolver;
use App\Intelligence\Connector\Amagno\AmagnoTagValueResolver;
use App\Intelligence\Infrastructure\Context\InMemoryContextProfileProvider;
use App\Intelligence\Infrastructure\Context\InMemoryContextSnapshotStore;
use App\Intelligence\Infrastructure\Context\NullContextProvider;
use App\Intelligence\Infrastructure\Context\TemplateMappedContextProviderResolver;
use App\Intelligence\Infrastructure\EventStore\InMemoryEventStore;
use App\Intelligence\Infrastructure\Normalizer\GenericPayloadEventNormalizer;
use App\Intelligence\Infrastructure\Process\InMemoryDocumentTimelineProvider;
use App\Intelligence\Infrastructure\Process\InMemoryProcessInstanceRepository;
use App\Intelligence\Infrastructure\Template\YamlProcessTemplateProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\HttpKernel\KernelInterface;

final class AprilFixturesLoadCommandTest extends TestCase
{
    public function testLoadsDefaultIncidentManagementScenario(): void
    {
        $repository = new InMemoryProcessInstanceRepository();
        $tester = new CommandTester($this->command($repository, dirname(__DIR__, 2).'/demo'));

        $exitCode = $tester->execute([]);
        $display = $tester->getDisplay();

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertStringContainsString('scenario: incident-management', $display);
        self::assertStringContainsString('event_files: 4', $display);
        self::assertStringContainsString('events_imported: 16', $display);
        self::assertStringContainsString('events_duplicate: 0', $display);
        self::assertStringContainsString('process_instances: 4', $display);
        self::assertStringContainsString('process_instances_total: 4', $display);
    }

    public function testLoadedIncidentFixturesProvideInlineContextForTemplateChecks(): void
    {
        $eventStore = new InMemoryEventStore();
        $repository = new InMemoryProcessInstanceRepository();
        $snapshotStore = new InMemoryContextSnapshotStore();
        $templateDirectory = dirname(__DIR__, 2).'/config/april/process-templates';
        $tester = new CommandTester($this->command(
            $repository,
            dirname(__DIR__, 2).'/demo',
            $eventStore,
            $snapshotStore,
            $this->templateResolver($templateDirectory)
        ));

        $tester->execute([]);

        $securityClassifySnapshot = $this->snapshotByExternalEventKey(
            $snapshotStore,
            'demo:incident-management:security-001:classify_incident'
        );
        self::assertSame([
            'data_exposure' => true,
            'category' => 'security',
            'system_type' => 'internal',
            'severity' => 'high',
        ], $securityClassifySnapshot->attributes);
        self::assertSame(0, $securityClassifySnapshot->freshnessSeconds);
        self::assertTrue($securityClassifySnapshot->isFreshForDecisionCheck);

        $template = (new YamlProcessTemplateProvider($templateDirectory))->findByProcessKey('incident-management');
        self::assertNotNull($template);

        $check = (new ProcessTemplateCheckService(new InMemoryDocumentTimelineProvider(
            $repository->all(),
            $eventStore->all(),
            $snapshotStore->all()
        )))->checkDocument(
            '10000000-0000-4000-8000-000000000004',
            'incident-management',
            $template,
            1
        );

        self::assertSame([], $check->contextIssues);
        self::assertStringContainsString(
            'Decision rule violation: route_after_classification after classify_incident expected trigger_security_review but got resolve_first_level.',
            implode("\n", $check->deviations)
        );
    }

    public function testLoadsSelectedScenario(): void
    {
        $demoDirectory = $this->temporaryDemoDirectory();
        $scenarioDirectory = $demoDirectory.'/custom-scenario';
        mkdir($scenarioDirectory, 0777, true);
        file_put_contents($scenarioDirectory.'/events-one.json', json_encode([
            $this->payload('custom-001', 'received'),
            $this->payload('custom-001', 'closed', '2026-07-08T09:05:00+00:00'),
        ], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));

        $repository = new InMemoryProcessInstanceRepository();
        $tester = new CommandTester($this->command($repository, $demoDirectory));

        $exitCode = $tester->execute(['--scenario' => 'custom-scenario']);
        $display = $tester->getDisplay();

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertStringContainsString('scenario: custom-scenario', $display);
        self::assertStringContainsString('event_files: 1', $display);
        self::assertStringContainsString('events_imported: 2', $display);
        self::assertStringContainsString('process_instances: 1', $display);
    }

    public function testReportsMissingScenario(): void
    {
        $tester = new CommandTester($this->command(new InMemoryProcessInstanceRepository(), $this->temporaryDemoDirectory()));

        $exitCode = $tester->execute(['--scenario' => 'missing']);

        self::assertSame(Command::FAILURE, $exitCode);
        self::assertStringContainsString('Demo scenario "missing" was not found', $tester->getDisplay());
    }

    private function command(
        InMemoryProcessInstanceRepository $repository,
        string $demoDirectory,
        ?InMemoryEventStore $eventStore = null,
        ?InMemoryContextSnapshotStore $snapshotStore = null,
        ?TemplateContextProviderResolver $templateContextProviderResolver = null
    ): AprilFixturesLoadCommand
    {
        $eventStore ??= new InMemoryEventStore();
        $snapshotStore ??= new InMemoryContextSnapshotStore();
        $receiver = new EventReceiver(
            new GenericPayloadEventNormalizer(),
            $eventStore,
            new ProcessInstanceManager($repository),
            new ContextSnapshotService(
                new InMemoryContextProfileProvider([]),
                new NullContextProvider(),
                $snapshotStore,
                $templateContextProviderResolver
            )
        );

        return new AprilFixturesLoadCommand(
            $receiver,
            $repository,
            $this->createStub(KernelInterface::class),
            $demoDirectory
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(string $documentId, string $stepKey, string $occurredAt = '2026-07-08T09:00:00+00:00'): array
    {
        return [
            'externalEventKey' => sprintf('demo:%s:%s', $documentId, $stepKey),
            'processKey' => 'custom-scenario',
            'eventKey' => 'demo.step_completed',
            'stepKey' => $stepKey,
            'eventPhase' => 'after',
            'sourceSystem' => 'community-demo',
            'documentId' => $documentId,
            'documentUuid' => '20000000-0000-4000-8000-000000000001',
            'documentVersion' => 1,
            'actorRef' => 'demo-user',
            'occurredAt' => $occurredAt,
            'attributes' => [
                'category' => 'demo',
            ],
        ];
    }

    private function temporaryDemoDirectory(): string
    {
        $directory = sys_get_temp_dir().'/april-fixtures-'.bin2hex(random_bytes(8));
        mkdir($directory, 0777, true);

        return $directory;
    }

    private function templateResolver(string $templateDirectory): TemplateContextProviderResolver
    {
        $gateway = $this->createMock(AmagnoDocumentGateway::class);
        $gateway
            ->expects(self::never())
            ->method('fetchDocumentTags');

        return new TemplateMappedContextProviderResolver(
            new YamlProcessTemplateProvider($templateDirectory),
            new AmagnoFieldMapFactory(),
            new AmagnoContextProviderFactory($gateway, new AmagnoTagValueResolver(), new AmagnoTagDefinitionResolver($gateway))
        );
    }

    private function snapshotByExternalEventKey(InMemoryContextSnapshotStore $store, string $externalEventKey): \App\Intelligence\Domain\ContextSnapshot
    {
        foreach ($store->all() as $snapshot) {
            if ($snapshot->externalEventKey === $externalEventKey) {
                return $snapshot;
            }
        }

        self::fail(sprintf('Snapshot "%s" was not found.', $externalEventKey));
    }
}
