<?php declare(strict_types = 0);

namespace Modules\HostGroupGrid\Includes;

use API,
	CButton,
	CButtonLink,
	CCol,
	CDiv,
	CLabel,
	CMultiSelect,
	CRow,
	CTable,
	CTemplateTag,
	CTextBox,
	CWidgetFieldView;

class CWidgetFieldCameraRowsView extends CWidgetFieldView {

	public function __construct(CWidgetFieldCameraRows $field) {
		$this->field = $field;
	}

	public function getView(): CDiv {
		$name = $this->field->getName();

		$table = (new CTable())
			->setId($name.'-table')
			->addClass(ZBX_STYLE_TABLE_FORMS)
			->setFooter(new CRow(
				new CCol(
					(new CButtonLink(_('Add item')))->addClass('element-table-add')
				)
			));

		$itemids = [];

		foreach ($this->field->getValue() as $row) {
			if (!empty($row['itemid'])) {
				$itemids[] = $row['itemid'];
			}
		}

		$items_data = [];

		if ($itemids) {
			$items = API::Item()->get([
				'output' => ['itemid', 'name', 'hostid'],
				'selectHosts' => ['name'],
				'itemids' => $itemids,
				'webitems' => true,
				'preservekeys' => true
			]);

			foreach ($items as $itemid => $item) {
				$items_data[$itemid] = [
					'id' => (string) $itemid,
					'name' => $item['name'],
					'prefix' => isset($item['hosts'][0]['name']) ? $item['hosts'][0]['name'].': ' : ''
				];
			}
		}

		foreach ($this->field->getValue() as $i => $row) {
			$row_items = [];

			if (!empty($row['itemid']) && isset($items_data[$row['itemid']])) {
				$row_items = [$items_data[$row['itemid']]];
			}

			$table->addRow($this->getRow((string) $i, $row, $row_items));
		}

		return (new CDiv($table))->setWidth(ZBX_TEXTAREA_STANDARD_WIDTH);
	}

	public function getJavaScript(): string {
		$name = $this->field->getName();

		return '
			(function() {
				var table = document.getElementById("'.$name.'-table");

				function initMultiSelects() {
					table.querySelectorAll(".js-itemid-ms").forEach(function(ms) {
						if (ms.dataset.msInit === "1") return;
						ms.dataset.msInit = "1";
						try { jQuery(ms).multiSelect(); } catch (err) {}
					});
				}

				initMultiSelects();

				jQuery(table)
					.dynamicRows({template: "#'.$name.'-row-tmpl", allow_empty: true})
					.on("afteradd.dynamicRows", initMultiSelects);
			})();
		';
	}

	public function getTemplates(): array {
		$name = $this->field->getName();

		return [
			new CTemplateTag($name.'-row-tmpl', $this->getRow('#{rowNum}'))
		];
	}

	private function getRow($row_num, array $data = [], array $row_items = []): CRow {
		$name = $this->field->getName();
		$prefix = $name.'['.$row_num.']';

		$type = $data['type'] ?? '';

		$item_select = (new CMultiSelect([
			'name' => $prefix.'[itemid]',
			'object_name' => 'items',
			'multiple' => false,
			'data' => $row_items,
			'add_post_js' => false,
			'popup' => [
				'parameters' => [
					'srctbl' => 'items',
					'srcfld1' => 'itemid',
					'dstfrm' => $this->form_name,
					'dstfld1' => zbx_formatDomId($prefix.'[itemid]'),
					'real_hosts' => true,
					'resolve_macros' => true
				]
			]
		]))
			->addClass('js-itemid-ms')
			->setWidth(ZBX_TEXTAREA_SMALL_WIDTH);

		$grid_style = 'display: grid; grid-template-columns: 120px max-content; gap: 8px 10px; align-items: center;';

		$settings_grid = (new CDiv([
			new CLabel(_('Item'), zbx_formatDomId($prefix.'[itemid]')),
			new CDiv($item_select),

			new CLabel(_('Camera type'), zbx_formatDomId($prefix.'[type]')),
			new CDiv(
				(new CTextBox($prefix.'[type]', $type, false))
					->setAttribute('placeholder', _('PTX (empty = all)'))
					->setWidth(ZBX_TEXTAREA_SMALL_WIDTH)
			)
		]))->addStyle($grid_style);

		$content = (new CDiv([
			$settings_grid,
			(new CDiv(
				(new CButton($prefix.'[remove]', _('Remove item')))
					->addClass(ZBX_STYLE_BTN_LINK)
					->addClass('element-table-remove')
			))->addStyle('margin-top: 10px; text-align: right;')
		]))->addStyle('border: 1px solid #ddd; border-radius: 4px; padding: 12px; margin: 6px 0;');

		return (new CRow(new CCol($content)))->addClass('form_row');
	}
}
