<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\CatalogEnrich\Test\Unit;

use PHPUnit\Framework\TestCase;

class DbSchemaTest extends TestCase
{
    private const REFERENCE_IDS = [
        'dmlab_catalog_enrich_rule' => ['PRIMARY', 'DMLAB_CATALOG_ENRICH_RULE_ATTRIBUTE_CODE'],
        'dmlab_catalog_enrich_attempt' => [
            'PRIMARY',
            'DMLAB_CATALOG_ENRICH_ATTEMPT_PRODUCT_ID_ATTRIBUTE_CODE',
            'DMLAB_CATALOG_ENRICH_ATTEMPT_OUTCOME_NEXT_ATTEMPT_AT',
            'DMLAB_CATALOG_ENRICH_ATTEMPT_ATTRIBUTE_CODE_OUTCOME',
            'DMLAB_CATALOG_ENRICH_ATTEMPT_PRD_ID_CAT_PRD_ENTT_ENTT_ID',
        ],
        'dmlab_catalog_enrich_suggestion' => [
            'PRIMARY',
            'DMLAB_CATALOG_ENRICH_SUGGESTION_STATUS',
            'DMLAB_CATALOG_ENRICH_SUGG_PRD_ID_ATTR_CODE_FINGERPRINT',
            'DMLAB_CATALOG_ENRICH_SUGG_PRD_ID_CAT_PRD_ENTT_ENTT_ID',
        ],
        'dmlab_catalog_enrich_deferred' => [
            'PRIMARY',
            'DMLAB_CATALOG_ENRICH_DEFERRED_DEFERRED_AT',
            'DMLAB_CATALOG_ENRICH_DEFERRED_PRD_ID_CAT_PRD_ENTT_ENTT_ID',
        ],
    ];

    private static function schema(): \SimpleXMLElement
    {
        $xml = simplexml_load_file(dirname(__DIR__, 2) . '/etc/db_schema.xml');
        self::assertInstanceOf(\SimpleXMLElement::class, $xml);

        return $xml;
    }

    /**
     * @return array<string, array<string, array<string, true>>>
     */
    private static function whitelistFromSchema(): array
    {
        $result = [];
        foreach (self::schema()->table as $table) {
            $entry = [];
            foreach ($table->column as $column) {
                $entry['column'][(string)$column['name']] = true;
            }
            foreach ($table->index as $index) {
                $entry['index'][(string)$index['referenceId']] = true;
            }
            foreach ($table->constraint as $constraint) {
                $entry['constraint'][(string)$constraint['referenceId']] = true;
            }
            $result[(string)$table['name']] = $entry;
        }

        return $result;
    }

    /**
     * @param array<mixed> $data
     * @return array<mixed>
     */
    private static function sorted(array $data): array
    {
        ksort($data);
        foreach ($data as &$value) {
            if (is_array($value)) {
                $value = self::sorted($value);
            }
        }

        return $data;
    }

    public function testWhitelistMatchesSchemaBothWays(): void
    {
        $json = file_get_contents(dirname(__DIR__, 2) . '/etc/db_schema_whitelist.json');
        $whitelist = json_decode((string)$json, true, 512, JSON_THROW_ON_ERROR);

        self::assertSame(self::sorted(self::whitelistFromSchema()), self::sorted($whitelist));
    }

    public function testReferenceIdsAsSpecifiedAndShortEnough(): void
    {
        $actual = [];
        foreach (self::whitelistFromSchema() as $table => $entry) {
            $ids = array_keys(($entry['index'] ?? []) + ($entry['constraint'] ?? []));
            sort($ids);
            $actual[$table] = $ids;
            foreach ($ids as $id) {
                self::assertLessThanOrEqual(64, strlen($id), $id);
            }
        }
        $expected = self::REFERENCE_IDS;
        foreach ($expected as &$ids) {
            sort($ids);
        }

        self::assertSame(self::sorted($expected), self::sorted($actual));
    }

    public function testProductForeignKeysCascade(): void
    {
        $foreign = self::schema()->xpath('//constraint[@*[local-name()="type"]="foreign"]');
        self::assertCount(3, $foreign);
        foreach ($foreign as $fk) {
            self::assertSame('product_id', (string)$fk['column']);
            self::assertSame('catalog_product_entity', (string)$fk['referenceTable']);
            self::assertSame('entity_id', (string)$fk['referenceColumn']);
            self::assertSame('CASCADE', (string)$fk['onDelete']);
        }
    }

    public function testAttemptOutcomeCountsAreIndexedByAttribute(): void
    {
        $columns = [];
        $xpath = '//table[@name="dmlab_catalog_enrich_attempt"]'
            . '/index[@referenceId="DMLAB_CATALOG_ENRICH_ATTEMPT_ATTRIBUTE_CODE_OUTCOME"]/column';
        foreach (self::schema()->xpath($xpath) as $column) {
            $columns[] = (string)$column['name'];
        }

        self::assertSame(['attribute_code', 'outcome'], $columns);
    }

    public function testRuleDefaults(): void
    {
        $columns = [];
        foreach (self::schema()->xpath('//table[@name="dmlab_catalog_enrich_rule"]/column') as $column) {
            $columns[(string)$column['name']] = $column;
        }

        self::assertSame('off', (string)$columns['mode']['default']);
        self::assertSame('0.9000', (string)$columns['threshold']['default']);
        self::assertSame('empty_only', (string)$columns['fill_mode']['default']);
        self::assertSame('0', (string)$columns['treat_zero_as_empty']['default']);
        self::assertSame('false', (string)$columns['treat_zero_as_empty']['nullable']);
        self::assertSame('1024', (string)$columns['attribute_set_ids']['length']);
        self::assertSame('true', (string)$columns['updated_at']['on_update']);
    }
}
