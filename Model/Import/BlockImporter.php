<?php
/**
 * Copyright © Rosvit. All rights reserved.
 */
declare(strict_types=1);

namespace Rosvit\CmsContentSync\Model\Import;

use Magento\Cms\Api\BlockRepositoryInterface;
use Magento\Cms\Api\Data\BlockInterface;
use Magento\Cms\Api\Data\BlockInterfaceFactory;
use Magento\Framework\Exception\LocalizedException;
use Rosvit\CmsContentSync\Model\BlockReferenceResolver;
use Rosvit\CmsContentSync\Model\EntityLocator;
use Rosvit\CmsContentSync\Model\Export\BlockExporter;
use Rosvit\CmsContentSync\Model\StoreCodeMapper;

/**
 * Writes one exported CMS Block into this environment.
 *
 * Every exported field is replaced; fields that are not exported (block_id, creation_time)
 * are left untouched.
 */
class BlockImporter
{
    public const RESULT_CREATED = 'created';
    public const RESULT_UPDATED = 'updated';

    public function __construct(
        private readonly BlockRepositoryInterface $blockRepository,
        private readonly BlockInterfaceFactory $blockFactory,
        private readonly EntityLocator $entityLocator,
        private readonly StoreCodeMapper $storeCodeMapper,
        private readonly BlockReferenceResolver $blockReferenceResolver
    ) {
    }

    /**
     * Create or overwrite the block described by this entity.
     *
     * CMS Block references in the content are mapped to the ids of this environment. The
     * ones that cannot be found are kept by identifier and reported in "missing_blocks".
     *
     * @param array<string, mixed> $entity
     * @return array{result: string, missing_blocks: string[]} result is RESULT_CREATED or RESULT_UPDATED
     * @throws LocalizedException
     */
    public function import(array $entity): array
    {
        $identifier = (string)($entity['identifier'] ?? '');

        if ($identifier === '') {
            throw new LocalizedException(__('The entity has no identifier.'));
        }

        $storeIds = $this->storeCodeMapper->codesToIds($this->storeCodes($entity));

        $existing = $this->entityLocator->find(BlockExporter::ENTITY_TYPE, $identifier, $storeIds);
        $result = $existing === null ? self::RESULT_CREATED : self::RESULT_UPDATED;

        $content = $this->blockReferenceResolver->toLocal(
            $this->nullableString($entity['content'] ?? null),
            $storeIds
        );

        /** @var BlockInterface $block */
        $block = $existing ?? $this->blockFactory->create();

        $block->setIdentifier($identifier)
            ->setTitle($this->nullableString($entity['title'] ?? null))
            ->setContent($content['content'])
            ->setIsActive((bool)($entity['is_active'] ?? false));

        $block->setData('stores', $storeIds);

        $this->blockRepository->save($block);

        return ['result' => $result, 'missing_blocks' => $content['missing']];
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

    private function nullableString(mixed $value): ?string
    {
        return $value === null ? null : (string)$value;
    }
}
