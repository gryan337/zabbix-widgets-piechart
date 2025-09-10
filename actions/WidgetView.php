<?php declare(strict_types = 0);


namespace Modules\RMEPieChart\Actions;

use API,
	CAggFunctionData,
	CArrayHelper,
	CColorPicker,
	CControllerDashboardWidgetView,
	CControllerResponseData,
	CItemHelper,
	CMacrosResolverHelper,
	Manager;

use Modules\RMEPieChart\Includes\{
	CWidgetFieldDataSet,
	WidgetForm
};

class WidgetView extends CControllerDashboardWidgetView {

	private const LEGEND_AGGREGATION_ON = 1;
	private const LEGEND_VALUE_ON = 1;
	private const MERGE_SECTORS_ON = 1;
	private const SHOW_TOTAL_ON = 1;
	private const SHOW_UNITS_ON = 1;
	private const VALUE_BOLD_ON = 1;

	protected function init(): void {
		parent::init();

		$this->addValidationRules([
			'has_custom_time_period' => 'in 1',
			'with_config' => 'in 1'
		]);
	}

	protected function doAction(): void {
		$pie_chart_options = [
			'data_sets' => array_values($this->fields_values['ds']),
			'data_source' => $this->fields_values['source'],
			'time_period' => [
				'time_from' => $this->fields_values['time_period']['from_ts'],
				'time_to' => $this->fields_values['time_period']['to_ts']
			],
			'templateid' => $this->getInput('templateid', ''),
			'override_hostid' => $this->fields_values['override_hostid']
				? $this->fields_values['override_hostid'][0]
				: '',
			'merge_sectors' => [
				'merge' => $this->fields_values['merge'],
				'percent' => $this->fields_values['merge'] == self::MERGE_SECTORS_ON
					? $this->fields_values['merge_percent']
					: null,
				'color' => $this->fields_values['merge'] == self::MERGE_SECTORS_ON
					? '#'.$this->fields_values['merge_color']
					: null
			],
			'total_value' => [
				'total_show' => $this->fields_values['total_show'],
				'decimal_places' => $this->fields_values['total_show'] == self::SHOW_TOTAL_ON
					? $this->fields_values['decimal_places']
					: null
			],
			'units' => [
				'units_show' => $this->fields_values['units_show'],
				'units_value' => $this->fields_values['units_show'] == self::SHOW_UNITS_ON
					? $this->fields_values['units']
					: null
			],
			'legend_aggregation_show' => $this->fields_values['legend_aggregation'] == self::LEGEND_AGGREGATION_ON
		];

		$data = [
			'name' => $this->getInput('name', $this->widget->getDefaultName()),
			'info' => $this->makeWidgetInfo(),
			'user' => [
				'debug_mode' => $this->getDebugMode()
			],
			'vars' => []
		];

		foreach ($pie_chart_options['data_sets'] as &$ds) {
			if (!isset($ds['dataset_type']) || (int)$ds['dataset_type'] !== CWidgetFieldDataSet::DATASET_TYPE_SINGLE_ITEM) {
				continue;
			}

			$newItemids = [];
			$newTypes = [];
			$newColors = [];

			$hasAny = false;
			foreach ($ds['itemids'] as $index => $itemidEntry) {
				$itemids = json_decode($itemidEntry, true);
				if (!is_array($itemids)) {
					continue;
				}

				$hasAny = true;

				$typeValue = $ds['type'][$index] ?? 0;
				$currentColors = [];
				$currentItemIds = [];

				foreach ($itemids as $itemid) {
					$currentItemIds[] = $itemid['itemid'];
					if (!empty($itemid['color'])) {
						$currentColors[] = strtoupper($itemid['color']);
					}
					else {
						$currentColors[] = null;
					}
				}

				$colors = $this->getColorblindFriendlyPalette(count($currentItemIds));
				$availableColors = $this->reorderColorsAvoidAdjacentSimilarity($colors);

				foreach ($currentItemIds as $i => $itemid) {
					if ($currentColors[$i] !== null) {
						$finalColor = $currentColors[$i];
					}
					else {
						$finalColor = array_shift($availableColors);
					}

					$newItemids[] = $itemid;
					$newTypes[] = $typeValue;
					$newColors[] = $finalColor;
				}
			}

			if ($hasAny) {
				$ds['itemids'] = $newItemids;
				$ds['type'] = $newTypes;
				$ds['color'] = $newColors;
			}
		}

		$metrics = $this->getData($pie_chart_options);

		$svg_data = $this->getSVGData($metrics['sectors'], $metrics['total_value']);

		$data['vars']['sectors'] = $svg_data['svg_sectors'];
		$data['vars']['all_sectorids'] = $metrics['all_sectorids'];
		$data['vars']['total_value'] = $svg_data['svg_total_value'];
		$data['vars']['legend'] = $this->getLegend($metrics['sectors']);
		if ($this->hasInput('with_config')) {
			$data['vars']['config'] = $this->getConfig();
		}

		$this->setResponse(new CControllerResponseData($data));
	}

