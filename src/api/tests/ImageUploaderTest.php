<?php

use MagratheaImages3\Images\ImageUploader;
use MagratheaImages3\Apikey\Apikey;

include_once(__DIR__."/../_inc.php");

class ImageUploaderTest extends \PHPUnit\Framework\TestCase {

	public function testSetFileThrowsOnEmptyArray(): void {
		$this->expectException(\Magrathea2\Exceptions\MagratheaException::class);
		(new ImageUploader())->SetFile([]);
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
		$this->expectException(\Magrathea2\Exceptions\MagratheaApiException::class);
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
		$this->expectException(\Magrathea2\Exceptions\MagratheaApiException::class);
		(new ImageUploader())->ValidateMime("plain text content, not an image at all");
	}

	public function testConvertPHPSizeToBytesHandlesSuffixes(): void {
		$this->assertEquals(100, ImageUploader::convertPHPSizeToBytes("100"));
		$this->assertEquals(512 * 1024, ImageUploader::convertPHPSizeToBytes("512K"));
		$this->assertEquals(2 * 1024 * 1024, ImageUploader::convertPHPSizeToBytes("2M"));
		$this->assertEquals(1024 * 1024 * 1024, ImageUploader::convertPHPSizeToBytes("1G"));
	}

	public function testGetMaximumFileUploadSizeReturnsInt(): void {
		$this->assertIsInt(ImageUploader::getMaximumFileUploadSize());
		$this->assertGreaterThan(0, ImageUploader::getMaximumFileUploadSize());
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

	public function testReturnImageNotUploadedShape(): void {
		$uploader = new ImageUploader();
		$rs = $uploader->returnImageNotUploaded("image-placeholder");
		$this->assertFalse($rs["success"]);
		$this->assertEquals("image was not uploaded", $rs["error"]);
		$this->assertEquals("image-placeholder", $rs["data"]);
	}

	public function testGetDestinationUsesKeyFolderRawPath(): void {
		$mediasPath = rtrim(\Magrathea2\Config::Instance()->Get("medias_path"), "/");
		$key = new Apikey();
		$key->folder = "my-folder";
		$uploader = (new ImageUploader())->SetKey($key);
		$this->assertEquals($mediasPath."/my-folder/raw/", $uploader->GetDestination());
	}

}
