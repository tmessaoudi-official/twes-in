<?php

/*
 * This file is part of twes-in.
 *
 * (c) Takieddine MESSAOUDI <takieddine.messaoudi.official@gmail.com>
 *
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Twes\Tests\E2E;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * THE FIRST CONTENT OF THE `e2e` SUITE — what a real client receives from a really-running server.
 *
 * **WHY THIS SUITE HAD TO EXIST, and why the `functional` one cannot do its job.** Every assertion below is about a
 * response header or a status code produced by **Caddy**, not by the Symfony kernel. `HttpSurfaceTest` goes through
 * the kernel: it never sees a `Content-Security-Policy`, never sees the `/bundles/*` file server, and never sees the
 * final catch-all `handle`. `CLAUDE.md` records this as owed in exactly those words — *"the Caddy-level CSP that makes
 * the local documentation UI safe cannot be seen through the kernel, because the kernel is not what serves it."*
 *
 * **THE THREE FAILURES THIS PINS were each a 200 with the correct `<title>`** (`CLAUDE.md` § Gotchas, 2026-08-05), so
 * none of them was visible from an exit code, a status code, or a passing test:
 *
 * 1. **The documentation page's assets 404'd.** The site serves ONLY the front controller, so `public/bundles/**`
 *    reached the catch-all and got 404. Closed by a narrow `handle /bundles/*` file server — narrow rather than a
 *    file server over `public/`, because "only the front controller executes" is what stops any `.php` under a
 *    writable path being run.
 * 2. **`default-src 'none'; … sandbox` blocked every stylesheet and disabled script execution.** Correct for an API
 *    response, fatal for a document. `curl` fetched every asset with 200 while Chromium refused to apply them.
 * 3. **The fix did not apply, because an unmatched `header` and a matcher-scoped `header` overriding the same field
 *    is ORDER-DEPENDENT** and Caddy did not resolve it the way the file reads. Closed by two DISJOINT matchers, so
 *    exactly one applies to any request and ordering stops mattering.
 *
 * Failure 3 is the one this suite is really for: it is a property of the *interaction* between two directives, and
 * the only way to observe it is to ask the running server what it actually sent.
 *
 * **IT FAILS RATHER THAN SKIPPING when no server is reachable**, and that is the same call `CLAUDE.md` records for the
 * integration suite: a green run that silently skipped the check is the worst outcome available. The consequence is
 * that this suite is deliberately **NOT** in `composer gate` — see `composer gate:e2e` and § "Quality gate". Making it
 * skip would have kept it in the chain at the cost of the one property that makes it worth having.
 *
 * Run it with the stack up:
 *
 *     make up
 *     cd api && TWES_E2E_BASE_URL=http://localhost:8080 composer gate:e2e
 */
#[CoversNothing]
final class ServedSurfaceTest extends TestCase
{
    /** The relaxed policy, verbatim from `infra/api/Caddyfile`. A document may load its own assets and nothing else. */
    private const DOCS_POLICY = "default-src 'self'; script-src 'self' 'unsafe-inline'; style-src 'self' "
        . "'unsafe-inline'; img-src 'self' data:; font-src 'self'; connect-src 'self'; frame-ancestors 'none'; "
        . "base-uri 'self'; form-action 'self'";

    /** The strict policy. An API response is DATA and must never be interpreted as a document. */
    private const DATA_POLICY = "default-src 'none'; frame-ancestors 'none'; sandbox";

    /**
     * A CONCRETE REQUEST PATH FOR EVERY PATTERN THE CADDYFILE MATCHES ON, keyed by the pattern.
     *
     * **Derived-and-checked rather than hand-listed**, which is the shape `test-gates.sh` uses for the gate inventory:
     * {@see self::testEveryMatchedPathPatternHasAProbe()} reads the `@apiDocs` and `@apiData` blocks out of the
     * Caddyfile and fails if either grows a pattern with no entry here. A glob cannot be turned into a request
     * automatically — `/api/docs.*` has to become some real suffix — so the mapping is written down and the
     * COMPLETENESS of it is what gets checked.
     *
     * @var array<string, string>
     */
    private const PROBES = [
        '/api' => '/api',
        '/api/docs' => '/api/docs',
        '/api/docs.*' => '/api/docs.jsonopenapi',
        '/api/contexts/*' => '/api/contexts/Invoice',
        '/api/*' => '/api/currencies',
        '/health' => '/health',
        '/health/*' => '/health/ready',
    ];

