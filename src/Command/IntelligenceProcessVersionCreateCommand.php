<?php

namespace App\Command;

use App\Intelligence\Application\ProcessVersionRepository;
use App\Intelligence\Domain\DateTimeNormalizer;
use App\Intelligence\Domain\ProcessVersion;
use DateTimeImmutable;
use Exception;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'intelligence:process-version:create',
    description: 'Creates an Intelligence process version baseline.'
)]
final class IntelligenceProcessVersionCreateCommand extends Command
{
    public function __construct(
        private readonly ProcessVersionRepository $processVersionRepository,
        private readonly DateTimeNormalizer $dateTimeNormalizer = new DateTimeNormalizer()
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('processKey', InputArgument::REQUIRED, 'Process key')
            ->addArgument('version', InputArgument::REQUIRED, 'Process version')
            ->addArgument('validFrom', InputArgument::REQUIRED, 'Baseline datetime')
            ->addOption('description', null, InputOption::VALUE_REQUIRED, 'Optional description');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $processKey = trim((string) $input->getArgument('processKey'));
        $version = trim((string) $input->getArgument('version'));
        if ($processKey === '' || $version === '') {
            $output->writeln('<error>processKey and version must not be empty.</error>');

            return Command::INVALID;
        }

        if ($this->processVersionRepository->findOneByProcessKeyAndVersion($processKey, $version) !== null) {
            $output->writeln(sprintf('<error>Process version "%s" already exists for process "%s".</error>', $version, $processKey));

            return Command::FAILURE;
        }

        try {
            $validFrom = $this->dateTimeNormalizer->parseAmagnoValue((string) $input->getArgument('validFrom'));
        } catch (Exception $exception) {
            $output->writeln(sprintf('<error>Invalid valid_from datetime: %s</error>', $exception->getMessage()));

            return Command::FAILURE;
        }

        $latest = $this->processVersionRepository->latestForProcess($processKey);
        if ($latest !== null && $validFrom <= $latest->validFrom) {
            $output->writeln(sprintf(
                '<error>valid_from must be after latest version "%s" (%s).</error>',
                $latest->version,
                $latest->validFrom->format(DATE_ATOM)
            ));

            return Command::FAILURE;
        }

        $created = $this->processVersionRepository->save(new ProcessVersion(
            null,
            $processKey,
            $version,
            $validFrom,
            $this->description($input->getOption('description')),
            $this->dateTimeNormalizer->nowUtc()
        ));

        $output->writeln(sprintf(
            '<info>Created process version %s/%s valid from %s.</info>',
            $created->processKey,
            $created->version,
            $created->validFrom->format(DATE_ATOM)
        ));

        return Command::SUCCESS;
    }

    private function description(mixed $value): ?string
    {
        $description = $value === null ? '' : trim((string) $value);

        return $description === '' ? null : $description;
    }
}
