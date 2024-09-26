<?php

namespace Northrook\Minify;

use Northrook\Clerk;
use Northrook\Minify\JavaScript\MinifierTokens;
use const Northrook\EMPTY_STRING;

final class JavaScriptMinifier implements \Stringable
{

    use MinifierTokens;

    private readonly string $profilerGroup;

    /**
     * The data to be minified.
     *
     * @var string[]
     */
    protected array $data = [];

    /**
     * Array of patterns to match.
     *
     * @var array<string, array<string|callable>>
     */
    protected array $patterns = [];

    /**
     * This array will hold content of strings and regular expressions that have
     * been extracted from the JS source code, so we can reliably match "code",
     * without having to worry about potential "code-like" characters inside.
     *
     * @internal
     *
     * @var string[]
     */
    protected array $extracted = [];

    /**
     * Initialize the Minifier.
     *
     * @param string|string[]  $source  [optional] Add one or more sources on initialization
     */
    public function __construct( string | array $source, ?string $profilerTag = null )
    {
        $this->profilerGroup = $this::class . ( $profilerTag ? "::$profilerTag" : null );
        Clerk::event( $this::class, $this->profilerGroup );
        foreach ( (array) $source as $data ) {
            $this->add( $data );
        }
    }

    public function __toString() : string
    {
        return $this->minify();
    }

    /**
     * Minify the {@see self::$data}
     *
     *
     * @return string The minified data
     */
    public function minify( bool $singleLine = false ) : string
    {
        $content = EMPTY_STRING;

        /*
         * Let's first take out strings, comments and regular expressions.
         * All of these can contain JS code-like characters, and we should make
         * sure any further magic ignores anything inside of these.
         *
         * Consider this example, where we should not strip any whitespace:
         * var str = "a   test";
         *
         * Comments will be removed altogether, strings and regular expressions
         * will be replaced by placeholder text, which we'll restore later.
         */
        $this->extractStrings( '\'"`' );
        $this->stripComments();
        $this->extractRegex();

        // loop files
        foreach ( $this->data as $js ) {
            // take out strings, comments & regex (for which we've registered
            // the regexes just a few lines earlier)
            $js = $this->replace( $js );

            $js = $this->propertyNotation( $js );
            $js = $this->shortenBools( $js );
            $js = $this->stripWhitespace( $js );

            // combine js: separating the scripts by a ;
            $content .= $js . ';';
        }
        // clean up leftover `;`s from the combination of multiple scripts
        $content = \ltrim( $content, ';' );
        $content = \substr( $content, 0, -1 );

        /*
         * Earlier, we extracted strings & regular expressions and replaced them
         * with placeholder text. This will restore them.
         */
        $content = $this->restoreExtractedData( $content );
        //
        // if ( $singleLine ) {
        //     $content = \str_replace( "\n", "; ", $content );
        //     // foreach ( $this::keywordsReserved as $keyword ) {
        //     //     $content = \str_replace( "}; {$keyword}", "} {$keyword}", $content );
        //     // }
        //     $content = \str_replace( "}; while", "} while", $content );
        // }

        Clerk::stopGroup( $this->profilerGroup );
        return $content;
    }

    /**
     * Add a file or straight-up code to be minified.
     *
     * @param string  ...$sourcePath
     *
     * @return static
     */
    public function add( string ...$sourcePath ) : static
    {
        // bogus "usage" of parameter $data: scrutinizer warns this variable is
        // not used (we're using func_get_args instead to support overloading),
        // but it still needs to be defined because it makes no sense to have
        // this function without argument :)
        // $args = [ $data ] + func_get_args();

        // this method can be overloaded
        foreach ( $sourcePath as $data ) {
            // load data
            $value = $this->load( $data );
            // dump( hashKey( $data ), hashKey( $value ) );
            // If the loaded value differs from the data, it's a file
            $key = ( $data != $value ) ? $data : \count( $this->data );

            $value = $this->normalizeLinefeeds( $value );

            // store data
            $this->data[ $key ] = $value;
        }

        return $this;
    }

