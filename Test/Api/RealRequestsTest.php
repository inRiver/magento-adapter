<?php

declare(strict_types=1);

namespace Inriver\Adapter\Test\Api;

use GuzzleHttp\Client;
use Inriver\Adapter\Api\Data\ImportInterface;
use Inriver\Adapter\Helper\ErrorCodesDirectory;
use Inriver\Adapter\Helper\InriverCallback;
use Inriver\Adapter\Setup\Patch\Data\CategoryPimUniqueId;
use Magento\AsynchronousOperations\Model\ResourceModel\Operation\CollectionFactory;
use Magento\Authorization\Model\UserContextInterface;
use Magento\Framework\App\Bootstrap;
use Magento\Framework\App\State;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\App\Cache\TypeListInterface;
use Magento\Framework\App\Config\ReinitableConfigInterface;
use Magento\Config\Model\ResourceModel\Config as ConfigResource;
use Magento\Framework\Bulk\OperationInterface;
use Magento\Framework\ObjectManagerInterface;
use Magento\Framework\Registry;
use Magento\Framework\Encryption\EncryptorInterface;
use Magento\Integration\Api\Data\UserTokenParametersInterface;
use Magento\Integration\Api\UserTokenIssuerInterface;
use Magento\Integration\Api\UserTokenRevokerInterface;
use Magento\Integration\Model\CustomUserContext;
use Magento\Store\Model\StoreManagerInterface;
use Magento\Store\Model\Store;
use Magento\User\Model\User;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

class RealRequestsTest extends TestCase
{
    private static ObjectManagerInterface $objectManager;

    private static ?User $testAdmin = null;

    private static string $token;

    private array $fixtureSkus = [];

    private array $fixturePaths = [];

    private array $fixtureAttributes = [];

    private array $fixtureStoreIds = [];

    private ?string $imageTracePath = null;

    protected function tearDown(): void
    {
        try {
            foreach (array_reverse($this->fixtureSkus) as $sku) {
                $this->request('DELETE', '/rest/default/V1/products/' . rawurlencode($sku));
            }
        } finally {
            foreach ($this->fixtureAttributes as $code) {
                $this->request('DELETE', '/rest/default/V1/products/attributes/' . $code);
            }
            foreach ($this->fixtureStoreIds as $storeId) {
                $this->deleteStoreFixture($storeId);
            }
            self::$objectManager->get(StoreManagerInterface::class)->reinitStores();
            foreach ($this->fixturePaths as $path) {
                if (is_file($path)) {
                    unlink($path);
                }
            }
            $importPath = self::$objectManager->get(ScopeConfigInterface::class)
                ->getValue(ImportInterface::XML_INRIVER_IMPORT_PATH_CSV);
            foreach (glob(BP . '/var/' . ltrim((string) $importPath, '/') . '/archives/*/import-*.csv') ?: [] as $archive) {
                $contents = file_get_contents($archive);
                foreach ($this->fixtureSkus as $sku) {
                    if ($contents !== false && str_contains($contents, $sku)) {
                        unlink($archive);
                        break;
                    }
                }
            }
        }
    }

    public function testRelationsCsvImportsPositionsAndIsIdempotent(): void
    {
        $this->requireEmptyQueue();
        $suffix = bin2hex(random_bytes(6));
        $sku = 'inriver-api-relations-' . $suffix;
        $linkedSku = 'inriver-api-linked-' . $suffix;
        $this->createProductFixture($sku);
        $this->createProductFixture($linkedSku);
        $url = $this->createCsvFixture([
            'sku', 'crosssell_skus', 'upsell_skus', 'related_skus',
            'crosssell_position', 'upsell_position', 'related_position',
        ], [[$sku, $linkedSku, $linkedSku, $linkedSku, '4', '5', '6']]);

        for ($attempt = 0; $attempt < 2; $attempt++) {
            $this->importCsvFixture($url, 'inriver-adapter/products/import/relations');
            $product = $this->request('GET', '/rest/default/V1/products/' . rawurlencode($sku));
            $links = $product['product_links'];
            $this->assertCount(3, $links);
            $positions = [];
            foreach ($links as $link) {
                $this->assertSame($linkedSku, $link['linked_product_sku']);
                $positions[$link['link_type']] = (int) $link['position'];
            }
            ksort($positions);
            $this->assertSame(['crosssell' => 4, 'related' => 6, 'upsell' => 5], $positions);
        }
    }

