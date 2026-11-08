<?php

declare(strict_types=1);

namespace AxolotlBucketLabs\UIApiDemo;

use AxolotlBucketLabs\UI\bossbar\BarColor;
use AxolotlBucketLabs\UI\bossbar\BarOverlay;
use AxolotlBucketLabs\UI\bossbar\BossBarManager;
use AxolotlBucketLabs\UI\form\CustomForm;
use AxolotlBucketLabs\UI\form\ModalForm;
use AxolotlBucketLabs\UI\form\SimpleForm;
use AxolotlBucketLabs\UI\sidebar\SidebarManager;
use pocketmine\command\Command;
use pocketmine\command\CommandSender;
use pocketmine\player\Player;
use pocketmine\plugin\PluginBase;

final class Main extends PluginBase
{
    private ?SidebarManager $sidebars = null;
    private ?BossBarManager $bossBars = null;

    protected function onEnable(): void
    {
        $this->sidebars = new SidebarManager($this);
        $this->bossBars = new BossBarManager($this);
    }

    public function onCommand(CommandSender $sender, Command $command, string $label, array $args): bool
    {
        if ($command->getName() !== 'ui') {
            return false;
        }
        if (!$sender instanceof Player) {
            $sender->sendMessage('Run this command in game.');
            return true;
        }

        $sender->sendForm($this->createMenu());
        return true;
    }

    private function createMenu(): SimpleForm
    {
        return (new SimpleForm('UI-API Demo', 'Choose an example to open.'))
            ->button('Custom form', function (Player $player): void {
                $player->sendForm($this->createCustomForm());
            })
            ->button('Confirmation form', function (Player $player): void {
                $player->sendForm($this->createConfirmationForm());
            })
            ->button('Show sidebar', function (Player $player): void {
                $this->showSidebar($player);
                $player->sendMessage('The demo sidebar is now visible.');
            })
            ->button('Show boss bar', function (Player $player): void {
                $this->showBossBar($player);
                $player->sendMessage('The demo boss bar is now visible.');
            })
            ->button('Clear UI', function (Player $player): void {
                $this->clearUi($player);
                $player->sendMessage('The demo UI has been cleared.');
            });
    }

    private function createCustomForm(): CustomForm
    {
        return (new CustomForm('UI-API Demo', function (Player $player, ?array $values): void {
            if ($values === null) {
                $player->sendMessage('You closed the custom form.');
                return;
            }

            $name = isset($values[1]) && is_string($values[1]) ? trim($values[1]) : '';
            $enabled = ($values[2] ?? false) === true;
            $player->sendMessage('Hello ' . ($name === '' ? $player->getName() : $name) . '.');
            $player->sendMessage('The example toggle is ' . ($enabled ? 'enabled.' : 'disabled.'));
        }))
            ->label('This form demonstrates validated input and toggle values.')
            ->input('Name', 'Type a name')
            ->toggle('Enable the example', true);
    }

    private function createConfirmationForm(): ModalForm
    {
        return new ModalForm(
            'Clear the demo UI?',
            'This hides the sidebar and removes the boss bar for you.',
            function (Player $player, ?bool $confirmed): void {
                if ($confirmed === true) {
                    $this->clearUi($player);
                    $player->sendMessage('The demo UI has been cleared.');
                    return;
                }
                $player->sendMessage($confirmed === false ? 'The demo UI was kept.' : 'You closed the confirmation form.');
            },
            'Clear',
            'Keep'
        );
    }

    private function showSidebar(Player $player): void
    {
        $sidebar = $this->sidebars()->claim($player, $this, 0);
        $sidebar->show('UI-API Demo', [
            'Forms: ready',
            'Sidebar: ready',
            'Boss bar: ready',
        ]);
    }

    private function showBossBar(Player $player): void
    {
        $this->bossBars()->get($player, 'demo')->show(
            'UI-API Demo',
            0.75,
            BarColor::GREEN,
            BarOverlay::PROGRESS
        );
    }

    private function clearUi(Player $player): void
    {
        $this->sidebars()->clear($player);
        $this->bossBars()->remove($player, 'demo');
    }

    private function sidebars(): SidebarManager
    {
        return $this->sidebars ?? throw new \LogicException('Sidebar manager is not ready');
    }

    private function bossBars(): BossBarManager
    {
        return $this->bossBars ?? throw new \LogicException('Boss bar manager is not ready');
    }
}
