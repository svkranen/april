<?php

namespace App\Controller\App;

use App\Intelligence\Application\DocumentObservedProcessKeysProvider;
use App\Intelligence\Application\TemplateDraftPageView;
use App\Intelligence\Application\TemplateDraftPreviewBuilder;
use App\Intelligence\Application\TemplateSuggestionScopeResolver;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Twig\Environment;

/**
 * Read-only UI to suggest a template draft from a single document.
 *
 * Thin orchestration only: all suggestion, validation and rendering logic
 * lives in the application layer (TemplateDraftPreviewBuilder). Nothing is
 * written to disk and no draft is persisted.
 */
final readonly class TemplateDraftController
{
    private const KEY_PATTERN = '/^[A-Za-z0-9._:-]+$/';
    private const SCOPES = [
        TemplateSuggestionScopeResolver::SCOPE_PROCESS,
        TemplateSuggestionScopeResolver::SCOPE_JOURNEY,
    ];

    public function __construct(
        private TemplateDraftPreviewBuilder $previewBuilder,
        private DocumentObservedProcessKeysProvider $observedProcessKeysProvider,
        private Environment $twig
    ) {
    }

    #[Route('/app/intelligence/template-draft', name: 'app_intelligence_template_draft', methods: ['GET'])]
    public function create(Request $request): Response
    {
        $input = $this->input($request);
        if ($input['error'] !== null) {
            return $this->render(new TemplateDraftPageView(
                $input['documentUuid'],
                $input['documentVersion'],
                [],
                $input['scope'],
                $input['templateKey'] === '' ? null : $input['templateKey'],
                $input['error']
            ));
        }

        $knownTemplatesByProcessKey = $this->observedProcessKeysProvider->knownTemplatesByProcessKey(
            $input['documentUuid'],
            $input['documentVersion']
        );

        $preview = null;
        if ($input['templateKey'] !== '') {
            $preview = $this->previewBuilder->build(
                $input['documentUuid'],
                $input['templateKey'],
                $input['scope'],
                $input['documentVersion']
            );
        }

        return $this->render(new TemplateDraftPageView(
            $input['documentUuid'],
            $input['documentVersion'],
            $knownTemplatesByProcessKey,
            $input['scope'],
            $input['templateKey'] === '' ? null : $input['templateKey'],
            null,
            $preview
        ));
    }

    #[Route('/app/intelligence/template-draft/download', name: 'app_intelligence_template_draft_download', methods: ['GET'])]
    public function download(Request $request): Response
    {
        $input = $this->input($request);
        if ($input['error'] !== null || $input['templateKey'] === '') {
            return $this->redirectToPage($input);
        }

        $preview = $this->previewBuilder->build(
            $input['documentUuid'],
            $input['templateKey'],
            $input['scope'],
            $input['documentVersion']
        );
        if ($preview->yaml === null) {
            return $this->redirectToPage($input);
        }

        $response = new Response($preview->yaml);
        $response->headers->set('Content-Type', 'application/x-yaml; charset=UTF-8');
        $response->headers->set('Content-Disposition', HeaderUtils::makeDisposition(
            HeaderUtils::DISPOSITION_ATTACHMENT,
            $preview->downloadFilename()
        ));

        return $response;
    }

    /**
     * @return array{documentUuid: string, documentVersion: ?int, scope: string, templateKey: string, error: ?string}
     */
    private function input(Request $request): array
    {
        $documentUuid = trim((string) $request->query->get('documentUuid', ''));
        $scope = strtolower(trim((string) $request->query->get('scope', TemplateSuggestionScopeResolver::SCOPE_PROCESS)));
        $templateKey = $this->templateKey($request, $scope);

        $input = [
            'documentUuid' => $documentUuid,
            'documentVersion' => $this->documentVersion($request),
            'scope' => $scope,
            'templateKey' => $templateKey,
            'error' => null,
        ];

        if ($documentUuid === '' || preg_match(self::KEY_PATTERN, $documentUuid) !== 1) {
            $input['error'] = 'Bitte eine gueltige Document UUID ohne Leerzeichen oder Slash uebergeben.';
        } elseif (!in_array($scope, self::SCOPES, true)) {
            $input['error'] = 'Unbekannter Template-Typ. Bitte Prozess- oder Journey-Template waehlen.';
        } elseif ($templateKey !== '' && preg_match(self::KEY_PATTERN, $templateKey) !== 1) {
            $input['error'] = 'Bitte einen gueltigen Template-Key ohne Leerzeichen oder Slash angeben.';
        }

        return $input;
    }

    /**
     * The key can arrive as generic "templateKey" (deep links) or from the
     * scope-specific form fields (select for process, text input for journey).
     */
    private function templateKey(Request $request, string $scope): string
    {
        $templateKey = trim((string) $request->query->get('templateKey', ''));
        if ($templateKey !== '') {
            return $templateKey;
        }

        $scopeField = $scope === TemplateSuggestionScopeResolver::SCOPE_JOURNEY ? 'journeyKey' : 'processKey';

        return trim((string) $request->query->get($scopeField, ''));
    }

    private function documentVersion(Request $request): ?int
    {
        $raw = trim((string) $request->query->get('documentVersion', ''));
        if ($raw === '' || !ctype_digit($raw)) {
            return null;
        }

        return (int) $raw;
    }

    /**
     * @param array{documentUuid: string, documentVersion: ?int, scope: string, templateKey: string, error: ?string} $input
     */
    private function redirectToPage(array $input): RedirectResponse
    {
        $query = array_filter([
            'documentUuid' => $input['documentUuid'],
            'documentVersion' => $input['documentVersion'],
            'scope' => $input['scope'],
            'templateKey' => $input['templateKey'],
        ], static fn (string|int|null $value): bool => $value !== null && $value !== '');

        return new RedirectResponse('/app/intelligence/template-draft?'.http_build_query($query));
    }

    private function render(TemplateDraftPageView $view): Response
    {
        return new Response($this->twig->render('intelligence/template_draft/create.html.twig', [
            'active_nav' => 'documents',
            'view' => $view,
        ]));
    }
}
