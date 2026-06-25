<?php

namespace App\Tests\Architecture;

use FilesystemIterator;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * Architecture guard: controllers must consume Application services only,
 * never console commands. Keeps the web layer and the CLI as separate adapters.
 */
class ControllersDoNotUseCommandsTest extends TestCase
{
    public function testNoControllerReferencesConsoleCommands(): void
    {
        $controllerDir = dirname(__DIR__, 2).'/src/Controller';
        self::assertDirectoryExists($controllerDir);

        $violations = [];
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($controllerDir, FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            $source = (string) file_get_contents($file->getPathname());
            if (str_contains($source, 'App\\Command\\')
                || str_contains($source, 'Symfony\\Component\\Console')) {
                $violations[] = $file->getPathname();
            }
        }

        self::assertSame(
            [],
            $violations,
            "Controllers must not reference console commands; offending files:\n".implode("\n", $violations)
        );
    }
}
