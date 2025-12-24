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
                'query' => "
                    query collective(\$slug: String) {
                      collective(slug: \$slug) {
                        name
                        slug
                        members(role: BACKER, accountType: INDIVIDUAL) {
                          nodes {
                            account {
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
                    }
                ",
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

        return [
            'collective' => $collective->name,
            'backers'    => collect($collective->members->nodes)->all(),
        ];
    }
}
