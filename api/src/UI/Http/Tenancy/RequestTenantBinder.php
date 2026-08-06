<?php

/*
 * This file is part of twes-in.
 *
 * (c) Takieddine MESSAOUDI <takieddine.messaoudi.official@gmail.com>
 *
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Twes\UI\Http\Tenancy;

use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Twes\Infrastructure\Tenancy\InMemoryTenantContext;
use Twes\Infrastructure\Tenancy\TenantResolver;

/**
 * Binds the request's tenant into the application's {@see InMemoryTenantContext}, once, before anything reads it.
 *
 * The HTTP half of the tenancy seam. `TenantResolver` decides *whether* this request proved a tenant; this puts
 * the answer where the rest of the application already looks for it, so no controller, provider or repository
 * learns that an HTTP request was involved.
 *
 * **ON `kernel.request` AT A DELIBERATE PRIORITY, and both halves of that matter.**
 * - `kernel.request` rather than a controller argument resolver, because the binding must exist for *everything*
 *   a request touches — a state provider, an event subscriber, a serialiser — not only for code that thought to
 *   ask for it.
 * - **Priority 8**, which is *after* Symfony's router (priority 32) and *before* the controller. After the router
 *   because a resolver may eventually want the matched route; before the controller because everything downstream
 *   depends on the binding. It is not the highest priority available, and that is on purpose: running before the
 *   router would mean running before the firewall does in Wave 7, and a tenant bound before authentication has
 *   decided anything is the shape this whole class exists to avoid.
 *
 * **MAIN REQUESTS ONLY.** A sub-request (a forwarded error page, an ESI fragment) inherits the main request's
 * tenant, and re-resolving one would let a forwarded request rebind — including to *nothing*, if the sub-request
 * lacks whatever carried the claim. Clearing a tenant mid-request is the divergence
 * `PostgresRowLevelSecurityIsolation::assertStillBoundTo()` exists to catch, so it must not be caused here.
 *
 * **WHY IT DEPENDS ON THE CONCRETE `InMemoryTenantContext` AND NOT ON `TenantContext`.** The port is read-only —
 * `hasTenant()` and `tenantId()` — because everything that consumes a tenant should be unable to change one.
 * Exactly one thing per process may write it, and that thing is this class (or a CLI command, or a worker between
 * messages). Depending on the concrete mutable type is how that asymmetry stays visible: a reader who sees
 * `TenantContext` injected knows it cannot rebind, and a reader who sees `InMemoryTenantContext` knows to look
 * closely. Widening the port with a `switchTo()` would hand that power to every consumer.
 */
#[AsEventListener(event: KernelEvents::REQUEST, priority: 8)]
final readonly class RequestTenantBinder
{
    public function __construct(
        private TenantResolver $resolver,
        private InMemoryTenantContext $context,
    ) {}

    /**
     * @throws \RuntimeException if the request claims a tenant the resolver refuses to honour
     */
    public function __invoke(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $tenant = $this->resolver->resolve($event->getRequest());

        if (null === $tenant) {
            // CLEARED, NOT LEFT ALONE, and this is the load-bearing line of the class.
            //
            // `InMemoryTenantContext` is a SERVICE — one instance for the container's life — so under any
            // long-running process (a Messenger worker today; FrankenPHP worker mode when Wave 10 unblocks it) a
            // tenant bound by an earlier unit of work is still there. Leaving it alone would mean a tenant-less
            // request INHERITING the previous request's tenant, which is a cross-tenant read arriving through
            // the most innocent possible path: a health check, or an unauthenticated endpoint, served correctly.
            //
            // `clear()` exists on the context for exactly this, and its own docblock makes the same argument for
            // workers: "a job which forgets to set one fails instead of silently inheriting its predecessor's
            // data". Fail-closed is the whole direction — the repository refuses to hydrate an aggregate with no
            // tenant, and the row-level-security policy returns nothing to an unbound session.
            $this->context->clear();

            return;
        }

        $this->context->switchTo($tenant);
    }
}
