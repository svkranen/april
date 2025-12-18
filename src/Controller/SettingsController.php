<?php

namespace App\Controller;

use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

class SettingsController
{
    #[Route('/settings', name: 'settings')]
    public function __invoke(): Response
    {
        $settingsFile = \dirname(__DIR__, 2).'/oldProject/settings.php';
        if (!is_file($settingsFile)) {
            return new Response('Settings template nicht gefunden.', Response::HTTP_NOT_FOUND);
        }

        ob_start();
        try {
            include $settingsFile;
        } catch (\Throwable $e) {
            ob_end_clean();
            throw $e;
        }

        $content = ob_get_clean();

        return new Response($content);
    }
}
