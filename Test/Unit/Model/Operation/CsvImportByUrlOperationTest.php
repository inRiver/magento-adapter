<?php

/**
 * @author InRiver <inriveradapters@inriver.com>
 * @copyright Copyright (c) InRiver (https://www.inriver.com/)
 * @link https://www.inriver.com/
 * @noinspection PhpUnhandledExceptionInspection
 */

declare(strict_types=1);

namespace Inriver\Adapter\Test\Unit\Model\Operation;

use Inriver\Adapter\Api\Data\OperationResultInterface;
use Inriver\Adapter\Api\Data\ProductsImportRequestInterface;
use Inriver\Adapter\Helper\ErrorCodesDirectory;
use Inriver\Adapter\Helper\FileDownloader;
use Inriver\Adapter\Helper\FileEncoding;
use Inriver\Adapter\Helper\Import as InriverImportHelper;
use Inriver\Adapter\Model\Data\Import;
use Inriver\Adapter\Model\Data\ImportFactory;
use Inriver\Adapter\Model\Operation\CsvImportByUrlOperation;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Filesystem;
use Magento\Framework\Filesystem\Directory\ReadInterface;
use Magento\Store\Api\WebsiteRepositoryInterface;
use Magento\Store\Api\Data\WebsiteInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class CsvImportByUrlOperationTest extends TestCase
{
    public const INRIVER_IMPORT_CSV_PATH = 'inriver/import/csv';
    private const SOME_URL = 'https://iifma.test';
    private const FAKE_PATH = '/var/import.csv';

    /** @var \Magento\Framework\App\Config\ScopeConfigInterface|\Inriver\Adapter\Test\Unit\Model\Operation\MockObject */
    private $scopeConfig;

    /** @var \Inriver\Adapter\Model\Data\ImportFactory|\Inriver\Adapter\Test\Unit\Model\Operation\MockObject */
    private $importFactory;

    /** @var \Inriver\Adapter\Helper\FileDownloader|\Inriver\Adapter\Test\Unit\Model\Operation\MockObject */
    private $downloader;

    /** @var \Magento\Framework\Filesystem|\Inriver\Adapter\Test\Unit\Model\Operation\MockObject */
    private $filesystem;

    /** @var \Inriver\Adapter\Helper\FileEncoding|\Inriver\Adapter\Test\Unit\Model\Operation\MockObject */
    private $fileEncoding;

    /** @var \Inriver\Adapter\Api\Data\ProductsImportRequestInterface|\Inriver\Adapter\Test\Unit\Model\Operation\MockObject */
    private $message;

    /** @var \Magento\Store\Api\WebsiteRepositoryInterface|\Inriver\Adapter\Test\Unit\Model\Operation\MockObject */
    private $websiteRepository;

    private $import;

    public function testMissingTargetDirectoryConfiguration(): void
    {
        $csvImportByUrlOperation = $this->getNewSubject();
        $this->downloader->expects($this->never())->method('download');
        $this->importFactory->expects($this->never())->method('create');

        $this->expectExceptionCode(ErrorCodesDirectory::INRIVER_IMPORT_PATH_NOT_SET);
        $csvImportByUrlOperation->post($this->message);
    }

    public function testEmptyCsvFile(): void
    {
        $this->scopeConfig->method('getValue')->willReturnCallback(static function ($path) {
            if ($path === Import::XML_INRIVER_IMPORT_PATH_CSV) {
                return CsvImportByUrlOperationTest::INRIVER_IMPORT_CSV_PATH;
            }

            return null;
        });

        $this->downloader->method('download')->willReturn(0);
        $this->fileEncoding->expects($this->never())->method('removeUtf8Bom');
        $this->importFactory->expects($this->never())->method('create');

        $csvImportByUrlOperation = $this->getNewSubject();

        $this->expectExceptionCode(ErrorCodesDirectory::SOURCE_CSV_FILE_EMPTY);
        $csvImportByUrlOperation->post($this->message);
    }

    #[DataProvider('importRequestsProvider')]
    public function testProcess(string $importPath, ?array $websiteIds, bool $hasErrors): void
    {
        $this->configureDownloadedFile($importPath);
        $this->message->method('getManagedWebsites')->willReturn(
            $websiteIds === null ? null : array_keys($websiteIds)
        );
        $this->websiteRepository->expects($this->exactly(count($websiteIds ?? [])))->method('get')
            ->willReturnCallback(function (string $code) use ($websiteIds) {
                $this->assertArrayHasKey($code, $websiteIds);
                $website = $this->createStub(WebsiteInterface::class);
                $website->method('getId')->willReturn($websiteIds[$code]);
                return $website;
            });
        $errors = $hasErrors ? ['error' => $this->createStub(OperationResultInterface::class)] : [];
        $this->fileEncoding->expects($this->once())->method('removeUtf8Bom')->with(self::FAKE_PATH);
        $this->import->expects($this->once())->method('setManagedWebsites')
            ->with(implode(',', $websiteIds ?? []));
        $this->import->expects($this->once())->method('setInriverImportType')
            ->with(InriverImportHelper::INRIVER_IMPORT_TYPE_PRODUCTS);
        $this->import->expects($this->once())->method('execute')->with(self::FAKE_PATH)->willReturn(true);
        $this->import->expects($this->once())->method('getErrorsAsArray')->willReturn($errors);

        $this->assertSame($errors, $this->getNewSubject()->post($this->message));
    }

    public static function importRequestsProvider(): array
    {
        return [
            'all websites' => [self::INRIVER_IMPORT_CSV_PATH, null, false],
            'empty website selection' => [self::INRIVER_IMPORT_CSV_PATH, [], false],
            'selected websites' => [self::INRIVER_IMPORT_CSV_PATH, ['base' => 1, 'second' => 3], false],
            'leading slash and import errors' => ['/' . self::INRIVER_IMPORT_CSV_PATH, ['base' => 1], true],
        ];
    }

    public function testDownloadFailureDoesNotStartImport(): void
    {
        $this->scopeConfig->method('getValue')->willReturn(self::INRIVER_IMPORT_CSV_PATH);
        $exception = new RuntimeException('Download failed');
        $this->downloader->expects($this->once())->method('download')->willThrowException($exception);
        $this->fileEncoding->expects($this->never())->method('removeUtf8Bom');
        $this->importFactory->expects($this->never())->method('create');
        $this->expectExceptionObject($exception);

        $this->getNewSubject()->post($this->message);
    }

    public function testUnknownWebsiteDoesNotStartImport(): void
    {
        $this->configureDownloadedFile(self::INRIVER_IMPORT_CSV_PATH);
        $this->message->method('getManagedWebsites')->willReturn(['unknown']);
        $exception = new NoSuchEntityException();
        $this->websiteRepository->expects($this->once())->method('get')->with('unknown')
            ->willThrowException($exception);
        $this->importFactory->expects($this->never())->method('create');
        $this->expectExceptionObject($exception);

        $this->getNewSubject()->post($this->message);
    }

    public function testEncodingFailureDoesNotStartImport(): void
    {
        $this->configureDownloadedFile(self::INRIVER_IMPORT_CSV_PATH);
        $exception = new RuntimeException('Cannot normalize CSV encoding');
        $this->fileEncoding->expects($this->once())->method('removeUtf8Bom')->with(self::FAKE_PATH)
            ->willThrowException($exception);
        $this->websiteRepository->expects($this->never())->method('get');
        $this->importFactory->expects($this->never())->method('create');
        $this->expectExceptionObject($exception);

        $this->getNewSubject()->post($this->message);
    }

    protected function setUp(): void
    {
        $this->scopeConfig = $this->createMock(ScopeConfigInterface::class);
        $this->import = $this->createMock(Import::class);
        $this->importFactory = $this->createMock(ImportFactory::class);
        $this->importFactory->method('create')->willReturn($this->import);
        $this->downloader = $this->createMock(FileDownloader::class);
        $this->filesystem = $this->createMock(Filesystem::class);
        $this->fileEncoding = $this->createMock(FileEncoding::class);

        $this->message = $this->createMock(ProductsImportRequestInterface::class);
        $this->message->method('getUrl')->willReturn(self::SOME_URL);

        $this->websiteRepository = $this->createMock(WebsiteRepositoryInterface::class);
    }

    private function configureDownloadedFile(string $importPath): void
    {
        $this->scopeConfig->expects($this->once())->method('getValue')
            ->with(Import::XML_INRIVER_IMPORT_PATH_CSV)->willReturn($importPath);
        $destination = $this->matchesRegularExpression(
            '#^' . preg_quote(ltrim($importPath, '/'), '#') . '/import-\d{14}\.csv$#'
        );
        $this->downloader->expects($this->once())->method('download')
            ->with(self::SOME_URL, $destination)->willReturn(10);
        $output = $this->createMock(ReadInterface::class);
        $output->expects($this->once())->method('getAbsolutePath')->with($destination)->willReturn(self::FAKE_PATH);
        $this->filesystem->expects($this->once())->method('getDirectoryRead')
            ->with(DirectoryList::VAR_DIR)->willReturn($output);
    }

    private function getNewSubject(): CsvImportByUrlOperation
    {
        return new CsvImportByUrlOperation(
            $this->scopeConfig,
            $this->importFactory,
            $this->downloader,
            $this->filesystem,
            $this->fileEncoding,
            $this->websiteRepository
        );
    }
}
