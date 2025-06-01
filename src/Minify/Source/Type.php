<?php

namespace Support\Minify\Source;

use function Support\{is_path, is_url, normalize_path, normalize_url, str_includes_any};

/**
 * @internal
 * @used-by \Support\Minify\Source
 */
enum Type
{
    /** The source file lives on the local filesystem. `/path/to/file.ext` */
    case PATH;

    /** The source file lives on a remote server. `//domain.tdl/path/to/file.ext` */
    case URL;

    /** The source contains raw code */
    case STRING;

    /** When the source type cannot be derived */
    case UNKNOWN;

    public static function from( string $source ) : self
    {
        return match ( true ) {
            is_url( $source )                  => Type::URL,
            is_path( $source )                 => Type::PATH,
            str_includes_any( $source, '{;}' ) => Type::STRING,
            default                            => Type::UNKNOWN,
        };
    }

    public static function normalize( string $source, ?Type $by = null ) : string
    {
        return match ( $by ?? Type::from( $source ) ) {
            Type::URL  => normalize_url( $source ),
            Type::PATH => normalize_path( $source ),
            default    => $source,
        };
    }
}
