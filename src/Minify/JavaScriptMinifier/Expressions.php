<?php

namespace Support\Minify\JavaScriptMinifier;

final class Expressions
{
    /** @var string[] */
    public array $tokens = [];

    /** @var string[] */
    public array $all = [];

    /** @var string[] */
    public array $safeNewlines = [];

    /** @var string[] */
    public array $whitespaceArrayPlaceholders = [];

    /** @var string[] */
    public array $whitespaceArrayNewline;

    public string $whitespaceCharsNewline;

    public string $optionalWhitespaceNewline;

    public string $someWhitespace;

    /** @lang RegExp */
    public string $regexp;

    public function __construct()
    {
        // white space (includes space and tab) https://262.ecma-international.org/6.0/#sec-white-space
        $whitespaceArray              = [' ', "\t"];
        $this->whitespaceArrayNewline = \array_merge( $whitespaceArray, ["\n"] );
        // placeholders are unused code points; see also https://en.wikipedia.org/wiki/Private_Use_Areas
        $this->whitespaceArrayPlaceholders = ["\x{E000}", "\x{E001}", "\x{E002}"];

        $whitespaceChars              = \implode( '', $whitespaceArray );
        $this->whitespaceCharsNewline = "\n".$whitespaceChars;

        $whitespace        = '['.$whitespaceChars.']';
        $whitespaceNewline = '['.$this->whitespaceCharsNewline.']';

        // possessive quantifier (++) is needed when used in combination with look ahead
        $this->someWhitespace            = $whitespace.'++';
        $someWhitespaceNewline           = $whitespaceNewline.'++';
        $optionalWhitespace              = $whitespace.'*+';
        $this->optionalWhitespaceNewline = $whitespaceNewline.'*+';

        // a single escaped character in a regexp, like \\ or \n (may not be newline)
        $regexEscape = "\\\\[^\n]";
        // regexp character class: [..] with escape characters
        // [^\n\\]] is any non-] character (also not be newline)
        $characterClass = '\\[(?:'.$regexEscape."|[^\n\\]])*+\\]";
        // regexp: /..(..)../i with character class and escaped characters
        // [^\n/] is any non-slash character (also not be newline)
        $this->regexp /** @lang RegExp */
                = '/(?:'.$regexEscape.'|'.$characterClass."|[^\n/])++/[igmus]*+";

        // characters than can form a word; these characters should not be joined together by the removal of whitespace
        $word /** @lang RegExp */
                = "[a-zA-Z0-9_\$\x{0080}-\x{FFFF}]";

        // note: ! is not infix and not safe
        $safeOperators            = ",\.&|+\-*/%=<>?:";
        $openingBrackets          = '({[';
        $closingBrackets          = ')}\\]';
        $expressionClosingBracket = ')';
        $blockOpeningBracket      = '{';

        // newlines that may be safely removed
        $this->safeNewlines = [
            // newline preceded by opening bracket or operator
            '(?<=[;'.$openingBrackets.$safeOperators.'])'."\n",
            // newline followed by closing bracket or operator
            "\n".'(?=[;'.$closingBrackets.$safeOperators.'])',
            // ( \n [
            '(?<=['.$openingBrackets.'])'."\n".'(?=['.$openingBrackets.'])',
            // ] \n )
            '(?<=['.$closingBrackets.'])'."\n".'(?=['.$closingBrackets.'])',
            // } \n (
            '(?<=['.$expressionClosingBracket.'])'."\n".'(?=['.$blockOpeningBracket.'])',
            // } word
            '(?<=['.$closingBrackets.'])'."\n".'(?='.$word.')',
        ];

        // these expression must always be present, because they keep tokens that contain whitespace together
        $this->tokens = [
            // /** comment */
            'starComment' => '(?<starComment>'."/\*.*?\*/".$this->optionalWhitespaceNewline.')',
            // // comment
            'lineComment' => "(?<lineComment>//[^\n]*".$this->optionalWhitespaceNewline.')',
            // regular expression
            'regexp' => '(?<regexp>'
                             // if there's whitespace, match it all
                             .$this->regexp.$optionalWhitespace
                             // to distinguish a regexp from a sequence of dividers (i.e.: x / y / z):
                             .'(?:'
                             // it is followed by a newline; add it to the match
                             ."\n"
                             .'|'
                             // it is followed by any of these characters
                             ."(?=[;,\.)])"
                             .')'
                             .')',
            // "double quotes"
            'double' => '(?<doubleQuote>"(?:\\\\.|[^"])*+")',
            // 'single quotes'
            'single' => "(?<singleQuote>'(?:\\\\.|[^'])*+')",
            // `template literal`
            'template' => '(?<templateLiteral>`(?:\\\\.|[^`])*+`)',
        ];

        $specificExpressions = [
            // a sequence of - and -- operators; i.e. a - --b; b-- -c; d-- - -e; f - -g
            'min' => '(?<=-)(?<min>'.$someWhitespaceNewline.')(?=-)',
            // a sequence of + and ++ operators
            'plus' => "(?<=\+)(?<plus>".$someWhitespaceNewline.")(?=\+)",
            // whitespace both preceded and succeeded by a word char
            'requiredSpace' => '(?<='.$word.')(?<requiredSpace>'.$someWhitespaceNewline.')(?='.$word.')',
        ];

        $this->all = \array_merge( $this->tokens, $specificExpressions );
    }
}
