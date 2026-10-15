<?php

/*
 * This file is part of fof/open-collective.
 *
 * Copyright (c) FriendsOfFlarum.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace FoF\OpenCollective\Tests\fixtures;

use Flarum\Foundation\AbstractServiceProvider;
use FoF\OpenCollective\Api\OpenCollectiveClient;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;

/**
 * Binds the Open Collective client to queued Guzzle responses, so the real
 * client code (pagination, error classification) runs without the network.
 */
class FakeOpenCollectiveProvider extends AbstractServiceProvider
{
    /** @var array<Response|\Throwable> */
    public static array $responses = [];

    public static function reset(array $responses = []): void
    {
        self::$responses = $responses;
    }

    /**
     * Queue a full successful fetch: the collective lookup, then one page each of
     * MONTHLY, YEARLY and ONETIME orders.
     *
     * @param array<array<string, mixed>> $monthly
     * @param array<array<string, mixed>> $onetime
     */
    public static function backers(array $monthly = [], array $onetime = []): void
    {
        $json = fn (array $body) => new Response(200, ['Content-Type' => 'application/json'], json_encode($body));
        $orders = fn (array $nodes) => $json(['data' => ['orders' => ['totalCount' => count($nodes), 'nodes' => $nodes]]]);

        self::reset([
            $json(['data' => ['collective' => ['name' => 'Test Collective', 'slug' => 'test']]]),
            $orders($monthly),
            $orders([]),
            $orders($onetime),
        ]);
    }

    public function register(): void
    {
        $this->container->singleton(OpenCollectiveClient::class, function () {
            return new OpenCollectiveClient(new Client(['handler' => HandlerStack::create(new MockHandler(self::$responses))]));
        });
    }
}
