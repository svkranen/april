<?php

namespace App\Controller\App;

use App\Intelligence\Application\AccessCoverageReportBuilder;
use App\Intelligence\Application\DocumentListFindingsProvider;
use App\Intelligence\Application\DocumentListProvider;
use App\Intelligence\Application\DocumentsIndexView;
use App\Intelligence\Application\ProcessTemplateCatalog;
use App\Intelligence\Application\ProcessTemplateProvider;
use App\Intelligence\Application\TemplateAccessView;
use App\Intelligence\Application\TemplateAssistantAnalyzer;
use App\Intelligence\Application\TemplateDetailView;
use App\Intelligence\Application\TemplateModelingSuggestionAnalyzer;
use App\Intelligence\Application\TemplateGraphFindingsProvider;
use App\Intelligence\Application\TemplateMermaidGraphBuilder;
use App\Intelligence\Application\TemplateMermaidGraphView;
use App\Intelligence\Domain\ProcessTemplate;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Annotation\Route;
use Twig\Environment;

/**
 * Server-side (Twig) frontend for the process template catalog.
 * Uses only Application services - never console commands.
 */
final class TemplateController
{
    public function __construct(
        private readonly ProcessTemplateCatalog $catalog,
        private readonly ProcessTemplateProvider $templateProvider,
        private readonly AccessCoverageReportBuilder $coverageBuilder,
        private readonly DocumentListProvider $documentListProvider,
        private readonly DocumentListFindingsProvider $documentListFindingsProvider,
        private readonly TemplateGraphFindingsProvider $graphFindingsProvider,
        private readonly TemplateMermaidGraphBuilder $graphBuilder,
        private readonly TemplateAssistantAnalyzer $assistantAnalyzer,
        private readonly TemplateModelingSuggestionAnalyzer $modelingSuggestionAnalyzer,
        private readonly Environment $twig,
        private readonly string $processTemplateDirectory
    ) {
    }

    private const FINDINGS_LIMIT = 50;

    #[Route('/app', name: 'app_home', methods: ['GET'])]
    public function home(): Response
    {
        return new RedirectResponse('/app/templates');
    }

    #[Route('/app/templates', name: 'app_templates_index', methods: ['GET'])]
    public function index(): Response
    {
        $catalog = $this->catalog->list();

        return new Response($this->twig->render('template/index.html.twig', [
            'active_nav' => 'templates',
            'entries' => $catalog->entries,
            'warnings' => $catalog->warnings,
        ]));
    }

    #[Route('/app/templates/{key}', name: 'app_templates_show', requirements: ['key' => '[A-Za-z0-9._-]+'], methods: ['GET'])]
    public function show(string $key): Response
    {
        $template = $this->templateProvider->findByProcessKey($key);
        if ($template === null) {
            throw new NotFoundHttpException($this->notFoundMessage($key));
        }

        return new Response($this->twig->render('template/show.html.twig', [
            'active_nav' => 'templates',
            'view' => TemplateDetailView::fromTemplate($template),
        ]));
    }

    #[Route('/app/templates/{key}/assistant', name: 'app_templates_assistant', requirements: ['key' => '[A-Za-z0-9._-]+'], methods: ['GET'])]
    public function assistant(string $key, Request $request): Response
    {
        $template = $this->templateProvider->findByProcessKey($key);
        if ($template === null) {
            throw new NotFoundHttpException($this->notFoundMessage($key));
        }

        // Read-only assistance: derive the conventional YAML path for display only;
        // no file is read or written here.
        $filePath = rtrim($this->processTemplateDirectory, '/').'/'.$template->key.'.yaml';

        // Modelling suggestions need on-demand findings and are opt-in: without
        // withFindings=1 we never read any document - the page shows a hint/link.
        $findings = null;
        if ($request->query->getBoolean('withFindings')) {
            $rows = $this->documentListProvider->documentsForProcess($template->key, 200);
            $findings = $this->graphFindingsProvider->aggregate(
                $template,
                array_map(static fn ($row): string => $row->documentUuid, $rows),
                self::FINDINGS_LIMIT
            );
        }

        return new Response($this->twig->render('template/assistant.html.twig', [
            'active_nav' => 'templates',
            'view' => $this->assistantAnalyzer->analyze($template, $filePath),
            'suggestions' => $this->modelingSuggestionAnalyzer->fromFindings($findings),
        ]));
    }

    #[Route('/app/templates/{key}/access', name: 'app_templates_access', requirements: ['key' => '[A-Za-z0-9._-]+'], methods: ['GET'])]
    public function access(string $key): Response
    {
        $template = $this->templateProvider->findByProcessKey($key);
        if ($template === null) {
            throw new NotFoundHttpException($this->notFoundMessage($key));
        }

        $report = $this->coverageBuilder->build($template);

        return new Response($this->twig->render('template/access.html.twig', [
            'active_nav' => 'templates',
            'view' => TemplateAccessView::fromTemplate($template, $report),
        ]));
    }