    public function testLocalizedAttributeOptionsUpdateWithoutDuplicates(): void
    {
        $this->requireEmptyQueue();
        $suffix = bin2hex(random_bytes(6));
        $code = 'inriver_test_' . $suffix;
        $this->createSelectAttributeFixture($code);
        $store = $this->createStoreFixture('inriver_api_' . $suffix);
        $defaultStore = self::$objectManager->get(StoreManagerInterface::class)->getDefaultStoreView();
        $optionId = null;
        foreach (['Petit', 'Tres Petit'] as $label) {
            $operation = $this->submitBulkFixture('inriver-adapter/products/options-by-attribute', [
                'optionsByAttribute' => [
                    'attributes' => [$code],
                    'options' => [[
                        'admin_value' => 'Small',
                        'values' => [
                            ['store_view_code' => $defaultStore->getCode(), 'value' => 'Small Local', 'description' => 'Small Local'],
                            ['store_view_code' => $store->getCode(), 'value' => $label, 'description' => $label],
                        ],
                    ]],
                ],
            ]);
            $this->assertSame(OperationInterface::STATUS_TYPE_COMPLETE, (int) $operation['status'], $operation['result_message']);
            $options = $this->request('GET', '/rest/all/V1/products/attributes/' . $code . '/options');
            $options = array_values(array_filter($options, static fn (array $option) => $option['value'] !== ''));
            $this->assertCount(1, $options);
            $currentId = (int) $options[0]['value'];
            if ($optionId !== null) {
                $this->assertSame($optionId, $currentId);
            }
            $optionId = $currentId;
            $labels = $this->optionLabels($optionId);
            $this->assertSame('Small', $labels[0]);
            $this->assertSame('Small Local', $labels[(int) $defaultStore->getId()]);
            $this->assertSame($label, $labels[(int) $store->getId()]);
        }
    }

    #[DataProvider('callbackResponsesProvider')]
    public function testCallbackDeliversFailureReportAndRetriesUnacknowledgedResponse(int $statusCode): void
    {
        $this->requireEmptyQueue();
        $suffix = bin2hex(random_bytes(6));
        $capture = BP . '/var/inriver-api-test-' . $suffix . '.jsonl';
        $statusFile = BP . '/var/inriver-api-test-' . $suffix . '.status';
        $this->fixturePaths[] = $capture;
        $this->fixturePaths[] = $statusFile;
        file_put_contents($statusFile, (string) $statusCode);
        $socket = stream_socket_server('tcp://0.0.0.0:0');
        if ($socket === false) {
            throw new \RuntimeException('Cannot allocate a local callback listener port.');
        }
        $address = stream_socket_get_name($socket, false);
        fclose($socket);
        $port = (int) substr($address, strrpos($address, ':') + 1);
        $server = new Process([
            PHP_BINARY, '-S', '0.0.0.0:' . $port, __DIR__ . '/Fixtures/CallbackRouter.php',
        ], BP, ['INRIVER_TEST_CALLBACK_CAPTURE' => $capture, 'INRIVER_TEST_CALLBACK_STATUS' => $statusFile], null, 60);
        $resource = self::$objectManager->get(ResourceConnection::class);
        $connection = $resource->getConnection();
        $configPath = 'inriver/import/inriver_api_key';
        $previous = $connection->fetchRow($connection->select()
            ->from($resource->getTableName('core_config_data'))->where('path = ?', $configPath)
            ->where('scope = ?', 'default')->where('scope_id = ?', 0));
        $config = self::$objectManager->get(ConfigResource::class);
        $bulkUuid = null;
        try {
            $server->start();
            $this->assertTrue($server->waitUntil(static fn (string $type, string $output) => str_contains($output, 'Development Server')));
            $config->saveConfig($configPath, self::$objectManager->get(EncryptorInterface::class)
                ->encrypt('inriver-api-test-key'), 'default', 0);
            $this->reloadConfig();
            $sku = 'inriver-api-callback-missing-' . $suffix;
            $operation = $this->submitBulkFixture('inriver-adapter/products/category-assignments', [
                'productCategories' => ['sku' => $sku, 'categories' => []],
            ], [
                InriverCallback::CALLBACK_HEADER => 'http://php:' . $port . '/capture',
                InriverCallback::RUNTIME_ID_HEADER => 'inriver-api-test-runtime-' . $suffix,
            ]);
            $bulkUuid = $operation['bulk_uuid'];
            $this->assertSame(ErrorCodesDirectory::SKU_NOT_FOUND, (int) $operation['error_code']);
            $this->assertFileExists($capture);
            $requests = array_map(static fn (string $line) => json_decode($line, true, 512, JSON_THROW_ON_ERROR), file($capture, FILE_IGNORE_NEW_LINES));
            $this->assertCount(1, $requests);
            $this->assertSame('PUT', $requests[0]['method']);
            $this->assertSame('inriver-api-test-key', $requests[0]['api_key']);
            $this->assertSame('RuntimeResult', $requests[0]['body'][0]['fieldTypeId']);
            $report = json_decode($requests[0]['body'][0]['value'], true, 512, JSON_THROW_ON_ERROR);
            $this->assertSame(1, $report['total_operations']);
            $this->assertSame(1, $report['failed_operations']);
            $this->assertCount(1, $report['operations_report']);
            $detail = array_values($report['operations_report'])[0];
            $this->assertSame(ErrorCodesDirectory::SKU_NOT_FOUND, $detail['error_code']);
            $this->assertStringContainsString($sku, $detail['messages']);
            $this->assertSame($statusCode === 200 ? 1 : 0, $this->callbackNotified($bulkUuid));
            if ($statusCode !== 200) {
                file_put_contents($statusFile, '200');
                self::$objectManager->get(InriverCallback::class)->returnResponseToInriverAfterAsyncOperations($bulkUuid);
                $this->assertSame(1, $this->callbackNotified($bulkUuid));
                $requests = file($capture, FILE_IGNORE_NEW_LINES);
                $this->assertCount(2, $requests);
                $retry = json_decode($requests[1], true, 512, JSON_THROW_ON_ERROR);
                $this->assertSame($report, json_decode($retry['body'][0]['value'], true, 512, JSON_THROW_ON_ERROR));
            }
        } finally {
            $server->stop();
            if ($previous !== false) {
                $config->saveConfig($configPath, $previous['value'], 'default', 0);
            } else {
                $config->deleteConfig($configPath, 'default', 0);
            }
            $this->reloadConfig();
            if ($bulkUuid !== null) {
                $connection->delete($resource->getTableName('inriver_async_callback'), ['bulk_uuid = ?' => $bulkUuid]);
            }
        }
    }

