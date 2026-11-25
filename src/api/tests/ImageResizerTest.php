<?php

use MagratheaImages3\Images\ImageResizer;
use MagratheaImages3\Images\Images;

include_once(__DIR__."/../_inc.php");
require_once(__DIR__."/BandFixture.php");

class ImageResizerTest extends \PHPUnit\Framework\TestCase {

	use BandFixture;

	protected function tearDown(): void {
		$this->removeBandFiles();
		parent::tearDown();
	}

	private function buildImage(): Images {
		$img = new Images();
		$img->extension = "jpg";
		$img->folder = "myfolder";
		$img->width = 1000;
		$img->height = 500;
		return $img;
	}

	public function testConstructorReadsExtensionAndRawFileFromImage(): void {
		$mediasPath = rtrim(\Magrathea\Config::Instance()->Get("medias_path"), "/");
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
		\Magrathea\ConfigApp::Instance()->Mock(["thumb_size" => "150"]);
		$resizer = new ImageResizer($this->buildImage());
		$this->assertEquals(150, $resizer->GetThumbSize());
	}

	// NOTE: ConfigApp::Get()'s $default parameter is only honored when reading
	// from the real (non-mocked) app config; under Mock() it always returns
	// whatever is in the mock array (or null), ignoring the caller's default.
	// So GetThumbSize()'s own default of 100 never kicks in under a mock with
	// no "thumb_size" key -- intval(null) is 0.
	public function testGetThumbSizeIsZeroWhenMockedWithoutValue(): void {
		\Magrathea\ConfigApp::Instance()->Mock([]);
		$resizer = new ImageResizer($this->buildImage());
		$this->assertEquals(0, $resizer->GetThumbSize());
	}

	public function testAdjustPlaceholderShrinksDimensionsWhenPlaceholder(): void {
		\Magrathea\ConfigApp::Instance()->Mock(["placeholder_size" => "0.4"]);
		$resizer = (new ImageResizer($this->buildImage()))->SetDimensions(200, 100)->SetPlaceholder();
		$resizer->AdjustPlaceholder();
		$this->assertEquals(80, $resizer->width);
		$this->assertEquals(40, $resizer->height);
	}

	public function testAdjustPlaceholderKeepsDimensionsWhenNotPlaceholder(): void {
		\Magrathea\ConfigApp::Instance()->Mock(["placeholder_size" => "0.4"]);
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

	public function testSetFitTogglesFlag(): void {
		$resizer = new ImageResizer($this->buildImage());
		$this->assertFalse($resizer->fit);
		$rs = $resizer->SetFit();
		$this->assertSame($resizer, $rs);
		$this->assertTrue($resizer->fit);
	}

	public static function fitCases(): array {
		return [
			"landscape into a square box"  => [1600, 900, 800, 800, [800, 450]],
			"portrait into a square box"   => [900, 1600, 800, 800, [450, 800]],
			"smaller than the box: no upscaling" => [400, 300, 800, 800, [400, 300]],
			"same ratio as the box"        => [1600, 1200, 800, 600, [800, 600]],
			"rounding"                     => [3000, 2000, 800, 800, [800, 533]],
			"extreme ratio clamped to 1"   => [4000, 10, 100, 100, [100, 1]],
			"limited by height in a wide box" => [1000, 1000, 800, 200, [200, 200]],
		];
	}

	#[\PHPUnit\Framework\Attributes\DataProvider("fitCases")]
	public function testFitDimensions(int $srcW, int $srcH, int $boxW, int $boxH, array $expected): void {
		$this->assertEquals($expected, ImageResizer::FitDimensions($srcW, $srcH, $boxW, $boxH));
	}

	public function testFitResizesTheWholeSourceIntoTheFittedSize(): void {
		$this->requireGD();
		$resizer = $this->bandResizer(1600, 900, "jpg")->SetFit()->SetDimensions(800, 800);
		$resizer->Resize();
		$gd = $resizer->GetGD();
		$this->assertEquals([800, 450], $this->sizeOf($gd));
		// both outer bands survive: nothing was cropped
		$this->assertEquals("red", $this->colorAt($gd, 2, 225));
		$this->assertEquals("green", $this->colorAt($gd, 400, 225));
		$this->assertEquals("blue", $this->colorAt($gd, 797, 225));
	}

	public function testFitNeverUpscales(): void {
		$this->requireGD();
		$resizer = $this->bandResizer(400, 300)->SetFit()->SetDimensions(800, 800);
		$resizer->Resize();
		$gd = $resizer->GetGD();
		$this->assertEquals([400, 300], $this->sizeOf($gd));
		$this->assertEquals("red", $this->colorAt($gd, 2, 150));
		$this->assertEquals("blue", $this->colorAt($gd, 397, 150));
	}

	public function testFitDebugReportsTheFittedSize(): void {
		$this->requireGD();
		$resizer = $this->bandResizer(1600, 900)->DebugOn()->SetFit()->SetDimensions(800, 800);
		$resizer->Resize();
		$this->assertContains("fit: box 800x800 -> 800x450", $resizer->GetDebug());
	}

	/**
	 * placeholder_size applies to the fitted size, not to the box. For an original smaller than
	 * the box the order matters: fit-then-placeholder gives 200×150, placeholder-then-fit 400×300.
	 */
	public function testFitPlaceholderScalesTheFittedSize(): void {
		$this->requireGD();
		\Magrathea\ConfigApp::Instance()->Mock(["placeholder_size" => "0.5"]);
		$small = $this->bandResizer(400, 300)->SetFit()->SetPlaceholder()->SetDimensions(800, 800);
		$small->Resize();
		$this->assertEquals([200, 150], $this->sizeOf($small->GetGD()));

		$large = $this->bandResizer(1600, 900)->SetFit()->SetPlaceholder()->SetDimensions(800, 800);
		$large->Resize();
		$this->assertEquals([400, 225], $this->sizeOf($large->GetGD()));
	}

	public function testFitPlaceholderNeverRoundsASideToZero(): void {
		$this->requireGD();
		\Magrathea\ConfigApp::Instance()->Mock(["placeholder_size" => "0.5"]);
		// fitted to 100×1; the placeholder's floor(1 × 0.5) would be 0 and GD can't create that
		$resizer = $this->bandResizer(4000, 10)->SetFit()->SetPlaceholder()->SetDimensions(100, 100);
		$resizer->Resize();
		$this->assertEquals([50, 1], $this->sizeOf($resizer->GetGD()));
	}

}
