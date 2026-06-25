<?php

namespace App\Tests\Command;

use App\Command\IntelligenceTemplateAccessCoverageCommand;
use App\Intelligence\Application\AccessCoverageReportBuilder;
use App\Intelligence\Application\ProcessTemplateProvider;
use App\Intelligence\Domain\ProcessTemplate;
use App\Intelligence\Domain\ProcessTemplateArrayFactory;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

class IntelligenceTemplateAccessCoverageCommandTest extends TestCase
{
    public function testOutputsStaticAccessCoverageAsJson(): void
    {
        $tester = new CommandTester(new IntelligenceTemplateAccessCoverageCommand(
            $this->provider($this->template()),
            new AccessCoverageReportBuilder()
        ));

        $exitCode = $tester->execute([
            'processKey' => 'invoice',
            '--format' => 'json',
        ]);
        $data = json_decode($tester->getDisplay(), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertSame('invoice', $data['processKey']);
        self::assertSame('amagno', $data['sourceSystem']);
        self::assertSame(3, $data['summary']['accessProbes']);
        self::assertSame(2, $data['summary']['visibilityChecks']);
        self::assertSame(1, $data['summary']['automatic']);
        self::assertSame(1, $data['summary']['unsupported']);
        self::assertSame(1, $data['summary']['manualAccessTests']);
        self::assertSame('initial_visibility', $data['checks'][0]['checkKey']);
        self::assertSame('automatic', $data['checks'][0]['coverage']);
        self::assertSame('route_to_location_approval', $data['checks'][1]['checkKey']);
        self::assertSame('unsupported_probe_type', $data['checks'][1]['reason']);
        self::assertSame('approver_scope_test', $data['manualAccessTests'][0]['key']);
        self::assertSame('Freigeberbezogene Sichtbarkeit', $data['manualAccessTests'][0]['title']);
        self::assertSame('Freigeber duerfen nur eigene Dokumente sehen.', $data['manualAccessTests'][0]['description']);
        self::assertSame(['Benutzer A pruefen.', 'Benutzer B pruefen.'], $data['manualAccessTests'][0]['testProcedure']);
        self::assertSame(['A sieht das Dokument.', 'B sieht es nicht.'], $data['manualAccessTests'][0]['expectedResult']);
        self::assertSame('Screenshot oder Pruefvermerk', $data['manualAccessTests'][0]['evidenceRequired']);
    }

    public function testOutputsStaticAccessCoverageAsText(): void
    {
        $tester = new CommandTester(new IntelligenceTemplateAccessCoverageCommand(
            $this->provider($this->template()),
            new AccessCoverageReportBuilder()
        ));

        $exitCode = $tester->execute(['processKey' => 'invoice']);
        $display = $tester->getDisplay();

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertStringContainsString('Access coverage for invoice', $display);
        self::assertStringContainsString('route_to_location_approval', $display);
        self::assertStringContainsString('unsupported_probe_type', $display);
        self::assertStringContainsString('approver_scope_test', $display);
    }

    public function testMissingTemplateReturnsFailure(): void
    {
        $tester = new CommandTester(new IntelligenceTemplateAccessCoverageCommand(
            $this->provider(null),
            new AccessCoverageReportBuilder()
        ));

        $exitCode = $tester->execute(['processKey' => 'missing']);

        self::assertSame(Command::FAILURE, $exitCode);
        self::assertStringContainsString('Template "missing" not found.', $tester->getDisplay());
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
                ],
                'approval_location_b_today' => [
                    'type' => 'amagno_magnet_documents',
                    'magnet_id' => 1002,
                    'max_documents' => 500,
                ],
                'external_today' => [
                    'source_system' => 'external_dms',
                    'type' => 'external_probe',
                    'query_id' => 'external-today',
                ],
            ],
            'visibility_check_profiles' => [
                'approval_location_a' => [
                    'expected_visible_in_probes' => ['approval_location_a_today'],
                    'expected_not_visible_in_probes' => ['external_today'],
                ],
                'incoming_invoice_scope' => [
                    'expected_visible_in_probes' => ['approval_location_a_today'],
                    'expected_not_visible_in_probes' => ['approval_location_b_today'],
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
                            ],
                        ],
                    ],
                    'before' => [
                        'visibility_checks' => [
                            [
                                'key' => 'initial_visibility',
                                'expected_profile' => 'incoming_invoice_scope',
                                'source_system' => 'amagno',
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
