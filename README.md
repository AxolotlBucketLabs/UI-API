# UI-API

A virion and Composer library for Axolotl-PM 5 plugins. It provides forms, shared
sidebars and boss bars.

[![CI](https://github.com/AxolotlBucketLabs/UI-API/actions/workflows/ci.yml/badge.svg)](https://github.com/AxolotlBucketLabs/UI-API/actions/workflows/ci.yml)
[![Latest release](https://img.shields.io/github/v/release/AxolotlBucketLabs/UI-API)](https://github.com/AxolotlBucketLabs/UI-API/releases/latest)
[![License](https://img.shields.io/github/license/AxolotlBucketLabs/UI-API)](https://github.com/AxolotlBucketLabs/UI-API/blob/main/LICENSE)
[![Discord](https://img.shields.io/discord/1568295845374206052?label=Discord&logo=discord&logoColor=white&color=5865F2)](https://discord.gg/Pk4FrNvHnM)

## Features

- Forms with response validation for simple, modal and custom controls.
- Shared sidebars that coordinate claims from multiple shaded plugin copies.
- Named boss bars with progress, titles, colors and overlays.
- Axolotl-PM packet handling for the supported UI features.

## Requirements

| Requirement | Value |
| --- | --- |
| Server | Axolotl-PM API 5 |
| PHP | 8.4 or newer |
| Virion name | `UI-API` |
| Composer package | `axolotlbucketlabs/ui-api` |
| PHP namespace | `AxolotlBucketLabs\\UI` |

The CI workflow currently tests Axolotl-PM 5.51.0 on PHP 8.4. PHP 8.5 is allowed
by the Composer constraint but is not a release requirement until it is tested
separately. Bedrock client rendering is not covered by the automated tests.

## Installation

### As a virion with DevTools

The virion manifest uses the name `UI-API` and the antigen
`AxolotlBucketLabs\\UI`.

1. Put this repository's `virion.yml`, `LICENSE` and `src/` in
   `virions/UI-API/`.
2. Declare the virion in your plugin's `devtools.yml`.

   ```yaml
   virions:
     - name: UI-API
       version: ^0.1.0
   ```

3. Write your plugin against `AxolotlBucketLabs\\UI\\...`.
4. Use [AxolotlBucketLabs DevTools](https://github.com/AxolotlBucketLabs/DevTools)
   to build the consumer plugin. DevTools shades the virion into the plugin's
   private namespace.

### As a Composer library

The Composer package is hosted in this repository and is not on Packagist yet.

```json
{
    "repositories": [
        {
            "type": "vcs",
            "url": "https://github.com/AxolotlBucketLabs/UI-API"
        }
    ],
    "require": {
        "axolotlbucketlabs/ui-api": "^0.1"
    }
}
```

The server API is provided by Axolotl-PM at runtime. It is not bundled as a
Composer dependency.

See [`examples/UIApiDemo`](examples/UIApiDemo) for a small runnable plugin that
uses the forms, sidebar and boss bar APIs.

## Usage

Create the managers in your plugin's `onEnable()` method.

```php
use AxolotlBucketLabs\UI\bossbar\BossBarManager;
use AxolotlBucketLabs\UI\sidebar\SidebarManager;
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

A manager takes your plugin because Axolotl-PM registers event listeners for a
plugin. Each manager clears its UI when a player quits and cleans up its own
state when the plugin is disabled.

## Forms

Form classes implement the Axolotl-PM form contract. Send them with
`$player->sendForm()`.

```php
use AxolotlBucketLabs\UI\form\{ModalForm, SimpleForm};
use pocketmine\player\Player;

$player->sendForm((new SimpleForm('Games', 'Pick a game'))
    ->button('Paintball', fn(Player $p) => $this->joinPaintball($p))
    ->button('Parkour', fn(Player $p) => $this->joinParkour($p)));

$player->sendForm(new ModalForm(
    'Leave match?',
    'You will lose your progress.',
    static function (Player $p, ?bool $leave): void {
        if ($leave === true) {
            $p->sendMessage('Left the match');
        }
    },
    'Leave',
    'Stay'
));
```

Client responses are validated before handlers run. Invalid button indexes,
control values and slider values are rejected with
`FormValidationException`.

## Sidebar

```php
$lobby = $this->sidebars->claim($player, $this, 0);
$lobby->show('Lobby', ['Players: 12', '', 'Coins: 340']);

$match = $this->sidebars->claim($player, 'Arena', 10);
$match->show('Round 2', ['Red: 3', 'Blue: 1', 'Time: 2:41']);
$match->setLine(2, 'Time: 2:40');

$match->release();
```

The highest-priority claim is shown. Lower-priority claims remain stored and
return when the active claim is released. A claim can contain up to 15 lines.
Use owner names that are unique to your plugin.

All shaded copies share the same sidebar host through the
`$GLOBALS['ui-api/sidebar/v1']` protocol key. The key does not contain the PHP
namespace, so shading does not isolate the host.

## Boss bars

```php
use AxolotlBucketLabs\UI\bossbar\{BarColor, BarOverlay};

$timer = $this->bossBars->get($player, 'timer');
$timer->show('Round ends in 3:00', 1.0, BarColor::GREEN, BarOverlay::NOTCHED_10);
$timer->setProgress(0.5);
$timer->setTitle('Round ends in 1:30');
$timer->setStyle(BarColor::RED);
$timer->hide();

$this->bossBars->remove($player, 'timer');
```

Progress must be finite and between 0 and 1. Multiple bars can be shown for
one player. Bars are removed when the player quits or the plugin is disabled.

## Migration from nk-ui

This release uses a new package, virion name and PHP namespace.

Update the Composer dependency:

```json
"axolotlbucketlabs/ui-api": "^0.1"
```

Use `UI-API` in `devtools.yml` and update imports to
`AxolotlBucketLabs\\UI\\...`. The old `ninjaknights/nk-ui-pmmp` package and
`ninjaknights\\ui` namespace are not provided by this project.

## Development

Install the development dependencies and run the local checks.

```sh
composer update --no-interaction --no-progress --prefer-dist
composer analyse
composer test
```

The CI workflow runs Composer validation, PHP lint, PHPStan level 10 and PHPUnit
against Axolotl-PM 5.51.0 on PHP 8.4.

The optional smoke script accepts an Axolotl-PM PHAR and checks two shaded
consumer plugins on a local server.

```sh
php -d phar.readonly=0 scripts/smoke.php path/to/Axolotl-PM.phar
```

## License

[GPL-3.0-only](LICENSE).

The project was created by NinjaKnights and is maintained under
AxolotlBucketLabs.
