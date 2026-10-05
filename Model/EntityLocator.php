<?php
/**
 * Copyright © Rosvit. All rights reserved.
 */
declare(strict_types=1);

namespace Rosvit\CmsContentSync\Model;

use Magento\Cms\Api\BlockRepositoryInterface;
use Magento\Cms\Api\Data\BlockInterface;
use Magento\Cms\Api\Data\PageInterface;
use Magento\Cms\Api\PageRepositoryInterface;
use Magento\Cms\Model\ResourceModel\Block as BlockResource;
use Magento\Cms\Model\ResourceModel\Page as PageResource;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Rosvit\CmsContentSync\Model\Export\PageExporter;

/**
 * Finds the entity an imported row would overwrite.
 *
 * The preview and the import must agree on this, or the preview would promise "create"
 * while the import silently overwrites something else. Both go through here.
 */
class EntityLocator
{
    public function __construct(
        private readonly PageRepositoryInterface $pageRepository,
        private readonly BlockRepositoryInterface $blockRepository,
        private readonly PageResource $pageResource,
        private readonly BlockResource $blockResource,
        private readonly SearchCriteriaBuilder $searchCriteriaBuilder
    ) {
    }

    /**
     * Look for an entity with this identifier whose store scope overlaps the target one.
     *
     * The identifier alone is not enough: the same identifier can legitimately exist more
     * than once, in different store scopes.
     *
     * @param int[] $storeIds
     */
    public function find(string $entityType, string $identifier, array $storeIds): PageInterface|BlockInterface|null
    {
        $isPage = $entityType === PageExporter::ENTITY_TYPE;

        $searchCriteria = $this->searchCriteriaBuilder
            ->addFilter('identifier', $identifier)
            ->create();

        $candidates = $isPage
            ? $this->pageRepository->getList($searchCriteria)->getItems()
            : $this->blockRepository->getList($searchCriteria)->getItems();

        foreach ($candidates as $candidate) {
            $candidateStoreIds = $isPage
                ? $this->pageResource->lookupStoreIds((int)$candidate->getId())
                : $this->blockResource->lookupStoreIds((int)$candidate->getId());

            if ($this->storesOverlap(array_map('intval', $candidateStoreIds), $storeIds)) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * Two scopes overlap when they share a store view, or when either covers all of them.
     *
     * @param int[] $a
     * @param int[] $b
     */
    private function storesOverlap(array $a, array $b): bool
    {
        if (in_array(0, $a, true) || in_array(0, $b, true)) {
            return true;
        }

        return array_intersect($a, $b) !== [];
    }
}
