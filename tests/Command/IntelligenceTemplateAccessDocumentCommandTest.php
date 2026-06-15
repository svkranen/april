<?php

namespace App\Tests\Command;

use App\Command\IntelligenceTemplateAccessDocumentCommand;
use App\Intelligence\Application\AccessDocumentationMarkdownRenderer;
use App\Intelligence\Application\ProcessTemplateProvider;
use App\Intelligence\Domain\ProcessTemplate;
use App\Intelligence\Domain\ProcessTemplateArrayFactory;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

class IntelligenceTemplateAccessDocumentCommandTest extends TestCase
{
    public function testRendersMarkdownDocumentationWithAutomaticChecksAndManualTestDetails(): void
    {
        $tester = new CommandTester(new IntelligenceTemplateAccessDocumentCommand(
            $this->provider($this->template()),
            new AccessDocumentationMarkdownRenderer()
        ));

        $exitCode = $tester->execute(['processKey' => 'invoice']);
        $display = $tester->getDisplay();

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertStringContainsString('# Access-/Visibility-Dokumentation: invoice', $display);
        self::assertStringContainsString('sourceSystem: `amagno`', $display);
        self::assertStringContainsString('## Access-Probes', $display);
        self::assertStringContainsString('### `approval_location_a_today`', $display);
        self::assertStringContainsString('type: `amagno_magnet_documents`', $display);
        self::assertStringContainsString('## Step-nahe Visibility-Checks', $display);
        self::assertStringContainsString('### `received` / `after` / `route_to_location_approval`', $display);
        self::assertStringContainsString('expectedProfileResolver: `approval_location_by_context`', $display);
        self::assertStringContainsString('## Manual Access Tests', $display);
        self::assertStringContainsString('Freigeberbezogene Sichtbarkeit', $display);
        self::assertStringContainsString('Freigeber duerfen nur eigene Dokumente sehen.', $display);
        self::assertStringContainsString('1. Benutzer A pruefen.', $display);
        self::assertStringContainsString('2. Benutzer B pruefen.', $display);
        self::assertStringContainsString('- A sieht das Dokument.', $display);
        self::assertStringContainsString('- B sieht es nicht.', $display);
        self::assertStringContainsString('evidenceRequired: Screenshot oder Pruefvermerk', $display);
    }

    public function testExplicitMarkdownFormatRemainsCompatible(): void
    {
        $tester = new CommandTester(new IntelligenceTemplateAccessDocumentCommand(
            $this->provider($this->template()),
            new AccessDocumentationMarkdownRenderer()
        ));

        $exitCode = $tester->execute([
            'processKey' => 'invoice',
            '--format' => 'markdown',
        ]);
        $display = $tester->getDisplay();

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertStringStartsWith('# Access-/Visibility-Dokumentation: invoice', $display);
        self::assertStringNotContainsString('<!doctype html>', $display);
    }

    public function testRendersHtmlDocumentationWithEscapedContent(): void
    {
        $tester = new CommandTester(new IntelligenceTemplateAccessDocumentCommand(
            $this->provider($this->templateWithSpecialCharacters()),
            new AccessDocumentationMarkdownRenderer()
        ));

        $exitCode = $tester->execute([
            'processKey' => 'invoice',
            '--format' => 'html',
        ]);
        $display = $tester->getDisplay();

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertStringContainsString('<!doctype html>', $display);
        self::assertStringContainsString('<meta charset="utf-8">', $display);
        self::assertStringContainsString('<h2>Access-Probes</h2>', $display);
        self::assertStringContainsString('approval_location_a_today', $display);
        self::assertStringContainsString('<h2>Manual Access Tests</h2>', $display);
        self::assertStringContainsString('Freigeberbezogene Sichtbarkeit', $display);
        self::assertStringContainsString('Benutzer &lt;A&gt; pruefen &amp; dokumentieren.', $display);
        self::assertStringContainsString('A sieht &quot;sein&quot; Dokument.', $display);
        self::assertStringContainsString('Screenshot &amp; Pruefvermerk &lt;PDF&gt;', $display);
        self::assertStringNotContainsString('Benutzer <A> pruefen & dokumentieren.', $display);
    }

    public function testWritesMarkdownDocumentationToOutputFile(): void
    {
        $tester = new CommandTester(new IntelligenceTemplateAccessDocumentCommand(
            $this->provider($this->template()),
            new AccessDocumentationMarkdownRenderer()
        ));
        $path = sys_get_temp_dir().'/access-document-'.bin2hex(random_bytes(4)).'/invoice.md';

        $exitCode = $tester->execute([
            'processKey' => 'invoice',
            '--output' => $path,
        ]);

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertFileExists($path);
        self::assertStringContainsString('# Access-/Visibility-Dokumentation: invoice', (string) file_get_contents($path));

        unlink($path);
        rmdir(dirname($path));
    }