    /**
     * **THE DOCUMENTATION PATHS GET THE RELAXED POLICY.** Failure 2 and 3 of the class docblock, directly.
     *
     * Asserted as the WHOLE header rather than by substring, because a substring check passes on a policy that also
     * carries `sandbox` — and `sandbox` alone is what disabled script execution and left the page a 200 with nothing
     * usable on it.
     */
    #[DataProvider('documentationPaths')]
    public function testADocumentationPathGetsTheRelaxedPolicy(string $path): void
    {
        $response = self::get($path);

        self::assertSame(
            self::DOCS_POLICY,
            $response['headers']['content-security-policy'] ?? null,
            $path . ' must get the document policy — the strict one blocks every stylesheet and disables scripts, '
            . 'which renders as a 200 with a correct title and nothing else',
        );
    }

    /**
     * **AND EVERY DATA PATH KEEPS THE LOCKDOWN.** The other half of the disjointness, and the more important half:
     * a relaxed policy leaking onto a resource endpoint is what makes an API response interpretable as a document.
     */
    #[DataProvider('dataPaths')]
    public function testADataPathGetsTheStrictPolicy(string $path): void
    {
        $response = self::get($path);

        self::assertSame(
            self::DATA_POLICY,
            $response['headers']['content-security-policy'] ?? null,
            $path . ' is DATA and must keep `default-src \'none\'` plus `sandbox`',
        );
    }

    /**
     * THE TWO POLICIES ARE ACTUALLY DIFFERENT ON THE WIRE — the assertion that would have caught failure 3.
     *
     * With an unmatched `header` plus a matcher-scoped one, Caddy served the STRICT policy on `/api` while the file
     * read as though the relaxed one applied. Both of the tests above would still have failed then, but this one says
     * *why* in one line, and it is the property the two-disjoint-matcher design exists to guarantee: whichever
     * directive order Caddy chooses, exactly one matcher applies.
     */
    public function testTheDocumentationAndDataPoliciesAreNotTheSameHeader(): void
    {
        $docs = self::get('/api')['headers']['content-security-policy'] ?? null;
        $data = self::get('/api/currencies')['headers']['content-security-policy'] ?? null;

        self::assertNotNull($docs, '/api sent no CSP at all');
        self::assertNotNull($data, '/api/currencies sent no CSP at all');
        self::assertNotSame(
            $docs,
            $data,
            'ONE policy is serving both, which means the two matchers are not disjoint and the result depends on '
            . 'directive order — the defect that made the documentation page unusable while the file read correctly',
        );
    }

    /**
     * **THE DOCUMENTATION'S ASSETS ARE SERVED.** Failure 1, and the reason the page is styled at all.
     *
     * A stylesheet rather than a script, because it is the one Chromium named in the original diagnosis. The
     * `Cache-Control` is asserted too: these assets are versioned by the dependency rather than content-hashed, so a
     * long immutable `max-age` would be wrong — a `composer update` changes the bytes at the same URL.
     */
    public function testTheDocumentationAssetsAreServedRatherThanFourOhFour(): void
    {
        $response = self::get('/bundles/apiplatform/swagger-ui/swagger-ui.css');

        self::assertSame(200, $response['status'], 'the narrow /bundles/* file server must serve this');
        self::assertStringContainsString('text/css', $response['headers']['content-type'] ?? '');
        self::assertSame('public, max-age=86400', $response['headers']['cache-control'] ?? null);
        self::assertStringContainsString('swagger', strtolower($response['body']), 'and it is really the stylesheet');
    }

    /**
     * **THE FILE SERVER IS NARROW: nothing outside `/bundles/` is served, and PHP has ONE entry point.**
     *
     * The security property that makes failure 1's fix acceptable. A file server over `public/` would have been the
     * obvious fix and would have made "only the front controller executes" false — which is what stops a `.php`
     * reaching any writable path from being run.
     *
     * `/index.php` is the sharpest of these: the front controller exists on disk at the document root, and it must
     * still 404, because the `php_server` directive lives inside `handle @php` and therefore only covers `/api*` and
     * `/health*`. A 200 here would mean the kernel is reachable at a second URL, outside every matcher above — so the
     * CSP assertions would be describing only one of two doors.
     *
     * @param string $path something an attacker would try
     */
    #[DataProvider('pathsThatMustNotBeServed')]
    public function testNothingOutsideTheBundlesDirectoryIsServed(string $path): void
    {
        $response = self::get($path);

        self::assertSame(
            404,
            $response['status'],
            $path . ' must not be served: the site executes only the front controller and serves only /bundles/*',
        );
        self::assertStringNotContainsString(
            '<?php',
            $response['body'],
            $path . ' returned PHP SOURCE, which is worse than executing it',
        );
    }

