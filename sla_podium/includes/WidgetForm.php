<?php declare(strict_types = 0);

namespace Modules\SlaPodium\Includes;

use Zabbix\Widgets\{CWidgetField, CWidgetForm};
use Zabbix\Widgets\Fields\{
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
				(new CWidgetFieldMultiSelectService('serviceids', 'Serviços do SLA'))
					->setFlags(CWidgetField::FLAG_NOT_EMPTY | CWidgetField::FLAG_LABEL_ASTERISK)
			)
			->addField(
				// Opcional. Quando preenchido, o SLI deste serviço alimenta o card de resumo
				// e o serviço é removido do ranking (pois agrega os filhos).
				(new CWidgetFieldMultiSelectService('parent_serviceid', 'Serviço pai (resumo)'))
					->setMultiple(false)
			)
			->addField(
				(new CWidgetFieldTextBox('period_label', 'Rótulo do período'))
					->setDefault('Último período')
			)
			->addField(
				(new CWidgetFieldRadioButtonList('theme', 'Tema', [
					0 => 'Claro',
					1 => 'Escuro'
				]))->setDefault(0)
			);
	}
}
