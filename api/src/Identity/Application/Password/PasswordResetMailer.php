<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Identity\Application\Password;

/** The port the reset request sends through; one adapter, Symfony Mailer over the configured DSN. */
interface PasswordResetMailer
{
    public function send(PasswordResetMail $mail): void;
}
