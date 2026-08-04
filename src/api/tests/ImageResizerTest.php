<?php

use MagratheaImages3\Images\ImageResizer;
use MagratheaImages3\Images\Images;

include_once(__DIR__."/../_inc.php");

class ImageResizerTest extends \PHPUnit\Framework\TestCase {

	private function buildImage(): Images {
		$img = new Images();
		$img->extension = "jpg";
		$img->folder = "myfolder";
		$img->width = 1000;
		$img->height = 500;
		return $img;
	}

	public function testConstructorReadsExtensionAndRawFileFromImage(): void {
		$mediasPath = rtrim(\Magrathea2\Config::Instance()->Get("medias_path"), "/");
		$img = $this->buildImage();
		$img->filename = "1_photo.jpg";
		$resizer = new ImageResizer($img);
		$this->assertEquals("jpg", $resizer->extension);
		$this->assertEquals($mediasPath."/myfolder/raw/1_photo.jpg", $resizer->rawFile);
	}

	public function testSetDimensionsStoresWidthAndHeight(): void {
		$resizer = new ImageResizer($this->buildImage());
		$rs = $resizer->SetDimensions(200, 100);
		$this->assertSame($resizer, $rs);
		$this->assertEquals(200, $resizer->width);
		$this->assertEquals(100, $resizer->height);
	}

	public function testSetNewFileStoresName(): void {
		$resizer = new ImageResizer($this->buildImage());
		$rs = $resizer->SetNewFile("some/path/file");
		$this->assertSame($resizer, $rs);
		$this->assertEquals("some/path/file", $resizer->newFile);
	}

	public function testSetPlaceholderTogglesFlag(): void {
		$resizer = new ImageResizer($this->buildImage());
		$this->assertFalse($resizer->placeholder);
		$rs = $resizer->SetPlaceholder();
		$this->assertSame($resizer, $rs);
		$this->assertTrue($resizer->placeholder);
	}

	public function testGetDebugStartsEmpty(): void {
		$resizer = new ImageResizer($this->buildImage());
		$this->assertEquals([], $resizer->GetDebug());
	}

	public function testPrintDebugIsNoopWhenDebugOff(): void {
		$resizer = new ImageResizer($this->buildImage());
		$resizer->PrintDebug("should not be recorded");
		$this->assertEquals([], $resizer->GetDebug());
	}

	public function testPrintDebugRecordsWhenDebugOn(): void {
		$resizer = (new ImageResizer($this->buildImage()))->DebugOn();
		$resizer->PrintDebug("step one");
		$this->assertEquals(["step one"], $resizer->GetDebug());
	}

	public function testAddDebugArrayAppendsAllSteps(): void {
		$resizer = new ImageResizer($this->buildImage());
		$resizer->AddDebugArray(["a", "b"]);
		$resizer->AddDebugArray(["c"]);
		$this->assertEquals(["a", "b", "c"], $resizer->GetDebug());
	}

	public function testGetThumbSizeReadsFromAppConfig(): void {
		\Magrathea2\ConfigApp::Instance()->Mock(["thumb_size" => "150"]);
		$resizer = new ImageResizer($this->buildImage());
		$this->assertEquals(150, $resizer->GetThumbSize());
	}

	// NOTE: ConfigApp::Get()'s $default parameter is only honored when reading
	// from the real (non-mocked) app config; under Mock() it always returns
	// whatever is in the mock array (or null), ignoring the caller's default.
	// So GetThumbSize()'s own default of 100 never kicks in under a mock with
	// no "thumb_size" key -- intval(null) is 0.
	public function testGetThumbSizeIsZeroWhenMockedWithoutValue(): void {
		\Magrathea2\ConfigApp::Instance()->Mock([]);
		$resizer = new ImageResizer($this->buildImage());
		$this->assertEquals(0, $resizer->GetThumbSize());
	}

	public function testAdjustPlaceholderShrinksDimensionsWhenPlaceholder(): void {
		\Magrathea2\ConfigApp::Instance()->Mock(["placeholder_size" => "0.4"]);
		$resizer = (new ImageResizer($this->buildImage()))->SetDimensions(200, 100)->SetPlaceholder();
		$resizer->AdjustPlaceholder();
		$this->assertEquals(80, $resizer->width);
		$this->assertEquals(40, $resizer->height);
	}

	public function testAdjustPlaceholderKeepsDimensionsWhenNotPlaceholder(): void {
		\Magrathea2\ConfigApp::Instance()->Mock(["placeholder_size" => "0.4"]);
		$resizer = (new ImageResizer($this->buildImage()))->SetDimensions(200, 100);
		$resizer->AdjustPlaceholder();
		$this->assertEquals(200, $resizer->width);
		$this->assertEquals(100, $resizer->height);
	}

	public function testGetGDReturnsNewGdImageWhenSet(): void {
		if (!function_exists("imagecreatetruecolor")) {
			$this->markTestSkipped("GD extension is not available in this environment");
		}
		$resizer = new ImageResizer($this->buildImage());
		$gd = imagecreatetruecolor(2, 2);
		$resizer->newGdImage = $gd;
		$this->assertSame($gd, $resizer->GetGD());
	}

}
