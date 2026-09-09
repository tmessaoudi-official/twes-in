<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tenancy\Application\Invitation;

/** The port the invitation use case sends through; one adapter, Symfony Mailer over the configured DSN. */
interface InvitationMailer
{
    public function send(InvitationMail $mail): void;
}
