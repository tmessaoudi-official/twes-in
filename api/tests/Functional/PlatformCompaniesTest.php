<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Identity\Domain\ValueObject\Email;
use App\Tenancy\Domain\Model\Company;
use App\Tenancy\Domain\Model\Invitation;
use App\Tenancy\Domain\Model\Role;
use App\Tenancy\Domain\ValueObject\InvitationToken;
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

    public function testSeveralStatusesAndCountriesCombine(): void
    {
        $this->company('Alpha', country: 'FR');
        $this->company('Bravo', country: 'TN');
        $this->company('Charlie', country: 'TN', status: 'pending');
        $this->company('Delta', country: 'FR', status: 'suspended');
        $this->company('Echo', country: 'MA', status: 'pending');
        $this->signedInAsOperator();

        foreach ([
            'status[]=pending&status[]=suspended' => ['Charlie', 'Delta', 'Echo'],
            'countryCode[]=FR&countryCode[]=MA' => ['Alpha', 'Delta', 'Echo'],
            'status[]=pending&status[]=active&countryCode[]=TN&countryCode[]=MA' => ['Bravo', 'Charlie', 'Echo'],
            'status=suspended&countryCode=FR' => ['Delta'],
        ] as $query => $names) {
            $this->getJson(self::PATH.'?'.$query);
            self::assertResponseIsSuccessful($query);
            self::assertSame($names, array_column($this->jsonList(), 'name'), $query);
            self::assertSame(\count($names), $this->jsonPage()['totalItems'], $query);
        }

        foreach (['status[]=pending&status[]=sleeping', 'countryCode[]=TN&countryCode[]=tunisia'] as $refused) {
            $this->getJson(self::PATH.'?'.$refused);
            self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY, $refused);
        }
    }

    /**
     * A company refused before it was ever opened is stored suspended, but it is not one whose access was taken away:
     * « refused » finds it alone, and « suspended » leaves it out, so the operator can tell the two apart.
     */
    public function testARefusedCompanyIsFoundAsRefusedAndNotAsSuspended(): void
    {
        $this->company('Alpha', country: 'TN');
        $this->company('Bravo', country: 'TN', status: 'suspended');
        $this->company('Charlie', country: 'TN', status: 'refused');
        $this->company('Delta', country: 'FR', status: 'refused');
        $this->company('Echo', country: 'TN', status: 'pending');
        $this->signedInAsOperator();

        foreach ([
            'status=refused' => ['Charlie', 'Delta'],
            'status=suspended' => ['Bravo'],
            'status[]=suspended&status[]=refused' => ['Bravo', 'Charlie', 'Delta'],
            'status[]=refused&status[]=pending' => ['Charlie', 'Delta', 'Echo'],
            'status=refused&countryCode=FR' => ['Delta'],
        ] as $query => $names) {
            $this->getJson(self::PATH.'?'.$query);
            self::assertResponseIsSuccessful($query);
            self::assertSame($names, array_column($this->jsonList(), 'name'), $query);
            self::assertSame(\count($names), $this->jsonPage()['totalItems'], $query);
        }

        $this->getJson(self::PATH.'?status=refused');
        self::assertSame([['suspended', false], ['suspended', false]], array_map(static fn (array $row): array => [$row['status'], $row['opened']], $this->jsonList()), 'a refused row is still the stored status, never opened');
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

    /** Workflow hunt, F3's second member: an owner invitation sent from here, open or run out lately, is said on its row. */
    public function testARowSaysItsOwnerInvitationOpenOrRunOutLately(): void
    {
        $alphaCompany = $this->company('Alpha');
        $alpha = $alphaCompany->getId()->toRfc4122();
        $bravo = $this->company('Bravo')->getId()->toRfc4122();
        $this->company('Charlie', owner: 'karim@nord.example');
        $this->seedBuiltInRoles();
        $this->signedInAsOperator();
        $this->stepUp('password-1234');
        foreach ([[$alpha, 'late@alpha.example'], [$bravo, 'open@bravo.example']] as [$company, $email]) {
            $this->postJson(self::PATH.'/'.$company.'/owners', ['email' => $email]);
            self::assertResponseStatusCodeSame(Response::HTTP_CREATED);
        }
        $this->em()->getConnection()->executeStatement("UPDATE invitation SET expires_at = expires_at - INTERVAL '8 days' WHERE email = 'late@alpha.example'");
        // An invitation to another role is the company's own business, not an owner the platform waits for.
        $admin = $this->em()->getRepository(Role::class)->findOneBy(['name' => Role::ADMIN, 'company' => null]);
        self::assertNotNull($admin);
        $this->em()->persist(new Invitation($this->em()->find(Company::class, $alphaCompany->getId()) ?? self::fail('Alpha is gone'), Email::fromString('admin@alpha.example'), $admin, InvitationToken::generate(), new \DateTimeImmutable(), new \DateInterval('P7D'), null));
        $this->em()->flush();

        $this->getJson(self::PATH);
        $rows = array_column($this->jsonList(), 'ownerInvitations', 'name');
        $said = array_map(static fn (mixed $invitations): array => array_map(
            static fn (mixed $invitation): array => \is_array($invitation) ? [$invitation['email'] ?? null, $invitation['expired'] ?? null, \is_string($invitation['expiresAt'] ?? null)] : [],
            \is_array($invitations) ? $invitations : [],
        ), $rows);
        self::assertSame(['Alpha' => [['late@alpha.example', true, true]], 'Bravo' => [['open@bravo.example', false, true]], 'Charlie' => []], $said);

        $this->em()->getConnection()->executeStatement("UPDATE invitation SET expires_at = expires_at - INTERVAL '60 days' WHERE email = 'late@alpha.example'");
        $this->getJson(self::PATH.'?q=Alpha');
        self::assertSame([], $this->jsonList()[0]['ownerInvitations'] ?? null, 'run out long ago, it is forgotten as on the members page');
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
        // A refused company is one suspended while it still waited, never opened.
        $company = \in_array($status, ['pending', 'refused'], true) ? Company::pending($name, $country, 'TND', 'fr', 'Africa/Tunis') : new Company($name, $country, 'TND', 'fr', 'Africa/Tunis');
        if (\in_array($status, ['suspended', 'refused'], true)) {
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
