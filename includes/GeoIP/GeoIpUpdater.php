<?php

declare(strict_types=1);

namespace VisitorIntelligence\GeoIP;

use MaxMind\Db\Reader;
use VisitorIntelligence\Core\Config;

defined('ABSPATH') || exit;

final class GeoIpUpdater
{
    private const PROVIDER = 'dbip_city_lite';

    private const UPDATE_INTERVAL = 2592000;

    private const DOWNLOAD_TIMEOUT = 120;

    private const URL_TEMPLATE =
        'https://download.db-ip.com/free/dbip-city-lite-%s.mmdb.gz';

    private const DEFAULT_RELATIVE_PATH =
        'data/geoip/dbip-city-lite.mmdb';

    private const LOCK_FILE =
        '.dbip-city-lite.lock';

    private const TEMP_SUFFIX =
        '.download';

    public function updateIfNeeded(): bool
    {
        if (
            !(bool) Config::get(
                'geoip_enabled',
                true
            )
        ) {
            return false;
        }

        $databasePath =
            $this->databasePath();

        $directory =
            dirname(
                $databasePath
            );

        $this->ensureDirectory(
            $directory
        );

        if (
            $this->isFresh(
                $databasePath
            )
        ) {
            return false;
        }

        $lockHandle =
            $this->acquireLock(
                $directory
            );

        if (
            $lockHandle === null
        ) {
            return false;
        }

        try {
            clearstatcache(
                true,
                $databasePath
            );

            if (
                $this->isFresh(
                    $databasePath
                )
            ) {
                return false;
            }

            $archivePath =
                $this->downloadArchive(
                    $directory
                );

            $databaseTemporaryPath =
                $this->extractDatabase(
                    $archivePath,
                    $directory
                );

            $this->validateDatabase(
                $databaseTemporaryPath
            );

            $this->replaceDatabase(
                $databaseTemporaryPath,
                $databasePath
            );

            return true;
        } catch (\Throwable $exception) {
            do_action(
                'vi_geoip_update_error',
                $exception,
                $databasePath
            );

            return false;
        } finally {
            $this->cleanupTemporaryFiles(
                $directory
            );

            flock(
                $lockHandle,
                LOCK_UN
            );

            fclose(
                $lockHandle
            );
        }
    }

    public function isUpdateNeeded(): bool
    {
        if (
            !(bool) Config::get(
                'geoip_enabled',
                true
            )
        ) {
            return false;
        }

        return !$this->isFresh(
            $this->databasePath()
        );
    }

    public function getDatabasePath(): string
    {
        return $this->databasePath();
    }

    private function databasePath(): string
    {
        if (
            defined(
                'VI_GEOIP_DB_PATH'
            )
            && is_string(
                VI_GEOIP_DB_PATH
            )
            && VI_GEOIP_DB_PATH !== ''
        ) {
            return VI_GEOIP_DB_PATH;
        }

        return VI_DIR .
            self::DEFAULT_RELATIVE_PATH;
    }

    private function isFresh(
        string $databasePath
    ): bool {
        if (
            !is_file(
                $databasePath
            )
        ) {
            return false;
        }

        if (
            !is_readable(
                $databasePath
            )
        ) {
            return false;
        }

        $modifiedAt =
            filemtime(
                $databasePath
            );

        if (
            $modifiedAt === false
        ) {
            return false;
        }

        return (
            time() -
            $modifiedAt
        ) < self::UPDATE_INTERVAL;
    }

    private function ensureDirectory(
        string $directory
    ): void {
        if (
            is_dir(
                $directory
            )
        ) {
            if (
                !is_writable(
                    $directory
                )
            ) {
                throw new \RuntimeException(
                    sprintf(
                        'GeoIP directory is not writable: %s',
                        $directory
                    )
                );
            }

            return;
        }

        if (
            !wp_mkdir_p(
                $directory
            )
        ) {
            throw new \RuntimeException(
                sprintf(
                    'Unable to create GeoIP directory: %s',
                    $directory
                )
            );
        }

        if (
            !is_dir(
                $directory
            )
            || !is_writable(
                $directory
            )
        ) {
            throw new \RuntimeException(
                sprintf(
                    'GeoIP directory was not created correctly: %s',
                    $directory
                )
            );
        }
    }

    /**
     * @return resource|null
     */
    private function acquireLock(
        string $directory
    ) {
        $lockPath =
            trailingslashit(
                $directory
            ) .
            self::LOCK_FILE;

        $handle =
            fopen(
                $lockPath,
                'c'
            );

        if (
            $handle === false
        ) {
            throw new \RuntimeException(
                sprintf(
                    'Unable to create GeoIP update lock: %s',
                    $lockPath
                )
            );
        }

        if (
            !flock(
                $handle,
                LOCK_EX | LOCK_NB
            )
        ) {
            fclose(
                $handle
            );

            return null;
        }

        return $handle;
    }

