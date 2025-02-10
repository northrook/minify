<?php

declare(strict_types=1);

namespace Northrook;

use LogicException;
use Northrook\StylesheetMinifier\Compiler;
use Psr\Log\LoggerInterface;
use Core\Pathfinder\Path;
use Stringable;
use function String\{hashKey, sourceKey};

/**
 * @author Martin Nielsen <mn@northrook.com>
 */
final class StylesheetMinifier extends Minify implements MinifierInterface
{
    protected const ?string EXTENSION = 'css';

    public const string CLERK_GROUP = 'minify_css';

    private readonly Compiler $compiler;

    /**
     * @param array<array-key, string|Stringable> $sources Will be scanned for .css files
     * @param ?LoggerInterface                    $logger  Optional PSR-3 logger
     */
    public function __construct(
        array                               $sources = [],
        protected readonly ?LoggerInterface $logger = null,
    ) {
        Clerk::event( $this::class, $this::CLERK_GROUP );
        $this->addSource( ...\array_values( $sources ) );
        Clerk::event( $this::class.'::initialized', $this::CLERK_GROUP );
    }

    /**
     * Add one or more stylesheets to this generator.
     *
     * Accepts raw CSS, or a path to a CSS file.
     *
     * @param string|Stringable ...$source
     *
     * @return self
     */
    public function addSource( string|Stringable ...$source ) : self
    {
        // TODO : [low] Support URL

        $this->throwIfLocked( 'Unable to add new source; locked by the build proccess.' );

        foreach ( $source as $data ) {
            if ( ! $data ) {
                $this->logger?->warning(
                    $this::class.' was provided an empty source string. It was not enqueued.',
                    ['sources' => $source],
                );

                continue;
            }

            // If the $source contains brackets, assume it is a raw CSS string
            if ( \is_string( $data ) && ( \str_contains( $data, '{' ) && \str_contains( $data, '}' ) ) ) {
                $this->sources['raw:'.hashKey( $data )] ??= $data;

                continue;
            }

            $path = $source instanceof Path ? $data : new Path( $data );

            // If the source is a valid, readable path, add it
            if ( $path->getExtension() === 'css' && $path->isReadable() ) {
                $this->sources["{$path->getExtension()}:".sourceKey( $path )] ??= $path;

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
