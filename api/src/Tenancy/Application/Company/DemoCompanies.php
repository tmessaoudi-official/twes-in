<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tenancy\Application\Company;

use Symfony\Component\Uid\Uuid;

/**
 * Whether a company is a demo sandbox's: a made-up company a visitor tries the product in, whose documents say on every
 * page that they are worth nothing. Answered by Demo, which holds the sandboxes.
 */
interface DemoCompanies
{
    public function isDemo(Uuid $companyId): bool;

    /**
     * Every sandbox's company, whatever its state: what the platform's operators never see. A few dozen at most, the
     * pool's size.
     *
     * @return list<Uuid>
     */
    public function all(): array;
}
