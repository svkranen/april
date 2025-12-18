<?php

namespace App\Controller;

use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

class HomeController
{
    #[Route('/', name: 'home')]
    public function __invoke(): Response
    {
        $html = <<<HTML
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <title>Amagno / Nevaris Interface</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 0; padding: 4rem; background: #f5f5f5; color: #333; }
        h1 { margin: 0; font-size: 2.5rem; text-align: center; }
    </style>
    <meta name="viewport" content="width=device-width, initial-scale=1">
</head>
<body>
    <h1>Amagno / Nevaris Interface</h1>
</body>
</html>
HTML;

        return new Response($html);
    }
}
