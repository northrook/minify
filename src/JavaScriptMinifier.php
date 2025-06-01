<?php

declare(strict_types=1);

namespace Support;

use Core\Exception\RegexpException;
use LogicException;

final class JavaScriptMinifier extends Minify
{
    /** @var string[] */
    protected array $comments = [];

    protected function process() : string
    {
        $this->buffer = \implode( NEWLINE, $this->process );

        $this
            ->removeComments()
            ->handleWhitespace();
        //
        // [JShrink]
        // $this->buffer = JavaScriptMinifier\Minifier::minify( $this->buffer );
        return $this->buffer;
    }

    protected function parse() : void
    {
        if ( ! $this->buffer ) {
            throw new LogicException( "The buffer is empty.\Run minify() first." );
        }
    }

    protected function removeComments() : self
    {
        $this->buffer = (string) \preg_replace_callback(
            pattern  : '#^\h*\/\/[^\n]*|^\h*\/\*[\s\S]*?\*\/#m',
            callback : function( $matches ) : string {
                $this->comments[] = $matches[0];
                return '';
            },
            subject  : $this->buffer,
        );
        RegexpException::check();

        return $this;
    }

    protected function handleWhitespace() : self
    {
        // reduce consecutive newlines to a single one
        $this->buffer = \preg_replace(
            pattern     : '~[\\n]+~',
            replacement : NEWLINE,
            subject     : $this->buffer,
        );
        RegexpException::check();

        $tabSize = null;

        $lines = \explode( NEWLINE, $this->buffer );

        for ( $i = 0; $i < \count( $lines ); $i++ ) {
            $leadingSpaces = \strspn( $lines[$i], ' ' );
            if ( ! $tabSize && $leadingSpaces ) {
                $tabSize = $leadingSpaces;
            }

            if ( ! \trim( $lines[$i] ) ) {
                unset( $lines[$i] );

                continue;
            }
            $line = \rtrim( $lines[$i] );
            $line = \substr( $line, $leadingSpaces );

            if ( $leadingSpaces && $tabSize ) {
                $line = \str_repeat( "\t", $leadingSpaces / $tabSize ).$line;
            }

            $nextLine = $lines[$i + 1] ?? null;

            if ( $nextLine
                 && \preg_match( '#[\w?!_h,.]\h*[`"\']\h\+$#', $line )
                 && \preg_match( '#^\h*[`"\']\h*[\w?!_h,.]#', $nextLine )
            ) {
                $line     = \substr( \rtrim( $line, ' +' ), 0, -1 );
                $nextLine = \substr( \ltrim( $nextLine ), 1 );

                $line .= $nextLine;

                $lines[$i + 1] = '';
            }
            $lines[$i] = $line;
        }

        $this->buffer = \implode( NEWLINE, \array_filter( $lines ) );
        $this->buffer = \trim( \str_replace( ["\t"], '  ', $this->buffer ) );
        return $this;
    }

    /**
     * Further compress the script using the Toptal API.
     *
     * @return $this
     */
    public function compress() : self
    {
        if ( ! $this->buffer ) {
            throw new LogicException( "The buffer is empty.\Run minify() first." );
        }

        $api     = 'https://www.toptal.com/developers/javascript-minifier/api/raw';
        $headers = ['Content-Type: application/x-www-form-urlencoded'];
        $query   = \http_build_query( ['input' => $this->buffer] );

        // init the request, set various options, and send it
        $connection = \curl_init();

        \curl_setopt( $connection, CURLOPT_URL, $api );
        \curl_setopt( $connection, CURLOPT_RETURNTRANSFER, true );
        \curl_setopt( $connection, CURLOPT_POST, true );
        \curl_setopt( $connection, CURLOPT_HTTPHEADER, $headers );
        \curl_setopt( $connection, CURLOPT_POSTFIELDS, $query );

        $this->buffer = \curl_exec( $connection );

        // finally, close the request
        \curl_close( $connection );

        return $this;
    }
}
