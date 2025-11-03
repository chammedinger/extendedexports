<?php

namespace CHammedinger\ExtendedExports\Block\Adminhtml\System\Config;

use CHammedinger\ExtendedExports\Block\Adminhtml\System\Config\Form\Field\ColumnSelect;
use CHammedinger\ExtendedExports\Block\Adminhtml\System\Config\Form\Field\TableSelect;
use Magento\Backend\Block\Template\Context;
use Magento\Config\Block\System\Config\Form\Field\FieldArray\AbstractFieldArray;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DataObject;
use Magento\Framework\Serialize\Serializer\Json;
use Zend_Db_Exception;

class ExtensionTables extends AbstractFieldArray
{
    private ?TableSelect $tableRenderer = null;

    private ?ColumnSelect $columnRenderer = null;

    private ?ColumnSelect $valueColumnRenderer = null;

    private ResourceConnection $resource;

    private Json $jsonSerializer;

    private array $tableColumns = [];

    public function __construct(Context $context, ResourceConnection $resource, Json $jsonSerializer, array $data = [])
    {
        $this->resource = $resource;
        $this->jsonSerializer = $jsonSerializer;
        parent::__construct($context, $data);
    }

    protected function _prepareToRender(): void
    {
        $this->addColumn('table_name', [
            'label'    => __('Table Name'),
            'renderer' => $this->getTableRenderer(),
        ]);

        $this->addColumn('key', [
            'label'    => __('Order ID Column'),
            'renderer' => $this->getColumnRenderer(),
        ]);

        $this->addColumn('export_value', [
            'label'    => __('Export Value Column'),
            'renderer' => $this->getValueRenderer(),
        ]);

        $this->_addAfter = false;
        $this->_addButtonLabel = __('Add Mapping');
    }

    protected function _prepareArrayRow(DataObject $row): void
    {
        $options = [];
        $table = $row->getData('table_name');
        if ($table) {
            $options['option_' . $this->getTableRenderer()->calcOptionHash($table)] = 'selected="selected"';
        }

        $row->setData('option_extra_attrs', $options);
    }

    private function getTableRenderer(): TableSelect
    {
        if (!isset($this->tableRenderer)) {
            $this->tableRenderer = $this->getLayout()->createBlock(
                TableSelect::class,
                'extendedexports_extension_tables_table_renderer',
                ['data' => ['is_render_to_js_template' => true]]
            );
        }

        return $this->tableRenderer;
    }

    private function getColumnRenderer(): ColumnSelect
    {
        if (!isset($this->columnRenderer)) {
            $this->columnRenderer = $this->getLayout()->createBlock(
                ColumnSelect::class,
                'extendedexports_extension_tables_column_renderer',
                ['data' => ['is_render_to_js_template' => true]]
            );
            $this->columnRenderer->setData('column_role', 'order-id');
            $placeholder = (string)__('-- Please Select --');
            $extraParams = sprintf(
                'data-role="order-id" data-current-value="#{key}" data-placeholder="%s"',
                $this->escapeHtmlAttr($placeholder)
            );
            $this->columnRenderer->setExtraParams($extraParams);
        }

        return $this->columnRenderer;
    }

    private function getValueRenderer(): ColumnSelect
    {
        if (!isset($this->valueColumnRenderer)) {
            $this->valueColumnRenderer = $this->getLayout()->createBlock(
                ColumnSelect::class,
                'extendedexports_extension_tables_value_renderer',
                ['data' => ['is_render_to_js_template' => true]]
            );
            $this->valueColumnRenderer->setData('column_role', 'export-value');
            $placeholder = (string)__('-- Please Select --');
            $extraParams = sprintf(
                'data-role="export-value" data-current-value="#{export_value}" data-placeholder="%s"',
                $this->escapeHtmlAttr($placeholder)
            );
            $this->valueColumnRenderer->setExtraParams($extraParams);
        }

        return $this->valueColumnRenderer;
    }

