<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Shared\Infrastructure\Http\LongRequest;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Routing\RouterInterface;

/**
 * A request given more than PHP's 30 seconds (LongRequest) is cut by the web server in front unless it waits as long:
 * every route whose action calls lift() is found through the router and must fall in nginx's one long location, which
 * waits app.long_request.max_seconds. Listing the routes makes giving a request that time a decision taken here.
 */
final class LongRequestRoutesTest extends KernelTestCase
{
    private const array LONG_ROUTES = [
        'api_import',
        'api_import_run_undo',
        'api_invoice_selection_pdf',
        'api_record_removal_restore',
    ];

    /** Values a path's variables are tried with: each one its requirement accepts, so every format is tried. */
    private const array SAMPLES = ['0192c3a4-0000-7000-8000-000000000001', 'customers', 'pdf', 'zip', 'csv', 'xlsx'];

    public function testEveryRouteGivenALongRequestsTimeIsWaitedForAsLongByNginx(): void
    {
        $allowed = static::getContainer()->getParameter('app.long_request.max_seconds');
        $nginx = (string) file_get_contents(\dirname(__DIR__, 3).'/infra/web/nginx.conf');
        self::assertSame(1, preg_match('#location ~ (\^/api/companies/\S+\$) \{\s+proxy_pass http://api;\s+proxy_read_timeout '.$allowed.'s;#', $nginx, $long), 'nginx has a location waiting as long as a long request is given');

        $router = static::getContainer()->get('router');
        self::assertInstanceOf(RouterInterface::class, $router);
        $found = [];
        $lifting = [];
        foreach ($router->getRouteCollection()->all() as $name => $route) {
            $controller = $route->getDefault('_controller');
            if (!\is_string($controller)) {
                continue;
            }
            // An invokable controller is named by its class alone.
            [$class, $method] = str_contains($controller, '::') ? explode('::', $controller, 2) : [$controller, '__invoke'];
            if (!class_exists($class) || !method_exists($class, $method)) {
                continue;
            }
            $action = new \ReflectionMethod($class, $method);
            $lines = file((string) $action->getFileName());
            self::assertIsArray($lines);
            $body = implode('', \array_slice($lines, (int) $action->getStartLine() - 1, (int) $action->getEndLine() - (int) $action->getStartLine() + 1));
            if (!str_contains($body, '->lift()')) {
                continue;
            }
            $found[] = $name;
            $lifting[$class] = true;
            foreach ($this->samplePaths($route->getPath(), $route->getRequirements()) as $path) {
                self::assertSame(1, preg_match('#'.$long[1].'#', $path), $name.' ('.$path.') waits as long behind nginx');
            }
        }

        sort($found);
        self::assertSame(self::LONG_ROUTES, $found, 'the routes given a long request\'s time');
        // An action lifting through a helper of its own is not seen above: every class holding a LongRequest lifts in one.
        $holders = [];
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(\dirname(__DIR__, 2).'/src', \FilesystemIterator::SKIP_DOTS)) as $file) {
            if ($file instanceof \SplFileInfo && str_ends_with($file->getFilename(), '.php') && str_contains((string) file_get_contents($file->getPathname()), 'LongRequest $')) {
                $holders[] = $file->getPathname();
            }
        }
        self::assertCount(\count($lifting), $holders, 'every class holding a '.LongRequest::class.' lifts in a routed action');
    }

    /**
     * @param array<mixed> $requirements
     *
     * @return list<string>
     */
    private function samplePaths(string $path, array $requirements): array
    {
        $paths = [$path];
        preg_match_all('#\{(\w+)\}#', $path, $variables);
        foreach ($variables[1] as $variable) {
            $requirement = $requirements[$variable] ?? null;
            $requirement = \is_string($requirement) ? $requirement : '[^/]+';
            $values = array_values(array_filter(self::SAMPLES, static fn (string $value): bool => 1 === preg_match('#^(?:'.$requirement.')$#', $value)));
            self::assertNotSame([], $values, 'a sample for {'.$variable.'} in '.$path);
            $next = [];
            foreach ($paths as $partial) {
                foreach ($values as $value) {
                    $next[] = str_replace('{'.$variable.'}', $value, $partial);
                }
            }
            $paths = $next;
        }

        return $paths;
    }
}
