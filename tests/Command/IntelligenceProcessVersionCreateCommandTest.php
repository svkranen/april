<?php

namespace App\Tests\Command;

use App\Command\IntelligenceProcessVersionCreateCommand;
use App\Intelligence\Domain\ProcessVersion;
use App\Intelligence\Infrastructure\Process\InMemoryProcessVersionRepository;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class IntelligenceProcessVersionCreateCommandTest extends TestCase
{
    public function testCreatesProcessVersion(): void
    {
        $repository = new InMemoryProcessVersionRepository();
        $tester = new CommandTester(new IntelligenceProcessVersionCreateCommand($repository));

        $exitCode = $tester->execute([
            'processKey' => 'ai-rechnungen',
            'version' => '1.0',
            'validFrom' => '2026-06-01 08:00',
            '--description' => 'Produktivstart',
        ]);

        $created = $repository->findOneByProcessKeyAndVersion('ai-rechnungen', '1.0');
        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertNotNull($created);
        self::assertSame('Produktivstart', $created->description);
        self::assertSame('2026-06-01T06:00:00+00:00', $created->validFrom->format(DATE_ATOM));
        self::assertStringContainsString('Created process version ai-rechnungen/1.0', $tester->getDisplay());
    }

    public function testDuplicateVersionFails(): void
    {
        $repository = new InMemoryProcessVersionRepository([
            new ProcessVersion(null, 'ai-rechnungen', '1.0', new DateTimeImmutable('2026-06-01T08:00:00+00:00')),
        ]);
        $tester = new CommandTester(new IntelligenceProcessVersionCreateCommand($repository));

        $exitCode = $tester->execute([
            'processKey' => 'ai-rechnungen',
            'version' => '1.0',
            'validFrom' => '2026-06-02 08:00',
        ]);

        self::assertSame(Command::FAILURE, $exitCode);
        self::assertStringContainsString('already exists', $tester->getDisplay());
    }

    public function testNonChronologicalVersionFails(): void
    {
        $repository = new InMemoryProcessVersionRepository([
            new ProcessVersion(null, 'ai-rechnungen', '1.0', new DateTimeImmutable('2026-06-10T08:00:00+00:00')),
        ]);
        $tester = new CommandTester(new IntelligenceProcessVersionCreateCommand($repository));

        $exitCode = $tester->execute([
            'processKey' => 'ai-rechnungen',
            'version' => '1.1',
            'validFrom' => '2026-06-09T08:00:00+00:00',
        ]);

        self::assertSame(Command::FAILURE, $exitCode);
        self::assertStringContainsString('valid_from must be after latest version', $tester->getDisplay());
    }
}
