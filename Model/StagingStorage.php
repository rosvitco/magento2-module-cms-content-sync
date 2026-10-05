<?php
/**
 * Copyright © Rosvit. All rights reserved.
 */
declare(strict_types=1);

namespace Rosvit\CmsContentSync\Model;

use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\Exception\FileSystemException;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Filesystem;
use Magento\Framework\Filesystem\Directory\WriteInterface;
use Magento\Framework\Math\Random;

/**
 * Keeps an uploaded exchange file on disk between the upload and the confirmed import.
 *
 * Only the token travels in the admin session, so a heavy export never inflates it.
 * Paths are always resolved through Filesystem, never assembled by hand.
 */
class StagingStorage
{
    /**
     * Directory under var/ that holds the staged files.
     */
    public const STAGING_DIR = 'cms_content_sync';

    /**
     * Admin session key holding the token of the file waiting to be imported.
     */
    public const SESSION_TOKEN_KEY = 'rosvit_cms_content_sync_token';

    /**
     * A token is exactly 32 hexadecimal characters.
     */
    private const TOKEN_LENGTH = 32;

    private const TOKEN_CHARS = '0123456789abcdef';

    private const TOKEN_PATTERN = '/^[a-f0-9]{32}$/';

    public function __construct(
        private readonly Filesystem $filesystem,
        private readonly Random $random
    ) {
    }

    /**
     * Store the raw upload and return the token that points at it.
     *
     * @throws LocalizedException
     */
    public function save(string $rawJson): string
    {
        $token = $this->random->getRandomString(self::TOKEN_LENGTH, self::TOKEN_CHARS);

        try {
            $directory = $this->directory();
            $directory->create(self::STAGING_DIR);
            $directory->writeFile($this->relativePath($token), $rawJson);
        } catch (FileSystemException $e) {
            throw new LocalizedException(
                __('The uploaded file could not be stored: %1', $e->getMessage())
            );
        }

        return $token;
    }

    /**
     * Read back a staged file as a decoded envelope.
     *
     * @return array<string, mixed>
     * @throws LocalizedException when the file is gone or no longer readable
     */
    public function load(string $token): array
    {
        $this->assertTokenFormat($token);

        $directory = $this->directory();
        $path = $this->relativePath($token);

        if (!$directory->isExist($path)) {
            throw new LocalizedException(
                __('The uploaded file is no longer available. Please upload it again.')
            );
        }

        try {
            $rawJson = $directory->readFile($path);
        } catch (FileSystemException $e) {
            throw new LocalizedException(
                __('The uploaded file could not be read: %1', $e->getMessage())
            );
        }

        $envelope = json_decode($rawJson, true);

        if (!is_array($envelope)) {
            throw new LocalizedException(
                __('The uploaded file is no longer readable. Please upload it again.')
            );
        }

        return $envelope;
    }

    /**
     * Drop a staged file. Missing files are not an error: the goal is that it is gone.
     */
    public function delete(string $token): void
    {
        if (!preg_match(self::TOKEN_PATTERN, $token)) {
            return;
        }

        try {
            $directory = $this->directory();
            $path = $this->relativePath($token);

            if ($directory->isExist($path)) {
                $directory->delete($path);
            }
        } catch (FileSystemException) {
            // Nothing to do: a staged file that cannot be removed is cleaned with the rest of var/.
        }
    }

    /**
     * @throws LocalizedException
     */
    private function assertTokenFormat(string $token): void
    {
        if (!preg_match(self::TOKEN_PATTERN, $token)) {
            throw new LocalizedException(__('The uploaded file reference is not valid.'));
        }
    }

    private function relativePath(string $token): string
    {
        return self::STAGING_DIR . '/' . $token . '.json';
    }

    private function directory(): WriteInterface
    {
        return $this->filesystem->getDirectoryWrite(DirectoryList::VAR_DIR);
    }
}
