<?php

declare(strict_types=1);

namespace Support;

use Support\Minify\{JavaScriptMinifier, StylesheetMinifier};
use Psr\Log\{LoggerAwareInterface, LoggerAwareTrait};
use Stringable;

// .. CSS
//    Merge and minify multiple CSS files or strings into one cohesive CSS string

// :: JavaScript
//    Minify one, or merge by `import` statement
//    Can be passed to external minifier API

abstract class Minify implements Stringable, LoggerAwareInterface
{
    protected const string NEWLINE = "\n";

    use LoggerAwareTrait;

    protected int $sizeBefore;

    protected int $sizeAfter;

    protected bool $compiled = false;

    public ?string $content = null;

    abstract public function setSource( string|Stringable ...$source ) : static;

    abstract public function minify() : self;

    final public function __toString() : string
    {
        return $this->content ?? $this->minify()->content;
    }

    final public static function JS(
        string|Stringable $source,
        bool              $imports = false,
    ) : JavaScriptMinifier {
        $minifier = new JavaScriptMinifier();
        if ( $imports ) {
            $minifier->imports = [];
        }
        return $minifier->setSource( $source );
    }

    /**
     * @param string|Stringable ...$source
     *
     * @return StylesheetMinifier
     */
    final public static function CSS(
        string|Stringable ...$source,
    ) : StylesheetMinifier {
        $minifier = new StylesheetMinifier();

        return $minifier->setSource( ...$source );
    }

    /**
     * @param string $string
     *
     * @return string
     */
    final protected function normalizeNewline( string $string ) : string
    {
        return \str_replace( [PHP_EOL, "\r\n", "\r"], $this::NEWLINE, $string );
    }
}
