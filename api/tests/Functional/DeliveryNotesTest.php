<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Fiscal\Application\Company\ProvisionCompany;
use App\Fiscal\Application\Regime\SyncCustomerTaxRegimes;
use App\Fiscal\Domain\Repository\CustomerTaxRegimeRepository;
use App\Fiscal\Domain\Repository\TaxComponentRepository;
use App\Fiscal\Domain\Repository\UnitRepository;
use App\Module\Customers\Domain\Model\Contact;
use App\Module\Customers\Domain\Model\Customer;
use App\Module\Customers\Domain\ValueObject\ContactDetails;
use App\Module\Customers\Domain\ValueObject\CustomerKind;
use App\Module\Customers\Domain\ValueObject\CustomerProfile;
use App\Module\DeliveryNotes\Application\PrintDeliveryNote;
use App\Module\DeliveryNotes\Domain\Model\DeliveryNote;
use App\Module\DeliveryNotes\Domain\ValueObject\DeliveryNoteHeader;
use App\Module\Products\Domain\Model\Product;
use App\Module\Products\Domain\ValueObject\ProductDetails;
use App\Module\Products\Domain\ValueObject\ProductKind;
use App\Settings\Application\ChangeSettings;
use App\Settings\Application\SettingContext;
use App\Settings\Domain\ValueObject\SettingLevel;
use App\Tenancy\Application\Company\CompanyLogo;
use App\Tenancy\Domain\Model\Company;
use App\Tenancy\Domain\Repository\EstablishmentRepository;
use App\Tenancy\Domain\ValueObject\CompanyProfile;
use App\Tests\Support\FakePdfRenderer;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Uid\Uuid;

final class DeliveryNotesTest extends ApiTestCase
{
    private const string ABSENT = '0192c3a4-0000-7000-8000-000000000000';
    /** What a row with no number yet is called, so an empty column cannot pass for one. */
    private const string DRAFT = '(draft)';

