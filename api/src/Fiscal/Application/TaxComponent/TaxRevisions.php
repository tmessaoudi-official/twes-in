<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Fiscal\Application\TaxComponent;

use Symfony\Component\Uid\Uuid;

/** What the activity log already says of a company's taxes: provisioning records no author, a person always does. */
interface TaxRevisions
{
    /** Whether a person has created or revised one of the company's taxes. */
    public function revisedByAPerson(Uuid $companyId): bool;
}
