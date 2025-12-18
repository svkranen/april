<?php

namespace App\Controller;

use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

class SettingsActionController
{
    #[Route('/save.php', name: 'settings_save', methods: ['POST'])]
    public function save(): Response
    {
        $oldProjectDir = \dirname(__DIR__, 2).'/oldProject';
        $previousCwd = getcwd();
        chdir($oldProjectDir);

        ob_start();
        try {
            include $oldProjectDir.'/save.php';
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

        return new Response($content);
    }
}
