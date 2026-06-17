<?php declare(strict_types = 0);

namespace Modules\HostGroupGridAuto\Includes;

use CButton,
	CButtonLink,
	CCol,
	CColorPicker,
	CDiv,
	CLabel,
	CRow,
	CTable,
	CTag,
	CTemplateTag,
	CTextBox,
	CVar,
	CWidgetFieldView;

class CWidgetFieldSiteTypeRowsView extends CWidgetFieldView {

	public function __construct(CWidgetFieldSiteTypeRows $field) {
		$this->field = $field;
	}

	public function getView(): CDiv {
		$name = $this->field->getName();

		$table = (new CTable())
			->setId($name.'-table')
			->addClass(ZBX_STYLE_TABLE_FORMS)
			->setFooter(new CRow(
				new CCol(
					(new CButton('', _('Adicionar tipo de site')))
						->addClass('element-table-add')
						->addClass(ZBX_STYLE_BTN_ALT)
				)
			));

		foreach ($this->field->getValue() as $i => $row) {
			$table->addRow($this->getSiteTypeRow((string) $i, $row));
		}

		$table->addStyle('width: 100%;');

		return (new CDiv([$table, $this->getPresetControls()]))
			->addStyle('width: 100%; max-width: 100%; box-sizing: border-box;');
	}

	/**
	 * Barra de predefinições (presets): carregar/apagar uma existente e salvar a atual como predefinição
	 * atrelada a um grupo de usuários. Os selects são preenchidos via AJAX (presets.list) — aqui só fica a
	 * casca; o JS popula opções e liga os botões.
	 */
	private function getPresetControls(): CDiv {
		$name = $this->field->getName();

		$row_style = 'display: flex; gap: 8px; align-items: center; flex-wrap: wrap; margin: 4px 0;';

		// Selects nativos (não CSelect/z-select): preenchidos por JS com .add(new Option())/.value, que só
		// funcionam de forma confiável em <select> nativo. Mesmo motivo do select de tipo na View de itens.
		$preset_select = (new CTag('select', true, []))
			->setId($name.'-preset-select')
			->addClass('js-preset-select')
			->addStyle('min-width: 260px;');

		$usrgrp_select = (new CTag('select', true, []))
			->setId($name.'-preset-usrgrp')
			->addClass('js-preset-usrgrp')
			->addStyle('min-width: 220px;');

		$load_row = (new CDiv([
			new CLabel(_('Predefinição')),
			$preset_select,
			(new CButtonLink(_('Carregar')))->addClass('js-preset-load'),
			(new CButtonLink(_('Apagar')))->addClass('js-preset-delete')
		]))->addStyle($row_style);

		$save_row = (new CDiv([
			new CLabel(_('Salvar como')),
			(new CTextBox($name.'-preset-name', '', false))
				->setId($name.'-preset-name')
				->setAttribute('placeholder', _('Nome da predefinição'))
				->setWidth(ZBX_TEXTAREA_SMALL_WIDTH),
			new CLabel(_('Grupo dono')),
			$usrgrp_select,
			(new CButtonLink(_('Salvar predefinição')))->addClass('js-preset-save')
		]))->addStyle($row_style);

		$status = (new CDiv())
			->setId($name.'-preset-status')
			->addStyle('font-size: 11px; opacity: 0.8; min-height: 14px; margin-top: 2px;');

		return (new CDiv([$load_row, $save_row, $status]))
			->addStyle('border-top: 1px solid #ddd; margin-top: 10px; padding-top: 10px;'
				.' width: 100%; max-width: 100%; box-sizing: border-box;');
	}

