<?php

declare(strict_types=1);

namespace Northrook\StylesheetMinifier\Syntax;

use Northrook\Exception\CompileException;
use Support\Str;

/**
 * ```
 * selector {
 *     property: value;
 * }
 * ```.
 *
 * @internal
 * @author Martin Nielsen <mn@northrook.com>
 */
final class Rule
{
    /** @var non-empty-string */
    public readonly string $selector;

    public readonly array $declarations;

    public function __construct(
        string $selector,
        string $declaration,
    ) {
        $this->selector( $selector );

        $this->declarations( $declaration );
    }

    private function selector( string $string ) : void
    {
        $selector       = \trim( $string );
        $selector       = \preg_replace( '/\s*\+\s*/m', '+', $selector );
        $this->selector = $selector;
    }

    private function declarations( string $declaration ) : void
    {
        $declarations = [];

        $exploded = $this->explode( $declaration );

        foreach ( $exploded as $declaration ) {
            if ( false === \str_contains( $declaration, ':' ) ) {
                throw new CompileException( 'Error parsing Stylesheet', $exploded );
            }

            [$selector, $value] = \explode( ':', $declaration );

            $value = Str::replaceEach(
                [
                    '0.' => '.',
                ],
                $value,
            );

            $declarations[$selector] = $value;
        }

        $this->declarations = $declarations;
    }

    private function explode( string $declaration ) : array
    {
        return \array_filter(
            \explode( ';', \trim( $declaration, " \n\r\t\v\0{}" ) ),
        );
    }
}