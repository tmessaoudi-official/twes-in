<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Texts\Application;

use App\Audit\Application\AuditEntry;
use App\Audit\Application\AuditTrail;
use App\Audit\Application\DeclaresAuditActions;
use App\Audit\Domain\ValueObject\AuditScope;
use App\Audit\Domain\ValueObject\Recorded;
use App\Shared\Application\Languages;
use App\Shared\Application\RecordChanged;
use App\Shared\Application\Transactions;
use App\Tenancy\Domain\Model\Company;
use App\Texts\Domain\Model\TextOverride;
use App\Texts\Domain\Repository\TextOverrideRepository;
use App\Texts\Domain\Service\OverrideRules;
use App\Texts\Domain\Service\TextLocks;
use App\Texts\Domain\ValueObject\TextAddress;
use Psr\Clock\ClockInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Words a shipped text otherwise for the installation (no company) or for one company, or takes that back. Who may
 * write at which level is the caller's decision; this use case knows what a text must keep to replace the original.
 */
final readonly class OverrideText implements DeclaresAuditActions
{
    /** Also the kind of the live change the audit entry stages, which every screen reloads its texts on. */
    public const string ENTITY_TYPE = 'text';
    public const string OVERRIDDEN = 'text.overridden';
    public const string RESET = 'text.reset';

    public function __construct(
        private ShippedTexts $shipped,
        private TextLocks $locks,
        private OverrideRules $rules,
        private Languages $languages,
        private TextOverrideRepository $overrides,
        private AuditTrail $audit,
        private Transactions $transactions,
        private ClockInterface $clock,
    ) {
    }

    /**
     * Stores the text over the version the caller read, 0 meaning it read none at that level.
     *
     * @throws TextOverrideRefused
     * @throws RecordChanged
     */
    public function override(?Company $company, TextAddress $address, string $value, int $version, ?Uuid $actorUserId): TextOverride
    {
        $original = $this->original($address);
        $refusal = $this->rules->refusal($address->syntax(), $original, $value);
        if (null !== $refusal) {
            throw TextOverrideRefused::because($refusal, $address);
        }

        return $this->transactions->run(function () use ($company, $address, $value, $original, $version, $actorUserId): TextOverride {
            $override = $this->overrides->lockedFind($company, $address);
            if ((null === $override ? 0 : $override->getVersion()) !== $version) {
                throw new RecordChanged();
            }
            $now = $this->clock->now();
            if (null === $override) {
                $override = new TextOverride($company, $address, $value, $original, $now);
            } elseif (!$override->change($value, $original, $now)) {
                return $override;
            }
            $this->overrides->save($override);
            $this->record(self::OVERRIDDEN, $override, $actorUserId);

            return $override;
        });
    }

    /** @return bool whether a text was there to take back */
    public function reset(?Company $company, TextAddress $address, ?Uuid $actorUserId): bool
    {
        return $this->transactions->run(function () use ($company, $address, $actorUserId): bool {
            $override = $this->overrides->lockedFind($company, $address);
            if (null === $override) {
                return false;
            }
            $this->overrides->remove($override);
            $this->record(self::RESET, $override, $actorUserId);

            return true;
        });
    }

    /** @throws TextOverrideRefused */
    private function original(TextAddress $address): string
    {
        if (!\in_array($address->language, $this->languages->interface(), true)) {
            throw new TextOverrideRefused(TextOverrideRefused::LANGUAGE, $address);
        }
        $original = $this->shipped->original($address) ?? throw new TextOverrideRefused(TextOverrideRefused::UNKNOWN_TEXT, $address);
        if ($this->locks->locked($address)) {
            throw new TextOverrideRefused(TextOverrideRefused::LOCKED, $address);
        }

        return $original;
    }

    /** By its address and level, never its words, which may be a bank account in a payment instruction. */
    private function record(string $action, TextOverride $override, ?Uuid $actorUserId): void
    {
        $address = $override->address();
        $changes = [
            'level' => null === $override->getCompany() ? 'installation' : 'company',
            'language' => $address->language,
            'domain' => $address->domain,
            'key' => $address->key,
        ];
        $this->audit->record(new AuditEntry(self::ENTITY_TYPE, $override->getId(), $action, $actorUserId, $changes, $override->getCompany()?->getId()));
    }

    public function auditActions(): array
    {
        return [
            self::OVERRIDDEN => new Recorded(self::ENTITY_TYPE, AuditScope::Either),
            self::RESET => new Recorded(self::ENTITY_TYPE, AuditScope::Either),
        ];
    }
}
