<?php

namespace Northrook\Minify;

use Northrook\Minify;
use function Northrook\Core\Function\replaceEach;

final class HTML extends Minify
{

    // private array $matchedElements = [];

    protected function minify() : void {
        $this->trimHtmlComments()
            ->trimWhitespace()
            ->trimElementTagBrackets();
    }

    /**
     * Trim unnecessary whitespace around brackets
     */
    private function trimElementTagBrackets() : HTML {
        $this->string = preg_replace( '#\s*(<|>|\/>)\s*#m', '$1', $this->string );
        return $this;
    }

    // TODO : In development
    private function removeEmptyAttributes() : HTML {
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