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
    /** Its owner let a passkey alone sign the account in, or stopped it. */
    case PasskeySignInOn = 'passkey_sign_in_on';
    case PasskeySignInOff = 'passkey_sign_in_off';
    case PasswordChanged = 'password_changed';
    case PasswordReset = 'password_reset';
    case RecoveryCodesRenewed = 'recovery_codes_renewed';
    case FactorsReplaced = 'factors_replaced';
    /** Wrong passwords or codes locked the account for a while: told once, when the lock is set, with when it ends. */
    case AccountLocked = 'account_locked';
    /**
     * The same lock, set by wrong second-factor codes: those are typed after the right password, so its owner is told the
     * password is known to someone else, not that someone guesses at it.
     */
    case SecondFactorLocked = 'second_factor_locked';
    /** An operator switched the account off, its sessions ended with it. */
    case OperatorDeactivated = 'operator_deactivated';
    /** An operator switched the account on again. */
    case OperatorReactivated = 'operator_reactivated';
    /** An operator ended every session of the account. */
    case OperatorEndedSessions = 'operator_ended_sessions';
    /** An owner of a company the account belongs to lifted the lock wrong guesses had set on its password. */
    case LockLiftedByOwner = 'lock_lifted_by_owner';
    /** An operator lifted the lock wrong guesses had set on the password of a company owner. */
    case LockLiftedByOperator = 'lock_lifted_by_operator';

    /** Whether it tells of a lock, which ends on its own and which its owner cannot sign in to look at. */
    public function isALock(): bool
    {
        return self::AccountLocked === $this || self::SecondFactorLocked === $this;
    }

    /**
     * Whether the installation, or someone in charge of the account, made it rather than someone holding its session:
     * its owner is told what follows, never asked whether it was them.
     */
    public function byTheInstallation(): bool
    {
        return \in_array($this, [self::FactorsReplaced, self::OperatorDeactivated, self::OperatorReactivated, self::OperatorEndedSessions, self::LockLiftedByOwner, self::LockLiftedByOperator], true);
    }
}
