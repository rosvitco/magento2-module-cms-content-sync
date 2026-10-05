<?php
/**
 * Copyright © Rosvit. All rights reserved.
 */
declare(strict_types=1);

namespace Rosvit\CmsContentSync\Model;

use Magento\Cms\Api\BlockRepositoryInterface;
use Magento\Framework\Exception\LocalizedException;
use Rosvit\CmsContentSync\Model\Export\BlockExporter;

/**
 * Rewrites CMS Block references inside content between block ids and block identifiers.
 *
 * Block ids do not match across environments, so the exchange format carries identifiers:
 * on export every numeric reference becomes the identifier of the block it points to, and
 * on import every identifier is turned back into the id that block has in this environment.
 *
 * Covered references:
 *  - {{widget type="Magento\Cms\Block\Widget\Block" block_id="..."}} (also Page Builder)
 *  - {{block class="Magento\Cms\Block\Block" block_id="..."}}
 *  - {{block id="..."}}
 *
 * A reference that cannot be resolved on import keeps the identifier: Magento also loads
 * blocks by identifier, so the content starts working as soon as that block is created.
 */
class BlockReferenceResolver
{
    /**
     * A {{widget ...}} or {{block ...}} directive, with its parameters.
     */
    private const DIRECTIVE_PATTERN = '/\{\{(widget|block)\s+(.*?)\}\}/si';

    /**
     * The block_id parameter, or the id parameter of a {{block}} directive.
     * Quotes may be plain or HTML encoded, as Page Builder sometimes stores them.
     */
    private const ID_PARAM_PATTERN = '/(?<![\w-])(block_id|id)(\s*=\s*)("|\'|&quot;)(.*?)\3/si';

    /**
     * Class names (in type= or class=) that render a CMS Block by id.
     */
    private const CMS_BLOCK_CLASS_PATTERN = '/(type|class)\s*=\s*(?:"|\'|&quot;)\\\\*Magento\\\\+Cms\\\\+Block\\\\+(Widget\\\\+)?Block(?:"|\'|&quot;)/i';

    /**
     * @var array<int, string|null> block id => identifier, cached for the request
     */
    private array $identifiersById = [];

    public function __construct(
        private readonly BlockRepositoryInterface $blockRepository,
        private readonly EntityLocator $entityLocator
    ) {
    }

    /**
     * Replace block ids with block identifiers, for export.
     */
    public function toPortable(?string $content): ?string
    {
        if ($content === null || $content === '') {
            return $content;
        }

        return $this->rewrite($content, function (string $value): string {
            if (!ctype_digit($value)) {
                return $value;
            }

            $identifier = $this->identifierById((int)$value);

            // An identifier made only of digits would read as an id on import: keep the id.
            if ($identifier === null || $identifier === '' || ctype_digit($identifier)) {
                return $value;
            }

            return $identifier;
        });
    }

    /**
     * Replace block identifiers with the ids they have in this environment, for import.
     *
     * @param int[] $storeIds store scope of the entity that holds the content
     * @return array{content: ?string, missing: string[]} missing lists unresolved identifiers
     */
    public function toLocal(?string $content, array $storeIds): array
    {
        $missing = [];

        if ($content === null || $content === '') {
            return ['content' => $content, 'missing' => $missing];
        }

        $content = $this->rewrite($content, function (string $value) use ($storeIds, &$missing): string {
            if ($value === '' || ctype_digit($value)) {
                return $value;
            }

            $blockId = $this->localBlockId($value, $storeIds);

            if ($blockId === null) {
                $missing[] = $value;

                return $value;
            }

            return (string)$blockId;
        });

        return ['content' => $content, 'missing' => array_values(array_unique($missing))];
    }

    /**
     * Identifiers referenced in the content that do not exist in this environment.
     *
     * Nothing is written: this is what the preview uses to warn before importing.
     *
     * @param int[] $storeIds store scope of the entity that holds the content
     * @return string[]
     */
    public function findMissing(?string $content, array $storeIds): array
    {
        return $this->toLocal($content, $storeIds)['missing'];
    }

    /**
     * Run a callback over the value of every CMS Block reference in the content.
     *
     * @param callable(string): string $replace
     */
    private function rewrite(string $content, callable $replace): string
    {
        return (string)preg_replace_callback(
            self::DIRECTIVE_PATTERN,
            function (array $directive) use ($replace): string {
                $name = strtolower($directive[1]);
                $params = $directive[2];

                if (!$this->referencesCmsBlock($name, $params)) {
                    return $directive[0];
                }

                // Only a class-less {{block}} reads "id" as the block; otherwise it is a layout name.
                $idIsBlock = $name === 'block' && !$this->hasClassParam($params);

                $params = (string)preg_replace_callback(
                    self::ID_PARAM_PATTERN,
                    static function (array $param) use ($idIsBlock, $replace): string {
                        if (strtolower($param[1]) === 'id' && !$idIsBlock) {
                            return $param[0];
                        }

                        return $param[1] . $param[2] . $param[3] . $replace(trim($param[4])) . $param[3];
                    },
                    $params
                );

                return '{{' . $directive[1] . ' ' . $params . '}}';
            },
            $content
        );
    }

    /**
     * Whether a directive renders a CMS Block, as opposed to any other widget or block class.
     */
    private function referencesCmsBlock(string $name, string $params): bool
    {
        if (preg_match(self::CMS_BLOCK_CLASS_PATTERN, $params)) {
            return true;
        }

        // {{block id="..."}} without a class is rendered by Magento\Cms\Block\Block.
        return $name === 'block' && !$this->hasClassParam($params);
    }

    private function hasClassParam(string $params): bool
    {
        return (bool)preg_match('/(?<![\w-])class\s*=/i', $params);
    }

    private function identifierById(int $blockId): ?string
    {
        if (!array_key_exists($blockId, $this->identifiersById)) {
            try {
                $this->identifiersById[$blockId] = (string)$this->blockRepository->getById($blockId)->getIdentifier();
            } catch (LocalizedException) {
                // A dangling reference in the source environment: leave it as it is.
                $this->identifiersById[$blockId] = null;
            }
        }

        return $this->identifiersById[$blockId];
    }

    /**
     * @param int[] $storeIds
     */
    private function localBlockId(string $identifier, array $storeIds): ?int
    {
        $block = $this->entityLocator->find(BlockExporter::ENTITY_TYPE, $identifier, $storeIds);

        return $block === null ? null : (int)$block->getId();
    }
}
