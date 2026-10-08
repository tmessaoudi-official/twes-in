<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Inventory\Infrastructure\Doctrine;

use App\Module\Inventory\Domain\LotOnHand;
use App\Module\Inventory\Domain\RunningValue;
use App\Module\Inventory\Domain\StockLevel;
use App\Module\Inventory\Domain\StockLevelSearch;
use App\Module\Inventory\Domain\StockLocationKind;
use App\Module\Inventory\Domain\StockLossReason;
use App\Module\Inventory\Domain\StockLot;
use App\Module\Inventory\Domain\StockMovement;
use App\Module\Inventory\Domain\StockMovementKind;
use App\Module\Inventory\Domain\StockMovementRepository;
use App\Module\Inventory\Domain\StockMovementSearch;
use App\Module\Inventory\Domain\StockValue;
use App\Module\Inventory\Domain\TypedCost;
use App\Module\Products\Domain\Product;
use App\Shared\Domain\Page;
use App\Shared\Domain\PageRequest;
use App\Shared\Infrastructure\Doctrine\Intervals;
use App\Shared\Infrastructure\Doctrine\ListOrder;
use App\Shared\Infrastructure\Doctrine\SearchText;
use BcMath\Number;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\ParameterType;
use Doctrine\DBAL\Types\Types;
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
        $products = [];
        foreach ($movements as $movement) {
            $products[$movement->getProduct()->getId()->toRfc4122()] = $movement->getProduct()->getId();
        }
        ksort($products);
        // A product's worth runs across all its places, so it is read and moved under the product's own lock.
        foreach ($products as $productId) {
            $this->lockValueOf($productId);
        }
        $running = [];
        foreach ($movements as $movement) {
            $key = $movement->getProduct()->getId()->toRfc4122();
            $running[$key] ??= RunningValue::of($this->valuedTotalsOf($movement->getProduct()));
            $running[$key] = $running[$key]->take($movement);
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
                'SUM(CASE WHEN m.unitCost IS NULL THEN 0 ELSE m.quantity * m.unitCost + COALESCE(m.revaluation, 0) END) AS value',
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

        return RunningValue::of($totals)->average($product->getDetails()->costPrice);
    }

    public function valuedTotalsBefore(StockMovement $movement): array
    {
        $this->lockValueOf($movement->getProduct()->getId());
        $row = $this->entityManager->createQueryBuilder()
            ->select('SUM(m.quantity) AS quantity', 'SUM(m.quantity * m.unitCost + COALESCE(m.revaluation, 0)) AS amount')
            ->from(StockMovement::class, 'm')
            ->where('m.product = :product')
            ->andWhere('m.unitCost IS NOT NULL')
            ->andWhere('m.at < :at OR (m.at = :at AND m.id < :id)')
            ->setParameter('product', $movement->getProduct()->getId(), 'uuid')
            ->setParameter('at', $movement->getAt(), Types::DATETIME_IMMUTABLE)
            ->setParameter('id', $movement->getId(), 'uuid')
            ->getQuery()
            ->getSingleResult();
        $row = \is_array($row) ? $row : [];

        return ['quantity' => self::decimal($row['quantity'] ?? 0), 'amount' => new Number('0.0000000')->add(self::amount($row['amount'] ?? 0))->value];
    }

    public function valuedAfter(StockMovement $movement): array
    {
        $after = $this->entityManager->createQueryBuilder()
            ->select('m')
            ->from(StockMovement::class, 'm')
            ->where('m.product = :product')
            ->andWhere('m.unitCost IS NOT NULL')
            ->andWhere('m.at > :at OR (m.at = :at AND m.id > :id)')
            ->setParameter('product', $movement->getProduct()->getId(), 'uuid')
            ->setParameter('at', $movement->getAt(), Types::DATETIME_IMMUTABLE)
            ->setParameter('id', $movement->getId(), 'uuid')
            ->orderBy('m.at')
            ->addOrderBy('m.id')
            ->getQuery()
            ->getResult();

        return array_values(array_filter(\is_array($after) ? $after : [], static fn (mixed $each): bool => $each instanceof StockMovement));
    }

    public function saveValued(StockMovement $movement): void
    {
        $this->entityManager->persist($movement);
        $this->entityManager->flush();
    }

    public function ofIdInCompany(Uuid $id, Uuid $companyId): ?StockMovement
    {
        $movement = $this->entityManager->find(StockMovement::class, $id);

        return $movement instanceof StockMovement && $movement->getCompany()->getId()->equals($companyId) ? $movement : null;
    }

    public function valuedTotalsOf(Product $product): array
    {
        $row = $this->entityManager->createQueryBuilder()
            ->select('SUM(m.quantity) AS quantity', 'SUM(m.quantity * m.unitCost + COALESCE(m.revaluation, 0)) AS amount')
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

    public function ofReversing(Uuid $invoiceId, Uuid $companyId): array
    {
        return $this->entityManager->getRepository(StockMovement::class)->findBy(['reversesSourceId' => $invoiceId, 'company' => $companyId], ['at' => 'ASC', 'id' => 'ASC']);
    }

    public function searchMovements(Uuid $companyId, StockMovementSearch $search, PageRequest $page): Page
    {
        // The product, the location, the lot and the vendor are joined and SELECTED whatever the search asks: every row names them,
        // and joined without being selected each row read them again, 18 statements for 6 rows (audit PF-07).
        $query = $this->entityManager->createQueryBuilder()
            ->select('m', 'p', 'l', 'lt', 'v')->from(StockMovement::class, 'm')
            ->join('m.product', 'p')->join('m.location', 'l')->leftJoin('m.lot', 'lt')->leftJoin('m.vendor', 'v')
            ->where('m.company = :company')->setParameter('company', $companyId, 'uuid');
        if ([] !== $search->products) {
            $query->andWhere('m.product IN (:products)')->setParameter('products', self::ids($search->products), ArrayParameterType::STRING);
        }
        if ([] !== $search->locations) {
            $query->andWhere('m.location IN (:locations)')->setParameter('locations', self::ids($search->locations), ArrayParameterType::STRING);
        }
        if ([] !== $search->kinds) {
            $query->andWhere('m.kind IN (:kinds)')
                ->setParameter('kinds', array_map(static fn (StockMovementKind $each): string => $each->value, $search->kinds), ArrayParameterType::STRING);
        }
        if ([] !== $search->sourceTypes) {
            $query->andWhere('m.sourceType IN (:sourceTypes)')->setParameter('sourceTypes', $search->sourceTypes, ArrayParameterType::STRING);
        }
        if ([] !== $search->reasons) {
            $query->andWhere('m.reason IN (:reasons)')
                ->setParameter('reasons', array_map(static fn (StockLossReason $each): string => $each->value, $search->reasons), ArrayParameterType::STRING);
        }
        if (null !== $search->costToComplete) {
            $query->andWhere('m.costToComplete = :costToComplete')->setParameter('costToComplete', $search->costToComplete, ParameterType::BOOLEAN);
        }
        Intervals::moments($query, 'm.at', 'moved', $search->movedOn, $search->timezone);
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

    /**
     * @param list<Uuid> $ids
     *
     * @return list<string>
     */
    private static function ids(array $ids): array
    {
        return array_map(static fn (Uuid $each): string => $each->toRfc4122(), $ids);
    }

    /**
     * A transaction-scoped advisory lock: stock is a sum of rows, so there is no one row to lock. The product's own lock
     * comes first, the one saving takes for its worth, so every writer takes the two in the same order.
     */
    public function lockStockOf(Uuid $productId, Uuid $locationId): void
    {
        $this->lockValueOf($productId);
        $this->entityManager->getConnection()->executeQuery(
            'SELECT pg_advisory_xact_lock(hashtextextended(?, 0))',
            ['stock:'.$productId->toRfc4122().':'.$locationId->toRfc4122()],
        );
    }

    private function lockValueOf(Uuid $productId): void
    {
        $this->entityManager->getConnection()->executeQuery(
            'SELECT pg_advisory_xact_lock(hashtextextended(?, 0))',
            ['stock:'.$productId->toRfc4122()],
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

    public function sellableInEstablishment(Uuid $productId, Uuid $establishmentId): string
    {
        return self::decimal($this->entityManager->createQueryBuilder()
            ->select('SUM(m.quantity)')
            ->from(StockMovement::class, 'm')
            ->join('m.location', 'l')
            ->where('m.product = :product')
            ->andWhere('l.establishment = :establishment')
            ->andWhere('l.kind <> :quarantine')
            ->setParameter('product', $productId, 'uuid')
            ->setParameter('establishment', $establishmentId, 'uuid')
            ->setParameter('quarantine', StockLocationKind::Quarantine)
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

    public function totalsOf(Uuid $companyId, array $productIds, ?Uuid $establishmentId = null): array
    {
        $totals = [];
        foreach ($productIds as $id) {
            $totals[$id->toRfc4122()] = '0.000';
        }
        if ([] === $productIds) {
            return $totals;
        }
        $query = $this->entityManager->createQueryBuilder()
            ->select('IDENTITY(m.product) AS product', 'SUM(m.quantity) AS quantity')
            ->from(StockMovement::class, 'm')
            ->where('m.company = :company')
            ->andWhere('m.product IN (:products)')
            ->groupBy('m.product')
            ->setParameter('company', $companyId, 'uuid')
            ->setParameter('products', array_map(static fn (Uuid $id): string => $id->toRfc4122(), $productIds), ArrayParameterType::STRING);
        if (null !== $establishmentId) {
            $query->join('m.location', 'l')->andWhere('l.establishment = :establishment')->setParameter('establishment', $establishmentId, 'uuid');
        }
        $rows = $query->getQuery()->getArrayResult();
        foreach ($rows as $row) {
            if (\is_array($row) && \is_string($row['product'] ?? null)) {
                $totals[Uuid::fromString($row['product'])->toRfc4122()] = self::decimal($row['quantity'] ?? 0);
            }
        }

        return $totals;
    }

    public function productsHeld(Uuid $companyId): array
    {
        $rows = $this->entityManager->createQueryBuilder()
            ->select('IDENTITY(m.location) AS location', 'IDENTITY(m.product) AS product')
            ->from(StockMovement::class, 'm')
            ->where('m.company = :company')
            ->groupBy('m.location', 'm.product')
            ->having('SUM(m.quantity) > 0')
            ->setParameter('company', $companyId, 'uuid')
            ->getQuery()
            ->getArrayResult();

        $held = [];
        foreach ($rows as $row) {
            if (\is_array($row) && \is_string($row['location'] ?? null) && \is_string($row['product'] ?? null)) {
                $held[$row['location']][] = $row['product'];
            }
        }

        return $held;
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

    public function levelsOf(Uuid $companyId, Uuid $productId): array
    {
        $rows = $this->entityManager->createQueryBuilder()
            ->select('IDENTITY(m.product) AS product', 'IDENTITY(m.location) AS location', 'lt.id AS lot', 'lt.code AS lotCode', 'lt.expiresOn AS lotExpiresOn', 'lt.releasedAt AS lotReleasedAt', 'SUM(m.quantity) AS quantity')
            ->from(StockMovement::class, 'm')
            ->leftJoin('m.lot', 'lt')
            ->where('m.company = :company')
            ->andWhere('m.product = :product')
            ->groupBy('m.product', 'm.location', 'lt.id')
            ->orderBy('IDENTITY(m.location)')
            ->addOrderBy('lt.id')
            ->setParameter('company', $companyId, 'uuid')
            ->setParameter('product', $productId, 'uuid')
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
        self::onTheSum($rows, $search);
        foreach ($search->order as $sort => $direction) {
            $rows->addOrderBy(self::SORTED_BY[$sort] ?? throw new \InvalidArgumentException("This list is not sorted by $sort."), $direction);
        }
        // Three keys settle every tie, because one row is one triple of them; a lot's code is unique within its product.
        $rows->addOrderBy('p.reference', 'ASC')->addOrderBy('l.code', 'ASC')->addOrderBy('lt.code', 'ASC');

        // How many rows the list holds is how many pairs the grouping makes, which no COUNT here can say in one
        // number: PostgreSQL cannot count a pair of uuids as one value, and a paginator counting an aggregate counts
        // the movements instead. So the pairs are asked for and counted — one small row each, and they are the same
        // set this list used to hand back whole.
        $total = \count(self::onTheSum($this->levelsQuery($companyId, $search)
            ->select('p.id AS product', 'l.id AS location', 'lt.id AS lot')
            ->groupBy('p.id')->addGroupBy('l.id')->addGroupBy('lt.id'), $search)
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
        if ([] !== $search->locations) {
            $query->andWhere('m.location IN (:locationIds)')->setParameter('locationIds', self::ids($search->locations), ArrayParameterType::STRING);
        }
        if ([] !== $search->establishments) {
            $query->andWhere('l.establishment IN (:establishmentIds)')->setParameter('establishmentIds', self::ids($search->establishments), ArrayParameterType::STRING);
        }
        if ([] !== $search->products) {
            $query->andWhere('m.product IN (:productIds)')->setParameter('productIds', self::ids($search->products), ArrayParameterType::STRING);
        }
        if ([] !== $search->categories) {
            $query->andWhere('p.category IN (:categoryIds)')->setParameter('categoryIds', self::ids($search->categories), ArrayParameterType::STRING);
        }
        if (null !== $search->expired) {
            // What the stock list marks « périmé »: past its use-by day and not released, which a delivery note does not take.
            $expired = 'lt.expiresOn IS NOT NULL AND lt.expiresOn < :today AND lt.releasedAt IS NULL';
            $query->andWhere($search->expired ? $expired : "NOT ($expired)")
                ->setParameter('today', new \DateTimeImmutable($search->today ?? 'today'), Types::DATE_IMMUTABLE);
        }
        Intervals::days($query, 'lt.expiresOn', 'useBy', $search->lotExpiresOn);

        return $query;
    }

    /** What narrows a level by its sum: a condition on the grouped rows, so it follows the grouping. */
    private static function onTheSum(QueryBuilder $query, StockLevelSearch $search): QueryBuilder
    {
        if (null !== $search->negative) {
            $query->having($search->negative ? 'SUM(m.quantity) < 0' : 'SUM(m.quantity) >= 0');
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
