<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Identity\Infrastructure\Passkey;

use App\Identity\Application\Mfa\Passkey\PasskeyHost;
use App\Identity\Application\Mfa\Passkey\PasskeyHostSettings;
use App\Settings\Application\ReadSetting;
use App\Settings\Application\SettingContext;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * The installation's configured name (APP_WEBAUTHN_RP_ID), or the Tailscale one (TAILNET_HOST) while the platform's
 * switch says so and the name is given. Read on every call: one worker serves the requests before and after a switch.
 */
final readonly class ConfiguredPasskeyHost implements PasskeyHost
{
    /** @param list<string> $origins */
    public function __construct(
        private ReadSetting $settings,
        #[Autowire(param: 'app.webauthn.rp_id')]
        private string $configured,
        #[Autowire(param: 'app.webauthn.origins')]
        private array $origins,
        #[Autowire(env: 'TAILNET_HOST')]
        private string $tailnet,
    ) {
    }

    public function current(): string
    {
        return $this->onTailnet() ? $this->tailnet : $this->configured;
    }

    public function address(): ?string
    {
        return $this->onTailnet() ? 'https://'.$this->tailnet : ($this->origins[0] ?? null);
    }

    public function madeHere(?string $host): bool
    {
        return ($host ?? $this->configured) === $this->current();
    }

    public function installationName(): string
    {
        return $this->configured;
    }

    private function onTailnet(): bool
    {
        return '' !== $this->tailnet && true === $this->settings->value(new SettingContext(), PasskeyHostSettings::ON_TAILNET_NAME);
    }
}
