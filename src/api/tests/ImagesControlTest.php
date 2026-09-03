<?php

use PHPUnit\Framework\TestCase;
use MagratheaImages3\Images\ImagesControl;

include_once(__DIR__ . "/../_inc.php");

class ImagesControlTest extends TestCase
{
	public function setUp(): void
	{
		// Mock the database to use DatabaseSimulate.
		// Using MockClass() directly (rather than Instance()->Mock()) keeps this
		// idempotent: once Database::Instance() has been swapped for a
		// DatabaseSimulate by any test in the suite, calling ->Mock() on it again
		// would fail since DatabaseSimulate has no Mock() method.
		\Magrathea2\DB\Database::MockClass(\Magrathea2\DB\DatabaseSimulate::Instance());
	}

	public function testGetLastReturnsArray()
	{
		$control = new ImagesControl();
		$result = $control->GetLast('testkey', 0, 1);
		$this->assertIsArray($result);
	}

	public function testRemoveThrowsWhenImageCannotBeLoaded()
	{
		// Remove() first resolves the api key, then loads `new Images($id)`.
		// Under the mocked DatabaseSimulate every query returns an empty
		// result, so GetById() always fails to find a row -- this is the
		// exception any caller actually observes in that situation.
		$this->expectException(\Magrathea2\Exceptions\MagratheaModelException::class);
		$control = new ImagesControl();
		$control->Remove('privateKey', 1);
	}

	public function testCloneImageThrowsWhenPrivateKeyNotFound()
	{
		// Under the mocked DatabaseSimulate, ApikeyControl::GetByKey() always
		// resolves to nothing, so CloneImage() reports the same 4042 a real
		// caller would see for an unknown destination private key.
		$this->expectException(\Magrathea2\Exceptions\MagratheaApiException::class);
		try {
			$control = new ImagesControl();
			$control->CloneImage('privateKey', 'publicKey', '9f8b7c6d-1234-4e56-9abc-1234567890ab');
		} catch (\Magrathea2\Exceptions\MagratheaApiException $e) {
			$this->assertEquals(4042, $e->getCode());
			throw $e;
		}
	}

	// Add more tests for RemoveImage and RemoveRawFile as needed
}
