<?php declare(strict_types = 0);

/**
 * SLA Podium widget edit form.
 *
 * @var CView $this
 * @var array $data
 */

// "serviceids" is multi-value, so the rendered multi-select uses name="serviceids[]" and DOM id
// "serviceids_" (trailing underscore from zbx_formatDomId). Using the field name directly would
// miss the underscore, so we build the View first and read its real getId().
// "parent_serviceid" is single-value (setMultiple(false)), so getName() returns the DOM id as-is.
$serviceids_view = new CWidgetFieldMultiSelectServiceView($data['fields']['serviceids']);

(new CWidgetFormView($data))
	->addField(
		new CWidgetFieldMultiSelectSlaView($data['fields']['slaid'])
	)
	->addField($serviceids_view)
	->addField(
		new CWidgetFieldMultiSelectServiceView($data['fields']['parent_serviceid'])
	)
	->addField(
		new CWidgetFieldTextBoxView($data['fields']['period_label'])
	)
	->addField(
		new CWidgetFieldRadioButtonListView($data['fields']['theme'])
	)
	->includeJsFile('widget.edit.js.php')
	->initFormJs('widget_form.init('.json_encode([
		'serviceids_field_id' => $serviceids_view->getId(),
		'parent_field_id' => $data['fields']['parent_serviceid']->getName()
	], JSON_THROW_ON_ERROR).');')
	->show();
