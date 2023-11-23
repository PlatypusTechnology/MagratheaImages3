<?php

namespace MagratheaImages3\Images;

use Magrathea2\Singleton;
use Magrathea2\Helper;
use Magrathea2\Logger;


class PathManager {

	public static function GetRawFolder($folder): string {
		return Helper::EnsureTrailingSlash($folder)."raw/";
	}

	public static function GetGeneratedFolder($folder): string {
		return Helper::EnsureTrailingSlash($folder)."generated/";
	}

	public static function CheckDestinationFolder($path): array {
		if (is_dir($path)) return [ "success" => true, "path" => $path ];
		try {
			if(mkdir($path, 0755, true)) {
				return [ "success" => true, "path" => $path ];
			} else {
				return [ "success" => false, "error" => "unknown error", "path" => $path ];
			}
			if(!is_writeable($path)){
				return [ "success" => false, "error" => "destination path has no writing permission", "path" => $path ];
			}
		} catch(\Exception $e) {
			Logger::Instance()->Log("error creating upload folder ". $e->getMessage());
			return [ "success" => false, "error" => $e->getMessage(), "path" => $path ];
		}
	}


}
