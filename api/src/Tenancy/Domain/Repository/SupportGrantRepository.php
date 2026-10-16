<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tenancy\Domain\Repository;

use App\Tenancy\Domain\Model\SupportGrant;
use Symfony\Component\Uid\Uuid;

interface SupportGrantRepository
{
    /** The company's grant live at that moment, if any; granting ends the previous one, so there is at most one. */
    public function liveIn(Uuid $companyId, \DateTimeImmutable $now): ?SupportGrant;

    /**
     * Every grant live at that moment, across companies: what an operator's switcher offers.
     *
     * @return list<SupportGrant>
     */
    public function liveAt(\DateTimeImmutable $now, int $limit): array;

    public function save(SupportGrant $grant): void;
}
