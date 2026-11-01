<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Concurrency\Support\Steps;

use App\Fiscal\Domain\Repository\UnitRepository;
use App\Module\Products\Application\ProductReferences;
use App\Module\Products\Domain\Model\Product;
use App\Module\Products\Domain\ValueObject\ProductDetails;
use App\Module\Products\Domain\ValueObject\ProductKind;
use App\Shared\Application\Transactions;
use App\Shared\Infrastructure\Doctrine\Rows;
use App\Tenancy\Domain\Model\Company;
use App\Tests\Concurrency\Support\RaceStep;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\TestContainer;
use Symfony\Component\Uid\Uuid;

/**
 * A product saved with its reference left empty, as `ManageProducts::create` saves one: the next free reference taken
 * from the company's counter inside the transaction that stores the product; the reference.
 */
final class TakeProductReference implements RaceStep
{
    public function run(TestContainer $container, int $index, array $arguments): mixed
    {
        $entityManager = $container->get(EntityManagerInterface::class);
        $company = $entityManager->find(Company::class, Uuid::fromString(Rows::text($arguments['company'] ?? null)));
        if (!$company instanceof Company) {
            throw new \LogicException('The race names no company of the stress database.');
        }
        $piece = $container->get(UnitRepository::class)->ofCodeInCompany('C62', $company->getId()) ?? throw new \LogicException('The company has no piece unit.');

        return $container->get(Transactions::class)->run(static function () use ($container, $entityManager, $company, $piece, $index): string {
            $reference = $container->get(ProductReferences::class)->take($company, null);
            $entityManager->persist(Product::create($company, $reference, new ProductDetails('Article '.$index, null, ProductKind::Goods, '10'), $piece, null, [], new \DateTimeImmutable()));
            $entityManager->flush();

            return $reference;
        });
    }
}
