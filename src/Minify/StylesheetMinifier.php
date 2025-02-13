<?php

declare(strict_types=1);

namespace Support\Minify;

use Support\Minify;
use Stringable;
use SplFileInfo;
use Support\Minify\StylesheetMinifier\Compiler;

final class StylesheetMinifier extends Minify
{
    private readonly Compiler $compiler;

    /** @var string[] */
    private array $sources = [];

    public function minify() : self
    {
        if ( $this->content ) {
            return $this;
        }

        $this->validateSource();

        // Initialize the compiler from provided $sources
        $this->compiler ??= new Compiler( $this->sources, $this->logger );
        $this->compiler
            ->parseEnqueued()
            ->mergeRules()
            ->generateStylesheet();

        $this->content = $this->compiler->css;

        $this->sizeAfter = \mb_strlen( $this->content ?? '' );

        return $this;
    }

    public function setSource( string|Stringable ...$source ) : static
    {
        foreach ( $source as $value ) {
            $this->sources[] = (string) $value;
        }

        return $this;
    }

    private function validateSource() : void
    {
        if ( isset( $this->content ) ) {
            $this->logger?->info( '{method} already called.', ['method' => __METHOD__] );
            return;
        }

        $sources = [];

        foreach ( $this->sources as $source ) {
            if ( ! $source ) {
                // TODO : [low] Log/throw on empty source
                continue;
            }

            if ( \str_contains( $source, '{' ) && \str_contains( $source, '}' ) ) {
                $source = $this->normalizeNewline( $source );
                $sources['raw:'.$this->hash( $source )] ??= $source;

                continue;
            }

            $path = new SplFileInfo( $source );

            // If the source is a valid, readable path, add it
            if ( $path->getExtension() === 'css' && $path->isReadable() ) {
                $source = \file_get_contents( $path->getPathname() );
                $source = $this->normalizeNewline( $source );
                $sources['css:'.$this->hash( $source )] ??= $source;

                continue;
            }

            $this->logger?->error(
                'Unable to add new source {source}, the path is not valid.',
                ['source' => $source, 'path' => $path],
            );
        }

        $this->sources = $sources;

        $this->sizeBefore = \mb_strlen( \implode( '', $this->sources ), 'UTF-8' );
    }

    private function hash( string|Stringable $value ) : string
    {
        return \hash( algo : 'xxh3', data : (string) $value );
    }
}
