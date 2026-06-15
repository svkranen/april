<?php

namespace App\Controller\App;

use App\Intelligence\Application\AccessCoverageReportBuilder;
use App\Intelligence\Application\DocumentListProvider;
use App\Intelligence\Application\ProcessTemplateCatalog;
use App\Intelligence\Application\ProcessTemplateProvider;
use App\Intelligence\Application\TemplateAccessView;
use App\Intelligence\Application\TemplateDetailView;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Annotation\Route;
use Twig\Environment;

/**
 * Server-side (Twig) frontend for the process template catalog.
 * Uses only Application services - never console commands.
 */
final class TemplateController
{
    public function __construct(
        private readonly ProcessTemplateCatalog $catalog,
        private readonly ProcessTemplateProvider $templateProvider,
        private readonly AccessCoverageReportBuilder $coverageBuilder,
        private readonly DocumentListProvider $documentListProvider,
        private readonly Environment $twig,
        private readonly string $processTemplateDirectory
    ) {
    }

    #[Route('/app', name: 'app_home', methods: ['GET'])]
    public function home(): Response
    {
        return new RedirectResponse('/app/templates');
    }

    #[Route('/app/templates', name: 'app_templates_index', methods: ['GET'])]
    public function index(): Response
    {
        $catalog = $this->catalog->list();

        return new Response($this->twig->render('template/index.html.twig', [
            'active_nav' => 'templates',
            'entries' => $catalog->entries,
            'warnings' => $catalog->warnings,
        ]));
    }

    #[Route('/app/templates/{key}', name: 'app_templates_show', requirements: ['key' => '[A-Za-z0-9._-]+'], methods: ['GET'])]
    public function show(string $key): Response
    {
        $template = $this->templateProvider->findByProcessKey($key);
        if ($template === null) {
            throw new NotFoundHttpException($this->notFoundMessage($key));
        }

        return new Response($this->twig->render('template/show.html.twig', [
            'active_nav' => 'templates',
            'view' => TemplateDetailView::fromTemplate($template),
        ]));
    }

    #[Route('/app/templates/{key}/access', name: 'app_templates_access', requirements: ['key' => '[A-Za-z0-9._-]+'], methods: ['GET'])]
    public function access(string $key): Response
    {
        $template = $this->templateProvider->findByProcessKey($key);
        if ($template === null) {
            throw new NotFoundHttpException($this->notFoundMessage($key));
        }

        $report = $this->coverageBuilder->build($template);

        return new Response($this->twig->render('template/access.html.twig', [
            'active_nav' => 'templates',
            'view' => TemplateAccessView::fromTemplate($template, $report),
        ]));
    }

    #[Route('/app/templates/{key}/documents', name: 'app_templates_documents', requirements: ['key' => '[A-Za-z0-9._-]+'], methods: ['GET'])]
    public function documents(string $key): Response
    {
        $template = $this->templateProvider->findByProcessKey($key);
        if ($template === null) {
            throw new NotFoundHttpException($this->notFoundMessage($key));
        }

        return new Response($this->twig->render('template/documents.html.twig', [
            'active_nav' => 'templates',
            'key' => $template->key,
            'version' => $template->version,
            'rows' => $this->documentListProvider->documentsForProcess($template->key, 200),
        ]));
    }

    private function notFoundMessage(string $key): string
    {
        return sprintf(
            'Template "%s" not found in configured APRIL process template directory "%s".',
            $key,
            $this->processTemplateDirectory
        );
    }
}
