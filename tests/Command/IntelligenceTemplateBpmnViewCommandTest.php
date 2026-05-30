<?php

namespace App\Tests\Command;

use App\Command\IntelligenceTemplateBpmnViewCommand;
use App\Intelligence\Bpmn\ProcessTemplateBpmnViewBuilder;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class IntelligenceTemplateBpmnViewCommandTest extends TestCase
{
    public function testOutputsBpmnViewAsJson(): void
    {
        $templatePath = $this->templatePath();
        $heatmapPath = $this->heatmapPath();

        $tester = new CommandTester(new IntelligenceTemplateBpmnViewCommand(new ProcessTemplateBpmnViewBuilder()));
        $exitCode = $tester->execute([
            'processKey' => 'invoice',
            '--template' => $templatePath,
            '--heatmap' => $heatmapPath,
            '--format' => 'json',
        ]);

        self::assertSame(Command::SUCCESS, $exitCode);
        $view = json_decode($tester->getDisplay(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('invoice', $view['template_key']);
        self::assertNotEmpty($view['nodes']);
        self::assertNotEmpty($view['edges']);
        self::assertNotNull($this->edge($view, 'edge:parallel-any:booking_and_payment:booked:payment_expected'));

        unlink($templatePath);
        unlink($heatmapPath);
    }

    public function testOutputsBpmnViewAsMermaid(): void
    {
        $templatePath = $this->templatePath();
        $heatmapPath = $this->heatmapPath();

        $tester = new CommandTester(new IntelligenceTemplateBpmnViewCommand(new ProcessTemplateBpmnViewBuilder()));
        $exitCode = $tester->execute([
            'processKey' => 'invoice',
            '--template' => $templatePath,
            '--heatmap' => $heatmapPath,
            '--format' => 'mermaid',
        ]);

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertStringContainsString('flowchart TD', $tester->getDisplay());
        self::assertStringContainsString('n_task_invoice_checked["invoice_checked (required)"]', $tester->getDisplay());
        self::assertStringContainsString('n_gateway_approval_route{"approval_route"}', $tester->getDisplay());

        unlink($templatePath);
        unlink($heatmapPath);
    }

    public function testOutputsMermaidWithViewOptions(): void
    {
        $templatePath = $this->templatePath();
        $heatmapPath = $this->heatmapPath();

        $tester = new CommandTester(new IntelligenceTemplateBpmnViewCommand(new ProcessTemplateBpmnViewBuilder()));
        $exitCode = $tester->execute([
            'processKey' => 'invoice',
            '--template' => $templatePath,
            '--heatmap' => $heatmapPath,
            '--format' => 'mermaid',
            '--view' => 'observed',
            '--min-unexpected-count' => '2',
        ]);

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertStringContainsString('3x · 30%', $tester->getDisplay());
        self::assertStringNotContainsString('n_task_invoice_checked -->|"expected"| n_task_invoice_finished', $tester->getDisplay());

        unlink($templatePath);
        unlink($heatmapPath);
    }

    public function testOutputsBpmnViewAsSvg(): void
    {
        $templatePath = $this->templatePath();
        $heatmapPath = $this->heatmapPath();

        $tester = new CommandTester(new IntelligenceTemplateBpmnViewCommand(new ProcessTemplateBpmnViewBuilder()));
        $exitCode = $tester->execute([
            'processKey' => 'invoice',
            '--template' => $templatePath,
            '--heatmap' => $heatmapPath,
            '--format' => 'svg',
            '--view' => 'observed',
            '--layout' => 'graph',
            '--width' => '900',
        ]);

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertStringContainsString('<svg xmlns="http://www.w3.org/2000/svg"', $tester->getDisplay());
        self::assertStringContainsString('data-node-id="task:invoice_checked"', $tester->getDisplay());
        self::assertStringContainsString('3x 30%', $tester->getDisplay());

        unlink($templatePath);
        unlink($heatmapPath);
    }

    public function testSvgDefaultsToSummaryView(): void
    {
        $templatePath = $this->templatePath();
        $heatmapPath = $this->heatmapPath();

        $tester = new CommandTester(new IntelligenceTemplateBpmnViewCommand(new ProcessTemplateBpmnViewBuilder()));
        $exitCode = $tester->execute([
            'processKey' => 'invoice',
            '--template' => $templatePath,
            '--heatmap' => $heatmapPath,
            '--format' => 'svg',
        ]);

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertStringContainsString('<svg xmlns="http://www.w3.org/2000/svg"', $tester->getDisplay());
        self::assertStringContainsString('data-layout="process"', $tester->getDisplay());
        self::assertStringNotContainsString('3x 30%', $tester->getDisplay());

        unlink($templatePath);
        unlink($heatmapPath);
    }

    public function testSvgAcceptsProcessLayoutOption(): void
    {
        $templatePath = $this->templatePath();
        $heatmapPath = $this->heatmapPath();

        $tester = new CommandTester(new IntelligenceTemplateBpmnViewCommand(new ProcessTemplateBpmnViewBuilder()));
        $exitCode = $tester->execute([
            'processKey' => 'invoice',
            '--template' => $templatePath,
            '--heatmap' => $heatmapPath,
            '--format' => 'svg',
            '--view' => 'summary',
            '--layout' => 'process',
        ]);

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertStringContainsString('data-layout="process"', $tester->getDisplay());
        self::assertStringContainsString('marker-end="url(#arrow-expected)"', $tester->getDisplay());

        unlink($templatePath);
        unlink($heatmapPath);
    }

    public function testSvgAcceptsBottleneckView(): void
    {
        $templatePath = $this->templatePath();
        $heatmapPath = $this->heatmapPath();

        $tester = new CommandTester(new IntelligenceTemplateBpmnViewCommand(new ProcessTemplateBpmnViewBuilder()));
        $exitCode = $tester->execute([
            'processKey' => 'invoice',
            '--template' => $templatePath,
            '--heatmap' => $heatmapPath,
            '--format' => 'svg',
            '--view' => 'bottleneck',
            '--layout' => 'process',
        ]);

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertStringContainsString('data-view="bottleneck"', $tester->getDisplay());
        self::assertStringNotContainsString('3x 30%', $tester->getDisplay());

        unlink($templatePath);
        unlink($heatmapPath);
    }

    private function templatePath(): string
    {
        return $this->tempFile('bpmn-template', <<<'YAML'
key: invoice
version: '1'
required_steps:
  - invoice_checked
  - invoice_finished
steps:
  - key: invoice_checked
  - key: booked
  - key: payment_expected
  - key: invoice_finished
decision_points:
  - key: approval_route
    after: invoice_checked
    required_fields:
      - amount
    rules:
      - when:
          amount:
            gt: 50
        expect_next: booked
      - else:
          expect_next: invoice_finished
parallel_groups:
  - key: booking_and_payment
    required_steps:
      - booked
      - payment_expected
    order: any
YAML);
    }

    private function heatmapPath(): string
    {
        return $this->tempFile('bpmn-heatmap', json_encode([
            'flow_heatmap' => [
                'transitions' => [
                    [
                        'from' => 'booked',
                        'to' => 'payment_expected',
                        'count' => 3,
                        'percentage' => 30.0,
                        'intensity' => 1.0,
                    ],
                ],
            ],
            'duration_heatmap' => [
                'steps' => [
                    [
                        'step' => 'booked',
                        'historical' => [
                            'completed_documents' => 3,
                            'avg_duration_minutes' => 2.0,
                        ],
                        'current' => [
                            'open_documents' => 1,
                        ],
                        'intensity' => [
                            'historical_duration' => 0.5,
                        ],
                    ],
                ],
            ],
        ], JSON_THROW_ON_ERROR));
    }

    private function tempFile(string $prefix, string $contents): string
    {
        $path = tempnam(sys_get_temp_dir(), $prefix);
        self::assertIsString($path);
        file_put_contents($path, $contents);

        return $path;
    }

    /**
     * @param array<string, mixed> $view
     * @return array<string, mixed>|null
     */
    private function edge(array $view, string $id): ?array
    {
        foreach ($view['edges'] as $edge) {
            if ($edge['id'] === $id) {
                return $edge;
            }
        }

        return null;
    }
}
