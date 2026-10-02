<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Identity\Infrastructure\ApiPlatform;

use App\Tenancy\Application\Company\AccountCompany;
use Symfony\Component\Serializer\Attribute\Groups;

/** One company of an account in the platform's list, embedded in its row and never a resource of its own. */
final class PlatformAccountCompanyRow
{
    #[Groups([PlatformAccountResource::READ])]
    public string $id = '';

    #[Groups([PlatformAccountResource::READ])]
    public string $name = '';

    /** The name of the role the account holds there: `owner`, `member`, or a role of the company's own. */
    #[Groups([PlatformAccountResource::READ])]
    public string $role = '';

    public static function of(AccountCompany $company): self
    {
        $row = new self();
        $row->id = $company->id;
        $row->name = $company->name;
        $row->role = $company->role;

        return $row;
    }
}
