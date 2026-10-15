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

use FoF\OpenCollective\Api\OpenCollectiveClient;
use FoF\OpenCollective\Api\OpenCollectiveException;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Runs the client against a real Guzzle client with queued responses, so HTTP
 * error handling (http_errors, connection failures) behaves as in production.
 */
class OpenCollectiveClientTest extends TestCase
{
    /** @var array<int, array{request: Request}> */
    protected array $history = [];

    /**
     * @param array<Response|\Throwable> $responses
     */
    protected function client(array $responses): OpenCollectiveClient
    {
        $this->history = [];
        $stack = HandlerStack::create(new MockHandler($responses));
        $stack->push(Middleware::history($this->history));

        return new OpenCollectiveClient(new Client(['handler' => $stack]));
    }

    protected function json(array $body, int $status = 200): Response
    {
        return new Response($status, ['Content-Type' => 'application/json'], json_encode($body));
    }

    protected function collective(): Response
    {
        return $this->json(['data' => ['collective' => ['name' => 'Test Collective', 'slug' => 'test']]]);
    }

    protected function orders(array $nodes, ?int $totalCount = null): Response
    {
        return $this->json(['data' => ['orders' => ['totalCount' => $totalCount ?? count($nodes), 'nodes' => $nodes]]]);
    }

    protected function order(string $status, string $frequency, ?string $email, ?string $accountId = null): array
    {
        return [
            'status'      => $status,
            'frequency'   => $frequency,
            'tier'        => ['name' => 'Backer'],
            'fromAccount' => ['id' => $accountId ?? 'acct-'.($email ?? uniqid()), 'email' => $email],
        ];
    }

    /** @return array<int, array<string, mixed>> */
    protected function sentBodies(): array
    {
        return array_map(fn ($entry) => json_decode((string) $entry['request']->getBody(), true), $this->history);
    }

    #[Test]
    public function it_returns_contributing_backers_with_the_collective_name(): void
    {
        $result = $this->client([
            $this->collective(),
            $this->orders([$this->order('ACTIVE', 'MONTHLY', 'monthly@example.com'), $this->order('CANCELLED', 'MONTHLY', 'cancelled@example.com')]),
            $this->orders([]),
            $this->orders([$this->order('PAID', 'ONETIME', 'onetime@example.com')]),
        ])->fetchBackers('token', 'test');

        $this->assertSame('Test Collective', $result['collective']);
        $this->assertEqualsCanonicalizing(
            ['monthly@example.com', 'cancelled@example.com', 'onetime@example.com'],
            array_map(fn ($backer) => $backer->fromAccount->email, $result['backers'])
        );
    }

    #[Test]
    public function every_request_carries_the_personal_token_and_slug(): void
    {
        $this->client([$this->collective(), $this->orders([]), $this->orders([]), $this->orders([])])->fetchBackers('secret-token', 'my-collective');

        $this->assertCount(4, $this->history);

        foreach ($this->history as $entry) {
            $this->assertSame('secret-token', $entry['request']->getHeaderLine('Personal-Token'));
            $this->assertFalse($entry['request']->hasHeader('Api-Key'));
        }

        foreach ($this->sentBodies() as $body) {
            $this->assertSame('my-collective', $body['variables']['slug']);
        }

        $this->assertSame([['MONTHLY'], ['YEARLY'], ['ONETIME']], array_map(fn ($body) => $body['variables']['frequency'], array_slice($this->sentBodies(), 1)));
    }

    #[Test]
    public function a_legacy_key_is_sent_as_api_key_on_every_request(): void
    {
        $this->client([$this->collective(), $this->orders([]), $this->orders([]), $this->orders([])])->fetchBackers('legacy-key', 'test', true);

        foreach ($this->history as $entry) {
            $this->assertSame('legacy-key', $entry['request']->getHeaderLine('Api-Key'));
            $this->assertFalse($entry['request']->hasHeader('Personal-Token'));
        }
    }

    #[Test]
    public function orders_beyond_the_first_page_are_fetched(): void
    {
        $firstPage = array_map(fn ($i) => $this->order('PAID', 'ONETIME', "backer$i@example.com"), range(1, 100));
        $secondPage = array_map(fn ($i) => $this->order('PAID', 'ONETIME', "backer$i@example.com"), range(101, 113));

        $result = $this->client([
            $this->collective(),
            $this->orders([]),
            $this->orders([]),
            $this->orders($firstPage, 113),
            $this->orders($secondPage, 113),
        ])->fetchBackers('token', 'test');

        $this->assertCount(113, $result['backers']);
        $this->assertSame([0, 100], array_map(fn ($body) => $body['variables']['offset'], array_slice($this->sentBodies(), 3)));
        $this->assertSame(100, $this->sentBodies()[3]['variables']['limit']);
    }

