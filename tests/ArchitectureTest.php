<?php

declare(strict_types=1);

namespace AxolotlBucketLabs\UI\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Guards the module boundaries and the project's independence. */
final class ArchitectureTest extends TestCase
{
    private const MODULES = ['form', 'sidebar', 'bossbar'];

    /** @return iterable<string, array{string}> */
    public static function modules(): iterable
    {
        foreach (self::MODULES as $module) {
            yield $module => [$module];
        }
    }

    #[DataProvider('modules')]
    public function testModuleUsesNothingOutsideItself(string $module): void
    {
        $files = self::phpFiles(dirname(__DIR__) . '/src/AxolotlBucketLabs/UI/' . $module);
        self::assertNotSame([], $files);
        foreach ($files as $file) {
            $code = (string) file_get_contents($file);
            preg_match_all('/AxolotlBucketLabs\\\\UI\\\\(\w+)/', $code, $matches);
            foreach ($matches[1] as $namespace) {
                self::assertSame($module, $namespace, basename($file) . ' reaches outside the ' . $module . ' module');
            }
        }
    }

    public function testNoAvaloxOrMissingLeftovers(): void
    {
        $root = dirname(__DIR__);
        $files = [...self::phpFiles($root . '/src'), ...self::phpFiles($root . '/tests'), $root . '/virion.yml', $root . '/composer.json', $root . '/README.md'];
        foreach ($files as $file) {
            if (realpath($file) === realpath(__FILE__)) {
                continue;
            }
            $text = (string) file_get_contents($file);
            self::assertDoesNotMatchRegularExpression('/avalox/i', $text, $file);
            self::assertDoesNotMatchRegularExpression('/\bMissing\\\\|missing\.admin|MissingPlugin/', $text, $file);
        }
    }

    public function testIsAVirionAndALibraryNotAPlugin(): void
    {
        $root = dirname(__DIR__);
        self::assertFileDoesNotExist($root . '/plugin.yml');
        $virion = yaml_parse_file($root . '/virion.yml');
        self::assertIsArray($virion);
        self::assertSame('AxolotlBucketLabs\UI', $virion['antigen'] ?? null);

        $composer = json_decode((string) file_get_contents($root . '/composer.json'), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($composer);
        self::assertSame('axolotlbucketlabs/ui-api', $composer['name'] ?? null);
        self::assertSame('library', $composer['type'] ?? null);
        $autoload = $composer['autoload'] ?? null;
        self::assertIsArray($autoload);
        self::assertSame(['AxolotlBucketLabs\UI\\' => 'src/AxolotlBucketLabs/UI/'], $autoload['psr-4'] ?? null, 'Composer and virion must agree on the namespace');
        self::assertDirectoryExists($root . '/src/AxolotlBucketLabs/UI', 'Virion sources live under src/<antigen path>');

        foreach (self::phpFiles($root . '/src') as $file) {
            $code = (string) file_get_contents($file);
            self::assertStringNotContainsString('PluginBase', $code, basename($file) . ' must not be a plugin');
            // Shading renames the namespace in code; a string spelling it out would break.
            foreach (token_get_all($code) as $token) {
                if (is_array($token) && $token[0] === T_CONSTANT_ENCAPSED_STRING) {
                    self::assertDoesNotMatchRegularExpression('/ninjaknights\\\\{1,2}ui/', $token[1], basename($file) . ' has the namespace in a string');
                }
            }
        }
    }

    /** @return list<string> */
    private static function phpFiles(string $directory): array
    {
        $files = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if ($file instanceof \SplFileInfo && $file->getExtension() === 'php') {
                $files[] = $file->getPathname();
            }
        }
        sort($files);
        return $files;
    }
}
