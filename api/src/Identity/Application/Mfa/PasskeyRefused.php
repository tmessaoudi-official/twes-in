<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Identity\Application\Mfa;

/** A passkey that does not verify, for whatever reason: the caller learns no more than that, the audit trail learns why. */
final class PasskeyRefused extends \RuntimeException
{
    public static function because(string $reason, ?\Throwable $previous = null): self
    {
        return new self($reason, 0, $previous);
    }

    /** What the audit trail records: unknown_passkey, bad_assertion, or refused when nothing more precise is known. */
    public function reason(): string
    {
        return '' === $this->getMessage() ? 'refused' : $this->getMessage();
    }
}
