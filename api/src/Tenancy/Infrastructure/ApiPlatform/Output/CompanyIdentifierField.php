<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tenancy\Infrastructure\ApiPlatform\Output;

use App\Tenancy\Infrastructure\ApiPlatform\Resource\CompanyProfileResource;
use Symfony\Component\Serializer\Attribute\Groups;

/** A registration number the company's fiscal preset asks for, labelled in the reader's language. */
final class CompanyIdentifierField
{
    public function __construct(
        #[Groups([CompanyProfileResource::READ])] public string $key,
        #[Groups([CompanyProfileResource::READ])] public string $label,
        /** A regular expression the whole value matches, without delimiters; null when the API alone judges every writing. */
        #[Groups([CompanyProfileResource::READ])] public ?string $pattern,
        #[Groups([CompanyProfileResource::READ])] public bool $required,
        /** What the form shows under the field, its example in it, in the reader's language; null when the preset says nothing. */
        #[Groups([CompanyProfileResource::READ])] public ?string $hint = null,
    ) {
    }
}
