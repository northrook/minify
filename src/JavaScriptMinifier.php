<?php

declare(strict_types=1);

namespace Support;

use Core\Exception\RegexpException;
use LogicException;

final class JavaScriptMinifier extends Minify
{
    /**
     * @var array<array-key, string>|string
     */
    protected string|array $source = PLACEHOLDER_STRING;

    protected bool $bundleImportStatements = false;

    /** @var string[] */
    protected array $comments = [];

    public function bundleImportStatements( bool $set = true ) : self
    {
        if ( $this->locked ) {
            throw new LogicException( 'The minifier is locked.' );
        }
        $this->bundleImportStatements = $set;

        return $this;
    }

    protected function prepare( ?string $key ) : array
    {
        $autoKey = '';
        $version = '';

        $isFile = \file_exists( $this->source );

        if ( $isFile || is_url( $this->source ) ) {
            $autoKey .= $this->source;
            $version .= \filemtime( $this->source );
        }
        else {
            $version .= $this->sourceHash( $this->source );
        }

        if ( ! $isFile ) {
            $this->bundleImportStatements = false;
        }

        if ( $this->bundleImportStatements ) {
            $basePath = \pathinfo( $this->source, PATHINFO_DIRNAME );
            $source   = normalize_newline( \file_get_contents( $this->source ) );

            $importCount  = \substr_count( $source, 'import ' ) + 1;
            $this->source = \explode( NEWLINE, $source, $importCount );

            foreach ( $this->source as $line => $string ) {
                if ( ! \str_starts_with( $string, 'import ' ) ) {
                    continue;
                }

                if ( $importPath = $this->importPath( $string, $basePath ) ) {
                    $autoKey .= $importPath;
                    $version .= \filemtime( $importPath );
                    $this->source[$line] = $importPath;
                }
            }
        }
        elseif ( $isFile ) {
            $this->source = normalize_newline( \file_get_contents( $this->source ) );
        }
        else {
            $this->source = normalize_newline( $this->source );
        }

        if ( ! ( $key ?? $autoKey ) ) {
            $this->logger?->warning(
                '{class}: No key set or derived from sources. Results will not be cached.',
                ['class' => $this::class],
            );
            return [
                null,
                $this->sourceHash( $version ),
            ];
        }

        return [
            $key ?? $this->sourceHash( $autoKey ),
            $this->sourceHash( $version ),
        ];
    }

    protected function process() : void
    {
        if ( $this->bundleImportStatements ) {
            $this->handleImportStatements();
            $this->buffer = \implode( NEWLINE, $this->source );
        }
        else {
            $this->buffer = $this->source;
        }

        $this->status->setSourceBytes( $this->buffer );

        $this->parse();

        // [JShrink]
        // $this->buffer = JavaScriptMinifier\Minifier::minify( $this->buffer );

        $this->status->setMinifiedBytes( $this->buffer );
    }

    protected function parse() : void
    {
        if ( ! $this->buffer ) {
            throw new LogicException( "The buffer is empty.\Run minify() first." );
        }

        $this->removeComments()
            ->handleWhitespace();
    }

    protected function removeComments() : self
    {
        $this->buffer = (string) \preg_replace_callback(
            pattern  : '#^\h*\/\/[^\n]*|^\h*\/\*[\s\S]*?\*\/#m',
            callback : function( $matches ) : string {
                $this->comments[] = $matches[0];
                return '';
            },
            subject  : $this->buffer,
        );
        RegexpException::check();

        return $this;
    }

    protected function handleWhitespace() : self
    {
        // reduce consecutive newlines to single one
        $this->buffer = \preg_replace(
            pattern     : '~[\\n]+~',
            replacement : NEWLINE,
            subject     : $this->buffer,
        );
        RegexpException::check();

        $tabSize = null;

        $lines = \explode( NEWLINE, $this->buffer );

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

        $this->buffer = \implode( NEWLINE, \array_filter( $lines ) );
        $this->buffer = \trim( \str_replace( ["\t"], '  ', $this->buffer ) );
        return $this;
    }

    private function handleImportStatements() : void
    {
        if ( ! \is_array( $this->source ) ) {
            throw new LogicException( 'Imports were not handled.' );
        }

        foreach ( $this->source as $index => $source ) {
            if ( \file_exists( $source ) ) {
                $source = \file_get_contents( $source );
            }

            $this->source[$index] = normalize_newline( $source );
        }
    }

    /**
     * @param string $string
     * @param string $basePath
     *
     * @return false|string
     */
    private function importPath( string $string, string $basePath ) : false|string
    {
        // Trim import statement, quotes and whitespace, and slashes
        $fileName = \trim( \substr( $string, \strlen( 'import ' ) ), " \n\r\t\v\0'\"/\\" );

        if ( ! \str_ends_with( $fileName, '.js' ) ) {
            $fileName .= '.js';
        }

        $importPath = "{$basePath}/{$fileName}";

        // TODO: Handle relative paths
        // TODO: Handle URL imports

        return \file_exists( $importPath ) ? $importPath : false;
    }

    /**
     * Further compress the script using the Toptal API.
     *
     * @return $this
     */
    public function compress() : self
    {
        if ( ! $this->buffer ) {
            throw new LogicException( "The buffer is empty.\Run minify() first." );
        }

        $api     = 'https://www.toptal.com/developers/javascript-minifier/api/raw';
        $headers = ['Content-Type: application/x-www-form-urlencoded'];
        $query   = \http_build_query( ['input' => $this->buffer] );

        // init the request, set various options, and send it
        $connection = \curl_init();

        \curl_setopt( $connection, CURLOPT_URL, $api );
        \curl_setopt( $connection, CURLOPT_RETURNTRANSFER, true );
        \curl_setopt( $connection, CURLOPT_POST, true );
        \curl_setopt( $connection, CURLOPT_HTTPHEADER, $headers );
        \curl_setopt( $connection, CURLOPT_POSTFIELDS, $query );

        $this->buffer = \curl_exec( $connection );

        // finally, close the request
        \curl_close( $connection );

        return $this;
    }
}
