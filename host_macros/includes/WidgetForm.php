<?php declare(strict_types = 0);

namespace Modules\HostMacros\Includes;

use Zabbix\Widgets\{CWidgetField, CWidgetForm};
use Zabbix\Widgets\Fields\{
	CWidgetFieldColor,
	CWidgetFieldIntegerBox,
	CWidgetFieldMultiSelectGroup,
	CWidgetFieldMultiSelectHost,
	CWidgetFieldMultiSelectOverrideHost,
	CWidgetFieldSelect,
	CWidgetFieldTextBox
};

class WidgetForm extends CWidgetForm {

	public function addFields(): self {
		return $this
			->addField(
				(new CWidgetFieldSelect('view_mode', _('View mode'), [
					0 => _('Single host (all macros)'),
					1 => _('Group (one macro per host)')
				]))->setDefault(0)
			)
			->addField(
				new CWidgetFieldMultiSelectGroup('groupids', _('Host groups'))
			)
			->addField(
				new CWidgetFieldMultiSelectHost('hostids', _('Hosts'))
			)
			->addField(
				(new CWidgetFieldTextBox('macro_filter', _('Macro name filter')))
					->setDefault('')
			)
			->addField(
				(new CWidgetFieldTextBox('hidden_macros', _('Hide values (comma-separated)')))
					->setDefault('')
			)
			->addField(
				(new CWidgetFieldIntegerBox('columns', _('Columns'), 1, 12))
					->setDefault(4)
			)
			->addField(
				(new CWidgetFieldColor('header_color', _('Header color')))
					->setDefault('1976D2')
			)
			->addField(
				new CWidgetFieldMultiSelectOverrideHost()
			);
	}
}
