<?php

/**
 * @author InRiver <inriveradapters@inriver.com>
 * @copyright Copyright (c) InRiver (https://www.inriver.com/)
 * @link https://www.inriver.com/
 * @noinspection PhpUnhandledExceptionInspection
 */

declare(strict_types=1);

namespace Inriver\Adapter\Test\Unit\Model\Operation;

use Exception;
use Inriver\Adapter\Api\Data\InriverMediaGalleryDataInterface;
use Inriver\Adapter\Api\InriverMediaGalleryDataRepositoryInterface;
use Inriver\Adapter\Helper\ErrorCodesDirectory;
use Inriver\Adapter\Helper\FileDownloader;
use Inriver\Adapter\Logger\Logger;
use Inriver\Adapter\Model\Data\InriverMediaGalleryData;
use Inriver\Adapter\Model\Data\InriverMediaGalleryDataFactory;
use Inriver\Adapter\Model\Data\ProductImages;
use Inriver\Adapter\Model\Data\ProductImages\Image;
use Inriver\Adapter\Model\Data\ProductImages\Images\Attributes;
use Inriver\Adapter\Model\MediaGallery\MediaGalleryManagement;
use Inriver\Adapter\Model\Operation\ProductImagesOperation;
use Magento\Catalog\Api\Data\ProductAttributeMediaGalleryEntryInterface;
use Magento\Catalog\Api\Data\ProductAttributeMediaGalleryEntryInterfaceFactory;
use Magento\Catalog\Api\Data\ProductInterface;
use Magento\Catalog\Model\ProductRepository;
use Magento\CatalogInventory\Model\StockRegistryStorage;
use Magento\Framework\Api\Data\ImageContentInterface;
use Magento\Framework\Api\Data\ImageContentInterfaceFactory;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Filesystem;
use Magento\Framework\Filesystem\Directory\ReadInterface;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

use function __;

class ProductImagesOperationTest extends TestCase
{
    private const AN_EXISTING_IMAGE_ID = 'an-existing-image-id';
    private const THE_SKU = 'the-sku';

    /** @var \Inriver\Adapter\Helper\FileDownloader|\Inriver\Adapter\Test\Unit\Model\Operation\MockObject */
    private $downloader;

    /** @var \Magento\Framework\Filesystem|\Inriver\Adapter\Test\Unit\Model\Operation\MockObject */
    private $filesystem;

    /** @var \Magento\Catalog\Api\Data\ProductAttributeMediaGalleryEntryInterfaceFactory|\Inriver\Adapter\Test\Unit\Model\Operation\MockObject */
    private $galleryEntryFactory;

    /** @var \Magento\Framework\Api\Data\ImageContentInterfaceFactory|\Inriver\Adapter\Test\Unit\Model\Operation\MockObject */
    private $imageContentFactory;

    /** @var \Magento\Store\Model\StoreManagerInterface|\Inriver\Adapter\Test\Unit\Model\Operation\MockObject */
    private $storeManager;

    /** @var \Magento\Catalog\Model\ProductRepository|\Inriver\Adapter\Test\Unit\Model\Operation\MockObject */
    private $productRepository;

    /** @var \Inriver\Adapter\Api\InriverMediaGalleryDataRepositoryInterface|\Inriver\Adapter\Test\Unit\Model\Operation\MockObject */
    private $inriverMediaGalleryDataRepository;

    /** @var \Inriver\Adapter\Model\Data\InriverMediaGalleryDataFactory|\Inriver\Adapter\Test\Unit\Model\Operation\MockObject */
    private $inriverMediaGalleryDataFactory;

    /** @var \Inriver\Adapter\Model\MediaGallery\MediaGalleryManagement|\Inriver\Adapter\Test\Unit\Model\Operation\MockObject */
    private $mediaGalleryManagement;

    /** @var \Magento\CatalogInventory\Model\StockRegistryStorage|\Inriver\Adapter\Test\Unit\Model\Operation\MockObject */
    private $stockRegistryStorage;