	private function getColorblindFriendlyPalette(int $count): array {
		$palette = [
			'377EB8', 'FF7F00', '4DAF4A', 'F781BF', 'A65628', '984EA3', '999999', 'E41A1C', 'DEDE00', '17BECF', '8C564B', 'FFCC80'
		];

		while (count($palette) < $count) {
			$palette = array_merge($palette, $palette);
		}

		return array_slice($palette, 0, $count);
	}

	private function reorderColorsAvoidAdjacentSimilarity(array $colors): array {
		if (count($colors) <= 2) {
			return $colors;
		}

		$labColors = array_map(fn($hex) => $this->hexToLab($hex), $colors);

		$ordered = [];
		$usedIndexes = [];

		$ordered[] = $colors[0];
		$usedIndexes[] = 0;

		while (count($ordered) < count($colors)) {
			$lastIndex = end($usedIndexes);
			$lastLab = $labColors[$lastIndex];

			$maxDist = -1;
			$nextIndex = null;

			foreach ($colors as $i => $color) {
				if (in_array($i, $usedIndexes, true)) {
					continue;
				}

				$dist = $this->labDistance($lastLab, $labColors[$i]);
				if ($dist > $maxDist) {
					$maxDist = $dist;
					$nextIndex = $i;
				}
			}

			if ($nextIndex === null) {
				break;
			}

			$ordered[] = $colors[$nextIndex];
			$usedIndexes[] = $nextIndex;
		}

		return $ordered;
	}

	private function hexToLab(string $hex): array {
		$hex = ltrim($hex, '#');
		if (strlen($hex) === 3) {
			$r = hexdec(str_repeat($hex[0], 2));
			$g = hexdec(str_repeat($hex[1], 2));
			$b = hexdec(str_repeat($hex[2], 2));
		}
		else {
			$r = hexdec(substr($hex, 0, 2));
			$g = hexdec(substr($hex, 2, 2));
			$b = hexdec(substr($hex, 4, 2));
		}

		[$r, $g, $b] = array_map(function ($c) {
			$c = $c / 255;
			return ($c > 0.04045) ? pow(($c + 0.055) / 1.055, 2.4) : $c / 12.92;
		}, [$r, $g, $b]);

		$x = $r * 0.4124 + $g * 0.3576 + $b * 0.1805;
		$y = $r * 0.2126 + $g * 0.7152 + $b * 0.0722;
		$z = $r * 0.0193 + $g * 0.1192 + $b * 0.9505;

		$xr = $x / 0.95047;
		$yr = $y / 1.00000;
		$zr = $z / 1.08883;

		$fx = ($xr > 0.008856) ? pow($xr, 1 / 3) : (7.787 * $xr) + (16 / 116);
		$fy = ($yr > 0.008856) ? pow($yr, 1 / 3) : (7.787 * $yr) + (16 / 116);
		$fz = ($zr > 0.008856) ? pow($zr, 1 / 3) : (7.787 * $zr) + (16 / 116);

		$l = (116 * $fy) - 16;
		$a = 500 * ($fx - $fy);
		$b = 200 * ($fy - $fz);

		return [$l, $a, $b];
	}

