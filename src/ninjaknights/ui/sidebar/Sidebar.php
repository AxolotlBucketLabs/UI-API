<?php

declare(strict_types=1);

namespace ninjaknights\ui\sidebar;

use pocketmine\player\Player;

/**
 * One owner's claim on one player's sidebar. Get it from SidebarManager::claim().
 *
 * Every claim keeps its own title and lines. Only the highest-priority claim is on
 * screen; the others are kept and come back when it is released. Changes to a claim
 * that is not on screen are stored and shown once it becomes active.
 *
 * After release (by the owner, on quit, or when the owning plugin is disabled) the
 * claim is inert: every change is ignored. Claim again to get a new one.
 */
final class Sidebar
{
    public const MAX_LINES = 15;

    /** @var array{id: int, priority: int, title: string, lines: list<string>, visible: bool} */
    private array $last;

    /** @internal Use SidebarManager::claim(). */
    public function __construct(
        private SidebarBus $bus,
        private Player $player,
        private string $owner,
        private int $id,
    ) {
        $this->last = ['id' => $id, 'priority' => 0, 'title' => '', 'lines' => [], 'visible' => false];
        $this->state();
    }

    public function owner(): string
    {
        return $this->owner;
    }

    /** @internal Identifies this claim; a new claim by the same owner gets a new id. */
    public function id(): int
    {
        return $this->id;
    }

    public function priority(): int
    {
        return $this->state()['priority'];
    }

    public function title(): string
    {
        return $this->state()['title'];
    }

    /** @return list<string> */
    public function lines(): array
    {
        return $this->state()['lines'];
    }

    /** Whether this claim has content to show (show() was called and hide() was not). */
    public function isVisible(): bool
    {
        return $this->state()['visible'];
    }

    /** Whether this claim currently owns the player's sidebar. */
    public function isActive(): bool
    {
        return !$this->isReleased() && ($this->bus->owners($this->player)[0] ?? null) === $this->owner;
    }

    public function isReleased(): bool
    {
        return $this->current() === null;
    }

    /** @param list<string> $lines up to 15 lines; blank and repeated lines are allowed */
    public function show(string $title, array $lines): void
    {
        self::validateLines($lines);
        $this->write($title, $lines, true);
    }

    public function setTitle(string $title): void
    {
        $state = $this->state();
        $this->write($title, $state['lines'], $state['visible']);
    }

    /** @param list<string> $lines */
    public function setLines(array $lines): void
    {
        self::validateLines($lines);
        $state = $this->state();
        $this->write($state['title'], $lines, $state['visible']);
    }

    /** Replaces line $index, or appends when $index equals the current line count. */
    public function setLine(int $index, string $text): void
    {
        $lines = $this->lines();
        if ($index < 0 || $index > count($lines)) {
            throw new \OutOfRangeException('Line index ' . $index . ' is outside 0..' . count($lines));
        }
        $lines[$index] = $text;
        $this->setLines(array_values($lines));
    }

    /**
     * Shows nothing while keeping the claim. Lower-priority claims stay hidden; use
     * release() to let them show again.
     */
    public function hide(): void
    {
        $state = $this->state();
        $this->write($state['title'], $state['lines'], false);
    }

    public function setPriority(int $priority): void
    {
        if ($this->bus->setPriority($this->player, $this->owner, $this->id, $priority)) {
            $this->state();
        }
    }

    /** Gives up the claim. The next claim in priority order is shown, if any. */
    public function release(): void
    {
        $this->bus->release($this->player, $this->owner, $this->id);
    }

    /** @param array<mixed> $lines */
    public static function validateLines(array $lines): void
    {
        if (!array_is_list($lines) || count($lines) > self::MAX_LINES) {
            throw new \InvalidArgumentException('A sidebar takes a list of at most ' . self::MAX_LINES . ' lines');
        }
        foreach ($lines as $i => $line) {
            if (!is_string($line)) {
                throw new \InvalidArgumentException('Sidebar line ' . $i . ' must be a string');
            }
            if (str_contains($line, "\n")) {
                throw new \InvalidArgumentException('Sidebar line ' . $i . ' must not contain a line break');
            }
        }
    }

    /** @param list<string> $lines */
    private function write(string $title, array $lines, bool $visible): void
    {
        if ($this->bus->update($this->player, $this->owner, $this->id, $title, $lines, $visible)) {
            $this->state();
        }
    }

    /** @return ?array{id: int, priority: int, title: string, lines: list<string>, visible: bool} */
    private function current(): ?array
    {
        $state = $this->bus->get($this->player, $this->owner);
        return $state !== null && $state['id'] === $this->id ? $state : null;
    }

    /**
     * The claim's state, or the last known state once released.
     *
     * @return array{id: int, priority: int, title: string, lines: list<string>, visible: bool}
     */
    private function state(): array
    {
        return $this->last = $this->current() ?? $this->last;
    }
}
