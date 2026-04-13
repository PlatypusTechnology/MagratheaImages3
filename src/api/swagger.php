<?php
$swaggerFile = realpath(__DIR__."/../swagger.yaml");
if (!$swaggerFile || !file_exists($swaggerFile)) {
	http_response_code(404);
	die("swagger.yaml not found");
}
header("Content-Type: application/yaml");
header("Access-Control-Allow-Origin: *");
readfile($swaggerFile);