	private function labDistance(array $lab1, array $lab2): float {
		return sqrt(
			pow($lab1[0] - $lab2[0], 2) +
			pow($lab1[1] - $lab2[1], 2) +
			pow($lab1[2] - $lab2[2], 2)
		);
	}

	private function getData($options): array {
		$metrics = [];
		$total_value = [];
		$all_sectorids = [];

		self::getItems($metrics, $options['data_sets'], $options['templateid'], $options['override_hostid']);
		self::getChartDataSource($metrics, $options['data_source'], $options['time_period']['time_from']);
		self::getMetricsData($metrics, $options['time_period'], $options['legend_aggregation_show'],
			$options['templateid'], $options['override_hostid']);
		self::getSectorsData($metrics, $total_value, $options['merge_sectors'], $options['total_value'],
			$options['units'], $options['templateid'], $options['override_hostid'], $all_sectorids);

		return [
			'sectors' => $metrics,
			'all_sectorids' => $all_sectorids,
			'total_value' => $total_value
		];
	}

	private function makeWidgetInfo(): array {
		$info = [];

		if ($this->hasInput('has_custom_time_period')) {
			$info[] = [
				'icon' => ZBX_ICON_TIME_PERIOD,
				'hint' => relativeDateToText($this->fields_values['time_period']['from'],
					$this->fields_values['time_period']['to']
				)
			];
		}

		return $info;
	}

	private static function getItems(array &$metrics, array $data_sets, string $templateid,
			string $override_hostid): void {
		$metrics = [];
		$max_metrics = SVG_GRAPH_MAX_NUMBER_OF_METRICS;

		foreach ($data_sets as $index => $data_set) {
			if ($max_metrics === 0) {
				break;
			}

			if ($data_set['dataset_type'] == CWidgetFieldDataSet::DATASET_TYPE_SINGLE_ITEM) {
				$ds_metrics = self::getMetricsSingleItemDS($data_set, $max_metrics, $templateid, $override_hostid);
			}
			else {
				$ds_metrics = self::getMetricsPatternItemDS($data_set, $max_metrics, $templateid, $override_hostid);
			}

			foreach ($ds_metrics as $ds_metric) {
				$ds_metric['data_set'] = $index;
				$metrics[] = $ds_metric;
				$max_metrics--;
			}
		}
	}

