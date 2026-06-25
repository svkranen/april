<?php

namespace App\Tests\Command;

use App\Command\IntelligenceAccessCheckDocumentCommand;
use App\Intelligence\Application\AccessProbeResult;
use App\Intelligence\Application\ProcessTemplateProvider;
use App\Intelligence\Domain\ProcessTemplate;
use App\Intelligence\Domain\ProcessTemplateAccessProbe;
use App\Intelligence\Domain\ProcessTemplateArrayFactory;
use App\Intelligence\Infrastructure\Access\InMemoryVisibilityCheckResultStore;
use App\Intelligence\Port\AccessProbeProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

class IntelligenceAccessCheckDocumentCommandTest extends TestCase
{
    public function testCommandOutputsExpectedVisibilityEvaluation(): void
    {
        $tester = new CommandTester(new IntelligenceAccessCheckDocumentCommand($this->provider($this->template())));

        $exitCode = $tester->execute([
            'processKey' => 'invoice',
            'documentUuid' => 'doc-1',
            '--step' => 'received',
            '--phase' => 'after',
            '--context' => '{"cost_center":"A"}',
            '--fake-visible-probes' => 'approval_location_a_today',
        ]);
        $display = $tester->getDisplay();

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertStringContainsString('route_to_location_approval', $display);
        self::assertStringContainsString('approval_location_a_today', $display);
        self::assertStringContainsString('external_today', $display);
        self::assertStringContainsString('ok', $display);
    }

