<?php

namespace App\Tests\Intelligence\Domain;

use App\Intelligence\Domain\DocumentRef;
use PHPUnit\Framework\TestCase;

class DocumentRefTest extends TestCase
{
    public function testStoresDocumentIdentity(): void
    {
        $document = new DocumentRef('amagno', 'doc-123', 'uuid-123', 2);

        self::assertSame('amagno', $document->sourceSystem);
        self::assertSame('doc-123', $document->externalId);
        self::assertSame('uuid-123', $document->externalUuid);
        self::assertSame(2, $document->version);
    }
}
