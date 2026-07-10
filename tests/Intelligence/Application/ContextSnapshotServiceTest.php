<?php

namespace App\Tests\Intelligence\Application;

use App\Intelligence\Application\TemplateContextProviderResolver;
use App\Intelligence\Application\ContextSnapshotService;
use App\Intelligence\Connector\Amagno\AmagnoContextProviderFactory;
use App\Intelligence\Connector\Amagno\AmagnoDocumentGateway;
use App\Intelligence\Connector\Amagno\AmagnoFieldMapFactory;
use App\Intelligence\Connector\Amagno\AmagnoTagDefinitionResolver;
use App\Intelligence\Connector\Amagno\AmagnoTagValueResolver;
use App\Intelligence\Domain\ProcessEventRecord;
use App\Intelligence\Infrastructure\Context\InMemoryContextProfileProvider;
use App\Intelligence\Infrastructure\Context\InMemoryContextSnapshotStore;
use App\Intelligence\Infrastructure\Context\NullContextProvider;
use App\Intelligence\Infrastructure\Context\TemplateMappedContextProviderResolver;
use App\Intelligence\Infrastructure\Template\YamlProcessTemplateProvider;
use App\Service\Amagno\ConnectionConfigLoader;
use App\Service\Amagno\ConnectionRegistry;
use App\Tests\Fake\RecordingContextProvider;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

class ContextSnapshotServiceTest extends TestCase
{
    public function testLoadsOnlyDefinedFieldsAndStoresSnapshot(): void
    {
        $contextProvider = new RecordingContextProvider([
            'amount' => 12000,
            'documentType' => 'Invoice',
            'ignored' => 'value',
        ]);
        $store = new InMemoryContextSnapshotStore();
        $service = new ContextSnapshotService(
            new InMemoryContextProfileProvider([
                'invoice-process' => ['amount', 'documentType'],
            ]),
            $contextProvider,
            $store
        );

        $result = $service->captureForEvent($this->event());

        self::assertSame(['amount', 'documentType'], $contextProvider->lastFields);
        self::assertSame('amagno', $contextProvider->lastDocument?->sourceSystem);
        self::assertSame('doc-123', $contextProvider->lastDocument?->externalId);
        self::assertSame('uuid-123', $contextProvider->lastDocument?->externalUuid);
        self::assertSame(2, $contextProvider->lastDocument?->version);
        self::assertSame(1, $store->count());
        self::assertSame([
            'amount' => 12000,
            'documentType' => 'Invoice',
        ], $result->snapshot->attributes);
        self::assertSame([], $result->warnings);
        self::assertSame('invoice-process', $result->snapshot->processKey);
        self::assertSame('evt-1', $result->snapshot->externalEventKey);
    }

    public function testMissingRequiredFieldIsWarning(): void
    {
        $service = new ContextSnapshotService(
            new InMemoryContextProfileProvider([
                'invoice-process' => ['amount', 'costCenter'],
            ]),
            new RecordingContextProvider([
                'amount' => 12000,
            ]),
            new InMemoryContextSnapshotStore()
        );

        $result = $service->captureForEvent($this->event());

        self::assertSame(['Missing required context field "costCenter".'], $result->warnings);
        self::assertSame(['Missing required context field "costCenter".'], $result->snapshot->warnings);
        self::assertSame(['amount' => 12000], $result->snapshot->attributes);
    }

    public function testTemplateFieldMappingUsesAmagnoContextProvider(): void
    {
        $templateDirectory = $this->templateDirectory([
            'invoice-process.yaml' => <<<'YAML'
key: invoice-process
context_profile:
  required:
    - invoice_direction
    - amount_net
field_mapping:
  invoice_direction:
    source: amagno
    tag_name: "Eingang/Ausgang"
  amount_net:
    source: amagno
    tag_name: "Nettobetrag"
    value_type: number
YAML,
        ]);

        $gateway = $this->createMock(AmagnoDocumentGateway::class);
        $gateway
            ->expects(self::once())
            ->method('fetchTagDefinitions')
            ->with(null, null, null)
            ->willReturn([
                'selectionDefinitions' => [
                    ['id' => 'direction-tag-id', 'caption' => 'Eingang/Ausgang'],
                ],
                'numberDefinitions' => [
                    ['id' => 'amount-tag-id', 'caption' => 'Nettobetrag'],
                ],
            ]);
        $gateway
            ->expects(self::once())
            ->method('fetchDocumentTags')
            ->with('uuid-123', null, null)
            ->willReturn([
                'singleLineStrings' => [
                    ['tagDefinitionId' => 'direction-tag-id', 'value' => 'RE - Ausgang'],
                ],
                'numbers' => [
                    ['tagDefinitionId' => 'amount-tag-id', 'value' => 500000],
                ],
            ]);
        $gateway
            ->expects(self::never())
            ->method('fetchSelectionNode');

        $fallbackProvider = new RecordingContextProvider(['fallback' => 'unused']);
        $service = new ContextSnapshotService(
            new InMemoryContextProfileProvider([
                'invoice-process' => ['fallback'],
            ]),
            $fallbackProvider,
            new InMemoryContextSnapshotStore(),
            $this->templateResolver($templateDirectory, $gateway)
        );

        $result = $service->captureForEvent($this->event());

        self::assertNull($fallbackProvider->lastFields);
        self::assertSame(
            [
                'invoice_direction' => 'RE - Ausgang',
                'amount_net' => 50.0,
            ],
            $result->snapshot->attributes
        );
        self::assertSame([], $result->warnings);
    }

