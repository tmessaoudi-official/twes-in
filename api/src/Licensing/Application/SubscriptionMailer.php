<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Licensing\Application;

/** The two mails licensing sends: the operator hears of a declared payment, the company hears of the decision. */
interface SubscriptionMailer
{
    public function paymentDeclared(PaymentDeclaredMail $mail): void;

    public function paymentDecided(PaymentDecidedMail $mail): void;
}
