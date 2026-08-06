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
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\KernelInterface;
use Twes\Infrastructure\Tenancy\InMemoryTenantContext;
use Twes\Infrastructure\Tenancy\TenantId;
use Twes\UI\Http\Tenancy\HeaderTenantResolver;
use Twes\UI\Http\Tenancy\RequestTenantBinder;

/**
 * REQUEST-TIME TENANCY: what binds a tenant, and — mostly — what must refuse to.
 *
 * **The balance of this file is deliberate.** Two cases assert that a permitted header binds; the rest assert
 * refusals and clearings. That is the right proportion for a mechanism whose failure mode is a cross-tenant read
 * of every client's invoices, and whose enabling flag is disabled in every committed configuration — so the
 * behaviour that runs in production is the REFUSAL, and it is what most needs proving.
 *
 * Driven by invoking the listener directly rather than by booting a kernel and issuing requests. That is not a
 * shortcut: the subject is the listener's decision — main-vs-sub request, bind-vs-clear, honour-vs-refuse — and a
 * kernel round trip would prove routing and serialisation instead. The container wiring is covered by
 * `lint:container` and by `debug:container` showing the port aliased to the one adapter.
 */
#[CoversClass(RequestTenantBinder::class)]
#[CoversClass(HeaderTenantResolver::class)]
final class RequestTenancyTest extends TestCase
{
    private const TENANT = '0199a5b2-0000-7000-8000-0000000003aa';
    private const OTHER_TENANT = '0199a5b2-0000-7000-8000-0000000003bb';

    /** A permitted header binds the tenant the rest of the application will read. */
    public function testAPermittedHeaderBindsTheTenant(): void
    {
        $context = InMemoryTenantContext::empty();
        $this->bind($context, permitted: true, headers: [HeaderTenantResolver::HEADER => self::TENANT]);

        self::assertTrue($context->hasTenant(), 'a tenant must be bound');
        self::assertSame(self::TENANT, $context->tenantId()->toString());
    }

    /**
     * **A FORBIDDEN HEADER IS REFUSED LOUDLY, NOT IGNORED.** The production behaviour, and the reason the class
     * exists in a safe form at all.
     *
     * Ignoring it would present as "you are nobody", which a caller reads as an empty account rather than as a
     * rejected request — and an empty invoice list is indistinguishable from a client who has no invoices, which
     * is the worst possible way to answer a request that tried to impersonate someone.
     */
    public function testAForbiddenHeaderIsRefusedLoudlyRatherThanIgnored(): void
    {
        $context = InMemoryTenantContext::empty();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('NOT PERMITTED');

        $this->bind($context, permitted: false, headers: [HeaderTenantResolver::HEADER => self::TENANT]);
    }

    /**
     * A TENANT-LESS REQUEST **CLEARS** A PREVIOUSLY BOUND TENANT. The single most important case here.
     *
     * `InMemoryTenantContext` is a service — one instance for the container's life — so under any long-running
     * process (a Messenger worker today, FrankenPHP worker mode when Wave 10 unblocks it) a tenant bound by an
     * earlier unit of work is still there. Leaving it alone would mean a tenant-less request INHERITING the
     * previous request's tenant: a cross-tenant read arriving through the most innocent path available, an
     * unauthenticated endpoint served correctly.
     */
    public function testATenantLessRequestClearsAPreviouslyBoundTenant(): void
    {
        $context = InMemoryTenantContext::forTenant(TenantId::fromString(self::OTHER_TENANT));
        self::assertTrue($context->hasTenant(), 'the fixture starts bound, standing in for a previous request');

        $this->bind($context, permitted: true, headers: []);

        self::assertFalse(
            $context->hasTenant(),
            'a request that proved no tenant must leave NO tenant bound — inheriting one is a cross-tenant read',
        );
    }

    /** And it clears even when the header is FORBIDDEN, because absence is checked before permission. */
    public function testATenantLessRequestClearsEvenWhenTheHeaderIsForbidden(): void
    {
        $context = InMemoryTenantContext::forTenant(TenantId::fromString(self::OTHER_TENANT));

        // No exception: a health check must not fail because of a tenancy setting. That ordering is explicit in
        // `HeaderTenantResolver` — a tenancy setting that breaks liveness probes is a setting somebody turns on.
        $this->bind($context, permitted: false, headers: []);

        self::assertFalse($context->hasTenant(), 'cleared, and no exception raised');
    }

