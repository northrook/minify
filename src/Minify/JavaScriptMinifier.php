<?php

/*
 * BSD 3-Clause license.
 *
 * Largely based on the JShrink package, https://github.com/tedious/JShrink.
 *
 * Copyright (c) 2024, Martin Nielsen <mn@northrook.com>
 * Copyright (c) 2009, Robert Hafner <tedivm@tedivm.com>
 */

namespace Northrook\Minify;

/**
 * The {@link https://www.toptal.com/developers/javascript-minifier Toptal } actively parses and rewords the structure.
 *
 * While this leads to a size improvement, it does break readability.
 *
 */
final class JavaScriptMinifier
{

    protected static array $keywords = [ "delete", "do", "for", "in", "instanceof", "return", "typeof", "yield" ];

    /**
     * The input javascript to be minified.
     *
     * @var string
     */
    private string $string;

    /**
     * Length of input javascript.
     *
     * @var int
     */
    protected int $length = 0;

    /**
     * The location of the character (in the input string) that is next to be
     * processed.
     *
     * @var int
     */
    protected int $index = 0;

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

    /**
     * This character is only active when certain look ahead actions take place.
     *
     * @var string
     */
    protected string $output;

    /**
     * These characters are used to define strings.
     */
    protected array $stringDelimiters = [ '\'' => true, '"' => true, '`' => true ];
    protected int   $max_keyword_len;

    /**
     * Contains lock ids which are used to replace certain code patterns and
     * prevent them from being minified
     *
     * @var array
     */
    protected array $locks = [];

    /**
     * Characters that can't stand alone preserve the newline.
     *
     * @var array
     */
    protected array $noNewLineCharacters = [
        '(' => true,
        '-' => true,
        '+' => true,
        '[' => true,
        '#' => true,
        '@' => true,
    ];

    public function __construct(
        string                $string,
        private readonly bool $flaggedComments = true,
    )
    {
        $this->string = $string;
        try {
            $this
                ->lock()
                ->minifyToString()
                ->unlock()
            ;
        }
        catch ( \Exception $exception ) {
            if ( isset( $self ) ) {
                // Since the breakdownScript function probably wasn't finished
                // we clean it out before discarding it.
                $self->clean();
            }
            throw new \LogicException( $exception->getMessage(), previous : $exception );
        }
    }

    public function __toString() : string
    {
        return $this->string;
    }

    /**
     * Processes a javascript string and outputs only the required characters,
     * stripping out all unneeded characters.
     *
     */
    protected function minifyToString() : self
    {
        $this->initialize();
        $this->loop();
        $this->clean();
        $this->string = $this->output;
        return $this;
    }

    /**
     *  Initializes internal variables, normalizes new lines,
     *
     */
    protected function initialize() : self
    {
        // We add a newline to the end of the script to make it easier
        // to deal with comments at the bottom of the script.
        // This prevents the unclosed comment error that can otherwise occur.
        $this->string .= PHP_EOL;

        // save input length to skip calculation every time
        $this->length = \strlen( $this->string );

        // Populate "a" with a new line, "b" with the first character, before
        // entering the loop
        $this->current  = "\n";
        $this->ahead    = "\n";
        $this->previous = "\n";
        $this->output   = "";

        $this->max_keyword_len = (int) \max( \array_map( '\strlen', $this::$keywords ) );

        return $this;
    }

    protected function echo( $char ) : void
    {
        $this->output   .= $char;
        $this->previous = $char[ -1 ];
    }