    /** @var \Inriver\Adapter\Logger\Logger|\Inriver\Adapter\Test\Unit\Model\Operation\MockObject */
    private $logger;

    public function testMessageWithoutData(): void
    {
        $subject = $this->getSubject();

        /** @var \Inriver\Adapter\Model\Data\ProductImages|\Inriver\Adapter\Test\Unit\Model\Operation\MockObject $productImages */
        $productImages = $this->createMock(ProductImages::class);

        $this->mediaGalleryManagement->expects($this->once())->method('getList')->willReturn([]);
        $subject->post($productImages);
    }

    public function testInriverImagesAreDeleted(): void
    {
        $subject = $this->getSubject();

        /** @var \Inriver\Adapter\Model\Data\ProductImages|\Inriver\Adapter\Test\Unit\Model\Operation\MockObject $productImages */
        $productImages = $this->createMock(ProductImages::class);

        $entry = $this->createMock(ProductAttributeMediaGalleryEntryInterface::class);

        $inriverMediaGalleryData = $this->createMock(InriverMediaGalleryDataInterface::class);
        $inriverMediaGalleryData->expects($this->once())->method('getImageId')->willReturn(null);

        $this->inriverMediaGalleryDataRepository
            ->expects($this->once())
            ->method('getById')
            ->willReturn($inriverMediaGalleryData);

        $this->mediaGalleryManagement->expects($this->once())->method('getList')->willReturn([$entry]);
        $this->mediaGalleryManagement->expects($this->once())->method('deleteGallery');

        $subject->post($productImages);
    }

    public function testNonExistingInriverImagesAreDeleted(): void
    {
        $subject = $this->getSubject();

        /** @var \Inriver\Adapter\Model\Data\ProductImages|\Inriver\Adapter\Test\Unit\Model\Operation\MockObject $productImages */
        $productImages = $this->createMock(ProductImages::class);

        $entry = $this->createMock(ProductAttributeMediaGalleryEntryInterface::class);

        $inriverMediaGalleryData = $this->createMock(InriverMediaGalleryDataInterface::class);
        $inriverMediaGalleryData
            ->expects($this->once())
            ->method('getImageId')
            ->willReturn('an-image-id');

        $this->inriverMediaGalleryDataRepository
            ->expects($this->once())
            ->method('getById')
            ->willReturn($inriverMediaGalleryData);

        $this->mediaGalleryManagement->expects($this->once())->method('getList')->willReturn([$entry]);
        $this->mediaGalleryManagement->expects($this->once())->method('deleteGallery');

        $subject->post($productImages);
    }

