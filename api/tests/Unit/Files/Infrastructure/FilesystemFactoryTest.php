<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Unit\Files\Infrastructure;

use App\Files\Infrastructure\Flysystem\FilesystemFactory;
use App\Files\Infrastructure\Flysystem\FlysystemFileStorage;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem as LocalFiles;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class FilesystemFactoryTest extends TestCase
{
    private const array S3 = [
        'bucket' => 'receipts',
        'endpoint' => 'https://s3.example.test',
        'region' => 'eu-west-3',
        'accessKey' => 'twes-access',
        'secretKey' => 'twes-secret',
    ];

    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir().'/twes-files-'.bin2hex(random_bytes(6));
    }

    protected function tearDown(): void
    {
        new LocalFiles()->remove($this->directory);
    }

    public function testTheLocalDriverKeepsFilesUnderTheDirectory(): void
    {
        $storage = new FlysystemFileStorage(FilesystemFactory::create('local', $this->directory, ...self::S3, pathStyle: true));

        $storage->write('companies/c1/f1', 'receipt');

        self::assertStringEqualsFile($this->directory.'/companies/c1/f1', 'receipt');
    }

    public function testTheS3DriverStoresAndReadsBackTheBytesInTheNamedBucketOfTheEndpoint(): void
    {
        $bytes = "%PDF-1.7\n\x00\xff binary";
        /** @var list<array{string, string, string}> $sent */
        $sent = [];
        $http = new MockHttpClient(static function (string $method, string $url, array $options) use (&$sent, $bytes): MockResponse {
            $body = $options['body'] ?? '';
            $sent[] = [$method, $url, \is_string($body) ? $body : ''];

            return match ($method) {
                'HEAD' => new MockResponse('', ['http_code' => 404]),
                'PUT' => new MockResponse('', ['http_code' => 200]),
                'GET' => new MockResponse($bytes, ['http_code' => 200]),
                default => throw new \LogicException($method.' was not expected'),
            };
        });
        $storage = new FlysystemFileStorage(FilesystemFactory::create('s3', $this->directory, ...self::S3, pathStyle: true, httpClient: $http));

        $storage->write('companies/c1/f1', $bytes);
        self::assertSame($bytes, $storage->read('companies/c1/f1'));

        $object = 'https://s3.example.test/receipts/companies/c1/f1';
        self::assertSame([['HEAD', $object], ['PUT', $object], ['GET', $object]], array_map(static fn (array $request): array => [$request[0], $request[1]], $sent));
        self::assertSame($bytes, $sent[1][2]);
        self::assertDirectoryDoesNotExist($this->directory);
    }

    /** @return iterable<string, array{string, string}> */
    public static function missingS3Settings(): iterable
    {
        yield 'bucket' => ['bucket', 'FILES_S3_BUCKET'];
        yield 'region' => ['region', 'FILES_S3_REGION'];
        yield 'access key' => ['accessKey', 'FILES_S3_ACCESS_KEY'];
        yield 'secret key' => ['secretKey', 'FILES_S3_SECRET_KEY'];
    }

    #[DataProvider('missingS3Settings')]
    public function testTheS3DriverRefusesToStartWithoutASetting(string $setting, string $variable): void
    {
        $value = static fn (string $name): string => $name === $setting ? '' : self::S3[$name];

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage($variable);

        FilesystemFactory::create(
            's3',
            $this->directory,
            bucket: $value('bucket'),
            endpoint: $value('endpoint'),
            region: $value('region'),
            accessKey: $value('accessKey'),
            secretKey: $value('secretKey'),
            pathStyle: true,
        );
    }

    public function testAnUnknownDriverIsNamed(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('"ftp"');

        FilesystemFactory::create('ftp', $this->directory, ...self::S3, pathStyle: true);
    }
}