    /**
     * The primary action occurs here. This function loops through the input string,
     * outputting anything that's relevant and discarding anything that is not.
     */
    protected function loop() : void
    {
        while ( $this->current !== false && !is_null( $this->current ) && $this->current !== '' ) {
            switch ( $this->current ) {
                // new lines
                case "\r":
                case "\n":
                    // if the next line is something that can't stand alone preserve the newline
                    if ( $this->ahead !== false && isset( $this->noNewLineCharacters[ $this->ahead ] ) ) {
                        $this->echo( $this->current );
                        $this->saveString();
                        break;
                    }

                    // if B is a space we skip the rest of the switch block and go down to the
                    // string/regex check below, resetting $this->b with getReal
                    if ( $this->ahead === ' ' ) {
                        break;
                    }

                // otherwise we treat the newline like a space

                // no break
                case ' ':
                    if ( $this::isAlphaNumeric( $this->ahead ) ) {
                        $this->echo( $this->current );
                    }

                    $this->saveString();
                    break;

                default:
                    switch ( $this->ahead ) {
                        case "\r":
                        case "\n":
                            if ( \str_contains( '}])+-"\'', $this->current ) ) {
                                $this->echo( $this->current );
                                $this->saveString();
                                break;
                            }
                            else {
                                if ( $this::isAlphaNumeric( $this->current ) ) {
                                    $this->echo( $this->current );
                                    $this->saveString();
                                }
                            }
                            break;

                        case ' ':
                            if ( !$this::isAlphaNumeric( $this->current ) ) {
                                break;
                            }

                        // no break
                        default:
                            // check for some regex that breaks stuff
                            if ( $this->current === '/' && ( $this->ahead === '\'' || $this->ahead === '"' ) ) {
                                $this->saveRegex();
                                continue 3;
                            }

                            $this->echo( $this->current );
                            $this->saveString();
                            break;
                    }
            }

            // do reg check of doom
            $this->ahead = $this->getReal();

            if ( $this->ahead == '/' ) {
                $valid_tokens = "(,=:[!&|?\n";

                # Find last "real" token, excluding spaces.
                $last_token = $this->current;
                if ( $last_token == " " ) {
                    $last_token = $this->previous;
                }

                if ( \str_contains( $valid_tokens, $last_token ) ) {
                    // Regex can appear unquoted after these symbols
                    $this->saveRegex();
                }
                else {
                    if ( $this->endsInKeyword() ) {
                        // This block checks for the "return" token before the slash.
                        $this->saveRegex();
                    }
                }
            }
        }
    }

    /**
     * Resets attributes that do not need to be stored between requests so that
     * the next request is ready to go. Another reason for this is to make sure
     * the variables are cleared and are not taking up memory.
     */
    protected function clean() : void
    {
        unset( $this->string );
        $this->length  = 0;
        $this->index   = 0;
        $this->current = $this->ahead = '';
        unset( $this->lookAhead );
    }

    /**
     * Returns the next string for processing based off of the current index.
     *
     * @return null|bool|string
     */
    protected function getChar() : bool | string | null
    {
        // Check to see if we had anything in the look ahead buffer and use that.
        if ( isset( $this->lookAhead ) ) {
            $char = $this->lookAhead;
            unset( $this->lookAhead );
        }
        else {
            // Otherwise we start pulling from the input.
            $char = $this->index < $this->length ? $this->string[ $this->index ] : false;

            // If the next character doesn't exist return false.
            if ( isset( $char ) && $char === false ) {
                return false;
            }

            // Otherwise increment the pointer and use this char.
            $this->index++;
        }

        # Convert all line endings to unix standard.
        # `\r\n` converts to `\n\n` and is minified.
        if ( $char == "\r" ) {
            $char = "\n";
        }

        // Normalize all whitespace except for the newline character into a
        // standard space.
        if ( $char !== "\n" && $char < "\x20" ) {
            return ' ';
        }

        return $char;
    }

    /**
     * This function returns the next character without moving the index forward.
     *
     *
     * @return false|string The next character
     */
    protected function peek() : false | string
    {
        if ( $this->index >= $this->length ) {
            return false;
        }

        $char = $this->string[ $this->index ];
        # Convert all line endings to unix standard.
        # `\r\n` converts to `\n\n` and is minified.
        if ( $char == "\r" ) {
            $char = "\n";
        }

        // Normalize all whitespace except for the newline character into a
        // standard space.
        if ( $char !== "\n" && $char < "\x20" ) {
            return ' ';
        }

        # Return the next character but don't push the index.
        return $char;
    }

