<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Architecture;

use App\Fiscal\Domain\CustomerTaxRegime;
use App\Identity\Domain\Passkey;
use App\Identity\Domain\RecoveryCodeEntry;
use App\Identity\Domain\User;
use App\Tenancy\Domain\Company;
use App\Tenancy\Domain\Signup;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\Mapping\ToOneOwningSideMapping;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * docs/SPEC.md § 3 Tenancy: every business table carries `company_id`. Read from the Doctrine mapping, not the source,
 * so a column the naming strategy names counts as much as one written out.
 */
final class CompanyColumnTest extends KernelTestCase
{
    /** What has no company by design, and why. */
    private const array WITHOUT_COMPANY = [
        Company::class => 'the tenant itself',
        User::class => 'an account belongs to no company; memberships attach it to several',
        Signup::class => 'a request for a company that does not exist yet',
        Passkey::class => "one of an account's second factors",
        RecoveryCodeEntry::class => "one of an account's second factors",
        CustomerTaxRegime::class => "a country preset's reference data, which no company edits",
    ];

    public function testEveryEntityCarriesACompanyIdUnlessItHasNoCompanyByDesign(): void
    {
        $missing = [];
        $all = $this->allMetadata();
        foreach ($all as $metadata) {
            if (!isset(self::WITHOUT_COMPANY[$metadata->getName()]) && null === self::companyColumnNullable($metadata)) {
                $missing[] = $metadata->getName();
            }
        }

        self::assertGreaterThan(30, \count($all), 'the mapping lists the entities');
        self::assertSame([], $missing, 'these entities carry no company_id');
    }

    public function testTheExemptionsAreEntitiesThatReallyHaveNoCompany(): void
    {
        $names = array_map(static fn (ClassMetadata $metadata): string => $metadata->getName(), $this->allMetadata());
        foreach (array_keys(self::WITHOUT_COMPANY) as $class) {
            self::assertContains($class, $names, "$class is still an entity");
        }
        foreach ($this->allMetadata() as $metadata) {
            if (isset(self::WITHOUT_COMPANY[$metadata->getName()])) {
                self::assertNull(self::companyColumnNullable($metadata), $metadata->getName().' has a company_id after all: drop its exemption');
            }
        }
    }

    /**
     * Whether the entity's company_id column accepts null, or null when it has no such column.
     *
     * @param ClassMetadata<object> $metadata
     */
    public static function companyColumnNullable(ClassMetadata $metadata): ?bool
    {
        foreach ($metadata->fieldMappings as $field) {
            if ('company_id' === $field->columnName) {
                return (bool) $field->nullable;
            }
        }
        foreach ($metadata->associationMappings as $association) {
            if (!$association instanceof ToOneOwningSideMapping) {
                continue;
            }
            foreach ($association->joinColumns as $joinColumn) {
                if ('company_id' === $joinColumn->name) {
                    return $joinColumn->nullable ?? true;
                }
            }
        }

        return null;
    }

    /** @return list<ClassMetadata<object>> */
    private function allMetadata(): array
    {
        self::bootKernel();

        return static::getContainer()->get(EntityManagerInterface::class)->getMetadataFactory()->getAllMetadata();
    }
}
