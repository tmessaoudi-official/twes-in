<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Fiscal\Application\Company\ProvisionCompany;
use App\Fiscal\Application\Regime\SyncCustomerTaxRegimes;
use App\Fiscal\Domain\CustomerTaxRegimeRepository;
use App\Fiscal\Domain\TaxComponentRepository;
use App\Fiscal\Domain\UnitRepository;
use App\Module\Customers\Domain\Customer;
use App\Module\Customers\Domain\CustomerKind;
use App\Module\Customers\Domain\CustomerProfile;
use App\Module\DeliveryNotes\Domain\DeliveryNote;
use App\Module\DeliveryNotes\Domain\DeliveryNoteHeader;
use App\Module\Products\Domain\Product;
use App\Module\Products\Domain\ProductDetails;
use App\Module\Products\Domain\ProductKind;
use App\Tenancy\Domain\Company;
use App\Tenancy\Domain\EstablishmentRepository;
use Symfony\Component\HttpFoundation\Response;

final class DeliveryNotesTest extends ApiTestCase
{
    private const string ABSENT = '0192c3a4-0000-7000-8000-000000000000';

    private Company $company;
    private string $customerId;
    private string $productId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->company = $this->createCompany('Acme');
        static::getContainer()->get(ProvisionCompany::class)->handle($this->company);
        static::getContainer()->get(SyncCustomerTaxRegimes::class)->handle();
        $this->customerId = $this->customer('CLI-0001', 'standard')->getId()->toRfc4122();
        $product = Product::create($this->company, 'ART-001', new ProductDetails('Portable 14"', null, ProductKind::Goods, '1250'), $this->unit('C62'), null, [$this->tax('FODEC')->getId(), $this->tax('TVA19')->getId()], new \DateTimeImmutable());
        $this->em()->persist($product);
        $this->em()->flush();
        $this->productId = $product->getId()->toRfc4122();
    }

    public function testTheOptionsSayWhatTheNoteFormAsksFor(): void
    {
        $this->customer('CLI-OLD', 'standard', active: false);
        $this->signedIn(['delivery_note.read']);

        $this->getJson($this->companyPath().'/delivery-note-options');

        self::assertResponseIsSuccessful();
        $options = $this->json();
        self::assertSame(['TND', 3], [$options['currency'], $options['currencyScale']]);
        self::assertSame(['000'], array_column($this->arrayAt($options, 'establishments'), 'code'));
        $customers = $this->arrayAt($options, 'customers');
        self::assertSame(['CLI-0001'], array_column($customers, 'number'), 'a deactivated customer is not offered');
        self::assertSame([[]], array_column($customers, 'excludedFamilies'));
        $products = $this->arrayAt($options, 'products');
        self::assertSame(['ART-001'], array_column($products, 'reference'));
        self::assertSame([$this->unitId('C62')], array_column($products, 'unitId'));
        self::assertSame(['1250.0000'], array_column($products, 'unitPriceNet'));
        self::assertSame([[$this->taxId('FODEC'), $this->taxId('TVA19')]], array_column($products, 'defaultTaxComponentIds'));
        self::assertContains('C62', array_column($this->arrayAt($options, 'units'), 'code'));
        $taxes = array_column($this->arrayAt($options, 'taxes'), null, 'code');
        self::assertArrayHasKey('TVA19', $taxes);
        self::assertArrayNotHasKey('TIMBRE', $taxes, 'a stamp is charged on the document');
        self::assertIsArray($taxes['TVA19']);
        self::assertSame(['vat', '19.000'], [$taxes['TVA19']['family'], $taxes['TVA19']['rate']]);
    }

    public function testAWriterDraftsANoteWhoseLinesStartFromTheirProducts(): void
    {
        $this->signedIn(['delivery_note.read', 'delivery_note.write']);

        $this->postJson($this->path(), $this->note([
            'deliveryDate' => '2026-09-20',
            'deliveryAddressLine1' => 'Rue de Marseille',
            'deliveryPostalCode' => '1000',
            'deliveryCity' => 'Tunis',
            'deliveryCountryCode' => 'TN',
            'customerReference' => 'PO-77',
            'lines' => [
                ['productId' => $this->productId, 'quantity' => '2'],
                ['description' => 'Pose', 'quantity' => '1.5', 'unitId' => $this->unitId('HUR'), 'unitPriceNet' => '40', 'taxComponentIds' => [$this->taxId('TVA19')]],
            ],
        ]));

        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);
        $note = $this->json();
        self::assertSame(['draft', null, null], [$note['status'], $note['number'], $note['issueDate']]);
        self::assertSame([$this->establishmentId(), $this->customerId], [$note['establishmentId'], $note['customerId']]);
        self::assertSame(['2026-09-20', 'Rue de Marseille', 'Tunis', 'PO-77'], [$note['deliveryDate'], $note['deliveryAddressLine1'], $note['deliveryCity'], $note['customerReference']]);
        $lines = $this->arrayAt($note, 'lines');
        self::assertSame([$this->productId, null], array_column($lines, 'productId'));
        self::assertSame(['Portable 14"', 'Pose'], array_column($lines, 'description'));
        self::assertSame(['2.000', '1.500'], array_column($lines, 'quantity'));
        self::assertSame([$this->unitId('C62'), $this->unitId('HUR')], array_column($lines, 'unitId'));
        self::assertSame(['1250.0000', '40.0000'], array_column($lines, 'unitPriceNet'));
        self::assertSame([[$this->taxId('FODEC'), $this->taxId('TVA19')], [$this->taxId('TVA19')]], array_column($lines, 'taxComponentIds'));
        self::assertSame(['2500.000', '60.000'], array_column($lines, 'net'));
        // FODEC 1 % of 2500 enters the VAT base of its line: VAT 19 % of (2525 + 60).
        $taxes = $this->arrayAt($note, 'taxes');
        self::assertSame([['FODEC', '25.000'], ['TVA19', '491.150']], array_map(null, array_column($taxes, 'code'), array_column($taxes, 'amount')));
        self::assertSame(['2560.000', '516.150', '3076.150'], [$note['subtotalNet'], $note['totalTax'], $note['total']]);

        $this->getJson($this->path($this->stringAt($note, 'id')));
        self::assertResponseIsSuccessful();
        self::assertSame('3076.150', $this->json()['total'], 'the stored note totals the same');
        $this->getJson($this->path());
        self::assertCount(1, $this->jsonList());
        self::assertSame('[]', $this->em()->getConnection()->fetchOne("SELECT changes::text FROM audit_log WHERE action = 'delivery_note.created'"));
    }

    public function testWhatTheShapeOrTheCompanyRefusesAnswersUnprocessableNamingTheField(): void
    {
        $this->signedIn(['delivery_note.read', 'delivery_note.write']);
        $piece = ['description' => 'Pièce', 'quantity' => '1', 'unitId' => $this->unitId('C62'), 'unitPriceNet' => '10'];
        $exempt = $this->customer('CLI-0002', 'exempt')->getId()->toRfc4122();

        foreach ([
            ['customerId', ['customerId' => self::ABSENT]],
            ['establishmentId', ['establishmentId' => self::ABSENT]],
            ['deliveryDate', ['deliveryDate' => '20/09/2026']],
            ['customerReference', ['customerReference' => str_repeat('a', 65)]],
            ['lines[0].quantity', ['lines' => [[...$piece, 'quantity' => '1.5']]]],
            ['lines[0].unitPriceNet', ['lines' => [[...$piece, 'unitPriceNet' => '-1']]]],
            ['lines[0].taxComponentIds', ['lines' => [[...$piece, 'taxComponentIds' => [$this->taxId('TIMBRE')]]]]],
            ['lines[1].productId', ['lines' => [$piece, ['productId' => self::ABSENT, 'quantity' => '1']]]],
            ['lines[0].taxComponentIds', ['customerId' => $exempt, 'lines' => [[...$piece, 'taxComponentIds' => [$this->taxId('TVA19')]]]]],
        ] as [$field, $change]) {
            $this->postJson($this->path(), $this->note($change));
            self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY, $field);
            self::assertStringContainsString($field, (string) $this->client->getResponse()->getContent());
        }

        $this->postJson($this->path(), $this->note(['lines' => ['first' => $piece]]));
        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY, 'the lines are a list, never a map');
        $this->postJson($this->path(), $this->note(['lines' => [['description' => 'Pièce', 'unitId' => $this->unitId('C62'), 'unitPriceNet' => '10']]]));
        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY, 'a line states its quantity');
        $this->postJson($this->path(), $this->note(['lines' => [[...$piece, 'quantity' => 2]]]));
        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY, 'a quantity is a decimal string, never a float');
        $this->getJson($this->path());
        self::assertSame([], $this->jsonList());
    }

    public function testARevisionIsAuditedWithTheNamesOfTheFieldsItChanged(): void
    {
        $this->signedIn(['delivery_note.read', 'delivery_note.write']);
        $this->postJson($this->path(), $this->note(['lines' => [['productId' => $this->productId, 'quantity' => '1']]]));
        $id = $this->stringAt($this->json(), 'id');

        $this->sendJson('PUT', $this->path($id), $this->note(['customerReference' => 'PO-78', 'lines' => [['productId' => $this->productId, 'quantity' => '3']]]));

        self::assertResponseIsSuccessful();
        $note = $this->json();
        self::assertSame(['PO-78', ['3.000']], [$note['customerReference'], array_column($this->arrayAt($note, 'lines'), 'quantity')]);
        $changes = $this->em()->getConnection()->fetchOne("SELECT changes::text FROM audit_log WHERE action = 'delivery_note.revised'");
        self::assertIsString($changes);
        self::assertSame(['fields' => ['customerReference', 'lines']], json_decode($changes, true));

        $this->sendJson('PUT', $this->path(self::ABSENT), $this->note());
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
    }

    public function testAValidatorNumbersANoteWithoutGapsAndItThenOnlyMovesForward(): void
    {
        $this->signedIn(['delivery_note.read', 'delivery_note.write', 'delivery_note.validate']);
        $today = new \DateTimeImmutable('now', new \DateTimeZone($this->company->getTimezone()))->format('Y-m-d');
        $number = static fn (int $sequence): string => \sprintf('BL-%s-%05d', substr($today, 0, 4), $sequence);
        $id = $this->draftWithALine();

        $this->postJson($this->path($id).'/validate', null);

        self::assertResponseStatusCodeSame(Response::HTTP_OK);
        $note = $this->json();
        self::assertSame(['validated', $number(1), $today], [$note['status'], $note['number'], $note['issueDate']]);
        $snapshot = $this->arrayAt($note, 'customerSnapshot');
        self::assertSame(['CLI-0001', 'company', 'Carthage Conseil', 'standard'], [$snapshot['number'], $snapshot['kind'], $snapshot['name'], $snapshot['taxRegimeCode']]);
        self::assertStringContainsString('"identifiers":{}', (string) $this->client->getResponse()->getContent(), 'no identifiers read as an empty object');
        $changes = $this->em()->getConnection()->fetchOne("SELECT changes::text FROM audit_log WHERE action = 'delivery_note.validated'");
        self::assertIsString($changes);
        self::assertSame(['number' => $number(1)], json_decode($changes, true));

        $this->sendJson('PUT', $this->path($id), $this->note());
        self::assertResponseStatusCodeSame(Response::HTTP_CONFLICT, 'a validated note is no longer revised');
        $this->postJson($this->path($id).'/validate', null);
        self::assertResponseStatusCodeSame(Response::HTTP_CONFLICT, 'a note is numbered once');

        foreach (['2000-01-01' => 'before its issue day', '2999-01-01' => 'after today', '15/09/2026' => 'not a day'] as $day => $case) {
            $this->postJson($this->path($id).'/deliver', ['deliveredOn' => $day]);
            self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY, $case);
            self::assertStringContainsString('deliveredOn', (string) $this->client->getResponse()->getContent(), $case);
        }
        $this->postJson($this->path($id).'/deliver', []);
        self::assertResponseStatusCodeSame(Response::HTTP_OK);
        self::assertSame(['delivered', $today], [$this->json()['status'], $this->json()['deliveryDate']]);
        $this->postJson($this->path($id).'/cancel', null);
        self::assertResponseStatusCodeSame(Response::HTTP_CONFLICT, 'delivered goods are not cancelled');

        $this->postJson($this->path(), $this->note());
        $this->postJson($this->path($this->stringAt($this->json(), 'id')).'/validate', null);
        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY, 'a note without a line is not validated');
        self::assertStringContainsString('lines', (string) $this->client->getResponse()->getContent());

        $second = $this->draftWithALine();
        $this->postJson($this->path($second).'/validate', null);
        self::assertSame($number(2), $this->json()['number'], 'a refused validation gives its number back');
        $this->postJson($this->path($second).'/cancel', null);
        self::assertResponseStatusCodeSame(Response::HTTP_OK);
        self::assertSame(['cancelled', $number(2)], [$this->json()['status'], $this->json()['number']]);

        $this->postJson($this->path(self::ABSENT).'/validate', null);
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
    }

    public function testAWriterDraftsButNeitherValidatesNorCancels(): void
    {
        $this->signedIn(['delivery_note.read', 'delivery_note.write']);
        $id = $this->draftWithALine();

        $this->postJson($this->path($id).'/validate', null);
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND, 'validating needs delivery_note.validate');
        $this->postJson($this->path($id).'/cancel', null);
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND, 'cancelling needs delivery_note.validate');
        $this->postJson($this->path($id).'/deliver', []);
        self::assertResponseStatusCodeSame(Response::HTTP_CONFLICT, 'a draft is not delivered');

        $this->getJson($this->path($id));
        self::assertSame(['draft', null, null], [$this->json()['status'], $this->json()['number'], $this->json()['customerSnapshot']]);
    }

    public function testADraftPrintsOnRequestAndAValidatedNotePrintsAsItWasIssued(): void
    {
        $this->signedIn(['delivery_note.read', 'delivery_note.write', 'delivery_note.validate']);
        $id = $this->draftWithALine();

        $this->client->request('GET', $this->path($id).'/pdf');

        self::assertResponseIsSuccessful();
        $response = $this->client->getResponse();
        self::assertSame('application/pdf', $response->headers->get('content-type'));
        self::assertStringContainsString('delivery-note-'.$id.'.pdf', (string) $response->headers->get('content-disposition'));
        $draft = (string) $response->getContent();
        self::assertStringStartsWith('%PDF-', $draft);
        self::assertStringContainsString('BROUILLON', $draft);
        self::assertStringContainsString("1\u{a0}250,000", $draft);
        self::assertSame(0, $this->em()->getConnection()->fetchOne('SELECT COUNT(*) FROM file'), 'a draft is never stored');

        $this->postJson($this->path($id).'/validate', null);
        self::assertResponseIsSuccessful();
        $number = $this->stringAt($this->json(), 'number');
        $stored = $this->em()->getConnection()->fetchAssociative('SELECT f.original_name, f.mime, f.sha256 FROM file f JOIN delivery_note n ON n.pdf_file_id = f.id WHERE n.id = ?', [$id]);
        self::assertIsArray($stored, 'validation stores the PDF as it was issued');
        self::assertSame([$number.'.pdf', 'application/pdf'], [$stored['original_name'], $stored['mime']]);

        $this->client->request('GET', $this->path($id).'/pdf');

        self::assertResponseIsSuccessful();
        $issued = (string) $this->client->getResponse()->getContent();
        self::assertSame($stored['sha256'], hash('sha256', $issued), 'the download is the stored file');
        self::assertStringContainsString($number, $issued);
        self::assertStringContainsString('Carthage Conseil', $issued);
        self::assertStringNotContainsString('BROUILLON', $issued);
    }

    public function testACompanyThatHidesPricesPrintsNoneAndAReaderDownloads(): void
    {
        $this->signedIn(['delivery_note.read', 'delivery_note.write', 'company.read', 'company.settings']);
        $this->createUser('reader@twes.local', 'password-1234', $this->company, ['delivery_note.read'], 'reader');
        $this->sendJson('PUT', $this->companyPath().'/settings/delivery_note.show_prices', ['level' => 'company', 'value' => false]);
        self::assertResponseIsSuccessful();
        $id = $this->draftWithALine();

        $this->client->request('GET', $this->path($id).'/pdf');

        self::assertResponseIsSuccessful();
        $pdf = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('Portable 14', $pdf);
        self::assertStringNotContainsString("1\u{a0}250,000", $pdf);

        $this->sendJson('POST', '/api/auth/logout');
        $this->login('reader@twes.local', 'password-1234');
        $this->client->request('GET', $this->path($id).'/pdf');
        self::assertResponseIsSuccessful();
        $this->client->request('GET', $this->path(self::ABSENT).'/pdf');
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
        $this->client->request('GET', '/api/companies/'.self::ABSENT.'/delivery-notes/'.$id.'/pdf');
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND, 'another company has no such note');

        $this->sendJson('POST', '/api/auth/logout');
        $this->client->request('GET', $this->path($id).'/pdf');
        self::assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
    }

    public function testAReaderOnlyReadsAndAnotherCompanysNoteIsNotFound(): void
    {
        $globex = $this->createCompany('Globex');
        static::getContainer()->get(ProvisionCompany::class)->handle($globex);
        $theirEstablishment = static::getContainer()->get(EstablishmentRepository::class)->ofCompany($globex->getId())[0];
        $theirs = DeliveryNote::create($globex, $theirEstablishment, $this->customer('CLI-0001', 'standard', company: $globex), new DeliveryNoteHeader(), [], new \DateTimeImmutable());
        $this->em()->persist($theirs);
        $this->em()->flush();
        $this->createUser('reader@twes.local', 'password-1234', $this->company, ['delivery_note.read'], 'reader');
        $this->signedIn(['delivery_note.read', 'delivery_note.write']);
        $this->postJson($this->path(), $this->note());
        self::assertResponseStatusCodeSame(Response::HTTP_CREATED, 'a draft may have no line yet');
        $mine = $this->stringAt($this->json(), 'id');
        self::assertSame(['0.000', '0.000', []], [$this->json()['total'], $this->json()['subtotalNet'], $this->json()['taxes']]);

        $this->sendJson('POST', '/api/auth/logout');
        $this->login('reader@twes.local', 'password-1234');
        $this->getJson($this->path($mine));
        self::assertResponseIsSuccessful();
        $this->getJson($this->companyPath().'/delivery-note-options');
        self::assertResponseIsSuccessful();
        $this->sendJson('PUT', $this->path($mine), $this->note());
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
        $this->postJson($this->path(), $this->note());
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);

        $this->getJson($this->path($theirs->getId()->toRfc4122()));
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
        $this->getJson('/api/companies/'.$globex->getId()->toRfc4122().'/delivery-notes');
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
        $this->getJson($this->path('not-a-uuid'));
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);

        $this->sendJson('POST', '/api/auth/logout');
        $this->getJson($this->path());
        self::assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
    }

    public function testSwitchedOffTheModuleAnswersNotFoundAndKeepsItsNotes(): void
    {
        $this->signedIn(['delivery_note.read', 'delivery_note.write', 'company.read', 'company.settings']);
        $this->postJson($this->path(), $this->note());
        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);
        $id = $this->stringAt($this->json(), 'id');

        $this->sendJson('PUT', $this->companyPath().'/modules/delivery_notes', ['enabled' => false]);
        self::assertResponseIsSuccessful();
        $this->getJson($this->path());
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
        $this->client->request('GET', $this->path($id).'/pdf');
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND, 'the PDF is the module\'s too');
        $this->getJson($this->companyPath().'/delivery-note-options');
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);

        $this->sendJson('PUT', $this->companyPath().'/modules/delivery_notes', ['enabled' => true]);
        $this->getJson($this->path());
        self::assertResponseIsSuccessful();
        self::assertCount(1, $this->jsonList());
    }

    /**
     * @param array<string, mixed> $changes
     *
     * @return array<string, mixed>
     */
    private function note(array $changes = []): array
    {
        return [...[
            'customerId' => $this->customerId,
            'establishmentId' => null,
            'deliveryDate' => null,
            'deliveryAddressLine1' => null,
            'deliveryAddressLine2' => null,
            'deliveryPostalCode' => null,
            'deliveryCity' => null,
            'deliveryCountryCode' => null,
            'customerReference' => null,
            'remarksPrinted' => null,
            'notesInternal' => null,
            'lines' => [],
        ], ...$changes];
    }

    /** A draft delivering one unit of the product; its id. */
    private function draftWithALine(): string
    {
        $this->postJson($this->path(), $this->note(['lines' => [['productId' => $this->productId, 'quantity' => '1']]]));
        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);

        return $this->stringAt($this->json(), 'id');
    }

    private function customer(string $number, string $regime, bool $active = true, ?Company $company = null): Customer
    {
        $taxRegime = static::getContainer()->get(CustomerTaxRegimeRepository::class)->ofPresetAndCode('TN', $regime);
        self::assertNotNull($taxRegime);
        $now = new \DateTimeImmutable();
        $customer = Customer::create($company ?? $this->company, $number, new CustomerProfile(CustomerKind::Company, 'Carthage Conseil'), null, $taxRegime, [], $now);
        if (!$active) {
            $customer->revise($number, $customer->getProfile(), null, $taxRegime, [], false, $now);
        }
        $this->em()->persist($customer);
        $this->em()->flush();

        return $customer;
    }

    private function unit(string $code): \App\Fiscal\Domain\Unit
    {
        $unit = static::getContainer()->get(UnitRepository::class)->ofCodeInCompany($code, $this->company->getId());
        self::assertNotNull($unit);

        return $unit;
    }

    private function unitId(string $code): string
    {
        return $this->unit($code)->getId()->toRfc4122();
    }

    private function tax(string $code): \App\Fiscal\Domain\TaxComponent
    {
        $tax = static::getContainer()->get(TaxComponentRepository::class)->ofCodeInCompany($code, $this->company->getId());
        self::assertNotNull($tax);

        return $tax;
    }

    private function taxId(string $code): string
    {
        return $this->tax($code)->getId()->toRfc4122();
    }

    private function establishmentId(): string
    {
        return static::getContainer()->get(EstablishmentRepository::class)->ofCompany($this->company->getId())[0]->getId()->toRfc4122();
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

    private function path(?string $id = null): string
    {
        return $this->companyPath().'/delivery-notes'.(null === $id ? '' : '/'.$id);
    }
}