    /**
     * Load data.
     *
     * @param string  $data  Either a path to a file or the content itself
     *
     * @return string
     */
    protected function load( string $data ) : string
    {
        // check if the data is a file
        if ( $this->canImportFile( $data ) ) {
            $data = \file_get_contents( $data );

            // strip BOM, if any
            if ( \str_starts_with( $data, "\xef\xbb\xbf" ) ) {
                $data = \substr( $data, 3 );
            }
        }

        return $data;
    }

    // ::: MINIFY :::

    /**
     * This method will restore all extracted data (strings, regexes) that were
     * replaced with placeholder text in extract*(). The original content was
     * saved in $this->extracted.
     *
     * @param string  $content
     *
     * @return string
     */
    final protected function restoreExtractedData( string $content ) : string
    {
        Clerk::event( __METHOD__, $this->profilerGroup );
        // dump( $this->extracted );
        // print_r(  \array_slice( $this->extracted, 592, 7) );
        if ( !$this->extracted ) {
            // nothing was extracted, nothing to restore
            return $content;
        }

        $content = strtr( $content, $this->extracted );

        $this->extracted = [];

        Clerk::stop( __METHOD__ );
        return $content;
    }

    /**
     * We can't "just" run some regular expressions against JavaScript: it's a
     * complex language. E.g. having an occurrence of // xyz would be a comment,
     * unless it's used within a string. Of you could have something that looks
     * like a 'string', but inside a comment.
     * The only way to accurately replace these pieces is to traverse the JS one
     * character at a time and try to find whatever starts first.
     *
     * @param string  $content  The content to replace patterns in
     *
     * @return string The (manipulated) content
     */
    final protected function replace( string $content ) : string
    {
        $contentLength   = strlen( $content );
        $output          = '';
        $processedOffset = 0;
        $positions       = array_fill( 0, count( $this->patterns ), -1 );
        $matches         = [];

        while ( $processedOffset < $contentLength ) {
            // find first match for all patterns
            foreach ( $this->patterns as $i => $pattern ) {
                [ $pattern, $replacement ] = $pattern;

                // we can safely ignore patterns for positions we've unset earlier,
                // because we know these won't show up anymore
                if ( !\array_key_exists( $i, $positions ) ) {
                    continue;
                }

                // no need to re-run matches that are still in the part of the
                // content that hasn't been processed
                if ( $positions[ $i ] >= $processedOffset ) {
                    continue;
                }

                $match = null;
                if ( preg_match( $pattern, $content, $match, PREG_OFFSET_CAPTURE, $processedOffset ) ) {
                    $matches[ $i ] = $match;

                    // we'll store the match position as well; that way, we
                    // don't have to redo all preg_matches after changing only
                    // the first (we'll still know where those others are)
                    $positions[ $i ] = $match[ 0 ][ 1 ];
                }
                else {
                    // if the pattern couldn't be matched, there's no point in
                    // executing it again in later runs on this same content;
                    // ignore this one until we reach end of content
                    unset( $matches[ $i ], $positions[ $i ] );
                }
            }

            // no more matches to find: everything's been processed, break out
            if ( !$matches ) {
                // output the remaining content
                $output .= substr( $content, $processedOffset );
                break;
            }

            // see which of the patterns actually found the first thing (we'll
            // only want to execute that one, since we're unsure if what the
            // other found was not inside what the first found)
            $matchOffset  = min( $positions );
            $firstPattern = array_search( $matchOffset, $positions );
            $match        = $matches[ $firstPattern ];

            // execute the pattern that matches earliest in the content string
            [ , $replacement ] = $this->patterns[ $firstPattern ];

            // add the part of the input between $processedOffset and the first match;
            // that content wasn't matched by anything
            $output .= substr( $content, $processedOffset, $matchOffset - $processedOffset );
            // add the replacement for the match
            $output .= $this->executeReplacement( $replacement, $match );
            // advance $processedOffset past the match
            $processedOffset = $matchOffset + strlen( $match[ 0 ][ 0 ] );
        }

        return $output;
    }

