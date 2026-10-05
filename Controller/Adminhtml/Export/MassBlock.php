<?php
/**
 * Copyright © Rosvit. All rights reserved.
 */
declare(strict_types=1);

namespace Rosvit\CmsContentSync\Controller\Adminhtml\Export;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Cms\Model\ResourceModel\Block\CollectionFactory;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\App\Response\Http\FileFactory;
use Magento\Framework\App\ResponseInterface;
use Magento\Framework\Controller\ResultInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Ui\Component\MassAction\Filter;
use Rosvit\CmsContentSync\Model\Export\BlockExporter;
use Rosvit\CmsContentSync\Model\Export\EnvelopeBuilder;

/**
 * Downloads the selected CMS blocks as a single JSON file.
 */
class MassBlock extends Action implements HttpPostActionInterface
{
    public const ADMIN_RESOURCE = 'Rosvit_CmsContentSync::export';

    public function __construct(
        Context $context,
        private readonly Filter $filter,
        private readonly CollectionFactory $collectionFactory,
        private readonly BlockExporter $blockExporter,
        private readonly EnvelopeBuilder $envelopeBuilder,
        private readonly FileFactory $fileFactory
    ) {
        parent::__construct($context);
    }

    public function execute(): ResultInterface|ResponseInterface
    {
        try {
            $collection = $this->filter->getCollection($this->collectionFactory->create());

            $entities = [];
            foreach ($collection as $block) {
                $entities[] = $this->blockExporter->toArray($block);
            }

            if ($entities === []) {
                throw new LocalizedException(__('No CMS blocks were selected to export.'));
            }

            $envelope = $this->envelopeBuilder->build($entities);

            return $this->fileFactory->create(
                $this->envelopeBuilder->fileName(),
                [
                    'type'  => 'string',
                    'value' => $this->envelopeBuilder->toJson($envelope),
                    'rm'    => true,
                ],
                DirectoryList::VAR_DIR,
                'application/json'
            );
        } catch (LocalizedException $e) {
            $this->messageManager->addErrorMessage($e->getMessage());
        } catch (\Exception $e) {
            $this->messageManager->addExceptionMessage($e, __('Could not export the selected CMS blocks.'));
        }

        return $this->resultRedirectFactory->create()->setPath('cms/block/index');
    }
}
