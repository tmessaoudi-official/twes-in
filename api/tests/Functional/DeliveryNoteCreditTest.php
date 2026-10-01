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
        $this->createUser('sales@twes.local', 'password-1234', $this->company, ['delivery_note.read', 'delivery_note.write', 'delivery_note.validate', 'invoice.read', 'invoice.write', 'invoice.issue'], 'member');
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

    private const string ABSENT = '0192c3a4-0000-7000-8000-000000000000';

    /** @return array<string, mixed> */
    private function credit(string $noteId): array
    {
        $this->getJson($this->path($noteId).'/credit');
        self::assertResponseIsSuccessful();

        return $this->json();
    }

    /** A draft delivering one line priced at $net, no tax; its id. */
    private function draftNote(string $net): string
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
            'lines' => [['description' => 'Livraison', 'quantity' => '1', 'unitId' => $this->unitId(), 'unitPriceNet' => $net, 'taxComponentIds' => []]],
        ]);
        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);

        return $this->stringAt($this->json(), 'id');
    }

    private function issueInvoice(string $net): void
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
        $this->postJson($this->companyPath().'/invoices/'.$this->stringAt($this->json(), 'id').'/issue', null);
        self::assertResponseStatusCodeSame(Response::HTTP_OK);
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
