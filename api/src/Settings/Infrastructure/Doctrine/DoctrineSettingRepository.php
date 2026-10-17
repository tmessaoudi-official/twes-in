<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Settings\Infrastructure\Doctrine;

use App\Settings\Domain\Model\Setting;
use App\Settings\Domain\Repository\SettingRepository;
use App\Settings\Domain\ValueObject\SettingAddress;
use App\Shared\Application\RecordChanged;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Query;

final readonly class DoctrineSettingRepository implements SettingRepository
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    public function at(array $addresses): array
    {
        if ([] === $addresses) {
            return [];
        }
        $query = $this->entityManager->createQueryBuilder()->select('s')->from(Setting::class, 's');
        $any = $query->expr()->orX();
        foreach ($addresses as $i => $address) {
            $any->add($query->expr()->andX("s.level = :level$i", "s.levelId = :levelId$i"));
            $query->setParameter("level$i", $address->level->value)->setParameter("levelId$i", $address->levelId);
        }

        /** @var list<Setting> $settings */
        $settings = $query->where($any)->getQuery()->getResult();

        return $settings;
    }

    public function find(SettingAddress $address, string $key): ?Setting
    {
        return $this->entityManager->getRepository(Setting::class)->findOneBy(['level' => $address->level, 'levelId' => $address->levelId, 'key' => $key]);
    }

    public function lockedFind(SettingAddress $address, string $key): ?Setting
    {
        $setting = $this->entityManager->createQueryBuilder()
            ->select('s')
            ->from(Setting::class, 's')
            ->where('s.level = :level')
            ->andWhere('s.levelId = :levelId')
            ->andWhere('s.key = :key')
            ->setParameter('level', $address->level)
            ->setParameter('levelId', $address->levelId)
            ->setParameter('key', $key)
            ->getQuery()
            // SELECT … FOR UPDATE, which Doctrine refuses outside a transaction; the refresh hint replaces whatever an
            // earlier read of the row in this request left in memory with what the lock just read.
            ->setLockMode(LockMode::PESSIMISTIC_WRITE)
            ->setHint(Query::HINT_REFRESH, true)
            ->getOneOrNullResult();

        return $setting instanceof Setting ? $setting : null;
    }

    public function save(Setting $setting): void
    {
        $this->entityManager->persist($setting);
        try {
            $this->entityManager->flush();
        } catch (UniqueConstraintViolationException $clash) {
            // A row that is not there yet cannot be held: two first values of one key meet here, and the later one
            // was written over a version it never read.
            throw new RecordChanged($clash);
        }
    }

    public function remove(Setting $setting): void
    {
        $this->entityManager->remove($setting);
        $this->entityManager->flush();
    }

    public function removeAt(SettingAddress $address): void
    {
        $this->entityManager->createQueryBuilder()
            ->delete(Setting::class, 's')
            ->where('s.level = :level')
            ->andWhere('s.levelId = :levelId')
            ->setParameter('level', $address->level->value)
            ->setParameter('levelId', $address->levelId)
            ->getQuery()
            ->execute();
    }
}