    #[DataProvider('imageRoleStoreScopesProvider')]
    public function testUpdateExistingEntriesWithoutExistingStoreConfiguration(
        bool $singleStore,
        int $roleStoreId,
        array $requiredStoreIds
    ): void
    {
        $subject = $this->getSubject();
        $this->storeManager->expects($this->once())->method('hasSingleStore')->willReturn($singleStore);
        $product = $this->createMock(ProductInterface::class);
        $removedStores = $singleStore ? [] : [10, 11];
        $value = '/i/n/test.jpg';

        $attribute = new Attributes();
        $attribute->setPosition(1);
        $attribute->setStoreViews(['a-store-view']);
        $attribute->setRoles(['a-role']);

        $image = new Image();
        $image->setImageId(self::AN_EXISTING_IMAGE_ID);
        $image->setAttributes([$attribute]);

        $message = new ProductImages();
        $message->setSku(self::THE_SKU);
        $message->setImages([$image]);

        $entry = $this->createMock(ProductAttributeMediaGalleryEntryInterface::class);
        $entry->method('getId')->willReturn(0);

        $inriverMediaGalleryData = $this->createMock(InriverMediaGalleryDataInterface::class);
        $inriverMediaGalleryData
            ->expects($this->once())
            ->method('getImageId')
            ->willReturn(self::AN_EXISTING_IMAGE_ID);

        $this->inriverMediaGalleryDataRepository
            ->expects($this->once())
            ->method('getById')
            ->willReturn($inriverMediaGalleryData);

        $this->mediaGalleryManagement->expects($this->once())->method('getList')->willReturn([$entry]);
        $this->mediaGalleryManagement->expects($this->once())->method('getMediaGalleryEntity')->willReturn($value);
        $this->mediaGalleryManagement->expects($this->never())->method('deleteGallery');
        $this->mediaGalleryManagement->expects($this->once())->method('insertGalleryValueInStore');
        $this->mediaGalleryManagement->expects($this->once())->method('updateImageTypes')
            ->with(['a-role' => 0], $value, $product, $roleStoreId);
        $this->mediaGalleryManagement->expects($this->exactly(count($removedStores)))->method('deleteExistingTypesForStore');
        $this->mediaGalleryManagement->expects($this->exactly(count($removedStores) + 1))->method('deleteGalleryValueInStore');

        $this->mediaGalleryManagement
            ->expects($this->once())
            ->method('getExistingStoreEntries')
            ->with($product, $requiredStoreIds, $entry)
            ->willReturn($removedStores);

        $storeEntry = $this->createMock(ProductAttributeMediaGalleryEntryInterface::class);
        $storeEntry->method('getTypes')->willReturn([]);
        $storeEntry->method('getPosition')->willReturn(2);

        $this->mediaGalleryManagement
            ->method('getStoreEntry')
            ->willReturn($storeEntry);

        $store = $this->createMock(StoreInterface::class);
        $store->method('getId')->willReturn(1);

        $this->storeManager->method('getStore')->willReturn($store);

        $this->productRepository->method('get')->willReturn($product);

        $subject->post($message);
    }

    public static function imageRoleStoreScopesProvider(): array
    {
        return [
            'single-store roles use admin scope' => [true, 0, [1, 0]],
            'multi-store roles use storefront scope' => [false, 1, [1]],
        ];
    }

    public function testCreateNewMediaEntry(): void
    {
        $subject = $this->getSubject();

        $message = $this->getBasicMessage();

        $this->createBasicTestConfiguration();

        $this->downloader->expects($this->once())->method('getRemoteFileContent');

        $subject->post($message);
    }

    public function testCreateNewMediaEntryFromLocalImage(): void
    {
        $subject = $this->getSubject();

        $message = $this->getBasicMessage();

        $this->createBasicTestConfiguration();

        $this->downloader->expects($this->never())->method('getRemoteFileContent');

        $read = $this->createMock(ReadInterface::class);
        $read->expects($this->once())->method('readFile')->willReturn('string-content');

        $this->filesystem->expects($this->once())->method('getDirectoryRead')
            ->willReturn($read);

        $inriverMediaGalleryData = $this->createMock(InriverMediaGalleryDataInterface::class);
        $inriverMediaGalleryData->method('getValueId')->willReturn(50);

        $this->inriverMediaGalleryDataRepository
            ->method('getFirstByImageId')
            ->willReturn($inriverMediaGalleryData);

        $subject->post($message);
    }

    public function testCannotDownloadRemoteImage(): void
    {
        $subject = $this->getSubject();

        $message = $this->getBasicMessage();

        $this->createBasicTestConfiguration();

        $this->downloader
            ->expects($this->once())
            ->method('getRemoteFileContent')
            ->willThrowException(new Exception(''));

        $errors = $subject->post($message);
        $this->assertEquals(ErrorCodesDirectory::CANNOT_DOWNLOAD_MEDIA_FILE, $errors[0]['error_code']);
    }

    public function testCannotReadLocalImage(): void
    {
        $subject = $this->getSubject();

        $message = $this->getBasicMessage();

        $this->createBasicTestConfiguration();

        $this->downloader->expects($this->never())->method('getRemoteFileContent');

        $read = $this->createMock(ReadInterface::class);
        $read->expects($this->once())->method('readFile')->willThrowException(new Exception(''));

        $this->filesystem->expects($this->once())->method('getDirectoryRead')
            ->willReturn($read);

        $inriverMediaGalleryData = $this->createMock(InriverMediaGalleryDataInterface::class);
        $inriverMediaGalleryData->method('getValueId')->willReturn(50);

        $this->inriverMediaGalleryDataRepository
            ->method('getFirstByImageId')
            ->willReturn($inriverMediaGalleryData);

        $errors = $subject->post($message);
        $this->assertEquals(ErrorCodesDirectory::CANNOT_READ_LOCAL_MEDIA_FILE, $errors[0]['error_code']);
    }

