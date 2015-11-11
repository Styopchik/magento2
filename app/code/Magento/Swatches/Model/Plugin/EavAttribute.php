<?php
/**
 * Copyright © 2015 Magento. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Magento\Swatches\Model\Plugin;

use Magento\Catalog\Model\ResourceModel\Eav\Attribute;
use Magento\Swatches\Model\Swatch;
use Magento\Framework\Exception\InputException;

/**
 * Plugin model for Catalog Resource Attribute
 */
class EavAttribute
{
    const DEFAULT_STORE_ID = 0;

    /**
     * Base option title used for string operations to detect is option already exists or new
     */
    const BASE_OPTION_TITLE = 'option';

    /**
     * @var \Magento\Swatches\Model\ResourceModel\Swatch\CollectionFactory
     */
    protected $swatchCollectionFactory;

    /**
     * @var \Magento\Swatches\Model\SwatchFactory
     */
    protected $swatchFactory;

    /**
     * @var \Magento\Swatches\Helper\Data
     */
    protected $swatchHelper;

    /**
     * Array which contains links for new created attributes for swatches
     *
     * @var array
     */
    protected $dependencyArray = [];

    /**
     * Swatch existing status
     *
     * @var bool
     */
    protected $isSwatchExists;

    /**
     * @param \Magento\Swatches\Model\ResourceModel\Swatch\CollectionFactory $collectionFactory
     * @param \Magento\Swatches\Model\SwatchFactory $swatchFactory
     * @param \Magento\Swatches\Helper\Data $swatchHelper
     */
    public function __construct(
        \Magento\Swatches\Model\ResourceModel\Swatch\CollectionFactory $collectionFactory,
        \Magento\Swatches\Model\SwatchFactory $swatchFactory,
        \Magento\Swatches\Helper\Data $swatchHelper
    ) {
        $this->swatchCollectionFactory = $collectionFactory;
        $this->swatchFactory = $swatchFactory;
        $this->swatchHelper = $swatchHelper;
    }

    /**
     * Set base data to Attribute
     *
     * @param Attribute $attribute
     * @return void
     */
    public function beforeSave(Attribute $attribute)
    {
        if ($this->swatchHelper->isSwatchAttribute($attribute) && $this->validateOptions($attribute)) {
            $this->setProperOptionsArray($attribute);
            $this->swatchHelper->assembleAdditionalDataEavAttribute($attribute);
        }
        $this->convertSwatchToDropdown($attribute);
    }

    /**
     * Validate that attribute options exist
     *
     * @param Attribute $attribute
     * @return bool
     * @throws InputException
     */
    protected function validateOptions(Attribute $attribute)
    {
        $attributeSavedOptions = $attribute->getSource()->getAllOptions(false);
        if (!count($attributeSavedOptions)) {
            throw new InputException(__('Admin is a required field in the each row'));
        }
        return true;
    }

    /**
     * Swatch save operations
     *
     * @param Attribute $attribute
     * @throws \Magento\Framework\Exception\LocalizedException
     * @return void
     */
    public function afterAfterSave(Attribute $attribute)
    {
        if ($this->swatchHelper->isSwatchAttribute($attribute)) {
            $this->processSwatchOptions($attribute);
            $this->saveDefaultSwatchOptionValue($attribute);
            $this->saveSwatchParams($attribute);
        }
    }

    /**
     * Substitute suitable options and swatches arrays
     *
     * @param Attribute $attribute
     * @return void
     */
    protected function setProperOptionsArray(Attribute $attribute)
    {
        $canReplace = false;
        if ($this->swatchHelper->isVisualSwatch($attribute)) {
            $canReplace = true;
            $defaultValue = $attribute->getData('defaultvisual');
            $optionsArray = $attribute->getData('optionvisual');
            $swatchesArray = $attribute->getData('swatchvisual');
        } elseif ($this->swatchHelper->isTextSwatch($attribute)) {
            $canReplace = true;
            $defaultValue = $attribute->getData('defaulttext');
            $optionsArray = $attribute->getData('optiontext');
            $swatchesArray = $attribute->getData('swatchtext');
        }
        if ($canReplace == true) {
            $attribute->setData('option', $optionsArray);
            $attribute->setData('default', $defaultValue);
            $attribute->setData('swatch', $swatchesArray);
        }
    }

