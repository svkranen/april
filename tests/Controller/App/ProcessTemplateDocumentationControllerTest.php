<?php

namespace App\Tests\Controller\App;

use App\Command\IntelligenceTemplateDocumentCommand;
use App\Intelligence\Application\ProcessTemplateProvider;
use App\Intelligence\Domain\ProcessTemplate;
use App\Intelligence\Domain\ProcessTemplateArrayFactory;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class ProcessTemplateDocumentationControllerTest extends AppWebTestCase
{
    public function testDocumentationRouteReturnsSameHtmlAsCli(): void
    {
        $client = self::createAuthenticatedClient();

        $command = static::getContainer()->get(IntelligenceTemplateDocumentCommand::class);
        self::assertInstanceOf(IntelligenceTemplateDocumentCommand::class, $command);
        $tester = new CommandTester($command);
        self::assertSame(Command::SUCCESS, $tester->execute([
            'processKey' => 'ai-rechnungen',
            '--format' => 'html',
        ]));

        $client->request('GET', '/app/templates/ai-rechnungen/documentation');

        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Content-Type', 'text/html; charset=utf-8');
        self::assertSame(
            $this->withoutGeneratedAt($tester->getDisplay()),
            $this->withoutGeneratedAt((string) $client->getResponse()->getContent())
        );
        self::assertStringContainsString('<title>AI Rechnungen Demo – APRIL Prozessdokumentation</title>', $tester->getDisplay());
        self::assertStringContainsString('| processKey |', str_replace(['<td>', '</td>'], ['| ', ' |'], $tester->getDisplay()));
    }

    public function testUnknownTemplateReturns404WithoutFilesystemDetails(): void
    {
        $client = self::createAuthenticatedClient();
        $client->request('GET', '/app/templates/does-not-exist/documentation');

        self::assertResponseStatusCodeSame(404);
        self::assertStringNotContainsString('config/april/process-templates', (string) $client->getResponse()->getContent());
    }

    public function testDocumentationEscapesDynamicTemplateContent(): void
    {
        $client = self::createAuthenticatedClient();
        static::getContainer()->set(ProcessTemplateProvider::class, $this->provider(
            ProcessTemplateArrayFactory::fromArray([
                'key' => 'unsafe',
                'name' => '<script>alert("x")</script>',
                'steps' => [['key' => 'receive<script>', 'name' => 'Receive & review']],
            ])
        ));

        $client->request('GET', '/app/templates/unsafe/documentation');

        self::assertResponseIsSuccessful();
        $html = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('&lt;script&gt;alert(&quot;x&quot;)&lt;/script&gt;', $html);
        self::assertStringContainsString('receive&lt;script&gt;', $html);
        self::assertStringContainsString('Receive &amp; review', $html);
        self::assertStringNotContainsString('<script>alert', $html);
    }

    public function testTemplateDetailLinksToProcessDocumentationWithSameKey(): void
    {
        $client = self::createAuthenticatedClient();
        $client->request('GET', '/app/templates/ai-rechnungen');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('a[href="/app/templates/ai-rechnungen/documentation"]');
        self::assertSelectorTextContains('a[href="/app/templates/ai-rechnungen/documentation"]', 'Prozessdokumentation');
    }

    private function provider(ProcessTemplate $template): ProcessTemplateProvider
    {
        return new class($template) implements ProcessTemplateProvider {
            public function __construct(private readonly ProcessTemplate $template) {}

            public function findByProcessKey(string $processKey): ?ProcessTemplate
            {
                return $processKey === $this->template->key ? $this->template : null;
            }
        };
    }

    private function withoutGeneratedAt(string $html): string
    {
        return preg_replace(
            '/(<td>Generiert am<\/td><td>)[^<]+(<\/td>)/',
            '$1[generated-at]$2',
            $html
        ) ?? $html;
    }
}
