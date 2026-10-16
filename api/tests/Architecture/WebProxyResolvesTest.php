<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Architecture;

use PHPUnit\Framework\TestCase;

/**
 * The web tier proxies the API and Centrifugo by their compose names. A name written in `proxy_pass` is resolved once,
 * when nginx starts, so a redeploy that recreates the api alone (a rotated application password does) can leave the web
 * tier sending every request to an address the api no longer has, answered 502 until nginx is restarted. Each name is
 * therefore an upstream whose server nginx asks Docker's resolver for again (`resolve`), and every proxy_pass goes
 * through one.
 */
final class WebProxyResolvesTest extends TestCase
{
    public function testEveryProxiedServiceIsResolvedAgainWhileNginxRuns(): void
    {
        $conf = self::configuration();

        self::assertMatchesRegularExpression('/^resolver\s+127\.0\.0\.11\b[^;]*\bvalid=\d+s\b[^;]*;/m', $conf, "Docker's resolver, with how long an answer is kept");

        $upstreams = [];
        preg_match_all('/^upstream\s+([\w-]+)\s*\{([^}]*)\}/m', $conf, $blocks, \PREG_SET_ORDER);
        foreach ($blocks as [, $name, $body]) {
            self::assertMatchesRegularExpression('/\bzone\s+\S+\s+\d+k;/', $body, $name.' keeps its addresses in a shared zone, which resolve needs');
            self::assertMatchesRegularExpression('/\bserver\s+\S+\s+resolve;/', $body, $name.' asks for its server again while running');
            $upstreams[] = $name;
        }

        // Host and port together: `centrifugo:8000` names the service directly, not the upstream called `centrifugo`.
        preg_match_all('#proxy_pass\s+https?://([^/;\s]+)#', $conf, $passes);
        self::assertNotEmpty($passes[1], 'the configuration proxies the API');
        foreach ($passes[1] as $target) {
            self::assertContains($target, $upstreams, 'proxy_pass to '.$target.' goes through an upstream that resolves it again');
        }
    }

    private static function configuration(): string
    {
        $conf = file_get_contents(\dirname(__DIR__, 3).'/infra/web/nginx.conf');
        self::assertIsString($conf);

        return $conf;
    }
}