    public function testTemplateConnectorConnectionProvidesBaseUriAndCredentialId(): void
    {
        $templateDirectory = $this->templateDirectory([
            'invoice-process.yaml' => <<<'YAML'
key: invoice-process
connector:
  type: amagno
  connection: default
context_profile:
  required:
    - invoice_direction
field_mapping:
  invoice_direction:
    source: amagno
    tag_name: "Eingang/Ausgang"
YAML,
        ]);

        $gateway = $this->createMock(AmagnoDocumentGateway::class);
        $gateway
            ->expects(self::once())
            ->method('fetchTagDefinitions')
            ->with(null, 'https://amagno.example/api/v2', 7)
            ->willReturn([
                'selectionDefinitions' => [
                    ['id' => 'direction-tag-id', 'caption' => 'Eingang/Ausgang'],
                ],
            ]);
        $gateway
            ->expects(self::once())
            ->method('fetchDocumentTags')
            ->with('uuid-123', null, 'https://amagno.example/api/v2', 7)
            ->willReturn([
                'singleLineStrings' => [
                    ['tagDefinitionId' => 'direction-tag-id', 'value' => 'RE - Ausgang'],
                ],
            ]);

        $service = new ContextSnapshotService(
            new InMemoryContextProfileProvider([
                'invoice-process' => ['fallback'],
            ]),
            new NullContextProvider(),
            new InMemoryContextSnapshotStore(),
            $this->templateResolver($templateDirectory, $gateway, $this->connectionRegistry())
        );

        $result = $service->captureForEvent($this->event());

        self::assertSame(['invoice_direction' => 'RE - Ausgang'], $result->snapshot->attributes);
        self::assertSame([], $result->warnings);
    }

    public function testTemplateWithoutConnectorUsesDefaultConnectionWhenAvailable(): void
    {
        $templateDirectory = $this->templateDirectory([
            'invoice-process.yaml' => <<<'YAML'
key: invoice-process
context_profile:
  required:
    - invoice_direction
field_mapping:
  invoice_direction:
    source: amagno
    tag_name: "Eingang/Ausgang"
YAML,
        ]);

        $gateway = $this->createMock(AmagnoDocumentGateway::class);
        $gateway
            ->expects(self::once())
            ->method('fetchTagDefinitions')
            ->with(null, 'https://amagno.example/api/v2', 7)
            ->willReturn([
                'selectionDefinitions' => [
                    ['id' => 'direction-tag-id', 'caption' => 'Eingang/Ausgang'],
                ],
            ]);
        $gateway
            ->expects(self::once())
            ->method('fetchDocumentTags')
            ->with('uuid-123', null, 'https://amagno.example/api/v2', 7)
            ->willReturn([
                'singleLineStrings' => [
                    ['tagDefinitionId' => 'direction-tag-id', 'value' => 'RE - Ausgang'],
                ],
            ]);

        $service = new ContextSnapshotService(
            new InMemoryContextProfileProvider([
                'invoice-process' => ['fallback'],
            ]),
            new NullContextProvider(),
            new InMemoryContextSnapshotStore(),
            $this->templateResolver($templateDirectory, $gateway, $this->connectionRegistry())
        );

        $result = $service->captureForEvent($this->event());

        self::assertSame(['invoice_direction' => 'RE - Ausgang'], $result->snapshot->attributes);
        self::assertSame([], $result->warnings);
    }

