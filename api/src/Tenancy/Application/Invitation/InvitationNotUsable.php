<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tenancy\Application\Invitation;

/**
 * The invitation cannot be used: unknown, malformed, expired or already accepted. One exception for all four
 * on purpose, so the answer never tells whoever is holding a link which of them it was.
 */
final class InvitationNotUsable extends \DomainException
{
}
