<?php

declare(strict_types=1);

namespace ninjaknights\ui\tests;

use ninjaknights\ui\ModuleConfig;
use ninjaknights\ui\ModuleDisabledException;
use ninjaknights\ui\NkUi;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ModuleConfigTest extends TestCase
{
    public function testDefaultsToEverythingOn(): void
    {
        self::assertSame(['forms' => true, 'sidebar' => true, 'bossbar' => true], ModuleConfig::fromArray([])->toArray());
        self::assertSame(['forms' => true, 'sidebar' => true, 'bossbar' => true], ModuleConfig::fromArray(['modules' => []])->toArray());
    }

    public function testShippedConfigEnablesEveryModule(): void
    {
        $config = yaml_parse_file(dirname(__DIR__) . '/resources/config.yml');
        self::assertIsArray($config);
        self::assertSame(['forms' => true, 'sidebar' => true, 'bossbar' => true], ModuleConfig::fromArray($config)->toArray());
    }

    public function testEachModuleTogglesIndependently(): void
    {
        $config = ModuleConfig::fromArray(['modules' => ['forms' => true, 'sidebar' => false, 'bossbar' => true]]);
        self::assertTrue($config->forms);
        self::assertFalse($config->sidebar);
        self::assertTrue($config->bossBar);
        self::assertSame('forms on, sidebar off, bossbar on', NkUi::describe($config->toArray()));
    }

    /** @return iterable<string, array{array<mixed>, string}> */
    public static function invalidConfigs(): iterable
    {
        yield 'string flag' => [['modules' => ['sidebar' => 'no']], 'modules.sidebar must be true or false'];
        yield 'int flag' => [['modules' => ['forms' => 0]], 'modules.forms must be true or false'];
        yield 'typo' => [['modules' => ['side-bar' => false]], 'Unknown module "side-bar"'];
        yield 'not a map' => [['modules' => 'all'], '"modules" must map'];
    }

    /** @param array<mixed> $config */
    #[DataProvider('invalidConfigs')]
    public function testRejectsInvalidSettings(array $config, string $message): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage($message);
        ModuleConfig::fromArray($config);
    }

    public function testDisabledModuleMessageNamesTheSetting(): void
    {
        self::assertStringContainsString('sidebar module is disabled', ModuleDisabledException::for('sidebar')->getMessage());
    }
}