	private static function getMetricsSingleItemDS(array $data_set, int $max_metrics, string $templateid,
			string $override_hostid): array {
		$metrics = [];
		$ds_items = [];

		if (!$data_set['itemids'] || count($data_set['color']) !== count($data_set['itemids'])
				|| count($data_set['type']) !== count($data_set['itemids'])) {
			return $metrics;
		}

		foreach ($data_set['itemids'] as $key => $item) {
			$ds_items[$item] = [
				'color' => $data_set['color'][$key],
				'type' => $data_set['type'][$key]
			];
		}

		unset($data_set['itemids'], $data_set['color'], $data_set['type']);

		if ($override_hostid !== '') {
			// Host dashboard (view).
			$tmp_items = API::Item()->get([
				'output' => ['itemid', 'key_'],
				'itemids' => array_keys($ds_items),
				'webitems' => true
			]);

			if ($tmp_items) {
				$items = API::Item()->get([
					'output' => ['itemid', 'key_'],
					'hostids' => [$override_hostid],
					'webitems' => true,
					'filter' => [
						'key_' => array_column($tmp_items, 'key_')
					]
				]);

				if (!$items) {
					$ds_items = [];
				}
				else {
					$old_item_keys = array_combine(array_column($tmp_items, 'itemid'), array_column($tmp_items, 'key_'));
					$new_itemids = array_combine(array_column($items, 'key_'), array_column($items, 'itemid'));

					foreach ($ds_items as $key => $item) {
						unset($ds_items[$key]);
						$new_id = $new_itemids[$old_item_keys[$key]];
						$ds_items[$new_id] = $item;
					}
				}
			}
		}

		$resolve_macros = $templateid === '' || $override_hostid !== '';

		$db_items = API::Item()->get([
			'output' => ['itemid', 'hostid', $resolve_macros ? 'name_resolved' : 'name', 'history', 'trends', 'units',
				'value_type'
			],
			'selectHosts' => ['name'],
			'webitems' => true,
			'filter' => [
				'value_type' => [ITEM_VALUE_TYPE_UINT64, ITEM_VALUE_TYPE_FLOAT]
			],
			'itemids' => array_keys($ds_items),
			'preservekeys' => true,
			'limit' => $max_metrics
		]);

		if ($resolve_macros) {
			$db_items = CArrayHelper::renameObjectsKeys($db_items, ['name_resolved' => 'name']);
		}

		foreach ($ds_items as $itemid => $ds_item) {
			if (array_key_exists($itemid, $db_items)) {
				$data_set['color'] = '#' . $ds_item['color'];
				$data_set['type'] = $ds_item['type'];

				$metrics[] = $db_items[$itemid] + ['options' => $data_set];
			}
		}

		return $metrics;
	}

	private static function getMetricsPatternItemDS(array $data_set, int $max_metrics, string $templateid,
			string $override_hostid): array {
		$metrics = [];

		if (($templateid === '' && (!$data_set['hosts'] || !$data_set['items']))
			|| ($templateid !== '' && !$data_set['items'])
		) {
			return $metrics;
		}

		if ($override_hostid === '' && $templateid === '') {
			$hosts = API::Host()->get([
				'output' => [],
				'search' => [
					'name' => self::processPattern($data_set['hosts'])
				],
				'searchWildcardsEnabled' => true,
				'searchByAny' => true,
				'preservekeys' => true
			]);

			$hostids = array_keys($hosts);
		}
		else {
			$hostids = $override_hostid !== '' ? [$override_hostid] : [$templateid];
		}

		if (!$hostids) {
			return $metrics;
		}

		$options = [
			'output' => ['itemid', 'hostid', 'history', 'trends', 'units', 'value_type'],
			'selectHosts' => ['name'],
			'webitems' => true,
			'hostids' => $hostids,
			'filter' => [
				'value_type' => [ITEM_VALUE_TYPE_UINT64, ITEM_VALUE_TYPE_FLOAT]
			],
			'searchWildcardsEnabled' => true,
			'searchByAny' => true,
			'sortfield' => 'name',
			'sortorder' => ZBX_SORT_UP,
			'limit' => $max_metrics
		];

		$resolve_macros = $templateid === '' || $override_hostid !== '';

		if ($resolve_macros) {
			$options['output'][] = 'name_resolved';

			if ($templateid === '') {
				$options['search']['name_resolved'] = self::processPattern($data_set['items']);
			}
			else {
				$options['search']['name'] = self::processPattern($data_set['items']);
			}
		}
		else {
			$options['output'][] = 'name';
			$options['search']['name'] = self::processPattern($data_set['items']);
		}

		$items = API::Item()->get($options);

		if ($resolve_macros) {
			$items = CArrayHelper::renameObjectsKeys($items, ['name_resolved' => 'name']);
		}

		$colors = array_key_exists('color', $data_set)
			? CColorPicker::getColorVariations($data_set['color'], count($items))
			: CColorPicker::getPaletteColors($data_set['color_palette'], count($items));

		unset($data_set['hosts'], $data_set['items'], $data_set['color'], $data_set['color_palette']);

		foreach ($items as $item) {
			$data_set['color'] = array_shift($colors);
			$metrics[] = $item + ['options' => $data_set];
		}

		return $metrics;
	}

