<?php

declare(strict_types=1);

namespace Northrook\StylesheetMinifier;

use LogicException;
use Northrook\StylesheetMinifier\Syntax\{Block, Rule, Statement};

/**
 * @internal
 * @author Martin Nielsen <mn@northrook.com>
 */
final class Parser
{
    private int $iteration = 0;

    private int $failsafe = 512;

    private int $level = 0;

    protected array $rules = [];

    protected bool $running = true;

    public function __construct(
        private string          $css,
        public readonly ?string $key = null,
    ) {
        $this->validateCssString();
        while ( $this->running ) {
            $this->matchNext();
        }
    }

    public function rules() : array
    {
        return $this->rules;
    }

    private function matchNext() : void
    {
        $this->iteration++;

        // Stop matching when the CSS is empty, or if the failsafe is reached
        if ( ! $this->css || $this->iteration > $this->failsafe ) {
            $this->running = false;
        }

        // If the next match character is closing,
        // it can only be a statement, or malformed.
        if ( $this->next( 'end' ) ) {
            $statement                = $this->extract( $this->next() );
            [$selector, $declaration] = \explode( ' ', $statement, 2 );
            $this->rules[$selector]   = new Statement( $selector, $declaration );
            return;
        }

        // If there are no groups to extract, bail early
        if ( ! $next = $this->extractRuleGroup() ) {
            return;
        }

        [$selector, $declaration] = \explode( '{', $next, 2 );
        $declaration              = \substr( $declaration, 0, \strripos( $declaration, '}' ) );

        if ( ! \str_contains( $declaration, '{' ) ) {
            $this->rules[$selector] = new Rule( $selector, $declaration );
        }
        else {
            $this->rules[$selector] = new Block(
                $selector,
                ( new Parser( $declaration ) )->rules(),
            );
        }
    }

    final protected function next( ?string $is = null )
    {
        $next = [
            'at'    => $this->match( '@' ),
            'open'  => $this->match( '{' ),
            'close' => $this->match( '}' ),
            'end'   => $this->match( ';' ),
        ];

        $next = \array_filter( $next );

        \asort( $next );

        return $is ? \key( $next ) == $is : \array_shift( $next );
    }

    final protected function match( string $string, int $offset = 0 ) : false|int
    {
        $match = \stripos( $this->css, $string, $offset );
        return ! $match ? $match : $match + 1;
    }

    final protected function extract( int $match ) : string
    {
        // $match++;
        $rule      = \substr( $this->css, 0, $match );
        $this->css = \substr( $this->css, $match );
        return $rule;
    }

    final protected function extractRuleGroup() : false|string
    {
        \preg_match( '/[^{]+\s*\{(?:[^{}]*|(?R))*}/s', $this->css, $extract );

        if ( ! $extract ) {
            $this->running = false;
            return false;
        }

        if ( \count( $extract ) > 1 ) {
            dump( $extract );
        }

        $length = \strlen( $extract[0] );

        $rule      = \substr( $this->css, 0, $length );
        $this->css = \substr( $this->css, $length );
        return $rule;
    }

    private function validateCssString( ?string $string = null ) : void
    {
        if (
            \substr_count( $string ?? $this->css, '{' )
            !== \substr_count( $string ?? $this->css, '}' )
        ) {
            throw new LogicException( 'Provided CSS does has an uneven block distribution.' );
        }
    }

    private function depth( string $string ) : int
    {
        $openBrackets = \substr_count( $string, '{' );
        if ( \substr_count( $string, '}' ) !== $openBrackets ) {
            throw new LogicException( 'Provided CSS does has an uneven block distribution.' );
        }
        return $openBrackets;
    }
}
