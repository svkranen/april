<?php

namespace App\Tests\Intelligence\Architecture;

use PHPUnit\Framework\TestCase;

class DomainDependencyTest extends TestCase
{
    private const DOCUMENT_FETCHER_IMPORT = 'App\\Service\\Amagno\\DocumentFetcher';

    /**
     * @var array<int, string>
     */
    private const FORBIDDEN_IMPORT_PREFIXES = [
        'Symfony\\',
        'Doctrine\\',
        'App\\Command\\',
        'App\\Controller\\',
        'App\\Service\\Amagno\\',
        'App\\Intelligence\\Infrastructure\\',
        'App\\Intelligence\\Application\\',
        'App\\Intelligence\\Connector\\',
    ];

    public function testDomainDoesNotImportForbiddenLayers(): void
    {
        $violations = [];

        foreach ($this->domainFiles() as $file) {
            foreach ($this->importsFromFile($file) as $line => $import) {
                foreach (self::FORBIDDEN_IMPORT_PREFIXES as $forbiddenPrefix) {
                    if (str_starts_with($import, $forbiddenPrefix)) {
                        $violations[] = sprintf('%s:%d imports forbidden dependency %s', $file, $line, $import);
                    }
                }
            }
        }

        self::assertSame([], $violations, implode("\n", $violations));
    }

    public function testAmagnoContextProviderDoesNotImportDocumentFetcher(): void
    {
        $contextProvider = dirname(__DIR__, 3).'/src/Intelligence/Connector/Amagno/AmagnoContextProvider.php';
        $imports = $this->importsFromFile($contextProvider);
        $violations = [];

        foreach ($imports as $line => $import) {
            if ($import === self::DOCUMENT_FETCHER_IMPORT) {
                $violations[] = sprintf('%s:%d imports forbidden dependency %s', $contextProvider, $line, $import);
            }
        }

        self::assertSame([], $violations, implode("\n", $violations));
    }

    public function testOnlyDocumentFetcherGatewayImportsDocumentFetcherInAmagnoConnector(): void
    {
        $allowedFile = dirname(__DIR__, 3).'/src/Intelligence/Connector/Amagno/DocumentFetcherGateway.php';
        $violations = [];

        foreach ($this->amagnoConnectorFiles() as $file) {
            foreach ($this->importsFromFile($file) as $line => $import) {
                if ($import === self::DOCUMENT_FETCHER_IMPORT && $file !== $allowedFile) {
                    $violations[] = sprintf('%s:%d imports forbidden dependency %s', $file, $line, $import);
                }
            }
        }

        self::assertSame([], $violations, implode("\n", $violations));
    }

    public function testTemplateContextResolverDoesNotImportConcreteConnectors(): void
    {
        $resolver = dirname(__DIR__, 3).'/src/Intelligence/Infrastructure/Context/TemplateMappedContextProviderResolver.php';
        $violations = [];
        foreach ($this->importsFromFile($resolver) as $line => $import) {
            if (str_starts_with($import, 'App\\Intelligence\\Connector\\')
                || str_starts_with($import, 'App\\Service\\Amagno\\')
                || str_starts_with($import, 'Iileven\\AmagnoConnector\\')) {
                $violations[] = sprintf('%s:%d imports concrete connector %s', $resolver, $line, $import);
            }
        }

        self::assertSame([], $violations, implode("\n", $violations));
    }

    /**
     * @return array<int, string>
     */
    private function domainFiles(): array
    {
        $domainDirectory = dirname(__DIR__, 3).'/src/Intelligence/Domain';
        $files = glob($domainDirectory.'/*.php') ?: [];
        sort($files);

        return $files;
    }

    /**
     * @return array<int, string>
     */
    private function amagnoConnectorFiles(): array
    {
        $connectorDirectory = dirname(__DIR__, 3).'/src/Intelligence/Connector/Amagno';
        $files = glob($connectorDirectory.'/*.php') ?: [];
        sort($files);

        return $files;
    }

    /**
     * @return array<int, string>
     */
    private function importsFromFile(string $file): array
    {
        $imports = [];
        foreach (file($file, FILE_IGNORE_NEW_LINES) ?: [] as $index => $line) {
            if (preg_match('/^use\s+([^;]+);$/', trim($line), $matches) !== 1) {
                continue;
            }

            $imports[$index + 1] = trim($matches[1]);
        }

        return $imports;
    }
}
