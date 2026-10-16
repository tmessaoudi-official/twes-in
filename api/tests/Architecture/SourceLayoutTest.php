<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Architecture;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Operation;
use ApiPlatform\OpenApi\Factory\OpenApiFactoryInterface;
use ApiPlatform\State\ProcessorInterface;
use ApiPlatform\State\ProviderInterface;
use App\Audit\Domain\Model\AuditLog;
use App\Audit\Infrastructure\ApiPlatform\Resource\ActivityResource;
use App\Audit\Infrastructure\ApiPlatform\State\Provider\ActivityCollectionProvider;
use App\CustomFields\Infrastructure\ApiPlatform\State\Processor\CreateCustomFieldProcessor;
use App\Files\Domain\Repository\AttachmentRepository;
use App\Fiscal\Domain\Calculation\Rate;
use App\Identity\Domain\ValueObject\Email;
use App\Identity\Infrastructure\ApiPlatform\OpenApi\OpenApiExtras;
use App\Identity\Infrastructure\Security\UserProvider;
use App\Module\Customers\Domain\Exception\InvalidContact;
use App\Module\Customers\Domain\ValueObject\CustomerKind;
use App\Module\Invoices\Domain\InvoiceIssued;
use App\Shared\Domain\DomainEvent;
use Doctrine\ORM\Mapping\Embeddable;
use Doctrine\ORM\Mapping\Entity;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Messenger\Attribute\AsMessage;
use Symfony\Component\Scheduler\Attribute\AsCronTask;
use Symfony\Component\Scheduler\Attribute\AsPeriodicTask;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Where a class sits says what it is (docs/SPEC.md § 7, 2026-10-16 09:47): the layout of API Platform's DDD reference,
 * mtarld/apip-ddd. In a context's Infrastructure/ApiPlatform/, a resource sits in Resource/, a state processor in
 * State/Processor/, a provider in State/Provider/, an OpenAPI decorator in OpenApi/, and the rest in Payload/, Output/,
 * Security/ or Metadata/; in its Domain/, an entity in Model/, a repository port in Repository/, an exception in
 * Exception/, an enum in ValueObject/, an event in Event/, and the rest in ValueObject/ or Service/. A folder past the
 * cap is split by aggregate. Each role is read by reflection, never from a name, so `UserProviderInterface` is no
 * API Platform provider. A class stored or routed by its fully-qualified name never moves.
 */
final class SourceLayoutTest extends TestCase
{
    private const string SRC = __DIR__.'/../../src';

    /** The files one folder holds directly, at most: past it, the folder is split by aggregate or capability. */
    private const int CAP = 25;

    private const array LABELS = [
        'resource' => 'a resource',
        'processor' => 'a state processor',
        'provider' => 'a state provider',
        'openapi' => 'an OpenAPI decorator',
        'entity' => 'an entity',
        'repository' => 'a repository port',
        'exception' => 'an exception',
        'enum' => 'an enum',
        'event' => 'a domain event',
    ];

    private const array DOMAIN_FOLDERS = ['Model', 'Repository', 'ValueObject', 'Event', 'Exception', 'Service'];

    private const array API_PLATFORM_FOLDERS = ['Resource', 'State', 'OpenApi', 'Payload', 'Output', 'Security', 'Metadata'];

    /** A Domain folder that is one coherent sub-domain rather than a role, kept whole under the cap. */
    private const array SUB_DOMAINS = ['Fiscal/Domain/Calculation'];

    /**
     * The contexts whose move has not landed yet: each still breaks the layout, which the last case checks, so the list
     * only shrinks, one context per commit.
     */
    private const array NOT_YET_MOVED = [
        'Module/Inventory',
        'Module/Invoices',
        'Module/Products',
        'Shared',
        'Tenancy',
    ];

