<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Vendors\Infrastructure\Doctrine;

use App\Module\Vendors\Domain\Vendor;
use App\Module\Vendors\Domain\VendorRepository;
use App\Module\Vendors\Domain\VendorSearch;
use App\Shared\Domain\Page;
use App\Shared\Domain\PageRequest;
use App\Shared\Infrastructure\Doctrine\ListOrder;
use App\Shared\Infrastructure\Doctrine\SearchText;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\Pagination\Paginator;
use Symfony\Component\Uid\Uuid;

final readonly class DoctrineVendorRepository implements VendorRepository
{
    /** The words of `:text`, found through idx_vendor_search: the index is built on this very SEARCH_TEXT expression. */
    public const string MATCHES_WORDS = "SEARCH_TEXT(v.number, v.name, v.legalName, v.email, v.address.line1, v.address.postalCode, v.address.city, JSON_VALUES(v.identifiers)) LIKE CONCAT('%', SEARCH_TEXT(:text), '%')";
    private const array SORTED_BY = ['number' => 'v.number', 'name' => 'v.name', 'city' => 'v.address.city', 'paymentTermsDays' => 'v.paymentTermsDays', 'isActive' => 'v.isActive'];

    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    public function ofCompany(Uuid $companyId): array
    {
        /** @var list<Vendor> $vendors */
        $vendors = $this->entityManager->getRepository(Vendor::class)->findBy(['company' => $companyId], ['number' => 'ASC']);

        return $vendors;
    }

    public function search(Uuid $companyId, VendorSearch $search, PageRequest $page): Page
    {
        $query = $this->entityManager->createQueryBuilder()
            ->select('v')->from(Vendor::class, 'v')
            ->where('v.company = :company')->setParameter('company', $companyId, 'uuid');
        $words = trim($search->text ?? '');
        if (mb_strlen($words) >= SearchText::SHORTEST) {
            $query->andWhere(self::MATCHES_WORDS)->setParameter('text', SearchText::escapeLike($words));
        } elseif ('' !== $words) {
            $query->andWhere('LOWER(v.number) = LOWER(:number)')->setParameter('number', $words);
        }
        if (null !== $search->active) {
            $query->andWhere('v.isActive = :active')->setParameter('active', $search->active);
        }
        ListOrder::apply($query, $search->order, self::SORTED_BY, ['city', 'paymentTermsDays'], 'v.number')
            ->setFirstResult($page->offset())->setMaxResults($page->size);

        $paginator = new Paginator($query, fetchJoinCollection: false);
        /** @var list<Vendor> $vendors */
        $vendors = iterator_to_array($paginator, false);

        return new Page($vendors, \count($paginator), $page);
    }

    public function pick(Uuid $companyId, string $words, int $limit): array
    {
        $query = $this->entityManager->createQueryBuilder()
            ->select('v')->from(Vendor::class, 'v')
            ->where('v.company = :company')->setParameter('company', $companyId, 'uuid')
            ->andWhere('v.isActive = true');
        $words = trim($words);
        if (mb_strlen($words) >= SearchText::SHORTEST) {
            $query->andWhere(self::MATCHES_WORDS)->setParameter('text', SearchText::escapeLike($words));
        } elseif ('' !== $words) {
            $query->andWhere('LOWER(v.number) = LOWER(:number)')->setParameter('number', $words);
        }

        /** @var list<Vendor> $vendors */
        $vendors = $query->orderBy('v.number', 'ASC')->setMaxResults($limit)->getQuery()->getResult();

        return $vendors;
    }

    public function ofIdsInCompany(array $ids, Uuid $companyId): array
    {
        return [] === $ids ? [] : $this->entityManager->getRepository(Vendor::class)->findBy(['id' => $ids, 'company' => $companyId]);
    }

    public function ofIdInCompany(Uuid $id, Uuid $companyId): ?Vendor
    {
        $vendor = $this->entityManager->find(Vendor::class, $id);

        return null !== $vendor && $vendor->getCompany()->getId()->equals($companyId) ? $vendor : null;
    }

    public function ofNumberInCompany(string $number, Uuid $companyId): ?Vendor
    {
        return $this->entityManager->getRepository(Vendor::class)->findOneBy(['company' => $companyId, 'number' => $number]);
    }

    public function save(Vendor $vendor): void
    {
        $this->entityManager->persist($vendor);
        $this->entityManager->flush();
    }
}
