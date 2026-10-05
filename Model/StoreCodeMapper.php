<?php
/**
 * Copyright © Rosvit. All rights reserved.
 */
declare(strict_types=1);

namespace Rosvit\CmsContentSync\Model;

use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Store\Api\StoreRepositoryInterface;

/**
 * Translates store ids into portable store codes and back.
 *
 * Store ids do not match across environments, so the exchange format carries codes.
 * The "All Store Views" scope (store id 0) travels as the literal code "*", because its
 * real code is "admin", which reads like a mistake inside a content file.
 */
class StoreCodeMapper
{
    /**
     * Serialized code for store id 0 (All Store Views).
     */
    public const ALL_STORE_VIEWS_CODE = '*';

    public function __construct(
        private readonly StoreRepositoryInterface $storeRepository
    ) {
    }

    /**
     * Translate store ids into store codes, for export.
     *
     * @param int[]|string[] $ids
     * @return string[]
     * @throws LocalizedException when an id has no store in this environment
     */
    public function idsToCodes(array $ids): array
    {
        $codes = [];

        foreach ($ids as $id) {
            $id = (int)$id;

            if ($id === 0) {
                $codes[] = self::ALL_STORE_VIEWS_CODE;
                continue;
            }

            try {
                $codes[] = $this->storeRepository->getById($id)->getCode();
            } catch (NoSuchEntityException) {
                throw new LocalizedException(
                    __('The store view with id "%1" does not exist in this environment.', $id)
                );
            }
        }

        return array_values(array_unique($codes));
    }

    /**
     * Translate store codes into store ids, for import.
     *
     * @param string[] $codes
     * @return int[]
     * @throws LocalizedException when a code has no store in this environment
     */
    public function codesToIds(array $codes): array
    {
        $ids = [];

        foreach ($codes as $code) {
            $code = (string)$code;

            if ($code === self::ALL_STORE_VIEWS_CODE) {
                $ids[] = 0;
                continue;
            }

            try {
                $ids[] = (int)$this->storeRepository->get($code)->getId();
            } catch (NoSuchEntityException) {
                throw new LocalizedException(
                    __('The store view with code "%1" does not exist in this environment.', $code)
                );
            }
        }

        return array_values(array_unique($ids));
    }
}
