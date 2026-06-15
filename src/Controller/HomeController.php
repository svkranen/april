<?php

namespace App\Controller;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

class HomeController
{
    public function __construct(
        #[Autowire('%kernel.project_dir%')] private readonly string $projectDir
    ) {
    }

    #[Route('/', name: 'home')]
    public function __invoke(): Response
    {
        $landingPage = $this->projectDir . '/docs/start.html';
        $html = is_file($landingPage)
            ? (string) file_get_contents($landingPage)
            : '<!DOCTYPE html><html lang="de"><head><meta charset="UTF-8"><title>Amagno Intelligence Tool</title></head><body><h1>Amagno Intelligence Tool</h1></body></html>';

        return new Response($html, Response::HTTP_OK, ['Content-Type' => 'text/html; charset=utf-8']);
    }
}
