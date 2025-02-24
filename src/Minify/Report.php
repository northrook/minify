<?php

declare(strict_types=1);

namespace Support\Minify;

use Core\Interface\DataInterface;
use DateTimeImmutable;
use Support\Num;

/**
 * @internal
 * @author Martin Nielsen <mn@northrook.com>
 */
final readonly class Report implements DataInterface
{
    public DateTimeImmutable $timestamp;

    public string $string;

    public string $timeElapsed;

    public float $sizeOriginal;

    public float $sizeMinified;

    public float $sizeDiff;

    public float $diffPercent;

    public function __construct( public ?string $key, public string $generator, Status $status )
    {
        $this->timestamp   = $status->timestamp;
        $this->timeElapsed = $status->getElapsedTime();

        [$this->sizeOriginal, $this->sizeMinified] = $status->getBytes();
        $this->sizeDiff                            = $this->sizeOriginal - $this->sizeMinified;
        $this->diffPercent                         = Num::percentDifference( $this->sizeOriginal, $this->sizeMinified );

        $originalKb = (float) Num::byteSize( $this->sizeOriginal );
        $minifiedKb = (float) Num::byteSize( $this->sizeMinified );
        $diffKB     = $originalKb - $minifiedKb;

        $report = [
            "{$this->generator} reduced",
            $this->key,
            "by {$this->diffPercent}%.",
            "{$originalKb}KB to {$minifiedKb}KB, saving {$diffKB}KB.",
            "Taking {$this->timeElapsed} to complete.",
        ];

        $this->string = \trim( \implode( ' ', \array_filter( $report ) ), " \n\r\t\v\0." ).'.';
    }
}
