<?php

namespace Northrook;

use Northrook\Core\Env;
use Northrook\Core\Interface\Printable;
use Northrook\Core\Trait\PrintableClass;
use Northrook\Logger\Log;
use Northrook\Support\Num;
use function Northrook\classBasename;
use function trim, str_replace, preg_replace;

/**
 * @method $this trimDocblockComments()
 * @method $this trimSingleComments()
 * @method $this trimBlockComments()
 * @method $this trimCssComments()
 * @method $this trimHtmlComments()
 * @method $this trimLatteComments()
 * @method $this trimTwigComments()
 * @method $this trimBladeComments()
 *
 * @author Martin Nielsen <mn@northrook.com>
 */
abstract class Minify implements Printable
{
    use PrintableClass;

    /**
     * Regex patterns for removing comments from a string.
     *
     * - Matches from the start of the line
     * - Includes the following line break
     */
    public const REGEX_PATTERN = [
        'trimDocblockComments' => '#^\h*?/\*\*.*?\*/\R#ms',  // PHP block comments
        'trimSingleComments'   => '#^\h*?//.+?\R#m',         // Single line comments
        'trimBlockComments'    => '#^\h*?/\*.*?\*/\R#ms',    // Block comments
        'trimCssComments'      => '#^\h*?/\*.*?\*/\R#ms',    // CSS comments
        'trimHtmlComments'     => '#^\h*?<!--.*?-->\R#ms',   // HTML comments
        'trimLatteComments'    => '#^\h*?{\*.*?\*}\R#ms',    // Latte comments
        'trimTwigComments'     => '/^\h*?{#.*?#}\R/ms',      // Twig comments
        'trimBladeComments'    => '#^\h*?{{--.*?--}}\R#ms',  // Blade comments
    ];

    private float $initialSizeKb;
    private float $minifiedSizeKb;

    public readonly string $type;

    final protected function __construct(
        protected string $string,
        protected ?bool  $logResults = null,
    ) {
        $this->type          = classBasename( $this::class );
        $this->initialSizeKb = Num::formatBytes( $string, 'kB', returnFloat : true );
        $this->logResults    ??= Env::isDebug();
    }

    abstract protected function minifyString() : void;

    final public function minify( bool $repeat = false ) : self {
        if ( !isset( $this->minifiedSizeKb ) || $repeat ) {
            $this->minifyString();
            $this->minifiedSizeKb = Num::formatBytes( $this->string, 'kB', returnFloat : true );
        }
        return $this;
    }

    public function __toString() : string {
        $this->minify();

        if ( $this->logResults ) {

            $differenceKb      = $this->initialSizeKb - $this->minifiedSizeKb;
            $differencePercent = Num::percentDifference( $this->initialSizeKb, $this->minifiedSizeKb );

            Log::Notice(
                message : $this->type . ' string minified {percent}, from {from} to {to} saving {diff},',
                context : [
                              'from'    => "{$this->initialSizeKb}KB",
                              'to'      => "{$this->minifiedSizeKb}KB",
                              'diff'    => "{$differenceKb}KB",
                              'percent' => "{$differencePercent}%",
                          ],
            );
        }

        return $this->string;
    }

    public function report() : string {

        if ( !isset( $this->minifiedSizeKb ) ) {
            $this->minify();
        }

        $differenceKb = $this->initialSizeKb - $this->minifiedSizeKb;

        return "CSS string minified. {$this->initialSizeKb}KB to {$this->minifiedSizeKb}KB, saving {$differenceKb}KB.";
    }

    // Static Functions --------------------

    public static function string(
        ?string      $string,
        bool | array $removeComments = true,
        bool         $removeTabs = true,
        bool         $removeNewlines = true,
    ) : ?string {

        if ( !$string ) {
            return null;
        }

        $minify = new Minify\StringMinifier( $string );

        if ( $removeComments !== false ) {
            $minify->trimComments();
        }

        return $minify->trimWhitespace( $removeTabs, $removeNewlines );
    }

    /**
     * Remove all unnecessary whitespace from a string.
     *
     * @param string  $string
     *
     * @return string
     */
    public static function squish( string $string ) : string {
        return ( new Minify\StringMinifier( $string ) )->trimWhitespace();
    }

    public static function HTML( string $source, ?bool $logResults = null ) : Minify {
        return new Minify\HTML( $source, $logResults );
    }

    public static function CSS( string $source, ?bool $logResults = null ) : Minify {
        return new Minify\CSS( $source, $logResults );
    }

    public static function JS( string $source, ?bool $logResults = null ) : Minify {
        return new Minify\JS( $source, $logResults );
    }

