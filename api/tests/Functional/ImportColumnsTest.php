<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Functional;

use App\CustomFields\Domain\CustomFieldDefinition;
use App\CustomFields\Domain\CustomFieldEntity;
use App\CustomFields\Domain\CustomFieldType;
use App\Tenancy\Domain\Company;
use Symfony\Component\HttpFoundation\Response;

/**
 * What the import screen shows beside the template download: each column of this company's file, as a person reads
 * it (docs/SPEC.md § 7, 2026-09-17 and 2026-09-19).
 */
final class ImportColumnsTest extends ApiTestCase
{
    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();
        $this->company = $this->createCompany('Acme');
        $now = new \DateTimeImmutable();
        $this->em()->persist(CustomFieldDefinition::create($this->company, CustomFieldEntity::Customer, 'employees', 'Salariés', CustomFieldType::Number, true, [], 1, $now));
        $retired = CustomFieldDefinition::create($this->company, CustomFieldEntity::Customer, 'legacy', 'Ancien code', CustomFieldType::Text, false, [], 2, $now);
        $retired->revise('Ancien code', false, [], 2, false, $now);
        $this->em()->persist($retired);
        $this->em()->flush();
    }

    public function testEachColumnIsDescribedAsAPersonReadsIt(): void
    {
        $this->signedIn(['customer.read', 'customer.write']);

        $this->getJson($this->path('customers'));

        self::assertResponseIsSuccessful();
        $guide = $this->json();
        self::assertSame(['customers', 'number', 2000], [$guide['subject'] ?? null, $guide['identity'] ?? null, $guide['maxRows'] ?? null]);
        $columns = [];
        foreach ($this->arrayAt($guide, 'columns') as $column) {
            self::assertIsArray($column);
            self::assertIsString($column['key'] ?? null);
            $columns[$column['key']] = $column;
        }
        self::assertSame(
            ['key' => 'number', 'required' => true, 'headingKey' => 'import.customers.number', 'label' => null, 'example' => 'CLI-0001', 'noteKey' => 'import.customers.number_note'],
            $columns['number'] ?? null,
            'a column every company has is named by the screen, in its own language',
        );
        self::assertSame(
            ['key' => 'matricule_fiscal', 'required' => false, 'headingKey' => null, 'label' => 'Matricule fiscal', 'example' => null, 'noteKey' => 'import.identifier_note'],
            $columns['matricule_fiscal'] ?? null,
            'a registration number is named by the preset, translated for the person asking',
        );
        self::assertSame(
            ['key' => 'custom.employees', 'required' => true, 'headingKey' => null, 'label' => 'Salariés', 'example' => null, 'noteKey' => null],
            $columns['custom.employees'] ?? null,
            'a custom field is named as the company named it',
        );
        self::assertArrayNotHasKey('custom.legacy', $columns, 'a field switched off is not asked for');
        self::assertSame('number', array_key_first($columns), 'in the order of the template');
    }

    public function testSomebodyWhoCannotWriteCustomersIsAnsweredAsAStranger(): void
    {
        $this->signedIn(['customer.read']);

        $this->getJson($this->path('customers'));

        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
    }

    public function testASubjectNobodyDeclaresIsNotFound(): void
    {
        $this->signedIn(['customer.read', 'customer.write']);

        $this->getJson($this->path('unicorns'));

        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
    }

    /** @param list<string> $permissions */
    private function signedIn(array $permissions): void
    {
        $this->createUser('sales@twes.local', 'password-1234', $this->company, $permissions, 'member');
        $this->login('sales@twes.local', 'password-1234');
        self::assertResponseIsSuccessful();
    }

    private function path(string $subject): string
    {
        return '/api/companies/'.$this->company->getId()->toRfc4122().'/imports/'.$subject;
    }
}