    /**
     * If $replacement is a callback, execute it, passing in the match data.
     * If it's a string, just pass it through.
     *
     * @param callable|string  $replacement  Replacement value
     * @param array            $match        Match data, in PREG_OFFSET_CAPTURE form
     *
     * @return string
     */
    final protected function executeReplacement( callable | string $replacement, array $match ) : string
    {
        if ( !is_callable( $replacement ) ) {
            return $replacement;
        }
        // convert $match from the PREG_OFFSET_CAPTURE form to the form the callback expects
        foreach ( $match as &$matchItem ) {
            $matchItem = $matchItem[ 0 ];
        }

        return $replacement( $match );
    }

    /**
     * Replaces all occurrences of array['key'] by array.key.
     *
     * @param string  $content
     *
     * @return string
     */
    protected function propertyNotation( string $content ) : string
    {
        // PHP only supports $this inside anonymous functions since 5.4
        $keywords = $this::keywordsReserved;
        $callback = function( $match ) use ( $keywords )
        {
            $minifier = $this;
            $property = trim( $minifier->extracted[ $match[ 1 ] ], '\'"' );

            /*
             * Check if the property is a reserved keyword. In this context (as
             * property of an object literal/array) it shouldn't matter, but IE8
             * freaks out with "Expected identifier".
             */
            if ( in_array( $property, $keywords ) ) {
                return $match[ 0 ];
            }

            /*
             * See if the property is in a variable-like format (e.g.
             * array['key-here'] can't be replaced by array.key-here since '-'
             * is not a valid character there.
             */
            if ( !preg_match( '/^' . $this::REGEX . '$/u', $property ) ) {
                return $match[ 0 ];
            }

            return '.' . $property;
        };

        /*
         * Figure out if previous character is a variable name (of the array
         * we want to use property notation on) - this is to make sure
         * standalone ['value'] arrays aren't confused for keys-of-an-array.
         * We can (and only have to) check the last character, because PHP's
         * regex implementation doesn't allow unfixed-length look-behind
         * assertions.
         */
        preg_match( '/(\[[^\]]+\])[^\]]*$/', static::REGEX, $previousChar );
        $previousChar = $previousChar[ 1 ];

        /*
         * Make sure word preceding the ['value'] is not a keyword, e.g.
         * return['x']. Because -again- PHP's regex implementation doesn't allow
         * unfixed-length look-behind assertions, I'm just going to do a lot of
         * separate look-behind assertions, one for each keyword.
         */
        $keywords = $this->getKeywordsForRegex( $keywords );
        $keywords = '(?<!' . implode( ')(?<!', $keywords ) . ')';

        return preg_replace_callback(
                '/(?<=' . $previousChar . '|\])' . $keywords . '\[\s*(([\'"])[0-9]+\\2)\s*\]/u', $callback, $content,
        );
    }

    /**
     * Replaces true & false by !0 and !1.
     *
     * @param string  $content
     *
     * @return string
     */
    protected function shortenBools( string $content ) : string
    {
        /*
         * 'true' or 'false' could be used as property names (which may be
         * followed by whitespace) - we must not replace those!
         * Since PHP doesn't allow variable-length (to account for the
         * whitespace) lookbehind assertions, I need to capture the leading
         * character and check if it's a `.`
         */
        $callback = function( $match )
        {
            if ( trim( $match[ 1 ] ) === '.' ) {
                return $match[ 0 ];
            }

            return $match[ 1 ] . ( $match[ 2 ] === 'true' ? '!0' : '!1' );
        };
        $content  = preg_replace_callback( '/(^|.\s*)\b(true|false)\b(?!:)/', $callback, $content );

        // for(;;) is exactly the same as while(true), but shorter :)
        $content = preg_replace( '/\bwhile\(!0\){/', 'for(;;){', $content );

        // now make sure we didn't turn any do ... while(true) into do ... for(;;)
        preg_match_all( '/\bdo\b/', $content, $dos, PREG_OFFSET_CAPTURE | PREG_SET_ORDER );

        // go backward to make sure positional offsets aren't altered when $content changes
        $dos = array_reverse( $dos );
        foreach ( $dos as $do ) {
            $offsetDo = $do[ 0 ][ 1 ];

            // find all `while` (now `for`) following `do`: one of those must be
            // associated with the `do` and be turned back into `while`
            preg_match_all( '/\bfor\(;;\)/', $content, $whiles, PREG_OFFSET_CAPTURE | PREG_SET_ORDER, $offsetDo );
            foreach ( $whiles as $while ) {
                $offsetWhile = $while[ 0 ][ 1 ];

                $open  = substr_count( $content, '{', $offsetDo, $offsetWhile - $offsetDo );
                $close = substr_count( $content, '}', $offsetDo, $offsetWhile - $offsetDo );
                if ( $open === $close ) {
                    // only restore `while` if amount of `{` and `}` are the same;
                    // otherwise, that `for` isn't associated with this `do`
                    $content = substr_replace( $content, 'while(!0)', $offsetWhile, strlen( 'for(;;)' ) );
                    break;
                }
            }
        }

        return $content;
    }

