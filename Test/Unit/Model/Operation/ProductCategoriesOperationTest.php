<?php

/**
 * @author InRiver <inriveradapters@inriver.com>
 * @copyright Copyright (c) InRiver (https://www.inriver.com/)
 * @link https://www.inriver.com/
 * @noinspection PhpUnhandledExceptionInspection
 */

declare(strict_types=1);

namespace Inriver\Adapter\Test\Unit\Model\Operation;

use Inriver\Adapter\Api\Data\ProductCategoriesInterface\CategoryInterfaceFactory;
use Inriver\Adapter\Helper\ErrorCodesDirectory;
use Inriver\Adapter\Logger\Logger;
use Inriver\Adapter\Model\Data\ProductCategories;
use Inriver\Adapter\Model\Data\ProductCategories\Category as DataCategory;
use Inriver\Adapter\Model\Operation\ProductCategoriesOperation;
use Magento\Catalog\Api\Data\CategoryLinkInterface;
use Magento\Catalog\Api\Data\CategoryLinkInterfaceFactory;
use Magento\Catalog\Api\Data\ProductExtensionInterface;
use Magento\Catalog\Api\ProductRepositoryInterfaceFactory;
use Magento\Catalog\Model\Category;
use Magento\Catalog\Model\CategoryLink;
use Magento\Catalog\Model\Product;
use Magento\Catalog\Model\ProductRepository;
use Magento\Catalog\Model\ResourceModel\Category\Collection;
use Magento\Catalog\Model\ResourceModel\Category\CollectionFactory;
use Magento\CatalogInventory\Model\StockRegistryStorage;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\TestFramework\Unit\Helper\ObjectManager;
use PHPUnit\Framework\TestCase;

use function __;

interface ProductCategoryLinksExtensionInterface extends ProductExtensionInterface
{
    public function getCategoryLinks();

    public function setCategoryLinks($categoryLinks);
}

class ProductCategoriesOperationTest extends TestCase
{
    private const AN_EXISTING_IMAGE_ID = 'an-existing-image-id';
    private const CAT_UNIQUE_ID = 'the-id';
    private const CAT_UNIQUE_ID_OTHER = 'other-the-id';
    private const WRONG_CAT_UNIQUE_ID = 'not-the-id';
    private const CAT_ID = '1234';
    private const CAT_ID_OTHER = '5678';
    private const POSITION = 4;

    /** @var \Magento\Catalog\Model\Product|\Inriver\Adapter\Test\Unit\Model\Operation\MockObject */
    private $product;

    /** @var ProductCategoryLinksExtensionInterface|\PHPUnit\Framework\MockObject\MockObject */
    private $productExtention;

    /** @var array */
    private $productCategoryLinks;

    /** @var \Magento\Catalog\Api\ProductRepositoryInterfaceFactory */
    private $productRepositoryFactory;

    /** @var \Magento\Catalog\Model\ProductRepository */
    private $productRepository;

    /** @var \Magento\Catalog\Model\ResourceModel\Category\CollectionFactory */
    private $categoryCollectionFactory;

    /** @var \Inriver\Adapter\Api\Data\ProductCategoriesInterface\CategoryInterfaceFactory|\Inriver\Adapter\Test\Unit\Model\Operation\MockObject */
    private $inriverCategoryFactory;

    /** @var \Magento\Catalog\Api\Data\CategoryLinkInterfaceFactory */
    private $categoryLinkInterfaceFactory;

    /** @var \Magento\CatalogInventory\Model\StockRegistryStorage|\Inriver\Adapter\Test\Unit\Model\Operation\MockObject */
    private $stockRegistryStorage;

    /** @var \Inriver\Adapter\Logger\Logger|\Inriver\Adapter\Test\Unit\Model\Operation\MockObject */
    private $logger;

    public function testAllNoPostion(): void
    {
        $subject = $this->getSubject();

        $message = new ProductCategories();
        $message->setSku(self::AN_EXISTING_IMAGE_ID);

        $category = new DataCategory();
        $category->setPosition(null);
        $category->setCategoryUniqueId(self::WRONG_CAT_UNIQUE_ID);

        $category1 = new DataCategory();
        $category1->setPosition(self::POSITION);
        $category1->setCategoryUniqueId(self::CAT_UNIQUE_ID);

        $category2 = new DataCategory();
        $category2->setPosition(null);
        $category2->setCategoryUniqueId(self::CAT_UNIQUE_ID_OTHER);

        $message->setCategories([$category, $category1, $category2]);

        $this->createBasicTestConfiguration();

        $this->setProductLinksForAllSecondNullPosition();
        $categoryCollection = $this->setCategories();
        $this->categoryCollectionFactory->expects($this->once())->method('create')->willReturn($categoryCollection);
        $errors = $subject->post($message);
        $this->assertEquals(ErrorCodesDirectory::CATEGORY_DOES_NOT_EXIST, $errors[0]['error_code']);
    }

