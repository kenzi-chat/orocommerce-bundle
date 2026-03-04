<?php

declare(strict_types=1);

namespace Kenzi\OroCommerceBundle\Tests\Unit\DependencyInjection;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

final class SystemConfigurationYmlTest extends TestCase
{
    private const YML_PATH = __DIR__ . '/../../../src/Resources/config/oro/system_configuration.yml';

    /** @var array<string, mixed> */
    private array $config;

    protected function setUp(): void
    {
        $this->assertFileExists(self::YML_PATH, 'system_configuration.yml must exist');

        $this->config = Yaml::parseFile(self::YML_PATH);
    }

    public function testTopLevelStructure(): void
    {
        $this->assertArrayHasKey('system_configuration', $this->config);

        $sysConfig = $this->config['system_configuration'];
        $this->assertArrayHasKey('groups', $sysConfig);
        $this->assertArrayHasKey('fields', $sysConfig);
        $this->assertArrayHasKey('tree', $sysConfig);
    }

    public function testRequiredGroupsDefined(): void
    {
        $groups = $this->config['system_configuration']['groups'];

        $this->assertArrayHasKey('kenzi', $groups, 'Root group "kenzi" must exist');
        $this->assertArrayHasKey('kenzi_connection', $groups, 'Group "kenzi_connection" must exist');
        $this->assertArrayHasKey('kenzi_connection_general', $groups, 'Group "kenzi_connection_general" must exist');
    }

    public function testAdminVisibleFieldsDefined(): void
    {
        $fields = $this->config['system_configuration']['fields'];

        $this->assertArrayHasKey(
            'kenzi_oro_commerce.widget_enabled',
            $fields,
            'widget_enabled field must have admin UI definition'
        );
        $this->assertArrayHasKey(
            'kenzi_oro_commerce.webhook_url',
            $fields,
            'webhook_url field must have admin UI definition'
        );
    }

    public function testWidgetEnabledFieldIsBoolean(): void
    {
        $field = $this->config['system_configuration']['fields']['kenzi_oro_commerce.widget_enabled'];

        $this->assertSame('boolean', $field['data_type']);
    }

    public function testDualTreePattern(): void
    {
        $tree = $this->config['system_configuration']['tree'];

        $this->assertArrayHasKey(
            'system_configuration',
            $tree,
            'Global-scope tree must exist'
        );
        $this->assertArrayHasKey(
            'website_configuration',
            $tree,
            'Website-scope tree must exist for CE/EE compatibility'
        );
    }

    public function testGlobalTreeContainsOnlyGlobalFields(): void
    {
        $globalChildren = $this->resolveTreeLeaves(
            $this->config['system_configuration']['tree']['system_configuration']
        );

        $this->assertContains('kenzi_oro_commerce.widget_enabled', $globalChildren);
        $this->assertContains('kenzi_oro_commerce.webhook_url', $globalChildren);

        // Secrets and per-website fields must NOT appear in global tree
        $this->assertNotContains(
            'kenzi_oro_commerce.secret',
            $globalChildren,
            'secret must not be in global tree — it is website-scoped'
        );
        $this->assertNotContains(
            'kenzi_oro_commerce.workspace_id',
            $globalChildren,
            'workspace_id must not be in global tree — it is website-scoped'
        );
    }

    public function testWebsiteTreeContainsPerWebsiteFields(): void
    {
        $websiteChildren = $this->resolveTreeLeaves(
            $this->config['system_configuration']['tree']['website_configuration']
        );

        $expectedWebsiteFields = [
            'kenzi_oro_commerce.widget_enabled',
            'kenzi_oro_commerce.widget_base_url',
            'kenzi_oro_commerce.workspace_id',
            'kenzi_oro_commerce.sync_enabled',
            'kenzi_oro_commerce.secret',
            'kenzi_oro_commerce.store_key',
            'kenzi_oro_commerce.connected_at',
        ];

        foreach ($expectedWebsiteFields as $field) {
            $this->assertContains(
                $field,
                $websiteChildren,
                "Website tree must contain {$field}"
            );
        }
    }

    public function testTreesNestUnderCommerceSection(): void
    {
        $sysTree = $this->config['system_configuration']['tree']['system_configuration'];
        $webTree = $this->config['system_configuration']['tree']['website_configuration'];

        $this->assertArrayHasKey('commerce', $sysTree, 'Global tree must nest under "commerce"');
        $this->assertArrayHasKey('commerce', $webTree, 'Website tree must nest under "commerce"');
    }

    /**
     * Recursively collect leaf strings (field references) from an Oro config tree node.
     *
     * @param array<string, mixed> $node
     * @return list<string>
     */
    private function resolveTreeLeaves(array $node): array
    {
        $leaves = [];

        foreach ($node as $key => $value) {
            if (is_string($value)) {
                $leaves[] = $value;
            } elseif (is_array($value)) {
                if (isset($value['children'])) {
                    $leaves = array_merge($leaves, $this->resolveTreeLeaves($value['children']));
                } elseif ($key === 'children') {
                    $leaves = array_merge($leaves, $this->resolveTreeLeaves($value));
                } else {
                    $leaves = array_merge($leaves, $this->resolveTreeLeaves($value));
                }
            }
        }

        return $leaves;
    }
}