	private static function getChartDataSource(array &$metrics, int $data_source, int $time): void {
		if ($data_source == WidgetForm::DATA_SOURCE_AUTO) {
			$metrics = CItemHelper::addDataSource($metrics, $time);
		}
		else {
			foreach ($metrics as &$metric) {
				$metric['source'] = $data_source == WidgetForm::DATA_SOURCE_TRENDS ? 'trends' : 'history';
			}
			unset($metric);
		}
	}

	private static function getMetricsData(array &$metrics, array $time_period, bool $legend_aggregation_show,
			string $templateid, string $override_hostid): void {
		$dataset_metrics = [];
		$add_ds_names = [];

		foreach ($metrics as $metric_num => &$metric) {
			$dataset_num = $metric['data_set'];
			if (!array_key_exists($dataset_num, $add_ds_names)) {
				$agg_ds_names[$dataset_num] = [];
			}

			$resolved = CMacrosResolverHelper::resolveItemBasedWidgetMacros(
				[$metric['itemid'] => $metric + ['label' => $metric['options']['data_set_label']]],
				['label' => 'label']
			);
			$resolved_value = $resolved[$metric['itemid']]['label'];

			if ($metric['options']['dataset_aggregation'] == AGGREGATE_NONE) {
				if ($legend_aggregation_show) {
					$name = CItemHelper::getAggregateFunctionName($metric['options']['aggregate_function']).
						'('.$metric['hosts'][0]['name'].NAME_DELIMITER.$metric['name'].')';
					$ds_func = CItemHelper::getAggregateFunctionName($metric['options']['aggregate_function']);
					if ($metric['options']['data_set_label'] !== '') {
						if ($resolved_value !== null && $resolved_value !== '' && $resolved_value !== '*UNKNOWN*') {
							$name = $ds_func.'('.$resolved_value.')';
						}
						else if ($resolved_value === $metric['options']['data_set_label']) {
							$name = $ds_func.'('.$metric['options']['data_set_label'].')';
						}
						else {
							$name = $ds_func.'('.$metric['hosts'][0]['name'].NAME_DELIMITER.$metric['name'].')';
						}
					}
				}
				else {
					$name = $metric['hosts'][0]['name'].NAME_DELIMITER.$metric['name'];
					if ($metric['options']['data_set_label'] !== '') {
						if ($resolved_value !== null && $resolved_value !== '' && $resolved_value !== '*UNKNOWN*') {
							$name = $resolved_value;
						}
						else if ($resolved_value === $metric['options']['data_set_label']) {
							$name = $metric['options']['data_set_label'];
						}
						else {
							$name = $metric['hosts'][0]['name'].NAME_DELIMITER.$metric['name'];
						}
					}
					else {
						$name = $metric['hosts'][0]['name'].NAME_DELIMITER.$metric['name'];
					}
				}
			}
			else {
				if ($resolved_value !== null && $resolved_value !== '' && $resolved_value !== '*UNKNOWN*') {
					$agg_ds_names[$dataset_num][] = $resolved_value;
				}

				$unique_agg_ds_names = array_unique($agg_ds_names[$dataset_num]);
				if ($unique_agg_ds_names) {
					$name = implode(', ', $unique_agg_ds_names);
				}
				else {
					$name = $metric['options']['data_set_label'] !== ''
						? $metric['options']['data_set_label']
						: _('Data set').' #'.($dataset_num + 1);
				}
			}

			$item = [
				'itemid' => $metric['itemid'],
				'value_type' => $metric['value_type'],
				'source' => $metric['source']
			];

			if (!array_key_exists($dataset_num, $dataset_metrics)) {
				$metric['name'] = $name;
				$metric['items'] = [$item];
				$metric['value'] = null;

				if ($metric['options']['dataset_aggregation'] != AGGREGATE_NONE) {
					$dataset_metrics[$dataset_num] = $metric_num;
				}
			}
			else {
				$metrics[$dataset_metrics[$dataset_num]]['name'] = $name;
				$metrics[$dataset_metrics[$dataset_num]]['items'][] = $item;
				unset($metrics[$metric_num]);
			}
		}
		unset($metric);

		$merged = [];
		$unmerged = [];
		foreach ($metrics as $array) {
			if ($array['options']['itemname_aggregation'] == AGGREGATE_NONE) {
				$unmerged[] = $array;
				continue;
			}

			$key = $array['name'] . '|' . $array['data_set'];

			if (!isset($merged[$key])) {
				$merged[$key] = $array;
			}
			else {
				$merged[$key]['items'] = array_merge($merged[$key]['items'], $array['items']);
			}
		}
		$metrics = $unmerged;
		foreach ($merged as $a) {
			$metrics[] = $a;
		}

		foreach ($metrics as &$metric) {
			if ($templateid !== '' && $override_hostid === '') {
				continue;
			}

			$values = Manager::History()->getAggregatedValues($metric['items'],
				$metric['options']['aggregate_function'], $time_period['time_from'], $time_period['time_to']
			);

			if (!$values) {
				continue;
			}

			$values = array_column($values, 'value');

			$agg = AGGREGATE_NONE;
			if (($temp = $metric['options']['itemname_aggregation']) !== AGGREGATE_NONE) {
				$agg = $temp;
			}

			if (($temp = $metric['options']['dataset_aggregation']) !== AGGREGATE_NONE) {
				$agg = $temp;
			}

			switch($agg) {
				case AGGREGATE_MAX:
					$metric['value'] = max($values);
					break;
				case AGGREGATE_MIN:
					$metric['value'] = min($values);
					break;
				case AGGREGATE_AVG:
					$metric['value'] = array_sum($values) / count($values);
					break;
				case AGGREGATE_COUNT:
					$metric['value'] = count($values);
					break;
				case AGGREGATE_SUM:
					$metric['value'] = array_sum($values);
					break;
				default:
					$metric['value'] = $values[0];
			}
		}
	}

