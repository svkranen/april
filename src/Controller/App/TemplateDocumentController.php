<?php

namespace App\Controller\App;

use App\Intelligence\Application\DocumentDetailView;
use App\Intelligence\Application\DocumentTimelineProvider;
use App\Intelligence\Application\ProcessTemplateProvider;
use App\Intelligence\Application\VisibilityCheckResultProvider;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Annotation\Route;
use Twig\Environment;

/**
 * Per-document detail page: renders the stored APRIL timeline and visibility
 * results for one document. Read-only; uses only Application services, never
 * console commands, and never executes checks at request time.
 */
final class TemplateDocumentController
{
    public function __construct(
        private readonly ProcessTemplateProvider $templateProvider,
        private readonly DocumentTimelineProvider $timelineProvider,
        private readonly VisibilityCheckResultProvider $visibilityResultProvider,
        private readonly Environment $twig,
        private readonly string $processTemplateDirectory
    ) {
    }

    #[Route(
        '/app/templates/{key}/documents/{documentUuid}',
        name: 'app_template_document_show',
        requirements: ['key' => '[A-Za-z0-9._-]+', 'documentUuid' => '[A-Za-z0-9._:-]+'],
        methods: ['GET']
    )]
    public function show(string $key, string $documentUuid): Response
    {
        $template = $this->templateProvider->findByProcessKey($key);
        if ($template === null) {
            throw new NotFoundHttpException(sprintf(
                'Template "%s" not found in configured APRIL process template directory "%s".',
                $key,
                $this->processTemplateDirectory
            ));
        }

        // Template exists but the document may have no stored events yet -> the
        // view renders a friendly empty state (200), we do not 404 the document.
        $timeline = $this->timelineProvider->build($documentUuid);
        $visibilityRecords = $this->visibilityResultProvider->findByDocument($documentUuid, $template->key);

        return new Response($this->twig->render('document/show.html.twig', [
            'active_nav' => 'templates',
            'view' => DocumentDetailView::fromData($template, $documentUuid, $timeline, $visibilityRecords),
        ]));
    }
}
