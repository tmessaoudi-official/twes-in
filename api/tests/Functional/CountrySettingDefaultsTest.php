<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Fiscal\Application\Company\ProvisionCompany;
use App\Fiscal\Application\Preset\FiscalPresets;
use App\Settings\Application\SettingCatalog;
use App\Tenancy\Domain\Company;

/**
 * A setting whose answer differs by country takes its default from the country's preset, data and not code: the total
 * written out in words closes a Tunisian invoice by custom and not a French one (docs/SPEC.md § 7, 2026-10-07 14:43).
 * What the company stores still wins, and the settings screen shows the default the company actually starts from.
 */
final class CountrySettingDefaultsTest extends ApiTestCase
{
    public function testEachCountryStartsFromItsPresetsDefaultWhichTheCompanyMayChange(): void
    {
        $tunis = $this->createCompany('Carthage');
        $paris = new Company('Lutèce', 'FR', 'EUR', 'fr', 'Europe/Paris');
        $this->em()->persist($paris);
        $this->em()->flush();
        foreach ([$tunis, $paris] as $company) {
            static::getContainer()->get(ProvisionCompany::class)->handle($company);
            $this->createUser('owner@'.strtolower($company->getCountryCode()).'.twes.local', 'password-1234', $company, ['company.read', 'company.settings'], 'owner');
        }

        self::assertSame([true, true, null], $this->amountInWords('tn', $tunis));
        self::assertSame([false, false, null], $this->amountInWords('fr', $paris), 'a French invoice is not written out in words unless the company wants it');

        $this->sendJson('PUT', $this->settings($paris).'/document.amount_in_words', ['level' => 'company', 'value' => true]);
        self::assertResponseIsSuccessful();
        self::assertSame([true, false, 'company'], $this->amountInWords('fr', $paris), 'what the company chose wins over its country');
    }

    public function testEveryPresetNamesOnlySettingsThatExistWithAValueTheyAccept(): void
    {
        $presets = static::getContainer()->get(FiscalPresets::class);
        $catalog = static::getContainer()->get(SettingCatalog::class);
        $named = 0;
        foreach ($presets->keys() as $country) {
            foreach ($presets->get($country)->settings as $key => $value) {
                $definition = $catalog->definitionOf($key);
                self::assertNotNull($definition, \sprintf('The %s preset names %s, which no module declares.', $country, $key));
                self::assertNull($definition->refusal($value), \sprintf('The %s preset gives %s a value it refuses.', $country, $key));
                ++$named;
            }
        }
        self::assertGreaterThanOrEqual(\count($presets->keys()), $named, 'every preset says at least whether its invoices are written out in words');
    }

    /** @return array{mixed, mixed, mixed} the value in force, the default shown, and where the value comes from */
    private function amountInWords(string $country, Company $company): array
    {
        $this->login('owner@'.$country.'.twes.local', 'password-1234');
        $this->getJson($this->settings($company).'?chain=parties');
        self::assertResponseIsSuccessful();
        foreach ($this->jsonList() as $row) {
            if ('document.amount_in_words' === $row['key']) {
                return [$row['value'], $row['default'], $row['source'] ?? null];
            }
        }
        self::fail('No document.amount_in_words in the parties chain.');
    }

    private function settings(Company $company): string
    {
        return '/api/companies/'.$company->getId()->toRfc4122().'/settings';
    }
}
