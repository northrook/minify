<?php

declare(strict_types=1);

namespace Support;

use Cache\CacheHandler;
use Core\Interface\{LogHandler, Loggable};
use Psr\Log\LoggerInterface;
use Psr\Cache\CacheItemPoolInterface;
use Support\Minify\{Report, Result, Status};
use Stringable, LogicException;

abstract class Minify implements Stringable, Loggable
{
    use LogHandler;

    private readonly Result $result;

    private readonly Report $report;

    protected readonly CacheHandler $cache;

    protected readonly Status $status;

    protected bool $locked = false;

    protected ?string $key;

    protected ?string $hash = null;

    protected bool $useCache;

    protected string $buffer = PLACEHOLDER_STRING;

    /**
     * @param null|CacheItemPoolInterface $cachePool
     * @param null|LoggerInterface        $logger
     */
    final public function __construct(
        ?CacheItemPoolInterface             $cachePool = null,
        protected readonly ?LoggerInterface $logger = null,
    ) {
        $this->setLogger( $logger );
        $this->cache = new CacheHandler(
            adapter : $cachePool,
            prefix  : 'minify',
            logger  : $logger,
        );
        $this->status = new Status();
    }

    final public function minify(
        ?string $key = null,
        ?int    $cacheExpiration = AUTO,
        ?bool   $deferCache = AUTO,
    ) : self {
        if ( $this->preflight( $key ) ) {
            return $this;
        }

        if ( $this->useCache ) {
            $cached = $this->cache->get( $this->key );

            if ( $cached && ( $this->hash === $cached['hash'] ) ) {
                $this->result = new Result( ...$cached );

                return $this;
            }

            // Indicate the cache was not used
            $this->useCache = false;
        }

        // ..  Skip if `$key` and `$hash` match a cached result
        $this->process();

        $this->status->timer( true );

        $result = [
            'key'     => $this->key,
            'hash'    => $this->hash,
            'version' => \hash( 'xxh32', $this->hash ),
            'string'  => $this->buffer,
            'report'  => \serialize( $this->getReport() ),
        ];

        if ( $this->key ) {
            $this->cache->set( $this->key, $result, $cacheExpiration, $deferCache );
        }

        $this->result = new Result( ...$result );
        unset( $this->buffer );
        return $this;
    }

    /**
     * @param null|string $key from {@see self::minify()}
     *
     * @return bool
     */
    final protected function preflight( ?string $key ) : bool
    {
        if ( $this->locked ) {
            return true;
        }

        $this->status->timer();

        [$this->key, $this->hash] = $this->prepare( $key );

        $this->useCache = $this->cache->has( $this->key );
        $this->locked   = true;

        return false;
    }

    /**
     * Parses and prepares provided `$source`.
     *
     * @param ?string $key
     *
     * @return array{0: ?string, 1: string} `key,hash`
     */
    abstract protected function prepare( ?string $key ) : array;

    abstract protected function process() : void;

    /**
     * Assign a source value.
     *
     * @param string|Stringable ...$source
     *
     * @return $this
     */
    final public function setSource( string|Stringable ...$source ) : static
    {
        if ( $this->locked ) {
            throw new LogicException( $this::class.' has been locked by the compile proccess.' );
        }

        if ( ! \property_exists( $this, 'source' ) ) {
            throw new LogicException( 'The source property is not defined.' );
        }

        if ( \is_string( $this->source ) ) {
            $this->source = (string) \current( $source );
        }
        elseif ( \is_array( $this->source ) ) {
            foreach ( $source as $value ) {
                $key = $value instanceof Stringable
                        ? class_id( $value, true )
                        : $this->sourceHash( $value );

                // if ( ! isset( $this->source[$key] ) ) {
                //     unset( $this->source[$key] );
                // }

                $this->source[$key] ??= (string) $value;
            }
        }
        else {
            $minifier = $this::class;
            $message  = "The '{$minifier}' source property must be string or array. ";
            throw new LogicException( $message );
        }

        return $this;
    }

    final public function usedCache() : bool
    {
        if ( ! isset( $this->useCache ) ) {
            throw new LogicException( "The 'usedCache()' method must be called after 'minify()'." );
        }
        return $this->useCache;
    }

    final public function getReport() : Report
    {
        if ( isset( $this->result, $this->result->report ) ) {
            return $this->result->report;
        }
        return $this->report ??= new Report(
            $this->key,
            $this::class,
            $this->status,
        );
    }

    public function getBuffer() : string
    {
        return $this->buffer;
    }

    final public function getOutput() : string
    {
        return $this->result->string;
    }

    final public function getResult() : Result
    {
        return $this->result;
    }

    final public function __toString() : string
    {
        return $this->result->string;
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

    final protected function sourceHash( string|Stringable $value ) : string
    {
        return \hash( algo : 'xxh64', data : normalize_newline( $value ) );
    }
}
