<?php

use MagratheaImages3\Images\FileManager;

include_once(__DIR__."/../_inc.php");

class FileManagerTest extends \PHPUnit\Framework\TestCase {

	private string $tmpDir;

	protected function setUp(): void {
		$this->tmpDir = sys_get_temp_dir()."/magrathea-images-filemanager-".uniqid();
		mkdir($this->tmpDir, 0755, true);
		parent::setUp();
	}

	protected function tearDown(): void {
		if (is_dir($this->tmpDir)) {
			foreach (scandir($this->tmpDir) as $item) {
				if ($item == "." || $item == "..") continue;
				@unlink($this->tmpDir."/".$item);
			}
			rmdir($this->tmpDir);
		}
		parent::tearDown();
	}

	public function testSetPathThrowsWhenFolderDoesNotExist(): void {
		$this->expectException(\Magrathea2\Exceptions\MagratheaConfigException::class);
		(new FileManager())->SetPath($this->tmpDir."/does-not-exist");
	}

	public function testSetPathEnsuresTrailingSlash(): void {
		$manager = (new FileManager())->SetPath($this->tmpDir);
		$this->assertEquals($this->tmpDir."/", $manager->path);
	}

	public function testGetFilesReturnsAllWhenNoExtensionsSet(): void {
		file_put_contents($this->tmpDir."/a.jpg", "x");
		file_put_contents($this->tmpDir."/b.txt", "x");
		$manager = (new FileManager())->SetPath($this->tmpDir);
		$files = $manager->GetFiles();
		$this->assertContains("a.jpg", $files);
		$this->assertContains("b.txt", $files);
	}

	public function testGetFilesFiltersByExtension(): void {
		file_put_contents($this->tmpDir."/a.jpg", "x");
		file_put_contents($this->tmpDir."/b.txt", "x");
		file_put_contents($this->tmpDir."/c.png", "x");
		$manager = (new FileManager())->SetPath($this->tmpDir)->AllowedExtensions(["jpg", "png"]);
		$files = $manager->GetFiles();
		$this->assertContains($this->tmpDir."/a.jpg", $files);
		$this->assertContains($this->tmpDir."/c.png", $files);
		$this->assertNotContains($this->tmpDir."/b.txt", $files);
	}

	public function testDeleteFileThrowsWhenFileDoesNotExist(): void {
		$this->expectException(\Magrathea2\Exceptions\MagratheaException::class);
		(new FileManager())->SetPath($this->tmpDir)->DeleteFile("missing.jpg");
	}

	public function testDeleteFileRemovesExistingFile(): void {
		file_put_contents($this->tmpDir."/a.jpg", "x");
		$manager = (new FileManager())->SetPath($this->tmpDir);
		$this->assertTrue($manager->DeleteFile("a.jpg"));
		$this->assertFileDoesNotExist($this->tmpDir."/a.jpg");
	}

}
