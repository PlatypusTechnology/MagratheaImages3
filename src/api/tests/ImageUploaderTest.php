<?php

use MagratheaImages3\Images\ImageUploader;
use MagratheaImages3\Apikey\Apikey;

include_once(__DIR__."/../_inc.php");

class ImageUploaderTest extends \PHPUnit\Framework\TestCase {

	private ?array $originalConfig = null;
	private ?string $tmpMedias = null;
	private array $tmpFiles = [];
	private array $originalFiles = [];

	protected function setUp(): void {
		$this->originalFiles = $_FILES;
		parent::setUp();
	}

	protected function tearDown(): void {
		$_FILES = $this->originalFiles;
		if ($this->originalConfig !== null) \Magrathea\Config::Instance()->SetConfig($this->originalConfig);
		$this->originalConfig = null;
		foreach ($this->tmpFiles as $file) @unlink($file);
		$this->tmpFiles = [];
		if ($this->tmpMedias) exec("rm -rf ".escapeshellarg($this->tmpMedias));
		$this->tmpMedias = null;
		parent::tearDown();
	}

	/** Points medias_path at a fresh temp folder: the configured one is the container's /var/www/medias. */
	private function useTempMediasPath(): void {
		$config = \Magrathea\Config::Instance();
		$this->originalConfig = $config->GetConfig();
		$this->tmpMedias = sys_get_temp_dir()."/magrathea-images-upload-".uniqid();
		mkdir($this->tmpMedias);
		$patched = $this->originalConfig;
		$patched[$config->GetEnvironment()]["medias_path"] = $this->tmpMedias;
		$config->SetConfig($patched);
	}

	/** A file upload as PHP hands it over in $_FILES["file"], also set there (Upload() reads $_FILES). */
	private function uploadedFile(string $name): array {
		$tmp = tempnam(sys_get_temp_dir(), "upl");
		file_put_contents($tmp, "not really an image");
		$this->tmpFiles[] = $tmp;
		$file = ["name" => $name, "type" => "image/jpeg", "size" => 19, "tmp_name" => $tmp, "error" => UPLOAD_ERR_OK];
		$_FILES = ["file" => $file];
		return $file;
	}

	private function uploadKey(): Apikey {
		$key = new Apikey();
		$key->id = 5;
		$key->folder = "upload-test";
		$key->public_key = "pub123";
		return $key;
	}

	private function assertApiError(int $code, callable $call): void {
		try {
			$rs = $call();
			$this->fail("Expected a MagratheaApiException with code {$code}, got a return value: ".json_encode($rs));
		} catch (\Magrathea\Exceptions\MagratheaApiException $e) {
			$this->assertEquals($code, $e->getCode(), $e->getMessage());
		}
	}

	/**
	 * Before 3.7.0, Upload() caught its own errors and returned them as an array, which the API
	 * sent as HTTP 200 with `success: true` and the failure nested in `data`. They must reach the
	 * router as exceptions, so the response carries the error's real HTTP status.
	 */
	public function testUploadThrowsOnAnInvalidExtension(): void {
		\Magrathea\DB\Database::MockClass(\Magrathea\DB\DatabaseSimulate::Instance());
		$this->useTempMediasPath();
		$uploader = (new ImageUploader())->SetKey($this->uploadKey())->SetFile($this->uploadedFile("evil.exe"));
		$this->assertApiError(4151, fn() => $uploader->Upload());
	}

	public function testUploadThrowsWhenTheFileIsNotSaved(): void {
		\Magrathea\DB\Database::MockClass(\Magrathea\DB\DatabaseSimulate::Instance());
		$this->useTempMediasPath();
		// under the CLI, move_uploaded_file() refuses any file that didn't come through an HTTP POST,
		// so the upload never lands in raw/: the same outcome as an unwritable folder or a full disk
		$uploader = (new ImageUploader())->SetKey($this->uploadKey())->SetFile($this->uploadedFile("photo.jpg"));
		$this->assertApiError(5007, fn() => $uploader->Upload());
		$this->assertSame([], glob($this->tmpMedias."/upload-test/raw/*"));
	}

