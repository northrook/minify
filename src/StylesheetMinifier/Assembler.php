<?php

declare(strict_types=1);

namespace Northrook\StylesheetMinifier;

use Support\Arr;

/**
 * @internal
 * @author Martin Nielsen <mn@northrook.com>
 */
final class Assembler
{
    private string $stylesheet = '';

    public function __construct(
        private array $rules,
        private bool  $pretty = false,
        private bool  $allowCharset = false,
    ) {}

    final public function build() : Assembler
    {
        $this->rules = $this->combineDeclarations( $this->rules );

        foreach ( $this->rules as $selector => $rule ) {
            // if ( $this->allowCharset === false && $selector === '@charset' ) {
            //     continue;
            // }

            if ( '@charset' === $selector || '@import' === $selector ) {
                $this->stylesheet .= $selector.'"'.$rule.'";';

                continue;
            }

            $declaration = $this->consumeRule( $selector, $rule );

            $this->stylesheet .= $declaration;
        }

        return $this;
    }

    final public function toString() : string
    {
        return $this->stylesheet;
    }

    final protected function consumeRule( string $selector, mixed $rule ) : string
    {
        $declaration = '{';

        foreach ( $rule as $property => $value ) {
            if ( \is_string( $value ) ) {
                $declaration .= "{$property}:{$value};";

                continue;
            }
            if ( \is_array( $value ) ) {
                $declaration .= $this->consumeRule( $property, $value );

                continue;
            }
            // dump( $property, $value );

        }

        $declaration .= '}';

        // dump( $declarations );

        return "{$selector}{$declaration}";
    }

    private function combineDeclarations( array $declaration ) : array
    {
        $merged = [];

        foreach ( $declaration as $selector => $rules ) {
            $merge = \array_search( $rules, $merged, true );

            // dump( $selector );
            if ( $merge ) {
                $combined = "{$merge}, {$selector}";
                $merged   = Arr::replaceKey( $merged, $merge, $combined );

                unset( $merged[$selector] ); // ! unset current key
            }
            else {
                $merged[$selector] = $rules;
            }
        }

        return $merged;
    }
}