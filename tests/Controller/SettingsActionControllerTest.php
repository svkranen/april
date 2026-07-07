<?php

namespace App\Tests\Controller;

use App\Controller\SettingsActionController;
use App\Service\Settings\LegacyMatchingSaveService;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class SettingsActionControllerTest extends WebTestCase
{
    private ?string $legacyDir = null;

    protected function tearDown(): void
    {
        if ($this->legacyDir !== null && is_dir($this->legacyDir)) {
            $this->removeDirectory($this->legacyDir);
        }

        $this->legacyDir = null;

        parent::tearDown();
    }

    public function testSavePhpStoresValidMatchingProfile(): void
    {
        $client = static::createClient();
        $this->replaceControllerWithTemporaryLegacyDir([
            'Existing' => [
                'sys' => 'onprem',
                'url' => 'https://old.example',
                'Kostenstelle' => 'ALT',
                'Kostenstellegroup' => '1',
            ],
        ]);

        $this->requestLegacySave($client, '/save.php', $this->validPayload([
            'profilename' => 'Neue Rechnung',
            'name1' => 'Kostenstelle',
            'fix1' => 'KST-100',
        ]));

        self::assertResponseIsSuccessful();
        self::assertSame(
            ['status' => 'newProfile', 'message' => 'Gespeichert.'],
            $this->responseSummary((string) $client->getResponse()->getContent())
        );

        $profiles = $this->matchingProfiles();
        self::assertArrayHasKey('Neue Rechnung', $profiles);
        self::assertSame('onprem', $profiles['Neue Rechnung']['sys']);
        self::assertSame('https://amagno.example', $profiles['Neue Rechnung']['url']);
        self::assertSame('KST-100', $profiles['Neue Rechnung']['Kostenstelle']);
        self::assertSame('1', $profiles['Neue Rechnung']['Kostenstellegroup']);
    }

    public function testSettingsRelativeSavePhpRejectsInvalidFunctionDefinition(): void
    {
        $client = static::createClient();
        $this->replaceControllerWithTemporaryLegacyDir([
            'Existing' => [
                'sys' => 'onprem',
                'url' => 'https://old.example',
                'Kostenstelle' => 'ALT',
            ],
        ]);

        $this->requestLegacySave($client, '/settings/save.php', $this->validPayload([
            'name1' => 'Kostenstelle',
            'fix1' => 'KST-100',
            'function1' => '1',
            'functiondef1' => '[INVALID]',
        ]));

        self::assertResponseIsSuccessful();
        self::assertSame(
            ['status' => 'error', 'message' => 'Fehler bei Funktion fuer Kostenstelle.'],
            $this->responseSummary((string) $client->getResponse()->getContent())
        );

        self::assertSame([
            'Existing' => [
                'sys' => 'onprem',
                'url' => 'https://old.example',
                'Kostenstelle' => 'ALT',
            ],
        ], $this->matchingProfiles());
    }

    public function testExistingMatchingJsonStructureRemainsCompatible(): void
    {
        $client = static::createClient();
        $existingProfile = [
            'sys' => 'onprem',
            'url' => 'https://old.example',
            'Betrag' => 'Altbetrag',
            'Betraggroup' => '2',
            'Betragmaxlen' => 12,
            'Betragfunc' => '[FORMAT][NUMBER][,][2]',
            'Stempel' => 'stamp-1',
        ];
        $this->replaceControllerWithTemporaryLegacyDir(['Bestand' => $existingProfile]);

        $this->requestLegacySave($client, '/settings/save.php', $this->validPayload([
            'profilename' => 'Neu',
            'name1' => 'Dokumentart',
            'fix1' => 'Rechnung',
        ]));

        self::assertResponseIsSuccessful();

        $profiles = $this->matchingProfiles();
        self::assertSame($existingProfile, $profiles['Bestand']);
        self::assertArrayHasKey('Neu', $profiles);
        self::assertSame('Rechnung', $profiles['Neu']['Dokumentart']);
    }

    public function testSavePhpDoesNotUseLegacyIncludeForNonOnPremSystem(): void
    {
        $client = static::createClient();
        $this->replaceControllerWithTemporaryLegacyDir([]);

        $this->requestLegacySave($client, '/save.php', $this->validPayload([
            'system' => 'cloud',
            'profilename' => 'Cloud Profil',
            'name1' => 'Kostenstelle',
            'fix1' => 'KST-CLOUD',
        ]));

        self::assertResponseIsSuccessful();
        self::assertSame(
            ['status' => 'newProfile', 'message' => 'Gespeichert.'],
            $this->responseSummary((string) $client->getResponse()->getContent())
        );

        $profiles = $this->matchingProfiles();
        self::assertSame('cloud', $profiles['Cloud Profil']['sys']);
        self::assertSame('KST-CLOUD', $profiles['Cloud Profil']['Kostenstelle']);
    }

    /**
     * @param array<string, mixed> $profiles
     */
    private function replaceControllerWithTemporaryLegacyDir(array $profiles): void
    {
        $this->legacyDir = sys_get_temp_dir().'/april_legacy_settings_'.bin2hex(random_bytes(8));
        mkdir($this->legacyDir);

        file_put_contents(
            $this->legacyDir.'/matching.json',
            json_encode($profiles, JSON_THROW_ON_ERROR)
        );

        static::getContainer()->set(
            SettingsActionController::class,
            new SettingsActionController(
                new LegacyMatchingSaveService($this->legacyDir.'/matching.json')
            )
        );
    }

    /**
     * @param array<string, string> $overrides
     * @return array<string, string>
     */
    private function validPayload(array $overrides = []): array
    {
        return array_replace([
            'system' => 'onprem',
            'profileselect' => '0',
            'profilename' => 'Profil',
            'aurl' => 'https://amagno.example',
            'name1' => 'Kostenstelle',
            'group1' => '1',
            'fix1' => 'KST-100',
            'tag1' => '',
            'maxlen1' => '',
            'function1' => '0',
            'functiondef1' => '',
        ], $overrides);
    }

    /**
     * @param array<string, string> $payload
     */
    private function requestLegacySave(KernelBrowser $client, string $uri, array $payload): void
    {
        $client->request('POST', $uri, $payload);
    }

    /**
     * @return array{status: string|null, message: string|null}
     */
    private function responseSummary(string $content): array
    {
        $data = json_decode($content, true, 512, JSON_THROW_ON_ERROR);

        return [
            'status' => $data['status'] ?? null,
            'message' => $data['message'] ?? null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function matchingProfiles(): array
    {
        self::assertNotNull($this->legacyDir);

        $content = file_get_contents($this->legacyDir.'/matching.json');

        return json_decode((string) $content, true, 512, JSON_THROW_ON_ERROR);
    }

    private function removeDirectory(string $directory): void
    {
        foreach (scandir($directory) ?: [] as $file) {
            if ($file === '.' || $file === '..') {
                continue;
            }

            $path = $directory.DIRECTORY_SEPARATOR.$file;
            if (is_dir($path)) {
                $this->removeDirectory($path);
                continue;
            }

            unlink($path);
        }

        rmdir($directory);
    }
}
