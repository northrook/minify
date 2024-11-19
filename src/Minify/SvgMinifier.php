<?php

namespace Northrook\Minify;

use Northrook\Minify;


// Following TODOs should find a home in the SvgMinifier class, as they add to the SvgMinifier string
// The Trim class should only be used stripping unwanted substrings
// They have just been put here because it is convenient fo me right now

// TODO - Automatically add height and width attributes based on viewBox

// TODO - Automatically add viewBox attribute based on width and height

// TODO - Automatically add preserveAspectRatio attribute based on width and height

// TODO - Warn if baked-in colors are used, preferring 'currentColor' instead

// TODO - Option to use StylesheetMinifier variables

final class SvgMinifier extends Minify
{
    public bool $preserveXmlNamespace = false;

    // protected function minifyString() : void {
    //     $this->trimHtmlComments()
    //          ->trimWhitespace()
    //          ->trimXmlNamespace();
    // }
    //
    // private function trimXmlNamespace() : SvgMinifier {
    //     $this->string = preg_replace(
    //         pattern     : '#(<svg[^>]*?)\s+xmlns="[^"]*"#',
    //         replacement : '$1',
    //         subject     : $this->string,
    //     );
    //
    //     return $this;
    // }
    protected function compile( array $sources ) : string
    {
        return \implode( '', $sources );
    }
}