    public function testWritesHtmlDocumentationToOutputFile(): void
    {
        $tester = new CommandTester(new IntelligenceTemplateAccessDocumentCommand(
            $this->provider($this->template()),
            new AccessDocumentationMarkdownRenderer()
        ));
        $path = sys_get_temp_dir().'/access-document-'.bin2hex(random_bytes(4)).'/invoice.html';

        $exitCode = $tester->execute([
            'processKey' => 'invoice',
            '--format' => 'html',
            '--output' => $path,
        ]);

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertFileExists($path);
        self::assertStringContainsString('<!doctype html>', (string) file_get_contents($path));

        unlink($path);
        rmdir(dirname($path));
    }

    private function template(): ProcessTemplate
    {
        return ProcessTemplateArrayFactory::fromArray([
            'key' => 'invoice',
            'source_system' => 'amagno',
            'access_probes' => [
                'approval_location_a_today' => [
                    'type' => 'amagno_magnet_documents',
                    'magnet_id' => 1001,
                    'max_documents' => 500,
                    'description' => 'Freigabe Standort A',
                ],
                'external_today' => [
                    'type' => 'amagno_magnet_documents',
                    'magnet_id' => 1009,
                    'max_documents' => 200,
                    'description' => 'Externe Sichtbarkeit',
                ],
            ],
            'visibility_check_profiles' => [
                'approval_location_a' => [
                    'expected_visible_in_probes' => ['approval_location_a_today'],
                    'expected_not_visible_in_probes' => ['external_today'],
                ],
            ],
            'visibility_profile_resolvers' => [
                'approval_location_by_context' => [
                    'field' => 'standort',
                    'map' => [
                        'A' => 'approval_location_a',
                    ],
                ],
            ],
            'manual_access_tests' => [
                [
                    'key' => 'approver_scope_test',
                    'title' => 'Freigeberbezogene Sichtbarkeit',
                    'description' => 'Freigeber duerfen nur eigene Dokumente sehen.',
                    'test_procedure' => ['Benutzer A pruefen.', 'Benutzer B pruefen.'],
                    'expected_result' => ['A sieht das Dokument.', 'B sieht es nicht.'],
                    'evidence_required' => 'Screenshot oder Pruefvermerk',
                    'frequency' => 'quartalsweise',
                ],
            ],
            'steps' => [
                [
                    'key' => 'received',
                    'after' => [
                        'visibility_checks' => [
                            [
                                'key' => 'route_to_location_approval',
                                'expected_profile_resolver' => 'approval_location_by_context',
                                'retry_policy' => 'amagno_today_magnets',
                            ],
                        ],
                    ],
                ],
            ],
        ]);
    }

    private function templateWithSpecialCharacters(): ProcessTemplate
    {
        return ProcessTemplateArrayFactory::fromArray([
            'key' => 'invoice',
            'source_system' => 'amagno',
            'access_probes' => [
                'approval_location_a_today' => [
                    'type' => 'amagno_magnet_documents',
                    'magnet_id' => 1001,
                    'max_documents' => 500,
                    'description' => 'Freigabe <Standort A> & intern',
                ],
            ],
            'visibility_check_profiles' => [
                'approval_location_a' => [
                    'expected_visible_in_probes' => ['approval_location_a_today'],
                ],
            ],
            'visibility_profile_resolvers' => [
                'approval_location_by_context' => [
                    'field' => 'standort',
                    'map' => [
                        '<A&1>' => 'approval_location_a',
                    ],
                ],
            ],
            'manual_access_tests' => [
                [
                    'key' => 'approver_scope_test',
                    'title' => 'Freigeberbezogene Sichtbarkeit',
                    'description' => 'Freigeber <Admin> duerfen nur eigene Dokumente sehen.',
                    'test_procedure' => ['Benutzer <A> pruefen & dokumentieren.'],
                    'expected_result' => ['A sieht "sein" Dokument.'],
                    'evidence_required' => 'Screenshot & Pruefvermerk <PDF>',
                    'frequency' => 'quartalsweise',
                ],
            ],
            'steps' => [
                [
                    'key' => 'received',
                    'after' => [
                        'visibility_checks' => [
                            [
                                'key' => 'route_to_location_approval',
                                'expected_profile_resolver' => 'approval_location_by_context',
                            ],
                        ],
                    ],
                ],
            ],
        ]);
    }

    private function provider(?ProcessTemplate $template): ProcessTemplateProvider
    {
        return new class($template) implements ProcessTemplateProvider {
            public function __construct(private readonly ?ProcessTemplate $template)
            {
            }

            public function findByProcessKey(string $processKey): ?ProcessTemplate
            {
                return $this->template;
            }
        };
    }
}
