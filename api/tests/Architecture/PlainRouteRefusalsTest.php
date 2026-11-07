<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Architecture;

use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Routing\RouterInterface;

/**
 * A refusal a plain controller answers with its reason (`DomainRule`, `ReceivedFile`) is written as violations only by
 * API Platform's error handling, which a route outside a resource takes through the default `_api_respond`. Without
 * it the screen meets a body it cannot read and says the code's words: restoring a receipt onto a recorded expense
 * answered 409 with no reason. Every route whose method, or a helper of its class it calls, throws one carries it.
 */
final class PlainRouteRefusalsTest extends KernelTestCase
{
    private const string REFUSAL = '/\b(?:DomainRule|ReceivedFile)::(?!WHOLE_REQUEST\b)\w+/';

    public function testEveryPlainRouteSayingARefusalsReasonTakesApiPlatformsErrorHandling(): void
    {
        self::bootKernel();
        $saying = [];
        $wrong = [];
        foreach (static::getContainer()->get(RouterInterface::class)->getRouteCollection()->all() as $name => $route) {
            $controller = $route->getDefault('_controller');
            if (!str_starts_with($route->getPath(), '/api/') || null !== $route->getDefault('_api_resource_class') || !\is_string($controller)) {
                continue;
            }
            // An invokable controller is named by its class alone.
            [$class, $method] = str_contains($controller, '::') ? explode('::', $controller, 2) : [$controller, '__invoke'];
            if (!class_exists($class) || !method_exists($class, $method)) {
                continue;
            }
            if (!self::saysARefusal(new \ReflectionClass($class), $method)) {
                continue;
            }
            $saying[] = $name;
            if (true !== $route->getDefault('_api_respond')) {
                $wrong[] = "$name ($controller) answers a refusal with its reason: give its route defaults: ['_api_respond' => true]";
            }
        }

        self::assertGreaterThanOrEqual(10, \count($saying), 'some ten plain routes say a refusal with its reason: a count reading fewer has stopped finding them; found '.implode(', ', $saying));
        self::assertSame([], $wrong, implode("\n", $wrong));
    }

    /** @param \ReflectionClass<object> $class */
    private static function saysARefusal(\ReflectionClass $class, string $method): bool
    {
        $lines = file((string) $class->getFileName());
        if (false === $lines) {
            return false;
        }
        $bodyOf = static function (string $name) use ($class, $lines): string {
            $reflected = $class->getMethod($name);

            return implode('', \array_slice($lines, (int) $reflected->getStartLine() - 1, (int) $reflected->getEndLine() - (int) $reflected->getStartLine() + 1));
        };
        $body = $bodyOf($method);
        if (1 === preg_match(self::REFUSAL, $body)) {
            return true;
        }
        preg_match_all('/(?:\$this->|self::|static::)(\w+)\(/', $body, $called);
        foreach (array_unique($called[1]) as $helper) {
            if ($helper !== $method && $class->hasMethod($helper) && $class->getMethod($helper)->getDeclaringClass()->getName() === $class->getName() && 1 === preg_match(self::REFUSAL, $bodyOf($helper))) {
                return true;
            }
        }

        return false;
    }
}
