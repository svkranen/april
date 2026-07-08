<?php

namespace App\Tests\Wizard;

use App\Wizard\WizardDefinitionException;
use App\Wizard\WizardDefinitionLoader;
use PHPUnit\Framework\TestCase;

final class WizardDefinitionLoaderTest extends TestCase
{
    public function testLoadsFirstInsightWizard(): void
    {
        $loader = new WizardDefinitionLoader(dirname(__DIR__, 2).'/config/april/wizards');

        $wizard = $loader->load('first-insight');

        self::assertSame('first-insight', $wizard->key);
        self::assertSame('1.0', $wizard->version);
        self::assertSame('First Insight', $wizard->name);
        self::assertSame(5, $wizard->stepCount());
        self::assertSame('welcome', $wizard->steps[0]->key);
        self::assertSame('Welcome to APRIL', $wizard->steps[0]->title);
    }

    public function testMissingRequiredFieldThrowsUnderstandableException(): void
    {
        $directory = $this->createDirectory([
            'broken.yaml' => "key: broken\nversion: '1.0'\nsteps:\n  - key: welcome\n    title: Welcome\n",
        ]);

        $this->expectException(WizardDefinitionException::class);
        $this->expectExceptionMessage('missing required field "name"');

        try {
            (new WizardDefinitionLoader($directory))->load('broken');
        } finally {
            $this->removeDirectory($directory);
        }
    }

    /**
     * @param array<string, string> $files
     */
    private function createDirectory(array $files): string
    {
        $directory = sys_get_temp_dir().'/april-wizard-loader-'.bin2hex(random_bytes(4));
        mkdir($directory);

        foreach ($files as $name => $content) {
            file_put_contents($directory.'/'.$name, $content);
        }

        return $directory;
    }

    private function removeDirectory(string $directory): void
    {
        foreach (glob($directory.'/*') ?: [] as $path) {
            unlink($path);
        }

        rmdir($directory);
    }
}
