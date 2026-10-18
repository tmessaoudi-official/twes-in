<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Settings\Domain\Event;

use App\Shared\Domain\Event\DomainEvent;
use Symfony\Component\Uid\Uuid;

/**
 * A person changed one of their own preferences, or went back to what their company chose (a null value). Told so a
 * context keeping its own copy of a preference, such as the language an account's mails are written in, can follow.
 */
final readonly class PersonalSettingChanged implements DomainEvent
{
    public function __construct(public Uuid $userId, public string $key, public mixed $value)
    {
    }
}
