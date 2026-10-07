<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Audit\Application;

use App\Shared\Domain\Page;
use App\Shared\Domain\PageRequest;
use Symfony\Component\Uid\Uuid;

/** The journal as stored: one company's entries from a moment on, narrowed and paged. */
interface ActivityJournal
{
    /** @return Page<ActivityEntry> */
    public function page(Uuid $companyId, ActivitySearch $search, \DateTimeImmutable $since, PageRequest $page): Page;
}
