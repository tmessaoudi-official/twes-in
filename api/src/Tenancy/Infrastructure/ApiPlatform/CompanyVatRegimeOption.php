<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tenancy\Infrastructure\ApiPlatform;

use Symfony\Component\Serializer\Attribute\Groups;

/** A VAT regime the company's fiscal preset offers companies, labelled in the reader's language. */
final class CompanyVatRegimeOption
{
    public function __construct(
        #[Groups([CompanyProfileResource::READ])] public string $code,
        #[Groups([CompanyProfileResource::READ])] public string $label,
    ) {
    }
}
