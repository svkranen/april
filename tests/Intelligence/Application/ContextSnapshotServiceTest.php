<?php

namespace App\Tests\Intelligence\Application;

use App\Intelligence\Application\TemplateContextProviderResolver;
use App\Intelligence\Application\ContextSnapshotService;
use App\Intelligence\Connector\Amagno\AmagnoContextProviderFactory;
use App\Intelligence\Connector\Amagno\AmagnoDocumentGateway;
use App\Intelligence\Connector\Amagno\AmagnoFieldMapFactory;
use App\Intelligence\Connector\Amagno\AmagnoTagValueResolver;
use App\Intelligence\Domain\ProcessEventRecord;
use App\Intelligence\Infrastructure\Context\InMemoryContextProfileProvider;
use App\Intelligence\Infrastructure\Context\InMemoryContextSnapshotStore;
use App\Intelligence\Infrastructure\Context\NullContextProvider;
use App\Intelligence\Infrastructure\Context\TemplateMappedContextProviderResolver;
use App\Intelligence\Infrastructure\Template\YamlProcessTemplateProvider;
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
            ->method('fetchDocumentTags')
            ->with('doc-123', null, null)
            ->willReturn([
                'singleLineStrings' => [
                    ['tagDefinitionId' => 'Eingang/Ausgang', 'value' => 'RE - Ausgang'],
                ],
                'numbers' => [
                    ['tagDefinitionId' => 'Nettobetrag', 'value' => 500000],
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

    private function event(): ProcessEventRecord
    {
        return new ProcessEventRecord(
            1,
            'evt-1',
            'amagno',
            'invoice-process',
            'received',
            'received',
            'doc-123',
            'uuid-123',
            2,
            'user-1',
            new DateTimeImmutable('2026-05-29T10:00:00+00:00'),
            new DateTimeImmutable('2026-05-29T10:00:01+00:00'),
            '{}',
            '{}'
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

    private function templateResolver(string $templateDirectory, ?AmagnoDocumentGateway $gateway = null): TemplateContextProviderResolver
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
            new AmagnoContextProviderFactory($gateway, new AmagnoTagValueResolver())
        );
    }
}
