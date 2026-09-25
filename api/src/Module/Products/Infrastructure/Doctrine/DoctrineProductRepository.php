<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Products\Infrastructure\Doctrine;

use App\Module\Products\Domain\Barcode;
use App\Module\Products\Domain\Gs1Scan;
use App\Module\Products\Domain\Product;
use App\Module\Products\Domain\ProductBarcode;
use App\Module\Products\Domain\ProductKind;
use App\Module\Products\Domain\ProductRepository;
use App\Module\Products\Domain\ProductSearch;
use App\Shared\Domain\Page;
use App\Shared\Domain\PageRequest;
use App\Shared\Infrastructure\Doctrine\ListOrder;
use App\Shared\Infrastructure\Doctrine\SearchText;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;
use Doctrine\ORM\Tools\Pagination\Paginator;
use Symfony\Component\Uid\Uuid;

final readonly class DoctrineProductRepository implements ProductRepository
{
    /**
     * The words of `:text`, found through idx_product_search: the index is built on this very SEARCH_TEXT expression.
     * A product's codes are not in it (docs/SPEC.md § 7, 2026-09-22): a code is found whole, through the company's
     * unique key on it, and added to the words as `OR p.id = :code` — two indexes PostgreSQL combines, where a joined
     * LIKE over the codes would read every row.
     */
    public const string MATCHES_WORDS = "SEARCH_TEXT(p.reference, p.name) LIKE CONCAT('%', SEARCH_TEXT(:text), '%')";
    private const array SORTED_BY = ['reference' => 'p.reference', 'name' => 'p.name', 'kind' => 'p.kind', 'category' => 'c.name', 'isActive' => 'p.isActive'];

    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    public function ofCompany(Uuid $companyId): array
    {
        return $this->entityManager->getRepository(Product::class)->findBy(['company' => $companyId], ['reference' => 'ASC']);
    }

    public function search(Uuid $companyId, ProductSearch $search, PageRequest $page): Page
    {
        $query = $this->entityManager->createQueryBuilder()
            ->select('p', 'c')->from(Product::class, 'p')
            ->leftJoin('p.category', 'c')
            ->where('p.company = :company')->setParameter('company', $companyId, 'uuid');
        $this->matchWords($query, $companyId, trim($search->text ?? ''));
        if (null !== $search->kind) {
            $query->andWhere('p.kind = :kind')->setParameter('kind', $search->kind->value);
        }
        if (null !== $search->active) {
            $query->andWhere('p.isActive = :active')->setParameter('active', $search->active);
        }
        ListOrder::apply($query, $search->order, self::SORTED_BY, ['category'], 'p.reference')
            ->setFirstResult($page->offset())->setMaxResults($page->size);

        $paginator = new Paginator($query, fetchJoinCollection: false);
        /** @var list<Product> $products */
        $products = iterator_to_array($paginator, false);
        $this->loadWhatARowShows($products);

        return new Page($products, \count($paginator), $page);
    }

    /**
     * A row answers its unit and its codes. Read in one statement for the whole page, they cost the same for 1 row or
     * 100, where walking them row by row cost 17 statements for 6 rows (Doctrine, "Improving performance": fetch joins;
     * audit PF-07). The query only fills products already in memory.
     *
     * @param list<Product> $products
     */
    private function loadWhatARowShows(array $products): void
    {
        if ([] === $products) {
            return;
        }
        $this->entityManager
            ->createQuery('SELECT p, u, b FROM '.Product::class.' p JOIN p.unit u LEFT JOIN p.barcodes b WHERE p.id IN (:ids)')
            ->setParameter('ids', array_map(static fn (Product $product): string => $product->getId()->toRfc4122(), $products), ArrayParameterType::STRING)
            ->getResult();
    }

    public function pick(Uuid $companyId, string $words, int $limit, ?ProductKind $kind = null): array
    {
        $query = $this->entityManager->createQueryBuilder()
            ->select('p')->from(Product::class, 'p')
            ->where('p.company = :company')->setParameter('company', $companyId, 'uuid')
            ->andWhere('p.isActive = true');
        if (null !== $kind) {
            $query->andWhere('p.kind = :kind')->setParameter('kind', $kind->value);
        }
        $code = $this->matchWords($query, $companyId, trim($words));
        if (null !== $code) {
            // A scan is exact, so the product it names is the first offered: that is what lets Enter take it.
            $query->addSelect('CASE WHEN p.id = :code THEN 0 ELSE 1 END AS HIDDEN exact')->orderBy('exact', 'ASC');
        }

        /** @var list<Product> $products */
        $products = $query->addOrderBy('p.reference', 'ASC')->setMaxResults($limit)->getQuery()->getResult();

        return $products;
    }

    public function ofIdsInCompany(array $ids, Uuid $companyId): array
    {
        return [] === $ids ? [] : $this->entityManager->getRepository(Product::class)->findBy(['id' => $ids, 'company' => $companyId]);
    }

    public function ofIdInCompany(Uuid $id, Uuid $companyId): ?Product
    {
        $product = $this->entityManager->find(Product::class, $id);

        return null !== $product && $product->getCompany()->getId()->equals($companyId) ? $product : null;
    }

    public function ofReferenceInCompany(string $reference, Uuid $companyId): ?Product
    {
        return $this->entityManager->getRepository(Product::class)->findOneBy(['company' => $companyId, 'reference' => $reference]);
    }

    public function barcodeOfKeyInCompany(string $key, Uuid $companyId): ?ProductBarcode
    {
        return $this->entityManager->getRepository(ProductBarcode::class)->findOneBy(['company' => $companyId, 'matchKey' => $key]);
    }

    /**
     * Narrows the query to what the words find, or to the product one of whose codes they spell exactly, a GS1 scan
     * by its GTIN.
     *
     * @return Uuid|null the product the words name by one of its codes
     */
    private function matchWords(QueryBuilder $query, Uuid $companyId, string $words): ?Uuid
    {
        if ('' === $words) {
            return null;
        }
        // A GS1 scan is looked up by the GTIN of its (01): the lot and serial it carries are no part of the product.
        $code = $this->barcodeOfKeyInCompany(Barcode::keyOf(Gs1Scan::read($words)->code()), $companyId)?->getProduct()->getId();
        $byCode = null === $code ? '' : ' OR p.id = :code';
        if (mb_strlen($words) >= SearchText::SHORTEST) {
            $query->andWhere('('.self::MATCHES_WORDS.$byCode.')')->setParameter('text', SearchText::escapeLike($words));
        } else {
            $query->andWhere('(LOWER(p.reference) = LOWER(:reference)'.$byCode.')')->setParameter('reference', $words);
        }
        if (null !== $code) {
            $query->setParameter('code', $code, 'uuid');
        }

        return $code;
    }

    public function countInCategory(Uuid $categoryId): int
    {
        return $this->entityManager->getRepository(Product::class)->count(['category' => $categoryId]);
    }

    public function save(Product $product): void
    {
        $this->entityManager->persist($product);
        $this->entityManager->flush();
    }
}
