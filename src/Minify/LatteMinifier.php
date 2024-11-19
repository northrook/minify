<?php

namespace Northrook\Minify;

use Northrook\Minify;

final class LatteMinifier extends Minify
{
    protected function compile( array $sources ) : string
    {
        return \implode( '', $sources );
    }
}
