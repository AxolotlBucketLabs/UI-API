<?php

declare(strict_types=1);

namespace AxolotlBucketLabs\UI\Tests\support;

use pmmp\encoding\ByteBufferWriter;
use pocketmine\form\Form;
use pocketmine\network\mcpe\protocol\AddActorPacket;
use pocketmine\network\mcpe\protocol\BossEventPacket;
use pocketmine\network\mcpe\protocol\ClientboundPacket;
use pocketmine\network\mcpe\protocol\DataPacket;
use pocketmine\network\mcpe\protocol\RemoveActorPacket;
use pocketmine\network\mcpe\protocol\RemoveObjectivePacket;
use pocketmine\network\mcpe\protocol\SetDisplayObjectivePacket;
use pocketmine\network\mcpe\protocol\SetScorePacket;
use pocketmine\network\mcpe\protocol\types\ScorePacketEntry;
use pocketmine\player\Player;

/**
 * Plays the part of a Bedrock client: it encodes every packet it receives (when the
 * encoding extension is loaded) and keeps the sidebar and boss bars it would show.
 * It reads the packet fields used by Axolotl-PM and keeps the state a client would
 * display.
 *
 * Packets that a real client would ignore or reject (scores for an unknown
 * objective, removing an unknown entry) are recorded in $errors.
 */
final class FakeClient
{
    public Player $player;
    public bool $connected = true;
    /** @var list<ClientboundPacket> */
    public array $packets = [];
    /** @var list<Form> */
    public array $forms = [];
    public int $formsClosed = 0;
    /** @var list<string> */
    public array $errors = [];

    /** @var array<string, array{title: string, entries: array<int, array{int, string}>}> */
    private array $objectives = [];
    private ?string $sidebar = null;
    /** @var array<int, array{title: string, progress: float, visible: bool}> */
    private array $bossBars = [];
    /** @var array<int, true> */
    private array $actors = [];

    /** @param \Closure(self): Player $player builds the player wired to this client */
    public function __construct(\Closure $player)
    {
        $this->player = $player($this);
    }

    public function receive(ClientboundPacket $packet): void
    {
        if (!$this->connected) {
            $this->errors[] = 'Packet sent to a disconnected player: ' . $packet::class;
        }
        if ($packet instanceof DataPacket && extension_loaded('encoding')) {
            $packet->encode(new ByteBufferWriter());
        }
        $this->packets[] = $packet;

        if ($packet instanceof AddActorPacket) {
            $this->actors[$packet->actorRuntimeId] = true;
        } elseif ($packet instanceof RemoveActorPacket) {
            if (!isset($this->actors[$packet->actorUniqueId])) {
                $this->errors[] = 'Removed unknown actor ' . $packet->actorUniqueId;
            }
            unset($this->actors[$packet->actorUniqueId]);
        } elseif ($packet instanceof SetDisplayObjectivePacket && $packet->displaySlot === SetDisplayObjectivePacket::DISPLAY_SLOT_SIDEBAR) {
            $this->objectives[$packet->objectiveName] = ['title' => $packet->displayName, 'entries' => []];
            $this->sidebar = $packet->objectiveName;
        } elseif ($packet instanceof RemoveObjectivePacket) {
            if (!isset($this->objectives[$packet->objectiveName])) {
                $this->errors[] = 'Removed unknown objective ' . $packet->objectiveName;
            }
            unset($this->objectives[$packet->objectiveName]);
            if ($this->sidebar === $packet->objectiveName) {
                $this->sidebar = null;
            }
        } elseif ($packet instanceof SetScorePacket) {
            $this->applyScores($packet);
        } elseif ($packet instanceof BossEventPacket) {
            $this->applyBossEvent($packet);
        }
    }

    /** @return ?array{title: string, lines: list<string>} what the sidebar shows, or null */
    public function sidebar(): ?array
    {
        if ($this->sidebar === null) {
            return null;
        }
        $objective = $this->objectives[$this->sidebar];
        $entries = array_values($objective['entries']);
        usort($entries, static fn(array $a, array $b): int => $a[0] <=> $b[0]);
        $lines = array_map(static fn(array $entry): string => (string) preg_replace('/\x{00A7}r\x{00A7}[0-9a-f]$/u', '', $entry[1]), $entries);
        return ['title' => $objective['title'], 'lines' => $lines];
    }

    /** @return list<string> titles of the boss bars on screen */
    public function visibleBossBars(): array
    {
        $titles = [];
        foreach ($this->bossBars as $bar) {
            if ($bar['visible']) {
                $titles[] = $bar['title'];
            }
        }
        return $titles;
    }

    /** @return ?array{title: string, progress: float, visible: bool} */
    public function bossBar(int $id): ?array
    {
        return $this->bossBars[$id] ?? null;
    }

    /**
     * @template T of ClientboundPacket
     * @param class-string<T> $class
     * @return list<T>
     */
    public function packetsOf(string $class): array
    {
        return array_values(array_filter($this->packets, static fn(ClientboundPacket $p): bool => $p instanceof $class));
    }

    public function clearPackets(): void
    {
        $this->packets = [];
    }

    private function applyScores(SetScorePacket $packet): void
    {
        // Axolotl-PM keeps the entries private.
        $entries = (new \ReflectionProperty(SetScorePacket::class, 'entries'))->getValue($packet);
        if (!is_array($entries)) {
            throw new \UnexpectedValueException('SetScorePacket entries must be an array');
        }
        foreach ($entries as $entry) {
            if (!$entry instanceof ScorePacketEntry) {
                throw new \UnexpectedValueException('Unexpected score entry');
            }
            // A removed entry has no custom name.
            $objective = self::stringOrEmpty($entry->objectiveName);
            if (!isset($this->objectives[$objective])) {
                $this->errors[] = 'Score for unknown objective ' . $objective;
                continue;
            }
            if (isset($entry->customName)) {
                $this->objectives[$objective]['entries'][$entry->scoreboardId] = [$entry->score, $entry->customName];
            } else {
                if (!isset($this->objectives[$objective]['entries'][$entry->scoreboardId])) {
                    $this->errors[] = 'Removed unknown score entry ' . $entry->scoreboardId;
                }
                unset($this->objectives[$objective]['entries'][$entry->scoreboardId]);
            }
        }
    }

    private static function stringOrEmpty(mixed $value): string
    {
        return is_string($value) ? $value : '';
    }

    private function applyBossEvent(BossEventPacket $packet): void
    {
        $id = $packet->bossActorUniqueId;
        if (!isset($this->actors[$id])) {
            $this->errors[] = 'Boss event for unknown actor ' . $id;
            return;
        }
        $bar = $this->bossBars[$id] ?? ['title' => '', 'progress' => 0.0, 'visible' => false];
        switch ($packet->eventType) {
            case BossEventPacket::TYPE_SHOW:
                $bar = ['title' => $packet->title, 'progress' => $packet->healthPercent, 'visible' => true];
                break;
            case BossEventPacket::TYPE_HIDE:
                $bar['visible'] = false;
                break;
            case BossEventPacket::TYPE_TITLE:
                $bar['title'] = $packet->title;
                break;
            case BossEventPacket::TYPE_HEALTH_PERCENT:
                $bar['progress'] = $packet->healthPercent;
                break;
        }
        $this->bossBars[$id] = $bar;
    }
}
