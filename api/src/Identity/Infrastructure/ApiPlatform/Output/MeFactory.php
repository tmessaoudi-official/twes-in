<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Identity\Infrastructure\ApiPlatform\Output;

use App\Identity\Application\Account\DemoAccounts;
use App\Identity\Application\CompanyProof\CompanyEntryProofs;
use App\Identity\Application\CompanyProof\EntryProof;
use App\Identity\Application\CustomerScreen\CustomerScreenLock;
use App\Identity\Application\Login\PasskeySignInSettings;
use App\Identity\Domain\Model\User;
use App\Identity\Domain\Repository\PasskeyRepository;
use App\Identity\Domain\Repository\RecoveryCodeRepository;
use App\Identity\Domain\Repository\UserRepository;
use App\Identity\Infrastructure\ApiPlatform\Resource\Me;
use App\Identity\Infrastructure\Security\SecurityUser;
use App\Licensing\Application\CompanyStandings;
use App\ModuleRegistry\Application\ModuleCatalog;
use App\ModuleRegistry\Application\ModuleManifest;
use App\ModuleRegistry\Application\ModuleStates;
use App\Settings\Application\ReadSetting;
use App\Settings\Application\SettingContext;
use App\Settings\Application\SupportSettings;
use App\Shared\Application\PhoneNumbers;
use App\Shared\Domain\Service\TypedText;
use App\Tenancy\Application\Mfa\MfaRequirement;
use App\Tenancy\Application\Session\CompanyEntry;
use App\Tenancy\Application\Session\DescribeWorkingContext;
use App\Tenancy\Application\Session\WorkingContext;
use App\Tenancy\Domain\Model\Company;
use App\Tenancy\Domain\Repository\CompanyRepository;
use Symfony\Component\Uid\Uuid;

/** The signed-in state of the authenticated account: the user row, the Tenancy working context and its modules. */
final readonly class MeFactory
{
    /**
     * The kinds of change whose writes move what this answers: a company's status, closing, logo or required second
     * factor, a membership or role and its permissions, a module switched, a subscription's standing and the payments
     * declared towards it, a support grant. The shell reads the session again on each (`SESSION_KINDS`,
     * web/src/app/shared/session/session-kinds.ts, which `SessionKindsTest` holds to this list): a field added here
     * names the kind its writer says.
     */
    public const array CHANGED_BY = ['company', 'membership', 'module', 'payment_declaration', 'role', 'subscription', 'support_grant'];

    public function __construct(
        private UserRepository $users,
        private DescribeWorkingContext $describeWorkingContext,
        private MfaRequirement $mfaRequirement,
        private ModuleStates $modules,
        private PasskeyRepository $passkeys,
        private CompanyStandings $standings,
        private ModuleCatalog $catalog,
        private CustomerScreenLock $customerScreen,
        private ReadSetting $settings,
        private DemoAccounts $demoAccounts,
        private RecoveryCodeRepository $recoveryCodes,
        private PhoneNumbers $phones,
        private CompanyEntry $entry,
        private CompanyEntryProofs $entryProofs,
        private CompanyRepository $companies,
    ) {
    }

    public function for(SecurityUser $account): Me
    {
        $user = $this->users->ofId($account->getId()) ?? throw new \LogicException('An authenticated account always has a user row.');
        $context = $this->describeWorkingContext->for($user->getId());
        $modules = null === $context ? [] : $this->modules->enabledKeys(Uuid::fromString($context->companyId));

        // A company closed down pays for nothing: kept for its records, its subscription is no longer judged.
        $standing = null === $context || Company::STATUS_CLOSED === $context->status ? null : $this->standings->of(Uuid::fromString($context->companyId));

        $planned = array_values(array_map(
            static fn (ModuleManifest $manifest) => new MePlannedModule($manifest->key, (string) $manifest->planned),
            array_filter($this->catalog->all(), static fn (ModuleManifest $manifest) => null !== $manifest->planned),
        ));

        return Me::of($user, $context, $this->mfaRequirement->appliesTo($user), $modules, $this->passkeys->countFor($user), $standing, $planned, $this->customerScreen->lockedFor($user->getId())?->toRfc4122(), $this->support(), $this->demoAccounts->isDemo($user->getId()), $this->recoveryCodes->countFor($user), true === $this->settings->value(new SettingContext(), PasskeySignInSettings::ALLOWED), $this->proofAsked($user, $context));
    }

    private function proofAsked(User $user, ?WorkingContext $context): ?EntryProof
    {
        if (null === $context || !$this->entry->awaitsProof($user->getId(), Uuid::fromString($context->companyId))) {
            return null;
        }
        $company = $this->companies->ofId(Uuid::fromString($context->companyId));

        return null === $company ? null : $this->entryProofs->of($user, $company);
    }

    private function support(): MeSupport
    {
        $channel = function (string $key): ?string {
            $value = $this->settings->value(new SettingContext(), $key);

            return \is_string($value) && '' !== TypedText::trimmed($value) ? TypedText::trimmed($value) : null;
        };

        // The phone is kept as E.164 and shown grouped as its country writes it; the link dials its digits.
        $phone = $channel(SupportSettings::PHONE);

        return new MeSupport(null === $phone ? null : $this->phones->grouped($phone), $channel(SupportSettings::WHATSAPP), $channel(SupportSettings::EMAIL));
    }
}
