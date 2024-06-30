<?php

namespace Northrook\Minify;

use Northrook\Minify;

final class CSS extends Minify
{
    protected function minify() : void {
        $this->trimCssComments()
             ->trimWhitespace()
             ->removeLeadingZeroIntegers()
             ->removeEmptySelectors()
             ->compress();
    }

    /**
     * Remove unnecessary leading zeros from the {@see CSS::$string} where possible.
     */
    private function removeLeadingZeroIntegers() : self {
        $this->string = preg_replace( '/(?<!\w)0\.(?!\d)|(?<!\w)(var|url|calc)\([^)]*\)/', '.', $this->string );
        return $this;
    }

    /**
     * Remove selectors with empty declarations from the {@see CSS::$string}.
     */
    private function removeEmptySelectors() : self {
        $this->string = preg_replace( '/(?<=^)[^\{\};]+\{\s*\}/', '', $this->string );
        $this->string = preg_replace( '/(?<=(\}|;))[^\{\};]+\{\s*\}/', '', $this->string );

        return $this;
    }

    /**
     * Further compress the {@see CSS::$string} by collapsing spaces where possible.
     */
    private function compress() : self {
        $this->string = preg_replace( '#\s*([*:;,>~{}])\s*#', '$1', $this->string );

        return $this;
    }

}