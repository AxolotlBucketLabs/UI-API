<?php

declare(strict_types=1);

namespace ninjaknights\ui\sidebar;

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

    private string $title = '';
    /** @var list<string> */
    private array $lines = [];
    private bool $visible = false;
    private bool $released = false;

    /** @internal Use SidebarManager::claim(). */
    public function __construct(
        private SidebarStack $stack,
        private string $owner,
        private int $priority,
        private int $order,
    ) {
    }

    public function owner(): string
    {
        return $this->owner;
    }

    public function priority(): int
    {
        return $this->priority;
    }

    public function title(): string
    {
        return $this->title;
    }

    /** @return list<string> */
    public function lines(): array
    {
        return $this->lines;
    }

    /** Whether this claim has content to show (show() was called and hide() was not). */
    public function isVisible(): bool
    {
        return $this->visible;
    }

    /** Whether this claim currently owns the player's sidebar. */
    public function isActive(): bool
    {
        return !$this->released && $this->stack->top() === $this;
    }

    public function isReleased(): bool
    {
        return $this->released;
    }

    /** @param list<string> $lines up to 15 lines; blank and repeated lines are allowed */
    public function show(string $title, array $lines): void
    {
        if ($this->released) {
            return;
        }
        self::validateLines($lines);
        $this->title = $title;
        $this->lines = $lines;
        $this->visible = true;
        $this->stack->refresh();
    }

    public function setTitle(string $title): void
    {
        if ($this->released) {
            return;
        }
        $this->title = $title;
        $this->stack->refresh();
    }

    /** @param list<string> $lines */
    public function setLines(array $lines): void
    {
        if ($this->released) {
            return;
        }
        self::validateLines($lines);
        $this->lines = $lines;
        $this->stack->refresh();
    }

    /** Replaces line $index, or appends when $index equals the current line count. */
    public function setLine(int $index, string $text): void
    {
        if ($index < 0 || $index > count($this->lines)) {
            throw new \OutOfRangeException('Line index ' . $index . ' is outside 0..' . count($this->lines));
        }
        $lines = $this->lines;
        $lines[$index] = $text;
        $this->setLines(array_values($lines));
    }

    /**
     * Shows nothing while keeping the claim. Lower-priority claims stay hidden; use
     * release() to let them show again.
     */
    public function hide(): void
    {
        if ($this->released) {
            return;
        }
        $this->visible = false;
        $this->stack->refresh();
    }

    public function setPriority(int $priority): void
    {
        if ($this->released) {
            return;
        }
        $this->priority = $priority;
        $this->stack->refresh();
    }

    /** Gives up the claim. The next claim in priority order is shown, if any. */
    public function release(): void
    {
        if (!$this->released) {
            $this->stack->release($this->owner);
        }
    }

    /** @internal */
    public function order(): int
    {
        return $this->order;
    }

    /** @internal Called by SidebarStack when the claim is removed. */
    public function markReleased(): void
    {
        $this->released = true;
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
}
