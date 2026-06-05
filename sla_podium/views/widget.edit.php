<?php declare(strict_types = 0);

/**
 * SLA Podium widget edit form.
 *
 * @var CView $this
 * @var array $data
 */

(new CWidgetFormView($data))
	->addField(
		new CWidgetFieldMultiSelectSlaView($data['fields']['slaid'])
	)
	->addField(
		new CWidgetFieldTextBoxView($data['fields']['period_label'])
	)
	->show();
