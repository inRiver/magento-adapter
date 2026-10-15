<?php

/**
 * @author InRiver <inriveradapters@inriver.com>
 * @copyright Copyright (c) InRiver (https://www.inriver.com/)
 * @link https://www.inriver.com/
 */

declare(strict_types=1);

namespace Inriver\Adapter\Test\Unit\Helper;

use Inriver\Adapter\Helper\Import;
use Magento\Catalog\Model\Product\Attribute\Source\Status;
use Magento\Catalog\Model\Product\Type;
use Magento\Catalog\Model\Product\Url;
use Magento\CatalogImportExport\Model\Import\Product;
use Magento\CatalogImportExport\Model\Import\Product\SkuProcessor;
use Magento\ConfigurableProduct\Model\Product\Type\Configurable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ImportTest extends TestCase
{
    private const STORE_VIEW_DEFAULT = 'DEFAULT';
    private const STORE_VIEW_EMPTY = '';
    private const STORE_VIEW_NULL = null;
    private const NAME = 'name';
    private const SKU = 'sku';
    private const URL = self::NAME . '-' . self::SKU;

    /** @var \Magento\CatalogImportExport\Model\Import\Product\SkuProcessor|\Inriver\Adapter\Test\Unit\Helper\MockObject */
    private $skuProcessor;

    /** @var \Magento\Catalog\Model\Product\Url|\Inriver\Adapter\Test\Unit\Helper\MockObject */
    private $productUrl;

    /**
     * @param string[] $rowData
     * @param bool $expected
     *
     */
    #[DataProvider('attributeProviderNewProduct')]
    public function testIsNewSimpleProduct(array $rowData, bool $expected): void
    {
        $validationHelper = new Import($this->skuProcessor, $this->productUrl);
        $this->assertEquals($expected, $validationHelper->isNewSimpleProductRowWithNoPrice($rowData));
    }

    /**
     * @param string[] $rowData
     * @param bool $expected
     *
     */
    #[DataProvider('attributeProviderDisable')]
    public function testIsImportTypeDisable(array $rowData, bool $expected): void
    {
        $validationHelper = new Import($this->skuProcessor, $this->productUrl);
        $this->assertEquals($expected, $validationHelper->isImportTypeDisable($rowData));
    }

    /**
     * @param string[] $rowData
     * @param string $expected
     *
     */
    #[DataProvider('attributeProviderProductUrl')]
    public function testGetUniqueProductUrl(array $rowData, string $expected): void
    {
        $validationHelper = new Import($this->skuProcessor, $this->productUrl);
        $this->assertEquals($expected, $validationHelper->getUniqueProductUrl($rowData));
    }

    /**
     * @param string $attributeValue
     * @param string $expected
     *
     */
    #[DataProvider('attributeValueProvider')]
    public function testDecodeImportAttributeValue(string $attributeValue, string $expected): void
    {
        $validationHelper = new Import($this->skuProcessor, $this->productUrl);
        $this->assertEquals($expected, $validationHelper->decodeImportAttributeValue($attributeValue));
    }

    /**
     * @return string[]
     */
    public static function attributeProviderNewProduct(): array
    {
        return [
            [
                'rowData' => [
                    Product::COL_TYPE => Type::TYPE_SIMPLE,
                    Product::COL_STORE_VIEW_CODE => self::STORE_VIEW_NULL,
                ],
                'expected' => false,
            ],
            [
                'rowData' => [
                    Product::COL_SKU => 'NEW_SKU',
                    Product::COL_STORE_VIEW_CODE => self::STORE_VIEW_NULL,
                ],
                'expected' => false,
            ],
            [
                'rowData' => [
                    Product::COL_SKU => 'NEW_SKU',
                    Product::COL_TYPE => Type::TYPE_SIMPLE,
                    Product::COL_STORE_VIEW_CODE => self::STORE_VIEW_NULL,
                ],
                'expected' => true,
            ],
            [
                'rowData' => [
                    Product::COL_SKU => 'OLD_SKU',
                    Product::COL_TYPE => Type::TYPE_SIMPLE,
                    Product::COL_STORE_VIEW_CODE => self::STORE_VIEW_NULL,
                ],
                'expected' => false,
            ],
            [
                'rowData' => [
                    Product::COL_SKU => 'NEW_SKU',
                    Product::COL_TYPE => Type::TYPE_SIMPLE,
                    Product::COL_STORE_VIEW_CODE => self::STORE_VIEW_NULL,
                    'price' => '0',
                ],
                'expected' => false,
            ],
            [
                'rowData' => [
                    Product::COL_SKU => 'NEW_SKU',
                    Product::COL_TYPE => Type::TYPE_SIMPLE,
                    Product::COL_STORE_VIEW_CODE => self::STORE_VIEW_EMPTY,
                ],
                'expected' => true,
            ],
            [
                'rowData' => [
                    Product::COL_SKU => 'NEW_SKU',
                    Product::COL_TYPE => Type::TYPE_SIMPLE,
                    Product::COL_STORE_VIEW_CODE => self::STORE_VIEW_DEFAULT,
                ],
                'expected' => true,
            ],
            [
                'rowData' => [
                    Product::COL_SKU => 'NEW_SKU',
                    Product::COL_TYPE => Configurable::TYPE_CODE,
                    Product::COL_STORE_VIEW_CODE => self::STORE_VIEW_NULL,
                ],
                'expected' => false,
            ],
        ];
    }

    /**
     * @return string[]
     */
    public static function attributeProviderDisable(): array
    {
        return [
            [
                'rowData' => [
                    Product::COL_NAME => self::NAME,
                    'product_online' => Status::STATUS_DISABLED,
                ],
                'expected' => false,
            ],
            [
                'rowData' => [
                    Product::COL_NAME => self::NAME,
                    'product_online' => Status::STATUS_ENABLED,
                ],
                'expected' => false,
            ],
            [
                'rowData' => [
                    'product_online' => Status::STATUS_DISABLED,
                ],
                'expected' => true,
            ],
            [
                'rowData' => [
                    'product_online' => '2',
                ],
                'expected' => true,
            ],
            [
                'rowData' => [
                    'product_online' => 'asdasdadas',
                ],
                'expected' => false,
            ],
            [
                'rowData' => [
                    'product_online' => Status::STATUS_ENABLED,
                ],
                'expected' => false,
            ],
        ];
    }

    /**
     * @return string[]
     */
    public static function attributeProviderProductUrl(): array
    {
        return [
            [
                'rowData' => [
                    Product::COL_NAME => self::NAME,
                    Product::COL_SKU => self::SKU,
                ],
                'expected' => self::URL,
            ],
            [
                'rowData' => [
                    Product::COL_NAME => '',
                    Product::COL_SKU => self::SKU,
                ],
                'expected' => '',
            ],
            [
                'rowData' => [
                    Product::COL_NAME => self::NAME,
                    Product::COL_SKU => '',
                ],
                'expected' => '',
            ],
            [
                'rowData' => [
                    Product::COL_SKU => self::SKU,
                ],
                'expected' => '',
            ],
            [
                'rowData' => [
                    Product::COL_NAME => self::NAME,
                ],
                'expected' => '',
            ],
        ];
    }

    /**
     * @return string[]
     */
    public static function attributeValueProvider(): array
    {
        return [
            [
                'alpha',
                'expected' => 'alpha',
            ],
            [
                'alpha%25',
                'expected' => 'alpha%',
            ],
            [
                'alpha%3D',
                'expected' => 'alpha=',
            ],
            [
                'alpha%2C',
                'expected' => 'alpha,',
            ],
            [
                'alpha%22',
                'expected' => 'alpha"',
            ],
            [
                'alpha%7C',
                'expected' => 'alpha|',
            ],
            [
                '%3D%2C%22%7C%25',
                'expected' => '=,"|%',
            ],
            [
                '%25%7C%22%3D%2C',
                'expected' => '%|"=,',
            ],
        ];
    }

    protected function setUp(): void
    {
        $this->productUrl = $this->createMock(Url::class);
        $this->productUrl->method('formatUrlKey')->willReturn(self::URL);
        $this->skuProcessor = $this->createMock(SkuProcessor::class);
        $this->skuProcessor->method('getOldSkus')->willReturn(['old_sku' => 'old_sku']);
    }
}
