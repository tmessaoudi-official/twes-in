<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Tenancy\Domain\Company;
use App\Tenancy\Domain\Role;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Uid\Uuid;

/** The members of a company: listed, added and removed only by someone that company is any business of. */
final class MembersTest extends ApiTestCase
{
    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedBuiltInRoles();
        $this->company = $this->createCompany('Acme');
    }

    public function testAnAdminListsTheMembers(): void
    {
        $this->adminSignedIn();

        $this->getJson($this->path());

        self::assertResponseIsSuccessful();
        $rows = $this->jsonList();
        self::assertCount(1, $rows);
        self::assertSame('admin@twes.local', $rows[0]['email']);
    }

    public function testAnAdminAddsSomeoneWhoAlreadyHasAnAccount(): void
    {
        $this->adminSignedIn();
        $this->createUser('joiner@twes.local', 'password-1234');

        $this->postJson($this->path(), ['email' => 'joiner@twes.local', 'role' => Role::MEMBER]);

        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);
        $body = $this->json();
        self::assertSame('joiner@twes.local', $body['email']);
        self::assertSame(Role::MEMBER, $body['role']);
    }

    public function testAnAddressWithNoAccountIsInvitedInstead(): void
    {
        $this->adminSignedIn();

        $this->postJson($this->path(), ['email' => 'stranger@twes.local', 'role' => Role::MEMBER]);

        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);
        $body = $this->json();
        self::assertSame('invited', $body['status']);
        self::assertNull($body['userId']);
        self::assertEmailCount(1);
    }

    public function testSomeoneWhoAlreadyHasAnAccountJoinsWithNoMail(): void
    {
        $this->adminSignedIn();
        $this->createUser('joiner@twes.local', 'password-1234');

        $this->postJson($this->path(), ['email' => 'joiner@twes.local', 'role' => Role::MEMBER]);

        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);
        self::assertSame('joined', $this->json()['status']);
        self::assertEmailCount(0);
    }

    public function testAMalformedAddressIsRefused(): void
    {
        $this->adminSignedIn();

        $this->postJson($this->path(), ['email' => 'not-an-address', 'role' => Role::MEMBER]);

        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
    }

    public function testAnInventedRoleIsRefused(): void
    {
        $this->adminSignedIn();
        $this->createUser('joiner@twes.local', 'password-1234');

        $this->postJson($this->path(), ['email' => 'joiner@twes.local', 'role' => 'emperor']);

        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
    }

    public function testTheSamePersonIsNotAddedTwice(): void
    {
        $this->adminSignedIn();
        $this->createUser('joiner@twes.local', 'password-1234');
        $this->postJson($this->path(), ['email' => 'joiner@twes.local', 'role' => Role::MEMBER]);
        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);

        $this->postJson($this->path(), ['email' => 'joiner@twes.local', 'role' => Role::MEMBER]);

        self::assertResponseStatusCodeSame(Response::HTTP_CONFLICT);
    }

    public function testAMemberIsRemoved(): void
    {
        $this->adminSignedIn();
        $joiner = $this->createUser('joiner@twes.local', 'password-1234');
        $this->postJson($this->path(), ['email' => 'joiner@twes.local', 'role' => Role::MEMBER]);
        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);

        $this->sendJson('DELETE', $this->path().'/'.$joiner->getId()->toRfc4122());

        self::assertResponseStatusCodeSame(Response::HTTP_NO_CONTENT);
    }

    public function testSomeoneWhoIsNotAMemberCannotBeRemoved(): void
    {
        $this->adminSignedIn();

        $this->sendJson('DELETE', $this->path().'/'.Uuid::v7()->toRfc4122());

        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
    }

    public function testACompanyTheCallerHasNothingToDoWithLooksAbsent(): void
    {
        $theirs = $this->createCompany('Globex');
        $this->adminSignedIn();

        $this->getJson('/api/companies/'.$theirs->getId()->toRfc4122().'/members');

        // 404, never 403: a 403 would confirm the company exists, which is how a tenant list is enumerated.
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
    }

    public function testACompanyThatDoesNotExistAnswersTheSameWay(): void
    {
        $this->adminSignedIn();

        $this->getJson('/api/companies/'.Uuid::v7()->toRfc4122().'/members');

        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
    }

    public function testNobodyMayBeAddedToACompanyTheCallerIsNotIn(): void
    {
        $theirs = $this->createCompany('Globex');
        $this->adminSignedIn();
        $this->createUser('joiner@twes.local', 'password-1234');

        $this->postJson('/api/companies/'.$theirs->getId()->toRfc4122().'/members', ['email' => 'joiner@twes.local', 'role' => Role::MEMBER]);

        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
    }

    public function testAPlainMemberMayNotManageMembers(): void
    {
        $this->createUser('plain@twes.local', 'password-1234', $this->company, ['company.read'], Role::MEMBER);
        $this->login('plain@twes.local', 'password-1234');

        $this->getJson($this->path());

        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
    }

    public function testAnOperatorReachesACompanyTheyAreNotAMemberOf(): void
    {
        $this->createUser('op@twes.local', 'password-1234', operator: true);
        $this->login('op@twes.local', 'password-1234');

        $this->getJson($this->path());

        // The operator opens a company and adds its first owner, so they are never a member of it yet.
        self::assertResponseIsSuccessful();
    }

    public function testTheLastOwnerCannotBeRemoved(): void
    {
        $owner = $this->createUser('owner@twes.local', 'password-1234', $this->company, ['*'], Role::OWNER);
        $this->login('owner@twes.local', 'password-1234');

        $this->sendJson('DELETE', $this->path().'/'.$owner->getId()->toRfc4122());

        self::assertResponseStatusCodeSame(Response::HTTP_CONFLICT);
    }

    public function testAnAnonymousCallerIsRefused(): void
    {
        $this->getJson($this->path());

        self::assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
    }

    public function testAMalformedCompanyIdentifierIsNotAnError(): void
    {
        $this->adminSignedIn();

        $this->getJson('/api/companies/not-a-uuid/members');

        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
    }

    public function testARefusalIsStillJsonForABrowserThatAcceptsAnything(): void
    {
        $this->adminSignedIn();

        // A browser sends "Accept: */*". With jsonld first in error_formats and jsonld not enabled, that
        // negotiated an unsupported format and every refusal became a 500. An invented role is the refusal
        // used here because an unknown address is no longer one: it becomes an invitation.
        $this->client->request(
            'POST',
            $this->path(),
            [],
            [],
            ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => '*/*', 'HTTP_CSRF_TOKEN' => '0123456789abcdef0123456789abcdef'],
            json_encode(['email' => 'joiner@twes.local', 'role' => 'emperor'], \JSON_THROW_ON_ERROR),
        );

        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        self::assertStringContainsString('json', (string) $this->client->getResponse()->headers->get('Content-Type'));
    }

    private function path(): string
    {
        return '/api/companies/'.$this->company->getId()->toRfc4122().'/members';
    }

    private function adminSignedIn(): void
    {
        $this->createUser('admin@twes.local', 'password-1234', $this->company, ['user.read', 'user.write'], Role::ADMIN);
        $this->login('admin@twes.local', 'password-1234');
        self::assertResponseIsSuccessful();
    }
}
