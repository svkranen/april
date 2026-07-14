<?php

namespace App\Controller\App;

use App\Intelligence\Application\HtmlProcessTemplateDocumentationRenderer;
use App\Intelligence\Application\ProcessTemplateDocumentationFactory;
use App\Intelligence\Application\ProcessTemplateProvider;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Annotation\Route;

final readonly class ProcessTemplateDocumentationController
{
    public function __construct(
        private ProcessTemplateProvider $templateProvider,
        private ProcessTemplateDocumentationFactory $documentationFactory,
        private HtmlProcessTemplateDocumentationRenderer $htmlRenderer
    ) {
    }

    #[Route(
        '/app/templates/{key}/documentation',
        name: 'app_templates_documentation',
        requirements: ['key' => '[A-Za-z0-9._-]+'],
        methods: ['GET']
    )]
    public function show(string $key): Response
    {
        $template = $this->templateProvider->findByProcessKey($key);
        if ($template === null) {
            throw new NotFoundHttpException(sprintf('Template "%s" not found.', $key));
        }

        $documentation = $this->documentationFactory->create($template);

        return new Response(
            $this->htmlRenderer->render($documentation),
            Response::HTTP_OK,
            ['Content-Type' => 'text/html; charset=utf-8']
        );
    }
}