    /**
     * This function gets the next "real" character. It is essentially a wrapper
     * around the getChar function that skips comments. This has significant
     * performance benefits as the skipping is done using native functions (ie,
     * c code) rather than in script php.
     *
     *
     * @return null|bool|string Next 'real' character to be processed.
     */
    protected function getReal() : bool | string | null
    {
        $startIndex = $this->index;
        $char       = $this->getChar();

        // Check to see if we're potentially in a comment
        if ( $char !== '/' ) {
            return $char;
        }

        $this->lookAhead = $this->getChar();

        if ( $this->lookAhead === '/' ) {
            $this->processOneLineComments( $startIndex );

            return $this->getReal();
        }
        elseif ( $this->lookAhead === '*' ) {
            $this->processMultiLineComments( $startIndex );

            return $this->getReal();
        }

        return $char;
    }

    /**
     * Removed one line comments, except some very specific types of conditional comments.
     *
     * @param int  $startIndex  The index point where "getReal" function started
     *
     * @return void
     */
    protected function processOneLineComments( int $startIndex ) : void
    {
        $thirdCommentString = $this->index < $this->length ? $this->string[ $this->index ] : false;

        // kill rest of line
        $this->getNext( "\n" );

        unset( $this->lookAhead );

        if ( $thirdCommentString == '@' ) {
            $endPoint        = $this->index - $startIndex;
            $this->lookAhead = "\n" . \substr( $this->string, $startIndex, $endPoint );
        }
    }

    /**
     * Skips multiline comments where appropriate, and includes them where needed.
     * Conditional comments and "license" style blocks are preserved.
     *
     * @param int  $startIndex  The index point where "getReal" function started
     *
     * @return void
     * @throws \RuntimeException Unclosed comments will throw an error
     */
    protected function processMultiLineComments( int $startIndex ) : void
    {
        $this->getChar(); // current C
        $thirdCommentString = $this->getChar();

        // Detect a completely empty comment, ie `/**/`
        if ( $thirdCommentString == "*" ) {
            $peekChar = $this->peek();
            if ( $peekChar == "/" ) {
                $this->index++;
                return;
            }
        }

        // kill everything up to the next */ if it's there
        if ( $this->getNext( '*/' ) ) {
            $this->getChar();         // get *
            $this->getChar();         // get /
            $char = $this->getChar(); // get next real character

            // Now we reinsert conditional comments and YUI-style licensing comments
            if ( ( $this->flaggedComments && $thirdCommentString === '!' )
                 || ( $thirdCommentString === '@' ) ) {
                // If conditional comments or flagged comments are not the first thing in the script
                // we need to echo a and fill it with a space before moving on.
                if ( $startIndex > 0 ) {
                    $this->echo( $this->current );
                    $this->current = " ";

                    // If the comment started on a new line we let it stay on the new line
                    if ( $this->string[ ( $startIndex - 1 ) ] === "\n" ) {
                        $this->echo( "\n" );
                    }
                }

                $endPoint = ( $this->index - 1 ) - $startIndex;
                $this->echo( \substr( $this->string, $startIndex, $endPoint ) );

                $this->lookAhead = $char;

                return;
            }
        }
        else {
            $char = false;
        }

        if ( $char === false ) {
            throw new \RuntimeException( 'Unclosed multiline comment at position: ' . ( $this->index - 2 ) );
        }

        // if we're here c is part of the comment and therefore tossed
        $this->lookAhead = $char;
    }

    /**
     * Pushes the index ahead to the next instance of the supplied string.
     *
     * If it is found the first character of the string is returned and the index is set to its position.
     *
     * @param string  $string
     *
     * @return string|false Returns the first character of the string or false.
     */
    protected function getNext( string $string ) : false | string
    {
        // Find the next occurrence of "string" after the current position.
        $position = \strpos( $this->string, $string, $this->index );

        // If it's not there return false.
        if ( $position === false ) {
            return false;
        }

        // Adjust position of index to jump ahead to the asked for string
        $this->index = $position;

        // Return the first character of that string.
        return $this->index < $this->length ? $this->string[ $this->index ] : false;
    }

