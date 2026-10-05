<?php
/**
 * Copyright © Rosvit. All rights reserved.
 */
declare(strict_types=1);

namespace Rosvit\CmsContentSync\Model\Import;

use Magento\Cms\Api\Data\BlockInterface;
use Magento\Cms\Api\Data\PageInterface;
use Magento\Framework\Exception\LocalizedException;
use Rosvit\CmsContentSync\Model\BlockReferenceResolver;
use Rosvit\CmsContentSync\Model\EntityLocator;
use Rosvit\CmsContentSync\Model\Export\BlockExporter;
use Rosvit\CmsContentSync\Model\Export\PageExporter;
use Rosvit\CmsContentSync\Model\StoreCodeMapper;

/**
 * Works out what importing each entity of a staged file would actually do.
 *
 * Nothing here writes: it answers "create, update, no change or error?" for every row,
 * so the operator confirms with the outcome already in front of them.
 */
class Preview
{
    public const STATUS_NEW = 'new';
    public const STATUS_UPDATE = 'update';
    public const STATUS_UNCHANGED = 'unchanged';
    public const STATUS_ERROR = 'error';

    public function __construct(
        private readonly EntityLocator $entityLocator,
        private readonly PageExporter $pageExporter,
        private readonly BlockExporter $blockExporter,
        private readonly StoreCodeMapper $storeCodeMapper,
        private readonly BlockReferenceResolver $blockReferenceResolver
    ) {
    }

    /**
     * Build one preview row per entity in the envelope.
     *
     * @param array<string, mixed> $envelope
     * @return array<int, array<string, mixed>>
     */
    public function build(array $envelope): array
    {
        $rows = [];
        $entities = is_array($envelope['entities'] ?? null) ? $envelope['entities'] : [];
        $blocksInFile = $this->blockIdentifiers($entities);

        foreach ($entities as $index => $entity) {
            $rows[] = $this->buildRow((int)$index, is_array($entity) ? $entity : [], $blocksInFile);
        }

        return $rows;
    }

    /**
     * Resolve a single entity against what already lives in this environment.
     *
     * @param array<string, mixed> $entity
     * @param string[] $blocksInFile identifiers of the blocks this same file carries
     * @return array<string, mixed>
     */
    private function buildRow(int $rowId, array $entity, array $blocksInFile): array
    {
        $storeCodes = $this->storeCodes($entity);

        $row = [
            'row_id'      => $rowId,
            'entity_type' => (string)($entity['entity_type'] ?? ''),
            'identifier'  => (string)($entity['identifier'] ?? ''),
            'title'       => (string)($entity['title'] ?? ''),
            'stores'      => implode(', ', $storeCodes),
            'status'      => self::STATUS_NEW,
            'message'     => '',
            'warnings'    => [],
        ];

        try {
            $storeIds = $this->storeCodeMapper->codesToIds($storeCodes);
        } catch (LocalizedException $e) {
            $row['status'] = self::STATUS_ERROR;
            $row['message'] = $e->getMessage();

            return $row;
        }

        try {
            $existing = $this->entityLocator->find($row['entity_type'], $row['identifier'], $storeIds);
        } catch (LocalizedException $e) {
            $row['status'] = self::STATUS_ERROR;
            $row['message'] = $e->getMessage();

            return $row;
        }

        $row['warnings'] = $this->blockWarnings($entity, $storeIds, $blocksInFile);

        if ($existing === null) {
            $row['status'] = self::STATUS_NEW;

            return $row;
        }

        $row['status'] = $this->hasChanges($existing, $entity)
            ? self::STATUS_UPDATE
            : self::STATUS_UNCHANGED;

        return $row;
    }

    /**
     * Explain which CMS Block references in the content have no block in this environment.
     *
     * A block that is missing here but travels in the same file is fine, as long as its row is
     * imported too: blocks are always imported before pages.
     *
     * @param array<string, mixed> $entity
     * @param int[] $storeIds
     * @param string[] $blocksInFile
     * @return string[]
     */
    private function blockWarnings(array $entity, array $storeIds, array $blocksInFile): array
    {
        $content = isset($entity['content']) ? (string)$entity['content'] : null;
        $missing = $this->blockReferenceResolver->findMissing($content, $storeIds);

        $inFile = array_values(array_intersect($missing, $blocksInFile));
        $notFound = array_values(array_diff($missing, $blocksInFile));

        $warnings = [];

        if ($notFound !== []) {
            $warnings[] = (string)__(
                'CMS blocks not found in this environment: %1. They will be kept by identifier until they exist.',
                implode(', ', $notFound)
            );
        }

        if ($inFile !== []) {
            $warnings[] = (string)__(
                'CMS blocks created by this same file: %1. Import their rows as well.',
                implode(', ', $inFile)
            );
        }

        return $warnings;
    }

    /**
     * Identifiers of the blocks carried in the file.
     *
     * @param array<int, mixed> $entities
     * @return string[]
     */
    private function blockIdentifiers(array $entities): array
    {
        $identifiers = [];

        foreach ($entities as $entity) {
            if (is_array($entity) && ($entity['entity_type'] ?? '') === BlockExporter::ENTITY_TYPE) {
                $identifiers[] = (string)($entity['identifier'] ?? '');
            }
        }

        return $identifiers;
    }

    /**
     * Store codes as written in the file, defaulting to All Store Views when absent.
     *
     * @param array<string, mixed> $entity
     * @return string[]
     */
    private function storeCodes(array $entity): array
    {
        $codes = $entity['store_codes'] ?? [];

        if (!is_array($codes) || $codes === []) {
            return [StoreCodeMapper::ALL_STORE_VIEWS_CODE];
        }

        return array_map('strval', $codes);
    }

    /**
     * Compare what is in this environment against what the file carries.
     *
     * The current entity is run through the same exporter that produced the file, so both
     * sides are compared in exactly the same shape.
     *
     * @param array<string, mixed> $entity
     * @throws LocalizedException
     */
    private function hasChanges(PageInterface|BlockInterface $existing, array $entity): bool
    {
        $current = $existing instanceof PageInterface
            ? $this->pageExporter->toArray($existing)
            : $this->blockExporter->toArray($existing);

        foreach ($current as $field => $currentValue) {
            if ($field === 'entity_type') {
                continue;
            }

            if ($field === 'store_codes') {
                $incomingCodes = $this->storeCodes($entity);
                sort($incomingCodes);
                sort($currentValue);

                if ($incomingCodes !== $currentValue) {
                    return true;
                }

                continue;
            }

            if ($this->normalize($field, $entity[$field] ?? null) !== $currentValue) {
                return true;
            }
        }

        return false;
    }

    /**
     * Cast an incoming value the way the exporter casts the outgoing one.
     */
    private function normalize(string $field, mixed $value): string|bool|null
    {
        if ($field === 'is_active') {
            return (bool)$value;
        }

        if ($field === 'sort_order') {
            return (string)($value ?? '0');
        }

        return $value === null ? null : (string)$value;
    }
}
