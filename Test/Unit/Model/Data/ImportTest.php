<?php

/**
 * @author InRiver <inriveradapters@inriver.com>
 * @copyright Copyright (c) InRiver (https://www.inriver.com/)
 * @link https://www.inriver.com/
 */

declare(strict_types=1);

namespace Inriver\Adapter\Test\Unit\Model\Data;

use Inriver\Adapter\Api\Data\OperationResultInterfaceFactory;
use Inriver\Adapter\Logger\Logger;
use Inriver\Adapter\Model\Data\Import;
use Inriver\Adapter\Model\Data\OperationResult;
use Inriver\Adapter\Model\ImportExport\Import as MagentoImport;
use InvalidArgumentException;
use Magento\CatalogImportExport\Model\Import\Product\RowValidatorInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Event\Manager;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Filesystem;
use Magento\Framework\Filesystem\Directory\ReadFactory;
use Magento\Framework\Filesystem\Directory\WriteInterface;
use Magento\Framework\Filesystem\Io\File;
use Magento\ImportExport\Model\Import\Source\Csv;
use Magento\ImportExport\Model\Import\Source\CsvFactory;
use Magento\ImportExport\Model\Import\ErrorProcessing\ProcessingError;
use Magento\ImportExport\Model\Import\ErrorProcessing\ProcessingErrorAggregatorInterface;
use Magento\ImportExport\Model\ImportFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

use function __;

class ImportTest extends TestCase
{
    /** @var \Magento\ImportExport\Model\ImportFactory|\Inriver\Adapter\Test\Unit\Model\Data\MockObject */
    private $importFactory;

    /** @var \Inriver\Adapter\Logger\Logger|\Inriver\Adapter\Test\Unit\Model\Data\MockObject */
    private $logger;

    /** @var \Magento\Framework\Filesystem\Directory\ReadFactory|\Inriver\Adapter\Test\Unit\Model\Data\MockObject */
    private $readFactory;

    /** @var \Magento\ImportExport\Model\Import\Source\CsvFactory|\Inriver\Adapter\Test\Unit\Model\Data\MockObject */
    private $csvFactory;

    /** @var \Magento\Framework\App\Config\ScopeConfigInterface|\Inriver\Adapter\Test\Unit\Model\Data\MockObject */
    private $scopeConfig;

    /** @var \Magento\Framework\Filesystem\Io\File|\Inriver\Adapter\Test\Unit\Model\Data\MockObject */
    private $ioFile;

    /** @var \Magento\Framework\Event\Manager|\Inriver\Adapter\Test\Unit\Model\Data\MockObject */
    private $eventManager;

    /** @var \Inriver\Adapter\Api\Data\OperationResultInterfaceFactory|\Inriver\Adapter\Test\Unit\Model\Data\MockObject */
    private $operationResultFactory;

    /** @var \Magento\Framework\Filesystem|\Inriver\Adapter\Test\Unit\Model\Data\MockObject */
    protected $fs;

    private $directory;

    private $magentoImport;

    public function testFileDoesNotExist(): void
    {
        $this->ioFile->method('fileExists')->willReturn(false);
        $this->ioFile->method('getPathInfo')->willReturn([
            'basename' => '',
            'dirname' => '',
        ]);

        $import = $this->createImportMock();
        $this->importFactory->expects($this->never())->method('create');
        $this->eventManager->expects($this->once())->method('dispatch')->with(
            'inriver_treatment_import_validation_failure',
            ['import' => $import, 'filename' => 'NON_EXISTING_FILENAME']
        );
        $this->assertFalse($import->execute('NON_EXISTING_FILENAME'));
    }

    #[DataProvider('validationFailuresProvider')]
    public function testFileValidationFailed(?string $exceptionType): void
    {
        $import = $this->createConfiguredImport();
        $this->configureExistingFile();
        $validation = $this->magentoImport->expects($this->once())->method('validateSource');
        if ($exceptionType === 'localized') {
            $validation->willThrowException(new LocalizedException(__('Invalid CSV')));
        } elseif ($exceptionType === 'runtime') {
            $validation->willThrowException(new RuntimeException('Unreadable CSV'));
        } else {
            $validation->willReturn(false);
        }
        $this->magentoImport->expects($this->never())->method('importSource');
        $this->magentoImport->expects($this->never())->method('invalidateIndex');
        $this->directory->expects($this->once())->method('renameFile')
            ->with('/var/inriver/import.csv', '/var/inriver/archives/errors/import.csv');
        $this->eventManager->expects($this->once())->method('dispatch')->with(
            'inriver_treatment_import_validation_failure',
            ['import' => $import, 'filename' => '/var/inriver/import.csv']
        );

        $this->assertFalse($import->execute('/var/inriver/import.csv'));
    }