	public function getJavaScript(): string {
		$name = $this->field->getName();
		$list_action = 'widget.host_group_grid_auto.presets.list';
		$save_action = 'widget.host_group_grid_auto.presets.save';
		$load_action = 'widget.host_group_grid_auto.presets.load';
		$delete_action = 'widget.host_group_grid_auto.presets.delete';
		$sites_action = 'widget.host_group_grid_auto.sites.get';

		// Strings traduzidas embutidas como literais JS via json_encode (escapa aspas/unicode).
		$t = [
			'select' => json_encode(_('(selecione)')),
			'no_groups' => json_encode(_('(sem grupos)')),
			'list_fail' => json_encode(_('Falha ao listar predefinições')),
			'need_name' => json_encode(_('Informe o nome da predefinição')),
			'need_group' => json_encode(_('Selecione o grupo dono')),
			'saved' => json_encode(_('Predefinição salva')),
			'save_fail' => json_encode(_('Falha ao salvar')),
			'need_preset' => json_encode(_('Selecione uma predefinição')),
			'loaded' => json_encode(_('Predefinição carregada')),
			'load_fail' => json_encode(_('Falha ao carregar')),
			'deleted' => json_encode(_('Predefinição apagada')),
			'delete_fail' => json_encode(_('Falha ao apagar')),
			'sites_fail' => json_encode(_('Falha ao listar sites')),
			'unnamed' => json_encode(_('sem nome')),
			'no_sites' => json_encode(_('Nenhum site descoberto sob o grupo pai.'))
		];

		return '
			(function() {
				var FIELD = '.json_encode($name).';
				var table = document.getElementById(FIELD + "-table");
				var form = table.closest("form");
				var addBtn = function() { return table.querySelector(".element-table-add"); };
				var presetSel = document.getElementById(FIELD + "-preset-select");
				var usrgrpSel = document.getElementById(FIELD + "-preset-usrgrp");
				var nameInp = document.getElementById(FIELD + "-preset-name");
				var statusEl = document.getElementById(FIELD + "-preset-status");

				var UNNAMED = '.$t['unnamed'].';
				var sitesCache = null; // universo de sites descobertos sob o grupo pai

				function setStatus(msg, isError) {
					if (!statusEl) return;
					statusEl.textContent = msg || "";
					statusEl.style.color = isError ? "#DC2626" : "";
				}

				function ajax(action, data) {
					var url = new Curl("zabbix.php");
					url.setArgument("action", action);
					return jQuery.ajax({
						url: url.getUrl(), method: "POST", data: data || {}, dataType: "json"
					});
				}

				// --- ler/escrever o grid -------------------------------------------------------------

				// Coleta as linhas atuais do grid em [{name, color, sites:[...]}].
				function collectRows() {
					var out = [];
					table.querySelectorAll("tr.form_row").forEach(function(tr) {
						var n = tr.querySelector(\'input[name$="[name]"]\');
						var g = tr.querySelector(\'input[name$="[sigla]"]\');
						var c = tr.querySelector(\'input[name$="[color]"]\');
						var s = tr.querySelector(\'input[name$="[sites]"]\');
						var nameVal = n ? n.value.trim() : "";
						var sitesVal = s ? s.value.trim() : "";
						if (nameVal === "" && sitesVal === "") return;
						out.push({
							name: nameVal,
							sigla: g ? g.value.trim() : "",
							color: c ? c.value.trim() : "",
							sites: sitesVal.split(/[\s,]+/).filter(function(x) { return x !== ""; })
						});
					});
					return out;
				}

				// Substitui o conteúdo do grid pelas linhas de uma predefinição carregada.
				function fillRows(types) {
					table.querySelectorAll("tr.form_row").forEach(function(tr) { tr.remove(); });
					(types || []).forEach(function(t) {
						var btn = addBtn();
						if (btn) { btn.dispatchEvent(new MouseEvent("click", {bubbles: true})); }
						var rows = table.querySelectorAll("tr.form_row");
						var tr = rows[rows.length - 1];
						if (!tr) return;
						var n = tr.querySelector(\'input[name$="[name]"]\');
						var g = tr.querySelector(\'input[name$="[sigla]"]\');
						var c = tr.querySelector(\'input[name$="[color]"]\');
						var s = tr.querySelector(\'input[name$="[sites]"]\');
						if (n) { n.value = t.name || ""; }
						if (g) { g.value = t.sigla || ""; }
						if (s) { s.value = (t.sites || []).join(", "); }
						if (c) {
							c.value = t.color || "";
							c.dispatchEvent(new Event("change", {bubbles: true}));
						}
					});
					rebuildSitesSelects();
				}

				// --- sites (select por linha) --------------------------------------------------------

				function parseNums(str) {
					return String(str || "").split(/[\s,]+/).filter(function(x) { return x !== ""; });
				}

				function mkCheckbox(num, checked, disabled, label) {
					var lbl = document.createElement("label");
					lbl.style.display = "flex";
					lbl.style.alignItems = "center";
					lbl.style.gap = "6px";
					lbl.style.padding = "1px 4px";
					lbl.style.borderRadius = "3px";
					lbl.style.cursor = disabled ? "not-allowed" : "pointer";
					if (disabled) { lbl.style.color = "#555"; lbl.style.background = "#e0e0e0"; }
					var cb = document.createElement("input");
					cb.type = "checkbox";
					cb.className = "js-site-cb";
					cb.value = num;
					cb.checked = !!checked;
					cb.disabled = !!disabled;
					var span = document.createElement("span");
					span.textContent = label;
					lbl.appendChild(cb);
					lbl.appendChild(span);
					return lbl;
				}

				// Reconstrói a lista de checkboxes de uma linha: um por site do universo; marcado se já
				// escolhido nesta linha; desabilitado (mais escuro + nome do tipo dono) se em outra linha.
				// Números salvos que não existem mais no universo entram marcados como "(?)".
				function buildSitesSelect(tr) {
					var box = tr.querySelector("div.js-sites-box");
					var hidden = tr.querySelector(\'input[name$="[sites]"]\');
					if (!box || !hidden) return;

					var selectedNums = parseNums(hidden.value);
					var selectedSet = {};
					selectedNums.forEach(function(n) { selectedSet[n] = true; });

					// Dono (outra linha) de cada número: número -> nome do tipo.
					var owners = {};
					table.querySelectorAll("tr.form_row").forEach(function(other) {
						if (other === tr) return;
						var oh = other.querySelector(\'input[name$="[sites]"]\');
						var on = other.querySelector(\'input[name$="[name]"]\');
						if (!oh) return;
						var oname = (on && on.value.trim()) ? on.value.trim() : UNNAMED;
						parseNums(oh.value).forEach(function(n) { owners[n] = oname; });
					});

					box.innerHTML = "";

					var universe = sitesCache || [];
					var inUniverse = {};
					universe.forEach(function(site) {
						inUniverse[site.number] = true;
						var isSel = !!selectedSet[site.number];
						var ownedElsewhere = owners[site.number] && !isSel;
						var label = ownedElsewhere
							? (site.number + "  —  (" + owners[site.number] + ")")
							: site.number;
						box.appendChild(mkCheckbox(site.number, isSel, ownedElsewhere, label));
					});

					// Preserva valores salvos que não estão (mais) entre os sites descobertos.
					selectedNums.forEach(function(n) {
						if (!inUniverse[n]) {
							box.appendChild(mkCheckbox(n, true, false, n + " (?)"));
						}
					});

					if (!box.children.length) {
						var empty = document.createElement("span");
						empty.style.cssText = "font-size: 11px; opacity: 0.7;";
						empty.textContent = '.$t['no_sites'].';
						box.appendChild(empty);
					}
				}

				// Lê os checkboxes marcados de uma linha para o input hidden [sites] (valor que é salvo).
				function syncHiddenFromSelect(tr) {
					var box = tr.querySelector("div.js-sites-box");
					var hidden = tr.querySelector(\'input[name$="[sites]"]\');
					if (!box || !hidden) return;
					var vals = [];
					box.querySelectorAll("input.js-site-cb").forEach(function(cb) {
						if (cb.checked) { vals.push(cb.value); }
					});
					hidden.value = vals.join(", ");
				}

				function rebuildSitesSelects() {
					table.querySelectorAll("tr.form_row").forEach(buildSitesSelect);
				}

				function getParentGroupids() {
					var ids = [];
					if (!form) return ids;
					form.querySelectorAll(\'[name^="parent_group["]\').forEach(function(inp) {
						if (inp.value) { ids.push(inp.value); }
					});
					return ids;
				}

				function refreshSites() {
					var ids = getParentGroupids();
					if (!ids.length) { sitesCache = []; rebuildSitesSelects(); return; }
					ajax("'.$sites_action.'", {groupids: ids}).done(function(resp) {
						if (resp && resp.error) { setStatus((resp.error.messages || []).join(" "), true); return; }
						sitesCache = (resp && resp.sites) ? resp.sites : [];
						rebuildSitesSelects();
					}).fail(function() { setStatus('.$t['sites_fail'].', true); });
				}

				// --- selects -------------------------------------------------------------------------

				function optionValue(usrgrpid, presetName) {
					return JSON.stringify([String(usrgrpid), String(presetName)]);
				}

				function rebuildPresetSelect(presets) {
					if (!presetSel) return;
					while (presetSel.options.length) { presetSel.remove(0); }
					presetSel.add(new Option('.$t['select'].', ""));
					(presets || []).forEach(function(p) {
						var label = p.name + " [" + (p.usrgrp_name || ("#" + p.usrgrpid)) + "]";
						presetSel.add(new Option(label, optionValue(p.usrgrpid, p.name)));
					});
				}

				function rebuildUsrgrpSelect(groups) {
					if (!usrgrpSel) return;
					while (usrgrpSel.options.length) { usrgrpSel.remove(0); }
					(groups || []).forEach(function(g) {
						usrgrpSel.add(new Option(g.name, String(g.usrgrpid)));
					});
					if (!groups || !groups.length) {
						usrgrpSel.add(new Option('.$t['no_groups'].', ""));
					}
				}

				function refreshList() {
					return ajax("'.$list_action.'").done(function(resp) {
						if (resp && resp.error) { setStatus((resp.error.messages || []).join(" "), true); return; }
						rebuildPresetSelect(resp ? resp.presets : []);
						rebuildUsrgrpSelect(resp ? resp.own_groups : []);
					}).fail(function() { setStatus('.$t['list_fail'].', true); });
				}

				// --- ações dos botões ----------------------------------------------------------------

				function onSave() {
					var pname = (nameInp && nameInp.value.trim()) || "";
					var usrgrpid = (usrgrpSel && usrgrpSel.value) || "";
					if (pname === "") { setStatus('.$t['need_name'].', true); return; }
					if (usrgrpid === "") { setStatus('.$t['need_group'].', true); return; }
					ajax("'.$save_action.'", {
						usrgrpid: usrgrpid, name: pname, data: JSON.stringify({types: collectRows()})
					}).done(function(resp) {
						if (resp && resp.error) { setStatus((resp.error.messages || []).join(" "), true); return; }
						setStatus('.$t['saved'].', false);
						refreshList();
					}).fail(function() { setStatus('.$t['save_fail'].', true); });
				}

				function selectedPreset() {
					if (!presetSel || !presetSel.value) return null;
					try { var v = JSON.parse(presetSel.value); return {usrgrpid: v[0], name: v[1]}; }
					catch (e) { return null; }
				}

				function onLoad() {
					var p = selectedPreset();
					if (!p) { setStatus('.$t['need_preset'].', true); return; }
					ajax("'.$load_action.'", {usrgrpid: p.usrgrpid, name: p.name}).done(function(resp) {
						if (resp && resp.error) { setStatus((resp.error.messages || []).join(" "), true); return; }
						var data = resp ? resp.data : null;
						fillRows(data ? data.types : []);
						if (nameInp) { nameInp.value = p.name; }
						if (usrgrpSel) { usrgrpSel.value = String(p.usrgrpid); }
						setStatus('.$t['loaded'].', false);
					}).fail(function() { setStatus('.$t['load_fail'].', true); });
				}

				function onDelete() {
					var p = selectedPreset();
					if (!p) { setStatus('.$t['need_preset'].', true); return; }
					ajax("'.$delete_action.'", {usrgrpid: p.usrgrpid, name: p.name}).done(function(resp) {
						if (resp && resp.error) { setStatus((resp.error.messages || []).join(" "), true); return; }
						setStatus('.$t['deleted'].', false);
						refreshList();
					}).fail(function() { setStatus('.$t['delete_fail'].', true); });
				}

				table.parentNode.addEventListener("click", function(e) {
					if (e.target.classList.contains("js-preset-save")) { e.preventDefault(); onSave(); }
					if (e.target.classList.contains("js-preset-load")) { e.preventDefault(); onLoad(); }
					if (e.target.classList.contains("js-preset-delete")) { e.preventDefault(); onDelete(); }
				});

				// Mudou a seleção de sites de uma linha: grava no hidden e recomputa o desabilitado das
				// demais. Mudou o nome de um tipo: recomputa para atualizar o rótulo do dono.
				table.addEventListener("change", function(e) {
					var t = e.target;
					if (t.classList && t.classList.contains("js-site-cb")) {
						var tr = t.closest("tr.form_row");
						if (tr) { syncHiddenFromSelect(tr); rebuildSitesSelects(); }
					}
					else if (t.matches && t.matches(\'input[name$="[name]"]\')) {
						rebuildSitesSelects();
					}
				});

				// Removeu uma linha: libera os sites dela para as demais (no próximo tick, após o DOM).
				table.addEventListener("click", function(e) {
					if (e.target.classList.contains("element-table-remove")) {
						setTimeout(rebuildSitesSelects, 0);
					}
				});

				jQuery(table)
					.dynamicRows({template: "#" + FIELD + "-row-tmpl", allow_empty: true})
					.on("afteradd.dynamicRows", function() { rebuildSitesSelects(); });

				if (form) {
					jQuery(form)
						.on("change", "#parent_group_", refreshSites)
						.on("change", \'[name^="parent_group["]\', refreshSites);
				}

				rebuildSitesSelects();
				refreshList();
				refreshSites();
			})();
		';
	}

	public function getTemplates(): array {
		$name = $this->field->getName();

		return [
			new CTemplateTag($name.'-row-tmpl', $this->getSiteTypeRow('#{rowNum}'))
		];
	}

	private function getSiteTypeRow($row_num, array $data = []): CRow {
		$name = $this->field->getName();
		$prefix = $name.'['.$row_num.']';

		$type_name = $data['name'] ?? '';
		$sigla = $data['sigla'] ?? '';
		$color = $data['color'] ?? '';
		$sites = $data['sites'] ?? '';

		$grid_style = 'display: grid; grid-template-columns: 110px minmax(0, 1fr); gap: 8px 10px;'
			.' align-items: center; width: 100%; max-width: 100%; box-sizing: border-box;';

		$settings_grid = (new CDiv([
			new CLabel(_('Nome do tipo'), zbx_formatDomId($prefix.'[name]')),
			new CDiv(
				(new CTextBox($prefix.'[name]', $type_name, false))
					->setAttribute('placeholder', _('Ex.: Pórtico de fronteira'))
					->setWidth(ZBX_TEXTAREA_SMALL_WIDTH)
			),

			new CLabel(_('Sigla'), zbx_formatDomId($prefix.'[sigla]')),
			new CDiv(
				(new CTextBox($prefix.'[sigla]', $sigla, false))
					->setAttribute('placeholder', _('Ex.: PF'))
					->setWidth(ZBX_TEXTAREA_SMALL_WIDTH)
			),

			new CLabel(_('Cor')),
			(new CDiv(
				(new CColorPicker($prefix.'[color]'))
					->setColor($color !== '' ? $color : null)
					->allowEmpty()
			))->addStyle('display: flex; align-items: center;'),

			new CLabel(_('Sites')),
			// Lista de checkboxes (UI) preenchida por JS com os sites descobertos — clique simples marca
			// cada um. O input hidden [sites] carrega o valor real que será salvo (números separados por
			// vírgula), sincronizado pelo JS a cada mudança.
			new CDiv([
				(new CDiv())
					->addClass('js-sites-box')
					->addStyle('display: grid; grid-template-columns: repeat(auto-fill, minmax(130px, 1fr));'
						.' gap: 2px 8px; max-height: 160px; overflow: auto; border: 1px solid #ddd;'
						.' border-radius: 4px; padding: 6px; width: 100%; max-width: 100%; box-sizing: border-box;'),
				new CVar($prefix.'[sites]', $sites),
				(new CDiv(_('Sites em uso por outro tipo aparecem desabilitados, com o nome do tipo dono.')))
					->addStyle('font-size: 11px; opacity: 0.7; margin-top: 2px;')
			])
		]))->addStyle($grid_style);

		$content = (new CDiv([
			$settings_grid,
			(new CDiv(
				(new CButton($prefix.'[remove]', _('Remover tipo')))
					->addClass(ZBX_STYLE_BTN_LINK)
					->addClass('element-table-remove')
			))->addStyle('margin-top: 10px; display: flex; justify-content: flex-end;')
		]))->addStyle('border: 1px solid #ddd; border-radius: 4px; padding: 12px; margin: 6px 0;'
			.' width: 100%; max-width: 100%; box-sizing: border-box; overflow: hidden;');

		return (new CRow(new CCol($content)))->addClass('form_row');
	}
}
