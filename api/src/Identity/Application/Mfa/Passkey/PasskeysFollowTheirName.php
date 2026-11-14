<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Identity\Application\Mfa\Passkey;

use App\Identity\Application\Security\SecurityChange;
use App\Identity\Application\Security\SecurityNotices;
use App\Identity\Domain\Repository\PasskeyRepository;
use App\Settings\Application\Rules\GovernsSetting;
use App\Shared\Domain\ValueObject\Refusal;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * A passkey works only on the name it was made on, so moving the installation's passkeys to the Tailscale name, or
 * back, leaves every key made before unusable. The move is refused while anyone's only second factor is a passkey, who
 * would be left with none, and refused on with no Tailscale name to bind to; once it moves, whoever holds a key made on
 * the other name is told to add one again.
 */
final readonly class PasskeysFollowTheirName implements GovernsSetting
{
    public const string PASSKEY_ONLY_FACTOR = 'passkey_only_factor';
    public const string NO_TAILNET_NAME = 'no_tailnet_name';

    public function __construct(
        private PasskeyRepository $passkeys,
        private PasskeyHost $host,
        private SecurityNotices $notices,
        #[Autowire(env: 'TAILNET_HOST')]
        private string $tailnet,
    ) {
    }

    public function key(): string
    {
        return PasskeyHostSettings::ON_TAILNET_NAME;
    }

    public function refusal(mixed $value): ?Refusal
    {
        if (true === $value && '' === $this->tailnet) {
            return self::noTailnetName();
        }
        // Saying again where they already are moves no passkey, so nobody is left without one.
        if ($this->nameFor($value) === $this->host->current()) {
            return null;
        }
        $alone = $this->passkeys->countAccountsWithPasskeysAlone();

        return 0 === $alone ? null : self::passkeyOnlyFactor($alone);
    }

    public function refusals(): array
    {
        return [self::noTailnetName(), self::passkeyOnlyFactor(1)];
    }

    public function changed(mixed $value): void
    {
        foreach ($this->passkeys->holdersOfPasskeysNotMadeOn($this->nameFor($value), $this->host->installationName()) as $userId) {
            $this->notices->tell($userId, SecurityChange::PasskeysMoved);
        }
    }

    /** The name passkeys are bound to under this value of the switch. */
    private function nameFor(mixed $value): string
    {
        return true === $value && '' !== $this->tailnet ? $this->tailnet : $this->host->installationName();
    }

    private static function noTailnetName(): Refusal
    {
        return new Refusal(self::NO_TAILNET_NAME, 'this installation has no Tailscale name (TAILNET_HOST) to bind passkeys to');
    }

    private static function passkeyOnlyFactor(int $count): Refusal
    {
        return new Refusal(self::PASSKEY_ONLY_FACTOR, \sprintf('%d account(s) would be left with no second factor: their only one is a passkey', $count), ['count' => $count]);
    }
}
