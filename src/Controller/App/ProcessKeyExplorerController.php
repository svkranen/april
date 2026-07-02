<?php

namespace App\Controller\App;

use App\Intelligence\Application\ProcessKeyDocumentsView;
use App\Intelligence\Application\ProcessKeyOverviewProvider;
use App\Intelligence\Application\ProcessKeyOverviewRow;
use App\Intelligence\Application\ProcessKeyOverviewView;
use App\Intelligence\Application\ProcessTemplateProvider;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Twig\Environment;

final readonly class ProcessKeyExplorerController
{
    private const DOCUMENT_LIMIT = 500;

    public function __construct(
        private ProcessKeyOverviewProvider $overviewProvider,
        private ProcessTemplateProvider $templateProvider,
        private Environment $twig
    ) {
    }

    #[Route('/app/intelligence/process-keys', name: 'app_intelligence_process_keys_index', methods: ['GET'])]
    public function index(): Response
    {
        $rows = array_map(
            fn (ProcessKeyOverviewRow $row): ProcessKeyOverviewRow => $row->withKnownTemplate($this->hasTemplate($row->processKey)),
            $this->overviewProvider->processKeys()
        );

        return new Response($this->twig->render('intelligence/process_keys/index.html.twig', [
            'active_nav' => 'documents',
            'view' => new ProcessKeyOverviewView($rows),
        ]));
    }

    #[Route(
        '/app/intelligence/process-keys/{processKey}/documents',
        name: 'app_intelligence_process_keys_documents',
        requirements: ['processKey' => '[A-Za-z0-9._:-]+'],
        methods: ['GET']
    )]
    public function documents(string $processKey): Response
    {
        return new Response($this->twig->render('intelligence/process_keys/documents.html.twig', [
            'active_nav' => 'documents',
            'view' => new ProcessKeyDocumentsView(
                $processKey,
                $this->hasTemplate($processKey),
                $this->overviewProvider->documentsForProcessKey($processKey, self::DOCUMENT_LIMIT)
            ),
        ]));
    }

    private function hasTemplate(string $processKey): bool
    {
        return $this->templateProvider->findByProcessKey($processKey) !== null;
    }
}
