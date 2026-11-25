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
	const key = $("#public_key").val();
	callFeature(mediaFeatureName, "Preview", "GET", { key, id, size })
		.then(rs => addTo("#media-image-viewer", rs));
}

function previewSize(el) {
	let data = getFormDataFromElement(el);
	let id = data['id'];
	let size = data['w'] + 'x' + data['h'];
	openPreview(id, size);
}

function removeImage(id) {
	if(!confirm("delete image ID# " + id + "?")) return;
	callFeature(mediaFeatureName, "Remove", "POST", { id })
		.then((rs) => {
			refreshMedias();
			window.setTimeout(() => addTo("#media-image-viewer", rs), 1000);
		});
}

function loadDropzone() {
	Dropzone.options.dropzoneUpload = {
		withCredentials: false,
		success: function (file, response) {
			console.info("success", response);
			showOn("#upload-response", JSON.stringify(response));
		},
		// upload errors are HTTP errors (4xx/5xx) since 3.7.0, so they never reach `success`.
		// A listener, not the `error` option: Dropzone's own handler still marks the file.
		init: function () {
			this.on("error", function (file, response) {
				console.error("upload failed", response);
				showOn("#upload-response", typeof response === "string" ? response : JSON.stringify(response));
			});
		}
	};
	Dropzone.discover();
}

function uploadUrl(el) {
	let data = getFormDataFromElement(el);
	console.info("uploading from url", data);
	let api = data['endpoint'];
	let url = data['image_url'];
	ajax("POST", api, { url })
	.then(rs => showOn("#upload-response", JSON.stringify(rs)));
	// callApi("Images", "Upload", { 
	// 	key: getSelectedApiKey(),
	// 	url: data['image_url'],
	// })
	// .then(rs => showOn("#upload-response", JSON.stringify(rs)));
}