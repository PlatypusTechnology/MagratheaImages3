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
