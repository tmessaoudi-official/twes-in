<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Identity\Domain\Email;
use App\Identity\Domain\User;
use App\Tenancy\Domain\Company;
use App\Tenancy\Domain\Invitation;
use App\Tenancy\Domain\InvitationToken;
use App\Tenancy\Domain\Membership;
use App\Tenancy\Domain\Permission;
use App\Tenancy\Domain\Role;
use App\Tenancy\Infrastructure\ApiPlatform\MemberPermission;
use App\Tenancy\Infrastructure\ApiPlatform\RoleResource;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Uid\Uuid;

/**
 * A company's own roles through the API (docs/SPEC.md § 7, 2026-09-20 11:30, row 104). The three built-in roles are
 * defined by the release and are read-only here; everything else a company makes for itself.
 */
final class RolesTest extends ApiTestCase
{
    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();
        $this->company = $this->createCompany('Acme');
        $this->seedBuiltInRoles();
    }

    public function testTheListCarriesTheBuiltInRolesAndTheCompanysOwn(): void
    {
        $this->signedIn(['company.settings']);

        $this->postJson($this->path(), ['name' => 'barista', 'permissions' => ['customer.read']]);
        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);

        $this->getJson($this->path());
        self::assertResponseIsSuccessful();

        $rows = $this->jsonList();
        $byName = [];
        foreach ($rows as $row) {
            self::assertIsString($row['name']);
            $byName[$row['name']] = $row;
        }

        self::assertArrayHasKey(Role::OWNER, $byName);
        self::assertArrayHasKey(Role::ADMIN, $byName);
        self::assertArrayHasKey(Role::MEMBER, $byName);
        self::assertArrayHasKey('barista', $byName);

        self::assertTrue($byName[Role::OWNER]['builtIn'], 'a released role, not the company\'s');
        self::assertFalse($byName['barista']['builtIn']);
        self::assertSame(['customer.read'], $byName['barista']['permissions']);

        // The screen says "3 members hold this" before it offers to delete anything, so the count travels with the row.
        self::assertSame(1, $byName['tester']['memberCount'], 'the signed-in tester holds it');
        self::assertSame(0, $byName['barista']['memberCount']);
        self::assertSame(0, $byName[Role::OWNER]['memberCount'], 'a built-in role nobody here holds');
    }

    public function testACustomRoleIsCreatedRevisedAndDeleted(): void
    {
        $this->signedIn(['company.settings']);

        $this->postJson($this->path(), ['name' => 'waiter', 'permissions' => ['customer.read', 'product.read']]);
        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);
        $created = $this->json();
        self::assertIsString($created['id']);
        self::assertSame(['customer.read', 'product.read'], $created['permissions']);

        $this->sendJson('PUT', $this->path($created['id']), ['name' => 'server', 'permissions' => ['customer.read']]);
        self::assertResponseIsSuccessful();
        self::assertSame('server', $this->json()['name']);
        self::assertSame(['customer.read'], $this->json()['permissions']);

        $this->sendJson('DELETE', $this->path($created['id']));
        self::assertResponseStatusCodeSame(Response::HTTP_NO_CONTENT);

        $this->getJson($this->path());
        self::assertNotContains('server', array_column($this->jsonList(), 'name'));
    }

    public function testEveryChangeToARoleIsAudited(): void
    {
        // A role is what decides who may do what, so it is the last thing that should change unrecorded. The audit
        // row is also what an open screen hears: DoctrineAuditTrail stages each entry as a live change whose kind is
        // the entity type, which is why roles-page listens for `role` (docs/SPEC.md § 7, 2026-09-17).
        $this->signedIn(['company.settings']);

        $this->postJson($this->path(), ['name' => 'waiter', 'permissions' => ['customer.read']]);
        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);
        $created = $this->json();
        self::assertIsString($created['id']);

        $this->sendJson('PUT', $this->path($created['id']), ['name' => 'server', 'permissions' => ['product.read']]);
        self::assertResponseIsSuccessful();
        $this->sendJson('DELETE', $this->path($created['id']));
        self::assertResponseStatusCodeSame(Response::HTTP_NO_CONTENT);

        // The SET of actions, not their order: audit_log.at is a timestamp(0), so three requests inside one second
        // tie and the order becomes whatever the tie-break gives. Asserting the sequence passed or failed by the
        // second the test happened to run in, which is a flake, not a guarantee.
        $rows = $this->em()->getConnection()->fetchAllAssociative(
            "SELECT action, changes, actor_user_id FROM audit_log WHERE entity_type = 'role'",
        );
        $actions = array_column($rows, 'action');
        sort($actions);
        self::assertSame(
            ['role.created', 'role.deleted', 'role.revised'],
            $actions,
            'creating, revising and deleting a role each leave a row',
        );

        foreach ($rows as $row) {
            self::assertNotNull($row['actor_user_id'], 'the row says who did it, or it settles nothing');
        }

        // What the role was called and what it then granted: a row saying only "revised" cannot answer the question
        // an audit is read to answer, which is what somebody was allowed to do on a given day.
        $byAction = array_column($rows, null, 'action');
        $revised = $this->changesOf($byAction['role.revised']);
        self::assertSame('server', $revised['name']);
        self::assertSame(['product.read'], $revised['permissions']);
        $deleted = $this->changesOf($byAction['role.deleted']);
        self::assertSame('server', $deleted['name'], 'the name is in the row, because the role itself is gone');
    }

    /**
     * The `changes` column of one audit row, as the array it holds.
     *
     * @param array<string, mixed> $row
     *
     * @return array<mixed> as json_decode gives it: the keys are whatever the column holds, not a promise
     */
    private function changesOf(array $row): array
    {
        $changes = $row['changes'];
        self::assertIsString($changes, 'the column holds JSON text');
        $decoded = json_decode($changes, true);
        self::assertIsArray($decoded);

        return $decoded;
    }

    public function testABuiltInRoleIsNeverEditedOrDeleted(): void
    {
        $this->signedIn(['company.settings']);

        $this->getJson($this->path());
        $ownerId = $this->roleNamed($this->jsonList(), Role::OWNER)['id'];
        self::assertIsString($ownerId);

        $this->sendJson('PUT', $this->path($ownerId), ['name' => 'owner', 'permissions' => ['customer.read']]);
        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY, 'the release defines it, not the company');
        self::assertRefusedWith(RoleResource::BUILT_IN, $this->json());

        $this->sendJson('DELETE', $this->path($ownerId));
        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        self::assertRefusedWith(RoleResource::BUILT_IN, $this->json());
    }

    public function testARoleSomebodyHoldsIsNotDeletedSilently(): void
    {
        // The developer's ruling (2026-09-20 13:10 gate): refuse and name who holds it, rather than quietly moving
        // three people to another role — a demotion, or worse a promotion, that nobody would notice until it bit.
        $this->signedIn(['company.settings']);

        $this->postJson($this->path(), ['name' => 'cashier', 'permissions' => ['customer.read']]);
        $role = $this->json();
        self::assertIsString($role['id']);
        $this->giveRoleTo('sami@twes.local', $role['id']);

        $this->sendJson('DELETE', $this->path($role['id']));
        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        // The screen tells these two apart by the token, never by the sentence: a reworded or translated message
        // must not silently turn "somebody holds it" into "the release owns it".
        self::assertRefusedWith(RoleResource::IN_USE, $this->json());
        self::assertStringContainsString('sami@twes.local', (string) $this->client->getResponse()->getContent(), 'it names who holds it');
    }

    public function testARoleAPendingInvitationNamesIsNotDeletedEither(): void
    {
        // An invitation stores its role by NAME and carries no foreign key, so deleting the role raises nothing at
        // the database and the refusal above never fires — the invitation simply becomes unacceptable, and the
        // person finds that out when they click the link. Counted with the holders, and named the same way.
        $this->signedIn(['company.settings']);

        $this->postJson($this->path(), ['name' => 'runner', 'permissions' => ['customer.read']]);
        $role = $this->json();
        self::assertIsString($role['id']);

        // Written directly rather than through the members endpoint: the signed-in tester holds a CUSTOM role, and
        // by the rank rule such a holder grants nobody anything — which is the fail-closed behaviour row 104 kept.
        $this->inviteTo('invited@twes.local', 'runner');

        $this->sendJson('DELETE', $this->path($role['id']));
        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        self::assertRefusedWith(RoleResource::IN_USE, $this->json());
        self::assertStringContainsString('invited@twes.local', (string) $this->client->getResponse()->getContent());
    }

    public function testAPermissionTheCatalogueDoesNotKnowIsRefused(): void
    {
        $this->signedIn(['company.settings']);

        // A typo, or a permission removed in a later release: either way it would sit in the role for ever, granting
        // nothing and showing nowhere, which is the drift the collected catalogue exists to prevent.
        $this->postJson($this->path(), ['name' => 'ghost', 'permissions' => ['customer.reed']]);
        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        self::assertRefusedWith(RoleResource::UNKNOWN_PERMISSION, $this->json());

        $this->postJson($this->path(), ['name' => 'god', 'permissions' => [Permission::WILDCARD]]);
        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY, 'the wildcard is the owner\'s, never ticked');

        $this->postJson($this->path(), ['name' => 'operator', 'permissions' => ['platform.company.create']]);
        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY, 'a company never grants platform scope');
    }

    public function testTwoRolesOfOneCompanyCannotShareAName(): void
    {
        $this->signedIn(['company.settings']);

        $this->postJson($this->path(), ['name' => 'barista', 'permissions' => ['customer.read']]);
        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);

        $this->postJson($this->path(), ['name' => 'barista', 'permissions' => ['product.read']]);
        self::assertResponseStatusCodeSame(Response::HTTP_CONFLICT);

        // A built-in name is taken for every company, even though the row carrying it belongs to none of them.
        $this->postJson($this->path(), ['name' => 'admin', 'permissions' => ['product.read']]);
        self::assertResponseStatusCodeSame(Response::HTTP_CONFLICT);
    }

    public function testAnotherCompanysRoleIsNotFound(): void
    {
        $other = $this->createCompany('Globex');
        $this->createUser('intruder@twes.local', 'password-1234', $other, ['company.settings'], 'member');

        $this->signedIn(['company.settings']);
        $this->postJson($this->path(), ['name' => 'barista', 'permissions' => ['customer.read']]);
        $role = $this->json();
        self::assertIsString($role['id']);
        $this->postJson('/api/auth/logout', null);

        $this->login('intruder@twes.local', 'password-1234');
        self::assertResponseIsSuccessful();

        $this->sendJson('PUT', $this->path($role['id']), ['name' => 'theirs', 'permissions' => ['customer.read']]);
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND, 'it is not even addressed under this company');
    }

    /**
     * Gives a fresh account the company's own role, directly. Inviting someone at a custom role is its own change
     * (the members page still offers three names), so this test does not wait on it to prove the refusal.
     */
    /** An open invitation of this company naming a role, as sending one would have left behind. */
    private function inviteTo(string $email, string $roleName): void
    {
        $em = $this->em();
        $company = $em->find(Company::class, $this->company->getId());
        self::assertNotNull($company);
        $em->persist(new Invitation(
            $company,
            Email::fromString($email),
            $roleName,
            InvitationToken::generate(),
            new \DateTimeImmutable(),
            new \DateInterval('P7D'),
            null,
        ));
        $em->flush();
    }

    private function giveRoleTo(string $email, string $roleId): void
    {
        $em = $this->em();
        $role = $em->find(Role::class, Uuid::fromString($roleId));
        self::assertInstanceOf(Role::class, $role);
        $company = $em->find(Company::class, $this->company->getId());
        self::assertInstanceOf(Company::class, $company);
        $user = new User(Email::fromString($email), 'Sami');
        $em->persist($user);
        $em->persist(new Membership($user, $company, $role));
        $em->flush();
    }

    public function testEditingRolesNeedsTheRightThatEditingSettingsNeeds(): void
    {
        // A refusal is 404, the guard's rule: a permission not held tells a stranger nothing about what exists.
        $this->signedIn([MemberPermission::READ, 'company.read']);

        $this->getJson($this->path());
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);

        $this->postJson($this->path(), ['name' => 'barista', 'permissions' => ['customer.read']]);
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
    }

    /** @param array<string, mixed> $body */
    private static function assertRefusedWith(string $code, array $body): void
    {
        $detail = $body['detail'] ?? '';
        self::assertIsString($detail);
        self::assertStringStartsWith($code.':', $detail, 'the refusal leads with the token the screen reads');
    }

    /**
     * @param list<array<string, mixed>> $rows
     *
     * @return array<string, mixed>
     */
    private function roleNamed(array $rows, string $name): array
    {
        foreach ($rows as $row) {
            if ($name === $row['name']) {
                return $row;
            }
        }

        self::fail(\sprintf('no role named "%s" in the list', $name));
    }

    /** @param list<string> $permissions */
    private function signedIn(array $permissions): void
    {
        $this->createUser('admin@twes.local', 'password-1234', $this->company, $permissions, 'tester');
        $this->login('admin@twes.local', 'password-1234');
        self::assertResponseIsSuccessful();
    }

    private function path(?string $roleId = null): string
    {
        $base = \sprintf('/api/companies/%s/roles', $this->company->getId()->toRfc4122());

        return null === $roleId ? $base : $base.'/'.$roleId;
    }
}