	private static function getSectorsData(array &$metrics, array &$total_value, array $merge_sectors,
			array $total_config, array $units_config, string $templateid, string $override_hostid,
			array &$all_sectorids): void {
		$has_total = false;
		$chart_units = null;
		$raw_total_value = null;
		$others_value = 0;
		$below_threshold_sectors = [];
		$sectors = [];

		if ($templateid !== '' && $override_hostid === '') {
			foreach ($metrics as $metric) {
				$is_total = ($metric['options']['dataset_aggregation'] == AGGREGATE_NONE
					&& $metric['options']['type'] == CWidgetFieldDataSet::ITEM_TYPE_TOTAL);

				$sectors[] = [
					'id' => $metric['data_set'].'_'.$metric['itemid'],
					'name' => $metric['name'],
					'color' => $metric['options']['color'],
					'value' => null,
					'units' => '',
					'is_total' => $is_total
				];

				$all_sectorids[] = $metric['data_set'].'_'.$metric['itemid'];
			}
		}
		else {
			foreach ($metrics as &$metric) {
				$is_total = ($metric['options']['dataset_aggregation'] == AGGREGATE_NONE
					&& $metric['options']['type'] == CWidgetFieldDataSet::ITEM_TYPE_TOTAL);

				if ($is_total) {
					$raw_total_value = $metric['value'] !== null ? abs($metric['value']) : null;
					$has_total = true;
				}
				elseif (!$has_total && $metric['value'] !== null) {
					if ($raw_total_value === null) {
						$raw_total_value = 0;
					}
					$raw_total_value += abs($metric['value']);
				}

				if ($units_config['units_show'] == self::SHOW_UNITS_ON && $units_config['units_value'] !== '') {
					$metric['units'] = $units_config['units_value'];
				}
				elseif (!CAggFunctionData::preservesUnits($metric['options']['aggregate_function'])) {
					$metric['units'] = '';
				}

				if ($chart_units === null || $is_total) {
					$chart_units = $metric['units'];
				}

				$sectors[] = [
					'id' => $metric['data_set'].'_'.$metric['itemid'],
					'name' => $metric['name'],
					'color' => $metric['options']['color'],
					'value' => $metric['value'],
					'units' => $metric['units'],
					'is_total' => $is_total
				];

				$all_sectorids[] = $metric['data_set'].'_'.$metric['itemid'];
			}
			unset($metric);

			if ($merge_sectors['merge'] == self::MERGE_SECTORS_ON) {
				$all_sectorids[] = 'other';
			}

			foreach ($sectors as $key => $sector) {
				if ($sector['value'] == 0 || $raw_total_value == 0) {
					$percentage = 0;
				}
				else {
					$percentage = (abs($sector['value']) / $raw_total_value) * 100;
				}

				if ($merge_sectors['merge'] == self::MERGE_SECTORS_ON
						&& $percentage < $merge_sectors['percent']) {
					$others_value += abs($sector['value'] ?? 0);

					$below_threshold_sectors[] = $key;
				}
			}

			if (count($below_threshold_sectors) >= 2) {
				foreach ($below_threshold_sectors as $sector_key) {
					unset($sectors[$sector_key]);
				}

				$sectors[] = [
					'id' => 'other',
					'name' => _('Other'),
					'color' => $merge_sectors['color'],
					'value' => $others_value,
					'units' => $chart_units,
					'is_total' => false
				];
			}
		}

		foreach ($sectors as &$sector) {
			$formatted_value = convertUnitsRaw([
				'value' => $sector['value'],
				'units' => $sector['units'],
				'zero_as_zero' => false
			]);
			unset($formatted_value['is_numeric']);

			$sector['formatted_value'] = $formatted_value;
		}
		unset($sector);

		$metrics = $sectors;

		$formatted_total_value = convertUnitsRaw([
			'value' => $raw_total_value,
			'units' => $chart_units,
			'power' => $units_config['units_show'] == self::SHOW_UNITS_ON ? null : 0,
			'decimals' => $total_config['decimal_places'],
			'decimals_exact' => true,
			'small_scientific' => false,
			'zero_as_zero' => false
		]);
		unset($formatted_total_value['is_numeric']);

		$total_value['value'] = $raw_total_value;

		if ($raw_total_value !== null) {
			$total_value['formatted_value'] = $formatted_total_value;
		}
	}

