<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Inventory\Infrastructure\Doctrine;

use App\Module\Inventory\Domain\LotOnHand;
use App\Module\Inventory\Domain\StockLevel;
use App\Module\Inventory\Domain\StockLevelSearch;
use App\Module\Inventory\Domain\StockLot;
use App\Module\Inventory\Domain\StockMovement;
use App\Module\Inventory\Domain\StockMovementRepository;
use App\Module\Inventory\Domain\StockMovementSearch;
use App\Module\Inventory\Domain\StockValue;
use App\Module\Inventory\Domain\TypedCost;
use App\Module\Inventory\Domain\WeightedAverageCost;
use App\Module\Products\Domain\Product;
use App\Shared\Domain\Page;
use App\Shared\Domain\PageRequest;
use App\Shared\Infrastructure\Doctrine\ListOrder;
use App\Shared\Infrastructure\Doctrine\SearchText;
use BcMath\Number;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;
use Doctrine\ORM\Tools\Pagination\Paginator;
use Symfony\Component\Uid\Uuid;

final readonly class DoctrineStockMovementRepository implements StockMovementRepository
{
    /** What the text looks through: a row names a product AND a location, and a person searches for either. */
    private const string MATCHES_WORDS = "SEARCH_TEXT(p.reference, p.name, l.code, l.name) LIKE CONCAT('%', SEARCH_TEXT(:text), '%')";

    /** The DQL each sort key reads; every one is non-empty, so none needs a hidden CASE the way a nullable does. */
    private const array MOVEMENTS_SORTED = [
        'movedAt' => 'm.at',
        'product' => 'p.name',
        'location' => 'l.code',
        'kind' => 'm.kind',
        'quantity' => 'm.quantity',
        'source' => 'm.sourceType',
    ];
    private const array SORTED_BY = ['reference' => 'p.reference', 'product' => 'p.name', 'location' => 'l.code', 'quantity' => 'SUM(m.quantity)'];

    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    public function save(StockMovement ...$movements): void
    {
        $averages = [];
        foreach ($movements as $movement) {
            if (null === $movement->getUnitCost()) {
                $key = $movement->getProduct()->getId()->toRfc4122();
                $averages[$key] ??= $this->averageCostOf($movement->getProduct());
                if (null !== $averages[$key]) {
                    $movement->valuedAt($averages[$key]);
                }
            }
            $this->entityManager->persist($movement);
        }
        $this->entityManager->flush();
    }

    public function valuation(Uuid $companyId): array
    {
        $rows = $this->entityManager->createQueryBuilder()
            ->select(
                'IDENTITY(m.product) AS product',
                'SUM(m.quantity) AS quantity',
                'SUM(CASE WHEN m.unitCost IS NULL THEN 0 ELSE m.quantity * m.unitCost END) AS value',
                'SUM(CASE WHEN m.unitCost IS NULL THEN m.quantity ELSE 0 END) AS unvalued',
            )
            ->from(StockMovement::class, 'm')
            ->where('m.company = :company')
            ->groupBy('m.product')
            ->setParameter('company', $companyId, 'uuid')
            ->getQuery()
            ->getArrayResult();

        $values = [];
        foreach ($rows as $row) {
            if (!\is_array($row) || !\is_string($row['product'] ?? null)) {
                continue;
            }
            $quantity = self::decimal($row['quantity'] ?? 0);
            $value = new Number('0.0000000')->add(self::amount($row['value'] ?? 0))->value;
            if (0 === new Number($quantity)->compare(0) && 0 === new Number($value)->compare(0)) {
                continue;
            }
            $values[] = new StockValue(Uuid::fromString($row['product']), $quantity, $value, self::decimal($row['unvalued'] ?? 0));
        }

        return $values;
    }

    /** @return numeric-string|null */
    public function averageCostOf(Product $product): ?string
    {
        $totals = $this->valuedTotalsOf($product);

        return WeightedAverageCost::of($totals['quantity'], $totals['amount'], $product->getDetails()->costPrice);
    }

    public function valuedTotalsOf(Product $product): array
    {
        $row = $this->entityManager->createQueryBuilder()
            ->select('SUM(m.quantity) AS quantity', 'SUM(m.quantity * m.unitCost) AS amount')
            ->from(StockMovement::class, 'm')
            ->where('m.product = :product')
            ->andWhere('m.unitCost IS NOT NULL')
            ->setParameter('product', $product->getId(), 'uuid')
            ->getQuery()
            ->getSingleResult();
        $row = \is_array($row) ? $row : [];

        return ['quantity' => self::decimal($row['quantity'] ?? 0), 'amount' => new Number('0.0000000')->add(self::amount($row['amount'] ?? 0))->value];
    }

    public function lastTypedCostOf(Product $product): ?TypedCost
    {
        $receipt = $this->entityManager->createQueryBuilder()
            ->select('m')
            ->from(StockMovement::class, 'm')
            ->where('m.product = :product')
            ->andWhere('m.costTyped = true')
            ->orderBy('m.at', 'DESC')
            ->addOrderBy('m.id', 'DESC')
            ->setMaxResults(1)
            ->setParameter('product', $product->getId(), 'uuid')
            ->getQuery()
            ->getOneOrNullResult();
        $cost = $receipt instanceof StockMovement ? $receipt->getUnitCost() : null;

        return null === $cost ? null : new TypedCost($cost, $receipt->getAt());
    }

    /** @return int|numeric-string */
    private static function amount(mixed $sum): int|string
    {
        return \is_int($sum) || (\is_string($sum) && is_numeric($sum)) ? $sum : 0;
    }

    public function ofSource(string $sourceType, Uuid $sourceId, Uuid $companyId): array
    {
        return $this->entityManager->getRepository(StockMovement::class)->findBy(['sourceType' => $sourceType, 'sourceId' => $sourceId, 'company' => $companyId], ['at' => 'ASC', 'id' => 'ASC']);
    }

    public function searchMovements(Uuid $companyId, StockMovementSearch $search, PageRequest $page): Page
    {
        // The product, the location and the lot are joined and SELECTED whatever the search asks: every row names them,
        // and joined without being selected each row read them again, 18 statements for 6 rows (audit PF-07).
        $query = $this->entityManager->createQueryBuilder()
            ->select('m', 'p', 'l', 'lt')->from(StockMovement::class, 'm')
            ->join('m.product', 'p')->join('m.location', 'l')->leftJoin('m.lot', 'lt')
            ->where('m.company = :company')->setParameter('company', $companyId, 'uuid');
        if (null !== $search->product) {
            $query->andWhere('m.product = :product')->setParameter('product', $search->product, 'uuid');
        }
        if (null !== $search->location) {
            $query->andWhere('m.location = :location')->setParameter('location', $search->location, 'uuid');
        }
        if (null !== $search->kind) {
            $query->andWhere('m.kind = :kind')->setParameter('kind', $search->kind->value);
        }
        if (null !== $search->sourceType) {
            $query->andWhere('m.sourceType = :sourceType')->setParameter('sourceType', $search->sourceType);
        }
        $lot = trim($search->lot ?? '');
        if ('' !== $lot) {
            $query->andWhere('LOWER(lt.code) = LOWER(:lot)')->setParameter('lot', $lot);
        }
        self::narrowToWords($query, $search->text);
        // `id` breaks the tie: two movements of the same moment are common, and a page boundary that falls between
        // them would otherwise show one row twice and hide another.
        ListOrder::apply($query, $search->order, self::MOVEMENTS_SORTED, [], 'm.at', 'DESC')
            ->addOrderBy('m.id', 'DESC')
            ->setFirstResult($page->offset())->setMaxResults($page->size);

        $paginator = new Paginator($query, fetchJoinCollection: false)->setUseOutputWalkers(false);
        /** @var list<StockMovement> $movements */
        $movements = iterator_to_array($paginator, false);

        return new Page($movements, \count($paginator), $page);
    }

    /** A transaction-scoped advisory lock: stock is a sum of rows, so there is no one row to lock. */
    public function lockStockOf(Uuid $productId, Uuid $locationId): void
    {
        $this->entityManager->getConnection()->executeQuery(
            'SELECT pg_advisory_xact_lock(hashtextextended(?, 0))',
            ['stock:'.$productId->toRfc4122().':'.$locationId->toRfc4122()],
        );
    }

    public function onHand(Uuid $productId, Uuid $locationId, ?Uuid $lotId = null): string
    {
        $query = $this->entityManager->createQueryBuilder()
            ->select('SUM(m.quantity)')
            ->from(StockMovement::class, 'm')
            ->where('m.product = :product')
            ->andWhere('m.location = :location')
            ->setParameter('product', $productId, 'uuid')
            ->setParameter('location', $locationId, 'uuid');
        if (null !== $lotId) {
            $query->andWhere('m.lot = :lot')->setParameter('lot', $lotId, 'uuid');
        }

        return self::decimal($query->getQuery()->getSingleScalarResult());
    }

    public function onHandInEstablishment(Uuid $productId, Uuid $establishmentId): string
    {
        return self::decimal($this->entityManager->createQueryBuilder()
            ->select('SUM(m.quantity)')
            ->from(StockMovement::class, 'm')
            ->join('m.location', 'l')
            ->where('m.product = :product')
            ->andWhere('l.establishment = :establishment')
            ->setParameter('product', $productId, 'uuid')
            ->setParameter('establishment', $establishmentId, 'uuid')
            ->getQuery()
            ->getSingleScalarResult());
    }

    public function onHandOfLot(Uuid $lotId): string
    {
        return self::decimal($this->entityManager->createQueryBuilder()
            ->select('SUM(m.quantity)')
            ->from(StockMovement::class, 'm')
            ->where('m.lot = :lot')
            ->setParameter('lot', $lotId, 'uuid')
            ->getQuery()
            ->getSingleScalarResult());
    }

    public function lotsAt(Uuid $productId, Uuid $locationId): array
    {
        $rows = $this->entityManager->createQueryBuilder()
            ->select('lt AS lot', 'SUM(m.quantity) AS quantity')
            ->from(StockLot::class, 'lt')
            ->join(StockMovement::class, 'm', 'WITH', 'm.lot = lt')
            ->where('m.product = :product')
            ->andWhere('m.location = :location')
            ->groupBy('lt.id')
            ->setParameter('product', $productId, 'uuid')
            ->setParameter('location', $locationId, 'uuid')
            ->getQuery()
            ->getResult();

        $lots = [];
        foreach (\is_array($rows) ? $rows : [] as $row) {
            if (\is_array($row) && ($row['lot'] ?? null) instanceof StockLot) {
                $lots[] = new LotOnHand($row['lot'], self::decimal($row['quantity'] ?? null));
            }
        }

        return $lots;
    }

    public function totalsOf(Uuid $companyId, array $productIds): array
    {
        $totals = [];
        foreach ($productIds as $id) {
            $totals[$id->toRfc4122()] = '0.000';
        }
        if ([] === $productIds) {
            return $totals;
        }
        $rows = $this->entityManager->createQueryBuilder()
            ->select('IDENTITY(m.product) AS product', 'SUM(m.quantity) AS quantity')
            ->from(StockMovement::class, 'm')
            ->where('m.company = :company')
            ->andWhere('m.product IN (:products)')
            ->groupBy('m.product')
            ->setParameter('company', $companyId, 'uuid')
            ->setParameter('products', array_map(static fn (Uuid $id): string => $id->toRfc4122(), $productIds), ArrayParameterType::STRING)
            ->getQuery()
            ->getArrayResult();
        foreach ($rows as $row) {
            if (\is_array($row) && \is_string($row['product'] ?? null)) {
                $totals[Uuid::fromString($row['product'])->toRfc4122()] = self::decimal($row['quantity'] ?? 0);
            }
        }

        return $totals;
    }

    public function levels(Uuid $companyId): array
    {
        $rows = $this->entityManager->createQueryBuilder()
            ->select('IDENTITY(m.product) AS product', 'IDENTITY(m.location) AS location', 'lt.id AS lot', 'lt.code AS lotCode', 'lt.expiresOn AS lotExpiresOn', 'lt.releasedAt AS lotReleasedAt', 'SUM(m.quantity) AS quantity')
            ->from(StockMovement::class, 'm')
            ->leftJoin('m.lot', 'lt')
            ->where('m.company = :company')
            ->groupBy('m.product', 'm.location', 'lt.id')
            ->setParameter('company', $companyId, 'uuid')
            ->getQuery()
            ->getArrayResult();

        $levels = [];
        foreach ($rows as $row) {
            if (\is_array($row) && \is_string($row['product'] ?? null) && \is_string($row['location'] ?? null)) {
                $levels[] = self::level(Uuid::fromString($row['product']), Uuid::fromString($row['location']), $row);
            }
        }

        return $levels;
    }

    public function searchLevels(Uuid $companyId, StockLevelSearch $search, PageRequest $page): Page
    {
        // Grouped on the three primary keys, so PostgreSQL lets the order read the product's, the location's and the
        // lot's other columns: they depend on a key it is already grouping by. An untracked product's movements name
        // no lot, and their NULLs group together, so its row is the one it always was.
        $rows = $this->levelsQuery($companyId, $search)
            ->select('p.id AS product', 'l.id AS location', 'lt.id AS lot', 'lt.code AS lotCode', 'lt.expiresOn AS lotExpiresOn', 'lt.releasedAt AS lotReleasedAt', 'SUM(m.quantity) AS quantity')
            ->groupBy('p.id')->addGroupBy('l.id')->addGroupBy('lt.id')
            ->setFirstResult($page->offset())->setMaxResults($page->size);
        foreach ($search->order as $sort => $direction) {
            $rows->addOrderBy(self::SORTED_BY[$sort] ?? throw new \InvalidArgumentException("This list is not sorted by $sort."), $direction);
        }
        // Three keys settle every tie, because one row is one triple of them; a lot's code is unique within its product.
        $rows->addOrderBy('p.reference', 'ASC')->addOrderBy('l.code', 'ASC')->addOrderBy('lt.code', 'ASC');

        // How many rows the list holds is how many pairs the grouping makes, which no COUNT here can say in one
        // number: PostgreSQL cannot count a pair of uuids as one value, and a paginator counting an aggregate counts
        // the movements instead. So the pairs are asked for and counted — one small row each, and they are the same
        // set this list used to hand back whole.
        $total = \count($this->levelsQuery($companyId, $search)
            ->select('p.id AS product', 'l.id AS location', 'lt.id AS lot')
            ->groupBy('p.id')->addGroupBy('l.id')->addGroupBy('lt.id')
            ->getQuery()->getArrayResult());

        $levels = [];
        foreach ($rows->getQuery()->getArrayResult() as $row) {
            if (!\is_array($row)) {
                continue;
            }
            $product = self::identifier($row['product'] ?? null);
            $location = self::identifier($row['location'] ?? null);
            if (null !== $product && null !== $location) {
                $levels[] = self::level($product, $location, $row);
            }
        }

        return new Page($levels, $total, $page);
    }

    /** @param array<mixed> $row a grouped row: its lot's key, code and date, and the sum */
    private static function level(Uuid $product, Uuid $location, array $row): StockLevel
    {
        $expiresOn = $row['lotExpiresOn'] ?? null;
        $code = $row['lotCode'] ?? null;

        return new StockLevel(
            $product,
            $location,
            self::decimal($row['quantity'] ?? null),
            self::identifier($row['lot'] ?? null),
            \is_string($code) ? $code : null,
            $expiresOn instanceof \DateTimeInterface ? $expiresOn->format('Y-m-d') : null,
            ($row['lotReleasedAt'] ?? null) instanceof \DateTimeInterface,
        );
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

    /**
     * The words a person typed, over a product's and a location's own words — the same expression for a stock list and
     * for a movements list, because a person types the same thing into both boxes and expects the same rows back.
     *
     * Shorter than the index's shortest word, the words are read as a reference or a code instead: a trigram index
     * cannot serve two letters, and a person typing that few means a code, not a search.
     */
    private static function narrowToWords(QueryBuilder $query, ?string $text): void
    {
        $words = trim($text ?? '');
        if (mb_strlen($words) >= SearchText::SHORTEST) {
            $query->andWhere(self::MATCHES_WORDS)->setParameter('text', SearchText::escapeLike($words));
        } elseif ('' !== $words) {
            $query->andWhere('LOWER(p.reference) = LOWER(:reference) OR LOWER(l.code) = LOWER(:reference)')->setParameter('reference', $words);
        }
    }

    /** What a stock list is read from and narrowed by, before it is either grouped or counted. */
    private function levelsQuery(Uuid $companyId, StockLevelSearch $search): QueryBuilder
    {
        $query = $this->entityManager->createQueryBuilder()
            ->from(StockMovement::class, 'm')
            ->join('m.product', 'p')->join('m.location', 'l')->leftJoin('m.lot', 'lt')
            ->where('m.company = :company')->setParameter('company', $companyId, 'uuid');
        self::narrowToWords($query, $search->text);
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

    public function countOf(Uuid $productId, Uuid $locationId): int
    {
        return $this->entityManager->getRepository(StockMovement::class)->count(['product' => $productId, 'location' => $locationId]);
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
