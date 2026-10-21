<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Module\Vendors\Domain\Model\Vendor;
use App\Module\Vendors\Domain\Repository\VendorRepository;
use App\Module\Vendors\Domain\ValueObject\VendorProfile;
use App\Module\Vendors\Domain\ValueObject\VendorSearch;
use App\Module\Vendors\Infrastructure\Watch\VendorCheckWatch;
use App\Shared\Domain\ValueObject\CheckMark;
use App\Shared\Domain\ValueObject\PageRequest;
use App\Tenancy\Domain\Model\Company;
use Symfony\Component\HttpFoundation\Response;

/**
 * « À vérifier » on vendors: marked by a person with a note, or named by a file the owner should look at again (« nom
 * douteux »), which a rename clears. The list narrows to exactly what each vendor says of itself.
 */
final class VendorCheckTest extends ApiTestCase
{
    private Company $company;

    /** @var array<string, string> each fixture's id by its number */
    private array $ids = [];

    public function testTheDatabaseNarrowsToWhatEachVendorSaysOfItself(): void
    {
        $repository = static::getContainer()->get(VendorRepository::class);
        $all = $repository->search($this->company->getId(), new VendorSearch(), new PageRequest(1, 100))->items;
        $expected = static fn (callable $keep): array => self::numbers(array_values(array_filter($all, $keep)));
        $found = fn (VendorSearch $search): array => self::numbers($repository->search($this->company->getId(), $search, new PageRequest(1, 100))->items);

        $toCheck = static fn (Vendor $vendor): bool => $vendor->getCheckMark()->marked || [] !== $vendor->checkReasons();
        self::assertSame(['DOUBTFUL', 'MARKED'], $expected($toCheck));
        self::assertSame($expected($toCheck), $found(new VendorSearch(toCheck: true)));
        self::assertSame($expected(static fn (Vendor $vendor): bool => !$toCheck($vendor)), $found(new VendorSearch(toCheck: false)));
    }

    public function testARenameClearsADoubtfulNameAndAPersonMarksAVendorWithANote(): void
    {
        $this->signedIn(['company.read', 'vendor.read', 'vendor.write']);
        $id = $this->ids['DOUBTFUL'];
        $this->getJson($this->path('vendors/'.$id));
        $vendor = $this->json();
        self::assertSame([false, null, true, ['name_doubtful']], [$vendor['toCheck'] ?? null, $vendor['checkNote'] ?? null, $vendor['nameDoubtful'] ?? null, $vendor['checkReasons'] ?? null]);

        $this->sendJson('PUT', $this->path('vendors/'.$id), [...self::written($vendor), 'name' => 'Sotupa SARL', 'toCheck' => true, 'checkNote' => 'RIB à demander']);
        self::assertResponseIsSuccessful();
        $saved = $this->json();
        self::assertSame([true, 'RIB à demander', false, []], [$saved['toCheck'] ?? null, $saved['checkNote'] ?? null, $saved['nameDoubtful'] ?? null, $saved['checkReasons'] ?? null], 'renamed, the name is no longer in doubt');
        self::assertGreaterThan($vendor['version'] ?? 0, $saved['version'] ?? 0);

        $this->sendJson('PUT', $this->path('vendors/'.$id), [...self::written($saved), 'toCheck' => false]);
        self::assertResponseIsSuccessful();
        self::assertSame([false, null], [$this->json()['toCheck'] ?? null, $this->json()['checkNote'] ?? null], 'taken off, the mark takes its note with it');

        $this->sendJson('PUT', $this->path('vendors/'.$id), [...self::written($this->json()), 'toCheck' => true, 'checkNote' => str_repeat('a', CheckMark::NOTE_MAX + 1)]);
        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
    }

    public function testAPersonSaysANameInDoubtIsRightWithoutRenamingIt(): void
    {
        $this->signedIn(['company.read', 'vendor.read', 'vendor.write']);
        $id = $this->ids['DOUBTFUL'];
        $this->getJson($this->path('vendors/'.$id));
        $this->sendJson('PUT', $this->path('vendors/'.$id), [...self::written($this->json()), 'nameDoubtful' => false]);
        self::assertResponseIsSuccessful();
        self::assertSame([false, []], [$this->json()['nameDoubtful'] ?? null, $this->json()['checkReasons'] ?? null]);
    }

    public function testTheWatchListsTheActiveVendorsToCheck(): void
    {
        $this->signedIn(['company.read', 'vendor.read']);
        $this->getJson($this->path('watch/'.VendorCheckWatch::TO_CHECK));
        self::assertResponseIsSuccessful();
        $params = array_column($this->jsonList(), 'params', 'subjectId');
        ksort($params);
        $expected = [
            $this->ids['DOUBTFUL'] => ['vendor' => 'Sotupa', 'number' => 'DOUBTFUL', 'reasons' => 'name_doubtful', 'marked' => 0, 'note' => ''],
            $this->ids['MARKED'] => ['vendor' => 'Marked', 'number' => 'MARKED', 'reasons' => '', 'marked' => 1, 'note' => 'à relancer'],
        ];
        ksort($expected);
        self::assertSame($expected, $params, 'a vendor switched off is bought from no more');
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->company = $this->createCompany('Acme');
        $now = new \DateTimeImmutable();
        $make = function (string $number, string $name) use ($now): Vendor {
            $vendor = Vendor::create($this->company, $number, new VendorProfile($name), $now);
            $this->em()->persist($vendor);
            $this->ids[$number] = $vendor->getId()->toRfc4122();

            return $vendor;
        };
        $make('PLAIN', 'Plain');
        $make('DOUBTFUL', 'Sotupa')->markNameDoubtful($now);
        $make('MARKED', 'Marked')->reviseCheck(new CheckMark(true, 'à relancer'), false, $now);
        $retired = $make('RETIRED', 'Retired');
        $retired->markNameDoubtful($now);
        $retired->revise('RETIRED', $retired->getProfile(), false, $now);
        $this->em()->flush();
    }

    /**
     * @param list<Vendor> $vendors
     *
     * @return list<string> sorted
     */
    private static function numbers(array $vendors): array
    {
        $numbers = array_map(static fn (Vendor $vendor): string => $vendor->getNumber(), array_filter($vendors, static fn (Vendor $vendor): bool => $vendor->isActive()));
        sort($numbers);

        return $numbers;
    }

    /**
     * What a form sends back of a vendor it read: nothing only read.
     *
     * @param array<array-key, mixed> $vendor
     *
     * @return array<string, mixed>
     */
    private static function written(array $vendor): array
    {
        $written = [];
        foreach ($vendor as $field => $value) {
            if (\is_string($field) && !\in_array($field, ['id', 'checkReasons', 'mergedInto'], true)) {
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
