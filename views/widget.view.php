<?php declare(strict_types = 0);


/**
 * Pie chart widget view.
 *
 * @var CView $this
 * @var array $data
 */

$view = new CWidgetView($data);

if ($data['info'] !== null) {
	$view->setVar('info', $data['info']);
}

foreach ($data['vars'] as $name => $value) {
	if ($value !== null) {
		$view->setVar($name, $value);
	}
}

$view->show();
