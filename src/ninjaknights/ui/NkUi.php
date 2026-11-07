<?php

declare(strict_types=1);

namespace ninjaknights\ui;

use ninjaknights\ui\bossbar\BossBarListener;
use ninjaknights\ui\bossbar\BossBarManager;
use ninjaknights\ui\form\FormModule;
use ninjaknights\ui\sidebar\SidebarListener;
use ninjaknights\ui\sidebar\SidebarManager;
use pocketmine\plugin\Plugin;
use pocketmine\Server;

/**
 * nk-ui for one plugin. Call NkUi::register($this, ...) from your plugin's onEnable().
 *
 * Only the enabled modules are created and only their listeners are registered, all
 * owned by your plugin. When your plugin is disabled, its sidebar claims are released
 * and its boss bars hidden.
 */
final class NkUi
{
    /** @var array<string, self> plugin name => instance */
    private static array $instances = [];

    private function __construct(
        private Plugin $plugin,
        private ?FormModule $forms,
        private ?SidebarManager $sidebars,
        private ?BossBarManager $bossBars,
    ) {
    }

    /**
     * Sets nk-ui up for the plugin. Later calls for the same plugin return the same
     * instance and ignore $modules.
     *
     * @param ?ModuleConfig $modules which modules to enable; null enables all
     * @throws \LogicException when the plugin is not enabled yet (call this from onEnable())
     */
    public static function register(Plugin $plugin, ?ModuleConfig $modules = null): self
    {
        $existing = self::$instances[$plugin->getName()] ?? null;
        if ($existing !== null && $existing->plugin === $plugin) {
            return $existing;
        }
        if (!$plugin->isEnabled()) {
            throw new \LogicException('NkUi::register() must be called from onEnable() or later');
        }
        $modules ??= new ModuleConfig();
        // The Plugin interface has no getServer(); there is one server per process.
        $events = Server::getInstance()->getPluginManager();

        $sidebars = null;
        if ($modules->sidebar) {
            $sidebars = new SidebarManager();
            $events->registerEvents(new SidebarListener($sidebars), $plugin);
        }
        $bossBars = null;
        if ($modules->bossBar) {
            $bossBars = new BossBarManager();
            $events->registerEvents(new BossBarListener($bossBars), $plugin);
        }
        $ui = new self($plugin, $modules->forms ? new FormModule() : null, $sidebars, $bossBars);
        $events->registerEvents(new LifecycleListener($plugin, $ui), $plugin);
        return self::$instances[$plugin->getName()] = $ui;
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

    /**
     * Releases the plugin's sidebar claims and hides its boss bars. Runs on its own
     * when the plugin is disabled; afterwards register() creates a fresh instance.
     */
    public function shutdown(): void
    {
        $this->sidebars?->releaseAll();
        $this->bossBars?->close();
        if ((self::$instances[$this->plugin->getName()] ?? null) === $this) {
            unset(self::$instances[$this->plugin->getName()]);
        }
    }
}
