<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Identity\Application\PasswordHasher;
use App\Identity\Domain\Model\User;
use App\Identity\Domain\Repository\UserRepository;
use App\Identity\Domain\ValueObject\Email;
use App\Identity\Infrastructure\Mfa\OtphpTotpCodes;
use App\Identity\Infrastructure\Mfa\SodiumSecretCipher;
use App\Identity\Infrastructure\Security\CsrfRequestListener;
use App\Module\Customers\Domain\ValueObject\CustomerKind;
use App\Module\Customers\Domain\ValueObject\CustomerProfile;
use App\Shared\Domain\Model\PostalAddress;
use App\Shared\Infrastructure\Submissions\Submissions;
use App\Tenancy\Domain\Model\Company;
use App\Tenancy\Domain\Model\Membership;
use App\Tenancy\Domain\Model\Role;
use App\Tenancy\Domain\ValueObject\CompanyProfile;
use App\Tenancy\Domain\ValueObject\Permission;
use Doctrine\Bundle\DoctrineBundle\DataCollector\DoctrineDataCollector;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Profiler\Profile;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\ReceivedStamp;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Functional tests talk to the API the way the SPA does: JSON bodies, a random csrf-token header on every
 * unsafe request, cookies kept between requests. Rows are created through the entity manager and rolled back by dama.
 */
abstract class ApiTestCase extends WebTestCase
{
    /** The authenticator createUser enrols; .env.test's key encrypts it. */
    protected const string AUTHENTICATOR_SECRET = 'JBSWY3DPEHPK3PXPJBSWY3DPEHPK3PXP';

    /** The session cookie as the browser holds it. */
    protected const string SESSION_COOKIE = '__Host-twes_session';
    private const string MFA_TEST_KEY = 'Fh0hAlB8Q7xUq0mJ0zRz2s4vXn6yKbPd8eGtWc3AjQY=';

    protected KernelBrowser $client;

    /** @var array<string, true> the addresses createUser enrolled, whose login() goes through the code step */
    private array $enrolled = [];

    protected function setUp(): void
    {
        $this->client = static::createClient();
        // Every request from a browser tab carries the site's origin; the CSRF manager checks it.
        $this->client->setServerParameter('HTTP_ORIGIN', 'http://localhost');
        // Every request from the web names the screen's language, French unless the person chose another; the browser
        // kit would otherwise send its own « en-us », and the API answers in the language a request names.
        $this->client->setServerParameter('HTTP_ACCEPT_LANGUAGE', 'fr');
        // The login limiter's counters live in a filesystem pool that outlives the transaction dama rolls back.
        static::getContainer()->get('cache.rate_limiter')->clear();
    }

    protected function em(): EntityManagerInterface
    {
        return static::getContainer()->get(EntityManagerInterface::class);
    }

    /**
     * The version a record is stored at, which its revision sends back: a test of something other than the version
     * revises the record as it stands.
     */
    protected function storedVersion(string $table, string $id): int
    {
        $version = $this->em()->getConnection()->fetchOne(\sprintf('SELECT version FROM %s WHERE id = ?', $table), [$id]);
        self::assertIsInt($version, "$table $id carries a version");

        return $version;
    }

    /**
     * An operator gets an authenticator unless `$authenticator` says otherwise: every operator must carry one
     * (docs/SPEC.md § 7, 2026-09-15, S3), so a test of anything else signs them in the way they really sign in.
     *
     * @param list<string> $permissions
     */
    protected function createUser(string $email, string $password, ?Company $company = null, array $permissions = ['*'], string $roleName = Role::OWNER, bool $operator = false, bool $active = true, ?bool $authenticator = null): User
    {
        $em = $this->em();
        $user = new User(Email::fromString($email), ucfirst(strtok($email, '@') ?: 'User'), 'fr');
        $user->setPasswordHash(static::getContainer()->get(PasswordHasher::class)->hash($password), new \DateTimeImmutable());
        $user->setPlatformOperator($operator);
        $user->setActive($active);
        if ($authenticator ?? $operator) {
            $user->beginTotpEnrolment((new SodiumSecretCipher(self::MFA_TEST_KEY))->encrypt(self::AUTHENTICATOR_SECRET));
            $user->confirmTotpEnrolment(self::spentTimestep());
            $this->enrolled[strtolower($email)] = true;
        }
        $em->persist($user);
        if (null !== $company) {
            $role = new Role($roleName, $permissions, $company);
            $em->persist($role);
            $em->persist(new Membership($user, $company, $role));
        }
        $em->flush();

        return $user;
    }

