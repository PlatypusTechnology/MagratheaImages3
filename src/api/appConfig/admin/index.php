<?php

use Magrathea2\Admin\AdminElements;
use Magrathea2\Admin\Models\AdminConfigControl;
use MagratheaImages3\Configs\ImagesConfigs;

$elements = AdminElements::Instance();
$elements->Header("Api keys");

$control = new AdminConfigControl();
$thumb = $control->GetValue("thumb_size");

$configRows = [
	[
		"name" => "App Name",
		"key" => "app_name",	
	],
	[
		"name" => "Medias Path",
		"key" => "medias_path",	
	],
	[
		"name" => "Thumb Size",
		"key" => "thumb_size",	
	],
];
$configCols = [
	[
		"title" => "Name",
		"key" => "name",
	],
	[
		"title" => "Value",
		"key" => function($i) use ($control) {
			$key = $i['key'];
			$value = $control->GetValue($key);
			return "getting ".$key.": ".$value;
		}
	]
];

?>

<div class="container">

<div class="row">
	<div class="col-12" id="container-keys-list">
	<?
	echo "thumb size:";
	echo ImagesConfigs::Instance()->GetThumbSize();
	AdminElements::Instance()->Table($configRows, $configCols);
	?>
	</div>
</div>

<div class="row">
	<div class="col-12 mt-4" id="container-keys-rs"></div>
	<div class="col-12" id="container-keys-form"></div>
</div>

</div>
