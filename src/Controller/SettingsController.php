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

        $previousCwd = getcwd();
        chdir(\dirname($settingsFile));

        ob_start();
        try {
            include $settingsFile;
        } catch (\Throwable $e) {
            ob_end_clean();
            if ($previousCwd !== false) {
                chdir($previousCwd);
            }
            throw $e;
        }
        if ($previousCwd !== false) {
            chdir($previousCwd);
        }

        $content = ob_get_clean();

        $html = <<<HTML
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <title>Amagno Settings</title>
</head>
<body>
$content
</body>
</html>
HTML;

        return new Response($html);
    }
}
