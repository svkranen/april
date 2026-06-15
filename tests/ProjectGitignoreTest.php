<?php

namespace App\Tests;

use PHPUnit\Framework\TestCase;

class ProjectGitignoreTest extends TestCase
{
    public function testGeneratedDocsAreIgnored(): void
    {
        $gitignore = file_get_contents(__DIR__.'/../.gitignore');

        self::assertIsString($gitignore);
        self::assertStringContainsString('/docs/generated/', $gitignore);
    }
}
