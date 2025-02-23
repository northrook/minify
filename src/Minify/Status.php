<?php

declare(strict_types=1);

namespace Support\Minify;

use DateTimeImmutable;
use LogicException;
use function Support\timestamp;

/**
 * @internal
 * @author Martin Nielsen <mn@northrook.com>
 */
final class Status
{
    protected null|int|float $timer = null;

    protected ?int $sourceBytes = null;

    protected ?int $minifiedBytes = null;

    public readonly DateTimeImmutable $timestamp;

    /**
     * @internal
     *
     * @param string ...$source
     *
     * @return void
     */
    public function setSourceBytes( string ...$source ) : void
    {
        $this->sourceBytes = \mb_strlen( \implode( '', $source ), '8bit' );
    }

    /**
     * @internal
     *
     * @param string $buffer
     *
     * @return void
     */
    public function setMinifiedBytes( string $buffer ) : void
    {
        $this->minifiedBytes = \mb_strlen( $buffer, '8bit' );
    }

    /**
     * @internal
     *
     * @param bool $stop
     *
     * @return self
     */
    public function timer( bool $stop = false ) : self
    {
        $this->timestamp ??= timestamp();

        if ( $stop ) {
            if ( ! $this->timer ) {
                throw new LogicException( 'Timer has not been started.' );
            }

            if ( \is_float( $this->timer ) ) {
                throw new LogicException( 'The Report timer has ended.' );
            }

            $this->timer = \hrtime( true ) - $this->timer;
            $this->timer *= 1e-6;
        }

        $this->timer ??= \hrtime( true );

        return $this;
    }

    public function getElapsedTime() : ?string
    {
        if ( ! $this->timer ) {
            throw new LogicException( 'Timer has not been started.' );
        }

        $time = \number_format( $this->timer, 3, '.', '' );

        $time = \str_pad( $time, 4, '0' );

        return $time ? $time.'ms' : null;
    }

    /**
     * @return array{0: ?int, 1: ?int}
     */
    public function getBytes() : array
    {
        return [
            $this->sourceBytes,
            $this->minifiedBytes,
        ];
    }
}
