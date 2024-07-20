<?php

namespace Northrook\Minify;

use Northrook\Minify;
use function Northrook\replaceEach;

final class HtmlMinifier extends Minify
{

    // private array $matchedElements = [];

    protected function minifyString() : void {
        $this->trimHtmlComments()
             ->trimWhitespace()
             ->trimElementTagBrackets();
    }

    /**
     * Trim unnecessary whitespace around brackets
     */
    private function trimElementTagBrackets() : HtmlMinifier {
        $this->string = preg_replace( '#\s*(<|>|\/>)\s*#m', '$1', $this->string );
        return $this;
    }

    // TODO : In development
    private function removeEmptyAttributes() : HtmlMinifier {
        // foreach ( $this->matchHtmlOpeningElement() as $match ) {
        //     if ( count( $match ) === 1 ) {
        //         continue;
        //     }
        //     dump( $match );
        // }
        return $this;
    }

    // TODO : In development
    // private function matchHtmlOpeningElement( bool $rescan = false ) : array {
    //     if ( !$this->matchedElements || $rescan ) {
    //         preg_match_all(
    /*            '/(<\w.*? ((\w+?)=["\']["\'])*.+?>)/s',*/
    //             $this->string,
    //             $this->matchedElements,
    //             PREG_SET_ORDER,
    //         );
    //     }
    //     return $this->matchedElements;
    // }
}