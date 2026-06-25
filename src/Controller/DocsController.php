<?php

namespace App\Controller;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Annotation\Route;

/**
 * Serves the static documentation files in docs/ over HTTP so the landing page
 * (served at "/") can link to them. Only a fixed whitelist is exposed.
 */
class DocsController
{
    private const ALLOWED = [
        'start.html',
        'index.html',
        'admin-guide.html',
        'process-analysis-guide.md',
        'doctrine-persistence.md',
    ];

    public function __construct(
        #[Autowire('%kernel.project_dir%')] private readonly string $projectDir
    ) {
    }

    #[Route('/docs/{page}', name: 'docs_page', requirements: ['page' => '[A-Za-z0-9_.-]+'], methods: ['GET'])]
    public function __invoke(string $page): Response
    {
        if (!in_array($page, self::ALLOWED, true)) {
            throw new NotFoundHttpException(sprintf('Unknown documentation page "%s".', $page));
        }

        $file = $this->projectDir . '/docs/' . $page;
        if (!is_file($file)) {
            throw new NotFoundHttpException(sprintf('Documentation page "%s" not found.', $page));
        }

        $contentType = str_ends_with($page, '.md')
            ? 'text/plain; charset=utf-8'
            : 'text/html; charset=utf-8';

        return new Response((string) file_get_contents($file), Response::HTTP_OK, ['Content-Type' => $contentType]);
    }
}
