<?php

/** @noinspection MessDetectorValidationInspection */

/**
 * @author InRiver <inriveradapters@inriver.com>
 * @copyright Copyright (c) InRiver (https://www.inriver.com/)
 * @link https://www.inriver.com/
 */

declare(strict_types=1);

namespace Inriver\Adapter\Test\Unit\Model\MediaGallery;

use Inriver\Adapter\Model\MediaGallery\MediaGalleryManagement;
use Magento\Catalog\Api\Data\ProductAttributeMediaGalleryEntryInterface;
use Magento\Catalog\Api\ProductAttributeMediaGalleryManagementInterface;
use Magento\Catalog\Model\Product;
use Magento\Catalog\Model\Product\Action;
use Magento\Catalog\Model\ProductRepository;
use Magento\Catalog\Model\ResourceModel\Product\Gallery;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Select;
use Magento\Framework\EntityManager\EntityMetadataInterface;
use Magento\Framework\EntityManager\MetadataPool;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class MediaGalleryManagementTest extends TestCase
{
    /** @var \Magento\Framework\App\ResourceConnection|\Inriver\Adapter\Test\Unit\Model\MediaGallery\MockObject */
    private $resourceConnection;

    /** @var \Magento\Catalog\Model\ProductRepository|\Inriver\Adapter\Test\Unit\Model\MediaGallery\MockObject */
    private $productRepository;

    /** @var \Magento\Framework\EntityManager\MetadataPool|\Inriver\Adapter\Test\Unit\Model\MediaGallery\MockObject */
    private $metadataPool;

    /** @var \Magento\Catalog\Model\Product\Action|\Inriver\Adapter\Test\Unit\Model\MediaGallery\MockObject */
    private $action;

    /** @var \Magento\Catalog\Model\ResourceModel\Product\Gallery|\Inriver\Adapter\Test\Unit\Model\MediaGallery\MockObject */
    private $resourceModel;

    /** @var \Magento\Catalog\Api\ProductAttributeMediaGalleryManagementInterface|\Inriver\Adapter\Test\Unit\Model\MediaGallery\MockObject */
    private $galleryManagement;

    private $adapter;

    private $metadata;

    public function testGetEntryValueById(): void
    {
        $subject = $this->getSubject();
        $result = $subject->getEntryValueById(1);

        $this->assertEquals('string', $result);
    }

    #[DataProvider('linkFieldsProvider')]
    public function testInsertGalleryValueInStore(string $linkField): void
    {
        $this->metadata->method('getLinkField')->willReturn($linkField);
        $subject = $this->getSubject();

        $product = $this->createMock(Product::class);
        $product->expects($this->once())->method('getData')->with($linkField)->willReturn('42');
        $this->resourceModel->expects($this->once())->method('insertGalleryValueInStore')->with([
            'value_id' => 10,
            'store_id' => 2,
            'position' => 3,
            $linkField => 42,
        ]);

        $subject->insertGalleryValueInStore(10, 2, 3, $product);
    }

    public static function linkFieldsProvider(): array
    {
        return [
            'Magento Open Source' => ['entity_id'],
            'Adobe Commerce staging' => ['row_id'],
        ];
    }

    public function testGetStoreEntry(): void
    {
        $subject = $this->getSubject();

        $entryId = 10;

        $productAttributeMediaGalleryEntry = $this->createMock(ProductAttributeMediaGalleryEntryInterface::class);
        $productAttributeMediaGalleryEntry->method('getId')->willReturn($entryId);

        /** @var \Inriver\Adapter\Test\Unit\Model\MediaGallery\ProductInterface|\Inriver\Adapter\Test\Unit\Model\MediaGallery\MockObject $product */
        $product = $this->createMock(Product::class);
        $product->method('getMediaGalleryEntries')->willReturn([$productAttributeMediaGalleryEntry]);

        $this->productRepository->method('get')->willReturn($product);

        /** @noinspection PhpUnhandledExceptionInspection */
        $result = $subject->getStoreEntry(
            '',
            0,
            $entryId
        );

        $this->assertEquals($result, $productAttributeMediaGalleryEntry);

        /** @noinspection PhpUnhandledExceptionInspection */
        $nullResult = $subject->getStoreEntry(
            '',
            0,
            20
        );

        $this->assertNull($nullResult);
    }

    public function testGetAttributeIdsByFrontendType(): void
    {
        $this->adapter->method('fetchAssoc')->willReturn([]);
        $subject = $this->getSubject();
        $result = $subject->getAttributeIdsByFrontendType('front-end-type');

        $this->assertEquals([], $result);
    }

    public function testUpdateImageTypes(): void
    {
        $subject = $this->getSubject();

        $types = ['image' => true, 'thumbnail' => true, 'unknown_attribute' => true];
        $this->adapter->expects($this->once())->method('fetchAssoc')->willReturn([
            'image' => ['attribute_id' => '85'],
            'thumbnail' => ['attribute_id' => '86'],
        ]);
        $product = $this->createMock(Product::class);
        $product->method('getId')->willReturn(42);
        $this->adapter->expects($this->once())->method('delete');
        $this->action->expects($this->once())->method('updateAttributes')->with(
            [42],
            ['image' => 'the-value', 'thumbnail' => 'the-value'],
            2
        );

        $subject->updateImageTypes(
            $types,
            'the-value',
            $product,
            2
        );
    }

    public function testUpdateImageTypesWithoutRecognizedTypes(): void
    {
        $this->adapter->method('fetchAssoc')->willReturn([]);
        $subject = $this->getSubject();
        $product = $this->createStub(Product::class);
        $product->method('getId')->willReturn(42);
        $this->adapter->expects($this->once())->method('delete');
        $this->action->expects($this->once())->method('updateAttributes')->with([42], [], 2);

        $subject->updateImageTypes(['unknown_attribute' => true], 'the-value', $product, 2);
    }

    public function testGetMediaGalleryEntity(): void
    {
        $subject = $this->getSubject();
        $result = $subject->getMediaGalleryEntity(0);

        $this->assertEquals('string', $result);
    }

    public function testGetExistingStoreEntries(): void
    {
        $subject = $this->getSubject();

        /** @var \Magento\Catalog\Model\Product|\Inriver\Adapter\Test\Unit\Model\MediaGallery\MockObject $product */
        $product = $this->createMock(Product::class);

        /** @var \Magento\Catalog\Api\Data\ProductAttributeMediaGalleryEntryInterface|\Inriver\Adapter\Test\Unit\Model\MediaGallery\MockObject $entry */
        $entry = $this->createMock(ProductAttributeMediaGalleryEntryInterface::class);

        $result = $subject->getExistingStoreEntries($product, [], $entry);

        $this->assertEquals([], $result);
    }

    #[DataProvider('linkFieldsProvider')]
    public function testDeleteExistingTypesForStore(string $linkField): void
    {
        $this->metadata->method('getLinkField')->willReturn($linkField);
        $this->adapter->method('fetchAssoc')->willReturn([]);
        $subject = $this->getSubject();

        $product = $this->createMock(Product::class);
        $product->expects($this->once())->method('getData')->with($linkField)->willReturn(42);
        $this->adapter->method('getTableName')->with('catalog_product_entity_varchar')
            ->willReturn('prefix_catalog_product_entity_varchar');
        $this->adapter->expects($this->once())->method('delete')->with(
            'prefix_catalog_product_entity_varchar',
            [
                'attribute_id IN(?)' => [],
                $linkField . ' = ?' => 42,
                'store_id = ?' => 2,
                'value = ?' => 'the-value',
            ]
        );

        $subject->deleteExistingTypesForStore($product, 'the-value', 2);
    }

    public function testGetList(): void
    {
        $productAttributeMediaGalleryEntryList = [$this->createMock(ProductAttributeMediaGalleryEntryInterface::class)];

        $this->galleryManagement->method('getList')->willReturn($productAttributeMediaGalleryEntryList);

        $subject = $this->getSubject();
        /** @noinspection PhpUnhandledExceptionInspection */
        $result = $subject->getList('the-sku');

        $this->assertEquals($productAttributeMediaGalleryEntryList, $result);
    }

    #[DataProvider('linkFieldsProvider')]
    public function testDeleteGalleryValueInStore(string $linkField): void
    {
        $this->metadata->method('getLinkField')->willReturn($linkField);
        $subject = $this->getSubject();

        $product = $this->createMock(Product::class);
        $product->expects($this->once())->method('getData')->with($linkField)->willReturn('42');

        $entry = $this->createStub(ProductAttributeMediaGalleryEntryInterface::class);
        $entry->method('getId')->willReturn(10);
        $this->resourceModel->expects($this->once())->method('deleteGalleryValueInStore')->with(10, 42, 2);

        $subject->deleteGalleryValueInStore($entry, $product, 2);
    }

    public function testDeleteGallery(): void
    {
        $subject = $this->getSubject();
        $this->resourceModel->expects($this->once())->method('deleteGallery')->with(10);
        $subject->deleteGallery(10);
    }

    public function testDeleteGalleryWithNullId(): void
    {
        $subject = $this->getSubject();
        $this->resourceModel->expects($this->once())->method('deleteGallery')->with(null);
        $subject->deleteGallery(null);
    }

    public function testCreate(): void
    {
        $subject = $this->getSubject();

        /** @var \Magento\Catalog\Api\Data\ProductAttributeMediaGalleryEntryInterface|\Inriver\Adapter\Test\Unit\Model\MediaGallery\MockObject $entry */
        $entry = $this->createMock(ProductAttributeMediaGalleryEntryInterface::class);

        $galleryEntryId = 10;

        $this->galleryManagement->method('create')->willReturn($galleryEntryId);

        /** @noinspection PhpUnhandledExceptionInspection */
        $result = $subject->create('sku', $entry);

        $this->assertEquals($galleryEntryId, $result);
    }

    protected function setUp(): void
    {
        $this->resourceConnection = $this->createMock(ResourceConnection::class);
        $this->productRepository = $this->createMock(ProductRepository::class);
        $this->metadataPool = $this->createMock(MetadataPool::class);
        $this->action = $this->createMock(Action::class);
        $this->resourceModel = $this->createMock(Gallery::class);
        $this->galleryManagement = $this->createMock(ProductAttributeMediaGalleryManagementInterface::class);

        $this->metadata = $this->createStub(EntityMetadataInterface::class);
        $this->metadataPool
            ->method('getMetadata')
            ->willReturn($this->metadata);

        $select = $this->createMock(Select::class);
        $select->method('from')->willReturnSelf();
        $select->method('where')->willReturnSelf();
        $select->method('limit')->willReturnSelf();

        $adapter = $this->createMock(AdapterInterface::class);
        $adapter->method('select')->willReturn($select);
        $adapter->method('fetchOne')->willReturn('string');
        $adapter->method('fetchCol')->willReturn([]);
        $this->adapter = $adapter;

        $this->resourceConnection->method('getConnection')->willReturn($adapter);
    }

    private function getSubject(): MediaGalleryManagement
    {
        /** @noinspection PhpUnhandledExceptionInspection */
        return new MediaGalleryManagement(
            $this->resourceConnection,
            $this->productRepository,
            $this->metadataPool,
            $this->action,
            $this->resourceModel,
            $this->galleryManagement
        );
    }
}
