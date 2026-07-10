<?php

namespace App\Controller\App;

use App\Intelligence\Application\JourneyMatchPreviewService;
use App\Intelligence\Application\JourneyMatchView;
use App\Intelligence\Application\ProcessKeyOverviewProvider;
use App\Intelligence\Application\ProcessKeyOverviewRow;
use App\Intelligence\Application\ProcessTemplateProvider;
use App\Intelligence\Domain\ProcessTemplate;
use App\Intelligence\Port\JourneyMatchStore;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Twig\Environment;

/**
 * Journey match editor with live candidate preview.
 *
 * Thin orchestration only: candidate matching and journey checks stay in the
 * existing application services (same chain as
 * intelligence:template:check-journey-documents). GET requests never write -
 * submitted match keys only override the preview. Only the POST save action
 * persists the match, via the JourneyMatchStore port.
 */
final readonly class JourneyMatchController
{
    private const KEY_PATTERN = '/^[A-Za-z0-9._:-]+$/';
    private const CSRF_TOKEN_ID = 'journey_match';

    public function __construct(
        private ProcessTemplateProvider $templateProvider,
        private ProcessKeyOverviewProvider $overviewProvider,
        private JourneyMatchPreviewService $previewService,
        private JourneyMatchStore $matchStore,
        private CsrfTokenManagerInterface $csrfTokenManager,
        private Environment $twig
    ) {
    }

    #[Route('/app/templates/{key}/matching', name: 'app_templates_matching', requirements: ['key' => '[A-Za-z0-9._-]+'], methods: ['GET'])]
    public function edit(string $key, Request $request): Response
    {
        $template = $this->journeyTemplate($key);
        if ($template->scope !== 'journey') {
            return $this->render($this->nonJourneyView($template));
        }

        $selection = $this->selection($request->query->all('keys'), (string) $request->query->get('extraKeys', ''));
        $overrideKeys = $request->query->has('preview') ? $selection['keys'] : null;

        if ($selection['invalidKeys'] !== []) {
            return $this->render($this->view(
                $template,
                null,
                errorMessage: sprintf(
                    'Ungueltige Process Keys (erlaubt sind Buchstaben, Ziffern und . _ : -): %s',
                    implode(', ', $selection['invalidKeys'])
                )
            ));
        }

        return $this->render($this->view(
            $template,
            $overrideKeys,
            saved: $request->query->get('saved') === '1'
        ));
    }

    #[Route('/app/templates/{key}/matching', name: 'app_templates_matching_save', requirements: ['key' => '[A-Za-z0-9._-]+'], methods: ['POST'])]
    public function save(string $key, Request $request): Response
    {
        $template = $this->journeyTemplate($key);
        if ($template->scope !== 'journey') {
            return $this->render($this->nonJourneyView($template));
        }

        $token = new CsrfToken(self::CSRF_TOKEN_ID, (string) $request->request->get('_token', ''));
        if (!$this->csrfTokenManager->isTokenValid($token)) {
            return $this->render(
                $this->view($template, null, errorMessage: 'Die Sitzung ist abgelaufen. Bitte die Auswahl erneut pruefen und nochmals speichern.'),
                Response::HTTP_BAD_REQUEST
            );
        }

        $selection = $this->selection($request->request->all('keys'), (string) $request->request->get('extraKeys', ''));
        if ($selection['invalidKeys'] !== []) {
            return $this->render(
                $this->view($template, null, errorMessage: sprintf(
                    'Nicht gespeichert - ungueltige Process Keys: %s',
                    implode(', ', $selection['invalidKeys'])
                )),
                Response::HTTP_UNPROCESSABLE_ENTITY
            );
        }

        try {
            $this->matchStore->saveMatch($template->key, $selection['keys']);
        } catch (InvalidArgumentException $exception) {
            return $this->render(
                $this->view($template, $selection['keys'], errorMessage: 'Nicht gespeichert: '.$exception->getMessage()),
                Response::HTTP_UNPROCESSABLE_ENTITY
            );
        }

        return new RedirectResponse('/app/templates/'.rawurlencode($template->key).'/matching?saved=1');
    }

    private function journeyTemplate(string $key): ProcessTemplate
    {
        $template = $this->templateProvider->findByProcessKey($key);
        if ($template === null) {
            throw new NotFoundHttpException(sprintf('Template "%s" wurde nicht gefunden.', $key));
        }

        return $template;
    }

    /**
     * @param array<int, string>|null $overrideKeys
     */
    private function view(
        ProcessTemplate $template,
        ?array $overrideKeys,
        bool $saved = false,
        ?string $errorMessage = null
    ): JourneyMatchView {
        $savedMatchKeys = $template->match?->anyProcessKeys ?? [];
        $selectedKeys = $overrideKeys ?? $savedMatchKeys;
        $report = $errorMessage === null
            ? $this->previewService->preview($template, $overrideKeys)
            : null;

        return new JourneyMatchView(
            $template->key,
            $template->name,
            $template->version,
            true,
            $this->options($selectedKeys),
            $savedMatchKeys,
            $report,
            previewOverridden: $overrideKeys !== null,
            saved: $saved,
            errorMessage: $errorMessage
        );
    }

    private function nonJourneyView(ProcessTemplate $template): JourneyMatchView
    {
        return new JourneyMatchView($template->key, $template->name, $template->version, false);
    }

    /**
     * Observed process keys plus any selected-but-unobserved keys, sorted
     * alphabetically, each flagged with its selection and observation state.
     *
     * @param array<int, string> $selectedKeys
     * @return array<int, array{key: string, selected: bool, observed: bool}>
     */
    private function options(array $selectedKeys): array
    {
        $observed = array_map(
            static fn (ProcessKeyOverviewRow $row): string => $row->processKey,
            $this->overviewProvider->processKeys()
        );

        $options = [];
        foreach ($observed as $processKey) {
            $options[$processKey] = ['key' => $processKey, 'selected' => false, 'observed' => true];
        }
        foreach ($selectedKeys as $processKey) {
            $options[$processKey] ??= ['key' => $processKey, 'selected' => false, 'observed' => false];
            $options[$processKey]['selected'] = true;
        }

        ksort($options, SORT_NATURAL | SORT_FLAG_CASE);

        return array_values($options);
    }

    /**
     * Normalizes checkbox values plus the free-text field (comma/whitespace
     * separated) into a unique key list and collects invalid entries.
     *
     * @param array<int|string, mixed> $checkboxKeys
     * @return array{keys: array<int, string>, invalidKeys: array<int, string>}
     */
    private function selection(array $checkboxKeys, string $extraKeysText): array
    {
        $rawKeys = array_map(static fn (mixed $value): string => trim((string) $value), $checkboxKeys);
        foreach (preg_split('/[\s,;]+/', $extraKeysText, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $extraKey) {
            $rawKeys[] = trim($extraKey);
        }

        $keys = [];
        $invalidKeys = [];
        foreach ($rawKeys as $rawKey) {
            if ($rawKey === '') {
                continue;
            }
            if (preg_match(self::KEY_PATTERN, $rawKey) !== 1) {
                $invalidKeys[] = $rawKey;
                continue;
            }
            $keys[] = $rawKey;
        }

        return [
            'keys' => array_values(array_unique($keys)),
            'invalidKeys' => array_values(array_unique($invalidKeys)),
        ];
    }

    private function render(JourneyMatchView $view, int $status = Response::HTTP_OK): Response
    {
        return new Response($this->twig->render('template/matching.html.twig', [
            'active_nav' => 'templates',
            'view' => $view,
        ]), $status);
    }
}
