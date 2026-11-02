<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Concurrency\Support\Steps;

use App\Module\Products\Application\ChangeProductCost;
use App\Module\Products\Domain\Model\Product;
use App\Module\Products\Domain\ValueObject\CostChangeSource;
use App\Shared\Infrastructure\Doctrine\Rows;
use App\Tests\Concurrency\Support\RacerContext;
use App\Tests\Concurrency\Support\RaceStep;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\TestContainer;
use Symfony\Component\Uid\Uuid;

/**
 * Racer i sets the cost of `product` to `costs[i]`, as an edit of the product does, with the product read before
 * the barrier as a request reads it first; whether the cost changed.
 */
final class ChangeCost implements RaceStep
{
    public function run(TestContainer $container, int $index, array $arguments): mixed
    {
        $product = $container->get(EntityManagerInterface::class)->find(Product::class, Uuid::fromString(Rows::text($arguments['product'] ?? null)));
        if (!$product instanceof Product) {
            throw new \LogicException('The race names no product of the stress database.');
        }

        return $container->get(ChangeProductCost::class)->handle(RacerContext::company($container, $arguments), $product, RacerContext::mine($arguments, 'costs', $index), CostChangeSource::Edited, null, null);
    }
}
