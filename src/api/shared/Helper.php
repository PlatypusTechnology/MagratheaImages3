<?php

namespace MagratheaImages3;

class Helper {
	public static function GetSize($size): string {
		if(empty($size)) return "-";
		$kb = $size / 1024;
		if($kb < 1024) return round($kb, 2)." KB";
		$mb = $kb / 1024;
		if($kb < 1024) return round($mb, 2)." MB";
		$gb = $mb / 1024;
		return round($gb, 2)."GB";
	}

	public static function IsGDWorking(): bool {
		return function_exists('gd_info');
	}
}
