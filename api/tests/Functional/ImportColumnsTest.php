<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Functional;

use App\CustomFields\Domain\Model\CustomFieldDefinition;
use App\CustomFields\Domain\ValueObject\CustomFieldEntity;
use App\CustomFields\Domain\ValueObject\CustomFieldType;
use App\Identity\Infrastructure\Security\SecurityUser;
use App\ImportExport\Application\ImportCatalogue;
use App\ImportExport\Application\ImportHeading;
use App\ImportExport\Application\ImportSettings;
use App\Settings\Domain\Model\Setting;
use App\Settings\Domain\ValueObject\SettingAddress;
use App\Tenancy\Domain\Model\Company;
use App\Tests\Support\ShippedLanguages;
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
        self::assertSame(['customers', ['number'], 5000], [$guide['subject'] ?? null, $guide['identity'] ?? null, $guide['maxRows'] ?? null]);
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
            ['key' => 'matricule_fiscal', 'required' => false, 'headingKey' => null, 'label' => 'Matricule fiscal', 'example' => '1234567A/B/M/000', 'noteKey' => 'import.identifier_note'],
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
    public function testEveryColumnAndSwitchIsWordedInEveryLanguage(): void
    {
        // An owner, who may do everything, is offered every column: the ones some callers are not (cost_price).
        $account = SecurityUser::of($this->createUser('owner@twes.local', 'password-1234', $this->company));
        static::getContainer()->get(TokenStorageInterface::class)->setToken(new UsernamePasswordToken($account, 'main', $account->getRoles()));
        $catalogue = static::getContainer()->get(ImportCatalogue::class);
        self::assertInstanceOf(ImportCatalogue::class, $catalogue);
        $checked = 0;
        $switches = 0;
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
            // A switch is worded by the screen alone, so it has nothing but these keys to show.
            foreach ($catalogue->declaration($subject)->subjectFor($this->company)->switches as $switch) {
                foreach (array_filter([$switch->label, $switch->note]) as $key) {
                    $this->assertScreenKeyTranslated($key);
                    ++$switches;
                }
            }
        }
        self::assertGreaterThan(40, $checked, 'the subjects were enumerated, not skipped');
        self::assertGreaterThanOrEqual(4, $switches, 'the products file offers two switches with a note each, whatever its modules');
    }

    /** A registration number is shown as the preset writes one, to whoever fills in a customer or a supplier file. */
    public function testEveryIdentifierColumnShowsThePresetsExample(): void
    {
        $this->signedIn(['customer.read', 'customer.write', 'vendor.read', 'vendor.write']);

        foreach (['customers', 'vendors'] as $subject) {
            $this->getJson($this->path($subject));
            self::assertResponseIsSuccessful();
            $columns = array_column($this->arrayAt($this->json(), 'columns'), 'example', 'key');
            self::assertSame('1234567A/B/M/000', $columns['matricule_fiscal'] ?? null, $subject);
        }
    }

    /** The row cap is the platform's setting, and the guide and the import read the same one. */
    public function testThePlatformsRowCapIsWhatTheGuideSaysAndWhatTheImportHolds(): void
    {
        $this->em()->persist(new Setting(SettingAddress::platform(), ImportSettings::MAX_ROWS, 3, new \DateTimeImmutable()));
        $this->em()->flush();
        $this->signedIn(['customer.read', 'customer.write']);

        $this->getJson($this->path('customers'));
        self::assertResponseIsSuccessful();
        self::assertSame(3, $this->json()['maxRows'] ?? null);

        $this->uploadFile($this->path('customers'), 'customers.csv', "number,name,custom.employees\nC-1,a,1\nC-2,b,1\nC-3,c,1\nC-4,d,1\n", 'file', ['mode' => 'create', 'dryRun' => '1']);
        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        self::assertSame(['too_many_rows', 3], [$this->json()['error'] ?? null, $this->json()['limit'] ?? null]);

        $this->uploadFile($this->path('customers'), 'customers.csv', "number,name,custom.employees\nC-1,a,1\nC-2,b,1\nC-3,c,1\n", 'file', ['mode' => 'create', 'dryRun' => '1']);
        self::assertResponseIsSuccessful();
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
        foreach (ShippedLanguages::catalogue()->interface() as $language) {
            $words = self::translations($language);
            foreach (explode('.', $key) as $step) {
                self::assertIsArray($words, \sprintf('"%s" is a leaf before "%s" in %s', $key, $step, $language));
                self::assertArrayHasKey($step, $words, \sprintf('"%s" is missing from web/public/i18n/%s.json and its import part', $key, $language));
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
            // The guide is the import page's, which opens its own part over the language's file.
            $read = [];
            foreach (['/web/public/i18n/'.$language.'.json', '/web/public/i18n/import/'.$language.'.json'] as $file) {
                $path = \dirname(__DIR__, 3).$file;
                self::assertFileExists($path);
                $part = json_decode((string) file_get_contents($path), true, 512, \JSON_THROW_ON_ERROR);
                self::assertIsArray($part);
                $read = array_replace_recursive($read, $part);
            }
            $keyed = [];
            foreach ($read as $key => $value) {
                $keyed[(string) $key] = $value;
            }
            self::$words[$language] = $keyed;
        }

        return self::$words[$language];
    }
}
