<?php

namespace Northrook\Minify;

use Northrook\Minify;

final class Latte extends Minify
{
    protected function minify() : void {
        $this->trimBlockComments()
             ->trimLatteComments()
             ->trimWhitespace();
    }

}