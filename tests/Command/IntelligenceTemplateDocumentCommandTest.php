<?php

namespace App\Tests\Command;

use App\Command\IntelligenceTemplateDocumentCommand;
use App\Intelligence\Application\HtmlProcessTemplateDocumentationRenderer;
use App\Intelligence\Application\MarkdownProcessTemplateDocumentationRenderer;
use App\Intelligence\Application\ProcessTemplateDocumentationBuilder;
use App\Intelligence\Application\ProcessTemplateProvider;
use App\Intelligence\Domain\ProcessTemplate;
use App\Intelligence\Domain\ProcessTemplateArrayFactory;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Clock\MockClock;

final class IntelligenceTemplateDocumentCommandTest extends TestCase
{
    public function testMarkdownContainsProcessDecisionsAccessAndControlPhases(): void
    {
        $tester = $this->tester($this->fullTemplate());

        self::assertSame(Command::SUCCESS, $tester->execute(['processKey' => 'invoice']));
        $display = $tester->getDisplay();

        self::assertStringContainsString('## Prozessschritte', $display);
        self::assertStringContainsString('`received` — Eingang', $display);
        self::assertStringContainsString('## Decision Points', $display);
        self::assertStringContainsString('Wenn `amount` `gt` `1000`, dann step `approve`.', $display);
        self::assertStringContainsString('## Access-/Visibility Summary', $display);
        self::assertStringContainsString('### Coverage Summary', $display);
        self::assertStringContainsString('before/after sind Kontrollphasen', $display);
        self::assertStringContainsString('after-Kontrollphasen: `visible_after_receive`', $display);
        self::assertSame(1, substr_count($display, '### `received`'));
        self::assertStringNotContainsString('### `visible_after_receive`', $display);
    }

    public function testHtmlIsStandaloneAndEscapesDynamicContent(): void
    {
        $tester = $this->tester(ProcessTemplateArrayFactory::fromArray([
            'key' => 'invoice',
            'name' => 'Invoice <script>alert("x")</script> & audit',
            'steps' => [
                [
                    'key' => 'received<meta>',
                    'name' => '<Receive & check>',
                    'after' => ['visibility_checks' => [[
                        'key' => '<visibility>',
                        'expected_profile' => 'profile&one',
                    ]]],
                ],
                ['key' => 'approve&archive'],
            ],
            'decision_points' => [[
                'key' => '<amount-route>',
                'after' => 'received<meta>',
                'required_fields' => ['amount&tax'],
                'rules' => [['when' => ['amount&tax' => ['gt' => '<1000>']], 'expect_next' => 'approve&archive']],
            ]],
            'access_probes' => ['probe<script>' => ['type' => 'generic&safe', 'description' => '<internal>']],
        ]));

        self::assertSame(Command::SUCCESS, $tester->execute(['processKey' => 'invoice', '--format' => 'html']));
        $display = $tester->getDisplay();
        self::assertStringStartsWith('<!doctype html>', $display);
        self::assertStringContainsString('<meta charset="utf-8">', $display);
        self::assertStringContainsString('&lt;script&gt;alert(&quot;x&quot;)&lt;/script&gt; &amp; audit', $display);
        self::assertStringContainsString('&lt;Receive &amp; check&gt;', $display);
        self::assertStringContainsString('&lt;amount-route&gt;', $display);
        self::assertStringContainsString('amount&amp;tax', $display);
        self::assertStringContainsString('probe&lt;script&gt;', $display);
        self::assertStringContainsString('&lt;internal&gt;', $display);
        self::assertStringNotContainsString('<script>alert', $display);
        self::assertStringNotContainsString('<visibility>', $display);
        self::assertStringContainsString('@media print', $display);
        self::assertStringNotContainsString('<script', $display);
    }

    public function testWritesOutputFile(): void
    {
        $tester = $this->tester(ProcessTemplateArrayFactory::fromArray(['key' => 'invoice', 'steps' => [['key' => 'received']]]));
        $directory = sys_get_temp_dir().'/process-document-'.bin2hex(random_bytes(4));
        $path = $directory.'/invoice.html';

        self::assertSame(Command::SUCCESS, $tester->execute(['processKey' => 'invoice', '--format' => 'html', '--output' => $path]));
        self::assertFileExists($path);
        self::assertStringContainsString('<!doctype html>', (string) file_get_contents($path));
        unlink($path);
        rmdir($directory);
    }

