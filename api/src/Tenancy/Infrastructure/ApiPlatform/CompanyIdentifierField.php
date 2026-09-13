<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tenancy\Infrastructure\ApiPlatform;

use Symfony\Component\Serializer\Attribute\Groups;

/** A registration number the company's fiscal preset asks for, labelled in the reader's language. */
final class CompanyIdentifierField
{
    public function __construct(
        #[Groups([CompanyProfileResource::READ])] public string $key,
        #[Groups([CompanyProfileResource::READ])] public string $label,
        /** A regular expression the whole value matches, without delimiters. */
        #[Groups([CompanyProfileResource::READ])] public string $pattern,
        #[Groups([CompanyProfileResource::READ])] public bool $required,
    ) {
    }
}
