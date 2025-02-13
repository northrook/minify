<?php

declare(strict_types=1);

namespace Support\Minify;

use Stringable;
use Support\Minify;
use function Support\isUrl;

final class JavaScriptMinifier extends Minify
{
    /** @var false|string[] */
    protected false|array $imports = false;

    private ?string $source;

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

    public function minify() : self
    {
        if ( $this->content ) {
            return $this;
        }

        $this->validateSource();

        if ( $this->imports !== false ) {
            $this->bundleImportStatements();
        }

        // TODO : [low] Merge into this file
        $this->content = JavaScriptMinifier\Minifier::minify( $this->content );

        $this->sizeAfter = \mb_strlen( $this->content );

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
        $query   = \http_build_query( ['input' => $this->content] );

        // init the request, set various options, and send it
        $connection = \curl_init();

        \curl_setopt( $connection, CURLOPT_URL, $api );
        \curl_setopt( $connection, CURLOPT_RETURNTRANSFER, true );
        \curl_setopt( $connection, CURLOPT_POST, true );
        \curl_setopt( $connection, CURLOPT_HTTPHEADER, $headers );
        \curl_setopt( $connection, CURLOPT_POSTFIELDS, $query );

        $this->content = \curl_exec( $connection );

        // finally, close the request
        \curl_close( $connection );

        $this->sizeAfter = \mb_strlen( $this->content );
        return $this;
    }

    public function bundleImportStatements() : self
    {
        $this->validateSource();

        $basePath = \pathinfo( $this->source, PATHINFO_DIRNAME );

        $importCount = \substr_count( $this->content, 'import ' ) + 1;
        $parseLines  = \explode( self::NEWLINE, $this->content, $importCount );

        foreach ( $parseLines as $line => $string ) {
            if ( ! \str_starts_with( $string, 'import ' ) ) {
                continue;
            }

            $imported = $this->importStatement( $string, $basePath );

            if ( $imported ) {
                $parseLines[$line] = $imported;
            }
        }

        $this->content = \implode( self::NEWLINE, $parseLines );

        $this->sizeBefore = \mb_strlen( $this->content );

        return $this;
    }

    /**
     * @param string $string
     * @param string $basePath
     *
     * @return false|string
     */
    private function importStatement( string $string, string $basePath ) : string|false
    {
        // Trim import statement, quotes and whitespace, and slashes
        $fileName = \trim( \substr( $string, \strlen( 'import ' ) ), " \n\r\t\v\0'\"/\\" );

        if ( ! \str_ends_with( $fileName, '.js' ) ) {
            $fileName .= '.js';
        }

        // TODO: Handle relative paths
        // TODO: Handle URL imports

        $filePath = "{$basePath}/{$fileName}";

        $this->imports[$fileName] = $filePath;

        return \file_get_contents( $filePath );
    }

    private function validateSource() : void
    {
        if ( isset( $this->content ) ) {
            $this->logger?->info( '{method} already called.', ['method' => __METHOD__] );
            return;
        }

        if ( \file_exists( $this->source ) || isUrl( $this->source ) ) {
            $source = \file_get_contents( $this->source );
        }
        else {
            $source       = $this->source;
            $this->source = null;
        }

        $this->content    = $this->normalizeNewline( $source );
        $this->sizeBefore = \mb_strlen( $source );
    }
}