	/**
	 * Prepare an array to be used for hosts/items filtering.
	 *
	 * @param array  $patterns  Array of strings containing hosts/items patterns.
	 *
	 * @return array|mixed  Returns array of patterns.
	 *                      Returns NULL if array contains '*' (so any possible host/item search matches).
	 */
	private static function processPattern(array $patterns): ?array {
		return in_array('*', $patterns, true) ? null : $patterns;
	}

	private function getConfig(): array {
		$config = [
			'draw_type' => $this->fields_values['draw_type'],
			'space' => $this->fields_values['space']
		];

		if ($this->fields_values['draw_type'] == WidgetForm::DRAW_TYPE_DOUGHNUT) {
			$config['width'] = $this->fields_values['width'];
			$config['stroke'] = $this->fields_values['stroke'];

			if ($this->fields_values['total_show'] == self::SHOW_TOTAL_ON) {
				$config['total_value'] = [
					'show' => true,
					'is_custom_size' => $this->fields_values['value_size_type'] == WidgetForm::VALUE_SIZE_CUSTOM,
					'is_bold' =>  $this->fields_values['value_bold'] == self::VALUE_BOLD_ON,
					'color' => '#'.$this->fields_values['value_color'],
					'units_show' => $this->fields_values['units_show'] == self::SHOW_UNITS_ON
				];

				if ($this->fields_values['value_size_type'] == WidgetForm::VALUE_SIZE_CUSTOM) {
					$config['total_value']['size'] = $this->fields_values['value_size'];
				}
			}
			else {
				$config['total_value'] = [
					'show' => false
				];
			}
		}

		return $config;
	}

