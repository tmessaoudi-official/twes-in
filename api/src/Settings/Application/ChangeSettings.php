<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Settings\Application;

use App\Audit\Application\AuditEntry;
use App\Audit\Application\AuditTrail;
use App\Settings\Domain\Model\Setting;
use App\Settings\Domain\Repository\SettingRepository;
use App\Settings\Domain\ValueObject\SettingAddress;
use App\Settings\Domain\ValueObject\SettingDefinition;
use App\Settings\Domain\ValueObject\SettingLevel;
use App\Shared\Application\RecordChanged;
use App\Shared\Application\Transactions;
use Psr\Clock\ClockInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Stores or forgets a value at one level of one context, and answers the setting as that context now sees it.
 * Who may write at which level is the caller's decision; this use case only knows what the setting allows.
 */
final readonly class ChangeSettings
{
    public const string ENTITY_TYPE = 'setting';
    public const string CHANGED = 'setting.changed';
    public const string RESET = 'setting.reset';

    public function __construct(
        private SettingCatalog $catalog,
        private SettingRepository $settings,
        private ResolveSettings $resolve,
        private AuditTrail $audit,
        private ClockInterface $clock,
        private Transactions $transactions,
    ) {
    }

    /**
     * @throws UnknownSetting
     * @throws SettingLevelRefused
     * @throws InvalidSettingValue
     */
    public function change(SettingContext $context, string $key, SettingLevel $level, mixed $value, ?Uuid $actorUserId): ResolvedSetting
    {
        [$definition, $address] = $this->locate($context, $key, $level);
        $refusal = $definition->refusal($value);
        if (null !== $refusal) {
            throw new InvalidSettingValue(\sprintf('%s: %s.', $key, $refusal));
        }
        $value = $definition->normalize($value);

        // A single key is a person's own choice or a value set from a script: the last word is the intent, and holding
        // the row keeps two of them from meeting at the version check of a flush.
        $this->transactions->run(function () use ($address, $key, $value, $actorUserId): void {
            $this->store($address, $key, $value, $this->settings->lockedFind($address, $key), $actorUserId);
        });

        return $this->resolve->one($context, $key);
    }

    /**
     * Stores every key a page changed at one level, or none: each key's row is held and must still be at the version
     * the page read, 0 meaning the page read no row there.
     *
     * @param list<SettingChange> $changes
     *
     * @throws UnknownSetting
     * @throws SettingLevelRefused
     * @throws InvalidSettingValue
     * @throws RecordChanged
     */
    public function changeAll(SettingContext $context, SettingLevel $level, array $changes, ?Uuid $actorUserId): void
    {
        $located = [];
        foreach ($changes as $change) {
            if (isset($located[$change->key])) {
                throw new InvalidSettingValue(\sprintf('%s: changed twice in one save.', $change->key));
            }
            [$definition, $address] = $this->locate($context, $change->key, $level);
            $refusal = $definition->refusal($change->value);
            if (null !== $refusal) {
                throw new InvalidSettingValue(\sprintf('%s: %s.', $change->key, $refusal));
            }
            $located[$change->key] = [$address, $definition->normalize($change->value), $change->version];
        }

        $this->transactions->run(function () use ($located, $actorUserId): void {
            foreach ($located as $key => [$address, $value, $version]) {
                $setting = $this->settings->lockedFind($address, $key);
                if ((null === $setting ? 0 : $setting->getVersion()) !== $version) {
                    throw new RecordChanged();
                }
                $this->store($address, $key, $value, $setting, $actorUserId);
            }
        });
    }

    private function store(SettingAddress $address, string $key, mixed $value, ?Setting $setting, ?Uuid $actorUserId): void
    {
        $now = $this->clock->now();
        if (null === $setting) {
            $setting = new Setting($address, $key, $value, $now);
        } elseif (!$setting->change($value, $now)) {
            return;
        }
        $this->settings->save($setting);
        $this->record(self::CHANGED, $setting, $address, $actorUserId);
    }

    /**
     * @throws UnknownSetting
     * @throws SettingLevelRefused
     */
    public function reset(SettingContext $context, string $key, SettingLevel $level, ?Uuid $actorUserId): ResolvedSetting
    {
        [, $address] = $this->locate($context, $key, $level);
        $this->transactions->run(function () use ($address, $key, $actorUserId): void {
            $setting = $this->settings->lockedFind($address, $key);
            if (null !== $setting) {
                $this->settings->remove($setting);
                $this->record(self::RESET, $setting, $address, $actorUserId);
            }
        });

        return $this->resolve->one($context, $key);
    }

    /** @return array{SettingDefinition, SettingAddress} */
    private function locate(SettingContext $context, string $key, SettingLevel $level): array
    {
        $definition = $this->catalog->definitionOf($key) ?? throw new UnknownSetting(\sprintf('No setting is declared as %s.', $key));
        if (!$definition->allows($level)) {
            throw new SettingLevelRefused(\sprintf('%s cannot be set at the %s level.', $key, $level->value));
        }
        $address = $context->addressOf($level) ?? throw new SettingLevelRefused(\sprintf('There is no %s here to set %s for.', $level->value, $key));

        return [$definition, $address];
    }

    /** A person's own preferences are theirs alone and change too often to be worth a trail; every shared default is audited. */
    private function record(string $action, Setting $setting, SettingAddress $address, ?Uuid $actorUserId): void
    {
        if (SettingLevel::User === $address->level) {
            return;
        }
        $changes = ['key' => $setting->getKey(), 'level' => $address->level->value, 'value' => $setting->getValue()];
        $this->audit->record(new AuditEntry(self::ENTITY_TYPE, $setting->getId(), $action, $actorUserId, $changes, $address->company?->getId()));
    }
}