    /**
     * Now as the API stamps it, PHP's clock in UTC to the second, for a row a test writes in SQL beside the API's own
     * stamps: the database's `now()` is the test transaction's start, which can fall a second before a request the test
     * made since, so a row written « after » it would read as before it.
     */
    protected static function apiNow(): string
    {
        return new \DateTimeImmutable('now', new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }

    /**
     * The day it is for the company, or that day shifted (`-100 days`), which is how the API reads a day it is sent or
     * counts days to: in UTC it is still yesterday for the hour after midnight in Tunis, and a receipt dated so is refused
     * as before its order was sent.
     */
    protected static function dayOf(Company $company, string $shift = 'today'): string
    {
        return new \DateTimeImmutable($shift, new \DateTimeZone($company->getTimezone()))->format('Y-m-d');
    }

    /**
     * A Tunisian company, named as an invoice must name its seller unless `$named` is false: issuing refuses a company
     * without its legal name, address and matricule fiscal, and the tests that are not about that issue all the same.
     */
    protected function createCompany(string $name = 'Demo', bool $named = true): Company
    {
        $company = new Company($name, 'TN', 'TND', 'fr', 'Africa/Tunis');
        if ($named) {
            $company->reviseProfile(new CompanyProfile(legalName: $name.' SARL', identifiers: ['matricule_fiscal' => '1234567A/B/M/000'], addressLine1: 'Rue de Marseille', postalCode: '1000', city: 'Tunis'));
        }
        $this->em()->persist($company);
        $this->em()->flush();

        return $company;
    }

    /**
     * A business customer at home in Tunisia, named as an invoice must name it: its billing address and its matricule
     * fiscal. Issuing refuses a customer without them, and the tests that are not about that issue all the same.
     */
    protected static function aTunisianBusiness(string $name, ?string $defaultDiscountRate = null): CustomerProfile
    {
        return new CustomerProfile(CustomerKind::Company, $name, identifiers: ['matricule_fiscal' => '7654321A/B/M/000'], billingAddress: new PostalAddress('Rue de Rome', null, '1000', 'Tunis', 'TN'), defaultDiscountRate: $defaultDiscountRate);
    }

    /** The SPA's token: one random value per page load, sent as a header (framework.csrf_protection, header only). */
    private const string CSRF_TOKEN = '0123456789abcdef0123456789abcdef';

    /**
     * A second (or third) company for a user who already exists.
     *
     * @param list<string> $permissions
     */
    protected function addMembership(User $user, Company $company, string $roleName = Role::MEMBER, array $permissions = ['*']): void
    {
        $em = $this->em();
        $role = new Role($roleName, $permissions, $company);
        $em->persist($role);
        $em->persist(new Membership($user, $company, $role));
        $em->flush();
    }

    /**
     * Each POST names its own attempt, as the screen does (`Submissions`): a fresh `Idempotency-Key` unless `$server`
     * names one, or names it null to send none.
     *
     * @param array<string, mixed>|null  $body
     * @param array<string, string|null> $server extra server parameters, such as `HTTP_X_TAB`; null leaves one out
     */
    protected function postJson(string $path, ?array $body, bool $withCsrf = true, array $server = []): void
    {
        $headers = array_filter(['CONTENT_TYPE' => 'application/json', Submissions::KEY_SERVER => Uuid::v4()->toRfc4122(), ...$server], static fn (?string $value): bool => null !== $value);
        if ($withCsrf) {
            $headers['HTTP_'.strtoupper(str_replace('-', '_', CsrfRequestListener::HEADER))] = self::CSRF_TOKEN;
        }
        $this->client->request('POST', $path, [], [], $headers, null === $body ? null : json_encode($body, \JSON_THROW_ON_ERROR));
    }

    /**
     * A file sent the way the SPA's FormData does: one multipart part under `$field`.
     *
     * @param array<string, string|list<string>|array<string, string>> $parameters form fields sent beside the file, a list as
     *                                                                             `name[]`, a map as `name[key]`
     * @param array<string, string>                                    $server     more headers, as server parameters (a paired phone's key)
     */
    protected function uploadFile(string $path, string $name, string $contents, string $field = 'file', array $parameters = [], array $server = []): void
    {
        $tmp = (string) tempnam(sys_get_temp_dir(), 'upload');
        file_put_contents($tmp, $contents);
        try {
            $this->client->request('POST', $path, $parameters, [$field => new UploadedFile($tmp, $name, null, null, true)], $server + [
                'HTTP_ACCEPT' => 'application/json',
                'HTTP_'.strtoupper(str_replace('-', '_', CsrfRequestListener::HEADER)) => self::CSRF_TOKEN,
                Submissions::KEY_SERVER => Uuid::v4()->toRfc4122(),
            ]);
        } finally {
            // The toolchain's TMPDIR outlives the run on the host's tmpfs: a file per upload adds up to its inodes.
            unlink($tmp);
        }
    }

    /** @param array<string, mixed>|null $body */
    protected function sendJson(string $method, string $path, ?array $body = null, bool $withCsrf = true): void
    {
        $headers = ['CONTENT_TYPE' => 'application/json', Submissions::KEY_SERVER => Uuid::v4()->toRfc4122()];
        if ($withCsrf) {
            $headers['HTTP_'.strtoupper(str_replace('-', '_', CsrfRequestListener::HEADER))] = self::CSRF_TOKEN;
        }
        $this->client->request($method, $path, [], [], $headers, null === $body ? null : json_encode($body, \JSON_THROW_ON_ERROR));
    }

    /** Accepts what the SPA's HttpClient accepts: a list answers JSON-LD, a single record plain JSON (docs/SPEC.md § 7). */
    protected function getJson(string $path): void
    {
        $this->client->request('GET', $path, [], [], ['HTTP_ACCEPT' => 'application/json, text/plain, */*']);
    }

    /** @return list<array<string, mixed>> the rows of a list, whether a Hydra page or a plain array */
    protected function jsonList(): array
    {
        $body = json_decode((string) $this->client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);
        self::assertIsArray($body);
        if (\array_key_exists('member', $body)) {
            $body = $body['member'];
            self::assertIsArray($body);
        }
        $out = [];
        foreach ($body as $row) {
            self::assertIsArray($row);
            $out[] = self::stringKeyed($row);
        }

        return $out;
    }

    /**
     * The statements one page of a list costs, asked for this many rows, which the page must hold (audit PF-07). A list
     * that reads a relation per row costs more for more rows; one that reads each relation once for the page does not.
     */
    protected function statementsForAPageOf(string $path, int $rows): int
    {
        $this->warmSession();
        $this->client->enableProfiler();
        $this->getJson($path.(str_contains($path, '?') ? '&' : '?').'itemsPerPage='.$rows);
        self::assertResponseIsSuccessful();
        self::assertCount($rows, $this->jsonList());
        $profile = $this->client->getProfile();
        self::assertInstanceOf(Profile::class, $profile, 'the profiler recorded the request');
        $collector = $profile->getCollector('db');
        self::assertInstanceOf(DoctrineDataCollector::class, $collector);

        return $collector->getQueryCount();
    }

    /** The statements one plain GET costs, once the session is warm; the page must answer 200. */
    protected function statementsFor(string $path): int
    {
        $this->warmSession();
        $this->client->enableProfiler();
        $this->getJson($path);
        self::assertResponseIsSuccessful();
        $profile = $this->client->getProfile();
        self::assertInstanceOf(Profile::class, $profile, 'the profiler recorded the request');
        $collector = $profile->getCollector('db');
        self::assertInstanceOf(DoctrineDataCollector::class, $collector);

        return $collector->getQueryCount();
    }

    /**
     * The first request of a session records it for the person's list of devices, which is a cost of that request and not
     * of the page it asked for; a request made first leaves the pages measured after it at what they cost every time.
     */
    private function warmSession(): void
    {
        $this->client->request('GET', '/api/auth/me');
    }

    /**
     * The SQL one page of a list runs, in order.
     *
     * @return list<string>
     */
    protected function sqlForAPageOf(string $path): array
    {
        $this->warmSession();
        $this->client->enableProfiler();
        $this->getJson($path.(str_contains($path, '?') ? '&' : '?').'itemsPerPage=1');
        self::assertResponseIsSuccessful();

        return $this->sqlOfTheLastRequest();
    }

    /**
     * The SQL the last request ran, in order; `enableProfiler()` must have been called before it.
     *
     * @return list<string>
     */
    protected function sqlOfTheLastRequest(): array
    {
        $profile = $this->client->getProfile();
        self::assertInstanceOf(Profile::class, $profile, 'the profiler recorded the request');
        $collector = $profile->getCollector('db');
        self::assertInstanceOf(DoctrineDataCollector::class, $collector);

        $sql = [];
        foreach ($collector->getQueries() as $connection) {
            self::assertIsArray($connection);
            foreach ($connection as $query) {
                self::assertIsArray($query);
                self::assertIsString($query['sql'] ?? null);
                $sql[] = $query['sql'];
            }
        }

        return $sql;
    }

    /**
     * A row is read `FOR UPDATE` before the statement that changes what it guards: two requests racing for one spend
     * then take turns, and the second reads what the first left.
     *
     * @param list<string> $sql
     */
    protected static function assertLockedBefore(array $sql, string $lockedTable, string $change): void
    {
        $lock = null;
        $changed = null;
        foreach ($sql as $at => $statement) {
            if (null === $lock && 1 === preg_match('/\\bFROM '.preg_quote($lockedTable, '/').'\\s.*\\bFOR UPDATE\\b/is', $statement)) {
                $lock = $at;
            }
            if (null === $changed && str_starts_with(ltrim($statement), $change)) {
                $changed = $at;
            }
        }
        self::assertNotNull($changed, \sprintf('the request ran "%s"', $change));
        self::assertNotNull($lock, \sprintf('the request locked a row of %s', $lockedTable));
        self::assertLessThan($changed, $lock, \sprintf('%s is locked before "%s"', $lockedTable, $change));
    }

    /** An invitation and its acceptance resolve a role through RoleRepository::builtIn, which only ever returns a company-less role. */
    protected function seedBuiltInRoles(): void
    {
        $em = $this->em();
        $em->persist(new Role(Role::OWNER, [Permission::WILDCARD]));
        $em->persist(new Role(Role::ADMIN, ['user.read', 'user.write', 'company.read']));
        $em->persist(new Role(Role::MEMBER, ['company.read']));
        $em->persist(new Role(Role::CLERK, ['company.read']));
        $em->persist(new Role(Role::ACCOUNTANT, ['company.read']));
        $em->flush();
    }

    /** Signs in; for an account createUser enrolled, the code step too, leaving the answer a one-step login gives. */
    protected function login(string $email, string $password): void
    {
        $this->postJson('/api/auth/login', ['email' => $email, 'password' => $password]);
        if (!isset($this->enrolled[strtolower($email)]) || Response::HTTP_OK !== $this->client->getResponse()->getStatusCode() || true !== ($this->json()['mfaRequired'] ?? null)) {
            return;
        }

        // The last spent step goes back into the past first, so signing in twice within thirty seconds is not a replay.
        $user = $this->em()->getRepository(User::class)->findOneBy(['email' => Email::fromString($email)]);
        self::assertNotNull($user);
        $user->confirmTotpEnrolment(self::spentTimestep());
        $this->em()->flush();
        $this->postJson('/api/auth/mfa/verify', ['code' => (new OtphpTotpCodes())->codeAt(self::AUTHENTICATOR_SECRET, new \DateTimeImmutable())]);
    }

    /** A step a minute old: any code for now is newer than it. */
    private static function spentTimestep(): int
    {
        return intdiv((new \DateTimeImmutable('-1 minute'))->getTimestamp(), 30);
    }

    /**
     * What a Hydra page says about itself: `totalItems` and, when there is more than one page, `view`.
     *
     * @return array{totalItems: int, view?: array<string, mixed>}
     */
    protected function jsonPage(): array
    {
        self::assertResponseHeaderSame('Content-Type', 'application/ld+json');
        $body = $this->json();
        self::assertIsInt($body['totalItems'] ?? null, 'a page carries its total');
        $page = ['totalItems' => $body['totalItems']];
        if (isset($body['view'])) {
            $page['view'] = $this->section($body, 'view');
        }

        return $page;
    }

    /** @return array<string, mixed> */
    protected function json(): array
    {
        $body = json_decode((string) $this->client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);
        self::assertIsArray($body);

        return self::stringKeyed($body);
    }

    /**
     * An import's answer without the id of the import it kept, which is new on every run: an id once committed, none
     * for a preview or a file that stopped.
     *
     * @return array<string, mixed>
     */
    protected function importReport(): array
    {
        $report = $this->json();
        self::assertArrayHasKey('run', $report);
        if (true === $report['committed']) {
            self::assertIsString($report['run']);
            self::assertTrue(Uuid::isValid($report['run']));
        } else {
            self::assertNull($report['run']);
        }
        unset($report['run']);

        return $report;
    }

    /**
     * What a 422 refused, as a screen reads it: each violation's property, the reason it translates and that text's
     * parameters (none when the payload carries none).
     *
     * @return list<array{propertyPath: mixed, reason: mixed, params: mixed}>
     */
    protected function refusals(): array
    {
        $refusals = [];
        foreach ($this->arrayAt($this->json(), 'violations') as $violation) {
            self::assertIsArray($violation);
            $payload = $violation['payload'] ?? [];
            self::assertIsArray($payload, 'a payload is an object');
            $refusals[] = ['propertyPath' => $violation['propertyPath'] ?? null, 'reason' => $payload['reason'] ?? null, 'params' => $payload['params'] ?? []];
        }

        return $refusals;
    }

    /**
     * Scalars out of a JSON body, asserted to be what they are. PHPStan runs at max here, so a test that
     * indexes straight into a decoded body is indexing into `mixed`.
     *
     * @param array<string, mixed> $body
     */
    protected function stringAt(array $body, string $key): string
    {
        $value = $body[$key] ?? null;
        self::assertIsString($value, "$key is a string");

        return $value;
    }

    /** @param array<string, mixed> $body */
    protected function intAt(array $body, string $key): int
    {
        $value = $body[$key] ?? null;
        self::assertIsInt($value, "$key is an integer");

        return $value;
    }

    /** @param array<string, mixed> $body */
    protected function boolAt(array $body, string $key): bool
    {
        $value = $body[$key] ?? null;
        self::assertIsBool($value, "$key is a boolean");

        return $value;
    }

    /**
     * @param array<string, mixed> $body
     *
     * @return array<mixed>
     */
    protected function arrayAt(array $body, string $key): array
    {
        $value = $body[$key] ?? null;
        self::assertIsArray($value, "$key is an array");

        return $value;
    }

    /**
     * A nested object of a JSON body, asserted to be one.
     *
     * @param array<string, mixed> $body
     *
     * @return array<string, mixed>
     */
    protected function section(array $body, string $key): array
    {
        $section = $body[$key] ?? null;
        self::assertIsArray($section, "$key is an object");

        return self::stringKeyed($section);
    }

    /**
     * @param array<mixed> $array
     *
     * @return array<string, mixed>
     */
    private static function stringKeyed(array $array): array
    {
        $out = [];
        foreach ($array as $k => $v) {
            $out[(string) $k] = $v;
        }

        return $out;
    }

    /**
     * Forgets the account's second-factor and step-up attempts, as if it had been set up earlier than the window they
     * count in: a factor is added after a step-up, which spends one of the five, and a case counting its five guesses
     * starts from none.
     */
    protected function forgetSecondFactorAttempts(string $email): void
    {
        $user = static::getContainer()->get(UserRepository::class)->ofEmail(Email::fromString($email));
        self::assertNotNull($user);
        $limiters = static::getContainer()->get('limiter.mfa_verify');
        self::assertInstanceOf(RateLimiterFactoryInterface::class, $limiters);
        $limiters->create($user->getId()->toRfc4122())->reset();
    }

    /** Proves who is at the screen again, as the step-up dialog does before an export (docs/SPEC.md § 7, audit H-b2). */
    protected function stepUp(string $password): void
    {
        $this->postJson('/api/auth/step-up', ['password' => $password]);
        self::assertResponseStatusCodeSame(Response::HTTP_NO_CONTENT);
    }

    /**
     * What the last request left for the worker, as it sits in the queue (config/packages/messenger.yaml: in memory under
     * test). Read it before the next request: the kernel reboots then, and the queue with it.
     *
     * @return list<object>
     */
    protected function queued(): array
    {
        // get() hands over one message unless asked for more: each queue is read whole.
        $queued = [];
        foreach (self::queues() as $queue) {
            $queued = [...$queued, ...array_map(static fn (Envelope $envelope): object => $envelope->getMessage(), array_values([...$queue->get(\PHP_INT_MAX)]))];
        }

        return $queued;
    }

    /** Hands what is queued to the bus as the worker would, until nothing is left; how many it handed. */
    protected function deliverQueued(): int
    {
        $bus = static::getContainer()->get(MessageBusInterface::class);
        $handed = 0;
        do {
            $before = $handed;
            foreach (self::queues() as $transport => $queue) {
                while ([] !== $envelopes = array_values([...$queue->get()])) {
                    foreach ($envelopes as $envelope) {
                        $bus->dispatch($envelope->with(new ReceivedStamp($transport)));
                        $queue->ack($envelope);
                        ++$handed;
                    }
                }
            }
        } while ($handed > $before);

        return $handed;
    }

    /**
     * Every transport a message leaves the request on, by name.
     *
     * @return array<string, InMemoryTransport>
     */
    private static function queues(): array
    {
        $queues = [];
        foreach (['async', 'demo'] as $transport) {
            $queue = static::getContainer()->get('messenger.transport.'.$transport);
            self::assertInstanceOf(InMemoryTransport::class, $queue);
            $queues[$transport] = $queue;
        }

        return $queues;
    }

    /** The kernel reboots between requests, so an entity from before a request must be re-read, not refreshed. */
    protected function reload(User $user): User
    {
        $fresh = $this->em()->find(User::class, $user->getId());
        self::assertNotNull($fresh);

        return $fresh;
    }
}
