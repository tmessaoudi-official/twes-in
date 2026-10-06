<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tenancy\Infrastructure\Invitation;

/** An invitation waiting for the worker to make its link and mail it; it carries the id only, never a link. */
final readonly class InvitationToMail
{
    public function __construct(public string $invitationId)
    {
    }
}
