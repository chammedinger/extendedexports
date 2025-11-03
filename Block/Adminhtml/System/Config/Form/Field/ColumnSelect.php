<?php

namespace CHammedinger\ExtendedExports\Block\Adminhtml\System\Config\Form\Field;

use Magento\Framework\View\Element\Context;
use Magento\Framework\View\Element\Html\Select;

class ColumnSelect extends Select
{
    public function __construct(Context $context, array $data = [])
    {
        parent::__construct($context, $data);
        $this->setClass('extension-column-select admin__control-select');
    }

    protected function _toHtml(): string
    {
        if (!$this->getOptions()) {
            $this->addOption('', __('-- Please Select --'));
        }

        $html = parent::_toHtml();

        $role = (string)($this->getData('column_role') ?? 'column');
        $hidden = sprintf(
            '<input type="hidden" class="extension-column-initial" data-role="%s-initial" value="%s" />',
            $this->escapeHtmlAttr($role),
            $this->escapeHtmlAttr((string)$this->getValue())
        );

        return $html . $hidden;
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
