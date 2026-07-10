<?php

namespace App\Tests\Intelligence\Application;

use App\Intelligence\Application\ConnectorContextProviderFactoryRegistry;
use App\Intelligence\Application\ContextProviderWarningProvider;
use App\Intelligence\Application\ProcessTemplateProvider;
use App\Intelligence\Domain\DocumentRef;
use App\Intelligence\Domain\ProcessTemplate;
use App\Intelligence\Domain\ProcessTemplateConnector;
use App\Intelligence\Infrastructure\Context\TemplateMappedContextProviderResolver;
use App\Intelligence\Port\ConnectorContextProviderFactory;
use App\Intelligence\Port\ContextProvider;
use PHPUnit\Framework\TestCase;

final class ConnectorContextProviderFactoryRegistryTest extends TestCase
{
    public function testRegistrySelectsSupportingFactory(): void
    {
        $provider = new class implements ContextProvider {
            public function loadAttributes(DocumentRef $document, array $fields): array
            {
                return ['loaded' => true];
            }
        };
        $factory = new class($provider) implements ConnectorContextProviderFactory {
            public function __construct(private ContextProvider $provider)
            {
            }

            public function supports(string $connectorType, ?string $connectionName = null): bool
            {
                return $connectorType === 'example';
            }

            public function create(ProcessTemplate $template): ContextProvider
            {
                return $this->provider;
            }
        };
        $template = new ProcessTemplate('process', connector: new ProcessTemplateConnector('example'));

        self::assertSame($provider, (new ConnectorContextProviderFactoryRegistry([$factory]))->create($template, 'example'));
    }

    public function testUnsupportedExplicitConnectorBecomesUnavailableWarning(): void
    {
        $template = new ProcessTemplate(
            'process',
            contextProfileRequiredFields: ['amount'],
            connector: new ProcessTemplateConnector('missing', 'default')
        );
        $templates = new class($template) implements ProcessTemplateProvider {
            public function __construct(private ProcessTemplate $template)
            {
            }

            public function findByProcessKey(string $processKey): ?ProcessTemplate
            {
                return $processKey === $this->template->key ? $this->template : null;
            }
        };
        $selection = (new TemplateMappedContextProviderResolver(
            $templates,
            new ConnectorContextProviderFactoryRegistry()
        ))->resolve('process');

        self::assertNotNull($selection);
        self::assertInstanceOf(ContextProviderWarningProvider::class, $selection->contextProvider);
        self::assertSame(
            ['Context connector "missing" for process template "process" is not installed or supported.'],
            $selection->contextProvider->warnings()
        );
    }
}
