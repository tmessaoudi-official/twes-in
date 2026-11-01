<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Architecture;

use App\Audit\Application\AuditVocabulary;
use App\Fiscal\Infrastructure\Preset\YamlFiscalPresets;
use App\Identity\Infrastructure\Session\PostgresSessionHandler;
use App\Kernel;
use App\Module\Invoices\Application\FacturX\CiiInvoiceXml;
use App\Shared\Infrastructure\Intl\CatalogueLanguages;
use App\Shared\Infrastructure\Logging\ChannelStreams;
use App\Tests\Support\FakeFacturXPdf;
use App\Tests\Support\FakePdfRenderer;
use App\Tests\Support\RecordedLogs;
use App\Tests\Support\Stateful\ForgetsTheLastCompany;
use App\Tests\Support\Stateful\KeepsTheLastCompany;
use App\Texts\Infrastructure\Catalogue\ShippedTextFiles;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\Argument\ArgumentInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Contracts\Service\Attribute\Required;
use Symfony\Contracts\Service\ResetInterface;

/**
 * FrankenPHP's worker mode keeps one kernel, and so one instance of each service, for many requests. A service that
 * writes one of its properties after it was built may carry what one request set into the next person's: it forgets it
 * through ResetInterface, which the kernel calls between requests, or it is listed here with why what it keeps belongs
 * to no request. The services are the ones each environment a worker serves really builds, inlined ones included, read
 * from the compiled container; the writes are read from each method's code. A write through another object's method
 * (a cache pool, a collection) is not seen: those objects are the framework's, which resets its own.
 */
final class StatefulServicesTest extends TestCase
{
    /** What each listed service keeps, and why it belongs to no one request. */
    private const array LISTED = [
        AuditVocabulary::class => 'a memo of the audit actions every class declares, the same for every request',
        CatalogueLanguages::class => 'a memo of config/languages.json, the same for every request',
        YamlFiscalPresets::class => 'a memo of the fiscal presets under config/fiscal, the same for every request',
        ShippedTextFiles::class => 'a memo of the shipped text files, the same for every request; what a company worded is OverridingTranslator\'s, which resets',
        PostgresSessionHandler::class => 'its connection outlives requests on purpose and is checked at each open(); which handler a session runs on is chosen at open() and dropped at close(), both called once per session cycle',
        ChannelStreams::class => 'a Monolog handler keeping one open file per channel and day, for every request alike; the logger it serves resets it through Monolog\'s own reset()',
        CiiInvoiceXml::class => 'the document of one write(), made anew at its start before anything reads it',
        Kernel::class => 'the kernel itself: its bundles and container are what every request runs on',
        FakePdfRenderer::class => 'a test\'s recorder, read by the test after its requests; no worker runs it',
        FakeFacturXPdf::class => 'a test\'s recorder, read by the test after its requests; no worker runs it',
        RecordedLogs::class => 'a test\'s recorder, read by the test after its requests; no worker runs it',
    ];

    /** The environments a worker serves: test, as the functional tests' kernel does once it no longer reboots. */
    private const array ENVIRONMENTS = ['test', 'dev', 'prod'];

    /** Fewer services than this means the container was not read, and every service would pass unseen. */
    private const int SERVICES_FLOOR = 1000;

    /** Fewer stateful services than this, resetting or listed, means the writes were not read. */
    private const int STATEFUL_FLOOR = 15;

    /** The functions writing into what they are handed, a property among them. */
    private const array BY_REFERENCE = ['array_pop', 'array_shift', 'array_push', 'array_unshift', 'array_splice', 'sort', 'rsort', 'usort', 'uasort', 'uksort', 'ksort', 'krsort', 'asort', 'arsort', 'shuffle', 'unset', 'settype', 'preg_match', 'preg_match_all'];

    /** @var array<class-string, true>|null */
    private static ?array $services = null;

