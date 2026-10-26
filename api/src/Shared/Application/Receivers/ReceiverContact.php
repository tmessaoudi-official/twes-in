<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Shared\Application\Receivers;

use Symfony\Component\Uid\Uuid;

/** One of a customer's contacts as a form offers it to name who received: its id and its name as a document prints it. */
final readonly class ReceiverContact
{
    public function __construct(public Uuid $id, public string $name)
    {
    }
}
