<?php

declare(strict_types=1);

namespace ninjaknights\ui\tests;

use ninjaknights\ui\ModuleConfig;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ModuleConfigTest extends TestCase
{
    public function testDefaultsToEverythingOn(): void
    {
        self::assertSame(['forms' => true, 'sidebar' => true, 'bossbar' => true], (new ModuleConfig())->toArray());
        self::assertSame(['forms' => true, 'sidebar' => true, 'bossbar' => true], ModuleConfig::fromArray([])->toArray());
    }

    public function testReadsAYamlSection(): void
    {
        $config = yaml_parse("ui:\n  forms: true\n  sidebar: false\n  bossbar: true\n");
        self::assertIsArray($config);
        $modules = ModuleConfig::fromArray($config['ui']);
        self::assertTrue($modules->forms);
        self::assertFalse($modules->sidebar);
        self::assertTrue($modules->bossBar);
    }

    /** @return iterable<string, array{mixed, string}> */
    public static function invalidConfigs(): iterable
    {
        yield 'string flag' => [['sidebar' => 'no'], 'nk-ui module "sidebar" must be true or false'];
        yield 'int flag' => [['forms' => 0], 'nk-ui module "forms" must be true or false'];
        yield 'typo' => [['side-bar' => false], 'Unknown nk-ui module "side-bar"'];
        yield 'not a map' => ['all', 'nk-ui modules must map'];
        yield 'missing section' => [null, 'nk-ui modules must map'];
    }

    #[DataProvider('invalidConfigs')]
    public function testRejectsInvalidSettings(mixed $config, string $message): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage($message);
        ModuleConfig::fromArray($config);
    }
}
