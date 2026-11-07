# nk-ui

[![CI](https://github.com/ninjaknights/nk-ui-pmmp/actions/workflows/ci.yml/badge.svg)](https://github.com/ninjaknights/nk-ui-pmmp/actions/workflows/ci.yml)

Forms, shared sidebars and boss bars for [PocketMine-MP 5](https://github.com/pmmp/PocketMine-MP)
and [Axolotl-PM](https://github.com/axolotl-pm/PocketMine-MP) plugins.

- **Forms**: `SimpleForm` (with an optional handler per button), `ModalForm` and `CustomForm`.
  Every client response is validated before your code runs, so forged responses never reach it.
- **Sidebar**: plugins *claim* a player's sidebar with an owner and a priority. The highest
  priority is shown; lower claims wait underneath and come back when it is released. Two
  plugins can no longer overwrite each other's scoreboard.
- **Boss bars**: any number of named bars per player, with progress, title, color and overlay.

Each module is self-contained (no module uses another) and can be switched off in `config.yml`.

| Server | Version | Unit tests + PHPStan | Server boot smoke test | Bedrock client |
| --- | --- | --- | --- | --- |
| Axolotl-PM | 5.51.0 | yes | yes | not yet tested |
| PocketMine-MP | 5.44.3 | yes | yes | not yet tested |

Axolotl-PM and upstream PocketMine-MP encode score entries and boss bar colors differently.
nk-ui detects the running server and uses the right packet format, so one `nk-ui.phar` works on both.

## Install

1. Download `nk-ui.phar` from the latest [CI run](https://github.com/ninjaknights/nk-ui-pmmp/actions/workflows/ci.yml)
   (artifact **nk-ui**), or build it yourself (see [Development](#development)).
2. Put it in your server's `plugins/` folder and restart.
3. In each plugin that uses it, add the dependency to `plugin.yml`:

```yaml
depend: [nk-ui]
```

Use nk-ui as a plugin, not as copied-in (shaded) source: sidebar ownership only works when all
plugins share the single nk-ui instance.

For IDE completion and static analysis in your own plugin, add this repository as a Composer dev
dependency (it is not on Packagist yet):

```json
{
    "repositories": [{ "type": "vcs", "url": "https://github.com/ninjaknights/nk-ui-pmmp" }],
    "require-dev": { "ninjaknights/nk-ui-pmmp": "dev-main" }
}
```

## Configuration

`plugin_data/nk-ui/config.yml`:

```yaml
modules:
  forms: true
  sidebar: true
  bossbar: true
```

Restart after editing. A disabled module registers nothing, and asking nk-ui for it throws
`ninjaknights\ui\ModuleDisabledException`. A missing entry counts as `true`. An invalid value
(for example `sidebar: maybe` or a misspelt module) is logged with the setting's name, and
PocketMine-MP then stops startup, as it does whenever a plugin fails to enable.

## Usage

Get the plugin once, for example in `onEnable()`:

```php
use ninjaknights\ui\NkUi;

$ui = $this->getServer()->getPluginManager()->getPlugin('nk-ui');
if (!$ui instanceof NkUi || !$ui->isEnabled()) {
    throw new \RuntimeException('nk-ui is required');
}
```

`$ui->hasForms()`, `hasSidebars()` and `hasBossBars()` tell you which modules are on.

### Forms

The form classes are ordinary `pocketmine\form\Form` objects: send them with `$player->sendForm()`,
or with `$ui->forms()->send($player, $form)`, which skips players who have disconnected (useful in
delayed tasks).

```php
use ninjaknights\ui\form\{CustomForm, FormImage, ModalForm, SimpleForm};
use pocketmine\player\Player;

// A menu: each button has its own handler. Closing it does nothing.
$player->sendForm((new SimpleForm('Games', 'Pick a game'))
    ->button('Paintball', fn(Player $p) => $this->joinPaintball($p), FormImage::path('textures/items/snowball'))
    ->button('Parkour', fn(Player $p) => $this->joinParkour($p))
    ->button('Website', null, FormImage::url('https://example.com/icon.png')));

// Or one handler for the whole form: the button index, or null when closed.
$player->sendForm((new SimpleForm('Menu', 'Choose one', static function (Player $p, ?int $choice): void {
    $p->sendMessage($choice === null ? 'Closed' : 'Picked button ' . $choice);
}))->button('Play')->button('Settings'));

// Yes / no. The answer is true, false, or null when closed.
$player->sendForm(new ModalForm('Leave match?', 'You will lose your progress.',
    static function (Player $p, ?bool $leave): void {
        if ($leave === true) {
            $p->sendMessage('Left the match');
        }
    }, 'Leave', 'Stay'));

// Controls. $data holds one value per control in order (labels give null), or is null when closed.
$player->sendForm((new CustomForm('Settings', static function (Player $p, ?array $data): void {
    if ($data === null) {
        return;
    }
    [, $name, $music, $mode, $volume, $size] = $data;
    $p->sendMessage("$name: music " . ($music ? 'on' : 'off') . ", mode $mode, volume $volume, size $size");
}))
    ->label('Your preferences')
    ->input('Nickname', 'Steve')
    ->toggle('Music', true)
    ->dropdown('Mode', ['Solo', 'Duo', 'Squad'])
    ->slider('Volume', 0, 100, 5, 50)
    ->stepSlider('Size', ['Small', 'Medium', 'Large'], 1));
```

Responses are checked before handlers run: button and option indexes must exist, toggles must be
booleans, inputs strings, sliders finite, in range and on a step. Anything else throws
`FormValidationException`, which PocketMine-MP handles by discarding the response.

### Sidebar

```php
$sidebars = $ui->sidebars();

// A lobby plugin shows its sidebar at priority 0 ($this is the plugin, so the owner is its name).
$lobby = $sidebars->claim($player, $this, 0);
$lobby->show('Lobby', ['Players: 12', '', 'Coins: 340']);

// A match plugin, named "Arena", claims at a higher priority and covers the lobby sidebar.
$match = $sidebars->claim($player, 'Arena', 10);
$match->show('Round 2', ['Red: 3', 'Blue: 1', 'Time: 2:41']);
$match->setLine(2, 'Time: 2:40');   // only the changed line is sent

// The lobby can keep updating; its changes appear once it is on top again.
$lobby->setLine(0, 'Players: 13');

// The match ends: the lobby sidebar (with 'Players: 13') comes back.
$match->release();
```

- **Owner**: a `Plugin` or a string. Use the plugin name, or `PluginName/suffix` when one plugin
  needs several layers (for example `Arena/alert` above `Arena`).
- **Priority**: higher wins. On a tie the earlier claim keeps the sidebar, so a later plugin cannot
  take it by claiming at the same priority.
- `claim()` on an owner that already has a claim returns that claim and updates its priority.
- `isActive()` tells you whether your claim is the one on screen; `$sidebars->activeOwner($player)`
  tells you whose is.
- `hide()` blanks the sidebar but keeps the claim, so lower claims stay hidden. Use `release()` to
  hand the sidebar back.
- Claims are released automatically when the player quits, and when the owning plugin is disabled
  (the plugin's name and every `PluginName/...` owner). A released claim ignores further calls;
  claim again for a new one.
- Up to 15 lines. Blank and repeated lines are fine. Bedrock shows score numbers next to sidebar
  lines; nk-ui numbers them 1, 2, 3… from the top.

### Boss bars

```php
use ninjaknights\ui\bossbar\{BarColor, BarOverlay};

$bossBars = $ui->bossBars();

$timer = $bossBars->get($player, 'Arena/timer');
$timer->show('Round ends in 3:00', 1.0, BarColor::GREEN, BarOverlay::NOTCHED_10);
$timer->setProgress(0.5);           // 0.0 to 1.0
$timer->setTitle('Round ends in 1:30');
$timer->setStyle(BarColor::RED);    // overlay unchanged
$timer->hide();                      // keep the bar for later show()

$bossBars->remove($player, 'Arena/timer');
```

Keys follow the same rule as sidebar owners: a `Plugin`, its name, or `PluginName/suffix`. Several
bars can be shown at once. Bars are removed when the player quits or the owning plugin is
disabled, and nk-ui answers the client's boss bar queries for you. Progress must be finite and
between 0 and 1.

## Command

`/nkui [status|demo|clear]`, permission `nkui.command` (operators by default).

- `status` lists the enabled modules and, in game, who owns your sidebar.
- `demo` shows a sidebar and a boss bar, then a form for trying every form type, a priority-100
  sidebar claim that releases itself after 3 seconds, and boss bar colors.
- `clear` removes the demo's own sidebar claims and boss bar. Other plugins' UI is left alone.

## Development

Requires PHP 8.1+ with the PocketMine-MP extensions (the `encoding` extension is needed to encode
packets in tests).

```sh
composer install --ignore-platform-req=ext-webrtc   # Axolotl-PM declares ext-webrtc; nk-ui does not use it
composer check     # PHPStan (level 10, strict rules) and PHPUnit, against Axolotl-PM 5.51.0
composer build     # out/nk-ui.phar
php -d phar.readonly=0 scripts/smoke.php path/to/PocketMine-MP.phar
```

The smoke test boots a real server in a temporary folder with the built plugin and a small plugin
that depends on it, and checks module toggles, cross-plugin access, the console command and the
invalid-config path for each module setting. It does not involve a Bedrock client.

To check against upstream PocketMine-MP instead of Axolotl-PM:

```sh
composer remove --dev --no-update axolotl-pm/pocketmine-mp
composer require --dev --no-update pocketmine/pocketmine-mp:5.44.3
composer update
vendor/bin/phpstan analyse -c phpstan.pocketmine.neon
vendor/bin/phpunit
```

CI runs all of this on both servers for every push and pull request.

Layout: `src/form`, `src/sidebar` and `src/bossbar` are the modules; each packet format difference
between the two servers lives in that module's `protocol/` folder. `src/NkUi.php` wires the enabled
modules into the plugin. `tests/ArchitectureTest.php` fails if a module starts using another.

## Known limits

- Nothing has been checked on a Bedrock client yet: rendering of sidebars, boss bars and forms is
  covered by packet-level tests only.
- Boss bars are sent without a backing entity. If a client turns out to need one, bars may not
  appear; this is the first thing to check in a client test.

## License

[GPL-3.0-only](LICENSE)
