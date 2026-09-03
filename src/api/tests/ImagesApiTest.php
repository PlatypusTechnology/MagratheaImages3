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

	public function testCloneThrowsWhenPrivateKeyMissing()
	{
		$api = new ImagesApi();
		try {
			$api->Clone(["public_key" => "abc123def456", "image_uuid" => "9f8b7c6d-1234-4e56-9abc-1234567890ab"]);
			$this->fail("Expected a MagratheaApiException to be thrown");
		} catch (\Magrathea2\Exceptions\MagratheaApiException $e) {
			$this->assertEquals(4005, $e->getCode());
		}
	}

	public function testCloneThrowsWhenPublicKeyMissing()
	{
		$api = new ImagesApi();
		try {
			$api->Clone(["private_key" => "a1b2c3d4e5f6g7h8i9j0k1l2m", "image_uuid" => "9f8b7c6d-1234-4e56-9abc-1234567890ab"]);
			$this->fail("Expected a MagratheaApiException to be thrown");
		} catch (\Magrathea2\Exceptions\MagratheaApiException $e) {
			$this->assertEquals(400, $e->getCode());
		}
	}

	public function testCloneThrowsWhenImageUuidMissing()
	{
		$api = new ImagesApi();
		try {
			$api->Clone(["private_key" => "a1b2c3d4e5f6g7h8i9j0k1l2m", "public_key" => "abc123def456"]);
			$this->fail("Expected a MagratheaApiException to be thrown");
		} catch (\Magrathea2\Exceptions\MagratheaApiException $e) {
			$this->assertEquals(400, $e->getCode());
		}
	}

	public function testCloneDelegatesToServiceAndSurfacesNotFound()
	{
		// Under DatabaseSimulate every query returns an empty result, so
		// ApikeyControl::GetByKey() never resolves the destination key --
		// the same 4042 a real caller would see for an unknown private key.
		// This exercises the wiring from ImagesApi::Clone() through to
		// ImagesControl::CloneImage() rather than CloneImage() in isolation.
		$api = new ImagesApi();
		try {
			$api->Clone([
				"private_key" => "a1b2c3d4e5f6g7h8i9j0k1l2m",
				"public_key" => "abc123def456",
				"image_uuid" => "9f8b7c6d-1234-4e56-9abc-1234567890ab",
			]);
			$this->fail("Expected a MagratheaApiException to be thrown");
		} catch (\Magrathea2\Exceptions\MagratheaApiException $e) {
			$this->assertEquals(4042, $e->getCode());
		}
	}
}
