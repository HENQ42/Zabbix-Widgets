<?php declare(strict_types = 0);
?>


window.widget_form = new class extends CWidgetForm {

	init({serviceids_field_id, parent_field_id}) {
		this._$services = jQuery(`#${serviceids_field_id}`);
		this._$parent = jQuery(`#${parent_field_id}`);

		this._wireButton(this._$services, () => this.selectServices());
		this._wireButton(this._$parent, () => this.selectParent());

		this.ready();
	}

	_wireButton($ms, handler) {
		if (!$ms.length) {
			return;
		}

		const btn = $ms.multiSelect('getSelectButton');

		if (btn) {
			btn.addEventListener('click', handler);
		}
	}

	selectServices() {
		const exclude_serviceids = [];

		for (const service of this._$services.multiSelect('getData')) {
			exclude_serviceids.push(service.id);
		}

		const overlay = PopUp('popup.services', {
			title: <?= json_encode('Serviços do SLA') ?>,
			exclude_serviceids,
			multiple: 1
		}, {dialogueid: 'services'});

		overlay.$dialogue[0].addEventListener('dialogue.submit', (e) => {
			const data = [];

			for (const service of e.detail) {
				data.push({id: service.serviceid, name: service.name});
			}

			this._$services.multiSelect('addData', data);
		});
	}

	selectParent() {
		const exclude_serviceids = [];

		for (const service of this._$parent.multiSelect('getData')) {
			exclude_serviceids.push(service.id);
		}

		const overlay = PopUp('popup.services', {
			title: <?= json_encode('Serviço pai (resumo)') ?>,
			exclude_serviceids,
			multiple: 0
		}, {dialogueid: 'services'});

		overlay.$dialogue[0].addEventListener('dialogue.submit', (e) => {
			const data = [];

			for (const service of e.detail) {
				data.push({id: service.serviceid, name: service.name});
			}

			this._$parent.multiSelect('addData', data);
		});
	}
};
