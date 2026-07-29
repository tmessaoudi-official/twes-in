<?php

/*
 * This file is part of twes-in.
 *
 * (c) Takieddine MESSAOUDI <takieddine.messaoudi.official@gmail.com>
 *
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Twes\Infrastructure\Tenancy\Exception;

final class NoCurrentTenant extends \LogicException
{
    public static function create(): self
    {
        return new self(
            'No tenant is bound to this context. A query issued without a tenant is a bug, not a '
            . 'query for every tenant — ask TenantContext::hasTenant() first, and handle genuinely '
            . 'cross-tenant work explicitly.',
        );
    }
}