    /**
     * What is stored or routed by its fully-qualified name: a message the queue keeps, the user the session keeps, a
     * scheduled task the schedule names. Moving one strands queued mail or signs everyone out, so these stay put.
     */
    private const array KEPT = [
        'App\Audit\Infrastructure\Scheduler\PurgeFailedSignInAddressesEveryNight',
        'App\DataHealth\Infrastructure\Scheduler\CheckDataEveryNight',
        'App\Erasure\Infrastructure\Scheduler\EndErasuresEveryQuarterHour',
        'App\Identity\Infrastructure\Password\PasswordResetAsked',
        'App\Identity\Infrastructure\Scheduler\FinalizeAccountDeletionsEveryNight',
        'App\Identity\Infrastructure\Security\SecurityUser',
        'App\Inbox\Infrastructure\Mail\NotificationToMail',
        'App\Module\Invoices\Infrastructure\Scheduler\RemindLateInvoicesEveryHour',
        'App\Module\RecurringExpenses\Infrastructure\Scheduler\RunRecurringExpensesEveryHour',
        'App\Module\Recurring\Infrastructure\Scheduler\RunRecurringInvoicesEveryHour',
        'App\Module\Scanning\Infrastructure\Scheduler\ClearUntakenPhotos',
        'App\Tenancy\Infrastructure\Invitation\InvitationToMail',
        'App\Tenancy\Infrastructure\Scheduler\RemoveClosedCompaniesEveryNight',
        'App\Tenancy\Infrastructure\Signup\SignupAsked',
    ];

    public function testEveryClassSitsInTheFolderOfItsRole(): void
    {
        $violations = [];
        $read = 0;
        foreach ($this->contexts() as $context) {
            foreach ($this->phpFilesUnder(self::SRC."/$context") as $relative) {
                ++$read;
                if (!\in_array($context, self::NOT_YET_MOVED, true)) {
                    $violations = [...$violations, ...$this->misplaced($context, $relative)];
                }
            }
        }
        self::assertGreaterThan(1700, $read, 'the contexts hold some 1,800 classes: a scan reading fewer has stopped finding them');
        self::assertSame([], $violations, "A class sits in the folder of its role:\n".implode("\n", $violations));
    }

    /**
     * Each rule, both ways, on real classes set at chosen places: until a context moves, a rule broken here would
     * otherwise go unseen, the contexts not yet moved breaking the layout by their flat folders alone.
     */
    public function testEachRuleKnowsTheRoleItPlaces(): void
    {
        /** @var list<array{class-string, string, string, ?string}> $cases */
        $cases = [
            [ActivityResource::class, 'Audit', 'Infrastructure/ApiPlatform/Resource/ActivityResource.php', null],
            [ActivityResource::class, 'Audit', 'Infrastructure/ApiPlatform/Output/ActivityResource.php', 'is a resource'],
            [ActivityResource::class, 'Audit', 'Infrastructure/Messenger/ActivityResource.php', 'is a resource'],
            [ActivityResource::class, 'Audit', 'Infrastructure/ApiPlatform/Resources/ActivityResource.php', 'is a resource'],
            [ActivityCollectionProvider::class, 'Audit', 'Infrastructure/ApiPlatform/State/Provider/ActivityCollectionProvider.php', null],
            [ActivityCollectionProvider::class, 'Audit', 'Infrastructure/ApiPlatform/Resource/ActivityCollectionProvider.php', 'not a resource'],
            [ActivityCollectionProvider::class, 'Audit', 'Infrastructure/ApiPlatform/State/Processor/ActivityCollectionProvider.php', 'not a state processor'],
            [CreateCustomFieldProcessor::class, 'CustomFields', 'Infrastructure/ApiPlatform/State/Processor/CreateCustomFieldProcessor.php', null],
            [CreateCustomFieldProcessor::class, 'CustomFields', 'Infrastructure/ApiPlatform/State/Provider/CreateCustomFieldProcessor.php', 'not a state provider'],
            [CreateCustomFieldProcessor::class, 'CustomFields', 'Infrastructure/ApiPlatform/Payload/CreateCustomFieldProcessor.php', 'is a state processor'],
            [UserProvider::class, 'Identity', 'Infrastructure/Security/UserProvider.php', null],
            [OpenApiExtras::class, 'Identity', 'Infrastructure/ApiPlatform/OpenApi/OpenApiExtras.php', null],
            [OpenApiExtras::class, 'Identity', 'Infrastructure/ApiPlatform/Output/OpenApiExtras.php', 'is an OpenAPI decorator'],
            [OpenApiExtras::class, 'Identity', 'Infrastructure/ApiPlatform/OpenApiExtras.php', 'sits flat in ApiPlatform/'],
            [OpenApiExtras::class, 'Identity', 'Infrastructure/ApiPlatform/Things/OpenApiExtras.php', 'ApiPlatform/Things/, which is none'],
            [OpenApiExtras::class, 'Identity', 'Infrastructure/ApiPlatform/State/OpenApiExtras.php', 'outside Processor/ and Provider/'],
            [AuditLog::class, 'Audit', 'Domain/Model/AuditLog.php', null],
            [AuditLog::class, 'Audit', 'Domain/ValueObject/AuditLog.php', 'is an entity'],
            [Email::class, 'Identity', 'Domain/ValueObject/Email.php', null],
            [Email::class, 'Identity', 'Domain/Service/Email.php', null],
            [Email::class, 'Identity', 'Domain/Model/Email.php', 'not an entity'],
            [Email::class, 'Identity', 'Domain/Email.php', 'sits flat in Domain/'],
            [Email::class, 'Identity', 'Domain/Things/Email.php', 'Domain/Things/, which is none'],
            [AttachmentRepository::class, 'Files', 'Domain/Repository/AttachmentRepository.php', null],
            [AttachmentRepository::class, 'Files', 'Domain/Service/AttachmentRepository.php', 'is a repository port'],
            [Email::class, 'Identity', 'Domain/Repository/Email.php', 'not a repository port'],
            [InvalidContact::class, 'Module/Customers', 'Domain/Exception/InvalidContact.php', null],
            [InvalidContact::class, 'Module/Customers', 'Domain/ValueObject/InvalidContact.php', 'is an exception'],
            [Email::class, 'Identity', 'Domain/Exception/Email.php', 'not an exception'],
            [CustomerKind::class, 'Module/Customers', 'Domain/ValueObject/CustomerKind.php', null],
            [CustomerKind::class, 'Module/Customers', 'Domain/Service/CustomerKind.php', 'is an enum'],
            [InvoiceIssued::class, 'Module/Invoices', 'Domain/Event/InvoiceIssued.php', null],
            [InvoiceIssued::class, 'Module/Invoices', 'Domain/ValueObject/InvoiceIssued.php', 'is a domain event'],
            [DomainEvent::class, 'Shared', 'Domain/Event/DomainEvent.php', null],
            [Email::class, 'Identity', 'Domain/Event/Email.php', 'not a domain event'],
            [Rate::class, 'Fiscal', 'Domain/Calculation/Rate.php', null],
        ];
        foreach ($cases as [$name, $context, $relative, $expected]) {
            $problems = implode("\n", $this->misplaced($context, $relative, new \ReflectionClass($name)));
            if (null === $expected) {
                self::assertSame('', $problems, "$name at $context/$relative keeps the layout");
            } else {
                self::assertStringContainsString($expected, $problems, "$name at $context/$relative breaks it");
            }
        }
    }

