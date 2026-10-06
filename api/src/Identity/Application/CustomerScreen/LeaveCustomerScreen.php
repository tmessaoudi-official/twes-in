<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Identity\Application\CustomerScreen;

use App\Identity\Application\StepUp\ConfirmStepUp;
use App\Identity\Application\StepUp\StepUpProofs;
use App\Identity\Application\StepUp\StepUpRequired;
use Symfony\Component\Uid\Uuid;

/**
 * Lets the clerk back into the app from the customer screen once they proved who they are, and spends that proof: the
 * customer standing at the screen next must prove it again, not ride on the clerk's few minutes.
 */
final readonly class LeaveCustomerScreen
{
    public function __construct(
        private ConfirmStepUp $confirm,
        private StepUpProofs $proofs,
        private CustomerScreenLock $lock,
    ) {
    }

    /** @throws StepUpRequired when this sign-in has no fresh proof */
    public function handle(Uuid $userId): void
    {
        if (!$this->confirm->isRecent($userId)) {
            throw new StepUpRequired();
        }
        $this->proofs->forget();
        $this->lock->release();
    }
}
