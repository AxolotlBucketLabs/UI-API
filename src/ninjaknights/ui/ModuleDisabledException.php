<?php

declare(strict_types=1);

namespace ninjaknights\ui;

/** Thrown when a plugin asks for an nk-ui module it did not enable. */
final class ModuleDisabledException extends \RuntimeException
{
    public static function for(string $module): self
    {
        return new self('The nk-ui ' . $module . ' module is not enabled for this plugin');
    }
}
