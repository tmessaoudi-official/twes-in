<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Inbox\Application;

use App\Identity\Domain\User;
use App\Identity\Domain\UserRepository;
use App\Inbox\Domain\NotificationPreference;
use App\Inbox\Domain\NotificationPreferenceRepository;
use App\ModuleRegistry\Application\ModuleStates;
use App\Tenancy\Domain\CompanyRepository;
use App\Tenancy\Domain\MembershipRepository;
use Symfony\Component\Uid\Uuid;

/**
 * « Mon compte › Notifications »: every kind a person is told, in each of their companies and about their account, with
 * how they chose to be told it. A kind nobody chose is told both ways, so the list starts with every switch on.
 */
final readonly class NotificationPreferences
{
    /** As many companies as the switcher lists. */
    private const int COMPANIES = 200;

    public function __construct(
        private UserRepository $users,
        private MembershipRepository $memberships,
        private CompanyRepository $companies,
        private NotificationPreferenceRepository $preferences,
        private NotificationKinds $kinds,
        private ModuleStates $modules,
    ) {
    }

    /** @return list<NotificationChoice> company by company in the switcher's order, then what is about the account */
    public function of(Uuid $userId): array
    {
        $user = $this->user($userId);
        $chosen = [];
        foreach ($this->preferences->ofUser($userId) as $preference) {
            $chosen[self::key($preference->getCompany()?->getId()->toRfc4122(), $preference->getType())] = $preference;
        }
        $choice = static function (?string $companyId, ?string $companyName, NotificationKind $kind) use ($chosen): NotificationChoice {
            $preference = $chosen[self::key($companyId, $kind->type)] ?? null;

            return new NotificationChoice($companyId, $companyName, $kind->type, $preference?->rings() ?? true, $preference?->mails() ?? true, $kind->mailed);
        };

        $choices = [];
        foreach ($this->memberships->ofUser($userId, self::COMPANIES) as $membership) {
            $company = $membership->getCompany();
            $role = $membership->getRole();
            $offered = $this->kinds->ofCompany(
                $role->getName(),
                static fn (string $permission): bool => $role->grants($permission),
                fn (string $module): bool => $this->modules->isEnabled($company->getId(), $module),
            );
            foreach ($offered as $kind) {
                $choices[] = $choice($company->getId()->toRfc4122(), $company->getName(), $kind);
            }
        }
        foreach ([...$this->kinds->personal(), ...($user->isPlatformOperator() ? $this->kinds->ofPlatform() : [])] as $kind) {
            $choices[] = $choice(null, null, $kind);
        }

        return $choices;
    }

    /** Keeps a choice about a kind this person is told there; any other is refused the same way. */
    public function change(Uuid $userId, ?Uuid $companyId, string $type, bool $bell, bool $email): void
    {
        if (null === $this->choiceOf($userId, $companyId, $type)) {
            throw new NotificationKindNotOffered(\sprintf('The kind %s is not told to this person there.', $type));
        }

        $preference = $this->preferences->find($userId, $companyId, $type);
        if (null === $preference) {
            $preference = new NotificationPreference(
                $this->user($userId),
                null === $companyId ? null : ($this->companies->ofId($companyId) ?? throw new \LogicException('A company offered a moment ago is gone.')),
                $type,
                $bell,
                $email,
            );
        } else {
            $preference->change($bell, $email);
        }
        $this->preferences->save($preference);
    }

    /** Where a person is told a kind, and how they chose it; null when it is not told to them there (any more). */
    public function choiceOf(Uuid $userId, ?Uuid $companyId, string $type): ?NotificationChoice
    {
        $company = $companyId?->toRfc4122();
        foreach ($this->of($userId) as $choice) {
            if ($choice->companyId === $company && $choice->type === $type) {
                return $choice;
            }
        }

        return null;
    }

    /** What a mail's stop link does: that kind's e-mail off there, the bell as it was. */
    public function stopMail(Uuid $userId, ?Uuid $companyId, string $type): void
    {
        $choice = $this->choiceOf($userId, $companyId, $type) ?? throw new NotificationKindNotOffered(\sprintf('The kind %s is not told to this person there.', $type));
        $this->change($userId, $companyId, $type, $choice->bell, false);
    }

    private function user(Uuid $userId): User
    {
        return $this->users->ofId($userId) ?? throw new \LogicException(\sprintf('User %s does not exist.', $userId->toRfc4122()));
    }

    private static function key(?string $companyId, string $type): string
    {
        return ($companyId ?? '').' '.$type;
    }
}
