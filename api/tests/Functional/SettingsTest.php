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

/**
 * A company's settings through the chains: anyone in the company reads them and keeps their own preferences;
 * company and role defaults belong to whoever may change the company's settings.
 */
final class SettingsTest extends ApiTestCase
{
    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();
        $this->company = $this->createCompany('Acme');
    }

    public function testAMemberReadsThePresentationChainWithItsDefaults(): void
    {
        $this->signedIn(['company.read']);

        $this->getJson($this->path().'?chain=presentation');

        self::assertResponseIsSuccessful();
        $rows = $this->jsonList();
        self::assertSame(['presentation.accent', 'presentation.scheme', 'presentation.density'], array_column($rows, 'key'));
        $accent = $rows[0];
        self::assertSame('#1f6feb', $accent['value']);
        self::assertSame('#1f6feb', $accent['default']);
        self::assertNull($accent['source']);
        self::assertSame('colour', $accent['type']);
        self::assertSame('presentation', $accent['chain']);
        self::assertSame([], $accent['levels']);
        self::assertSame(['company', 'role', 'user'], $accent['overridableLevels']);
        self::assertSame(['user'], $accent['writableLevels']);
        self::assertSame(['comfortable', 'compact'], $rows[2]['choices']);
    }

    public function testAPreferenceOfTheUserWinsOverTheCompanyDefault(): void
    {
        $this->signedIn(['company.read', 'company.settings']);

        $this->sendJson('PUT', $this->path().'/presentation.density', ['level' => 'company', 'value' => 'compact']);
        self::assertResponseIsSuccessful();
        self::assertSame('company', $this->json()['source']);
        $this->sendJson('PUT', $this->path().'/presentation.density', ['level' => 'user', 'value' => 'comfortable']);
        self::assertResponseIsSuccessful();

        $this->getJson($this->path().'?chain=presentation');
        $density = $this->row('presentation.density');
        self::assertSame('comfortable', $density['value']);
        self::assertSame('user', $density['source']);
        self::assertSame([['level' => 'company', 'value' => 'compact'], ['level' => 'user', 'value' => 'comfortable']], $density['levels']);
        self::assertSame(['company', 'role', 'user'], $density['writableLevels']);
    }

    public function testAResetFallsBackToTheCompanyDefault(): void
    {
        $this->signedIn(['company.read', 'company.settings']);
        $this->sendJson('PUT', $this->path().'/presentation.density', ['level' => 'company', 'value' => 'compact']);
        $this->sendJson('PUT', $this->path().'/presentation.density', ['level' => 'user', 'value' => 'comfortable']);

        $this->sendJson('DELETE', $this->path().'/presentation.density?level=user');

        self::assertResponseStatusCodeSame(Response::HTTP_NO_CONTENT);
        $this->getJson($this->path().'?chain=presentation');
        self::assertSame('compact', $this->row('presentation.density')['value']);
        self::assertSame('company', $this->row('presentation.density')['source']);
    }

    public function testAMemberKeepsTheirOwnPreferenceButMayNotChangeTheCompanyDefault(): void
    {
        $this->signedIn(['company.read']);

        $this->sendJson('PUT', $this->path().'/presentation.density', ['level' => 'user', 'value' => 'compact']);
        self::assertResponseIsSuccessful();

        $this->sendJson('PUT', $this->path().'/presentation.density', ['level' => 'company', 'value' => 'compact']);
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
        $this->sendJson('DELETE', $this->path().'/presentation.density?level=company');
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
    }

    public function testARoleDefaultReachesThatRole(): void
    {
        $this->signedIn(['company.read', 'company.settings']);
        $role = $this->em()->getRepository(Role::class)->findOneBy(['company' => $this->company, 'name' => 'accountant']);
        self::assertNotNull($role);

        $this->sendJson('PUT', $this->path().'/presentation.scheme', ['level' => 'role', 'roleId' => $role->getId()->toRfc4122(), 'value' => 'dark']);

        self::assertResponseIsSuccessful();
        self::assertSame('role', $this->json()['source']);
        $this->getJson($this->path().'?chain=presentation');
        self::assertSame('dark', $this->row('presentation.scheme')['value']);
    }

    public function testARoleOfAnotherCompanyIsNotFound(): void
    {
        $this->signedIn(['company.read', 'company.settings']);
        $foreign = new Role('clerk', ['company.read'], $this->createCompany('Globex'));
        $this->em()->persist($foreign);
        $this->em()->flush();

        $this->sendJson('PUT', $this->path().'/presentation.scheme', ['level' => 'role', 'roleId' => $foreign->getId()->toRfc4122(), 'value' => 'dark']);

        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
    }

    public function testAListLayoutIsKeptUnderItsOwnKey(): void
    {
        $this->signedIn(['company.read']);
        $layout = ['hidden' => ['email'], 'order' => ['role', 'name'], 'widths' => ['name' => 240], 'sort' => null];

        $this->sendJson('PUT', $this->path().'/presentation.list.members', ['level' => 'user', 'value' => $layout]);
        self::assertResponseIsSuccessful();

        $this->getJson($this->path().'?chain=presentation');
        self::assertEquals($layout, $this->row('presentation.list.members')['value']);
    }

    public function testAnUnknownSettingIsNotFound(): void
    {
        $this->signedIn(['company.read']);

        $this->sendJson('PUT', $this->path().'/presentation.invented', ['level' => 'user', 'value' => 'x']);

        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
    }

    public function testAValueOfTheWrongShapeIsUnprocessable(): void
    {
        $this->signedIn(['company.read']);

        $this->sendJson('PUT', $this->path().'/presentation.density', ['level' => 'user', 'value' => 'cosy']);

        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
    }

    public function testALevelTheSettingDoesNotAllowIsUnprocessable(): void
    {
        $this->signedIn(['company.read', 'company.settings']);

        $this->sendJson('PUT', $this->path().'/presentation.list.members', ['level' => 'company', 'value' => ['hidden' => []]]);
        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);

        $this->sendJson('PUT', $this->path().'/presentation.density', ['level' => 'galaxy', 'value' => 'compact']);
        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
    }

    public function testAnUnknownChainIsABadRequest(): void
    {
        $this->signedIn(['company.read']);

        $this->getJson($this->path().'?chain=galaxy');

        self::assertResponseStatusCodeSame(Response::HTTP_BAD_REQUEST);
    }

    public function testAnotherCompanysSettingsAreNotFound(): void
    {
        $this->signedIn(['company.read', 'company.settings']);
        $other = $this->createCompany('Globex');

        $this->getJson('/api/companies/'.$other->getId()->toRfc4122().'/settings?chain=presentation');
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
        $this->sendJson('PUT', '/api/companies/'.$other->getId()->toRfc4122().'/settings/presentation.density', ['level' => 'user', 'value' => 'compact']);
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
    }

    public function testAnAnonymousCallerIsRefused(): void
    {
        $this->getJson($this->path().'?chain=presentation');

        self::assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
    }

    /** @param list<string> $permissions */
    private function signedIn(array $permissions): void
    {
        $this->createUser('accountant@twes.local', 'password-1234', $this->company, $permissions, 'accountant');
        $this->login('accountant@twes.local', 'password-1234');
        self::assertResponseIsSuccessful();
    }

    /** @return array<string, mixed> */
    private function row(string $key): array
    {
        foreach ($this->jsonList() as $row) {
            if ($row['key'] === $key) {
                return $row;
            }
        }
        self::fail("No setting $key in the response.");
    }

    private function path(): string
    {
        return '/api/companies/'.$this->company->getId()->toRfc4122().'/settings';
    }
}