	private function getLegend(array $sectors): array {
		$legend['data'] = [];

		foreach ($sectors as $sector) {
			$legend['data'][] = [
				'id' => $sector['id'],
				'name' => $sector['name'],
				'value' => convertUnits([
					'value' => $sector['value'],
					'units' => $sector['units'],
					'convert' => ITEM_CONVERT_NO_UNITS
				]),
				'color' => $sector['color'],
				'is_total' => $sector['is_total']
			];
		}

		if ($this->fields_values['legend'] == WidgetForm::LEGEND_ON) {
			$legend['show'] = true;
			$legend['value_show'] = $this->fields_values['legend_value'] === self::LEGEND_VALUE_ON;
			$legend['lines_mode'] = $this->fields_values['legend_lines_mode'];
			$legend['lines'] = $this->fields_values['legend_lines'];

			if (!$legend['value_show']) {
				$legend['columns'] = $this->fields_values['legend_columns'];
			}
		}
		else {
			$legend['show'] = false;
		}

		return $legend;
	}

	private function getSVGData(array $sectors, array $total_value): array {
		if ($total_value['value'] === null) {
			return [
				'svg_sectors' => [],
				'svg_total_value' => $total_value
			];
		}

		$sector_total_value = 0;
		$non_total_sectors = [];
		$has_total_item = false;

		$sectors = array_filter($sectors, static function ($sector) {
			return $sector['value'] !== null;
		});

		// Move total sector to the end.
		foreach ($sectors as $key => $sector) {
			if ($sector['is_total']) {
				$has_total_item = true;
				$sectors[] = $sector;
				unset($sectors[$key]);
				break;
			}
		}

		$svg_sectors = array_values($sectors);

		foreach ($svg_sectors as &$sector) {
			if ($total_value['value']) {
				$sector['percent_of_total'] = (abs($sector['value']) / $total_value['value']) * 100;
			}
			else {
				$sector['percent_of_total'] = 0;
			}

			if (!$sector['is_total']) {
				$sector_total_value += abs($sector['value']);
				$non_total_sectors[] = $sector;
			}
		}
		unset($sector);

		if ($has_total_item) {
			if ($sector_total_value === $total_value['value']) {
				// Sectors use the full total value, no remaining space for the total sector.
				array_pop($svg_sectors);
			}
			elseif ($sector_total_value < $total_value['value']) {
				// Sectors use less than total value, the remaining of the total sector will be displayed.
				$svg_sectors[count($svg_sectors) - 1]['percent_of_total'] =
					($total_value['value'] - $sector_total_value) * 100 / $total_value['value'];
			}
			else {
				// Sectors use more than total value.
				$current_value = 0;
				$remaining_value = $total_value['value'];
				$sectors_to_keep = [];

				foreach ($non_total_sectors as &$sector) {
					if (($current_value + abs($sector['value'])) <= $remaining_value) {
						// There is enough space for this sector.
						$sectors_to_keep[] = $sector;
						$current_value += abs($sector['value']);
						$remaining_value -= abs($sector['value']);
					}
					elseif (abs($sector['value']) >= $remaining_value && $current_value < $total_value['value']) {
						// This sector needs to be cut, to fit.
						$sector['percent_of_total'] = ($remaining_value / $total_value['value']) * 100;
						$sectors_to_keep[] = $sector;
						break;
					}
					else {
						// This sector doesn't fit.
						break;
					}
				}
				unset($sector);

				$svg_sectors = $sectors_to_keep;
			}
		}

		foreach($svg_sectors as &$sector) {
			unset($sector['value']);
		}
		unset($sector);

		return [
			'svg_sectors' => $svg_sectors,
			'svg_total_value' => $total_value
		];
	}
}
