<?php

use Magrathea2\Admin\AdminElements;
use Magrathea2\Admin\AdminManager;
use MagratheaContacts\Apikey\Apikey;

$elements = AdminElements::Instance();

?>

<div class="card">
	<div class="card-header">
		Api Keys
	</div>
	<div class="card-body">
	<?
	$elements->Table($list, [
		[
			"title" => "#ID",
			"key" => "id"
		],
		[
			"title" => "Key",
			"key" => "val"
		],
		[
			"title" => "Folder",
			"key" => "folder"
		],
		[
			"title" => "Uses",
			"key" => "uses"
		],
		[
			"title" => "Limit",
			"key" => "usage_limit"
		],
	]);
	?>
	</div>
</div>
