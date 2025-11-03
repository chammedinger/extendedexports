<?php

namespace CHammedinger\ExtendedExports\Block\Adminhtml\System\Config;

use CHammedinger\ExtendedExports\Block\Adminhtml\System\Config\Form\Field\OrderAttributeSelect;
use Magento\Backend\Block\Template\Context;
use Magento\Config\Block\System\Config\Form\Field\FieldArray\AbstractFieldArray;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DataObject;

class OrderAttributes extends AbstractFieldArray
{
    private ?OrderAttributeSelect $attributeRenderer = null;

    private ResourceConnection $resource;

    public function __construct(Context $context, ResourceConnection $resource, array $data = [])
    {
        $this->resource = $resource;
        parent::__construct($context, $data);
    }

    protected function _prepareToRender(): void
    {
        $this->addColumn('column_name', [
            'label'    => __('Order Attribute'),
            'renderer' => $this->getAttributeRenderer(),
        ]);

        $this->_addAfter = false;
        $this->_addButtonLabel = __('Add Order Attribute');
    }

    protected function _prepareArrayRow(DataObject $row): void
    {
        $options = [];
        $column = $row->getData('column_name');
        if ($column) {
            $options['option_' . $this->getAttributeRenderer()->calcOptionHash($column)] = 'selected="selected"';
        }

        $row->setData('option_extra_attrs', $options);
    }

    private function getAttributeRenderer(): OrderAttributeSelect
    {
        if (!isset($this->attributeRenderer)) {
            $this->attributeRenderer = $this->getLayout()->createBlock(
                OrderAttributeSelect::class,
                'extendedexports_order_attribute_renderer',
                ['data' => ['is_render_to_js_template' => true]]
            );
            $this->attributeRenderer->setOptions($this->getColumnsOptions());
        }

        return $this->attributeRenderer;
    }

    private function getColumnsOptions(): array
    {
        $connection = $this->resource->getConnection();
        $tableName = $connection->getTableName('sales_order');

        try {
            $columns = $connection->describeTable($tableName);
        } catch (\Throwable $exception) {
            return [];
        }

        $options = ['' => __('-- Please Select --')];
        foreach (array_keys($columns) as $name) {
            $options[$name] = sprintf('%s (%s)', ucwords(str_replace('_', ' ', $name)), $name);
        }

        return $options;
    }
}