    /** @return iterable<string, array{string}> */
    public static function pathsThatMustNotBeServed(): iterable
    {
        yield 'the front controller itself' => ['/index.php'];
        yield 'a vendor file' => ['/vendor/autoload.php'];
        yield 'the manifest' => ['/composer.json'];
        yield 'the dotenv' => ['/.env'];
        // A traversal attempt out of the one directory the file server does cover.
        yield 'traversal out of /bundles' => ['/bundles/../composer.json'];
    }

    /**
     * **EVERY RESPONSE CARRIES THE SITE-WIDE SECURITY HEADERS, and none advertises the server.**
     *
     * These are set once at the site level, and the point of asserting them HERE is that a `header` block inside a
     * `handle` can silently replace rather than merge — which is precisely how the CSP fix failed to apply. So the
     * site-level headers are re-checked on a documentation path, a data path AND a static asset, each of which is
     * served by a different handler.
     *
     * `-Server` is asserted as an ABSENCE. It is the only one of these that leaks rather than protects, and an absence
     * is the kind of thing that regresses without any test noticing.
     */
    #[DataProvider('oneProbePerHandler')]
    public function testTheSiteWideSecurityHeadersSurviveEveryHandler(string $path): void
    {
        $headers = self::get($path)['headers'];

        self::assertSame('nosniff', $headers['x-content-type-options'] ?? null, $path);
        self::assertSame('DENY', $headers['x-frame-options'] ?? null, $path);
        self::assertSame('strict-origin-when-cross-origin', $headers['referrer-policy'] ?? null, $path);
        self::assertArrayHasKey('strict-transport-security', $headers, $path);
        self::assertArrayHasKey('permissions-policy', $headers, $path);

        self::assertArrayNotHasKey(
            'server',
            $headers,
            $path . ' advertises its server software, which is free reconnaissance',
        );
    }

    /** @return iterable<string, array{string}> */
    public static function oneProbePerHandler(): iterable
    {
        yield 'the documentation handler' => ['/api'];
        yield 'the data handler' => ['/api/currencies'];
        yield 'the static asset handler' => ['/bundles/apiplatform/swagger-ui/swagger-ui.css'];
        yield 'the catch-all handler' => ['/nothing-is-here'];
    }

    /**
     * **NO API RESPONSE MAY BE HELD BY A SHARED CACHE, and `Vary` names what makes one response differ from another.**
     *
     * Every API response is tenant-scoped. A shared cache keying on URL alone would serve one tenant's invoice to
     * another — the HTTP-layer version of the row-level-security boundary, and a cross-tenant read that never touches
     * the database at all.
     */
    #[DataProvider('apiPaths')]
    public function testNoApiResponseIsShareableByACache(string $path): void
    {
        $headers = self::get($path)['headers'];

        self::assertSame('no-store, private', $headers['cache-control'] ?? null, $path);
        self::assertSame('Accept, Authorization', $headers['vary'] ?? null, $path);
    }

    /**
     * NOTHING ANYWHERE SETS A COOKIE. The stateless contract, checked at the layer that would actually send one.
     *
     * `framework.yaml` disables sessions and every resource declares `stateless: true`, and `HttpSurfaceTest` asserts
     * it through the kernel — but a `Set-Cookie` can also be added by the server in front of the kernel, which is the
     * layer this suite is for.
     */
    #[DataProvider('everyProbe')]
    public function testNothingSetsACookie(string $path): void
    {
        self::assertArrayNotHasKey('set-cookie', self::get($path)['headers'], $path);
    }

