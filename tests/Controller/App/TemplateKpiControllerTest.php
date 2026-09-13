<?php

namespace App\Tests\Controller\App;

use App\Intelligence\Application\ProcessVersionRepository;
use App\Intelligence\Domain\ProcessEventRecord;
use App\Intelligence\Domain\ProcessVersion;
use App\Intelligence\Infrastructure\Process\InMemoryProcessVersionRepository;
use App\Intelligence\Port\ProcessEventReader;
use App\Tests\Fake\InMemoryProcessEventReader;
use DateTimeImmutable;

final class TemplateKpiControllerTest extends AppWebTestCase
{
    public function testAnonymousUserMustAuthenticate(): void
    {
        $client = self::createClient();
        $client->request('GET', '/app/templates/incident-management/kpi');
        self::assertResponseRedirects('/login');
    }

    public function testEmptyPageIsProtectedAndShowsUnavailableDurations(): void
    {
        $client = self::createAuthenticatedClient();
        $this->history([]);
        $client->request('GET', '/app/templates/incident-management/kpi?from=2026-02-01&to=2026-02-01');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Process KPIs');
        self::assertSelectorTextContains('[data-kpi="completed"]', '0');
        self::assertSelectorTextContains('[data-kpi="median"]', 'Not available');
        self::assertSelectorTextContains('[role="status"]', 'No runs');
        self::assertSelectorExists('input[name="from"][value="2026-02-01"]');
        self::assertSelectorExists('th[scope="col"]');
        self::assertSelectorExists('link[rel="stylesheet"]');
    }

    public function testUnknownTemplateReturns404(): void
    {
        $client = self::createAuthenticatedClient();
        $client->request('GET', '/app/templates/not-a-template/kpi');
        self::assertResponseStatusCodeSame(404);
    }

    public function testDefinitionMissingDoesNotFabricateMetrics(): void
    {
        $client = self::createAuthenticatedClient();
        $client->request('GET', '/app/templates/ai-rechnungen/kpi');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('[role="status"]', 'No explicit KPI measurement definition');
        self::assertSelectorNotExists('[data-kpi="completed"]');
    }

    public function testCompletionWithoutStartIsNotDisplayedAsZeroSeconds(): void
    {
        $client = self::createAuthenticatedClient();
        $this->history([$this->event('end', 'close_incident', 'after', '2026-02-01T12:00:00Z')]);
        $client->request('GET', '/app/templates/incident-management/kpi?from=2026-02-01&to=2026-02-01');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('[data-kpi="completed"]', '1');
        self::assertSelectorTextContains('[data-kpi="measurable_e2e"]', '0');
        self::assertSelectorTextContains('[data-kpi="median"]', 'Not available');
        self::assertSelectorTextContains('body', 'Missing start marker');
        self::assertSelectorTextContains('[data-step="close_incident"]', 'After only');
    }

    public function testDatesAndVersionFilterUseCompleteHistoryAndOneRead(): void
    {
        $client = self::createAuthenticatedClient();
        $events = [
            $this->event('s', 'incident_received', 'after', '2026-01-31T23:00:00Z'),
            $this->event('e', 'close_incident', 'after', '2026-02-01T01:00:00Z'),
        ];
        $reader = new class($events) implements ProcessEventReader {
            public int $reads = 0;
            public function __construct(private array $events) {}
            public function readForProcess(string $processKey): iterable
            {
                ++$this->reads;
                yield from $this->events;
            }
        };
        $this->history($events, $reader);
        $client->request('GET', '/app/templates/incident-management/kpi?from=2026-02-01&to=2026-02-01&version=1');
        self::assertResponseIsSuccessful();
        self::assertSame(1, $reader->reads);
        self::assertSelectorTextContains('[data-kpi="started"]', '1');
        self::assertSelectorTextContains('[data-kpi="completed"]', '1');
        self::assertSelectorTextContains('[data-kpi="median"]', '2 h');
        $client->request('GET', '/app/templates/incident-management/kpi?from=2026-01-31&to=2026-01-31&version=1');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('[data-kpi="open"]', '0');
        self::assertSelectorTextContains('[data-kpi="completed"]', '0');
        $client->request('GET', '/app/templates/incident-management/kpi?from=2026-02-01&to=2026-02-01&version=absent');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('[role="status"]', 'No runs');
    }

    public function testMixedCoverageIsDisplayedPerVisitCategory(): void
    {
        $client = self::createAuthenticatedClient();
        $this->history([
            $this->event('s', 'incident_received', 'after', '2026-02-01T10:00:00Z'),
            $this->event('a1', 'classify_incident', 'after', '2026-02-01T10:01:00Z'),
            $this->event('b2', 'classify_incident', 'before', '2026-02-01T10:02:00Z'),
            $this->event('a2', 'classify_incident', 'after', '2026-02-01T10:03:00Z'),
            $this->event('b3', 'classify_incident', 'before', '2026-02-01T10:04:00Z'),
        ]);
        $client->request('GET', '/app/templates/incident-management/kpi?from=2026-02-01&to=2026-02-01');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('[data-step="classify_incident"]', 'Mixed coverage');
        self::assertSelectorTextContains('[data-step="classify_incident"]', 'Before and after: 1');
        self::assertSelectorTextContains('[data-step="classify_incident"]', 'Before only: 1');
        self::assertSelectorTextContains('[data-step="classify_incident"]', 'After only: 1');
        self::assertSelectorExists('code[aria-hidden="true"]');
    }

    public function testInvalidDateIsRejected(): void
    {
        $client = self::createAuthenticatedClient();
        $client->request('GET', '/app/templates/incident-management/kpi?from=2026-02-30&to=2026-03-01');
        self::assertResponseStatusCodeSame(400);
        self::assertSelectorTextContains('[role="alert"]', 'Invalid filter');
    }

    private function history(array $events, ?ProcessEventReader $reader = null): void
    {
        self::getContainer()->set(ProcessEventReader::class, $reader ?? new InMemoryProcessEventReader($events));
        self::getContainer()->set(ProcessVersionRepository::class, new InMemoryProcessVersionRepository([
            new ProcessVersion(null, 'incident-management', '1', new DateTimeImmutable('2026-01-01T00:00:00Z')),
        ]));
    }

    private function event(string $key, string $step, string $phase, string $time): ProcessEventRecord
    {
        return new ProcessEventRecord(null, $key, 'community-demo', 'incident-management', $step, $step, 'item', null, 1,
            null, new DateTimeImmutable($time), new DateTimeImmutable($time), '{}', '{}', eventPhase: $phase);
    }
}
