<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Settings\Infrastructure\ApiPlatform;

use App\Settings\Application\SettingContext;
use App\Settings\Domain\SettingDefinition;
use App\Settings\Domain\SettingLevel;
use App\Tenancy\Domain\Company;
use App\Tenancy\Domain\MembershipRepository;
use App\Tenancy\Domain\RoleRepository;
use App\Tenancy\Infrastructure\ApiPlatform\CompanyGuard;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\Uid\Uuid;

/**
 * Who may read and change settings in a company. Anyone the company lets read it reads its settings and keeps
 * their own preferences; a default shared with others, for the whole company or for a role, needs
 * `company.settings`. Another company's settings answer 404, like everything behind CompanyGuard.
 */
final readonly class SettingAccess
{
    public const string READ = 'company.read';
    public const string SHARE = 'company.settings';

    public function __construct(
        private CompanyGuard $guard,
        private MembershipRepository $memberships,
        private RoleRepository $roles,
    ) {
    }

    public function companyToRead(Uuid $companyId): Company
    {
        return $this->guard->companyForActing($companyId, self::READ);
    }

    public function companyToWrite(Uuid $companyId, SettingLevel $level): Company
    {
        return $this->guard->companyForActing($companyId, SettingLevel::User === $level ? self::READ : self::SHARE);
    }

    public function mayShare(Company $company): bool
    {
        return $this->guard->may($company, self::SHARE);
    }

    public function callerId(): Uuid
    {
        return $this->guard->account()->getId();
    }

    /** The caller in the company: their role there, when they hold one (an operator may not), and themselves. */
    public function contextOf(Company $company): SettingContext
    {
        $userId = $this->callerId();

        return new SettingContext($company, $this->memberships->ofUserInCompany($userId, $company->getId())?->getRole()->getId(), $userId);
    }

    /** The caller's context with the named role in place of their own, which is how a role's default is written. */
    public function contextOfRole(Company $company, ?string $roleId): SettingContext
    {
        if (null === $roleId || '' === $roleId) {
            throw new UnprocessableEntityHttpException('roleId: the role level names a role.');
        }
        $role = Uuid::isValid($roleId) ? $this->roles->ofIdForCompany(Uuid::fromString($roleId), $company->getId()) : null;
        if (null === $role) {
            throw new NotFoundHttpException('No such role.');
        }

        return new SettingContext($company, $role->getId(), $this->callerId());
    }

    /** @return list<string> the levels of the definition the caller may change */
    public function writableLevels(SettingDefinition $definition, bool $mayShare): array
    {
        $levels = [];
        foreach ($definition->chain->levels() as $level) {
            $mine = SettingLevel::User === $level;
            // This endpoint writes the company and role levels. The platform level belongs to the operator's own
            // screen, and a customer group, customer, product or document is written from its own screen.
            $shared = $mayShare && \in_array($level, [SettingLevel::Company, SettingLevel::Role], true);
            if ($definition->allows($level) && ($mine || $shared)) {
                $levels[] = $level->value;
            }
        }

        return $levels;
    }
}
