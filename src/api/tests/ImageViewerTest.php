<?php

use MagratheaImages3\Images\ImageViewer;
use MagratheaImages3\Images\Images;

include_once(__DIR__."/../_inc.php");
require_once(__DIR__."/BandFixture.php");

class ImageViewerTest extends \PHPUnit\Framework\TestCase {

	use BandFixture;

	protected function tearDown(): void {
		$this->removeBandFiles();
		parent::tearDown();
	}

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

	/** A viewer over a w×h band fixture that generates in memory and never writes a file. */
	private function bandViewer(int $w, int $h): ImageViewer {
		$resizer = $this->bandResizer($w, $h);
		$viewer = new ImageViewer($resizer->image);
		$viewer->resizer = $resizer;
		$viewer->SetFile(sys_get_temp_dir()."/never-written-".uniqid());
		return $viewer->DontSave()->ForceGeneration();
	}

	/**
	 * Stretched, x=5 of 500 maps to source x≈10, inside the red quarter of a 1000-wide source.
	 * The default crop takes source x 250-750 (all green): before 3.7.0, Process() set
	 * keepAspectRatio after Resize() had run, so stretch=1 returned exactly that crop.
	 */
	public function testStretchResizesTheWholeSourceWithoutCropping(): void {
		$this->requireGD();
		$gd = $this->bandViewer(1000, 500)->Process(500, 500, true)->resizer->GetGD();
		$this->assertEquals([500, 500], $this->sizeOf($gd));
		$this->assertEquals("red", $this->colorAt($gd, 5, 250));
		$this->assertEquals("blue", $this->colorAt($gd, 494, 250));
	}

	public function testWithoutStretchTheDefaultCropIsUnchanged(): void {
		$this->requireGD();
		$gd = $this->bandViewer(1000, 500)->Process(500, 500, false)->resizer->GetGD();
		$this->assertEquals([500, 500], $this->sizeOf($gd));
		$this->assertEquals("green", $this->colorAt($gd, 5, 250));
		$this->assertEquals("green", $this->colorAt($gd, 494, 250));
	}

	public function testFitWinsOverStretch(): void {
		$this->requireGD();
		$gd = $this->bandViewer(1600, 900)->Process(800, 800, true, true)->resizer->GetGD();
		$this->assertEquals([800, 450], $this->sizeOf($gd));
		$this->assertEquals("red", $this->colorAt($gd, 2, 225));
		$this->assertEquals("blue", $this->colorAt($gd, 797, 225));
	}

}
