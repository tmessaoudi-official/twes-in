<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Settings\Application;

use App\Audit\Application\AuditEntry;
use App\Audit\Application\AuditTrail;
use App\Audit\Application\DeclaresAuditActions;
use App\Audit\Domain\ValueObject\AuditScope;
use App\Audit\Domain\ValueObject\Recorded;
use App\Settings\Domain\Event\PersonalSettingChanged;
use App\Settings\Domain\Model\Setting;
use App\Settings\Domain\Repository\SettingRepository;
use App\Settings\Domain\ValueObject\SettingAddress;
use App\Settings\Domain\ValueObject\SettingDefinition;
use App\Settings\Domain\ValueObject\SettingLevel;
use App\Shared\Application\DomainEvents;
use App\Shared\Application\RecordChanged;
use App\Shared\Application\Transactions;
use App\Shared\Application\TypedPhones;
use App\Shared\Domain\ValueObject\Refusal;
use Psr\Clock\ClockInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Stores or forgets a value at one level of one context, and answers the setting as that context now sees it.
 * Who may write at which level is the caller's decision; this use case only knows what the setting allows.
 */
final readonly class ChangeSettings implements DeclaresAuditActions
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
        private DomainEvents $events,
        private TypedPhones $phones,
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
            throw new InvalidSettingValue([$key => $refusal]);
        }
        [$value, $refusal] = $this->phoneKept($definition, $address, $key, $definition->normalize($value));
        if (null !== $refusal) {
            throw new InvalidSettingValue([$key => $refusal]);
        }

        // A single key is a person's own choice or a value set from a script: the last word is the intent, and holding
        // the row keeps two of them from meeting at the version check of a flush.
        $told = $this->transactions->run(fn (): array => $this->store($address, $key, $value, $this->settings->lockedFind($address, $key), $actorUserId));
        $this->events->publish(...$told);

        return $this->resolve->one($context, $key);
    }

    /**
     * Stores every key a page changed at one level, or none: each key's row is held and must still be at the version
     * the page read, 0 meaning the page read no row there. Every key it would not store is refused at once, a level the
     * key is not kept at among them.
     *
     * @param list<SettingChange> $changes
     *
     * @throws UnknownSetting
     * @throws InvalidSettingValue
     * @throws RecordChanged
     */
    public function changeAll(SettingContext $context, SettingLevel $level, array $changes, ?Uuid $actorUserId): void
    {
        $located = [];
        $refused = [];
        foreach ($changes as $change) {
            if (isset($located[$change->key]) || isset($refused[$change->key])) {
                $refused[$change->key] ??= new Refusal('invalid_value', 'changed twice in one save');
                continue;
            }
            try {
                [$definition, $address] = $this->locate($context, $change->key, $level);
            } catch (SettingLevelRefused $notHere) {
                $refused[$change->key] = new Refusal('level_refused', $notHere->getMessage());
                continue;
            }
            $refusal = $definition->refusal($change->value);
            if (null !== $refusal) {
                $refused[$change->key] = $refusal;
                continue;
            }
            [$value, $refusal] = $this->phoneKept($definition, $address, $change->key, $definition->normalize($change->value));
            if (null !== $refusal) {
                $refused[$change->key] = $refusal;
                continue;
            }
            $located[$change->key] = [$address, $value, $change->version];
        }
        if ([] !== $refused) {
            throw new InvalidSettingValue($refused);
        }

        $told = $this->transactions->run(function () use ($located, $actorUserId): array {
            $told = [];
            foreach ($located as $key => [$address, $value, $version]) {
                $setting = $this->settings->lockedFind($address, $key);
                if ((null === $setting ? 0 : $setting->getVersion()) !== $version) {
                    throw new RecordChanged();
                }
                array_push($told, ...$this->store($address, $key, $value, $setting, $actorUserId));
            }

            return $told;
        });
        $this->events->publish(...$told);
    }

    /**
     * A phone setting's value as the numbering plans keep it (`TypedPhones`), with no country to read it by, so written
     * with « + » or « 00 »; what the setting already holds is judged only once it changes. Any other value as it came.
     *
     * @return array{mixed, ?Refusal}
     */
    private function phoneKept(SettingDefinition $definition, SettingAddress $address, string $key, mixed $value): array
    {
        if (!$definition->phone || !\is_string($value)) {
            return [$value, null];
        }
        $held = $this->settings->find($address, $key)?->getValue();
        $kept = $this->phones->kept($value, \is_string($held) ? $held : null, null);

        return [$kept->phone ?? '', $kept->refusal];
    }

    /** @return list<PersonalSettingChanged> what to tell once the transaction has committed */
    private function store(SettingAddress $address, string $key, mixed $value, ?Setting $setting, ?Uuid $actorUserId): array
    {
        $now = $this->clock->now();
        if (null === $setting) {
            $setting = new Setting($address, $key, $value, $now);
        } elseif (!$setting->change($value, $now)) {
            return [];
        }
        $this->settings->save($setting);
        $this->record(self::CHANGED, $setting, $address, $actorUserId);

        return self::personal($address, $key, $value);
    }

    /**
     * @throws UnknownSetting
     * @throws SettingLevelRefused
     */
    public function reset(SettingContext $context, string $key, SettingLevel $level, ?Uuid $actorUserId): ResolvedSetting
    {
        [, $address] = $this->locate($context, $key, $level);
        $removed = $this->transactions->run(function () use ($address, $key, $actorUserId): bool {
            $setting = $this->settings->lockedFind($address, $key);
            if (null === $setting) {
                return false;
            }
            $this->settings->remove($setting);
            $this->record(self::RESET, $setting, $address, $actorUserId);

            return true;
        });
        $resolved = $this->resolve->one($context, $key);
        if ($removed) {
            $this->events->publish(...self::personal($address, $key, null, $resolved->value));
        }

        return $resolved;
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

    /** @return list<PersonalSettingChanged> */
    private static function personal(SettingAddress $address, string $key, mixed $value, mixed $inForce = null): array
    {
        return SettingLevel::User === $address->level ? [new PersonalSettingChanged(Uuid::fromString($address->levelId), $key, $value, $inForce)] : [];
    }

    /**
     * A person's own preferences are theirs alone and change too often to be worth a trail; every shared default is
     * audited, by its name and level and never its value, which may be a bank account in a document's printed notes or
     * a person's phone in the publisher's details.
     */
    private function record(string $action, Setting $setting, SettingAddress $address, ?Uuid $actorUserId): void
    {
        if (SettingLevel::User === $address->level) {
            return;
        }
        $changes = ['key' => $setting->getKey(), 'level' => $address->level->value];
        $this->audit->record(new AuditEntry(self::ENTITY_TYPE, $setting->getId(), $action, $actorUserId, $changes, $address->company?->getId()));
    }

    public function auditActions(): array
    {
        return [
            self::CHANGED => new Recorded(self::ENTITY_TYPE, AuditScope::Either),
            self::RESET => new Recorded(self::ENTITY_TYPE, AuditScope::Either),
        ];
    }
}
