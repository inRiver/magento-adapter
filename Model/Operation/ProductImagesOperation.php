<?php

/**
 * @author InRiver <inriveradapters@inriver.com>
 * @copyright Copyright (c) InRiver (https://www.inriver.com/)
 * @link https://www.inriver.com/
 */

declare(strict_types=1);

namespace Inriver\Adapter\Model\Operation;

use Inriver\Adapter\Api\Data\ProductImagesInterface;
use Inriver\Adapter\Api\Data\ProductImagesInterface\ImageInterface;
use Inriver\Adapter\Api\Data\ProductImagesInterface\ImageInterface\AttributesInterface;
use Inriver\Adapter\Api\ImagesInterface;
use Inriver\Adapter\Api\InriverMediaGalleryDataRepositoryInterface;
use Inriver\Adapter\Helper\ErrorCodesDirectory;
use Inriver\Adapter\Helper\FileDownloader;
use Inriver\Adapter\Logger\Logger;
use Inriver\Adapter\Model\Data\InriverMediaGalleryDataFactory;
use Inriver\Adapter\Model\MediaGallery\MediaGalleryManagement;
use Magento\Catalog\Api\Data\ProductAttributeMediaGalleryEntryInterface;
use Magento\Catalog\Api\Data\ProductAttributeMediaGalleryEntryInterfaceFactory;
use Magento\Catalog\Api\Data\ProductInterface;
use Magento\Catalog\Model\ProductRepository;
use Magento\CatalogInventory\Model\StockRegistryStorage;
use Magento\Framework\Api\Data\ImageContentInterfaceFactory;
use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Filesystem;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;
use Throwable;

use function __;
use function array_diff;
use function array_flip;
use function array_key_exists;
use function base64_encode;
use function count;
use function is_array;

class ProductImagesOperation implements ImagesInterface
{
    /** @var \Inriver\Adapter\Helper\FileDownloader */
    protected $downloader;

    /** @var \Magento\Framework\Filesystem */
    protected $filesystem;

    /** @var \Magento\Catalog\Api\Data\ProductAttributeMediaGalleryEntryInterfaceFactory */
    protected $galleryEntryFactory;

    /** @var \Magento\Framework\Api\Data\ImageContentInterfaceFactory */
    protected $imageContentFactory;

    /** @var \Magento\Store\Model\StoreManagerInterface */
    protected $storeManager;

    /** @var \Magento\Catalog\Model\ProductRepository */
    protected $productRepository;

    /** @var \Inriver\Adapter\Model\Data\InriverMediaGalleryDataFactory */
    protected $inriverMediaGalleryDataFactory;

    /** @var \Inriver\Adapter\Api\InriverMediaGalleryDataRepositoryInterface */
    protected $inriverMediaGalleryDataRepository;

    /** @var \Inriver\Adapter\Model\MediaGallery\MediaGalleryManagement */
    protected $mediaGalleryManagement;

    /** @var \Magento\CatalogInventory\Model\StockRegistryStorage */
    private $stockRegistryStorage;

    /** @var \Inriver\Adapter\Logger\Logger */
    private $logger;