	public function testUploadWithKeyPassesTheErrorToTheRouter(): void {
		\Magrathea\DB\Database::MockClass(\Magrathea\DB\DatabaseSimulate::Instance());
		$this->useTempMediasPath();
		$this->uploadedFile("evil.exe");
		$this->assertApiError(4151, fn() => (new \MagratheaImages3\Images\ImagesApi())->UploadWithKey($this->uploadKey()));
	}

	/**
	 * max_upload_size is enforced by PHP only when PHP's own limits are lower. Before 3.7.0 a file
	 * over max_upload_size but under upload_max_filesize was accepted (URL uploads checked it).
	 */
	public function testSetFileThrowsWhenTheFileIsOverMaxUploadSize(): void {
		\Magrathea\ConfigApp::Instance()->Mock(["max_upload_size" => "1K"]);
		$file = ["name" => "big.jpg", "type" => "image/jpeg", "size" => 1025, "tmp_name" => "/nowhere", "error" => UPLOAD_ERR_OK];
		$this->assertApiError(4002, fn() => (new ImageUploader())->SetFile($file));
	}

	public function testSetFileAcceptsAFileOfExactlyMaxUploadSize(): void {
		\Magrathea\ConfigApp::Instance()->Mock(["max_upload_size" => "1K"]);
		$file = ["name" => "ok.jpg", "type" => "image/jpeg", "size" => 1024, "tmp_name" => "/nowhere", "error" => UPLOAD_ERR_OK];
		$this->assertEquals($file, (new ImageUploader())->SetFile($file)->file);
	}

	/**
	 * A failure after the raw file is written (here: a PNG without readable dimensions, 4153)
	 * must not leave the file in raw/: no row references it, so nothing would ever delete it.
	 * Tested through UploadUrl(): under the CLI, Upload()'s move_uploaded_file() never succeeds.
	 */
	public function testUploadUrlRemovesTheRawFileWhenALaterStepFails(): void {
		\Magrathea\DB\Database::MockClass(\Magrathea\DB\DatabaseSimulate::Instance());
		\Magrathea\ConfigApp::Instance()->Mock([]);
		$this->useTempMediasPath();
		$source = tempnam(sys_get_temp_dir(), "png");
		file_put_contents($source, "\x89PNG\r\n\x1a\n\x00\x00\x00\x0dIHDR"); // finfo: image/png; getimagesize(): false
		$this->tmpFiles[] = $source;
		$uploader = (new ImageUploader())->SetKey($this->uploadKey());
		$this->assertApiError(4153, fn() => $uploader->UploadUrl("file://".$source));
		$this->assertSame([], glob($this->tmpMedias."/upload-test/raw/*"));
	}

	public function testSetFileThrowsOnEmptyArray(): void {
		$this->expectException(\Magrathea\Exceptions\MagratheaException::class);
		(new ImageUploader())->SetFile([]);
	}

	public function testSetFileThrowsOnIniSizeError(): void {
		$this->expectException(\Magrathea\Exceptions\MagratheaException::class);
		(new ImageUploader())->SetFile(["name" => "a.jpg", "size" => 0, "tmp_name" => "", "error" => UPLOAD_ERR_INI_SIZE]);
	}

	public function testSetFileThrowsOnFormSizeError(): void {
		$this->expectException(\Magrathea\Exceptions\MagratheaException::class);
		(new ImageUploader())->SetFile(["name" => "a.jpg", "size" => 0, "tmp_name" => "", "error" => UPLOAD_ERR_FORM_SIZE]);
	}

	public function testSetFileStoresFile(): void {
		$file = ["name" => "a.jpg", "size" => 10, "type" => "image/jpeg"];
		$uploader = (new ImageUploader())->SetFile($file);
		$this->assertEquals($file, $uploader->file);
	}

