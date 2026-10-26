<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Identity\Infrastructure\ApiPlatform\Resource;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use App\Identity\Application\Account\DemoAccounts;
use App\Identity\Domain\Model\User;
use App\Identity\Infrastructure\ApiPlatform\Output\MeClosing;
use App\Identity\Infrastructure\ApiPlatform\Output\MeCompany;
use App\Identity\Infrastructure\ApiPlatform\Output\MeMfa;
use App\Identity\Infrastructure\ApiPlatform\Output\MePlannedModule;
use App\Identity\Infrastructure\ApiPlatform\Output\MeSubscription;
use App\Identity\Infrastructure\ApiPlatform\Output\MeSupport;
use App\Identity\Infrastructure\ApiPlatform\Output\MeUser;
use App\Identity\Infrastructure\ApiPlatform\State\Provider\MeProvider;
use App\Licensing\Domain\ValueObject\Access;
use App\Licensing\Domain\ValueObject\Standing;
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
        /** The company whose customer screen holds this sign-in, null while none does: every tab opens on it. */
        #[ApiProperty(required: true)] public ?string $customerScreenCompanyId = null,
        /** How to reach the platform's operators, from the help panel. */
        #[ApiProperty(required: true)] public MeSupport $support = new MeSupport(),
        /** Until when the working company lets the platform's support read it, null while it does not: the banner. */
        #[ApiProperty(required: true)] public ?string $supportAccessUntil = null,
    ) {
    }

    /**
     * @param list<string>          $modules        keys of the modules the working company has on, none without one
     * @param Standing|null         $standing       where the working company stands in its subscription, null when licensing does not manage it
     * @param list<MePlannedModule> $plannedModules the modules not built yet, whoever is signed in
     */
    public static function of(User $user, ?WorkingContext $context, bool $mfaRequired = false, array $modules = [], int $passkeys = 0, ?Standing $standing = null, array $plannedModules = [], ?string $customerScreenCompanyId = null, MeSupport $support = new MeSupport(), bool $demo = false, int $recoveryCodes = 0): self
    {
        return new self(
            new MeUser($user->getId()->toRfc4122(), $demo ? DemoAccounts::SHOWN_AS : $user->getEmail()->value, $user->getDisplayName(), $user->getLocale(), $user->isPlatformOperator(), $demo),
            null === $context ? null : new MeCompany($context->companyId, $context->name, $context->countryCode, $context->currency, $context->locale, $context->timezone, $context->status, $context->role, (null === $standing ? Access::Full : $standing->access)->value, null === $standing ? null : MeSubscription::of($standing), $context->logoVersion, null === $context->closing ? null : MeClosing::of($context->closing)),
            null === $context ? [] : $context->permissions,
            new MeMfa($user->hasTotp() || $passkeys > 0, $mfaRequired, $user->hasTotp(), $passkeys, $recoveryCodes),
            $modules,
            $plannedModules,
            $customerScreenCompanyId,
            $support,
            $context?->supportAccessUntil?->format(\DateTimeInterface::ATOM),
        );
    }
}