    /**
     * Strings are a pattern we need to match, in order to ignore potential
     * code-like content inside them, but we just want all of the string
     * content to remain untouched.
     *
     * This method will replace all string content with simple STRING#
     * placeholder text, so we've rid all strings from characters that may be
     * misinterpreted. Original string content will be saved in $this->extracted
     * and after doing all other minifying, we can restore the original content
     * via restoreStrings().
     *
     * @param string  $chars
     * @param string  $placeholderPrefix
     */
    final protected function extractStrings( string $chars = '\'"', string $placeholderPrefix = '' ) : void
    {
        Clerk::event( __METHOD__, $this->profilerGroup );
        // PHP only supports $this inside anonymous functions since 5.4
        $callback = function( $match ) use ( $placeholderPrefix )
        {
            // check the second index here, because the first always contains a quote
            if ( $match[ 2 ] === '' ) {
                /*
                 * Empty strings need no placeholder; they can't be confused for
                 * anything else anyway.
                 * But we still needed to match them, for the extraction routine
                 * to skip over this particular string.
                 */
                return $match[ 0 ];
            }

            $count       = count( $this->extracted );
            $placeholder = $match[ 1 ] . $placeholderPrefix . $count . $match[ 1 ];

            if ( preg_match( '#\\\\(\\s+?)\\.#m', $match[ 2 ] ) ) {
                // $hasNewline = \str_contains( $match[ 2 ], "\n" ) ? "\n" : null;
                $match[ 2 ] = preg_replace( '#(\\\\)\\s*?([\\.\'`"])#m', '$1$2', $match[ 2 ] );
            }

            $this->extracted[ $placeholder ] = $match[ 1 ] . $match[ 2 ] . $match[ 1 ];

            return $placeholder;
        };

        /*
         * The \\ messiness explained:
         * * Don't count ' or " as end-of-string if it's escaped (has backslash
         * in front of it)
         * * Unless... that backslash itself is escaped (another leading slash),
         * in which case it's no longer escaping the ' or "
         * * So there can be either no backslash, or an even number
         * * multiply all of that times 4, to account for the escaping that has
         * to be done to pass the backslash into the PHP string without it being
         * considered as escape-char (times 2) and to get it in the regex,
         * escaped (times 2)
         */
        $this->registerPattern( '/([' . $chars . '])(.*?(?<!\\\\)(\\\\\\\\)*+)\\1/s', $callback );

        Clerk::stop( __METHOD__ );
    }

