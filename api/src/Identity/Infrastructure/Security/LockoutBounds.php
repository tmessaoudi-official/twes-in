<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Identity\Infrastructure\Security;

use App\Shared\Infrastructure\DependencyInjection\ParameterBounds;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * How long wrong guesses lock an account is the operator's (app.auth.lock_duration, RecordFailedLogin), within bounds
 * checked when the container is built: none, or a minute or two, hands a guesser the next five tries at once, which is
 * the lockout switched off; past a day the lock is a way to keep the account's owner out, whom only their company's owner
 * can let in again meanwhile. How many failures lock it is the operator's too (app.auth.lock_after_failures): one or two
 * lock a person out on a typo, past twenty a guesser has twenty tries a lock.
 */
final class LockoutBounds implements CompilerPassInterface
{
    public const string LOCK_DURATION = 'app.auth.lock_duration';
    public const string LOCK_AFTER = 'app.auth.lock_after_failures';

    public function process(ContainerBuilder $container): void
    {
        ParameterBounds::duration($container, self::LOCK_DURATION, 300, 86_400, 'five minutes to a day (PT5M to PT24H)');
        ParameterBounds::whole($container, self::LOCK_AFTER, 3, 20);
    }
}
