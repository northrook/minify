<?php

namespace Northrook;

use RuntimeException;
use Interface\Printable;
use Interface\PrintableClass;
use Support\FileInfo;
use Support\Num;
use function String\{hashKey, sourceKey};
use function Support\classBasename;

/**
 * @author Martin Nielsen <mn@northrook.com>
 */
abstract class Minify implements Printable
{
    use PrintableClass;

    protected const ?string EXTENSION = null;

    public const string CLERK_GROUP = 'minify';

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

    /** @var array<string, array|resource|string|\Support\FileInfo> */
    protected array $sources = [];

    protected bool $locked = false;

    // Compile will be called when calling ->minify()
    // before compile, we check combined file size
    // returned string stored in ->string
    // ->report() provides raw/compiled size comparison

    abstract protected function compile( array $sources ) : string;

    public function __toString() : string
    {
        return $this->minify();
    }

    final public function minify() : string
    {
        // Profiler
        Clerk::event( __METHOD__, static::CLERK_GROUP );

        // Lock the $sources
        $this->locked = true;

        if ( null === $this::EXTENSION ) {
            throw new RuntimeException( 'The ' . __CLASS__ . '::EXTENSION must be defined.' );
        }

        $compiled = $this->compile( $this->sources() );

        $this->minifiedSizeKb = \mb_strlen( $compiled );

        $this->locked = false;

        Clerk::stopGroup( static::CLERK_GROUP );

        // dump( $this->report() );
        return $compiled;
    }

    /**
     * Parses all provided {@see \Northrook\Minify::$sources}, returning an array of their content.
     *
     * - Empty sources will bbe skipped.
     *
     * @return array<string, string>
     */
    private function sources() : array
    {
        $this->initialSizeKb = 0;
        $sources             = [];

        foreach ( $this->parseSources( $this->sources ) as $key => $source ) {
            $content = $source instanceof FileInfo ? $source->getContents() : $source;
            if ( !$content ) {
                continue;
            }
            $this->initialSizeKb += \mb_strlen( $content, 'UTF-8' );
            $sources[ $key ]     = $content;
        }

        return $sources;
    }

    /**
     * @param array<string, array|resource|string|FileInfo>  $sources
     *
     * @return array<string, FileInfo|string>
     */
    private function parseSources( array $sources = [] ) : array
    {
        $array = [];

        foreach ( $sources as $source ) {
            if ( \is_array( $source ) ) {
                $array = [ ...$array, ...$this->parseSources( $source ) ];

                continue;
            }

            // If the $source contains brackets, assume it is a raw CSS string
            if ( \is_string( $source ) && ( \str_contains( $source, '{' ) && \str_contains( $source, '}' ) ) ) {
                $array[ 'raw:' . hashKey( $source ) ] ??= $source;

                continue;
            }

            $resource = \is_string( $source ) ? new FileInfo( $source ) : $source;

            // TODO : Handle URL
            // if ( $resource instanceof URL && $resource->exists() ) {
            //     $externalContent = $resource->fetch();
            //     if ( \is_string( $externalContent ) ) {
            //         $array[ 'url:' . hashKey( $externalContent ) ] ??= $externalContent;
            //     }
            //     else {
            //         Log::warning(
            //                 '{minifier} was unable to process external source from URL {path}. The file was fetched, but appears empty.',
            //                 [
            //                         'minifier' => $this::class,
            //                         'path'     => (string) $resource,
            //                         'resource' => $resource,
            //                 ],
            //         );
            //     }
            //
            //     continue;
            // }

            \assert( $resource instanceof FileInfo );

            // If the source is a valid, readable path, add it
            if ( $this::EXTENSION === $resource->getExtension() && $resource->isReadable() ) {
                $array[ "{$resource->getExtension()}:" . sourceKey( $resource ) ] ??= $resource;

                continue;
            }
        }
        return $array;
    }

    /**
     * Generate a basic compression report.
     *
     * - Returns a formatted string by default.
     * - Can return an array of initialKb, minifiedKb, differenceKb, differencePercent
     *
     * @param bool  $getData
     *
     * @return array|string
     */
    final public function report( bool $getData = false ) : string | array
    {
        $minifier = classBasename( $this::class );

        if ( !isset( $this->minifiedSizeKb ) ) {
            return "{$minifier} has not been compiled yet. Please run the " . $this::class . '->minify() method first.';
        }

        $this->initialSizeKb  = (float) Num::byteSize( $this->initialSizeKb );
        $this->minifiedSizeKb = (float) Num::byteSize( $this->minifiedSizeKb );

        $differenceKb      = $this->initialSizeKb - $this->minifiedSizeKb;
        $differencePercent = Num::percentDifference( $this->initialSizeKb, $this->minifiedSizeKb );

        if ( $getData ) {
            return [
                    'initialKb'         => $this->initialSizeKb,
                    'minifiedKb'        => $this->minifiedSizeKb,
                    'differenceKb'      => $differenceKb,
                    'differencePercent' => $differencePercent,
            ];
        }

        return "{$minifier} compressed {$differencePercent}%. {$this->initialSizeKb}KB to {$this->minifiedSizeKb}KB, saving {$differenceKb}KB.";
    }
}

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
