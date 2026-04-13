<?php

use Magrathea2\Admin\AdminElements;
use Magrathea2\Config;

$elements = AdminElements::Instance();
$elements->Header("Swagger");

$swaggerUrl = Config::Instance()->Get("app_url") . "/swagger.php";

?>

<link rel="stylesheet" href="https://unpkg.com/swagger-ui-dist@5/swagger-ui.css" />

<div id="swagger-ui"></div>

<script src="https://unpkg.com/swagger-ui-dist@5/swagger-ui-bundle.js"></script>
<script>
	SwaggerUIBundle({
		url: <?= json_encode($swaggerUrl) ?>,
		dom_id: "#swagger-ui",
		presets: [SwaggerUIBundle.presets.apis, SwaggerUIBundle.SwaggerUIStandalonePreset],
		layout: "BaseLayout",
	});
</script>