    private function downloadArchive(
        string $directory
    ): string {
        $release =
            gmdate(
                'Y-m'
            );

        $url =
            sprintf(
                self::URL_TEMPLATE,
                $release
            );

        $archivePath =
            trailingslashit(
                $directory
            ) .
            'dbip-city-lite-' .
            $release .
            '.mmdb.gz' .
            self::TEMP_SUFFIX;

        $response =
            wp_remote_get(
                $url,
                [
                    'timeout' =>
                        self::DOWNLOAD_TIMEOUT,

                    'redirection' =>
                        3,

                    'headers' => [
                        'Accept' =>
                            'application/gzip, application/octet-stream',
                    ],
                ]
            );

        if (
            is_wp_error(
                $response
            )
        ) {
            throw new \RuntimeException(
                sprintf(
                    'GeoIP database download failed: %s',
                    $response->get_error_message()
                )
            );
        }

        $statusCode =
            (int) wp_remote_retrieve_response_code(
                $response
            );

        if (
            $statusCode < 200
            || $statusCode >= 300
        ) {
            throw new \RuntimeException(
                sprintf(
                    'GeoIP database download returned HTTP %d.',
                    $statusCode
                )
            );
        }

        $body =
            wp_remote_retrieve_body(
                $response
            );

        if (
            !is_string(
                $body
            )
            || $body === ''
        ) {
            throw new \RuntimeException(
                'GeoIP database download returned an empty response.'
            );
        }

        $temporaryPath =
            $archivePath .
            '.' .
            bin2hex(
                random_bytes(
                    8
                )
            );

        if (
            file_put_contents(
                $temporaryPath,
                $body,
                LOCK_EX
            ) === false
        ) {
            throw new \RuntimeException(
                sprintf(
                    'Unable to write downloaded GeoIP archive: %s',
                    $temporaryPath
                )
            );
        }

        if (
            !is_readable(
                $temporaryPath
            )
            || filesize(
                $temporaryPath
            ) === false
            || filesize(
                $temporaryPath
            ) < 1024
        ) {
            @unlink(
                $temporaryPath
            );

            throw new \RuntimeException(
                'Downloaded GeoIP archive is invalid or empty.'
            );
        }

        $this->replaceTemporaryFile(
            $temporaryPath,
            $archivePath
        );

        return $archivePath;
    }

    private function extractDatabase(
        string $archivePath,
        string $directory
    ): string {
        $temporaryDatabase =
            trailingslashit(
                $directory
            ) .
            'dbip-city-lite.mmdb' .
            self::TEMP_SUFFIX .
            '.' .
            bin2hex(
                random_bytes(
                    8
                )
            );

        $input =
            gzopen(
                $archivePath,
                'rb'
            );

        if (
            $input === false
        ) {
            throw new \RuntimeException(
                'Unable to open downloaded GeoIP gzip archive.'
            );
        }

        $output =
            fopen(
                $temporaryDatabase,
                'wb'
            );

        if (
            $output === false
        ) {
            gzclose(
                $input
            );

            throw new \RuntimeException(
                sprintf(
                    'Unable to create temporary GeoIP database: %s',
                    $temporaryDatabase
                )
            );
        }

        try {
            while (
                !gzeof(
                    $input
                )
            ) {
                $chunk =
                    gzread(
                        $input,
                        1048576
                    );

                if (
                    $chunk === false
                ) {
                    throw new \RuntimeException(
                        'Unable to read downloaded GeoIP gzip archive.'
                    );
                }

                if (
                    $chunk === ''
                    && !gzeof(
                        $input
                    )
                ) {
                    throw new \RuntimeException(
                        'GeoIP gzip archive returned an invalid data chunk.'
                    );
                }

                if (
                    $chunk !== ''
                    && fwrite(
                        $output,
                        $chunk
                    ) === false
                ) {
                    throw new \RuntimeException(
                        'Unable to write temporary GeoIP database.'
                    );
                }
            }
        } finally {
            gzclose(
                $input
            );

            fclose(
                $output
            );
        }

        if (
            !is_file(
                $temporaryDatabase
            )
            || !is_readable(
                $temporaryDatabase
            )
            || filesize(
                $temporaryDatabase
            ) === false
            || filesize(
                $temporaryDatabase
            ) < 1024
        ) {
            @unlink(
                $temporaryDatabase
            );

            throw new \RuntimeException(
                'Extracted GeoIP database is invalid or empty.'
            );
        }

        return $temporaryDatabase;
    }

