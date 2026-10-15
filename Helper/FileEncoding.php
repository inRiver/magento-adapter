<?php

/**
 * @author InRiver <inriveradapters@inriver.com>
 * @copyright Copyright (c) InRiver (https://www.inriver.com/)
 * @link https://www.inriver.com/
 */

declare(strict_types=1);

namespace Inriver\Adapter\Helper;

use Exception;
use Magento\Framework\Filesystem\Driver\File as FileDriver;

//phpcs:ignore Magento2.Exceptions.TryProcessSystemResources.MissingTryCatch
use function stream_copy_to_stream;
use function unlink;

/**
 * Class FileEncoding File Encoding
 */
class FileEncoding
{
    public const UTF8_BOM = "\xEF\xBB\xBF";

    /** @var \Magento\Framework\Filesystem\Driver\File */
    protected $fileDriver;

    public function __construct(
        FileDriver $fileDriver
    ) {
        $this->fileDriver = $fileDriver;
    }

    /**
     * Detect if a file is of type UTF8 with BOM
     * Using file_get_contents directly because framework does not implement partial reading of a file
     *
     * @param string $fullPath
     *
     * @return bool
     * @throws \Magento\Framework\Exception\FileSystemException
     */
    public function isUtf8WithBom(string $fullPath): bool
    {
        //phpcs:ignore Magento2.Functions.DiscouragedFunction.Discouraged
        $content = file_get_contents($fullPath, false, null, 0, 3);

        return $content === self::UTF8_BOM;
    }

    /**
     * Remove utf8 BOM from the beginning of a file if utf8 BOM is detected
     * Using filesystem functions directly for performance considerations.
     * Replace with framework implementation if available
     *
     * @param $fullPath
     *
     * @return bool
     * @throws \Magento\Framework\Exception\FileSystemException
     */
    public function removeUtf8Bom(string $fullPath): bool
    {
        if (!$this->isUtf8WithBom($fullPath)) {
            return false;
        }

        $src = $this->fileDriver->fileOpen($fullPath, 'rb');
        $temporaryPath = $fullPath . '_TEMP';
        $destination = $this->fileDriver->fileOpen($temporaryPath, 'wb');

        try {
            //phpcs:ignore Magento2.Functions.DiscouragedFunction.Discouraged
            stream_copy_to_stream($src, $destination, -1, 3);
        } catch (Exception $exception) {
            return false;
        }

        $this->fileDriver->fileClose($src);
        $this->fileDriver->fileClose($destination);
        //phpcs:ignore Magento2.Functions.DiscouragedFunction.Discouraged
        unlink($fullPath);
        $this->fileDriver->rename($temporaryPath, $fullPath);

        return true;
    }
}
