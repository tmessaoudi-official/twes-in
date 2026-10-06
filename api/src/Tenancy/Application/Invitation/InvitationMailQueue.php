<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tenancy\Application\Invitation;

use Symfony\Component\Uid\Uuid;

/**
 * Where an invitation waits to be mailed (docs/SPEC.md § 7, 2026-10-06): called inside the transaction that writes it,
 * so it is mailed if and only if it was committed. One adapter, the worker's queue.
 */
interface InvitationMailQueue
{
    public function queue(Uuid $invitationId): void;
}
