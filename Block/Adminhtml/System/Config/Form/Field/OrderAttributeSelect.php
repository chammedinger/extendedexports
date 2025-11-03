<?php

namespace CHammedinger\ExtendedExports\Block\Adminhtml\System\Config\Form\Field;

use Magento\Framework\View\Element\Context;
use Magento\Framework\View\Element\Html\Select;

class OrderAttributeSelect extends Select
{
    public function __construct(Context $context, array $data = [])
    {
        parent::__construct($context, $data);
        $this->setClass('extension-order-attribute-select admin__control-select');
    }

    public function setOptions($options)
    {
        $prepared = [];

        if (is_array($options)) {
            foreach ($options as $value => $label) {
                $prepared[] = ['value' => $value, 'label' => $label];
            }
        }

        return parent::setOptions($prepared);
    }

    public function setInputName($value)
    {
        return $this->setName($value);
    }

    public function setColumnName($value)
    {
        return $this->setId($value);
    }
}