    #[Test]
    public function orders_that_never_paid_are_not_backers(): void
    {
        $result = $this->client([
            $this->collective(),
            $this->orders([]),
            $this->orders([]),
            $this->orders([
                $this->order('PAID', 'ONETIME', 'paid@example.com'),
                $this->order('ERROR', 'ONETIME', 'error@example.com'),
                $this->order('EXPIRED', 'ONETIME', 'expired@example.com'),
                $this->order('REQUIRE_CLIENT_CONFIRMATION', 'ONETIME', 'pending@example.com'),
            ]),
        ])->fetchBackers('token', 'test');

        $this->assertSame(['paid@example.com'], array_map(fn ($backer) => $backer->fromAccount->email, $result['backers']));
    }

    #[Test]
    public function an_active_subscription_wins_over_a_cancelled_one_for_the_same_person(): void
    {
        $result = $this->client([
            $this->collective(),
            $this->orders([$this->order('CANCELLED', 'MONTHLY', 'Person@Example.com', 'acct-1')]),
            $this->orders([$this->order('ACTIVE', 'YEARLY', 'person@example.com', 'acct-1')]),
            $this->orders([$this->order('PAID', 'ONETIME', 'person@example.com', 'acct-1')]),
        ])->fetchBackers('token', 'test');

        $this->assertCount(1, $result['backers']);
        $this->assertSame('YEARLY', $result['backers'][0]->frequency);
        $this->assertSame('ACTIVE', $result['backers'][0]->status);
    }

    #[Test]
    public function backers_without_an_email_are_kept_once_per_account(): void
    {
        $result = $this->client([
            $this->collective(),
            $this->orders([]),
            $this->orders([]),
            $this->orders([
                $this->order('PAID', 'ONETIME', 'known@example.com'),
                $this->order('PAID', 'ONETIME', null, 'incognito'),
                $this->order('PAID', 'ONETIME', null, 'incognito'),
            ]),
        ])->fetchBackers('token', 'test');

        $this->assertCount(2, $result['backers']);
    }

    #[Test]
    public function a_collective_with_no_orders_has_no_backers(): void
    {
        $result = $this->client([$this->collective(), $this->orders([]), $this->orders([]), $this->orders([])])->fetchBackers('token', 'test');

        $this->assertSame([], $result['backers']);
    }

    #[Test]
    public function orders_without_any_email_mean_the_token_lacks_the_email_scope(): void
    {
        $this->assertFailsWith(OpenCollectiveException::EMAILS_HIDDEN, [
            $this->collective(),
            $this->orders([$this->order('ACTIVE', 'MONTHLY', null)]),
            $this->orders([]),
            $this->orders([$this->order('PAID', 'ONETIME', null)]),
        ], '"email" scope');
    }

    /**
     * @param array<Response|\Throwable> $responses
     */
    protected function assertFailsWith(string $kind, array $responses, ?string $message = null): void
    {
        try {
            $this->client($responses)->fetchBackers('token', 'test');
            $this->fail("Expected an OpenCollectiveException of kind $kind");
        } catch (OpenCollectiveException $e) {
            $this->assertSame($kind, $e->kind);

            if ($message !== null) {
                $this->assertStringContainsString($message, $e->getMessage());
            }
        }
    }

    #[Test]
    public function a_rejected_token_is_unauthorized(): void
    {
        $this->assertFailsWith(
            OpenCollectiveException::UNAUTHORIZED,
            [$this->json(['error' => ['code' => 401, 'type' => 'unauthorized', 'message' => 'Invalid Personal Token (Api Key)']], 401)],
            'Invalid Personal Token'
        );
    }

    #[Test]
    public function a_token_missing_the_orders_scope_is_forbidden(): void
    {
        // The shape Open Collective returns: HTTP 200, a Forbidden error code, null data.
        $this->assertFailsWith(OpenCollectiveException::FORBIDDEN, [
            $this->collective(),
            $this->json([
                'errors' => [[
                    'message'    => 'The Personal Token is not allowed for operations in scope "orders".',
                    'path'       => ['orders'],
                    'extensions' => ['code' => 'Forbidden'],
                ]],
                'data' => null,
            ]),
        ], 'scope "orders"');
    }

