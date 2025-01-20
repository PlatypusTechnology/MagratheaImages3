<?php

use Magrathea2\Config;
use Magrathea2\ConfigApp;
use Magrathea2\Tests\TestsHelper;
use MagratheaImages3\Apikey\Apikey;

include_once(__DIR__."/../_inc.php");

class fileTest extends \PHPUnit\Framework\TestCase {

	private $testMediaFolder = "/some/path/to/media";
	public function mockAppConfig() {
		ConfigApp::Instance()->Mock(['media_folder' => $this->testMediaFolder]);
	}

	protected function setUp(): void {
		$this->mockAppConfig();
		parent::setUp();
	}

	protected function tearDown(): void {
		parent::tearDown();
	}

	function testGetMediaFolderFromApiKey() {
		$mediaFolder = Config::Instance()->Get("medias_path");
		$destinationFolder = "gen-bla";
		$apikey = new Apikey();
		$apikey->public_key = "randomkey";
		$apikey->folder = $destinationFolder;
		$folder = $apikey->GetDestinationFolder();
		$this->assertEquals($mediaFolder."/".$destinationFolder."/", $folder);
	}

}
