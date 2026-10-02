<?php

/**
 * Entry point for a tray app. Global scope allows declarations only — every
 * executable statement must live inside a function or method.
 *
 * Unlike a normal window app there is no store here: a tray app's state is
 * usually small (visibility, counters, connection status), so the controller
 * holds it directly. Add an app/ store when you actually need persistence.
 */

function main(): int
{
    try {
        return (new TrayController('<APP>'))->run();
    } catch (Throwable $error) {
        fwrite(STDERR, '<APP>: ' . $error->getMessage() . PHP_EOL);
        return 1;
    }
}
