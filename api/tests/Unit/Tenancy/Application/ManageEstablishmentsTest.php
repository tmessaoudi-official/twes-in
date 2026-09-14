<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Unit\Tenancy\Application;

use App\Fiscal\Application\Company\ProvisionCompany;
use App\Tenancy\Application\Establishment\EstablishmentCodeTaken;
use App\Tenancy\Application\Establishment\EstablishmentDetails;
use App\Tenancy\Application\Establishment\EstablishmentNotFound;
use App\Tenancy\Application\Establishment\ManageEstablishments;
use App\Tenancy\Domain\Company;
use App\Tenancy\Domain\Establishment;
use App\Tenancy\Domain\InvalidEstablishment;
use App\Tenancy\Domain\NumberingSeries;
use App\Tests\Support\InMemoryAuditTrail;
use App\Tests\Support\InMemoryEstablishments;
use App\Tests\Support\InMemoryNumberingSeries;
use App\Tests\Support\InMemoryTaxComponents;
use App\Tests\Support\InMemoryUnits;
use App\Tests\Support\ShippedFiscalPresets;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

final class ManageEstablishmentsTest extends TestCase
{
    private InMemoryEstablishments $establishments;
    private InMemoryNumberingSeries $series;
    private InMemoryAuditTrail $audit;
    private ManageEstablishments $manage;
    private ProvisionCompany $provision;
    private Company $company;

    protected function setUp(): void
    {
        $clock = new MockClock('2026-09-13 10:00:00');
        $this->establishments = new InMemoryEstablishments();
        $this->series = new InMemoryNumberingSeries();
        $this->audit = new InMemoryAuditTrail();
        $this->provision = new ProvisionCompany(ShippedFiscalPresets::presets(), new InMemoryTaxComponents(), new InMemoryUnits(), $this->establishments, $this->series, ShippedFiscalPresets::scales(), $clock);
        $this->manage = new ManageEstablishments($this->establishments, $this->series, ShippedFiscalPresets::presets(), $this->audit, $clock);
        $this->company = new Company('Acme', 'TN', 'TND', 'fr', 'Africa/Tunis');
        $this->provision->handle($this->company);
    }

    public function testACodeIsShapedTheWayTheCompanysPresetSays(): void
    {
        self::assertSame('^[0-9]{3}$', $this->manage->codePattern($this->company));

        try {
            $this->manage->create($this->company, self::details('12', 'Agence'), null);
            self::fail('A two-digit code was accepted in Tunisia.');
        } catch (InvalidEstablishment $refused) {
            self::assertSame('code', $refused->field);
        }
        self::assertCount(1, $this->manage->list($this->company));
    }

    public function testANewEstablishmentNumbersEachDocumentTheWayTheDefaultOneDoes(): void
    {
        $default = $this->manage->list($this->company)[0];
        $invoices = $this->seriesOf($default, 'invoice');
        $invoices->revise(new \App\Tenancy\Domain\NumberFormat('F{EST}-{SEQ:4}'), $invoices->getResetPeriod(), 40, new \DateTimeImmutable());

        $sfax = $this->manage->create($this->company, self::details('001', 'Agence de Sfax', city: 'Sfax'), null);

        self::assertFalse($sfax->isDefault());
        self::assertSame('Sfax', $sfax->getCity());
        self::assertSame(['credit_note', 'delivery_note', 'invoice'], array_map(static fn (NumberingSeries $s): string => $s->getDocumentType(), $this->seriesOfEstablishment($sfax)));
        $copy = $this->seriesOf($sfax, 'invoice');
        self::assertSame('F{EST}-{SEQ:4}', $copy->getFormat());
        self::assertSame(1, $copy->getNextNumber());
        self::assertTrue($copy->isDefault());
        self::assertSame('F001-0001', $copy->preview(new \DateTimeImmutable('2026-09-13')));
        self::assertSame('establishment.created', $this->audit->entries[0]->action);
    }

    public function testTwoEstablishmentsOfOneCompanyCannotShareACode(): void
    {
        $this->expectException(EstablishmentCodeTaken::class);
        $this->manage->create($this->company, self::details('000', 'Again'), null);
    }

