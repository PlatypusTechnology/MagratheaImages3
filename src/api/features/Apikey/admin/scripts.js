function listKeys() {
	callFeature("AdminApikey", "List")
		.then(rs => showOn("#container-keys-list", rs));
}

function newKey() {
	callFeature("AdminApikey", "Form")
		.then(rs => showOn("#container-keys-form", rs));
}

function createKey(el) {
	let data = getFormDataFromElement(el);
	console.info("creating key ", data);
	callFeature("AdminApikey", "Create", "POST", data)
		.then((rs) => {
			rs = JSON.parse(rs);
			if(!rs.success) {
				showToast(rs.error, "Error", true);
			} else {
				let type = rs.type;
				if(type == "insert") {
					showToast("Api key ["+rs.data?.id+"] inserted!", "New Key", false);
				} else {
					showToast("Api key ["+rs.data?.id+"] updated!", "Data Saved", false);
				}
			}
		})
		.then(listKeys);
}

function viewKey(id) {
	callFeature("AdminApikey", "ViewKey", "GET", { id })
		.then(rs => {
			showOn("#container-keys-rs", rs);
			return callFeature("AdminMedia", "Home", "GET", { apikey: id });
		})
		.then(rs => showOn("#key-media-" + id, rs));
}

function confirmDeleteKey(id) {
	callFeature("AdminApikey", "ConfirmDeleteApikey", "GET", { id })
		.then(rs => showOn("#key-delete-confirm-" + id, rs));
}

function deleteKey(id) {
	let input = $("#delete-challenge-" + id);
	if(input.val() !== "DELETE") {
		showToast("Type DELETE to confirm", "Error", true);
		return;
	}
	callFeature("AdminApikey", "DeleteApikey", "POST", { id })
		.then((rs) => {
			rs = JSON.parse(rs);
			if(!rs.success) {
				showToast(rs.error, "Error", true);
				return;
			}
			showToast("Api key #" + id + " deleted", "Deleted", false);
			let warnings = rs.data?.warnings || [];
			warnings.forEach(w => showToast(w, "Warning", true));
			$("#container-keys-rs").empty();
			listKeys();
		});
}

function cache() {
	callFeature("AdminApikey", "Cache")
		.then(rs => showOn("#container-keys-rs", rs));
}
function createCache() {
	callFeature("AdminApikey", "CreateCache")
		.then(rs => showOn("#container-keys-rs", rs));
}
