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
use Magento\Framework\Filesystem\Driver\File as FileDriver;
use Rosvit\CmsContentSync\Model\Import\Parser;
use Rosvit\CmsContentSync\Model\StagingStorage;

/**
 * Takes the uploaded exchange file, validates it and stages it for preview.
 *
 * Nothing is written to the catalog here: the upload only parks a validated file and
 * remembers its token, so the operator still gets a preview before anything changes.
 */
class Upload extends Action implements HttpPostActionInterface
{
    public const ADMIN_RESOURCE = 'Rosvit_CmsContentSync::import';

    /**
     * Name of the file input in the upload form.
     */
    private const FILE_INPUT = 'import_file';

    public function __construct(
        Context $context,
        private readonly Parser $parser,
        private readonly StagingStorage $stagingStorage,
        private readonly Session $session,
        private readonly FileDriver $fileDriver
    ) {
        parent::__construct($context);
    }

    public function execute(): ResultInterface
    {
        try {
            $file = $this->getRequest()->getFiles(self::FILE_INPUT);

            $this->assertUploadSucceeded(is_array($file) ? $file : []);
            $this->parser->validateFile((string)$file['name'], (int)$file['size']);

            $rawJson = $this->fileDriver->fileGetContents($file['tmp_name']);
            $envelope = $this->parser->parse($rawJson);

            $this->discardStagedFile();
            $this->session->setData(
                StagingStorage::SESSION_TOKEN_KEY,
                $this->stagingStorage->save($rawJson)
            );

            $this->messageManager->addSuccessMessage(
                __('%1 entities were read from the file. Review them before importing.', count($envelope['entities']))
            );
        } catch (LocalizedException $e) {
            $this->messageManager->addErrorMessage($e->getMessage());
        } catch (\Exception $e) {
            $this->messageManager->addExceptionMessage($e, __('The file could not be uploaded.'));
        }

        return $this->resultRedirectFactory->create()->setPath('cmscontentsync/import/index');
    }

    /**
     * Turn PHP's upload error codes into messages the operator can act on.
     *
     * @param array<string, mixed> $file
     * @throws LocalizedException
     */
    private function assertUploadSucceeded(array $file): void
    {
        if (!isset($file['tmp_name'], $file['name'], $file['size'], $file['error'])) {
            throw new LocalizedException(__('Please choose a file to upload.'));
        }

        $error = (int)$file['error'];

        if ($error === UPLOAD_ERR_NO_FILE) {
            throw new LocalizedException(__('Please choose a file to upload.'));
        }

        if ($error === UPLOAD_ERR_INI_SIZE || $error === UPLOAD_ERR_FORM_SIZE) {
            throw new LocalizedException(
                __(
                    'The file is larger than this server accepts for uploads (%1). Export the content in smaller batches.',
                    ini_get('upload_max_filesize')
                )
            );
        }

        if ($error !== UPLOAD_ERR_OK) {
            throw new LocalizedException(__('The file could not be uploaded. Please try again.'));
        }

        // phpcs:ignore Magento2.Security.InsecureFunction
        if (!is_uploaded_file($file['tmp_name'])) {
            throw new LocalizedException(__('The uploaded file could not be read.'));
        }
    }

    /**
     * Drop whatever was staged before, so a new upload always replaces the previous one.
     */
    private function discardStagedFile(): void
    {
        $token = $this->session->getData(StagingStorage::SESSION_TOKEN_KEY);

        if (is_string($token) && $token !== '') {
            $this->stagingStorage->delete($token);
        }

        $this->session->unsetData(StagingStorage::SESSION_TOKEN_KEY);
    }
}
