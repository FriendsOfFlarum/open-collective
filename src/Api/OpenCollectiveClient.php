<?php

/*
 * This file is part of fof/open-collective.
 *
 * Copyright (c) FriendsOfFlarum.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace FoF\OpenCollective\Api;

use Exception;
use GuzzleHttp\Client;
use Illuminate\Support\Arr;

class OpenCollectiveClient
{
    /**
     * @var Client
     */
    private $client;

    public function __construct(?Client $client = null)
    {
        $this->client = $client ?? new Client();
    }

    /**
     * Fetch backers from Open Collective GraphQL API.
     *
     * @param string $apiKey      Open Collective API key or Personal Token
     * @param string $slug        Collective slug
     * @param bool   $isLegacyKey Whether using legacy API key format
     *
     * @throws Exception
     *
     * @return array{collective: string, backers: array}
     */
    public function fetchBackers(string $apiKey, string $slug, bool $isLegacyKey = false): array
    {
        $header = $isLegacyKey ? 'Api-Key' : 'Personal-Token';

        $response = $this->client->post('https://api.opencollective.com/graphql/v2', [
            'json' => [
                'query' => '
                    query collective($slug: String) {
                      collective(slug: $slug) {
                        name
                        slug
                      }
                    }
                ',
                'variables' => ['slug' => $slug],
            ],
            'headers' => [$header => $apiKey],
        ]);

        $json = json_decode($response->getBody()->getContents());

        if (isset($json->errors)) {
            throw new Exception(implode("\n", Arr::pluck($json->errors, 'message')));
        }

        if (!isset($json->data->collective)) {
            throw new Exception("Collective '$slug' not found");
        }

        $collective = $json->data->collective;

        // Fetch all orders (both recurring and one-time)
        // Note: Fetching with onlyActive=false gets ALL orders (both ACTIVE and CANCELLED)
        // so we don't need to fetch active orders separately
        $allBackers = array_merge(
            // All MONTHLY subscriptions (both ACTIVE and CANCELLED)
            $this->fetchOrdersByFrequency($apiKey, $slug, $isLegacyKey, 'MONTHLY', false),
            // All YEARLY subscriptions (both ACTIVE and CANCELLED)
            $this->fetchOrdersByFrequency($apiKey, $slug, $isLegacyKey, 'YEARLY', false),
            // One-time contributions
            $this->fetchOrdersByFrequency($apiKey, $slug, $isLegacyKey, 'ONETIME', false)
        );

        // Remove duplicates by email and account ID (keep the first occurrence)
        // Note: Backers without emails are kept but can't be matched to Flarum users
        $uniqueBackers = [];
        $seenEmails = [];
        $seenAccountIds = [];

        foreach ($allBackers as $backer) {
            $email = $backer->fromAccount->email ?? null;
            $accountId = $backer->fromAccount->id ?? null;

            // Check if we've already seen this backer (by email OR account ID)
            $alreadySeen = false;

            if ($email && in_array($email, $seenEmails)) {
                $alreadySeen = true;
            }

            if ($accountId && in_array($accountId, $seenAccountIds)) {
                $alreadySeen = true;
            }

            if (!$alreadySeen) {
                if ($email) {
                    $seenEmails[] = $email;
                }
                if ($accountId) {
                    $seenAccountIds[] = $accountId;
                }
                $uniqueBackers[] = $backer;
            }
        }

        return [
            'collective' => $collective->name,
            'backers'    => $uniqueBackers,
        ];
    }

    /**
     * Fetch orders by frequency from Open Collective GraphQL API.
     *
     * @param string $apiKey      Open Collective API key or Personal Token
     * @param string $slug        Collective slug
     * @param bool   $isLegacyKey Whether using legacy API key format
     * @param string $frequency   Frequency (MONTHLY, YEARLY, or ONETIME)
     * @param bool   $onlyActive  Whether to fetch only active subscriptions
     *
     * @throws Exception
     *
     * @return array
     */
    private function fetchOrdersByFrequency(
        string $apiKey,
        string $slug,
        bool $isLegacyKey,
        string $frequency,
        bool $onlyActive
    ): array {
        $header = $isLegacyKey ? 'Api-Key' : 'Personal-Token';

        $response = $this->client->post('https://api.opencollective.com/graphql/v2', [
            'json' => [
                'query' => '
                    query orders($slug: String!, $frequency: [ContributionFrequency], $onlyActive: Boolean) {
                      orders(
                        account: { slug: $slug }
                        frequency: $frequency
                        onlyActiveSubscriptions: $onlyActive
                        filter: INCOMING
                      ) {
                        nodes {
                          status
                          frequency
                          tier {
                            name
                          }
                          fromAccount {
                            id
                            ... on Individual {
                              email
                            }
                            ... on Organization {
                              email
                            }
                          }
                        }
                      }
                    }
                ',
                'variables' => [
                    'slug'       => $slug,
                    'frequency'  => [$frequency],
                    'onlyActive' => $onlyActive,
                ],
            ],
            'headers' => [$header => $apiKey],
        ]);

        $json = json_decode($response->getBody()->getContents());

        if (isset($json->errors)) {
            throw new Exception(implode("\n", Arr::pluck($json->errors, 'message')));
        }

        if (!isset($json->data->orders)) {
            return [];
        }

        // Filter by status - only return ACTIVE orders for recurring subscriptions
        // The onlyActiveSubscriptions parameter doesn't seem to filter out CANCELLED orders
        $orders = collect($json->data->orders->nodes);

        if ($onlyActive) {
            $orders = $orders->filter(function ($order) {
                return ($order->status ?? null) === 'ACTIVE';
            });
        }

        return $orders->all();
    }
}
