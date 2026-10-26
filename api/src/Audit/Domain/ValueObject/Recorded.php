<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Audit\Domain\ValueObject;

/**
 * How an action is recorded: on which kind of record (an entry's entity type, which a journal names and filters by, and
 * open screens reload on) and where (`AuditScope`). An action's kind is often not the start of its name: a payment is
 * recorded on its invoice, a sign-in on its user.
 */
final readonly class Recorded
{
    public function __construct(
        public string $kind,
        public AuditScope $scope,
    ) {
    }
}
