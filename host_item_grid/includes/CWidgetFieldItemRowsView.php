<?php declare(strict_types = 0);

namespace Modules\HostItemGrid\Includes;

use API,
	CButton,
	CButtonLink,
	CCheckBox,
	CCol,
	CColorPicker,
	CDiv,
	CLabel,
	CMultiSelect,
	CRadioButtonList,
	CRow,
	CTable,
	CTag,
	CTemplateTag,
	CTextBox,
	CVar,
	CWidgetFieldView;

class CWidgetFieldItemRowsView extends CWidgetFieldView {

	public function __construct(CWidgetFieldItemRows $field) {
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

			$table->addRow($this->getItemRow((string) $i, $row, $row_items));
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

				table.addEventListener("click", function(e) {
					if (e.target.classList.contains("js-add-condition")) {
						e.preventDefault();
						var itemRow = e.target.getAttribute("data-item-row");
						var container = document.getElementById("'.$name.'-" + itemRow + "-conds");
						var idx = container.querySelectorAll(".condition-entry").length;
						var html = document.getElementById("'.$name.'-cond-tmpl").innerHTML
							.replace(/\{itemRow\}/g, itemRow)
							.replace(/\{condRow\}/g, idx);
						var div = document.createElement("div");
						div.innerHTML = html;
						container.appendChild(div.firstElementChild);
					}
					if (e.target.classList.contains("js-remove-condition")) {
						e.preventDefault();
						e.target.closest(".condition-entry").remove();
					}
				});

				jQuery(table)
					.dynamicRows({template: "#'.$name.'-row-tmpl", allow_empty: true})
					.on("afteradd.dynamicRows", initMultiSelects);
			})();
		';
	}

	public function getTemplates(): array {
		$name = $this->field->getName();

		return [
			new CTemplateTag($name.'-row-tmpl', $this->getItemRow('#{rowNum}')),
			new CTemplateTag($name.'-cond-tmpl', $this->getConditionRow('{itemRow}', '{condRow}'))
		];
	}

	private function getItemRow($row_num, array $data = [], array $row_items = []): CRow {
		$name = $this->field->getName();
		$prefix = $name.'['.$row_num.']';

		$label = $data['label'] ?? '';
		$regex = $data['regex'] ?? '';
		$bold = (int) ($data['bold'] ?? 0);
		$default_color = $data['default_color'] ?? '';
		$default_state = (int) ($data['default_state'] ?? CWidgetFieldItemRows::STATE_STABLE);
		$conditions = $data['conditions'] ?? [];

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

		$conditions_div = (new CDiv())
			->setId($name.'-'.$row_num.'-conds')
			->addStyle('margin-top: 4px;');

		foreach ($conditions as $ci => $cond) {
			$conditions_div->addItem($this->getConditionRow($row_num, (string) $ci, $cond));
		}

		$grid_style = 'display: grid; grid-template-columns: 120px max-content; gap: 8px 10px; align-items: center;';

		$settings_grid = (new CDiv([
			new CLabel(_('Item'), zbx_formatDomId($prefix.'[itemid]')),
			new CDiv($item_select),

			new CLabel(_('Name'), zbx_formatDomId($prefix.'[label]')),
			new CDiv(
				(new CTextBox($prefix.'[label]', $label, false))
					->setAttribute('placeholder', _('Display name'))
					->setWidth(ZBX_TEXTAREA_SMALL_WIDTH)
			),

			new CLabel('Regex', zbx_formatDomId($prefix.'[regex]')),
			new CDiv(
				(new CTextBox($prefix.'[regex]', $regex, false))
					->setAttribute('placeholder', '/(\d+)/')
					->setWidth(ZBX_TEXTAREA_SMALL_WIDTH)
			),

			new CLabel(_('Bold'), zbx_formatDomId($prefix.'[bold]')),
			(new CDiv([
				new CVar($prefix.'[bold]', '0'),
				(new CCheckBox($prefix.'[bold]', '1'))->setChecked($bold == 1)
			])),

			new CLabel(_('Default color')),
			(new CDiv(
				(new CColorPicker($prefix.'[default_color]'))
					->setColor($default_color !== '' ? $default_color : null)
					->allowEmpty()
			))->addStyle('display: flex; align-items: center;'),

			new CLabel(_('Default state')),
			new CDiv(
				(new CRadioButtonList($prefix.'[default_state]', $default_state))
					->addValue(_('Stable'), CWidgetFieldItemRows::STATE_STABLE)
					->addValue(_('Critical'), CWidgetFieldItemRows::STATE_CRITICAL)
					->setModern()
			),

			(new CDiv(new CTag('b', true, _('Color conditions'))))
				->addStyle('grid-column: 1 / span 2; margin-top: 6px;'),

			(new CDiv([
				$conditions_div,
				(new CButtonLink(_('Add condition')))
					->addClass('js-add-condition')
					->setAttribute('data-item-row', (string) $row_num)
			]))->addStyle('grid-column: 1 / span 2;')
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

	private function getConditionRow($item_row, $cond_row, array $data = []): CDiv {
		$name = $this->field->getName();
		$prefix = $name.'['.$item_row.'][conditions]['.$cond_row.']';

		$value = $data['value'] ?? '';
		$display = $data['display'] ?? '';
		$color = $data['color'] ?? '';
		$state = (int) ($data['state'] ?? CWidgetFieldItemRows::STATE_STABLE);

		return (new CDiv([
			(new CDiv([
				new CLabel(_('If value'), zbx_formatDomId($prefix.'[value]')),
				(new CTextBox($prefix.'[value]', $value, false))
					->setAttribute('placeholder', '0')
					->setWidth(ZBX_TEXTAREA_SMALL_WIDTH)
			]))->addStyle('display: flex; gap: 6px; align-items: center;'),
			(new CDiv([
				new CLabel(_('show as'), zbx_formatDomId($prefix.'[display]')),
				(new CTextBox($prefix.'[display]', $display, false))
					->setAttribute('placeholder', _('(original value)'))
					->setWidth(ZBX_TEXTAREA_SMALL_WIDTH)
			]))->addStyle('display: flex; gap: 6px; align-items: center;'),
			(new CDiv([
				new CLabel(_('color')),
				(new CColorPicker($prefix.'[color]'))
					->setColor($color !== '' ? $color : null)
					->allowEmpty()
			]))->addStyle('display: flex; gap: 6px; align-items: center;'),
			(new CDiv([
				new CLabel(_('state')),
				(new CRadioButtonList($prefix.'[state]', $state))
					->addValue(_('Stable'), CWidgetFieldItemRows::STATE_STABLE)
					->addValue(_('Critical'), CWidgetFieldItemRows::STATE_CRITICAL)
					->setModern()
			]))->addStyle('display: flex; gap: 6px; align-items: center;'),
			(new CButtonLink(_('Remove')))->addClass('js-remove-condition')
		]))
			->addClass('condition-entry')
			->addStyle('display: flex; gap: 16px; align-items: center; flex-wrap: wrap; margin: 4px 0; padding: 4px 0;');
	}
}
