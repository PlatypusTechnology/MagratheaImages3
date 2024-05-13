<?php

use Magrathea2\Admin\AdminElements;
use MagratheaImages3\Apikey\CacheClassCreator;

$cacheControl = new CacheClassCreator();
$file = $cacheControl->GetFile();

?>

<div class="card">
	<div class="card-header">
		Cache
		<div class="card-close" aria-label="Close" onclick="closeCard(this);">&times;</div>
	</div>
	<div class="card-body">
		<div class="row">
			<div class="col-sm-12">Location: <?=$file?></div>
		</div>
		<div class="row mt-2">
			<div class="col-sm-12">
				<?php
				if(!file_exists($file)) {
					AdminElements::Instance()->Alert("Cache file does not exist", "danger");
				} else {
					$data = file_get_contents($file);
					echo "<textarea class='code'>".$data."</textarea>";
				}
				?>
			</div>
		</div>
		<div class="row mt-2">
			<div class="col-sm-12 right">
				<?
				AdminElements::Instance()->Button("Generate!", "createCache()", ["btn-success"]);
				?>
			</div>
		</div>
	</div>
</div>
