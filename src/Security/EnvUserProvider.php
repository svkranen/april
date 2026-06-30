<?php

namespace App\Security;

use Symfony\Component\Security\Core\Exception\UserNotFoundException;
use Symfony\Component\Security\Core\User\InMemoryUser;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Core\User\UserProviderInterface;

/**
 * Single APRIL web user backed by environment values.
 *
 * The username is intentionally not persisted in Doctrine; production should
 * provide APRIL_APP_PASSWORD_HASH through the real environment or secrets.
 */
final class EnvUserProvider implements UserProviderInterface
{
    public function __construct(
        private readonly string $aprilAppUsername,
        private readonly string $aprilAppPasswordHash
    ) {
    }

    public function loadUserByIdentifier(string $identifier): UserInterface
    {
        if ($identifier !== $this->aprilAppUsername || $this->aprilAppPasswordHash === '') {
            $exception = new UserNotFoundException();
            $exception->setUserIdentifier($identifier);

            throw $exception;
        }

        return new InMemoryUser($this->aprilAppUsername, $this->aprilAppPasswordHash, ['ROLE_USER']);
    }

    public function refreshUser(UserInterface $user): UserInterface
    {
        return $this->loadUserByIdentifier($user->getUserIdentifier());
    }

    public function supportsClass(string $class): bool
    {
        return $class === InMemoryUser::class || is_subclass_of($class, InMemoryUser::class);
    }
}
