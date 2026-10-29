<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Fiscal\Application\Company\ProvisionCompany;
use App\Fiscal\Application\Regime\SyncCustomerTaxRegimes;
use App\Fiscal\Domain\Model\CustomerTaxRegime;
use App\Fiscal\Domain\Repository\CustomerTaxRegimeRepository;
use App\Fiscal\Domain\Repository\TaxComponentRepository;
use App\Module\Customers\Domain\Model\Customer;
use App\Module\Customers\Domain\Model\CustomerGroup;
use App\Module\Customers\Domain\ValueObject\CustomerKind;
use App\Module\Customers\Domain\ValueObject\CustomerProfile;
use App\Shared\Application\RecordChanged;
use App\Shared\Application\RecordVersions;
use App\Shared\Application\Transactions;
use App\Shared\Domain\Model\PostalAddress;
use App\Tenancy\Domain\Model\Company;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Uid\Uuid;

final class CustomersTest extends ApiTestCase
{
    private const string MATRICULE = '1234567A/B/M/000';

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();
        static::getContainer()->get(SyncCustomerTaxRegimes::class)->handle();
        $this->company = $this->createCompany('Acme');
        static::getContainer()->get(ProvisionCompany::class)->handle($this->company);
    }

    public function testTheOptionsSayWhatTheCustomerFormAsksFor(): void
    {
        $this->signedIn(['customer.read']);

        $this->getJson($this->companyPath().'/customer-options');

        self::assertResponseIsSuccessful();
        $options = $this->json();
        self::assertSame('TN', $options['countryCode']);
        $identifier = $this->arrayAt($options, 'identifiers')[0];
        self::assertIsArray($identifier);
        self::assertSame(['matricule_fiscal', null, true, "Exemple\u{00A0}: 1234567A/B/M/000. Il commence par ses 7 chiffres, même si la carte l’imprime dans l’autre sens."], [$identifier['key'], $identifier['pattern'], $identifier['requiredForBusiness'], $identifier['hint']]);
        self::assertSame(['standard', 'exempt', 'suspended', 'export'], array_column($this->arrayAt($options, 'regimes'), 'code'));
        self::assertContains('TVA19', array_column($this->arrayAt($options, 'taxes'), 'code'));
    }

    public function testAWriterAddsATunisianBusinessCustomer(): void
    {
        $this->signedIn(['customer.read', 'customer.write']);

        $this->postJson($this->path(), $this->customer(['defaultTaxComponentIds' => [$this->taxId('TVA19')], 'defaultDiscountRate' => '5']));

        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);
        $created = $this->json();
        self::assertSame('CLI-0001', $created['number']);
        self::assertSame(['matricule_fiscal' => self::MATRICULE], $created['identifiers']);
        self::assertSame('TN', $created['billingCountryCode'], 'an address without a country is in the company’s');
        self::assertSame('5.000', $created['defaultDiscountRate']);
        self::assertSame([$this->taxId('TVA19')], $created['defaultTaxComponentIds']);
        self::assertTrue($created['isActive']);

        $this->getJson($this->path($this->stringAt($created, 'id')));
        self::assertResponseIsSuccessful();
        self::assertSame('Carthage Conseil', $this->json()['name']);
        $this->getJson($this->path());
        self::assertCount(1, $this->jsonList());
        self::assertSame('[]', $this->em()->getConnection()->fetchOne("SELECT changes::text FROM audit_log WHERE action = 'customer.created'"));
    }

    public function testWhatThePresetOrTheCompanyRefusesAnswersUnprocessableNamingTheField(): void
    {
        $this->signedIn(['customer.read', 'customer.write']);

        foreach ([
            'identifiers.matricule_fiscal' => ['identifiers' => []],
            'taxRegime' => ['taxRegime' => 'franchise'],
            'kind' => ['kind' => 'robot'],
            'billingCountryCode' => ['billingCountryCode' => 'XX'],
            'defaultTaxComponentIds' => ['taxRegime' => 'exempt', 'defaultTaxComponentIds' => [$this->taxId('TVA19')]],
            'customerGroupId' => ['customerGroupId' => '0192c3a4-0000-7000-8000-000000000000'],
        ] as $field => $change) {
            $this->postJson($this->path(), $this->customer($change));
            self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY, $field);
            self::assertStringContainsString($field, (string) $this->client->getResponse()->getContent());
        }

        $this->postJson($this->path(), $this->customer(['defaultTaxComponentIds' => ['first' => $this->taxId('TVA19')]]));
        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY, 'the default taxes are a list, never a map');
        self::assertStringContainsString('defaultTaxComponentIds', (string) $this->client->getResponse()->getContent());
    }

    public function testAMatriculeWrittenAnyWayIsStoredTheOneWayAndOneWrittenBackToFrontIsSaidSo(): void
    {
        $this->signedIn(['customer.read', 'customer.write']);

        $this->postJson($this->path(), $this->customer(['identifiers' => ['matricule_fiscal' => "1234567 a\u{200B}/a/m 000"]]));
        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);
        $id = $this->json()['id'];
        self::assertIsString($id);
        self::assertSame(['matricule_fiscal' => '1234567A/A/M/000'], $this->json()['identifiers']);

        $this->sendJson('PUT', $this->path($id), [...$this->customer(['identifiers' => ['matricule_fiscal' => '1234567/b/m/000']]), 'version' => $this->json()['version']]);
        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        self::assertSame([['propertyPath' => 'identifiers.matricule_fiscal', 'reason' => 'identifier_part', 'params' => ['identifier' => 'matricule_fiscal', 'example' => '1234567A/B/M/000', 'part' => 'key']]], $this->refusals());

        $this->postJson($this->path(), $this->customer(['number' => 'CLI-0002', 'identifiers' => ['matricule_fiscal' => '000/M/A/A1234567']]));
        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        self::assertSame('identifier_reversed', $this->refusals()[0]['reason'] ?? null);
    }

    /**
     * A form's rules and the domain's refuse in one shape: each violation names its property and carries the reason a
     * screen translates, the import's own codes, with what that text needs (a length's maximum).
     */
    public function testARefusalNamesItsFieldAndAReasonTheScreenTranslates(): void
    {
        $this->signedIn(['customer.read', 'customer.write']);

        foreach ([
            'email' => [['email' => 'nobody@nowhere'], 'invalid_email', []],
            'a long email' => [['email' => str_repeat('a', 250).'@b.tn'], 'invalid_email', []],
            'phone' => [['phone' => 'abc'], 'invalid_phone', []],
            'website' => [['website' => 'javascript:alert(1)'], 'invalid_website', []],
            'legalName' => [['legalName' => str_repeat('a', 201)], 'invalid_legal_name', []],
            'shippingCountryCode' => [['shippingCity' => 'Paris', 'shippingCountryCode' => 'FX'], 'invalid_country', []],
            'notes' => [['notes' => str_repeat('a', 5001)], 'invalid_text', ['max' => 5000]],
            'billingAddressLine1' => [['billingAddressLine1' => str_repeat('a', 201)], 'invalid_text', ['max' => 200]],
            'billingCountryCode' => [['billingCountryCode' => 'XX'], 'invalid_country', []],
            'identifiers.matricule_fiscal' => [['identifiers' => ['matricule_fiscal' => '123']], 'identifier_part', ['identifier' => 'matricule_fiscal', 'example' => '1234567A/B/M/000', 'part' => 'number']],
            'defaultTaxComponentIds' => [['taxRegime' => 'exempt', 'defaultTaxComponentIds' => [$this->taxId('TVA19')]], 'regime_excludes_tax', ['tax' => 'TVA 19 %']],
        ] as $case => [$change, $reason, $params]) {
            $field = 'a long email' === $case ? 'email' : $case;
            $this->postJson($this->path(), $this->customer($change));

            self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY, $case);
            self::assertSame([['propertyPath' => $field, 'reason' => $reason, 'params' => $params]], $this->refusals(), $case);
        }
    }

    /** The domain stops at a profile's first fault; the form holds the same rules and names every fault in one answer. */
    public function testAFormIsToldEveryFaultInOneAnswer(): void
    {
        $this->signedIn(['customer.read', 'customer.write']);

        $this->postJson($this->path(), $this->customer(['email' => 'nobody@nowhere', 'phone' => 'abc', 'website' => 'exemple.tn', 'billingCountryCode' => 'XX']));

        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        self::assertSame([
            ['propertyPath' => 'email', 'reason' => 'invalid_email', 'params' => []],
            ['propertyPath' => 'phone', 'reason' => 'invalid_phone', 'params' => []],
            ['propertyPath' => 'website', 'reason' => 'invalid_website', 'params' => []],
            ['propertyPath' => 'billingCountryCode', 'reason' => 'invalid_country', 'params' => []],
        ], $this->refusals());
    }

    /**
     * A phone is read by its customer's country, the company's when the address names none, kept as E.164 whatever way
     * it was written, refused with its country's example when its length is wrong, and « À vérifier » when it has the
     * length but lies outside the numbers handed out. A stored value is judged only once it changes (row 208).
     */
    public function testAPhoneIsReadByItsCountryAndKeptInOneWay(): void
    {
        $this->signedIn(['customer.read', 'customer.write']);

        $this->postJson($this->path(), $this->customer(['phone' => "71\u{00A0}900 868"]));
        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);
        $id = $this->stringAt($this->json(), 'id');
        self::assertSame(['+21671900868', false], [$this->json()['phone'], $this->json()['toCheck']], 'a national number read in the company\'s country');

        $this->sendJson('PUT', $this->path($id), [...$this->customer(['phone' => '71 900']), 'version' => $this->json()['version']]);
        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        self::assertSame([['propertyPath' => 'phone', 'reason' => 'phone_length', 'params' => ['example' => '+216 30 010 123', 'callingCode' => '+216']]], $this->refusals());

        $this->sendJson('PUT', $this->path($id), [...$this->customer(['phone' => '0033 1 23 45 67 89', 'billingCountryCode' => 'TN']), 'version' => 1]);
        self::assertResponseIsSuccessful();
        self::assertSame('+33123456789', $this->json()['phone'], '« 00 » names another country');

        // A phone kept before the rule, which it would refuse, is accepted while it is sent back unchanged.
        $this->em()->getConnection()->executeStatement("UPDATE customer SET phone = '71 900' WHERE id = ?", [$id]);
        $this->sendJson('PUT', $this->path($id), [...$this->customer(['name' => 'Carthage Conseil SA', 'phone' => '71 900']), 'version' => 2]);
        self::assertResponseIsSuccessful();
        self::assertSame(['Carthage Conseil SA', '71 900'], [$this->json()['name'], $this->json()['phone']]);

        $this->sendJson('PUT', $this->path($id), [...$this->customer(['phone' => '+216 11 111 111', 'toCheck' => false]), 'version' => 3]);
        self::assertResponseIsSuccessful();
        self::assertSame(['+21611111111', true], [$this->json()['phone'], $this->json()['toCheck']], 'the length of a number, outside the ones handed out');
        self::assertStringContainsString('+216 11111111', $this->stringAt($this->json(), 'checkNote'), 'the number outside the plan, grouped as far as its code goes');

        // The person who looked takes the mark off; the same number sent back, written either way, leaves it off.
        $this->sendJson('PUT', $this->path($id), [...$this->customer(['phone' => '+216 11 111 111', 'toCheck' => false]), 'version' => 4]);
        self::assertResponseIsSuccessful();
        self::assertSame(['+21611111111', false], [$this->json()['phone'], $this->json()['toCheck']]);
    }

    public function testANumberAnotherCustomerHasAnswersConflict(): void
    {
        $this->signedIn(['customer.read', 'customer.write']);
        $this->postJson($this->path(), $this->customer());

        $this->postJson($this->path(), $this->customer());

        self::assertResponseStatusCodeSame(Response::HTTP_CONFLICT);
    }

    public function testARevisionIsAuditedWithTheNamesOfTheFieldsItChanged(): void
    {
        $this->signedIn(['customer.read', 'customer.write']);
        $this->postJson($this->path(), $this->customer());
        $id = $this->stringAt($this->json(), 'id');

        $this->sendJson('PUT', $this->path($id), $this->customer(['version' => 1, 'email' => 'compta@carthage.tn', 'isActive' => false, 'shippingCity' => 'Sfax', 'shippingCountryCode' => 'TN']));

        self::assertResponseIsSuccessful();
        self::assertFalse($this->json()['isActive']);
        self::assertSame('Sfax', $this->json()['shippingCity']);
        $changes = $this->em()->getConnection()->fetchOne("SELECT changes::text FROM audit_log WHERE action = 'customer.revised'");
        self::assertIsString($changes);
        self::assertSame(['fields' => ['email', 'shippingAddress', 'isActive']], json_decode($changes, true));
    }

    public function testARevisionOfAVersionSavedSinceIsRefusedAsChangedAndWhatWasSavedStays(): void
    {
        $this->signedIn(['customer.read', 'customer.write']);
        $this->postJson($this->path(), $this->customer());
        $id = $this->stringAt($this->json(), 'id');
        self::assertSame(1, $this->json()['version']);

        $this->sendJson('PUT', $this->path($id), $this->customer(['version' => 1, 'billingCity' => 'Sfax']));
        self::assertResponseIsSuccessful();
        self::assertSame(2, $this->json()['version']);

        $this->sendJson('PUT', $this->path($id), $this->customer(['version' => 1, 'billingCity' => 'Sousse']));

        self::assertResponseStatusCodeSame(Response::HTTP_CONFLICT);
        self::assertSame('/problems/record-changed', $this->json()['type']);
        $this->getJson($this->path($id));
        self::assertSame(['Sfax', 2], [$this->json()['billingCity'], $this->json()['version']]);
    }

    public function testASaveThatLostTheRaceToAnotherIsRefusedAsChanged(): void
    {
        $this->signedIn(['customer.read', 'customer.write']);
        $this->postJson($this->path(), $this->customer());
        $id = Uuid::fromString($this->stringAt($this->json(), 'id'));
        $customer = $this->em()->find(Customer::class, $id);
        self::assertNotNull($customer);
        static::getContainer()->get(RecordVersions::class)->expect($customer, 1);
        // Another request saves the same customer between this one's check and its write.
        $this->em()->getConnection()->executeStatement('UPDATE customer SET version = version + 1 WHERE id = ?', [$id->toRfc4122()]);

        $this->expectException(RecordChanged::class);
        static::getContainer()->get(Transactions::class)->run(static fn (): array => $customer->revise('CLI-0002', $customer->getProfile(), null, $customer->getTaxRegime(), [], true, new \DateTimeImmutable()));
    }

    public function testARevisionThatChangesNothingKeepsItsVersion(): void
    {
        $this->signedIn(['customer.read', 'customer.write']);
        $this->postJson($this->path(), $this->customer());
        $id = $this->stringAt($this->json(), 'id');

        $this->sendJson('PUT', $this->path($id), $this->customer(['version' => 1]));

        self::assertResponseIsSuccessful();
        self::assertSame(1, $this->json()['version']);
    }

    public function testARevisionSaysWhichVersionItRevises(): void
    {
        $this->signedIn(['customer.read', 'customer.write']);
        $this->postJson($this->path(), $this->customer());
        $id = $this->stringAt($this->json(), 'id');

        $this->sendJson('PUT', $this->path($id), $this->customer(['billingCity' => 'Sfax']));

        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        self::assertSame(['version'], array_column($this->arrayAt($this->json(), 'violations'), 'propertyPath'));
    }

    public function testAReaderOnlyReadsAndAnotherCompanysCustomerIsNotFound(): void
    {
        $globex = $this->createCompany('Globex');
        // Both people exist before the first request: the client reboots the kernel, which detaches the company.
        $this->createUser('reader@twes.local', 'password-1234', $this->company, ['customer.read'], 'reader');
        $this->signedIn(['customer.read', 'customer.write']);
        $this->postJson($this->path(), $this->customer());
        $mine = $this->stringAt($this->json(), 'id');

        $this->sendJson('POST', '/api/auth/logout');
        $this->login('reader@twes.local', 'password-1234');
        $this->getJson($this->path($mine));
        self::assertResponseIsSuccessful();
        $this->sendJson('PUT', $this->path($mine), $this->customer(['version' => 1]));
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
        $this->postJson($this->path(), $this->customer(['number' => 'CLI-0002']));
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
        $this->getJson($this->path());
        self::assertCount(1, $this->jsonList());

        $this->getJson('/api/companies/'.$globex->getId()->toRfc4122().'/customers');
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
        $this->getJson($this->path('not-a-uuid'));
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
    }

    public function testAnotherCompanysCustomerIsNotFoundThroughThisCompany(): void
    {
        $globex = $this->createCompany('Globex');
        $regime = static::getContainer()->get(CustomerTaxRegimeRepository::class)->ofPresetAndCode('TN', 'standard');
        self::assertNotNull($regime);
        $theirs = Customer::create($globex, 'CLI-0001', new CustomerProfile(CustomerKind::Individual, 'Amel'), null, $regime, [], new \DateTimeImmutable());
        $this->em()->persist($theirs);
        $this->em()->flush();
        $this->signedIn(['customer.read', 'customer.write']);

        $this->getJson($this->path($theirs->getId()->toRfc4122()));
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
        $this->sendJson('PUT', $this->path($theirs->getId()->toRfc4122()), $this->customer(['version' => 1, 'kind' => 'individual', 'identifiers' => []]));
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
    }

    public function testTheListIsOnePageAtATimeWithTheTotal(): void
    {
        $this->seedCustomers(105);
        $this->signedIn(['customer.read']);

        $this->getJson($this->path());
        self::assertResponseIsSuccessful();
        self::assertSame(['CLI-0001', 'CLI-0025'], [$this->jsonList()[0]['number'], $this->jsonList()[24]['number']]);
        self::assertCount(25, $this->jsonList(), 'a page holds 25 rows unless asked otherwise');
        $page = $this->jsonPage();
        self::assertSame(105, $page['totalItems']);
        self::assertStringContainsString('page=2', $this->stringAt($this->section($page, 'view'), 'next'));

        $this->getJson($this->path().'?page=5');
        self::assertSame(['CLI-0101', 'CLI-0105'], [$this->jsonList()[0]['number'], $this->jsonList()[4]['number']]);

        $this->getJson($this->path().'?itemsPerPage=50&page=3');
        self::assertCount(5, $this->jsonList());
        $this->getJson($this->path().'?itemsPerPage=1000');
        self::assertCount(100, $this->jsonList(), 'a page never holds more than 100 rows');

        $id = $this->stringAt($this->jsonList()[0], 'id');
        $this->client->request('GET', $this->path($id), [], [], ['HTTP_ACCEPT' => 'application/ld+json']);
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_ACCEPTABLE, 'a single record is plain JSON only');
        $this->getJson($this->path($id));
        self::assertResponseHeaderSame('Content-Type', 'application/json');
        $this->client->request('GET', '/api/docs.jsonld');
        self::assertResponseIsSuccessful('the documentation every Hydra answer links to exists');
    }

    public function testTheListIsSearchedNarrowedAndSortedByTheApi(): void
    {
        $retail = CustomerGroup::create($this->company, 'Retail', null, new \DateTimeImmutable());
        $this->em()->persist($retail);
        $regime = $this->standardRegime();
        $now = new \DateTimeImmutable();
        $this->em()->persist(Customer::create($this->company, 'CLI-0001', new CustomerProfile(CustomerKind::Company, 'Carthagé Conseil', identifiers: ['matricule_fiscal' => self::MATRICULE], email: 'contact@carthage.tn', billingAddress: new PostalAddress('12, rue du Lac Léman', null, '1053', 'Sfax')), $retail, $regime, [], $now));
        $this->em()->persist(Customer::create($this->company, 'K9', new CustomerProfile(CustomerKind::Individual, 'Kais'), null, $regime, [], $now));
        $this->em()->persist(Customer::create($this->company, 'CLI-0002', new CustomerProfile(CustomerKind::Individual, 'Amel Ben Salah', billingAddress: new PostalAddress(city: 'Tunis')), null, $regime, [], $now));
        $retired = Customer::create($this->company, 'CLI-0003', new CustomerProfile(CustomerKind::Company, 'Zitouna Négoce', legalName: 'Société Zitouna'), $retail, $regime, [], $now);
        $retired->revise('CLI-0003', $retired->getProfile(), $retail, $regime, [], false, $now);
        $this->em()->persist($retired);
        $this->em()->flush();
        $retailId = $retail->getId()->toRfc4122();
        $this->signedIn(['customer.read']);

        foreach ([
            'q=CARTHAGE' => ['CLI-0001'],
            'q=carthage.tn' => ['CLI-0001'],
            'q=cli-0002' => ['CLI-0002'],
            'q=societe' => ['CLI-0003'],
            'q=tunis' => ['CLI-0002'],
            'q=lac leman' => ['CLI-0001'],
            'q=1053' => ['CLI-0001'],
            'q=1234567a' => ['CLI-0001'],
            'q=matricule' => [],
            'q=ca' => [],
            'q=k9' => ['K9'],
            'order[city]=asc&isActive=true' => ['CLI-0001', 'CLI-0002', 'K9'],
            'order[city]=desc&isActive=true' => ['CLI-0002', 'CLI-0001', 'K9'],
            'order[customerGroup]=desc' => ['CLI-0001', 'CLI-0003', 'CLI-0002', 'K9'],
            'q=nobody' => [],
            'kind=individual' => ['CLI-0002', 'K9'],
            'isActive=false' => ['CLI-0003'],
            'customerGroupId='.$retailId => ['CLI-0001', 'CLI-0003'],
            'customerGroupId='.$retailId.'&isActive=true' => ['CLI-0001'],
            'order[name]=desc' => ['CLI-0003', 'K9', 'CLI-0001', 'CLI-0002'],
            'order[number]=desc' => ['K9', 'CLI-0003', 'CLI-0002', 'CLI-0001'],
        ] as $query => $numbers) {
            $this->getJson($this->path().'?'.$query);
            self::assertResponseIsSuccessful($query);
            self::assertSame($numbers, array_column($this->jsonList(), 'number'), $query);
            self::assertSame(\count($numbers), $this->jsonPage()['totalItems'], $query);
        }

        $this->getJson($this->path().'?kind=robot');
        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
    }

    public function testEveryFilterOfTheListCombines(): void
    {
        $retail = CustomerGroup::create($this->company, 'Retail', null, new \DateTimeImmutable());
        $wholesale = CustomerGroup::create($this->company, 'Wholesale', null, new \DateTimeImmutable());
        $this->em()->persist($retail);
        $this->em()->persist($wholesale);
        $standard = $this->standardRegime();
        $exempt = static::getContainer()->get(CustomerTaxRegimeRepository::class)->ofPresetAndCode('TN', 'exempt');
        self::assertNotNull($exempt);
        // The company keeps Tunis time, an hour ahead of the stored UTC: 23:30 UTC on the 14th is already the 15th there.
        foreach ([
            ['A', CustomerKind::Company, $retail, $standard, true, '2026-03-10 10:00'],
            ['B', CustomerKind::Individual, $wholesale, $exempt, true, '2026-03-14 23:30'],
            ['C', CustomerKind::Individual, null, $standard, false, '2026-03-15 14:00'],
            ['D', CustomerKind::Company, null, $exempt, true, '2026-03-20 09:00'],
        ] as [$number, $kind, $group, $regime, $active, $at]) {
            $at = new \DateTimeImmutable($at, new \DateTimeZone('UTC'));
            $customer = Customer::create($this->company, $number, new CustomerProfile($kind, 'Client '.$number), $group, $regime, [], $at);
            $customer->revise($number, $customer->getProfile(), $group, $regime, [], $active, $at);
            $this->em()->persist($customer);
        }
        $this->em()->flush();
        [$retailId, $wholesaleId] = [$retail->getId()->toRfc4122(), $wholesale->getId()->toRfc4122()];
        $this->signedIn(['customer.read']);

        foreach ([
            'kind[]=company&kind[]=individual' => ['A', 'B', 'C', 'D'],
            'kind=individual' => ['B', 'C'],
            "customerGroupId[]=$retailId&customerGroupId[]=$wholesaleId" => ['A', 'B'],
            "customerGroupId=$wholesaleId" => ['B'],
            "kind[]=company&customerGroupId[]=$retailId&customerGroupId[]=$wholesaleId" => ['A'],
            'taxRegime[]=exempt' => ['B', 'D'],
            'taxRegime[]=exempt&taxRegime[]=standard&isActive=false' => ['C'],
            'taxRegime=standard&kind=company' => ['A'],
            'createdAt[from]=2026-03-15' => ['B', 'C', 'D'],
            'createdAt[to]=2026-03-14' => ['A'],
            'createdAt[from]=2026-03-15&createdAt[to]=2026-03-15' => ['B', 'C'],
            'createdAt[from]=2026-03-11&kind[]=company&isActive=true' => ['D'],
        ] as $query => $numbers) {
            $this->getJson($this->path().'?'.$query);
            self::assertResponseIsSuccessful($query);
            self::assertSame($numbers, array_column($this->jsonList(), 'number'), $query);
            self::assertSame(\count($numbers), $this->jsonPage()['totalItems'], $query);
        }

        foreach (['kind[]=company&kind[]=robot', 'customerGroupId[]=not-a-uuid', 'taxRegime[]=nowhere', 'createdAt[from]=2026-03-20&createdAt[to]=2026-03-10', 'createdAt[from]=2026-02-30'] as $refused) {
            $this->getJson($this->path().'?'.$refused);
            self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY, $refused);
        }
    }

    private function seedCustomers(int $count): void
    {
        $regime = $this->standardRegime();
        $now = new \DateTimeImmutable();
        foreach (range(1, $count) as $n) {
            $this->em()->persist(Customer::create($this->company, \sprintf('CLI-%04d', $n), new CustomerProfile(CustomerKind::Individual, 'Client '.$n), null, $regime, [], $now));
        }
        $this->em()->flush();
    }

    private function standardRegime(): CustomerTaxRegime
    {
        $regime = static::getContainer()->get(CustomerTaxRegimeRepository::class)->ofPresetAndCode('TN', 'standard');
        self::assertNotNull($regime);

        return $regime;
    }

    /**
     * @param array<string, mixed> $changes
     *
     * @return array<string, mixed>
     */
    private function customer(array $changes = []): array
    {
        return [...[
            'number' => 'CLI-0001',
            'kind' => 'company',
            'name' => 'Carthage Conseil',
            'identifiers' => ['matricule_fiscal' => self::MATRICULE],
            'taxRegime' => 'standard',
            'billingAddressLine1' => '12, rue du Lac Léman',
            'billingCity' => 'Tunis',
            'defaultTaxComponentIds' => [],
            'isActive' => true,
        ], ...$changes];
    }

    private function taxId(string $code): string
    {
        $tax = static::getContainer()->get(TaxComponentRepository::class)->ofCodeInCompany($code, $this->company->getId());
        self::assertNotNull($tax);

        return $tax->getId()->toRfc4122();
    }

    /** @param list<string> $permissions */
    private function signedIn(array $permissions): void
    {
        $this->createUser('sales@twes.local', 'password-1234', $this->company, $permissions, 'member');
        $this->login('sales@twes.local', 'password-1234');
        self::assertResponseIsSuccessful();
    }

    private function companyPath(): string
    {
        return '/api/companies/'.$this->company->getId()->toRfc4122();
    }

    private function path(?string $customerId = null): string
    {
        return $this->companyPath().'/customers'.(null === $customerId ? '' : '/'.$customerId);
    }
}