    /**
     * JS can have /-delimited regular expressions, like: /ab+c/.match(string).
     *
     * The content inside the regex can contain characters that may be confused
     * for JS code: e.g. it could contain whitespace it needs to match & we
     * don't want to strip whitespace in there.
     *
     * The regex can be pretty simple: we don't have to care about comments,
     * (which also use slashes) because stripComments() will have stripped those
     * already.
     *
     * This method will replace all string content with simple REGEX#
     * placeholder text, so we've rid all regular expressions from characters
     * that may be misinterpreted. Original regex content will be saved in
     * $this->extracted and after doing all other minifying, we can restore the
     * original content via restoreRegex()
     */
    protected function extractRegex() : void
    {
        Clerk::event( __METHOD__, $this->profilerGroup );
        // PHP only supports $this inside anonymous functions since 5.4
        $callback = function( $match )
        {
            $count                           = count( $this->extracted );
            $placeholder                     = '"' . $count . '"';
            $this->extracted[ $placeholder ] = $match[ 0 ];

            return $placeholder;
        };

        // match all chars except `/` and `\`
        // `\` is allowed though, along with whatever char follows (which is the
        // one being escaped)
        // this should allow all chars, except for an unescaped `/` (= the one
        // closing the regex)
        // then also ignore bare `/` inside `[]`, where they don't need to be
        // escaped: anything inside `[]` can be ignored safely
        $pattern
                = '\\/(?!\*)(?:[^\\[\\/\\\\\n\r]++|(?:\\\\.)++|(?:\\[(?:[^\\]\\\\\n\r]++|(?:\\\\.)++)++\\])++)++\\/[gimuy]*';

        // a regular expression can only be followed by a few operators or some
        // of the RegExp methods (a `\` followed by a variable or value is
        // likely part of a division, not a regex)
        $keywords             = [ 'do', 'in', 'new', 'else', 'throw', 'yield', 'delete', 'return', 'typeof' ];
        $before               = '(^|[=:,;\+\-\*\?\/\}\(\{\[&\|!]|' . implode( '|', $keywords ) . ')\s*';
        $propertiesAndMethods = [
            // https://developer.mozilla.org/en-US/docs/Web/JavaScript/Reference/Global_Objects/RegExp#Properties_2
            'constructor',
            'flags',
            'global',
            'ignoreCase',
            'multiline',
            'source',
            'sticky',
            'unicode',
            // https://developer.mozilla.org/en-US/docs/Web/JavaScript/Reference/Global_Objects/RegExp#Methods_2
            'compile(',
            'exec(',
            'test(',
            'toSource(',
            'toString(',
        ];
        $delimiters           = array_fill( 0, count( $propertiesAndMethods ), '/' );
        $propertiesAndMethods = array_map( 'preg_quote', $propertiesAndMethods, $delimiters );
        $after                = '(?=\s*([\.,;:\)\}&\|+]|\/\/|$|\.(' . implode( '|', $propertiesAndMethods ) . ')))';
        $this->registerPattern( '/' . $before . '\K' . $pattern . $after . '/', $callback );

        // regular expressions following a `)` are rather annoying to detect...
        // quite often, `/` after `)` is a division operator & if it happens to
        // be followed by another one (or a comment), it is likely to be
        // confused for a regular expression
        // however, it's perfectly possible for a regex to follow a `)`: after
        // a single-line `if()`, `while()`, ... statement, for example
        // since, when they occur like that, they're always the start of a
        // statement, there's only a limited amount of ways they can be useful:
        // by calling the regex methods directly
        // if a regex following `)` is not followed by `.<property or method>`,
        // it's quite likely not a regex
        $before = '\)\s*';
        $after  = '(?=\s*\.(' . implode( '|', $propertiesAndMethods ) . '))';
        $this->registerPattern( '/' . $before . '\K' . $pattern . $after . '/', $callback );

        // 1 more edge case: a regex can be followed by a lot more operators or
        // keywords if there's a newline (ASI) in between, where the operator
        // actually starts a new statement
        // (https://github.com/matthiasmullie/minify/issues/56)
        $operators = $this->getOperatorsForRegex( $this::operatorsBefore );
        $operators += $this->getOperatorsForRegex( $this::keywordsReserved );
        $after     = '(?=\s*\n\s*(' . implode( '|', $operators ) . '))';
        $this->registerPattern( '/' . $pattern . $after . '/', $callback );

        Clerk::stop( __METHOD__ );
    }

    /**
     * Register a pattern to execute against the source content.
     *
     * If $replacement is a string, it must be plain text. Placeholders like $1 or \2 don't work.
     * If you need that functionality, use a callback instead.
     *
     * @param string           $pattern      PCRE pattern
     * @param callable|string  $replacement  Replacement value for matched pattern
     */
    final protected function registerPattern( string $pattern, callable | string $replacement = '' ) : void
    {
        // study the pattern, we'll execute it more than once
        $pattern .= 'S';

        $this->patterns[] = [ $pattern, $replacement ];
    }

