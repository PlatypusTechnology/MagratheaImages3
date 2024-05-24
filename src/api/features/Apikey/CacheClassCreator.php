<?php
namespace MagratheaImages3\Apikey;

use function Magrathea2\now;

class CacheClassCreator {

	public function FileDestination(): string {
		$folder = __DIR__."/cache";
		return realpath($folder);
	}
	public function GetFile(): string {
		$file = "ApikeyCache.php";
		return $this->FileDestination()."/".$file;
	}

	public function GetHeader(): string {
		$code = "<?php\n";
		$code .= "## FILE AUTOMATICALLY GENERATED\n";
		$code .= "## -- date of creation: [".now()."]\n\n";
		$code .= "namespace MagratheaImages3\Apikey;\n\n";
		return $code;
	}

	public function GetCode(): string {
		$code = $this->GetHeader();
		$code .= "function GetCachedApiKey (string \$id): array|null {\n";
		$code .= $this->GetKeysArray();
		$code .= "\treturn @\$keys[\$id];\n";
		$code .= "}\n";
		return $code;
	}

	private function GetKeysArray(): string {
		$control = new ApikeyControl();
		/** @var ApiKey[] */
		$keys = $control->GetAll();
		$code = "\t\$keys = [\n";
		foreach($keys as $k) {
			$code .= "\t\t\"".$k->public_key."\" => [\"id\" => \"".$k->id."\", \"folder\" => \"".$k->folder."\"],\n";
		}
		$code .= "\t];\n";
		return $code;
	}
	
	public function Generate() {
		return file_put_contents($this->GetFile(), $this->GetCode());
	}

}