    public static function callbackResponsesProvider(): array
    {
        return ['successful delivery' => [200], 'failed delivery then explicit retry' => [503]];
    }

    #[DataProvider('imageStoreConfigurationsProvider')]
    public function testImagesBulkImportsBytesUpdatesRolesAndDeletesGallery(bool $additionalStore): void
    {
        $this->requireEmptyQueue();
        $suffix = bin2hex(random_bytes(6));
        if ($additionalStore) {
            $this->createStoreFixture('inriver_api_' . $suffix);
        }
        $storeManager = self::$objectManager->get(StoreManagerInterface::class);
        $this->assertSame(!$additionalStore, $storeManager->hasSingleStore(), 'This regression test requires one initial storefront.');
        $storeCode = $storeManager->getDefaultStoreView()->getCode();
        if (getenv('INRIVER_IMAGE_TRACE') === '1') {
            $this->imageTracePath = BP . '/var/inriver-api-test-image-trace-' . $suffix . '.jsonl';
            $this->fixturePaths[] = $this->imageTracePath;
        }
        $sku = 'inriver-api-image-' . $suffix;
        $this->createProductFixture($sku);
        $filename = 'inriver-api-test-' . $suffix . '.jpg';
        $source = BP . '/pub/' . $filename;
        $this->fixturePaths[] = $source;
        $image = imagecreatetruecolor(32, 16);
        imagefill($image, 0, 0, imagecolorallocate($image, 36, 120, 150));
        $this->assertTrue(imagejpeg($image, $source, 85));
        $entryId = null;
        foreach ([[2, ['image', 'thumbnail']], [4, ['image']]] as [$position, $roles]) {
            $operation = $this->submitBulkFixture('inriver-adapter/products/images', [
                'productImage' => [
                    'sku' => $sku,
                    'images' => [[
                        'attributes' => [[
                            'position' => $position, 'roles' => $roles,
                            'storeViews' => [$storeCode],
                        ]],
                        'url' => 'https://nginx:8443/' . $filename,
                        'mimeType' => 'image/jpeg',
                        'imageId' => 'inriver-api-image-id-' . $suffix,
                        'filename' => $filename,
                    ]],
                ],
            ]);
            $this->assertSame(OperationInterface::STATUS_TYPE_COMPLETE, (int) $operation['status'], $operation['result_message']);
            $result = json_decode($operation['result_serialized_data'], true, 512, JSON_THROW_ON_ERROR);
            $this->assertSame([], $result, json_encode($result));
            $product = $this->request('GET', '/rest/' . rawurlencode($storeCode) . '/V1/products/' . $sku);
            $entries = $product['media_gallery_entries'];
            $this->assertCount(1, $entries);
            $entry = $entries[0];
            $savedPath = BP . '/pub/media/catalog/product' . $entry['file'];
            $this->fixturePaths[] = $savedPath;
            $this->fixturePaths[] = BP . '/pub/media/tmp/catalog/product' . $entry['file'];
            if ($entryId !== null) {
                $this->assertSame($entryId, (int) $entry['id']);
            }
            $entryId = (int) $entry['id'];
            $this->assertSame($position, (int) $entry['position']);
            $actualRoles = $entry['types'];
            sort($actualRoles);
            sort($roles);
            $trace = $this->imageTracePath !== null && is_file($this->imageTracePath)
                ? file_get_contents($this->imageTracePath) : '';
            $this->assertSame($roles, $actualRoles, $trace);
            $this->assertFileExists($savedPath);
            $this->assertSame(hash_file('sha256', $source), hash_file('sha256', $savedPath));
        }
        $operation = $this->submitBulkFixture('inriver-adapter/products/images', [
            'productImage' => ['sku' => $sku, 'images' => []],
        ]);
        $this->assertSame(OperationInterface::STATUS_TYPE_COMPLETE, (int) $operation['status'], $operation['result_message']);
        $product = $this->request('GET', '/rest/default/V1/products/' . $sku);
        $this->assertSame([], $product['media_gallery_entries']);
    }

