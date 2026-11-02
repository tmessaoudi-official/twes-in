<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Concurrency\Support\Steps;

use App\Module\Inventory\Application\KeepStock;
use App\Module\Inventory\Domain\ValueObject\NamedLot;
use App\Shared\Infrastructure\Doctrine\Rows;
use App\Tests\Concurrency\Support\RacerContext;
use App\Tests\Concurrency\Support\RaceStep;
use Symfony\Bundle\FrameworkBundle\Test\TestContainer;
use Symfony\Component\Uid\Uuid;

/** One unit of `product` received at `location` under the lot or serial number `lot`, as « Réception » records it; the movement's id. */
final class ReceiveLot implements RaceStep
{
    public function run(TestContainer $container, int $index, array $arguments): mixed
    {
        return $container->get(KeepStock::class)->receive(
            RacerContext::company($container, $arguments),
            Uuid::fromString(Rows::text($arguments['product'] ?? null)),
            Uuid::fromString(Rows::text($arguments['location'] ?? null)),
            '1',
            null,
            new NamedLot(Rows::text($arguments['lot'] ?? null)),
        )->getId()->toRfc4122();
    }
}
