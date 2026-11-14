<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Infrastructure\Http;

use App\Shared\Infrastructure\Http\ClientAddress;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

final class ClientAddressTest extends TestCase
{
    public function testTheAddressARequestCameFrom(): void
    {
        self::assertSame('192.0.2.7', ClientAddress::of(Request::create('/api/health', server: ['REMOTE_ADDR' => '192.0.2.7'])));
    }

    public function testNoRequestHasNoBudgetOfItsOwn(): void
    {
        $this->expectExceptionObject(new \LogicException('No client address: a per-client budget is spent only by a request made over HTTP.'));

        ClientAddress::of(null);
    }

    public function testARequestWithNoAddressSharesNoKeyWithAnother(): void
    {
        $request = Request::create('/api/health');
        $request->server->remove('REMOTE_ADDR');

        $this->expectExceptionObject(new \LogicException('No client address: a per-client budget is spent only by a request made over HTTP.'));

        ClientAddress::of($request);
    }
}
