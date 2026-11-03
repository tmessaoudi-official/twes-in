<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tenancy\Infrastructure\Invitation;

use App\Shared\Infrastructure\DependencyInjection\ParameterBounds;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * How long an invitation's link works is the operator's (app.invitation.valid_for), within bounds checked when the
 * container is built: a link open for months is a way into a company for whoever finds the mail later, and one gone
 * within a day misses the person away for a weekend.
 *
 * How long an invitation that ran out unused stays listed, marked expired, to be sent again, is the operator's too
 * (app.invitation.expired_shown_for: ListMembers, PlatformCompanies): under a day the person who sent it never learns it
 * lapsed, past ninety days the lists keep showing an address nobody answered for a season.
 */
final class InvitationBounds implements CompilerPassInterface
{
    public const string VALID_FOR = 'app.invitation.valid_for';
    public const string EXPIRED_SHOWN_FOR = 'app.invitation.expired_shown_for';

    public function process(ContainerBuilder $container): void
    {
        ParameterBounds::duration($container, self::VALID_FOR, 86_400, 30 * 86_400, 'one day to thirty (P1D to P30D)');
        ParameterBounds::duration($container, self::EXPIRED_SHOWN_FOR, 86_400, 90 * 86_400, 'one day to ninety (P1D to P90D)');
    }
}
