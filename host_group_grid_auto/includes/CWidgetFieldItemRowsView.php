<?php declare(strict_types = 0);

namespace Modules\HostGroupGridAuto\Includes;

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
					(new CButton('', _('Adicionar item')))
						->addClass('element-table-add')
						->addClass(ZBX_STYLE_BTN_ALT)
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

		// Fluid width: fill the dialog instead of a fixed px width, so the drill-down rows never force a
		// horizontal scrollbar in the widget config.
		$table->addStyle('width: 100%;');

		return (new CDiv($table))->addStyle('width: 100%; max-width: 100%; box-sizing: border-box;');
	}

	public function getJavaScript(): string {
		$name = $this->field->getName();

		return '
			(function() {
				var table = document.getElementById("'.$name.'-table");
				var form = table.closest("form");

				function initMultiSelects() {
					table.querySelectorAll(".js-itemid-ms").forEach(function(ms) {
						if (ms.dataset.msInit === "1") return;
						ms.dataset.msInit = "1";
						try { jQuery(ms).multiSelect(); } catch (err) {}
					});
				}

				// "Host type" selects are filled from the TYPEs discovered under the selected parent host
				// group(s). The list is fetched via AJAX on load and whenever the parent group changes, and
				// re-applied to every row (existing and freshly added).
				var typesCache = null;

				function applyTypes(sel, types) {
					var current = sel.value || sel.getAttribute("data-current") || "";
					// Keep option[0] (the empty "all types" entry), drop the rest, then rebuild.
					while (sel.options.length > 1) { sel.remove(1); }
					var matched = false;
					types.forEach(function(t) {
						var o = new Option(t, t);
						if (t === current) { o.selected = true; matched = true; }
						sel.add(o);
					});
					// Preserve a saved value that is no longer among the discovered types.
					if (current !== "" && !matched) {
						var o = new Option(current + " (?)", current);
						o.selected = true;
						sel.add(o);
					}
					if (current === "") { sel.selectedIndex = 0; }
				}

				function applyTypesToAll(types) {
					table.querySelectorAll("select.js-type-select").forEach(function(sel) {
						applyTypes(sel, types);
					});
				}

				function getParentGroupids() {
					var ids = [];
					if (!form) return ids;
					form.querySelectorAll(\'[name^="parent_group["]\').forEach(function(inp) {
						if (inp.value) { ids.push(inp.value); }
					});
					return ids;
				}

				function refreshTypes() {
					var ids = getParentGroupids();
					if (!ids.length) { typesCache = []; applyTypesToAll([]); return; }
					var url = new Curl("zabbix.php");
					url.setArgument("action", "widget.host_group_grid_auto.types.get");
					jQuery.ajax({
						url: url.getUrl(),
						method: "POST",
						data: {groupids: ids},
						dataType: "json"
					}).done(function(resp) {
						typesCache = (resp && resp.types) ? resp.types : [];
						applyTypesToAll(typesCache);
					});
				}

				refreshTypes();

				if (form) {
					jQuery(form)
						.on("change", "#parent_group_", refreshTypes)
						.on("change", \'[name^="parent_group["]\', refreshTypes);
				}

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
					if (e.target.classList.contains("js-move-up")) {
						e.preventDefault();
						var row = e.target.closest("tr.form_row");
						var prev = row ? row.previousElementSibling : null;
						if (prev && prev.classList.contains("form_row")) {
							row.parentNode.insertBefore(row, prev);
						}
					}
					if (e.target.classList.contains("js-move-down")) {
						e.preventDefault();
						var row = e.target.closest("tr.form_row");
						var next = row ? row.nextElementSibling : null;
						if (next && next.classList.contains("form_row")) {
							row.parentNode.insertBefore(next, row);
						}
					}
				});

				jQuery(table)
					.dynamicRows({template: "#'.$name.'-row-tmpl", allow_empty: true})
					.on("afteradd.dynamicRows", function() {
						initMultiSelects();
						if (typesCache !== null) { applyTypesToAll(typesCache); }
					});
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

		$type = $data['type'] ?? '';
		$label = $data['label'] ?? '';
		$regex = $data['regex'] ?? '';
		$bold = (int) ($data['bold'] ?? 0);
		$dependent = (int) ($data['dependent'] ?? 0);
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

		// "Host type" is a select populated by JS from the parent group's discovered TYPEs (see
		// getJavaScript). Server-side we only render the empty "all types" option plus the currently saved
		// value (so editing an existing config never loses it even before the AJAX list arrives).
		$type_options = [
			(new CTag('option', true, _('(todos os tipos)')))->setAttribute('value', '')
		];
		if ($type !== '') {
			$type_options[] = (new CTag('option', true, $type))
				->setAttribute('value', $type)
				->setAttribute('selected', 'selected');
		}

		$type_select = (new CTag('select', true, $type_options))
			->addClass('js-type-select')
			->setId(zbx_formatDomId($prefix.'[type]'))
			->setAttribute('name', $prefix.'[type]')
			->setAttribute('data-current', $type)
			->addStyle('width: '.ZBX_TEXTAREA_SMALL_WIDTH.'px;');

		$conditions_div = (new CDiv())
			->setId($name.'-'.$row_num.'-conds')
			->addStyle('margin-top: 4px;');

		foreach ($conditions as $ci => $cond) {
			$conditions_div->addItem($this->getConditionRow($row_num, (string) $ci, $cond));
		}

		// Label column fixed, value column flexible and allowed to shrink to 0 (minmax(0, 1fr)) so long
		// content (e.g. the "Dependente" help text) wraps instead of widening the grid past the dialog.
		$grid_style = 'display: grid; grid-template-columns: 110px minmax(0, 1fr); gap: 8px 10px;'
			.' align-items: center; width: 100%; max-width: 100%; box-sizing: border-box;';

		$settings_grid = (new CDiv([
			new CLabel(_('Tipo de host'), zbx_formatDomId($prefix.'[type]')),
			new CDiv($type_select),

			new CLabel(_('Item'), zbx_formatDomId($prefix.'[itemid]')),
			new CDiv($item_select),

			new CLabel(_('Nome'), zbx_formatDomId($prefix.'[label]')),
			new CDiv(
				(new CTextBox($prefix.'[label]', $label, false))
					->setAttribute('placeholder', _('Nome de exibição'))
					->setWidth(ZBX_TEXTAREA_SMALL_WIDTH)
			),

			new CLabel('Regex', zbx_formatDomId($prefix.'[regex]')),
			new CDiv(
				(new CTextBox($prefix.'[regex]', $regex, false))
					->setAttribute('placeholder', '/(\d+)/')
					->setWidth(ZBX_TEXTAREA_SMALL_WIDTH)
			),

			new CLabel(_('Negrito'), zbx_formatDomId($prefix.'[bold]')),
			(new CDiv([
				new CVar($prefix.'[bold]', '0'),
				(new CCheckBox($prefix.'[bold]', '1'))->setChecked($bold == 1)
			])),

			new CLabel(_('Dependente'), zbx_formatDomId($prefix.'[dependent]')),
			(new CDiv([
				new CVar($prefix.'[dependent]', '0'),
				(new CCheckBox($prefix.'[dependent]', '1'))->setChecked($dependent == 1),
				(new CDiv(_('Forçado a crítico quando todas as linhas não dependentes do mesmo host estão críticas')))
					->addStyle('font-size: 11px; opacity: 0.7; margin-top: 2px;')
			])),

			new CLabel(_('Cor padrão')),
			(new CDiv(
				(new CColorPicker($prefix.'[default_color]'))
					->setColor($default_color !== '' ? $default_color : null)
					->allowEmpty()
			))->addStyle('display: flex; align-items: center;'),

			new CLabel(_('Estado padrão')),
			new CDiv(
				(new CRadioButtonList($prefix.'[default_state]', $default_state))
					->addValue(_('Estável'), CWidgetFieldItemRows::STATE_STABLE)
					->addValue(_('Crítico'), CWidgetFieldItemRows::STATE_CRITICAL)
					->setModern()
			),

			(new CDiv(new CTag('b', true, _('Condições de cor'))))
				->addStyle('grid-column: 1 / span 2; margin-top: 6px;'),

			(new CDiv([
				$conditions_div,
				(new CButtonLink(_('Adicionar condição')))
					->addClass('js-add-condition')
					->setAttribute('data-item-row', (string) $row_num)
			]))->addStyle('grid-column: 1 / span 2;')
		]))->addStyle($grid_style);

		$content = (new CDiv([
			$settings_grid,
			(new CDiv([
				// Reorder controls: move this drill-down row up/down in reading order. The order is
				// taken straight from the DOM at submit time (setValue re-sequences), so swapping the
				// table rows is all that is needed — no index rewriting.
				(new CDiv([
					(new CButtonLink('↑'))
						->addClass('js-move-up')
						->setAttribute('title', _('Mover para cima')),
					(new CButtonLink('↓'))
						->addClass('js-move-down')
						->setAttribute('title', _('Mover para baixo'))
				]))->addStyle('display: flex; gap: 12px;'),
				(new CButton($prefix.'[remove]', _('Remover item')))
					->addClass(ZBX_STYLE_BTN_LINK)
					->addClass('element-table-remove')
			]))->addStyle('margin-top: 10px; display: flex; justify-content: space-between; align-items: center;')
		]))->addStyle('border: 1px solid #ddd; border-radius: 4px; padding: 12px; margin: 6px 0;'
			.' width: 100%; max-width: 100%; box-sizing: border-box; overflow: hidden;');

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
				new CLabel(_('Se o valor'), zbx_formatDomId($prefix.'[value]')),
				(new CTextBox($prefix.'[value]', $value, false))
					->setAttribute('placeholder', '0, >=10, <5')
					->setWidth(ZBX_TEXTAREA_SMALL_WIDTH)
			]))->addStyle('display: flex; gap: 6px; align-items: center;'),
			(new CDiv([
				new CLabel(_('exibir como'), zbx_formatDomId($prefix.'[display]')),
				(new CTextBox($prefix.'[display]', $display, false))
					->setAttribute('placeholder', _('(valor original)'))
					->setWidth(ZBX_TEXTAREA_SMALL_WIDTH)
			]))->addStyle('display: flex; gap: 6px; align-items: center;'),
			(new CDiv([
				new CLabel(_('cor')),
				(new CColorPicker($prefix.'[color]'))
					->setColor($color !== '' ? $color : null)
					->allowEmpty()
			]))->addStyle('display: flex; gap: 6px; align-items: center;'),
			(new CDiv([
				new CLabel(_('estado')),
				(new CRadioButtonList($prefix.'[state]', $state))
					->addValue(_('Estável'), CWidgetFieldItemRows::STATE_STABLE)
					->addValue(_('Crítico'), CWidgetFieldItemRows::STATE_CRITICAL)
					->setModern()
			]))->addStyle('display: flex; gap: 6px; align-items: center;'),
			(new CButtonLink(_('Remover')))->addClass('js-remove-condition')
		]))
			->addClass('condition-entry')
			->addStyle('display: flex; gap: 12px; align-items: center; flex-wrap: wrap; margin: 4px 0;'
				.' padding: 4px 0; width: 100%; max-width: 100%; box-sizing: border-box;');
	}
}
