<?php

declare(strict_types=1);

namespace ninjaknights\ui;

use ninjaknights\ui\bossbar\BarColor;
use ninjaknights\ui\form\CustomForm;
use ninjaknights\ui\form\ModalForm;
use ninjaknights\ui\form\SimpleForm;
use pocketmine\command\Command;
use pocketmine\command\CommandSender;
use pocketmine\player\Player;
use pocketmine\plugin\Plugin;
use pocketmine\plugin\PluginOwned;
use pocketmine\scheduler\ClosureTask;

/** /nkui status | demo | clear. The demo only uses enabled modules and only clears its own UI. */
final class NkUiCommand extends Command implements PluginOwned
{
    private const ALERT_TICKS = 60;
    private const USAGE = '/nkui [status|demo|clear]';

    public function __construct(private NkUi $plugin)
    {
        parent::__construct('nkui', 'nk-ui status and demo', self::USAGE);
        $this->setPermission('nkui.command');
    }

    public function getOwningPlugin(): Plugin
    {
        return $this->plugin;
    }

    /** @param array<int, string> $args */
    public function execute(CommandSender $sender, string $commandLabel, array $args): bool
    {
        if (!$this->testPermission($sender)) {
            return true;
        }
        $action = $args[0] ?? 'status';
        if ($action === 'status') {
            $sender->sendMessage('nk-ui ' . $this->plugin->getDescription()->getVersion() . ' modules: ' . NkUi::describe($this->plugin->modules()));
            if ($sender instanceof Player && $this->plugin->hasSidebars()) {
                $owner = $this->plugin->sidebars()->activeOwner($sender);
                $sender->sendMessage('Your sidebar owner: ' . ($owner ?? 'none'));
            }
            return true;
        }
        if ($action !== 'demo' && $action !== 'clear') {
            $sender->sendMessage('Usage: ' . self::USAGE);
            return true;
        }
        if (!$sender instanceof Player) {
            $sender->sendMessage('Use this command in-game.');
            return true;
        }
        if ($action === 'clear') {
            $this->clear($sender);
            $sender->sendMessage('Cleared the nk-ui demo.');
            return true;
        }
        $this->demo($sender);
        return true;
    }

    private function demo(Player $player): void
    {
        if ($this->plugin->hasSidebars()) {
            $this->plugin->sidebars()->claim($player, $this->plugin, 0)->show('nk-ui demo', ['Base sidebar', 'owner: nk-ui', 'priority: 0']);
        }
        if ($this->plugin->hasBossBars()) {
            $this->plugin->bossBars()->get($player, $this->plugin)->show('nk-ui boss bar', 0.75);
        }
        if (!$this->plugin->hasForms()) {
            $player->sendMessage('Forms module is off; showing sidebar and boss bar only.');
            return;
        }
        $form = new SimpleForm('nk-ui demo', 'Choose something to test.');
        $form->button('Modal form', static function (Player $p): void {
            $p->sendForm(new ModalForm('Modal', 'Does this form display correctly?', static function (Player $p, ?bool $answer): void {
                $p->sendMessage($answer === null ? 'Closed.' : ($answer ? 'Yes.' : 'No.'));
            }));
        });
        $form->button('Custom form', static function (Player $p): void {
            $p->sendForm((new CustomForm('Custom form', static function (Player $p, ?array $data): void {
                $p->sendMessage($data === null ? 'Closed.' : json_encode($data, JSON_THROW_ON_ERROR));
            }))->label('Every control')->input('Name')->toggle('Enabled')->dropdown('Choice', ['One', 'Two'])
                ->slider('Amount', 0, 10)->stepSlider('Size', ['Small', 'Large']));
        });
        if ($this->plugin->hasSidebars()) {
            $form->button('Sidebar priority', function (Player $p): void {
                $this->priorityDemo($p);
            });
        }
        if ($this->plugin->hasBossBars()) {
            $form->button('Boss bar color', function (Player $p): void {
                $bar = $this->plugin->bossBars()->get($p, $this->plugin);
                $bar->setStyle(BarColor::from(($bar->color()->value + 1) % count(BarColor::cases())));
            });
        }
        $form->button('Clear', function (Player $p): void {
            $this->clear($p);
        });
        $this->plugin->forms()->send($player, $form);
    }

    /** A priority-100 claim covers the base sidebar, then is released so the base one returns. */
    private function priorityDemo(Player $player): void
    {
        $alert = $this->plugin->sidebars()->claim($player, $this->plugin->getName() . '/alert', 100);
        $alert->show('Alert', ['Higher priority claim', 'owner: nk-ui/alert', 'Releasing in 3 seconds']);
        $this->plugin->getScheduler()->scheduleDelayedTask(new ClosureTask(static function () use ($alert): void {
            $alert->release();
        }), self::ALERT_TICKS);
    }

    private function clear(Player $player): void
    {
        if ($this->plugin->hasSidebars()) {
            $sidebars = $this->plugin->sidebars();
            // Base first: while the alert is on top, releasing the base changes nothing on screen.
            $sidebars->release($player, $this->plugin);
            $sidebars->release($player, $this->plugin->getName() . '/alert');
        }
        if ($this->plugin->hasBossBars()) {
            $this->plugin->bossBars()->remove($player, $this->plugin);
        }
    }
}
