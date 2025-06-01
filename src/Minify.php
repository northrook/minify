<?php

declare(strict_types=1);

namespace Support;

use Cache\CacheHandler;
use Core\Exception\InvalidStateException;
use Core\Interface\ProfilerInterface;
use Core\Autowire\{Logger};
use Core\Profiler;
use Psr\Log\LoggerInterface;
use Psr\Cache\CacheItemPoolInterface;
use Stringable, LogicException;
use Support\Minify\Exception\SourceNotFoundException;
use Support\Minify\{Output, Source};
use Support\Minify\Source\Type;
use Symfony\Component\Stopwatch\Stopwatch;

/**
 * @internal
 *
 * @used-by JavaScriptMinifier, StylesheetMinifier
 */
abstract class Minify implements Stringable
{
    use Logger;

    private readonly Output $output;

    // private readonly Report $report;

    // private readonly Status $status;

    protected readonly CacheHandler $cache;

    protected readonly ProfilerInterface $profiler;

    protected bool $bundleImports = false;

    protected bool $locked = false;

    protected ?string $key;

    protected ?int $fingerprint = null;

    protected bool $useCache;

    /** @var array<array-key, string> */
    protected array $source = [];

    /** @var array<string, Source> */
    protected array $sources = [];

    /** @var array<string, string> */
    protected array $process = [];

    /** @var string[] */
    protected array $comments = [];

    protected string $buffer = PLACEHOLDER_STRING;

    /**
     * @param null|CacheItemPoolInterface           $cachePool
     * @param null|LoggerInterface                  $logger
     * @param null|bool|ProfilerInterface|Stopwatch $profiler
     */
    final public function __construct(
        ?CacheItemPoolInterface               $cachePool = null,
        ?LoggerInterface                      $logger = null,
        null|bool|Stopwatch|ProfilerInterface $profiler = null,
    ) {
        $this->setLogger( $logger, true );
        $this->cache = new CacheHandler(
            adapter : $cachePool,
            prefix  : 'minify',
            logger  : $logger,
        );
        $this->profiler = $profiler instanceof ProfilerInterface
                ? $profiler
                : new Profiler( $profiler );
        $this->profiler->setCategory( $this::class );
        // $this->status = new Status();
    }

    final public function bundleImports( bool $set = true ) : self
    {
        $this->validateLockState();

        $this->bundleImports = $set;

        return $this;
    }

    /**
     * Add one or more sources.
     *
     * @param string|Stringable ...$source
     *
     * @return $this
     */
    final public function setSource( string|Stringable ...$source ) : static
    {
        $this->validateLockState();

        foreach ( $source as $key => $value ) {
            if ( \is_int( $key ) ) {
                $key = $value instanceof Stringable
                        ? class_id( $value, true )
                        : \hash( algo : 'xxh64', data : $value );
            }
            if ( \array_key_exists( $key, $this->sources ) ) {
                $this->log(
                    message : '{method} The source {key} already exists.',
                    context : [
                        'method' => __METHOD__,
                        'key'    => $key,
                        'value'  => $value,
                        'stack'  => \debug_backtrace( DEBUG_BACKTRACE_IGNORE_ARGS ),
                    ],
                    level   : 'notice',
                );
            }

            $this->source[$key] ??= new Source( $value );
        }

        return $this;
    }

    final public function minify(
        ?string $key = null,
        ?int    $cacheExpiration = AUTO,
        ?bool   $deferCache = AUTO,
    ) : self {
        $this->key = $key;

        if ( $this->preflight() ) {
            return $this;
        }

        if ( $this->useCachedResult() ) {
            return $this;
        }

        $this->loadSources();

        // $this->status->setSourceBytes( ...$this->process );
        $this->buffer = $this->process();
        // $this->status->setMinifiedBytes( $this->buffer );

        $this->output = new Output(
            $this->key,
            $this->fingerprint,
            $this->buffer,
            // $this->getReport(),
        );
        unset( $this->buffer );

        // $this->status->timer( true );

        if ( $this->key ) {
            $this->cache->set( $this->key, $this->output->array(), $cacheExpiration, $deferCache );
        }
        return $this;
    }

