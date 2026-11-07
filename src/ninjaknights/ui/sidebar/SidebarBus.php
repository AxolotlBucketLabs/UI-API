<?php

declare(strict_types=1);

namespace ninjaknights\ui\sidebar;

use ninjaknights\ui\sidebar\host\SidebarHost;
use ninjaknights\ui\sidebar\protocol\ScoreboardPackets;
use ninjaknights\ui\sidebar\protocol\ScoreboardProtocol;
use pocketmine\player\Player;

/**
 * @internal A typed client for the sidebar host shared by every copy of nk-ui.
 *
 * The host is published in $GLOBALS under a key that contains no namespace, so
 * virion shading (which renames this library's namespace per plugin) leaves it alone
 * and all copies find the same host. Every value coming back is checked, because
 * the host may belong to another copy.
 */
final class SidebarBus
{
    public const GLOBAL_KEY = 'nk-ui/sidebar/v1';
    private const CALLS = ['claim', 'release', 'update', 'setPriority', 'get', 'owners', 'releaseWhere', 'clear'];

    /** @param array<string, \Closure(mixed...): mixed> $calls */
    private function __construct(private array $calls)
    {
    }

    /** The server-wide host, created by the first copy that asks. */
    public static function shared(): self
    {
        $bus = $GLOBALS[self::GLOBAL_KEY] ?? null;
        if ($bus === null) {
            $bus = (new SidebarHost(ScoreboardProtocol::detect()))->bus();
            $GLOBALS[self::GLOBAL_KEY] = $bus;
        }
        return self::fromArray($bus);
    }

    /** A host of its own, shared with nobody. For tests. */
    public static function isolated(?ScoreboardPackets $packets = null): self
    {
        return self::fromArray((new SidebarHost($packets ?? ScoreboardProtocol::detect()))->bus());
    }

    public static function fromArray(mixed $bus): self
    {
        if (!is_array($bus) || ($bus['protocol'] ?? null) !== SidebarHost::PROTOCOL) {
            throw new \UnexpectedValueException('$GLOBALS["' . self::GLOBAL_KEY . '"] does not hold an nk-ui sidebar host');
        }
        $calls = [];
        foreach (self::CALLS as $name) {
            $call = $bus[$name] ?? null;
            if (!$call instanceof \Closure) {
                throw new \UnexpectedValueException('The shared sidebar host has no "' . $name . '" operation');
            }
            $calls[$name] = $call;
        }
        return new self($calls);
    }

    public function claim(Player $player, string $owner, int $priority): int
    {
        return self::int($this->calls['claim']($player, $owner, $priority));
    }

    public function release(Player $player, string $owner, int $id): void
    {
        $this->calls['release']($player, $owner, $id);
    }

    /** @param list<string> $lines */
    public function update(Player $player, string $owner, int $id, string $title, array $lines, bool $visible): bool
    {
        return $this->calls['update']($player, $owner, $id, $title, $lines, $visible) === true;
    }

    public function setPriority(Player $player, string $owner, int $id, int $priority): bool
    {
        return $this->calls['setPriority']($player, $owner, $id, $priority) === true;
    }

    /** @return ?array{id: int, priority: int, title: string, lines: list<string>, visible: bool} */
    public function get(Player $player, string $owner): ?array
    {
        $state = $this->calls['get']($player, $owner);
        if ($state === null) {
            return null;
        }
        if (!is_array($state)) {
            throw new \UnexpectedValueException('Shared sidebar host returned an invalid claim');
        }
        $lines = $state['lines'] ?? null;
        if (!is_array($lines) || !array_is_list($lines)) {
            throw new \UnexpectedValueException('Shared sidebar host returned invalid lines');
        }
        return [
            'id' => self::int($state['id'] ?? null),
            'priority' => self::int($state['priority'] ?? null),
            'title' => self::string($state['title'] ?? null),
            'lines' => array_map(self::string(...), $lines),
            'visible' => ($state['visible'] ?? null) === true,
        ];
    }

    /** @return list<string> */
    public function owners(Player $player): array
    {
        $owners = $this->calls['owners']($player);
        if (!is_array($owners) || !array_is_list($owners)) {
            throw new \UnexpectedValueException('Shared sidebar host returned invalid owners');
        }
        return array_map(self::string(...), $owners);
    }

    /** @param \Closure(string): bool $matches */
    public function releaseWhere(\Closure $matches): void
    {
        $this->calls['releaseWhere']($matches);
    }

    public function clear(Player $player): void
    {
        $this->calls['clear']($player);
    }

    private static function int(mixed $value): int
    {
        if (!is_int($value)) {
            throw new \UnexpectedValueException('Shared sidebar host returned a non-integer');
        }
        return $value;
    }

    private static function string(mixed $value): string
    {
        if (!is_string($value)) {
            throw new \UnexpectedValueException('Shared sidebar host returned a non-string');
        }
        return $value;
    }
}
