<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Concurrency\Support\Steps;

use App\Licensing\Application\ManagePayments as ManageSubscriptionPayments;
use App\Shared\Infrastructure\Doctrine\Rows;
use App\Tests\Concurrency\Support\RaceStep;
use Symfony\Bundle\FrameworkBundle\Test\TestContainer;
use Symfony\Component\Uid\Uuid;

/** An operator confirming the payment declaration `declaration` for one period, as the platform's screen confirms it; its status. */
final class DecidePaymentDeclaration implements RaceStep
{
    public function run(TestContainer $container, int $index, array $arguments): mixed
    {
        return $container->get(ManageSubscriptionPayments::class)->decide(
            Uuid::fromString(Rows::text($arguments['declaration'] ?? null)),
            true,
            Uuid::fromString(Rows::text($arguments['operator'] ?? null)),
        )->getStatus()->value;
    }
}
