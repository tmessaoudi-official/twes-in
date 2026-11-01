<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Concurrency\Support\Steps;

use App\Module\Customers\Application\CustomerInput;
use App\Module\Customers\Application\ManageCustomers;
use App\Module\Customers\Domain\ValueObject\CustomerKind;
use App\Module\Customers\Domain\ValueObject\CustomerProfile;
use App\Shared\Domain\Model\PostalAddress;
use App\Shared\Infrastructure\Doctrine\Rows;
use App\Tenancy\Domain\Model\Company;
use App\Tests\Concurrency\Support\RaceStep;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\TestContainer;
use Symfony\Component\Uid\Uuid;

/** A customer created as its processor creates it, every racer typing the same `number`; its id. */
final class CreateCustomer implements RaceStep
{
    public function run(TestContainer $container, int $index, array $arguments): mixed
    {
        $company = $container->get(EntityManagerInterface::class)->find(Company::class, Uuid::fromString(Rows::text($arguments['company'] ?? null)));
        if (!$company instanceof Company) {
            throw new \LogicException('The race names no company of the stress database.');
        }
        $input = new CustomerInput(
            Rows::text($arguments['number'] ?? null),
            new CustomerProfile(CustomerKind::Individual, 'Client '.$index, null, [], null, null, null, new PostalAddress(countryCode: $company->getCountryCode()), null),
            null,
            'standard',
            [],
            true,
            [],
        );

        return $container->get(ManageCustomers::class)->create($company, $input, null)->getId()->toRfc4122();
    }
}
