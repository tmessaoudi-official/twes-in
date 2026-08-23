<?php

/*
 * This file is part of twes-in.
 *
 * (c) Takieddine MESSAOUDI <takieddine.messaoudi.official@gmail.com>
 *
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Twes\Tests\Functional\Http;

use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Twes\Infrastructure\Tenancy\Exception\NoTenantBound;

/**
 * A request that proves no tenant is refused with an AUTHORIZATION status, not a server fault.
 *
 * **ROUND 4, R4S-5 · R4K-11.** Five repositories raised a bare `\RuntimeException` for Wave 1's boundary rule, so
 * the transport had nothing to key on and answered an untyped **500**. Nothing leaked — the refusal is fail-closed
 * and always was — but the report was wrong in two ways that cost more than they look:
 *
 *   * a 500 tells a caller to retry something they must instead authenticate for;
 *   * and it makes a tenancy control FIRING indistinguishable, in logs and in monitoring, from any other crash.
 *     The one signal that would show this boundary working was buried in exactly the noise it should stand out
 *     from — which matters most on the day it starts firing for a reason nobody expected.
 *
 * `§ "Translation keys"` already listed `error.tenant_required` as *"a reachable answer of the live transport"*,
 * and the reachable answer was a stack trace.
 *
 * **THIS ASSERTS THE STATUS, NOT THE BODY, and deliberately so.** Nothing resolves any translation key yet —
 * `CLAUDE.md` § "Translation keys" records that as owed, and says resolving them needs a typed exception per
 * refusal across the whole document kernel. {@see NoTenantBound} carries its key for that eventual resolver; what
 * changes for a caller TODAY is the status, so that is what is pinned. Asserting a translated sentence here would
 * be asserting a thing the transport does not do.
 */
#[CoversClass(NoTenantBound::class)]
final class TenantlessRequestStatusTest extends WebTestCase
{
    /**
     * A GET with no tenant header answers 401, not 500.
     *
     * `GET /api/invoices/{id}` because the read path reaches `DoctrineInvoiceRepository::currentTenant()` with no
     * request body to be rejected by the validator first — a POST would be refused for its payload before the
     * boundary rule was ever consulted, and would prove nothing about this.
     *
     * The id is well-formed and names nothing. That is deliberate: the refusal must happen because no tenant is
     * bound, BEFORE any question about whether the document exists. If this ever starts answering 404 the boundary
     * rule has moved behind the lookup, which is the ordering that lets a tenant-less path touch tenant data.
     */
    public function testATenantLessReadAnswersAnAuthorizationStatusRatherThanAServerFault(): void
    {
        $client = static::createClient();
        $client->request('GET', '/api/invoices/0199a5b2-0000-7000-8000-0000000000ff');

        $status = $client->getResponse()->getStatusCode();

        self::assertNotSame(
            500,
            $status,
            'A tenant-less request must not be reported as a server fault. It is an authorization-shaped refusal, '
            . 'and reporting it as a crash is what makes a tenancy control firing invisible in the logs.',
        );

        self::assertSame(
            401,
            $status,
            'The request has not proved WHO it is, so 401 — "authenticate and try again" — is the instruction. '
            . '403 would claim we know the caller and are denying them, which this code cannot say: there is no '
            . 'identity to deny until Wave 7.',
        );
    }
}
