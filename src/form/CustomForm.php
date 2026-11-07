<?php

declare(strict_types=1);

namespace ninjaknights\ui\form;

use pocketmine\form\Form;
use pocketmine\form\FormValidationException;
use pocketmine\player\Player;

/**
 * A form of controls: label, input, toggle, dropdown, slider and step slider.
 *
 * The handler receives one value per control, in the order added (labels give null),
 * or null when the player closed the form. Every value is checked against its control
 * before the handler runs, so a forged response never reaches plugin code.
 */
final class CustomForm implements Form
{
    /** @var list<array<string, mixed>> */
    private array $controls = [];
    /** @var list<\Closure(mixed): bool> */
    private array $validators = [];

    /** @param \Closure(Player, ?list<mixed>): void $onResponse */
    public function __construct(private string $title, private \Closure $onResponse)
    {
    }

    public function label(string $text): self
    {
        return $this->add(['type' => 'label', 'text' => $text], static fn(mixed $v): bool => $v === null);
    }

    public function input(string $text, string $placeholder = '', string $default = ''): self
    {
        return $this->add(
            ['type' => 'input', 'text' => $text, 'placeholder' => $placeholder, 'default' => $default],
            static fn(mixed $v): bool => is_string($v)
        );
    }

    public function toggle(string $text, bool $default = false): self
    {
        return $this->add(
            ['type' => 'toggle', 'text' => $text, 'default' => $default],
            static fn(mixed $v): bool => is_bool($v)
        );
    }

    /** @param list<string> $options */
    public function dropdown(string $text, array $options, int $default = 0): self
    {
        return $this->choice('dropdown', 'options', $text, $options, $default);
    }

    /** @param list<string> $steps */
    public function stepSlider(string $text, array $steps, int $default = 0): self
    {
        return $this->choice('step_slider', 'steps', $text, $steps, $default);
    }

    public function slider(string $text, float $min, float $max, float $step = 1.0, ?float $default = null): self
    {
        $default ??= $min;
        foreach ([$min, $max, $step, $default] as $number) {
            if (!is_finite($number)) {
                throw new \InvalidArgumentException('Slider values must be finite');
            }
        }
        if ($min >= $max || $step <= 0.0 || $default < $min || $default > $max) {
            throw new \InvalidArgumentException('Invalid slider range');
        }
        return $this->add(
            ['type' => 'slider', 'text' => $text, 'min' => $min, 'max' => $max, 'step' => $step, 'default' => $default],
            static function (mixed $v) use ($min, $max, $step): bool {
                if (!is_int($v) && !is_float($v)) {
                    return false;
                }
                $value = (float) $v;
                if (!is_finite($value) || $value < $min || $value > $max) {
                    return false;
                }
                $steps = ($value - $min) / $step;
                return abs($steps - round($steps)) < 0.0001;
            }
        );
    }

    public function controlCount(): int
    {
        return count($this->controls);
    }

    /** @return ?list<mixed> */
    public function validate(mixed $data): ?array
    {
        if ($data === null) {
            return null;
        }
        if (!is_array($data) || !array_is_list($data) || count($data) !== count($this->controls)) {
            throw new FormValidationException('Invalid custom form response length');
        }
        foreach ($this->validators as $i => $validator) {
            if (!$validator($data[$i])) {
                throw new FormValidationException('Invalid response for control ' . $i);
            }
        }
        return $data;
    }

    public function handleResponse(Player $player, mixed $data): void
    {
        ($this->onResponse)($player, $this->validate($data));
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return ['type' => 'custom_form', 'title' => $this->title, 'content' => $this->controls];
    }

    /**
     * @param array<string, mixed> $control
     * @param \Closure(mixed): bool $validator
     */
    private function add(array $control, \Closure $validator): self
    {
        $this->controls[] = $control;
        $this->validators[] = $validator;
        return $this;
    }

    /** @param list<string> $options */
    private function choice(string $type, string $key, string $text, array $options, int $default): self
    {
        if ($options === [] || !array_is_list($options) || !isset($options[$default])) {
            throw new \InvalidArgumentException('Choices must be a non-empty list containing the default index');
        }
        $count = count($options);
        return $this->add(
            ['type' => $type, 'text' => $text, $key => $options, 'default' => $default],
            static fn(mixed $v): bool => is_int($v) && $v >= 0 && $v < $count
        );
    }
}
