<?php

namespace App\Tests\Intelligence\Architecture;

use PHPUnit\Framework\TestCase;

class DomainDependencyTest extends TestCase
{
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
