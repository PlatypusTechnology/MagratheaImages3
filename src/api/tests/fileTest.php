<?php

use Magrathea2\ConfigApp;
use Magrathea2\Tests\TestsHelper;
use MagratheaImages3\Apikey\Apikey;

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
		$mediaFolder = "/some/path/to/media";
		$destinationFolder = "gen-bla";
		$apikey = new Apikey();
		$apikey->val = "randomkey";
		$apikey->folder = $destinationFolder;
		$mediaFolder = "/some/path/to/media";
		$folder = $apikey->GetDestinationFolder();
		$this->assertEquals($mediaFolder."/".$destinationFolder, $folder);
	}

}
