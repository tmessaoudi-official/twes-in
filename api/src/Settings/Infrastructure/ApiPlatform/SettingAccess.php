<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Settings\Infrastructure\ApiPlatform;

use App\Settings\Application\PartySubjects;
use App\Settings\Application\SettingContext;
use App\Settings\Domain\SettingDefinition;
use App\Settings\Domain\SettingLevel;
use App\Tenancy\Domain\Company;
use App\Tenancy\Domain\MembershipRepository;
use App\Tenancy\Domain\RoleRepository;
use App\Tenancy\Infrastructure\ApiPlatform\CompanyGuard;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\Uid\Uuid;

/**
 * Who may read and change settings in a company. Anyone the company lets read it reads its settings and keeps
 * their own preferences; a default shared with others, for the whole company or for a role, needs
 * `company.settings`. A customer's or a customer group's settings are read with `customer.read` and changed with
 * `customer.write`, naming the customer or the group. Another company's settings, customers and groups answer 404,
 * like everything behind CompanyGuard.
 */
final readonly class SettingAccess
{
    public const string READ = 'company.read';
    public const string SHARE = 'company.settings';
    public const string PARTIES_READ = 'customer.read';
    public const string PARTIES_WRITE = 'customer.write';

    public function __construct(
        private CompanyGuard $guard,
        private MembershipRepository $memberships,
        private RoleRepository $roles,
        private PartySubjects $parties,
    ) {
    }

    public function companyToRead(Uuid $companyId): Company
    {
        return $this->guard->companyForActing($companyId, self::READ);
    }

    public function companyToWrite(Uuid $companyId, SettingLevel $level): Company
    {
        return $this->guard->companyForActing($companyId, match ($level) {
            SettingLevel::User => self::READ,
            SettingLevel::CustomerGroup, SettingLevel::Customer => self::PARTIES_WRITE,
            default => self::SHARE,
        });
    }

    public function mayShare(Company $company): bool
    {
        return $this->guard->may($company, self::SHARE);
    }

    public function mayWriteParties(Company $company): bool
    {
        return $this->guard->may($company, self::PARTIES_WRITE);
    }

    public function callerId(): Uuid
    {
        return $this->guard->account()->getId();
    }

    /**
     * The caller in the company: their role there, when they hold one (an operator may not), and themselves; and, when
     * one is named, the customer (with its group) or the customer group the chain is read for.
     */
    public function contextOf(Company $company, ?string $customerId = null, ?string $customerGroupId = null): SettingContext
    {
        $userId = $this->callerId();
        $roleId = $this->memberships->ofUserInCompany($userId, $company->getId())?->getRole()->getId();
        $customerId = self::named($customerId);
        $customerGroupId = self::named($customerGroupId);
        if (null === $customerId && null === $customerGroupId) {
            return new SettingContext($company, $roleId, $userId);
        }
        if (null !== $customerId && null !== $customerGroupId) {
            throw new BadRequestHttpException('customerId, customerGroupId: name a customer or a customer group, not both.');
        }
        if (!$this->guard->may($company, self::PARTIES_READ)) {
            throw new NotFoundHttpException('No such company.');
        }
        if (null !== $customerId) {
            $subject = Uuid::isValid($customerId) ? $this->parties->customer($company, Uuid::fromString($customerId)) : null;
            if (null === $subject) {
                throw new NotFoundHttpException('No such customer.');
            }

            return new SettingContext($company, $roleId, $userId, $subject->customerGroupId, $subject->customerId);
        }
        $groupId = Uuid::isValid((string) $customerGroupId) ? Uuid::fromString((string) $customerGroupId) : null;
        if (null === $groupId || !$this->parties->hasCustomerGroup($company, $groupId)) {
            throw new NotFoundHttpException('No such customer group.');
        }

        return new SettingContext($company, $roleId, $userId, $groupId);
    }

    /** The context a change at the level is made in: the named role, customer or group in place of the caller's own. */
    public function contextToWrite(Company $company, SettingLevel $level, ?string $roleId, ?string $customerId, ?string $customerGroupId): SettingContext
    {
        return match ($level) {
            SettingLevel::Role => $this->contextOfRole($company, $roleId),
            SettingLevel::Customer => $this->contextOf($company, self::named($customerId) ?? throw new UnprocessableEntityHttpException('customerId: the customer level names a customer.')),
            SettingLevel::CustomerGroup => $this->contextOf($company, null, self::named($customerGroupId) ?? throw new UnprocessableEntityHttpException('customerGroupId: the customer group level names a group.')),
            default => $this->contextOf($company),
        };
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

    /** @return list<string> the levels of the definition the caller may change, for the subject the chain was read for */
    public function writableLevels(SettingDefinition $definition, bool $mayShare, bool $mayWriteParties = false, ?SettingContext $context = null): array
    {
        $levels = [];
        foreach ($definition->chain->levels() as $level) {
            $mine = SettingLevel::User === $level;
            // This endpoint writes the company and role levels, and the customer group or customer the chain was read
            // for. The platform level belongs to the operator's own screen; products and documents arrive later.
            $shared = $mayShare && \in_array($level, [SettingLevel::Company, SettingLevel::Role], true);
            $party = $mayWriteParties && null !== $context && match ($level) {
                SettingLevel::CustomerGroup => null !== $context->customerGroupId && null === $context->customerId,
                SettingLevel::Customer => null !== $context->customerId,
                default => false,
            };
            if ($definition->allows($level) && ($mine || $shared || $party)) {
                $levels[] = $level->value;
            }
        }

        return $levels;
    }

    private static function named(?string $identifier): ?string
    {
        return null === $identifier || '' === $identifier ? null : $identifier;
    }
}
