<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Fiscal\Application\Regime\SyncCustomerTaxRegimes;
use App\Identity\Application\PasswordHasher;
use App\Identity\Domain\Email;
use App\Identity\Domain\User;
use App\Tenancy\Domain\Company;
use App\Tenancy\Domain\Membership;
use App\Tenancy\Domain\Role;
use Symfony\Component\HttpFoundation\Response;

/** The regimes a company's customers may be under: its preset's, read-only, labelled in the caller's language. */
final class CustomerTaxRegimesTest extends ApiTestCase
{
    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();
        static::getContainer()->get(SyncCustomerTaxRegimes::class)->handle();
        $this->company = $this->createCompany('Acme');
    }

    public function testTheCompanysPresetRegimesAreListedInOrder(): void
    {
        $this->signedIn($this->company, 'fr');

        $this->getJson($this->path($this->company));

        self::assertResponseIsSuccessful();
        $rows = $this->jsonList();
        self::assertSame(['standard', 'exempt', 'suspended', 'export'], array_column($rows, 'code'));
        self::assertSame(['vat'], $rows[3]['excludedFamilies']);
        self::assertTrue($rows[3]['hasMention']);
        self::assertFalse($rows[0]['hasMention']);
        self::assertSame('Exonéré de TVA', $rows[1]['label']);
    }

    public function testTheLabelsFollowTheCallersLanguage(): void
    {
        $this->signedIn($this->company, 'en');

        $this->getJson($this->path($this->company));

        self::assertResponseIsSuccessful();
        self::assertSame('VAT exempt', $this->jsonList()[1]['label']);
    }

    public function testAFrenchCompanySeesTheFrenchRegimes(): void
    {
        $dupont = new Company('Dupont', 'FR', 'EUR', 'fr', 'Europe/Paris');
        $this->em()->persist($dupont);
        $this->em()->flush();
        $this->signedIn($dupont, 'fr');

        $this->getJson($this->path($dupont));

        self::assertResponseIsSuccessful();
        self::assertSame(['standard', 'exempt', 'intra_eu', 'export'], array_column($this->jsonList(), 'code'));
    }

    public function testSomeoneWithoutTheFiscalPermissionSeesNothing(): void
    {
        $this->createUser('member@twes.local', 'password-1234', $this->company, ['company.read'], Role::MEMBER);
        $this->login('member@twes.local', 'password-1234');

        $this->getJson($this->path($this->company));

        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
    }

    public function testAnAnonymousCallerIsRefused(): void
    {
        $this->getJson($this->path($this->company));

        self::assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
    }

    public function testACompanyTheCallerHasNothingToDoWithLooksAbsent(): void
    {
        $globex = $this->createCompany('Globex');
        $this->signedIn($this->company, 'fr');

        $this->getJson($this->path($globex));

        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
    }

    private function signedIn(Company $company, string $locale): void
    {
        $em = $this->em();
        $user = new User(Email::fromString('accountant@twes.local'), 'Accountant', $locale);
        $user->setPasswordHash(static::getContainer()->get(PasswordHasher::class)->hash('password-1234'), new \DateTimeImmutable());
        $role = new Role('accountant', ['fiscal.read'], $company);
        $em->persist($user);
        $em->persist($role);
        $em->persist(new Membership($user, $company, $role));
        $em->flush();
        $this->login('accountant@twes.local', 'password-1234');
        self::assertResponseIsSuccessful();
    }

    private function path(Company $company): string
    {
        return '/api/companies/'.$company->getId()->toRfc4122().'/customer-tax-regimes';
    }
}