    public function testEveryServiceKeepingWhatItIsGivenResetsOrIsListed(): void
    {
        $services = self::services();
        self::assertGreaterThanOrEqual(self::SERVICES_FLOOR, \count($services), 'the compiled containers were not read');

        $stateful = 0;
        $unsaid = [];
        $keeping = [];
        foreach (array_keys($services) as $class) {
            $writes = self::writesAfterConstruction($class);
            if ([] === $writes) {
                continue;
            }
            ++$stateful;
            if (is_a($class, ResetInterface::class, true)) {
                continue;
            }
            $keeping[$class] = true;
            if (!\array_key_exists($class, self::LISTED)) {
                $unsaid[] = $class.' writes '.implode(', ', array_map(static fn (string $what, string $where): string => $what.' in '.$where.'()', array_keys($writes), $writes));
            }
        }

        self::assertSame([], $unsaid, 'each of these keeps what a request set: implement ResetInterface, or list it here with why it belongs to no request');
        self::assertSame([], array_values(array_diff(array_keys(self::LISTED), array_keys($keeping))), 'listed, but no longer a service keeping anything, or now resetting: take it off the list');
        self::assertGreaterThanOrEqual(self::STATEFUL_FLOOR, $stateful, 'the writes were not read');
    }

    public function testTheRuleFindsAServiceKeepingWhatARequestGaveIt(): void
    {
        self::assertSame(
            ['company' => 'actFor', 'seen' => 'actFor', 'static $calls' => 'actFor'],
            self::writesAfterConstruction(KeepsTheLastCompany::class),
        );
        self::assertSame(['company' => 'actFor'], self::writesAfterConstruction(ForgetsTheLastCompany::class), 'reset() itself is read like any method');
    }

    /** @return array<class-string, true> every App class the test, dev and production containers build, inlined ones included */
    private static function services(): array
    {
        if (null !== self::$services) {
            return self::$services;
        }
        $found = [];
        foreach (self::ENVIRONMENTS as $environment) {
            $directory = sys_get_temp_dir().'/stateful-services-'.bin2hex(random_bytes(6));
            try {
                $container = self::compiled($environment, $directory);
                foreach ($container->getDefinitions() as $definition) {
                    self::collect($definition, $found);
                }
                unset($container);
            } finally {
                new Filesystem()->remove($directory);
            }
        }

        return self::$services = $found;
    }

    /** The container an environment builds, compiled as its kernel compiles it, in a directory of the test's own. */
    private static function compiled(string $environment, string $directory): ContainerBuilder
    {
        $kernel = new class($environment, 'prod' !== $environment, $directory) extends Kernel {
            public function __construct(string $environment, bool $debug, private readonly string $directory)
            {
                parent::__construct($environment, $debug);
            }

            public function getCacheDir(): string
            {
                return $this->directory.'/cache';
            }

            public function getBuildDir(): string
            {
                return $this->directory.'/build';
            }

            public function getLogDir(): string
            {
                return $this->directory.'/log';
            }

            public function compiledContainer(): ContainerBuilder
            {
                $this->initializeBundles();
                $container = $this->buildContainer();
                $container->compile();

                return $container;
            }
        };

        return $kernel->compiledContainer();
    }

    /** @param array<class-string, true> $found */
    private static function collect(mixed $value, array &$found): void
    {
        if ($value instanceof Definition) {
            $class = $value->getClass();
            if (null !== $class && str_starts_with($class, 'App\\') && class_exists($class)) {
                $found[$class] = true;
            }
            foreach ([$value->getArguments(), $value->getMethodCalls(), $value->getProperties(), $value->getFactory(), $value->getConfigurator()] as $inner) {
                self::collect($inner, $found);
            }

            return;
        }
        if ($value instanceof ArgumentInterface) {
            self::collect($value->getValues(), $found);

            return;
        }
        if (\is_array($value)) {
            foreach ($value as $inner) {
                self::collect($inner, $found);
            }
        }
    }

