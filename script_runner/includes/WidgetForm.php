<?php declare(strict_types = 0);

namespace Modules\ScriptRunner\Includes;

use Zabbix\Widgets\CWidgetForm;
use Zabbix\Widgets\Fields\CWidgetFieldTextBox;

/**
 * O widget e auto-suficiente: o catalogo de scripts vem da pasta scripts/.
 * O unico campo de configuracao e um titulo opcional.
 */
class WidgetForm extends CWidgetForm {

	public function addFields(): self {
		return $this
			->addField(
				new CWidgetFieldTextBox('titulo', _('Titulo (opcional)'))
			);
	}
}
