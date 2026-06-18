<?php declare(strict_types = 0);

namespace Modules\CameraTrigger\Includes;

use Zabbix\Widgets\{CWidgetField, CWidgetForm};
use Zabbix\Widgets\Fields\{
	CWidgetFieldIntegerBox,
	CWidgetFieldMultiSelectHost,
	CWidgetFieldMultiSelectOverrideHost,
	CWidgetFieldTextBox
};

class WidgetForm extends CWidgetForm {

	public function addFields(): self {
		return $this
			// Em template dashboard o host vem do contexto (override_hostid);
			// o seletor explícito só existe em dashboards normais.
			->addField($this->isTemplateDashboard()
				? null
				: (new CWidgetFieldMultiSelectHost('hostid', _('Host')))
					->setFlags(CWidgetField::FLAG_NOT_EMPTY | CWidgetField::FLAG_LABEL_ASTERISK)
					->setMultiple(false)
			)
			->addField(
				new CWidgetFieldMultiSelectOverrideHost()
			)
			->addField(
				(new CWidgetFieldTextBox('macro_url', _('URL macro')))
					->setDefault('{$CAM_URL}')
					->setMaxLength(255)
			)
			->addField(
				(new CWidgetFieldTextBox('macro_user', _('Username macro')))
					->setDefault('{$CAM_USER}')
					->setMaxLength(255)
			)
			->addField(
				(new CWidgetFieldTextBox('macro_pass', _('Password macro')))
					->setDefault('{$CAM_PASS}')
					->setMaxLength(255)
			)
			->addField(
				(new CWidgetFieldIntegerBox('trigger_timeout', _('Trigger timeout (seconds)'), 1, 300))
					->setDefault(60)
			)
			->addField(
				(new CWidgetFieldIntegerBox('refresh_sec', _('Auto refresh (seconds, 0 = off)'), 0, 3600))
					->setDefault(0)
			);
	}
}
