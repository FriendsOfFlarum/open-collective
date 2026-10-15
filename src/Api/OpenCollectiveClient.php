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

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Exception\RequestException;

class OpenCollectiveClient
{
    protected const ENDPOINT = 'https://api.opencollective.com/graphql/v2';

    /** The API's maximum page size for `orders`; it silently defaults to 100 when no limit is sent. */
    protected const PAGE_SIZE = 100;

    /**
     * Order statuses that mean money actually changed hands. Excludes ERROR, EXPIRED,
     * REJECTED, PENDING, REQUIRE_CLIENT_CONFIRMATION, REFUNDED and the like, which
     * would otherwise earn the one-time group for a failed payment.
     */
    public const CONTRIBUTED_STATUSES = ['ACTIVE', 'PAID', 'CANCELLED', 'PAUSED'];

    protected Client $client;

    public function __construct(?Client $client = null)
    {
        // Without a timeout a hung request blocks forever, and the scheduler's
        // withoutOverlapping() lock then stops every later run for 24 hours.
        $this->client = $client ?? new Client(['timeout' => 30, 'connect_timeout' => 10]);
    }

    /**
     * Fetch every contributing backer of a collective, one order per person.
     *
     * @throws OpenCollectiveException
     *
     * @return array{collective: string, backers: array<object>}
     */
    public function fetchBackers(string $apiKey, string $slug, bool $isLegacyKey = false): array
    {
        $data = $this->query($apiKey, $isLegacyKey, '
            query collective($slug: String) {
              collective(slug: $slug) {
                name
                slug
              }
            }
        ', ['slug' => $slug]);

        if (!isset($data->collective)) {
            throw new OpenCollectiveException(OpenCollectiveException::NOT_FOUND, "Collective '$slug' not found");
        }

        $orders = array_merge(
            $this->fetchOrdersByFrequency($apiKey, $slug, $isLegacyKey, 'MONTHLY'),
            $this->fetchOrdersByFrequency($apiKey, $slug, $isLegacyKey, 'YEARLY'),
            $this->fetchOrdersByFrequency($apiKey, $slug, $isLegacyKey, 'ONETIME')
        );

        $orders = array_values(array_filter(
            $orders,
            fn ($order) => in_array($order->status ?? null, self::CONTRIBUTED_STATUSES, true)
        ));

        // Some backers are legitimately email-less (incognito, guests), but none at
        // all means the token cannot read emails. Syncing that would match nobody
        // and remove every managed member.
        if ($orders && !array_filter($orders, fn ($order) => !empty($order->fromAccount->email))) {
            throw new OpenCollectiveException(
                OpenCollectiveException::EMAILS_HIDDEN,
                'Open Collective returned no backer emails. The token needs the "email" scope.'
            );
        }

        return [
            'collective' => $data->collective->name,
            'backers'    => $this->uniqueBackers($orders),
        ];
    }

    /**
     * Check a token can do everything the sync needs, cheaply: authenticate, find
     * the collective, read orders (`orders` scope) and see emails (`email` scope).
     * A collective lookup alone succeeds even when the orders query is forbidden.
     *
     * @throws OpenCollectiveException
     *
     * @return array{account: string|null, collective: string, orders: int, emailsVisible: bool|null} emailsVisible is null when there are no orders to check
     */
    public function testConnection(string $apiKey, string $slug, bool $isLegacyKey = false): array
    {
        $data = $this->query($apiKey, $isLegacyKey, '
            query test($slug: String) {
              me {
                slug
              }
              collective(slug: $slug) {
                name
              }
            }
        ', ['slug' => $slug]);

        if (!isset($data->collective)) {
            throw new OpenCollectiveException(OpenCollectiveException::NOT_FOUND, "Collective '$slug' not found");
        }

        // A sample, not a page: incognito backers legitimately have no email, so
        // judging email access from a single order would be unreliable.
        $orders = $this->query($apiKey, $isLegacyKey, '
            query orders($slug: String!) {
              orders(account: { slug: $slug }, filter: INCOMING, limit: 25) {
                totalCount
                nodes {
                  fromAccount {
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
        ', ['slug' => $slug]);

        if (!isset($orders->orders->nodes, $orders->orders->totalCount)) {
            throw new OpenCollectiveException(OpenCollectiveException::INVALID_RESPONSE, 'Open Collective returned no orders data');
        }

        $nodes = (array) $orders->orders->nodes;
        $emailsVisible = $nodes ? (bool) array_filter($nodes, fn ($order) => !empty($order->fromAccount->email)) : null;

        if ($emailsVisible === false) {
            throw new OpenCollectiveException(
                OpenCollectiveException::EMAILS_HIDDEN,
                'Open Collective returned no backer emails. The token needs the "email" scope.'
            );
        }

        return [
            'account'       => $data->me->slug ?? null,
            'collective'    => $data->collective->name,
            'orders'        => (int) $orders->orders->totalCount,
            'emailsVisible' => $emailsVisible,
        ];
    }

    /**
     * Reduce orders to one per person (by email, else account ID). An active
     * recurring order wins, so a cancelled subscription never hides a live one.
     *
     * @param array<object> $orders
     *
     * @return array<object>
     */
    protected function uniqueBackers(array $orders): array
    {
        $backers = [];

        foreach ($orders as $order) {
            $email = $order->fromAccount->email ?? null;
            $key = $email ? 'email:'.strtolower($email) : 'account:'.($order->fromAccount->id ?? spl_object_id($order));

            if (!isset($backers[$key]) || (!$this->isActiveRecurring($backers[$key]) && $this->isActiveRecurring($order))) {
                $backers[$key] = $order;
            }
        }

        return array_values($backers);
    }

    protected function isActiveRecurring(object $order): bool
    {
        return in_array($order->frequency ?? null, ['MONTHLY', 'YEARLY'], true) && ($order->status ?? null) === 'ACTIVE';
    }

    /**
     * @throws OpenCollectiveException
     *
     * @return array<object>
     */
    protected function fetchOrdersByFrequency(string $apiKey, string $slug, bool $isLegacyKey, string $frequency): array
    {
        $orders = [];
        $offset = 0;

        do {
            $data = $this->query($apiKey, $isLegacyKey, '
                query orders($slug: String!, $frequency: [ContributionFrequency], $limit: Int!, $offset: Int!) {
                  orders(
                    account: { slug: $slug }
                    frequency: $frequency
                    filter: INCOMING
                    limit: $limit
                    offset: $offset
                  ) {
                    totalCount
                    nodes {
                      status
                      frequency
                      tier {
                        name
                      }
                      fromAccount {
                        id
                        isIncognito
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
            ', [
                'slug'      => $slug,
                'frequency' => [$frequency],
                'limit'     => self::PAGE_SIZE,
                'offset'    => $offset,
            ]);

            if (!isset($data->orders->nodes, $data->orders->totalCount)) {
                throw new OpenCollectiveException(OpenCollectiveException::INVALID_RESPONSE, "Open Collective returned no $frequency orders data");
            }

            $page = (array) $data->orders->nodes;
            array_push($orders, ...$page);
            $offset += self::PAGE_SIZE;
        } while ($page && $offset < $data->orders->totalCount);

        return $orders;
    }

    /**
     * Run a GraphQL query and return its `data`, turning every failure mode into
     * an {@see OpenCollectiveException}.
     *
     * @param array<string, mixed> $variables
     *
     * @throws OpenCollectiveException
     */
    protected function query(string $apiKey, bool $isLegacyKey, string $query, array $variables): object
    {
        try {
            $response = $this->client->post(self::ENDPOINT, [
                'json'    => ['query' => $query, 'variables' => $variables],
                'headers' => [($isLegacyKey ? 'Api-Key' : 'Personal-Token') => $apiKey],
            ]);
        } catch (ConnectException $e) {
            throw new OpenCollectiveException(OpenCollectiveException::NETWORK, 'Could not reach Open Collective: '.$e->getMessage(), $e);
        } catch (RequestException $e) {
            $status = $e->getResponse()?->getStatusCode();
            $kind = match ($status) {
                401     => OpenCollectiveException::UNAUTHORIZED,
                403     => OpenCollectiveException::FORBIDDEN,
                404     => OpenCollectiveException::NOT_FOUND,
                429     => OpenCollectiveException::RATE_LIMITED,
                default => OpenCollectiveException::API_ERROR,
            };
            $body = json_decode((string) $e->getResponse()?->getBody());

            throw new OpenCollectiveException($kind, $body->error->message ?? "Open Collective request failed (HTTP $status)", $e);
        } catch (GuzzleException $e) {
            throw new OpenCollectiveException(OpenCollectiveException::NETWORK, 'Open Collective request failed: '.$e->getMessage(), $e);
        }

        $json = json_decode((string) $response->getBody());

        if (!is_object($json)) {
            throw new OpenCollectiveException(OpenCollectiveException::INVALID_RESPONSE, 'Open Collective returned a response that is not JSON');
        }

        if (!empty($json->errors)) {
            $codes = array_map(fn ($error) => $error->extensions->code ?? null, $json->errors);
            $kind = match (true) {
                in_array('Unauthorized', $codes, true) => OpenCollectiveException::UNAUTHORIZED,
                in_array('Forbidden', $codes, true)    => OpenCollectiveException::FORBIDDEN,
                default                                => OpenCollectiveException::API_ERROR,
            };

            throw new OpenCollectiveException($kind, implode("\n", array_map(fn ($error) => $error->message ?? 'Unknown error', $json->errors)));
        }

        if (!isset($json->data) || !is_object($json->data)) {
            throw new OpenCollectiveException(OpenCollectiveException::INVALID_RESPONSE, 'Open Collective returned no data');
        }

        return $json->data;
    }
}
