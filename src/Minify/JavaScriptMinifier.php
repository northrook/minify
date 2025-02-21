<?php

declare(strict_types=1);

namespace Support\Minify;

use Support\Minify;
use Core\Exception\{NotSupportedException, RegexpException};
use Stringable, SplFileInfo, LogicException;
use function Support\{isUrl};

final class JavaScriptMinifier extends Minify
{
    /** @var array<array-key, SplFileInfo|string> */
    private string|array $source;

    /** @var string[] */
    protected array $comments = [];

    /**
     * @param string|Stringable ...$source
     *
     * @return $this
     */
    public function setSource( string|Stringable ...$source ) : static
    {
        $this->source = (string) \current( $source );

        return $this;
    }

    public function minify( ?string $key = null, bool $bundleImportStatements = false ) : self
    {
        if ( $this->output ) {
            return $this;
        }

        $this->validateSources( $key, $bundleImportStatements );

        if ( $this->cachePool && $this->useCachedContent() ) {
            return $this;
        }

        if ( $bundleImportStatements ) {
            $this->handleImportStatements();
        }

        if ( \is_array( $this->source ) ) {
            $this->source = \implode( self::NEWLINE, $this->source );
        }

        $this->sizeBefore = \mb_strlen( $this->source );

        $this->parse();

        // [JShrink]
        // $this->output = JavaScriptMinifier\Minifier::minify( $this->source );

        $this->sizeAfter = \mb_strlen( $this->output );

        if ( $this->cachePool && $this->key ) {
            $this->updateCache( $this->version, $this->output );
        }

        return $this;
    }

    /**
     * Further compress the script using the Toptal API.
     *
     * @return $this
     */
    public function compress() : self
    {
        $api     = 'https://www.toptal.com/developers/javascript-minifier/api/raw';
        $headers = ['Content-Type: application/x-www-form-urlencoded'];
        $query   = \http_build_query( ['input' => $this->output] );

        // init the request, set various options, and send it
        $connection = \curl_init();

        \curl_setopt( $connection, CURLOPT_URL, $api );
        \curl_setopt( $connection, CURLOPT_RETURNTRANSFER, true );
        \curl_setopt( $connection, CURLOPT_POST, true );
        \curl_setopt( $connection, CURLOPT_HTTPHEADER, $headers );
        \curl_setopt( $connection, CURLOPT_POSTFIELDS, $query );

        $this->output = \curl_exec( $connection );

        // finally, close the request
        \curl_close( $connection );

        $this->sizeAfter = \mb_strlen( $this->output );
        return $this;
    }

    protected function parse() : void
    {
        // Normalize tabs
        $this->output = $this->source;

        $this->removeComments()
            ->handleWhitespace();
    }

    protected function removeComments() : self
    {
        $this->output = (string) \preg_replace_callback(
            pattern  : '/^\h*\/\/[^\n]*|^\h*\/\*[\s\S]*?\*\//m',
            callback : function( $matches ) : string {
                $this->comments[] = $matches[0];
                return '';
            },
            subject  : $this->output,
        );
        RegexpException::check();

        return $this;
    }

    protected function handleWhitespace() : self
    {
        // reduce consecutive newlines to single one
        $this->output = \preg_replace(
            pattern     : '~[\\n]+~',
            replacement : $this::NEWLINE,
            subject     : $this->output,
        );
        RegexpException::check();

        $tabSize = null;

        $lines = \explode( $this::NEWLINE, $this->output );

        for ( $i = 0; $i < \count( $lines ); $i++ ) {
            $leadingSpaces = \strspn( $lines[$i], ' ' );
            if ( ! $tabSize && $leadingSpaces ) {
                $tabSize = $leadingSpaces;
            }

            if ( ! \trim( $lines[$i] ) ) {
                unset( $lines[$i] );

                continue;
            }
            $line = \rtrim( $lines[$i] );
            $line = \substr( $line, $leadingSpaces );

            if ( $leadingSpaces && $tabSize ) {
                $line = \str_repeat( "\t", $leadingSpaces / $tabSize ).$line;
            }

            $nextLine = $lines[$i + 1] ?? null;

            if ( $nextLine
                 && \preg_match( '#[\w?!_h,.]\h*[`"\']\h\+$#', $line )
                 && \preg_match( '#^\h*[`"\']\h*[\w?!_h,.]#', $nextLine )
            ) {
                $line     = \substr( \rtrim( $line, ' +' ), 0, -1 );
                $nextLine = \substr( \ltrim( $nextLine ), 1 );

                $line .= $nextLine;

                $lines[$i + 1] = '';
            }
            $lines[$i] = $line;
        }

        $this->output = \implode( $this::NEWLINE, \array_filter( $lines ) );
        $this->output = \trim( \str_replace( ["\t"], '  ', $this->output ) );
        return $this;
    }