    /**
     * A SUB-REQUEST DOES NOT REBIND. A forwarded error page or an ESI fragment inherits the main request's tenant.
     *
     * Re-resolving one would let a forwarded request rebind — including to NOTHING, if the sub-request lacks
     * whatever carried the claim. Clearing a tenant mid-request is precisely the divergence
     * `PostgresRowLevelSecurityIsolation::assertStillBoundTo()` exists to detect, so it must not be caused here.
     */
    public function testASubRequestDoesNotRebind(): void
    {
        $context = InMemoryTenantContext::forTenant(TenantId::fromString(self::TENANT));

        $this->bind(
            $context,
            permitted: true,
            headers: [HeaderTenantResolver::HEADER => self::OTHER_TENANT],
            type: HttpKernelInterface::SUB_REQUEST,
        );

        self::assertSame(
            self::TENANT,
            $context->tenantId()->toString(),
            'a sub-request must not rebind — it inherits the main request\'s tenant',
        );
    }

    /** A malformed claim is a caller error, refused rather than answered with an empty result set. */
    public function testAMalformedTenantIdIsRefused(): void
    {
        $context = InMemoryTenantContext::empty();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('not a canonical tenant id');

        $this->bind($context, permitted: true, headers: [
            HeaderTenantResolver::HEADER => 'not-a-uuid-at-all',
        ]);
    }

    /**
     * AN UPPERCASE TENANT ID IS **NORMALISED**, NOT REFUSED — and the asymmetry with `DocumentIdentity` is
     * deliberate, so it is pinned here rather than left to surprise someone.
     *
     * `DocumentIdentity` REFUSES a non-canonical document id, because a document id is a key the client supplies
     * back to us and two spellings of one key compare unequal in an application-level comparison.
     * `TenantId::fromString()` NORMALISES with `strtolower()` before validating, and that is right for a different
     * reason: a tenant id ends up in a **text** GUC (`twes.tenant_id`) which the canonical policy casts with
     * `::uuid`, and PostgreSQL's `uuid` comparison is case-insensitive — so normalising loses nothing and refusing
     * would reject a caller who was not wrong about anything.
     *
     * The first version of this test asserted the opposite and FAILED, which is how the asymmetry got noticed.
     * A reader who knows one of these two types would guess wrong about the other.
     */
    public function testAnUppercaseTenantIdIsNormalisedRatherThanRefused(): void
    {
        $context = InMemoryTenantContext::empty();
        $this->bind($context, permitted: true, headers: [
            HeaderTenantResolver::HEADER => strtoupper(self::TENANT),
        ]);

        self::assertSame(
            self::TENANT,
            $context->tenantId()->toString(),
            'normalised to the canonical lowercase form, not refused',
        );
    }

    /**
     * THE HEADER NAME IS `X-Tenant-Id`, and that is pinned rather than incidental.
     *
     * The `X-` prefix is deprecated for standardised headers and exactly right for this one: it makes the value
     * read as obviously caller-supplied. A friendlier name — or worse a cookie — would read like something the
     * framework had established, which is the misreading this whole class is designed against.
     */
    public function testTheHeaderNameAdvertisesThatItIsCallerSupplied(): void
    {
        self::assertSame('X-Tenant-Id', HeaderTenantResolver::HEADER);
    }

    /**
     * @param array<string, string> $headers
     */
    private function bind(
        InMemoryTenantContext $context,
        bool $permitted,
        array $headers,
        int $type = HttpKernelInterface::MAIN_REQUEST,
    ): void {
        $request = new Request();

        foreach ($headers as $name => $value) {
            $request->headers->set($name, $value);
        }

        $binder = new RequestTenantBinder(new HeaderTenantResolver($permitted), $context);
        $binder(new RequestEvent($this->createStub(KernelInterface::class), $request, $type));
    }
}
