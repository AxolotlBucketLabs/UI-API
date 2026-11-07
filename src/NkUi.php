<?php

declare(strict_types=1);

namespace ninjaknights\ui;

use ninjaknights\ui\bossbar\BossBarListener;
use ninjaknights\ui\bossbar\BossBarManager;
use ninjaknights\ui\form\FormModule;
use ninjaknights\ui\sidebar\SidebarListener;
use ninjaknights\ui\sidebar\SidebarManager;
use pocketmine\plugin\DisablePluginException;
use pocketmine\plugin\PluginBase;

/**
 * The nk-ui plugin. Other plugins add "depend: [nk-ui]" and fetch it with
 * $server->getPluginManager()->getPlugin('nk-ui').
 */
final class NkUi extends PluginBase
{
    private ?FormModule $forms = null;
    private ?SidebarManager $sidebars = null;
    private ?BossBarManager $bossBars = null;

    protected function onEnable(): void
    {
        $this->saveDefaultConfig();
        try {
            $modules = ModuleConfig::fromArray($this->getConfig()->getAll());
        } catch (\InvalidArgumentException $e) {
            $this->getLogger()->error('Invalid config.yml: ' . $e->getMessage());
            throw new DisablePluginException();
        }

        $events = $this->getServer()->getPluginManager();
        if ($modules->forms) {
            $this->forms = new FormModule();
        }
        if ($modules->sidebar) {
            $this->sidebars = new SidebarManager();
            $events->registerEvents(new SidebarListener($this->sidebars), $this);
        }
        if ($modules->bossBar) {
            $this->bossBars = new BossBarManager();
            $events->registerEvents(new BossBarListener($this->bossBars), $this);
        }
        $this->getServer()->getCommandMap()->register($this->getName(), new NkUiCommand($this));
        $this->getLogger()->info('Modules: ' . self::describe($modules->toArray()));
    }

    protected function onDisable(): void
    {
        $this->sidebars?->close();
        $this->bossBars?->close();
        $this->forms = null;
        $this->sidebars = null;
        $this->bossBars = null;
    }

    /** @throws ModuleDisabledException */
    public function forms(): FormModule
    {
        return $this->forms ?? throw ModuleDisabledException::for(ModuleConfig::FORMS);
    }

    /** @throws ModuleDisabledException */
    public function sidebars(): SidebarManager
    {
        return $this->sidebars ?? throw ModuleDisabledException::for(ModuleConfig::SIDEBAR);
    }

    /** @throws ModuleDisabledException */
    public function bossBars(): BossBarManager
    {
        return $this->bossBars ?? throw ModuleDisabledException::for(ModuleConfig::BOSSBAR);
    }

    public function hasForms(): bool
    {
        return $this->forms !== null;
    }

    public function hasSidebars(): bool
    {
        return $this->sidebars !== null;
    }

    public function hasBossBars(): bool
    {
        return $this->bossBars !== null;
    }

    /** @return array<string, bool> module name => enabled */
    public function modules(): array
    {
        return [
            ModuleConfig::FORMS => $this->hasForms(),
            ModuleConfig::SIDEBAR => $this->hasSidebars(),
            ModuleConfig::BOSSBAR => $this->hasBossBars(),
        ];
    }

    /** @param array<string, bool> $modules */
    public static function describe(array $modules): string
    {
        $parts = [];
        foreach ($modules as $name => $enabled) {
            $parts[] = $name . ' ' . ($enabled ? 'on' : 'off');
        }
        return implode(', ', $parts);
    }
}
