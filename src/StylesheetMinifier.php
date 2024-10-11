<?php

declare(strict_types=1);

namespace Northrook;

use LogicException;
use Northrook\Filesystem\Resource;
use Northrook\Resource\Path;
use Northrook\StylesheetMinifier\Compiler;
use Psr\Log\LoggerInterface;
use function String\{hashKey, sourceKey};

/**
 * @author Martin Nielsen <mn@northrook.com>
 */
final class StylesheetMinifier extends Minify
{
    protected const ?string EXTENSION = 'css';

    private readonly Compiler $compiler;

    /**
     * @param array<array-key, Path|string> $sources Will be scanned for .css files
     * @param ?LoggerInterface              $logger  Optional PSR-3 logger
     */
    public function __construct(
        array                               $sources = [],
        protected readonly ?LoggerInterface $logger = null,
    ) {
        Clerk::event( $this::class, 'document' );
        $this->addSource( ...$sources );
        Clerk::event( $this::class.'::initialized', 'document' );
    }

    /**
     * Add one or more stylesheets to this generator.
     *
     * Accepts raw CSS, or a path to a CSS file.
     *
     * @param resource|string ...$add
     *
     * @return $this
     */
    final public function addSource( string|Resource ...$add ) : self
    {
        // TODO : [low] Support URL

        $this->throwIfLocked( 'Unable to add new source; locked by the build proccess.' );

        foreach ( $add as $source ) {
            if ( ! $source ) {
                $this->logger?->warning(
                    $this::class.' was provided an empty source string. It was not enqueued.',
                    ['sources' => $add],
                );

                continue;
            }

            // If the $source contains brackets, assume it is a raw CSS string
            if ( \is_string( $source ) && ( \str_contains( $source, '{' ) && \str_contains( $source, '}' ) ) ) {
                $this->sources['raw:'.hashKey( $source )] ??= $source;

                continue;
            }

            $path = $source instanceof Path ? $source : new Path( $source );

            // If the source is a valid, readable path, add it
            if ( 'css' === $path->extension && $path->isReadable ) {
                $this->sources["{$path->extension}:".sourceKey( $path )] ??= $path;

                continue;
            }

            $this->logger?->error(
                'Unable to add new source {source}, the path is not valid.',
                ['source' => $source, 'path' => $path],
            );
        }

        return $this;
    }

    protected function compile( array $sources ) : string
    {
        // Initialize the compiler from provided $sources
        $this->compiler ??= new Compiler( $sources, $this->logger );
        $this->compiler->parseEnqueued()
            ->mergeRules()
            ->generateStylesheet();

        return $this->compiler->css;
    }

    // ? Locked

    /**
     * Check if the {@see StylesheetMinifier} is locked.
     *
     * @return bool
     */
    public function isLocked() : bool
    {
        return $this->locked;
    }

    /**
     * @param ?string $message Optional message
     *
     * @return void
     */
    private function throwIfLocked( ?string $message = null ) : void
    {
        if ( $this->locked ) {
            throw new LogicException( $message ?? $this::class.' has been locked by the compile proccess.' );
        }
    }
}