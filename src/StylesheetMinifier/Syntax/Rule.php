<?php

declare(strict_types=1);

namespace Support\StylesheetMinifier\Syntax;

use Core\Interface\DataInterface;
use RuntimeException;
use function Support\str_replace_each;

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

    /** @var array<string,string> */
    public array $declarations;

    public function __construct(
        string $selector,
        string $declaration,
    ) {
        $this->selector = $this->selector( $selector );

        $this->declarations = $this->declarations( $declaration );
    }

    protected function selector( string $string ) : string
    {
        return \preg_replace( '/\s*\+\s*/m', '+', \trim( $string ) );
    }

    /**
     * @param string $declaration
     *
     * @return array<string,string>
     */
    protected function declarations( string $declaration ) : array
    {
        /** @var array<string,string> $declarations */
        $declarations = [];

        $exploded = $this->explode( $declaration );

        foreach ( $exploded as $declaration ) {
            if ( \str_contains( $declaration, ':' ) === false ) {
                throw new RuntimeException( 'Error parsing Stylesheet: '.\print_r( $exploded, true ) );
            }

            $declaration = \str_replace( '\:', '≡', $declaration );

            // dump($declaration);
            [$selector, $value] = \explode( ':', $declaration );

            $selector                = (string) \str_replace( '≡', '\:', $selector );
            $declarations[$selector] = (string) str_replace_each(
                [
                    '0.' => '.',
                    '≡'  => '\:',
                ],
                $value,
            );
        }

        return $declarations;
    }

    /**
     * @param string $declaration
     *
     * @return string[]
     */
    private function explode( string $declaration ) : array
    {
        return \array_filter( \explode( ';', \trim( $declaration, " \n\r\t\v\0{}" ) ) );
    }
}
