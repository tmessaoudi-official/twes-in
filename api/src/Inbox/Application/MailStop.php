<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Inbox\Application;

use Symfony\Component\Uid\Uuid;

/** What a mail's stop link turns off: one kind's e-mail, for one person, in one company or about their account. */
final readonly class MailStop
{
    public function __construct(public Uuid $userId, public ?Uuid $companyId, public string $type)
    {
    }
}
