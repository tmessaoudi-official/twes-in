<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Identity\Infrastructure\CustomerScreen;

use App\Identity\Application\CustomerScreen\CustomerScreenLock;
use App\Identity\Infrastructure\Security\SecurityUser;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;

/**
 * While the customer screen holds a sign-in, the sign-in reaches what the screen reads and the way out, nothing else
 * (docs/SPEC.md § 7, 2026-10-06 19:44): another tab, a new one or a typed call gets the clerk's app no more than the
 * screen does. The refusal names itself, so a tab that meets it goes to the screen.
 *
 * After the firewall (8), so the account is known, and before API Platform reads anything (4). Matched on paths, as
 * `MfaEnrolmentListener` is, and on methods: the screen only reads, so locking again onto another company is refused.
 */
#[AsEventListener(priority: 5)]
final readonly class CustomerScreenLockListener
{
    public const string LOCKED = 'customer_screen_locked';

    /** Who is signed in, the way out (signing out, proving it again), and the realtime token the screen's live reload needs. */
    private const array ANY_METHOD = ['/api/auth/me', '/api/auth/logout', '/api/auth/step-up', '/api/auth/step-up/passkey', '/api/auth/step-up/passkey/options', '/api/me/realtime-token'];

    public function __construct(private Security $security, private CustomerScreenLock $lock)
    {
    }

    public function __invoke(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }
        $request = $event->getRequest();
        $path = rtrim($request->getPathInfo(), '/');
        // The hold lives in the session: a request bringing none cannot be held, and asking who is signed in would make
        // the lazy firewall read a session for it, which turns a public answer's cache header private.
        if (!str_starts_with($path, '/api') || !$request->hasPreviousSession()) {
            return;
        }
        $account = $this->security->getUser();
        if (!$account instanceof SecurityUser) {
            return;
        }
        $company = $this->lock->lockedFor($account->getId());
        if (null === $company || self::open($request, $path, $company->toRfc4122())) {
            return;
        }

        $event->setResponse(new JsonResponse(['error' => self::LOCKED], Response::HTTP_FORBIDDEN));
    }

    private static function open(Request $request, string $path, string $company): bool
    {
        if (\in_array($path, self::ANY_METHOD, true) || ('/api/auth/customer-screen' === $path && $request->isMethod('DELETE'))) {
            return true;
        }
        if (!$request->isMethod('GET')) {
            return false;
        }
        $base = '/api/companies/'.$company;

        return str_starts_with($path, $base.'/customer-screen/')
            || $base.'/establishments' === $path
            || ($base.'/settings' === $path && 'presentation' === $request->query->get('chain'));
    }
}
