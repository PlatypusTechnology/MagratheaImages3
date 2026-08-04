<?php

use MagratheaImages3\Images\ImageViewer;
use MagratheaImages3\Images\Images;

include_once(__DIR__."/../_inc.php");

class ImageViewerTest extends \PHPUnit\Framework\TestCase {

	private function buildImage(): Images {
		$img = new Images();
		$img->id = 1;
		$img->name = "photo";
		$img->extension = "jpg";
		$img->folder = "myfolder";
		return $img;
	}

	public function testHeaderExtensionReturnsTrueForKnownExtensions(): void {
		foreach (["jpg", "jpeg", "png", "bmp", "gif", "webp", "wbmp", "ico", "svg"] as $ext) {
			$this->assertTrue(ImageViewer::HeaderExtension($ext));
		}
	}

	public function testHeaderExtensionReturnsFalseForUnknownExtension(): void {
		$this->assertFalse(ImageViewer::HeaderExtension("docx"));
	}

	public function testSetFileStoresFilenameAndIsChainable(): void {
		$viewer = new ImageViewer($this->buildImage());
		$rs = $viewer->SetFile("some/path.jpg");
		$this->assertSame($viewer, $rs);
		$this->assertEquals("some/path.jpg", $viewer->file);
	}

	public function testDebugTogglesFlag(): void {
		$viewer = new ImageViewer($this->buildImage());
		$this->assertFalse($viewer->debugOn);
		$rs = $viewer->Debug();
		$this->assertSame($viewer, $rs);
		$this->assertTrue($viewer->debugOn);
	}

	public function testForceGenerationTogglesFlag(): void {
		$viewer = new ImageViewer($this->buildImage());
		$this->assertFalse($viewer->forceGeneration);
		$viewer->ForceGeneration();
		$this->assertTrue($viewer->forceGeneration);
	}

	public function testDontSaveTogglesFlag(): void {
		$viewer = new ImageViewer($this->buildImage());
		$this->assertTrue($viewer->saveImage);
		$viewer->DontSave();
		$this->assertFalse($viewer->saveImage);
	}

	public function testPlaceholderTogglesLowQualityAndIsChainable(): void {
		$viewer = new ImageViewer($this->buildImage());
		$this->assertFalse($viewer->lowQuality);
		$rs = $viewer->Placeholder();
		$this->assertSame($viewer, $rs);
		$this->assertTrue($viewer->lowQuality);
	}

	public function testGetResizerDebugWithoutResizer(): void {
		$viewer = new ImageViewer($this->buildImage());
		$this->assertEquals(["no-resizer"], $viewer->GetResizerDebug());
	}

	public function testShouldGenerateTrueWhenDebugOn(): void {
		$viewer = (new ImageViewer($this->buildImage()))->Debug();
		$viewer->file = sys_get_temp_dir(); // exists, but debug forces true anyway
		$this->assertTrue($viewer->ShouldGenerate());
	}

	public function testShouldGenerateTrueWhenForceGeneration(): void {
		$viewer = (new ImageViewer($this->buildImage()))->ForceGeneration();
		$viewer->file = __FILE__; // exists, but force generation forces true anyway
		$this->assertTrue($viewer->ShouldGenerate());
	}

	public function testShouldGenerateFalseWhenFileExists(): void {
		$viewer = new ImageViewer($this->buildImage());
		$viewer->file = __FILE__;
		$this->assertFalse($viewer->ShouldGenerate());
		$this->assertTrue($viewer->fileExists);
	}

	public function testShouldGenerateTrueWhenFileMissing(): void {
		$viewer = new ImageViewer($this->buildImage());
		$viewer->file = sys_get_temp_dir()."/does-not-exist-".uniqid().".jpg";
		$this->assertTrue($viewer->ShouldGenerate());
		$this->assertFalse($viewer->fileExists);
	}

}
