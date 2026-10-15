<?php

/**
 * @author InRiver <inriveradapters@inriver.com>
 * @copyright Copyright (c) InRiver (https://www.inriver.com/)
 * @link https://www.inriver.com/
 */

declare(strict_types=1);

namespace Inriver\Adapter\Test\Unit\Helper;

use GuzzleHttp\Psr7\Response;
use Inriver\Adapter\Api\CallbackOperationRepositoryInterface;
use Inriver\Adapter\Api\CallbackRepositoryInterface;
use Inriver\Adapter\Api\Data\CallbackOperationInterface;
use Inriver\Adapter\Helper\HttpRequest;
use Inriver\Adapter\Helper\InriverCallback;
use Inriver\Adapter\Logger\Logger;
use Inriver\Adapter\Model\Data\Callback;
use Inriver\Adapter\Model\Data\CallbackOperation;
use InvalidArgumentException;
use Magento\Framework\Api\SearchResultsInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Bulk\OperationInterface;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Serialize\Serializer\Json;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class InriverCallbackTest extends TestCase
{
    private const BULK_UUID = '64bd75e0-afcc-41a7-ac77-3c0d91818bd7';
    private const MESSAGE = 'This is a message';
    private const MESSAGE_SERIALIZE = '{"status":1000,"messages":"Service execution success"}';
    private const ERROR_CODE = 500;
    private const CALLBACK_ID = 123;
    private const OPERATION_ID = 555;
    private const OPERATION_KEY = 0;

    /** @var \Inriver\Adapter\Api\CallbackRepositoryInterface|\Inriver\Adapter\Test\Unit\Helper\MockObject */
    private $callbackRepository;

    /** @var \Inriver\Adapter\Model\Data\Callback|\Inriver\Adapter\Test\Unit\Helper\MockObject */
    private $callback;

    /** @var \Inriver\Adapter\Api\CallbackOperationRepositoryInterface|\Inriver\Adapter\Test\Unit\Helper\MockObject */
    private $callbackOperationRepository;

    /** @var \Magento\Framework\Serialize\Serializer\Json|\Inriver\Adapter\Test\Unit\Helper\MockObject */
    private $json;

    /** @var \Inriver\Adapter\Helper\HttpRequest|\Inriver\Adapter\Test\Unit\Helper\MockObject */
    private $httpRequest;

    /** @var \Magento\Framework\App\Config\ScopeConfigInterface|\Inriver\Adapter\Test\Unit\Helper\MockObject */
    private $scopeConfig;

    /** @var \Inriver\Adapter\Logger\Logger|\Inriver\Adapter\Test\Unit\Helper\MockObject */
    private $logger;

    public function testNoCallbackToHandle(): void
    {
        $this->callbackRepository
            ->expects($this->once())
            ->method('getByBulkUuid')
            ->willThrowException(new NoSuchEntityException());

        $this->callback->expects($this->never())->method('getCallBackId');

        /** @noinspection PhpUnhandledExceptionInspection */
        $this->getInriverCallback()->createCallbackOperationAfterAsyncOperations(
            self::OPERATION_ID,
            self::OPERATION_KEY,
            self::BULK_UUID,
            OperationInterface::STATUS_TYPE_NOT_RETRIABLY_FAILED,
            self::ERROR_CODE,
            self::MESSAGE
        );
    }

    public function testCreateCallbackIdNull(): void
    {
        $this->callback->method('getCallBackId')
            ->willReturn(null);

        /** @noinspection PhpUnhandledExceptionInspection */
        $this->getInriverCallback()->createCallbackOperationAfterAsyncOperations(
            self::OPERATION_ID,
            self::OPERATION_KEY,
            self::BULK_UUID,
            OperationInterface::STATUS_TYPE_NOT_RETRIABLY_FAILED,
            self::ERROR_CODE,
            self::MESSAGE
        );

        $this->callbackOperationRepository->expects($this->never())->method('save');
    }

    /**
     * @param int|null $errorCode
     * @param int $status
     * @param bool $setErrorCountCalled
     * @param bool $setMessagesCalled
     * @param string|null $resultData
     *
     * @throws \Magento\Framework\Exception\NoSuchEntityException
     * @throws \Magento\Framework\Exception\CouldNotSaveException
     * @throws \Magento\Framework\Exception\LocalizedException
     *
     */
    #[DataProvider('callbackScenarios')]
    public function testCreateCallbackId(
        ?int $errorCode,
        int $status,
        bool $setErrorCountCalled,
        bool $setMessagesCalled,
        ?string $resultData
    ): void {
        $this->callback->method('getCallBackId')
            ->willReturn(self::CALLBACK_ID);

        $callbackOperation = $this->createMock(CallbackOperationInterface::class);

        if ($setErrorCountCalled) {
            $callbackOperation->expects($this->once())->method('setErrorCount');
        }

        if ($setMessagesCalled) {
            $callbackOperation->expects($this->once())->method('setMessages');
        } else {
            $callbackOperation->expects($this->never())->method('setMessages');
        }

        $this->callbackOperationRepository->method('getByOperationId')->willReturn($callbackOperation);

        $this->callbackOperationRepository->expects($this->once())->method('save');

        if ($resultData !== null) {
            $this->json->method('unserialize')->willReturn(['some-stuff']);
        }

        $this->getInriverCallback()->createCallbackOperationAfterAsyncOperations(
            self::OPERATION_ID,
            self::OPERATION_KEY,
            self::BULK_UUID,
            $status,
            $errorCode,
            self::MESSAGE,
            $resultData
        );
    }

    /**
     * @throws \Magento\Framework\Exception\NoSuchEntityException
     * @throws \Magento\Framework\Exception\CouldNotSaveException
     * @throws \Magento\Framework\Exception\LocalizedException
     */
    public function testCreateCallbackInvalidResultDataLogExceptionButReturnDataTo(): void
    {
        $resultData = ['error' => 'Error, resultData should be a json formatted string'];
        $this->callback->method('getCallBackId')->willReturn(self::CALLBACK_ID);

        $callbackOperation = $this->createMock(CallbackOperationInterface::class);
        $this->callbackOperationRepository->method('getByOperationId')->willReturn($callbackOperation);

        $this->callbackOperationRepository->expects($this->once())->method('save');

        $exception = new InvalidArgumentException();
        $this->json->method('unserialize')->willThrowException($exception);

        $callbackOperation->expects($this->once())->method('setMessages');

        $this->logger->expects($this->once())->method('log');

        $this->getInriverCallback()->createCallbackOperationAfterAsyncOperations(
            self::OPERATION_ID,
            self::OPERATION_KEY,
            self::BULK_UUID,
            OperationInterface::STATUS_TYPE_COMPLETE,
            null,
            self::MESSAGE,
            $resultData
        );
    }

    /**
     * @return string[]
     */
    public static function callbackScenarios(): array
    {
        return [
            [
                'errorCode' => null,
                'status' => OperationInterface::STATUS_TYPE_COMPLETE,
                'setErrorCountCalled' => false,
                'setMessagesCalled' => false,
                'resultData' => null,
            ],
            [
                'errorCode' => null,
                'status' => OperationInterface::STATUS_TYPE_RETRIABLY_FAILED,
                'setErrorCountCalled' => true,
                'setMessagesCalled' => true,
                'resultData' => null,
            ],
            [
                'errorCode' => null,
                'status' => OperationInterface::STATUS_TYPE_NOT_RETRIABLY_FAILED,
                'setErrorCountCalled' => true,
                'setMessagesCalled' => true,
                'resultData' => null,
            ],
            [
                'errorCode' => null,
                'status' => OperationInterface::STATUS_TYPE_OPEN,
                'setErrorCountCalled' => true,
                'setMessagesCalled' => true,
                'resultData' => null,
            ],
            [
                'errorCode' => null,
                'status' => OperationInterface::STATUS_TYPE_REJECTED,
                'setErrorCountCalled' => true,
                'setMessagesCalled' => true,
                'resultData' => null,
            ],
            [
                'errorCode' => 123,
                'status' => OperationInterface::STATUS_TYPE_COMPLETE,
                'setErrorCountCalled' => true,
                'setMessagesCalled' => true,
                'resultData' => null,
            ],
            [
                'errorCode' => null,
                'status' => OperationInterface::STATUS_TYPE_COMPLETE,
                'setErrorCountCalled' => false,
                'setMessagesCalled' => false,
                'resultData' => null,
            ],
            [
                'errorCode' => null,
                'status' => OperationInterface::STATUS_TYPE_COMPLETE,
                'setErrorCountCalled' => false,
                'setMessagesCalled' => true,
                'resultData' => '[{"error_code":"attrCodeDoesNotExist"}]',
            ],
        ];
    }

    public function testReturnWithoutApiKey(): void
    {
        $this->scopeConfig->method('getValue')->willReturn('');

        $this->callback->expects($this->never())->method('getCallBackId');

        $this->logger->expects($this->once())->method('log');

        $this->getInriverCallback()->returnResponseToInriverAfterAsyncOperations(self::BULK_UUID);
    }

    public function testReturnWithCallbackErrorIsLog(): void
    {
        $this->scopeConfig->method('getValue')->willReturn('error-key');

        $callbackOperation = $this->createMock(CallbackOperationInterface::class);
        $callbackOperation->expects($this->once())->method('getErrorCount')->willReturn(1);
        $callbackOperation->expects($this->once())->method('getMessages')->willReturn('A message');

        $searchResults = $this->createMock(SearchResultsInterface::class);
        $searchResults->method('getItems')->willReturn([$callbackOperation]);

        $this->callback->expects($this->once())->method('getCallBackId')->willReturn(1);
        $this->callback->expects($this->exactly(2))->method('getNumberOfOperations')->willReturn(1);
        $this->callbackOperationRepository->method('getListByCallbackId')->willReturn($searchResults);

        $response = $this->createMock(Response::class);
        $response->method('getStatusCode')->willReturn(400);

        $this->httpRequest->expects($this->once())->method('sendRequest')->willReturn($response);

        $this->logger
            ->expects($this->once())
            ->method('log')
            ->with('error', $this->stringContains('400'));

        /** @noinspection PhpUnhandledExceptionInspection */
        $this->getInriverCallback()->returnResponseToInriverAfterAsyncOperations(
            self::BULK_UUID
        );
    }

    public function testReturnWithoutCallback(): void
    {
        $this->scopeConfig->method('getValue')->willReturn('api-keu');

        $this->callbackRepository
            ->expects($this->once())
            ->method('getByBulkUuid')
            ->willThrowException(new NoSuchEntityException());

        $this->callback->expects($this->never())->method('getCallBackId');

        /** @noinspection PhpUnhandledExceptionInspection */
        $this->getInriverCallback()->returnResponseToInriverAfterAsyncOperations(
            self::BULK_UUID
        );
    }

    public function testReturnWithNoCallbackId(): void
    {
        $this->scopeConfig->method('getValue')->willReturn('api-key');

        $this->callback->expects($this->once())->method('getCallBackId')->willReturn(null);

        $this->callbackOperationRepository->expects($this->never())->method('getListByCallbackId');

        /** @noinspection PhpUnhandledExceptionInspection */
        $this->getInriverCallback()->returnResponseToInriverAfterAsyncOperations(
            self::BULK_UUID
        );
    }

    public function testReturnWithCallbackButNotDone(): void
    {
        $this->scopeConfig->method('getValue')->willReturn('api-key');

        $searchResults = $this->createMock(SearchResultsInterface::class);
        $searchResults->method('getItems')->willReturn([1]);

        $this->callback->expects($this->once())->method('getCallBackId')->willReturn(1);
        $this->callback->expects($this->once())->method('getNumberOfOperations')->willReturn(2);
        $this->callbackOperationRepository->method('getListByCallbackId')->willReturn($searchResults);

        $this->callback->expects($this->never())->method('getCallBackUrl');

        /** @noinspection PhpUnhandledExceptionInspection */
        $this->getInriverCallback()->returnResponseToInriverAfterAsyncOperations(
            self::BULK_UUID
        );
    }

    public function testReturnWithCallback(): void
    {
        $this->scopeConfig->method('getValue')->willReturn('api-key');

        $callbackOperation = $this->createMock(CallbackOperationInterface::class);
        $callbackOperation->expects($this->once())->method('getErrorCount')->willReturn(1);
        $callbackOperation->expects($this->once())->method('getMessages')->willReturn('A message');

        $searchResults = $this->createMock(SearchResultsInterface::class);
        $searchResults->method('getItems')->willReturn([$callbackOperation]);

        $this->callback->expects($this->once())->method('getCallBackId')->willReturn(1);
        $this->callback->expects($this->exactly(2))->method('getNumberOfOperations')->willReturn(1);
        $this->callbackOperationRepository->method('getListByCallbackId')->willReturn($searchResults);

        $response = $this->createMock(Response::class);
        $response->expects($this->once())->method('getStatusCode')->willReturn(200);

        $this->httpRequest->expects($this->once())->method('sendRequest')->willReturn($response);

        /** @noinspection PhpUnhandledExceptionInspection */
        $this->getInriverCallback()->returnResponseToInriverAfterAsyncOperations(
            self::BULK_UUID
        );
    }

    protected function setUp(): void
    {
        $this->httpRequest = $this->createMock(HttpRequest::class);
        $this->json = $this->createMock(Json::class);
        $this->json->method('serialize')->willReturn(self::MESSAGE_SERIALIZE);
        $this->scopeConfig = $this->createMock(ScopeConfigInterface::class);
        $this->logger = $this->createMock(Logger::class);

        $this->callback = $this->createMock(Callback::class);

        $this->callbackRepository = $this->createMock(CallbackRepositoryInterface::class);
        $this->callbackRepository->method('getByBulkUuid')->willReturn($this->callback);

        $this->callbackOperationRepository =
            $this->createMock(CallbackOperationRepositoryInterface::class);

        $callbackOperation = $this->createPartialMock(
            CallbackOperation::class,
            []
        );
        $searchResults = $this->createMock(SearchResultsInterface::class);
        $searchResults->method('getItems')->willReturn([$callbackOperation]);
    }

    /**
     * @return \Inriver\Adapter\Helper\InriverCallback
     */
    private function getInriverCallback(): InriverCallback
    {
        return new InriverCallback(
            $this->callbackRepository,
            $this->callbackOperationRepository,
            $this->httpRequest,
            $this->json,
            $this->scopeConfig,
            $this->logger
        );
    }
}
