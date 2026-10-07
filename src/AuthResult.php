<?php

declare(strict_types=1);

namespace App;

/**
 * Result of an authentication attempt. Controllers use this to produce an
 * accurate, reason-specific response instead of collapsing every failure
 * into a generic "wrong credentials" message.
 */
enum AuthResult
{
    /** Credentials valid and the session was established. */
    case SUCCESS;

    /** Unknown account OR wrong password — deliberately indistinguishable. */
    case WRONG_CREDENTIALS;

    /** Account suspended by an administrator (reason/until carry context). */
    case SUSPENDED;

    /** Account locked for security reasons. */
    case LOCKED;

    /** Service Bot account — web login disabled. */
    case BOT;

    /** Account has not verified its email address. */
    case NOT_VERIFIED;

    /** Credentials valid but a second-factor code is required (2FA flow). */
    case TWOFA_PENDING;
}
