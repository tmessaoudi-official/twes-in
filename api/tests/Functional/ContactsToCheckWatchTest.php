<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Fiscal\Application\Company\ProvisionCompany;
use App\Settings\Application\PhoneSettings;
use App\Settings\Domain\Model\Setting;
use App\Settings\Domain\ValueObject\SettingAddress;
use App\Tenancy\Domain\Model\Company;
use Symfony\Component\HttpFoundation\Response;

/**
 * The company's own contact and each establishment's hold no mark of their own: what their pages say « À vérifier »
 * (a value today's rules refuse, kept from before them, or a phone the platform keeps outside its country's ranges) is
 * also a part of « À surveiller », so whoever may correct it learns of it without opening those pages.
 */
final class ContactsToCheckWatchTest extends ApiTestCase
{
    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();
        $this->company = $this->createCompany('Acme');
        static::getContainer()->get(ProvisionCompany::class)->handle($this->company);
        $this->em()->persist(new Setting(SettingAddress::platform(), PhoneSettings::OUTSIDE_RANGES, PhoneSettings::KEEP, self::heldNow()));
        $this->em()->flush();
    }

    public function testTheProfilesAndEachEstablishmentsContactToCheckAreWatchedUntilCorrected(): void
    {
        $this->createUser('admin@twes.local', 'password-1234', $this->company, ['company.read', 'company.settings'], 'admin');
        $this->createUser('reader@twes.local', 'password-1234', $this->company, ['company.read'], 'member');
        $this->login('admin@twes.local', 'password-1234');
        $watch = $this->companyPath().'/watch/company.contacts_to_check';

        $this->getJson($watch);
        self::assertResponseIsSuccessful();
        self::assertSame([], $this->jsonList(), 'nothing to check while every contact is one the rules take');

        $this->em()->getConnection()->executeStatement("UPDATE company SET email = 'contact@acme', phone = '+21680123456' WHERE id = ?", [$this->company->getId()->toRfc4122()]);
        $this->postJson($this->companyPath().'/establishments', ['code' => '001', 'name' => 'Agence de Sfax', 'phone' => '+216 80 123 457']);
        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);
        $establishment = $this->stringAt($this->json(), 'id');

        $this->getJson($watch);
        self::assertResponseIsSuccessful();
        self::assertSame([
            ['kind' => 'company.contacts_to_check', 'subjectId' => $this->company->getId()->toRfc4122(), 'params' => ['record' => 'profile', 'name' => 'Acme', 'contacts' => 'email,phone_outside_ranges']],
            ['kind' => 'company.contacts_to_check', 'subjectId' => $establishment, 'params' => ['record' => 'establishment', 'name' => 'Agence de Sfax', 'code' => '001', 'contacts' => 'phone_outside_ranges']],
        ], array_map(static fn (array $row): array => array_intersect_key($row, array_flip(['kind', 'subjectId', 'params'])), $this->jsonList()), 'the profile first, then each establishment by its code');
        $this->getJson($this->companyPath().'/watch');
        self::assertContains(['kind' => 'company.contacts_to_check', 'count' => 2], $this->arrayAt($this->json(), 'subjects'));

        $this->getJson($this->companyPath().'/establishments');
        $row = array_find($this->jsonList(), static fn (array $row): bool => $establishment === $row['id']) ?? self::fail('no establishment');
        $this->sendJson('PUT', $this->companyPath().'/establishments/'.$establishment, ['version' => $row['version'], 'code' => '001', 'name' => 'Agence de Sfax', 'phone' => '74 400 400']);
        self::assertResponseIsSuccessful();
        $this->getJson($watch);
        self::assertSame(['profile'], array_map(fn (array $row): mixed => $this->arrayAt($row, 'params')['record'] ?? null, $this->jsonList()), 'the establishment corrected leaves the part');

        $this->login('reader@twes.local', 'password-1234');
        $this->getJson($watch);
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND, 'the part is for whoever may correct the contacts');
    }

    private function companyPath(): string
    {
        return '/api/companies/'.$this->company->getId()->toRfc4122();
    }
}
