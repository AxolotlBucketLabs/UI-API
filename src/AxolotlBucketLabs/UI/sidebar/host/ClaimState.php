<?php

declare(strict_types=1);

namespace AxolotlBucketLabs\UI\sidebar\host;

/** @internal One owner's claim on one player's sidebar, as the host stores it. */
final class ClaimState
{
    public string $title = '';
    /** @var list<string> */
    public array $lines = [];
    public bool $visible = false;

    public function __construct(
        public readonly int $id,
        public readonly string $owner,
        public int $priority,
        public readonly int $order,
    ) {
    }

    /** @return array{id: int, priority: int, title: string, lines: list<string>, visible: bool} */
    public function toArray(): array
    {
        return ['id' => $this->id, 'priority' => $this->priority, 'title' => $this->title, 'lines' => $this->lines, 'visible' => $this->visible];
    }
}
