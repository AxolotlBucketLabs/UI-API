<?php

declare(strict_types=1);

namespace AxolotlBucketLabs\UI\sidebar;

use pocketmine\player\Player;
use pocketmine\plugin\Plugin;
use pocketmine\Server;

/**
 * One plugin's access to the server-wide sidebar. Create it in your onEnable():
 *
 *     $this->sidebars = new SidebarManager($this);
 *
 * Every plugin, whatever copy of UI-API it bundles, claims from the same shared host
 * (see SidebarBus). A claim has an owner and a priority. The highest priority is
 * shown; on a tie the earlier claim keeps the sidebar. Releasing a claim shows the
 * next one again. A player's claims go when they quit, and every claim this manager
 * made goes when your plugin is disabled.
 */
final class SidebarManager
{
    private SidebarBus $bus;
    /** @var \WeakMap<Player, array<string, Sidebar>> */
    private \WeakMap $handles;
    /** @var array<string, true> owners this manager has claimed with */
    private array $owners = [];

    /**
     * @param Plugin $plugin your plugin, which must be enabled; the cleanup listener is registered for it
     * @param ?SidebarBus $bus the host to use; the server-wide shared one by default
     */
    public function __construct(Plugin $plugin, ?SidebarBus $bus = null)
    {
        $this->bus = $bus ?? SidebarBus::shared();
        $this->handles = new \WeakMap();
        Server::getInstance()->getPluginManager()->registerEvents(new SidebarListener($this, $plugin), $plugin);
    }

    /**
     * Claims the player's sidebar, or returns the owner's existing claim with its
     * priority updated. The claim may not be on screen; see Sidebar::isActive().
     */
    public function claim(Player $player, Plugin|string $owner, int $priority = 0): Sidebar
    {
        $owner = self::ownerName($owner);
        $this->owners[$owner] = true;
        return $this->handle($player, $owner, $this->bus->claim($player, $owner, $priority));
    }

    public function get(Player $player, Plugin|string $owner): ?Sidebar
    {
        $owner = self::ownerName($owner);
        $state = $this->bus->get($player, $owner);
        return $state === null ? null : $this->handle($player, $owner, $state['id']);
    }

    public function release(Player $player, Plugin|string $owner): void
    {
        $this->bus->release($player, self::ownerName($owner), 0);
    }

    /** The owner whose claim is on screen for the player, or null. */
    public function activeOwner(Player $player): ?string
    {
        return $this->bus->owners($player)[0] ?? null;
    }

    /** @return list<Sidebar> the player's claims from every plugin, highest priority first */
    public function claims(Player $player): array
    {
        $claims = [];
        foreach ($this->bus->owners($player) as $owner) {
            $claim = $this->get($player, $owner);
            if ($claim !== null) {
                $claims[] = $claim;
            }
        }
        return $claims;
    }

    /** Releases every claim this manager made, for every player. Runs on its own when your plugin is disabled. */
    public function releaseAll(): void
    {
        $owners = $this->owners;
        $this->owners = [];
        if ($owners !== []) {
            $this->bus->releaseWhere(static fn(string $owner): bool => isset($owners[$owner]));
        }
        $this->handles = new \WeakMap();
    }

    /** Releases every claim for the player, from every plugin, and removes the sidebar. */
    public function clear(Player $player): void
    {
        $this->bus->clear($player);
        unset($this->handles[$player]);
    }

    public static function ownerName(Plugin|string $owner): string
    {
        $name = $owner instanceof Plugin ? $owner->getName() : $owner;
        if (trim($name) === '') {
            throw new \InvalidArgumentException('Sidebar owner must not be empty');
        }
        return $name;
    }

    private function handle(Player $player, string $owner, int $id): Sidebar
    {
        $handles = $this->handles[$player] ?? [];
        $handle = $handles[$owner] ?? null;
        if ($handle === null || $handle->id() !== $id) {
            $handle = new Sidebar($this->bus, $player, $owner, $id);
            $handles[$owner] = $handle;
            $this->handles[$player] = $handles;
        }
        return $handle;
    }
}
