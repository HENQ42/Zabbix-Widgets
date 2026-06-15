<?php declare(strict_types = 0);

namespace Modules\MetricPanel\Includes;

use Zabbix\Widgets\{CWidgetField, CWidgetForm};
use Zabbix\Widgets\Fields\{
	CWidgetFieldCheckBox,
	CWidgetFieldMultiSelectItem,
	CWidgetFieldMultiSelectOverrideHost,
	CWidgetFieldRadioButtonList,
	CWidgetFieldRangeControl,
	CWidgetFieldSelect,
	CWidgetFieldTextBox
};

class WidgetForm extends CWidgetForm {

	// Tipos de amostra de dados.
	public const SAMPLE_SINGLE  = 1; // 1 item -> 1 serie historica
	public const SAMPLE_USAGE   = 2; // % de uso (principal) + valor usado + valor total (contexto)
	public const SAMPLE_MULTI   = 3; // % principal + N cores (multi-serie com fill)

	// Unidades binarias para conversao "de/para" (chave = expoente; fator = 1024^expoente).
	public const BIN_UNITS = [
		0 => 'B', 1 => 'KiB', 2 => 'MiB', 3 => 'GiB', 4 => 'TiB', 5 => 'PiB'
	];

	public function addFields(): self {
		return $this
			->addField(
				(new CWidgetFieldRadioButtonList('sample_type', _('Sample type'), [
					self::SAMPLE_SINGLE => _('Single item'),
					self::SAMPLE_USAGE  => _('Usage % (used / total)'),
					self::SAMPLE_MULTI  => _('Multi-percent (cores)')
				]))->setDefault(self::SAMPLE_SINGLE)
			)
			// Item principal (sempre exibido no grafico).
			->addField(
				(new CWidgetFieldMultiSelectItem('main_item', _('Main item')))
					->setFlags(CWidgetField::FLAG_NOT_EMPTY | CWidgetField::FLAG_LABEL_ASTERISK)
					->setMultiple(false)
			)
			->addField(
				(new CWidgetFieldTextBox('main_label', _('Main item label')))
					->setMaxLength(64)
			)
			// Tipo 2: itens de contexto (somente valor atual na lateral).
			->addField(
				(new CWidgetFieldMultiSelectItem('used_item', _('Used value item (type "Usage %")')))
					->setMultiple(false)
			)
			->addField(
				(new CWidgetFieldMultiSelectItem('total_item', _('Total value item (type "Usage %")')))
					->setMultiple(false)
			)
			// Conversao de unidades dos valores Used/Total (tipo "Usage %").
			// Desligado: mostra o valor cru com ate 2 casas decimais, sem escala.
			// Ligado: converte cada valor de "from" para "to" (base 1024) e usa o rotulo de destino.
			->addField(
				(new CWidgetFieldCheckBox('convert_units', _('Convert used/total units')))
					->setDefault(0)
			)
			->addField(
				(new CWidgetFieldSelect('used_from', _('Used: from'), self::BIN_UNITS))
					->setDefault(0)
			)
			->addField(
				(new CWidgetFieldSelect('used_to', _('Used: to'), self::BIN_UNITS))
					->setDefault(0)
			)
			->addField(
				(new CWidgetFieldSelect('total_from', _('Total: from'), self::BIN_UNITS))
					->setDefault(0)
			)
			->addField(
				(new CWidgetFieldSelect('total_to', _('Total: to'), self::BIN_UNITS))
					->setDefault(0)
			)
			// Tipo 3: cores (multi-serie no grafico).
			->addField(
				(new CWidgetFieldMultiSelectItem('core_items', _('Core items (type "Multi-percent")')))
					->setMultiple(true)
			)
			// Janela de historico.
			->addField(
				(new CWidgetFieldTextBox('time_from', _('From')))
					->setDefault('now-1h')
					->setFlags(CWidgetField::FLAG_NOT_EMPTY | CWidgetField::FLAG_LABEL_ASTERISK)
					->setMaxLength(32)
			)
			->addField(
				(new CWidgetFieldTextBox('time_to', _('To')))
					->setDefault('now')
					->setFlags(CWidgetField::FLAG_NOT_EMPTY | CWidgetField::FLAG_LABEL_ASTERISK)
					->setMaxLength(32)
			)
			// Aparencia.
			->addField(
				(new CWidgetFieldTextBox('accent_color', _('Main series color (hex)')))
					->setDefault('#1f7faa')
					->setMaxLength(7)
			)
			->addField(
				(new CWidgetFieldRangeControl('line_thickness', _('Line thickness (0-10)'), 0, 10, 1))
					->setDefault(3)
			)
			->addField(
				(new CWidgetFieldRangeControl('fill_intensity', _('Fill intensity (0-10)'), 0, 10, 1))
					->setDefault(4)
			)
			->addField(
				new CWidgetFieldMultiSelectOverrideHost()
			);
	}
}
