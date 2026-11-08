<?php

declare(strict_types=1);

namespace AxolotlBucketLabs\UI\bossbar;

use AxolotlBucketLabs\UI\bossbar\protocol\BossBarPackets;
use pocketmine\entity\Attribute as EntityAttribute;
use pocketmine\entity\Entity;
use pocketmine\network\mcpe\protocol\AddActorPacket;
use pocketmine\network\mcpe\protocol\BossEventPacket;
use pocketmine\network\mcpe\protocol\ClientboundPacket;
use pocketmine\network\mcpe\protocol\RemoveActorPacket;
use pocketmine\network\mcpe\protocol\types\entity\Attribute as NetworkAttribute;
use pocketmine\network\mcpe\protocol\types\entity\EntityIds;
use pocketmine\network\mcpe\protocol\types\entity\EntityMetadataCollection;
use pocketmine\network\mcpe\protocol\types\entity\EntityMetadataFlags;
use pocketmine\network\mcpe\protocol\types\entity\EntityMetadataProperties;
use pocketmine\network\mcpe\protocol\types\entity\PropertySyncData;
use pocketmine\player\Player;

/**
 * One named boss bar for one player. Get it from BossBarManager::get().
 *
 * Changes made while hidden are stored and sent by the next show(). Once removed
 * (by the manager, on quit, or when your plugin is disabled) the bar is inert and
 * every change is ignored.
 */
final class BossBar
{
    private int $id;
    private bool $visible = false;
    private bool $removed = false;
    private string $title = '';
    private float $progress = 1.0;
    private BarColor $color = BarColor::PURPLE;
    private BarOverlay $overlay = BarOverlay::PROGRESS;
    private bool $actorSpawned = false;

    /** @internal Use BossBarManager::get(). */
    public function __construct(private Player $player, private string $key, private BossBarPackets $packets)
    {
        $this->id = Entity::nextRuntimeId();
    }

    /** The actor id the client knows this bar by. */
    public function id(): int
    {
        return $this->id;
    }

    public function key(): string
    {
        return $this->key;
    }

    public function title(): string
    {
        return $this->title;
    }

    public function progress(): float
    {
        return $this->progress;
    }

    public function color(): BarColor
    {
        return $this->color;
    }

    public function overlay(): BarOverlay
    {
        return $this->overlay;
    }

    public function isVisible(): bool
    {
        return $this->visible;
    }

    public function isRemoved(): bool
    {
        return $this->removed;
    }

    /**
     * Shows the bar, or updates it if already shown. A null color or overlay keeps
     * the current one (purple, unsegmented by default).
     */
    public function show(string $title, float $progress = 1.0, ?BarColor $color = null, ?BarOverlay $overlay = null): void
    {
        self::validateProgress($progress);
        if ($this->removed) {
            return;
        }
        $this->title = $title;
        $this->progress = $progress;
        $this->color = $color ?? $this->color;
        $this->overlay = $overlay ?? $this->overlay;
        $this->visible = true;
        $this->ensureActorSpawned();
        $this->send($this->packets->show($this->id, $this->title, $this->progress, $this->color, $this->overlay));
    }

    public function setTitle(string $title): void
    {
        if ($this->removed) {
            return;
        }
        $this->title = $title;
        if ($this->visible) {
            $this->send(BossEventPacket::title($this->id, $title));
        }
    }

    /** @param float $progress from 0.0 (empty) to 1.0 (full) */
    public function setProgress(float $progress): void
    {
        self::validateProgress($progress);
        if ($this->removed) {
            return;
        }
        $this->progress = $progress;
        if ($this->visible) {
            $this->send(BossEventPacket::healthPercent($this->id, $progress));
        }
    }

    public function setStyle(BarColor $color, ?BarOverlay $overlay = null): void
    {
        if ($this->removed) {
            return;
        }
        $this->color = $color;
        $this->overlay = $overlay ?? $this->overlay;
        if ($this->visible) {
            $this->send($this->packets->style($this->id, $this->color, $this->overlay));
        }
    }

    public function hide(): void
    {
        if ($this->visible) {
            $this->send(BossEventPacket::hide($this->id));
        }
        $this->visible = false;
    }

    /** Sends the bar again, e.g. when the client asks for it. */
    public function resend(): void
    {
        if ($this->visible && !$this->removed) {
            $this->ensureActorSpawned();
            $this->send($this->packets->show($this->id, $this->title, $this->progress, $this->color, $this->overlay));
        }
    }

    /** @internal Called by BossBarManager. Hides the bar and makes it inert. */
    public function markRemoved(): void
    {
        $this->hide();
        if ($this->actorSpawned) {
            $this->send(RemoveActorPacket::create($this->id));
            $this->actorSpawned = false;
        }
        $this->removed = true;
    }

    public static function validateProgress(float $progress): void
    {
        if (!is_finite($progress) || $progress < 0.0 || $progress > 1.0) {
            throw new \InvalidArgumentException('Boss bar progress must be between 0 and 1');
        }
    }

    private function ensureActorSpawned(): void
    {
        if (!$this->actorSpawned && $this->send($this->createActorPacket())) {
            $this->actorSpawned = true;
        }
    }

    private function createActorPacket(): AddActorPacket
    {
        $metadata = new EntityMetadataCollection();
        $metadata->setGenericFlag(EntityMetadataFlags::FIRE_IMMUNE, true);
        $metadata->setGenericFlag(EntityMetadataFlags::SILENT, true);
        $metadata->setGenericFlag(EntityMetadataFlags::INVISIBLE, true);
        $metadata->setGenericFlag(EntityMetadataFlags::NO_AI, true);
        $metadata->setString(EntityMetadataProperties::NAMETAG, '');
        $metadata->setFloat(EntityMetadataProperties::SCALE, 0.0);
        $metadata->setLong(EntityMetadataProperties::LEAD_HOLDER_EID, -1);
        $metadata->setFloat(EntityMetadataProperties::BOUNDING_BOX_WIDTH, 0.0);
        $metadata->setFloat(EntityMetadataProperties::BOUNDING_BOX_HEIGHT, 0.0);

        return AddActorPacket::create(
            $this->id,
            $this->id,
            EntityIds::SLIME,
            $this->player->getPosition()->asVector3(),
            null,
            0.0,
            0.0,
            0.0,
            0.0,
            [new NetworkAttribute(EntityAttribute::HEALTH, 0.0, 100.0, 100.0)],
            $metadata->getAll(),
            new PropertySyncData([], []),
            [],
            null
        );
    }

    private function send(ClientboundPacket $packet): bool
    {
        if (!$this->player->isConnected()) {
            return false;
        }
        $this->player->getNetworkSession()->sendDataPacket($packet);
        return true;
    }
}
