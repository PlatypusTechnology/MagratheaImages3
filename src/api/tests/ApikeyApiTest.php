<?php

use PHPUnit\Framework\TestCase;
use MagratheaImages3\Apikey\ApikeyApi;

include_once(__DIR__ . "/../_inc.php");

class ApikeyApiTest extends TestCase
{
	public function setUp(): void
	{
		\Magrathea2\DB\Database::MockClass(\Magrathea2\DB\DatabaseSimulate::Instance());
	}

	public function tearDown(): void
	{
		unset($_GET["public_key"]);
	}

	public function testDeleteThrowsWhenPrivateKeyMissing()
	{
		$api = new ApikeyApi();
		try {
			$api->Delete(["private_key" => ""]);
			$this->fail("Expected a MagratheaApiException to be thrown");
		} catch (\Magrathea2\Exceptions\MagratheaApiException $e) {
			$this->assertEquals(4005, $e->getCode());
		}
	}

	public function testDeleteThrowsWhenPrivateKeyNotFound()
	{
		// Under DatabaseSimulate every query returns an empty result, so
		// ApikeyApi::_GetKey() never resolves the private key -- the same 4042
		// a real caller sees for an unknown or already-deleted key.
		$_GET["public_key"] = "abc123def456";
		$api = new ApikeyApi();
		try {
			$api->Delete(["private_key" => "a1b2c3d4e5f6g7h8i9j0k1l2m"]);
			$this->fail("Expected a MagratheaApiException to be thrown");
		} catch (\Magrathea2\Exceptions\MagratheaApiException $e) {
			$this->assertEquals(4042, $e->getCode());
		}
	}

	public function testDeleteThrowsSame4042WhenPublicKeyMissing()
	{
		// _GetKey() fails to resolve the private key under the mock before the
		// public_key check is ever reached -- both "key not found" and "public_key
		// missing/wrong" surface as the same 4042 by design (see plan-delete-image.md),
		// so this and the test above are indistinguishable at this layer without a
		// real database. Kept as its own test to document that public_key is not
		// silently ignored -- it's still read from $_GET without error either way.
		unset($_GET["public_key"]);
		$api = new ApikeyApi();
		try {
			$api->Delete(["private_key" => "a1b2c3d4e5f6g7h8i9j0k1l2m"]);
			$this->fail("Expected a MagratheaApiException to be thrown");
		} catch (\Magrathea2\Exceptions\MagratheaApiException $e) {
			$this->assertEquals(4042, $e->getCode());
		}
	}
}
