<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Inventory\Infrastructure\Doctrine;

use App\Module\Inventory\Application\Merge\MergedProducts;
use App\Module\Inventory\Domain\Exception\InvalidStockMovement;
use App\Module\Inventory\Domain\Model\StockLot;
use App\Module\Inventory\Domain\Model\StockMovement;
use App\Module\Inventory\Domain\Repository\StockMovementRepository;
use App\Module\Inventory\Domain\ValueObject\LotOnHand;
use App\Module\Inventory\Domain\ValueObject\RunningValue;
use App\Module\Inventory\Domain\ValueObject\StockLevel;
use App\Module\Inventory\Domain\ValueObject\StockLevelSearch;
use App\Module\Inventory\Domain\ValueObject\StockLocationKind;
use App\Module\Inventory\Domain\ValueObject\StockLossReason;
use App\Module\Inventory\Domain\ValueObject\StockMovementKind;
use App\Module\Inventory\Domain\ValueObject\StockMovementSearch;
use App\Module\Inventory\Domain\ValueObject\StockValue;
use App\Module\Inventory\Domain\ValueObject\TypedCost;
use App\Module\Products\Domain\Model\Product;
use App\Settings\Application\ReadSetting;
use App\Shared\Domain\Service\TypedText;
use App\Shared\Domain\ValueObject\Page;
use App\Shared\Domain\ValueObject\PageRequest;
use App\Shared\Infrastructure\Doctrine\Intervals;
use App\Shared\Infrastructure\Doctrine\ListOrder;
use App\Shared\Infrastructure\Doctrine\Rows;
use App\Shared\Infrastructure\Doctrine\SearchText;
use App\Tenancy\Domain\Model\Company;
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

    /** Whether stock is kept of the product `p`, the company's word bound as `companyKeepsStock` ({@see StockKept}). */
    private const string KEPT = 'STOCK_KEPT(p.kind, IDENTITY(p.company), IDENTITY(p.category), p.id, :companyKeepsStock) = true';

    /**
     * « Sortie sans stock » (Decisions Log 2026-10-28 00:13), of a movement `m` whose product is `p`: a sale or a delivery
     * after which its place held less than nothing, every movement of the product there summed up to it in the order they
     * moved (the second, then the id), and that no count of the product at that place followed, nor of the product it
     * was merged into, which counts what it carried. A sale is never blocked, so this is what a count has to settle; of
     * a product whose stock is no longer kept, a count is refused, so its sales are none.
     */
    private const string WITHOUT_STOCK = 'm.quantity < 0 AND m.sourceType IN (:soldSources) AND '.self::KEPT
        .' AND (SELECT SUM(b.quantity) FROM '.StockMovement::class.' b WHERE b.company = m.company AND b.product = m.product'
        .' AND b.location = m.location AND (b.at < m.at OR (b.at = m.at AND b.id <= m.id))) < 0'
        .' AND NOT EXISTS (SELECT c.id FROM '.StockMovement::class.' c WHERE c.company = m.company AND c.location = m.location'
        .' AND (c.product = m.product OR c.product = p.mergedInto) AND c.sourceType = :countSource'
        .' AND (c.at > m.at OR (c.at = m.at AND c.id > m.id)))';

    public function __construct(private EntityManagerInterface $entityManager, private MergedProducts $mergedProducts, private ReadSetting $settings)
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
        // Read under the locks: a merge holds them while it carries the stock away and aliases the product.
        $merged = $this->mergedProducts->mergedAway(array_values($products));
        foreach ($movements as $movement) {
            $kept = $merged[$movement->getProduct()->getId()->toRfc4122()] ?? null;
            if (null !== $kept) {
                $reference = $movement->getProduct()->getReference();
                throw new InvalidStockMovement('productId', \sprintf('The product %s was merged into %s: its stock is that product\'s now.', $reference, $kept), reason: 'product_merged', params: ['reference' => $reference, 'kept' => $kept]);
            }
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
        foreach ($rows as $each) {
            $row = Rows::row($each);
            $quantity = self::decimal($row['quantity']);
            $value = new Number('0.0000000')->add(Rows::sum($row['value']))->value;
            if (0 === new Number($quantity)->compare(0) && 0 === new Number($value)->compare(0)) {
                continue;
            }
            $values[] = new StockValue(Uuid::fromString(Rows::text($row['product'])), $quantity, $value, self::decimal($row['unvalued']));
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
        $row = Rows::row($row);

        return ['quantity' => self::decimal($row['quantity']), 'amount' => new Number('0.0000000')->add(Rows::sum($row['amount']))->value];
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

        return Rows::entities($after, StockMovement::class);
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
        $row = Rows::row($row);

        return ['quantity' => self::decimal($row['quantity']), 'amount' => new Number('0.0000000')->add(Rows::sum($row['amount']))->value];
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

    public function ofSource(string $sourceType, Uuid $sourceId, Uuid $companyId): array
    {
        return $this->entityManager->getRepository(StockMovement::class)->findBy(['sourceType' => $sourceType, 'sourceId' => $sourceId, 'company' => $companyId], ['at' => 'ASC', 'id' => 'ASC']);
    }

    public function ofImportRun(Uuid $runId, Uuid $companyId): array
    {
        return $this->entityManager->getRepository(StockMovement::class)->findBy(['importRunId' => $runId, 'company' => $companyId], ['at' => 'ASC', 'id' => 'ASC']);
    }

    public function movedSinceLastCount(Uuid $productId, Uuid $locationId, Uuid $companyId): bool
    {
        // Plain SQL, which the company filter does not reach: nothing ties a movement's product to its company, so only
        // the company's own rows answer. `at` keeps the second only, so a movement in the very second of the count is taken as after it: asking again is
        // the safe side of a tie. A cost correction moves no goods.
        $moved = $this->entityManager->getConnection()->fetchOne(
            'SELECT EXISTS (SELECT 1 FROM stock_movement m WHERE m.company_id = :company AND m.product_id = :product AND m.location_id = :location'
            .' AND m.source_type NOT IN (:count, :correction) AND m.quantity <> 0'
            .' AND m.at >= COALESCE((SELECT MAX(c.at) FROM stock_movement c WHERE c.company_id = :company AND c.product_id = :product AND c.location_id = :location AND c.source_type = :count), \'-infinity\'))',
            ['company' => $companyId->toRfc4122(), 'product' => $productId->toRfc4122(), 'location' => $locationId->toRfc4122(), 'count' => StockMovement::SOURCE_COUNT, 'correction' => StockMovement::SOURCE_COST_CORRECTION],
        );

        return true === $moved;
    }

    public function countedSince(Uuid $productId, Uuid $locationId, \DateTimeImmutable $since, Uuid $exceptRunId, Uuid $companyId): bool
    {
        // Plain SQL: only the company's own counts answer, as above. `at` keeps the second only, so a count in the same
        // second is taken as after: the safe side of a tie.
        $counted = $this->entityManager->getConnection()->fetchOne(
            'SELECT EXISTS (SELECT 1 FROM stock_movement m WHERE m.company_id = :company AND m.product_id = :product AND m.location_id = :location'
            .' AND m.source_type = :count AND m.at >= :since AND m.import_run_id IS DISTINCT FROM :run)',
            ['company' => $companyId->toRfc4122(), 'product' => $productId->toRfc4122(), 'location' => $locationId->toRfc4122(), 'count' => StockMovement::SOURCE_COUNT, 'since' => $since, 'run' => $exceptRunId->toRfc4122()],
            ['since' => Types::DATETIME_IMMUTABLE],
        );

        return true === $counted;
    }

    public function ofReversing(Uuid $invoiceId, Uuid $companyId): array
    {
        return $this->entityManager->getRepository(StockMovement::class)->findBy(['reversesSourceId' => $invoiceId, 'company' => $companyId], ['at' => 'ASC', 'id' => 'ASC']);
    }

    public function searchMovements(Uuid $companyId, StockMovementSearch $search, PageRequest $page): Page
    {
        // The product, the location, the lot and the vendor (the one kept, for a vendor merged away) are joined and
        // SELECTED whatever the search asks: every row names them, and joined without being selected each row read them
        // again, 18 statements for 6 rows (audit PF-07).
        $query = $this->entityManager->createQueryBuilder()
            ->select('m', 'p', 'l', 'lt', 'v', 'vk')->from(StockMovement::class, 'm')
            ->join('m.product', 'p')->join('m.location', 'l')->leftJoin('m.lot', 'lt')->leftJoin('m.vendor', 'v')->leftJoin('v.mergedInto', 'vk')
            ->where('m.company = :company')->setParameter('company', $companyId, 'uuid');
        if ([] !== $search->products) {
            // A product's movements are also those of the products merged into it, each still naming the product it moved.
            $query->andWhere('m.product IN (:products) OR p.mergedInto IN (:products)')->setParameter('products', self::ids($search->products), ArrayParameterType::STRING);
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
        if (null !== $search->withoutStock) {
            $this->soldWithoutStock($query->andWhere($search->withoutStock ? self::WITHOUT_STOCK : 'NOT ('.self::WITHOUT_STOCK.')'), $companyId);
        }
        Intervals::moments($query, 'm.at', 'moved', $search->movedOn, $search->timezone);
        $lot = TypedText::trimmed($search->lot ?? '');
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

    public function soldWithoutStockAmong(Uuid $companyId, array $movements): array
    {
        // Only a sale or a delivery out can be one: a page holding none asks the database nothing more.
        $sales = array_filter($movements, static fn (StockMovement $each): bool => \in_array($each->getSourceType(), [StockMovement::SOURCE_DELIVERY_NOTE, StockMovement::SOURCE_INVOICE], true)
            && str_starts_with($each->getQuantity(), '-'));
        if ([] === $sales) {
            return [];
        }
        $rows = $this->soldWithoutStock($this->entityManager->createQueryBuilder()
            ->select('m.id')->from(StockMovement::class, 'm')->join('m.product', 'p')
            ->where('m.company = :company')->setParameter('company', $companyId, 'uuid')
            ->andWhere('m.id IN (:movements)')->setParameter('movements', self::ids(array_map(static fn (StockMovement $each): Uuid => $each->getId(), array_values($sales))), ArrayParameterType::STRING)
            ->andWhere(self::WITHOUT_STOCK), $companyId)
            ->getQuery()->getScalarResult();

        $found = [];
        foreach ($rows as $row) {
            $id = \is_array($row) ? ($row['id'] ?? null) : null;
            $found[] = match (true) {
                $id instanceof Uuid => $id->toRfc4122(),
                \is_string($id) => $id,
                default => throw new \LogicException('A movement read back without its id.'),
            };
        }

        return $found;
    }

    /** The bindings WITHOUT_STOCK names, the company's own word on keeping stock among them. */
    private function soldWithoutStock(QueryBuilder $query, Uuid $companyId): QueryBuilder
    {
        return $query->setParameter('soldSources', [StockMovement::SOURCE_DELIVERY_NOTE, StockMovement::SOURCE_INVOICE], ArrayParameterType::STRING)
            ->setParameter('countSource', StockMovement::SOURCE_COUNT)
            ->setParameter('companyKeepsStock', $this->companyKeepsStock($companyId), ParameterType::BOOLEAN);
    }

    /** What the company says of keeping stock, before a category or a product says otherwise: KEPT's bound word. */
    private function companyKeepsStock(Uuid $companyId): bool
    {
        $company = $this->entityManager->find(Company::class, $companyId) ?? throw new \LogicException('The stock of a company that is not there.');

        return StockKept::byCompany($this->settings, $company);
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

    public function lockValueOf(Uuid $productId): void
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
            $row = Rows::row($row);
            $totals[Uuid::fromString(Rows::text($row['product']))->toRfc4122()] = self::decimal($row['quantity']);
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

    public function awaitsCost(Uuid $companyId, Uuid $productId): bool
    {
        return (bool) $this->entityManager->getConnection()->fetchOne(
            'SELECT EXISTS (SELECT 1 FROM stock_movement WHERE company_id = ? AND product_id = ? AND cost_to_complete)',
            [$companyId->toRfc4122(), $productId->toRfc4122()],
        );
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
        $this->onTheSum($rows, $search, $companyId);
        foreach ($search->order as $sort => $direction) {
            $rows->addOrderBy(self::SORTED_BY[$sort] ?? throw new \InvalidArgumentException("This list is not sorted by $sort."), $direction);
        }
        // Three keys settle every tie, because one row is one triple of them; a lot's code is unique within its product.
        $rows->addOrderBy('p.reference', 'ASC')->addOrderBy('l.code', 'ASC')->addOrderBy('lt.code', 'ASC');

        // How many rows the list holds is how many pairs the grouping makes, which no COUNT here can say in one
        // number: PostgreSQL cannot count a pair of uuids as one value, and a paginator counting an aggregate counts
        // the movements instead. So the pairs are asked for and counted — one small row each, and they are the same
        // set this list used to hand back whole.
        $total = \count($this->onTheSum($this->levelsQuery($companyId, $search)
            ->select('p.id AS product', 'l.id AS location', 'lt.id AS lot')
            ->groupBy('p.id')->addGroupBy('l.id')->addGroupBy('lt.id'), $search, $companyId)
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
        $words = TypedText::trimmed($text ?? '');
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

    /**
     * What narrows a level by its sum: a condition on the grouped rows, so it follows the grouping. « Sous zéro » is the
     * watch's subject of the same name, which links here as holding the same rows: a level below zero of a product whose
     * stock is kept, since a count of any other is refused; « no » is every other level, so the two split the list.
     */
    private function onTheSum(QueryBuilder $query, StockLevelSearch $search, Uuid $companyId): QueryBuilder
    {
        if (null !== $search->negative) {
            $belowZero = 'SUM(m.quantity) < 0 AND '.self::KEPT;
            $query->having($search->negative ? $belowZero : 'NOT ('.$belowZero.')')
                ->setParameter('companyKeepsStock', $this->companyKeepsStock($companyId), ParameterType::BOOLEAN);
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
        return new Number('0.000')->add(Rows::sum($sum))->value;
    }
}