    public static function Latte( string $source, ?bool $logResults = null ) : Minify {
        return new Minify\Latte( $source, $logResults );
    }


    /**
     * Optimize an SVG string
     *
     * - Removes all whitespace, including tabs and newlines
     * - Removes consecutive spaces
     * - Removes the XML namespace by default
     *
     * @param string  $string  The string SVG string
     *
     * @return Minify
     */
    public static function SVG( string $string, ?bool $logResults = null ) : Minify {
        return new Minify\SVG( $string, $logResults );
    }

    // Trim Functions ------

    /**
     * Compress a string by removing consecutive whitespace and empty lines.
     *
     * - Removes empty lines
     * - Removes consecutive spaces
     * - Remove tabs, newlines, and carriage returns by default
     *
     * @param bool  $removeTabs      Also remove tabs
     * @param bool  $removeNewlines  Also remove newlines
     *
     * @return $this
     */
    final protected function trimWhitespace(
        bool $removeTabs = true,
        bool $removeNewlines = true,
    ) : self {

        // Trim according to arguments
        $this->string = match ( true ) {
            // Remove all whitespace, including tabs and newlines
            $removeTabs && $removeNewlines => preg_replace( '/\s+/', ' ', $this->string ),
            // Remove tabs only
            $removeTabs                    => str_replace( '\t', ' ', $this->string ),
            // Remove newlines only
            $removeNewlines                => str_replace( '\R', ' ', $this->string ),
            // Remove consecutive whitespaces
            default                        => preg_replace( '# +#', ' ', $this->string ),
        };

        // Remove empty lines
        $this->string = preg_replace( '#^\s*?$\n#m', '', $this->string );

        $this->string = trim( $this->string );

        return $this;
    }

    public function trimComments() : self {
        foreach ( Minify::REGEX_PATTERN as $pattern ) {
            $this->string = preg_replace(
                pattern     : $pattern,
                replacement : '',
                subject     : $this->string,
            );
        }
        return $this;
    }

    public function __call( string $method, array $arguments ) : self {
        $pattern = Minify::REGEX_PATTERN[ $method ] ?? false;

        if ( $pattern ) {
            $this->string = preg_replace(
                pattern     : $pattern,
                replacement : '',
                subject     : $this->string,
            );
        }

        return $this;
    }

    // final protected function removeAllComments() : Minify {
    //
    //     return $this;
    // }
    //
    // final protected function removeDocblockComments() : Minify {
    //
    //     $this->string = preg_replace(
    //         pattern     : Minify::REGEX_PATTERN[ 'docblock' ],
    //         replacement : '',
    //         subject     : $this->string,
    //     );
    //     return $this;
    // }
    //
    // final protected function removeSingleComments() : Minify {
    //
    //     $this->string = preg_replace(
    //         pattern     : Minify::REGEX_PATTERN[ 'single' ],
    //         replacement : '',
    //         subject     : $this->string,
    //     );
    //     return $this;
    // }
    //
    // final protected function removeBlockComments() : Minify {
    //
    //     $this->string = preg_replace(
    //         pattern     : Minify::REGEX_PATTERN[ 'block' ],
    //         replacement : '',
    //         subject     : $this->string,
    //     );
    //     return $this;
    // }
    //
    // final protected function removeCssComments() : Minify {
    //
    //     $this->string = preg_replace(
    //         pattern     : Minify::REGEX_PATTERN[ 'css' ],
    //         replacement : '',
    //         subject     : $this->string,
    //     );
    //     return $this;
    // }
    //
    // final protected function removeHtmlComments() : Minify {
    //
    //     $this->string = preg_replace(
    //         pattern     : Minify::REGEX_PATTERN[ 'html' ],
    //         replacement : '',
    //         subject     : $this->string,
    //     );
    //     return $this;
    // }
    //
    // final protected function removeLatteComments() : Minify {
    //     $this->string = preg_replace(
    //         pattern     : Minify::REGEX_PATTERN[ 'latte' ],
    //         replacement : '',
    //         subject     : $this->string,
    //     );
    //     return $this;
    // }
    //
    // final protected function removeTwigComments() : Minify {
    //     $this->string = preg_replace(
    //         pattern     : Minify::REGEX_PATTERN[ 'twig' ],
    //         replacement : '',
    //         subject     : $this->string,
    //     );
    //     return $this;
    // }
    //
    // final protected function removeBladeComments() : Minify {
    //     $this->string = preg_replace(
    //         pattern     : Minify::REGEX_PATTERN[ 'blade' ],
    //         replacement : '',
    //         subject     : $this->string,
    //     );
    //     return $this;
    // }
}