<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Scanning\Infrastructure\Http;

use App\Scanning\Domain\ScanPairingRefused;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/** How both sides of a pairing answer a refusal: one code the phone's screen translates. */
final class PairingErrors
{
    public static function refused(ScanPairingRefused $refused): JsonResponse
    {
        return new JsonResponse(['error' => $refused->reason], match ($refused->reason) {
            'unknown' => Response::HTTP_NOT_FOUND,
            'key' => Response::HTTP_FORBIDDEN,
            default => Response::HTTP_GONE,
        });
    }

    public static function invalid(string $detail): JsonResponse
    {
        return new JsonResponse(['error' => 'invalid', 'detail' => $detail], Response::HTTP_UNPROCESSABLE_ENTITY);
    }

    /** @return array<mixed> the JSON body, or nothing when there is none or it is not an object */
    public static function body(Request $request): array
    {
        try {
            $body = json_decode($request->getContent(), true, 8, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return [];
        }

        return \is_array($body) ? $body : [];
    }
}
