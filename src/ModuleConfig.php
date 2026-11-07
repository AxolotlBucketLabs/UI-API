<?php

declare(strict_types=1);

namespace ninjaknights\ui;

/** The "modules" section of config.yml. Missing entries default to enabled. */
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
     * @param array<mixed> $config the whole parsed config.yml
     * @throws \InvalidArgumentException naming the bad setting
     */
    public static function fromArray(array $config): self
    {
        $modules = $config['modules'] ?? [];
        if (!is_array($modules)) {
            throw new \InvalidArgumentException('"modules" must map module names to true or false');
        }
        foreach (array_keys($modules) as $name) {
            if (!in_array($name, self::MODULES, true)) {
                throw new \InvalidArgumentException('Unknown module "' . $name . '"; expected ' . implode(', ', self::MODULES));
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
            throw new \InvalidArgumentException('modules.' . $name . ' must be true or false');
        }
        return $value;
    }
}
