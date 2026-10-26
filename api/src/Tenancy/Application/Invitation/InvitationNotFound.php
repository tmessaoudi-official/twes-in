<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tenancy\Application\Invitation;

/** No open invitation of that company by that id: withdrawn already, accepted, or another company's. */
final class InvitationNotFound extends \DomainException
{
}