    /**
     * @return bool
     */
    private function preflight() : bool
    {
        if ( $this->locked ) {
            return true;
        }

        $this->locked = true;

        $this->profiler->start( 'preflight' );

        // $this->status->timer();

        foreach ( $this->source as $key => $path ) {
            $source = new Source( $path );

            if ( $source->exists() ) {
                // Add it to sources
                $this->sources[$key] = $source;
            }
            else {
                $this->log( new SourceNotFoundException( $source ) );

                continue;
            }

            if ( $this->bundleImports ) {
                foreach ( $source->getImports() as $hash => $import ) {
                    $this->sources[$hash] = $import;
                }
            }
        }

        $version = [];

        foreach ( $this->sources as $key => $source ) {
            $lastModified = $source->lastModified();

            if ( $lastModified === false ) {
                $lastModified = \time();
                $this->log(
                    'Unable to get lastModified from source {source}.',
                    ['source' => $source],
                    'warning',
                );
            }

            $version[$key] = $lastModified;
        }

        $this->key ??= key_hash( 'xxh64', \array_keys( $this->source ) );

        $this->fingerprint = num_xor( $version ) ?: \max( $version );

        $this->key .= '-'.$this->fingerprint;

        $this->useCache = $this->cache->has( $this->key );

        $this->profiler->stop( 'preflight' );

        return false;
    }

    private function useCachedResult() : bool
    {
        if ( $this->useCache === false ) {
            return false;
        }

        $cached = $this->cache->get( $this->key );

        if ( $cached && ( $this->fingerprint === $cached['fingerprint'] ) ) {
            $this->log(
                '{method} => {action} cache',
                [
                    'method' => __METHOD__,
                    'action' => 'valid',
                ],
                'notice',
            );

            $this->output = new Output( ...$cached );
            // $this->report = $this->output->report;
        }
        else {
            $this->log(
                '{method} => {action} cache',
                [
                    'method' => __METHOD__,
                    'action' => 'invalid',
                ],
                'warning',
            );

            $this->useCache = false;
        }

        return $this->useCache;
    }

    private function loadSources() : self
    {
        foreach ( $this->sources as $hash => $source ) {
            $content = null;
            if ( $source->type === Type::STRING ) {
                $content = $source->get();
            }
            elseif ( $source->type === Type::PATH ) {
                $content = \file_get_contents( $source->get() );
            }
            elseif ( $source->type === Type::URL ) {
                dump( [__METHOD__ => $source->get()] );
            }

            if ( ! $content ) {
                continue;
            }

            foreach ( $source->importStatements as $statement ) {
                $content = \str_replace( $statement, '', $content );
            }

            $this->process[$hash] = $content;
        }

        return $this;
    }

    abstract protected function process() : string;

    final protected function sourceHash( string|Stringable $value ) : string
    {
        return $value instanceof Stringable
                ? class_id( $value, true )
                : \hash( algo : 'xxh64', data : $value );
    }

    final public function usedCache() : bool
    {
        if ( ! isset( $this->useCache ) ) {
            throw new LogicException( "The 'usedCache()' method must be called after 'minify()'." );
        }
        return $this->useCache;
    }

    // final public function getReport() : Report
    // {
    //     if ( isset( $this->result, $this->result->report ) ) {
    //         return $this->result->report;
    //     }
    //     return $this->report ??= new Report(
    //         $this->key,
    //         $this::class,
    //         $this->status,
    //     );
    // }

    public function getBuffer() : string
    {
        return $this->buffer;
    }

    final public function getString() : string
    {
        return $this->output->string;
    }

    final public function getOutput() : Output
    {
        return $this->output;
    }

    final public function __toString() : string
    {
        return $this->output->string;
    }

    final public static function JS(
        string|Stringable $source,
    ) : JavaScriptMinifier {
        return ( new JavaScriptMinifier() )->setSource( $source );
    }

    /**
     * @param string|Stringable ...$source
     *
     * @return StylesheetMinifier
     */
    final public static function CSS(
        string|Stringable ...$source,
    ) : StylesheetMinifier {
        return ( new StylesheetMinifier() )->setSource( ...$source );
    }

    final protected function sourceName( string $string ) : string
    {
        $string = \strstr( $string, '.', true ) ?: $string;

        $string = (string) \preg_replace( '/[^a-z0-9.]+/i', '.', $string );

        $string = \trim( $string, '.' );

        return \strtolower( $string );
    }

    final protected function validateLockState() : void
    {
        if ( $this->locked ) {
            throw new InvalidStateException(
                $this::class.' has been locked by the compile proccess.',
            );
        }
    }
}
