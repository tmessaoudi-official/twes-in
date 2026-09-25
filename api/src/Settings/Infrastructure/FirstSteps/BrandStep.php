<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Settings\Infrastructure\FirstSteps;

use App\FirstSteps\Application\DeclaresFirstStep;
use App\ModuleRegistry\Infrastructure\ApiPlatform\ModulePermission;
use App\Settings\Domain\SettingLevel;
use App\Tenancy\Domain\Company;
use Doctrine\DBAL\Connection;

/**
 * « Votre couleur »: done once the company has chosen its own accent. The approved step also names the logo, which
 * waits for the brand kit (row 36); the step keeps its key when the logo joins it.
 */
final readonly class BrandStep implements DeclaresFirstStep
{
    public function __construct(private Connection $connection)
    {
    }

    public function key(): string
    {
        return 'company.brand';
    }

    public function position(): int
    {
        return 50;
    }

    public function module(): ?string
    {
        return null;
    }

    public function permission(): string
    {
        return ModulePermission::WRITE;
    }

    public function isDone(Company $company): bool
    {
        return true === $this->connection->fetchOne(
            'SELECT EXISTS (SELECT 1 FROM setting WHERE level = :level AND level_id = :company AND key = :key)',
            ['level' => SettingLevel::Company->value, 'company' => $company->getId()->toRfc4122(), 'key' => 'presentation.accent'],
        );
    }
}
