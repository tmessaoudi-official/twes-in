<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Integration\Identity;

use App\Identity\Domain\Email;
use App\Identity\Domain\User;
use App\Identity\Domain\UserRepository;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

/** The adapter against the real PostgreSQL: the Email value object goes through the custom mapping type and back. */
final class DoctrineUserRepositoryTest extends KernelTestCase
{
    public function testAUserRoundTripsThroughTheDatabaseWithItsEmailValueObject(): void
    {
        self::bootKernel();
        $users = static::getContainer()->get(UserRepository::class);
        $user = new User(Email::fromString('Round@Trip.test'), 'Round');
        $users->save($user);
        static::getContainer()->get('doctrine.orm.entity_manager')->clear();

        $byEmail = $users->ofEmail(Email::fromString('round@trip.test'));
        self::assertNotNull($byEmail);
        self::assertTrue($byEmail->getId()->equals($user->getId()));
        self::assertInstanceOf(Email::class, $byEmail->getEmail());
        self::assertSame('round@trip.test', $byEmail->getEmail()->value);
        self::assertNotNull($users->ofId($user->getId()));
        self::assertNull($users->ofId(Uuid::v7()));
        self::assertNull($users->ofEmail(Email::fromString('nobody@trip.test')));
    }
}
