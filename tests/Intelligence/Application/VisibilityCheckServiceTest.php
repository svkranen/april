<?php

namespace App\Tests\Intelligence\Application;

use App\Intelligence\Application\AccessProbeProviderRegistry;
use App\Intelligence\Application\VisibilityCheckService;
use App\Intelligence\Domain\ProcessTemplate;
use App\Intelligence\Domain\ProcessTemplateArrayFactory;
use App\Intelligence\Infrastructure\Access\InMemoryAccessProbeProvider;
use PHPUnit\Framework\TestCase;

class VisibilityCheckServiceTest extends TestCase
{
    public function testDirectExpectedProfileIsResolved(): void
    {
        $results = $this->service(['approval_location_a_today'])->evaluate(
            $this->template(profileMode: 'direct'),
            'doc-1',
            'received',
            'after',
            'direct_visibility',
            []
        );

        self::assertSame('approval_location_a', $results[0]->profileKey);
        self::assertSame('ok', $results[0]->status);
    }

    public function testProfileResolverMapsContextValue(): void
    {
        $results = $this->service(['approval_location_a_today'])->evaluate(
            $this->template(),
            'doc-1',
            'received',
            'after',
            'route_to_location_approval',
            ['cost_center' => 'A']
        );

        self::assertSame('approval_location_a', $results[0]->profileKey);
    }

    public function testMissingContextFieldReturnsWarning(): void
    {
        $results = $this->service()->evaluate(
            $this->template(),
            'doc-1',
            'received',
            'after',
            'route_to_location_approval',
            []
        );

        self::assertSame('warning', $results[0]->status);
        self::assertSame('missing_context_field', $results[0]->reason);
    }

    public function testUnmappedContextValueReturnsWarning(): void
    {
        $results = $this->service()->evaluate(
            $this->template(),
            'doc-1',
            'received',
            'after',
            'route_to_location_approval',
            ['cost_center' => 'C']
        );

        self::assertSame('warning', $results[0]->status);
        self::assertSame('unmapped_context_value', $results[0]->reason);
    }

    public function testExpectedVisibleAndVisibleIsOk(): void
    {
        $results = $this->service(['approval_location_a_today'])->evaluate(
            $this->template(),
            'doc-1',
            'received',
            'after',
            'route_to_location_approval',
            ['cost_center' => 'A']
        );

        self::assertSame('approval_location_a_today', $results[0]->probeKey);
        self::assertSame('visible', $results[0]->expected);
        self::assertSame('visible', $results[0]->actual);
        self::assertSame('ok', $results[0]->status);
    }

    public function testExpectedHiddenAndVisibleIsViolation(): void
    {
        $results = $this->service(['approval_location_a_today', 'external_today'])->evaluate(
            $this->template(),
            'doc-1',
            'received',
            'after',
            'route_to_location_approval',
            ['cost_center' => 'A']
        );

        self::assertSame('external_today', $results[1]->probeKey);
        self::assertSame('hidden', $results[1]->expected);
        self::assertSame('visible', $results[1]->actual);
        self::assertSame('violation', $results[1]->status);
        self::assertSame('forbidden_visibility', $results[1]->reason);
    }

    public function testExpectedVisibleAndHiddenIsWarning(): void
    {
        $results = $this->service([])->evaluate(
            $this->template(),
            'doc-1',
            'received',
            'after',
            'route_to_location_approval',
            ['cost_center' => 'A']
        );

        self::assertSame('approval_location_a_today', $results[0]->probeKey);
        self::assertSame('hidden', $results[0]->actual);
        self::assertSame('warning', $results[0]->status);
        self::assertSame('missing_expected_visibility', $results[0]->reason);
    }

    public function testUnsupportedProbeTypeIsSkipped(): void
    {
        $service = new VisibilityCheckService(new AccessProbeProviderRegistry([]));
        $results = $service->evaluate(
            $this->template(),
            'doc-1',
            'received',
            'after',
            'route_to_location_approval',
            ['cost_center' => 'A']
        );

        self::assertSame('skipped', $results[0]->status);
        self::assertSame('unsupported_probe_type', $results[0]->reason);
    }

    public function testProbeDocumentCountAboveMaxDocumentsIsTechnicalWarning(): void
    {
        $results = $this->service(['approval_location_a_today'], ['approval_location_a_today' => 501])->evaluate(
            $this->template(maxDocuments: 100),
            'doc-1',
            'received',
            'after',
            'route_to_location_approval',
            ['cost_center' => 'A']
        );

        self::assertSame('technical_warning', $results[0]->status);
        self::assertSame('probe_too_large', $results[0]->reason);
    }

    /**
     * @param array<int, string> $visibleProbeKeys
     * @param array<string, int> $documentCountsByProbeKey
     */
    private function service(array $visibleProbeKeys = [], array $documentCountsByProbeKey = []): VisibilityCheckService
    {
        return new VisibilityCheckService(new AccessProbeProviderRegistry([
            new InMemoryAccessProbeProvider(
                $visibleProbeKeys,
                ['fake:fake_document_visibility'],
                $documentCountsByProbeKey
            ),
        ]));
    }

    private function template(string $profileMode = 'resolver', int $maxDocuments = 500): ProcessTemplate
    {
        $check = $profileMode === 'direct'
            ? ['key' => 'direct_visibility', 'expected_profile' => 'approval_location_a']
            : ['key' => 'route_to_location_approval', 'expected_profile_resolver' => 'approval_location_by_context'];

        return ProcessTemplateArrayFactory::fromArray([
            'key' => 'invoice',
            'source_system' => 'fake',
            'access_probes' => [
                'approval_location_a_today' => [
                    'type' => 'fake_document_visibility',
                    'max_documents' => $maxDocuments,
                ],
                'external_today' => [
                    'type' => 'fake_document_visibility',
                    'max_documents' => 500,
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
                    'field' => 'cost_center',
                    'map' => [
                        'A' => 'approval_location_a',
                    ],
                ],
            ],
            'steps' => [
                [
                    'key' => 'received',
                    'after' => [
                        'visibility_checks' => [$check],
                    ],
                ],
            ],
        ]);
    }
}
