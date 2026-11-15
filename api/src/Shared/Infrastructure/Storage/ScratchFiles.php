<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Shared\Infrastructure\Storage;

use App\Shared\Application\Storage\StorageFull;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;

/**
 * A file a request prepares on the server's own disk and hands over or reads (a download being written, an import's
 * working copy), outside the stored files. A write that fails there is said by what kept it: the disk out of room is
 * the storage full, in words (`StorageFullListener`), anything else a moment to wait. The writers say only that they
 * could not write, in production a warning PHP does not throw, so the room left is read once the write has failed.
 */
final readonly class ScratchFiles
{
    private const string UNPREPARED = 'The file could not be prepared; try again shortly.';

    /** @param int $roomMinimum the bytes under which a disk a write failed on is read as out of room */
    public function __construct(
        #[Autowire(param: 'app.files.scratch_room_minimum')] private int $roomMinimum,
    ) {
    }

    /**
     * A new empty file, for the caller to write, hand over and remove.
     *
     * @param string $folder where it is made; empty for the system's temporary directory
     *
     * @throws StorageFull                     when the disk has no room left for it
     * @throws ServiceUnavailableHttpException when it could not be made otherwise
     */
    public function path(string $prefix, string $folder = ''): string
    {
        $path = @tempnam(self::folder($folder), $prefix);
        if (false === $path) {
            throw $this->unprepared(null, $folder);
        }

        return $path;
    }

    /** What a file that could not be written answers: the disk out of room in words, else a moment to wait. */
    public function unprepared(?\Throwable $failure, string $folder = ''): StorageFull|ServiceUnavailableHttpException
    {
        $free = @disk_free_space(self::folder($folder));
        if (\is_float($free) && $free < $this->roomMinimum) {
            return new StorageFull(\sprintf('The disk under %s has no room left to prepare a file.', self::folder($folder)), 0, $failure);
        }

        return new ServiceUnavailableHttpException(30, self::UNPREPARED, $failure);
    }

    private static function folder(string $folder): string
    {
        return '' === $folder ? sys_get_temp_dir() : $folder;
    }
}
