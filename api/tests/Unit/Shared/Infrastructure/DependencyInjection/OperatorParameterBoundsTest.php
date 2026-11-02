<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Infrastructure\DependencyInjection;

use App\Identity\Infrastructure\Mfa\RecoveryCodeBounds;
use App\Identity\Infrastructure\Password\PasswordResetBounds;
use App\Identity\Infrastructure\Session\SessionBounds;
use App\Kernel;
use App\Shared\Infrastructure\ErrorReporting\ErrorReportingMasksBounds;
use App\Tenancy\Infrastructure\Http\SupportVisitBounds;
use App\Tenancy\Infrastructure\Invitation\InvitationBounds;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Exception\InvalidArgumentException;
use Symfony\Component\Yaml\Yaml;

/**
 * What only the operator may set is a parameter of services.yaml, each held between a floor and a ceiling when the
 * container is built, so a deployment outside them never starts: no value an operator types can weaken the protection
 * the shipped value gives (a session's use noted an hour late, a support visit's pages unrecorded, a recovery set of
 * three codes, a reset link open for a week, an invitation open for a year, a mask that is no regular expression).
 */
final class OperatorParameterBoundsTest extends TestCase
{
    /** What a shop sees before an operator edits anything: today's values, every one of them within its bounds. */
    public function testTheShippedValuesAreTodaysAndWithinTheBounds(): void
    {
        $parameters = $this->shipped();

        self::assertSame('PT5M', $parameters['app.session.touch_every']);
        self::assertSame('PT15M', $parameters['app.support.read_recorded_every']);
        self::assertSame(10, $parameters['app.recovery_codes.per_set']);
        self::assertSame('PT1H', $parameters['app.password_reset.valid_for']);
        self::assertSame('P7D', $parameters['app.invitation.valid_for']);
        self::assertSame([], $parameters['app.error_reporting.extra_masks']);

        $container = new ContainerBuilder();
        foreach ($parameters as $name => $value) {
            $container->setParameter($name, $value);
        }
        foreach (self::passes() as $pass) {
            $pass->process($container);
        }
        $this->addToAssertionCount(1);
    }

    /** A pass the kernel does not add checks nothing: every one is added where the container is built. */
    public function testTheKernelAddsEveryPass(): void
    {
        $container = new ContainerBuilder();
        $build = new \ReflectionMethod(Kernel::class, 'build');
        $build->invoke(new Kernel('test', false), $container);

        $added = array_map(static fn (CompilerPassInterface $pass): string => $pass::class, $container->getCompilerPassConfig()->getPasses());
        foreach (self::passes() as $pass) {
            self::assertContains($pass::class, $added);
        }
    }

    /** @return iterable<string, array{string, array<mixed>|int|string}> */
    public static function withinBounds(): iterable
    {
        yield 'a session noted every minute' => ['app.session.touch_every', 'PT1M'];
        yield 'a session noted every quarter hour' => ['app.session.touch_every', 'PT15M'];
        yield 'every support read recorded' => ['app.support.read_recorded_every', 'PT0M'];
        yield 'a support read recorded once an hour' => ['app.support.read_recorded_every', 'PT1H'];
        yield 'eight recovery codes' => ['app.recovery_codes.per_set', 8];
        yield 'sixteen recovery codes' => ['app.recovery_codes.per_set', 16];
        yield 'a reset link for a quarter hour' => ['app.password_reset.valid_for', 'PT15M'];
        yield 'a reset link for a day' => ['app.password_reset.valid_for', 'PT24H'];
        yield 'an invitation for a day' => ['app.invitation.valid_for', 'P1D'];
        yield 'an invitation for thirty days' => ['app.invitation.valid_for', 'P30D'];
        yield 'a mask of the operator' => ['app.error_reporting.extra_masks', [['pattern' => '/\bTN\d{2}\b/', 'replacement' => '[branch]']]];
    }

    /** @param array<mixed>|int|string $value */
    #[DataProvider('withinBounds')]
    public function testAValueWithinItsBoundsIsKept(string $parameter, array|int|string $value): void
    {
        $container = $this->withShipped($parameter, $value);
        foreach (self::passes() as $pass) {
            $pass->process($container);
        }
        self::assertSame($value, $container->getParameter($parameter));
    }

