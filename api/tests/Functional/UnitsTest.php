<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Fiscal\Application\Company\ProvisionCompany;
use App\Tenancy\Domain\Model\Company;
use Symfony\Component\HttpFoundation\Response;

/** A company's units: listed by anyone who may read the fiscal setup, added and revised by whoever may write it. */
final class UnitsTest extends ApiTestCase
{
    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();
        $this->company = $this->createCompany('Acme');
        static::getContainer()->get(ProvisionCompany::class)->handle($this->company);
    }

    public function testAReaderListsThePresetsUnitsInTheCompanysLanguage(): void
    {
        $this->signedIn(['fiscal.read']);

        $this->getJson($this->path());

        self::assertResponseIsSuccessful();
        $rows = $this->jsonList();
        self::assertSame(['C62', 'H87', 'HUR', 'DAY', 'KGM', 'MTR', 'MTK', 'LTR'], array_column($rows, 'code'));
        self::assertSame(3, $rows[4]['decimals']);
    }

    public function testAWriterAddsAndRevisesAUnit(): void
    {
        $this->signedIn(['fiscal.read', 'fiscal.write']);

        $this->postJson($this->path(), ['code' => 'TNE', 'name' => 'Tonne', 'decimals' => 3, 'sortOrder' => 90]);
        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);
        $id = $this->stringAt($this->json(), 'id');

        self::assertSame(1, $this->json()['version']);

        $this->sendJson('PUT', $this->path().'/'.$id, ['version' => 1, 'code' => 'XXX', 'name' => 'Tonne métrique', 'decimals' => 2, 'isActive' => false, 'sortOrder' => 90]);

        self::assertResponseIsSuccessful();
        $body = $this->json();
        self::assertSame('TNE', $body['code']);
        self::assertSame('Tonne métrique', $body['name']);
        self::assertSame(2, $body['decimals']);
        self::assertFalse($body['isActive']);
        self::assertSame(2, $body['version']);
    }

    public function testARevisionOfAVersionSavedSinceIsRefusedAsChangedAndWhatWasSavedStays(): void
    {
        $this->signedIn(['fiscal.read', 'fiscal.write']);
        $this->postJson($this->path(), ['code' => 'TNE', 'name' => 'Tonne', 'decimals' => 3, 'sortOrder' => 90]);
        $id = $this->stringAt($this->json(), 'id');
        $this->sendJson('PUT', $this->path().'/'.$id, ['version' => 1, 'name' => 'Tonne métrique', 'decimals' => 3, 'isActive' => true, 'sortOrder' => 90]);
        self::assertResponseIsSuccessful();

        $this->sendJson('PUT', $this->path().'/'.$id, ['version' => 1, 'name' => 'Tonne courte', 'decimals' => 3, 'isActive' => true, 'sortOrder' => 90]);

        self::assertResponseStatusCodeSame(Response::HTTP_CONFLICT);
        self::assertSame('/problems/record-changed', $this->json()['type']);
        $this->getJson($this->path());
        $saved = array_values(array_filter($this->jsonList(), static fn (array $row): bool => $id === $row['id']))[0];
        self::assertSame(['Tonne métrique', 2], [$saved['name'], $saved['version']]);
    }

    public function testARevisionThatChangesNothingKeepsItsVersion(): void
    {
        $this->signedIn(['fiscal.read', 'fiscal.write']);
        $this->postJson($this->path(), ['code' => 'TNE', 'name' => 'Tonne', 'decimals' => 3, 'sortOrder' => 90]);
        $id = $this->stringAt($this->json(), 'id');

        $this->sendJson('PUT', $this->path().'/'.$id, ['version' => 1, 'name' => 'Tonne', 'decimals' => 3, 'isActive' => true, 'sortOrder' => 90]);

        self::assertResponseIsSuccessful();
        self::assertSame(1, $this->json()['version']);
    }

    public function testARevisionSaysWhichVersionItRevises(): void
    {
        $this->signedIn(['fiscal.read', 'fiscal.write']);
        $this->postJson($this->path(), ['code' => 'TNE', 'name' => 'Tonne', 'decimals' => 3, 'sortOrder' => 90]);
        $id = $this->stringAt($this->json(), 'id');

        $this->sendJson('PUT', $this->path().'/'.$id, ['name' => 'Tonne métrique', 'decimals' => 3, 'isActive' => true, 'sortOrder' => 90]);

        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        self::assertSame(['version'], array_column($this->arrayAt($this->json(), 'violations'), 'propertyPath'));
    }

    public function testADuplicateCodeIsAConflict(): void
    {
        $this->signedIn(['fiscal.read', 'fiscal.write']);

        $this->postJson($this->path(), ['code' => 'KGM', 'name' => 'Kilo', 'decimals' => 3]);

        self::assertResponseStatusCodeSame(Response::HTTP_CONFLICT);
    }

    public function testAMalformedUnitIsUnprocessable(): void
    {
        $this->signedIn(['fiscal.read', 'fiscal.write']);

        $this->postJson($this->path(), ['code' => 'kilo', 'name' => 'Kilo', 'decimals' => 7]);

        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
    }

    public function testAReaderMayNotWrite(): void
    {
        $this->signedIn(['fiscal.read']);

        $this->postJson($this->path(), ['code' => 'TNE', 'name' => 'Tonne', 'decimals' => 3]);

        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
    }

    public function testSomeoneWithoutTheFiscalPermissionSeesNothing(): void
    {
        $this->signedIn(['company.read']);

        $this->getJson($this->path());

        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
    }

    public function testAnAnonymousCallerIsRefused(): void
    {
        $this->getJson($this->path());

        self::assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
    }

    /** @param list<string> $permissions */
    private function signedIn(array $permissions): void
    {
        $this->createUser('accountant@twes.local', 'password-1234', $this->company, $permissions, 'accountant');
        $this->login('accountant@twes.local', 'password-1234');
        self::assertResponseIsSuccessful();
    }

    private function path(): string
    {
        return '/api/companies/'.$this->company->getId()->toRfc4122().'/units';
    }
}
