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
use App\Identity\Infrastructure\Security\SecurityUser;
use App\ImportExport\Application\ImportCatalogue;
use App\ImportExport\Application\ImportHeading;
use App\Tenancy\Domain\Company;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;

/**
 * What the import screen shows beside the template download: each column of this company's file, as a person reads
 * it (docs/SPEC.md § 7, 2026-09-17 and 2026-09-19).
 */
final class ImportColumnsTest extends ApiTestCase
{
    /** @var array<string, array<string, mixed>> each language file, read at most once */
    private static array $words = [];

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
        self::assertSame(['customers', ['number'], 2000], [$guide['subject'] ?? null, $guide['identity'] ?? null, $guide['maxRows'] ?? null]);
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

    /** Every subject's columns, so a column added to any importer without its words is caught here. */
    public function testEveryColumnsHeadingAndNoteExistsInBothLanguages(): void
    {
        // An owner, who may do everything, is offered every column: the ones some callers are not (cost_price).
        $account = SecurityUser::of($this->createUser('owner@twes.local', 'password-1234', $this->company));
        static::getContainer()->get(TokenStorageInterface::class)->setToken(new UsernamePasswordToken($account, 'main', $account->getRoles()));
        $catalogue = static::getContainer()->get(ImportCatalogue::class);
        self::assertInstanceOf(ImportCatalogue::class, $catalogue);
        $checked = 0;
        foreach ($catalogue->keys() as $subject) {
            foreach ($catalogue->declaration($subject)->subjectFor($this->company)->columns as $column) {
                if (ImportHeading::ScreenText === $column->headingIs) {
                    $this->assertScreenKeyTranslated($column->heading);
                    ++$checked;
                }
                if (null !== $column->note) {
                    $this->assertScreenKeyTranslated($column->note);
                    ++$checked;
                }
            }
        }
        self::assertGreaterThan(40, $checked, 'the subjects were enumerated, not skipped');
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

    /**
     * Every heading and note a column declares as the screen's own (`ImportHeading::ScreenText`) exists in both
     * language files, so a new column cannot reach the guide as a raw key like `import.products.barcode`.
     *
     * This is the one place both tiers are visible at once: the keys are written in PHP and read from
     * `web/public/i18n`, and nothing on either side alone can see the pair. A column added without its words fails
     * here rather than in front of a person filling in a spreadsheet.
     */
    private function assertScreenKeyTranslated(string $key): void
    {
        foreach (['fr', 'en'] as $language) {
            $words = self::translations($language);
            foreach (explode('.', $key) as $step) {
                self::assertIsArray($words, \sprintf('"%s" is a leaf before "%s" in %s', $key, $step, $language));
                self::assertArrayHasKey($step, $words, \sprintf('"%s" is missing from web/public/i18n/%s.json', $key, $language));
                $words = $words[$step];
            }
            self::assertIsString($words, \sprintf('"%s" is not a sentence in %s', $key, $language));
            self::assertNotSame('', trim($words), \sprintf('"%s" is empty in %s', $key, $language));
        }
    }

    /**
     * Read once per language: a guide's columns ask for hundreds of keys, and the files do not change under a run.
     *
     * @return array<string, mixed>
     */
    private static function translations(string $language): array
    {
        if (!isset(self::$words[$language])) {
            $path = \dirname(__DIR__, 3).'/web/public/i18n/'.$language.'.json';
            self::assertFileExists($path);
            $read = json_decode((string) file_get_contents($path), true, 512, \JSON_THROW_ON_ERROR);
            self::assertIsArray($read);
            $keyed = [];
            foreach ($read as $key => $value) {
                $keyed[(string) $key] = $value;
            }
            self::$words[$language] = $keyed;
        }

        return self::$words[$language];
    }
}
