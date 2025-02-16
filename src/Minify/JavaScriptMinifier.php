<?php

declare(strict_types=1);

namespace Support\Minify;

use Support\Minify;
use Core\Exception\{NotSupportedException, RegexpException};
use Stringable, SplFileInfo, LogicException;
use Support\Minify\JavaScriptMinifier\Expressions;
use function Support\isUrl;

final class JavaScriptMinifier extends Minify
{
    public bool $flaggedComments = true;

    /** @var array<array-key, SplFileInfo|string> */
    private string|array $source;

    private Expressions $expressions;

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

    /**
     * A key will be generated from provided file paths.
     *
     * @return bool
     */
    protected function useCachedContent() : bool
    {
        ['hash' => $hash, 'data' => $data] = $this->getCached( $this->key );

        if ( $this->version === $hash && $data ) {
            $this->content   = $data;
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

    public function minify( ?string $key = null, bool $bundleImportStatements = false ) : self
    {
        if ( $this->content ) {
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

        // TODO : [low] Merge into this file

        $this->content = $this->parse();
        // $this->content = JavaScriptMinifier\Parser::minify( $this->source );
        // $this->content = JavaScriptMinifier\Minifier::minify( $this->source );

        $this->sizeAfter = \mb_strlen( $this->content );

        if ( $this->cachePool && $this->key ) {
            $this->updateCache( $this->version, $this->content );
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

    protected function parse() : string
    {
        $this->expressions = new Expressions();

        // Remove comments
        $parse = (string) \preg_replace_callback(
            pattern  : '~(?:'.\implode( '|', $this->expressions->tokens ).')~su',
            callback : [$this, 'removeComments'],
            subject  : $this->source,
        );
        RegexpException::check();

        // rewrite blocks by inserting whitespace placeholders
        $parse = (string) \preg_replace_callback(
            pattern  : '~(?:'.\implode( '|', $this->expressions->all ).')~su',
            callback : [$this, 'processBlocks'],
            subject  : $parse,
        );
        RegexpException::check();

        // remove all remaining space (without the newlines)
        $parse = \preg_replace(
            pattern     : '~'.$this->expressions->someWhitespace.'~',
            replacement : '',
            subject     : $parse,
        );
        RegexpException::check();

        // reduce consecutive newlines to single one
        $parse = \preg_replace(
            pattern     : '~[\\n]+~',
            replacement : "\n",
            subject     : $parse,
        );
        RegexpException::check();

        // remove newlines that may safely be removed
        foreach ( $this->expressions->safeNewlines as $safeNewline ) {
            $parse = \preg_replace(
                pattern     : '~'.$safeNewline.'~su',
                replacement : '',
                subject     : $parse,
            );
            RegexpException::check();
        }

        // replace whitespace placeholders by their original whitespace
        $parse = \str_replace(
            $this->expressions->whitespaceArrayPlaceholders,
            $this->expressions->whitespaceArrayNewline,
            $parse,
        );
        RegexpException::check();

        // remove leading and trailing whitespace
        return \trim( $parse );
    }

    /**
     * Removes all comments that need to be removed
     * The newlines that are added here may later be removed again
     *
     * @param array<array-key, string> $matches
     *
     * @return string
     */
    protected function removeComments( array $matches ) : string
    {
        // the fully matching text
        /** @var string $match */
        $match = $matches[0];

        if ( ! empty( $matches['lineComment'] ) ) {
            // not empty because this might glue words together
            return "\n";
        }
        if ( ! empty( $matches['starComment'] ) ) {
            // create a version without leading and trailing whitespace
            $trimmed = \trim( $match, $this->expressions->whitespaceCharsNewline );

            switch ( $trimmed[2] ) {
                case '@':
                    // IE conditional comment
                    return $match;
                case '!':
                    if ( $this->flaggedComments ) {
                        // option says: leave flagged comments in
                        return $match;
                    }
            }
            // multi line comment; not empty because this might glue words together
            return "\n";
        }

        // leave other matches unchanged
        return $match;
    }

    /**
     * Updates the code for all blocks (they contain whitespace that should be conserved)
     * No early returns: all code must reach `end` and have the whitespace replaced by placeholders
     *
     * @param array<array-key, string> $matches
     *
     * @return string
     */
    protected function processBlocks( array $matches ) : string
    {
        // the fully matching text
        $match = $matches[0];

        // create a version without leading and trailing whitespace
        $trimmed = \trim( $match, $this->expressions->whitespaceCharsNewline );

        // Should be handled before optional whitespace
        if ( ! empty( $matches['requiredSpace'] ) ) {
            $match = ! \str_contains( $matches['requiredSpace'], "\n" ) ? ' ' : "\n";
            goto end;
        }
        // + followed by +, or - followed by -
        if ( ! empty( $matches['plus'] ) || ! empty( $matches['min'] ) ) {
            $match = ' ';
            goto end;
        }
        if ( ! empty( $matches['doubleQuote'] ) ) {
            // remove line continuation
            $match = \str_replace( "\\\n", '', $match );
            goto end;
        }
        if ( ! empty( $matches['starComment'] ) ) {
            switch ( $trimmed[2] ) {
                case '@':
                    // IE conditional comment
                    $match = $trimmed;
                    goto end;
                case '!':
                    if ( $this->flaggedComments ) {
                        // ensure newlines before and after
                        $match = "\n".$trimmed."\n";
                        goto end;
                    }
            }
            // simple multi line comment; will have been removed in the first step
            goto end;
        }
        if ( ! empty( $matches['regexp'] ) ) {
            // regular expression
            // only if the space after the regexp contains a newline, keep it
            \preg_match(
                '~^'.$this->expressions->regexp.'(?P<post>'.$this->expressions->optionalWhitespaceNewline.')$~su',
                $match,
                $newMatches,
            );
            $postfix = ! \str_contains( $newMatches['post'], "\n" ) ? '' : "\n";
            $match   = $trimmed.$postfix;
            goto end;
        }

        end:

        return \str_replace(
            $this->expressions->whitespaceArrayNewline,
            $this->expressions->whitespaceArrayPlaceholders,
            $match,
        );
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
