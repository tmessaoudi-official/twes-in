<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Concurrency\Support\Steps;

use App\Module\Invoices\Application\Payment\ManagePayments;
use App\Module\Invoices\Domain\ValueObject\PaymentDetails;
use App\Shared\Domain\ValueObject\PaymentMethod;
use App\Shared\Infrastructure\Doctrine\Rows;
use App\Tenancy\Domain\Model\Company;
use App\Tests\Concurrency\Support\RaceStep;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\TestContainer;
use Symfony\Component\Uid\Uuid;

/** A payment of `amount` in cash on `day` on the invoice `invoice`, recorded as its processor records it; its id. */
final class PayInvoice implements RaceStep
{
    public function run(TestContainer $container, int $index, array $arguments): mixed
    {
        $company = $container->get(EntityManagerInterface::class)->find(Company::class, Uuid::fromString(Rows::text($arguments['company'] ?? null)));
        if (!$company instanceof Company) {
            throw new \LogicException('The race names no company of the stress database.');
        }

        return $container->get(ManagePayments::class)->record(
            $company,
            Uuid::fromString(Rows::text($arguments['invoice'] ?? null)),
            new PaymentDetails(new \DateTimeImmutable(Rows::text($arguments['day'] ?? null)), Rows::text($arguments['amount'] ?? null), PaymentMethod::Cash),
            null,
        )->getId()->toRfc4122();
    }
}
