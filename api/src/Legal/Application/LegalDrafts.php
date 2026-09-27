<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Legal\Application;

/** The starting texts the platform ships. */
interface LegalDrafts
{
    /** @return list<LegalDraft> */
    public function all(): array;
}
