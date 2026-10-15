<?php

/**
 * @author InRiver <inriveradapters@inriver.com>
 * @copyright Copyright (c) InRiver (https://www.inriver.com/)
 * @link https://www.inriver.com/
 */

declare(strict_types=1);

namespace Inriver\Adapter\Test\Unit\Plugin;

use Inriver\Adapter\Helper\Import as InriverImportHelper;
use Inriver\Adapter\Model\ImportExport\Import;
use Inriver\Adapter\Model\ResourceModel\Import\Data;
use Inriver\Adapter\Plugin\UseInriverImportDataPlugin;
use Magento\ImportExport\Model\ResourceModel\Import\Data as MagentoImportData;
use PHPUnit\Framework\TestCase;

use function get_class;

class UseInriverImportDataPluginTest extends TestCase
{
    /** @var \Inriver\Adapter\Model\ImportExport\Import|\Inriver\Adapter\Test\Unit\Plugin\MockObject */
    private $import;

    /** @var \Magento\ImportExport\Model\ResourceModel\Import\Data|\Inriver\Adapter\Test\Unit\Plugin\MockObject */
    private $importData;

    /** @var \Inriver\Adapter\Model\ResourceModel\Import\Data|\Inriver\Adapter\Test\Unit\Plugin\MockObject */
    private $inriverImportData;

    public function testUseInriverDataImportIfInriverImport(): void
    {
        $useInriverImportDataPlugin = new UseInriverImportDataPlugin($this->inriverImportData);
        /** @noinspection PhpParamsInspection */
        $this->import->method('getData')
            ->with(InriverImportHelper::IS_INRIVER_IMPORT)->willReturn(true);
        $result = $useInriverImportDataPlugin->afterGetDataSourceModel($this->import, $this->importData);

        $this->assertEquals(
            get_class($this->inriverImportData),
            get_class($result)
        );
    }

    public function testUseMagentoDataImportIfInriverImport(): void
    {
        $useInriverImportDataPlugin = new UseInriverImportDataPlugin($this->inriverImportData);
        /** @noinspection PhpParamsInspection */
        $this->import->method('getData')
            ->with(InriverImportHelper::IS_INRIVER_IMPORT)->willReturn(false);
        $result = $useInriverImportDataPlugin->afterGetDataSourceModel($this->import, $this->importData);

        $this->assertEquals(
            get_class($this->importData),
            get_class($result)
        );
    }

    protected function setUp(): void
    {
        $this->import =
            $this->getMockBuilder(Import::class)
                ->disableOriginalConstructor()
                ->onlyMethods(['getData'])
                ->getMock();
        $this->importData =
            $this->getMockBuilder(MagentoImportData::class)
                ->disableOriginalConstructor()
                ->getMock();
        $this->inriverImportData =
            $this->getMockBuilder(Data::class)
                ->disableOriginalConstructor()
                ->getMock();
    }
}
