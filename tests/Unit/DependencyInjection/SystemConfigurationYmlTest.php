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
        $this->assertArrayHasKey('kenzi_connection_website', $groups, 'Group "kenzi_connection_website" must exist');
    }

    public function testAdminVisibleFieldsDefined(): void
    {
        $fields = $this->config['system_configuration']['fields'];

        $this->assertArrayHasKey(
            'kenzi_oro_commerce.connect_button',
            $fields,
            'connect_button field must have admin UI definition'
        );
        $this->assertArrayHasKey(
            'kenzi_oro_commerce.widget_enabled',
            $fields,
            'widget_enabled field must have admin UI definition'
        );
    }

    public function testConnectButtonFieldIsUiOnly(): void
    {
        $field = $this->config['system_configuration']['fields']['kenzi_oro_commerce.connect_button'];

        $this->assertTrue($field['ui_only'], 'connect_button must be ui_only');
        $this->assertSame(
            'Kenzi\OroCommerceBundle\Form\Type\KenziConnectButtonType',
            $field['type']
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

    public function testGlobalTreeContainsExpectedFields(): void
    {
        $globalChildren = $this->resolveTreeLeaves(
            $this->config['system_configuration']['tree']['system_configuration']
        );

        // Connect button appears in both trees for CE/EE compatibility
        $this->assertContains('kenzi_oro_commerce.connect_button', $globalChildren);
        $this->assertContains('kenzi_oro_commerce.widget_enabled', $globalChildren);

        // Secrets and per-website fields must NOT appear in global tree
        $this->assertNotContains(
            'kenzi_oro_commerce.shared_secret',
            $globalChildren,
            'shared_secret must not be in global tree — it is website-scoped'
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

        // Only admin-visible fields belong in the tree.
        // Programmatic-only fields (shared_secret, workspace_id, etc.) are scoped
        // via ConfigManager::set() and do NOT need tree entries.
        $expectedWebsiteFields = [
            'kenzi_oro_commerce.connect_button',
            'kenzi_oro_commerce.widget_enabled',
        ];

        foreach ($expectedWebsiteFields as $field) {
            $this->assertContains(
                $field,
                $websiteChildren,
                "Website tree must contain {$field}"
            );
        }

        // Programmatic-only fields should NOT be in any tree
        $this->assertNotContains(
            'kenzi_oro_commerce.shared_secret',
            $websiteChildren,
            'shared_secret must not be in website tree — it is programmatic-only'
        );
    }

    public function testGlobalTreeNestsUnderPlatformIntegrations(): void
    {
        $sysTree = $this->config['system_configuration']['tree']['system_configuration'];

        $this->assertArrayHasKey('platform', $sysTree, 'Global tree must nest under "platform"');
        $this->assertArrayHasKey(
            'integrations',
            $sysTree['platform']['children'],
            'Global tree must nest under "platform > integrations"'
        );
    }

    public function testWebsiteTreeNestsUnderCommerce(): void
    {
        $webTree = $this->config['system_configuration']['tree']['website_configuration'];

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
