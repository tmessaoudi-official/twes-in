<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Erasure\Application;

use App\Identity\Application\StepUp\ConfirmStepUp;
use App\Identity\Application\StepUp\StepUpRequired;
use App\Tenancy\Domain\Company;
use App\Tenancy\Domain\MembershipRepository;
use App\Tenancy\Domain\Role;
use Symfony\Component\Uid\Uuid;

/**
 * Who may come near « Effacer des données »: the company's owner, whatever a custom role grants, and to see what would
 * go or to erase it, only within minutes of proving again who is at the screen.
 */
final readonly class ErasureGate
{
    public function __construct(private MembershipRepository $memberships, private ConfirmStepUp $stepUp)
    {
    }

    /** @throws OwnerOnly */
    public function owner(Company $company, Uuid $userId): void
    {
        if (Role::OWNER !== $this->memberships->ofUserInCompany($userId, $company->getId())?->getRole()->getName()) {
            throw new OwnerOnly('Only the owner erases the company\'s data.');
        }
    }

    /**
     * @throws OwnerOnly
     * @throws StepUpRequired
     */
    public function provedOwner(Company $company, Uuid $userId): void
    {
        $this->owner($company, $userId);
        if (!$this->stepUp->isRecent($userId)) {
            throw new StepUpRequired();
        }
    }
}
