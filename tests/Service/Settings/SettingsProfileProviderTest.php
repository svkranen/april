<?php

namespace App\Tests\Service\Settings;

use App\Service\Settings\SettingsProfileProvider;
use PHPUnit\Framework\TestCase;

class SettingsProfileProviderTest extends TestCase
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

    public function testMissingFileReturnsEmptyProfileList(): void
    {
        $provider = new SettingsProfileProvider(sys_get_temp_dir().'/missing_april_matching_'.bin2hex(random_bytes(8)).'.json');

        self::assertSame([], $provider->profiles());
        self::assertSame([], $provider->mappingsByName());
    }

    public function testEmptyFileReturnsEmptyProfileList(): void
    {
        $provider = new SettingsProfileProvider($this->writeMatchingFile(''));

        self::assertSame([], $provider->profiles());
        self::assertSame([], $provider->mappingsByName());
    }

    public function testInvalidJsonReturnsEmptyProfileList(): void
    {
        $provider = new SettingsProfileProvider($this->writeMatchingFile('{invalid json'));

        self::assertSame([], $provider->profiles());
        self::assertSame([], $provider->mappingsByName());
    }

    public function testExistingProfilesAreReturnedWithNamesAndRawMappings(): void
    {
        $profiles = [
            'Rechnung' => [
                'sys' => 'onprem',
                'url' => 'https://amagno.example',
                'Kostenstelle' => 'tag-1',
                'Kostenstellegroup' => 'group-1',
            ],
            'Gutschrift' => [
                'sys' => 'onprem',
                'Betrag' => 'fix-value',
                'Betraggroup' => '1',
            ],
        ];
        $provider = new SettingsProfileProvider($this->writeMatchingFile(json_encode($profiles, JSON_THROW_ON_ERROR)));

        self::assertSame([
            [
                'name' => 'Rechnung',
                'mapping' => $profiles['Rechnung'],
            ],
            [
                'name' => 'Gutschrift',
                'mapping' => $profiles['Gutschrift'],
            ],
        ], $provider->profiles());
        self::assertSame($profiles, $provider->mappingsByName());
    }

    public function testExistingStructureRemainsUnchanged(): void
    {
        $profiles = [
            'Bestand' => [
                'sys' => 'onprem',
                'url' => 'https://old.example',
                'Betrag' => 'Altbetrag',
                'Betraggroup' => '2',
                'Betragmaxlen' => 12,
                'Betragfunc' => '[FORMAT][NUMBER][,][2]',
                'Stempel' => 'stamp-1',
                'nested' => [
                    'kept' => true,
                    'values' => ['a', 'b'],
                ],
            ],
        ];
        $path = $this->writeMatchingFile(json_encode($profiles, JSON_THROW_ON_ERROR));
        $provider = new SettingsProfileProvider($path);

        self::assertSame($profiles['Bestand'], $provider->profiles()[0]['mapping']);
        self::assertSame($profiles, json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR));
    }

    private function writeMatchingFile(string $content): string
    {
        $this->matchingFile = tempnam(sys_get_temp_dir(), 'april_profiles_') ?: null;
        self::assertNotNull($this->matchingFile);
        file_put_contents($this->matchingFile, $content);

        return $this->matchingFile;
    }
}
