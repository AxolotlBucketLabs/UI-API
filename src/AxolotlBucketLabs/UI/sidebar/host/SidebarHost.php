<?php

declare(strict_types=1);

namespace AxolotlBucketLabs\UI\sidebar\host;

use AxolotlBucketLabs\UI\sidebar\protocol\ScoreboardPackets;
use AxolotlBucketLabs\UI\sidebar\Sidebar;
use pocketmine\player\Player;

/**
 * @internal Owns every sidebar claim on the server and draws the winning one.
 *
 * UI-API is shaded into each plugin that uses it, so every plugin has its own copy
 * of these classes. Only the first copy to start creates a host; it publishes the
 * host's operations as plain closures (see bus()), and every copy, including the
 * first, works through them. The closures only take and return PHP and server API
 * types, so copies with different namespaces can call each other's host.
 *
 * Protocol 1. Changing what a closure accepts or returns needs a new protocol
 * number and a new global key in SidebarBus.
 */
final class SidebarHost
{
    public const PROTOCOL = 1;

    /** @var \WeakMap<Player, ClaimStack> */
    private \WeakMap $stacks;
    private int $nextId = 1;
    private int $nextOrder = 0;

    public function __construct(private ScoreboardPackets $packets)
    {
        $this->stacks = new \WeakMap();
    }

    /**
     * The published form: plain values and closures only, readable by any copy.
     *
     * @return array<string, mixed>
     */
    public function bus(): array
    {
        return [
            'protocol' => self::PROTOCOL,
            'claim' => $this->claim(...),
            'release' => $this->release(...),
            'update' => $this->update(...),
            'setPriority' => $this->setPriority(...),
            'get' => $this->get(...),
            'owners' => $this->owners(...),
            'releaseWhere' => $this->releaseWhere(...),
            'clear' => $this->clear(...),
        ];
    }

    /** Claims, or re-prioritises the owner's existing claim. Returns the claim id. */
    public function claim(Player $player, string $owner, int $priority): int
    {
        if (trim($owner) === '') {
            throw new \InvalidArgumentException('Sidebar owner must not be empty');
        }
        $stack = $this->stacks[$player] ?? null;
        if ($stack === null) {
            $stack = new ClaimStack($player, $this->packets);
            $this->stacks[$player] = $stack;
        }
        $claim = $stack->get($owner);
        if ($claim === null) {
            $claim = new ClaimState($this->nextId++, $owner, $priority, $this->nextOrder++);
            $stack->add($claim);
        } else {
            $claim->priority = $priority;
        }
        $stack->refresh();
        return $claim->id;
    }

    /** Releases the owner's claim if its id matches; id 0 matches any claim. */
    public function release(Player $player, string $owner, int $id): void
    {
        $stack = $this->stacks[$player] ?? null;
        $claim = $stack?->get($owner);
        if ($stack === null || $claim === null || ($id !== 0 && $claim->id !== $id)) {
            return;
        }
        $stack->remove($owner);
        $this->settle($player, $stack);
    }

    /**
     * @param list<string> $lines
     * @return bool false when the claim no longer exists
     */
    public function update(Player $player, string $owner, int $id, string $title, array $lines, bool $visible): bool
    {
        Sidebar::validateLines($lines);
        $stack = $this->stacks[$player] ?? null;
        $claim = $this->find($player, $owner, $id);
        if ($stack === null || $claim === null) {
            return false;
        }
        $claim->title = $title;
        $claim->lines = $lines;
        $claim->visible = $visible;
        $stack->refresh();
        return true;
    }

    public function setPriority(Player $player, string $owner, int $id, int $priority): bool
    {
        $stack = $this->stacks[$player] ?? null;
        $claim = $this->find($player, $owner, $id);
        if ($stack === null || $claim === null) {
            return false;
        }
        $claim->priority = $priority;
        $stack->refresh();
        return true;
    }

    /** @return ?array{id: int, priority: int, title: string, lines: list<string>, visible: bool} */
    public function get(Player $player, string $owner): ?array
    {
        return ($this->stacks[$player] ?? null)?->get($owner)?->toArray();
    }

    /** @return list<string> owners, the one on screen first */
    public function owners(Player $player): array
    {
        $stack = $this->stacks[$player] ?? null;
        return $stack === null ? [] : array_map(static fn(ClaimState $c): string => $c->owner, $stack->ordered());
    }

    /**
     * Releases matching owners for every player, redrawing each player once.
     *
     * @param \Closure(string): bool $matches
     */
    public function releaseWhere(\Closure $matches): void
    {
        $changed = [];
        foreach ($this->stacks as $player => $stack) {
            if ($stack->removeWhere($matches)) {
                $changed[] = [$player, $stack];
            }
        }
        foreach ($changed as [$player, $stack]) {
            $this->settle($player, $stack);
        }
    }

    public function clear(Player $player): void
    {
        $stack = $this->stacks[$player] ?? null;
        if ($stack !== null) {
            $stack->removeAll();
            $this->settle($player, $stack);
        }
    }

    private function find(Player $player, string $owner, int $id): ?ClaimState
    {
        $claim = ($this->stacks[$player] ?? null)?->get($owner);
        return $claim !== null && $claim->id === $id ? $claim : null;
    }

    /** Redraws, and forgets the player once nothing is claimed. */
    private function settle(Player $player, ClaimStack $stack): void
    {
        $stack->refresh();
        if ($stack->isEmpty()) {
            unset($this->stacks[$player]);
        }
    }
}
