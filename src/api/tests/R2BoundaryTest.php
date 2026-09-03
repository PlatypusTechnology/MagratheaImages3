<?php

use PHPUnit\Framework\TestCase;

/**
 * The R2 backup module is optional to *configure*, not optional to *delete*:
 * nothing under these folders may reference an R2 class by name, so removing
 * `features/R2/` (and its one connection point, once phase 4 adds it in
 * `MagratheaImages3\R2\R2Admin`) never requires touching the upload/serve path.
 */
class R2BoundaryTest extends TestCase
{
	private const GUARDED_DIRS = [
		"features/Images",
		"admin/MediaManager",
		"admin/GeneratedFileManager",
	];

	public function testNoCoreCodeReferencesR2ByName()
	{
		$hits = [];
		foreach(self::GUARDED_DIRS as $dir) {
			$path = __DIR__."/../".$dir;
			$this->assertDirectoryExists($path, "expected guarded directory to exist: ".$dir);
			$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS));
			foreach($iterator as $file) {
				if($file->getExtension() !== "php" && $file->getExtension() !== "js") continue;
				$contents = file_get_contents($file->getPathname());
				if(preg_match('/\bR2[A-Za-z]*\b/', $contents)) {
					$hits[] = $file->getPathname();
				}
			}
		}
		$this->assertSame([], $hits, "found an R2 reference in a directory the backup module must stay invisible to");
	}
}
