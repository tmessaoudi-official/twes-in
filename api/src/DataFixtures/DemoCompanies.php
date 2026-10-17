<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\DataFixtures;

use App\Demo\Infrastructure\Dataset\DemoCatalogue;
use App\Demo\Infrastructure\Dataset\DemoCompany;
use App\Demo\Infrastructure\Dataset\DemoDataset;
use App\Demo\Infrastructure\Dataset\OpenedCompany;
use App\Identity\Domain\Repository\UserRepository;
use App\Identity\Domain\ValueObject\Email;
use App\Tenancy\Application\Invitation\AcceptInvitation;
use App\Tenancy\Application\Invitation\AcceptRequest;
use App\Tenancy\Application\Invitation\InviteRequest;
use App\Tenancy\Application\Invitation\InviteToCompany;
use App\Tenancy\Application\Seed\SeedPlatform;
use App\Tenancy\Application\Seed\SeedRequest;
use App\Tenancy\Domain\Repository\CompanyRepository;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Persistence\ObjectManager;
use Symfony\Component\Uid\Uuid;

/**
 * The demo companies of `DemoCatalogue` (docs/SPEC.md § 7, 2026-09-19), owned by the seeded operator and written by
 * `DemoDataset`, each with one member per built-in role.
 *
 * Load with `make fixtures` (`doctrine:fixtures:load --append`: never without it, which empties the database). A
 * company that already exists is left as it is, so a second load changes nothing.
 */
final class DemoCompanies extends Fixture
{
    public const string OPERATOR = 'operator@twes.local';
    /**
     * One member per built-in role in every demo company, so what a role may not do is something a person can sign
     * in and meet rather than read about (§ 7, 2026-09-20). They join through the invitation use cases like any
     * other member, so their memberships, audit rows and password rules are the product's own and not seeded rows.
     * Development passwords, on a development dataset: `make fixtures` refuses to run outside dev and test.
     */
    public const array TESTERS = [
        'owner' => 'owner@twes.local',
        'admin' => 'admin@twes.local',
        'member' => 'member@twes.local',
        'clerk' => 'clerk@twes.local',
        'accountant' => 'accountant@twes.local',
    ];
    /** Long enough for the password policy, and not a word any breach list carries. */
    public const string TESTER_PASSWORD = 'twes-role-test-2026';

    public function __construct(
        private readonly UserRepository $users,
        private readonly CompanyRepository $companies,
        private readonly SeedPlatform $seed,
        private readonly DemoDataset $dataset,
        private readonly InviteToCompany $invitations,
        private readonly AcceptInvitation $acceptances,
        private readonly CapturingInvitationMailer $mailed,
        private readonly ImmediateInvitationMail $mailNow,
    ) {
    }

    public function load(ObjectManager $manager): void
    {
        $operatorId = ($this->users->ofEmail(Email::fromString(self::OPERATOR))
            ?? throw new \RuntimeException(\sprintf('The demo companies belong to %s, who does not exist yet: run make seed first.', self::OPERATOR)))->getId();

        foreach (DemoCatalogue::all() as $demo) {
            if (null !== $this->companies->ofName($demo->name)) {
                continue;
            }
            $this->dataset->write($demo, function (DemoCompany $demo) use ($operatorId): OpenedCompany {
                // The seed is the one path that makes a company active, owned by the operator and provisioned at once.
                $this->seed->seed(new SeedRequest(self::OPERATOR, null, 'Operator', $demo->name, $demo->country, $demo->currency, 'fr', $demo->timezone));
                $companyId = ($this->companies->ofName($demo->name) ?? throw new \LogicException("$demo->name was seeded a moment ago."))->getId();
                $this->addTesters($companyId, $operatorId);

                return new OpenedCompany($companyId, $operatorId);
            });
        }
    }

    /**
     * Puts one person of each built-in role into the company, through the invitation the product itself sends.
     *
     * Nothing is written directly: the address is invited and the invitation is then accepted, so each member gets
     * the membership, the audit rows and the password checks any real member gets. The second company invites the
     * same addresses again, and accepting with an account that already exists simply adds the membership —
     * which is why the name and password are only offered the first time.
     */
    private function addTesters(Uuid $companyId, Uuid $actor): void
    {
        foreach (self::TESTERS as $role => $email) {
            // Mailed here and now, not by the worker: the token is read off this run's own mail just below.
            $this->mailNow->during(fn () => $this->invitations->handle(new InviteRequest($companyId, $email, $role), $actor));
            $known = null !== $this->users->ofEmail(Email::fromString($email));
            $this->acceptances->handle(new AcceptRequest(
                $this->mailed->tokenFor($email),
                $known ? null : ucfirst($role).' Demo',
                $known ? null : self::TESTER_PASSWORD,
            ));
        }
    }
}
