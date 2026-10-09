<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Inbox\Application;

/** How one kind is told to one person in one of their companies, or about their account when the company is null. */
final readonly class NotificationChoice
{
    public function __construct(
        public ?string $companyId,
        public ?string $companyName,
        public string $type,
        public bool $bell,
        public bool $email,
        /** false for a kind mailed on its own by its context, whose e-mail switch then changes nothing */
        public bool $mailed,
    ) {
    }
}
