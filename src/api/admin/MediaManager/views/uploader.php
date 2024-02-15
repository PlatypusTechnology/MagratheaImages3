<div class="card">
	<div class="card-header">
		Upload to
		<?= $api ?>
	</div>
	<div class="card-body">
		<div class="row">
			<div class="col-8">
				<form action="<?= $uploadApi ?>" class="dropzone mt-2" id="dropzone-upload"></form>
				<?
	use Magrathea2\Config;
	use Magrathea2\Admin\AdminForm;

	$url = Config::Instance()->Get("app_url");
	$form = new AdminForm();
	$form->Build([
		[
			"id" => "endpoint",
			"type" => "hidden",
		],
		[
			"type" => "text",
			"name" => "By Url:",
			"key" => "image_url",
			"size" => "col-9",
		],
		[
			"type" => "button",
			"name" => "Upload Url",
			"key" => "uploadUrl(this);",
			"size" => "col-3",
			"class" => ["btn-primary", "w-100"],
		]
	],
	[
		"endpoint" => $url.$api,
	]);
	$form->Print();
				?>
			</div>
			<div class="col-4">
				<pre id="upload-response" class="code-light"></pre>
			</div>
		</div>
	</div>
</div>