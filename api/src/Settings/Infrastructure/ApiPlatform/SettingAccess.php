<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Settings\Infrastructure\ApiPlatform;

use App\Settings\Application\ArticleSubjects;
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
 * `customer.write`, a product's or a product category's with `product.read` and `product.write`, naming that one
 * subject. Another company's settings and subjects answer 404, like everything behind CompanyGuard.
 */
final readonly class SettingAccess
{
    public const string READ = 'company.read';
    public const string SHARE = 'company.settings';
    public const string PARTIES_READ = 'customer.read';
    public const string PARTIES_WRITE = 'customer.write';
    public const string ARTICLES_READ = 'product.read';
    public const string ARTICLES_WRITE = 'product.write';

    public function __construct(
        private CompanyGuard $guard,
        private MembershipRepository $memberships,
        private RoleRepository $roles,
        private PartySubjects $parties,
        private ArticleSubjects $articles,
    ) {
    }

    public function companyToRead(Uuid $companyId): Company
    {
        return $this->guard->companyForActing($companyId, self::READ);
    }

    /** @throws UnprocessableEntityHttpException for the platform level, which belongs to the platform's operators */
    public function companyToWrite(Uuid $companyId, SettingLevel $level): Company
    {
        // A company's own context reaches the platform address like any chain does, so a company endpoint must refuse
        // that level outright, or anyone who may set a company default could set one for every company at once.
        if (SettingLevel::Platform === $level) {
            throw new UnprocessableEntityHttpException('level: the platform level is set by the platform\'s operators.');
        }

        return $this->guard->companyForActing($companyId, match ($level) {
            SettingLevel::User => self::READ,
            SettingLevel::CustomerGroup, SettingLevel::Customer => self::PARTIES_WRITE,
            SettingLevel::ProductCategory, SettingLevel::Product => self::ARTICLES_WRITE,
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

    public function mayWriteArticles(Company $company): bool
    {
        return $this->guard->may($company, self::ARTICLES_WRITE);
    }

    public function callerId(): Uuid
    {
        return $this->guard->account()->getId();
    }

    /**
     * The caller in the company: their role there, when they hold one (an operator may not), and themselves; and, when
     * one is named, the subject the chain is read for: a customer (with its group) or a customer group, a product (with
     * its category) or a product category. A read names one subject at most, whatever its chain.
     */
    public function contextOf(Company $company, ?string $customerId = null, ?string $customerGroupId = null, ?string $productId = null, ?string $productCategoryId = null): SettingContext
    {
        $userId = $this->callerId();
        $roleId = $this->memberships->ofUserInCompany($userId, $company->getId())?->getRole()->getId();
        $named = [];
        foreach (['customerId' => $customerId, 'customerGroupId' => $customerGroupId, 'productId' => $productId, 'productCategoryId' => $productCategoryId] as $name => $identifier) {
            $value = self::named($identifier);
            if (null !== $value) {
                $named[$name] = $value;
            }
        }
        if ([] === $named) {
            return new SettingContext($company, $roleId, $userId);
        }
        if (\count($named) > 1) {
            throw new BadRequestHttpException(implode(', ', array_keys($named)).': name one subject, not several.');
        }
        $name = array_key_first($named);
        $id = Uuid::isValid($named[$name]) ? Uuid::fromString($named[$name]) : null;

        return match ($name) {
            'customerId', 'customerGroupId' => $this->partyContext($company, $roleId, $userId, $name, $id),
            default => $this->articleContext($company, $roleId, $userId, $name, $id),
        };
    }

    /** The context a change at the level is made in: the named role or subject in place of the caller's own. */
    public function contextToWrite(Company $company, SettingLevel $level, ?string $roleId, ?string $customerId, ?string $customerGroupId, ?string $productId = null, ?string $productCategoryId = null): SettingContext
    {
        return match ($level) {
            SettingLevel::Role => $this->contextOfRole($company, $roleId),
            SettingLevel::Customer => $this->contextOf($company, self::named($customerId) ?? throw new UnprocessableEntityHttpException('customerId: the customer level names a customer.')),
            SettingLevel::CustomerGroup => $this->contextOf($company, null, self::named($customerGroupId) ?? throw new UnprocessableEntityHttpException('customerGroupId: the customer group level names a group.')),
            SettingLevel::Product => $this->contextOf($company, productId: self::named($productId) ?? throw new UnprocessableEntityHttpException('productId: the product level names a product.')),
            SettingLevel::ProductCategory => $this->contextOf($company, productCategoryId: self::named($productCategoryId) ?? throw new UnprocessableEntityHttpException('productCategoryId: the product category level names a category.')),
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
    public function writableLevels(SettingDefinition $definition, bool $mayShare, bool $mayWriteParties = false, ?SettingContext $context = null, bool $mayWriteArticles = false): array
    {
        $levels = [];
        foreach ($definition->chain->levels() as $level) {
            $mine = SettingLevel::User === $level;
            // This endpoint writes the company and role levels, and the one subject the chain was read for. The platform
            // level belongs to the operator's own screen; documents and their lines arrive later.
            $shared = $mayShare && \in_array($level, [SettingLevel::Company, SettingLevel::Role], true);
            $subject = null !== $context && match ($level) {
                SettingLevel::CustomerGroup => $mayWriteParties && null !== $context->customerGroupId && null === $context->customerId,
                SettingLevel::Customer => $mayWriteParties && null !== $context->customerId,
                SettingLevel::ProductCategory => $mayWriteArticles && null !== $context->productCategoryId && null === $context->productId,
                SettingLevel::Product => $mayWriteArticles && null !== $context->productId,
                default => false,
            };
            if ($definition->allows($level) && ($mine || $shared || $subject)) {
                $levels[] = $level->value;
            }
        }

        return $levels;
    }

    /** A customer, with its group, or a customer group; read with customer.read. */
    private function partyContext(Company $company, ?Uuid $roleId, Uuid $userId, string $name, ?Uuid $id): SettingContext
    {
        if (!$this->guard->may($company, self::PARTIES_READ)) {
            throw new NotFoundHttpException('No such company.');
        }
        if ('customerId' === $name) {
            $subject = null === $id ? null : $this->parties->customer($company, $id);
            if (null === $subject) {
                throw new NotFoundHttpException('No such customer.');
            }

            return new SettingContext($company, $roleId, $userId, $subject->customerGroupId, $subject->customerId);
        }
        if (null === $id || !$this->parties->hasCustomerGroup($company, $id)) {
            throw new NotFoundHttpException('No such customer group.');
        }

        return new SettingContext($company, $roleId, $userId, $id);
    }

    /** A product, with its category, or a product category; read with product.read. */
    private function articleContext(Company $company, ?Uuid $roleId, Uuid $userId, string $name, ?Uuid $id): SettingContext
    {
        if (!$this->guard->may($company, self::ARTICLES_READ)) {
            throw new NotFoundHttpException('No such company.');
        }
        if ('productId' === $name) {
            $subject = null === $id ? null : $this->articles->product($company, $id);
            if (null === $subject) {
                throw new NotFoundHttpException('No such product.');
            }

            return new SettingContext($company, $roleId, $userId, productCategoryId: $subject->productCategoryId, productId: $subject->productId);
        }
        if (null === $id || !$this->articles->hasProductCategory($company, $id)) {
            throw new NotFoundHttpException('No such product category.');
        }

        return new SettingContext($company, $roleId, $userId, productCategoryId: $id);
    }

    private static function named(?string $identifier): ?string
    {
        return null === $identifier || '' === $identifier ? null : $identifier;
    }
}
