<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tenancy\Application\Approval;

use App\Shared\Domain\ValueObject\Page;
use App\Shared\Domain\ValueObject\PageRequest;
use App\Tenancy\Application\Company\DemoCompanies;
use App\Tenancy\Domain\Model\Company;
use App\Tenancy\Domain\Model\Invitation;
use App\Tenancy\Domain\Model\Role;
use App\Tenancy\Domain\Repository\CompanyRepository;
use App\Tenancy\Domain\Repository\InvitationRepository;
use App\Tenancy\Domain\Repository\MembershipRepository;
use App\Tenancy\Domain\ValueObject\CompanySearch;
use Psr\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Uid\Uuid;

/**
 * The companies as the platform's operators see them, across every tenant: only an operator's endpoint reaches this.
 * The demo's sandboxes are made-up companies a visitor tries the product in, no operator's concern, so the list leaves
 * them out. Beside its owners, a company says the owner invitations nobody used, open or run out within the window the
 * members page keeps them, so an operator sees that the one they sent lapsed and sends it again.
 */
final readonly class PlatformCompanies
{
    public function __construct(
        private CompanyRepository $companies,
        private MembershipRepository $memberships,
        private DemoCompanies $demos,
        private InvitationRepository $invitations,
        private ClockInterface $clock,
        #[Autowire(param: 'app.invitation.expired_shown_for')]
        private string $expiredShownFor,
    ) {
    }

    /**
     * One page of the companies, narrowed by words, status and country and sorted in the database.
     *
     * @return Page<PlatformCompanyView>
     */
    public function search(CompanySearch $search, PageRequest $page): Page
    {
        $found = $this->companies->search($search->leavingOut($this->demos->all()), $page);
        $owners = [];
        foreach ($this->memberships->ownersOfCompanies(array_map(static fn (Company $company): Uuid => $company->getId(), $found->items)) as $membership) {
            $owners[$membership->getCompany()->getId()->toRfc4122()][] = $membership->getUser()->getEmail()->value;
        }

        $invited = $this->ownerInvitations(array_map(static fn (Company $company): Uuid => $company->getId(), $found->items));

        return $found->map(fn (Company $company): PlatformCompanyView => $this->viewWith($company, $owners[$company->getId()->toRfc4122()] ?? [], $invited[$company->getId()->toRfc4122()] ?? []));
    }

    public function viewOf(Company $company): PlatformCompanyView
    {
        $owners = [];
        foreach ($this->memberships->ofCompany($company->getId()) as $membership) {
            if (Role::OWNER === $membership->getRole()->getName()) {
                $owners[] = $membership->getUser()->getEmail()->value;
            }
        }

        return $this->viewWith($company, $owners, $this->ownerInvitations([$company->getId()])[$company->getId()->toRfc4122()] ?? []);
    }

    /**
     * @param list<Uuid> $companyIds
     *
     * @return array<string, list<PlatformOwnerInvitationView>> by company
     */
    private function ownerInvitations(array $companyIds): array
    {
        $now = $this->clock->now();
        $invited = [];
        foreach ($this->invitations->unusedOfCompanies($companyIds, $now->sub(new \DateInterval($this->expiredShownFor))) as $invitation) {
            if (Role::OWNER !== $invitation->getRoleName()) {
                continue;
            }
            $invited[$invitation->getCompany()->getId()->toRfc4122()][] = self::invitation($invitation, $now);
        }

        return $invited;
    }

    private static function invitation(Invitation $invitation, \DateTimeImmutable $now): PlatformOwnerInvitationView
    {
        return new PlatformOwnerInvitationView($invitation->getEmail()->value, $invitation->getExpiresAt()->format(\DateTimeInterface::ATOM), !$invitation->isUsableAt($now));
    }

    /**
     * @param list<string>                      $owners
     * @param list<PlatformOwnerInvitationView> $ownerInvitations
     */
    private function viewWith(Company $company, array $owners, array $ownerInvitations): PlatformCompanyView
    {
        return new PlatformCompanyView(
            $company->getId()->toRfc4122(),
            $company->getName(),
            $company->getCountryCode(),
            $company->getStatus(),
            $company->getCreatedAt()->format(\DateTimeInterface::ATOM),
            $owners,
            $ownerInvitations,
        );
    }
}
