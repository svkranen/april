<?php

namespace App\Tests\Controller\App;

use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

abstract class AppWebTestCase extends WebTestCase
{
    protected static function createAuthenticatedClient(): KernelBrowser
    {
        $client = static::createClient();
        $client->disableReboot();
        $client->request('GET', '/login');
        $client->submitForm('Anmelden', [
            'username' => 'april-test',
            'password' => 'test-password',
        ]);

        self::assertResponseRedirects('/app');

        return $client;
    }
}