    #[Test]
    public function an_unknown_collective_is_not_found(): void
    {
        $this->assertFailsWith(OpenCollectiveException::NOT_FOUND, [$this->json(['data' => ['collective' => null]])], "'test'");
    }

    #[Test]
    public function a_rate_limited_request_is_reported_as_such(): void
    {
        $this->assertFailsWith(OpenCollectiveException::RATE_LIMITED, [$this->json(['error' => ['message' => 'Too many requests']], 429)]);
    }

    #[Test]
    public function an_unreachable_api_is_a_network_failure(): void
    {
        $this->assertFailsWith(OpenCollectiveException::NETWORK, [
            new ConnectException('Connection timed out', new Request('POST', 'https://api.opencollective.com/graphql/v2')),
        ]);
    }

    #[Test]
    public function a_body_that_is_not_json_is_an_invalid_response(): void
    {
        $this->assertFailsWith(OpenCollectiveException::INVALID_RESPONSE, [new Response(200, [], '<html>Bad gateway</html>')]);
    }

    #[Test]
    public function missing_orders_data_is_an_invalid_response_rather_than_no_backers(): void
    {
        $this->assertFailsWith(OpenCollectiveException::INVALID_RESPONSE, [$this->collective(), $this->json(['data' => ['orders' => null]])]);
    }

    protected function connectionCheck(): Response
    {
        return $this->json(['data' => ['me' => ['slug' => 'token-owner'], 'collective' => ['name' => 'Test Collective']]]);
    }

    protected function sample(array $emails, ?int $totalCount = null): Response
    {
        return $this->json(['data' => ['orders' => [
            'totalCount' => $totalCount ?? count($emails),
            'nodes'      => array_map(fn ($email) => ['fromAccount' => ['email' => $email]], $emails),
        ]]]);
    }

    #[Test]
    public function a_working_token_reports_its_owner_collective_and_orders(): void
    {
        $result = $this->client([$this->connectionCheck(), $this->sample([null, 'backer@example.com'], 169)])->testConnection('token', 'test');

        $this->assertSame(['account' => 'token-owner', 'collective' => 'Test Collective', 'orders' => 169, 'emailsVisible' => true], $result);
        $this->assertSame('token', $this->history[1]['request']->getHeaderLine('Personal-Token'));
    }

    #[Test]
    public function a_collective_without_orders_cannot_confirm_email_access(): void
    {
        $result = $this->client([$this->connectionCheck(), $this->sample([])])->testConnection('token', 'test');

        $this->assertNull($result['emailsVisible']);
        $this->assertSame(0, $result['orders']);
    }

    protected function assertConnectionFailsWith(string $kind, array $responses): void
    {
        try {
            $this->client($responses)->testConnection('token', 'test');
            $this->fail("Expected an OpenCollectiveException of kind $kind");
        } catch (OpenCollectiveException $e) {
            $this->assertSame($kind, $e->kind);
        }
    }

    #[Test]
    public function the_connection_test_spots_hidden_emails(): void
    {
        $this->assertConnectionFailsWith(OpenCollectiveException::EMAILS_HIDDEN, [$this->connectionCheck(), $this->sample([null, null], 169)]);
    }

    #[Test]
    public function the_connection_test_spots_a_missing_orders_scope(): void
    {
        // The collective lookup succeeds with such a token; only the orders query fails.
        $this->assertConnectionFailsWith(OpenCollectiveException::FORBIDDEN, [
            $this->connectionCheck(),
            $this->json(['errors' => [['message' => 'The Personal Token is not allowed for operations in scope "orders".', 'extensions' => ['code' => 'Forbidden']]], 'data' => null]),
        ]);
    }

    #[Test]
    public function the_connection_test_spots_a_rejected_token(): void
    {
        $this->assertConnectionFailsWith(OpenCollectiveException::UNAUTHORIZED, [$this->json(['error' => ['message' => 'Invalid Personal Token (Api Key)']], 401)]);
    }

    #[Test]
    public function the_connection_test_spots_an_unknown_collective(): void
    {
        $this->assertConnectionFailsWith(OpenCollectiveException::NOT_FOUND, [$this->json(['data' => ['me' => ['slug' => 'token-owner'], 'collective' => null]])]);
    }
}
