<?php

/**
 * @author InRiver <inriveradapters@inriver.com>
 * @copyright Copyright (c) InRiver (https://www.inriver.com/)
 * @link https://www.inriver.com/
 */

declare(strict_types=1);

namespace Inriver\Adapter\Test\Unit\Plugin;

use Inriver\Adapter\Helper\Import as InriverImportHelper;
use Inriver\Adapter\Plugin\InriverProductImportTypeAbstractPlugin;
use Magento\CatalogImportExport\Model\Import\Product;
use Magento\CatalogImportExport\Model\Import\Product\Type\AbstractType;
use PHPUnit\Framework\TestCase;

use function array_pop;

class InriverProductImportTypeAbstractPluginTest extends TestCase
{
    /** @var \Magento\CatalogImportExport\Model\Import\Product\Type\AbstractType|\Inriver\Adapter\Test\Unit\Plugin\MockObject */
    private $productImportTypeAbstract;

    /** @var \Inriver\Adapter\Helper\Import|\Inriver\Adapter\Test\Unit\Plugin\MockObject */
    private $inriverImportHelper;

    public function testIsNotInriver(): void
    {
        $inriverProductImportPlugin = new InriverProductImportTypeAbstractPlugin($this->inriverImportHelper);
        $this->inriverImportHelper->expects($this->never())->method('decodeImportAttributeValue');
        $this->productImportTypeAbstract->expects($this->never())->method('retrieveAttribute');

        $rowData = ['attr1' => 'abc%25', 'sku' => 'abc%25', InriverImportHelper::COL_IS_INRIVER_IMPORT => true];
        $return = $inriverProductImportPlugin->beforePrepareAttributesWithDefaultValueForSave(
            $this->productImportTypeAbstract,
            $rowData,
            true
        );

        $this->assertEquals(
            'abc%25',
            $return[0]['attr1']
        );
        $this->assertEquals(
            'abc%25',
            $return[0]['sku']
        );
    }

    public function testNoAttributeSet(): void
    {
        $inriverProductImportPlugin = new InriverProductImportTypeAbstractPlugin($this->inriverImportHelper);
        $this->inriverImportHelper->expects($this->never())->method('decodeImportAttributeValue');
        $this->productImportTypeAbstract->expects($this->never())->method('retrieveAttribute');

        $rowData = ['attr1' => 'abc%25', 'sku' => 'abc%25', InriverImportHelper::COL_IS_INRIVER_IMPORT => true];
        $return = $inriverProductImportPlugin->beforePrepareAttributesWithDefaultValueForSave(
            $this->productImportTypeAbstract,
            $rowData,
            true
        );

        $this->assertEquals(
            'abc%25',
            $return[0]['attr1']
        );
        $this->assertEquals(
            'abc%25',
            $return[0]['sku']
        );
    }

    public function testAttributeSetInvalid(): void
    {
        $inriverProductImportPlugin = new InriverProductImportTypeAbstractPlugin($this->inriverImportHelper);
        $this->inriverImportHelper->expects($this->never())->method('decodeImportAttributeValue');
        $this->productImportTypeAbstract->expects($this->never())->method('retrieveAttribute');

        $rowData = ['attr1' => 'abc%25', 'sku' => 'abc%25', Product::COL_ATTR_SET => null, InriverImportHelper::COL_IS_INRIVER_IMPORT => true];
        $return = $inriverProductImportPlugin->beforePrepareAttributesWithDefaultValueForSave(
            $this->productImportTypeAbstract,
            $rowData,
            true
        );

        $this->assertEquals(
            'abc%25',
            $return[0]['attr1']
        );
        $this->assertEquals(
            'abc%25',
            $return[0]['sku']
        );

        $return = $inriverProductImportPlugin->beforePrepareAttributesWithDefaultValueForSave(
            $this->productImportTypeAbstract,
            ['attr1' => 'abc%25', 'sku' => 'abc%25', Product::COL_ATTR_SET => ''],
            true
        );

        $this->assertEquals(
            'abc%25',
            $return[0]['attr1']
        );
        $this->assertEquals(
            'abc%25',
            $return[0]['sku']
        );
    }

