<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Fiscal\Application\Regime\SyncCustomerTaxRegimes;
use App\Fiscal\Domain\Repository\CustomerTaxRegimeRepository;
use App\Module\Customers\Application\CustomerCheckScopes;
use App\Module\Customers\Domain\Model\Customer;
use App\Module\Customers\Domain\Repository\CustomerRepository;
use App\Module\Customers\Domain\ValueObject\CustomerKind;
use App\Module\Customers\Domain\ValueObject\CustomerProfile;
use App\Module\Customers\Domain\ValueObject\CustomerSearch;
use App\Module\Customers\Infrastructure\Watch\CustomerCheckWatch;
use App\Shared\Domain\Model\PostalAddress;
use App\Shared\Domain\ValueObject\CheckMark;
use App\Shared\Domain\ValueObject\PageRequest;
use App\Tenancy\Domain\Model\Company;
use Symfony\Component\HttpFoundation\Response;

/**
 * « À vérifier » on customers: marked by a person with a note, or a business of the company's country without a number
 * its preset requires, which a file may leave out and an invoice may not. Such a customer can still be revised for
 * anything else; a number it held cannot be taken away, and a new one cannot be created without it.
 */
final class CustomerCheckTest extends ApiTestCase
{
    private const string MATRICULE = '1234567A/B/M/000';

    private Company $company;

    /** @var array<string, string> each fixture's id by its number */
    private array $ids = [];

    public function testTheDatabaseNarrowsToWhatEachCustomerSaysOfItself(): void
    {
        $repository = static::getContainer()->get(CustomerRepository::class);
        $scope = static::getContainer()->get(CustomerCheckScopes::class)->of($this->company);
        $search = static fn (?bool $toCheck): CustomerSearch => new CustomerSearch(toCheck: $toCheck, checkScope: $scope);
        $all = $repository->search($this->company->getId(), $search(null), new PageRequest(1, 100))->items;
        $expected = static fn (callable $keep): array => self::numbers(array_values(array_filter($all, $keep)));
        $found = fn (?bool $toCheck): array => self::numbers($repository->search($this->company->getId(), $search($toCheck), new PageRequest(1, 100))->items);

        $toCheck = static fn (Customer $customer): bool => $customer->getCheckMark()->marked || [] !== $customer->checkReasons($scope);
        self::assertSame(['MARKED', 'MISSING', 'NO-COUNTRY', 'RETIRED'], $expected($toCheck));
        self::assertSame($expected($toCheck), $found(true));
        self::assertSame($expected(static fn (Customer $customer): bool => !$toCheck($customer)), $found(false));
    }

    public function testTheListNarrowsByTheSameWords(): void
    {
        $this->signedIn(['company.read', 'customer.read']);
        $this->getJson($this->path('customers?toCheck=true&isActive=true'));
        self::assertResponseIsSuccessful();
        $numbers = array_column($this->jsonList(), 'number');
        sort($numbers);
        self::assertSame(['MARKED', 'MISSING', 'NO-COUNTRY'], $numbers);
    }

    public function testABusinessLackingItsNumberIsRevisedForAnythingElseAndClearsOnceItHasIt(): void
    {
        $this->signedIn(['company.read', 'customer.read', 'customer.write']);
        $id = $this->ids['MISSING'];
        $this->getJson($this->path('customers/'.$id));
        $customer = $this->json();
        self::assertSame([false, null, ['identifier_missing'], ['matricule_fiscal']], [$customer['toCheck'] ?? null, $customer['checkNote'] ?? null, $customer['checkReasons'] ?? null, $customer['missingIdentifiers'] ?? null]);

        $this->sendJson('PUT', $this->path('customers/'.$id), [...self::written($customer), 'phone' => '+216 71 000 000', 'toCheck' => true, 'checkNote' => 'matricule à demander']);
        self::assertResponseIsSuccessful();
        $saved = $this->json();
        self::assertSame([true, 'matricule à demander', ['identifier_missing']], [$saved['toCheck'] ?? null, $saved['checkNote'] ?? null, $saved['checkReasons'] ?? null], 'a number it never had does not refuse the rest');

        $this->sendJson('PUT', $this->path('customers/'.$id), [...self::written($saved), 'identifiers' => ['matricule_fiscal' => self::MATRICULE], 'toCheck' => false]);
        self::assertResponseIsSuccessful();
        self::assertSame([false, null, [], []], [$this->json()['toCheck'] ?? null, $this->json()['checkNote'] ?? null, $this->json()['checkReasons'] ?? null, $this->json()['missingIdentifiers'] ?? null]);

        $this->sendJson('PUT', $this->path('customers/'.$id), [...self::written($this->json()), 'toCheck' => true, 'checkNote' => str_repeat('a', CheckMark::NOTE_MAX + 1)]);
        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
    }

