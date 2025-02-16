<?php

declare(strict_types=1);

namespace Support\Minify;

use Support\Minify;
use Support\Minify\StylesheetMinifier\Compiler;
use Stringable, SplFileInfo;

final class StylesheetMinifier extends Minify
{
    private readonly Compiler $compiler;

    /** @var string[] */
    private array $sources = [];

    public function minify( ?string $key = null ) : self
    {
        if ( $this->content ) {
            return $this;
        }

        if ( $this->cachePool && $this->useCachedContent( $key ) ) {
            return $this;
        }

        $this->validateSources();

        // Initialize the compiler from provided $sources
        $this->compiler ??= new Compiler( $this->sources, $this->logger );
        $this->compiler
            ->parseEnqueued()
            ->mergeRules()
            ->generateStylesheet();

        $this->content = $this->compiler->css;

        $this->sizeAfter = \mb_strlen( $this->content ?? '' );

        if ( $this->cachePool && $this->key ) {
            $this->updateCache( $this->version, $this->content );
        }

        return $this;
    }

    public function setSource( string|Stringable ...$source ) : static
    {
        foreach ( $source as $value ) {
            $this->sources[] = (string) $value;
        }

        return $this;
    }

    private function validateSources() : void
    {
        if ( isset( $this->content ) ) {
            $this->logger?->info( '{method} already called.', ['method' => __METHOD__] );
            return;
        }

        $sources = [];

        foreach ( $this->sources as $source ) {
            if ( ! $source ) {
                continue;
            }

            if ( $this->rawStyleString( $source ) ) {
                $source = $this->normalizeNewline( $source );
                $hash   = $this->sourceHash( $source );
                $sources['raw:'.$hash] ??= $source;

                continue;
            }

            $path = new SplFileInfo( $source );

            // If the source is a valid, readable path, add it
            if ( $path->getExtension() === 'css' && $path->isReadable() ) {
                $source = \file_get_contents( $path->getPathname() );
                $name   = $this->sourceName( $path->getPathname() );
                $sources['css:'.$name] ??= $this->normalizeNewline( $source );

                continue;
            }

            $this->logger?->error(
                'Unable to add new source {source}, the path is not valid.',
                [
                    'source' => $source,
                    'path'   => $path,
                ],
            );
        }

        $this->sources = $sources;

        $this->sizeBefore = \mb_strlen( \implode( '', $this->sources ), 'UTF-8' );
    }

    /**
     * A key will be generated from provided file paths.
     *
     * @param null|string $key
     *
     * @return bool
     */
    protected function useCachedContent( ?string $key = null ) : bool
    {
        $version = '';
        $autoKey = '';

        foreach ( $this->sources as $source ) {
            if ( $this->rawStyleString( $source ) ) {
                $version .= $this->sourceHash( $source );
            }
            elseif ( \file_exists( $source ) ) {
                $autoKey .= $source;
                $version .= \filemtime( $source );
            }
            else {
                $this->logger?->error(
                    'Unable to add new source {source}, the path is not valid.',
                    [
                        'source' => $source,
                    ],
                );
            }
        }

        if ( ! ( $key ?? $autoKey ) ) {
            $this->logger?->warning(
                '{class}: No key set or derived from sources. Results will not be cached.',
                ['class' => $this::class],
            );
            $this->key = null;
            return false;
        }

        $this->key     = $key ?? $this->sourceHash( $autoKey );
        $this->version = $this->sourceHash( $version );

        ['hash' => $hash, 'data' => $data] = $this->getCached( $this->key );

        if ( $this->version === $hash && $data ) {
            $this->content   = $data;
            $this->usedCache = true;
        }

        return $this->usedCache;
    }

    private function rawStyleString( string $string ) : bool
    {
        return \str_contains( $string, '{' ) && \str_contains( $string, '}' );
    }
}
