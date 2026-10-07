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
use App\Fiscal\Domain\UnitRepository;
use App\Module\Customers\Domain\Customer;
use App\Module\Customers\Domain\CustomerKind;
use App\Module\Customers\Domain\CustomerProfile;
use App\Settings\Application\ChangeSettings;
use App\Settings\Application\SettingContext;
use App\Settings\Domain\SettingLevel;
use App\Tenancy\Domain\Company;
use Symfony\Component\HttpFoundation\Response;

/**
 * What a delivery would do to the customer's credit limit: the account as it stands plus the note's own total, against
 * the limit that applies. It warns, it never refuses.
 */
final class DeliveryNoteCreditTest extends ApiTestCase
{
    private Company $company;
    private string $customerId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->company = $this->createCompany('Acme');
        static::getContainer()->get(ProvisionCompany::class)->handle($this->company);
        static::getContainer()->get(SyncCustomerTaxRegimes::class)->handle();
        $regime = static::getContainer()->get(CustomerTaxRegimeRepository::class)->ofPresetAndCode('TN', 'standard');
        self::assertNotNull($regime);
        $customer = Customer::create($this->company, 'CLI-0001', new CustomerProfile(CustomerKind::Company, 'Carthage Conseil'), null, $regime, [], new \DateTimeImmutable());
        $this->em()->persist($customer);
        $this->em()->flush();
        $this->customerId = $customer->getId()->toRfc4122();
        $this->createUser('sales@twes.local', 'password-1234', $this->company, ['delivery_note.read', 'delivery_note.write', 'delivery_note.validate', 'invoice.read', 'invoice.write', 'invoice.issue', 'payment.write', 'customer.read'], 'member');
        $this->login('sales@twes.local', 'password-1234');
        self::assertResponseIsSuccessful();
    }

    public function testWithNoLimitNothingIsOverWhateverIsOwed(): void
    {
        $this->issueInvoice('1000');
        $note = $this->draftNote('300');

        $credit = $this->credit($note);

        self::assertSame(['0.000', '1000.000', '300.000', '1300.000', false], [$credit['limit'], $credit['owed'], $credit['noteTotal'], $credit['afterDelivery'], $credit['over']]);
    }

    public function testADeliveryThatTakesTheAccountPastTheLimitIsFlaggedAndOneThatKeepsItUnderIsNot(): void
    {
        $this->setLimit('1200');
        $this->issueInvoice('1000');

        self::assertTrue($this->credit($this->draftNote('300'))['over'], '1000 owed and 300 delivered passes 1200');
        self::assertFalse($this->credit($this->draftNote('200'))['over'], 'exactly the limit is not past it');
    }

    public function testMoneyTheCustomerHoldsOnAccountCountsAgainstWhatTheyOwe(): void
    {
        $this->setLimit('1200');
        $this->issueInvoice('1000');
        $today = new \DateTimeImmutable('now', new \DateTimeZone($this->company->getTimezone()))->format('Y-m-d');
        // 100 left on account: an invoice paid in full and that much more paid beyond it, owing nothing more.
        $paid = $this->issueInvoice('10');
        $this->getJson($this->companyPath().'/invoices/'.$paid);
        $this->postJson($this->companyPath().'/invoices/'.$paid.'/payments', ['date' => $today, 'amount' => $this->stringAt($this->json(), 'amountDue'), 'method' => 'transfer']);
        self::assertResponseStatusCodeSame(201);
        $this->postJson($this->companyPath().'/invoices/'.$paid.'/overpayments', ['amount' => '100', 'date' => $today]);
        self::assertResponseStatusCodeSame(204);

        // Audit 2026-10-06, E-10: 1000 owed less 100 left on account, and 300 delivered, is exactly the limit.
        $credit = $this->credit($this->draftNote('300'));
        self::assertSame(['900.000', '1200.000', false], [$credit['owed'], $credit['afterDelivery'], $credit['over']]);
    }

    public function testADeliveredOrCancelledNoteIsNeverFlaggedBecauseThereIsNothingLeftToWarnAbout(): void
    {
        $this->setLimit('1');
        $note = $this->draftNote('300');
        self::assertTrue($this->credit($note)['over']);

        $this->postJson($this->path($note).'/cancel', null);
        self::assertResponseIsSuccessful();
        self::assertFalse($this->credit($note)['over']);
    }

    public function testANoteThatIsNotThereIsNotFoundAndOneThatIsIsRead(): void
    {
        $note = $this->draftNote('300');
        $this->getJson($this->path(self::ABSENT).'/credit');
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);

        $this->getJson($this->path($note).'/credit');
        self::assertResponseIsSuccessful();
    }

    /** Audit 2026-10-06, D-5 / G-15: another company's real note, reached through this company's path, is not there. */
    public function testAnotherCompanysNoteIsNotFound(): void
    {
        [$acme, $acmeCustomer] = [$this->company, $this->customerId];
        $this->company = $this->createCompany('Globex');
        static::getContainer()->get(ProvisionCompany::class)->handle($this->company);
        $regime = static::getContainer()->get(CustomerTaxRegimeRepository::class)->ofPresetAndCode('TN', 'standard');
        self::assertNotNull($regime);
        $customer = Customer::create($this->company, 'CLI-0001', new CustomerProfile(CustomerKind::Company, 'Globex Client'), null, $regime, [], new \DateTimeImmutable());
        $this->em()->persist($customer);
        $this->em()->flush();
        $this->customerId = $customer->getId()->toRfc4122();
        $this->createUser('globex@twes.local', 'password-1234', $this->company, ['delivery_note.read', 'delivery_note.write'], 'member');
        $this->login('globex@twes.local', 'password-1234');
        $theirs = $this->draftNote('300');
        [$this->company, $this->customerId] = [$acme, $acmeCustomer];
        $this->login('sales@twes.local', 'password-1234');

        $this->getJson($this->path($theirs).'/credit');
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
    }

    /** Audit 2026-10-06, A-F4: the part of a note an issued invoice already took is owed, so it is not counted again. */
    public function testAPartlyInvoicedNoteCountsOnlyWhatIsLeftToInvoice(): void
    {
        $this->setLimit('1000');
        $note = $this->draftNote('100', '8');
        $this->postJson($this->path($note).'/validate', null);
        self::assertResponseIsSuccessful();
        $line = $this->em()->getConnection()->fetchOne('SELECT id FROM delivery_note_line WHERE delivery_note_id = ?', [$note]);
        self::assertIsString($line);
        $this->postJson($this->companyPath().'/invoices/from-delivery-notes', ['deliveryNoteIds' => [$note], 'quantities' => [$line => '6']]);
        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);
        $this->postJson($this->companyPath().'/invoices/'.$this->stringAt($this->json(), 'id').'/issue', null);
        self::assertResponseStatusCodeSame(Response::HTTP_OK);

        $credit = $this->credit($note);

        self::assertSame(['601.000', '200.000', '801.000', false], [$credit['owed'], $credit['noteTotal'], $credit['afterDelivery'], $credit['over']], '600 invoiced and owed with its 1 of stamp duty, 200 left to deliver: 801, under 1000');
    }

    /** Audit 2026-10-06, A-F5: the warning shows the customer's account, which takes the statement's two rights. */
    public function testTheCustomersAccountTakesTheRightToReadTheCustomerAndItsInvoices(): void
    {
        // Created before any request: the kernel reboots between requests and would leave the company detached.
        $this->createUser('driver@twes.local', 'password-1234', $this->company, ['delivery_note.read', 'customer.read'], 'driver');
        $this->createUser('biller@twes.local', 'password-1234', $this->company, ['delivery_note.read', 'invoice.read'], 'biller');
        $note = $this->draftNote('300');

        foreach (['driver@twes.local' => 'invoice.read', 'biller@twes.local' => 'customer.read'] as $email => $missing) {
            $this->login($email, 'password-1234');
            $this->getJson($this->path($note).'/credit');
            self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND, "without $missing");
            $this->getJson($this->path($note));
            self::assertResponseIsSuccessful('the note itself is still theirs to read');
        }
    }

    /** Audit 2026-10-06, A-F7: a note the calculator refuses says so, it does not fail. */
    public function testANoteThatCannotBeTotalledIsRefusedNotFailed(): void
    {
        $note = $this->draftNote('300');
        $this->em()->getConnection()->executeStatement('UPDATE delivery_note_line SET unit_price_net = -1 WHERE delivery_note_id = :id', ['id' => $note]);

        $this->getJson($this->path($note).'/credit');

        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
    }

    private const string ABSENT = '0192c3a4-0000-7000-8000-000000000000';

    /** @return array<string, mixed> */
    private function credit(string $noteId): array
    {
        $this->getJson($this->path($noteId).'/credit');
        self::assertResponseIsSuccessful();

        return $this->json();
    }

    /** A draft delivering one line of $quantity priced at $net each, no tax; its id. */
    private function draftNote(string $net, string $quantity = '1'): string
    {
        $this->postJson($this->path(), [
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
            'lines' => [['description' => 'Livraison', 'quantity' => $quantity, 'unitId' => $this->unitId(), 'unitPriceNet' => $net, 'taxComponentIds' => []]],
        ]);
        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);

        return $this->stringAt($this->json(), 'id');
    }

    private function issueInvoice(string $net): string
    {
        $this->postJson($this->companyPath().'/invoices', [
            'customerId' => $this->customerId,
            'establishmentId' => null,
            'supplyDate' => null,
            'paymentTermsDays' => null,
            'customerReference' => null,
            'notesPrinted' => null,
            'notesInternal' => null,
            'discountAmount' => null,
            'documentTaxComponentIds' => [],
            'lines' => [['description' => 'Prestation', 'quantity' => '1', 'unitId' => $this->unitId(), 'unitPriceNet' => $net, 'taxComponentIds' => []]],
        ]);
        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);
        $id = $this->stringAt($this->json(), 'id');
        $this->postJson($this->companyPath().'/invoices/'.$id.'/issue', null);
        self::assertResponseStatusCodeSame(Response::HTTP_OK);

        return $id;
    }

    /** The kernel reboots between requests: the company and the service are found again each time. */
    private function setLimit(string $amount): void
    {
        $company = $this->em()->find(Company::class, $this->company->getId()) ?? throw new \LogicException('no company');
        static::getContainer()->get(ChangeSettings::class)->change(new SettingContext($company), 'credit.limit', SettingLevel::Company, $amount, null);
    }

    private function unitId(): string
    {
        $unit = static::getContainer()->get(UnitRepository::class)->ofCodeInCompany('C62', $this->company->getId());
        self::assertNotNull($unit);

        return $unit->getId()->toRfc4122();
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
