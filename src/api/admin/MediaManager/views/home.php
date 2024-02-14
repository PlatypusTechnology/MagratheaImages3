<?php

use Magrathea2\Admin\AdminElements;

$elements = AdminElements::Instance();

$tableData = [
	[
		"title" => "#ID",
		"key" => "id"
	],
	[
		"title" => "name",
		"key" => "name"
	],
	[
		"title" => "extension",
		"key" => "extension"
	],
	[
		"title" => "filename",
		"key" => "extension"
	],
	[
		"title" => "dimensions",
		"key" => function($i) {
			return $i->width."_x_".$i->height;
		}
	],
	[
		"title" => "size",
		"key" => function($i) { return $i->GetSize(); },
	],
	[
		"title" => "...",
		"key" => function($i) {
			$id = $i->id;
			$actions = "";
			$actions .= "<a onclick='viewMedia(".$id.");' class='action'>view</a>";
			return $actions;
		}
	]
];

?>

<div class="row">
	<div class="col-12">
		<div class="card">
			<div class="card-header">Medias</div>
			<div class="card-body">
				<?
				$elements->Table($images, $tableData);
				?>
			</div>
		</div>
	</div>
	<div class="col-12" id="media-image-viewer"></div>
</div>