    public function testNewCatAndWrongCat(): void
    {
        $subject = $this->getSubject();

        $message = new ProductCategories();
        $message->setSku(self::AN_EXISTING_IMAGE_ID);

        $category = new DataCategory();
        $category->setPosition(null);
        $category->setCategoryUniqueId(self::WRONG_CAT_UNIQUE_ID);

        $category1 = new DataCategory();
        $category1->setPosition(null);
        $category1->setCategoryUniqueId(self::CAT_UNIQUE_ID);

        $category2 = new DataCategory();
        $category2->setPosition(self::POSITION);
        $category2->setCategoryUniqueId(self::CAT_UNIQUE_ID_OTHER);

        $message->setCategories([$category, $category1, $category2]);

        $this->createBasicTestConfiguration();

        $this->setProductLinksForNewCatWrongCat();
        $categoryCollection = $this->setCategories();
        $this->categoryCollectionFactory->expects($this->once())->method('create')->willReturn($categoryCollection);
        $errors = $subject->post($message);
        $this->assertEquals(ErrorCodesDirectory::CATEGORY_DOES_NOT_EXIST, $errors[0]['error_code']);
    }

    public function testWrongUniqueId(): void
    {
        $subject = $this->getSubject();

        $message = new ProductCategories();
        $message->setSku(self::AN_EXISTING_IMAGE_ID);

        $category = new DataCategory();
        $category->setPosition(self::POSITION);
        $category->setCategoryUniqueId(self::WRONG_CAT_UNIQUE_ID);
        $message->setCategories([$category]);

        $this->createBasicTestConfiguration();

        $this->setProductLinksBasic();
        $categoryCollection = $this->setCategories();
        $this->categoryCollectionFactory->expects($this->once())->method('create')->willReturn($categoryCollection);

        $errors = $subject->post($message);
        $this->assertEquals(ErrorCodesDirectory::CATEGORY_DOES_NOT_EXIST, $errors[0]['error_code']);
        $this->assertCount(1, $errors);
    }

    public function testCannotSaveProduct(): void
    {
        $subject = $this->getSubject();

        $message = $this->getBasicMessage();

        $this->createBasicTestConfiguration();

        $this->setProductLinksBasic();
        $categoryCollection = $this->setCategories();
        $this->categoryCollectionFactory->expects($this->once())->method('create')->willReturn($categoryCollection);

        $exception = new NoSuchEntityException(
            __(),
            null,
            ErrorCodesDirectory::CANNOT_NOT_SAVE_PRODUCT_CATEGORIES
        );

        $this->productRepository->expects($this->once())->method('save')->willThrowException($exception);

        $this->expectExceptionCode(ErrorCodesDirectory::CANNOT_NOT_SAVE_PRODUCT_CATEGORIES);

        $subject->post($message);
    }

    public function testSaveCategory(): void
    {
        $subject = $this->getSubject();

        $message = $this->getBasicMessage();

        $this->createBasicTestConfiguration();

        $this->setProductLinksBasic();
        $categoryCollection = $this->setCategories();
        $this->categoryCollectionFactory->expects($this->once())->method('create')->willReturn($categoryCollection);

        $subject->post($message);
    }

    public function testSkuDoesNotExists(): void
    {
        $subject = $this->getSubject();

        $exception = new NoSuchEntityException(
            __(),
            null,
            ErrorCodesDirectory::SKU_NOT_FOUND
        );

        /** @var \Inriver\Adapter\Model\Data\ProductCategories|\Inriver\Adapter\Test\Unit\Model\Operation\MockObject $productCategories */
        $productCategories = $this->createMock(ProductCategories::class);
        $this->productRepository->expects($this->once())->method('get')->willThrowException($exception);
        $this->productRepositoryFactory->expects($this->once())->method('create')->willReturn($this->productRepository);
        $this->expectExceptionCode(ErrorCodesDirectory::SKU_NOT_FOUND);
        $subject->post($productCategories);
    }

    protected function setUp(): void
    {
        $this->productRepositoryFactory = $this->createMock(ProductRepositoryInterfaceFactory::class);
        $this->categoryCollectionFactory = $this->createMock(CollectionFactory::class);
        $this->inriverCategoryFactory = $this->createMock(CategoryInterfaceFactory::class);
        $this->categoryLinkInterfaceFactory = $this->createMock(CategoryLinkInterfaceFactory::class);
        $this->product = $this->createMock(Product::class);
        $this->productRepository = $this->createMock(ProductRepository::class);
        $this->productExtention = $this->createMock(ProductCategoryLinksExtensionInterface::class);
        $this->stockRegistryStorage = $this->createMock(StockRegistryStorage::class);
        $this->logger = $this->createMock(Logger::class);
    }

