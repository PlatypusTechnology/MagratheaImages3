let mediaFeatureName = "AdminMedia";

function getSelectedApiKey() {
	return $("#apikey :selected").val();
}

function selectMediaApiKey(key) {
	$(".hide-w-api").slideDown();
	loadHome(key.value);
}

function refreshMedias() {
	loadHome(getSelectedApiKey());
}

function loadHome(keyId) {
	callFeature(mediaFeatureName, "Home", "GET", { "apikey": keyId })
		.then(rs => showOn("#media-response", rs));
}

function loadUploader() {
	callFeature(mediaFeatureName, "Upload", "GET", { apikey: getSelectedApiKey() })
		.then((rs) => {
			showOn("#media-response", rs);
			loadDropzone();
		});
}

function viewMedia(id) {
	callFeature(mediaFeatureName, "ViewImage", "GET", { "id": id })
		.then(rs => addTo("#media-image-viewer", rs));
}

function openPreview(id, size) {
	callFeature(mediaFeatureName, "Preview", "GET", { id, size })
		.then(rs => addTo("#media-image-viewer", rs));
}

function previewSize(el) {
	let data = getFormDataFromElement(el);
	let id = data['id'];
	let size = data['w'] + 'x' + data['h'];
	openPreview(id, size);
}

function loadDropzone() {
	Dropzone.options.dropzoneUpload = {
		withCredentials: false,
		success: function (file, response) {
			console.info("success", response);
			showOn("#upload-response", JSON.stringify(response));
		}
	};
	Dropzone.discover();
}