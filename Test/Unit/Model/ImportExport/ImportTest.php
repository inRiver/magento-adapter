<?php

/**
 * @author InRiver <inriveradapters@inriver.com>
 * @copyright Copyright (c) InRiver (https://www.inriver.com/)
 * @link https://www.inriver.com/
 */

declare(strict_types=1);

namespace Inriver\Adapter\Test\Unit\Model\ImportExport;

use Inriver\Adapter\Helper\Import as InriverImportHelper;
use Inriver\Adapter\Model\Import\Product;
use Inriver\Adapter\Model\ImportExport\Import;
use Magento\CatalogImportExport\Model\Import\Product as MagentoProduct;
use Magento\Framework\TestFramework\Unit\Helper\ObjectManager;
use Magento\ImportExport\Model\Import\Config as ImportConfig;
use Magento\ImportExport\Model\Import\Entity\Factory;
use PHPUnit\Framework\TestCase;

class ImportTest extends TestCase
{
    private const PRODUCT = 'catalog_product';

    /** @var \Inriver\Adapter\Model\ImportExport\Import */
    private $import;

    /** @var \Inriver\Adapter\Model\Import\Product|\Inriver\Adapter\Test\Unit\Model\ImportExport\MockObject */
    private $entityAdapterInriver;

    /** @var \Magento\CatalogImportExport\Model\Import\Product|\Inriver\Adapter\Test\Unit\Model\ImportExport\MockObject */
    private $entityAdapterProduct;

    /** @var \Magento\ImportExport\Model\Import\Config|\Inriver\Adapter\Test\Unit\Model\ImportExport\MockObject */
    private $importConfig;

    /** @var |MockObject */
    private $entityFactory;

    public function setUp(): void
    {
        $this->importConfig = $this->createMock(ImportConfig::class);
        $this->entityAdapterInriver = $this->createMock(Product::class);
        $this->entityAdapterProduct = $this->createMock(MagentoProduct::class);
        $this->entityFactory = $this->createMock(Factory::class);
        $objectManager = new ObjectManager($this);

        $this->import = $objectManager->getObject(
            Import::class,
            [
                'importConfig' => $this->importConfig,
                'entityFactory' => $this->entityFactory,
                'data' => ['entity' => self::PRODUCT],
            ]
        );
    }

    public function testIsImportTypeDisableWithInriverImport(): void
    {
        $this->import->setData('entity', InriverImportHelper::INRIVER_ENTITY);
        $this->importConfig->method('getEntities')->willReturn([
            InriverImportHelper::INRIVER_ENTITY => ['model' => 'catalog'],
            self::PRODUCT => ['model' => 'catalog'],
        ]);
        $this->entityAdapterInriver->method('getEntityTypeCode')->willReturn(self::PRODUCT);
        $this->entityAdapterInriver->method('isImportTypeDisable')->willReturn(true);
        $this->entityFactory->method('create')->willReturn($this->entityAdapterInriver);

        /** @noinspection PhpUnhandledExceptionInspection */
        $this->assertTrue($this->import->isImportTypeDisable());
    }

    public function testIsImportTypeDisableWithProductImport(): void
    {
        $this->importConfig->method('getEntities')->willReturn([self::PRODUCT => ['model' => 'catalog']]);
        $this->entityAdapterProduct->method('getEntityTypeCode')->willReturn(self::PRODUCT);
        $this->entityFactory->method('create')->willReturn($this->entityAdapterProduct);

        /** @noinspection PhpUnhandledExceptionInspection */
        $this->assertFalse($this->import->isImportTypeDisable());
    }
}
