<?php

namespace App\Controller\App;

use App\Intelligence\Application\KpiPeriod;
use App\Intelligence\Application\ProcessKpiPageProvider;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use OutOfBoundsException;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Twig\Environment;

final readonly class TemplateKpiController
{
    public function __construct(
        private ProcessKpiPageProvider $pages,
        private Environment $twig,
        private string $reportingTimezone = 'Europe/Berlin'
    )
    {
    }

    #[Route('/app/templates/{key}/kpi', name: 'app_templates_kpi', requirements: ['key' => '[A-Za-z0-9._-]+'], methods: ['GET'])]
    public function show(string $key, Request $request): Response
    {
        $today = new DateTimeImmutable('today', new DateTimeZone($this->reportingTimezone));
        try {
            $period = KpiPeriod::fromDates($request->query->getString('from', $today->modify('first day of this month')->format('Y-m-d')),
                $request->query->getString('to', $today->format('Y-m-d')), $this->reportingTimezone);
            $version = trim($request->query->getString('version'));
            if ($version !== '' && preg_match('/^[A-Za-z0-9._-]{1,64}$/D', $version) !== 1) {
                throw new InvalidArgumentException('Invalid process version.');
            }
        } catch (InvalidArgumentException) {
            return new Response($this->twig->render('template/kpi_error.html.twig', ['active_nav' => 'templates', 'key' => $key]), 400);
        }
        try {
            $page = $this->pages->build($key, $period, $version === '' ? null : $version);
        } catch (OutOfBoundsException $exception) {
            throw new NotFoundHttpException('Process template not found.', $exception);
        }

        return new Response($this->twig->render('template/kpi.html.twig', ['active_nav' => 'templates', 'page' => $page]));
    }
}
