<?php

namespace Northrook\Minify;

use Northrook\Minify;

/**
 * Could very likely use JShrink
 * @link https://github.com/tedious/JShrink
 */
final class JS extends Minify
{

    protected function minify() : void {
        $this->trimBlockComments()
             ->trimSingleComments()
             ->trimWhitespace();
    }

}