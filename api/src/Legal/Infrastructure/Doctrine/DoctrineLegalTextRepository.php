<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Legal\Infrastructure\Doctrine;

use App\Legal\Domain\LegalLanguage;
use App\Legal\Domain\LegalPage;
use App\Legal\Domain\LegalText;
use App\Legal\Domain\LegalTextRepository;
use Doctrine\ORM\EntityManagerInterface;

final readonly class DoctrineLegalTextRepository implements LegalTextRepository
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    public function latest(LegalPage $page, LegalLanguage $language): ?LegalText
    {
        /** @var LegalText|null $text */
        $text = $this->entityManager->createQueryBuilder()
            ->select('t')
            ->from(LegalText::class, 't')
            ->where('t.page = :page')
            ->andWhere('t.language = :language')
            ->setParameter('page', $page)
            ->setParameter('language', $language)
            // A uuid v7 orders by time within the same instant, so two versions written in one second keep their order.
            ->orderBy('t.createdAt', \SortDirection::Descending)
            ->addOrderBy('t.id', \SortDirection::Descending)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        return $text;
    }

    public function add(LegalText $text): void
    {
        $this->entityManager->persist($text);
        $this->entityManager->flush();
    }
}
