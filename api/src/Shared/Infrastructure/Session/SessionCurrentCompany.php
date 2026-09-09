<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Shared\Infrastructure\Session;

use App\Shared\Application\CurrentCompany;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Uid\Uuid;

/** The session company as a session attribute; gone with the session at logout. */
final readonly class SessionCurrentCompany implements CurrentCompany
{
    private const string SESSION_KEY = 'company_id';

    public function __construct(private RequestStack $requestStack)
    {
    }

    public function id(): ?Uuid
    {
        $raw = $this->requestStack->getSession()->get(self::SESSION_KEY);

        return \is_string($raw) && Uuid::isValid($raw) ? Uuid::fromString($raw) : null;
    }

    public function set(?Uuid $companyId): void
    {
        $session = $this->requestStack->getSession();
        if (null === $companyId) {
            $session->remove(self::SESSION_KEY);

            return;
        }
        $session->set(self::SESSION_KEY, $companyId->toRfc4122());
    }
}