    public function testTheCapIsTwentyFiveFilesAFolder(): void
    {
        self::assertSame(['Probe/Crowded' => 26], $this->crowded(['Probe/Crowded' => 26, 'Probe/Full' => 25, 'Probe/Light' => 3]));
    }

    public function testNoFolderHoldsMoreThanTheCap(): void
    {
        $crowded = [];
        foreach ($this->crowded($this->folders()) as $folder => $count) {
            if (!$this->notYetMoved($folder)) {
                $crowded[] = "$folder holds $count files";
            }
        }
        self::assertSame([], $crowded, 'A folder past '.self::CAP." files is split by aggregate or capability:\n".implode("\n", $crowded));
    }

    public function testEveryContextNotYetMovedStillBreaksTheLayout(): void
    {
        self::assertSame([], array_values(array_diff(self::NOT_YET_MOVED, $this->contexts())), 'NOT_YET_MOVED names contexts only');
        // Two contexts keep the layout already: what tells a context that breaks it must say so of them.
        self::assertFalse($this->breaks('DataHealth'), 'DataHealth keeps the layout');
        self::assertFalse($this->breaks('Module/AccountingExport'), 'AccountingExport keeps the layout');
        foreach (self::NOT_YET_MOVED as $context) {
            self::assertTrue($this->breaks($context), "$context keeps the layout now: take it off NOT_YET_MOVED, which only shrinks");
        }
    }

    public function testWhatIsStoredOrRoutedByItsClassNameStaysPut(): void
    {
        $found = $this->routedMessages();
        foreach ($this->contexts() as $context) {
            foreach ($this->phpFilesUnder(self::SRC."/$context") as $relative) {
                $class = new \ReflectionClass($this->className($context, $relative));
                $named = [] !== $class->getAttributes(AsMessage::class)
                    || [] !== $class->getAttributes(AsPeriodicTask::class)
                    || [] !== $class->getAttributes(AsCronTask::class)
                    || (!$class->isInterface() && !$class->isAbstract() && $class->implementsInterface(UserInterface::class));
                if ($named) {
                    $found[] = $class->getName();
                }
            }
        }
        $found = array_values(array_unique($found));
        sort($found);
        self::assertSame(self::KEPT, $found, 'a class stored or routed by its name is listed here, and stays where it is');
    }

