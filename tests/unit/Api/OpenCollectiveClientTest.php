<?php

/*
 * This file is part of fof/open-collective.
 *
 * Copyright (c) FriendsOfFlarum.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace FoF\OpenCollective\Tests\Unit\Api;

use Exception;
use FoF\OpenCollective\Api\OpenCollectiveClient;
use GuzzleHttp\Client;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;

class OpenCollectiveClientTest extends TestCase
{
    public function testFetchBackersSuccess()
    {
        // Mock response data
        $mockResponse = json_encode([
            'data' => [
                'collective' => [
                    'name'    => 'Test Collective',
                    'slug'    => 'test-collective',
                    'members' => [
                        'nodes' => [
                            [
                                'account' => [
                                    'email' => 'backer@example.com',
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ]);

        // Mock Guzzle client
        $mockClient = $this->createMock(Client::class);
        $mockClient->expects($this->once())
            ->method('post')
            ->with(
                'https://api.opencollective.com/graphql/v2',
                $this->callback(function ($options) {
                    return isset($options['json']['query']) &&
                           isset($options['json']['variables']) &&
                           isset($options['headers']['Personal-Token']);
                })
            )
            ->willReturn(new Response(200, [], $mockResponse));

        // Test
        $client = new OpenCollectiveClient($mockClient);
        $result = $client->fetchBackers('test-token', 'test-collective', false);

        $this->assertEquals('Test Collective', $result['collective']);
        $this->assertCount(1, $result['backers']);
        $this->assertEquals('backer@example.com', $result['backers'][0]->account->email);
    }

    public function testFetchBackersWithLegacyKey()
    {
        $mockResponse = json_encode([
            'data' => [
                'collective' => [
                    'name'    => 'Test Collective',
                    'slug'    => 'test',
                    'members' => ['nodes' => []],
                ],
            ],
        ]);

        $mockClient = $this->createMock(Client::class);
        $mockClient->expects($this->once())
            ->method('post')
            ->with(
                'https://api.opencollective.com/graphql/v2',
                $this->callback(function ($options) {
                    return isset($options['headers']['Api-Key']);
                })
            )
            ->willReturn(new Response(200, [], $mockResponse));

        $client = new OpenCollectiveClient($mockClient);
        $result = $client->fetchBackers('legacy-key', 'test', true);

        $this->assertEquals('Test Collective', $result['collective']);
    }

    public function testFetchBackersThrowsExceptionOnGraphQLError()
    {
        $mockResponse = json_encode([
            'errors' => [
                ['message' => 'Invalid token'],
            ],
        ]);

        $mockClient = $this->createMock(Client::class);
        $mockClient->method('post')
            ->willReturn(new Response(200, [], $mockResponse));

        $client = new OpenCollectiveClient($mockClient);

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Invalid token');

        $client->fetchBackers('invalid-token', 'test', false);
    }

    public function testFetchBackersThrowsExceptionOnInvalidSlug()
    {
        $mockResponse = json_encode([
            'data' => [],
        ]);

        $mockClient = $this->createMock(Client::class);
        $mockClient->method('post')
            ->willReturn(new Response(200, [], $mockResponse));

        $client = new OpenCollectiveClient($mockClient);

        $this->expectException(Exception::class);
        $this->expectExceptionMessage("Collective 'nonexistent' not found");

        $client->fetchBackers('test-token', 'nonexistent', false);
    }
}
