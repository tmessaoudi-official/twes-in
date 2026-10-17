<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Demo\Infrastructure\ApiPlatform\Output;

use ApiPlatform\Metadata\ApiProperty;
use App\Demo\Domain\Model\DemoMail;

/** A mail kept for the visitor: its subject, when it would have left, and its HTML as rendered, which the screen sanitizes. */
final readonly class DemoMailRecord
{
    public function __construct(
        #[ApiProperty(required: true)] public string $id,
        #[ApiProperty(required: true)] public string $subject,
        #[ApiProperty(required: true)] public \DateTimeImmutable $capturedAt,
        #[ApiProperty(required: true)] public string $html,
    ) {
    }

    public static function of(DemoMail $mail): self
    {
        return new self($mail->getId()->toRfc4122(), $mail->getSubject(), $mail->getCapturedAt(), $mail->getHtml());
    }
}
