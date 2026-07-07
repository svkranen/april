<?php

namespace App\Tests\Service\SignatureCheck;

use App\Dto\SignatureCheckOptions;
use App\Service\Amagno\ApiTokenProviderInterface;
use App\Service\Amagno\CredentialStoreInterface;
use App\Service\Amagno\DocumentGatewayInterface;
use App\Service\Amagno\DocumentTagWriter;
use App\Service\Checkpoint\CheckpointStore;
use App\Service\Processing\StampService;
use App\Service\SignatureCheck\AmagnoSignatureCheckService;
use App\SignatureCheck\AmagnoTagValueExtractor;
use App\SignatureCheck\SignatureCompletenessChecker;
use PHPUnit\Framework\TestCase;

class AmagnoSignatureCheckServiceTest extends TestCase
{
    public function testCheckUsesStaticTokenWithoutCredentialProvider(): void
    {
        $documentGateway = $this->createMock(DocumentGatewayInterface::class);
        $documentGateway
            ->expects($this->once())
            ->method('fetchDocuments')
            ->with(
                'magnet',
                200,
                null,
                'static-token',
                'https://amagno.example'
            )
            ->willReturn([]);

        $tokenProvider = $this->createMock(ApiTokenProviderInterface::class);
        $tokenProvider
            ->expects($this->never())
            ->method('tokenForCredential');

        $service = new AmagnoSignatureCheckService(
            defaultBaseUri: 'https://amagno.example',
            defaultCredentialId: 42,
            defaultApiToken: 'static-token',
            defaultApiUsername: null,
            defaultApiPassword: null,
            documentFetcher: $documentGateway,
            tagWriter: $this->createMock(DocumentTagWriter::class),
            stampService: $this->createMock(StampService::class),
            checkpointStore: $this->createMock(CheckpointStore::class),
            tokenProvider: $tokenProvider,
            credentialStore: $this->createMock(CredentialStoreInterface::class),
            tagValueExtractor: new AmagnoTagValueExtractor(),
            checker: new SignatureCompletenessChecker()
        );

        $result = $service->check(new SignatureCheckOptions(
            magnetId: 'magnet',
            requiredTagId: 'required',
            confirmedTagId: 'confirmed',
            dryRun: true
        ));

        self::assertSame(0, $result['document_count']);
        self::assertTrue($result['dry_run']);
    }
}
