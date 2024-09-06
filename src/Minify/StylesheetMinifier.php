<?php

namespace Northrook\Minify;

use Northrook\Minify;


final class StylesheetMinifier extends Minify
{
    protected function minifyString() : void
    {
        $this->string = $this->minifyCSS( $this->string );
        // $this->trimCssComments()
        //      ->trimWhitespace()
        //      // ->removeLeadingZeroIntegers()
        //      ->removeEmptySelectors()
        //      ->compress();
    }

    private function minifyCSS( string $input ) : string
    {
        if ( \trim( $input ) === "" ) {
            return $input;
        }
        return (string) \preg_replace(
            [
                // Remove comment(s)
                '#("(?:[^"\\\]++|\\\.)*+"|\'(?:[^\'\\\\]++|\\\.)*+\')|\/\*(?!\!)(?>.*?\*\/)|^\s*|\s*$#s',
                // Remove unused white-space(s)
                '#("(?:[^"\\\]++|\\\.)*+"|\'(?:[^\'\\\\]++|\\\.)*+\'|\/\*(?>.*?\*\/))|\s*+;\s*+(})\s*+|\s*+([*$~^|]?+=|[{};,>~]|\s(?![0-9\.])|!important\b)\s*+|([[(:])\s++|\s++([])])|\s++(:)\s*+(?!(?>[^{}"\']++|"(?:[^"\\\]++|\\\.)*+"|\'(?:[^\'\\\\]++|\\\.)*+\')*+{)|^\s++|\s++\z|(\s)\s+#si',
                // Replace `0(cm|em|ex|in|mm|pc|pt|px|vh|vw|%)` with `0`
                '#(?<=[\s:])(0)(cm|em|ex|in|mm|pc|pt|px|vh|vw|%)#si',
                // Replace `:0 0 0 0` with `:0`
                '#:(0\s+0|0\s+0\s+0\s+0)(?=[;\}]|\!important)#i',
                // Replace `background-position:0` with `background-position:0 0`
                '#(background-position):0(?=[;\}])#si',
                // Replace `0.6` with `.6`, but only when preceded by `:`, `,`, `-` or a white-space
                '#(?<=[\s:,\-])0+\.(\d+)#s',
                // Minify string value
                '#(\/\*(?>.*?\*\/))|(?<!content\:)([\'"])([a-z_][a-z0-9\-_]*?)\2(?=[\s\{\}\];,])#si',
                '#(\/\*(?>.*?\*\/))|(\burl\()([\'"])([^\s]+?)\3(\))#si',
                // Minify HEX color code
                '#(?<=[\s:,\-]\#)([a-f0-6]+)\1([a-f0-6]+)\2([a-f0-6]+)\3#i',
                // Replace `(border|outline):none` with `(border|outline):0`
                '#(?<=[\{;])(border|outline):none(?=[;\}\!])#',
                // Remove empty selector(s)
                '#(\/\*(?>.*?\*\/))|(^|[\{\}])(?:[^\s\{\}]+)\{\}#s',
            ],
            [
                '$1',
                '$1$2$3$4$5$6$7',
                '$1',
                ':0',
                '$1:0 0',
                '.$1',
                '$1$3',
                '$1$2$4$5',
                '$1$2$3',
                '$1:0',
                '$1$2',
            ],
            $input,
        );
    }

    /**
     * Remove unnecessary leading zeros from the {@see StylesheetMinifier::$string} where possible.
     */
    private function removeLeadingZeroIntegers() : self
    {
        $this->string = preg_replace( '/(?<!\w)0\.(?!\d)|(?<!\w)(var|url|calc)\([^)]*\)/', '.', $this->string );
        return $this;
    }

    /**
     * Remove selectors with empty declarations from the {@see StylesheetMinifier::$string}.
     */
    private function removeEmptySelectors() : self
    {
        $this->string = preg_replace( '/(?<=^)[^\{\};]+\{\s*\}/', '', $this->string );
        $this->string = preg_replace( '/(?<=(\}|;))[^\{\};]+\{\s*\}/', '', $this->string );

        return $this;
    }

    /**
     * Further compress the {@see StylesheetMinifier::$string} by collapsing spaces where possible.
     */
    private function compress() : self
    {
        $this->string = preg_replace( '#\s*([*:;,>~{}])\s*#', '$1', $this->string );

        return $this;
    }

}