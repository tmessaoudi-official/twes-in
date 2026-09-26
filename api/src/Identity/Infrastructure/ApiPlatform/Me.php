<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Identity\Infrastructure\ApiPlatform;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use App\Identity\Domain\User;
use App\Licensing\Domain\Access;
use App\Licensing\Domain\Standing;
use App\Tenancy\Application\Session\WorkingContext;

/**
 * Who am I, where am I working, what may I do. The same shape is returned by a successful login, so the SPA
 * has one type for "the signed-in state".
 */
#[ApiResource(
    shortName: 'Me',
    // skip_null_values off: the login answer is serialized by the plain serializer and this one by API Platform, and the
    // SPA holds one type for both, so a null field is written in both rather than missing from one.
    operations: [new Get(uriTemplate: '/auth/me', provider: MeProvider::class, security: 'is_granted("ROLE_USER")', normalizationContext: ['skip_null_values' => false])],
)]
final readonly class Me
{
    /**
     * @param list<string>          $permissions    permission strings the user holds in the current company; ["*"] for an owner
     * @param list<string>          $modules        keys of the modules the current company has on
     * @param list<MePlannedModule> $plannedModules the modules not built yet, in key order: the menus show them « Bientôt »
     */
    public function __construct(
        #[ApiProperty(required: true)] public MeUser $user,
        #[ApiProperty(required: true)] public ?MeCompany $company,
        #[ApiProperty(required: true, schema: ['type' => 'array', 'items' => ['type' => 'string']])] public array $permissions,
        #[ApiProperty(required: true)] public MeMfa $mfa,
        #[ApiProperty(required: true, schema: ['type' => 'array', 'items' => ['type' => 'string']])] public array $modules = [],
        #[ApiProperty(required: true)] public array $plannedModules = [],
    ) {
    }

    /**
     * @param list<string>          $modules        keys of the modules the working company has on, none without one
     * @param Standing|null         $standing       where the working company stands in its subscription, null when licensing does not manage it
     * @param list<MePlannedModule> $plannedModules the modules not built yet, whoever is signed in
     */
    public static function of(User $user, ?WorkingContext $context, bool $mfaRequired = false, array $modules = [], int $passkeys = 0, ?Standing $standing = null, array $plannedModules = []): self
    {
        return new self(
            new MeUser($user->getId()->toRfc4122(), $user->getEmail()->value, $user->getDisplayName(), $user->getLocale(), $user->isPlatformOperator()),
            null === $context ? null : new MeCompany($context->companyId, $context->name, $context->countryCode, $context->currency, $context->locale, $context->timezone, $context->status, $context->role, (null === $standing ? Access::Full : $standing->access)->value, null === $standing ? null : MeSubscription::of($standing)),
            null === $context ? [] : $context->permissions,
            new MeMfa($user->hasTotp() || $passkeys > 0, $mfaRequired, $user->hasTotp(), $passkeys),
            $modules,
            $plannedModules,
        );
    }
}
