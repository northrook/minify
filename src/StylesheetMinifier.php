<?php

declare(strict_types=1);

namespace Support;

use Support\StylesheetMinifier\Compiler;

final class StylesheetMinifier extends Minify
{
    private readonly Compiler $compiler;

    /** @var string[] */
    protected array $source = PLACEHOLDER_ARRAY;

    protected function prepare( ?string $key ) : array
    {
        $autoKey = '';
        $version = '';

        foreach ( $this->source as $source ) {
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
        foreach ( $this->source as $index => $source ) {
            if ( \file_exists( $source ) ) {
                $source = \file_get_contents( $source );
            }

            if ( ! $this->rawStyleString( $source ) ) {
                $this->logger?->notice( 'Source {source} was skipped; either file not fond or empty.', ['source' => $source] );
            }

            $this->source[$index] = normalizeNewline( $source );
        }

        $this->status->setSourceBytes( ...$this->source );

        // Initialize the compiler from provided $sources
        $this->compiler ??= new Compiler( $this->source, $this->logger );
        $this->compiler
            ->parseEnqueued()
            ->mergeRules()
            ->generateStylesheet();

        $this->buffer = $this->compiler->css;

        $this->status->setMinifiedBytes( $this->buffer );
    }

    private function rawStyleString( string $string ) : bool
    {
        return str_includes( $string, '{;}' );
    }
}
