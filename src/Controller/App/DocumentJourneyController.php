<?php

namespace App\Controller\App;

use App\Intelligence\Application\DocumentJourneyDetailView;
use App\Intelligence\Application\DocumentJourneySearchView;
use App\Intelligence\Application\DocumentTimelineEventRow;
use App\Intelligence\Application\DocumentTimelineInstanceRow;
use App\Intelligence\Application\DocumentTimelineProvider;
use App\Intelligence\Application\DocumentTimelineReport;
use App\Intelligence\Application\ProcessTemplateProvider;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Twig\Environment;

final readonly class DocumentJourneyController
{
    public function __construct(
        private DocumentTimelineProvider $timelineProvider,
        private ProcessTemplateProvider $templateProvider,
        private Environment $twig
    ) {
    }

    #[Route('/app/intelligence/documents', name: 'app_intelligence_documents_index', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $query = trim((string) $request->query->get('documentUuid', ''));
        if ($query !== '') {
            if (!$this->isValidDocumentUuid($query)) {
                return new Response($this->twig->render('intelligence/documents/index.html.twig', [
                    'active_nav' => 'documents',
                    'view' => new DocumentJourneySearchView($query, 'Bitte eine gueltige Document UUID ohne Leerzeichen oder Slash eingeben.'),
                ]));
            }

            return new RedirectResponse('/app/intelligence/documents/'.rawurlencode($query));
        }

        return new Response($this->twig->render('intelligence/documents/index.html.twig', [
            'active_nav' => 'documents',
            'view' => new DocumentJourneySearchView(),
        ]));
    }

    #[Route(
        '/app/intelligence/documents/{documentUuid}',
        name: 'app_intelligence_documents_show',
        requirements: ['documentUuid' => '[A-Za-z0-9._:-]+'],
        methods: ['GET']
    )]
    public function show(string $documentUuid, Request $request): Response
    {
        $timeline = $this->timelineProvider->build($documentUuid);
        $documentVersion = $this->documentVersion($request);
        $knownTemplates = $this->knownTemplatesByProcessKey($timeline, $documentVersion);

        return new Response($this->twig->render('intelligence/documents/show.html.twig', [
            'active_nav' => 'documents',
            'view' => DocumentJourneyDetailView::fromTimeline($timeline, $documentVersion, $knownTemplates),
        ]));
    }

    private function documentVersion(Request $request): ?int
    {
        $raw = trim((string) $request->query->get('documentVersion', ''));
        if ($raw === '' || !ctype_digit($raw)) {
            return null;
        }

        return (int) $raw;
    }

    private function isValidDocumentUuid(string $documentUuid): bool
    {
        return preg_match('/^[A-Za-z0-9._:-]+$/', $documentUuid) === 1;
    }

    /**
     * @return array<string, bool>
     */
    private function knownTemplatesByProcessKey(DocumentTimelineReport $timeline, ?int $documentVersion): array
    {
        $processKeys = [];
        foreach ($timeline->events as $event) {
            if ($event instanceof DocumentTimelineEventRow && ($documentVersion === null || $event->documentVersion === $documentVersion)) {
                $processKeys[$event->processKey] = true;
            }
        }
        foreach ($timeline->instances as $instance) {
            if ($instance instanceof DocumentTimelineInstanceRow && ($documentVersion === null || $instance->documentVersion === $documentVersion)) {
                $processKeys[$instance->processKey] = true;
            }
        }

        $known = [];
        foreach (array_keys($processKeys) as $processKey) {
            $known[$processKey] = $this->templateProvider->findByProcessKey($processKey) !== null;
        }

        return $known;
    }
}
