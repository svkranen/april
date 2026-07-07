<?php

namespace App\Tests\Service\Settings;

use App\Service\Settings\LegacyMatchingSaveService;
use PHPUnit\Framework\TestCase;

class LegacyMatchingSaveServiceTest extends TestCase
{
    private ?string $matchingFile = null;

    protected function tearDown(): void
    {
        if ($this->matchingFile !== null && is_file($this->matchingFile)) {
            unlink($this->matchingFile);
        }

        $this->matchingFile = null;

        parent::tearDown();
    }

    public function testExistingProfileCanBeUpdatedWithLegacyResponseShape(): void
    {
        $this->matchingFile = tempnam(sys_get_temp_dir(), 'april_matching_') ?: null;
        self::assertNotNull($this->matchingFile);
        file_put_contents($this->matchingFile, json_encode([
            'Bestand' => [
                'sys' => 'onprem',
                'url' => 'https://old.example',
                'Feld' => 'Alt',
            ],
        ], JSON_THROW_ON_ERROR));

        $service = new LegacyMatchingSaveService($this->matchingFile);
        $response = $service->save([
            'system' => 'onprem',
            'profileselect' => 'Bestand',
            'profilename' => '',
            'aurl' => 'https://amagno.example',
            'name1' => 'Feld',
            'group1' => '1',
            'fix1' => 'Neu',
            'tag1' => '',
            'maxlen1' => '',
            'function1' => '0',
            'functiondef1' => '',
        ]);

        self::assertSame([
            'status' => 'ok',
            'message' => 'Gespeichert.',
        ], json_decode($response, true, 512, JSON_THROW_ON_ERROR));

        $profiles = json_decode((string) file_get_contents($this->matchingFile), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('Neu', $profiles['Bestand']['Feld']);
        self::assertSame('1', $profiles['Bestand']['Feldgroup']);
        self::assertSame('onprem', $profiles['Bestand']['sys']);
    }
}
