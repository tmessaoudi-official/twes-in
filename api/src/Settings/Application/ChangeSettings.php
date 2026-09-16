<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Settings\Application;

use App\Audit\Application\AuditEntry;
use App\Audit\Application\AuditTrail;
use App\Settings\Domain\Setting;
use App\Settings\Domain\SettingAddress;
use App\Settings\Domain\SettingDefinition;
use App\Settings\Domain\SettingLevel;
use App\Settings\Domain\SettingRepository;
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

        $this->transactions->run(function () use ($address, $key, $value, $actorUserId): void {
            $now = $this->clock->now();
            $setting = $this->settings->find($address, $key);
            if (null === $setting) {
                $setting = new Setting($address, $key, $value, $now);
                $this->settings->save($setting);
                $this->record(self::CHANGED, $setting, $address, $actorUserId);
            } elseif ($setting->change($value, $now)) {
                $this->settings->save($setting);
                $this->record(self::CHANGED, $setting, $address, $actorUserId);
            }
        });

        return $this->resolve->one($context, $key);
    }

    /**
     * @throws UnknownSetting
     * @throws SettingLevelRefused
     */
    public function reset(SettingContext $context, string $key, SettingLevel $level, ?Uuid $actorUserId): ResolvedSetting
    {
        [, $address] = $this->locate($context, $key, $level);
        $this->transactions->run(function () use ($address, $key, $actorUserId): void {
            $setting = $this->settings->find($address, $key);
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
