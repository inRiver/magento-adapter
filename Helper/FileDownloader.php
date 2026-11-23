<?php

/**
 * @author InRiver <inriveradapters@inriver.com>
 * @copyright Copyright (c) InRiver (https://www.inriver.com/)
 * @link https://www.inriver.com/
 */

declare(strict_types=1);

namespace Inriver\Adapter\Helper;

use Inriver\Adapter\Api\Data\ImportInterface;
use Inriver\Adapter\Logger\Logger;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Filesystem;
use Magento\Framework\Filesystem\Driver\File as FileDriver;
use Magento\Framework\Filesystem\DriverPool;
use Magento\Framework\Filesystem\File\ReadFactory;
use Magento\Framework\Filesystem\Io\File;
use Throwable;

use function __;
use function array_key_exists;
use function preg_match;
use function str_replace;

/**
 * Class FileDownloader
 * File downloader helper to create the csv file when receiving an import
 */
class FileDownloader
{
    /** @var \Magento\Framework\Filesystem\Directory\WriteInterface */
    protected $directory;

    /** @var \Magento\Framework\Filesystem\File\ReadFactory */
    protected $readFactory;

    /** @var \Magento\Framework\Filesystem\Io\File */
    protected $file;

    /** @var \Magento\Framework\Filesystem\Driver\File */
    protected $fileDriver;

    /** @var \Inriver\Adapter\Logger\Logger */
    protected $logger;

    /** @var \Magento\Framework\App\Config\ScopeConfigInterface */
    protected $scopeConfig;

    /**
     * @param \Magento\Framework\Filesystem $filesystem
     * @param \Magento\Framework\Filesystem\File\ReadFactory $readFactory
     * @param \Magento\Framework\Filesystem\Io\File $file
     * @param \Magento\Framework\Filesystem\Driver\File $fileDriver
     * @param \Magento\Framework\App\Config\ScopeConfigInterface $scopeConfig
     * @param \Inriver\Adapter\Logger\Logger $logger
     *
     * @throws \Magento\Framework\Exception\FileSystemException
     */
    public function __construct(
        Filesystem $filesystem,
        ReadFactory $readFactory,
        File $file,
        FileDriver $fileDriver,
        ScopeConfigInterface $scopeConfig,
        Logger $logger
    ) {
        $this->directory = $filesystem->getDirectoryWrite(DirectoryList::VAR_DIR);
        $this->readFactory = $readFactory;
        $this->file = $file;
        $this->fileDriver = $fileDriver;
        $this->scopeConfig = $scopeConfig;
        $this->logger = $logger;
    }

    /**
     * Download a file from an url
     *
     * @param $url
     * @param $destination
     *
     * @return int
     * @throws \Magento\Framework\Exception\LocalizedException
     * @throws \Magento\Framework\Exception\FileSystemException
     */
    public function download(string $url, string $destination): int
    {
        if (!preg_match('/\bhttps:\/\//i', $url, $matches)) {
            throw new LocalizedException(
                __('Source URL should start with https')
            );
        }

        $normalizedUrl = str_replace($matches[0], '', $url);
        $driver = DriverPool::HTTPS;

        if (!$this->createDestinationFolder($destination)) {
            throw new LocalizedException(__('Cannot create target directory.'));
        }

        if (!$this->directory->isWritable($this->fileDriver->getParentDirectory($destination))) {
            throw new LocalizedException(
                __('Target directory must be writable.')
            );
        }

        $attempts = 1;
        $retryAfter = (int) $this->getAttemptSleep();
        $maxAttempt = (int) $this->getMaxAttempt();
        while (true) {
            try {
                return $this->directory->writeFile(
                    $destination,
                    $this->readFactory->create($normalizedUrl, $driver)->readAll()
                );
            } catch (Throwable $e) {
                if ($attempts < $maxAttempt) {
                    $this->logger->info(__('Cannot download file from %1: %2 (%3)', $url, $e->getMessage(), $attempts + 1));
                    sleep($retryAfter);
                    $retryAfter *= 2;
                    $attempts++;

                    continue;
                }

                throw new LocalizedException(
                    __('Cannot download file from %1: %2', $url, $e->getMessage()),
                    null,
                    ErrorCodesDirectory::CANNOT_DOWNLOAD_CSV_FILE
                );
            }
        }
    }

    /**
     * @param $url
     *
     * @return string
     * @throws \Magento\Framework\Exception\LocalizedException
     * @throws Throwable
     */
    public function getRemoteFileContent(string $url): string
    {
        if (!preg_match('/\bhttp(?P<isHttps>s)?:\/\//i', $url, $matches)) {
            throw new LocalizedException(
                __('Source URL should start with http or https'),
                null,
                ErrorCodesDirectory::INVALID_URL
            );
        }

        $driver = array_key_exists('isHttps', $matches) ? DriverPool::HTTPS : DriverPool::HTTP;
        $normalizedUrl = str_replace($matches[0], '', $url);

        $attempts = 1;
        $retryAfter = (int) $this->getAttemptSleepImages();
        $maxAttempt = (int) $this->getMaxAttemptImages();
        $data = '';
        do {
            try {
                $data = $this->readFactory->create($normalizedUrl, $driver)->readAll();
            } catch (Throwable $e) {
                if ($attempts < $maxAttempt) {
                    $this->logger->info(__('Cannot download file from %1: %2 (%3)', $url, $e->getMessage(), $attempts + 1));
                    sleep($retryAfter);
                    $retryAfter *= 2;
                    $attempts++;

                    continue;
                }

                throw $e;
            }
            break;
        } while ($attempts <= $maxAttempt);

        return $data;
    }

    /**
     * Create destination folder
     *
     * @param string $destination
     *
     * @return bool
     * @throws \Magento\Framework\Exception\FileSystemException
     */
    protected function createDestinationFolder(string $destination): bool
    {
        $this->file->setAllowCreateFolders(true);

        return $this->directory->create($this->fileDriver->getParentDirectory($destination));
    }

    public function getMaxAttempt()
    {
        return $this->scopeConfig->getValue(ImportInterface::XML_INRIVER_MAX_DOWNLOAD_RETRY_ATTEMPT);
    }

    public function getAttemptSleep()
    {
        return $this->scopeConfig->getValue(ImportInterface::XML_INRIVER_INITIAL_DOWNLOAD_RETRY_SLEEP);
    }

    public function getMaxAttemptImages()
    {
        return $this->scopeConfig->getValue(ImportInterface::XML_INRIVER_MAX_DOWNLOAD_IMAGES_RETRY_ATTEMPT);
    }

    public function getAttemptSleepImages()
    {
        return $this->scopeConfig->getValue(ImportInterface::XML_INRIVER_INITIAL_DOWNLOAD_IMAGES_RETRY_SLEEP);
    }
}