    public function testAttributeInNotToDecode(): void
    {
        $inriverProductImportPlugin = new InriverProductImportTypeAbstractPlugin($this->inriverImportHelper);
        $this->inriverImportHelper->expects($this->never())->method('decodeImportAttributeValue');
        $this->productImportTypeAbstract->expects($this->never())->method('retrieveAttribute');
        $valueToNotDecode = array_filter(
            InriverImportHelper::ATTRIBUTES_NOT_TO_DECODE,
            fn($attr) => $attr !== InriverImportHelper::COL_IS_INRIVER_IMPORT
        );
        $attributeCode = array_pop($valueToNotDecode);
        $rowData = [$attributeCode => 'abc%25', Product::COL_ATTR_SET => 'attrSet', InriverImportHelper::COL_IS_INRIVER_IMPORT => true];
        $return = $inriverProductImportPlugin->beforePrepareAttributesWithDefaultValueForSave(
            $this->productImportTypeAbstract,
            $rowData,
            true
        );

        $this->assertEquals(
            'abc%25',
            $return[0][$attributeCode]
        );
    }

    public function testValidateAttributeThatFail(): void
    {
        $inriverProductImportPlugin = new InriverProductImportTypeAbstractPlugin($this->inriverImportHelper);
        $this->inriverImportHelper->expects($this->never())->method('decodeImportAttributeValue');
        $typeToNotDecode = InriverImportHelper::ATTRIBUTES_NOT_TO_DECODE;
        $type = array_pop($typeToNotDecode);
        $attributes = [
            [],
            ['is_static' => false, 'type' => $type],
            ['is_static' => true],
            ['is_static' => false],
            ['type' => $type],
        ];

        foreach ($attributes as $attribute) {
            $this->validateForDifferentAttributeThatFail($inriverProductImportPlugin, $attribute);
        }
    }

    public function testValidateAttributeThatWork(): void
    {
        $inriverProductImportPlugin = new InriverProductImportTypeAbstractPlugin($this->inriverImportHelper);
        $this->inriverImportHelper->expects($this->exactly(2))
            ->method('decodeImportAttributeValue')->willReturn('abc%');
        $this->productImportTypeAbstract->method('retrieveAttribute')
            ->willReturn(['is_static' => false, 'type' => 'attrNotInList']);

        $rowData = [
               'attr1' => 'abc%25',
               'attr2' => null, 'sku' => 'abc%25',
               Product::COL_ATTR_SET => 'attrSet',
               'attr3' => 'abc%',
               'attr4' => false,
               InriverImportHelper::COL_IS_INRIVER_IMPORT => true
        ];
        $return = $inriverProductImportPlugin->beforePrepareAttributesWithDefaultValueForSave(
            $this->productImportTypeAbstract,
            $rowData,
            true
        );

        $this->assertEquals(
            'abc%',
            $return[0]['attr1']
        );
        $this->assertEquals(
            'abc%25',
            $return[0]['sku']
        );
        $this->assertEquals(
            'attrSet',
            $return[0][Product::COL_ATTR_SET]
        );
        $this->assertFalse($return[0]['attr4']);
        $this->assertNull($return[0]['attr2']);
    }

    protected function setUp(): void
    {
        $this->productImportTypeAbstract = $this->createPartialMock(
            AbstractType::class,
            ['retrieveAttribute']
        );
        $this->inriverImportHelper = $this->createPartialMock(
            InriverImportHelper::class,
            ['decodeImportAttributeValue']
        );
    }

    /**
     * @param \Inriver\Adapter\Plugin\InriverProductImportTypeAbstractPlugin $inriverProductImportPlugin
     * @param string[] $attribute
     */
    private function validateForDifferentAttributeThatFail(
        InriverProductImportTypeAbstractPlugin $inriverProductImportPlugin,
        array $attribute
    ): void {
        $this->productImportTypeAbstract->method('retrieveAttribute')->willReturn($attribute);
        $rowData = ['attr1' => 'abc%25', 'sku' => 'abc%25', Product::COL_ATTR_SET => 'attrSet', InriverImportHelper::COL_IS_INRIVER_IMPORT => true];
        $return = $inriverProductImportPlugin->beforePrepareAttributesWithDefaultValueForSave(
            $this->productImportTypeAbstract,
            $rowData,
            true
        );

        $this->assertEquals(
            'abc%25',
            $return[0]['attr1']
        );
        $this->assertEquals(
            'abc%25',
            $return[0]['sku']
        );
    }
}
