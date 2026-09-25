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
        $keys = array_column($rows, 'key');
        self::assertSame(['presentation.accent', 'presentation.scheme', 'presentation.density', 'presentation.sidebar', 'presentation.sidebar-settings', 'presentation.plan-labels', 'presentation.language', 'presentation.customer-view.cost', 'presentation.customer-view.supplier-codes', 'presentation.show-coming'], $keys);
        // Read by key and not by position: what each case below is about is one setting's own default, and an
        // ordinal makes every future presentation setting shift assertions that have nothing to do with it.
        $row = static function (string $key) use ($rows, $keys): array {
            $at = array_search($key, $keys, true);
            self::assertIsInt($at, $key.' is not in the presentation chain.');

            return $rows[$at];
        };
        self::assertSame('expanded', $row('presentation.sidebar')['value']);
        // The scheme follows the device until someone chooses (docs/SPEC.md § 7, 2026-09-16 review).
        self::assertSame('auto', $row('presentation.scheme')['value']);
        self::assertSame(['auto', 'light', 'dark'], $row('presentation.scheme')['choices']);
        // The plan writes the location's code until a reader asks for its name (docs/SPEC.md § 7, 2026-09-22).
        self::assertSame('code', $row('presentation.plan-labels')['value']);
        self::assertSame(['code', 'name', 'both'], $row('presentation.plan-labels')['choices']);
        // What customer view hides, a company's choice (docs/SPEC.md § 7, 2026-09-23 slice 5): both, until it says otherwise.
        self::assertTrue($row('presentation.customer-view.cost')['value']);
        self::assertTrue($row('presentation.customer-view.supplier-codes')['value']);
        // The interface language is remembered like any presentation choice, French until one is made.
        self::assertSame('fr', $row('presentation.language')['value']);
        self::assertSame(['fr', 'en'], $row('presentation.language')['choices']);
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

    public function testAnAdministratorSetsTheCompanysPaymentTerms(): void
    {
        $this->signedIn(['company.read', 'company.settings']);

        $this->getJson($this->path().'?chain=parties');
        self::assertResponseIsSuccessful();
        self::assertSame(['delivery_note.show_prices', 'delivery_note.reception_block', 'watch.late_after_days', 'document.payment_terms_days', 'document.language', 'document.printed_notes'], array_column($this->jsonList(), 'key'));
        $terms = $this->row('document.payment_terms_days');
        self::assertSame(30, $terms['value']);
        self::assertSame(['company'], $terms['writableLevels']);

        $this->sendJson('PUT', $this->path().'/document.payment_terms_days', ['level' => 'company', 'value' => 45]);
        self::assertResponseIsSuccessful();

        $this->getJson($this->path().'?chain=parties');
        self::assertSame(45, $this->row('document.payment_terms_days')['value']);
        $this->sendJson('PUT', $this->path().'/document.payment_terms_days', ['level' => 'user', 'value' => 10]);
        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
    }

    public function testEveryChainIsReadWithoutTheParameter(): void
    {
        $this->signedIn(['company.read']);

        $this->getJson($this->path());

        self::assertResponseIsSuccessful();
        $chains = array_map(fn (array $row): string => $this->stringAt($row, 'chain'), $this->jsonList());
        self::assertSame(['parties', 'articles', 'presentation', 'venue'], array_values(array_unique($chains)));
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