    /**
     * When a javascript string is detected this function crawls for the end of
     * it and saves the whole string.
     *
     * @throws \RuntimeException Unclosed strings will throw an error
     */
    protected function saveString() : void
    {
        $startpos = $this->index;

        // saveString is always called after a gets cleared, so we push b into
        // that spot.
        $this->current = $this->ahead;

        // If this isn't a string we don't need to do anything.
        if ( !isset( $this->stringDelimiters[ $this->current ] ) ) {
            return;
        }

        // String type is the quote used, " or '
        $stringType = $this->current;

        // Echo out that starting quote
        $this->echo( $this->current );

        // Loop until the string is done
        // Grab the very next character and load it into a
        while ( ( $this->current = $this->getChar() ) !== false ) {
            switch ( $this->current ) {
                // If the string opener (single or double quote) is used
                // output it and break out of the while looping.
                case $stringType:
                    break 2;

                // New lines in strings without line delimiters are bad.
                // Actual new lines will be represented by the string `\n`, not the ascii character.
                // So those will be treated just fine using the switch  block below.
                case "\n":
                    if ( $stringType === '`' ) {
                        $this->echo( $this->current );
                    }
                    else {
                        throw new \RuntimeException( 'Unclosed string at position: ' . $startpos );
                    }
                    break;

                // Escaped characters get picked up here. If it's an escaped new line it's not really needed
                case '\\':

                    // If $this->ahead is a slash, w want to keep it, and the next character.
                    // Unless it's a new line; New lines as actual strings will be
                    // preserved, but escaped new lines should be reduced.
                    $this->ahead = $this->getChar();

                    // If b is a new line we discard a and b and restart the loop.
                    if ( $this->ahead === "\n" ) {
                        break;
                    }

                    // echo out the escaped character and restart the loop.
                    $this->echo( $this->current . $this->ahead );
                    break;

                // Since we're not dealing with any special cases we simply
                // output the character and continue our loop.
                default:
                    $this->echo( $this->current );
            }
        }
    }

    /**
     * When a regular expression is detected this function crawls for the end of
     * it and saves the whole regex.
     *
     * @throws \RuntimeException Unclosed regex will throw an error
     */
    protected function saveRegex() : void
    {
        if ( $this->current != " " ) {
            $this->echo( $this->current );
        }

        $this->echo( $this->ahead );

        while ( ( $this->current = $this->getChar() ) !== false ) {
            if ( $this->current === '/' ) {
                break;
            }

            if ( $this->current === '\\' ) {
                $this->echo( $this->current );
                $this->current = $this->getChar();
            }

            if ( $this->current === "\n" ) {
                throw new \RuntimeException( 'Unclosed regex pattern at position: ' . $this->index );
            }

            $this->echo( $this->current );
        }
        $this->ahead = $this->getReal();
    }

    /**
     * Checks to see if a character is alphanumeric.
     *
     * @param string  $char  Just one character
     *
     * @return bool
     */
    protected static function isAlphaNumeric( string $char ) : bool
    {
        return preg_match( '/^[\w\$\pL]$/', $char ) === 1 || $char == '/';
    }

    protected function endsInKeyword() : bool
    {
        # When this function is called A is not yet assigned to output.
        # Regular expression only needs to check final part of output for keyword.
        $testOutput = \substr( $this->output . $this->current, -1 * ( $this->max_keyword_len + 10 ) );

        foreach ( $this::$keywords as $keyword ) {
            if ( \preg_match( '/[^\w]' . $keyword . '[ ]?$/i', $testOutput ) === 1 ) {
                return true;
            }
        }
        return false;
    }

    /**
     * Replace patterns in the given string and store the replacement
     *
     *
     */
    protected function lock() : self
    {
        /* lock things like <code>"asd" + ++x;</code> */
        $lock = '"LOCK---' . crc32( time() ) . '"';

        $matches = [];
        \preg_match( '/([+-])(\s+)([+-])/S', $this->string, $matches );
        if ( empty( $matches ) ) {
            return $this;
        }

        dump( $matches );

        $this->locks[ $lock ] = $matches[ 2 ];

        $this->string = \preg_replace( '/([+-])\s+([+-])/S', "$1{$lock}$2", $this->string );
        /* -- */

        return $this;
    }

    /**
     * Replace "locks" with the original characters
     *
     */
    protected function unlock() : void
    {
        if ( empty( $this->locks ) ) {
            return;
        }

        foreach ( $this->locks as $lock => $replacement ) {
            $this->string = \str_replace( $lock, $replacement, $this->string );
        }
    }

}