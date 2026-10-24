<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tenancy\Application\Invitation;

use Symfony\Component\Uid\Uuid;

/** Everything the invitation mail needs, with no idea how it is rendered or sent. */
final readonly class InvitationMail
{
    public function __construct(
        public string $to,
        public string $companyName,
        public string $roleName,
        public ?string $invitedByName,
        public string $acceptUrl,
        public string $locale,
        /** The company's zone: the deadline is read by a person, and every timestamp here is stored UTC. */
        public string $timezone,
        public \DateTimeImmutable $expiresAt,
        /** The company the person is invited to, whose wording the mail takes. */
        public ?Uuid $companyId = null,
    ) {
    }
}
