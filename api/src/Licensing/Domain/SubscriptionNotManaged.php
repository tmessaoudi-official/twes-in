<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Licensing\Domain;

/** The company has no subscription, so there is nothing to pay for. */
final class SubscriptionNotManaged extends \DomainException
{
}