    /**
     * @param \Inriver\Adapter\Helper\FileDownloader $downloader
     * @param \Magento\Framework\Filesystem $filesystem
     * @param \Magento\Catalog\Api\Data\ProductAttributeMediaGalleryEntryInterfaceFactory $galleryEntryFactory
     * @param \Magento\Framework\Api\Data\ImageContentInterfaceFactory $imageContentFactory
     * @param \Magento\Store\Model\StoreManagerInterface $storeManager
     * @param \Magento\Catalog\Model\ProductRepository $productRepository
     * @param \Inriver\Adapter\Api\InriverMediaGalleryDataRepositoryInterface $inriverMediaGalleryDataRepository
     * @param \Inriver\Adapter\Model\Data\InriverMediaGalleryDataFactory $inriverMediaGalleryDataFactory
     * @param \Inriver\Adapter\Model\MediaGallery\MediaGalleryManagement $mediaGalleryManagement
     * @param \Magento\CatalogInventory\Model\StockRegistryStorage $stockRegistryStorage
     * @param \Inriver\Adapter\Logger\Logger $logger
     */
    public function __construct(
        FileDownloader $downloader,
        Filesystem $filesystem,
        ProductAttributeMediaGalleryEntryInterfaceFactory $galleryEntryFactory,
        ImageContentInterfaceFactory $imageContentFactory,
        StoreManagerInterface $storeManager,
        ProductRepository $productRepository,
        InriverMediaGalleryDataRepositoryInterface $inriverMediaGalleryDataRepository,
        InriverMediaGalleryDataFactory $inriverMediaGalleryDataFactory,
        MediaGalleryManagement $mediaGalleryManagement,
        StockRegistryStorage $stockRegistryStorage,
        Logger $logger
    ) {
        $this->downloader = $downloader;
        $this->filesystem = $filesystem;
        $this->galleryEntryFactory = $galleryEntryFactory;
        $this->imageContentFactory = $imageContentFactory;
        $this->storeManager = $storeManager;
        $this->productRepository = $productRepository;
        $this->inriverMediaGalleryDataRepository = $inriverMediaGalleryDataRepository;
        $this->inriverMediaGalleryDataFactory = $inriverMediaGalleryDataFactory;
        $this->mediaGalleryManagement = $mediaGalleryManagement;
        $this->stockRegistryStorage = $stockRegistryStorage;
        $this->logger = $logger;
    }

    /**
     * Import product images
     *
     * @param \Inriver\Adapter\Api\Data\ProductImagesInterface $productImage
     *
     * @return string[]
     * @throws \Magento\Framework\Exception\InputException
     * @throws \Magento\Framework\Exception\LocalizedException
     * @throws \Magento\Framework\Exception\NoSuchEntityException
     * @throws \Magento\Framework\Exception\StateException
     *
     * @noinspection PhpFullyQualifiedNameUsageInspection
     */
    public function post(ProductImagesInterface $productImage): array
    {
        $this->logger->info(
            __('Starting Product Images Operation for sku: %1', $productImage->getSku())
        );
        $result = $this->syncProductImages($productImage->getSku(), $productImage->getImages());
        $this->logger->info(
            __('Finished Product Images Operation for sku: %1', $productImage->getSku())
        );
        return $result;
    }

    /**
     * Synchronize product images
     *
     * @param string $sku
     * @param \Inriver\Adapter\Api\Data\ProductImagesInterface\ImageInterface[] $images
     *
     * @return string[]
     * @throws \Magento\Framework\Exception\NoSuchEntityException
     * @throws \Magento\Framework\Exception\StateException
     * @throws \Magento\Framework\Exception\InputException|\Magento\Framework\Exception\LocalizedException
     */
    private function syncProductImages(string $sku, array $images): array
    {
        $this->storeManager->setCurrentStore(0);

        // Clean both stock registry and product repository cache to force a reload.
        // This Fixes a rare bug if another extension loaded wrong data in them.
        $this->stockRegistryStorage->clean();
        $this->productRepository->cleanCache();

        $newImagesByImageId = $this->imagesByImageId($images);

        try {
            $entries = $this->mediaGalleryManagement->getList($sku);
        } catch (NoSuchEntityException $exception) {
            throw new LocalizedException(
                __('The sku %1 does not exist', $sku),
                $exception,
                ErrorCodesDirectory::SKU_NOT_FOUND
            );
        }

        foreach ($entries as $entry) {
            $inriverGalleryData = $this->inriverMediaGalleryDataRepository->getById((int) $entry->getId());

            $imageId = $inriverGalleryData->getImageId();

            if ($imageId === null || !array_key_exists($imageId, $newImagesByImageId)) {
                $this->mediaGalleryManagement->deleteGallery((int) $entry->getId());
            } else {
                $this->updateStoreValues(
                    $entry,
                    $sku,
                    $newImagesByImageId[$imageId]
                );
                unset($newImagesByImageId[$imageId]);
            }
        }

        $errors = [];

        foreach ($newImagesByImageId as $newImage) {
            try {
                $this->createNewMediaEntry($newImage, $sku);
            } catch (LocalizedException $ex) {
                $errors[] = [
                    'error_code' => $ex->getCode() ? $ex->getCode() : ErrorCodesDirectory::CANNOT_DOWNLOAD_MEDIA_FILE,
                    'error_message' => 'There was an error with image ' . $newImage->getFilename() .
                        ' for sku ' . $sku . ': ' . $ex->getMessage(),
                ];
                $this->logger->error($ex);
            }
        }

        return $errors;
    }

