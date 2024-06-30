<?php

namespace Northrook\Minify;

use Northrook\Minify;


// Following TODOs should find a home in the SVG class, as they add to the SVG string
// The Trim class should only be used stripping unwanted substrings
// They have just been put here because it is convenient for me right now

// TODO - Automatically add height and width attributes based on viewBox

// TODO - Automatically add viewBox attribute based on width and height

// TODO - Automatically add preserveAspectRatio attribute based on width and height

// TODO - Warn if baked-in colors are used, preferring 'currentColor' instead

// TODO - Option to use CSS variables

final class SVG extends Minify
{
    public bool $preserveXmlNamespace = false;

    protected function minify() : void {
        $this->trimHtmlComments()
             ->trimWhitespace()
             ->trimXmlNamespace();
    }

    private function trimXmlNamespace() : SVG {
        $this->string = preg_replace(
            pattern     : '#(<svg[^>]*?)\s+xmlns="[^"]*"#',
            replacement : '$1',
            subject     : $this->string,
        );

        return $this;
    }
}