<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Products\Infrastructure\Doctrine;

use App\Module\Products\Domain\Product;
use App\Module\Products\Domain\ProductKind;
use App\Module\Products\Domain\ProductRepository;
use App\Module\Products\Domain\ProductSearch;
use App\Shared\Domain\Page;
use App\Shared\Domain\PageRequest;
use App\Shared\Infrastructure\Doctrine\ListOrder;
use App\Shared\Infrastructure\Doctrine\SearchText;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\Pagination\Paginator;
use Symfony\Component\Uid\Uuid;

final readonly class DoctrineProductRepository implements ProductRepository
{
    /** The words of `:text`, found through idx_product_search: the index is built on this very SEARCH_TEXT expression. */
    public const string MATCHES_WORDS = "SEARCH_TEXT(p.reference, p.name, p.barcode) LIKE CONCAT('%', SEARCH_TEXT(:text), '%')";
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
        $words = trim($search->text ?? '');
        if (mb_strlen($words) >= SearchText::SHORTEST) {
            $query->andWhere(self::MATCHES_WORDS)->setParameter('text', SearchText::escapeLike($words));
        } elseif ('' !== $words) {
            $query->andWhere('LOWER(p.reference) = LOWER(:reference)')->setParameter('reference', $words);
        }
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

        return new Page($products, \count($paginator), $page);
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
        $words = trim($words);
        if (mb_strlen($words) >= SearchText::SHORTEST) {
            $query->andWhere(self::MATCHES_WORDS)->setParameter('text', SearchText::escapeLike($words));
        } elseif ('' !== $words) {
            $query->andWhere('LOWER(p.reference) = LOWER(:reference)')->setParameter('reference', $words);
        }

        /** @var list<Product> $products */
        $products = $query->orderBy('p.reference', 'ASC')->setMaxResults($limit)->getQuery()->getResult();

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

    public function ofBarcodeInCompany(string $barcode, Uuid $companyId): ?Product
    {
        return $this->entityManager->getRepository(Product::class)->findOneBy(['company' => $companyId, 'barcode' => $barcode]);
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