    /** @return iterable<string, array{string, array<mixed>|int|string}> */
    public static function outOfBounds(): iterable
    {
        yield 'a session noted every 59 seconds' => ['app.session.touch_every', 'PT59S'];
        yield 'a session noted every 16 minutes' => ['app.session.touch_every', 'PT16M'];
        yield 'a session noted by no duration' => ['app.session.touch_every', 'five minutes'];
        yield 'support reads recorded every 61 minutes' => ['app.support.read_recorded_every', 'PT61M'];
        yield 'support reads recorded once a day' => ['app.support.read_recorded_every', 'P1D'];
        yield 'seven recovery codes' => ['app.recovery_codes.per_set', 7];
        yield 'seventeen recovery codes' => ['app.recovery_codes.per_set', 17];
        yield 'recovery codes as text' => ['app.recovery_codes.per_set', '10'];
        yield 'a reset link for 14 minutes' => ['app.password_reset.valid_for', 'PT14M'];
        yield 'a reset link for 25 hours' => ['app.password_reset.valid_for', 'PT25H'];
        yield 'an invitation for 23 hours' => ['app.invitation.valid_for', 'PT23H'];
        yield 'an invitation for 31 days' => ['app.invitation.valid_for', 'P31D'];
        yield 'a mask that is no regular expression' => ['app.error_reporting.extra_masks', [['pattern' => '/(unclosed/', 'replacement' => 'x']]];
        yield 'a mask with no replacement' => ['app.error_reporting.extra_masks', [['pattern' => '/x/']]];
        yield 'a mask with a key of its own' => ['app.error_reporting.extra_masks', [['pattern' => '/x/', 'replacement' => 'y', 'flags' => 'i']]];
        yield 'masks as a map' => ['app.error_reporting.extra_masks', ['/x/' => 'y']];
        yield 'masks as text' => ['app.error_reporting.extra_masks', '/x/'];
    }

    /** @param array<mixed>|int|string $value */
    #[DataProvider('outOfBounds')]
    public function testAValueOutOfItsBoundsStopsTheContainer(string $parameter, array|int|string $value): void
    {
        $container = $this->withShipped($parameter, $value);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/'.preg_quote($parameter, '/').'/');

        foreach (self::passes() as $pass) {
            $pass->process($container);
        }
    }

    /** @return list<CompilerPassInterface> */
    private static function passes(): array
    {
        return [new SessionBounds(), new SupportVisitBounds(), new RecoveryCodeBounds(), new PasswordResetBounds(), new InvitationBounds(), new ErrorReportingMasksBounds()];
    }

    /** @param array<mixed>|bool|float|int|string|null $value */
    private function withShipped(string $parameter, mixed $value): ContainerBuilder
    {
        $container = new ContainerBuilder();
        foreach ($this->shipped() as $name => $shipped) {
            $container->setParameter($name, $shipped);
        }
        $container->setParameter($parameter, $value);

        return $container;
    }

    /** @return array<string, array<mixed>|bool|float|int|string|null> the six parameters as services.yaml ships them */
    private function shipped(): array
    {
        $file = Yaml::parseFile(__DIR__.'/../../../../../config/services.yaml', Yaml::PARSE_CONSTANT | Yaml::PARSE_CUSTOM_TAGS);
        self::assertIsArray($file);
        self::assertIsArray($file['parameters'] ?? null);
        $names = ['app.session.touch_every', 'app.support.read_recorded_every', 'app.recovery_codes.per_set', 'app.password_reset.valid_for', 'app.invitation.valid_for', 'app.error_reporting.extra_masks'];
        $shipped = [];
        foreach ($names as $name) {
            self::assertArrayHasKey($name, $file['parameters']);
            $value = $file['parameters'][$name];
            self::assertTrue(\is_array($value) || \is_scalar($value) || null === $value, $name.' is a value a container holds');
            $shipped[$name] = $value;
        }

        return $shipped;
    }
}
