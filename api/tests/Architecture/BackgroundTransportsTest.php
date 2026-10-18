<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Architecture;

use App\Demo\Infrastructure\Messenger\SandboxToBuild;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * A consumer handles one message at a time, and a demo sandbox takes it a quarter of a minute or more to build: a mail
 * queued behind a build waits for all of it, so an invitation or a reset arrives late, once for every build the pool
 * queues. Builds therefore travel on a transport of their own, consumed by a container that consumes nothing else, and
 * every message and the schedule are consumed by exactly one container, live mode included.
 */
final class BackgroundTransportsTest extends TestCase
{
    private const string SCHEDULE = 'scheduler_default';

    public function testEveryTransportAMessageTakesIsConsumedByExactlyOneContainer(): void
    {
        $consumers = self::consumers('compose.yaml');
        $routed = [...array_values(self::routing()), self::SCHEDULE];

        foreach (array_unique($routed) as $transport) {
            $by = array_keys(array_filter($consumers, static fn (array $transports): bool => \in_array($transport, $transports, true)));
            self::assertCount(1, $by, \sprintf('%s is consumed by exactly one container, here by: %s', $transport, implode(', ', $by) ?: 'none'));
        }
        foreach ($consumers as $service => $transports) {
            self::assertSame([], array_values(array_diff($transports, $routed)), $service.' consumes only transports a message takes');
        }
    }

    public function testASandboxBuildHoldsBackNoOtherMessage(): void
    {
        $routing = self::routing();
        $builds = $routing[SandboxToBuild::class] ?? self::fail('the sandbox build is routed');
        $sharing = array_keys(array_filter($routing, static fn (string $transport, string $message): bool => $transport === $builds && SandboxToBuild::class !== $message, \ARRAY_FILTER_USE_BOTH));

        self::assertSame([], $sharing, 'no other message travels with the builds');
        foreach (self::consumers('compose.yaml') as $service => $transports) {
            if (\in_array($builds, $transports, true)) {
                self::assertSame([$builds], $transports, $service.' consumes the builds and nothing else');
            }
        }
    }

    public function testLiveModeConsumesWhatTheImagesConsume(): void
    {
        // Live mode restarts each consumer every minute so it runs the source as edited: it overrides every one of them,
        // and each still consumes what its image would.
        self::assertSame(self::consumers('compose.yaml'), self::consumers('compose.live.yaml'));
    }

    /**
     * Each message class and the transport it is routed to.
     *
     * @return array<string, string>
     */
    private static function routing(): array
    {
        $routing = Yaml::parseFile(self::root().'/api/config/packages/messenger.yaml');
        foreach (['framework', 'messenger', 'routing'] as $key) {
            $routing = \is_array($routing) ? $routing[$key] ?? null : null;
        }
        self::assertIsArray($routing, 'messenger.yaml routes its messages');
        $routes = [];
        foreach ($routing as $message => $transport) {
            self::assertIsString($transport, $message.' goes to one transport');
            $routes[(string) $message] = $transport;
        }
        self::assertGreaterThanOrEqual(5, \count($routes), 'the routes are read, so an empty set cannot pass');

        return $routes;
    }

    /**
     * Each service of a compose file running `messenger:consume`, and the transports it names, in its order.
     *
     * @return array<string, list<string>>
     */
    private static function consumers(string $file): array
    {
        $compose = Yaml::parseFile(self::root().'/'.$file, Yaml::PARSE_CUSTOM_TAGS);
        $services = \is_array($compose) && \is_array($compose['services'] ?? null) ? $compose['services'] : self::fail($file.' has services');
        $consumers = [];
        foreach ($services as $service => $definition) {
            $command = \is_array($definition) ? ($definition['command'] ?? null) : null;
            $line = \is_array($command) ? implode(' ', array_filter($command, \is_string(...))) : (\is_string($command) ? $command : '');
            if (1 === preg_match('/messenger:consume((?:\s+[a-z_]+)+)/', $line, $match)) {
                $consumers[(string) $service] = array_values(array_filter(preg_split('/\s+/', trim($match[1])) ?: []));
            }
        }
        ksort($consumers);

        return $consumers;
    }

    private static function root(): string
    {
        return \dirname(__DIR__, 3);
    }
}
