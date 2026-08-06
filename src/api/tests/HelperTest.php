<?php

use MagratheaImages3\Helper;

include_once(__DIR__."/../_inc.php");

class HelperTest extends \PHPUnit\Framework\TestCase {

	public function testGetSizeEmptyReturnsDash(): void {
		$this->assertEquals("-", Helper::GetSize(0));
		$this->assertEquals("-", Helper::GetSize(null));
	}

	public function testGetSizeUnderOneMbReturnsKB(): void {
		$this->assertEquals("1 KB", Helper::GetSize(1024));
		$this->assertEquals("2.5 KB", Helper::GetSize(2560));
	}

	public function testGetSizeUnderOneGbReturnsMB(): void {
		$oneMb = 1024 * 1024;
		$this->assertEquals("1 MB", Helper::GetSize($oneMb));
		$this->assertEquals("2.5 MB", Helper::GetSize(1024 * 1024 * 2.5));
	}

	public function testGetSizeOneGigabyteReturnsGB(): void {
		$oneGb = 1024 * 1024 * 1024;
		$this->assertEquals("1GB", Helper::GetSize($oneGb));
	}

	public function testIsGDWorkingReturnsBool(): void {
		$this->assertIsBool(Helper::IsGDWorking());
	}

	public function testCleanReplacesPunctuationWithDash(): void {
		$this->assertEquals("my-folder", Helper::Clean("my.folder"));
		$this->assertEquals("a-b-c", Helper::Clean("a!b?c"));
	}

	public function testCleanReplacesWhitespaceWithUnderscore(): void {
		$this->assertEquals("my_folder_name", Helper::Clean("my folder name"));
	}

	public function testCleanTransliteratesNonAscii(): void {
		$this->assertEquals("cafe", Helper::Clean("café"));
	}

}
