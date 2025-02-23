<?php

declare(strict_types=1);

namespace Support\StylesheetMinifier\Syntax;

use Core\Interface\DataInterface;

/**
 * ```
 * block {
 *     selector {
 *         property: value;
 *     }
 * }
 * ```.
 *
 * @internal
 * @author Martin Nielsen <mn@northrook.com>
 */
final readonly class Block implements DataInterface
{
    /**
     * @param string         $selector
     * @param Block[]|Rule[] $declarations
     */
    public function __construct(
        public string $selector,
        public array  $declarations,
    ) {}
}
