<?php

namespace App\Tests\Command;

use App\Command\AprilDemoUserCreateCommand;
use App\Security\EnvUserProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\HttpKernel\KernelInterface;
use Symfony\Component\PasswordHasher\Hasher\PasswordHasherFactory;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasher;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Security\Core\User\InMemoryUser;

final class AprilDemoUserCreateCommandTest extends TestCase
{
    public function testCreatesDemoUserEnvFileWithWorkingPasswordHash(): void
    {
        $envFile = $this->temporaryEnvFile();
        $hasher = $this->hasher();
        $tester = new CommandTester($this->command($envFile, 'dev', $hasher));

        $exitCode = $tester->execute([]);

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertStringContainsString('username: admin@example.local', $tester->getDisplay());
        self::assertStringContainsString('password: april', $tester->getDisplay());

        $values = $this->readEnvFile($envFile);
        self::assertSame('admin@example.local', $values['APRIL_APP_USERNAME']);
        self::assertTrue($hasher->isPasswordValid(
            new InMemoryUser('admin@example.local', $values['APRIL_APP_PASSWORD_HASH'], ['ROLE_USER']),
            'april'
        ));

        $provider = new EnvUserProvider($values['APRIL_APP_USERNAME'], $values['APRIL_APP_PASSWORD_HASH']);
        $user = $provider->loadUserByIdentifier('admin@example.local');
        self::assertSame(['ROLE_USER'], $user->getRoles());
    }

    public function testRepeatedRunIsIdempotentWhenUserAndPasswordAlreadyMatch(): void
    {
        $envFile = $this->temporaryEnvFile();
        $tester = new CommandTester($this->command($envFile));
        $tester->execute([]);
        $firstContents = (string) file_get_contents($envFile);

        $exitCode = $tester->execute([]);
        $secondContents = (string) file_get_contents($envFile);

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertSame($firstContents, $secondContents);
        self::assertStringContainsString('Demo user already exists', $tester->getDisplay());
    }

    public function testUpdatesExistingDemoUserHashWhenPasswordDiffers(): void
    {
        $envFile = $this->temporaryEnvFile();
        mkdir(dirname($envFile), 0777, true);
        file_put_contents($envFile, "APRIL_APP_USERNAME='admin@example.local'\nAPRIL_APP_PASSWORD_HASH='old-hash'\n");
        $tester = new CommandTester($this->command($envFile));

        $exitCode = $tester->execute([]);

        $values = $this->readEnvFile($envFile);
        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertNotSame('old-hash', $values['APRIL_APP_PASSWORD_HASH']);
    }

    public function testRejectsProductionEnvironmentWithoutForce(): void
    {
        $envFile = $this->temporaryEnvFile();
        $tester = new CommandTester($this->command($envFile, 'prod'));

        $exitCode = $tester->execute([]);

        self::assertSame(Command::FAILURE, $exitCode);
        self::assertFileDoesNotExist($envFile);
        self::assertStringContainsString('intended for dev/test only', $tester->getDisplay());
    }

    public function testForceAllowsProductionEnvironment(): void
    {
        $envFile = $this->temporaryEnvFile();
        $tester = new CommandTester($this->command($envFile, 'prod'));

        $exitCode = $tester->execute(['--force' => true]);

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertFileExists($envFile);
    }

    private function command(
        string $envFile,
        string $environment = 'dev',
        ?UserPasswordHasherInterface $hasher = null
    ): AprilDemoUserCreateCommand {
        $kernel = $this->createStub(KernelInterface::class);
        $kernel->method('getEnvironment')->willReturn($environment);
        $kernel->method('getProjectDir')->willReturn(dirname(__DIR__, 2));

        return new AprilDemoUserCreateCommand($kernel, $hasher ?? $this->hasher(), $envFile);
    }

    private function hasher(): UserPasswordHasherInterface
    {
        return new UserPasswordHasher(new PasswordHasherFactory([
            InMemoryUser::class => ['algorithm' => 'auto'],
        ]));
    }

    private function temporaryEnvFile(): string
    {
        return sys_get_temp_dir().'/april-demo-user-'.bin2hex(random_bytes(8)).'/.env.local';
    }

    /**
     * @return array<string, string>
     */
    private function readEnvFile(string $path): array
    {
        $values = [];
        foreach (file($path, FILE_IGNORE_NEW_LINES) ?: [] as $line) {
            if (preg_match('/^([A-Z0-9_]+)=\'(.*)\'$/', $line, $matches) === 1) {
                $values[$matches[1]] = str_replace('\'\\\'\'', '\'', $matches[2]);
            }
        }

        return $values;
    }
}
