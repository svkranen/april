<?php

namespace App\Controller;

use LogicException;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Authentication\AuthenticationUtils;
use Twig\Environment;

final class SecurityController
{
    public function __construct(
        private readonly Environment $twig
    ) {
    }

    #[Route('/login', name: 'app_login', methods: ['GET', 'POST'])]
    public function login(AuthenticationUtils $authenticationUtils): Response
    {
        if ($authenticationUtils->getLastUsername() !== '' && $authenticationUtils->getLastAuthenticationError() === null) {
            return new Response('', Response::HTTP_FOUND, ['Location' => '/app']);
        }

        return new Response($this->twig->render('security/login.html.twig', [
            'last_username' => $authenticationUtils->getLastUsername(),
            'error' => $authenticationUtils->getLastAuthenticationError(),
        ]));
    }

    #[Route('/logout', name: 'app_logout', methods: ['GET'])]
    public function logout(): void
    {
        throw new LogicException(sprintf('Logout is handled by %s.', Security::class));
    }
}