    public function testCommandOutputsJson(): void
    {
        $tester = new CommandTester(new IntelligenceAccessCheckDocumentCommand($this->provider($this->template())));

        $exitCode = $tester->execute([
            'processKey' => 'invoice',
            'documentUuid' => 'doc-1',
            '--step' => 'received',
            '--phase' => 'after',
            '--context' => '{"cost_center":"A"}',
            '--fake-visible-probes' => 'approval_location_a_today',
            '--format' => 'json',
        ]);
        $data = json_decode($tester->getDisplay(), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertSame('doc-1', $data['documentUuid']);
        self::assertSame('approval_location_a_today', $data['results'][0]['probeKey']);
        self::assertSame('ok', $data['results'][0]['status']);
        self::assertSame('external_today', $data['results'][1]['probeKey']);
        self::assertSame('ok', $data['results'][1]['status']);
    }

    public function testCommandUsesInjectedProviderWithoutFakeOption(): void
    {
        $tester = new CommandTester(new IntelligenceAccessCheckDocumentCommand(
            $this->provider($this->amagnoTemplate()),
            [
                new class implements AccessProbeProvider {
                    public function supports(string $sourceSystem, string $type): bool
                    {
                        return $sourceSystem === 'amagno' && $type === 'amagno_magnet_documents';
                    }

                    public function evaluate(ProcessTemplateAccessProbe $probe, string $documentUuid): AccessProbeResult
                    {
                        return $probe->key === 'approval_location_a_today'
                            ? AccessProbeResult::visible(1, ['provider' => 'test'])
                            : AccessProbeResult::hidden(1, ['provider' => 'test']);
                    }
                },
            ]
        ));

        $exitCode = $tester->execute([
            'processKey' => 'invoice',
            'documentUuid' => 'doc-1',
            '--step' => 'received',
            '--phase' => 'after',
            '--context' => '{"cost_center":"A"}',
        ]);
        $display = $tester->getDisplay();

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertStringContainsString('approval_location_a_today', $display);
        self::assertStringContainsString('external_today', $display);
        self::assertStringContainsString('ok', $display);
    }

    public function testPersistOptionStoresResults(): void
    {
        $store = new InMemoryVisibilityCheckResultStore();
        $tester = new CommandTester(new IntelligenceAccessCheckDocumentCommand($this->provider($this->template()), [], $store));

        $exitCode = $tester->execute([
            'processKey' => 'invoice',
            'documentUuid' => 'doc-1',
            '--step' => 'received',
            '--phase' => 'after',
            '--context' => '{"cost_center":"A"}',
            '--fake-visible-probes' => 'approval_location_a_today',
            '--persist' => true,
            '--event-key' => 'evt-42',
            '--document-version' => '3',
        ]);

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertStringContainsString('Persisted visibility check results: 2', $tester->getDisplay());

        $records = $store->findByDocument('doc-1', 'invoice');
        self::assertCount(2, $records);

        $record = $records[0];
        self::assertSame('doc-1', $record->documentUuid);
        self::assertSame('invoice', $record->processKey);
        self::assertSame('received', $record->stepKey);
        self::assertSame('after', $record->eventPhase);
        self::assertSame('route_to_location_approval', $record->checkKey);
        self::assertSame('approval_location_a_today', $record->probeKey);
        self::assertSame('visible', $record->expected);
        self::assertSame('visible', $record->actual);
        self::assertSame('ok', $record->status);
        self::assertNull($record->reason);
        self::assertSame('fake', $record->sourceSystem);
        self::assertSame(3, $record->documentVersion);
    }

    public function testWithoutPersistNothingIsStored(): void
    {
        $store = new InMemoryVisibilityCheckResultStore();
        $tester = new CommandTester(new IntelligenceAccessCheckDocumentCommand($this->provider($this->template()), [], $store));

        $tester->execute([
            'processKey' => 'invoice',
            'documentUuid' => 'doc-1',
            '--step' => 'received',
            '--phase' => 'after',
            '--context' => '{"cost_center":"A"}',
            '--fake-visible-probes' => 'approval_location_a_today',
        ]);

        self::assertSame([], $store->findByDocument('doc-1'));
        self::assertStringNotContainsString('Persisted visibility check results', $tester->getDisplay());
    }

    public function testRepeatedManualDryRunsForSameDocumentAccumulate(): void
    {
        $store = new InMemoryVisibilityCheckResultStore();
        $command = new IntelligenceAccessCheckDocumentCommand($this->provider($this->template()), [], $store);

        $arguments = [
            'processKey' => 'invoice',
            'documentUuid' => 'doc-1',
            '--step' => 'received',
            '--phase' => 'after',
            '--context' => '{"cost_center":"A"}',
            '--fake-visible-probes' => 'approval_location_a_today',
            '--persist' => true,
        ];

        (new CommandTester($command))->execute($arguments);
        (new CommandTester($command))->execute($arguments);

        // No unique constraint blocks repeated manual checks without an event key.
        self::assertCount(4, $store->findByDocument('doc-1', 'invoice'));
    }

    public function testJsonOutputRemainsCompatibleWithPersist(): void
    {
        $store = new InMemoryVisibilityCheckResultStore();
        $tester = new CommandTester(new IntelligenceAccessCheckDocumentCommand($this->provider($this->template()), [], $store));

        $tester->execute([
            'processKey' => 'invoice',
            'documentUuid' => 'doc-1',
            '--step' => 'received',
            '--phase' => 'after',
            '--context' => '{"cost_center":"A"}',
            '--fake-visible-probes' => 'approval_location_a_today',
            '--format' => 'json',
            '--persist' => true,
        ]);
        $data = json_decode($tester->getDisplay(), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame('doc-1', $data['documentUuid']);
        self::assertTrue($data['persisted']);
        self::assertSame(2, $data['persistedCount']);
        self::assertSame('approval_location_a_today', $data['results'][0]['probeKey']);
        self::assertSame('ok', $data['results'][0]['status']);
    }

    public function testPersistFailsWhenStoreNotConfigured(): void
    {
        $tester = new CommandTester(new IntelligenceAccessCheckDocumentCommand($this->provider($this->template())));

        $exitCode = $tester->execute([
            'processKey' => 'invoice',
            'documentUuid' => 'doc-1',
            '--step' => 'received',
            '--phase' => 'after',
            '--context' => '{"cost_center":"A"}',
            '--fake-visible-probes' => 'approval_location_a_today',
            '--persist' => true,
        ]);

        self::assertSame(Command::FAILURE, $exitCode);
        self::assertStringContainsString('store is not configured', $tester->getDisplay());
    }

    private function template(): ProcessTemplate
    {
        return ProcessTemplateArrayFactory::fromArray([
            'key' => 'invoice',
            'source_system' => 'fake',
            'access_probes' => [
                'approval_location_a_today' => [
                    'type' => 'fake_document_visibility',
                ],
                'external_today' => [
                    'type' => 'fake_document_visibility',
                ],
            ],
            'visibility_check_profiles' => [
                'approval_location_a' => [
                    'expected_visible_in_probes' => ['approval_location_a_today'],
                    'expected_not_visible_in_probes' => ['external_today'],
                ],
            ],
            'visibility_profile_resolvers' => [
                'approval_location_by_context' => [
                    'field' => 'cost_center',
                    'map' => ['A' => 'approval_location_a'],
                ],
            ],
            'steps' => [
                [
                    'key' => 'received',
                    'after' => [
                        'visibility_checks' => [
                            [
                                'key' => 'route_to_location_approval',
                                'expected_profile_resolver' => 'approval_location_by_context',
                            ],
                        ],
                    ],
                ],
            ],
        ]);
    }

    private function amagnoTemplate(): ProcessTemplate
    {
        return ProcessTemplateArrayFactory::fromArray([
            'key' => 'invoice',
            'source_system' => 'amagno',
            'access_probes' => [
                'approval_location_a_today' => [
                    'type' => 'amagno_magnet_documents',
                    'magnet_id' => 1001,
                ],
                'external_today' => [
                    'type' => 'amagno_magnet_documents',
                    'magnet_id' => 1009,
                ],
            ],
            'visibility_check_profiles' => [
                'approval_location_a' => [
                    'expected_visible_in_probes' => ['approval_location_a_today'],
                    'expected_not_visible_in_probes' => ['external_today'],
                ],
            ],
            'visibility_profile_resolvers' => [
                'approval_location_by_context' => [
                    'field' => 'cost_center',
                    'map' => ['A' => 'approval_location_a'],
                ],
            ],
            'steps' => [
                [
                    'key' => 'received',
                    'after' => [
                        'visibility_checks' => [
                            [
                                'key' => 'route_to_location_approval',
                                'expected_profile_resolver' => 'approval_location_by_context',
                            ],
                        ],
                    ],
                ],
            ],
        ]);
    }

    private function provider(?ProcessTemplate $template): ProcessTemplateProvider
    {
        return new class($template) implements ProcessTemplateProvider {
            public function __construct(private readonly ?ProcessTemplate $template)
            {
            }

            public function findByProcessKey(string $processKey): ?ProcessTemplate
            {
                return $this->template;
            }
        };
    }
}
