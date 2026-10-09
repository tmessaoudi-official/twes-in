<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Inbox\Domain;

use Symfony\Component\Uid\Uuid;

interface NotificationPreferenceRepository
{
    /** @return list<NotificationPreference> every choice one person made */
    public function ofUser(Uuid $userId): array;

    /** The choice for one kind in one company, or for a personal kind when the company is null. */
    public function find(Uuid $userId, ?Uuid $companyId, string $type): ?NotificationPreference;

    public function save(NotificationPreference $preference): void;
}
