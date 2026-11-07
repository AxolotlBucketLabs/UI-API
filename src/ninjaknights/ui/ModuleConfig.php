<?php

declare(strict_types=1);

namespace ninjaknights\ui;

/**
 * Which nk-ui modules a plugin uses. Build it in code, or read it from your plugin's
 * config.yml with fromArray(), e.g. a section like:
 *
 *     ui:
 *       forms: true
 *       sidebar: true
 *       bossbar: false
 *
 * Missing entries default to enabled.
 */
final class ModuleConfig
{
    public const FORMS = 'forms';
    public const SIDEBAR = 'sidebar';
    public const BOSSBAR = 'bossbar';
    private const MODULES = [self::FORMS, self::SIDEBAR, self::BOSSBAR];

    public function __construct(
        public readonly bool $forms = true,
        public readonly bool $sidebar = true,
        public readonly bool $bossBar = true,
    ) {
    }

    /**
     * @param mixed $modules a map of module name => true/false, as parsed from YAML
     * @throws \InvalidArgumentException naming the bad setting
     */
    public static function fromArray(mixed $modules): self
    {
        if (!is_array($modules)) {
            throw new \InvalidArgumentException('nk-ui modules must map module names to true or false');
        }
        foreach (array_keys($modules) as $name) {
            if (!in_array($name, self::MODULES, true)) {
                throw new \InvalidArgumentException('Unknown nk-ui module "' . $name . '"; expected ' . implode(', ', self::MODULES));
            }
        }
        return new self(
            self::flag($modules, self::FORMS),
            self::flag($modules, self::SIDEBAR),
            self::flag($modules, self::BOSSBAR),
        );
    }

    /** @return array<string, bool> module name => enabled */
    public function toArray(): array
    {
        return [self::FORMS => $this->forms, self::SIDEBAR => $this->sidebar, self::BOSSBAR => $this->bossBar];
    }

    /** @param array<mixed> $modules */
    private static function flag(array $modules, string $name): bool
    {
        $value = $modules[$name] ?? true;
        if (!is_bool($value)) {
            throw new \InvalidArgumentException('nk-ui module "' . $name . '" must be true or false');
        }
        return $value;
    }
}