    private function getSubject(): ProductCategoriesOperation
    {
        return new ProductCategoriesOperation(
            $this->productRepositoryFactory,
            $this->categoryCollectionFactory,
            $this->inriverCategoryFactory,
            $this->categoryLinkInterfaceFactory,
            $this->stockRegistryStorage,
            $this->logger
        );
    }

    /**
     * @return \Inriver\Adapter\Model\Data\ProductCategories
     */
    private function getBasicMessage(): ProductCategories
    {
        $message = new ProductCategories();
        $message->setSku(self::AN_EXISTING_IMAGE_ID);

        $category = new DataCategory();
        $category->setPosition(self::POSITION);
        $category->setCategoryUniqueId(self::CAT_UNIQUE_ID);
        $message->setCategories([$category]);

        return $message;
    }

    private function createBasicTestConfiguration(): void
    {
        $this->product->method('getExtensionAttributes')
            ->willReturn($this->productExtention);

        $this->productRepository->expects($this->once())->method('get')->willReturn($this->product);
        $this->productRepositoryFactory->expects($this->once())->method('create')->willReturn($this->productRepository);

        $categoryLinkInterface = $this->createMock(CategoryLink::class);
        $categoryLinkInterface->method('setPosition')->willReturn($categoryLinkInterface);
        $categoryLinkInterface->method('setCategoryId')->willReturn($categoryLinkInterface);
        $this->categoryLinkInterfaceFactory->method('create')
            ->willReturn($categoryLinkInterface);
    }

    private function setProductLinksBasic(): void
    {
        $categoryLink1 = $this->createMock(CategoryLinkInterface::class);
        $categoryLink1->method('getCategoryId')->willReturn(self::CAT_ID);
        $categoryLink1->method('getPosition')->willReturn(self::POSITION);

        $categoryLink2 = $this->createMock(CategoryLinkInterface::class);
        $categoryLink2->method('getCategoryId')->willReturn(self::CAT_ID_OTHER);
        $categoryLink2->method('getPosition')->willReturn(self::POSITION);

        $this->productCategoryLinks = [$categoryLink1, $categoryLink2];

        $this->productExtention->method('getCategoryLinks')
            ->willReturn($this->productCategoryLinks);
    }

    private function setProductLinksForNewCatWrongCat(): void
    {
        $categoryLink1 = $this->createMock(CategoryLinkInterface::class);
        $categoryLink1->expects($this->exactly(2))->method('getCategoryId')->willReturn(self::CAT_ID);
        $categoryLink1->expects($this->never())->method('getPosition');

        $categoryLink2 = $this->createMock(CategoryLinkInterface::class);
        $categoryLink2->expects($this->once())->method('getCategoryId')->willReturn(self::CAT_ID_OTHER);
        $categoryLink2->expects($this->never())->method('getPosition');

        $this->productCategoryLinks = [$categoryLink1, $categoryLink2];
        $this->productExtention->method('getCategoryLinks')
            ->willReturn($this->productCategoryLinks);
    }

    private function setProductLinksForAllSecondNullPosition(): void
    {
        $categoryLink1 = $this->createMock(CategoryLinkInterface::class);
        $categoryLink1->expects($this->exactly(2))->method('getCategoryId')->willReturn(self::CAT_ID);
        $categoryLink1->expects($this->never())->method('getPosition');

        $categoryLink2 = $this->createMock(CategoryLinkInterface::class);
        $categoryLink2->expects($this->once())->method('getCategoryId')->willReturn(self::CAT_ID_OTHER);
        $categoryLink2->expects($this->never())->method('getPosition');

        $this->productCategoryLinks = [$categoryLink1, $categoryLink2];
        $this->productExtention->method('getCategoryLinks')
            ->willReturn($this->productCategoryLinks);
    }

    /**
     * @return \Magento\Catalog\Model\ResourceModel\Category\Collection|\Inriver\Adapter\Test\Unit\Model\Operation\MockObject
     */
    private function setCategories(): Collection
    {
        $objectManager = new ObjectManager($this);

        $category1 = $this->createMock(Category::class);
        $category1->method('getId')->willReturn(self::CAT_ID);
        $category1->method('getData')->willReturn(self::CAT_UNIQUE_ID);

        $category2 = $this->createMock(Category::class);
        $category2->method('getId')->willReturn(self::CAT_ID_OTHER);
        $category2->method('getData')->willReturn(self::CAT_UNIQUE_ID_OTHER);

        return $objectManager->getCollectionMock(Collection::class, [$category1, $category2]);
    }
}
