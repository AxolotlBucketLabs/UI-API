<?php

declare(strict_types=1);

namespace ninjaknights\ui\sidebar;

use ninjaknights\ui\sidebar\protocol\ScoreboardPackets;
use pocketmine\entity\Entity;
use pocketmine\network\mcpe\protocol\ClientboundPacket;
use pocketmine\network\mcpe\protocol\RemoveObjectivePacket;
use pocketmine\network\mcpe\protocol\SetDisplayObjectivePacket;
use pocketmine\player\Player;

/**
 * @internal All sidebar claims of one player, and what the client currently shows.
 *
 * The highest-priority claim owns the sidebar; on a tie the earlier claim keeps it.
 * Only that claim's content is sent. Line changes are sent as a diff, so updating
 * a score does not redraw the whole sidebar.
 */
final class SidebarStack
{
    /** @var array<string, Sidebar> */
    private array $claims = [];
    private int $nextOrder = 0;

    private ?Sidebar $rendered = null;
    private ?string $objective = null;
    private string $renderedTitle = '';
    /** @var list<array{int, int, string}> scoreboard id, score, text per line */
    private array $renderedLines = [];

    public function __construct(private Player $player, private ScoreboardPackets $packets)
    {
    }

    public function claim(string $owner, int $priority): Sidebar
    {
        $claim = $this->claims[$owner] ?? null;
        if ($claim !== null) {
            $claim->setPriority($priority);
            return $claim;
        }
        $claim = new Sidebar($this, $owner, $priority, $this->nextOrder++);
        $this->claims[$owner] = $claim;
        $this->refresh();
        return $claim;
    }

    public function get(string $owner): ?Sidebar
    {
        return $this->claims[$owner] ?? null;
    }

    /** @return list<Sidebar> claims, highest priority first */
    public function claims(): array
    {
        $claims = array_values($this->claims);
        usort($claims, static fn(Sidebar $a, Sidebar $b): int => [$b->priority(), $a->order()] <=> [$a->priority(), $b->order()]);
        return $claims;
    }

    public function top(): ?Sidebar
    {
        return $this->claims()[0] ?? null;
    }

    public function isEmpty(): bool
    {
        return $this->claims === [];
    }

    public function release(string $owner): void
    {
        $claim = $this->claims[$owner] ?? null;
        if ($claim === null) {
            return;
        }
        unset($this->claims[$owner]);
        $claim->markReleased();
        $this->refresh();
    }

    /**
     * Releases every claim whose owner matches, then refreshes once, so no
     * intermediate claim flashes on screen.
     *
     * @param \Closure(string): bool $matches
     */
    public function releaseWhere(\Closure $matches): void
    {
        $released = false;
        foreach ($this->claims as $owner => $claim) {
            if ($matches($owner)) {
                unset($this->claims[$owner]);
                $claim->markReleased();
                $released = true;
            }
        }
        if ($released) {
            $this->refresh();
        }
    }

    public function releaseAll(): void
    {
        foreach ($this->claims as $claim) {
            $claim->markReleased();
        }
        $this->claims = [];
        $this->refresh();
    }

    /** Brings the client in line with the top claim. */
    public function refresh(): void
    {
        if (!$this->player->isConnected()) {
            $this->forgetRendered();
            return;
        }
        $top = $this->top();
        if ($top === null || !$top->isVisible()) {
            $this->removeRendered();
            return;
        }
        $objective = $this->objective;
        if ($objective === null || $this->rendered !== $top || $this->renderedTitle !== $top->title()) {
            $this->removeRendered();
            $objective = 'nkui.' . Entity::nextRuntimeId();
            $this->send(SetDisplayObjectivePacket::create(
                SetDisplayObjectivePacket::DISPLAY_SLOT_SIDEBAR,
                $objective,
                $top->title(),
                'dummy',
                SetDisplayObjectivePacket::SORT_ORDER_ASCENDING
            ));
            $this->objective = $objective;
            $this->rendered = $top;
            $this->renderedTitle = $top->title();
        }
        $this->syncLines($objective, $top->lines());
    }

    /** @param list<string> $lines */
    private function syncLines(string $objective, array $lines): void
    {
        $remove = [];
        $add = [];
        $next = [];
        $count = max(count($lines), count($this->renderedLines));
        for ($i = 0; $i < $count; ++$i) {
            $old = $this->renderedLines[$i] ?? null;
            // A suffix of invisible format codes keeps blank and repeated lines distinct.
            $text = isset($lines[$i]) ? $lines[$i] . "\u{00A7}r\u{00A7}" . dechex($i) : null;
            if ($old !== null && $old[2] === $text) {
                $next[] = $old;
                continue;
            }
            if ($old !== null) {
                $remove[] = [$old[0], $old[1]];
            }
            if ($text !== null) {
                $entry = [Entity::nextRuntimeId(), $i + 1, $text];
                $add[] = $entry;
                $next[] = $entry;
            }
        }
        if ($remove !== []) {
            $this->send($this->packets->removeEntries($objective, $remove));
        }
        if ($add !== []) {
            $this->send($this->packets->setEntries($objective, $add));
        }
        $this->renderedLines = $next;
    }

    private function removeRendered(): void
    {
        if ($this->objective !== null) {
            $this->send(RemoveObjectivePacket::create($this->objective));
        }
        $this->forgetRendered();
    }

    private function forgetRendered(): void
    {
        $this->rendered = null;
        $this->objective = null;
        $this->renderedTitle = '';
        $this->renderedLines = [];
    }

    private function send(ClientboundPacket $packet): void
    {
        $this->player->getNetworkSession()->sendDataPacket($packet);
    }
}