    public function testMinimalTemplateCanBeDocumented(): void
    {
        $tester = $this->tester(ProcessTemplateArrayFactory::fromArray(['key' => 'minimal']));

        self::assertSame(Command::SUCCESS, $tester->execute(['processKey' => 'minimal']));
        self::assertStringContainsString('# minimal', $tester->getDisplay());
        self::assertStringContainsString('| sourceSystem | amagno |', $tester->getDisplay());
    }

    public function testRejectsInvalidFormat(): void
    {
        $tester = $this->tester(ProcessTemplateArrayFactory::fromArray(['key' => 'invoice']));

        self::assertSame(Command::INVALID, $tester->execute(['processKey' => 'invoice', '--format' => 'pdf']));
        self::assertStringContainsString('Invalid --format', $tester->getDisplay());
    }

    public function testGeneratedAtIsDeterministicAndViewModelContainsNoRendererMarkup(): void
    {
        $builder = new ProcessTemplateDocumentationBuilder(
            new \App\Intelligence\Application\AccessCoverageReportBuilder(),
            new MockClock('2026-02-03 04:05:06+01:00')
        );

        $documentation = $builder->build($this->fullTemplate(), '/templates/invoice.yaml');

        self::assertSame('2026-02-03T04:05:06+01:00', $documentation->generatedAt);
        $values = $this->flatten((array) $documentation);
        foreach ($values as $value) {
            self::assertDoesNotMatchRegularExpression('/^(?:#{1,6}\s|<\/?(?:html|table|h[1-6])\b|\|\s*---)/i', $value);
        }
    }

    public function testStdoutContainsOnlyDocumentWithoutOutputOption(): void
    {
        $tester = $this->tester(ProcessTemplateArrayFactory::fromArray(['key' => 'invoice']));

        self::assertSame(Command::SUCCESS, $tester->execute(['processKey' => 'invoice']));
        self::assertStringStartsWith('# invoice', $tester->getDisplay());
        self::assertStringNotContainsString('Wrote ', $tester->getDisplay());
    }

    public function testRejectsOutputPathWhoseParentCannotBeCreated(): void
    {
        $tester = $this->tester(ProcessTemplateArrayFactory::fromArray(['key' => 'invoice']));
        $file = tempnam(sys_get_temp_dir(), 'process-document-parent-');
        self::assertIsString($file);

        $exitCode = $tester->execute(['processKey' => 'invoice', '--output' => $file.'/invoice.md']);

        self::assertSame(Command::FAILURE, $exitCode);
        self::assertStringContainsString('Could not create output directory', $tester->getDisplay());
        unlink($file);
    }

    private function tester(ProcessTemplate $template): CommandTester
    {
        $markdown = new MarkdownProcessTemplateDocumentationRenderer();
        $clock = new MockClock('2026-01-02 03:04:05+00:00');

        return new CommandTester(new IntelligenceTemplateDocumentCommand(
            $this->provider($template),
            new ProcessTemplateDocumentationBuilder(new \App\Intelligence\Application\AccessCoverageReportBuilder(), $clock),
            $markdown,
            new HtmlProcessTemplateDocumentationRenderer(),
            '/project/config/april/process-templates'
        ));
    }

    /** @return array<int, string> */
    private function flatten(array $values): array
    {
        $flat = [];
        array_walk_recursive($values, static function (mixed $value) use (&$flat): void {
            if (is_string($value)) {
                $flat[] = $value;
            }
        });
        return $flat;
    }

    private function provider(ProcessTemplate $template): ProcessTemplateProvider
    {
        return new class($template) implements ProcessTemplateProvider {
            public function __construct(private readonly ProcessTemplate $template) {}
            public function findByProcessKey(string $processKey): ?ProcessTemplate
            {
                return $processKey === $this->template->key ? $this->template : null;
            }
        };
    }

    private function fullTemplate(): ProcessTemplate
    {
        return ProcessTemplateArrayFactory::fromArray([
            'key' => 'invoice',
            'initial_step' => 'received',
            'steps' => [
                [
                    'key' => 'received',
                    'name' => 'Eingang',
                    'after' => ['visibility_checks' => [[
                        'key' => 'visible_after_receive',
                        'expected_profile' => 'internal',
                    ]]],
                ],
                ['key' => 'approve'],
            ],
            'decision_points' => [[
                'key' => 'amount_route',
                'after' => 'received',
                'required_fields' => ['amount'],
                'rules' => [['when' => ['amount' => ['gt' => 1000]], 'expect_next' => 'approve']],
            ]],
            'access_probes' => ['inbox' => ['type' => 'generic']],
            'visibility_check_profiles' => ['internal' => ['expected_visible_in_probes' => ['inbox']]],
        ]);
    }
}
