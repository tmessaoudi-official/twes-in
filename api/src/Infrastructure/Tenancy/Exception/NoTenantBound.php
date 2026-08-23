<?php

/*
 * This file is part of twes-in.
 *
 * (c) Takieddine MESSAOUDI <takieddine.messaoudi.official@gmail.com>
 *
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Twes\Infrastructure\Tenancy\Exception;

/**
 * Wave 1's boundary rule, refused as a TYPE rather than as a sentence: no tenant-less path may reach tenant data.
 *
 * **WHY THIS EXISTS AT ALL — round 4, R4S-5 · R4K-11.** Five call sites raised a bare `\RuntimeException` for this,
 * so the transport could not tell an authorization-shaped refusal from any other server fault and answered an
 * untyped **500**. That is the worst possible status for it: `error.tenant_required` is listed in `CLAUDE.md`
 * § "Translation keys" as a reachable answer of the live transport, a 500 tells a caller to retry something they
 * must instead authenticate for, and — the part that matters most — **a tenancy control failing looks exactly like
 * any other crash in the logs**, so the one signal that would show the boundary rule firing was indistinguishable
 * from noise. No data leaked: the refusal is fail-closed and always was. The defect was the *report*.
 *
 * **ALL FIVE SITES, not the one the finding named.** `DoctrineInvoiceRepository`, `DoctrineClientRepository`,
 * `DoctrineProductRepository`, `DoctrineCompanySettingsRepository` and `PostgresDocumentNumberSequence` — found by
 * grepping for the rule rather than by following the finding, because a typed exception replacing one site of five
 * is this repository's most-recorded defect shape and would leave four refusals still answering 500.
 *
 * **EACH SITE KEEPS ITS OWN MESSAGE.** The messages are not interchangeable — a repository explains that a
 * tenant-less read is indistinguishable from "there is nothing", while the number sequence explains that a document
 * number is per (tenant, type). Collapsing them into one sentence here would trade a precise diagnostic for a
 * shorter constructor.
 *
 * **IT EXTENDS `\RuntimeException` DELIBERATELY**, so every `@throws \RuntimeException` tag on the paths above stays
 * true and any existing `catch` keeps working. Narrowing a thrown type is safe; widening one is not.
 *
 * **THE TRANSLATION KEY IS CARRIED AND NOT RESOLVED, and that is the honest state rather than an oversight.**
 * `CLAUDE.md` § "Translation keys" records that NOTHING resolves any of these keys yet, and that resolving them
 * needs a typed exception per refusal across the whole document kernel — a larger deliverable than this. That
 * sentence stays true: this class carries {@see self::TRANSLATION_KEY} so the eventual resolver has something to
 * read, and the status mapping below is what actually changes for a caller today.
 */
final class NoTenantBound extends \RuntimeException
{
    /**
     * The key § "Translation keys" already lists for this refusal, in all three locales.
     *
     * Carried rather than resolved — see the class docblock. A consumer that wants the translated sentence reads
     * this; a consumer that wants a status reads `api_platform.exception_to_status`.
     */
    public const string TRANSLATION_KEY = 'error.tenant_required';

    /**
     * @param string $attempted the operation refused, in the caller's own words, so the message names what failed
     * @param string $why the site's own explanation of what a tenant-less path would do here
     */
    public static function whileAttempting(string $attempted, string $why): self
    {
        return new self(\sprintf('Refusing to %s with no tenant bound. %s', $attempted, $why));
    }
}
