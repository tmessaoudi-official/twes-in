<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Files\Infrastructure\Flysystem;

use AsyncAws\S3\S3Client;
use League\Flysystem\AsyncAwsS3\AsyncAwsS3Adapter;
use League\Flysystem\Filesystem;
use League\Flysystem\FilesystemOperator;
use League\Flysystem\Local\LocalFilesystemAdapter;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * The filesystem stored files live on, chosen by FILES_STORAGE: a local volume under FILES_DIRECTORY, or a bucket of any
 * S3-compatible service (docs/SPEC.md § 2 Files). An S3 setting left empty stops the filesystem from being built at all,
 * rather than letting the client look for credentials elsewhere.
 */
final class FilesystemFactory
{
    public static function create(
        string $driver,
        string $directory,
        string $bucket,
        string $endpoint,
        string $region,
        string $accessKey,
        string $secretKey,
        bool $pathStyle,
        ?HttpClientInterface $httpClient = null,
    ): FilesystemOperator {
        return new Filesystem(match ($driver) {
            'local' => new LocalFilesystemAdapter($directory),
            's3' => new AsyncAwsS3Adapter(
                new S3Client(array_filter([
                    'region' => self::required('FILES_S3_REGION', $region),
                    'accessKeyId' => self::required('FILES_S3_ACCESS_KEY', $accessKey),
                    'accessKeySecret' => self::required('FILES_S3_SECRET_KEY', $secretKey),
                    'endpoint' => $endpoint,
                    'pathStyleEndpoint' => $pathStyle ? 'true' : 'false',
                ], static fn (string $value): bool => '' !== $value), null, $httpClient),
                self::required('FILES_S3_BUCKET', $bucket),
            ),
            default => throw new \InvalidArgumentException(\sprintf('FILES_STORAGE is "%s"; it must be "local" or "s3".', $driver)),
        });
    }

    private static function required(string $variable, string $value): string
    {
        if ('' === $value) {
            throw new \InvalidArgumentException(\sprintf('%s must be set when FILES_STORAGE is "s3".', $variable));
        }

        return $value;
    }
}
