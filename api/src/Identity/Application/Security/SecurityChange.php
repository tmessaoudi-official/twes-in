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
    /** Wrong passwords or codes locked the account for a while: told once, when the lock is set, with when it ends. */
    case AccountLocked = 'account_locked';
    /** An operator switched the account off, its sessions ended with it. */
    case OperatorDeactivated = 'operator_deactivated';
    /** An operator switched the account on again. */
    case OperatorReactivated = 'operator_reactivated';
    /** An operator ended every session of the account. */
    case OperatorEndedSessions = 'operator_ended_sessions';

    /**
     * Whether the installation made it rather than someone holding a session: its owner is told what follows, never
     * asked whether it was them.
     */
    public function byTheInstallation(): bool
    {
        return \in_array($this, [self::FactorsReplaced, self::OperatorDeactivated, self::OperatorReactivated, self::OperatorEndedSessions], true);
    }
}
