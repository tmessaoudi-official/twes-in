<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Customers\Infrastructure\Doctrine;

use App\Module\Customers\Domain\Contact;
use App\Module\Customers\Domain\ContactRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

final readonly class DoctrineContactRepository implements ContactRepository
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    public function ofCustomer(Uuid $customerId): array
    {
        // Identifiers are UUID v7, so their order is the order the contacts were added in.
        return $this->entityManager->getRepository(Contact::class)->findBy(['customer' => $customerId], ['isPrimary' => 'DESC', 'id' => 'ASC']);
    }

    public function ofIdForCustomer(Uuid $id, Uuid $customerId): ?Contact
    {
        $contact = $this->entityManager->find(Contact::class, $id);

        return null !== $contact && $contact->getCustomer()->getId()->equals($customerId) ? $contact : null;
    }

    public function save(Contact $contact): void
    {
        $this->entityManager->persist($contact);
        $this->entityManager->flush();
    }

    public function remove(Contact $contact): void
    {
        $this->entityManager->remove($contact);
        $this->entityManager->flush();
    }
}
