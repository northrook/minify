<?php

declare(strict_types=1);

namespace Northrook\StylesheetMinifier\Syntax;

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
final class Block
{
    /**
     * @param string         $selector
     * @param Block[]|Rule[] $declarations
     */
    public function __construct(
        public readonly string $selector,
        public readonly array  $declarations,
    ) {}
}