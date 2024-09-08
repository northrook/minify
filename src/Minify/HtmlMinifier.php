<?php

namespace Northrook\Minify;

use JetBrains\PhpStorm\Language;
use Northrook\HTML\HtmlNode;
use Northrook\Interface\Printable;
use Northrook\Minify;
use Northrook\Trait\PrintableClass;
use function Northrook\replaceEach;
use const Northrook\EMPTY_STRING;


final class HtmlMinifier implements Printable
{
    use PrintableClass;


    private string $string;

    /**
     * The first of the characters currently being looked at.
     *
     * @var string | bool | null
     */
    protected string | bool | null $current = '';

    /**
     * The next character being looked at (after a);
     *
     * @var string | bool | null
     */
    protected string | bool | null $ahead = '';

    /**
     * This character is only active when certain look ahead actions take place.
     *
     * @var  string | bool | null
     */
    protected string | bool | null $lookAhead;

    /**
     * This character is only active when certain look ahead actions take place.
     *
     * @var  string | bool | null
     */
    protected string | bool | null $previous;

    public function __construct(
        #[Language( 'HTML' )]
        string $string,
    )
    {
        $string = \preg_replace( '#^\h*?<!--.*?-->\R*#ms', '', $string );
        $string = \preg_replace( '#\s+#', ' ', $string );

        $this->string = \trim( $string, ' ' );

        $this->domDocumentOperations();

        $this->string = \preg_replace( [ '#(^<\w+.*?>)\s+#', '#\s+(</\w+?>)$#', ], '$1', $this->string );
        /*$this->string = \preg_replace( '#( +?<.+?>.*?) *?(<\/(?:a|b|i)>)#m', '$1$2', $this->string );*/
        /*$this->string = \preg_replace( '#( +?<.+?>.*?) *?(<\/small>)#m', '$1$2', $this->string );*/
        // $this->string = \preg_replace( '# (<\/[bi]>) *?<#m', '$1', $this->string );
        /*/*$this->string = \preg_replace( '# (<\/\w+?>)<#m', '$1 <', $this->string );*/
        /*$this->string = \preg_replace( '#<\/\w+?>(\s+?)<\/\w+?>#m', '', $this->string );*/
    }

    public function __toString() : string
    {
        return $this->string;
    }

    private function domDocumentOperations() : self
    {
        $html = new HtmlNode( $this->string );

        $this->parseDomElements( $html->iterateChildNodes() );

        $this->string = $html->html;

        return $this;
    }

    private function parseDomElements( \DOMNodeList $iterateChildNodes ) : void
    {
        foreach ( $iterateChildNodes as $node ) {
            // if ( $node->previousSibling )

            if ( $node->hasChildNodes() ) {
                if ( $node->hasAttributes() ) {
                    $this->parseElementAttributes( $node );
                }

                $this->parseDomElements( $node->childNodes );
                continue;
            }
            // if ( $node instanceof \DOMText ) {
            //     $this->parseWhitespace( $node );
            // }

        }
    }

    private function parseWhitespace( \DOMText $textNode ) : void
    {
        if ( !\trim( $textNode->textContent ) ) {
            $textNode->nodeValue = EMPTY_STRING;
            return;
        }
        dump(
            $textNode->previousSibling?->nodeValue,
            $textNode->nextSibling?->nodeValue,
        );
    }

    private function parseElementAttributes( mixed $node ) : void
    {
        /** @var \DOMAttr $attribute */
        foreach ( $node->attributes as $attribute ) {
            $attribute->nodeValue = match ( $attribute->nodeName ) {
                'id', 'class' => \trim( $attribute->nodeValue ),
                'style'       => \trim( \preg_replace( '#\s*([:;])\s*#m', '$1', $attribute->nodeValue ) ),
                default       => $attribute->nodeValue,
            };
        }
    }
}