    /**
     * Get image id as array key
     *
     * @param \Inriver\Adapter\Api\Data\ProductImagesInterface\ImageInterface[] $images
     *
     * @return \Inriver\Adapter\Api\Data\ProductImagesInterface\ImageInterface[]
     */
    private function imagesByImageId(array $images): array
    {
        $imagesByImageId = [];

        foreach ($images as $image) {
            $imagesByImageId[$image->getImageId()] = $image;
        }

        return $imagesByImageId;
    }

    /**
     * Create a new media entry
     *
     * @param \Inriver\Adapter\Api\Data\ProductImagesInterface\ImageInterface $newImage
     * @param string $sku
     *
     * @throws \Magento\Framework\Exception\FileSystemException
     * @throws \Magento\Framework\Exception\InputException
     * @throws \Magento\Framework\Exception\LocalizedException
     * @throws \Magento\Framework\Exception\NoSuchEntityException
     * @throws \Magento\Framework\Exception\StateException
     */
    private function createNewMediaEntry(ImageInterface $newImage, string $sku): void
    {
        $this->storeManager->setCurrentStore(0);

        $existingImage = $this->inriverMediaGalleryDataRepository->getFirstByImageId($newImage->getImageId());

        if ($existingImage->getValueId() === null) {
            try {
                $imagePayload = $this->downloader->getRemoteFileContent($newImage->getUrl());
            } catch (Throwable $exception) {
                throw new LocalizedException(
                    __('Cannot download image %1: %2', $newImage->getFilename(), $exception->getMessage()),
                    null,
                    ErrorCodesDirectory::CANNOT_DOWNLOAD_MEDIA_FILE
                );
            }
        } else {
            try {
                $imagePayload = $this->getLocalImage($existingImage->getValueId());
            } catch (Throwable $exception) {
                throw new LocalizedException(
                    __('Cannot read local image %1: %2', $newImage->getFilename(), $exception->getMessage()),
                    null,
                    ErrorCodesDirectory::CANNOT_READ_LOCAL_MEDIA_FILE
                );
            }
        }

        $imageContent = $this->imageContentFactory->create();
        $imageContent->setBase64EncodedData(base64_encode($imagePayload));
        $imageContent->setType($newImage->getMimeType());
        $imageContent->setName($newImage->getFilename());

        $newEntry = $this->galleryEntryFactory->create();
        $newEntry->setFile($newImage->getFilename());
        $newEntry->setMediaType('image');
        $newEntry->setDisabled(false);
        $newEntry->setContent($imageContent);
        $newEntry->setTypes();

        $galleryEntryId = $this->mediaGalleryManagement->create($sku, $newEntry);

        $newEntry->setId($galleryEntryId);

        $inriverMediaGalleryData = $this->inriverMediaGalleryDataFactory->create();
        $inriverMediaGalleryData->setImageId($newImage->getImageId());
        $inriverMediaGalleryData->setId($galleryEntryId);

        $this->inriverMediaGalleryDataRepository->save($inriverMediaGalleryData);

        $this->updateStoreValues($newEntry, $sku, $newImage, true);
    }

    /**
     * Get image on local filesystem
     *
     * @param int $valueId
     *
     * @return string
     * @throws \Magento\Framework\Exception\FileSystemException
     */
    private function getLocalImage(int $valueId): string
    {
        $value = $this->mediaGalleryManagement->getEntryValueById($valueId);

        $mediaDirectory = $this->filesystem->getDirectoryRead(DirectoryList::MEDIA);

        return $mediaDirectory->readFile('/catalog/product' . $value);
    }

