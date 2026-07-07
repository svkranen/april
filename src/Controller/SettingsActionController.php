<?php

namespace App\Controller;

use App\Service\Settings\LegacyMatchingSaveService;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

class SettingsActionController
{
    public function __construct(
        private readonly LegacyMatchingSaveService $matchingSaveService
    ) {
    }

    #[Route('/save.php', name: 'settings_save', methods: ['POST'])]
    #[Route('/settings/save.php', name: 'settings_save_relative', methods: ['POST'])]
    public function save(Request $request): Response
    {
        return new Response($this->matchingSaveService->save($request->request->all()));
    }
}
