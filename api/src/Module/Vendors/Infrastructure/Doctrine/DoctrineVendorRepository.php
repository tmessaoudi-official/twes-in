<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Vendors\Infrastructure\Doctrine;

use App\Module\Vendors\Domain\Model\Vendor;
use App\Module\Vendors\Domain\Repository\VendorRepository;
use App\Module\Vendors\Domain\ValueObject\VendorSearch;
use App\Shared\Domain\Service\TypedText;
use App\Shared\Domain\ValueObject\Page;
use App\Shared\Domain\ValueObject\PageRequest;
use App\Shared\Infrastructure\Doctrine\Intervals;
use App\Shared\Infrastructure\Doctrine\ListOrder;
use App\Shared\Infrastructure\Doctrine\SearchText;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\Pagination\Paginator;
use Symfony\Component\Uid\Uuid;

final readonly class DoctrineVendorRepository implements VendorRepository
{
    /** The words of `:text`, found through idx_vendor_search: the index is built on this very SEARCH_TEXT expression. */
    public const string MATCHES_WORDS = "SEARCH_TEXT(v.number, v.name, v.legalName, v.email, v.address.line1, v.address.postalCode, v.address.city, JSON_VALUES(v.identifiers)) LIKE CONCAT('%', SEARCH_TEXT(:text), '%')";
    /** « À vérifier », as `Vendor::getCheckMark()` and `checkReasons()` say it of each vendor. */
    private const string TO_CHECK = '(v.toCheck = true OR v.nameDoubtful = true)';
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
            ->where('v.company = :company')->setParameter('company', $companyId, 'uuid')
            // A vendor merged away is listed as the vendor kept, which is listed already.
            ->andWhere('v.mergedInto IS NULL');
        $words = TypedText::trimmed($search->text ?? '');
        if (mb_strlen($words) >= SearchText::SHORTEST) {
            $query->andWhere(self::MATCHES_WORDS)->setParameter('text', SearchText::escapeLike($words));
        } elseif ('' !== $words) {
            $query->andWhere('LOWER(v.number) = LOWER(:number)')->setParameter('number', $words);
        }
        if (null !== $search->active) {
            $query->andWhere('v.isActive = :active')->setParameter('active', $search->active);
        }
        if (null !== $search->termsAtLeast) {
            $query->andWhere('v.paymentTermsDays >= :termsMin')->setParameter('termsMin', $search->termsAtLeast, Types::INTEGER);
        }
        if (null !== $search->termsAtMost) {
            $query->andWhere('v.paymentTermsDays <= :termsMax')->setParameter('termsMax', $search->termsAtMost, Types::INTEGER);
        }
        Intervals::moments($query, 'v.createdAt', 'created', $search->createdOn, $search->timezone);
        if (null !== $search->toCheck) {
            $query->andWhere($search->toCheck ? self::TO_CHECK : 'NOT '.self::TO_CHECK);
        }
        ListOrder::apply($query, $search->order, self::SORTED_BY, ['city', 'paymentTermsDays'], 'v.number')
            ->setFirstResult($page->offset())->setMaxResults($page->size);

        $paginator = new Paginator($query, fetchJoinCollection: false)->setUseOutputWalkers(false);
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
        $words = TypedText::trimmed($words);
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

    public function mergedInto(array $ids, Uuid $companyId): array
    {
        /** @var list<Vendor> $vendors */
        $vendors = [] === $ids ? [] : $this->entityManager->getRepository(Vendor::class)->findBy(['mergedInto' => $ids, 'company' => $companyId]);

        return $vendors;
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