    /**
     * A key will be generated from provided file paths.
     *
     * @return bool
     */
    protected function useCachedContent() : bool
    {
        ['hash' => $hash, 'data' => $data] = $this->getCached( $this->key );

        if ( $this->version === $hash && $data ) {
            $this->output    = $data;
            $this->usedCache = true;
        }

        return $this->usedCache;
    }

    private function validateSources(
        ?string $key,
        bool    $bundleImportStatements,
    ) : void {
        $version = '';
        $autoKey = '';

        $isFile = \file_exists( $this->source );

        if ( $isFile || isUrl( $this->source ) ) {
            $autoKey .= $this->source;
            $version .= \filemtime( $this->source );
        }
        else {
            $version .= $this->sourceHash( $this->source );
        }

        if ( $bundleImportStatements ) {
            if ( ! $isFile ) {
                throw new NotSupportedException( 'Imports only supported for local files.' );
            }

            $basePath = \pathinfo( $this->source, PATHINFO_DIRNAME );
            $source   = $this->normalizeNewline( \file_get_contents( $this->source ) );

            $importCount  = \substr_count( $source, 'import ' ) + 1;
            $this->source = \explode( self::NEWLINE, $source, $importCount );

            foreach ( $this->source as $line => $string ) {
                if ( ! \str_starts_with( $string, 'import ' ) ) {
                    continue;
                }

                if ( $importPath = $this->importPath( $string, $basePath ) ) {
                    $autoKey .= $importPath;
                    $version .= $importPath->getMTime();
                    $this->source[$line] = $importPath;
                }
            }
        }
        elseif ( $isFile ) {
            $this->source = $this->normalizeNewline( \file_get_contents( $this->source ) );
        }
        else {
            $this->source = $this->normalizeNewline( $this->source );
        }

        if ( ! ( $key ?? $autoKey ) ) {
            $this->logger?->warning(
                '{class}: No key set or derived from sources. Results will not be cached.',
                ['class' => $this::class],
            );
            $this->key = null;
            return;
        }

        $this->key     = $key ?? $this->sourceHash( $autoKey );
        $this->version = $this->sourceHash( $version );
    }

    private function handleImportStatements() : void
    {
        if ( ! \is_array( $this->source ) ) {
            throw new LogicException( 'Imports were not handled.' );
        }

        foreach ( $this->source as $index => $source ) {
            if ( $source instanceof SplFileInfo ) {
                $source = \file_get_contents( $source->getPathname() );
            }

            $this->source[$index] = $this->normalizeNewline( $source );
        }
    }

    /**
     * @param string $string
     * @param string $basePath
     *
     * @return false|SplFileInfo
     */
    private function importPath( string $string, string $basePath ) : SplFileInfo|false
    {
        // Trim import statement, quotes and whitespace, and slashes
        $fileName = \trim( \substr( $string, \strlen( 'import ' ) ), " \n\r\t\v\0'\"/\\" );

        if ( ! \str_ends_with( $fileName, '.js' ) ) {
            $fileName .= '.js';
        }

        // TODO: Handle relative paths
        // TODO: Handle URL imports

        $filePath = new SplFileInfo( "{$basePath}/{$fileName}" );

        return $filePath->isFile() ? $filePath : false;
    }
}
