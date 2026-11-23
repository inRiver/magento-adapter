<?php

/**
 * @author InRiver <inriveradapters@inriver.com>
 * @copyright Copyright (c) InRiver (https://www.inriver.com/)
 * @link https://www.inriver.com/
 * This file applies minor modifications to native Magento code.
 * It should be kept in sync with the latest Magento version.
 */

declare(strict_types=1);

namespace Inriver\Adapter\Model;

use Exception;
use Magento\AsynchronousOperations\Api\Data\AsyncResponseInterfaceFactory;
use Magento\AsynchronousOperations\Api\Data\ItemStatusInterface;
use Magento\AsynchronousOperations\Api\Data\ItemStatusInterfaceFactory;
use Magento\AsynchronousOperations\Model\ResourceModel\Operation\OperationRepository;
use Magento\Authorization\Model\UserContextInterface;
use Magento\Framework\App\ObjectManager;
use Magento\Framework\Bulk\BulkManagementInterface;
use Magento\Framework\DataObject\IdentityGeneratorInterface;
use Magento\Framework\Encryption\Encryptor;
use Magento\Framework\Exception\BulkException;
use Magento\Framework\Exception\LocalizedException;
use Psr\Log\LoggerInterface;

use function __;

/**
 * Class MassSchedule used for adding multiple entities as Operations to Bulk Management with the status tracking
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) Suppressed without refactoring to not introduce BiC
 */
class MassSchedule extends \Magento\AsynchronousOperations\Model\MassSchedule
{
    /** @var \Magento\Framework\DataObject\IdentityGeneratorInterface */
    private $identityService;

    /** @var \Magento\AsynchronousOperations\Api\Data\AsyncResponseInterfaceFactory */
    private $asyncResponseFactory;

    /** @var \Magento\AsynchronousOperations\Api\Data\ItemStatusInterfaceFactory */
    private $itemStatusInterfaceFactory;

    /** @var \Magento\Framework\Bulk\BulkManagementInterface */
    private $bulkManagement;

    /** @var \Psr\Log\LoggerInterface */
    private $logger;

    /** @var \Magento\AsynchronousOperations\Model\ResourceModel\Operation\OperationRepository */
    private $operationRepository;

    /** @var \Magento\Authorization\Model\UserContextInterface */
    private $userContext;

    /** @var \Magento\Framework\Encryption\Encryptor */
    private $encryptor;

    /**
     * Initialize dependencies.
     *
     * @param \Magento\Framework\DataObject\IdentityGeneratorInterface $identityService
     * @param \Magento\AsynchronousOperations\Api\Data\ItemStatusInterfaceFactory $itemStatusInterfaceFactory
     * @param \Magento\AsynchronousOperations\Api\Data\AsyncResponseInterfaceFactory $asyncResponseFactory
     * @param \Magento\Framework\Bulk\BulkManagementInterface $bulkManagement
     * @param \Psr\Log\LoggerInterface $logger
     * @param \Magento\AsynchronousOperations\Model\ResourceModel\Operation\OperationRepository $operationRepository
     * @param \Magento\Authorization\Model\UserContextInterface|null $userContext
     * @param \Magento\Framework\Encryption\Encryptor|null $encryptor
     */
    public function __construct(
        IdentityGeneratorInterface $identityService,
        ItemStatusInterfaceFactory $itemStatusInterfaceFactory,
        AsyncResponseInterfaceFactory $asyncResponseFactory,
        BulkManagementInterface $bulkManagement,
        LoggerInterface $logger,
        OperationRepository $operationRepository,
        ?UserContextInterface $userContext = null,
        ?Encryptor $encryptor = null
    ) {
        $this->identityService = $identityService;
        $this->itemStatusInterfaceFactory = $itemStatusInterfaceFactory;
        $this->asyncResponseFactory = $asyncResponseFactory;
        $this->bulkManagement = $bulkManagement;
        $this->logger = $logger;
        $this->operationRepository = $operationRepository;
        $this->userContext = $userContext ?
            $userContext :
            ObjectManager::getInstance()->get(UserContextInterface::class);
        $this->encryptor = $encryptor ? $encryptor : ObjectManager::getInstance()->get(Encryptor::class);
    }

    /**
     * Overwrite Magento
     * Schedule new bulk operation based on the list of entities
     *
     * @param string $topicName
     * @param string[] $entitiesArray
     * @param string|null $groupId
     * @param string|null $userId
     *
     * @return \Magento\AsynchronousOperations\Api\Data\AsyncResponseInterface
     * @throws \Magento\Framework\Exception\BulkException
     * @throws \Magento\Framework\Exception\LocalizedException
     */
    public function publishMass(
        $topicName,
        array $entitiesArray,
        $groupId = null,
        $userId = null
    ) {
        $bulkDescription = __('Topic %1', $topicName);

        if ($userId === null) {
            $userId = $this->userContext->getUserId();
        }

        if ($groupId === null) {
            $groupId = $this->identityService->generateId();

            /* create new bulk without operations */
            if (!$this->bulkManagement->scheduleBulk($groupId, [], $bulkDescription, $userId)) {
                throw new LocalizedException(
                    __('Something went wrong while processing the request.')
                );
            }
        }

        $operations = [];
        $requestItems = [];
        $bulkException = new BulkException();

        foreach ($entitiesArray as $key => $entityParams) {
            /** @var \Magento\AsynchronousOperations\Api\Data\ItemStatusInterface $requestItem */
            $requestItem = $this->itemStatusInterfaceFactory->create();

            $operation = null;
            try {
                $operation = $this->operationRepository->createByTopic($topicName, $entityParams, $groupId);
                $operations[] = $operation;
                // InRiver custom code start
                /** @var \Magento\AsynchronousOperations\Api\Data\OperationInterface $operation */
                $requestItem->setId($operation->getId());
                // InRiver custom code end
                $requestItem->setStatus(ItemStatusInterface::STATUS_ACCEPTED);
                $requestItem->setDataHash(
                    $this->encryptor->hash($operation->getSerializedData(), Encryptor::HASH_VERSION_SHA256)
                );
                $requestItems[] = $requestItem;
            } catch (Exception $exception) {
                $this->logger->error($exception);
                // InRiver custom code start
                if ($operation !== null) {
                    $requestItem->setId($operation->getId());
                }
                // InRiver custom code end
                $requestItem->setStatus(ItemStatusInterface::STATUS_REJECTED);
                $requestItem->setErrorMessage($exception);
                $requestItem->setErrorCode($exception);
                $requestItems[] = $requestItem;
                $bulkException->addException(new LocalizedException(
                    __('Error processing %key element of input data', ['key' => $key]),
                    $exception
                ));
            }
        }

        if (!$this->bulkManagement->scheduleBulk($groupId, $operations, $bulkDescription, $userId)) {
            throw new LocalizedException(
                __('Something went wrong while processing the request.')
            );
        }

        /** @var \Magento\AsynchronousOperations\Api\Data\AsyncResponseInterface $asyncResponse */
        $asyncResponse = $this->asyncResponseFactory->create();
        $asyncResponse->setBulkUuid($groupId);
        $asyncResponse->setRequestItems($requestItems);

        if ($bulkException->wasErrorAdded()) {
            $asyncResponse->setErrors(true);
            $bulkException->addData($asyncResponse);

            throw $bulkException;
        } else {
            $asyncResponse->setErrors(false);
        }

        return $asyncResponse;
    }
}