    public function testACodeCannotBeRevisedIntoAnotherEstablishmentsCode(): void
    {
        $sfax = $this->manage->create($this->company, self::details('001', 'Agence de Sfax'), null);

        $this->expectException(EstablishmentCodeTaken::class);
        $this->manage->revise($this->company, $sfax->getId(), self::details('000', 'Agence de Sfax'), null);
    }

    public function testAnotherEstablishmentBecomesTheDefaultInItsPredecessorsPlace(): void
    {
        $head = $this->manage->list($this->company)[0];
        $sfax = $this->manage->create($this->company, self::details('001', 'Agence de Sfax'), null);

        $this->manage->revise($this->company, $sfax->getId(), self::details('001', 'Agence de Sfax', isDefault: true), null);

        self::assertTrue($sfax->isDefault());
        self::assertFalse($head->isDefault());
    }

    public function testACodeFreezesOnceADocumentCarriesANumberFromTheEstablishment(): void
    {
        $head = $this->manage->list($this->company)[0];
        self::assertFalse($this->manage->isCodeLocked($this->company, $head));

        $this->seriesOf($head, 'delivery_note')->allocate(new \DateTimeImmutable('2026-09-13'), new \DateTimeImmutable());

        self::assertTrue($this->manage->isCodeLocked($this->company, $head));
        try {
            $this->manage->revise($this->company, $head->getId(), self::details('002', 'Acme', isDefault: true), null);
            self::fail('The code printed on a numbered document was changed.');
        } catch (InvalidEstablishment $refused) {
            self::assertSame('code', $refused->field);
        }
        self::assertSame('000', $head->getCode());

        $this->manage->revise($this->company, $head->getId(), self::details('000', 'Siège social', isDefault: true), null);
        self::assertSame('Siège social', $head->getName());
    }

    public function testTheDefaultCannotStepDownWithoutASuccessor(): void
    {
        $head = $this->manage->list($this->company)[0];

        try {
            $this->manage->revise($this->company, $head->getId(), self::details('000', 'Acme'), null);
            self::fail('The company was left without a default establishment.');
        } catch (InvalidEstablishment $refused) {
            self::assertSame('isDefault', $refused->field);
        }
        self::assertTrue($head->isDefault());
    }

    public function testARevisionIsAuditedOnlyWhenItChangesSomething(): void
    {
        $head = $this->manage->list($this->company)[0];

        $this->manage->revise($this->company, $head->getId(), self::details('000', 'Acme', isDefault: true), null);
        $this->manage->revise($this->company, $head->getId(), self::details('000', 'Siège', postalCode: '1000', isDefault: true), null);

        self::assertCount(1, $this->audit->entries);
        self::assertSame('establishment.revised', $this->audit->entries[0]->action);
        self::assertSame(['fields' => ['name', 'postalCode']], $this->audit->entries[0]->changes);
    }

    public function testAnotherCompanysEstablishmentIsNotFound(): void
    {
        $globex = new Company('Globex', 'TN', 'TND', 'fr', 'Africa/Tunis');
        $this->provision->handle($globex);
        $theirs = $this->manage->list($globex)[0];

        $this->expectException(EstablishmentNotFound::class);
        $this->manage->revise($this->company, $theirs->getId(), self::details('009', 'Hijacked', isDefault: true), null);
    }

    private static function details(string $code, string $name, ?string $city = null, ?string $postalCode = null, bool $isDefault = false): EstablishmentDetails
    {
        return new EstablishmentDetails($code, $name, null, null, $postalCode, $city, null, null, $isDefault);
    }

    /** @return list<NumberingSeries> */
    private function seriesOfEstablishment(Establishment $establishment): array
    {
        return array_values(array_filter($this->series->ofCompany($this->company->getId()), static fn (NumberingSeries $s): bool => $s->getEstablishment() === $establishment));
    }

    private function seriesOf(Establishment $establishment, string $documentType): NumberingSeries
    {
        foreach ($this->seriesOfEstablishment($establishment) as $series) {
            if ($series->getDocumentType() === $documentType) {
                return $series;
            }
        }
        self::fail("no $documentType series");
    }
}
