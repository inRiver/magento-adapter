<?php

/**
 * @author InRiver <inriveradapters@inriver.com>
 * @copyright Copyright (c) InRiver (https://www.inriver.com/)
 * @link https://www.inriver.com/
 */

declare(strict_types=1);

namespace Inriver\Adapter\Test\Unit\Plugin;

use Inriver\Adapter\Model\Config\Source\Behavior;
use Inriver\Adapter\Model\Import\Product;
use Inriver\Adapter\Plugin\InriverImportConfigPlugin;
use Magento\BundleImportExport\Model\Import\Product\Type\Bundle;
use Magento\CatalogImportExport\Model\Import\Product\Type\Simple;
use Magento\CatalogImportExport\Model\Import\Product\Type\Virtual;
use Magento\ConfigurableImportExport\Model\Import\Product\Type\Configurable;
use Magento\DownloadableImportExport\Model\Import\Product\Type\Downloadable;
use Magento\GroupedImportExport\Model\Import\Product\Type\Grouped;
use Magento\ImportExport\Model\Import\Config;
use PHPUnit\Framework\TestCase;

class InriverImportConfigPluginTest extends TestCase
{
    private const CATALOG_PRODUCT = [
        'name' => 'inriver_product',
        'label' => 'Inriver Products',
        'behaviorModel' => Behavior::class,
        'model' => Product::class,
        'types' => [
            'bundle' => [
                'name' => 'bundle',
                'model' => Bundle::class,
            ],
            'simple' => [
                'name' => 'simple',
                'model' => Simple::class,
            ],
            'virtual' => [
                'name' => 'virtual',
                'model' => Virtual::class,
            ],
            'configurable' => [
                'name' => 'configurable',
                'model' => Configurable::class,
            ],
            'grouped' => [
                'name' => 'grouped',
                'model' => Grouped::class,
            ],
            'downloadable' => [
                'name' => 'downloadable',
                'model' => Downloadable::class,
            ],
        ],
        'relatedIndexers' => [
            'catalog_product_price' => ['name' => 'catalog_product_price'],
            'catalogsearch_fulltext' => ['name' => 'catalogsearch_fulltext'],
            'catalog_product_flat' => ['name' => 'catalog_product_flat'],
        ],
    ];

    private const INRIVER_PRODUCT = [
        'name' => 'inriver_product',
        'label' => 'Inriver Products',
        'behaviorModel' => Behavior::class,
        'model' => Product::class,
        'types' => [
            'bundle' => [
                'name' => 'bundle',
                'model' => Bundle::class,
            ],
            'simple' => [
                'name' => 'simple',
                'model' => Simple::class,
            ],
            'virtual' => [
                'name' => 'virtual',
                'model' => Virtual::class,
            ],
            'configurable' => [
                'name' => 'configurable',
                'model' => Configurable::class,
            ],
            'grouped' => [
                'name' => 'grouped',
                'model' => Grouped::class,
            ],
            'downloadable' => [
                'name' => 'downloadable',
                'model' => Downloadable::class,
            ],
        ],
        'relatedIndexers' => [
            'catalog_product_price' => ['name' => 'catalog_product_price'],
            'catalogsearch_fulltext' => ['name' => 'catalogsearch_fulltext'],
            'catalog_product_flat' => ['name' => 'catalog_product_flat'],
        ],
    ];

    private const FINAL_INRIVER_ARRAY_PRODUCT = [
        'name' => 'inriver_product',
        'label' => 'Inriver Products',
        'behaviorModel' => Behavior::class,
        'model' => Product::class,
        'types' => [
            'bundle' => [
                'name' => 'bundle',
                'model' => Bundle::class,
            ],
            'simple' => [
                'name' => 'simple',
                'model' => Simple::class,
            ],
            'virtual' => [
                'name' => 'virtual',
                'model' => Virtual::class,
            ],
            'configurable' => [
                'name' => 'configurable',
                'model' => Configurable::class,
            ],
            'grouped' => [
                'name' => 'grouped',
                'model' => Grouped::class,
            ],
            'downloadable' => [
                'name' => 'downloadable',
                'model' => Downloadable::class,
            ],
        ],
        'relatedIndexers' => [
            'catalog_product_price' => ['name' => 'catalog_product_price'],
            'catalogsearch_fulltext' => ['name' => 'catalogsearch_fulltext'],
            'catalog_product_flat' => ['name' => 'catalog_product_flat'],
        ],
    ];

    /** @var \Magento\ImportExport\Model\Import\Config|\Inriver\Adapter\Test\Unit\Plugin\MockObject */
    private $config;

    public function testIfConfigArrayEmpty(): void
    {
        $useCatalogEntityDataPlugin = new InriverImportConfigPlugin();
        $result = $useCatalogEntityDataPlugin->afterGetEntities($this->config, []);

        $this->assertEquals(
            [],
            $result
        );
    }

    public function testIfNormalConfigArray(): void
    {
        $useCatalogEntityDataPlugin = new InriverImportConfigPlugin();
        $configArray = [
            'catalog_product' => self::CATALOG_PRODUCT,
            'inriver_product' => self::INRIVER_PRODUCT,
        ];
        $configFinalArray = [
            'catalog_product' => self::CATALOG_PRODUCT,
            'inriver_product' => self::FINAL_INRIVER_ARRAY_PRODUCT,
        ];
        $result = $useCatalogEntityDataPlugin->afterGetEntities($this->config, $configArray);

        $this->assertEquals(
            $configFinalArray,
            $result
        );
    }

    public function testOtherArray(): void
    {
        $useCatalogEntityDataPlugin = new InriverImportConfigPlugin();
        $configArray = [
            'catalog_product' => self::CATALOG_PRODUCT,
            'other_product' => self::INRIVER_PRODUCT,
        ];
        $configFinalArray = [
            'catalog_product' => self::CATALOG_PRODUCT,
            'other_product' => self::INRIVER_PRODUCT,
        ];
        $result = $useCatalogEntityDataPlugin->afterGetEntities($this->config, $configArray);

        $this->assertEquals(
            $configFinalArray,
            $result
        );
    }

    protected function setUp(): void
    {
        $this->config =
            $this->getMockBuilder(Config::class)
                ->disableOriginalConstructor()
                ->getMock();
    }
}
