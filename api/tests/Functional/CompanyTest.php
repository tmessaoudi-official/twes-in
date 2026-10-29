<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Fiscal\Domain\Model\TaxComponent;
use App\Fiscal\Domain\Model\Unit;
use App\Settings\Domain\Repository\SettingRepository;
use App\Settings\Domain\ValueObject\SettingAddress;
use App\Tenancy\Domain\Model\Company;
use App\Tests\Support\ShippedFiscalPresets;
use Symfony\Component\HttpFoundation\Response;

/** POST /api/companies: only a platform operator opens a company, and it waits for its first owner. */
final class CompanyTest extends ApiTestCase
{
    private const array VALID = [
        'name' => 'Acme',
        'countryCode' => 'TN',
        'currency' => 'TND',
        'locale' => 'fr',
        'timezone' => 'Africa/Tunis',
    ];

    public function testAnOperatorOpensACompanyThatWaitsForItsOwner(): void
    {
        $this->operatorSignedIn();

        $this->postJson('/api/companies', self::VALID);

        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);
        $body = $this->json();
        self::assertSame('Acme', $body['name']);
        self::assertSame(Company::STATUS_PENDING, $body['status']);
        self::assertIsString($body['id']);
    }

    /** The country's preset says which language a company there prints in; the operator need not repeat it. */
    public function testACompanyCreatedWithNoLanguageTakesItsPresetsFirst(): void
    {
        $this->operatorSignedIn();

        $body = self::VALID;
        unset($body['locale']);
        $this->postJson('/api/companies', $body);

        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);
        self::assertSame(ShippedFiscalPresets::presets()->get('TN')->documentLanguages[0], $this->json()['locale']);
    }

    /**
     * The country's preset says the currency a company there keeps its books in and the zone its days turn in: the
     * platform page sends the name and the country alone, so no list of countries lives in the screen.
     */
    public function testACompanyOpenedWithItsCountryAloneTakesItsPresetsCurrencyAndZone(): void
    {
        $this->operatorSignedIn();

        foreach (['TN', 'FR'] as $country) {
            $this->postJson('/api/companies', ['name' => 'Acme '.$country, 'countryCode' => $country]);

            self::assertResponseStatusCodeSame(Response::HTTP_CREATED);
            $preset = ShippedFiscalPresets::presets()->get($country);
            self::assertSame([$preset->currency, $preset->timezone], [$this->json()['currency'], $this->json()['timezone']], $country);
        }
        self::assertSame(['Africa/Tunis', 'Europe/Paris'], [ShippedFiscalPresets::presets()->get('TN')->timezone, ShippedFiscalPresets::presets()->get('FR')->timezone]);
    }

    /**
     * A company prints in the language it was opened with from its first document, and changes it as any setting: the
     * value is the company's own from the start, not the installation's default it would otherwise fall back on.
     */
    public function testTheCompanysDocumentLanguageIsWrittenFromTheLanguageItIsOpenedWith(): void
    {
        $this->operatorSignedIn();

        $this->postJson('/api/companies', [...self::VALID, 'locale' => 'ar']);
        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);
        $body = self::VALID;
        unset($body['locale']);
        $this->postJson('/api/companies', [...$body, 'name' => 'Sans langue']);
        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);

        self::assertSame('ar', $this->documentLanguageOf('Acme'));
        self::assertSame(ShippedFiscalPresets::presets()->get('TN')->documentLanguages[0], $this->documentLanguageOf('Sans langue'));
    }

    public function testAnEmptyLanguageIsRefused(): void
    {
        $this->operatorSignedIn();

        $this->postJson('/api/companies', [...self::VALID, 'locale' => '']);

        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        self::assertSame(['locale'], array_column($this->refusals(), 'propertyPath'));
    }

    public function testTheCompanyIsReallyStored(): void
    {
        $this->operatorSignedIn();

        $this->postJson('/api/companies', self::VALID);

        self::assertNotNull($this->em()->getRepository(Company::class)->findOneBy(['name' => 'Acme']));
    }

    public function testTheCompanyOpensWithItsCountrysTaxesAndUnits(): void
    {
        $this->operatorSignedIn();

        $this->postJson('/api/companies', self::VALID);

        $company = $this->em()->getRepository(Company::class)->findOneBy(['name' => 'Acme']);
        self::assertNotNull($company);
        self::assertSame('TN', $company->getFiscalPreset());
        self::assertCount(6, $this->em()->getRepository(TaxComponent::class)->findBy(['company' => $company]));
        self::assertCount(8, $this->em()->getRepository(Unit::class)->findBy(['company' => $company]));
    }

    public function testACountryWithNoFiscalPresetIsRefused(): void
    {
        $this->operatorSignedIn();

        $this->postJson('/api/companies', [...self::VALID, 'countryCode' => 'DE', 'currency' => 'EUR', 'timezone' => 'Europe/Berlin']);

        // A company there could not invoice without its taxes; a new country is a preset file plus translations.
        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        self::assertNull($this->em()->getRepository(Company::class)->findOneBy(['name' => 'Acme']));
    }

    public function testAnAnonymousCallerIsRefused(): void
    {
        $this->postJson('/api/companies', self::VALID);

        self::assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
    }

    public function testAnOrdinaryUserMayNotOpenACompany(): void
    {
        $company = $this->createCompany('Existing');
        $this->createUser('member@twes.local', 'password-1234', $company, ['*']);
        $this->login('member@twes.local', 'password-1234');

        $this->postJson('/api/companies', self::VALID);

        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
    }

    public function testAnOwnerWildcardDoesNotReachPlatformScope(): void
    {
        $company = $this->createCompany('Existing');
        $this->createUser('owner@twes.local', 'password-1234', $company, ['*']);
        $this->login('owner@twes.local', 'password-1234');

        $this->postJson('/api/companies', self::VALID);

        // "*" is a company wildcard. Platform scope sits outside memberships and is not reachable by any role.
        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
    }

    public function testABlankNameIsRefused(): void
    {
        $this->operatorSignedIn();

        $this->postJson('/api/companies', [...self::VALID, 'name' => '']);

        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        // Said on the name's own field, in the screens' words.
        self::assertSame([['propertyPath' => 'name', 'reason' => 'value_required', 'params' => []]], $this->refusals());
    }

    public function testAnUnknownCountryIsRefused(): void
    {
        $this->operatorSignedIn();

        $this->postJson('/api/companies', [...self::VALID, 'countryCode' => 'ZZ']);

        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
    }

    /** A company's language is the one its documents print in: a tag of the catalogue, never any locale ICU knows. */
    public function testALanguageDocumentsDoNotPrintInIsRefused(): void
    {
        $this->operatorSignedIn();

        foreach (['de', 'fr_TN', 'ar-TN'] as $locale) {
            $this->postJson('/api/companies', [...self::VALID, 'name' => 'Acme '.$locale, 'locale' => $locale]);
            self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY, $locale);
            self::assertSame(['locale'], array_column($this->refusals(), 'propertyPath'), $locale);
        }
        $this->postJson('/api/companies', [...self::VALID, 'locale' => 'en']);
        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);
    }

    public function testAnUnknownTimezoneIsRefused(): void
    {
        $this->operatorSignedIn();

        $this->postJson('/api/companies', [...self::VALID, 'timezone' => 'Mars/Olympus']);

        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
    }

    public function testTwoCompaniesCannotShareAName(): void
    {
        $this->operatorSignedIn();
        $this->postJson('/api/companies', self::VALID);
        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);

        $this->postJson('/api/companies', self::VALID);

        self::assertResponseStatusCodeSame(Response::HTTP_CONFLICT);
    }

    public function testTheRequestNeedsItsCsrfHeader(): void
    {
        $this->operatorSignedIn();

        $this->postJson('/api/companies', self::VALID, withCsrf: false);

        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
    }

    private function operatorSignedIn(): void
    {
        $this->createUser('op@twes.local', 'password-1234', operator: true);
        $this->login('op@twes.local', 'password-1234');
        $this->stepUp('password-1234');
        self::assertResponseIsSuccessful();
    }

    /** The document language the company itself stores, null when it stores none. */
    private function documentLanguageOf(string $name): mixed
    {
        $company = $this->em()->getRepository(Company::class)->findOneBy(['name' => $name]);
        self::assertNotNull($company);

        return static::getContainer()->get(SettingRepository::class)->find(SettingAddress::company($company), 'document.language')?->getValue();
    }
}