    protected function _toHtml(): string
    {
        $columnsJson = $this->jsonSerializer->serialize($this->getTableColumns());
        $storedRowsJson = $this->jsonSerializer->serialize($this->getStoredConfigRows());
        $html = '<div data-role="extendedexports-extension-tables">' . parent::_toHtml() . '</div>';

        $script = <<<HTML
<script type="text/javascript">
require(['jquery'], function ($) {
    'use strict';
    var columnsMap = {$columnsJson};
    var storedRows = {$storedRowsJson};

    function normalize(value) {
        if (value === undefined || value === null) {
            return '';
        }

        var stringValue = value.toString();
        if (stringValue.indexOf('#{') === 0) {
            return '';
        }

        return stringValue;
    }

    function buildOptions(table, selected, placeholder) {
        var html = '<option value="">' + placeholder + '</option>';
        if (columnsMap[table]) {
            columnsMap[table].forEach(function (column) {
                var isSelected = selected === column ? ' selected="selected"' : '';
                html += '<option value="' + column + '"' + isSelected + '>' + column + '</option>';
            });
        }
        return html;
    }

    function syncRow(row, forceReset) {
        var tableSelect = row.find('select.extension-table-select').first();
        var columnSelects = row.find('select.extension-column-select');
        if (!tableSelect.length || !columnSelects.length) {
            return;
        }

        columnSelects.each(function () {
            var element = $(this);
            var placeholder = element.data('placeholder') || '-- Please Select --';
            var selected = normalize(element.attr('data-current-value'));


            var initialHolder = element.siblings('.extension-column-initial');

            if (!selected && initialHolder.length) {
                selected = normalize(initialHolder.val());
                if (selected) {
                    element.attr('data-current-value', selected);
                }
            }

            if (forceReset) {
                selected = '';
            }

            element.html(buildOptions(tableSelect.val(), selected, placeholder));

            if (selected && columnsMap[tableSelect.val()] && columnsMap[tableSelect.val()].indexOf(selected) !== -1) {
                element.val(selected);
            } else {
                element.val('');
                selected = '';
            }

            element.attr('data-current-value', normalize(element.val()));
            if (initialHolder.length) {
                initialHolder.val(normalize(element.val()));
            }
        });
    }

    $('[data-role="extendedexports-extension-tables"]').each(function () {
        var scope = $(this);

        function syncAllRows() {
            scope.find('select.extension-table-select').each(function () {
                syncRow($(this).closest('tr'), false);
            });
        }

        function applyStoredValues(row, rowData) {
            row.find('select.extension-column-select').each(function () {
                var select = $(this);
                var role = select.data('role');
                var holder = select.siblings('.extension-column-initial');

                var storedValue = '';
                if (rowData) {
                    if (role === 'order-id') {
                        storedValue = normalize(rowData['key']);
                    } else if (role === 'export-value') {
                        storedValue = normalize(rowData['export_value']);
                    }
                }

                if (!storedValue && holder.length) {
                    storedValue = normalize(holder.val());
                }

                if (storedValue) {
                    select.attr('data-current-value', storedValue);
                    if (holder.length) {
                        holder.val(storedValue);
                    }
                }
            });
        }

        function loadStoredConfiguration() {
            var rowsData = [];
            if ($.isArray(storedRows)) {
                rowsData = storedRows;
            } else if (storedRows && typeof storedRows === 'object') {
                $.each(storedRows, function (key, value) {
                    rowsData.push(value);
                });
            }

            scope.find('tbody tr').each(function (index) {
                var data = rowsData[index] || null;
                var row = $(this);
                var tableSelect = row.find('select.extension-table-select');

                if (data && data['table_name']) {
                    tableSelect.val(data['table_name']);
                }

                applyStoredValues(row, data);

                tableSelect.trigger('change', [{ preserve: true }]);
            });
        }

        scope.on('change', 'select.extension-table-select', function (event, options) {
            var preserve = options && options.preserve === true;
            syncRow($(this).closest('tr'), !preserve);
        });

        scope.on('change', 'select.extension-column-select', function () {
            var select = $(this);
            var value = normalize(select.val());
            select.attr('data-current-value', value);
            var holder = select.siblings('.extension-column-initial');
            if (holder.length) {
                holder.val(value);
            }
        });

        scope.on('click', 'button.action-add', function () {
            var trigger = $(this);
            setTimeout(function () {
                var newRow = trigger.closest('[data-role="extendedexports-extension-tables"]').find('tbody tr').last();
                syncRow(newRow, true);
            }, 200);
        });

        $(window).on('load', loadStoredConfiguration);
    });
});
</script>
HTML;

        return $html . $script;
    }

    private function getStoredConfigRows(): array
    {
        $element = $this->getElement();
        if (!$element) {
            return [];
        }

        $value = $element->getValue();
        if (empty($value)) {
            return [];
        }

        if (is_array($value)) {
            return $value;
        }

        if (is_string($value)) {
            try {
                $decoded = $this->jsonSerializer->unserialize($value);
                if (is_array($decoded)) {
                    return $decoded;
                }
            } catch (\InvalidArgumentException $exception) {
                // fall back to PHP serialization
            } catch (\Throwable $exception) {
                // fall back to PHP serialization
            }

            try {
                $decoded = unserialize($value, ['allowed_classes' => false]);
                if (is_array($decoded)) {
                    return $decoded;
                }
            } catch (\Throwable $exception) {
                // ignore, we will return empty array
            }
        }

        return [];
    }

    private function getTableColumns(): array
    {
        if (!empty($this->tableColumns)) {
            return $this->tableColumns;
        }

        $connection = $this->resource->getConnection();
        foreach ($connection->getTables() as $tableName) {
            try {
                $describe = $connection->describeTable($tableName);
            } catch (Zend_Db_Exception $exception) {
                continue;
            }

            if (!empty($describe)) {
                $this->tableColumns[$tableName] = array_keys($describe);
            }
        }

        return $this->tableColumns;
    }
}
