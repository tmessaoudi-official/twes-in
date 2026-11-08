<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Texts\Application;

use App\Texts\Domain\ValueObject\TextAddress;
use App\Texts\Domain\ValueObject\TextRefusal;

/**
 * The wording typed cannot replace the one shipped, and why ({@see TextRefusal}): a refusal of the value sent, which
 * the screen marks on the field it was typed in. A text that cannot be worded at all is {@see TextOverrideRefused}.
 */
final class TextValueRefused extends \DomainException
{
    public readonly string $reason;

    public function __construct(public readonly TextRefusal $refusal, TextAddress $address)
    {
        $this->reason = $refusal->value;
        parent::__construct(\sprintf('The wording of %s of %s in %s is refused: %s.', $address->key, $address->domain, $address->language, $refusal->value));
    }
}
