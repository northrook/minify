<?php

namespace Northrook;

use Stringable;

interface MinifierInterface extends Stringable
{
    public function addSource( string|Stringable ...$source ) : self;

    public function minify() : string;
}