    private Company $company;
    private string $customerId;
    private string $productId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->company = $this->createCompany('Acme', named: false);
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
        // Neither the book of customers nor the catalogue is here: both are asked a few at a time (DeliveryNotePickTest).
        self::assertArrayNotHasKey('customers', $options);
        self::assertArrayNotHasKey('products', $options);
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
        // The note carries the words for what it names, so a form that opens it need not be handed the company's
        // whole book of customers or its catalogue.
        self::assertNotSame('', $note['customerName']);
        $lines = $this->arrayAt($note, 'lines');
        self::assertSame([$this->productId, null], array_column($lines, 'productId'));
        self::assertSame(['Portable 14"', null], array_column($lines, 'productName'));
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
            ['customerId', ['customerId' => self::ABSENT], 'unknown_choice', []],
            ['establishmentId', ['establishmentId' => self::ABSENT], 'unknown_choice', []],
            ['deliveryDate', ['deliveryDate' => '20/09/2026'], 'not_a_date', []],
            ['customerReference', ['customerReference' => str_repeat('a', 65)], 'invalid_text', ['max' => 64]],
            ['lines[0].quantity', ['lines' => [[...$piece, 'quantity' => '1.5']]], 'quantity_decimals', ['unit' => 'C62', 'decimals' => 0]],
            ['lines[0].unitPriceNet', ['lines' => [[...$piece, 'unitPriceNet' => '-1']]], 'invalid_price', []],
            ['lines[0].taxComponentIds', ['lines' => [[...$piece, 'taxComponentIds' => [$this->taxId('TIMBRE')]]]], 'tax_on_document', ['tax' => 'TIMBRE']],
            ['lines[0].taxComponentIds', ['lines' => [[...$piece, 'taxComponentIds' => [$this->taxId('TVA19'), $this->taxId('TVA19')]]]], 'tax_twice', ['tax' => 'TVA19']],
            ['lines[1].productId', ['lines' => [$piece, ['productId' => self::ABSENT, 'quantity' => '1']]], 'unknown_choice', []],
            ['lines[0].taxComponentIds', ['customerId' => $exempt, 'lines' => [[...$piece, 'taxComponentIds' => [$this->taxId('TVA19')]]]], 'regime_excludes_tax', ['tax' => 'TVA19']],
        ] as [$field, $change, $reason, $params]) {
            $this->postJson($this->path(), $this->note($change));
            self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY, $field);
            self::assertStringContainsString($field, (string) $this->client->getResponse()->getContent());
            self::assertSame([$reason, $params], $this->refusalOf($field), $field.': the refusal names its field and why, which the screen says');
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

        $this->sendJson('PUT', $this->path($id), $this->note(['version' => 1, 'customerReference' => 'PO-78', 'lines' => [['productId' => $this->productId, 'quantity' => '3']]]));

        self::assertResponseIsSuccessful();
        $note = $this->json();
        self::assertSame(['PO-78', ['3.000']], [$note['customerReference'], array_column($this->arrayAt($note, 'lines'), 'quantity')]);
        $changes = $this->em()->getConnection()->fetchOne("SELECT changes::text FROM audit_log WHERE action = 'delivery_note.revised'");
        self::assertIsString($changes);
        self::assertSame(['fields' => ['customerReference', 'lines']], json_decode($changes, true));

        $this->sendJson('PUT', $this->path(self::ABSENT), $this->note(['version' => 1]));
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
    }

    public function testARevisionOfAVersionSavedSinceIsRefusedAsChangedAndWhatWasSavedStays(): void
    {
        $this->signedIn(['delivery_note.read', 'delivery_note.write']);
        $id = $this->draftWithALine();
        self::assertSame(1, $this->json()['version']);
        $line = [['productId' => $this->productId, 'quantity' => '1']];

        $this->sendJson('PUT', $this->path($id), $this->note(['version' => 1, 'customerReference' => 'PO-78', 'lines' => $line]));
        self::assertResponseIsSuccessful();
        self::assertSame(2, $this->json()['version']);

        $this->sendJson('PUT', $this->path($id), $this->note(['version' => 1, 'customerReference' => 'PO-79', 'lines' => $line]));

        self::assertResponseStatusCodeSame(Response::HTTP_CONFLICT);
        self::assertSame('/problems/record-changed', $this->json()['type']);
        $this->getJson($this->path($id));
        self::assertSame(['PO-78', 2], [$this->json()['customerReference'], $this->json()['version']]);
    }

    /** A document's lines are one field of it: a revision of them alone moves the version its form is checked against. */
    public function testARevisionOfItsLinesAloneMovesTheVersion(): void
    {
        $this->signedIn(['delivery_note.read', 'delivery_note.write']);
        $sold = ['productId' => $this->productId, 'quantity' => '1'];
        $fitting = ['description' => 'Pose', 'quantity' => '1.5', 'unitId' => $this->unitId('HUR'), 'unitPriceNet' => '40', 'taxComponentIds' => [$this->taxId('TVA19')]];
        $this->postJson($this->path(), $this->note(['lines' => [$sold, $fitting]]));
        $id = $this->stringAt($this->json(), 'id');
        $total = $this->json()['total'];

        // The same lines in another order: every total stays, and only the lines say the note changed.
        $this->sendJson('PUT', $this->path($id), $this->note(['version' => 1, 'lines' => [$fitting, $sold]]));

        self::assertResponseIsSuccessful();
        self::assertSame($total, $this->json()['total']);
        self::assertSame(2, $this->json()['version']);
        $this->sendJson('PUT', $this->path($id), $this->note(['version' => 1, 'customerReference' => 'PO-78', 'lines' => [$fitting, $sold]]));
        self::assertResponseStatusCodeSame(Response::HTTP_CONFLICT);
    }

    public function testARevisionThatChangesNothingKeepsItsVersion(): void
    {
        $this->signedIn(['delivery_note.read', 'delivery_note.write']);
        $id = $this->draftWithALine();

        $this->sendJson('PUT', $this->path($id), $this->note(['version' => 1, 'lines' => [['productId' => $this->productId, 'quantity' => '1']]]));

        self::assertResponseIsSuccessful();
        self::assertSame(1, $this->json()['version']);
    }

    public function testARevisionSaysWhichVersionItRevises(): void
    {
        $this->signedIn(['delivery_note.read', 'delivery_note.write']);
        $id = $this->draftWithALine();

        $this->sendJson('PUT', $this->path($id), $this->note(['customerReference' => 'PO-78', 'lines' => [['productId' => $this->productId, 'quantity' => '1']]]));

        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        self::assertSame(['version'], array_column($this->arrayAt($this->json(), 'violations'), 'propertyPath'));
    }

    public function testAnIssuedPdfStoredAfterTheNoteWasSavedSinceItWasReadIsKept(): void
    {
        $this->signedIn(['delivery_note.read', 'delivery_note.write', 'delivery_note.validate']);
        $id = $this->draftWithALine();
        // Kept across requests, so the renderer is down while the note is validated and nothing is stored then.
        $this->client->disableReboot();
        $renderer = static::getContainer()->get(FakePdfRenderer::class);
        $renderer->failing = true;
        $this->postJson($this->path($id).'/validate', null);
        self::assertResponseIsSuccessful();
        $renderer->failing = false;
        self::assertNull($this->em()->getConnection()->fetchOne('SELECT pdf_file_id FROM delivery_note WHERE id = ?', [$id]));
        $version = $this->storedVersion('delivery_note', $id);
        // Read as a download reads it, then delivering it saves the note before its PDF is stored.
        self::assertNotNull($this->em()->find(DeliveryNote::class, Uuid::fromString($id)));
        $this->em()->getConnection()->executeStatement('UPDATE delivery_note SET version = version + 1 WHERE id = ?', [$id]);

        static::getContainer()->get(PrintDeliveryNote::class)->storeIssued($this->company->getId(), Uuid::fromString($id));

        self::assertNotNull($this->em()->getConnection()->fetchOne('SELECT pdf_file_id FROM delivery_note WHERE id = ?', [$id]));
        self::assertSame($version + 2, $this->storedVersion('delivery_note', $id));
    }

    public function testAValidatorNumbersANoteWithoutGapsAndItThenOnlyMovesForward(): void
    {
        $this->signedIn(['delivery_note.read', 'delivery_note.write', 'delivery_note.validate']);
        $today = new \DateTimeImmutable('now', new \DateTimeZone($this->company->getTimezone()))->format('Y-m-d');
        $number = static fn (int $sequence): string => \sprintf('BL-%s-%s-%05d', substr($today, 0, 4), substr($today, 5, 2), $sequence);
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

        $this->sendJson('PUT', $this->path($id), $this->note(['version' => $this->storedVersion('delivery_note', $id)]));
        self::assertResponseStatusCodeSame(Response::HTTP_CONFLICT, 'a validated note is no longer revised');
        self::assertStringNotContainsString('record-changed', (string) $this->client->getResponse()->getContent(), 'fixed, not saved since');
        $this->postJson($this->path($id).'/validate', null);
        self::assertResponseStatusCodeSame(Response::HTTP_CONFLICT, 'a note is numbered once');

        foreach (['2000-01-01T10:00' => 'before its issue day', '2999-01-01T10:00' => 'after now', $today => 'a day without its time', '2026-02-30T10:00' => 'a day that does not exist', '15/09/2026 10:00' => 'not a moment'] as $moment => $case) {
            $this->postJson($this->path($id).'/deliver', ['delivered' => $moment]);
            self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY, $case);
            self::assertStringContainsString('delivered: ', (string) $this->client->getResponse()->getContent(), $case.', named as sent');
            $reason = ['before its issue day' => 'on_or_after', 'after now' => 'after_today'][$case] ?? null;
            if (null !== $reason) {
                self::assertSame($reason, $this->refusalOf('delivered')[0], $case.', said why');
            }
        }
        $before = new \DateTimeImmutable('-1 second');
        $this->postJson($this->path($id).'/deliver', []);
        self::assertResponseStatusCodeSame(Response::HTTP_OK);
        self::assertSame(['delivered', $today], [$this->json()['status'], $this->json()['deliveryDate']]);
        $at = new \DateTimeImmutable($this->stringAt($this->json(), 'deliveredAt'));
        self::assertTrue($at >= $before && $at <= new \DateTimeImmutable(), 'left out, the delivery is stamped now');
        self::assertSame('+00:00', $at->format('P'), 'a moment is answered in UTC');
        $this->postJson($this->path($id).'/cancel', null);
        self::assertResponseStatusCodeSame(Response::HTTP_CONFLICT, 'delivered goods are not cancelled');

        $this->postJson($this->path(), $this->note());
        $this->postJson($this->path($this->stringAt($this->json(), 'id')).'/validate', null);
        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY, 'a note without a line is not validated');
        self::assertSame(['lines_required', []], $this->refusalOf('lines'));

        $second = $this->draftWithALine();
        $this->postJson($this->path($second).'/validate', null);
        self::assertSame($number(2), $this->json()['number'], 'a refused validation gives its number back');
        $this->postJson($this->path($second).'/cancel', null);
        self::assertResponseStatusCodeSame(Response::HTTP_OK);
        self::assertSame(['cancelled', $number(2)], [$this->json()['status'], $this->json()['number']]);

        $this->postJson($this->path(self::ABSENT).'/validate', null);
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
    }

    /** A round recorded at its end: the time is the company's clock, as written on the signed note, and kept in UTC. */
    public function testADeliveryTakesTheTimeItHappenedOnTheCompanysClock(): void
    {
        $this->signedIn(['delivery_note.read', 'delivery_note.write', 'delivery_note.validate']);
        $zone = new \DateTimeZone($this->company->getTimezone());
        $today = new \DateTimeImmutable('now', $zone)->format('Y-m-d');
        $id = $this->draftWithALine();
        $this->postJson($this->path($id).'/validate', null);

        $this->postJson($this->path($id).'/deliver', ['delivered' => $today.'T00:00']);

        self::assertResponseStatusCodeSame(Response::HTTP_OK);
        self::assertSame(
            [$today, new \DateTimeImmutable($today.' 00:00', $zone)->setTimezone(new \DateTimeZone('UTC'))->format(\DATE_ATOM)],
            [$this->json()['deliveryDate'], $this->json()['deliveredAt']],
        );

        // Midnight in Tunis is still yesterday in UTC: a filter on the day reads the company's day.
        $this->getJson($this->path().'?deliveryDate[from]='.$today.'&deliveryDate[to]='.$today);
        self::assertResponseIsSuccessful();
        self::assertSame([$id], array_column($this->jsonList(), 'id'));
    }

    // docs/SPEC.md § 7, 2026-09-21 17:25: the step people want to skip is an action doing both moves, not a setting.
    public function testAValidatorValidatesAndDeliversADraftInOneStep(): void
    {
        $this->signedIn(['delivery_note.read', 'delivery_note.write', 'delivery_note.validate']);
        $today = new \DateTimeImmutable('now', new \DateTimeZone($this->company->getTimezone()))->format('Y-m-d');
        $id = $this->draftWithALine();

        $this->postJson($this->path($id).'/validate-and-deliver', []);

        self::assertResponseStatusCodeSame(Response::HTTP_OK);
        $note = $this->json();
        self::assertSame(['delivered', $today, $today], [$note['status'], $note['issueDate'], $note['deliveryDate']]);
        self::assertMatchesRegularExpression('/^BL-\d{4}-\d{2}-00001$/', $this->stringAt($note, 'number'));
        $actions = $this->em()->getConnection()->fetchFirstColumn("SELECT action FROM audit_log WHERE action IN ('delivery_note.validated', 'delivery_note.delivered') ORDER BY action");
        self::assertSame(['delivery_note.delivered', 'delivery_note.validated'], $actions);

        $this->postJson($this->path($id).'/validate-and-deliver', []);
        self::assertResponseStatusCodeSame(Response::HTTP_CONFLICT, 'a note is numbered once');
        $this->postJson($this->path(), $this->note());
        $this->postJson($this->path($this->stringAt($this->json(), 'id')).'/validate-and-deliver', []);
        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY, 'a note without a line is not validated');
        $this->postJson($this->path(self::ABSENT).'/validate-and-deliver', []);
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
    }

    public function testADeliveryNamingNobodyRecordsNobodyWhileOneSayingNothingKeepsTheDrafts(): void
    {
        $this->signedIn(['delivery_note.read', 'delivery_note.write', 'delivery_note.validate']);
        $named = function (): string {
            $this->postJson($this->path(), $this->note(['lines' => [['productId' => $this->productId, 'quantity' => '1']], 'receivedBy' => ['name' => 'Karim Jaziri']]));
            self::assertResponseStatusCodeSame(Response::HTTP_CREATED);
            $id = $this->stringAt($this->json(), 'id');
            $this->postJson($this->path($id).'/validate', null);
            self::assertResponseIsSuccessful();

            return $id;
        };

        // The field emptied in the dialog is sent as a choice naming nobody: what the screen showed is what is recorded.
        $emptied = $named();
        $this->postJson($this->path($emptied).'/deliver', ['receivedBy' => ['contactId' => null, 'name' => null, 'addToContacts' => false]]);
        self::assertResponseIsSuccessful();
        self::assertSame(['delivered', null], [$this->json()['status'] ?? null, $this->json()['receivedBy'] ?? null]);

        // A body that says nothing of who received (an API client, « Livrer » with no field) keeps whoever the draft named.
        $kept = $named();
        $this->postJson($this->path($kept).'/deliver', []);
        self::assertResponseIsSuccessful();
        self::assertSame(['name' => 'Karim Jaziri', 'contactId' => null], $this->json()['receivedBy'] ?? null);
    }

    public function testWhoReceivedIsNamedOnTheDraftAtTheDeliveryAndPutRightAfterwards(): void
    {
        $this->signedIn(['delivery_note.read', 'delivery_note.write', 'delivery_note.validate']);
        $customer = $this->em()->find(Customer::class, Uuid::fromString($this->customerId)) ?? throw new \LogicException('no customer');
        $contact = Contact::create($customer, new ContactDetails('Ahmed', 'Ben Salah', null, null, null), false, new \DateTimeImmutable());
        $this->em()->persist($contact);
        $this->em()->flush();
        $ahmed = $contact->getId()->toRfc4122();
        $elsewhere = $this->customer('CLI-0002', 'standard');
        $theirs = Contact::create($elsewhere, new ContactDetails('Someone', 'Else', null, null, null), false, new \DateTimeImmutable());
        $this->em()->persist($theirs);
        $this->em()->flush();

        $receivers = function (string $customerId): array {
            $this->getJson($this->companyPath().'/delivery-note-options/customers/'.$customerId.'/receivers');
            self::assertResponseIsSuccessful();

            return array_map(static fn (array $row): array => [$row['contactId'] ?? null, $row['name'] ?? null], $this->jsonList());
        };
        self::assertSame([[$ahmed, 'Ahmed Ben Salah']], $receivers($this->customerId), 'the contacts a note offers, by name');

        $this->postJson($this->path(), $this->note(['lines' => [['productId' => $this->productId, 'quantity' => '1']], 'receivedBy' => ['contactId' => $ahmed]]));
        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);
        $note = $this->json();
        self::assertSame([['name' => 'Ahmed Ben Salah', 'contactId' => $ahmed], 'optional'], [$note['receivedBy'], $note['receiverRule']]);
        // A page of the list names who received but leaves the rule out, read one customer at a time.
        $this->getJson($this->path());
        $row = array_values(array_filter($this->jsonList(), static fn (array $row): bool => ($row['id'] ?? null) === $note['id']))[0] ?? [];
        self::assertSame([['name' => 'Ahmed Ben Salah', 'contactId' => $ahmed], null], [$row['receivedBy'] ?? 'missing', $row['receiverRule'] ?? null]);

        // The contact renamed since, the draft sent back as read keeps the name it copied rather than taking the new one.
        $renamed = $this->em()->find(Contact::class, $contact->getId()) ?? throw new \LogicException('no contact');
        $renamed->revise(new ContactDetails('Ahmed', 'Ben Salem', null, null, null), new \DateTimeImmutable());
        $this->em()->flush();
        $this->sendJson('PUT', $this->path($this->stringAt($note, 'id')), $this->note(['version' => $note['version'], 'lines' => [['productId' => $this->productId, 'quantity' => '1']], 'receivedBy' => ['contactId' => $ahmed]]));
        self::assertResponseIsSuccessful();
        self::assertSame(['name' => 'Ahmed Ben Salah', 'contactId' => $ahmed], $this->json()['receivedBy'] ?? null);

        $this->postJson($this->path($this->stringAt($note, 'id')).'/validate-and-deliver', ['receivedBy' => ['contactId' => $theirs->getId()->toRfc4122()]]);
        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY, 'another customer\'s contact');
        self::assertSame('unknown_choice', $this->refusalOf('receivedBy')[0]);
        $this->postJson($this->path($this->stringAt($note, 'id')).'/validate-and-deliver', []);
        self::assertResponseStatusCodeSame(Response::HTTP_OK, 'naming nobody: whoever the draft names');
        self::assertSame(['name' => 'Ahmed Ben Salah', 'contactId' => $ahmed], $this->json()['receivedBy'] ?? null);

        $this->changeReceiverRule($customer, 'required');
        $validated = $this->draftWithALine();
        $this->postJson($this->path($validated).'/validate', null);
        $this->postJson($this->path($validated).'/deliver', []);
        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        self::assertSame('receiver_required', $this->refusalOf('receivedBy')[0]);
        $this->postJson($this->path($validated).'/deliver', ['receivedBy' => ['name' => ' Sami Trabelsi ', 'addToContacts' => true]]);
        self::assertResponseStatusCodeSame(Response::HTTP_OK);
        $delivered = $this->json();
        $received = $delivered['receivedBy'];
        self::assertIsArray($received);
        // The name added to the contacts is linked to the contact it became.
        self::assertSame(['delivered', 'Sami Trabelsi', true, 'required'], [$delivered['status'], $received['name'] ?? null, \is_string($received['contactId'] ?? null), $delivered['receiverRule']]);
        $added = $this->em()->getConnection()->fetchAllAssociative('SELECT first_name, last_name FROM contact WHERE customer_id = ? ORDER BY last_name', [$this->customerId]);
        self::assertSame([['first_name' => 'Ahmed', 'last_name' => 'Ben Salem'], ['first_name' => 'Sami', 'last_name' => 'Trabelsi']], $added);
        self::assertSame(['Ahmed Ben Salem', 'Sami Trabelsi'], array_column($receivers($this->customerId), 1));

        $correct = ['receivedBy' => ['name' => 'Sami Trabelsy']];
        $this->postJson($this->path($validated).'/receiver', $correct);
        self::assertResponseStatusCodeSame(Response::HTTP_OK);
        self::assertSame(['name' => 'Sami Trabelsy', 'contactId' => null], $this->json()['receivedBy']);
        $this->postJson($this->path($validated).'/receiver', $correct);
        self::assertResponseStatusCodeSame(Response::HTTP_OK, 'sent again, set to the same');
        $corrections = $this->em()->getConnection()->fetchFirstColumn("SELECT changes FROM audit_log WHERE action = 'delivery_note.receiver_corrected'");
        self::assertCount(1, $corrections, 'the same name records nothing');
        self::assertSame(['fields' => ['receivedBy']], json_decode($this->stringOf($corrections[0]), true), 'the journal keeps the field, never the name');
        $this->postJson($this->path($validated).'/receiver', ['receivedBy' => null]);
        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY, 'nobody, where the customer\'s notes must name someone');
        self::assertSame('receiver_required', $this->refusalOf('receivedBy')[0]);

        $draft = $this->draftWithALine();
        $this->postJson($this->path($draft).'/receiver', $correct);
        self::assertResponseStatusCodeSame(Response::HTTP_CONFLICT, 'a draft is put right by a save');

        $this->changeReceiverRule($customer, 'off');
        $this->postJson($this->path(), $this->note(['receivedBy' => ['name' => 'Sami']]));
        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        self::assertSame('receiver_off', $this->refusalOf('receivedBy')[0]);
        $this->getJson($this->path($draft));
        self::assertSame([null, 'off'], [$this->json()['receivedBy'], $this->json()['receiverRule']]);
        $this->postJson($this->path(), $this->note(['receivedBy' => ['name' => ['Sami']]]));
        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY, 'a name is a text');
    }

    public function testValidatingAndDeliveringNeedsBothPermissions(): void
    {
        $this->signedIn(['delivery_note.read', 'delivery_note.write']);
        $id = $this->draftWithALine();
        $this->postJson($this->path($id).'/validate-and-deliver', []);
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND, 'validating needs delivery_note.validate');

        $company = $this->em()->find(Company::class, $this->company->getId()) ?? throw new \LogicException('no company');
        $this->createUser('validator@twes.local', 'password-1234', $company, ['delivery_note.read', 'delivery_note.validate'], 'validator');
        $this->login('validator@twes.local', 'password-1234');
        $this->postJson($this->path($id).'/validate-and-deliver', []);
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND, 'delivering needs delivery_note.write');

        $this->getJson($this->path($id));
        self::assertSame(['draft', null], [$this->json()['status'], $this->json()['number']]);
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

    // docs/SPEC.md § 7, 2026-09-26 22:24: a company's documents carry its logo, as it was the day the document was issued.
    public function testTheCompanysLogoIsPrintedOnItsDeliveryNotes(): void
    {
        $this->signedIn(['delivery_note.read', 'delivery_note.write', 'delivery_note.validate']);
        $id = $this->draftWithALine();

        $this->client->request('GET', $this->path($id).'/pdf');
        self::assertStringNotContainsString('class="logo"', (string) $this->client->getResponse()->getContent(), 'no logo, no picture');

        static::getContainer()->get(CompanyLogo::class)->set($this->em()->find(Company::class, $this->company->getId()) ?? throw new \LogicException('no company'), 'logo.png', (string) base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==', true), null);
        $this->client->request('GET', $this->path($id).'/pdf');
        self::assertStringContainsString('<img class="logo" src="data:image/png;base64,', (string) $this->client->getResponse()->getContent());
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

    public function testANoteCancelledAfterTheCompanyMovedStillNamesTheSellerAsItWasValidated(): void
    {
        $this->signedIn(['delivery_note.read', 'delivery_note.write', 'delivery_note.validate']);
        $id = $this->draftWithALine();
        $this->postJson($this->path($id).'/validate', null);
        self::assertResponseIsSuccessful();
        $company = $this->em()->find(Company::class, $this->company->getId());
        self::assertNotNull($company);
        $company->reviseProfile(new CompanyProfile(legalName: 'Acme Holding', addressLine1: '99 avenue Nouvelle', city: 'Sousse'));
        $this->em()->flush();
        $this->postJson($this->path($id).'/cancel', null);
        self::assertResponseIsSuccessful();

        $this->client->request('GET', $this->path($id).'/pdf');

        self::assertResponseIsSuccessful();
        $cancelled = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('<div class="company-name">Acme</div>', $cancelled);
        self::assertStringNotContainsString('Acme Holding', $cancelled);
        self::assertStringNotContainsString('avenue Nouvelle', $cancelled);
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
        $this->sendJson('PUT', $this->path($mine), $this->note(['version' => 1]));
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
        $this->getJson($this->companyPath().'/delivery-note-status-counts');
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND, 'the chips\' counts are the module\'s too');

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

    public function testAPageOfTheListCostsTheSameStatementsWhateverTheRowsItHolds(): void
    {
        $this->signedIn(['delivery_note.read', 'delivery_note.write', 'delivery_note.validate', 'invoice.read', 'invoice.write']);
        // A product per note, so the identity map cannot hide a read per row; made before the first request, which
        // reboots the kernel and leaves the company detached.
        $products = [];
        foreach (range(1, 6) as $n) {
            $product = Product::create($this->company, 'ART-1'.$n, new ProductDetails('Article '.$n, null, ProductKind::Goods, '100'), $this->unit('C62'), null, [$this->tax('TVA19')->getId()], new \DateTimeImmutable());
            $this->em()->persist($product);
            $products[$n] = $product->getId()->toRfc4122();
        }
        $this->em()->flush();
        foreach ($products as $n => $productId) {
            $this->postJson($this->path(), $this->note(['lines' => [['productId' => $productId, 'quantity' => '2'], ['productId' => $this->productId, 'quantity' => '1']]]));
            self::assertResponseStatusCodeSame(Response::HTTP_CREATED);
            if ($n > 3) {
                $note = $this->stringAt($this->json(), 'id');
                $this->postJson($this->path($note).'/validate', null);
                self::assertResponseStatusCodeSame(Response::HTTP_OK);
                // An invoice of its own per note, so reading each invoice's lines apart would show as a read per row.
                $this->postJson($this->companyPath().'/invoices/from-delivery-notes', ['deliveryNoteIds' => [$note]]);
                self::assertResponseStatusCodeSame(Response::HTTP_CREATED);
            }
        }
        $this->em()->clear();

        $statements = [1 => $this->statementsForAPageOf($this->path(), 1), 6 => $this->statementsForAPageOf($this->path(), 6)];

        self::assertSame($statements[1], $statements[6], 'six rows cost what one does (audit PF-07)');
        // Measured 11 (15 for one row, 35 for six before), the session and the company's checks included, plus two: the read
        // of whether the person ended this session (the connected devices screen), made on every signed-in request, and the
        // invoices carrying the page's notes, which each note links to, read once for the page with their lines; plus
        // three for whether the person may open those invoices: the invoices module's state, their role and the
        // company's standing, read once for the page.
        self::assertLessThanOrEqual(16, $statements[6]);
    }

    private function changeReceiverRule(Customer $customer, string $rule): void
    {
        $company = $this->em()->find(Company::class, $this->company->getId()) ?? throw new \LogicException('no company');
        static::getContainer()->get(ChangeSettings::class)->change(new SettingContext($company, customerId: $customer->getId()), 'delivery_note.receiver', SettingLevel::Customer, $rule, null);
    }

    private function stringOf(mixed $value): string
    {
        self::assertIsString($value);

        return $value;
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

    private function unit(string $code): \App\Fiscal\Domain\Model\Unit
    {
        $unit = static::getContainer()->get(UnitRepository::class)->ofCodeInCompany($code, $this->company->getId());
        self::assertNotNull($unit);

        return $unit;
    }

    private function unitId(string $code): string
    {
        return $this->unit($code)->getId()->toRfc4122();
    }

    private function tax(string $code): \App\Fiscal\Domain\Model\TaxComponent
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

    public function testTheListIsAPageSearchedNarrowedAndSortedByTheApi(): void
    {
        $this->signedIn(['delivery_note.read', 'delivery_note.write', 'delivery_note.validate']);
        $other = $this->customer('CLI-0002', 'standard')->getId()->toRfc4122();

        $validated = $this->draftWithALine();
        $this->postJson($this->path($validated).'/validate', null);
        self::assertResponseIsSuccessful();
        $number = $this->stringAt($this->json(), 'number');
        // A draft carries no number and no customer snapshot: it is the row the searchable text cannot cover by name.
        $this->postJson($this->path(), $this->note([
            'customerId' => $other,
            'customerReference' => 'BC-7788',
            'deliveryDate' => '2026-09-20',
            'lines' => [['productId' => $this->productId, 'quantity' => '1']],
        ]));
        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);

        $this->getJson($this->path());
        self::assertCount(2, $this->jsonList());
        self::assertSame(2, $this->jsonPage()['totalItems']);

        $this->getJson($this->path().'?itemsPerPage=1');
        self::assertCount(1, $this->jsonList());
        self::assertSame(2, $this->jsonPage()['totalItems']);

        $zone = new \DateTimeZone($this->company->getTimezone());
        $today = new \DateTimeImmutable('now', $zone)->format('Y-m-d');
        $tomorrow = new \DateTimeImmutable('tomorrow', $zone)->format('Y-m-d');
        $yesterday = new \DateTimeImmutable('yesterday', $zone)->format('Y-m-d');

        foreach ([
            // Combined (docs/SPEC.md § 7, 2026-10-06): the values of one filter are OR'd, different filters AND'd.
            'status[]=draft&status[]=validated' => [self::DRAFT, $number],
            'status[]=draft&status[]=delivered' => [self::DRAFT],
            'status[]=draft&status[]=validated&customerId[]='.$other => [self::DRAFT],
            'customerId[]='.$other.'&customerId[]='.$this->customerId => [self::DRAFT, $number],
            // A draft has no issue day, so any end of that interval leaves it out.
            'issueDate[from]='.$today.'&issueDate[to]='.$today => [$number],
            'issueDate[from]='.$tomorrow => [],
            'issueDate[to]='.$yesterday => [],
            // The validated note names no delivery day, so any end of that interval leaves it out.
            'deliveryDate[from]=2026-09-20&deliveryDate[to]=2026-09-20' => [self::DRAFT],
            'deliveryDate[to]=2026-09-19' => [],
            'deliveryDate[from]=2026-09-21' => [],
            'status[]=draft&issueDate[from]='.$today => [],
            'q='.$number => [$number],
            'q='.strtolower($number) => [$number],
            'q=carthage' => [$number],
            'q=BC-7788' => [self::DRAFT],
            'q=zzzz' => [],
            'status=draft' => [self::DRAFT],
            'status=validated' => [$number],
            'customerId='.$other => [self::DRAFT],
            'order[number]=asc' => [$number, self::DRAFT],
        ] as $query => $numbers) {
            $this->getJson($this->path().'?'.$query);
            self::assertResponseIsSuccessful($query);
            // A draft's number is null, so it is named rather than read as an empty column.
            $shown = array_map(
                static fn (array $row): string => \is_string($row['number'] ?? null) ? $row['number'] : self::DRAFT,
                $this->jsonList(),
            );
            self::assertSame($numbers, $shown, $query);
        }

        foreach (['status=delivered-ish', 'status[]=draft&status[]=delivered-ish', 'customerId=not-an-id', 'customerId[]=nope', 'issueDate[from]=yesterday', 'deliveryDate[to]=2026-02-30'] as $refused) {
            $this->getJson($this->path().'?'.$refused);
            self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY, $refused);
        }
    }

    /** docs/SPEC.md § 7, 2026-09-26: each status chip of « Bons de livraison » says how many it would list. */
    public function testEachStatusChipCountsWhatItsFilterWouldList(): void
    {
        $this->signedIn(['delivery_note.read', 'delivery_note.write', 'delivery_note.validate']);
        $other = $this->customer('CLI-0002', 'standard')->getId()->toRfc4122();
        $this->postJson($this->path($this->draftWithALine()).'/validate', null);
        self::assertResponseIsSuccessful();
        $cancelled = $this->draftWithALine();
        $this->postJson($this->path($cancelled).'/validate', null);
        $this->postJson($this->path($cancelled).'/cancel', null);
        self::assertResponseIsSuccessful();
        $this->postJson($this->path(), $this->note([
            'customerId' => $other,
            'customerReference' => 'BC-7788',
            'lines' => [['productId' => $this->productId, 'quantity' => '1']],
        ]));
        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);

        $this->getJson($this->companyPath().'/delivery-note-status-counts');
        self::assertResponseIsSuccessful();
        self::assertSame(3, $this->json()['all']);
        self::assertSame(['draft' => 1, 'validated' => 1, 'delivered' => 0, 'cancelled' => 1, 'invoiced' => 0], $this->json()['statuses']);

        $today = new \DateTimeImmutable('now', new \DateTimeZone($this->company->getTimezone()))->format('Y-m-d');
        foreach (['', 'q=carthage', 'q=BC-7788', 'customerId='.$other, 'status=draft', 'customerId[]='.$other.'&customerId[]='.$this->customerId, 'issueDate[from]='.$today, 'deliveryDate[to]=2026-09-19'] as $filters) {
            $this->getJson($this->companyPath().'/delivery-note-status-counts?'.$filters);
            self::assertResponseIsSuccessful($filters);
            $counts = $this->json();
            $statuses = $counts['statuses'];
            self::assertIsArray($statuses);
            // The chips narrow by status themselves: a status the list carries is left aside.
            $listed = preg_replace('/(^|&)status=[a-z]+/', '', $filters);
            $this->getJson($this->path().'?'.$listed);
            self::assertSame($this->jsonPage()['totalItems'], $counts['all'], $filters);
            foreach (['draft', 'validated', 'delivered', 'cancelled', 'invoiced'] as $status) {
                $this->getJson($this->path().'?'.ltrim($listed.'&status='.$status, '&'));
                self::assertSame($this->jsonPage()['totalItems'], $statuses[$status], $filters.' '.$status);
            }
        }
    }

    public function testStatusCountsAreAReadersOnly(): void
    {
        $this->signedIn(['customer.read']);
        $this->getJson($this->companyPath().'/delivery-note-status-counts');
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
    }

    public function testStatusCountsOfAnotherCompanyAreNotFound(): void
    {
        $this->signedIn(['delivery_note.read']);
        $globex = $this->createCompany('Globex');
        $this->getJson('/api/companies/'.$globex->getId()->toRfc4122().'/delivery-note-status-counts');
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
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
