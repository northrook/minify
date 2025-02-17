<?php

declare(strict_types=1);

namespace Support;

use Psr\Log\{LoggerInterface};
use Psr\Cache\{CacheItemInterface, CacheItemPoolInterface, InvalidArgumentException};
use Support\Minify\{JavaScriptMinifier, StylesheetMinifier};
use Symfony\Component\Stopwatch\Stopwatch;
use Stringable, LogicException;

// .. CSS
//    Merge and minify multiple CSS files or strings into one cohesive CSS string

// :: JavaScript
//    Minify one, or merge by `import` statement
//    Can be passed to external minifier API

abstract class Minify implements Stringable
{
    protected const string NEWLINE = "\n";

    protected ?string $key;

    protected ?string $version = null;

    protected readonly ?CacheItemInterface $cache;

    protected bool $usedCache = false;

    protected int $sizeBefore;

    protected int $sizeAfter;

    public ?string $content = null;

    /**
     * @param null|CacheItemPoolInterface $cachePool
     * @param null|LoggerInterface        $logger
     * @param ?Stopwatch                  $stopwatch
     */
    final public function __construct(
        protected readonly ?CacheItemPoolInterface $cachePool = null,
        protected readonly ?LoggerInterface        $logger = null,
        protected readonly ?Stopwatch              $stopwatch = null,
    ) {}

    final public function getReport() : string
    {
        $source = $this->cachePool ? $this->cache->get() : $this->createReport();

        return $source['report'];
    }

    final public function usedCache() : bool
    {
        return $this->usedCache;
    }

    final protected function updateCache(
        string $hash,
        string $data,
    ) : string {
        $var = \get_defined_vars() + $this->createReport();

        $this->cache->set( $var );

        $this->cachePool->save( $this->cache );

        return $data;
    }

    /**
     * @return array{report: string, status: array{initialKb: float, minifiedKb: float, differenceKb: float, differencePercent: float}}
     */
    private function createReport() : array
    {
        $beforeKB = (float) Num::byteSize( $this->sizeBefore );
        $afterKB  = (float) Num::byteSize( $this->sizeAfter );

        $deltaKb = $beforeKB - $afterKB;
        $percent = Num::percentDifference( $beforeKB, $afterKB );

        return [
            'report' => "Minified {$percent}%. {$beforeKB}KB to {$afterKB}KB, saving {$deltaKb}KB.",
            'status' => [
                'initialKb'         => $beforeKB,
                'minifiedKb'        => $afterKB,
                'differenceKb'      => $deltaKb,
                'differencePercent' => $percent,
            ],
        ];
    }

    /**
     * @param string $key
     *
     * @return array{'hash': ?string, 'data': ?string}
     */
    final protected function getCached( string $key ) : array
    {
        try {
            $this->cache ??= $this->cachePool->getItem( "minify.{$key}" );
        }
        catch ( InvalidArgumentException $e ) {
            throw new LogicException( $e->getMessage(), $e->getCode(), $e );
        }

        if ( ! $this->cache->isHit() ) {
            return [
                'hash' => null,
                'data' => null,
            ];
        }

        $var = $this->cache->get();

        return \array_slice( $var, 0, 2 );
    }

    abstract public function setSource( string|Stringable ...$source ) : static;

    abstract public function minify( ?string $key = null ) : self;

    final public function __toString() : string
    {
        return $this->content ?? $this->minify()->content;
    }

    final public static function JS(
        string|Stringable $source,
        bool              $imports = false,
    ) : JavaScriptMinifier {
        $minifier = new JavaScriptMinifier();
        return $minifier->setSource( $source );
    }

    /**
     * @param string|Stringable ...$source
     *
     * @return StylesheetMinifier
     */
    final public static function CSS(
        string|Stringable ...$source,
    ) : StylesheetMinifier {
        $minifier = new StylesheetMinifier();

        return $minifier->setSource( ...$source );
    }

    /**
     * @param string $string
     *
     * @return string
     */
    final protected function normalizeNewline( string $string ) : string
    {
        return \str_replace( [PHP_EOL, "\r\n", "\r"], $this::NEWLINE, $string );
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
        return \hash( algo : 'xxh3', data : $this->normalizeNewline( (string) $value ) );
    }
}
