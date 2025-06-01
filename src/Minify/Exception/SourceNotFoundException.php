<?php

namespace Support\Minify\Exception;

use Core\Exception\ErrorException;
use Support\Minify\Source;
use Exception;

final class SourceNotFoundException extends Exception
{
    public function __construct( public readonly Source $source )
    {
        parent::__construct(
            "Unable to ingest source {$this->source}, it does not exist.",
            E_RECOVERABLE_ERROR,
            ErrorException::getLast(),
        );
    }
}