    public function testSkuDoesNotExists(): void
    {
        $subject = $this->getSubject();

        $exception = new NoSuchEntityException(
            __(),
            null,
            ErrorCodesDirectory::SKU_NOT_FOUND
        );

        $this->mediaGalleryManagement->method('getList')->willThrowException($exception);

        /** @var \Inriver\Adapter\Model\Data\ProductImages|\Inriver\Adapter\Test\Unit\Model\Operation\MockObject $productImages */
        $productImages = $this->createMock(ProductImages::class);

        $this->expectExceptionCode(ErrorCodesDirectory::SKU_NOT_FOUND);
        $subject->post($productImages);
    }

    protected function setUp(): void
    {
        $this->downloader = $this->createMock(FileDownloader::class);
        $this->filesystem = $this->createMock(Filesystem::class);
        $this->galleryEntryFactory = $this->createMock(ProductAttributeMediaGalleryEntryInterfaceFactory::class);
        $this->imageContentFactory = $this->createMock(ImageContentInterfaceFactory::class);
        $this->storeManager = $this->createMock(StoreManagerInterface::class);
        $this->productRepository = $this->createMock(ProductRepository::class);
        $this->inriverMediaGalleryDataRepository = $this->createMock(InriverMediaGalleryDataRepositoryInterface::class);
        $this->inriverMediaGalleryDataFactory = $this->createMock(InriverMediaGalleryDataFactory::class);
        $this->mediaGalleryManagement = $this->createMock(MediaGalleryManagement::class);
        $this->stockRegistryStorage = $this->createMock(StockRegistryStorage::class);
        $this->logger = $this->createMock(Logger::class);
    }

    private function getSubject(): ProductImagesOperation
    {
        /** @noinspection PhpUnhandledExceptionInspection */
        return new ProductImagesOperation(
            $this->downloader,
            $this->filesystem,
            $this->galleryEntryFactory,
            $this->imageContentFactory,
            $this->storeManager,
            $this->productRepository,
            $this->inriverMediaGalleryDataRepository,
            $this->inriverMediaGalleryDataFactory,
            $this->mediaGalleryManagement,
            $this->stockRegistryStorage,
            $this->logger
        );
    }

    /**
     * @return \Inriver\Adapter\Model\Data\ProductImages
     */
    private function getBasicMessage(): ProductImages
    {
        $image = new Image();
        $image
            ->setImageId(self::AN_EXISTING_IMAGE_ID)
            ->setAttributes([])
            ->setUrl('https://example.com')
            ->setMimeType('a-mime-type')
            ->setFilename('a-file-name');

        $message = new ProductImages();
        $message->setSku(self::THE_SKU);
        $message->setImages([$image]);

        return $message;
    }

    private function createBasicTestConfiguration(): void
    {
        $this->mediaGalleryManagement->expects($this->once())->method('getList')->willReturn([]);

        $imageContent = $this->createMock(ImageContentInterface::class);
        $this->imageContentFactory->method('create')->willReturn($imageContent);

        $galleryEntry = $this->createMock(ProductAttributeMediaGalleryEntryInterface::class);
        $this->galleryEntryFactory->method('create')->willReturn($galleryEntry);

        $inriverGalleryData = $this->createMock(InriverMediaGalleryData::class);
        $this->inriverMediaGalleryDataFactory->method('create')->willReturn($inriverGalleryData);

        $store = $this->createMock(StoreInterface::class);
        $store->method('getId')->willReturn(1);

        $this->storeManager->method('getStore')->willReturn($store);

        $this->productRepository->method('get')->willReturn(
            $this->createMock(ProductInterface::class)
        );
    }
}
