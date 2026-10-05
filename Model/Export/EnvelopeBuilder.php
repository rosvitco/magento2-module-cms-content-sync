<?php
/**
 * Copyright © Rosvit. All rights reserved.
 */
declare(strict_types=1);

namespace Rosvit\CmsContentSync\Model\Export;

use Magento\Backend\Model\Auth\Session as AuthSession;
use Magento\Framework\Stdlib\DateTime\DateTime;
use Magento\Framework\UrlInterface;
use Magento\Store\Model\StoreManagerInterface;

/**
 * Wraps exported entities into the envelope described by the exchange format.
 *
 * Only "format_version" carries meaning for the import; the other envelope keys are
 * informative and are shown in the preview so the operator knows where the file came from.
 */
class EnvelopeBuilder
{
    /**
     * Version of the exchange format this module reads and writes.
     */
    public const FORMAT_VERSION = 1;

    public function __construct(
        private readonly DateTime $dateTime,
        private readonly StoreManagerInterface $storeManager,
        private readonly AuthSession $authSession
    ) {
    }

    /**
     * Build the envelope around already serialized entities.
     *
     * @param array<int, array<string, mixed>> $entities
     * @return array<string, mixed>
     */
    public function build(array $entities): array
    {
        return [
            'format_version' => self::FORMAT_VERSION,
            'exported_at'    => $this->exportedAt(),
            'exported_from'  => $this->exportedFrom(),
            'exported_by'    => $this->exportedBy(),
            'entities'       => array_values($entities),
        ];
    }

    /**
     * Encode an envelope as the JSON that gets downloaded.
     *
     * Pretty printed on purpose: these files are read, diffed and versioned by hand.
     *
     * @param array<string, mixed> $envelope
     */
    public function toJson(array $envelope): string
    {
        return (string)json_encode(
            $envelope,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        );
    }

    /**
     * Name of the downloaded file, stamped with the export time.
     */
    public function fileName(): string
    {
        return 'cms-content-export-' . $this->dateTime->gmtDate('Ymd-His') . '.json';
    }

    /**
     * Export timestamp, in UTC and ISO 8601.
     */
    private function exportedAt(): string
    {
        return $this->dateTime->gmtDate('c');
    }

    /**
     * Host of the environment the export was produced in.
     */
    private function exportedFrom(): string
    {
        $baseUrl = $this->storeManager->getStore()->getBaseUrl(UrlInterface::URL_TYPE_WEB);

        return parse_url($baseUrl, PHP_URL_HOST) ?: $baseUrl;
    }

    /**
     * Admin user who produced the export, when the session still holds one.
     */
    private function exportedBy(): string
    {
        return (string)($this->authSession->getUser()?->getUserName() ?? '');
    }
}
