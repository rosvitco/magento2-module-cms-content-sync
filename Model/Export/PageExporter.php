<?php
/**
 * Copyright © Rosvit. All rights reserved.
 */
declare(strict_types=1);

namespace Rosvit\CmsContentSync\Model\Export;

use Magento\Cms\Api\Data\PageInterface;
use Magento\Cms\Model\ResourceModel\Page as PageResource;
use Magento\Framework\Exception\LocalizedException;
use Rosvit\CmsContentSync\Model\BlockReferenceResolver;
use Rosvit\CmsContentSync\Model\StoreCodeMapper;

/**
 * Serializes a CMS Page into the portable array shape of the exchange format.
 *
 * page_id, creation_time and update_time are deliberately left out: they belong to the
 * environment the page was exported from, not to the content itself. For the same reason,
 * CMS Block references inside the content travel by identifier instead of by id.
 */
class PageExporter
{
    /**
     * Value of the "entity_type" key for pages.
     */
    public const ENTITY_TYPE = 'cms_page';

    public function __construct(
        private readonly PageResource $pageResource,
        private readonly StoreCodeMapper $storeCodeMapper,
        private readonly BlockReferenceResolver $blockReferenceResolver
    ) {
    }

    /**
     * Build the exportable representation of a page.
     *
     * @return array<string, mixed>
     * @throws LocalizedException when a store id assigned to the page no longer exists
     */
    public function toArray(PageInterface $page): array
    {
        $storeIds = $this->pageResource->lookupStoreIds((int)$page->getId());

        return [
            'entity_type'              => self::ENTITY_TYPE,
            'identifier'               => (string)$page->getIdentifier(),
            'title'                    => $this->nullableString($page->getTitle()),
            'page_layout'              => $this->nullableString($page->getPageLayout()),
            'meta_title'               => $this->nullableString($page->getMetaTitle()),
            'meta_keywords'            => $this->nullableString($page->getMetaKeywords()),
            'meta_description'         => $this->nullableString($page->getMetaDescription()),
            'content_heading'          => $this->nullableString($page->getContentHeading()),
            'content'                  => $this->blockReferenceResolver->toPortable($this->nullableString($page->getContent())),
            'sort_order'               => (string)$page->getSortOrder(),
            'layout_update_xml'        => $this->nullableString($page->getLayoutUpdateXml()),
            'custom_theme'             => $this->nullableString($page->getCustomTheme()),
            'custom_root_template'     => $this->nullableString($page->getCustomRootTemplate()),
            'custom_layout_update_xml' => $this->nullableString($page->getCustomLayoutUpdateXml()),
            'is_active'                => (bool)$page->isActive(),
            'store_codes'              => $this->storeCodeMapper->idsToCodes($storeIds),
        ];
    }

    /**
     * Keep nulls as nulls instead of collapsing them into empty strings.
     *
     * The import replaces every exported field, so a null has to survive the round trip
     * for "clear this meta description" to be expressible.
     */
    private function nullableString(mixed $value): ?string
    {
        return $value === null ? null : (string)$value;
    }
}