    /**
     * @param \ReflectionClass<object>|null $class the class the file declares, read from its path when not given
     *
     * @return list<string> how the file breaks the layout, empty when it keeps it
     */
    private function misplaced(string $context, string $relative, ?\ReflectionClass $class = null): array
    {
        $file = "$context/$relative";
        $folder = \dirname($relative);
        $parts = explode('/', $relative);
        $layer = $parts[0];
        $class ??= new \ReflectionClass($this->className($context, $relative));
        $role = $this->role($class);
        $problems = [];

        if ('Infrastructure' === $layer) {
            $expected = match ($role) {
                'resource' => 'Infrastructure/ApiPlatform/Resource',
                'processor' => 'Infrastructure/ApiPlatform/State/Processor',
                'provider' => 'Infrastructure/ApiPlatform/State/Provider',
                'openapi' => 'Infrastructure/ApiPlatform/OpenApi',
                default => null,
            };
            if (null !== $expected && !$this->within($folder, $expected)) {
                $problems[] = "$file is ".self::LABELS[$role].": it belongs in $context/$expected/";
            }
            if ('resource' !== $role && $this->within($folder, 'Infrastructure/ApiPlatform/Resource')) {
                $problems[] = "$file is not a resource: Resource/ holds resources only";
            }
            if ('processor' !== $role && $this->within($folder, 'Infrastructure/ApiPlatform/State/Processor')) {
                $problems[] = "$file is not a state processor: State/Processor/ holds processors only";
            }
            if ('provider' !== $role && $this->within($folder, 'Infrastructure/ApiPlatform/State/Provider')) {
                $problems[] = "$file is not a state provider: State/Provider/ holds providers only";
            }
            if ('ApiPlatform' === ($parts[1] ?? null)) {
                $under = $parts[2] ?? '';
                if (3 === \count($parts)) {
                    $problems[] = "$file sits flat in ApiPlatform/: it goes in one of ".implode(', ', self::API_PLATFORM_FOLDERS);
                } elseif (!\in_array($under, self::API_PLATFORM_FOLDERS, true)) {
                    $problems[] = "$file sits in ApiPlatform/$under/, which is none of ".implode(', ', self::API_PLATFORM_FOLDERS);
                } elseif ('State' === $under && !\in_array($parts[3] ?? '', ['Processor', 'Provider'], true)) {
                    $problems[] = "$file sits in State/ outside Processor/ and Provider/";
                }
            }
        }

        if ('Domain' === $layer && !$this->inSubDomain($context, $folder)) {
            $under = $parts[1] ?? '';
            if (2 === \count($parts)) {
                $problems[] = "$file sits flat in Domain/: it goes in one of ".implode(', ', self::DOMAIN_FOLDERS);
            } elseif (!\in_array($under, self::DOMAIN_FOLDERS, true)) {
                $problems[] = "$file sits in Domain/$under/, which is none of ".implode(', ', self::DOMAIN_FOLDERS);
            } else {
                $expected = match ($role) {
                    'entity' => 'Model',
                    'repository' => 'Repository',
                    'exception' => 'Exception',
                    'enum' => 'ValueObject',
                    'event' => 'Event',
                    default => null,
                };
                if (null !== $expected && $under !== $expected) {
                    $problems[] = "$file is ".self::LABELS[$role].": it belongs in $context/Domain/$expected/";
                }
                $only = ['Model' => 'entity', 'Repository' => 'repository', 'Exception' => 'exception', 'Event' => 'event'];
                if (isset($only[$under]) && $role !== $only[$under] && !('Event' === $under && $class->isInterface())) {
                    $problems[] = "$file is not ".self::LABELS[$only[$under]].": Domain/$under/ holds those only";
                }
            }
        }

        return $problems;
    }

