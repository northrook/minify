<?php

namespace Northrook\Minify;

use Northrook\Minify;

final class LatteMinifier extends Minify
{
    protected function minifyString() : void {
        $this->trimBlockComments()
             ->trimLatteComments()
             ->trimWhitespace( removeNewlines : false );
    }

}