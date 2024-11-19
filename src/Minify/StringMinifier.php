<?php

namespace Northrook\Minify;

use Northrook\Minify;

/**
 * @internal
 */
final class StringMinifier extends Minify
{
    protected function compile( array $sources ) : string
    {
        return \implode( '', $sources );
    }
}
