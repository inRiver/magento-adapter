<?php

declare(strict_types=1);

namespace Inriver\Adapter\Test\Api\Fixtures;

use Inriver\Adapter\Model\MediaGallery\MediaGalleryManagement;
use Magento\Catalog\Api\Data\ProductInterface;
use Magento\Framework\Console\Cli;
use Magento\Store\Model\StoreManagerInterface;

require '/app/app/bootstrap.php';

class ImageTraceMediaGalleryManagement extends MediaGalleryManagement
{
    public function updateImageTypes(array $types, string $value, ProductInterface $product, int $storeId): void
    {
        parent::updateImageTypes($types, $value, $product, $storeId);
        $this->capture('after_role_assignment', $product, $storeId);
    }

    public function deleteExistingTypesForStore(ProductInterface $product, string $value, int $storeId): void
    {
        $this->capture('before_role_deletion', $product, $storeId);
        parent::deleteExistingTypesForStore($product, $value, $storeId);
        $this->capture('after_role_deletion', $product, $storeId);
    }

    private function capture(string $stage, ProductInterface $product, int $storeId): void
    {
        $connection = $this->resourceConnection->getConnection();
        $linkField = $this->metadata->getLinkField();
        $rows = $connection->fetchAll($connection->select()
            ->from(['value' => $this->resourceConnection->getTableName('catalog_product_entity_varchar')], ['store_id', 'value'])
            ->join(['attribute' => $this->resourceConnection->getTableName('eav_attribute')],
                'attribute.attribute_id = value.attribute_id', ['attribute_code'])
            ->join(['product' => $this->resourceConnection->getTableName('catalog_product_entity')],
                'product.' . $linkField . ' = value.' . $linkField, [])
            ->where('product.sku = ?', $product->getSku())
            ->where('attribute.attribute_code IN (?)', ['image', 'thumbnail'])
            ->order(['value.store_id', 'attribute.attribute_code']));
        $manager = \Magento\Framework\App\ObjectManager::getInstance();
        $snapshot = [
            'stage' => $stage,
            'requested_store_id' => $storeId,
            'has_single_store' => $manager->get(StoreManagerInterface::class)->hasSingleStore(),
            'roles_in_database' => $rows,
        ];
        file_put_contents(getenv('INRIVER_IMAGE_TRACE_FILE'), json_encode($snapshot, JSON_THROW_ON_ERROR) . "\n", FILE_APPEND | LOCK_EX);
    }
}

$application = new Cli('Magento CLI');
$manager = \Magento\Framework\App\ObjectManager::getInstance();
$manager->configure(['preferences' => [MediaGalleryManagement::class => ImageTraceMediaGalleryManagement::class]]);
exit($application->run());