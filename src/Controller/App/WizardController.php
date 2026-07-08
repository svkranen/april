<?php

namespace App\Controller\App;

use App\Wizard\WizardDefinitionException;
use App\Wizard\WizardDefinitionLoader;
use App\Wizard\WizardSummaryProvider;
use App\Wizard\WizardViewFactory;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Annotation\Route;
use Twig\Environment;

final readonly class WizardController
{
    public function __construct(
        private WizardDefinitionLoader $loader,
        private WizardSummaryProvider $summaryProvider,
        private WizardViewFactory $viewFactory,
        private Environment $twig
    ) {
    }

    #[Route('/app/wizards', name: 'app_wizards_index', methods: ['GET'])]
    public function index(): Response
    {
        return new Response($this->twig->render('wizard/index.html.twig', [
            'active_nav' => 'wizards',
            'summaries' => $this->summaryProvider->all(),
        ]));
    }

    #[Route('/app/wizards/{key}', name: 'app_wizards_show', requirements: ['key' => '[A-Za-z0-9._-]+'], methods: ['GET'])]
    public function show(string $key): Response
    {
        try {
            $wizard = $this->loader->load($key);
        } catch (WizardDefinitionException $exception) {
            throw new NotFoundHttpException(sprintf('Wizard "%s" was not found.', $key), $exception);
        }

        return new Response($this->twig->render('wizard/show.html.twig', [
            'active_nav' => 'wizards',
            'view' => $this->viewFactory->create($wizard),
        ]));
    }
}
