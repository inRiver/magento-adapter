<?php

/**
 * @author InRiver <inriveradapters@inriver.com>
 * @copyright Copyright (c) InRiver (https://www.inriver.com/)
 * @link https://www.inriver.com/
 */

declare(strict_types=1);

namespace Inriver\Adapter\Model\Operation;

use Inriver\Adapter\Api\AttributeOptionsInterface;
use Inriver\Adapter\Api\Data\OptionsByAttributeInterface;
use Inriver\Adapter\Api\Data\OptionsByAttributeInterface\OptionInterface;
use Inriver\Adapter\Helper\ErrorCodesDirectory;
use Inriver\Adapter\Logger\Logger;
use Magento\Catalog\Api\Data\ProductAttributeInterface;
use Magento\Catalog\Model\Product;
use Magento\Eav\Api\AttributeRepositoryInterface;
use Magento\Eav\Api\Data\AttributeInterface;
use Magento\Eav\Api\Data\AttributeOptionInterfaceFactory;
use Magento\Eav\Api\Data\AttributeOptionLabelInterfaceFactory;
use Magento\Eav\Model\Entity\Attribute\Option as AttributeOption;
use Magento\Eav\Model\Entity\Attribute\OptionManagement;
use Magento\Eav\Model\ResourceModel\Entity\Attribute\Option;
use Magento\Eav\Model\ResourceModel\Entity\Attribute\Option\Collection;
use Magento\Eav\Model\ResourceModel\Entity\Attribute\Option\CollectionFactory;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Exception\InputException;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Phrase;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;
use Magento\Swatches\Helper\Data;

use function __;
use function array_key_exists;
use function count;

class AttributeOptionsOperation implements AttributeOptionsInterface
{
    /** @var \Magento\Framework\App\Config\ScopeConfigInterface */
    protected $scopeConfig;

    /** @var string */
    protected $sourceUrl;

    /** @var \Inriver\Adapter\Model\Data\Import */
    protected $import;

    /** @var \Inriver\Adapter\Helper\FileDownloader */
    protected $downloader;

    /** @var \Magento\Framework\Filesystem */
    protected $filesystem;

    /** @var \Magento\Eav\Api\AttributeRepositoryInterface */
    protected $attributeRepository;

    /** @var \Magento\Eav\Model\Entity\Attribute\OptionManagement */
    protected $attributeOptionManagement;

    /** @var \Magento\Eav\Api\Data\AttributeOptionInterfaceFactory */
    protected $attributeOptionFactory;

    /** @var \Magento\Eav\Api\Data\AttributeOptionLabelInterfaceFactory */
    protected $attributeOptionLabelFactory;

    /** @var \Magento\Store\Model\StoreManagerInterface */
    protected $storeManager;

    /** @var \Magento\Eav\Model\ResourceModel\Entity\Attribute\Option */
    protected $option;

    /** @var \Magento\Eav\Model\ResourceModel\Entity\Attribute\Option\CollectionFactory */
    protected $attributeOptionCollectionFactory;

    /** @var \Magento\Swatches\Helper\Data */
    private $swatchHelper;

    /** @var \Inriver\Adapter\Logger\Logger */
    private $logger;

