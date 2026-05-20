<?php declare(strict_types = 0);
?>


window.widget_form = new class extends CWidgetForm {

	init({serviceid_field_id}) {
		this._$service = jQuery(`#${serviceid_field_id}`);

		if (this._$service.length) {
			const btn = this._$service.multiSelect('getSelectButton');

			if (btn) {
				btn.addEventListener('click', () => this.selectService());
			}
		}

		this.ready();
	}

	selectService() {
		const exclude_serviceids = [];

		for (const service of this._$service.multiSelect('getData')) {
			exclude_serviceids.push(service.id);
		}

		const overlay = PopUp('popup.services', {
			title: <?= json_encode('Serviço SLA') ?>,
			exclude_serviceids,
			multiple: 0
		}, {dialogueid: 'services'});

		overlay.$dialogue[0].addEventListener('dialogue.submit', (e) => {
			const data = [];

			for (const service of e.detail) {
				data.push({id: service.serviceid, name: service.name});
			}

			this._$service.multiSelect('addData', data);
		});
	}
};
