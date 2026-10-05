<?php
/**
 * Copyright © Rosvit. All rights reserved.
 */
declare(strict_types=1);

namespace Rosvit\CmsContentSync\Controller\Adminhtml\Import;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Backend\Model\Session;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\Controller\ResultInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\View\Result\PageFactory;
use Rosvit\CmsContentSync\Model\Import\Preview;
use Rosvit\CmsContentSync\Model\StagingStorage;

/**
 * Renders the upload form and, when a file is staged, the preview of what it would do.
 */
class Index extends Action implements HttpGetActionInterface
{
    public const ADMIN_RESOURCE = 'Rosvit_CmsContentSync::import';

    public function __construct(
        Context $context,
        private readonly PageFactory $resultPageFactory,
        private readonly Session $session,
        private readonly StagingStorage $stagingStorage,
        private readonly Preview $preview
    ) {
        parent::__construct($context);
    }

    public function execute(): ResultInterface
    {
        $resultPage = $this->resultPageFactory->create();
        $resultPage->setActiveMenu('Rosvit_CmsContentSync::sync');
        $resultPage->getConfig()->getTitle()->prepend(__('CMS Content Sync'));

        $block = $resultPage->getLayout()->getBlock('cmscontentsync.import');

        if ($block) {
            $block->setData('preview', $this->stagedPreview());
        }

        return $resultPage;
    }

    /**
     * Load the staged file and resolve it into preview rows.
     *
     * @return array{envelope: array<string, mixed>, rows: array<int, array<string, mixed>>}|null
     */
    private function stagedPreview(): ?array
    {
        $token = $this->session->getData(StagingStorage::SESSION_TOKEN_KEY);

        if (!is_string($token) || $token === '') {
            return null;
        }

        try {
            $envelope = $this->stagingStorage->load($token);

            return [
                'envelope' => $envelope,
                'rows'     => $this->preview->build($envelope),
            ];
        } catch (LocalizedException $e) {
            $this->session->unsetData(StagingStorage::SESSION_TOKEN_KEY);
            $this->messageManager->addErrorMessage($e->getMessage());

            return null;
        }
    }
}
