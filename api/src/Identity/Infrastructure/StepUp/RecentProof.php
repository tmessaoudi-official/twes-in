<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Identity\Infrastructure\StepUp;

use App\Identity\Application\StepUp\ConfirmStepUp;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\Uid\Uuid;

/**
 * What an API Platform write on the step-up list asks before it runs (docs/SPEC.md § 7: the company's bank details, a
 * member added or removed, a role changed): a stolen session changing the IBAN so customers pay the attacker is the
 * attack the list exists for. A processor asks it last, after the company and the permission, so who may not act at all
 * is answered as before rather than told to prove who they are. The refusal leads its detail with a token, as the other
 * refusals of these resources do, and the screen asks for the password or a passkey and tries again.
 */
final readonly class RecentProof
{
    public const string REQUIRED = 'step_up_required';

    public function __construct(private ConfirmStepUp $stepUp)
    {
    }

    /** @throws AccessDeniedHttpException when this sign-in has not proved who is at the screen in the last few minutes */
    public function demand(Uuid $userId): void
    {
        if (!$this->stepUp->isRecent($userId)) {
            throw new AccessDeniedHttpException(self::REQUIRED.': confirm the password or a passkey again before this change.');
        }
    }
}
