<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Identity\Application\Mfa\Passkey;

/**
 * The name passkeys are bound to now (WebAuthn's relying party id): the installation's own, or once its operator
 * switches, the shop's Tailscale name. A passkey works only on the name it was made on, so each one keeps it.
 */
interface PasskeyHost
{
    /** The name passkeys are made and checked on now. */
    public function current(): string;

    /** Where passkeys work now, the address to open; null when the installation names none. */
    public function address(): ?string;

    /** Whether a passkey made on this name works now; null is a passkey made before the name was kept, on the installation's own. */
    public function madeHere(?string $host): bool;

    /** The installation's own name, which a passkey made before the name was kept was made on. */
    public function installationName(): string;
}
