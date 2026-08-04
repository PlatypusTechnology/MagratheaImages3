<?php

use MagratheaImages3\Apikey\ApikeyControl;

include_once(__DIR__."/../_inc.php");

class ApikeyControlTest extends \PHPUnit\Framework\TestCase {

	private string $tmpDir;

	protected function setUp(): void {
		\Magrathea2\DB\Database::MockClass(\Magrathea2\DB\DatabaseSimulate::Instance());
		$this->tmpDir = sys_get_temp_dir()."/apikeycontrol-test-".uniqid();
		mkdir($this->tmpDir, 0755, true);
		parent::setUp();
	}

	protected function tearDown(): void {
		if (is_dir($this->tmpDir)) {
			$this->removeDirRecursive($this->tmpDir);
		}
		parent::tearDown();
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

	public function testCreateRandomStrLength(): void {
		$control = new ApikeyControl();
		$str = $control->createRandomStr(25);
		$this->assertEquals(25, strlen($str));
	}

	public function testCreateRandomStrOnlyUsesAllowedCharacters(): void {
		$control = new ApikeyControl();
		$str = $control->createRandomStr(100);
		$this->assertMatchesRegularExpression('/^[0-9a-z]+$/', $str);
	}

	public function testAssertKeyNotInUseIsTrueWhenNoRowsMatch(): void {
		// with the DB mocked, QueryOne() always returns null (== 0), so this
		// always reports the key as free -- that's expected under the mock.
		$control = new ApikeyControl();
		$this->assertTrue($control->assertKeyNotInUse("some-key", true));
		$this->assertTrue($control->assertKeyNotInUse("some-key", false));
	}

	public function testCreateKeyReturnsExpectedLengths(): void {
		// NOTE: createKey() calls assertKeyNotInUse($private, $key) with the
		// arguments swapped (see ApikeyControl::createKey(), line 27) --
		// the boolean flag is passed where the key string is expected and
		// vice-versa. In practice this means the real uniqueness check never
		// actually queries for the generated key, it queries for the boolean.
		// This test only pins down the lengths createKey() currently produces,
		// not the (broken) uniqueness guarantee.
		$control = new ApikeyControl();
		$this->assertEquals(25, strlen($control->createKey(true)));
		$this->assertEquals(12, strlen($control->createKey(false)));
	}

	public function testCreateDirCreatesFolderAndReturnsSuccess(): void {
		$dir = $this->tmpDir."/new-dir";
		$control = new ApikeyControl();
		$rs = $control->CreateDir($dir);
		$this->assertTrue($rs["success"]);
		$this->assertDirectoryExists($dir);
	}

	public function testRemoveDirDeletesFilesAndSubfolders(): void {
		mkdir($this->tmpDir."/sub", 0755, true);
		file_put_contents($this->tmpDir."/a.txt", "x");
		file_put_contents($this->tmpDir."/sub/b.txt", "x");

		$control = new ApikeyControl();
		$warnings = [];
		$rs = $control->RemoveDir($this->tmpDir."/", $warnings);

		$this->assertTrue($rs);
		$this->assertEmpty($warnings);
		$this->assertDirectoryDoesNotExist($this->tmpDir);
	}

	public function testRemoveDirOnMissingFolderWarnsAndReturnsTrue(): void {
		$control = new ApikeyControl();
		$warnings = [];
		$missing = sys_get_temp_dir()."/does-not-exist-".uniqid()."/";
		$rs = $control->RemoveDir($missing, $warnings);

		$this->assertTrue($rs);
		$this->assertCount(1, $warnings);
		$this->assertStringContainsString("folder does not exist", $warnings[0]);
	}

}
