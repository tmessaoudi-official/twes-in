<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Inventory\Infrastructure\Doctrine;

use App\Module\Inventory\Domain\StockLevel;
use App\Module\Inventory\Domain\StockLevelSearch;
use App\Module\Inventory\Domain\StockMovement;
use App\Module\Inventory\Domain\StockMovementRepository;
use App\Shared\Domain\Page;
use App\Shared\Domain\PageRequest;
use App\Shared\Infrastructure\Doctrine\SearchText;
use BcMath\Number;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;
use Symfony\Component\Uid\Uuid;

final readonly class DoctrineStockMovementRepository implements StockMovementRepository
{
    /** What the text looks through: a row names a product AND a location, and a person searches for either. */
    private const string MATCHES_WORDS = "SEARCH_TEXT(p.reference, p.name, l.code, l.name) LIKE CONCAT('%', SEARCH_TEXT(:text), '%')";

    /** The DQL each sort key reads; every one is non-empty, so none needs a hidden CASE the way a nullable does. */
    private const array SORTED_BY = ['reference' => 'p.reference', 'product' => 'p.name', 'location' => 'l.code', 'quantity' => 'SUM(m.quantity)'];

    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    public function save(StockMovement ...$movements): void
    {
        foreach ($movements as $movement) {
            $this->entityManager->persist($movement);
        }
        $this->entityManager->flush();
    }

    public function ofSource(string $sourceType, Uuid $sourceId, Uuid $companyId): array
    {
        return $this->entityManager->getRepository(StockMovement::class)->findBy(['sourceType' => $sourceType, 'sourceId' => $sourceId, 'company' => $companyId], ['at' => 'ASC', 'id' => 'ASC']);
    }

    public function ofCompany(Uuid $companyId, int $limit): array
    {
        return $this->entityManager->getRepository(StockMovement::class)->findBy(['company' => $companyId], ['at' => 'DESC', 'id' => 'DESC'], $limit);
    }

    public function ofProduct(Uuid $productId, Uuid $companyId, int $limit): array
    {
        return $this->entityManager->getRepository(StockMovement::class)->findBy(['product' => $productId, 'company' => $companyId], ['at' => 'DESC', 'id' => 'DESC'], $limit);
    }

    /** A transaction-scoped advisory lock: stock is a sum of rows, so there is no one row to lock. */
    public function lockStockOf(Uuid $productId, Uuid $locationId): void
    {
        $this->entityManager->getConnection()->executeQuery(
            'SELECT pg_advisory_xact_lock(hashtextextended(?, 0))',
            ['stock:'.$productId->toRfc4122().':'.$locationId->toRfc4122()],
        );
    }

    public function onHand(Uuid $productId, Uuid $locationId): string
    {
        $sum = $this->entityManager->createQueryBuilder()
            ->select('SUM(m.quantity)')
            ->from(StockMovement::class, 'm')
            ->where('m.product = :product')
            ->andWhere('m.location = :location')
            ->setParameter('product', $productId, 'uuid')
            ->setParameter('location', $locationId, 'uuid')
            ->getQuery()
            ->getSingleScalarResult();

        return self::decimal($sum);
    }

    public function levels(Uuid $companyId): array
    {
        $rows = $this->entityManager->createQueryBuilder()
            ->select('IDENTITY(m.product) AS product', 'IDENTITY(m.location) AS location', 'SUM(m.quantity) AS quantity')
            ->from(StockMovement::class, 'm')
            ->where('m.company = :company')
            ->groupBy('m.product', 'm.location')
            ->setParameter('company', $companyId, 'uuid')
            ->getQuery()
            ->getArrayResult();

        $levels = [];
        foreach ($rows as $row) {
            if (\is_array($row) && \is_string($row['product'] ?? null) && \is_string($row['location'] ?? null)) {
                $levels[] = new StockLevel(Uuid::fromString($row['product']), Uuid::fromString($row['location']), self::decimal($row['quantity'] ?? null));
            }
        }

        return $levels;
    }

    public function searchLevels(Uuid $companyId, StockLevelSearch $search, PageRequest $page): Page
    {
        // Grouped on the two primary keys, so PostgreSQL lets the order read the product's and the location's other
        // columns: they depend on a key it is already grouping by.
        $rows = $this->levelsQuery($companyId, $search)
            ->select('p.id AS product', 'l.id AS location', 'SUM(m.quantity) AS quantity')
            ->groupBy('p.id')->addGroupBy('l.id')
            ->setFirstResult($page->offset())->setMaxResults($page->size);
        foreach ($search->order as $sort => $direction) {
            $rows->addOrderBy(self::SORTED_BY[$sort] ?? throw new \InvalidArgumentException("This list is not sorted by $sort."), $direction);
        }
        // Two keys settle every tie, because one row is one pair of them.
        $rows->addOrderBy('p.reference', 'ASC')->addOrderBy('l.code', 'ASC');

        // How many rows the list holds is how many pairs the grouping makes, which no COUNT here can say in one
        // number: PostgreSQL cannot count a pair of uuids as one value, and a paginator counting an aggregate counts
        // the movements instead. So the pairs are asked for and counted — one small row each, and they are the same
        // set this list used to hand back whole.
        $total = \count($this->levelsQuery($companyId, $search)
            ->select('p.id AS product', 'l.id AS location')
            ->groupBy('p.id')->addGroupBy('l.id')
            ->getQuery()->getArrayResult());

        $levels = [];
        foreach ($rows->getQuery()->getArrayResult() as $row) {
            if (!\is_array($row)) {
                continue;
            }
            $product = self::identifier($row['product'] ?? null);
            $location = self::identifier($row['location'] ?? null);
            if (null !== $product && null !== $location) {
                $levels[] = new StockLevel($product, $location, self::decimal($row['quantity'] ?? null));
            }
        }

        return new Page($levels, $total, $page);
    }

    /** A selected key comes back as the uuid type hydrates it — an object here, a string where the raw column is read. */
    private static function identifier(mixed $value): ?Uuid
    {
        return match (true) {
            $value instanceof Uuid => $value,
            \is_string($value) => Uuid::fromString($value),
            default => null,
        };
    }

    /** What a stock list is read from and narrowed by, before it is either grouped or counted. */
    private function levelsQuery(Uuid $companyId, StockLevelSearch $search): QueryBuilder
    {
        $query = $this->entityManager->createQueryBuilder()
            ->from(StockMovement::class, 'm')
            ->join('m.product', 'p')->join('m.location', 'l')
            ->where('m.company = :company')->setParameter('company', $companyId, 'uuid');
        $words = trim($search->text ?? '');
        if (mb_strlen($words) >= SearchText::SHORTEST) {
            $query->andWhere(self::MATCHES_WORDS)->setParameter('text', SearchText::escapeLike($words));
        } elseif ('' !== $words) {
            $query->andWhere('LOWER(p.reference) = LOWER(:reference) OR LOWER(l.code) = LOWER(:reference)')->setParameter('reference', $words);
        }
        if (null !== $search->location) {
            $query->andWhere('m.location = :locationId')->setParameter('locationId', $search->location, 'uuid');
        }
        if (null !== $search->establishment) {
            $query->andWhere('l.establishment = :establishmentId')->setParameter('establishmentId', $search->establishment, 'uuid');
        }

        return $query;
    }

    public function countAt(Uuid $locationId): int
    {
        return $this->entityManager->getRepository(StockMovement::class)->count(['location' => $locationId]);
    }

    /**
     * The database's sum with three decimals; no row sums to nothing.
     *
     * @return numeric-string
     */
    private static function decimal(mixed $sum): string
    {
        return new Number('0.000')->add(\is_int($sum) || (\is_string($sum) && is_numeric($sum)) ? $sum : 0)->value;
    }
}
