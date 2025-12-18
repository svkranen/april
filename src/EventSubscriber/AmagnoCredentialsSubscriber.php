<?php

namespace App\EventSubscriber;

use App\Service\Amagno\CredentialStore;
use Iileven\AmagnoConnector\Event\CredentialsFetchEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

class AmagnoCredentialsSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly CredentialStore $credentialStore
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            CredentialsFetchEvent::NAME => 'onFetch',
        ];
    }

    public function onFetch(CredentialsFetchEvent $event): void
    {
        $credentials = $this->credentialStore->getCredentials($event->getCredentialId());
        if ($credentials !== null) {
            $event->setCredentialData($credentials);
        }
    }
}
