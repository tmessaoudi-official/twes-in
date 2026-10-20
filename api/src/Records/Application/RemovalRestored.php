<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Records\Application;

use App\Records\Domain\Model\RecordRemoval;
use App\Tenancy\Domain\Model\Company;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;
use Symfony\Component\Uid\Uuid;

/**
 * What else a restore puts back, said by the context that made the removal: the import whose undo it was stands again,
 * with the stock that undo took back. Runs inside the restore's transaction, after its rows stand again; a refusal
 * refuses the whole restore.
 */
#[AutoconfigureTag('app.record.removal_restored')]
interface RemovalRestored
{
    public function restored(Company $company, RecordRemoval $removal, ?Uuid $actorUserId): void;
}
