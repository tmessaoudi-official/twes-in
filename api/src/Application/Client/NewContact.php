<?php

/*
 * This file is part of twes-in.
 *
 * (c) Takieddine MESSAOUDI <takieddine.messaoudi.official@gmail.com>
 *
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Twes\Application\Client;

use Twes\Domain\Client\Contact;

/**
 * A contact a caller has asked for, before it has an identity.
 *
 * **IT EXISTS BECAUSE {@see Contact} REQUIRES AN ID AND THE CALLER MAY NOT SUPPLY ONE.** Identity is minted by
 * {@see CreateClientHandler} from the {@see \Twes\Domain\Shared\IdGenerator} port, for the reason
 * {@see \Twes\UI\Http\ApiResource\NewClientInput} gives about the client's own id — so between the wire and the
 * aggregate there is a moment where the three fields exist and the identity does not, and this type is that
 * moment. Without it the command would carry a shapeless `array{name: string, ...}` and every consumer would
 * re-describe it.
 *
 * **IT IS NOT A DOMAIN TYPE AND MUST NOT BECOME ONE.** It enforces nothing: `Contact` owns every bound, and the
 * handler constructing one is what applies them. A second type that validated the same three fields would be the
 * duplicated-guard shape `CLAUDE.md` § Gotchas 2026-08-07 records — where a mutant revealed a new guard was a
 * second copy of a rule the mapper already enforced, with a worse message.
 */
final readonly class NewContact
{
    public function __construct(
        public string $name,
        public ?string $email,
        public ?string $phone,
    ) {}
}
