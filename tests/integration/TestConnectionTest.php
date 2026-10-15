<?php

/*
 * This file is part of fof/open-collective.
 *
 * Copyright (c) FriendsOfFlarum.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace FoF\OpenCollective\Tests\integration;

use Flarum\Extend;
use Flarum\Testing\integration\RetrievesAuthorizedUsers;
use Flarum\Testing\integration\TestCase;
use Flarum\User\User;
use FoF\OpenCollective\Tests\fixtures\FakeOpenCollectiveProvider;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\Test;

class TestConnectionTest extends TestCase
{
    use RetrievesAuthorizedUsers;

    protected function setUp(): void
    {
        parent::setUp();

        $this->extension('fof-open-collective');
        $this->extend((new Extend\ServiceProvider())->register(FakeOpenCollectiveProvider::class));
        FakeOpenCollectiveProvider::reset();

        $this->setting('fof-open-collective.api_key', 'saved-token');
        $this->setting('fof-open-collective.slug', 'saved-slug');

        $this->prepareDatabase([User::class => [$this->normalUser()]]);
    }

    /**
     * @return array{0: int, 1: array<string, mixed>|null}
     */
    protected function test(int $actorId, array $body = []): array
    {
        $response = $this->send($this->request('POST', '/api/fof/open-collective/test-connection', [
            'authenticatedAs' => $actorId,
            'json'            => $body,
        ]));

        return [$response->getStatusCode(), json_decode((string) $response->getBody(), true)];
    }

    #[Test]
    public function an_admin_sees_who_the_token_belongs_to_and_what_it_can_read(): void
    {
        FakeOpenCollectiveProvider::connection(['backer@example.com'], 169);

        [$status, $body] = $this->test(1);

        $this->assertSame(200, $status);
        $this->assertSame(['ok' => true, 'account' => 'admin-account', 'collective' => 'Test Collective', 'orders' => 169, 'emailsVisible' => true], $body);
    }

    #[Test]
    public function values_from_the_form_win_over_saved_settings(): void
    {
        FakeOpenCollectiveProvider::connection();

        $this->test(1, ['apiKey' => 'unsaved-token', 'slug' => 'Unsaved-Slug', 'useLegacyApiKey' => true]);

        $request = FakeOpenCollectiveProvider::$history[0]['request'];
        $this->assertSame('unsaved-token', $request->getHeaderLine('Api-Key'));
        $this->assertSame('unsaved-slug', json_decode((string) $request->getBody(), true)['variables']['slug']);
    }

    #[Test]
    public function saved_settings_are_used_when_the_form_sends_nothing(): void
    {
        FakeOpenCollectiveProvider::connection();

        $this->test(1);

        $request = FakeOpenCollectiveProvider::$history[0]['request'];
        $this->assertSame('saved-token', $request->getHeaderLine('Personal-Token'));
        $this->assertSame('saved-slug', json_decode((string) $request->getBody(), true)['variables']['slug']);
    }

    #[Test]
    public function a_failing_check_explains_why(): void
    {
        FakeOpenCollectiveProvider::reset([new Response(401, [], json_encode(['error' => ['message' => 'Invalid Personal Token (Api Key)']]))]);

        [$status, $body] = $this->test(1);

        $this->assertSame(200, $status);
        $this->assertSame(['ok' => false, 'kind' => 'unauthorized', 'message' => 'Invalid Personal Token (Api Key)'], $body);
    }

    #[Test]
    public function missing_settings_are_reported_without_calling_open_collective(): void
    {
        $this->setting('fof-open-collective.api_key', '');

        [, $body] = $this->test(1);

        $this->assertSame('configuration', $body['kind']);
        $this->assertSame([], FakeOpenCollectiveProvider::$history);
    }

    #[Test]
    public function only_admins_can_test(): void
    {
        FakeOpenCollectiveProvider::connection();

        [$status] = $this->test(2);

        $this->assertSame(403, $status);
        $this->assertSame([], FakeOpenCollectiveProvider::$history);
    }
}
