<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Shared\Infrastructure\Submissions;

use App\Shared\Infrastructure\Http\RoutedPath;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Uid\Uuid;

/**
 * One submission, one write (docs/SPEC.md § 3): a write that adds rather than moves a state (a payment, money kept to a
 * customer's credit, goods taken in, a file's rows) carries an `Idempotency-Key`, the screen's name for one attempt, and
 * the same key from the same account answers what the first answered instead of adding again. The header follows the
 * convention Stripe set and the IETF draft (draft-ietf-httpapi-idempotency-key-header, expired unpublished) wrote down:
 * a missing key 400, a key sent again with another body 422, a key still being worked on 409. A route takes part by its
 * route default `ROUTE_DEFAULT`, on an API Platform operation (`defaults:`) and a controller (`#[Route(defaults:)]`) alike.
 */
final class Submissions
{
    public const string ROUTE_DEFAULT = '_one_submission';
    public const string HEADER = 'Idempotency-Key';
    public const string KEY_SERVER = 'HTTP_IDEMPOTENCY_KEY';
    public const string REPLAYED_HEADER = 'Idempotent-Replayed';

    public const string KEY_MISSING = '/problems/submission-key-missing';
    public const string KEY_INVALID = '/problems/submission-key-invalid';
    public const string KEY_REUSED = '/problems/submission-key-reused';
    public const string IN_PROGRESS = '/problems/submission-in-progress';
    public const string UNANSWERED = '/problems/submission-unanswered';

    /** Whether the request's route takes part. */
    public static function guards(Request $request): bool
    {
        return true === $request->attributes->get(self::ROUTE_DEFAULT);
    }

    /**
     * The key the request sends, bare or written as a structured field String (`"…"`, RFC 8941), the way the draft
     * writes it; null when it sends none.
     *
     * @throws \InvalidArgumentException when what it sends is not a UUID
     */
    public static function keyOf(Request $request): ?Uuid
    {
        $value = trim((string) $request->headers->get(self::HEADER, ''));
        if ('' === $value) {
            return null;
        }
        if (1 === preg_match('/^"(.*)"$/', $value, $quoted)) {
            $value = $quoted[1];
        }
        if (!Uuid::isValid($value)) {
            throw new \InvalidArgumentException('The submission key is not a UUID.');
        }

        return Uuid::fromString($value);
    }

    /**
     * What a submission asked: its method, its path and its body. A file is read by its name and content, since the
     * browser draws a new multipart boundary for each sending of the same form.
     */
    public static function fingerprintOf(Request $request): string
    {
        if ([] === $request->files->all()) {
            return self::fingerprint($request->getMethod(), RoutedPath::of($request), $request->getContent());
        }
        $files = [];
        foreach (self::flatten($request->files->all()) as $field => $file) {
            $files[$field] = $file instanceof UploadedFile
                ? [$file->getClientOriginalName(), hash_file('sha256', $file->getPathname())]
                : null;
        }
        ksort($files);
        $fields = self::flatten($request->request->all());
        ksort($fields);

        return self::fingerprint($request->getMethod(), RoutedPath::of($request), json_encode(['fields' => $fields, 'files' => $files], \JSON_THROW_ON_ERROR));
    }

    public static function fingerprint(string $method, string $path, string $body): string
    {
        return hash('sha256', $method."\n".$path."\n".$body);
    }

    /**
     * @param array<array-key, mixed> $values
     *
     * @return array<string, mixed> each leaf under its bracketed name (`choices[stock]`)
     */
    private static function flatten(array $values, string $prefix = ''): array
    {
        $flat = [];
        foreach ($values as $name => $value) {
            $key = '' === $prefix ? (string) $name : $prefix.'['.$name.']';
            if (\is_array($value)) {
                $flat = [...$flat, ...self::flatten($value, $key)];
            } else {
                $flat[$key] = $value;
            }
        }

        return $flat;
    }
}