    public function testMissingTemplateConnectorConnectionIsVisibleInSnapshotWarnings(): void
    {
        $templateDirectory = $this->templateDirectory([
            'invoice-process.yaml' => <<<'YAML'
key: invoice-process
connector:
  type: amagno
  connection: missing
context_profile:
  required:
    - invoice_direction
field_mapping:
  invoice_direction:
    source: amagno
    tag_name: "Eingang/Ausgang"
YAML,
        ]);

        $gateway = $this->createMock(AmagnoDocumentGateway::class);
        $gateway
            ->expects(self::never())
            ->method('fetchDocumentTags');

        $service = new ContextSnapshotService(
            new InMemoryContextProfileProvider([
                'invoice-process' => ['invoice_direction'],
            ]),
            new NullContextProvider(),
            new InMemoryContextSnapshotStore(),
            $this->templateResolver($templateDirectory, $gateway, $this->connectionRegistry())
        );

        $result = $service->captureForEvent($this->event());

        self::assertSame([], $result->snapshot->attributes);
        self::assertSame([
            'Amagno context provider is unavailable for process template "invoice-process" and connection "missing".',
            'Missing required context field "invoice_direction".',
        ], $result->warnings);
    }

    public function testMissingDocumentUuidProducesMissingContextWarningWithoutFetchingTags(): void
    {
        $templateDirectory = $this->templateDirectory([
            'invoice-process.yaml' => <<<'YAML'
key: invoice-process
context_profile:
  required:
    - amount_net
field_mapping:
  amount_net:
    source: amagno
    tag_id: "amount-tag-id"
YAML,
        ]);

        $gateway = $this->createMock(AmagnoDocumentGateway::class);
        $gateway
            ->expects(self::never())
            ->method('fetchDocumentTags');

        $service = new ContextSnapshotService(
            new InMemoryContextProfileProvider([
                'invoice-process' => ['fallback'],
            ]),
            new NullContextProvider(),
            new InMemoryContextSnapshotStore(),
            $this->templateResolver($templateDirectory, $gateway)
        );

        $result = $service->captureForEvent($this->event(null));

        self::assertSame([], $result->snapshot->attributes);
        self::assertSame(['Missing required context field "amount_net".'], $result->warnings);
    }

    public function testUnknownAmagnoTagNameIsStoredAsSnapshotWarning(): void
    {
        $templateDirectory = $this->templateDirectory([
            'invoice-process.yaml' => <<<'YAML'
key: invoice-process
context_profile:
  required:
    - amount_net
field_mapping:
  amount_net:
    source: amagno
    tag_name: "Nettobetrag"
YAML,
        ]);

        $gateway = $this->createMock(AmagnoDocumentGateway::class);
        $gateway
            ->expects(self::once())
            ->method('fetchTagDefinitions')
            ->willReturn(['numberDefinitions' => []]);
        $gateway
            ->expects(self::never())
            ->method('fetchDocumentTags');

        $service = new ContextSnapshotService(
            new InMemoryContextProfileProvider([
                'invoice-process' => ['fallback'],
            ]),
            new NullContextProvider(),
            new InMemoryContextSnapshotStore(),
            $this->templateResolver($templateDirectory, $gateway)
        );

        $result = $service->captureForEvent($this->event());

        self::assertSame([], $result->snapshot->attributes);
        self::assertSame(
            [
                'Unknown Amagno tag_name "Nettobetrag".',
                'Missing required context field "amount_net".',
            ],
            $result->warnings
        );
    }

    public function testTemplateWithoutFieldMappingFallsBackToNullContextProvider(): void
    {
        $templateDirectory = $this->templateDirectory([
            'invoice-process.yaml' => <<<'YAML'
key: invoice-process
context_profile:
  required:
    - amount_net
YAML,
        ]);

        $service = new ContextSnapshotService(
            new InMemoryContextProfileProvider([
                'invoice-process' => ['amount_net'],
            ]),
            new NullContextProvider(),
            new InMemoryContextSnapshotStore(),
            $this->templateResolver($templateDirectory)
        );

        $result = $service->captureForEvent($this->event());

        self::assertSame([], $result->snapshot->attributes);
        self::assertSame(['Missing required context field "amount_net".'], $result->warnings);
    }