    /**
     * **A HEADER THE EDGE OWNS IS SENT EXACTLY ONCE — the first real defect this suite found, and the one my own
     * parser was blind to.**
     *
     * `infra/api/Caddyfile` set `Cache-Control` and `Vary` without Caddy's `>` (deferred-set) prefix on EITHER field
     * — a `header` block containing any deferred operation is applied as a whole, so the mutant that kills this case
     * has to remove the prefix from both — and Caddy therefore wrote
     * them before the PHP handler ran and Symfony then sent its own: the response carried
     * `Cache-Control: no-store, private` AND `Cache-Control: no-cache, private`, `Vary: Accept, Authorization` AND
     * `Vary: Accept`. RFC 9110 lets a recipient combine repeated field lines, so a conforming cache saw the union and
     * the stricter directive won — not a hole, but an outcome that depended on the recipient rather than on our
     * configuration.
     *
     * **The lesson is about this TEST, not about Caddy.** `get()`'s header MAP collapses a repeated field to whichever
     * line came last, so the value assertions elsewhere in this class would have passed unchanged had Caddy's line
     * happened to be second — the defect was visible only because the application's line happened to come last. A
     * check on a collapsed representation cannot see a duplication defect at all, which is the same shape `CLAUDE.md`
     * records twice for assertions about a *representation* rather than a *property*. So this one counts raw lines.
     */
    #[DataProvider('apiPaths')]
    public function testTheEdgeOwnedHeadersAreSentExactlyOnce(string $path): void
    {
        $lines = self::get($path)['lines'];

        foreach (['cache-control', 'vary', 'content-security-policy'] as $field) {
            self::assertSame(
                1,
                \count(array_keys($lines, $field, true)),
                \sprintf(
                    '%s sent %s %d times. Two values for one field is resolved by the RECIPIENT, not by us — use '
                    . 'Caddy\'s `>` prefix so the edge sets it after the response is written.',
                    $path,
                    $field,
                    \count(array_keys($lines, $field, true)),
                ),
            );
        }
    }

    /**
     * **THE CADDYFILE'S MATCHERS AND THIS FILE'S PROBES AGREE — a completeness check on the test, not on the server.**
     *
     * `CLAUDE.md` records, repeatedly, that a hand-written enumeration is fail-open for every member nobody thought
     * of. The two matchers here are the enumeration, so they are read out of the Caddyfile and compared against
     * {@see self::PROBES}: add a documentation path to `@apiDocs` without adding a probe and this fails, rather than
     * the new path going untested while the suite reports green.
     */
    public function testEveryMatchedPathPatternHasAProbe(): void
    {
        $caddyfile = (string) file_get_contents(\dirname(__DIR__, 3) . '/infra/api/Caddyfile');

        $patterns = [];

        foreach (['apiDocs', 'apiData'] as $matcher) {
            self::assertSame(
                1,
                preg_match('/@' . $matcher . ' \{(.*?)\n\t\}/s', $caddyfile, $block),
                'could not read the @' . $matcher . ' matcher out of the Caddyfile',
            );

            // `path` lines only, and NOT `not path` — the negated line restates the docs paths in order to exclude
            // them, so counting it would demand probes for patterns that are deliberately handled elsewhere.
            preg_match_all('/^\t\tpath (.+)$/m', $block[1], $lines);

            foreach ($lines[1] as $line) {
                foreach (preg_split('/\s+/', trim($line)) ?: [] as $pattern) {
                    $patterns[$pattern] = true;
                }
            }
        }

        self::assertNotEmpty($patterns, 'the derivation found no patterns, so this check was vacuous');

        self::assertSame(
            [],
            array_keys(array_diff_key($patterns, self::PROBES)),
            'the Caddyfile matches path patterns this suite has no concrete probe for, so they are untested',
        );
    }

    // ------------------------------------------------------------------ providers

    /** @return iterable<string, array{string}> */
    public static function documentationPaths(): iterable
    {
        foreach (['/api', '/api/docs', '/api/docs.*', '/api/contexts/*'] as $pattern) {
            yield $pattern => [self::PROBES[$pattern]];
        }
    }

    /** @return iterable<string, array{string}> */
    public static function dataPaths(): iterable
    {
        foreach (['/api/*', '/health', '/health/*'] as $pattern) {
            yield $pattern => [self::PROBES[$pattern]];
        }
    }

    /** @return iterable<string, array{string}> */
    public static function apiPaths(): iterable
    {
        foreach (self::PROBES as $pattern => $path) {
            yield $pattern => [$path];
        }
    }

    /** @return iterable<string, array{string}> */
    public static function everyProbe(): iterable
    {
        yield from self::apiPaths();

        yield 'a static asset' => ['/bundles/apiplatform/swagger-ui/swagger-ui.css'];
        yield 'the catch-all' => ['/nothing-is-here'];
    }

