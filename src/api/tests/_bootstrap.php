<?php

require_once __DIR__."/../_inc.php";

// The framework's default Debugger mode (LOG) makes constructing a
// "killer" exception (e.g. MagratheaConfigException) call
// ErrorManager::DisplayException() and die() immediately, and makes any other
// exception attempt to write to the configured logs_path (e.g. /var/www/logs),
// which doesn't exist outside the app container. Both behaviors turn expected,
// caught-in-test exceptions into a hard process exit. NONE disables both, which
// is what unit tests need: exceptions should be catchable, not fatal.
\Magrathea2\Debugger::Instance()->SetType(\Magrathea2\Debugger::NONE);
