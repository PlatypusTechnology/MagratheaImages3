<?php

use PHPUnit\Framework\TestCase;
use MagratheaImages3\Images\ImagesApi;

include_once(__DIR__ . "/../_inc.php");

class ImagesApiTest extends TestCase
{
	private string $originalEnvironment;

	public function setUp(): void
	{
		\Magrathea2\DB\Database::MockClass(\Magrathea2\DB\DatabaseSimulate::Instance());
		// The "images" environment (this repo's default) has force_uuid enabled,
		// which isn't the scenario under test here. "production" has it unset,
		// matching the real deployment where the 999999/notarealkey123 request
		// reached `new Images($id)` instead of the force_uuid check.
		$this->originalEnvironment = \Magrathea2\Config::Instance()->GetEnvironment();
		\Magrathea2\Config::Instance()->SetEnvironment("production");
	}

	public function tearDown(): void
	{
		\Magrathea2\Config::Instance()->SetEnvironment($this->originalEnvironment);
	}

	public function testGetByIdThrowsNotFoundForMissingNumericId()
	{
		// Under DatabaseSimulate every query returns an empty result, so
		// `new Images($id)` fails to find a row and throws MagratheaModelException.
		// ResolveImage() must absorb that into a null return so GetById() reports
		// the intended 4043 "Image not found" instead of misclassifying it as the
		// generic 5001 "Error on Internal API Request".
		$api = new ImagesApi();
		try {
			$api->GetById(["id" => "999999", "public_key" => "notarealkey123"]);
			$this->fail("Expected a MagratheaApiException to be thrown");
		} catch (\Magrathea2\Exceptions\MagratheaApiException $e) {
			$this->assertEquals(4043, $e->getCode());
		}
	}
}
