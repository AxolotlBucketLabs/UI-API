<?php

declare(strict_types=1);

namespace ninjaknights\ui;

/** Thrown when a plugin asks nk-ui for a module that config.yml turned off. */
final class ModuleDisabledException extends \RuntimeException
{
    public static function for(string $module): self
    {
        return new self('The nk-ui ' . $module . ' module is disabled (modules.' . $module . ' in nk-ui\'s config.yml)');
    }
}