    /**
     * @param \Magento\Framework\App\Config\ScopeConfigInterface $scopeConfig
     * @param \Magento\Eav\Api\AttributeRepositoryInterface $attributeRepository
     * @param \Magento\Eav\Model\Entity\Attribute\OptionManagement $attributeOptionManagement
     * @param \Magento\Eav\Api\Data\AttributeOptionInterfaceFactory $attributeOptionFactory
     * @param \Magento\Eav\Api\Data\AttributeOptionLabelInterfaceFactory $attributeOptionLabelFactory
     * @param \Magento\Store\Model\StoreManagerInterface $storeManager
     * @param \Magento\Eav\Model\ResourceModel\Entity\Attribute\Option $option
     * @param \Magento\Eav\Model\ResourceModel\Entity\Attribute\Option\CollectionFactory
     *        $attributeOptionCollectionFactory
     * @param \Magento\Swatches\Helper\Data $swatchHelper
     * @param \Inriver\Adapter\Logger\Logger $logger
     */
    public function __construct(
        ScopeConfigInterface $scopeConfig,
        AttributeRepositoryInterface $attributeRepository,
        OptionManagement $attributeOptionManagement,
        AttributeOptionInterfaceFactory $attributeOptionFactory,
        AttributeOptionLabelInterfaceFactory $attributeOptionLabelFactory,
        StoreManagerInterface $storeManager,
        Option $option,
        CollectionFactory $attributeOptionCollectionFactory,
        Data $swatchHelper,
        Logger $logger
    ) {
        $this->scopeConfig = $scopeConfig;
        $this->attributeRepository = $attributeRepository;
        $this->attributeOptionManagement = $attributeOptionManagement;
        $this->attributeOptionFactory = $attributeOptionFactory;
        $this->attributeOptionLabelFactory = $attributeOptionLabelFactory;
        $this->storeManager = $storeManager;
        $this->attributeOptionCollectionFactory = $attributeOptionCollectionFactory;
        $this->option = $option;
        $this->swatchHelper = $swatchHelper;
        $this->logger = $logger;
    }

    /**
     * Synchronized attribute options
     *
     * @param \Inriver\Adapter\Api\Data\OptionsByAttributeInterface $optionsByAttribute
     *
     * @throws \Magento\Framework\Exception\InputException
     * @throws \Magento\Framework\Exception\LocalizedException
     * @throws \Magento\Framework\Exception\NoSuchEntityException
     * @throws \Magento\Framework\Exception\StateException
     *
     * @noinspection PhpFullyQualifiedNameUsageInspection
     */
    public function post(OptionsByAttributeInterface $optionsByAttribute): void
    {
        $this->logger->info(__('Starting Attribute options Operation'));
        foreach ($optionsByAttribute->getAttributes() as $attribute) {
            $this->logger->info(__('Importing options for Attributes: %1', $attribute));
            $this->processAttribute($attribute, $optionsByAttribute->getOptions(), Product::ENTITY);
        }
        $this->logger->info(__('Finished Attribute options Operation'));
    }

    /**
     * @param string $attribute
     * @param \Inriver\Adapter\Api\Data\OptionsByAttributeInterface\OptionInterface[] $newOptions
     * @param string $entityType
     *
     * @throws \Magento\Framework\Exception\InputException
     * @throws \Magento\Framework\Exception\LocalizedException
     * @throws \Magento\Framework\Exception\NoSuchEntityException
     * @throws \Magento\Framework\Exception\StateException
     */
    protected function processAttribute(string $attribute, array $newOptions, string $entityType): void
    {
        try {
            $attribute = $this->attributeRepository->get($entityType, $attribute);
        } catch (NoSuchEntityException $exception) {
            throw new LocalizedException(
                __('Attribute %1 does not exist', $attribute),
                $exception,
                ErrorCodesDirectory::ATTRIBUTE_DOES_NOT_EXIST
            );
        }

        $newOptionList = [];
        $attributeOptionErrors = [];

        foreach ($newOptions as $option) {
            $newOptionList[$option->getAdminValue()] = $option;
        }

        $currentOptions = $this->getAttributeOptions($attribute);

        // Update existing options first
        foreach ($currentOptions as $currentOption) {
            /** @var \Magento\Eav\Model\Entity\Attribute\Option $currentOption */
            if (array_key_exists($currentOption->getValue(), $newOptionList)) {
                try {
                    $updatedOption = $newOptionList[$currentOption->getValue()];
                    $this->updateOption($attribute, $currentOption, $updatedOption);
                    unset($newOptionList[$currentOption->getValue()]);
                } catch (InputException $exception) {
                    $attributeOptionErrors[] = __(
                        'An error occured while importing value(' .
                            $updatedOption->getAdminValue() .
                            ') for attribute(' .
                            $attribute->getAttributeCode() . '): ' .
                            $exception->getMessage()
                    );
                }
            }
        }

        // Create remaining options
        foreach ($newOptionList as $newOption) {
            try {
                $this->createOption($attribute, $newOption);
            } catch (InputException $exception) {
                $attributeOptionErrors[] = __(
                    'An error occured while importing value(' .
                    $newOption->getAdminValue() .
                    ') for attribute(' .
                    $attribute->getAttributeCode() . '): ' .
                    $exception->getMessage()
                );
            }
        }

        if (count($attributeOptionErrors) >0) {
            $errorMessage = __('There were errors while importing attributes option values: ');
            $errorMessage .= implode(',', $attributeOptionErrors);
            throw new InputException(new Phrase($errorMessage));
        }
    }