    #[Route('/app/templates/{key}/documents', name: 'app_templates_documents', requirements: ['key' => '[A-Za-z0-9._-]+'], methods: ['GET'])]
    public function documents(string $key, Request $request): Response
    {
        $template = $this->templateProvider->findByProcessKey($key);
        if ($template === null) {
            throw new NotFoundHttpException($this->notFoundMessage($key));
        }

        $rows = $this->documentListProvider->documentsForProcess($template->key, 200);

        // Optional graph filters (coming from the process graph): step, decision
        // gateway or observed transition. They are mutually exclusive (step wins,
        // then decision, then transition); each only honours valid values and each
        // implies withFindings - the smallest, clearest behaviour. Unknown values
        // are ignored rather than producing an error.
        $stepKey = $this->resolveStepKey($template, $request->query->get('step'));
        $decisionKey = $stepKey === null
            ? $this->resolveDecisionKey($template, $request->query->get('decision'))
            : null;
        $transition = ($stepKey === null && $decisionKey === null)
            ? $this->resolveTransition($request->query->get('transitionFrom'), $request->query->get('transitionTo'))
            : null;

        $stepLabel = $stepKey !== null ? $this->stepName($template, $stepKey) : null;
        $decisionLabel = $decisionKey;
        $transitionLabel = $transition !== null
            ? $this->stepName($template, $transition['from']).' → '.$this->stepName($template, $transition['to'])
            : null;

        $withFindings = $request->query->getBoolean('withFindings')
            || $stepKey !== null
            || $decisionKey !== null
            || $transition !== null;
        $findings = [];
        if ($withFindings) {
            $findings = $this->documentListFindingsProvider->forDocuments(
                $template,
                array_map(static fn ($row): string => $row->documentUuid, $rows),
                self::FINDINGS_LIMIT
            );
        }

        return new Response($this->twig->render('template/documents.html.twig', [
            'active_nav' => 'templates',
            'key' => $template->key,
            'version' => $template->version,
            'index' => DocumentsIndexView::build(
                $rows,
                $withFindings,
                $findings,
                $request->query->get('severity'),
                self::FINDINGS_LIMIT,
                $stepKey,
                $stepLabel,
                $decisionKey,
                $decisionLabel,
                $transition['from'] ?? null,
                $transition['to'] ?? null,
                $transitionLabel
            ),
        ]));
    }

    #[Route('/app/templates/{key}/graph', name: 'app_templates_graph', requirements: ['key' => '[A-Za-z0-9._-]+'], methods: ['GET'])]
    public function graph(string $key, Request $request): Response
    {
        $template = $this->templateProvider->findByProcessKey($key);
        if ($template === null) {
            throw new NotFoundHttpException($this->notFoundMessage($key));
        }

        // Findings are opt-in: without withFindings=1 we never read any document.
        $withFindings = $request->query->getBoolean('withFindings');
        $findings = null;
        if ($withFindings) {
            $rows = $this->documentListProvider->documentsForProcess($template->key, 200);
            $findings = $this->graphFindingsProvider->aggregate(
                $template,
                array_map(static fn ($row): string => $row->documentUuid, $rows),
                self::FINDINGS_LIMIT
            );
        }

        return new Response($this->twig->render('template/graph.html.twig', [
            'active_nav' => 'templates',
            'view' => TemplateMermaidGraphView::build(
                $template,
                $withFindings,
                $findings,
                $this->graphBuilder->build($template, $findings),
                self::FINDINGS_LIMIT
            ),
        ]));
    }

    /**
     * Returns the trimmed step key only if the template declares it, otherwise null.
     */
    private function resolveStepKey(ProcessTemplate $template, ?string $rawStep): ?string
    {
        $stepKey = $rawStep !== null ? trim($rawStep) : '';
        if ($stepKey === '') {
            return null;
        }

        foreach ($template->steps as $step) {
            if ($step->key === $stepKey) {
                return $stepKey;
            }
        }

        return null;
    }

    /**
     * Returns the trimmed decision key only if the template declares a decision
     * point with that key, otherwise null (conservative: unknown -> ignored).
     */
    private function resolveDecisionKey(ProcessTemplate $template, ?string $rawDecision): ?string
    {
        $decisionKey = $rawDecision !== null ? trim($rawDecision) : '';
        if ($decisionKey === '') {
            return null;
        }

        foreach ($template->decisionPoints as $decisionPoint) {
            if ($decisionPoint->key === $decisionKey) {
                return $decisionKey;
            }
        }

        return null;
    }

    /**
     * Returns the observed transition only if both endpoints are present; the
     * actual (Ist) target may be an unexpected step, so it is not validated against
     * the template - matching is done structurally against the findings.
     *
     * @return array{from: string, to: string}|null
     */
    private function resolveTransition(?string $rawFrom, ?string $rawTo): ?array
    {
        $from = $rawFrom !== null ? trim($rawFrom) : '';
        $to = $rawTo !== null ? trim($rawTo) : '';
        if ($from === '' || $to === '') {
            return null;
        }

        return ['from' => $from, 'to' => $to];
    }

    private function stepName(ProcessTemplate $template, string $stepKey): string
    {
        foreach ($template->steps as $step) {
            if ($step->key === $stepKey) {
                return $step->name ?? $step->key;
            }
        }

        return $stepKey;
    }

    private function notFoundMessage(string $key): string
    {
        return sprintf(
            'Template "%s" not found in configured APRIL process template directory "%s".',
            $key,
            $this->processTemplateDirectory
        );
    }
}
