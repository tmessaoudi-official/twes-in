<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\ImportExport\Application\Undo;

use Symfony\Component\Uid\Uuid;

/** Who ran each import, by the name the person goes by, for « Imports récents ». */
interface ImporterNames
{
    /**
     * @param list<Uuid> $userIds
     *
     * @return array<string, string> each name by its account's id; an account gone since is absent
     */
    public function namesOf(array $userIds): array;
}