    /**
     * Prepare attribute for conversion from any swatch type to dropdown
     *
     * @param Attribute $attribute
     * @return void
     */
    protected function convertSwatchToDropdown(Attribute $attribute)
    {
        if ($attribute->getData(Swatch::SWATCH_INPUT_TYPE_KEY) == Swatch::SWATCH_INPUT_TYPE_DROPDOWN) {
            $additionalData = $attribute->getData('additional_data');
            if (!empty($additionalData)) {
                $additionalData = unserialize($additionalData);
                if (is_array($additionalData) && isset($additionalData[Swatch::SWATCH_INPUT_TYPE_KEY])) {
                    unset($additionalData[Swatch::SWATCH_INPUT_TYPE_KEY]);
                    $attribute->setData('additional_data', serialize($additionalData));
                }
            }
        }
    }

    /**
     * Creates array which link new option ids
     *
     * @param Attribute $attribute
     * @return Attribute
     * @throws \Magento\Framework\Exception\LocalizedException
     */
    protected function processSwatchOptions(Attribute $attribute)
    {
        $optionsArray = $attribute->getData('option');

        if (!empty($optionsArray) && is_array($optionsArray)) {
            $optionsArray = $this->prepareOptionIds($optionsArray);
            $attributeSavedOptions = $attribute->getSource()->getAllOptions();
            $this->prepareOptionLinks($optionsArray, $attributeSavedOptions);
        }

        return $attribute;
    }

    /**
     * Get options array without deleted items
     *
     * @param array $optionsArray
     * @return array
     */
    protected function prepareOptionIds(array $optionsArray)
    {
        if (isset($optionsArray['value']) && is_array($optionsArray['value'])) {
            foreach (array_keys($optionsArray['value']) as $optionId) {
                if ($optionsArray['delete'][$optionId] == 1) {
                    unset($optionsArray['value'][$optionId]);
                }
            }
        }
        return $optionsArray;
    }

    /**
     * Create links for non existed swatch options
     *
     * @param array $optionsArray
     * @param array $attributeSavedOptions
     * @return void
     */
    protected function prepareOptionLinks(array $optionsArray, array $attributeSavedOptions)
    {
        $dependencyArray = [];
        if (is_array($optionsArray['value'])) {
            $optionCounter = 1;
            foreach (array_keys($optionsArray['value']) as $baseOptionId) {
                $dependencyArray[$baseOptionId] = $attributeSavedOptions[$optionCounter]['value'];
                $optionCounter++;
            }
        }

        $this->dependencyArray = $dependencyArray;
    }

    /**
     * Save all Swatches data
     *
     * @param Attribute $attribute
     * @return void
     */
    protected function saveSwatchParams(Attribute $attribute)
    {
        if ($this->swatchHelper->isVisualSwatch($attribute)) {
            $this->processVisualSwatch($attribute);
        } elseif ($this->swatchHelper->isTextSwatch($attribute)) {
            $this->processTextualSwatch($attribute);
        }
    }

    /**
     * Save Visual Swatch data
     *
     * @param Attribute $attribute
     * @return void
     */
    protected function processVisualSwatch(Attribute $attribute)
    {
        $swatchArray = $attribute->getData('swatch/value');
        if (isset($swatchArray) && is_array($swatchArray)) {
            foreach ($swatchArray as $optionId => $value) {
                $optionId = $this->getAttributeOptionId($optionId);
                $isOptionForDelete = $this->isOptionForDelete($attribute, $optionId);
                if ($optionId === null || $isOptionForDelete) {
                    //option was deleted by button with basket
                    continue;
                }
                $swatch = $this->loadSwatchIfExists($optionId, self::DEFAULT_STORE_ID);

                $swatchType = $this->determineSwatchType($value);

                $this->saveSwatchData($swatch, $optionId, self::DEFAULT_STORE_ID, $swatchType, $value);
                $this->isSwatchExists = null;
            }
        }
    }

