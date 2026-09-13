<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Settings\Infrastructure\Doctrine;

use App\Settings\Domain\Setting;
use App\Settings\Domain\SettingAddress;
use App\Settings\Domain\SettingRepository;
use Doctrine\ORM\EntityManagerInterface;

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

    public function save(Setting $setting): void
    {
        $this->entityManager->persist($setting);
        $this->entityManager->flush();
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
