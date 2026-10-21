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
use App\Fiscal\Domain\Repository\UnitRepository;
use App\Module\Customers\Domain\Model\Customer;
use App\Module\Vendors\Domain\Model\Vendor;
use App\Module\Vendors\Domain\ValueObject\VendorProfile;
use App\Tenancy\Domain\Model\Company;
use Symfony\Component\HttpFoundation\Response;

/**
 * A quantity and a price each within their own bounds can still come to more than an amount column holds,
 * 99,999,999,999.999: such a line is refused on the line when the draft is saved, never a 500 from the database
 * when it is issued.
 */
final class DocumentFigureBoundsTest extends ApiTestCase
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
        $customer = Customer::create($this->company, 'CLI-0001', self::aTunisianBusiness('Carthage Conseil'), null, $regime, [], new \DateTimeImmutable());
        $this->em()->persist($customer);
        $this->em()->flush();
        $this->customerId = $customer->getId()->toRfc4122();
        $this->createUser('sales@twes.local', 'password-1234', $this->company, ['invoice.read', 'invoice.write', 'invoice.issue', 'quote.read', 'quote.write', 'delivery_note.read', 'delivery_note.write', 'expense.read', 'expense.write'], 'member');
        $this->login('sales@twes.local', 'password-1234');
    }

    public function testALineComingToMoreThanAnAmountHoldsIsRefusedOnTheLineWhenTheDraftIsSaved(): void
    {
        $this->postJson($this->path(), $this->draftOf([['Conseil', '1', '100'], ['Licence', '99999999999', '9999999999']]));

        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        self::assertStringStartsWith('lines[1].quantity: ', $this->stringAt($this->json(), 'detail'));
    }

    public function testLinesEachWithinBoundsButTogetherBeyondThemAreRefusedOnTheLines(): void
    {
        $this->postJson($this->path(), $this->draftOf([['Licence', '6000000', '10000'], ['Licence', '6000000', '10000']]));

        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        self::assertStringStartsWith('lines: ', $this->stringAt($this->json(), 'detail'));
    }

    public function testTheLargestAmountAColumnHoldsIsIssued(): void
    {
        $this->postJson($this->path(), $this->draftOf([['Licence', '10', '9999999999.9999']]));
        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);

        $this->postJson($this->path($this->stringAt($this->json(), 'id')).'/issue', null);

        self::assertResponseIsSuccessful();
        self::assertSame('99999999999.999', $this->stringAt($this->json(), 'amountDue'));
    }

    /** Every document totalled by the same calculator names its own line: a quote's and a delivery note's quantity, a bill's amount. */
    public function testEveryOtherDocumentNamesItsOwnLine(): void
    {
        $line = $this->draftOf([['Conseil', '1', '100'], ['Licence', '99999999999', '9999999999']]);
        foreach (['quotes', 'delivery-notes'] as $documents) {
            $this->postJson($this->companyPath().'/'.$documents, ['customerId' => $this->customerId, 'lines' => $line['lines']]);

            self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY, $documents);
            self::assertStringStartsWith('lines[1].quantity: ', $this->stringAt($this->json(), 'detail'), $documents);
        }

        $company = $this->em()->find(Company::class, $this->company->getId());
        self::assertNotNull($company);
        $vendor = Vendor::create($company, 'FRN-0001', new VendorProfile('Sotumag', paymentTermsDays: 30), new \DateTimeImmutable());
        $this->em()->persist($vendor);
        $this->em()->flush();
        $tax = $this->em()->getConnection()->fetchOne('SELECT id FROM tax_component WHERE company_id = ? AND code = ?', [$this->company->getId()->toRfc4122(), 'TVA19']);
        self::assertIsString($tax);
        $this->postJson($this->companyPath().'/expenses', [
            'date' => '2026-09-10',
            'reference' => null,
            'description' => 'Machine',
            'vendorId' => $vendor->getId()->toRfc4122(),
            'categoryId' => null,
            // Within what an amount holds, but not once its VAT is added.
            'lines' => [['amountNet' => '10', 'taxComponentIds' => [$tax]], ['amountNet' => '99999999999', 'taxComponentIds' => [$tax]]],
            'notes' => null,
        ]);

        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY, 'expenses');
        self::assertStringStartsWith('lines[1].amountNet: ', $this->stringAt($this->json(), 'detail'));
    }

    /**
     * @param list<array{string, string, string}> $lines description, quantity, net unit price
     *
     * @return array<string, mixed>
     */
    private function draftOf(array $lines): array
    {
        $unit = static::getContainer()->get(UnitRepository::class)->ofCodeInCompany('C62', $this->company->getId());
        self::assertNotNull($unit);

        return [
            'customerId' => $this->customerId,
            'establishmentId' => null,
            'supplyDate' => null,
            'paymentTermsDays' => null,
            'customerReference' => null,
            'notesPrinted' => null,
            'notesInternal' => null,
            'discountAmount' => null,
            'documentTaxComponentIds' => [],
            'lines' => array_map(static fn (array $line): array => ['description' => $line[0], 'quantity' => $line[1], 'unitId' => $unit->getId()->toRfc4122(), 'unitPriceNet' => $line[2], 'taxComponentIds' => []], $lines),
        ];
    }

    private function companyPath(): string
    {
        return '/api/companies/'.$this->company->getId()->toRfc4122();
    }

    private function path(?string $id = null): string
    {
        return $this->companyPath().'/invoices'.(null === $id ? '' : '/'.$id);
    }
}
