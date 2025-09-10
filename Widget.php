<?php declare(strict_types = 0);


namespace Modules\RMEPieChart;

use Zabbix\Core\CWidget;

class Widget extends CWidget {

	public function getDefaultName(): string {
		return _('RME Pie chart');
	}

	public function getTranslationStrings(): array {
		return [
			'class.svgpie.js' => [
				'No data' => _('No data')
			],
			'class.widget.js' => [
				'Actions' => _('Actions'),
				'Download image' => _('Download image'),
				'Value' => _('Value'),
				'no data' => _('no data')
			]
		];
	}
}