    /**
     * We'll strip whitespace around certain operators with regular expressions.
     * This will prepare the given array by escaping all characters.
     *
     * @param string[]  $operators
     * @param string    $delimiter
     *
     * @return string[]
     */
    protected function getOperatorsForRegex( array $operators, string $delimiter = '/' ) : array
    {
        // escape operators for use in regex
        $delimiters = array_fill( 0, count( $operators ), $delimiter );
        $escaped    = array_map( 'preg_quote', $operators, $delimiters );

        $operators = array_combine( $operators, $escaped );

        // ignore + & - for now, they'll get special treatment
        unset( $operators[ '+' ], $operators[ '-' ] );

        // dot can not just immediately follow a number; it can be confused for
        // decimal point, or calling a method on it, e.g. 42 .toString()
        $operators[ '.' ] = '(?<![0-9]\s)\.';

        // don't confuse = with other assignment shortcuts (e.g. +=)
        $chars            = preg_quote( '+-*\=<>%&|', $delimiter );
        $operators[ '=' ] = '(?<![' . $chars . '])\=';

        return $operators;
    }

    /**
     * We'll strip whitespace around certain keywords with regular expressions.
     * This will prepare the given array by escaping all characters.
     *
     * @param string[]  $keywords
     * @param string    $delimiter
     *
     * @return string[]
     */
    protected function getKeywordsForRegex( array $keywords, string $delimiter = '/' ) : array
    {
        // escape keywords for use in regex
        $delimiter = array_fill( 0, count( $keywords ), $delimiter );
        $escaped   = array_map( 'preg_quote', $keywords, $delimiter );

        // add word boundaries
        array_walk(
                $keywords, function( $value )
        {
            return '\b' . $value . '\b';
        },
        );

        return \array_combine( $keywords, $escaped );
    }


    // :: COMMENTS :::

    /**
     * Strip comments from source code.
     */
    protected function stripComments() : void
    {
        Clerk::event( __METHOD__, $this->profilerGroup );
        $this->stripMultilineComments();

        // single-line comments
        $this->registerPattern( '/\/\/.*$/m' );
        Clerk::stop( __METHOD__ );
    }

    /**
     * Both JS and CSS use the same form of multi-line comment, so putting the common code here.
     */
    final protected function stripMultilineComments() : void
    {
        // First extract comments we want to keep, so they can be restored later
        // PHP only supports $this inside anonymous functions since 5.4
        $callback = function( $match )
        {
            $count                           = count( $this->extracted );
            $placeholder                     = '/*' . $count . '*/';
            $this->extracted[ $placeholder ] = $match[ 0 ];

            return $placeholder;
        };
        $this->registerPattern(
                '/
            # optional newline
            \n?

            # start comment
            \/\*

            # comment content
            (?:
                # either starts with an !
                !
            |
                # or, after some number of characters which do not end the comment
                (?:(?!\*\/).)*?

                # there is either a @license or @preserve tag
                @(?:license|preserve)
            )

            # then match to the end of the comment
            .*?\*\/\n?

            /ixs', $callback,
        );

        // Then strip all other comments
        $this->registerPattern( '/\/\*.*?\*\//s' );
    }

