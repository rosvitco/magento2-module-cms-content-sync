<?php
/**
 * Copyright © Rosvit. All rights reserved.
 */
declare(strict_types=1);

namespace Rosvit\CmsContentSync\Model\Import;

use Magento\Framework\Exception\LocalizedException;
use Rosvit\CmsContentSync\Model\Export\BlockExporter;
use Rosvit\CmsContentSync\Model\Export\EnvelopeBuilder;
use Rosvit\CmsContentSync\Model\Export\PageExporter;

/**
 * Validates an uploaded exchange file before anything is staged or imported.
 *
 * Every failure is reported as a LocalizedException carrying a message the operator can
 * act on: which rule failed, and on which entity.
 */
class Parser
{
    /**
     * Hard limit for the uploaded file, in bytes.
     */
    public const MAX_FILE_SIZE = 8 * 1024 * 1024;

    /**
     * The only accepted file extension.
     */
    public const ALLOWED_EXTENSION = 'json';

    /**
     * Entity types this format version knows how to import.
     */
    public const SUPPORTED_ENTITY_TYPES = [
        PageExporter::ENTITY_TYPE,
        BlockExporter::ENTITY_TYPE,
    ];

    /**
     * Validate the uploaded file itself, before reading its contents.
     *
     * @throws LocalizedException
     */
    public function validateFile(string $fileName, int $fileSize): void
    {
        $extension = strtolower((string)pathinfo($fileName, PATHINFO_EXTENSION));

        if ($extension !== self::ALLOWED_EXTENSION) {
            throw new LocalizedException(
                __('Only .json files can be imported. "%1" was uploaded instead.', $fileName)
            );
        }

        if ($fileSize <= 0) {
            throw new LocalizedException(__('The uploaded file is empty.'));
        }

        if ($fileSize > self::MAX_FILE_SIZE) {
            throw new LocalizedException(
                __(
                    'The uploaded file is %1 MB, over the %2 MB limit. Export the content in smaller batches.',
                    round($fileSize / 1024 / 1024, 2),
                    round(self::MAX_FILE_SIZE / 1024 / 1024)
                )
            );
        }
    }

    /**
     * Decode and validate the envelope and every entity in it.
     *
     * @return array<string, mixed> the decoded envelope
     * @throws LocalizedException
     */
    public function parse(string $rawJson): array
    {
        try {
            $envelope = json_decode($rawJson, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new LocalizedException(
                __('The file is not valid JSON: %1', $e->getMessage())
            );
        }

        if (!is_array($envelope)) {
            throw new LocalizedException(__('The file does not contain a CMS content export.'));
        }

        $this->validateFormatVersion($envelope);
        $this->validateEntities($envelope);

        return $envelope;
    }

    /**
     * Refuse formats this module was not written against, instead of failing halfway through.
     *
     * @param array<string, mixed> $envelope
     * @throws LocalizedException
     */
    private function validateFormatVersion(array $envelope): void
    {
        if (!array_key_exists('format_version', $envelope)) {
            throw new LocalizedException(
                __('The file has no "format_version" and cannot be imported.')
            );
        }

        if ($envelope['format_version'] !== EnvelopeBuilder::FORMAT_VERSION) {
            throw new LocalizedException(
                __(
                    'This file uses format version %1, and this module only reads version %2.',
                    is_scalar($envelope['format_version']) ? (string)$envelope['format_version'] : 'unknown',
                    EnvelopeBuilder::FORMAT_VERSION
                )
            );
        }
    }

    /**
     * Every entity needs a type this module can import and an identifier to match on.
     *
     * @param array<string, mixed> $envelope
     * @throws LocalizedException
     */
    private function validateEntities(array $envelope): void
    {
        if (!isset($envelope['entities']) || !is_array($envelope['entities'])) {
            throw new LocalizedException(__('The file has no "entities" list.'));
        }

        if ($envelope['entities'] === []) {
            throw new LocalizedException(__('The file has no entities to import.'));
        }

        foreach ($envelope['entities'] as $index => $entity) {
            $position = (int)$index + 1;

            if (!is_array($entity)) {
                throw new LocalizedException(__('Entity #%1 is not a valid object.', $position));
            }

            if (empty($entity['entity_type'])) {
                throw new LocalizedException(__('Entity #%1 has no "entity_type".', $position));
            }

            if (!in_array($entity['entity_type'], self::SUPPORTED_ENTITY_TYPES, true)) {
                throw new LocalizedException(
                    __(
                        'Entity #%1 has the unsupported type "%2". Supported types are: %3.',
                        $position,
                        (string)$entity['entity_type'],
                        implode(', ', self::SUPPORTED_ENTITY_TYPES)
                    )
                );
            }

            if (empty($entity['identifier'])) {
                throw new LocalizedException(__('Entity #%1 has no "identifier".', $position));
            }
        }
    }
}
