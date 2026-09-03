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
		$this->removeDir($this->tmpDir);
		parent::tearDown();
	}

	private function removeDir(string $dir): void {
		if (!is_dir($dir)) return;
		foreach (scandir($dir) as $item) {
			if ($item == "." || $item == "..") continue;
			$path = $dir."/".$item;
			if (is_dir($path)) {
				$this->removeDir($path);
			} else {
				@unlink($path);
			}
		}
		rmdir($dir);
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

	public function testDeleteFileRejectsTraversalOutsideBasePath(): void {
		$outside = sys_get_temp_dir()."/magrathea-filemanager-canary-".uniqid().".txt";
		file_put_contents($outside, "x");
		$manager = (new FileManager())->SetPath($this->tmpDir);
		try {
			$this->expectException(\Magrathea2\Exceptions\MagratheaException::class);
			$manager->DeleteFile("../".basename($outside));
		} finally {
			$this->assertFileExists($outside);
			@unlink($outside);
		}
	}

	public function testDeleteGeneratedPatternRemovesMatchingFilesById(): void {
		mkdir($this->tmpDir."/generated");
		file_put_contents($this->tmpDir."/generated/123_thumb.jpg", "x");
		file_put_contents($this->tmpDir."/generated/123_preview.jpg", "x");
		file_put_contents($this->tmpDir."/generated/456_thumb.jpg", "x");
		$manager = (new FileManager())->SetPath($this->tmpDir);
		$manager->DeleteGeneratedPattern("123_*");
		$this->assertFileDoesNotExist($this->tmpDir."/generated/123_thumb.jpg");
		$this->assertFileDoesNotExist($this->tmpDir."/generated/123_preview.jpg");
		$this->assertFileExists($this->tmpDir."/generated/456_thumb.jpg");
	}

	public function testDeleteGeneratedPatternRemovesMatchingFilesByUuid(): void {
		mkdir($this->tmpDir."/generated");
		$uuid = "0198abcd-1234-7abc-89ab-1234567890ab";
		file_put_contents($this->tmpDir."/generated/".$uuid."_thumb.jpg", "x");
		$manager = (new FileManager())->SetPath($this->tmpDir);
		$manager->DeleteGeneratedPattern($uuid."_*");
		$this->assertFileDoesNotExist($this->tmpDir."/generated/".$uuid."_thumb.jpg");
	}

	public function testDeleteGeneratedPatternRejectsShellMetacharacters(): void {
		mkdir($this->tmpDir."/generated");
		$canary = $this->tmpDir."/pwned";
		$manager = (new FileManager())->SetPath($this->tmpDir);
		try {
			$this->expectException(\Magrathea2\Exceptions\MagratheaException::class);
			$manager->DeleteGeneratedPattern("123_*; touch ".$canary." #");
		} finally {
			$this->assertFileDoesNotExist($canary);
		}
	}

	public function testDeleteGeneratedPatternRejectsTraversalPattern(): void {
		mkdir($this->tmpDir."/generated");
		$manager = (new FileManager())->SetPath($this->tmpDir);
		$this->expectException(\Magrathea2\Exceptions\MagratheaException::class);
		$manager->DeleteGeneratedPattern("../*");
	}

	public function testDeleteGeneratedPatternAllowsSizeSpecificAdminPattern(): void {
		// Images::GetFileName()/BuildGenFileName() names variants "{id}_{w}x{h}", not
		// just "{id}_*" -- the admin's free-text Delete Pattern field needs to be able
		// to target one size (e.g. "uuid_200*") without matching every generated variant.
		mkdir($this->tmpDir."/generated");
		$uuid = "0198abcd-1234-7abc-89ab-1234567890ab";
		file_put_contents($this->tmpDir."/generated/".$uuid."_200x300.webp", "x");
		file_put_contents($this->tmpDir."/generated/".$uuid."_200x300-s.webp", "x");
		file_put_contents($this->tmpDir."/generated/".$uuid."_thumb.webp", "x");
		$manager = (new FileManager())->SetPath($this->tmpDir);
		$manager->DeleteGeneratedPattern($uuid."_200*");
		$this->assertFileDoesNotExist($this->tmpDir."/generated/".$uuid."_200x300.webp");
		$this->assertFileDoesNotExist($this->tmpDir."/generated/".$uuid."_200x300-s.webp");
		$this->assertFileExists($this->tmpDir."/generated/".$uuid."_thumb.webp");
	}

}
