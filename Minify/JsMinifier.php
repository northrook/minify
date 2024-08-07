<?php

namespace Northrook\Minify;

use Northrook\Minify;

/**
 * Could very likely use JShrink
 * @link https://github.com/tedious/JShrink
 */
final class JsMinifier extends Minify
{

    protected function minifyString() : void {
        $this->trimBlockComments()
             ->trimSingleComments()
             ->trimWhitespace();
    }

}