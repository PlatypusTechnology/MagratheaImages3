let fileFeatureName = "AdminGenerated";

function GeneratedFileGoToAction(action, data) {
	let featurePage = "?magrathea_feature=AdminGenerated";
	let subpage = "&magrathea_feature_action="+action + "&" + buildQueryString(data);
	window.location.href = getCurrentPage() + featurePage + subpage;
}

function selectApiKey(key) {
	callFeature(fileFeatureName, "Folder", "GET", {"folder_id": key.value })
		.then(rs => showOn("#folder-response", rs));
}

function getSelectedApiKey() {
	return $("#apikey :selected").val();
}

function openFolder(folder) {
	let apikey = getSelectedApiKey();
	callFeature(fileFeatureName, "Files", "POST", { apikey, folder })
		.then(rs => showOn("#files-response", rs));
}

function viewImage(folder, image) {
	let apikey = getSelectedApiKey();
	callFeature(fileFeatureName, "Preview", "POST", { apikey, folder, image })
		.then(rs => showOn("#folder-image-viewer", rs));
}

function deleteGeneratedImage(btn, apikey, file) {
	$(btn).fadeOut();
	callFeature(fileFeatureName, "DeleteFile", "POST", { apikey, file })
		.then((rs) => {
			addTo("#folder-image-viewer", rs);
			openFolder('generated');
		});
}

function deletePattern(apikey) {
	const pattern = $("#file_pattern").val();
	callFeature(fileFeatureName, "Pattern", "POST", { apikey, pattern })
		.then((rs) => {
			addTo("#folder-image-viewer", rs);
			openFolder('generated');
		});
}
