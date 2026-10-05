<?php
/**
 * Copyright © Rosvit. All rights reserved.
 */
declare(strict_types=1);

namespace Rosvit\CmsContentSync\Model\Export;

use Magento\Cms\Api\Data\BlockInterface;
use Magento\Cms\Model\ResourceModel\Block as BlockResource;
use Magento\Framework\Exception\LocalizedException;
use Rosvit\CmsContentSync\Model\BlockReferenceResolver;
use Rosvit\CmsContentSync\Model\StoreCodeMapper;

/**
 * Serializes a CMS Block into the portable array shape of the exchange format.
 *
 * block_id, creation_time and update_time are deliberately left out: they belong to the
 * environment the block was exported from, not to the content itself. For the same reason,
 * CMS Block references inside the content travel by identifier instead of by id.
 */
class BlockExporter
{
    /**
     * Value of the "entity_type" key for blocks.
     */
    public const ENTITY_TYPE = 'cms_block';

    public function __construct(
        private readonly BlockResource $blockResource,
        private readonly StoreCodeMapper $storeCodeMapper,
        private readonly BlockReferenceResolver $blockReferenceResolver
    ) {
    }

    /**
     * Build the exportable representation of a block.
     *
     * @return array<string, mixed>
     * @throws LocalizedException when a store id assigned to the block no longer exists
     */
    public function toArray(BlockInterface $block): array
    {
        $storeIds = $this->blockResource->lookupStoreIds((int)$block->getId());

        return [
            'entity_type' => self::ENTITY_TYPE,
            'identifier'  => (string)$block->getIdentifier(),
            'title'       => $this->nullableString($block->getTitle()),
            'content'     => $this->blockReferenceResolver->toPortable($this->nullableString($block->getContent())),
            'is_active'   => (bool)$block->isActive(),
            'store_codes' => $this->storeCodeMapper->idsToCodes($storeIds),
        ];
    }

    /**
     * Keep nulls as nulls instead of collapsing them into empty strings.
     *
     * The import replaces every exported field, so a null has to survive the round trip.
     */
    private function nullableString(mixed $value): ?string
    {
        return $value === null ? null : (string)$value;
    }
}
