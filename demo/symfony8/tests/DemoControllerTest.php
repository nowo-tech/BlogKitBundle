<?php

declare(strict_types=1);

namespace App\Tests;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class DemoControllerTest extends WebTestCase
{
    public function testHomePageIsSuccessful(): void
    {
        $client = static::createClient();
        $client->request('GET', '/');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Blog kit');
        self::assertSelectorTextContains('body', 'BlogKitBundle');
    }

    public function testPingEndpointReturnsJson(): void
    {
        $client = static::createClient();
        $client->request('GET', '/api/ping');

        self::assertResponseIsSuccessful();
        self::assertJson($client->getResponse()->getContent() ?: '');
    }
}