    /**
     * @param \Magento\Eav\Api\Data\AttributeInterface $attribute
     * @param \Magento\Eav\Model\Entity\Attribute\Option $currentOption
     * @param \Inriver\Adapter\Api\Data\OptionsByAttributeInterface\OptionInterface $updatedOption
     *
     * @throws \Magento\Framework\Exception\InputException
     * @throws \Magento\Framework\Exception\NoSuchEntityException
     * @throws \Magento\Framework\Exception\StateException
     */
    protected function updateOption(
        AttributeInterface $attribute,
        AttributeOption $currentOption,
        OptionInterface $updatedOption
    ): void {
        $isSwatch = $this->swatchHelper->isSwatchAttribute($attribute);
        $labels = $this->getStoreLabels($updatedOption, $isSwatch, $currentOption, $attribute);
        $currentOption->setLabel($updatedOption->getAdminValue());
        $currentOption->setStoreLabels($labels);

        if ($isSwatch) {
            //necessary for swatch to reload attribute. Fail to save swatch textbox on multiple option otherwise
            $attribute = $this->initAttribute($attribute->getAttributeCode());
            $this->setSwatchAttributeOption($attribute, $currentOption, $currentOption->getId());
        }

        $this->attributeOptionManagement->update(
            (string)$attribute->getEntityTypeId(),
            (string)$attribute->getAttributeCode(),
            (int)$currentOption->getId(),
            $currentOption
        );
    }

    /**
     * @param \Magento\Eav\Api\Data\AttributeInterface $attribute
     * @param \Inriver\Adapter\Api\Data\OptionsByAttributeInterface\OptionInterface $newOption
     *
     * @throws \Magento\Framework\Exception\InputException
     * @throws \Magento\Framework\Exception\StateException
     * @throws \Magento\Framework\Exception\NoSuchEntityException
     */
    protected function createOption(AttributeInterface $attribute, OptionInterface $newOption): void
    {
        $isSwatch = $this->swatchHelper->isSwatchAttribute($attribute);
        $labels = $this->getStoreLabels($newOption, $isSwatch);

        $newAttributeOption = $this->attributeOptionFactory->create();
        $newAttributeOption->setLabel($newOption->getAdminValue());
        $newAttributeOption->setValue($newOption->getAdminValue());
        $newAttributeOption->setStoreLabels($labels);
        $newAttributeOption->setSortOrder(0);
        $newAttributeOption->setIsDefault(false);

        if ($isSwatch) {
            //necessary for swatch to reload attribute. Fail to save swatch textbox on multiple option otherwise
            $newOptionId = $this->getNewOptionId($newAttributeOption);
            $this->setSwatchAttributeOption($attribute, $newAttributeOption, $newOptionId);
        }

        $this->attributeOptionManagement->add(
            $attribute->getEntityTypeId(),
            $attribute->getAttributeCode(),
            $newAttributeOption
        );
    }

