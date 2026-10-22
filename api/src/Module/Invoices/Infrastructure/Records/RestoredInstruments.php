<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Invoices\Infrastructure\Records;

use App\Module\Invoices\Application\Payment\ManageInstruments;
use App\Records\Application\RemovalConflict;
use App\Records\Application\RemovalRestored;
use App\Records\Domain\Model\RecordRemoval;
use App\Records\Domain\ValueObject\RemovalKind;
use App\Tenancy\Domain\Model\Company;
use Symfony\Component\Uid\Uuid;

/**
 * A held cheque or traite put back from the bin is held to what its invoice still owes, as one received is: another
 * entered since may cover that debt already, and then the whole restore is refused.
 */
final readonly class RestoredInstruments implements RemovalRestored
{
    private const string SUBJECT = 'payment_instrument';

    public function __construct(private ManageInstruments $instruments)
    {
    }

    public function restored(Company $company, RecordRemoval $removal, ?Uuid $actorUserId): void
    {
        if (RemovalKind::Delete !== $removal->getKind() || self::SUBJECT !== $removal->getSubject()) {
            return;
        }
        if (!$this->instruments->fitWhatIsDue($company, $removal->recordIds())) {
            throw new RemovalConflict(self::SUBJECT);
        }
    }
}
