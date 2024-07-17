<?php

namespace Northrook\Minify;

use Northrook\Minify;

final class Latte extends Minify
{
    protected function minifyString() : void {
        $this->trimBlockComments()
             ->trimLatteComments()
             ->trimWhitespace();
    }

}