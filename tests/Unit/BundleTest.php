<?php

declare(strict_types=1);

namespace Kenzi\OroCommerceBundle\Tests\Unit;

use Kenzi\OroCommerceBundle\KenziOroCommerceBundle;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpKernel\Bundle\Bundle;

final class BundleTest extends TestCase
{
    public function testBundleInstantiates(): void
    {
        $bundle = new KenziOroCommerceBundle();

        $this->assertInstanceOf(Bundle::class, $bundle);
    }

    public function testBundleName(): void
    {
        $bundle = new KenziOroCommerceBundle();

        $this->assertSame('KenziOroCommerceBundle', $bundle->getName());
    }
}
