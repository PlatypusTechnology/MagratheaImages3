<?php

$sentryDsn = "sentry_dsn";

\Sentry\init([ 'dsn' => $sentryDsn ]);
set_exception_handler(function (Throwable $e) {
	\Sentry\captureException($e);
});
