<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Support;

use App\Tenancy\Application\Invitation\InvitationMail;
use App\Tenancy\Application\Invitation\InvitationMailer;

final class InMemoryInvitationMailer implements InvitationMailer
{
    /** @var list<InvitationMail> */
    public array $sent = [];

    public function send(InvitationMail $mail): void
    {
        $this->sent[] = $mail;
    }
}