    /**
     * Strip whitespace.
     *
     * We won't strip *all* whitespace, but as much as possible. The thing that
     * we'll preserve are newlines we're unsure about.
     * JavaScript doesn't require statements to be terminated with a semicolon.
     * It will automatically fix missing semicolons with ASI (automatic semicolon
     * insertion) at the end of line causing errors (without semicolon.)
     *
     * Because it's sometimes hard to tell if a newline is part of a statement
     * that should be terminated or not, we'll just leave some of them alone.
     *
     * @param string  $content  The content to strip the whitespace for
     *
     * @return string
     * @noinspection
     */
    protected function stripWhitespace( string $content ) : string
    {
        // uniform line endings, make them all line feed
        $content = str_replace( [ "\r\n", "\r" ], "\n", $content );

        // collapse all non-line feed whitespace into a single space
        $content = preg_replace( '/[^\S\n]+/', ' ', $content );

        // strip leading & trailing whitespace
        $content = str_replace( [ " \n", "\n " ], "\n", $content );

        // collapse consecutive line feeds into just 1
        $content = preg_replace( '/\n+/', "\n", $content );

        $operatorsBefore = $this->getOperatorsForRegex( $this::operatorsBefore );
        $operatorsAfter  = $this->getOperatorsForRegex( $this::operatorsAfter );
        $operators       = $this->getOperatorsForRegex( $this::operators );
        $keywordsBefore  = $this->getKeywordsForRegex( $this::keywordsBefore );
        $keywordsAfter   = $this->getKeywordsForRegex( $this::keywordsAfter );

        // strip whitespace that ends in (or next line begin with) an operator
        // that allows statements to be broken up over multiple lines
        unset( $operatorsBefore[ '+' ], $operatorsBefore[ '-' ], $operatorsAfter[ '+' ], $operatorsAfter[ '-' ] );
        $content = preg_replace(
                [
                        '/(' . implode( '|', $operatorsBefore ) . ')\s+/',
                        '/\s+(' . implode( '|', $operatorsAfter ) . ')/',
                ],
                '\\1',
                $content,
        );

        // make sure + and - can't be mistaken for, or joined into ++ and --
        $content = preg_replace(
                [
                        '/(?<![\+\-])\s*([\+\-])(?![\+\-])/',
                        '/(?<![\+\-])([\+\-])\s*(?![\+\-])/',
                ],
                '\\1',
                $content,
        );

        // collapse whitespace around reserved words into single space
        $content = preg_replace( '/(^|[;\}\s])\K(' . implode( '|', $keywordsBefore ) . ')\s+/', '\\2 ', $content );
        $content = preg_replace( '/\s+(' . implode( '|', $keywordsAfter ) . ')(?=([;\{\s]|$))/', ' \\1', $content );

        /*
         * We didn't strip whitespace after a couple of operators because they
         * could be used in different contexts and we can't be sure it's ok to
         * strip the newlines. However, we can safely strip any non-line feed
         * whitespace that follows them.
         */
        $operatorsDiffBefore = array_diff( $operators, $operatorsBefore );
        $operatorsDiffAfter  = array_diff( $operators, $operatorsAfter );
        $content             = preg_replace(
                '/(' . implode( '|', $operatorsDiffBefore ) . ')[^\S\n]+/', '\\1', $content,
        );
        $content             = preg_replace(
                '/[^\S\n]+(' . implode( '|', $operatorsDiffAfter ) . ')/', '\\1', $content,
        );

        /*
         * Whitespace after `return` can be omitted in a few occasions
         * (such as when followed by a string or regex)
         * Same for whitespace in between `)` and `{`, or between `{` and some
         * keywords.
         */
        $content = preg_replace( '/\breturn\s+(["\'\/\+\-])/', 'return$1', $content );
        $content = preg_replace( '/\)\s+\{/', '){', $content );
        $content = preg_replace( '/}\n(else|catch|finally)\b/', '}$1', $content );

        /*
         * Get rid of double semicolons, except where they can be used like:
         * "for(v=1,_=b;;)", "for(v=1;;v++)" or "for(;;ja||(ja=true))".
         * I'll safeguard these double semicolons inside for-loops by
         * temporarily replacing them with an invalid condition: they won't have
         * a double semicolon and will be easy to spot to restore afterwards.
         */
        $content = preg_replace( '/\bfor\(([^;]*);;([^;]*)\)/', 'for(\\1;-;\\2)', $content );
        $content = preg_replace( '/;+/', ';', $content );
        $content = preg_replace( '/\bfor\(([^;]*);-;([^;]*)\)/', 'for(\\1;;\\2)', $content );

        /*
         * Next, we'll be removing all semicolons where ASI kicks in.
         * for-loops however, can have an empty body (ending in only a
         * semicolon), like: `for(i=1;i<3;i++);`, of `for(i in list);`
         * Here, nothing happens during the loop; it's just used to keep
         * increasing `i`. With that ; omitted, the next line would be expected
         * to be the for-loop's body... Same goes for while loops.
         * I'm going to double that semicolon (if any) so after the next line,
         * which strips semicolons here & there, we're still left with this one.
         * Note the special recursive construct in the three inner parts of the for:
         * (\{([^\{\}]*(?-2))*[^\{\}]*\})? - it is intended to match inline
         * functions bodies, e.g.: i<arr.map(function(e){return e}).length.
         * Also note that the construct is applied only once and multiplied
         * for each part of the for, otherwise it risks a catastrophic backtracking.
         * The limitation is that it will not allow closures in more than one
         * of the three parts for a specific for() case.
         * REGEX throwing catastrophic backtracking: $content = preg_replace('/(for\([^;\{]*(\{([^\{\}]*(?-2))*[^\{\}]*\})?[^;\{]*;[^;\{]*(\{([^\{\}]*(?-2))*[^\{\}]*\})?[^;\{]*;[^;\{]*(\{([^\{\}]*(?-2))*[^\{\}]*\})?[^;\{]*\));(\}|$)/s', '\\1;;\\8', $content);
         */
        /**
         * @noinspectionAll
         */
        $content = \preg_replace(
                '/(for\((?:[^;\{]*|[^;\{]*function[^;\{]*(\{([^\{\}]*(?-2))*[^\{\}]*\})?[^;\{]*);[^;\{]*;[^;\{]*\));(\}|$)/',
                '\\1;;\\4', $content,
        );
        $content = \preg_replace(
                '/(for\([^;\{]*;(?:[^;\{]*|[^;\{]*function[^;\{]*(\{([^\{\}]*(?-2))*[^\{\}]*\})?[^;\{]*);[^;\{]*\));(\}|$)/',
                '\\1;;\\4', $content,
        );
        $content = \preg_replace(
                '/(for\([^;\{]*;[^;\{]*;(?:[^;\{]*|[^;\{]*function[^;\{]*(\{([^\{\}]*(?-2))*[^\{\}]*\})?[^;\{]*)\));(\}|$)/',
                '\\1;;\\4', $content,
        );

        $content = \preg_replace( '/(for\([^;\{]+\s+in\s+[^;\{]+\));(\}|$)/', '\\1;;\\2', $content );

        /*
         * Do the same for the if's that don't have a body but are followed by ;}
         */
        $content = \preg_replace( '/(\bif\s*\([^{;]*\));\}/', '\\1;;}', $content );

        /*
         * Below will also keep `;` after a `do{}while();` along with `while();`
         * While these could be stripped after do-while, detecting this
         * distinction is cumbersome, so I'll play it safe and make sure `;`
         * after any kind of `while` is kept.
         */
        $content = \preg_replace( '/(while\([^;\{]+\));(\}|$)/', '\\1;;\\2', $content );

        /*
         * We also can't strip empty else-statements. Even though they're
         * useless and probably shouldn't be in the code in the first place, we
         * shouldn't be stripping the `;` that follows it as it breaks the code.
         * We can just remove those useless else-statements completely.
         *
         * @see https://github.com/matthiasmullie/minify/issues/91
         */
        $content = \preg_replace( '/else;/', '', $content );

        /*
         * We also don't really want to terminate statements followed by closing
         * curly braces (which we've ignored completely up until now) or end-of-
         * script: ASI will kick in here & we're all about minifying.
         * Semicolons at beginning of the file don't make any sense either.
         */
        $content = \preg_replace( '/;(\}|$)/', '\\1', $content );
        $content = \ltrim( $content, ';' );

        // get rid of remaining whitespace af beginning/end
        return \trim( $content );
    }


    // ::: UTILITY :::

    /**
     * @see https://github.com/matthiasmullie/minify/pull/139
     *
     * @param string  $string
     *
     * @return string
     */
    final protected function normalizeLinefeeds( string $string ) : string
    {
        return \str_replace( [ "\r\n", "\r" ], "\n", $string );
    }

    /**
     * Check if the path is a regular file and can be read.
     *
     * @param string  $path
     *
     * @return bool
     */
    final protected function canImportFile( string $path ) : bool
    {
        $parsed = \parse_url( $path );
        if (
                // file is elsewhere
                isset( $parsed[ 'host' ] )
                // file responds to queries (may change, or need to bypass cache)
                || isset( $parsed[ 'query' ] )
        ) {
            return false;
        }

        try {
            return \strlen( $path ) < PHP_MAXPATHLEN && @\is_file( $path ) && \is_readable( $path );
        }
            // catch openbasedir exceptions which are not caught by @ on is_file()
        catch ( \Exception $e ) {
            return false;
        }
    }
}