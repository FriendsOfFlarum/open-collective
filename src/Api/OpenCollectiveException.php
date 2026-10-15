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

use RuntimeException;
use Throwable;

/**
 * A failed Open Collective request, classified so callers can explain it.
 */
class OpenCollectiveException extends RuntimeException
{
    /** The token was rejected outright (missing, revoked or expired). */
    public const UNAUTHORIZED = 'unauthorized';

    /** The token is valid but lacks the scope for an operation (Open Collective answers HTTP 200 with a `Forbidden` error code). */
    public const FORBIDDEN = 'forbidden';

    /** Orders were returned, but none carries an email: the token lacks the `email` scope. */
    public const EMAILS_HIDDEN = 'emails_hidden';

    public const NOT_FOUND = 'not_found';

    public const RATE_LIMITED = 'rate_limited';

    public const NETWORK = 'network';

    /** The response could not be understood (not JSON, or missing expected data). */
    public const INVALID_RESPONSE = 'invalid_response';

    public const API_ERROR = 'api_error';

    public function __construct(public readonly string $kind, string $message, ?Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }
}
