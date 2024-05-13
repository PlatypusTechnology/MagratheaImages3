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
		"key" => function($i) {
			return mb_strimwidth($i->name, 0, 10, "...");
		}
	],
	[
		"title" => "extension",
		"key" => "extension"
	],
	// [
	// 	"title" => "filename",
	// 	"key" => "filename"
	// ],
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
	<div class="col-6">
		<div class="card">
			<div class="card-header">
				Medias [<?=$apikey->public_key?>]
			</div>
			<div class="card-body medias-table">
				<?
				$elements->Table($images, $tableData);
				?>
			</div>
		</div>
	</div>
	<div class="col-6" id="media-image-viewer"></div>
</div>