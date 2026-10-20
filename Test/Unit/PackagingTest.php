<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\CatalogEnrich\Test\Unit;

use FilesystemIterator;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

class PackagingTest extends TestCase
{
    private const HEADER = ' * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.';

    private const REQUIRED = [
        'php',
        'dmlab/module-typesafe-core',
        'magento/framework',
        'magento/framework-message-queue',
        'magento/module-backend',
        'magento/module-catalog',
        'magento/module-config',
        'magento/module-eav',
        'magento/module-indexer',
        'magento/module-mysql-mq',
        'magento/module-store',
        'magento/module-ui',
        'psr/log',
        'symfony/console',
    ];

    public function testComposerRequiresExactlyTheDeclaredPackages(): void
    {
        $composer = json_decode((string)file_get_contents($this->root() . '/composer.json'), true);
        self::assertIsArray($composer);
        self::assertSame('dmlab/module-catalog-enrich', $composer['name']);
        self::assertArrayHasKey('version', $composer);
        self::assertNotSame('', (string)$composer['version']);

        $require = $composer['require'];
        self::assertEqualsCanonicalizing(self::REQUIRED, array_keys($require));
        foreach ($require as $package => $constraint) {
            $prefix = $package === 'php' ? '~' : '^';
            self::assertStringStartsWith($prefix, $constraint, $package);
        }
    }

    public function testModuleXmlHasNoSetupVersion(): void
    {
        $xml = simplexml_load_file($this->root() . '/etc/module.xml');
        self::assertNotFalse($xml);
        self::assertFalse(isset($xml->module['setup_version']));
    }

    public function testEveryPhpAndXmlFileHasTheOslHeader(): void
    {
        $files = $this->sourceFiles();
        self::assertNotEmpty($files);
        foreach ($files as $file) {
            self::assertStringContainsString(self::HEADER, (string)file_get_contents($file), $file);
        }
    }

    public function testTranslationFileHasTwoColumnsPerLine(): void
    {
        $handle = fopen($this->root() . '/i18n/en_US.csv', 'r');
        self::assertNotFalse($handle);
        $lines = 0;
        while (($row = fgetcsv($handle, null, ',', '"', '')) !== false) {
            $lines++;
            self::assertCount(2, $row, 'line ' . $lines);
            self::assertSame($row[0], $row[1], 'line ' . $lines);
        }
        fclose($handle);
        self::assertGreaterThan(0, $lines);
    }

    public function testEveryTranslatablePhraseIsInTheTranslationFile(): void
    {
        $phrases = [...$this->xmlPhrases(), ...$this->phpPhrases()];
        self::assertContains('Ask again on next run', $phrases);
        self::assertContains('Include Products Not Visible Individually', $phrases);
        self::assertContains('There is no active enrichment rule for attribute "%1".', $phrases);

        $missing = array_values(array_unique(array_diff($phrases, $this->translationKeys())));
        sort($missing);
        self::assertSame([], $missing);
    }

    public function testGitattributesExcludesTestsAndGithubFromArchives(): void
    {
        $lines = file($this->root() . '/.gitattributes', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        self::assertIsArray($lines);
        self::assertContains('/Test export-ignore', $lines);
        self::assertContains('/.github export-ignore', $lines);
    }

    /**
     * @return string[]
     */
    private function translationKeys(): array
    {
        $handle = fopen($this->root() . '/i18n/en_US.csv', 'r');
        self::assertNotFalse($handle);
        $keys = [];
        while (($row = fgetcsv($handle, null, ',', '"', '')) !== false) {
            $keys[] = (string)$row[0];
        }
        fclose($handle);

        return $keys;
    }

    /**
     * Texts of `translate="true"` elements, the attributes or children a `translate="a b"` list names,
     * and the module's ACL titles.
     *
     * @return string[]
     */
    private function xmlPhrases(): array
    {
        $phrases = [];
        foreach ($this->sourceFiles() as $file) {
            if (!str_ends_with($file, '.xml') || str_contains($file, '/Test/')) {
                continue;
            }
            $xml = simplexml_load_file($file);
            self::assertNotFalse($xml, $file);
            foreach ($xml->xpath('//*[@translate]') ?: [] as $node) {
                $translate = trim((string)$node['translate']);
                if ($translate === 'true') {
                    $phrases[] = trim((string)$node);
                    continue;
                }
                foreach (preg_split('/\s+/', $translate) ?: [] as $name) {
                    $phrases[] = trim(isset($node[$name]) ? (string)$node[$name] : (string)$node->{$name});
                }
            }
            foreach ($xml->xpath('//resource[starts-with(@id, "DmLab_")][@title]') ?: [] as $node) {
                $phrases[] = (string)$node['title'];
            }
        }

        return array_values(array_filter($phrases, static fn (string $phrase): bool => $phrase !== ''));
    }

    /**
     * Literal first arguments of `__()` in PHP and templates, single-quoted pieces joined by `.`.
     *
     * @return string[]
     */
    private function phpPhrases(): array
    {
        $literal = "'(?:[^'\\\\]|\\\\.)*'";
        $pattern = '/\b__\(\s*(' . $literal . '(?:\s*\.\s*' . $literal . ')*)\s*[,)]/';
        $phrases = [];
        foreach ($this->sourceFiles() as $file) {
            if (!preg_match('/\.(php|phtml)$/', $file) || str_contains($file, '/Test/')) {
                continue;
            }
            preg_match_all($pattern, (string)file_get_contents($file), $matches);
            foreach ($matches[1] as $expression) {
                preg_match_all('/' . $literal . '/', $expression, $parts);
                $phrases[] = implode('', array_map(
                    static fn (string $part): string => stripslashes(substr($part, 1, -1)),
                    $parts[0]
                ));
            }
        }

        return $phrases;
    }

    private function root(): string
    {
        return dirname(__DIR__, 2);
    }

    /**
     * @return string[]
     */
    private function sourceFiles(): array
    {
        $root = $this->root();
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)
        );
        $files = [];
        foreach ($iterator as $file) {
            $relative = substr($file->getPathname(), strlen($root) + 1);
            if (preg_match('#^(\.[^/]+|vendor)/#', $relative)
                || !preg_match('/\.(php|xml|phtml|js)$/', $relative)
            ) {
                continue;
            }
            $files[] = $file->getPathname();
        }

        return $files;
    }
}
