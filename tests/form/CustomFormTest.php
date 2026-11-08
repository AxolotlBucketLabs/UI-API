<?php

declare(strict_types=1);

namespace AxolotlBucketLabs\UI\Tests\form;

use AxolotlBucketLabs\UI\form\CustomForm;
use AxolotlBucketLabs\UI\Tests\support\CreatesFakeClients;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use pocketmine\form\FormValidationException;
use pocketmine\player\Player;

final class CustomFormTest extends TestCase
{
    use CreatesFakeClients;

    private static function form(): CustomForm
    {
        return (new CustomForm('Test', static function (): void {
        }))
            ->label('Label')
            ->input('Input')
            ->toggle('Toggle')
            ->dropdown('Dropdown', ['A', 'B'])
            ->slider('Slider', 0, 10, 2)
            ->stepSlider('Step', ['A', 'B']);
    }

    public function testAcceptsValidResponses(): void
    {
        $form = self::form();
        self::assertSame(6, $form->controlCount());
        $valid = [null, 'Hello', true, 1, 6.0, 0];
        self::assertSame($valid, $form->validate($valid));
        self::assertSame([null, '', false, 0, 10, 1], $form->validate([null, '', false, 0, 10, 1]));
        self::assertNull($form->validate(null));
    }

    /** @return iterable<string, array{mixed}> */
    public static function forgedResponses(): iterable
    {
        yield 'dropdown out of range' => [[null, '', true, 2, 6, 0]];
        yield 'dropdown negative' => [[null, '', true, -1, 6, 0]];
        yield 'toggle as string' => [[null, '', 'true', 0, 6, 0]];
        yield 'slider off step' => [[null, '', true, 0, 5, 0]];
        yield 'slider above max' => [[null, '', true, 0, 12, 0]];
        yield 'slider infinite' => [[null, '', true, 0, INF, 0]];
        yield 'slider NaN' => [[null, '', true, 0, NAN, 0]];
        yield 'label with value' => [['x', '', true, 0, 6, 0]];
        yield 'input as int' => [[null, 5, true, 0, 6, 0]];
        yield 'step slider out of range' => [[null, '', true, 0, 6, 2]];
        yield 'too short' => [[null, '', true]];
        yield 'too long' => [[null, '', true, 0, 6, 0, 0]];
        yield 'empty' => [[]];
        yield 'map instead of list' => [[1 => '', 2 => true, 3 => 0, 4 => 6, 5 => 0, 6 => null]];
        yield 'scalar' => ['data'];
    }

    #[DataProvider('forgedResponses')]
    public function testRejectsForgedResponses(mixed $data): void
    {
        $this->expectException(FormValidationException::class);
        self::form()->validate($data);
    }

    public function testHandlerGetsValidatedData(): void
    {
        $client = $this->fakeClient();
        $received = [];
        $form = (new CustomForm('Settings', static function (Player $p, ?array $data) use (&$received): void {
            $received[] = $data;
        }))->input('Name')->toggle('On');
        $form->handleResponse($client->player, ['Steve', true]);
        $form->handleResponse($client->player, null);
        self::assertSame([['Steve', true], null], $received);
    }

    public function testRejectsInvalidDefinitions(): void
    {
        $base = static fn(): CustomForm => new CustomForm('T', static function (): void {
        });
        $invalid = [
            'empty dropdown' => static fn() => $base()->dropdown('D', []),
            'default out of range' => static fn() => $base()->dropdown('D', ['A'], 1),
            'step slider default' => static fn() => $base()->stepSlider('S', ['A'], -1),
            'min >= max' => static fn() => $base()->slider('S', 5, 5),
            'zero step' => static fn() => $base()->slider('S', 0, 5, 0),
            'default below min' => static fn() => $base()->slider('S', 0, 5, 1, -1),
            'infinite' => static fn() => $base()->slider('S', 0, INF),
        ];
        foreach ($invalid as $name => $make) {
            try {
                $make();
                self::fail('Accepted ' . $name);
            } catch (\InvalidArgumentException) {
            }
        }
        $this->addToAssertionCount(1);
    }

    public function testJsonShape(): void
    {
        $json = (new CustomForm('Settings', static function (): void {
        }))->dropdown('Mode', ['A', 'B'], 1)->stepSlider('Size', ['S', 'L'])->jsonSerialize();
        self::assertSame([
            'type' => 'custom_form',
            'title' => 'Settings',
            'content' => [
                ['type' => 'dropdown', 'text' => 'Mode', 'options' => ['A', 'B'], 'default' => 1],
                ['type' => 'step_slider', 'text' => 'Size', 'steps' => ['S', 'L'], 'default' => 0],
            ],
        ], $json);
    }
}
