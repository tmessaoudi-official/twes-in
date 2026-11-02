<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Concurrency\Support\Steps;

use App\Module\Invoices\Application\Credit\ManageCustomerCredit;
use App\Shared\Infrastructure\Doctrine\Rows;
use App\Tests\Concurrency\Support\RacerContext;
use App\Tests\Concurrency\Support\RaceStep;
use Symfony\Bundle\FrameworkBundle\Test\TestContainer;
use Symfony\Component\Uid\Uuid;

/** Racer i pays `amount` of the invoice `invoices[i]` from its customer's credit, as « Utiliser l'avoir » does; the payment's id. */
final class ApplyCustomerCredit implements RaceStep
{
    public function run(TestContainer $container, int $index, array $arguments): mixed
    {
        return $container->get(ManageCustomerCredit::class)->apply(
            RacerContext::company($container, $arguments),
            Uuid::fromString(RacerContext::mine($arguments, 'invoices', $index)),
            Rows::text($arguments['amount'] ?? null),
            null,
        )->getId()->toRfc4122();
    }
}
