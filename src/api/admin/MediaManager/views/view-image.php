<?

use Magrathea2\Admin\AdminElements;
use Magrathea2\Admin\AdminForm;

$elements = AdminElements::Instance();

?>

<div class="card">
	<div class="card-header">
		<?=$api?>
		<div class="card-close" aria-label="Close" onclick="closeCard(this);">&times;</div>
	</div>
	<div class="card-body">
		<div class="row">
			<div class="col-12">
				thumb:<br/>
				<img src="<?=$imgApi?>" />
				<hr class="mb-1"/>
			</div>
			<div class="col-6">
			<?
$elements->Button("View Raw", "openPreview(".$id.", 'raw');", ['btn-primary', 'margin-label']);
$elements->Button("View Thumb", "openPreview(".$id.", 'thumb');", ['btn-primary', 'margin-label']);
				?>
			</div>
			<div class="col-6">
			<?
$elements->Input("disabled", "public_key", "public_key", $key);
				?>
			</div>
				<hr class="mt-1 mb-1"/>
				<?
$form = new AdminForm();
$form->Build([
	[
		'key' => "id",
		'type' => "hidden",
		'size' => "col-0",
	],
	[
		'key' => "w",
		'type' => "text",
		'name' => "width:",
		'size' => "col-4",
	],	
	[
		'key' => "h",
		'type' => "text",
		'name' => "height:",
		'size' => "col-4",
	],
	[
		'type' => "button",
		'name' => "preview",
		'size' => "col-4",
		'key' => 'previewSize(this)',
		'class' => ['btn-primary', 'w-100'],
	]
], [ 'id' => $id, 'w' => 200, 'h' => 300 ]);
$form->Print();

				?>
			</div>
			<div class="col-12 mt-2">
				<pre><? echo $image; ?></pre>
			</div>
		</div>
	</div>
</div>
