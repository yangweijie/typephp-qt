<?php

/**
 * Entry point. Global scope allows declarations only — every executable
 * statement must live inside a function or method.
 *
 * The natural next step is an "add / edit" dialog: add a <APP>_save() bridge
 * function, emit a `save` event from the C++ dialog, and handle it here.
 */

function main(): int
{
    try {
        return (new AppController(new AppStore()))->run();
    } catch (Throwable $error) {
        fwrite(STDERR, '<APP>: ' . $error->getMessage() . PHP_EOL);
        return 1;
    }
}
