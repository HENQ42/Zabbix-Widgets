<?php declare(strict_types = 0);

namespace Modules\SlaCard\Includes;

use Zabbix\Widgets\{CWidgetField, CWidgetForm};
use Zabbix\Widgets\Fields\{
	CWidgetFieldMultiSelectOverrideHost,
	CWidgetFieldMultiSelectService,
	CWidgetFieldMultiSelectSla,
	CWidgetFieldRadioButtonList,
	CWidgetFieldTextBox
};

class WidgetForm extends CWidgetForm {

	public function addFields(): self {
		return $this
			->addField(
				(new CWidgetFieldMultiSelectSla('slaid', 'SLA'))
					->setFlags(CWidgetField::FLAG_NOT_EMPTY | CWidgetField::FLAG_LABEL_ASTERISK)
					->setMultiple(false)
			)
			->addField(
				// Optional manual override. When empty, the controller tries to find a
				// service whose name matches the dashboard host's name.
				(new CWidgetFieldMultiSelectService('serviceid', 'Serviço SLA (opcional)'))
					->setMultiple(false)
			)
			->addField(
				(new CWidgetFieldTextBox('period_label', 'Rótulo do período'))
					->setDefault('Mês atual')
			)
			->addField(
				// "Automático" segue o tema do perfil do usuário (fallback: tema padrão da GUI).
				(new CWidgetFieldRadioButtonList('theme', 'Tema', [
					2 => 'Automático',
					0 => 'Claro',
					1 => 'Escuro'
				]))->setDefault(2)
			)
			->addField(
				// Required for template dashboards — auto-injects the active host context.
				new CWidgetFieldMultiSelectOverrideHost()
			);
	}
}