    /**
     * What a class writes once built, each with the method writing it: its properties, static ones and a method's own
     * static variables, in every method the application declares for it but the constructor and the container's setters.
     *
     * @param class-string $name
     *
     * @return array<string, string>
     */
    private static function writesAfterConstruction(string $name): array
    {
        $class = new \ReflectionClass($name);
        $writes = [];
        if ($class->isInterface() || $class->isAbstract()) {
            return $writes;
        }
        foreach ($class->getMethods() as $method) {
            if (!str_starts_with($method->getDeclaringClass()->getName(), 'App\\')
                || \in_array($method->getName(), ['__construct', '__clone'], true)
                || [] !== $method->getAttributes(Required::class)) {
                continue;
            }
            foreach (self::writesIn($method) as $what) {
                $writes[$what] ??= $method->getName();
            }
        }

        return $writes;
    }

    /** @return list<string> */
    private static function writesIn(\ReflectionMethod $method): array
    {
        $file = $method->getFileName();
        $start = $method->getStartLine();
        $end = $method->getEndLine();
        if (false === $file || false === $start || false === $end) {
            return [];
        }
        $lines = file($file);
        self::assertIsArray($lines);
        $tokens = array_values(array_filter(
            \PhpToken::tokenize('<?php '.implode('', \array_slice($lines, $start - 1, $end - $start + 1))),
            static fn (\PhpToken $token): bool => !$token->isIgnorable(),
        ));

        $written = [];
        foreach ($tokens as $i => $token) {
            $next = $tokens[$i + 1] ?? null;
            $name = $tokens[$i + 2] ?? null;
            if ($token->is(\T_STATIC) && null !== $next && $next->is(\T_VARIABLE) && !($tokens[$i - 1] ?? null)?->is(\T_DOUBLE_COLON)) {
                $written[] = 'local static '.$next->text;

                continue;
            }
            if ($token->is(\T_VARIABLE) && '$this' === $token->text && null !== $next && $next->is([\T_OBJECT_OPERATOR, \T_NULLSAFE_OBJECT_OPERATOR]) && null !== $name && $name->is(\T_STRING)) {
                $what = $name->text;
            } elseif ($token->is([\T_STATIC, \T_STRING]) && \in_array(strtolower($token->text), ['self', 'static'], true) && null !== $next && $next->is(\T_DOUBLE_COLON) && null !== $name && $name->is(\T_VARIABLE)) {
                $what = 'static '.$name->text;
            } else {
                continue;
            }
            if (self::isWritten($tokens, $i, $i + 3)) {
                $written[] = $what;
            }
        }

        return $written;
    }

    /**
     * Whether the property read from $first is written: assigned, by an operator or through an index, incremented, or
     * handed to a function writing into what it is given.
     *
     * @param list<\PhpToken> $tokens
     */
    private static function isWritten(array $tokens, int $first, int $after): bool
    {
        while ('[' === ($tokens[$after] ?? null)?->text) {
            $depth = 0;
            do {
                $text = $tokens[$after]->text;
                $depth += '[' === $text ? 1 : (']' === $text ? -1 : 0);
                ++$after;
            } while ($depth > 0 && isset($tokens[$after]));
        }
        $next = $tokens[$after] ?? null;
        $before = $tokens[$first - 1] ?? null;
        $call = $tokens[$first - 2] ?? null;

        return (null !== $next && ('=' === $next->text || $next->is([\T_COALESCE_EQUAL, \T_PLUS_EQUAL, \T_MINUS_EQUAL, \T_MUL_EQUAL, \T_DIV_EQUAL, \T_CONCAT_EQUAL, \T_MOD_EQUAL, \T_POW_EQUAL, \T_AND_EQUAL, \T_OR_EQUAL, \T_XOR_EQUAL, \T_SL_EQUAL, \T_SR_EQUAL, \T_INC, \T_DEC])))
            || (null !== $before && $before->is([\T_INC, \T_DEC]))
            || (null !== $before && '(' === $before->text && null !== $call && $call->is([\T_STRING, \T_UNSET]) && \in_array(strtolower($call->text), self::BY_REFERENCE, true));
    }
}
