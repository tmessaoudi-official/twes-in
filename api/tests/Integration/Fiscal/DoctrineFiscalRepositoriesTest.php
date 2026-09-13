<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Integration\Fiscal;

use App\Fiscal\Domain\CustomerTaxRegime;
use App\Fiscal\Domain\CustomerTaxRegimeRepository;
use App\Fiscal\Domain\TaxComponent;
use App\Fiscal\Domain\TaxComponentRepository;
use App\Fiscal\Domain\TaxFamily;
use App\Fiscal\Domain\Unit;
use App\Fiscal\Domain\UnitRepository;
use App\Tenancy\Domain\Company;
use App\Tenancy\Domain\CompanyRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

/** The fiscal adapters against the real PostgreSQL: decimals, enums and jsonb come back as the domain wrote them. */
final class DoctrineFiscalRepositoriesTest extends KernelTestCase
{
    private Company $acme;
    private Company $globex;

    protected function setUp(): void
    {
        self::bootKernel();
        $companies = static::getContainer()->get(CompanyRepository::class);
        $this->acme = new Company('Acme', 'TN', 'TND', 'fr', 'Africa/Tunis');
        $this->globex = new Company('Globex', 'TN', 'TND', 'fr', 'Africa/Tunis');
        $companies->save($this->acme);
        $companies->save($this->globex);
    }

    public function testTaxComponentsRoundTripOneCompanyAtATimeInOrder(): void
    {
        $repository = static::getContainer()->get(TaxComponentRepository::class);
        $now = new \DateTimeImmutable();
        $repository->save(TaxComponent::create($this->acme, 'TIMBRE', 'Timbre', TaxFamily::Stamp, null, '1', null, false, true, null, 50, 3, $now));
        $repository->save(TaxComponent::create($this->acme, 'TVA19', 'TVA 19 %', TaxFamily::Vat, '19', null, null, false, true, null, 10, 3, $now));
        $theirs = TaxComponent::create($this->globex, 'TVA19', 'TVA 19 %', TaxFamily::Vat, '19', null, null, false, true, null, 10, 3, $now);
        $repository->save($theirs);
        $this->clear();

        $mine = $repository->ofCompany($this->acme->getId());
        self::assertSame(['TVA19', 'TIMBRE'], array_map(static fn (TaxComponent $c) => $c->getCode(), $mine));
        self::assertSame('19.000', $mine[0]->getRate());
        self::assertSame('1.000', $mine[1]->getAmount());
        self::assertSame(TaxFamily::Stamp, $mine[1]->getFamily());
        self::assertNotNull($repository->ofCodeInCompany('TVA19', $this->globex->getId()));
        self::assertNull($repository->ofCodeInCompany('TIMBRE', $this->globex->getId()));
        self::assertNull($repository->ofIdInCompany($theirs->getId(), $this->acme->getId()));
        self::assertNotNull($repository->ofIdInCompany($theirs->getId(), $this->globex->getId()));
        self::assertNull($repository->ofIdInCompany(Uuid::v7(), $this->acme->getId()));
    }

    public function testARevisedComponentIsNotReportedAsChangedWhenReadBack(): void
    {
        $repository = static::getContainer()->get(TaxComponentRepository::class);
        $vat = TaxComponent::create($this->acme, 'TVA7', 'TVA 7 %', TaxFamily::Vat, '7', null, null, false, false, null, 30, 3, new \DateTimeImmutable());
        $repository->save($vat);
        $this->clear();

        $read = $repository->ofIdInCompany($vat->getId(), $this->acme->getId());
        self::assertNotNull($read);
        // The database hands decimals back as it stores them; the entity wrote them that way, so nothing changed.
        self::assertFalse($read->revise('TVA 7 %', '7', null, null, false, false, true, null, 30, 3, new \DateTimeImmutable()));
    }

    public function testUnitsRoundTripOneCompanyAtATime(): void
    {
        $repository = static::getContainer()->get(UnitRepository::class);
        $unit = Unit::create($this->acme, 'KGM', 'Kilogramme', 3, 40, new \DateTimeImmutable());
        $repository->save($unit);
        $this->clear();

        self::assertSame(3, $repository->ofCodeInCompany('KGM', $this->acme->getId())?->getDecimals());
        self::assertSame([], $repository->ofCompany($this->globex->getId()));
        self::assertNull($repository->ofIdInCompany($unit->getId(), $this->globex->getId()));
    }

    public function testRegimesRoundTripTheirExcludedFamilies(): void
    {
        $repository = static::getContainer()->get(CustomerTaxRegimeRepository::class);
        $repository->save(new CustomerTaxRegime('TN', 'export', 'fiscal.regime.export', [TaxFamily::Vat], 'fiscal.mention.tn.export', 40, new \DateTimeImmutable()));
        $repository->save(new CustomerTaxRegime('TN', 'standard', 'fiscal.regime.standard', [], null, 10, new \DateTimeImmutable()));
        $this->clear();

        self::assertSame(['standard', 'export'], array_map(static fn (CustomerTaxRegime $r) => $r->getCode(), $repository->ofPreset('TN')));
        self::assertSame([TaxFamily::Vat], $repository->ofPresetAndCode('TN', 'export')?->getExcludedFamilies());
        self::assertSame([], $repository->ofPreset('FR'));
    }

    private function clear(): void
    {
        static::getContainer()->get(EntityManagerInterface::class)->clear();
    }
}
