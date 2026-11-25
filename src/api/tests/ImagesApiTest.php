<?php

use PHPUnit\Framework\TestCase;
use MagratheaImages3\Images\ImagesApi;

include_once(__DIR__ . "/../_inc.php");

class ImagesApiTest extends TestCase
{
	private string $originalEnvironment;
	private array $originalGet;

	public function setUp(): void
	{
		$this->originalGet = $_GET;
		\Magrathea\DB\Database::MockClass(\Magrathea\DB\DatabaseSimulate::Instance());
		// The "images" environment (this repo's default) has force_uuid enabled,
		// which isn't the scenario under test here. "production" has it unset,
		// matching the real deployment where the 999999/notarealkey123 request
		// reached `new Images($id)` instead of the force_uuid check.
		$this->originalEnvironment = \Magrathea\Config::Instance()->GetEnvironment();
		\Magrathea\Config::Instance()->SetEnvironment("production");
	}

	public function tearDown(): void
	{
		$_GET = $this->originalGet;
		\Magrathea\Config::Instance()->SetEnvironment($this->originalEnvironment);
	}

	/** [query string, the generated name both sides must agree on] */
	public static function quickAccessCases(): array
	{
		return [
			"no flags"                  => [[], "7_800x600"],
			"stretch"                   => [["stretch" => "1"], "7_800x600-s"],
			"fit"                       => [["fit" => "1"], "7_800x600-f"],
			"fit + placeholder"         => [["fit" => "1", "placeholder" => "1"], "7_800x600-f_placeholder"],
			"fit + stretch: fit wins"   => [["fit" => "1", "stretch" => "1"], "7_800x600-f"],
			"all three"                 => [["fit" => "1", "stretch" => "1", "placeholder" => "1"], "7_800x600-f_placeholder"],
			"stretch + placeholder"     => [["stretch" => "1", "placeholder" => "1"], "7_800x600-s_placeholder"],
			"fit=0 is no fit"           => [["fit" => "0", "stretch" => "1"], "7_800x600-s"],
		];
	}

	/**
	 * The quick-access lookup runs before the image is loaded and builds the file name on its own.
	 * If it differs from the name the file is generated at, quick access never hits and nothing
	 * fails: every request just takes the slow path. So: same id, size and flags → same name.
	 */
	#[\PHPUnit\Framework\Attributes\DataProvider("quickAccessCases")]
	public function testQuickAccessFileNameMatchesTheGeneratedFileName(array $query, string $expected)
	{
		$_GET = $query;
		$image = new \MagratheaImages3\Images\Images();
		$image->id = 7;
		$image->folder = "myfolder";
		if (($query["placeholder"] ?? null) == "1") $image->SetPlaceholder();
		// what ViewImage() hands Size(): the raw flags (GetFileName() applies "fit wins" too)
		$generated = $image->GetFileName("800", "600", ($query["stretch"] ?? null) == "1", ($query["fit"] ?? null) == "1");

		$quick = (new ImagesApi())->QuickAccessFileName(["id" => "7", "public_key" => "abc"], "800x600");
		$this->assertEquals($expected.".webp", $quick);
		$this->assertEquals(basename($generated).".webp", $quick);
	}

	public function testQuickAccessThumbIgnoresFitAndStretch()
	{
		$_GET = ["fit" => "1", "stretch" => "1"];
		$image = new \MagratheaImages3\Images\Images();
		$image->id = 7;
		$image->folder = "myfolder";
		$api = new ImagesApi();
		$params = ["id" => "7", "public_key" => "abc"];
		$this->assertEquals("7_thumb.webp", $api->QuickAccessFileName($params, "thumb"));
		$this->assertEquals(basename($image->GetThumbFile()).".webp", $api->QuickAccessFileName($params, "thumb"));

		$_GET["placeholder"] = "1";
		$image->SetPlaceholder();
		$this->assertEquals("7_thumb_placeholder.webp", $api->QuickAccessFileName($params, "thumb"));
		$this->assertEquals(basename($image->GetThumbFile()).".webp", $api->QuickAccessFileName($params, "thumb"));
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
		} catch (\Magrathea\Exceptions\MagratheaApiException $e) {
			$this->assertEquals(4043, $e->getCode());
		}
	}

	public function testCloneThrowsWhenPrivateKeyMissing()
	{
		$api = new ImagesApi();
		try {
			$api->Clone(["public_key" => "abc123def456", "image_uuid" => "9f8b7c6d-1234-4e56-9abc-1234567890ab"]);
			$this->fail("Expected a MagratheaApiException to be thrown");
		} catch (\Magrathea\Exceptions\MagratheaApiException $e) {
			$this->assertEquals(4005, $e->getCode());
		}
	}

	public function testCloneThrowsWhenPublicKeyMissing()
	{
		$api = new ImagesApi();
		try {
			$api->Clone(["private_key" => "a1b2c3d4e5f6g7h8i9j0k1l2m", "image_uuid" => "9f8b7c6d-1234-4e56-9abc-1234567890ab"]);
			$this->fail("Expected a MagratheaApiException to be thrown");
		} catch (\Magrathea\Exceptions\MagratheaApiException $e) {
			$this->assertEquals(400, $e->getCode());
		}
	}

	public function testCloneThrowsWhenImageUuidMissing()
	{
		$api = new ImagesApi();
		try {
			$api->Clone(["private_key" => "a1b2c3d4e5f6g7h8i9j0k1l2m", "public_key" => "abc123def456"]);
			$this->fail("Expected a MagratheaApiException to be thrown");
		} catch (\Magrathea\Exceptions\MagratheaApiException $e) {
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
		} catch (\Magrathea\Exceptions\MagratheaApiException $e) {
			$this->assertEquals(4042, $e->getCode());
		}
	}
}