    public static function validationFailuresProvider(): array
    {
        return [
            'invalid data' => [null],
            'localized exception' => ['localized'],
            'unexpected exception' => ['runtime'],
        ];
    }

    public function testGetFormattedLogTrace(): void
    {
        $import = $this->createConfiguredImport();
        $this->magentoImport->expects($this->once())->method('getFormatedLogTrace')->willReturn('Rows processed: 5');

        $this->assertSame('Rows processed: 5', $import->getFormattedLogTrace());
    }

    public function testGetErrors(): void
    {
        $errors = [$this->createProcessingError('invalidSku', 'sku', 'Missing SKU', 0)];
        $import = $this->createConfiguredImport($errors);

        $this->assertSame($errors, $import->getErrors());
        $this->assertSame($errors, $import->getErrors());
    }

    public function testGetErrorsWithoutProcessingErrors(): void
    {
        $this->assertSame([], $this->createConfiguredImport()->getErrors());
    }

    #[DataProvider('importOutcomesProvider')]
    public function testExecuteArchivesFileAndDispatchesEvents(bool $result, bool $hasErrors): void
    {
        $errors = $hasErrors ? [$this->createProcessingError('invalidSku', 'sku', 'Missing SKU', 0)] : [];
        $import = $this->createConfiguredImport($errors);
        $this->configureExistingFile();
        $this->magentoImport->method('validateSource')->willReturn(true);
        $this->magentoImport->expects($this->once())->method('importSource')->willReturn($result);
        $this->magentoImport->method('getFormatedLogTrace')->willReturn('Rows processed: 1');
        $this->magentoImport->expects($result ? $this->once() : $this->never())->method('invalidateIndex');
        $state = $result && !$hasErrors ? Import::SUCCESS_FOLDER : Import::ERRORS_FOLDER;
        $this->directory->expects($this->once())->method('renameFile')->with(
            '/var/inriver/import.csv',
            '/var/inriver/archives/' . $state . '/import.csv'
        );
        if ($result && $hasErrors) {
            $this->eventManager->expects($this->never())->method('dispatch');
        } else {
            $this->eventManager->expects($this->once())->method('dispatch')->with(
                $result ? 'inriver_treatment_after_import_success' : 'inriver_treatment_after_import_failure',
                ['import' => $import, 'filename' => '/var/inriver/import.csv']
            );
        }

        $this->assertSame($result, $import->execute('/var/inriver/import.csv'));
    }

    public static function importOutcomesProvider(): array
    {
        return [
            'success' => [true, false],
            'partial success' => [true, true],
            'failure without details' => [false, false],
            'failure with processing errors' => [false, true],
        ];
    }

    #[DataProvider('importExceptionsProvider')]
    public function testExecuteHandlesImportExceptions(string $exceptionType): void
    {
        $import = $this->createConfiguredImport();
        $this->configureExistingFile();
        $this->magentoImport->method('validateSource')->willReturn(true);
        $exception = $exceptionType === 'invalid_argument'
            ? new InvalidArgumentException('Invalid source')
            : new LocalizedException(__('Import failed'));
        $this->magentoImport->expects($this->once())->method('importSource')->willThrowException($exception);
        $this->magentoImport->method('getFormatedLogTrace')->willReturn('');
        $this->magentoImport->expects($this->never())->method('invalidateIndex');
        $this->directory->expects($this->once())->method('renameFile')
            ->with('/var/inriver/import.csv', '/var/inriver/archives/errors/import.csv');
        $this->eventManager->expects($this->once())->method('dispatch')->with(
            $exceptionType === 'invalid_argument'
                ? 'inriver_treatment_import_exception'
                : 'inriver_treatment_after_import_failure',
            ['import' => $import, 'filename' => '/var/inriver/import.csv', 'exception' => $exception]
        );

        $this->assertFalse($import->execute('/var/inriver/import.csv'));
    }

    public static function importExceptionsProvider(): array
    {
        return [
            'invalid source' => ['invalid_argument'],
            'localized failure' => ['localized'],
        ];
    }

