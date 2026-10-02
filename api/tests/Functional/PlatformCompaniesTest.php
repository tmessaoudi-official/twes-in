<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Tenancy\Domain\Company;
use Symfony\Component\HttpFoundation\Response;

/** The platform's companies list at scale (docs/SPEC.md § 7): a page at a time, searched, narrowed and sorted in the database. */
final class PlatformCompaniesTest extends ApiTestCase
{
    private const string PATH = '/api/platform/companies';

    public function testTheListIsAPageWithItsTotalAndIsSortedByNameByDefault(): void
    {
        foreach (['Delta', 'Alpha', 'Charlie', 'Bravo', 'Echo'] as $name) {
            $this->company($name);
        }
        $this->signedInAsOperator();

        $this->getJson(self::PATH.'?itemsPerPage=2&page=2');

        self::assertResponseIsSuccessful();
        self::assertSame(['Charlie', 'Delta'], array_column($this->jsonList(), 'name'));
        self::assertSame(5, $this->jsonPage()['totalItems']);
    }

    public function testWordsFindACompanyByItsNameOrByTheAddressOfAnOwnerWhateverTheCaseAndAccents(): void
    {
        $this->company('Société Générale du Nord', owner: 'karim@nord.example');
        $this->company('Atelier Sud', owner: 'nadia@sud.example');
        $this->company('Zéro', owner: 'someone@else.example');
        $this->signedInAsOperator();

        foreach ([['societe generale', ['Société Générale du Nord']], ['ATELIER', ['Atelier Sud']], ['nadia@sud', ['Atelier Sud']], ['SUD.EXAMPLE', ['Atelier Sud']], ['example', ['Atelier Sud', 'Société Générale du Nord', 'Zéro']], ['introuvable', []]] as [$words, $expected]) {
            $this->getJson(self::PATH.'?q='.rawurlencode($words));
            self::assertResponseIsSuccessful($words);
            self::assertSame($expected, array_column($this->jsonList(), 'name'), $words);
            self::assertSame(\count($expected), $this->jsonPage()['totalItems'], $words);
        }
    }

    public function testAPercentOrUnderscoreInTheWordsIsLookedForNotTakenForAWildcard(): void
    {
        $this->company('100% Bois');
        $this->company('Alpha');
        $this->signedInAsOperator();

        $this->getJson(self::PATH.'?q='.rawurlencode('%'));

        self::assertSame(['100% Bois'], array_column($this->jsonList(), 'name'));
    }

    public function testStatusAndCountryNarrowTheListAndOrderSortsIt(): void
    {
        $this->company('Alpha', country: 'FR');
        $this->company('Bravo', country: 'TN');
        $this->company('Charlie', country: 'TN', status: 'pending');
        $this->company('Delta', country: 'TN', status: 'suspended');
        $this->signedInAsOperator();

        $this->getJson(self::PATH.'?status=pending');
        self::assertSame(['Charlie'], array_column($this->jsonList(), 'name'));
        $this->getJson(self::PATH.'?countryCode=TN&status=active');
        self::assertSame(['Bravo'], array_column($this->jsonList(), 'name'));
        $this->getJson(self::PATH.'?countryCode=FR');
        self::assertSame(['Alpha'], array_column($this->jsonList(), 'name'));
        $this->getJson(self::PATH.'?order[name]=desc');
        self::assertSame(['Delta', 'Charlie', 'Bravo', 'Alpha'], array_column($this->jsonList(), 'name'));
        $this->getJson(self::PATH.'?order[status]=asc&order[name]=desc');
        self::assertSame(['Bravo', 'Alpha', 'Charlie', 'Delta'], array_column($this->jsonList(), 'name'));
    }

    public function testARowCarriesItsOwnersAndAnUnknownStatusOrOrderIsRefused(): void
    {
        $company = $this->company('Alpha', owner: 'karim@nord.example');
        $this->signedInAsOperator();

        $this->getJson(self::PATH);
        self::assertSame([$company->getId()->toRfc4122(), ['karim@nord.example']], [$this->jsonList()[0]['id'], $this->jsonList()[0]['owners']]);

        foreach (['?status=sleeping', '?order[name]=sideways', '?countryCode=tunisia'] as $query) {
            $this->getJson(self::PATH.$query);
            self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY, $query);
        }
    }

    public function testAPageCostsTheSameNumberOfStatementsWhateverItsSize(): void
    {
        foreach (range(1, 12) as $n) {
            $this->company(\sprintf('Société %02d', $n), owner: \sprintf('owner%02d@example.test', $n));
        }
        $this->signedInAsOperator();

        self::assertSame($this->statementsForAPageOf(self::PATH, 3), $this->statementsForAPageOf(self::PATH, 12), 'owners are read once for the page, not once per company');
    }

    private function company(string $name, string $country = 'TN', string $status = 'active', ?string $owner = null): Company
    {
        $company = 'pending' === $status ? Company::pending($name, $country, 'TND', 'fr', 'Africa/Tunis') : new Company($name, $country, 'TND', 'fr', 'Africa/Tunis');
        if ('suspended' === $status) {
            $company->suspend();
        }
        $this->em()->persist($company);
        $this->em()->flush();
        if (null !== $owner) {
            $this->createUser($owner, 'password-1234', $company);
        }

        return $company;
    }

    private function signedInAsOperator(): void
    {
        $this->createUser('op@twes.local', 'password-1234', operator: true);
        $this->login('op@twes.local', 'password-1234');
        self::assertResponseIsSuccessful();
    }
}