	public function testValidateExtensionAcceptsAllowedExtensions(): void {
		$uploader = new ImageUploader();
		foreach (["jpg", "jpeg", "png", "bmp", "webp", "wbmp", "svg"] as $ext) {
			$this->assertTrue($uploader->ValidateExtension($ext));
		}
	}

	public function testValidateExtensionRejectsDisallowedExtension(): void {
		$this->expectException(\Magrathea\Exceptions\MagratheaApiException::class);
		(new ImageUploader())->ValidateExtension("exe");
	}

	public function testValidateUploadFalseWithoutKey(): void {
		$uploader = new ImageUploader();
		$uploader->SetFile(["name" => "a.jpg", "size" => 1, "type" => "image/jpeg"]);
		$this->assertFalse($uploader->ValidateUpload());
	}

	public function testValidateUploadFalseWithoutFile(): void {
		$uploader = new ImageUploader();
		$uploader->SetKey(new Apikey());
		$this->assertFalse($uploader->ValidateUpload());
	}

	public function testValidateUploadTrueWithKeyAndFile(): void {
		$uploader = new ImageUploader();
		$uploader->SetKey(new Apikey());
		$uploader->SetFile(["name" => "a.jpg", "size" => 1, "type" => "image/jpeg"]);
		$this->assertTrue($uploader->ValidateUpload());
	}

	public function testValidateMimeAcceptsKnownImageType(): void {
		$png = base64_decode("iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=");
		$this->assertEquals("png", (new ImageUploader())->ValidateMime($png));
	}

	public function testValidateMimeRejectsUnknownType(): void {
		$this->expectException(\Magrathea\Exceptions\MagratheaApiException::class);
		(new ImageUploader())->ValidateMime("plain text content, not an image at all");
	}

	public function testConvertPHPSizeToBytesHandlesSuffixes(): void {
		$this->assertEquals(100, ImageUploader::convertPHPSizeToBytes("100"));
		$this->assertEquals(512 * 1024, ImageUploader::convertPHPSizeToBytes("512K"));
		$this->assertEquals(2 * 1024 * 1024, ImageUploader::convertPHPSizeToBytes("2M"));
		$this->assertEquals(1024 * 1024 * 1024, ImageUploader::convertPHPSizeToBytes("1G"));
	}

	public function testGetMaximumFileUploadSizeReturnsInt(): void {
		\Magrathea\ConfigApp::Instance()->Mock([]);
		$this->assertIsInt(ImageUploader::getMaximumFileUploadSize());
		$this->assertGreaterThan(0, ImageUploader::getMaximumFileUploadSize());
	}

	public function testGetMaximumFileUploadSizeUsesValidOverrideWhenSmaller(): void {
		\Magrathea\ConfigApp::Instance()->Mock(["max_upload_size" => "1K"]);
		$this->assertEquals(1024, ImageUploader::getMaximumFileUploadSize());
	}

	public function testGetMaximumFileUploadSizeIgnoresOverrideLargerThanPhpLimit(): void {
		\Magrathea\ConfigApp::Instance()->Mock([]);
		$phpLimit = ImageUploader::getMaximumFileUploadSize();
		\Magrathea\ConfigApp::Instance()->Mock(["max_upload_size" => (string)($phpLimit + 1024)]);
		$this->assertEquals($phpLimit, ImageUploader::getMaximumFileUploadSize());
	}

	public function testGetMaximumFileUploadSizeFallsBackToPhpLimitWhenOverrideInvalid(): void {
		\Magrathea\ConfigApp::Instance()->Mock([]);
		$phpLimit = ImageUploader::getMaximumFileUploadSize();
		\Magrathea\ConfigApp::Instance()->Mock(["max_upload_size" => "not-a-size"]);
		$this->assertEquals($phpLimit, ImageUploader::getMaximumFileUploadSize());
	}

