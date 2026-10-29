<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Identity\Infrastructure\ApiPlatform\Output;

use ApiPlatform\Metadata\ApiProperty;
use App\Identity\Application\CompanyProof\EntryProof;

/**
 * What the working company asks before this sign-in acts in it: the password where `password` is set, the authenticator
 * code beside it where `code` is, and a passkey instead where `passkey` is.
 */
final readonly class MeCompanyProof
{
    public function __construct(
        #[ApiProperty(required: true)] public bool $passkey,
        #[ApiProperty(required: true)] public bool $code,
        #[ApiProperty(required: true)] public bool $password,
    ) {
    }

    public static function of(EntryProof $proof): self
    {
        return new self($proof->passkey, $proof->code, $proof->password);
    }
}
