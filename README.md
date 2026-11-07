# nk-ui

A virion and Composer library for [PocketMine-MP 5](https://github.com/pmmp/PocketMine-MP) and
[Axolotl-PM](https://github.com/axolotl-pm/PocketMine-MP) plugins: forms, shared sidebars and boss bars.
It is not a plugin; you bundle it into yours.

- **Forms**: `SimpleForm` (with an optional handler per button), `ModalForm` and `CustomForm`.
  Every client response is validated before your code runs, so forged responses never reach it.
- **Sidebar**: plugins *claim* a player's sidebar with an owner and a priority. The highest
  priority is shown; lower claims wait underneath and come back when it is released. This works
  across plugins even though each one bundles its own copy of nk-ui.
- **Boss bars**: any number of named bars per player, with progress, title, color and overlay.

Each module is self-contained (no module uses another). There is nothing to configure or switch
on: use the classes you need, and the rest stays idle.

| Server | Version | Unit tests + PHPStan | Server boot smoke test | Bedrock client |
| --- | --- | --- | --- | --- |
| Axolotl-PM | 5.51.0 | yes | yes | not yet tested |
| PocketMine-MP | 5.44.3 | yes | yes | not yet tested |

With DevTools 3.0.0 on Axolotl-PM 5.51.0, three things were checked: loading from `virions/`,
`/devtools build`, and two built plugins sharing one sidebar from their private copies.

Axolotl-PM and upstream PocketMine-MP encode score entries and boss bar colors differently.
nk-ui detects the running server and uses the right packet format, so the same code works on both.

## Install

**As a virion with [DevTools](https://github.com/AxolotlBucketLabs/DevTools).** `virion.yml` declares
the name `nk-ui` and the antigen `ninjaknights\ui`.

1. Put this repository's `virion.yml`, `LICENSE` and `src/` in your server's `virions/nk-ui/`.
2. Declare it in your plugin's `devtools.yml`:

   ```yaml
   virions:
     - name: nk-ui
       version: ^0.1.0
   ```

3. Write your plugin against `ninjaknights\ui\...`. While developing, DevTools loads one shared copy
   of nk-ui for all folder plugins.
4. `/devtools build MyPlugin` shades nk-ui into `build/MyPlugin.phar` under a private namespace, so
   every plugin carries its own copy and versions never clash.

**As a Composer library**, for IDE completion, static analysis and Composer-based builds. It is not
on Packagist yet, so add the repository:

```json
{
    "repositories": [{ "type": "vcs", "url": "https://github.com/ninjaknights/nk-ui-pmmp" }],
    "require": { "ninjaknights/nk-ui-pmmp": "dev-main" }
}
```

The server (PocketMine-MP or Axolotl-PM) is not a Composer requirement; it is provided at runtime.

## Usage

Forms need nothing: create them and send them. For sidebars and boss bars, create a manager in
your plugin's `onEnable()`:

```php
use ninjaknights\ui\bossbar\BossBarManager;
use ninjaknights\ui\sidebar\SidebarManager;
use pocketmine\plugin\PluginBase;

final class Main extends PluginBase
{
    private SidebarManager $sidebars;
    private BossBarManager $bossBars;

    protected function onEnable(): void
    {
        $this->sidebars = new SidebarManager($this);
        $this->bossBars = new BossBarManager($this);
    }
}
```

A manager takes your plugin because PocketMine-MP only registers event listeners for a plugin.
Each manager registers one listener for it: it clears a player's UI when they quit and, when your
plugin is disabled, releases your sidebar claims or hides your boss bars. The boss bar listener
also answers the client's boss bar queries. Create the managers once your plugin is enabled.

## Forms

The form classes are ordinary `pocketmine\form\Form` objects: send them with `$player->sendForm()`.

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

## Sidebar

```php
// A lobby plugin shows its sidebar at priority 0 ($this is the plugin, so the owner is its name).
$lobby = $this->sidebars->claim($player, $this, 0);
$lobby->show('Lobby', ['Players: 12', '', 'Coins: 340']);

// Meanwhile an arena plugin, with its own SidebarManager ($arenaSidebars), claims at a
// higher priority and covers the lobby sidebar.
$match = $arenaSidebars->claim($player, 'Arena', 10);
$match->show('Round 2', ['Red: 3', 'Blue: 1', 'Time: 2:41']);
$match->setLine(2, 'Time: 2:40');   // only the changed line is sent

// The lobby can keep updating; its changes appear once it is on top again.
$lobby->setLine(0, 'Players: 13');

// The match ends: the lobby sidebar (with 'Players: 13') comes back.
$match->release();
```

- **Owner**: a `Plugin` (its name is used) or any non-empty string. Use names unique to your
  plugin, such as `MyPlugin` and `MyPlugin/alert` for two layers.
- **Priority**: higher wins. On a tie the earlier claim keeps the sidebar, so a later plugin cannot
  take it by claiming at the same priority.
- `claim()` on an owner that already has a claim returns that claim and updates its priority.
- `isActive()` tells you whether your claim is the one on screen; `activeOwner($player)` tells you
  whose is, whichever plugin it belongs to.
- `hide()` blanks the sidebar but keeps the claim, so lower claims stay hidden. Use `release()` to
  hand the sidebar back.
- Claims are released when the player quits, and every claim your plugin made is released when
  your plugin is disabled. A released claim ignores further calls; claim again for a new one.
- Up to 15 lines. Blank and repeated lines are fine. Bedrock shows score numbers next to sidebar
  lines; nk-ui numbers them 1, 2, 3… from the top.

### How sharing works between shaded copies

Shading gives every plugin its own copy of nk-ui's classes, so copies cannot share static state.
The first copy to start creates the sidebar host and publishes its operations as plain closures in
`$GLOBALS['nk-ui/sidebar/v1']`. The key contains no namespace, so shading leaves it unchanged and
every copy finds the same host. The closures only take and return PHP and PocketMine types, so a
copy can call a host that lives in another plugin's namespace. The `v1` in the key is the protocol
version; an incompatible change to it would use a new key.

## Boss bars

```php
use ninjaknights\ui\bossbar\{BarColor, BarOverlay};

$timer = $this->bossBars->get($player, 'timer');
$timer->show('Round ends in 3:00', 1.0, BarColor::GREEN, BarOverlay::NOTCHED_10);
$timer->setProgress(0.5);           // 0.0 to 1.0
$timer->setTitle('Round ends in 1:30');
$timer->setStyle(BarColor::RED);    // overlay unchanged
$timer->hide();                      // keep the bar for later show()

$this->bossBars->remove($player, 'timer');
```

Keys are your plugin's own: two plugins can both use `timer` without clashing. Several bars can be
shown at once. Bars are removed when the player quits or your plugin is disabled, and nk-ui
answers the client's boss bar queries for you. Progress must be finite and between 0 and 1.

## Layout

`src/ninjaknights/ui/form`, `sidebar` and `bossbar` are the modules; none uses another. Each packet
format difference between the two servers lives in that module's `protocol/` folder. Requires PHP
8.1 or newer.

## Known limits

- Nothing has been checked on a Bedrock client yet: rendering of sidebars, boss bars and forms is
  covered by packet-level tests only.
- Boss bars are sent without a backing entity. If a client turns out to need one, bars may not
  appear; this is the first thing to check in a client test.
- Sidebar owner names are not enforced per plugin; two plugins using the same owner string share
  one claim.

## License

[GPL-3.0-only](LICENSE)
