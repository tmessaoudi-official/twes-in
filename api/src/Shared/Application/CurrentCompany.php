<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Shared\Application;

use Symfony\Component\Uid\Uuid;

/**
 * The company the session is working in: set at login when the user has exactly one membership, changed by
 * the switcher (G1b), gone with the session. Every company-scoped use case reads it from here; the session
 * adapter lives in Shared\Infrastructure.
 */
interface CurrentCompany
{
    public function id(): ?Uuid;

    public function set(?Uuid $companyId): void;
}
