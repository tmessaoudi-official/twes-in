<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tenancy\Application\Session;

use Symfony\Component\Uid\Uuid;

/**
 * The companies this sign-in has proved itself for, kept with the sign-in so it goes with it: when each was proved and
 * when it was last used. What lasts how long is CompanyEntry's to judge.
 */
interface CompanyProofs
{
    public function proved(Uuid $companyId, \DateTimeImmutable $at): void;

    /** @return array{proved: \DateTimeImmutable, used: \DateTimeImmutable}|null */
    public function of(Uuid $companyId): ?array;

    public function used(Uuid $companyId, \DateTimeImmutable $at): void;

    public function forget(Uuid $companyId): void;

    public function forgetAll(): void;
}
