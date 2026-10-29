<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Unit\Tenancy\Infrastructure;

use App\Tenancy\Infrastructure\Session\SessionCompanyProofs;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\Uid\Uuid;

final class SessionCompanyProofsTest extends TestCase
{
    public function testAProofIsKeptWithTheSignInAndGoesWithIt(): void
    {
        $requests = new RequestStack();
        $request = new Request();
        $request->setSession(new Session(new MockArraySessionStorage()));
        $requests->push($request);
        $proofs = new SessionCompanyProofs($requests);
        $company = Uuid::v7();
        $at = new \DateTimeImmutable('@1800000000');

        $proofs->proved($company, $at);
        self::assertEquals(['proved' => $at, 'used' => $at], $proofs->of($company));
        $proofs->used($company, $at->modify('+5 minutes'));
        self::assertEquals($at->modify('+5 minutes'), $proofs->of($company)['used'] ?? null);

        $proofs->forgetAll();
        self::assertNull($proofs->of($company));
    }

    public function testWithoutASessionNothingIsHeldSoNothingIsProved(): void
    {
        $proofs = new SessionCompanyProofs(new RequestStack());
        $company = Uuid::v7();

        $proofs->proved($company, new \DateTimeImmutable());

        self::assertNull($proofs->of($company), 'a console or a worker has no sign-in to prove a company for');
    }
}
