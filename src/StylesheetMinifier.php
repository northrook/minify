<?php

declare(strict_types=1);

namespace Support;

use Support\StylesheetMinifier\Compiler;

final class StylesheetMinifier extends Minify
{
    private readonly Compiler $compiler;

    protected function process() : string
    {
        // Initialize the compiler from provided $sources
        $this->compiler ??= new Compiler( $this->process, $this->logger );
        $this->compiler
            ->parseEnqueued()
            ->mergeRules()
            ->generateStylesheet();

        return $this->compiler->css;
    }
}
