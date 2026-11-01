<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Concurrency\Support\Steps;

use App\Module\Vendors\Application\ManageVendors;
use App\Module\Vendors\Application\VendorInput;
use App\Module\Vendors\Domain\ValueObject\VendorProfile;
use App\Shared\Infrastructure\Doctrine\Rows;
use App\Tenancy\Domain\Model\Company;
use App\Tests\Concurrency\Support\RaceStep;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\TestContainer;
use Symfony\Component\Uid\Uuid;

/** A vendor created as its processor creates it, every racer typing the same `number`; its id. */
final class CreateVendor implements RaceStep
{
    public function run(TestContainer $container, int $index, array $arguments): mixed
    {
        $company = $container->get(EntityManagerInterface::class)->find(Company::class, Uuid::fromString(Rows::text($arguments['company'] ?? null)));
        if (!$company instanceof Company) {
            throw new \LogicException('The race names no company of the stress database.');
        }

        return $container->get(ManageVendors::class)->create($company, new VendorInput(Rows::text($arguments['number'] ?? null), new VendorProfile('Fournisseur '.$index), true), null)->getId()->toRfc4122();
    }
}
