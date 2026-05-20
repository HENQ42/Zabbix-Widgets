<?php declare(strict_types = 0);

/**
 * SLA Card widget edit form.
 *
 * @var CView $this
 * @var array $data
 */

$form = (new CWidgetFormView($data))
	->addField(
		new CWidgetFieldMultiSelectSlaView($data['fields']['slaid'])
	)
	->addField(
		new CWidgetFieldMultiSelectServiceView($data['fields']['serviceid'])
	)
	->addField(
		new CWidgetFieldTextBoxView($data['fields']['period_label'])
	)
	->addField(
		new CWidgetFieldRadioButtonListView($data['fields']['theme'])
	);

// Override host selector — only render on regular dashboards (template dashboards inject it).
if ($data['templateid'] === null) {
	$form->addField(
		new CWidgetFieldMultiSelectOverrideHostView($data['fields']['override_hostid'])
	);
}

$form
	->includeJsFile('widget.edit.js.php')
	->initFormJs('widget_form.init('.json_encode([
		'serviceid_field_id' => $data['fields']['serviceid']->getName()
	], JSON_THROW_ON_ERROR).');')
	->show();
