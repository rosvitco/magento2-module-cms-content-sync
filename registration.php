<?php
/**
 * Copyright © Rosvit. All rights reserved.
 */
declare(strict_types=1);

use Magento\Framework\Component\ComponentRegistrar;

ComponentRegistrar::register(
    ComponentRegistrar::MODULE,
    'Rosvit_CmsContentSync',
    __DIR__
);