    /**
     * @param string $value
     * @return int
     */
    private function determineSwatchType($value)
    {
        $swatchType = Swatch::SWATCH_TYPE_EMPTY;
        if (!empty($value) && $value[0] == '#') {
            $swatchType = Swatch::SWATCH_TYPE_VISUAL_COLOR;
        } elseif (!empty($value) && $value[0] == '/') {
            $swatchType = Swatch::SWATCH_TYPE_VISUAL_IMAGE;
        }
        return $swatchType;
    }

    /**
     * Save Textual Swatch data
     *
     * @param Attribute $attribute
     * @return void
     */
    protected function processTextualSwatch(Attribute $attribute)
    {
        $swatchArray = $attribute->getData('swatch/value');
        if (isset($swatchArray) && is_array($swatchArray)) {
            foreach ($swatchArray as $optionId => $storeValues) {
                $optionId = $this->getAttributeOptionId($optionId);
                $isOptionForDelete = $this->isOptionForDelete($attribute, $optionId);
                if ($optionId === null || $isOptionForDelete) {
                    //option was deleted by button with basket
                    continue;
                }
                foreach ($storeValues as $storeId => $value) {
                    $swatch = $this->loadSwatchIfExists($optionId, $storeId);
                    $swatch->isDeleted($isOptionForDelete);
                    $this->saveSwatchData(
                        $swatch,
                        $optionId,
                        $storeId,
                        \Magento\Swatches\Model\Swatch::SWATCH_TYPE_TEXTUAL,
                        $value
                    );
                    $this->isSwatchExists = null;
                }
            }
        }
    }

    /**
     * Get option id. If it not exist get it from dependency link array
     *
     * @param integer $optionId
     * @return int
     */
    protected function getAttributeOptionId($optionId)
    {
        if (substr($optionId, 0, 6) == self::BASE_OPTION_TITLE) {
            $optionId = isset($this->dependencyArray[$optionId]) ? $this->dependencyArray[$optionId] : null;
        }
        return $optionId;
    }

    /**
     * Check if is option for delete
     *
     * @param Attribute $attribute
     * @param integer $optionId
     * @return bool
     */
    protected function isOptionForDelete(Attribute $attribute, $optionId)
    {
        $isOptionForDelete = $attribute->getData('option/delete/'.$optionId);
        return isset($isOptionForDelete) && $isOptionForDelete;
    }

    /**
     * Load swatch if it exists in database
     *
     * @param int $optionId
     * @param int $storeId
     * @return Swatch
     */
    protected function loadSwatchIfExists($optionId, $storeId)
    {
        $collection = $this->swatchCollectionFactory->create();
        $collection->addFieldToFilter('option_id', $optionId);
        $collection->addFieldToFilter('store_id', $storeId);
        $collection->setPageSize(1);
        
        $loadedSwatch = $collection->getFirstItem();
        if ($loadedSwatch->getId()) {
            $this->isSwatchExists = true;
        }
        return $loadedSwatch;
    }

    /**
     * Save operation
     *
     * @param Swatch $swatch
     * @param integer $optionId
     * @param integer $storeId
     * @param integer $type
     * @param string $value
     * @return void
     */
    protected function saveSwatchData($swatch, $optionId, $storeId, $type, $value)
    {
        if ($this->isSwatchExists) {
            $swatch->setData('type', $type);
            $swatch->setData('value', $value);

        } else {
            $swatch->setData('option_id', $optionId);
            $swatch->setData('store_id', $storeId);
            $swatch->setData('type', $type);
            $swatch->setData('value', $value);
        }
        $swatch->save();
    }

    /**
     * Save default swatch value using Swatch model instead of Eav model
     *
     * @param Attribute $attribute
     * @return void
     */
    protected function saveDefaultSwatchOptionValue(Attribute $attribute)
    {
        if (!$this->swatchHelper->isSwatchAttribute($attribute)) {
            return;
        }
        $defaultValue = $attribute->getData('default/0');
        if (!empty($defaultValue)) {
            /** @var \Magento\Swatches\Model\Swatch $swatch */
            $swatch = $this->swatchFactory->create();
            if (substr($defaultValue, 0, 6) == self::BASE_OPTION_TITLE) {
                $defaultValue = $this->dependencyArray[$defaultValue];
            }
            $swatch->getResource()->saveDefaultSwatchOption($attribute->getId(), $defaultValue);
        }
    }
}
