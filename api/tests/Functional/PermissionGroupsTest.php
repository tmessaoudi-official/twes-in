<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Tenancy\Application\Seed\SeedPlatform;
use App\Tenancy\Domain\Company;
use App\Tenancy\Domain\Permission;
use App\Tenancy\Domain\Role;
use App\Tenancy\Infrastructure\ApiPlatform\MemberPermission;
use Symfony\Component\HttpFoundation\Response;

/**
 * The permission catalogue through the API (docs/SPEC.md § 7, 2026-09-20 11:30, row 104): what a roles screen offers
 * a company to tick.
 */
final class PermissionGroupsTest extends ApiTestCase
{
    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();
        $this->company = $this->createCompany('Acme');
    }

    public function testSomeoneEditingSettingsReadsEveryPermissionARoleMayHold(): void
    {
        $this->signedIn(['company.settings']);

        $this->getJson($this->path());
        self::assertResponseIsSuccessful();

        $keys = [];
        $offered = [];
        foreach ($this->jsonList() as $group) {
            self::assertSame(['key', 'labelKey', 'permissions'], array_keys($group), 'a heading, its label and what sits under it');
            self::assertIsString($group['key']);
            self::assertIsArray($group['permissions']);
            self::assertNotEmpty($group['permissions'], "the {$group['key']} group would be an empty heading");
            $keys[] = $group['key'];
            foreach ($group['permissions'] as $permission) {
                self::assertIsString($permission);
                $offered[] = $permission;
            }
        }
        self::assertNotEmpty($keys);

        // The three groups that belong to no module, and one that does, so both halves of the catalogue are answered.
        self::assertSame(
            ['company', 'members', 'fiscal'],
            \array_slice($keys, 0, 3),
            'what a company runs on comes before the modules, which follow in key order',
        );
        self::assertContains('inventory', $keys);

        // Every permission the built-in admin holds is offerable, or a company cannot build a role that matches it.
        self::assertSame([], array_values(array_diff(SeedPlatform::BUILT_IN_ROLES[Role::ADMIN], $offered)));
        self::assertContains(MemberPermission::READ, $offered);
        // The owner holds the wildcard, which is not a permission and is never a row to tick.
        self::assertNotContains(Permission::WILDCARD, $offered);
        self::assertSame([], array_values(array_filter($offered, static fn (string $p): bool => Permission::fromString($p)->isPlatformScoped())));
    }

    public function testReadingItNeedsTheRightThatEditingRolesWillNeed(): void
    {
        // company.read is enough to USE the product; the catalogue is only of use to someone changing what a role may
        // do, so it is behind the same right as the rest of a company's settings. A permission not held reads here as
        // "no such company" rather than "forbidden" — the guard's own rule, so that a refusal tells a stranger nothing.
        $this->signedIn(['company.read']);

        $this->getJson($this->path());
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
    }

    public function testAnotherCompanysMemberIsNotAnsweredAtAll(): void
    {
        $other = $this->createCompany('Globex');
        $this->signedIn(['company.settings'], $other);

        $this->getJson($this->path());
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
    }

    /** @param list<string> $permissions */
    private function signedIn(array $permissions, ?Company $company = null): void
    {
        $this->createUser('admin@twes.local', 'password-1234', $company ?? $this->company, $permissions, 'member');
        $this->login('admin@twes.local', 'password-1234');
        self::assertResponseIsSuccessful();
    }

    private function path(): string
    {
        return \sprintf('/api/companies/%s/permission-groups', $this->company->getId()->toRfc4122());
    }
}
