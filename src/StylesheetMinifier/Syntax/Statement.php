<?php

declare(strict_types=1);

namespace Support\StylesheetMinifier\Syntax;

use Core\Interface\DataInterface;
use InvalidArgumentException;

/**
 * ```.
 * @ identifier rule;
 * ```
 *
 * @internal
 * @author Martin Nielsen <mn@northrook.com>
 */
final readonly class Statement implements DataInterface
{
    public string $identifier;

    public string $rule;

    public function __construct(
        string $identifier,
        string $rule,
    ) {
        if ( ! \str_starts_with( $identifier, '@' ) ) {
            throw new InvalidArgumentException( 'CSS Identifier must start with "@".' );
        }

        $this->identifier = \strtolower( \trim( $identifier ) );
        $this->rule       = \trim( $rule, " \n\r\t\v\0\"';" );
    }
}
