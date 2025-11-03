<?php

namespace CHammedinger\ExtendedExports\Block\Adminhtml\System\Config;

use CHammedinger\ExtendedExports\Block\Adminhtml\System\Config\Form\Field\ProductAttributeSelect;
use Magento\Backend\Block\Template\Context;
use Magento\Config\Block\System\Config\Form\Field\FieldArray\AbstractFieldArray;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DataObject;

class ProductAttributes extends AbstractFieldArray
{
    private ?ProductAttributeSelect $attributeRenderer = null;

    private ResourceConnection $resource;

    public function __construct(Context $context, ResourceConnection $resource, array $data = [])
    {
        $this->resource = $resource;
        parent::__construct($context, $data);
    }

    protected function _prepareToRender(): void
    {
        $this->addColumn('attribute_code', [
            'label'    => __('Attribute'),
            'renderer' => $this->getAttributeRenderer(),
        ]);

        $this->_addAfter = false;
        $this->_addButtonLabel = __('Add Attribute');
    }

    protected function _prepareArrayRow(DataObject $row): void
    {
        $options = [];
        $attributeCode = $row->getData('attribute_code');
        if ($attributeCode) {
            $options['option_' . $this->getAttributeRenderer()->calcOptionHash($attributeCode)] = 'selected="selected"';
        }

        $row->setData('option_extra_attrs', $options);
    }

    private function getAttributeRenderer(): ProductAttributeSelect
    {
        if (!isset($this->attributeRenderer)) {
            $this->attributeRenderer = $this->getLayout()->createBlock(
                ProductAttributeSelect::class,
                'extendedexports_product_attribute_renderer',
                ['data' => ['is_render_to_js_template' => true]]
            );
            $this->attributeRenderer->setOptions($this->getAttributeOptions());
        }

        return $this->attributeRenderer;
    }

    private function getAttributeOptions(): array
    {
        $connection = $this->resource->getConnection();

        $entityTypeId = $connection->fetchOne(
            sprintf(
                "SELECT entity_type_id FROM %s WHERE entity_type_code = 'catalog_product'",
                $connection->getTableName('eav_entity_type')
            )
        );

        if (!$entityTypeId) {
            return [];
        }

        $select = $connection->select()
            ->from($connection->getTableName('eav_attribute'), ['attribute_code', 'frontend_label'])
            ->where('entity_type_id = ?', $entityTypeId)
            ->order('frontend_label ASC');

        $rows = $connection->fetchAll($select);

        $options = ['' => __('-- Please Select --')];
        foreach ($rows as $row) {
            $code = $row['attribute_code'];
            $label = $row['frontend_label'] ?: $code;
            $options[$code] = sprintf('%s (%s)', $label, $code);
        }

        return $options;
    }
}
