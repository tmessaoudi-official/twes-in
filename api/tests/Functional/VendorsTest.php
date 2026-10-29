<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Fiscal\Application\Company\ProvisionCompany;
use App\Module\Expenses\Domain\Model\ExpenseCategory;
use App\Module\Vendors\Domain\Model\Vendor;
use App\Module\Vendors\Domain\ValueObject\VendorProfile;
use App\ModuleRegistry\Domain\Model\ModuleState;
use App\Shared\Domain\Model\PostalAddress;
use App\Tenancy\Domain\Model\Company;
use Symfony\Component\HttpFoundation\Response;

final class VendorsTest extends ApiTestCase
{
    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();
        $this->company = $this->createCompany('Acme');
        static::getContainer()->get(ProvisionCompany::class)->handle($this->company);
    }

    public function testTheOptionsSayWhatTheVendorFormAsksFor(): void
    {
        $this->signedIn(['vendor.read']);

        $this->getJson($this->companyPath().'/vendor-options');

        self::assertResponseIsSuccessful();
        $options = $this->json();
        self::assertSame('TN', $options['countryCode']);
        $identifier = $this->arrayAt($options, 'identifiers')[0];
        self::assertIsArray($identifier);
        // Read part by part, the matricule is judged by the API alone, which takes every way of writing it.
        self::assertSame(['matricule_fiscal', null, "Exemple\u{00A0}: 1234567A/B/M/000. Il commence par ses 7 chiffres, même si la carte l’imprime dans l’autre sens."], [$identifier['key'], $identifier['pattern'], $identifier['hint']]);
    }

    public function testTheFormIsOfferedTheExpenseCategoriesWhileTheExpensesModuleIsOn(): void
    {
        $this->signedIn(['vendor.read', 'expense.read']);
        $company = $this->em()->find(Company::class, $this->company->getId());
        self::assertInstanceOf(Company::class, $company);
        $supplies = ExpenseCategory::create($company, 'Fournitures', null, new \DateTimeImmutable());
        $rent = ExpenseCategory::create($company, 'Loyer', null, new \DateTimeImmutable());
        $rent->revise('Loyer', null, false, new \DateTimeImmutable());
        $this->em()->persist($supplies);
        $this->em()->persist($rent);
        $this->em()->flush();

        $this->getJson($this->companyPath().'/vendor-options');
        self::assertResponseIsSuccessful();
        $offered = $this->arrayAt($this->json(), 'expenseCategories');
        self::assertSame([
            ['id' => $supplies->getId()->toRfc4122(), 'name' => 'Fournitures', 'active' => true],
            ['id' => $rent->getId()->toRfc4122(), 'name' => 'Loyer', 'active' => false],
        ], $offered, 'the archived one named, for the vendor that still holds it');

        // Whoever may not read the expense categories is not told their names here either.
        $this->createUser('reader@twes.local', 'password-1234', $this->em()->find(Company::class, $this->company->getId()), ['vendor.read'], 'reader');
        $this->login('reader@twes.local', 'password-1234');
        $this->getJson($this->companyPath().'/vendor-options');
        self::assertResponseIsSuccessful();
        self::assertNull($this->json()['expenseCategories'] ?? null, 'no category is offered without expense.read');
        $this->login('buyer@twes.local', 'password-1234');

        $company = $this->em()->find(Company::class, $this->company->getId());
        self::assertInstanceOf(Company::class, $company);
        $this->em()->persist(ModuleState::of($company, 'expenses', false, new \DateTimeImmutable()));
        $this->em()->flush();
        $this->getJson($this->companyPath().'/vendor-options');
        self::assertResponseIsSuccessful();
        self::assertNull($this->json()['expenseCategories'] ?? null, 'no category is offered while the module is off');
    }

    public function testAWriterAddsAVendorWithoutRegistrationNumbers(): void
    {
        $this->signedIn(['vendor.read', 'vendor.write']);

        $this->postJson($this->path(), $this->vendor());

        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);
        $created = $this->json();
        self::assertSame('FRN-0001', $created['number']);
        self::assertEquals([], $created['identifiers'], 'a domestic supplier need not carry the preset’s numbers');
        self::assertSame('TN', $created['countryCode'], 'an address without a country is in the company’s');
        self::assertSame('TN5910006035183598478831', $created['iban']);
        self::assertSame('STBKTNTT', $created['bic']);
        self::assertSame(30, $created['paymentTermsDays']);
        self::assertTrue($created['isActive']);

        $this->getJson($this->path($this->stringAt($created, 'id')));
        self::assertResponseIsSuccessful();
        self::assertSame('Sotumag', $this->json()['name']);
        $this->getJson($this->path());
        self::assertCount(1, $this->jsonList());
        self::assertSame('[]', $this->em()->getConnection()->fetchOne("SELECT changes::text FROM audit_log WHERE action = 'vendor.created'"));
    }

    public function testWhatTheShapeOrThePresetRefusesAnswersUnprocessableNamingTheField(): void
    {
        $this->signedIn(['vendor.read', 'vendor.write']);

        foreach ([
            'number' => ['number' => '-FRN'],
            'name' => ['name' => ''],
            'identifiers.matricule_fiscal' => ['identifiers' => ['matricule_fiscal' => '123']],
            'identifiers.siret' => ['identifiers' => ['siret' => '12345678900011']],
            'iban' => ['iban' => 'TN00 1234'],
            'bic' => ['bic' => 'NOPE'],
            'paymentTermsDays' => ['paymentTermsDays' => 400],
            'countryCode' => ['countryCode' => 'XX'],
            'email' => ['email' => 'not-an-email'],
        ] as $field => $change) {
            $this->postJson($this->path(), $this->vendor($change));
            self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY, $field);
            self::assertStringContainsString($field, (string) $this->client->getResponse()->getContent(), $field);
        }
        $this->getJson($this->path());
        self::assertCount(0, $this->jsonList());
    }

    /** The form's rules and the domain's answer one shape, under the reasons a vendor file is refused with. */
    /**
     * A vendor's phone is read by its own country, kept as E.164, refused with that country's example, and « À vérifier »
     * outside the numbers handed out; a stored value is judged only once it changes.
     */
    public function testAVendorsPhoneIsReadByItsOwnCountry(): void
    {
        $this->signedIn(['vendor.read', 'vendor.write']);

        $this->postJson($this->path(), $this->vendor(['countryCode' => 'FR', 'city' => 'Lyon', 'iban' => null, 'bic' => null, 'phone' => '01 23 45 67 89']));
        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);
        self::assertSame('+33123456789', $this->json()['phone']);
        $id = $this->stringAt($this->json(), 'id');

        $this->sendJson('PUT', $this->path($id), [...$this->vendor(['countryCode' => 'FR', 'city' => 'Lyon', 'iban' => null, 'bic' => null, 'phone' => '01 23 45']), 'version' => 1]);
        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        self::assertSame([['propertyPath' => 'phone', 'reason' => 'phone_length', 'params' => ['example' => '+33 1 23 45 67 89', 'callingCode' => '+33']]], $this->refusals());

        $this->em()->getConnection()->executeStatement("UPDATE vendor SET phone = '01 23 45' WHERE id = ?", [$id]);
        $this->sendJson('PUT', $this->path($id), [...$this->vendor(['countryCode' => 'FR', 'city' => 'Lyon', 'iban' => null, 'bic' => null, 'phone' => '01 23 45', 'name' => 'Sotumag SA']), 'version' => 1]);
        self::assertResponseIsSuccessful();
        self::assertSame(['Sotumag SA', '01 23 45'], [$this->json()['name'], $this->json()['phone']]);

        $this->sendJson('PUT', $this->path($id), [...$this->vendor(['countryCode' => 'TN', 'iban' => null, 'bic' => null, 'phone' => '11 111 111']), 'version' => 2]);
        self::assertResponseIsSuccessful();
        self::assertSame(['+21611111111', true], [$this->json()['phone'], $this->json()['toCheck']]);
    }

    /** An address kept before today's rule is refused only once it changes (row 208), never for another field's save. */
    public function testAnAddressKeptBeforeTheRuleIsJudgedOnlyOnceItChanges(): void
    {
        $this->signedIn(['vendor.read', 'vendor.write']);
        $this->postJson($this->path(), $this->vendor(['email' => 'ventes@sotumag.tn']));
        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);
        $id = $this->stringAt($this->json(), 'id');
        $this->em()->getConnection()->executeStatement("UPDATE vendor SET email = 'ventes@sotumag' WHERE id = ?", [$id]);

        $this->sendJson('PUT', $this->path($id), [...$this->vendor(['name' => 'Sotumag SA', 'email' => 'ventes@sotumag']), 'version' => 1]);
        self::assertResponseIsSuccessful();
        self::assertSame(['Sotumag SA', 'ventes@sotumag'], [$this->json()['name'], $this->json()['email']]);

        $this->sendJson('PUT', $this->path($id), [...$this->vendor(['email' => 'ventes@sotuma']), 'version' => 2]);
        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        self::assertSame('invalid_email', $this->refusals()[0]['reason'] ?? null);
    }

    public function testARefusalNamesItsFieldAndAReasonTheScreenTranslates(): void
    {
        $this->signedIn(['vendor.read', 'vendor.write']);

        foreach ([
            'number' => [['number' => '-FRN'], 'invalid_number', []],
            'name' => [['name' => ''], 'value_required', []],
            'email' => [['email' => 'nobody@nowhere'], 'invalid_email', []],
            'phone' => [['phone' => 'abc'], 'invalid_phone', []],
            'website' => [['website' => 'https://exemple'], 'invalid_website', []],
            'legalName' => [['legalName' => str_repeat('a', 201)], 'invalid_legal_name', []],
            'notes' => [['notes' => str_repeat('a', 5001)], 'invalid_text', ['max' => 5000]],
            'addressLine1' => [['addressLine1' => str_repeat('a', 201)], 'invalid_text', ['max' => 200]],
            'countryCode' => [['countryCode' => 'XX'], 'invalid_country', []],
            'iban' => [['iban' => 'TN00 1234'], 'invalid_iban', []],
            'bic' => [['bic' => 'NOPE'], 'invalid_bic', []],
            'paymentTermsDays' => [['paymentTermsDays' => 400], 'invalid_payment_terms', ['max' => 365]],
            'identifiers.matricule_fiscal' => [['identifiers' => ['matricule_fiscal' => '123']], 'identifier_part', ['identifier' => 'matricule_fiscal', 'example' => '1234567A/B/M/000', 'part' => 'number']],
        ] as $field => [$change, $reason, $params]) {
            $this->postJson($this->path(), $this->vendor($change));

            self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY, $field);
            self::assertSame([['propertyPath' => $field, 'reason' => $reason, 'params' => $params]], $this->refusals(), $field);
        }
    }

    public function testANumberAnotherVendorHasAnswersConflict(): void
    {
        $this->signedIn(['vendor.read', 'vendor.write']);
        $this->postJson($this->path(), $this->vendor());

        $this->postJson($this->path(), $this->vendor(['name' => 'Autre']));

        self::assertResponseStatusCodeSame(Response::HTTP_CONFLICT);
    }

    public function testARevisionIsAuditedWithTheNamesOfTheFieldsItChanged(): void
    {
        $this->signedIn(['vendor.read', 'vendor.write']);
        $this->postJson($this->path(), $this->vendor());
        $id = $this->stringAt($this->json(), 'id');

        $this->sendJson('PUT', $this->path($id), $this->vendor(['version' => 1, 'email' => 'compta@sotumag.tn', 'city' => 'Sfax', 'isActive' => false]));

        self::assertResponseIsSuccessful();
        self::assertFalse($this->json()['isActive']);
        self::assertSame('Sfax', $this->json()['city']);
        $changes = $this->em()->getConnection()->fetchOne("SELECT changes::text FROM audit_log WHERE action = 'vendor.revised'");
        self::assertIsString($changes);
        self::assertSame(['fields' => ['email', 'address', 'isActive']], json_decode($changes, true));
    }

    public function testARevisionOfAVersionSavedSinceIsRefusedAsChangedAndWhatWasSavedStays(): void
    {
        $this->signedIn(['vendor.read', 'vendor.write']);
        $this->postJson($this->path(), $this->vendor());
        $id = $this->stringAt($this->json(), 'id');
        self::assertSame(1, $this->json()['version']);

        $this->sendJson('PUT', $this->path($id), $this->vendor(['version' => 1, 'city' => 'Sfax']));
        self::assertResponseIsSuccessful();
        self::assertSame(2, $this->json()['version']);

        $this->sendJson('PUT', $this->path($id), $this->vendor(['version' => 1, 'city' => 'Sousse']));

        self::assertResponseStatusCodeSame(Response::HTTP_CONFLICT);
        self::assertSame('/problems/record-changed', $this->json()['type']);
        $this->getJson($this->path($id));
        self::assertSame(['Sfax', 2], [$this->json()['city'], $this->json()['version']]);
    }

    public function testARevisionThatChangesNothingKeepsItsVersion(): void
    {
        $this->signedIn(['vendor.read', 'vendor.write']);
        $this->postJson($this->path(), $this->vendor());
        $id = $this->stringAt($this->json(), 'id');

        $this->sendJson('PUT', $this->path($id), $this->vendor(['version' => 1]));

        self::assertResponseIsSuccessful();
        self::assertSame(1, $this->json()['version']);
    }

    public function testARevisionSaysWhichVersionItRevises(): void
    {
        $this->signedIn(['vendor.read', 'vendor.write']);
        $this->postJson($this->path(), $this->vendor());
        $id = $this->stringAt($this->json(), 'id');

        $this->sendJson('PUT', $this->path($id), $this->vendor(['city' => 'Sfax']));

        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        self::assertSame(['version'], array_column($this->arrayAt($this->json(), 'violations'), 'propertyPath'));
    }

    public function testAReaderOnlyReadsAndSomeoneSignedOutReadsNothing(): void
    {
        $this->createUser('reader@twes.local', 'password-1234', $this->company, ['vendor.read'], 'reader');
        $this->signedIn(['vendor.read', 'vendor.write']);
        $this->postJson($this->path(), $this->vendor());
        $mine = $this->stringAt($this->json(), 'id');

        $this->sendJson('POST', '/api/auth/logout');
        $this->getJson($this->path());
        self::assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);

        $this->login('reader@twes.local', 'password-1234');
        $this->getJson($this->path($mine));
        self::assertResponseIsSuccessful();
        $this->sendJson('PUT', $this->path($mine), $this->vendor(['version' => 1]));
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
        $this->postJson($this->path(), $this->vendor(['number' => 'FRN-0002']));
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
    }

    public function testAnotherCompanysVendorIsNotFoundThroughThisCompany(): void
    {
        $globex = $this->createCompany('Globex');
        $theirs = Vendor::create($globex, 'FRN-0001', new VendorProfile('Globex Supply'), new \DateTimeImmutable());
        $this->em()->persist($theirs);
        $this->em()->flush();
        $this->signedIn(['vendor.read', 'vendor.write']);

        $this->getJson($this->path($theirs->getId()->toRfc4122()));
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
        $this->sendJson('PUT', $this->path($theirs->getId()->toRfc4122()), $this->vendor(['version' => 1]));
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
        $this->getJson('/api/companies/'.$globex->getId()->toRfc4122().'/vendors');
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
    }

    public function testTheListIsAPageSearchedNarrowedAndSortedByTheApi(): void
    {
        $now = new \DateTimeImmutable();
        foreach (range(1, 27) as $n) {
            $this->em()->persist(Vendor::create($this->company, \sprintf('FRN-%04d', $n), new VendorProfile('Fournisseur '.$n), $now));
        }
        $this->em()->persist(Vendor::create($this->company, 'FRN-0100', new VendorProfile('Société Générale d’Emballage', identifiers: ['matricule_fiscal' => '7654321B/A/M/000'], email: 'achats@sge.tn', address: new PostalAddress('4, rue de Marseille', null, '1000', 'Tunis'), paymentTermsDays: 60), $now));
        $retired = Vendor::create($this->company, 'FRN-0101', new VendorProfile('Zitouna Bureautique', paymentTermsDays: 15), $now);
        $retired->revise('FRN-0101', new VendorProfile('Zitouna Bureautique', paymentTermsDays: 15), false, $now);
        $this->em()->persist($retired);
        $this->em()->flush();
        $this->signedIn(['vendor.read']);

        $this->getJson($this->path());
        self::assertCount(25, $this->jsonList());
        self::assertSame(29, $this->jsonPage()['totalItems']);

        foreach ([
            'q=generale' => ['FRN-0100'],
            'q=marseille' => ['FRN-0100'],
            'q=7654321' => ['FRN-0100'],
            'q=sge.tn' => ['FRN-0100'],
            'q=fr' => [],
            'isActive=false' => ['FRN-0101'],
            'order[paymentTermsDays]=desc&itemsPerPage=2' => ['FRN-0100', 'FRN-0101'],
            'order[name]=desc&itemsPerPage=1' => ['FRN-0101'],
            'order[city]=asc&itemsPerPage=1' => ['FRN-0100'],
        ] as $query => $numbers) {
            $this->getJson($this->path().'?'.$query);
            self::assertResponseIsSuccessful($query);
            self::assertSame($numbers, array_column($this->jsonList(), 'number'), $query);
        }
    }

    public function testEveryFilterOfTheListCombines(): void
    {
        // The company keeps Tunis time, an hour ahead of the stored UTC: 23:30 UTC on the 14th is already the 15th there.
        foreach ([
            ['V1', 30, true, '2026-03-10 10:00'],
            ['V2', 60, true, '2026-03-14 23:30'],
            ['V3', null, true, '2026-03-15 14:00'],
            ['V4', 0, false, '2026-03-20 09:00'],
        ] as [$number, $terms, $active, $at]) {
            $at = new \DateTimeImmutable($at, new \DateTimeZone('UTC'));
            $profile = new VendorProfile('Fournisseur '.$number, paymentTermsDays: $terms);
            $vendor = Vendor::create($this->company, $number, $profile, $at);
            $vendor->revise($number, $profile, $active, $at);
            $this->em()->persist($vendor);
        }
        $this->em()->flush();
        $this->signedIn(['vendor.read']);

        foreach ([
            // A vendor with no terms is left out by either end.
            'paymentTermsDays[min]=30' => ['V1', 'V2'],
            'paymentTermsDays[max]=30' => ['V1', 'V4'],
            'paymentTermsDays[min]=0&paymentTermsDays[max]=0' => ['V4'],
            'createdAt[from]=2026-03-15' => ['V2', 'V3', 'V4'],
            'createdAt[from]=2026-03-15&createdAt[to]=2026-03-15' => ['V2', 'V3'],
            'createdAt[to]=2026-03-14&paymentTermsDays[min]=1' => ['V1'],
            'isActive=true&paymentTermsDays[max]=45' => ['V1'],
        ] as $query => $numbers) {
            $this->getJson($this->path().'?'.$query);
            self::assertResponseIsSuccessful($query);
            self::assertSame($numbers, array_column($this->jsonList(), 'number'), $query);
            self::assertSame(\count($numbers), $this->jsonPage()['totalItems'], $query);
        }

        foreach (['paymentTermsDays[min]=7.5', 'paymentTermsDays[min]=thirty', 'paymentTermsDays[min]=60&paymentTermsDays[max]=30', 'createdAt[from]=2026-02-30'] as $refused) {
            $this->getJson($this->path().'?'.$refused);
            self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY, $refused);
        }
    }

    /**
     * @param array<string, mixed> $changes
     *
     * @return array<string, mixed>
     */
    private function vendor(array $changes = []): array
    {
        return [...[
            'number' => 'FRN-0001',
            'name' => 'Sotumag',
            'identifiers' => [],
            'addressLine1' => 'Zone industrielle',
            'city' => 'Ben Arous',
            'iban' => 'tn59 1000 6035 1835 9847 8831',
            'bic' => 'stbktntt',
            'paymentTermsDays' => 30,
            'isActive' => true,
        ], ...$changes];
    }

    /** @param list<string> $permissions */
    private function signedIn(array $permissions): void
    {
        $this->createUser('buyer@twes.local', 'password-1234', $this->company, $permissions, 'member');
        $this->login('buyer@twes.local', 'password-1234');
        self::assertResponseIsSuccessful();
        // A vendor's bank details are on the step-up list; VendorBankDetailsStepUpTest is about that.
        $this->stepUp('password-1234');
    }

    private function companyPath(): string
    {
        return '/api/companies/'.$this->company->getId()->toRfc4122();
    }

    private function path(?string $vendorId = null): string
    {
        return $this->companyPath().'/vendors'.(null === $vendorId ? '' : '/'.$vendorId);
    }
}
