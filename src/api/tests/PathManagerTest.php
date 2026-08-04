<?php

use Magrathea2\Config;
use MagratheaImages3\Images\PathManager;

include_once(__DIR__."/../_inc.php");

class PathManagerTest extends \PHPUnit\Framework\TestCase {

	private $mediasPath;

	protected function setUp(): void {
		$this->mediasPath = rtrim(Config::Instance()->Get("medias_path"), "/");
		parent::setUp();
	}

	public function testGetMediaFolderAppendsApiFolder(): void {
		$folder = PathManager::GetMediaFolder("my-folder");
		$this->assertEquals($this->mediasPath."/my-folder/", $folder);
	}

	public function testGetRawFolderAppendsRaw(): void {
		$folder = PathManager::GetRawFolder("my-folder");
		$this->assertEquals($this->mediasPath."/my-folder/raw/", $folder);
	}

	public function testGetGeneratedFolderAppendsGenerated(): void {
		$folder = PathManager::GetGeneratedFolder("my-folder");
		$this->assertEquals($this->mediasPath."/my-folder/generated/", $folder);
	}

	public function testCheckDestinationFolderCreatesMissingFolder(): void {
		$path = sys_get_temp_dir()."/magrathea-images-test-".uniqid();
		$this->assertDirectoryDoesNotExist($path);
		$rs = PathManager::CheckDestinationFolder($path);
		$this->assertTrue($rs["success"]);
		$this->assertEquals($path, $rs["path"]);
		$this->assertDirectoryExists($path);
		rmdir($path);
	}

	public function testCheckDestinationFolderReturnsSuccessForExistingFolder(): void {
		$path = sys_get_temp_dir();
		$rs = PathManager::CheckDestinationFolder($path);
		$this->assertTrue($rs["success"]);
		$this->assertEquals($path, $rs["path"]);
	}

	public function testCheckDestinationFolderCreatesNestedFolders(): void {
		$base = sys_get_temp_dir()."/magrathea-images-test-".uniqid();
		$path = $base."/nested/deeper";
		$rs = PathManager::CheckDestinationFolder($path);
		$this->assertTrue($rs["success"]);
		$this->assertDirectoryExists($path);
		$this->removeDirRecursive($base);
	}

	private function removeDirRecursive(string $dir): void {
		if (!is_dir($dir)) return;
		foreach (scandir($dir) as $item) {
			if ($item == "." || $item == "..") continue;
			$p = $dir."/".$item;
			is_dir($p) ? $this->removeDirRecursive($p) : unlink($p);
		}
		rmdir($dir);
	}

}
