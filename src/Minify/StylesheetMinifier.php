<?php

declare(strict_types=1);

namespace Northrook\Minify;

use LogicException;
use Northrook\{Clerk};
use Northrook\Minify\Stylesheet\Compiler;
use Northrook\Resource\Path;
use Psr\Log\LoggerInterface;
use function String\{hashKey, sourceKey};
/**
 * @author Martin Nielsen <mn@northrook.com>
 */
final class StylesheetMinifier
{
    private readonly Compiler $compiler;

    /** @var array<string, Path|string> */
    private array $sources = [];

    protected bool $locked = false;

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
     * @param Path|string ...$add
     *
     * @return $this
     */
    final public function addSource( string|Path ...$add ) : self
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

    final public function minify() : string
    {
        Clerk::event( __METHOD__ );

        // Lock the $sources
        $this->locked = true;

        // Initialize the compiler from provided $sources
        $this->compiler ??= new Compiler(
            $this->enqueueSources( $this->sources ),
            $this->logger,
        );
        $this->compiler->parseEnqueued()
            ->mergeRules()
            ->generateStylesheet();

        $this->locked = false;

        Clerk::event( __METHOD__ )->stop();
        return $this->compiler->css;
    }

    private function enqueueSources( array $sources ) : array
    {
        foreach ( $sources as $index => $source ) {
            $value = $source instanceof Path ? $source->read : $source;

            if ( ! $value ) {
                $this->logger?->critical(
                    $this::class.' is unable to read source "{source}"',
                    ['source' => $source],
                );
            }
            $sources[$index] = $value;
        }
        return $sources;
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
