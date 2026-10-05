<?php
/**
 * Copyright © Rosvit. All rights reserved.
 */
declare(strict_types=1);

namespace Rosvit\CmsContentSync\Controller\Adminhtml\Import;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Backend\Model\Session;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\Controller\ResultInterface;
use Magento\Framework\Exception\LocalizedException;
use Rosvit\CmsContentSync\Model\Export\BlockExporter;
use Rosvit\CmsContentSync\Model\Export\PageExporter;
use Rosvit\CmsContentSync\Model\Import\BlockImporter;
use Rosvit\CmsContentSync\Model\Import\PageImporter;
use Rosvit\CmsContentSync\Model\StagingStorage;

/**
 * Imports the rows the operator confirmed in the preview.
 *
 * A row that fails does not abort the batch: the point of importing 40 pages is not to
 * lose 39 of them because one references a store view that is missing here.
 * Blocks are imported before pages, so a page can reference a block from the same file.
 */
class Process extends Action implements HttpPostActionInterface
{
    public const ADMIN_RESOURCE = 'Rosvit_CmsContentSync::import';

    public function __construct(
        Context $context,
        private readonly Session $session,
        private readonly StagingStorage $stagingStorage,
        private readonly PageImporter $pageImporter,
        private readonly BlockImporter $blockImporter
    ) {
        parent::__construct($context);
    }

    public function execute(): ResultInterface
    {
        try {
            $token = $this->session->getData(StagingStorage::SESSION_TOKEN_KEY);

            if (!is_string($token) || $token === '') {
                throw new LocalizedException(
                    __('There is no uploaded file to import. Please upload one first.')
                );
            }

            $envelope = $this->stagingStorage->load($token);
            $this->importEntities(
                is_array($envelope['entities'] ?? null) ? $envelope['entities'] : [],
                $this->selectedRows()
            );

            $this->stagingStorage->delete($token);
            $this->session->unsetData(StagingStorage::SESSION_TOKEN_KEY);
        } catch (LocalizedException $e) {
            $this->messageManager->addErrorMessage($e->getMessage());
        } catch (\Exception $e) {
            $this->messageManager->addExceptionMessage($e, __('The import could not be completed.'));
        }

        return $this->resultRedirectFactory->create()->setPath('cmscontentsync/import/index');
    }

    /**
     * Import every selected row, reporting what happened to each one.
     *
     * @param array<int, mixed> $entities
     * @param int[] $selectedRows
     */
    private function importEntities(array $entities, array $selectedRows): void
    {
        $created = 0;
        $updated = 0;
        $skipped = 0;
        $failed = 0;

        foreach ($this->blocksFirst($entities) as $index => $entity) {
            if (!in_array((int)$index, $selectedRows, true)) {
                $skipped++;
                continue;
            }

            $entity = is_array($entity) ? $entity : [];

            try {
                $outcome = $this->importEntity($entity);

                if ($outcome['result'] === PageImporter::RESULT_CREATED) {
                    $created++;
                } else {
                    $updated++;
                }

                $this->reportMissingBlocks($entity, $outcome['missing_blocks']);
            } catch (\Exception $e) {
                $failed++;
                $this->messageManager->addErrorMessage(
                    __(
                        '%1 "%2" was not imported: %3',
                        $entity['entity_type'] ?? __('Entity'),
                        $entity['identifier'] ?? __('unknown'),
                        $e->getMessage()
                    )
                );
            }
        }

        $summary = __(
            'Import finished: %1 created, %2 updated, %3 skipped, %4 with errors.',
            $created,
            $updated,
            $skipped,
            $failed
        );

        if ($failed > 0) {
            $this->messageManager->addWarningMessage($summary);

            return;
        }

        $this->messageManager->addSuccessMessage($summary);
    }

    /**
     * Hand one entity to the importer that knows its type.
     *
     * @param array<string, mixed> $entity
     * @return array{result: string, missing_blocks: string[]}
     * @throws LocalizedException
     */
    private function importEntity(array $entity): array
    {
        $entityType = (string)($entity['entity_type'] ?? '');

        if ($entityType === PageExporter::ENTITY_TYPE) {
            return $this->pageImporter->import($entity);
        }

        return $this->blockImporter->import($entity);
    }

    /**
     * Reorder the entities so blocks come before pages, keeping their original row ids.
     *
     * @param array<int, mixed> $entities
     * @return array<int, mixed>
     */
    private function blocksFirst(array $entities): array
    {
        $blocks = [];
        $others = [];

        foreach ($entities as $index => $entity) {
            if (is_array($entity) && ($entity['entity_type'] ?? '') === BlockExporter::ENTITY_TYPE) {
                $blocks[$index] = $entity;
            } else {
                $others[$index] = $entity;
            }
        }

        return $blocks + $others;
    }

    /**
     * Warn about CMS Block references that were kept by identifier because no block matched.
     *
     * @param array<string, mixed> $entity
     * @param string[] $missingBlocks
     */
    private function reportMissingBlocks(array $entity, array $missingBlocks): void
    {
        if ($missingBlocks === []) {
            return;
        }

        $this->messageManager->addWarningMessage(
            __(
                '%1 "%2" was imported, but these CMS blocks do not exist in this environment: %3. '
                . 'Import or create them and the content will pick them up by identifier.',
                $entity['entity_type'] ?? __('Entity'),
                $entity['identifier'] ?? __('unknown'),
                implode(', ', $missingBlocks)
            )
        );
    }

    /**
     * Row ids ticked in the preview.
     *
     * @return int[]
     */
    private function selectedRows(): array
    {
        $rows = $this->getRequest()->getParam('rows', []);

        if (!is_array($rows)) {
            return [];
        }

        return array_map('intval', $rows);
    }
}