    // ------------------------------------------------------------------ transport

    /**
     * One request. Returns the status, the LOWERCASED header names, and the body.
     *
     * **FAILS rather than skipping when there is no server**, with a message that says how to start one. The
     * alternative was `markTestSkipped`, and `CLAUDE.md` § Gotchas records what that costs: the integration suite
     * skipped 62 tenancy tests and reported `OK` with exit 0 because two PostgreSQL clusters shared a port, and the
     * proof standing between this product and a reportable breach did not execute.
     *
     * `ignore_errors` so a 404 is a response to assert on rather than a warning and a `false`, which is the whole
     * point for the catch-all cases.
     *
     * @return array{status: int, headers: array<string, string>, lines: list<string>, body: string}
     */
    private static function get(string $path): array
    {
        $base = getenv('TWES_E2E_BASE_URL');

        if (!\is_string($base) || '' === $base) {
            self::fail(
                'TWES_E2E_BASE_URL is not set, so this suite has nothing to talk to. It asserts what CADDY sends — '
                . "the CSP, the /bundles file server, the catch-all — none of which the kernel can produce.\n"
                . "    make up\n"
                . '    cd api && TWES_E2E_BASE_URL=http://localhost:8080 composer gate:e2e',
            );
        }

        $context = stream_context_create(['http' => [
            'method' => 'GET',
            'ignore_errors' => true,
            'timeout' => 10,
            // A browser-ish Accept, because `/api` negotiates on it and the documentation page is the HTML branch.
            // The CSP does NOT depend on it — Caddy matches on path — and asserting that is the point of including
            // `/api` in the documentation set even though a client gets JSON-LD from the same URL.
            'header' => "Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8\r\n",
            'follow_location' => 0,
        ]]);

        // `fopen` + `stream_get_meta_data`, NOT `file_get_contents` + `$http_response_header`.
        //
        // **The magic variable version was DEAD CODE and PHPStan said so.** `$http_response_header` is created in the
        // calling scope only when a request produced headers at all, so the unreachable-server guard read
        // `false === $body && !isset($http_response_header)` — which PHPStan reports as `always false`, because its
        // stub declares the variable as always existing. Whichever of the two is right about the stub, the guard could
        // not do its job: on a refused connection PHP would reach the `??` below and raise an undefined-variable
        // warning instead of failing with the message that tells you to start the stack.
        //
        // A stream has no magic: `fopen` returns `false` when the connection fails, and `wrapper_data` carries the
        // status and header lines when it does not — including for a 404, which `ignore_errors` turns into a response
        // to assert on rather than a failure.
        $handle = @fopen(rtrim($base, '/') . $path, 'rb', false, $context);

        if (false === $handle) {
            self::fail(\sprintf(
                'Could not reach %s%s. Is the stack up? `make up`, then check `docker compose ps`.',
                rtrim($base, '/'),
                $path,
            ));
        }

        $meta = stream_get_meta_data($handle);
        $body = (string) stream_get_contents($handle);
        fclose($handle);

        $rawHeaders = array_values(array_filter(
            \is_array($meta['wrapper_data'] ?? null) ? $meta['wrapper_data'] : [],
            'is_string',
        ));
        $status = 0;
        $headers = [];
        $lines = [];

        foreach ($rawHeaders as $line) {
            if (1 === preg_match('#^HTTP/\S+\s+(\d{3})#', $line, $matched)) {
                // REASSIGNED, not kept from the first line: a redirect chain puts several status lines in here and the
                // LAST one is the response actually being described by the headers below it.
                $status = (int) $matched[1];
                $headers = [];
                $lines = [];

                continue;
            }

            $parts = explode(':', $line, 2);

            if (2 === \count($parts)) {
                $headers[strtolower(trim($parts[0]))] = trim($parts[1]);
                // KEPT UNCOLLAPSED as well, because the map above silently discards a repeated field — and a
                // repeated field was the first real defect this suite found. See
                // `testTheEdgeOwnedHeadersAreSentExactlyOnce()`.
                $lines[] = strtolower(trim($parts[0]));
            }
        }

        self::assertNotSame(0, $status, 'no HTTP status line came back for ' . $path);

        return ['status' => $status, 'headers' => $headers, 'lines' => $lines, 'body' => $body];
    }
}