    public static function imageStoreConfigurationsProvider(): array
    {
        return ['single storefront' => [false], 'multiple storefronts' => [true]];
    }

    public function testConfigurableCsvImportsVariationsAndRemovesObsoleteLinks(): void
    {
        $this->requireEmptyQueue();
        $suffix = bin2hex(random_bytes(6));
        $code = 'inriver_test_' . $suffix;
        $attribute = $this->createSelectAttributeFixture($code);
        $storeCode = self::$objectManager->get(StoreManagerInterface::class)->getDefaultStoreView()->getCode();
        $operation = $this->submitBulkFixture('inriver-adapter/products/options-by-attribute', [
            'optionsByAttribute' => [
                'attributes' => [$code],
                'options' => array_map(static fn (string $label) => [
                    'admin_value' => $label,
                    'values' => [['store_view_code' => $storeCode, 'value' => $label, 'description' => $label]],
                ], ['Small', 'Large']),
            ],
        ]);
        $this->assertSame(OperationInterface::STATUS_TYPE_COMPLETE, (int) $operation['status'], $operation['result_message']);
        $smallSku = 'inriver-api-small-' . $suffix;
        $largeSku = 'inriver-api-large-' . $suffix;
        $parentSku = 'inriver-api-configurable-' . $suffix;
        $small = $this->createProductFixture($smallSku);
        $large = $this->createProductFixture($largeSku);
        $this->createProductFixture($parentSku, ['type_id' => 'configurable']);
        $columns = [
            'sku', 'product_type', 'product_websites', 'store_view_code', 'visibility', 'attribute_set_code',
            'name', 'short_description', 'description', 'configurable_variations', 'additional_attributes', 'associated_skus',
        ];
        $parentRow = [$parentSku, 'configurable', 'base', '', 'Catalog, Search', 'Default',
            'Configurable ' . $suffix, '', '', 'sku=' . $smallSku . ',' . $code . '=Small|sku=' . $largeSku . ',' . $code . '=Large', '', ''];
        $url = $this->createCsvFixture($columns, [
            [$smallSku, 'simple', 'base', '', 'Not Visible Individually', 'Default', 'Small ' . $suffix, '', '', '', $code . '=Small', ''],
            [$largeSku, 'simple', 'base', '', 'Not Visible Individually', 'Default', 'Large ' . $suffix, '', '', '', $code . '=Large', ''],
            $parentRow,
        ]);
        $this->importCsvFixture($url);
        $parent = $this->request('GET', '/rest/default/V1/products/' . $parentSku);
        $this->assertSame('configurable', $parent['type_id']);
        $links = $parent['extension_attributes']['configurable_product_links'];
        sort($links);
        $expectedIds = [(int) $small['id'], (int) $large['id']];
        sort($expectedIds);
        $this->assertSame($expectedIds, $links);
        $options = $parent['extension_attributes']['configurable_product_options'];
        $this->assertCount(1, $options);
        $this->assertSame((int) $attribute['attribute_id'], (int) $options[0]['attribute_id']);
        $this->assertCount(2, $options[0]['values']);
        $selectOptions = $this->request('GET', '/rest/all/V1/products/attributes/' . $code . '/options');
        $optionIds = array_column($selectOptions, 'value', 'label');
        foreach ([$smallSku => 'Small', $largeSku => 'Large'] as $sku => $label) {
            $child = $this->request('GET', '/rest/default/V1/products/' . $sku);
            $values = array_column($child['custom_attributes'], 'value', 'attribute_code');
            $this->assertSame((int) $optionIds[$label], (int) $values[$code]);
        }

        $parentRow[9] = 'sku=' . $smallSku . ',' . $code . '=Small';
        $this->importCsvFixture($this->createCsvFixture($columns, [$parentRow]));
        $parent = $this->request('GET', '/rest/default/V1/products/' . $parentSku);
        $this->assertSame([(int) $small['id']], $parent['extension_attributes']['configurable_product_links']);
        $remainingChild = $this->request('GET', '/rest/default/V1/products/' . $largeSku);
        $this->assertSame($largeSku, $remainingChild['sku']);
    }

