<?php

namespace App\Controller\App;

use App\Intelligence\Application\AccessDocumentationHtmlRenderer;
use App\Intelligence\Application\AccessDocumentationMarkdownRenderer;
use App\Intelligence\Application\ProcessTemplateProvider;
use App\Intelligence\Domain\ProcessTemplate;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Annotation\Route;
use Twig\Environment;

/**
 * Serves the human-readable access/visibility documentation for a template.
 * The Markdown/HTML is rendered on-demand and never persisted; uses only
 * Application services, never console commands.
 */
final class TemplateDocumentationController
{
    public function __construct(
        private readonly ProcessTemplateProvider $templateProvider,
        private readonly AccessDocumentationMarkdownRenderer $markdownRenderer,
        private readonly AccessDocumentationHtmlRenderer $htmlRenderer,
        private readonly Environment $twig
    ) {
    }

    #[Route('/app/templates/{key}/docs', name: 'app_templates_docs', requirements: ['key' => '[A-Za-z0-9._-]+'], methods: ['GET'])]
    public function page(string $key): Response
    {
        $template = $this->requireTemplate($key);

        return new Response($this->twig->render('template/docs.html.twig', [
            'active_nav' => 'templates',
            'key' => $template->key,
            'version' => $template->version,
            'sourceSystem' => $template->sourceSystem,
        ]));
    }

    #[Route('/app/templates/{key}/docs/preview', name: 'app_templates_docs_preview', requirements: ['key' => '[A-Za-z0-9._-]+'], methods: ['GET'])]
    public function preview(string $key): Response
    {
        $template = $this->requireTemplate($key);

        // Full standalone HTML document - rendered for the iframe, no app layout.
        return new Response(
            $this->htmlRenderer->render($template),
            Response::HTTP_OK,
            ['Content-Type' => 'text/html; charset=utf-8']
        );
    }

    #[Route('/app/templates/{key}/docs/download', name: 'app_templates_docs_download', requirements: ['key' => '[A-Za-z0-9._-]+'], methods: ['GET'])]
    public function download(string $key, Request $request): Response
    {
        $template = $this->requireTemplate($key);
        $format = (string) $request->query->get('format', '');

        [$body, $contentType, $extension] = match ($format) {
            'md' => [$this->markdownRenderer->render($template), 'text/markdown; charset=utf-8', 'md'],
            'html' => [$this->htmlRenderer->render($template), 'text/html; charset=utf-8', 'html'],
            default => throw new BadRequestHttpException('Invalid format. Use "md" or "html".'),
        };

        $response = new Response($body, Response::HTTP_OK, ['Content-Type' => $contentType]);
        $response->headers->set('Content-Disposition', HeaderUtils::makeDisposition(
            HeaderUtils::DISPOSITION_ATTACHMENT,
            sprintf('%s-access.%s', $template->key, $extension)
        ));

        return $response;
    }

    private function requireTemplate(string $key): ProcessTemplate
    {
        $template = $this->templateProvider->findByProcessKey($key);
        if ($template === null) {
            throw new NotFoundHttpException(sprintf('Template "%s" not found.', $key));
        }

        return $template;
    }
}