    #[DataProvider('disableImportProvider')]
    public function testGetErrorsAsArrayGroupsRowsAndFiltersDisabledProductType(bool $disableImport): void
    {
        $errors = [
            $this->createProcessingError('invalidValue', 'sku', 'Invalid value', 0),
            $this->createProcessingError('invalidValue', 'sku', 'Invalid value', 2),
            $this->createProcessingError('invalidValue', 'qty', 'Invalid value', 1),
            $this->createProcessingError('missingValue', 'sku', 'Invalid value', 3),
            $this->createProcessingError('invalidValue', 'sku', 'Different message', 4),
            $this->createProcessingError(RowValidatorInterface::ERROR_INVALID_TYPE, 'product_type', 'Invalid type', 5),
        ];
        $import = $this->createConfiguredImport($errors);
        $this->magentoImport->method('isImportTypeDisable')->willReturn($disableImport);
        $this->operationResultFactory->expects($this->exactly($disableImport ? 4 : 5))->method('create')
            ->willReturnCallback(static fn () => new OperationResult());

        $result = $import->getErrorsAsArray();

        $this->assertCount($disableImport ? 4 : 5, $result);
        foreach ([0, 2, 3, 4, 5] as $errorIndex) {
            $error = $errors[$errorIndex];
            $key = base64_encode($error->getErrorCode() . $error->getColumnName() . $error->getErrorMessage());
            if ($disableImport && $errorIndex === 5) {
                $this->assertArrayNotHasKey($key, $result);
                continue;
            }
            $this->assertArrayHasKey($key, $result);
            $this->assertSame($error->getErrorCode(), $result[$key]->getErrorCode());
            $this->assertSame($error->getColumnName(), $result[$key]->getColumnName());
            $this->assertSame($error->getErrorMessage(), $result[$key]->getErrorMessage());
            $this->assertSame($errorIndex === 0 ? [1, 3] : [$error->getRowNumber() + 1], $result[$key]->getRowNumbers());
        }
    }

    public static function disableImportProvider(): array
    {
        return [
            'regular import' => [false],
            'disable import' => [true],
        ];
    }

    public function testGetErrorsAsArrayWithoutErrors(): void
    {
        $import = $this->createConfiguredImport();
        $this->operationResultFactory->expects($this->never())->method('create');

        $this->assertSame([], $import->getErrorsAsArray());
    }

    protected function setUp(): void
    {
        $this->importFactory = $this->createMock(ImportFactory::class);
        $this->logger = $this->createMock(Logger::class);
        $this->readFactory = $this->createMock(ReadFactory::class);
        $this->csvFactory = $this->createMock(CsvFactory::class);
        $this->scopeConfig = $this->createMock(ScopeConfigInterface::class);
        $this->ioFile = $this->createMock(File::class);
        $this->eventManager = $this->createMock(Manager::class);
        $this->operationResultFactory = $this->createMock(OperationResultInterfaceFactory::class);
        $directory = $this->createMock(WriteInterface::class);
        $this->directory = $directory;
        $this->fs = $this->createMock(Filesystem::class);
        $this->fs->method('getDirectoryWrite')->willReturn($directory);
    }

    private function createConfiguredImport(array $errors = []): Import
    {
        $this->magentoImport = $this->createMock(MagentoImport::class);
        $this->magentoImport->method('setData')->willReturnSelf();
        $aggregator = $this->createStub(ProcessingErrorAggregatorInterface::class);
        $aggregator->method('getAllErrors')->willReturn($errors);
        $this->magentoImport->method('getErrorAggregator')->willReturn($aggregator);
        $this->importFactory->expects($this->once())->method('create')->willReturn($this->magentoImport);
        $import = $this->createImportMock();
        $import->setManagedWebsites('');

        return $import;
    }

    private function configureExistingFile(): void
    {
        $this->ioFile->method('fileExists')->willReturn(true);
        $this->ioFile->method('getPathInfo')->willReturn([
            'basename' => 'import.csv',
            'dirname' => '/var/inriver',
        ]);
        $this->csvFactory->method('create')->willReturn($this->createStub(Csv::class));
    }

    private function createProcessingError(string $code, string $column, string $message, int $row): ProcessingError
    {
        $error = new ProcessingError();
        $error->init($code, ProcessingError::ERROR_LEVEL_NOT_CRITICAL, $row, $column, $message, 'Invalid CSV row');

        return $error;
    }

    private function createImportMock(): Import
    {
        return new Import(
            $this->importFactory,
            $this->logger,
            $this->readFactory,
            $this->csvFactory,
            $this->scopeConfig,
            $this->ioFile,
            $this->eventManager,
            $this->operationResultFactory,
            $this->fs
        );
    }
}