    public function testANumberHeldIsNotTakenAwayAndANewBusinessIsNotCreatedWithoutIt(): void
    {
        $this->signedIn(['company.read', 'customer.read', 'customer.write']);
        $this->getJson($this->path('customers/'.$this->ids['PLAIN']));
        $this->sendJson('PUT', $this->path('customers/'.$this->ids['PLAIN']), [...self::written($this->json()), 'identifiers' => []]);
        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);

        $this->postJson($this->path('customers'), ['number' => 'NEW', 'kind' => 'company', 'name' => 'Nouvelle SARL', 'taxRegime' => 'standard', 'defaultTaxComponentIds' => [], 'isActive' => true]);
        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
    }

    public function testTheWatchListsTheActiveCustomersToCheck(): void
    {
        $this->signedIn(['company.read', 'customer.read']);
        $this->getJson($this->path('watch/'.CustomerCheckWatch::TO_CHECK));
        self::assertResponseIsSuccessful();
        $params = array_column($this->jsonList(), 'params', 'subjectId');
        ksort($params);
        $expected = [
            $this->ids['MISSING'] => ['customer' => 'Missing', 'number' => 'MISSING', 'reasons' => 'identifier_missing', 'missing' => 'matricule_fiscal', 'marked' => 0, 'note' => ''],
            $this->ids['NO-COUNTRY'] => ['customer' => 'No country', 'number' => 'NO-COUNTRY', 'reasons' => 'identifier_missing', 'missing' => 'matricule_fiscal', 'marked' => 0, 'note' => ''],
            $this->ids['MARKED'] => ['customer' => 'Marked', 'number' => 'MARKED', 'reasons' => '', 'missing' => '', 'marked' => 1, 'note' => 'à relancer'],
        ];
        ksort($expected);
        self::assertSame($expected, $params, 'a customer switched off is sold to no more');
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->company = $this->createCompany('Acme');
        static::getContainer()->get(SyncCustomerTaxRegimes::class)->handle();
        $standard = static::getContainer()->get(CustomerTaxRegimeRepository::class)->ofPresetAndCode('TN', 'standard');
        self::assertNotNull($standard);
        $now = new \DateTimeImmutable();
        $tunis = new PostalAddress('12, rue du Lac', null, '1053', 'Tunis', 'TN');
        $make = function (string $number, CustomerProfile $profile) use ($now, $standard): Customer {
            $customer = Customer::create($this->company, $number, $profile, null, $standard, [], $now);
            $this->em()->persist($customer);
            $this->ids[$number] = $customer->getId()->toRfc4122();

            return $customer;
        };
        $make('PLAIN', new CustomerProfile(CustomerKind::Company, 'Plain', identifiers: ['matricule_fiscal' => self::MATRICULE], billingAddress: $tunis));
        $make('MISSING', new CustomerProfile(CustomerKind::Company, 'Missing', billingAddress: $tunis));
        $make('NO-COUNTRY', new CustomerProfile(CustomerKind::Company, 'No country'));
        $make('ABROAD', new CustomerProfile(CustomerKind::Company, 'Abroad', billingAddress: new PostalAddress('1, rue de Rivoli', null, '75001', 'Paris', 'FR')));
        $make('PERSON', new CustomerProfile(CustomerKind::Individual, 'Person'));
        $make('MARKED', new CustomerProfile(CustomerKind::Individual, 'Marked'))->reviseCheck(new CheckMark(true, 'à relancer'), $now);
        $retired = $make('RETIRED', new CustomerProfile(CustomerKind::Company, 'Retired', billingAddress: $tunis));
        $retired->revise('RETIRED', $retired->getProfile(), null, $standard, [], false, $now);
        $this->em()->flush();
    }

    /**
     * @param list<Customer> $customers
     *
     * @return list<string> sorted
     */
    private static function numbers(array $customers): array
    {
        $numbers = array_map(static fn (Customer $customer): string => $customer->getNumber(), $customers);
        sort($numbers);

        return $numbers;
    }

    /**
     * What a form sends back of a customer it read: nothing only read.
     *
     * @param array<array-key, mixed> $customer
     *
     * @return array<string, mixed>
     */
    private static function written(array $customer): array
    {
        $written = [];
        foreach ($customer as $field => $value) {
            if (\is_string($field) && !\in_array($field, ['id', 'checkReasons', 'missingIdentifiers', 'mergedInto'], true)) {
                $written[$field] = $value;
            }
        }

        return $written;
    }

    /** @param list<string> $permissions */
    private function signedIn(array $permissions): void
    {
        $this->createUser('owner@twes.local', 'password-1234', $this->company, $permissions, 'member');
        $this->login('owner@twes.local', 'password-1234');
        self::assertResponseIsSuccessful();
    }

    private function path(string $resource): string
    {
        return '/api/companies/'.$this->company->getId()->toRfc4122().'/'.$resource;
    }
}
