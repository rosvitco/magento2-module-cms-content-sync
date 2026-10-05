<?php
/**
 * Copyright © Rosvit. All rights reserved.
 */
declare(strict_types=1);

namespace Rosvit\CmsContentSync\Model\Import;

use Magento\Cms\Api\Data\PageInterface;
use Magento\Cms\Api\Data\PageInterfaceFactory;
use Magento\Cms\Api\PageRepositoryInterface;
use Magento\Framework\Exception\LocalizedException;
use Rosvit\CmsContentSync\Model\BlockReferenceResolver;
use Rosvit\CmsContentSync\Model\EntityLocator;
use Rosvit\CmsContentSync\Model\Export\PageExporter;
use Rosvit\CmsContentSync\Model\StoreCodeMapper;

/**
 * Writes one exported CMS Page into this environment.
 *
 * Every exported field is replaced, including the ones the file carries as null: the file
 * is the source of truth, so clearing a meta description has to be expressible.
 * Fields that are not exported (page_id, creation_time) are left untouched.
 */
class PageImporter
{
    public const RESULT_CREATED = 'created';
    public const RESULT_UPDATED = 'updated';

    public function __construct(
        private readonly PageRepositoryInterface $pageRepository,
        private readonly PageInterfaceFactory $pageFactory,
        private readonly EntityLocator $entityLocator,
        private readonly StoreCodeMapper $storeCodeMapper,
        private readonly BlockReferenceResolver $blockReferenceResolver
    ) {
    }

    /**
     * Create or overwrite the page described by this entity.
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

        $existing = $this->entityLocator->find(PageExporter::ENTITY_TYPE, $identifier, $storeIds);
        $result = $existing === null ? self::RESULT_CREATED : self::RESULT_UPDATED;

        $content = $this->blockReferenceResolver->toLocal(
            $this->nullableString($entity['content'] ?? null),
            $storeIds
        );

        /** @var PageInterface $page */
        $page = $existing ?? $this->pageFactory->create();

        $page->setIdentifier($identifier)
            ->setTitle($this->nullableString($entity['title'] ?? null))
            ->setPageLayout($this->nullableString($entity['page_layout'] ?? null))
            ->setMetaTitle($this->nullableString($entity['meta_title'] ?? null))
            ->setMetaKeywords($this->nullableString($entity['meta_keywords'] ?? null))
            ->setMetaDescription($this->nullableString($entity['meta_description'] ?? null))
            ->setContentHeading($this->nullableString($entity['content_heading'] ?? null))
            ->setContent($content['content'])
            ->setSortOrder((string)($entity['sort_order'] ?? '0'))
            ->setLayoutUpdateXml($this->nullableString($entity['layout_update_xml'] ?? null))
            ->setCustomTheme($this->nullableString($entity['custom_theme'] ?? null))
            ->setCustomRootTemplate($this->nullableString($entity['custom_root_template'] ?? null))
            ->setCustomLayoutUpdateXml($this->nullableString($entity['custom_layout_update_xml'] ?? null))
            ->setIsActive((bool)($entity['is_active'] ?? false));

        $page->setData('stores', $storeIds);

        $this->pageRepository->save($page);

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
