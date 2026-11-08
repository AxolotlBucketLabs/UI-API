<?php

declare(strict_types=1);

namespace AxolotlBucketLabs\UI\Tests\form;

use AxolotlBucketLabs\UI\form\FormImage;
use AxolotlBucketLabs\UI\form\ModalForm;
use AxolotlBucketLabs\UI\form\SimpleForm;
use AxolotlBucketLabs\UI\Tests\support\CreatesFakeClients;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use pocketmine\form\FormValidationException;
use pocketmine\player\Player;

final class SimpleFormTest extends TestCase
{
    use CreatesFakeClients;

    /** @return iterable<string, array{mixed}> */
    public static function forgedButtons(): iterable
    {
        yield 'negative' => [-1];
        yield 'past the end' => [2];
        yield 'numeric string' => ['0'];
        yield 'bool' => [true];
        yield 'float' => [0.0];
        yield 'array' => [[]];
    }

    #[DataProvider('forgedButtons')]
    public function testRejectsForgedButton(mixed $data): void
    {
        $form = (new SimpleForm('Test'))->button('One')->button('Two');
        $this->expectException(FormValidationException::class);
        $form->validate($data);
    }

    public function testButtonHandlerRunsBeforeFormHandler(): void
    {
        $client = $this->fakeClient();
        $calls = [];
        $form = new SimpleForm('Menu', 'Pick', static function (Player $p, ?int $choice) use (&$calls): void {
            $calls[] = 'form:' . var_export($choice, true);
        });
        $form->button('Play', static function (Player $p) use (&$calls): void {
            $calls[] = 'play';
        });
        $form->button('Settings');

        $form->handleResponse($client->player, 0);
        $form->handleResponse($client->player, 1);
        $form->handleResponse($client->player, null);

        self::assertSame(['play', 'form:0', 'form:1', 'form:NULL'], $calls);
    }

    public function testMenuWithoutFormHandlerIgnoresClose(): void
    {
        $client = $this->fakeClient();
        $clicked = 0;
        $form = (new SimpleForm('Menu'))->button('Go', static function () use (&$clicked): void {
            ++$clicked;
        });
        $form->handleResponse($client->player, null);
        $form->handleResponse($client->player, 0);
        self::assertSame(1, $clicked);
    }

    public function testForgedResponseNeverReachesHandlers(): void
    {
        $client = $this->fakeClient();
        $called = false;
        $form = (new SimpleForm('Menu', '', static function () use (&$called): void {
            $called = true;
        }))->button('Go', static function () use (&$called): void {
            $called = true;
        });
        try {
            $form->handleResponse($client->player, 5);
            self::fail('Accepted a forged button');
        } catch (FormValidationException) {
        }
        self::assertFalse($called);
    }

    public function testJsonIncludesImages(): void
    {
        $form = (new SimpleForm('Shop', 'Buy'))
            ->button('Plain')
            ->button('Path', null, FormImage::path('textures/items/diamond'))
            ->button('Url', null, FormImage::url('https://example.com/icon.png'));
        self::assertSame(3, $form->buttonCount());
        self::assertSame([
            'type' => 'form',
            'title' => 'Shop',
            'content' => 'Buy',
            'buttons' => [
                ['text' => 'Plain'],
                ['text' => 'Path', 'image' => ['type' => 'path', 'data' => 'textures/items/diamond']],
                ['text' => 'Url', 'image' => ['type' => 'url', 'data' => 'https://example.com/icon.png']],
            ],
        ], $form->jsonSerialize());
    }

    public function testImageValidation(): void
    {
        foreach ([static fn() => FormImage::url('textures/x'), static fn() => FormImage::path(' '), static fn() => FormImage::url('javascript:alert(1)')] as $make) {
            try {
                $make();
                self::fail('Accepted an invalid image');
            } catch (\InvalidArgumentException) {
            }
        }
        self::assertSame('url', FormImage::url('HTTPS://example.com/a.png')->type());
    }

    public function testModalForm(): void
    {
        $client = $this->fakeClient();
        $answers = [];
        $modal = new ModalForm('Confirm', 'Continue?', static function (Player $p, ?bool $answer) use (&$answers): void {
            $answers[] = $answer;
        }, 'Continue', 'Cancel');
        $modal->handleResponse($client->player, true);
        $modal->handleResponse($client->player, false);
        $modal->handleResponse($client->player, null);
        self::assertSame([true, false, null], $answers);
        foreach ([1, 0, 'true', []] as $bad) {
            try {
                $modal->validate($bad);
                self::fail('Accepted a forged modal response');
            } catch (FormValidationException) {
            }
        }
        self::assertSame(
            ['type' => 'modal', 'title' => 'Confirm', 'content' => 'Continue?', 'button1' => 'Continue', 'button2' => 'Cancel'],
            $modal->jsonSerialize()
        );
    }
}