    /**
     * Update media values for a store
     *
     * @param \Magento\Catalog\Api\Data\ProductAttributeMediaGalleryEntryInterface $entry
     * @param string $sku
     * @param \Inriver\Adapter\Api\Data\ProductImagesInterface\ImageInterface $newImage
     * @param bool $newlyAddedImage
     *
     * @throws \Magento\Framework\Exception\NoSuchEntityException
     * @throws \Magento\Framework\Exception\LocalizedException
     */
    private function updateStoreValues(
        ProductAttributeMediaGalleryEntryInterface $entry,
        string $sku,
        ImageInterface $newImage,
        bool $newlyAddedImage = false
    ): void {
        $entry->setFile(null);
        $entry->setContent(null);

        $product = $this->productRepository->get($sku);

        $value = $this->mediaGalleryManagement->getMediaGalleryEntity((int) $entry->getId());
        $singleStore = $this->storeManager->hasSingleStore();

        foreach ($newImage->getAttributes() as $storesAttributes) {
            $entry->setTypes($storesAttributes->getRoles());

            $requiredStoreIds = [];

            foreach ($storesAttributes->getStoreViews() as $storeView) {
                $this->storeManager->setCurrentStore($storeView);

                $storeId = (int) $this->storeManager->getStore($storeView)->getId();
                $requiredStoreIds[] = $storeId;

                $storeEntry = $this->mediaGalleryManagement->getStoreEntry($sku, $storeId, (int) $entry->getId());

                if ($storeEntry === null || !$this->storeValuesAreEqual($storesAttributes, $storeEntry)) {
                    $this->updateMediaValues($entry, $product, $storeId, $storesAttributes);
                    $storeEntry = $this->mediaGalleryManagement->getStoreEntry($sku, $storeId, (int) $entry->getId());
                }

                $roles = array_flip($storesAttributes->getRoles());

                if ($newlyAddedImage || !$this->imageTypesAreEqual($storeEntry->getTypes(), $storesAttributes->getRoles())) {
                    $this->mediaGalleryManagement->updateImageTypes(
                        $roles,
                        $value,
                        $product,
                        $singleStore ? Store::DEFAULT_STORE_ID : $storeId
                    );
                }
            }

            if ($singleStore && $requiredStoreIds !== []) {
                $requiredStoreIds[] = Store::DEFAULT_STORE_ID;
            }

            $existing = $this->mediaGalleryManagement->getExistingStoreEntries($product, $requiredStoreIds, $entry);

            foreach ($existing as $storeIdToRemove) {
                $storeIdAsInt = (int) $storeIdToRemove;
                $this->mediaGalleryManagement->deleteExistingTypesForStore($product, $value, $storeIdAsInt);
                $this->mediaGalleryManagement->deleteGalleryValueInStore($entry, $product, $storeIdAsInt);
            }
        }
    }

    /**
     * Test that store values are equal
     *
     * @param \Inriver\Adapter\Api\Data\ProductImagesInterface\ImageInterface\AttributesInterface $storesAttributes
     * @param \Magento\Catalog\Api\Data\ProductAttributeMediaGalleryEntryInterface $storeEntry
     *
     * @return bool
     */
    private function storeValuesAreEqual(
        AttributesInterface $storesAttributes,
        ProductAttributeMediaGalleryEntryInterface $storeEntry
    ): bool {
        return $storesAttributes->getPosition() === (int) $storeEntry->getPosition();
    }

    /**
     * Test if image types are equal
     *
     * @param string[] $currentTypes
     * @param string[] $requiredTypes
     *
     * @return bool
     */
    private function imageTypesAreEqual(array $currentTypes, array $requiredTypes): bool
    {
        return is_array($currentTypes)
            && is_array($requiredTypes)
            && count($currentTypes) === count($requiredTypes)
            && array_diff($currentTypes, $requiredTypes) === array_diff($requiredTypes, $currentTypes);
    }

    /**
     * Update media values
     *
     * @param \Magento\Catalog\Api\Data\ProductAttributeMediaGalleryEntryInterface $entry
     * @param \Magento\Catalog\Api\Data\ProductInterface $product
     * @param int $storeId
     * @param \Inriver\Adapter\Api\Data\ProductImagesInterface\ImageInterface\AttributesInterface $storesAttributes
     */
    private function updateMediaValues(
        ProductAttributeMediaGalleryEntryInterface $entry,
        ProductInterface $product,
        int $storeId,
        AttributesInterface $storesAttributes
    ): void {
        $this->mediaGalleryManagement->deleteGalleryValueInStore($entry, $product, $storeId);

        $this->mediaGalleryManagement->insertGalleryValueInStore(
            (int) $entry->getId(),
            $storeId,
            $storesAttributes->getPosition(),
            $product
        );
    }
}
