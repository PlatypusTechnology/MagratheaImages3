<?php

namespace MagratheaImages3;

use Magrathea\Admin\AdminElements;

/**
 * For admin feature actions that change data. The admin checks its CSRF token on POST only, so
 * without this a GET link (or an `<img src>` in a page an admin opens) could call them.
 * Same contract as MagratheaPHP's own `AdminCrudObject::RefuseIfNotPost()`.
 */
trait AdminPostOnly {

	/**
	 * Answers 405 and stops, unless the request is a POST.
	 * @param bool $json the action answers JSON ({success, error}) instead of HTML
	 */
	protected function RefuseIfNotPost(bool $json = false): void {
		if(@$_SERVER["REQUEST_METHOD"] === "POST") return;
		http_response_code(405);
		$message = "this action requires a POST request";
		if($json) {
			echo json_encode(["success" => false, "error" => $message]);
		} else {
			AdminElements::Instance()->Alert($message, "danger");
		}
		die;
	}

}
