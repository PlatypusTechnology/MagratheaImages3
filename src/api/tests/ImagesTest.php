<?php

use MagratheaImages3\Images\Images;

include_once(__DIR__."/../_inc.php");

class ImagesTest extends \PHPUnit\Framework\TestCase {

	protected function setUp(): void {
		// FromUploadFile()/FromUrl() call SetFilename(), which reads the next
		// auto-increment id from the DB via GetNextID() — needs a mocked DB.
		\Magrathea2\DB\Database::MockClass(\Magrathea2\DB\DatabaseSimulate::Instance());
		parent::setUp();
	}

	public function testFromUploadFileParsesNameAndExtension(): void {
		$img = new Images();
		$img->FromUploadFile(["name" => "My Photo.jpg", "size" => 12345, "type" => "image/jpeg"]);
		$this->assertEquals("jpg", $img->extension);
		$this->assertEquals("My_Photo", $img->name);
		$this->assertEquals(12345, $img->size);
		$this->assertEquals("image/jpeg", $img->file_type);
		$this->assertStringEndsWith("_My_Photo.jpg", $img->filename);
	}

	public function testFromUploadFileReplacesSpacesInFilenameNotJustName(): void {
		$img = new Images();
		$img->FromUploadFile(["name" => "a b c.png", "size" => 1, "type" => "image/png"]);
		$this->assertStringNotContainsString(" ", $img->filename);
	}

	public function testFromUrlDetectsExtensionFromUrl(): void {
		$img = new Images();
		$img->FromUrl("https://example.com/path/My Pic.png");
		$this->assertEquals("png", $img->extension);
		$this->assertEquals("My_Pic", $img->name);
		$this->assertStringEndsWith("_My_Pic.png", $img->filename);
	}

	public function testFromUrlFallsBackToDetectedExtensionWhenUrlHasNone(): void {
		$img = new Images();
		$img->FromUrl("https://example.com/path/noext", "jpg");
		$this->assertEquals("jpg", $img->extension);
		$this->assertEquals("noext", $img->name);
		$this->assertStringEndsWith("_noext.jpg", $img->filename);
	}

	public function testFromUrlWithoutExtensionAndNoDetectedExtension(): void {
		$img = new Images();
		$img->FromUrl("https://example.com/path/noext");
		$this->assertNull($img->extension);
		$this->assertStringEndsWith("_noext", $img->filename);
	}

	public function testBuildGenFileNamePrefixesWithId(): void {
		$img = new Images();
		$img->id = 7;
		$this->assertEquals("7_thumb", $img->BuildGenFileName("thumb"));
	}

	public function testBuildFilenameInsertsAddonBeforeExtension(): void {
		$img = new Images();
		$img->filename = "42_photo.jpg";
		$this->assertEquals("42_photo_100x100.jpg", $img->BuildFilename("100x100"));
	}

	public function testCanResizeIsFalseForSvg(): void {
		$img = new Images();
		$img->extension = "svg";
		$this->assertFalse($img->CanResize());
	}

	public function testCanResizeIsTrueForOtherExtensions(): void {
		$img = new Images();
		$img->extension = "png";
		$this->assertTrue($img->CanResize());
	}

	public function testIsSquare(): void {
		$img = new Images();
		$img->width = 100;
		$img->height = 100;
		$this->assertTrue($img->IsSquare());
		$this->assertFalse($img->IsPortrait());
		$this->assertFalse($img->IsLandscape());
	}

	public function testIsPortrait(): void {
		$img = new Images();
		$img->width = 100;
		$img->height = 200;
		$this->assertTrue($img->IsPortrait());
		$this->assertFalse($img->IsSquare());
		$this->assertFalse($img->IsLandscape());
	}

	public function testIsLandscape(): void {
		$img = new Images();
		$img->width = 200;
		$img->height = 100;
		$this->assertTrue($img->IsLandscape());
		$this->assertFalse($img->IsSquare());
		$this->assertFalse($img->IsPortrait());
	}

	public function testGetSizeDelegatesToHelper(): void {
		$img = new Images();
		$img->size = 0;
		$this->assertEquals("-", $img->GetSize());
	}

	public function testSetPlaceholderTogglesFlag(): void {
		$img = new Images();
		$this->assertFalse($img->placeholder);
		$rs = $img->SetPlaceholder();
		$this->assertSame($img, $rs);
		$this->assertTrue($img->placeholder);
	}

}
