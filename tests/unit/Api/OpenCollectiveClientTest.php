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
        // Mock response for collective query
        $collectiveResponse = json_encode([
            'data' => [
                'collective' => [
                    'name' => 'Test Collective',
                    'slug' => 'test-collective',
                ],
            ],
        ]);

        // Mock response for MONTHLY orders (includes both ACTIVE and CANCELLED)
        $monthlyResponse = json_encode([
            'data' => [
                'orders' => [
                    'nodes' => [
                        [
                            'status'      => 'ACTIVE',
                            'frequency'   => 'MONTHLY',
                            'tier'        => ['name' => 'Backer'],
                            'fromAccount' => [
                                'id'    => 'account-1',
                                'email' => 'monthly@example.com',
                            ],
                        ],
                        [
                            'status'      => 'CANCELLED',
                            'frequency'   => 'MONTHLY',
                            'tier'        => ['name' => 'Backer'],
                            'fromAccount' => [
                                'id'    => 'account-2',
                                'email' => 'cancelled@example.com',
                            ],
                        ],
                    ],
                ],
            ],
        ]);

        // Mock response for YEARLY orders
        $yearlyResponse = json_encode([
            'data' => [
                'orders' => [
                    'nodes' => [],
                ],
            ],
        ]);

        // Mock response for ONETIME orders
        $onetimeResponse = json_encode([
            'data' => [
                'orders' => [
                    'nodes' => [
                        [
                            'status'      => 'PAID',
                            'frequency'   => 'ONETIME',
                            'tier'        => ['name' => 'Backer'],
                            'fromAccount' => [
                                'id'    => 'account-3',
                                'email' => 'onetime@example.com',
                            ],
                        ],
                    ],
                ],
            ],
        ]);

        // Mock Guzzle client
        $mockClient = $this->createMock(Client::class);
        $mockClient->expects($this->exactly(4))
            ->method('post')
            ->withConsecutive(
                // First call: collective query
                [
                    'https://api.opencollective.com/graphql/v2',
                    $this->callback(function ($options) {
                        return isset($options['json']['query']) &&
                               strpos($options['json']['query'], 'collective(slug:') !== false &&
                               isset($options['headers']['Personal-Token']);
                    }),
                ],
                // Second call: MONTHLY orders
                [
                    'https://api.opencollective.com/graphql/v2',
                    $this->callback(function ($options) {
                        return isset($options['json']['variables']['frequency']) &&
                               $options['json']['variables']['frequency'] === ['MONTHLY'];
                    }),
                ],
                // Third call: YEARLY orders
                [
                    'https://api.opencollective.com/graphql/v2',
                    $this->callback(function ($options) {
                        return isset($options['json']['variables']['frequency']) &&
                               $options['json']['variables']['frequency'] === ['YEARLY'];
                    }),
                ],
                // Fourth call: ONETIME orders
                [
                    'https://api.opencollective.com/graphql/v2',
                    $this->callback(function ($options) {
                        return isset($options['json']['variables']['frequency']) &&
                               $options['json']['variables']['frequency'] === ['ONETIME'];
                    }),
                ]
            )
            ->willReturnOnConsecutiveCalls(
                new Response(200, [], $collectiveResponse),
                new Response(200, [], $monthlyResponse),
                new Response(200, [], $yearlyResponse),
                new Response(200, [], $onetimeResponse)
            );

        // Test
        $client = new OpenCollectiveClient($mockClient);
        $result = $client->fetchBackers('test-token', 'test-collective', false);

        $this->assertEquals('Test Collective', $result['collective']);
        // Should have 3 unique backers (1 ACTIVE monthly, 1 CANCELLED monthly, 1 onetime)
        $this->assertCount(3, $result['backers']);
        $this->assertEquals('monthly@example.com', $result['backers'][0]->fromAccount->email);
        $this->assertEquals('cancelled@example.com', $result['backers'][1]->fromAccount->email);
        $this->assertEquals('onetime@example.com', $result['backers'][2]->fromAccount->email);
    }

    public function testFetchBackersWithLegacyKey()
    {
        // Mock response for collective query
        $collectiveResponse = json_encode([
            'data' => [
                'collective' => [
                    'name' => 'Test Collective',
                    'slug' => 'test',
                ],
            ],
        ]);

        // Mock empty responses for orders
        $emptyOrdersResponse = json_encode([
            'data' => [
                'orders' => [
                    'nodes' => [],
                ],
            ],
        ]);

        $mockClient = $this->createMock(Client::class);
        $mockClient->expects($this->exactly(4))
            ->method('post')
            ->withConsecutive(
                // First call should use Api-Key header
                [
                    'https://api.opencollective.com/graphql/v2',
                    $this->callback(function ($options) {
                        return isset($options['headers']['Api-Key']);
                    }),
                ],
                // Subsequent calls for orders
                [$this->anything(), $this->anything()],
                [$this->anything(), $this->anything()],
                [$this->anything(), $this->anything()]
            )
            ->willReturnOnConsecutiveCalls(
                new Response(200, [], $collectiveResponse),
                new Response(200, [], $emptyOrdersResponse),
                new Response(200, [], $emptyOrdersResponse),
                new Response(200, [], $emptyOrdersResponse)
            );

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

    public function testFetchBackersDeduplicatesByEmail()
    {
        $collectiveResponse = json_encode([
            'data' => [
                'collective' => [
                    'name' => 'Test Collective',
                    'slug' => 'test',
                ],
            ],
        ]);

        // Multiple orders from same person (same email)
        $monthlyResponse = json_encode([
            'data' => [
                'orders' => [
                    'nodes' => [
                        [
                            'status'      => 'ACTIVE',
                            'frequency'   => 'MONTHLY',
                            'fromAccount' => [
                                'id'    => 'account-1',
                                'email' => 'same@example.com',
                            ],
                        ],
                        [
                            'status'      => 'CANCELLED',
                            'frequency'   => 'MONTHLY',
                            'fromAccount' => [
                                'id'    => 'account-1',
                                'email' => 'same@example.com',
                            ],
                        ],
                    ],
                ],
            ],
        ]);

        $emptyOrdersResponse = json_encode([
            'data' => [
                'orders' => [
                    'nodes' => [],
                ],
            ],
        ]);

        $mockClient = $this->createMock(Client::class);
        $mockClient->expects($this->exactly(4))
            ->method('post')
            ->willReturnOnConsecutiveCalls(
                new Response(200, [], $collectiveResponse),
                new Response(200, [], $monthlyResponse),
                new Response(200, [], $emptyOrdersResponse),
                new Response(200, [], $emptyOrdersResponse)
            );

        $client = new OpenCollectiveClient($mockClient);
        $result = $client->fetchBackers('test-token', 'test', false);

        // Should only return 1 backer (deduplicated by email)
        $this->assertCount(1, $result['backers']);
        $this->assertEquals('same@example.com', $result['backers'][0]->fromAccount->email);
    }

    public function testFetchBackersDeduplicatesByAccountId()
    {
        $collectiveResponse = json_encode([
            'data' => [
                'collective' => [
                    'name' => 'Test Collective',
                    'slug' => 'test',
                ],
            ],
        ]);

        // Multiple orders from same person without email (deduplicate by account ID)
        $onetimeResponse = json_encode([
            'data' => [
                'orders' => [
                    'nodes' => [
                        [
                            'status'      => 'PAID',
                            'frequency'   => 'ONETIME',
                            'fromAccount' => [
                                'id' => 'account-1',
                            ],
                        ],
                        [
                            'status'      => 'ERROR',
                            'frequency'   => 'ONETIME',
                            'fromAccount' => [
                                'id' => 'account-1',
                            ],
                        ],
                    ],
                ],
            ],
        ]);

        $emptyOrdersResponse = json_encode([
            'data' => [
                'orders' => [
                    'nodes' => [],
                ],
            ],
        ]);

        $mockClient = $this->createMock(Client::class);
        $mockClient->expects($this->exactly(4))
            ->method('post')
            ->willReturnOnConsecutiveCalls(
                new Response(200, [], $collectiveResponse),
                new Response(200, [], $emptyOrdersResponse),
                new Response(200, [], $emptyOrdersResponse),
                new Response(200, [], $onetimeResponse)
            );

        $client = new OpenCollectiveClient($mockClient);
        $result = $client->fetchBackers('test-token', 'test', false);

        // Should only return 1 backer (deduplicated by account ID)
        $this->assertCount(1, $result['backers']);
        $this->assertEquals('account-1', $result['backers'][0]->fromAccount->id);
    }
}