    /**
     * @param \Inriver\Adapter\Api\Data\OptionsByAttributeInterface\OptionInterface $newOption
     * @param bool $isSwatch
     * @param \Magento\Eav\Model\Entity\Attribute\Option|null $currentOption
     * @param \Magento\Eav\Api\Data\AttributeInterface|null $attribute
     * @return string[]
     * @throws \Magento\Framework\Exception\NoSuchEntityException
     */
    protected function getStoreLabels(
        OptionInterface $newOption,
        bool $isSwatch = false,
        ?AttributeOption $currentOption = null,
        ?AttributeInterface $attribute = null
    ): array {
        $originalStoreId = $attribute !== null ? $attribute->getStoreId() : 0;
        $labels = [];
        foreach ($newOption->getValues() as $value) {
            $storeId = $this->storeManager->getStore($value->getStoreViewCode())->getId();

            $label = $this->attributeOptionLabelFactory->create();
            $label->setStoreId($storeId);
            if ($isSwatch) {
                if ($value->getDescription() !== null) {
                    $label->setLabel($value->getDescription());
                } else {
                    if ($currentOption !== null && $attribute !== null) {
                        $labelValue = $attribute
                            ->setStoreId($storeId)
                            ->getSource()
                            ->getOptionText($currentOption->getId());
                        $label->setLabel($labelValue);
                    }
                }
                $label->setData('swatchtext', $value->getValue());
            } else {
                $label->setLabel($value->getValue());
            }
            $labels[$storeId] = $label;
        }

        if ($currentOption !== null && $attribute !== null) {
            foreach ($this->storeManager->getStores() as $store) {
                $storeId = $store->getId();
                if (!array_key_exists($storeId, $labels)) {
                    $label = $this->attributeOptionLabelFactory->create();
                    $label->setStoreId($storeId);

                    $labelValue = $attribute->setStoreId($storeId)->getSource()->getOptionText($currentOption->getId());
                    $label->setLabel($labelValue);
                    $labels[$storeId] = $label;
                }
            }
            $attribute->setStoreId($originalStoreId);
        }
        return $labels;
    }

    /**
     * @param \Magento\Eav\Api\Data\AttributeInterface $attribute
     *
     * @return \Magento\Eav\Model\ResourceModel\Entity\Attribute\Option\Collection
     */
    private function getAttributeOptions(AttributeInterface $attribute): Collection
    {
        $collection = $this->attributeOptionCollectionFactory->create();
        $collection
            ->setPositionOrder('asc')
            ->setAttributeFilter($attribute->getAttributeId())
            ->setStoreFilter('admin')
            ->load();

        return $collection;
    }

    /**
     * Set attribute swatch option
     *
     * @param \Magento\Eav\Api\Data\AttributeInterface $attribute
     * @param \Magento\Eav\Model\Entity\Attribute\Option $option
     * @param string $optionId
     */
    private function setSwatchAttributeOption(
        AttributeInterface $attribute,
        AttributeOption $option,
        string $optionId
    ): void {
        $optionsValue = trim($option->getLabel() ?: '');
        if ($this->swatchHelper->isVisualSwatch($attribute)) {
            if (strpos($optionsValue, "#") === 0) {
                $attribute->setData('swatchvisual', ['value' => [$optionId => $optionsValue]]);
            }
        } else {
            $options = [];
            $options['value'][$optionId][Store::DEFAULT_STORE_ID] = $optionsValue;
            foreach ($option->getStoreLabels() as $label) {
                if ($label->getData('swatchtext') !== null) {
                    $options['value'][$optionId][$label->getStoreId()] = $label->getData('swatchtext');
                }
            }
            $attribute->setData('swatchtext', $options);
        }
    }

    /**
     * Get option id to create new option
     *
     * @param \Magento\Eav\Model\Entity\Attribute\Option $option
     * @return string
     */
    private function getNewOptionId(AttributeOption $option): string
    {
        $optionId = trim($option->getLabel() ?: '');
        if (empty($optionId)) {
            $optionId = 'new_option';
        }

        return 'id_' . $optionId;
    }

    /**
     * Init swatch attribute
     *
     * @param string $attributeCode
     * @return \Magento\Eav\Api\Data\AttributeInterface
     * @throws \Magento\Framework\Exception\NoSuchEntityException
     */
    private function initAttribute(string $attributeCode): AttributeInterface
    {
        return $this->attributeRepository->get(
            ProductAttributeInterface::ENTITY_TYPE_CODE,
            $attributeCode
        );
    }
}
