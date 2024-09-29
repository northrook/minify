<?php

namespace Northrook;

use Northrook\Interface\Printable;
use Northrook\Logger\Log;
use Northrook\Minify\JavaScriptMinifier;
use Northrook\Support\Num;
use Northrook\Trait\PrintableClass;
use function Number\percentDifference;
use function preg_replace;
use function str_replace;
use function Support\classBasename;
use function trim;

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
class Minify implements Printable
{
    use PrintableClass;

    /**
     * Regex patterns for removing comments from a string.
     *
     * - Matches from the start of the line
     * - Includes the following line break
     */
    public const array REGEX_PATTERN
            = [
                    'trimDocblockComments' => '#^\h*?/\*\*.*?\*/\R*#ms',  // PHP block comments
                    'trimSingleComments'   => '#\h*?//.+?\R*#m',         // Single line comments
                    'trimBlockComments'    => '#\h*?/\*.*?\*/\R*#ms',    // Block comments
                    'trimCssComments'      => '#\h*?/\*.*?\*/\R*#ms',    // StylesheetMinifier comments
                    'trimHtmlComments'     => '#^\h*?<!--.*?-->\R*#ms',   // HTML comments
                    'trimLatteComments'    => '#^\h*?{\*.*?\*}\R*#ms',    // Latte comments
                    'trimTwigComments'     => '/^\h*?{#.*?#}\R*/ms',      // Twig comments
                    'trimBladeComments'    => '#^\h*?{{--.*?--}}\R*#ms',  // Blade comments
            ];

    private float $initialSizeKb;
    private float $minifiedSizeKb;

    public readonly string $type;

    final protected function __construct(
            protected string $string,
            protected ?bool  $logResults = null,
    )
    {
        $this->type          = classBasename( $this::class );
        $this->initialSizeKb = Num::formatBytes( $string, 'kB', returnFloat : true );
        $this->logResults    ??= Env::isDebug();
    }

    protected function minifyString() : void
    {
        $this->trimWhitespace( true, true );
    }

    final public function minify( bool $repeat = false ) : self
    {
        if ( !isset( $this->minifiedSizeKb ) || $repeat ) {
            $this->minifyString();
            $this->minifiedSizeKb = Num::formatBytes( $this->string, 'kB', returnFloat : true );
        }
        return $this;
    }

    public function __toString() : string
    {
        $this->minify();

        if ( $this->logResults ) {
            $differenceKb      = $this->initialSizeKb - $this->minifiedSizeKb;
            $differencePercent = percentDifference( $this->initialSizeKb, $this->minifiedSizeKb );

            if ( $differenceKb >= 1 ) {
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
        }

        return $this->string;
    }

    public function report() : string
    {
        if ( !isset( $this->minifiedSizeKb ) ) {
            $this->minify();
        }

        $differenceKb = $this->initialSizeKb - $this->minifiedSizeKb;

        return "StylesheetMinifier string minified. {$this->initialSizeKb}KB to {$this->minifiedSizeKb}KB, saving {$differenceKb}KB.";
    }

    // Static Functions --------------------

    public static function string(
            ?string      $string,
            bool | array $removeComments = true,
            bool         $removeTabs = true,
            bool         $removeNewlines = true,
    ) : ?string
    {
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
    public static function squish( string $string ) : string
    {
        return ( new Minify\StringMinifier( $string ) )->trimWhitespace();
    }

    public static function HTML( string $source, ?bool $logResults = null ) : string
    {
        return (string) new Minify\HtmlMinifier( $source, $logResults );
    }

    public static function CSS( string $source, ?bool $logResults = null ) : Minify
    {
        return new Minify\StylesheetMinifier( $source, $logResults );
    }

    /**
     * @param string   $source
     * @param ?string  $profilerTag
     *
     * @return null|string
     */
    public static function JS( string $source, ?string $profilerTag = null ) : ?string
    {
        return $source ? ( new JavaScriptMinifier( $source, $profilerTag ) )->minify() : $source;
    }

    public static function Latte( string $source, ?bool $logResults = null ) : Minify
    {
        return new Minify\LatteMinifier( $source, $logResults );
    }

    /**
     * Optimize an SvgMinifier string
     *
     * - Removes all whitespace, including tabs and newlines
     * - Removes consecutive spaces
     * - Removes the XML namespace by default
     *
     * @param string     $string  The string SvgMinifier string
     * @param null|bool  $logResults
     *
     * @return Minify
     */
    public static function SVG( string $string, ?bool $logResults = null ) : Minify
    {
        return new Minify\SvgMinifier( $string, $logResults );
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
    ) : self
    {
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

    public function trimComments() : self
    {
        foreach ( Minify::REGEX_PATTERN as $pattern ) {
            $this->string = preg_replace(
                    pattern     : $pattern,
                    replacement : '',
                    subject     : $this->string,
            );
        }
        return $this;
    }

    public function __call( string $method, array $arguments ) : self
    {
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
}