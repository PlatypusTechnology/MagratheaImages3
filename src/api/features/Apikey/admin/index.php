<?php

use Magrathea2\Admin\AdminElements;
use Magrathea2\Admin\AdminManager;

$elements = AdminElements::Instance();
$elements->Header("Api keys");

$feature = AdminManager::Instance()->GetActiveFeature();

?>

<div class="container">

<div class="row">
	<div class="col-12" id="container-keys-list">
	<?
		$feature->List();
	?>
	</div>
</div>
<div class="row">
	<div class="col-12 right">
		<?
		$elements->Button("New Api Key", "newKey()", ["btn-success"]);
		$elements->Button("Cache", "cache()", ["btn-warning"]);
		?>
	</div>
</div>

<div class="row">
	<div class="col-12 mt-4" id="container-keys-rs"></div>
	<div class="col-12" id="container-keys-form"></div>
</div>

</div>