	public function testGetMaximumFileUploadSizeFallsBackToPhpLimitWhenOverrideUnset(): void {
		\Magrathea\ConfigApp::Instance()->Mock([]);
		$phpLimit = ImageUploader::getMaximumFileUploadSize();
		$this->assertEquals($phpLimit, ImageUploader::getMaximumFileUploadSize());
	}

	public function testIsValidSizeFormatAcceptsPhpIniStyleValues(): void {
		foreach (["100", "10K", "5M", "2G", "1T", "3P", "5m", "10k"] as $value) {
			$this->assertTrue(ImageUploader::IsValidSizeFormat($value), "expected [{$value}] to be valid");
		}
	}

	public function testIsValidSizeFormatRejectsMalformedValues(): void {
		foreach (["", "abc", "5MB", "-5M", "5.5M", "M5", "5 M"] as $value) {
			$this->assertFalse(ImageUploader::IsValidSizeFormat($value), "expected [{$value}] to be invalid");
		}
	}

	public function testCreateImageUsesKeyFolderAndId(): void {
		$key = new Apikey();
		$key->id = 5;
		$key->folder = "my-folder";
		$uploader = (new ImageUploader())->SetKey($key);
		$image = $uploader->CreateImage();
		$this->assertEquals("my-folder", $image->folder);
		$this->assertEquals(5, $image->upload_key);
		$this->assertNull($image->subfolder);
	}

	public function testCreateImageSetsSubfolderWhenGiven(): void {
		$key = new Apikey();
		$key->id = 5;
		$key->folder = "my-folder";
		$uploader = (new ImageUploader())->SetKey($key)->SetSubfolder("thumbs");
		$image = $uploader->CreateImage();
		$this->assertEquals("thumbs", $image->subfolder);
	}

	public function testGetImageReturnIncludesPublicKeyWhenKeySet(): void {
		$key = new Apikey();
		$key->public_key = "pub123";
		$uploader = (new ImageUploader())->SetKey($key);
		$rs = $uploader->getImageReturn("image-placeholder");
		$this->assertEquals("image-placeholder", $rs["image"]);
		$this->assertEquals("pub123", $rs["public_key"]);
	}

	public function testGetImageReturnOmitsPublicKeyWithoutKey(): void {
		$uploader = new ImageUploader();
		$rs = $uploader->getImageReturn("image-placeholder");
		$this->assertArrayNotHasKey("public_key", $rs);
	}

	public function testGetExternalContentReturnsContentWithinLimit(): void {
		\Magrathea\ConfigApp::Instance()->Mock(["max_upload_size" => "1M"]);
		$tmp = tempnam(sys_get_temp_dir(), "img");
		file_put_contents($tmp, str_repeat("a", 100));
		$content = (new ImageUploader())->GetExternalContent("file://".$tmp);
		unlink($tmp);
		$this->assertEquals(str_repeat("a", 100), $content);
	}

	public function testGetExternalContentThrowsWhenContentExceedsLimit(): void {
		\Magrathea\ConfigApp::Instance()->Mock(["max_upload_size" => "10"]);
		$tmp = tempnam(sys_get_temp_dir(), "img");
		file_put_contents($tmp, str_repeat("a", 100));
		$this->expectException(\Magrathea\Exceptions\MagratheaApiException::class);
		try {
			(new ImageUploader())->GetExternalContent("file://".$tmp);
		} finally {
			unlink($tmp);
		}
	}

	public function testGetDestinationUsesKeyFolderRawPath(): void {
		$mediasPath = rtrim(\Magrathea\Config::Instance()->Get("medias_path"), "/");
		$key = new Apikey();
		$key->folder = "my-folder";
		$uploader = (new ImageUploader())->SetKey($key);
		$this->assertEquals($mediasPath."/my-folder/raw/", $uploader->GetDestination());
	}

}
