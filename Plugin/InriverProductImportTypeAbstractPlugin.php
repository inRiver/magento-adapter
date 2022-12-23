<?php

/**
 * @author InRiver <iif-magento@inriver.com>
 * @copyright Copyright (c) InRiver (https://www.inriver.com/)
 * @link https://www.inriver.com/
 */

declare(strict_types=1);

namespace Inriver\Adapter\Plugin;

use Inriver\Adapter\Helper\Import as InriverImportHelper;
use Magento\CatalogImportExport\Model\Import\Product;
use Magento\CatalogImportExport\Model\Import\Product\Type\AbstractType;

use function array_key_exists;
use function in_array;
use function is_string;

/**
 * Class InriverProductImportTypeAbstractPlugin InriverProductImportTypeAbstractPlugin
 */
class InriverProductImportTypeAbstractPlugin
{
    /** @var \Inriver\Adapter\Helper\Import */
    private $inriverImportHelper;

    /**
     * @param \Inriver\Adapter\Helper\Import $inriverImportHelper
     */
    public function __construct(
        InriverImportHelper $inriverImportHelper
    ) {
        $this->inriverImportHelper = $inriverImportHelper;
    }

    /**
     * Plugin for IsAttributeValid
     *
     * @param \Magento\CatalogImportExport\Model\Import\Product\Type\AbstractType $subject
     * @param array $rowData
     * @param bool $withDefaultValue
     *
     * @return array
     */
    // phpcs:ignore SlevomatCodingStandard.Functions.UnusedParameter.UnusedParameter
    public function beforePrepareAttributesWithDefaultValueForSave(
        AbstractType $subject,
        array $rowData,
        bool $withDefaultValue
    ): array {
        $isInriverImport = isset($subject->{InriverImportHelper::IS_INRIVER_IMPORT}) ?? false;

        if ($isInriverImport === true && array_key_exists(Product::COL_ATTR_SET, $rowData)) {
            $attributeSet = $rowData[Product::COL_ATTR_SET];

            if ($attributeSet !== null && $attributeSet !== '') {
                foreach ($rowData as $attributeCode => $attributeValue) {
                    if (!in_array($attributeCode, InriverImportHelper::ATTRIBUTES_NOT_TO_DECODE, true)) {
                        $attribute = $subject->retrieveAttribute($attributeCode, $attributeSet);

                        if (
                            $attribute !== []
                            && array_key_exists('is_static', $attribute)
                            && array_key_exists('type', $attribute)
                            && !$attribute['is_static']
                            && is_string($attributeValue)
                            && !in_array(
                                $attribute['type'],
                                InriverImportHelper::ATTRIBUTES_TYPE_NOT_TO_DECODE_FOR_INSERT,
                                true
                            )
                        ) {
                            $rowData[$attributeCode] =
                                $this->inriverImportHelper->decodeImportAttributeValue($attributeValue);
                        }
                    }
                }
            }
        }

        return [$rowData, $withDefaultValue];
    }
}
