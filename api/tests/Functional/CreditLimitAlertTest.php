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
 * Alerts, not reports (docs/SPEC.md § 7): an invoice that takes a customer past their credit limit tells the people who
 * issue invoices, once, as it is issued.
 */
final class CreditLimitAlertTest extends ApiTestCase
{
    private Company $company;
    private string $customerId;
    private string $issuerId;

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
        $this->issuerId = $this->createUser('sales@twes.local', 'password-1234', $this->company, ['customer.read', 'invoice.read', 'invoice.write', 'invoice.issue'], 'sales')->getId()->toRfc4122();
        $this->createUser('clerk@twes.local', 'password-1234', $this->company, ['invoice.read', 'invoice.write'], 'clerk');
        $this->login('sales@twes.local', 'password-1234');
    }

    public function testAnInvoiceThatTakesTheAccountPastTheLimitTellsTheIssuersOnceAndOnlyWhenItCrossesIt(): void
    {
        $this->setLimit('1000');
        $this->issue('600');
        self::assertSame([], $this->told(), 'under the limit');

        $this->issue('600');
        $told = $this->told();
        self::assertSame([$this->issuerId], $this->recipients($told), 'those who issue are told, whoever merely writes is not');
        $raw = $told[0]['payload'];
        self::assertIsString($raw);
        $payload = json_decode($raw, true);
        self::assertIsArray($payload);
        self::assertSame([$this->customerId, '1200.000', '1000.000'], [$payload['customer_id'], $payload['owed'], $payload['limit']]);

        $this->issue('100');
        self::assertCount(1, $this->told(), 'already past it: no second alert');
    }

    public function testNoLimitMeansNothingToCross(): void
    {
        $this->issue('5000');

        self::assertSame([], $this->told());
    }

    private function setLimit(string $amount): void
    {
        $company = $this->em()->find(Company::class, $this->company->getId()) ?? throw new \LogicException('no company');
        static::getContainer()->get(ChangeSettings::class)->change(new SettingContext($company), 'credit.limit', SettingLevel::Company, $amount, null);
    }

    /**
     * @param list<array<string, mixed>> $rows
     *
     * @return list<string>
     */
    private function recipients(array $rows): array
    {
        $ids = [];
        foreach ($rows as $row) {
            self::assertIsString($row['recipient_id']);
            $ids[] = $row['recipient_id'];
        }

        return $ids;
    }

    /** @return list<array<string, mixed>> */
    private function told(): array
    {
        return $this->em()->getConnection()->fetchAllAssociative("SELECT recipient_id, payload FROM inbox_item WHERE type = 'invoice.credit_limit_passed'");
    }

    private function issue(string $net): void
    {
        $this->postJson('/api/companies/'.$this->company->getId()->toRfc4122().'/invoices', [
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
        $this->postJson('/api/companies/'.$this->company->getId()->toRfc4122().'/invoices/'.$id.'/issue', null);
        self::assertResponseStatusCodeSame(Response::HTTP_OK);
    }

    private function unitId(): string
    {
        $unit = static::getContainer()->get(UnitRepository::class)->ofCodeInCompany('C62', $this->company->getId());
        self::assertNotNull($unit);

        return $unit->getId()->toRfc4122();
    }
}
