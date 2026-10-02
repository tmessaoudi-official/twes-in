<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Support;

use App\Identity\Application\Password\PasswordResetMail;
use App\Identity\Application\Password\PasswordResetMailer;

/** Keeps the reset mails it was asked to send, so a test reads what a person would have received. */
final class InMemoryPasswordResetMailer implements PasswordResetMailer
{
    /** @var list<PasswordResetMail> */
    public array $sent = [];

    public function send(PasswordResetMail $mail): void
    {
        $this->sent[] = $mail;
    }
}
