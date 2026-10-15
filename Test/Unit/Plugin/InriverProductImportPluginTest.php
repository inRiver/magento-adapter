<?php

/**
 * @author InRiver <inriveradapters@inriver.com>
 * @copyright Copyright (c) InRiver (https://www.inriver.com/)
 * @link https://www.inriver.com/
 */

declare(strict_types=1);

namespace Inriver\Adapter\Test\Unit\Plugin;

use Inriver\Adapter\Helper\Import;
use Inriver\Adapter\Model\Import\Product;
use Inriver\Adapter\Plugin\InriverProductImportPlugin;
use Magento\Catalog\Model\Product\Type;
use Magento\CatalogImportExport\Model\Import\Product as MagentoProduct;
use PHPUnit\Framework\TestCase;

use function count;

class InriverProductImportPluginTest extends TestCase
{
    private const NEW_SKU = 'newSKU';
    private const NEW_SKU_WITH_STATUS = 'newSKUStatus';
    private const DEFAULT_ROW_DATA = [
        MagentoProduct::COL_SKU => self::NEW_SKU,
        MagentoProduct::COL_TYPE => Type::TYPE_SIMPLE,
    ];

    /** @var \Inriver\Adapter\Model\Import\Product|\Inriver\Adapter\Test\Unit\Plugin\MockObject */
    private $productImport;

    /** @var \Inriver\Adapter\Helper\Import|\Inriver\Adapter\Test\Unit\Plugin\MockObject */
    private $importHelper;

    /** @var array */
    private $rowsData = [];

    public function testIsDisableCheck(): void
    {
        $inriverProductImportPlugin = new InriverProductImportPlugin($this->importHelper);
        $this->importHelper
            ->method('isNewProductRowWithNoPrice')->willReturn(false);
        $this->productImport
            ->method('isImportTypeDisable')->willReturn(true);
        $this->productImport
            ->method('isImportTypeConfirm')->willReturn(true);
        $this->productImport
            ->expects($this->never())
            ->method('setIsImportTypeDisable');

        foreach ($this->getRowsData() as $rowData) {
            $inriverProductImportPlugin->beforeValidateRow(
                $this->productImport,
                $rowData,
                1
            );
        }
    }

    public function testIsDisableCheckOff(): void
    {
        $inriverProductImportPlugin = new InriverProductImportPlugin($this->importHelper);
        $this->importHelper
            ->method('isNewProductRowWithNoPrice')->willReturn(false);
        $this->productImport
            ->method('isImportTypeDisable')->willReturn(true);
        $this->productImport
            ->method('isImportTypeConfirm')->willReturn(false);
        $this->productImport
            ->expects($this->atLeastOnce())
            ->method('setIsImportTypeDisable');

        foreach ($this->getRowsData() as $rowData) {
            $inriverProductImportPlugin->beforeValidateRow(
                $this->productImport,
                $rowData,
                1
            );
        }
    }

    public function testIfIsNotNewProduct(): void
    {
        $inriverProductImportPlugin = new InriverProductImportPlugin($this->importHelper);
        $this->importHelper
            ->method('isNewProductRowWithNoPrice')->willReturn(false);
        $this->productImport
            ->method('isImportTypeDisable')->willReturn(false);

        foreach ($this->getRowsData() as $rowData) {
            $return = $inriverProductImportPlugin->beforeValidateRow(
                $this->productImport,
                $rowData,
                1
            );
            $rowData = $return[0];

            if ($rowData['sku'] === self::NEW_SKU_WITH_STATUS) {
                $this->assertEquals(
                    1,
                    $rowData['status']
                );
            } elseif ($rowData['sku'] === self::NEW_SKU) {
                $this->assertEquals(
                    false,
                    isset($rowData['status'])
                );
            }
        }
    }

    public function testIfIsImportTypeDisable(): void
    {
        $inriverProductImportPlugin = new InriverProductImportPlugin($this->importHelper);
        $this->importHelper
            ->method('isNewProductRowWithNoPrice')->willReturn(false);
        $this->productImport
            ->method('isImportTypeDisable')->willReturn(true);

        foreach ($this->getRowsData() as $rowData) {
            $return = $inriverProductImportPlugin->beforeValidateRow(
                $this->productImport,
                $rowData,
                1
            );
            $rowData = $return[0];

            if ($rowData['sku'] === self::NEW_SKU_WITH_STATUS) {
                $this->assertEquals(
                    1,
                    $rowData['status']
                );
            } elseif ($rowData['sku'] === self::NEW_SKU) {
                $this->assertEquals(
                    false,
                    isset($rowData['status'])
                );
            }
        }
    }

    public function testIfIsNewProduct(): void
    {
        $inriverProductImportPlugin = new InriverProductImportPlugin($this->importHelper);
        $this->importHelper
            ->method('isNewProductRowWithNoPrice')->willReturn(true);
        $this->productImport
            ->method('isImportTypeDisable')->willReturn(false);

        foreach ($this->getRowsData() as $rowData) {
            $return = $inriverProductImportPlugin->beforeValidateRow(
                $this->productImport,
                $rowData,
                1
            );
            $rowData = $return[0];
            $expectedStatus = $rowData[MagentoProduct::COL_SKU] === self::NEW_SKU_WITH_STATUS ? 1 : 2;
            $this->assertSame($expectedStatus, $rowData['status']);
        }
    }

    public function testParseMultiple(): void
    {
        $inriverProductImportPlugin = new InriverProductImportPlugin($this->importHelper);
        $this->importHelper
            ->method('decodeImportAttributeValue')->willReturn('abc%');

        $return = $inriverProductImportPlugin->afterParseMultiselectValues(
            $this->productImport,
            ['attr1' => 'abc%25', 'attr2' => 'abc%25']
        );
        $this->assertEquals(
            'abc%',
            $return['attr1']
        );
        $this->assertEquals(
            'abc%',
            $return['attr2']
        );
    }

    public function testParseMultipleIsString(): void
    {
        $inriverProductImportPlugin = new InriverProductImportPlugin($this->importHelper);
        $this->importHelper
            ->expects($this->once())
            ->method('decodeImportAttributeValue')->willReturn('abc%');

        $return = $inriverProductImportPlugin->afterParseMultiselectValues(
            $this->productImport,
            ['attr1' => null, 'attr2' => 'abc%25', 'attr3' => 0, 'attr4' => false]
        );
        $this->assertEquals(
            'abc%',
            $return['attr2']
        );
    }

    protected function setUp(): void
    {
        $this->productImport = $this->createPartialMock(
            Product::class,
            ['isImportTypeConfirm', 'setIsImportTypeDisable', 'isImportTypeDisable']
        );
        $this->importHelper = $this->createPartialMock(
            Import::class,
            ['isNewProductRowWithNoPrice', 'isImportTypeDisable', 'decodeImportAttributeValue']
        );
    }

    /**
     * @return string[]
     */
    private function getRowsData(): array
    {
        if (count($this->rowsData) === 0) {
            $this->rowsData[] = self::DEFAULT_ROW_DATA;
            $rowDataWithStatus = self::DEFAULT_ROW_DATA;
            $rowDataWithStatus[MagentoProduct::COL_SKU] = self::NEW_SKU_WITH_STATUS;
            $rowDataWithStatus['status'] = 1;
            $this->rowsData[] = $rowDataWithStatus;
        }

        return $this->rowsData;
    }
}
