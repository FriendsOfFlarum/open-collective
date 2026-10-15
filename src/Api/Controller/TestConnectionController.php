<?php

/*
 * This file is part of fof/open-collective.
 *
 * Copyright (c) FriendsOfFlarum.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace FoF\OpenCollective\Api\Controller;

use Flarum\Http\RequestUtil;
use Flarum\Settings\SettingsRepositoryInterface;
use FoF\OpenCollective\Api\OpenCollectiveClient;
use FoF\OpenCollective\Api\OpenCollectiveException;
use FoF\OpenCollective\Services\SyncStatus;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Checks a token the way the sync uses it. Values sent in the body (the admin
 * form, possibly unsaved) win over the saved settings.
 *
 * A failed check is a successful request: it answers 200 with `ok: false` and the
 * failure kind, so the admin page can explain it.
 */
class TestConnectionController implements RequestHandlerInterface
{
    public function __construct(
        protected OpenCollectiveClient $client,
        protected SettingsRepositoryInterface $settings
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        RequestUtil::getActor($request)->assertAdmin();

        $body = (array) $request->getParsedBody();
        $apiKey = trim((string) ($body['apiKey'] ?? '')) ?: (string) $this->settings->get('fof-open-collective.api_key');
        $slug = strtolower(trim((string) ($body['slug'] ?? '')) ?: (string) $this->settings->get('fof-open-collective.slug'));
        $isLegacyKey = array_key_exists('useLegacyApiKey', $body)
            ? (bool) $body['useLegacyApiKey']
            : (bool) (int) $this->settings->get('fof-open-collective.use_legacy_api_key');

        if (!$apiKey || !$slug) {
            return new JsonResponse(['ok' => false, 'kind' => SyncStatus::CONFIGURATION, 'message' => 'A token and a collective slug are both required.']);
        }

        try {
            return new JsonResponse(['ok' => true] + $this->client->testConnection($apiKey, $slug, $isLegacyKey));
        } catch (OpenCollectiveException $e) {
            return new JsonResponse(['ok' => false, 'kind' => $e->kind, 'message' => $e->getMessage()]);
        }
    }
}
