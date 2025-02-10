<?php

declare(strict_types=1);

namespace Northrook\StylesheetMinifier\Syntax;

use Core\Interface\DataInterface;
use Support\Str;
use RuntimeException;

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
final readonly class Rule implements DataInterface
{
    /** @var non-empty-string */
    public string $selector;

    public array $declarations;

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
            if ( \str_contains( $declaration, ':' ) === false ) {
                throw new RuntimeException( 'Error parsing Stylesheet: '.\print_r( $exploded, true ) );
            }

            $declaration = \str_replace( '\:', '≡', $declaration );

            // dump($declaration);
            [$selector, $value] = \explode( ':', $declaration );

            // dump($selector, $value);

            $value = Str::replaceEach(
                [
                    '0.' => '.',
                    '≡'  => '\:',
                ],
                $value,
            );

            $selector                = \str_replace( '≡', '\:', $selector );
            $declarations[$selector] = $value;
        }

        // dump( $declarations );
        $this->declarations = $declarations;
    }

    private function explode( string $declaration ) : array
    {
        return \array_filter(
            \explode( ';', \trim( $declaration, " \n\r\t\v\0{}" ) ),
        );
    }
}
