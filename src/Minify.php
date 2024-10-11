<?php

namespace Northrook;

use Northrook\Exception\E_Value;
use Northrook\Filesystem\Resource;
use Northrook\Logger\Log;
use Northrook\Resource\{Path, URL};
use Support\Num;
use Northrook\Trait\PrintableClass;
use function String\{hashKey, sourceKey};
use RuntimeException;

/**
 * @author Martin Nielsen <mn@northrook.com>
 */
abstract class Minify
{
    use PrintableClass;

    protected const ?string EXTENSION = null;

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

    private string $compiled;

    /** @var array<string, array|resource|string> */
    protected array $sources = [];

    protected bool $locked = false;

    // Compile will be called when calling ->minify()
    // before compile, we check combined file size
    // returned string stored in ->string
    // ->report() provides raw/compiled size comparison

    abstract protected function compile( array $sources ) : string;

    final public function minify() : string
    {
        // Profiler
        Clerk::event( __METHOD__ );

        // Lock the $sources
        $this->locked = true;

        if ( null === $this::EXTENSION ) {
            throw new RuntimeException( 'The '.__CLASS__.'::EXTENSION must be defined.' );
        }

        $sources = $this->parseSources();

        dump( $sources );

        $this->compiled = $this->compile( $sources );

        $this->minifiedSizeKb = (float) Num::byteSize( $this->compiled );

        $this->locked = false;

        Clerk::stop( __METHOD__ );

        return $this->compiled;
    }

    /**
     * @param array<string, array|resource|string> $sources
     *
     * @return array<string, Path|string>
     */
    private function parseSources( array $sources = [] ) : array
    {
        $array = [];

        foreach ( $sources as $source ) {
            if ( \is_array( $source ) ) {
                $array = [...$array, ...$this->parseSources( $source )];

                continue;
            }

            // If the $source contains brackets, assume it is a raw CSS string
            if ( \is_string( $source ) && ( \str_contains( $source, '{' ) && \str_contains( $source, '}' ) ) ) {
                $array['raw:'.hashKey( $source )] ??= $source;

                continue;
            }

            $resource = \is_string( $source ) ? Resource::from( $source ) : $source;

            if ( $resource instanceof URL && $resource->exists ) {
                $externalContent = $resource->fetch();
                if ( \is_string( $externalContent ) ) {
                    $array['url:'.hashKey( $externalContent )] ??= $externalContent;
                }
                else {
                    E_Value::warning(
                        '{minifier} was unable to process external source from URL {path}. The file was fetched, but appears empty.',
                        [
                            'minifier' => $this::class,
                            'path'     => $resource->path,
                            'resource' => $resource,
                        ],
                    );
                }

                continue;
            }

            \assert( $resource instanceof Path );

            // If the source is a valid, readable path, add it
            if ( $this::EXTENSION === $resource->extension && $resource->isReadable ) {
                $array["{$resource->extension}:".sourceKey( $resource )] ??= $resource;

                continue;
            }
        }
        return $array;
    }

    // public function __toString() : string
    // {
    //     $this->minify();
    //
    //     if ( $this->logResults ) {
    //         $differenceKb      = $this->initialSizeKb - $this->minifiedSizeKb;
    //         $differencePercent = Num::percentDifference( $this->initialSizeKb, $this->minifiedSizeKb );
    //
    //         if ( $differenceKb >= 1 ) {
    //             Log::Notice(
    //                 message : $this->type.' string minified {percent}, from {from} to {to} saving {diff},',
    //                 context : [
    //                     'from'    => "{$this->initialSizeKb}KB",
    //                     'to'      => "{$this->minifiedSizeKb}KB",
    //                     'diff'    => "{$differenceKb}KB",
    //                     'percent' => "{$differencePercent}%",
    //                 ],
    //             );
    //         }
    //     }
    //
    //     return $this->string;
    // }

    public function report() : string
    {
        if ( ! isset( $this->minifiedSizeKb ) ) {
            $this->minify();
        }

        $differenceKb = $this->initialSizeKb - $this->minifiedSizeKb;

        return "StylesheetMinifier string minified. {$this->initialSizeKb}KB to {$this->minifiedSizeKb}KB, saving {$differenceKb}KB.";
    }
}