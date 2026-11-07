<?php

declare(strict_types=1);

namespace ninjaknights\ui\tests;

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
        $files = self::phpFiles(dirname(__DIR__) . '/src/' . $module);
        self::assertNotSame([], $files);
        foreach ($files as $file) {
            $code = (string) file_get_contents($file);
            preg_match_all('/ninjaknights\\\\ui\\\\(\w+)/', $code, $matches);
            foreach ($matches[1] as $namespace) {
                self::assertSame($module, $namespace, basename($file) . ' reaches outside the ' . $module . ' module');
            }
        }
    }

    public function testNoAvaloxOrMissingLeftovers(): void
    {
        $root = dirname(__DIR__);
        $files = [...self::phpFiles($root . '/src'), ...self::phpFiles($root . '/tests'), $root . '/plugin.yml', $root . '/resources/config.yml', $root . '/composer.json', $root . '/README.md'];
        foreach ($files as $file) {
            if (realpath($file) === realpath(__FILE__)) {
                continue;
            }
            $text = (string) file_get_contents($file);
            self::assertDoesNotMatchRegularExpression('/avalox/i', $text, $file);
            self::assertDoesNotMatchRegularExpression('/\bMissing\\\\|missing\.admin|MissingPlugin/', $text, $file);
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