    private function validateDatabase(
        string $databasePath
    ): void {
        $reader = null;

        try {
            $reader =
                new Reader(
                    $databasePath
                );

            $metadata =
                $reader->metadata();

            if (
                !is_string(
                    $metadata->databaseType
                )
                || $metadata->databaseType === ''
            ) {
                throw new \RuntimeException(
                    'GeoIP database metadata is invalid.'
                );
            }

            $buildEpoch =
                $metadata->buildEpoch;

            if (
                !is_int(
                    $buildEpoch
                )
                && !is_float(
                    $buildEpoch
                )
                && !is_numeric(
                    $buildEpoch
                )
            ) {
                throw new \RuntimeException(
                    'GeoIP database build date is invalid.'
                );
            }

            if (
                (int) $buildEpoch <= 0
            ) {
                throw new \RuntimeException(
                    'GeoIP database build date is invalid.'
                );
            }
        } catch (\Throwable $exception) {
            if (
                $exception instanceof \RuntimeException
            ) {
                throw $exception;
            }

            throw new \RuntimeException(
                'Downloaded GeoIP database could not be parsed.',
                0,
                $exception
            );
        } finally {
            if (
                $reader !== null
            ) {
                $reader->close();
            }
        }
    }

    private function replaceDatabase(
        string $temporaryDatabase,
        string $databasePath
    ): void {
        $directory =
            dirname(
                $databasePath
            );

        $backupPath =
            trailingslashit(
                $directory
            ) .
            basename(
                $databasePath
            ) .
            '.previous';

        if (
            is_file(
                $backupPath
            )
        ) {
            @unlink(
                $backupPath
            );
        }

        if (
            is_file(
                $databasePath
            )
        ) {
            if (
                !rename(
                    $databasePath,
                    $backupPath
                )
            ) {
                throw new \RuntimeException(
                    sprintf(
                        'Unable to prepare existing GeoIP database for replacement: %s',
                        $databasePath
                    )
                );
            }
        }

        if (
            !rename(
                $temporaryDatabase,
                $databasePath
            )
        ) {
            if (
                is_file(
                    $backupPath
                )
            ) {
                @rename(
                    $backupPath,
                    $databasePath
                );
            }

            throw new \RuntimeException(
                sprintf(
                    'Unable to install new GeoIP database: %s',
                    $databasePath
                )
            );
        }

        @unlink(
            $backupPath
        );

        clearstatcache(
            true,
            $databasePath
        );

        if (
            !is_readable(
                $databasePath
            )
        ) {
            throw new \RuntimeException(
                sprintf(
                    'Installed GeoIP database is not readable: %s',
                    $databasePath
                )
            );
        }
    }

    private function replaceTemporaryFile(
        string $temporaryPath,
        string $destinationPath
    ): void {
        if (
            is_file(
                $destinationPath
            )
            && !unlink(
                $destinationPath
            )
        ) {
            @unlink(
                $temporaryPath
            );

            throw new \RuntimeException(
                sprintf(
                    'Unable to replace temporary GeoIP archive: %s',
                    $destinationPath
                )
            );
        }

        if (
            !rename(
                $temporaryPath,
                $destinationPath
            )
        ) {
            @unlink(
                $temporaryPath
            );

            throw new \RuntimeException(
                sprintf(
                    'Unable to store temporary GeoIP archive: %s',
                    $destinationPath
                )
            );
        }
    }

    private function cleanupTemporaryFiles(
        string $directory
    ): void {
        $pattern =
            trailingslashit(
                $directory
            ) .
            '*.mmdb' .
            self::TEMP_SUFFIX .
            '.*';

        $files =
            glob(
                $pattern
            );

        if (
            is_array(
                $files
            )
        ) {
            foreach (
                $files as $file
            ) {
                if (
                    is_file(
                        $file
                    )
                ) {
                    @unlink(
                        $file
                    );
                }
            }
        }

        $archivePattern =
            trailingslashit(
                $directory
            ) .
            'dbip-city-lite-*.mmdb.gz' .
            self::TEMP_SUFFIX;

        $archives =
            glob(
                $archivePattern
            );

        if (
            is_array(
                $archives
            )
        ) {
            foreach (
                $archives as $archive
            ) {
                if (
                    is_file(
                        $archive
                    )
                ) {
                    @unlink(
                        $archive
                    );
                }
            }
        }
    }
}