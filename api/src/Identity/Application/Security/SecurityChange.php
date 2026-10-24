<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Identity\Application\Security;

/**
 * A change to how an account signs in, which its owner is told of by mail whoever made it: someone holding a session
 * could make any of these, and the mail reaches the address the account was made for. Each value names its texts,
 * `security_notice.<value>` in the `emails` domain.
 */
enum SecurityChange: string
{
    case AuthenticatorAdded = 'authenticator_added';
    case AuthenticatorReplaced = 'authenticator_replaced';
    case AuthenticatorRemoved = 'authenticator_removed';
    case PasskeyAdded = 'passkey_added';
    case PasskeyRemoved = 'passkey_removed';
    case PasswordChanged = 'password_changed';
    case PasswordReset = 'password_reset';
    case RecoveryCodesRenewed = 'recovery_codes_renewed';
    case FactorsReplaced = 'factors_replaced';
}
