<?php

namespace App\Tests\Intelligence\Application;

use App\Intelligence\Application\AccessCoverageReport;
use App\Intelligence\Application\TemplateAccessView;
use App\Intelligence\Domain\ProcessTemplate;
use App\Intelligence\Domain\ProcessTemplateAccessProbe;
use App\Intelligence\Domain\ProcessTemplateVisibilityProfile;
use App\Intelligence\Domain\ProcessTemplateVisibilityProfileResolver;
use PHPUnit\Framework\TestCase;

class TemplateAccessViewTest extends TestCase
{
    public function testBundlesReportWithProbeProfileAndResolverRows(): void
    {
        $template = new ProcessTemplate(
            key: 'invoice',
            version: '2.0',
            accessProbes: [
                'a' => new ProcessTemplateAccessProbe('a', 'amagno', 'amagno_magnet_documents', ['magnet_id' => 1001, 'page_size' => 50], 500, 'Standort A'),
                'b' => new ProcessTemplateAccessProbe('b', 'amagno', 'amagno_magnet_documents', ['magnet_id' => 1009], null, null),
            ],
            visibilityProfiles: [
                'p' => new ProcessTemplateVisibilityProfile('p', ['a'], ['b']),
            ],
            visibilityProfileResolvers: [
                'r' => new ProcessTemplateVisibilityProfileResolver('r', 'cost_center', ['A' => 'p']),
            ],
            sourceSystem: 'amagno'
        );

        $summary = [
            'accessProbes' => 2,
            'visibilityChecks' => 1,
            'automatic' => 1,
            'unsupported' => 0,
            'notCovered' => 0,
            'manualAccessTests' => 1,
        ];
        $checks = [[
            'stepKey' => 'A', 'phase' => 'after', 'checkKey' => 'route',
            'expectedProfile' => null, 'expectedProfileResolver' => 'r',
            'profileKeys' => ['p'], 'coverage' => 'automatic', 'reason' => null,
        ]];
        $manualTests = [['key' => 'approver_scope_test', 'title' => 'Scope', 'testProcedure' => [], 'expectedResult' => []]];

        $report = new AccessCoverageReport('invoice', 'amagno', $checks, $manualTests, $summary);

        $view = TemplateAccessView::fromTemplate($template, $report);

        self::assertSame('invoice', $view->key);
        self::assertSame('2.0', $view->version);
        self::assertSame('amagno', $view->sourceSystem);

        // Report data is passed through unchanged.
        self::assertSame($summary, $view->summary);
        self::assertSame($checks, $view->checks);
        self::assertSame($manualTests, $view->manualTests);

        // Probe rows incl. options (page_size, magnet_id).
        self::assertCount(2, $view->probes);
        self::assertSame('a', $view->probes[0]['key']);
        self::assertSame('amagno', $view->probes[0]['sourceSystem']);
        self::assertSame('amagno_magnet_documents', $view->probes[0]['type']);
        self::assertSame('Standort A', $view->probes[0]['description']);
        self::assertSame(500, $view->probes[0]['maxDocuments']);
        self::assertSame(50, $view->probes[0]['pageSize']);
        self::assertSame('1001', $view->probes[0]['probeRef']);
        self::assertNull($view->probes[1]['pageSize']);
        self::assertNull($view->probes[1]['maxDocuments']);

        // Profiles.
        self::assertSame('p', $view->profiles[0]['key']);
        self::assertSame(['a'], $view->profiles[0]['expectedVisibleInProbes']);
        self::assertSame(['b'], $view->profiles[0]['expectedNotVisibleInProbes']);

        // Resolvers.
        self::assertSame('r', $view->resolvers[0]['key']);
        self::assertSame('cost_center', $view->resolvers[0]['field']);
        self::assertSame(['A' => 'p'], $view->resolvers[0]['map']);
    }
}