    public function testTemplateEventContextFieldMappingUsesInlineEventAttributes(): void
    {
        $templateDirectory = $this->templateDirectory([
            'invoice-process.yaml' => <<<'YAML'
key: invoice-process
context_profile:
  required:
    - amount_net
    - invoice_direction
field_mapping:
  amount_net:
    source: event_context
    value_type: number
    stability: snapshot_required
  invoice_direction:
    source: event_context
    value_type: string
    stability: snapshot_required
context_policy:
  snapshot:
    max_delay_seconds: 300
    stale_behavior: uncertain
YAML,
        ]);

        $fallbackProvider = new RecordingContextProvider(['fallback' => 'unused']);
        $service = new ContextSnapshotService(
            new InMemoryContextProfileProvider([]),
            $fallbackProvider,
            new InMemoryContextSnapshotStore(),
            $this->templateResolver($templateDirectory)
        );

        $result = $service->captureForEvent($this->eventWithInlineAttributes([
            'amount_net' => 120.5,
            'invoice_direction' => 'outbound',
            'ignored' => 'not in profile',
        ]));

        self::assertSame(0, $fallbackProvider->calls);
        self::assertNull($fallbackProvider->lastFields);
        self::assertSame([
            'amount_net' => 120.5,
            'invoice_direction' => 'outbound',
        ], $result->snapshot->attributes);
        self::assertSame([], $result->warnings);
        self::assertSame(0, $result->snapshot->freshnessSeconds);
        self::assertTrue($result->snapshot->isFreshForDecisionCheck);
    }

    public function testUnknownProcessKeyFallsBackToNullContextProvider(): void
    {
        $service = new ContextSnapshotService(
            new InMemoryContextProfileProvider([
                'invoice-process' => ['amount_net'],
            ]),
            new NullContextProvider(),
            new InMemoryContextSnapshotStore(),
            $this->templateResolver($this->templateDirectory([]))
        );

        $result = $service->captureForEvent($this->event());

        self::assertSame([], $result->snapshot->attributes);
        self::assertSame(['Missing required context field "amount_net".'], $result->warnings);
    }

    private function event(?string $documentUuid = 'uuid-123'): ProcessEventRecord
    {
        return new ProcessEventRecord(
            1,
            'evt-1',
            'amagno',
            'invoice-process',
            'received',
            'received',
            'doc-123',
            $documentUuid,
            2,
            'user-1',
            new DateTimeImmutable('2026-05-29T10:00:00+00:00'),
            new DateTimeImmutable('2026-05-29T10:00:01+00:00'),
            '{}',
            '{}'
        );
    }

    /**
     * @param array<string, mixed> $attributes
     */
    private function eventWithInlineAttributes(array $attributes): ProcessEventRecord
    {
        return new ProcessEventRecord(
            1,
            'evt-1',
            'community-demo',
            'invoice-process',
            'received',
            'received',
            'doc-123',
            'uuid-123',
            1,
            'user-1',
            new DateTimeImmutable('2026-05-29T10:00:00+00:00'),
            new DateTimeImmutable('2026-05-29T10:00:01+00:00'),
            json_encode(['attributes' => $attributes], JSON_THROW_ON_ERROR),
            json_encode(['attributes' => $attributes], JSON_THROW_ON_ERROR)
        );
    }

    /**
     * @param array<string, string> $files
     */
    private function templateDirectory(array $files): string
    {
        $directory = sys_get_temp_dir().'/amagno-template-provider-'.bin2hex(random_bytes(6));
        mkdir($directory, 0775, true);
        foreach ($files as $filename => $contents) {
            file_put_contents($directory.'/'.$filename, $contents);
        }

        return $directory;
    }

    private function templateResolver(string $templateDirectory, ?AmagnoDocumentGateway $gateway = null, ?ConnectionRegistry $connectionRegistry = null): TemplateContextProviderResolver
    {
        if ($gateway === null) {
            $gateway = $this->createMock(AmagnoDocumentGateway::class);
            $gateway
                ->expects(self::never())
                ->method('fetchDocumentTags');
        }

        return new TemplateMappedContextProviderResolver(
            new YamlProcessTemplateProvider($templateDirectory),
            new AmagnoFieldMapFactory(),
            new AmagnoContextProviderFactory($gateway, new AmagnoTagValueResolver(), new AmagnoTagDefinitionResolver($gateway)),
            $connectionRegistry
        );
    }

    private function connectionRegistry(): ConnectionRegistry
    {
        $path = sys_get_temp_dir().'/amagno-connections-'.bin2hex(random_bytes(6)).'.json';
        file_put_contents($path, json_encode([
            'credentials' => [
                [
                    'cid' => 7,
                    'base_uri' => 'https://amagno.example',
                    'username' => 'user',
                    'password' => 'password',
                ],
            ],
            'configurations' => [
                [
                    'id' => 'default',
                    'credential_id' => 7,
                    'vault_id' => 'vault-1',
                    'magnet_id' => 'magnet-1',
                ],
            ],
        ], JSON_THROW_ON_ERROR));

        return new ConnectionRegistry(new ConnectionConfigLoader($path));
    }
}
