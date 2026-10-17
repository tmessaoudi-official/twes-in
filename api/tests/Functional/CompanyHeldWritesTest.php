<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Fiscal\Application\Company\ProvisionCompany;
use App\Identity\Application\StepUp\StepUpProofs;
use App\Identity\Domain\Model\User;
use App\Identity\Domain\ValueObject\Email;
use App\Tenancy\Application\Approval\DecideCompanyApproval;
use App\Tenancy\Application\Closing\CloseDownCompany;
use App\Tenancy\Application\Closing\ClosePeriod;
use App\Tenancy\Application\Company\RequireSecondFactor;
use App\Tenancy\Application\Invitation\AcceptInvitation;
use App\Tenancy\Application\Invitation\AcceptRequest;
use App\Tenancy\Domain\Model\Company;
use App\Tenancy\Domain\Model\Invitation;
use App\Tenancy\Domain\Model\Role;
use App\Tenancy\Domain\ValueObject\InvitationToken;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;

/**
 * A write no person's form made holds the company's row and works on what it holds (docs/SPEC.md § 3 « Saving over
 * someone else »): read as a request reads it, saved by someone else since, then written, it is never refused for
 * the version it did not send, and never decides on what it read before the other save.
 */
final class CompanyHeldWritesTest extends ApiTestCase
{
    private const string PASSWORD = 'password-1234';

    private Company $company;
    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->company = $this->createCompany('Acme');
        static::getContainer()->get(ProvisionCompany::class)->handle($this->company);
        $this->owner = $this->createUser('owner@twes.local', self::PASSWORD, $this->company);
    }

    public function testASuspensionIsWrittenOverASaveMadeSince(): void
    {
        $version = $this->savedSinceRead();

        static::getContainer()->get(DecideCompanyApproval::class)->reject($this->company->getId(), $this->owner->getId());

        self::assertSame([Company::STATUS_SUSPENDED, $version + 2], [$this->stored('status'), $this->storedVersion('company', $this->id())]);
    }

    /** Suspended since it was read: what was read said active, and only the held row says there is something to approve. */
    public function testAnApprovalDecidesOnTheRowItHolds(): void
    {
        $version = $this->savedSinceRead("status = 'suspended'");

        static::getContainer()->get(DecideCompanyApproval::class)->approve($this->company->getId(), $this->owner->getId());

        self::assertSame([Company::STATUS_ACTIVE, $version + 2], [$this->stored('status'), $this->storedVersion('company', $this->id())]);
    }

    public function testRequiringASecondFactorIsWrittenOverASaveMadeSince(): void
    {
        $version = $this->savedSinceRead();

        static::getContainer()->get(RequireSecondFactor::class)->handle($this->company, true, $this->owner->getId());

        self::assertSame([true, $version + 2], [$this->stored('mfa_required'), $this->storedVersion('company', $this->id())]);
    }

    public function testClosingAPeriodIsWrittenOverASaveMadeSince(): void
    {
        $version = $this->savedSinceRead();

        static::getContainer()->get(ClosePeriod::class)->handle($this->company, new \DateTimeImmutable('2026-01-31'), $this->owner->getId());

        self::assertSame(['2026-01-31', $version + 2], [$this->stored('closed_through'), $this->storedVersion('company', $this->id())]);
    }

    public function testClosingDownAndReopeningAreWrittenOverSavesMadeSince(): void
    {
        // The owner proved who they are a moment ago, in the session of the request this runs in.
        $request = new Request();
        $request->setSession(new Session(new MockArraySessionStorage()));
        static::getContainer()->get(RequestStack::class)->push($request);
        static::getContainer()->get(StepUpProofs::class)->remember($this->owner->getId(), new \DateTimeImmutable());
        $closing = static::getContainer()->get(CloseDownCompany::class);

        $version = $this->savedSinceRead();
        $closing->closeDown($this->company, $this->owner->getId());
        self::assertSame([Company::STATUS_CLOSED, $version + 2], [$this->stored('status'), $this->storedVersion('company', $this->id())]);

        $version = $this->savedSinceRead();
        $closing->reopen($this->company, $this->owner->getId());
        self::assertSame([Company::STATUS_ACTIVE, $version + 2], [$this->stored('status'), $this->storedVersion('company', $this->id())]);
    }

    public function testAnOwnerJoiningActivatesTheRowItHolds(): void
    {
        $role = $this->em()->getRepository(Role::class)->findOneBy(['company' => $this->company, 'name' => Role::OWNER]) ?? throw new \LogicException('no owner role');
        $token = InvitationToken::generate();
        $this->em()->persist(new Invitation($this->company, Email::fromString('second@twes.local'), $role, $token, new \DateTimeImmutable(), new \DateInterval('P7D'), $this->owner));
        $this->em()->flush();
        $version = $this->savedSinceRead("status = 'pending'");

        static::getContainer()->get(AcceptInvitation::class)->handle(new AcceptRequest($token->raw, 'Second Owner', 'a-long-password-5678'));

        self::assertSame([Company::STATUS_ACTIVE, $version + 2], [$this->stored('status'), $this->storedVersion('company', $this->id())]);
    }

    /**
     * Reads the company as a request reads it, then saves it behind that read's back, as another request would.
     *
     * @return int the version before the other save
     */
    private function savedSinceRead(string $alsoSet = 'name = name'): int
    {
        $version = $this->storedVersion('company', $this->id());
        // Kept as the request's own read: a request made since started another kernel, and the company with it.
        $this->company = $this->em()->find(Company::class, $this->company->getId()) ?? throw new \LogicException('no company');
        $this->em()->getConnection()->executeStatement("UPDATE company SET version = version + 1, $alsoSet WHERE id = ?", [$this->id()]);

        return $version;
    }

    private function stored(string $column): mixed
    {
        return $this->em()->getConnection()->fetchOne("SELECT $column FROM company WHERE id = ?", [$this->id()]);
    }

    private function id(): string
    {
        return $this->company->getId()->toRfc4122();
    }
}
