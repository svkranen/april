<?php

namespace App\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\HttpKernel\KernelInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Security\Core\User\InMemoryUser;

#[AsCommand(
    name: 'april:demo:user:create',
    description: 'Creates a local APRIL demo login in .env.local.'
)]
final class AprilDemoUserCreateCommand extends Command
{
    private const DEFAULT_USERNAME = 'admin@example.local';
    private const DEFAULT_PASSWORD = 'april';

    public function __construct(
        private readonly KernelInterface $kernel,
        private readonly UserPasswordHasherInterface $passwordHasher,
        private readonly ?string $envFile = null
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('username', null, InputOption::VALUE_REQUIRED, 'Demo username/email', self::DEFAULT_USERNAME)
            ->addOption('password', null, InputOption::VALUE_REQUIRED, 'Demo password', self::DEFAULT_PASSWORD)
            ->addOption('force', null, InputOption::VALUE_NONE, 'Allow writing the demo user outside dev/test environments');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $environment = $this->kernel->getEnvironment();
        if (!in_array($environment, ['dev', 'test'], true) && $input->getOption('force') !== true) {
            $output->writeln(sprintf(
                '<error>Demo user creation is intended for dev/test only. Current environment: %s. Use --force to override.</error>',
                $environment
            ));

            return Command::FAILURE;
        }

        $username = trim((string) $input->getOption('username'));
        $password = (string) $input->getOption('password');
        if ($username === '' || $password === '') {
            $output->writeln('<error>Username and password must not be empty.</error>');

            return Command::INVALID;
        }

        $path = $this->envFile ?? $this->kernel->getProjectDir().'/.env.local';
        $values = $this->readEnvValues($path);
        $existingUsername = $values['APRIL_APP_USERNAME'] ?? null;
        $existingHash = $values['APRIL_APP_PASSWORD_HASH'] ?? null;

        if (
            $existingUsername === $username
            && $existingHash !== null
            && $this->passwordHasher->isPasswordValid(new InMemoryUser($username, $existingHash, ['ROLE_USER']), $password)
        ) {
            $output->writeln(sprintf('<info>Demo user already exists in %s.</info>', $path));
            $output->writeln(sprintf('username: %s', $username));

            return Command::SUCCESS;
        }

        $hash = $this->passwordHasher->hashPassword(new InMemoryUser($username, null, ['ROLE_USER']), $password);
        $this->writeEnvValues($path, [
            'APRIL_APP_USERNAME' => $username,
            'APRIL_APP_PASSWORD_HASH' => $hash,
        ]);

        $output->writeln(sprintf('<info>Demo user written to %s.</info>', $path));
        $output->writeln(sprintf('username: %s', $username));
        $output->writeln(sprintf('password: %s', $password));
        $output->writeln('hint: Restart the app container or clear the Symfony container if the old env was already loaded.');

        return Command::SUCCESS;
    }

    /**
     * @return array<string, string>
     */
    private function readEnvValues(string $path): array
    {
        if (!is_file($path)) {
            return [];
        }

        $values = [];
        foreach (file($path, FILE_IGNORE_NEW_LINES) ?: [] as $line) {
            if (preg_match('/^([A-Z0-9_]+)=(.*)$/', $line, $matches) !== 1) {
                continue;
            }

            $values[$matches[1]] = $this->unquoteEnvValue($matches[2]);
        }

        return $values;
    }

    /**
     * @param array<string, string> $values
     */
    private function writeEnvValues(string $path, array $values): void
    {
        $lines = is_file($path) ? (file($path, FILE_IGNORE_NEW_LINES) ?: []) : [];
        $written = [];

        foreach ($lines as $index => $line) {
            foreach ($values as $key => $value) {
                if (preg_match('/^'.preg_quote($key, '/').'=/', $line) === 1) {
                    $lines[$index] = $key.'='.$this->quoteEnvValue($value);
                    $written[$key] = true;
                }
            }
        }

        if ($lines !== [] && end($lines) !== '') {
            $lines[] = '';
        }

        foreach ($values as $key => $value) {
            if (!isset($written[$key])) {
                $lines[] = $key.'='.$this->quoteEnvValue($value);
            }
        }

        $directory = dirname($path);
        if ($directory !== '' && $directory !== '.' && !is_dir($directory)) {
            mkdir($directory, 0775, true);
        }

        file_put_contents($path, implode("\n", $lines)."\n");
    }

    private function quoteEnvValue(string $value): string
    {
        return '\''.str_replace('\'', '\'\\\'\'', $value).'\'';
    }

    private function unquoteEnvValue(string $value): string
    {
        $value = trim($value);
        if (strlen($value) >= 2 && $value[0] === '\'' && $value[strlen($value) - 1] === '\'') {
            return str_replace('\'\\\'\'', '\'', substr($value, 1, -1));
        }

        if (strlen($value) >= 2 && $value[0] === '"' && $value[strlen($value) - 1] === '"') {
            return stripcslashes(substr($value, 1, -1));
        }

        return $value;
    }
}
