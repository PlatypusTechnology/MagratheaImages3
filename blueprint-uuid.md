# UUID for images:

## the change:
apply UUID for the images.
we will only need this for the images, once apikeys are already protected with private/public keys

## version update:
minor change (v.3.5.0)
update changelog with this change

## Database:
add UUID field to the images table. Remember it has to be unique (preferably add it below `id`);
add `migration-3.5.0-image-uuid` file with the migration for existing running project, including the uuid generation for the existing rows.

## Configuration
UUID will only run under a configuration, in magrathea.conf file. Just as `webp_quick_access`, there will be a `force_uuid` field:
If this field is `1`, the only way of getting an image is through the UUID, the endpoints with ids will throw an error message

## new endpoints
now, all the endpoints that get images will also work if the user asks the image through its UUID.

## generated images
The generated images will be generated with UUID as well — though, if the `force_uuid` is off, and the user requests through the id, a image with the id will also be generated.
The generated images names will be exactly as it is today, but with the uuid replacing the id

## other changes
- `secure_api` will be dropped: we will ALWAYS consider the request as secures from now on. we can remove the endpoints that would not work with `secure_api` off
- `private_key`/`public_key` sizes will be dynamic. Right now we have a fixed size: `$length = $private ? 25 : 12;` (ApiKeyControl.php, line 25). We're gonna have OPTIONAL fields in AppConfiguration (the magratheaphp database configuration, not the magrathea.conf one) called `private_key_size`/`public_key_size`, that will define those sizes. if the key is not present, the default will be the same as it is today. Confirm if keys with different sizes would work the same way
- add `changelog` endpoint — it will return the changelog. Get inspired by this function which is working in another API:
```php
	public function GetChangelog(): array {
		$this->Cache("changelog");
		$filepath = MagratheaHelper::EnsureTrailingSlash(MagratheaPHP::Instance()->magRoot)."changelog.md";
		if (!file_exists($filepath)) return [];
		$content = file_get_contents($filepath);
		$lines = preg_split('/\r?\n/', $content);
		$changelog = [];
		$current = null;
		foreach ($lines as $line) {
			$line = trim($line);
			if (preg_match('/^## v\\.([0-9.]+)/i', $line, $m)) {
				if ($current) $changelog[] = $current;
				$current = [
					'version' => $m[1],
					'date' => null,
					'changes' => []
				];
			} elseif (preg_match('/^### ([0-9]{4}-[0-9]{2}-[0-9]{2})/', $line, $m)) {
				if ($current) $current['date'] = $m[1];
			} elseif (preg_match('/^- \*\*(new|fix|improvement)\*\*:? (.+)$/i', $line, $m)) {
				if ($current) {
					$current['changes'][] = [
						'type' => strtolower($m[1]),
						'description' => $m[2]
					];
				}
			}
		}
		if ($current) $changelog[] = $current;
		return array_slice($changelog, 0, 5);
	}
```

## result:
this is quite a big change. we can split in different phases, starting with the database and migration changes, then moving to the last section with other changes, then implementing the reinforced uuid changes.
Analyze this plan and assure you understood it.
Then generate a plan-uuid.md with the guided implementation for this change, splitting it into different phases — a session per task, so we won't overload your session with too much.

don't change anything apart from the creation of plan-uuid.md