    /**
     * What a class is, read from what it declares, never from its name.
     *
     * @param \ReflectionClass<object> $class
     */
    private function role(\ReflectionClass $class): ?string
    {
        if ([] !== $class->getAttributes(ApiResource::class) || [] !== $class->getAttributes(Operation::class, \ReflectionAttribute::IS_INSTANCEOF)) {
            return 'resource';
        }
        if ($class->implementsInterface(ProcessorInterface::class)) {
            return 'processor';
        }
        if ($class->implementsInterface(ProviderInterface::class)) {
            return 'provider';
        }
        if ($class->implementsInterface(OpenApiFactoryInterface::class)) {
            return 'openapi';
        }
        if ([] !== $class->getAttributes(Entity::class) || [] !== $class->getAttributes(Embeddable::class)) {
            return 'entity';
        }
        if ($class->isInterface() && str_ends_with($class->getShortName(), 'Repository')) {
            return 'repository';
        }
        if ($class->implementsInterface(\Throwable::class)) {
            return 'exception';
        }
        if ($class->isEnum()) {
            return 'enum';
        }
        if (!$class->isInterface() && $class->implementsInterface(DomainEvent::class)) {
            return 'event';
        }

        return null;
    }

    /** Whether a context breaks the layout, listed or not: a class out of its folder, or a folder past the cap. */
    private function breaks(string $context): bool
    {
        foreach ($this->phpFilesUnder(self::SRC."/$context") as $relative) {
            if ([] !== $this->misplaced($context, $relative)) {
                return true;
            }
        }
        foreach (array_keys($this->crowded($this->folders())) as $folder) {
            if ($this->within($folder, $context)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, int> $folders
     *
     * @return array<string, int> the folders past the cap
     */
    private function crowded(array $folders): array
    {
        return array_filter($folders, static fn (int $count): bool => $count > self::CAP);
    }

    private function within(string $folder, string $root): bool
    {
        return $folder === $root || str_starts_with($folder, "$root/");
    }

    private function inSubDomain(string $context, string $folder): bool
    {
        foreach (self::SUB_DOMAINS as $subDomain) {
            if ($this->within("$context/$folder", $subDomain)) {
                return true;
            }
        }

        return false;
    }

    private function notYetMoved(string $folder): bool
    {
        foreach (self::NOT_YET_MOVED as $context) {
            if ($this->within($folder, $context)) {
                return true;
            }
        }

        return false;
    }

    /** @return list<string> the routing keys of messenger.yaml, the messages the queue stores by their names */
    private function routedMessages(): array
    {
        $yaml = (string) file_get_contents(__DIR__.'/../../config/packages/messenger.yaml');
        preg_match_all('/^\s+(App\\\\[\w\\\\]+):/m', $yaml, $matches);
        self::assertNotEmpty($matches[1], 'messenger.yaml routes some messages: reading none means the pattern broke');

        return $matches[1];
    }

    /** @return list<string> every context, a top-level directory with a layer or a module under Module/ */
    private function contexts(): array
    {
        $contexts = [];
        foreach ([...glob(self::SRC.'/*', \GLOB_ONLYDIR) ?: [], ...glob(self::SRC.'/Module/*', \GLOB_ONLYDIR) ?: []] as $dir) {
            if (is_dir("$dir/Domain") || is_dir("$dir/Application") || is_dir("$dir/Infrastructure")) {
                $contexts[] = substr($dir, \strlen(self::SRC) + 1);
            }
        }
        sort($contexts);

        return $contexts;
    }

    /** @return array<string, int> every folder under src/ by the PHP files it holds directly */
    private function folders(): array
    {
        $folders = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(self::SRC, \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $entry) {
            if ($entry instanceof \SplFileInfo && 'php' === $entry->getExtension()) {
                $folder = substr($entry->getPath(), \strlen(self::SRC) + 1);
                $folders[$folder] = ($folders[$folder] ?? 0) + 1;
            }
        }
        ksort($folders);

        return $folders;
    }

    /** @return list<string> the PHP files under a directory, relative to it */
    private function phpFilesUnder(string $directory): array
    {
        $files = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $entry) {
            if ($entry instanceof \SplFileInfo && 'php' === $entry->getExtension()) {
                $files[] = substr($entry->getPathname(), \strlen($directory) + 1);
            }
        }
        sort($files);

        return $files;
    }

    /** @return class-string */
    private function className(string $context, string $relative): string
    {
        $name = 'App\\'.str_replace('/', '\\', $context.'/'.substr($relative, 0, -4));
        self::assertTrue(class_exists($name) || interface_exists($name) || enum_exists($name) || trait_exists($name), "$name loads");

        return $name;
    }
}
