<?php

namespace CHammedinger\ExtendedExports\Block\Adminhtml\System\Config\Form\Field;

use Magento\Framework\View\Element\Context;
use Magento\Framework\View\Element\Html\Select;

class ProductAttributeSelect extends Select
{
    /**
     * @var array
     */
    private array $options = [];

    public function __construct(Context $context, array $data = [])
    {
        parent::__construct($context, $data);
        $this->setClass('extension-product-attribute-select admin__control-select');
    }

    public function setOptions($options)
    {
        $this->options = is_array($options) ? $options : [];
        return parent::setOptions($this->options);
    }

    protected function _toHtml(): string
    {
        return parent::_toHtml();
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
