<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Integration\Tenancy;

use App\Identity\Domain\Email;
use App\Identity\Domain\User;
use App\Identity\Infrastructure\Security\SecurityUser;
use App\Module\Products\Domain\ProductCategory;
use App\Tenancy\Domain\Company;
use App\Tenancy\Domain\Membership;
use App\Tenancy\Domain\Role;
use App\Tenancy\Infrastructure\ApiPlatform\CompanyGuard;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\TerminateEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Uid\Uuid;

/**
 * docs/SPEC.md § 3 Tenancy, review S5: once a request acts for a company, the rows of every other company are out of
 * reach of any query, a repository that forgets its own company condition included. The explicit conditions stay the
 * first layer; this is the second.
 */
final class CompanyFilterTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private Uuid $mine;
    private Uuid $theirCategory;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $now = new \DateTimeImmutable();

        $mine = new Company('Mine', 'TN', 'TND', 'fr', 'Africa/Tunis');
        $theirs = new Company('Theirs', 'TN', 'TND', 'fr', 'Africa/Tunis');
        $ours = ProductCategory::create($mine, 'Ours', null, $now);
        $their = ProductCategory::create($theirs, 'Theirs only', null, $now);
        $user = new User(Email::fromString('member@twes.local'), 'Member');
        $role = new Role('tester', ['*'], $mine);
        foreach ([$mine, $theirs, $ours, $their, $user, $role, new Membership($user, $mine, $role)] as $entity) {
            $this->em->persist($entity);
        }
        $this->em->flush();
        $this->em->clear();

        $account = SecurityUser::of($user);
        static::getContainer()->get(TokenStorageInterface::class)->setToken(new UsernamePasswordToken($account, 'main', $account->getRoles()));
        $this->mine = $mine->getId();
        $this->theirCategory = $their->getId();
    }

    public function testNothingIsFilteredBeforeARequestActsForACompany(): void
    {
        self::assertSame(['Ours', 'Theirs only'], $this->categoryNames());
    }

    public function testActingForACompanyPutsEveryOtherCompanysRowsOutOfReach(): void
    {
        static::getContainer()->get(CompanyGuard::class)->companyForActing($this->mine, 'product.read');
        $this->em->clear();
        $categories = $this->em->getRepository(ProductCategory::class);

        self::assertSame(['Ours'], $this->categoryNames());
        self::assertNull($categories->find($this->theirCategory));
        self::assertNull($categories->findOneBy(['name' => 'Theirs only']));
        self::assertSame(1, (int) $this->em->createQuery('SELECT COUNT(c) FROM '.ProductCategory::class.' c')->getSingleScalarResult());
    }

    public function testTheEndOfTheRequestLiftsTheFilter(): void
    {
        static::getContainer()->get(CompanyGuard::class)->companyForActing($this->mine, 'product.read');
        self::assertSame(['Ours'], $this->categoryNames());

        static::getContainer()->get('event_dispatcher')->dispatch(new TerminateEvent(self::$kernel ?? self::fail('No kernel.'), Request::create('/'), new Response()), KernelEvents::TERMINATE);
        $this->em->clear();

        self::assertSame(['Ours', 'Theirs only'], $this->categoryNames());
    }

    /** @return list<string> */
    private function categoryNames(): array
    {
        $names = array_map(static fn (ProductCategory $category): string => $category->getName(), $this->em->getRepository(ProductCategory::class)->findAll());
        sort($names);

        return $names;
    }
}
