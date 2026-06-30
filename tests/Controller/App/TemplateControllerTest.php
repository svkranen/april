<?php

namespace App\Tests\Controller\App;

use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class TemplateControllerTest extends AppWebTestCase
{
    public function testTemplatesIndexReturns200AndRendersBaseLayout(): void
    {
        $client = self::createAuthenticatedClient();
        $client->request('GET', '/app/templates');

        self::assertResponseIsSuccessful();

        $html = (string) $client->getResponse()->getContent();
        // Base layout (templates/web/layout/base.html.twig) was rendered.
        self::assertStringContainsString('<!DOCTYPE html>', $html);
        self::assertStringContainsString('lang="de"', $html);
        // Navigation contains "Templates".
        self::assertSelectorTextContains('nav.app-nav', 'Templates');
        // Real process template under config/april/process-templates/ is discovered by the catalog.
        self::assertStringContainsString('ai-rechnungen', $html);
        self::assertStringContainsString('config/april/process-templates/ai-rechnungen.yaml', $html);
        self::assertStringNotContainsString('/templates/ai-rechnungen.yaml', $html);
    }

    public function testHomeRedirectsToTemplates(): void
    {
        $client = self::createAuthenticatedClient();
        $client->request('GET', '/app');

        self::assertResponseRedirects('/app/templates');
    }

    public function testUnknownTemplateSubrouteReturns404(): void
    {
        $client = self::createAuthenticatedClient();
        $client->request('GET', '/app/templates/this-does-not-exist');

        self::assertResponseStatusCodeSame(404);
    }

    public function testTemplateDetailReturns200AndShowsTemplate(): void
    {
        $client = self::createAuthenticatedClient();
        $client->request('GET', '/app/templates/ai-rechnungen');

        self::assertResponseIsSuccessful();

        $html = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('<!DOCTYPE html>', $html);
        // Template identity + version.
        self::assertStringContainsString('ai-rechnungen', $html);
        self::assertStringContainsString('1.1', $html);
        // Required steps section + a known step from the template.
        self::assertStringContainsString('Required Steps', $html);
        self::assertStringContainsString('01 Rechnungen pruefen', $html);
        // Access short summary (no full coverage matrix in this step).
        self::assertStringContainsString('Kurzfassung', $html);
        self::assertStringContainsString('Access Probes', $html);
    }

    public function testTemplateDetailUnknownKeyReturns404(): void
    {
        $client = self::createAuthenticatedClient();
        $client->request('GET', '/app/templates/does-not-exist-xyz');

        self::assertResponseStatusCodeSame(404);
    }

    public function testTemplateDetailUnknownKeyReportsProcessTemplateDirectory(): void
    {
        $client = self::createAuthenticatedClient();
        $client->catchExceptions(false);

        try {
            $client->request('GET', '/app/templates/does-not-exist-xyz');
            self::fail('Expected NotFoundHttpException.');
        } catch (NotFoundHttpException $exception) {
            self::assertStringContainsString('does-not-exist-xyz', $exception->getMessage());
            self::assertStringContainsString('config/april/process-templates', $exception->getMessage());
        }
    }

    public function testFrontendDoesNotRequireProcessTemplateUnderTwigTemplatesDirectory(): void
    {
        self::assertFileExists(dirname(__DIR__, 3).'/config/april/process-templates/ai-rechnungen.yaml');
        self::assertFileDoesNotExist(dirname(__DIR__, 3).'/templates/ai-rechnungen.yaml');

        $client = self::createAuthenticatedClient();
        $client->request('GET', '/app/templates/ai-rechnungen');

        self::assertResponseIsSuccessful();
    }

    public function testTemplateDetailHasActiveAccessLink(): void
    {
        $client = self::createAuthenticatedClient();
        $client->request('GET', '/app/templates/ai-rechnungen');

        self::assertResponseIsSuccessful();
        // Active (non-disabled) link to the access page.
        self::assertSelectorExists('a.pill-link[href="/app/templates/ai-rechnungen/access"]');
    }

    public function testAccessPageReturns200AndShowsSections(): void
    {
        $client = self::createAuthenticatedClient();
        $client->request('GET', '/app/templates/ai-rechnungen/access');

        self::assertResponseIsSuccessful();

        $html = (string) $client->getResponse()->getContent();
        // Compliance notice (not dismissible).
        self::assertStringContainsString('Amagno-ACL', $html);
        // Coverage summary.
        self::assertStringContainsString('Coverage-Zusammenfassung', $html);
        self::assertStringContainsString('automatic', $html);
        // Probe, resolver and manual test from the real template.
        self::assertStringContainsString('approval_location_a_today', $html);
        self::assertStringContainsString('approval_location_by_context', $html);
        self::assertStringContainsString('approver_scope_test', $html);
    }

    public function testAccessPageUnknownKeyReturns404(): void
    {
        $client = self::createAuthenticatedClient();
        $client->request('GET', '/app/templates/does-not-exist-xyz/access');

        self::assertResponseStatusCodeSame(404);
    }

    public function testDocsPageReturns200WithIframeAndDownloads(): void
    {
        $client = self::createAuthenticatedClient();
        $client->request('GET', '/app/templates/ai-rechnungen/docs');

        self::assertResponseIsSuccessful();

        $html = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('Amagno-ACL', $html); // compliance notice
        self::assertStringContainsString('on-demand', $html);  // generation note
        self::assertSelectorExists('iframe[src="/app/templates/ai-rechnungen/docs/preview"]');
        self::assertSelectorExists('a[href="/app/templates/ai-rechnungen/docs/download?format=md"]');
        self::assertSelectorExists('a[href="/app/templates/ai-rechnungen/docs/download?format=html"]');
    }

    public function testDocsPreviewReturnsStandaloneHtml(): void
    {
        $client = self::createAuthenticatedClient();
        $client->request('GET', '/app/templates/ai-rechnungen/docs/preview');

        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Content-Type', 'text/html; charset=utf-8');
        self::assertStringContainsStringIgnoringCase('<!doctype html>', (string) $client->getResponse()->getContent());
    }

    public function testDocsDownloadMarkdown(): void
    {
        $client = self::createAuthenticatedClient();
        $client->request('GET', '/app/templates/ai-rechnungen/docs/download?format=md');

        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Content-Type', 'text/markdown; charset=utf-8');
        self::assertStringContainsString(
            'attachment; filename=ai-rechnungen-access.md',
            (string) $client->getResponse()->headers->get('Content-Disposition')
        );
        self::assertStringContainsString('Access-/Visibility-Dokumentation', (string) $client->getResponse()->getContent());
    }

    public function testDocsDownloadHtml(): void
    {
        $client = self::createAuthenticatedClient();
        $client->request('GET', '/app/templates/ai-rechnungen/docs/download?format=html');

        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Content-Type', 'text/html; charset=utf-8');
        self::assertStringContainsString(
            'attachment; filename=ai-rechnungen-access.html',
            (string) $client->getResponse()->headers->get('Content-Disposition')
        );
        self::assertStringContainsStringIgnoringCase('<!doctype html>', (string) $client->getResponse()->getContent());
    }

    public function testDocsDownloadInvalidFormatReturns400(): void
    {
        $client = self::createAuthenticatedClient();
        $client->request('GET', '/app/templates/ai-rechnungen/docs/download?format=pdf');

        self::assertResponseStatusCodeSame(400);
    }

    public function testDocsRoutesUnknownKeyReturn404(): void
    {
        $client = self::createAuthenticatedClient();

        $client->request('GET', '/app/templates/does-not-exist-xyz/docs');
        self::assertResponseStatusCodeSame(404);

        $client->request('GET', '/app/templates/does-not-exist-xyz/docs/preview');
        self::assertResponseStatusCodeSame(404);

        $client->request('GET', '/app/templates/does-not-exist-xyz/docs/download?format=md');
        self::assertResponseStatusCodeSame(404);
    }

    public function testDetailPageHasActiveDocsLink(): void
    {
        $client = self::createAuthenticatedClient();
        $client->request('GET', '/app/templates/ai-rechnungen');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('a.pill-link[href="/app/templates/ai-rechnungen/docs"]');
    }

    public function testAccessPageHasDocsLink(): void
    {
        $client = self::createAuthenticatedClient();
        $client->request('GET', '/app/templates/ai-rechnungen/access');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('a[href="/app/templates/ai-rechnungen/docs"]');
    }

    public function testAssistantPageReturns200AndShowsSections(): void
    {
        $client = self::createAuthenticatedClient();
        $client->request('GET', '/app/templates/ai-rechnungen/assistant');

        self::assertResponseIsSuccessful();

        $html = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('Template-Assistent', $html);
        self::assertStringContainsString('Übersicht', $html);
        self::assertStringContainsString('Schritte', $html);
        self::assertStringContainsString('Übergänge', $html);
        self::assertStringContainsString('Konsistenzprüfung', $html);
        // A real step from the template is listed.
        self::assertStringContainsString('01 Rechnungen pruefen', $html);
        // Read-only assurance is communicated.
        self::assertStringContainsString('keine automatischen Änderungen', $html);
    }

    public function testAssistantPageUnknownKeyReturns404(): void
    {
        $client = self::createAuthenticatedClient();
        $client->request('GET', '/app/templates/does-not-exist-xyz/assistant');

        self::assertResponseStatusCodeSame(404);
    }

    public function testDetailPageHasAssistantLink(): void
    {
        $client = self::createAuthenticatedClient();
        $client->request('GET', '/app/templates/ai-rechnungen');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('a.pill-link[href="/app/templates/ai-rechnungen/assistant"]');
    }

    public function testAssistantSeparatesTechnicalChecksFromModellingSuggestions(): void
    {
        $client = self::createAuthenticatedClient();
        $client->request('GET', '/app/templates/ai-rechnungen/assistant');

        self::assertResponseIsSuccessful();

        $html = (string) $client->getResponse()->getContent();
        // Both distinct sections exist and are clearly separated.
        self::assertStringContainsString('Technische Konsistenzprüfung', $html);
        self::assertStringContainsString('Änderungsvorschläge / Modellierungsentscheidungen', $html);
    }

    public function testAssistantWithoutFindingsOffersComputeLinkAndDoesNotRunChecks(): void
    {
        $client = self::createAuthenticatedClient();
        $client->request('GET', '/app/templates/ai-rechnungen/assistant');

        self::assertResponseIsSuccessful();
        // Smaller clean solution: a link to compute findings instead of a runtime check.
        self::assertSelectorExists('a.pill-link[href="/app/templates/ai-rechnungen/assistant?withFindings=1"]');

        $html = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('nicht automatisch aktiv', $html);
    }
}
