<?php

namespace App\Tests\Service\Amagno;

use App\Service\Amagno\ApiTokenProviderInterface;
use App\Service\Amagno\CommunityApiTokenProvider;
use App\Service\Amagno\DocumentFetcher;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

class DocumentFetcherTest extends TestCase
{
    public function testUsesTokenOverride(): void
    {
        $headers = null;
        $fetcher = new DocumentFetcher(
            $this->httpClientRecordingHeaders($headers),
            'https://amagno.example',
            'static-token'
        );

        $fetcher->fetchDocumentTags('doc-1', 'override-token');

        self::assertSame('Authorization: Bearer override-token', $headers['authorization'][0] ?? null);
    }

    public function testBuildsDocumentTagUrlFromRootBaseUri(): void
    {
        $url = null;
        $fetcher = new DocumentFetcher(
            $this->httpClientRecordingRequest($url),
            'https://amagno.example',
            'static-token'
        );

        $fetcher->fetchDocumentTags('doc-1');

        self::assertSame('https://amagno.example/api/v2/documents/doc-1/tags', $url);
    }

    public function testBuildsDocumentTagUrlFromApiBaseUriWithoutDuplicatingPath(): void
    {
        $url = null;
        $fetcher = new DocumentFetcher(
            $this->httpClientRecordingRequest($url),
            'https://amagno.example/api/v2/',
            'static-token'
        );

        $fetcher->fetchDocumentTags('doc-1');

        self::assertSame('https://amagno.example/api/v2/documents/doc-1/tags', $url);
    }

    public function testBuildsDocumentTagUrlFromApiBaseUriOverrideWithoutDuplicatingPath(): void
    {
        $url = null;
        $fetcher = new DocumentFetcher(
            $this->httpClientRecordingRequest($url),
            'https://fallback.example/api/v2',
            'static-token'
        );

        $fetcher->fetchDocumentTags('doc-1', baseUriOverride: 'https://amagno.example/api/v2/');

        self::assertSame('https://amagno.example/api/v2/documents/doc-1/tags', $url);
    }

    public function testBuildsTagDefinitionsUrl(): void
    {
        $url = null;
        $fetcher = new DocumentFetcher(
            $this->httpClientRecordingRequest($url),
            'https://amagno.example/api/v2/',
            'static-token'
        );

        $fetcher->fetchTagDefinitions();

        self::assertSame('https://amagno.example/api/v2/documents/tag-definitions', $url);
    }

    public function testBuildsMagnetDocumentsUrlWithCountAndOffset(): void
    {
        $url = null;
        $fetcher = new DocumentFetcher(
            $this->httpClientRecordingRequest($url),
            'https://amagno.example/api/v2/',
            'static-token'
        );

        $fetcher->fetchDocuments('1001', 25, offset: 50);

        self::assertSame('https://amagno.example/api/v2/magnets/1001/documents?count=25&offset=50', $url);
    }

    public function testUsesStaticApiToken(): void
    {
        $headers = null;
        $tokenProvider = $this->createMock(ApiTokenProviderInterface::class);
        $tokenProvider
            ->expects(self::never())
            ->method('tokenForCredential');

        $fetcher = new DocumentFetcher(
            $this->httpClientRecordingHeaders($headers),
            'https://amagno.example',
            'static-token',
            $tokenProvider,
            42
        );

        $fetcher->fetchDocumentTags('doc-1');

        self::assertSame('Authorization: Bearer static-token', $headers['authorization'][0] ?? null);
    }

    public function testCommunityTokenProviderRejectsCredentialBasedTokenFetch(): void
    {
        $fetcher = new DocumentFetcher(
            new MockHttpClient(new MockResponse('{}')),
            'https://amagno.example',
            null,
            new CommunityApiTokenProvider(),
            42
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Credential-basierter Connector-Tokenabruf ist im Community-Default nicht verfuegbar. Konfiguriere einen statischen Token oder aktiviere einen optionalen Connector fuer Credential-ID 42.');

        $fetcher->fetchDocumentTags('doc-1');
    }

    public function testUsesTokenProviderAndCredentialId(): void
    {
        $headers = null;
        $tokenProvider = $this->createMock(ApiTokenProviderInterface::class);
        $tokenProvider
            ->expects(self::once())
            ->method('tokenForCredential')
            ->with(42)
            ->willReturn('provider-token');

        $fetcher = new DocumentFetcher(
            $this->httpClientRecordingHeaders($headers),
            'https://amagno.example',
            null,
            $tokenProvider,
            42
        );

        $fetcher->fetchDocumentTags('doc-1');

        self::assertSame('Authorization: Bearer provider-token', $headers['authorization'][0] ?? null);
    }

    public function testUsesCredentialIdOverrideForTokenProvider(): void
    {
        $headers = null;
        $tokenProvider = $this->createMock(ApiTokenProviderInterface::class);
        $tokenProvider
            ->expects(self::once())
            ->method('tokenForCredential')
            ->with(7)
            ->willReturn('connection-token');

        $fetcher = new DocumentFetcher(
            $this->httpClientRecordingHeaders($headers),
            'https://amagno.example',
            null,
            $tokenProvider,
            42
        );

        $fetcher->fetchDocumentTags('doc-1', credentialIdOverride: 7);

        self::assertSame('Authorization: Bearer connection-token', $headers['authorization'][0] ?? null);
    }

    public function testThrowsClearExceptionWhenNoTokenIsAvailable(): void
    {
        $fetcher = new DocumentFetcher(
            new MockHttpClient(new MockResponse('{}')),
            'https://amagno.example'
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Kein Connector API Token verfügbar. Konfiguriere einen statischen Token oder eine optionale Connector-Credential.');

        $fetcher->fetchDocumentTags('doc-1');
    }

    /**
     * @param array<string, array<int, string>>|null $headers
     */
    private function httpClientRecordingHeaders(?array &$headers): MockHttpClient
    {
        return new MockHttpClient(static function (string $method, string $url, array $options) use (&$headers): MockResponse {
            $headers = $options['normalized_headers'] ?? [];

            return new MockResponse('{}');
        });
    }

    private function httpClientRecordingRequest(?string &$recordedUrl): MockHttpClient
    {
        return new MockHttpClient(static function (string $method, string $url) use (&$recordedUrl): MockResponse {
            $recordedUrl = $url;

            return new MockResponse('{}');
        });
    }
}
