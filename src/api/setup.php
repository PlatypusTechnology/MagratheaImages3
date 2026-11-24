<?php
// MagratheaPHP 3 setup wizard. Locked by a token written to configs/.setup-token (readable only by
// someone with access to the server's files), and, once a config exists, by
// `bootstrap_unlocked = true` in the active environment. Installed instances answer 403.
require __DIR__."/../vendor/autoload.php";
Magrathea\Bootstrap\Start::Instance()->AppPath(__DIR__)->Load();
