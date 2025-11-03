<?php

namespace CHammedinger\ExtendedExports\Block\Adminhtml\System\Config\Form\Field;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\View\Element\Context;
use Magento\Framework\View\Element\Html\Select;

class TableSelect extends Select
{
    private ResourceConnection $resource;

    public function __construct(Context $context, ResourceConnection $resource, array $data = [])
    {
        $this->resource = $resource;
        parent::__construct($context, $data);
        $this->setClass('extension-table-select admin__control-select');
    }

    protected function _toHtml(): string
    {
        if (!$this->getOptions()) {
            foreach ($this->getTableOptions() as $value => $label) {
                $this->addOption($value, $label);
            }
        }

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

    private function getTableOptions(): array
    {
        $connection = $this->resource->getConnection();
        $options = ['' => __('-- Please Select --')];

        foreach ($connection->getTables() as $tableName) {
            $options[$tableName] = $tableName;
        }

        return $options;
    }
}