    public static function setUpBeforeClass(): void
    {
        self::$objectManager = Bootstrap::create(BP, $_SERVER)->getObjectManager();
        self::$objectManager->get(State::class)->setAreaCode('adminhtml');
        $admin = self::$objectManager->create(User::class);
        $admin->loadByUsername(getenv('MAGENTO_ADMIN_USER') ?: 'admin');
        if (!$admin->getId()) {
            throw new \RuntimeException('The local Magento administrator is required for API fixtures.');
        }

        $suffix = bin2hex(random_bytes(6));
        self::$testAdmin = self::$objectManager->create(User::class);
        try {
            self::$testAdmin->setData([
                'username' => 'inriver_api_test_' . $suffix,
                'firstname' => 'Inriver',
                'lastname' => 'API Test',
                'email' => 'inriver-api-' . $suffix . '@example.invalid',
                'password' => 'Aa9_' . bin2hex(random_bytes(20)),
                'is_active' => 1,
            ])->setRoleId($admin->getRole()->getId())->save();
            self::$token = self::$objectManager->get(UserTokenIssuerInterface::class)->create(
                new CustomUserContext((int) self::$testAdmin->getId(), UserContextInterface::USER_TYPE_ADMIN),
                self::$objectManager->create(UserTokenParametersInterface::class)
            );
        } catch (\Throwable $exception) {
            self::tearDownAfterClass();
            throw $exception;
        }
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$testAdmin !== null && self::$testAdmin->getId()) {
            try {
                self::$objectManager->get(UserTokenRevokerInterface::class)->revokeFor(
                    new CustomUserContext((int) self::$testAdmin->getId(), UserContextInterface::USER_TYPE_ADMIN)
                );
            } finally {
                self::$testAdmin->delete();
            }
        }
    }

    public function testAuthenticatedVersions(): void
    {
        $body = $this->request('GET', '/rest/all/V1/inriver-adapter/versions');

        $this->assertSame(getenv('MAGENTO_VERSION'), $body['magento_version']);
        $this->assertArrayHasKey('magento_edition', $body);
        $this->assertArrayHasKey('adapter_version', $body);
    }

    public function testBulkRequestsRejectInvalidToken(): void
    {
        $response = $this->client()->post('/rest/all/async/bulk/V1/inriver-adapter/products/category-assignments', [
            'headers' => ['Authorization' => 'Bearer inriver-invalid-test-token'],
            'json' => [[
                'productCategories' => ['sku' => 'inriver-api-test-product', 'categories' => []],
            ]],
        ]);
        $this->assertContains($response->getStatusCode(), [401, 403]);
        $body = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertArrayNotHasKey('bulk_uuid', $body);
    }

    public function testCategoryAssignmentBulkReportsMissingSku(): void
    {
        $this->requireEmptyQueue();
        $sku = 'inriver-api-missing-' . bin2hex(random_bytes(6));
        $bulk = $this->submitCategoryAssignment($sku, 'inriver-api-missing-category');
        $operation = $this->consumeBulk($bulk['bulk_uuid']);

        $this->assertSame(OperationInterface::STATUS_TYPE_NOT_RETRIABLY_FAILED, (int) $operation['status']);
        $this->assertSame(ErrorCodesDirectory::SKU_NOT_FOUND, (int) $operation['error_code']);
        $this->assertStringContainsString($sku, $operation['result_message']);
    }

    public function testCategoryAssignmentBulkPersistsProductCategory(): void
    {
        $this->requireEmptyQueue();
        $suffix = bin2hex(random_bytes(6));
        $sku = 'inriver-api-product-' . $suffix;
        $uniqueId = 'inriver-api-category-' . $suffix;
        $categoryId = null;
        $productCreated = false;
        try {
            $this->request('POST', '/rest/default/V1/products', ['product' => [
                'sku' => $sku,
                'name' => 'Inriver API Test Product',
                'attribute_set_id' => 4,
                'price' => 10,
                'status' => 1,
                'visibility' => 4,
                'type_id' => 'simple',
            ]]);
            $productCreated = true;
            $rootId = self::$objectManager->get(StoreManagerInterface::class)->getDefaultStoreView()->getRootCategoryId();
            $category = $this->request('POST', '/rest/default/V1/categories', ['category' => [
                'name' => 'Inriver API Test Category',
                'parent_id' => (int) $rootId,
                'is_active' => true,
                'include_in_menu' => false,
                'custom_attributes' => [[
                    'attribute_code' => CategoryPimUniqueId::CATEGORY_PIM_UNIQUE_ID,
                    'value' => $uniqueId,
                ]],
            ]]);
            $categoryId = (int) $category['id'];
            $bulk = $this->submitCategoryAssignment($sku, $uniqueId);
            $operation = $this->consumeBulk($bulk['bulk_uuid']);
            $this->assertSame(OperationInterface::STATUS_TYPE_COMPLETE, (int) $operation['status']);
            $product = $this->request('GET', '/rest/default/V1/products/' . rawurlencode($sku));
            $links = $product['extension_attributes']['category_links'];
            $this->assertCount(1, $links);
            $this->assertSame($categoryId, (int) $links[0]['category_id']);
            $this->assertSame(20000, (int) $links[0]['position']);
        } finally {
            if ($productCreated) {
                $this->request('DELETE', '/rest/default/V1/products/' . rawurlencode($sku));
            }
            if ($categoryId !== null) {
                $this->request('DELETE', '/rest/default/V1/categories/' . $categoryId);
            }
        }
    }

    public function testProductImportBulkUpdatesProductFromCsv(): void
    {
        $this->requireEmptyQueue();
        $suffix = bin2hex(random_bytes(6));
        $sku = 'inriver-api-csv-' . $suffix;
        $filename = 'inriver-api-test-' . $suffix . '.csv';
        $path = BP . '/pub/' . $filename;
        $importPath = self::$objectManager->get(ScopeConfigInterface::class)
            ->getValue(ImportInterface::XML_INRIVER_IMPORT_PATH_CSV);
        $archiveRoot = BP . '/var/' . ltrim((string) $importPath, '/');
        $archivesBefore = glob($archiveRoot . '/archives/*/import-*.csv') ?: [];
        $productCreated = false;
        try {
            $this->request('POST', '/rest/default/V1/products', ['product' => [
                'sku' => $sku,
                'name' => 'Before CSV Import',
                'attribute_set_id' => 4,
                'price' => 10,
                'status' => 1,
                'visibility' => 4,
                'type_id' => 'simple',
            ]]);
            $productCreated = true;
            $stream = fopen($path, 'xb');
            if ($stream === false) {
                throw new \RuntimeException('Cannot create the local CSV fixture.');
            }
            try {
                fputcsv($stream, [
                    'sku', 'product_type', 'product_websites', 'store_view_code', 'visibility',
                    'attribute_set_code', 'name', 'short_description', 'description',
                    'configurable_variations', 'additional_attributes', 'associated_skus',
                ], ',', '"', '');
                fputcsv($stream, [
                    $sku, 'simple', 'base', '', 'Catalog, Search', 'Default', 'After CSV Import',
                    '', '', '', '', '',
                ], ',', '"', '');
            } finally {
                fclose($stream);
            }
            $download = $this->client()->get('/' . $filename);
            $this->assertSame(200, $download->getStatusCode());
            $this->assertStringContainsString($sku, (string) $download->getBody());
            $bulk = $this->request('POST', '/rest/all/async/bulk/V1/inriver-adapter/products/import', [[
                'import' => ['url' => 'https://nginx:8443/' . $filename, 'managed_websites' => ['base']],
            ]], 202);
            $this->assertFalse($bulk['errors']);
            $this->assertSame('accepted', $bulk['request_items'][0]['status']);
            $operation = $this->consumeBulk($bulk['bulk_uuid']);
            $this->assertSame(OperationInterface::STATUS_TYPE_COMPLETE, (int) $operation['status']);
            $this->assertSame([], json_decode($operation['result_serialized_data'], true, 512, JSON_THROW_ON_ERROR));
            $product = $this->request('GET', '/rest/default/V1/products/' . rawurlencode($sku));
            $this->assertSame('After CSV Import', $product['name']);
            $this->assertSame(10.0, (float) $product['price']);
        } finally {
            if (is_file($path)) {
                unlink($path);
            }
            foreach (array_diff(glob($archiveRoot . '/archives/*/import-*.csv') ?: [], $archivesBefore) as $archive) {
                $contents = file_get_contents($archive);
                if ($contents !== false && str_contains($contents, $sku)) {
                    unlink($archive);
                }
            }
            if ($productCreated) {
                $this->request('DELETE', '/rest/default/V1/products/' . rawurlencode($sku));
            }
        }
    }

    #[DataProvider('bulkRequestsProvider')]
    public function testBulkRequestsRequireAuthentication(string $route, array $payload): void
    {
        $response = $this->client()->post('/rest/all/async/bulk/V1/' . $route, ['json' => [$payload]]);

        $this->assertContains($response->getStatusCode(), [401, 403]);
        $body = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertArrayHasKey('message', $body);
        $this->assertArrayNotHasKey('bulk_uuid', $body);
    }

    public static function bulkRequestsProvider(): array
    {
        return [
            'product import' => ['inriver-adapter/products/import', [
                'import' => ['url' => 'http://nginx:8080/inriver-api-test.csv', 'managed_websites' => ['base']],
            ]],
            'relations import' => ['inriver-adapter/products/import/relations', [
                'import' => ['url' => 'http://nginx:8080/inriver-api-test-relations.csv'],
            ]],
            'attribute options' => ['inriver-adapter/products/options-by-attribute', [
                'optionsByAttribute' => [
                    'attributes' => ['size'],
                    'options' => [[
                        'admin_value' => 'api-test-size',
                        'values' => [['store_view_code' => 'default', 'value' => 'API Test Size', 'description' => 'API Test Size']],
                    ]],
                ],
            ]],
            'product image' => ['inriver-adapter/products/images', [
                'productImage' => [
                    'sku' => 'inriver-api-test-product',
                    'images' => [[
                        'attributes' => [['position' => 0, 'roles' => ['image', 'thumbnail'], 'storeViews' => ['default']]],
                        'url' => 'http://nginx:8080/inriver-api-test.jpg',
                        'mimeType' => 'image/jpeg',
                        'imageId' => 'inriver-api-test-image',
                        'filename' => 'inriver-api-test.jpg',
                    ]],
                ],
            ]],
            'category assignments' => ['inriver-adapter/products/category-assignments', [
                'productCategories' => [
                    'sku' => 'inriver-api-test-product',
                    'categories' => [['categoryUniqueId' => 'inriver-api-test-category', 'position' => 20000]],
                ],
            ]],
        ];
    }

    private function reloadConfig(): void
    {
        self::$objectManager->get(TypeListInterface::class)->cleanType('config');
        self::$objectManager->get(ReinitableConfigInterface::class)->reinit();
    }

    private function callbackNotified(string $uuid): int
    {
        $resource = self::$objectManager->get(ResourceConnection::class);

        return (int) $resource->getConnection()->fetchOne($resource->getConnection()->select()
            ->from($resource->getTableName('inriver_async_callback'), 'inriver_notified')->where('bulk_uuid = ?', $uuid));
    }

    private function createSelectAttributeFixture(string $code): array
    {
        $attribute = $this->request('POST', '/rest/default/V1/products/attributes', ['attribute' => [
            'attribute_code' => $code,
            'frontend_input' => 'select',
            'default_frontend_label' => 'Inriver API Test Size',
            'backend_type' => 'int',
            'is_required' => false,
            'is_user_defined' => true,
            'scope' => 'global',
            'is_unique' => false,
            'is_visible' => true,
        ]]);
        $this->fixtureAttributes[] = $code;
        $resource = self::$objectManager->get(ResourceConnection::class);
        $connection = $resource->getConnection();
        $groupId = $connection->fetchOne($connection->select()
            ->from($resource->getTableName('eav_attribute_group'), 'attribute_group_id')
            ->where('attribute_set_id = ?', 4)->order('sort_order ASC')->limit(1));
        $this->request('POST', '/rest/default/V1/products/attribute-sets/attributes', [
            'attributeSetId' => 4, 'attributeGroupId' => (int) $groupId, 'attributeCode' => $code, 'sortOrder' => 200,
        ]);

        return $attribute;
    }

    private function createStoreFixture(string $code): Store
    {
        $defaultStore = self::$objectManager->get(StoreManagerInterface::class)->getDefaultStoreView();
        $store = self::$objectManager->create(Store::class);
        $store->setCode($code)->setName('Inriver API Test Store')->setWebsiteId($defaultStore->getWebsiteId())
            ->setGroupId($defaultStore->getStoreGroupId())->setIsActive(1)->save();
        $this->fixtureStoreIds[] = (int) $store->getId();
        self::$objectManager->get(StoreManagerInterface::class)->reinitStores();

        return $store;
    }

    private function deleteStoreFixture(int $storeId): void
    {
        $registry = self::$objectManager->get(Registry::class);
        $previous = $registry->registry('isSecureArea');
        $registry->unregister('isSecureArea');
        $registry->register('isSecureArea', true);
        try {
            self::$objectManager->create(Store::class)->load($storeId)->delete();
        } finally {
            $registry->unregister('isSecureArea');
            if ($previous !== null) {
                $registry->register('isSecureArea', $previous);
            }
        }
    }

    private function optionLabels(int $optionId): array
    {
        $resource = self::$objectManager->get(ResourceConnection::class);

        return $resource->getConnection()->fetchPairs($resource->getConnection()->select()
            ->from($resource->getTableName('eav_attribute_option_value'), ['store_id', 'value'])
            ->where('option_id = ?', $optionId));
    }

    private function submitBulkFixture(string $route, array $payload, array $headers = []): array
    {
        $response = $this->client()->post('/rest/all/async/bulk/V1/' . $route, [
            'headers' => array_merge(['Authorization' => 'Bearer ' . self::$token], $headers),
            'json' => [$payload],
        ]);
        $bulk = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame(202, $response->getStatusCode(), json_encode($bulk));
        $this->assertFalse($bulk['errors']);
        $this->assertSame('accepted', $bulk['request_items'][0]['status']);

        return $this->consumeBulk($bulk['bulk_uuid']);
    }

    private function createProductFixture(string $sku, array $data = []): array
    {
        $product = $this->request('POST', '/rest/default/V1/products', ['product' => array_replace([
            'sku' => $sku,
            'name' => 'Inriver API Test Product',
            'attribute_set_id' => 4,
            'price' => 10,
            'status' => 1,
            'visibility' => 4,
            'type_id' => 'simple',
            'extension_attributes' => ['website_ids' => [1]],
            'custom_attributes' => [['attribute_code' => 'url_key', 'value' => $sku]],
        ], $data)]);
        $this->fixtureSkus[] = $sku;

        return $product;
    }

    private function createCsvFixture(array $columns, array $rows): string
    {
        $filename = 'inriver-api-test-' . bin2hex(random_bytes(6)) . '.csv';
        $path = BP . '/pub/' . $filename;
        $stream = fopen($path, 'xb');
        if ($stream === false) {
            throw new \RuntimeException('Cannot create the local CSV fixture.');
        }
        $this->fixturePaths[] = $path;
        try {
            foreach (array_merge([$columns], $rows) as $row) {
                fputcsv($stream, $row, ',', '"', '');
            }
        } finally {
            fclose($stream);
        }

        return 'https://nginx:8443/' . $filename;
    }

    private function importCsvFixture(string $url, string $route = 'inriver-adapter/products/import'): void
    {
        $bulk = $this->request('POST', '/rest/all/async/bulk/V1/' . $route, [[
            'import' => ['url' => $url, 'managed_websites' => ['base']],
        ]], 202);
        $this->assertFalse($bulk['errors']);
        $this->assertSame('accepted', $bulk['request_items'][0]['status']);
        $operation = $this->consumeBulk($bulk['bulk_uuid']);
        $this->assertSame(OperationInterface::STATUS_TYPE_COMPLETE, (int) $operation['status'], $operation['result_message']);
        $result = json_decode($operation['result_serialized_data'], true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame([], $result, json_encode($result));
    }

    private function requireEmptyQueue(): void
    {
        $pending = self::$objectManager->get(CollectionFactory::class)->create()
            ->addFieldToFilter('status', OperationInterface::STATUS_TYPE_OPEN)->getSize();
        $this->assertSame(0, (int) $pending, 'Process existing local bulk operations before running API tests.');
    }

    private function submitCategoryAssignment(string $sku, string $uniqueId): array
    {
        $bulk = $this->request('POST', '/rest/all/async/bulk/V1/inriver-adapter/products/category-assignments', [[
            'productCategories' => [
                'sku' => $sku,
                'categories' => [['categoryUniqueId' => $uniqueId, 'position' => 20000]],
            ],
        ]], 202);
        $this->assertFalse($bulk['errors']);
        $this->assertCount(1, $bulk['request_items']);
        $this->assertSame('accepted', $bulk['request_items'][0]['status']);

        return $bulk;
    }

    private function consumeBulk(string $uuid): array
    {
        $process = new Process([
            PHP_BINARY, '-d', 'openssl.cafile=/opt/tls/local.crt',
            $this->imageTracePath === null ? 'bin/magento' : __DIR__ . '/Fixtures/ImageTraceConsumer.php',
            'queue:consumers:start', 'async.operations.all', '--max-messages=1',
        ], BP, $this->imageTracePath === null ? null : ['INRIVER_IMAGE_TRACE_FILE' => $this->imageTracePath], null, 60);
        $process->mustRun();
        $status = $this->request('GET', '/rest/all/V1/bulk/' . rawurlencode($uuid) . '/detailed-status');
        $this->assertCount(1, $status['operations_list']);

        return $status['operations_list'][0];
    }

    private function request(string $method, string $path, ?array $payload = null, int $expectedStatus = 200): array
    {
        $options = ['headers' => ['Authorization' => 'Bearer ' . self::$token]];
        if ($payload !== null) {
            $options['json'] = $payload;
        }
        $response = $this->client()->request($method, $path, $options);
        $body = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame($expectedStatus, $response->getStatusCode(), json_encode($body));

        return is_array($body) ? $body : [];
    }

    private function client(): Client
    {
        $baseUrl = getenv('INRIVER_API_BASE_URL') ?: 'http://nginx:8080';
        $host = parse_url($baseUrl, PHP_URL_HOST);
        if (!in_array($host, ['nginx', 'localhost', '127.0.0.1'], true)) {
            throw new \RuntimeException('API tests may only target the local Docker environment.');
        }

        return new Client(['base_uri' => $baseUrl, 'http_errors' => false, 'timeout' => 30, 'allow_redirects' => false]);
    }
}