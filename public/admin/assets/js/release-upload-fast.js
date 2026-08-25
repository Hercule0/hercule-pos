(function () {
  "use strict";

  if (!/\/public\/admin\/releases\.php$/.test(window.location.pathname)) return;

  var form = document.getElementById("release-upload-form");
  if (!form) return;

  var fileInput = document.getElementById("release-bundle-file");
  var progress = document.getElementById("upload-progress");
  var bar = document.getElementById("progress-bar");
  var text = document.getElementById("progress-text");
  var detail = document.getElementById("progress-detail");
  var button = document.getElementById("upload-btn");
  var help = document.getElementById("upload-help");
  var csrf = form.querySelector('input[name="csrf_token"]');
  var mode = document.getElementById("target-mode");
  var endpoint = "/public/admin/release_upload_fast.php";
  var activeXhrs = new Set();
  var running = false;

  if (help) {
    help.textContent = "Fast upload uses several small parts in parallel. If Azure rejects a part size, Hercule automatically switches to a smaller size and continues with a new secure upload session.";
  }

  function mb(value) {
    return (Number(value || 0) / 1048576).toFixed(1) + " MB";
  }

  function sleep(ms) {
    return new Promise(function (resolve) { setTimeout(resolve, ms); });
  }

  function setProgress(percent, label, sub) {
    if (progress) progress.classList.add("show");
    if (bar) bar.style.width = Math.max(0, Math.min(100, percent || 0)) + "%";
    if (text) text.textContent = label || "";
    if (detail) detail.textContent = sub || "";
  }

  function abortActive() {
    activeXhrs.forEach(function (xhr) {
      try { xhr.abort(); } catch (_) {}
    });
    activeXhrs.clear();
  }

  function parseResponse(xhr) {
    var data = null;
    try { data = JSON.parse(xhr.responseText || "{}"); } catch (_) {}
    if (xhr.status >= 200 && xhr.status < 300 && data && data.ok) return data;
    var message = data && data.message ? data.message : (xhr.status === 413 ? "Server rejected this part size (HTTP 413)." : "Server returned HTTP " + xhr.status + ".");
    var error = new Error(message);
    error.status = xhr.status;
    error.data = data;
    throw error;
  }

  function sendForm(fd, timeout) {
    return new Promise(function (resolve, reject) {
      var xhr = new XMLHttpRequest();
      xhr.open("POST", endpoint, true);
      xhr.timeout = timeout || 60000;
      xhr.setRequestHeader("X-Requested-With", "XMLHttpRequest");
      activeXhrs.add(xhr);
      xhr.onload = function () {
        activeXhrs.delete(xhr);
        try { resolve(parseResponse(xhr)); } catch (error) { reject(error); }
      };
      xhr.onerror = function () { activeXhrs.delete(xhr); reject(new Error("Network connection was interrupted.")); };
      xhr.ontimeout = function () { activeXhrs.delete(xhr); reject(new Error("The server did not answer in time.")); };
      xhr.onabort = function () { activeXhrs.delete(xhr); reject(new Error("Upload request was cancelled.")); };
      xhr.send(fd);
    });
  }

  function sendRawChunk(uploadId, index, blob, onProgress) {
    return new Promise(function (resolve, reject) {
      var xhr = new XMLHttpRequest();
      var url = endpoint + "?action=chunk&upload_id=" + encodeURIComponent(uploadId) + "&chunk_index=" + encodeURIComponent(String(index));
      xhr.open("POST", url, true);
      xhr.timeout = 60000;
      xhr.setRequestHeader("X-Requested-With", "XMLHttpRequest");
      xhr.setRequestHeader("X-CSRF-Token", csrf ? csrf.value : "");
      xhr.setRequestHeader("Content-Type", "application/octet-stream");
      if (xhr.upload && onProgress) {
        xhr.upload.onprogress = function (event) {
          if (event.lengthComputable) onProgress(event.loaded, event.total);
        };
      }
      activeXhrs.add(xhr);
      xhr.onload = function () {
        activeXhrs.delete(xhr);
        try { resolve(parseResponse(xhr)); } catch (error) { reject(error); }
      };
      xhr.onerror = function () { activeXhrs.delete(xhr); reject(new Error("Network connection was interrupted while sending a part.")); };
      xhr.ontimeout = function () { activeXhrs.delete(xhr); reject(new Error("An upload part timed out.")); };
      xhr.onabort = function () { activeXhrs.delete(xhr); reject(new Error("Upload part was cancelled.")); };
      xhr.send(blob);
    });
  }

  async function bestEffortCancel(uploadId) {
    if (!uploadId) return;
    try {
      var fd = new FormData();
      fd.append("csrf_token", csrf ? csrf.value : "");
      fd.append("action", "cancel");
      fd.append("upload_id", uploadId);
      await sendForm(fd, 15000);
    } catch (_) {}
  }

  async function initSession(file, requestedChunkSize) {
    var fd = new FormData();
    fd.append("csrf_token", csrf ? csrf.value : "");
    fd.append("action", "init");
    fd.append("bundle_name", file.name);
    fd.append("bundle_size", String(file.size));
    fd.append("requested_chunk_size", String(requestedChunkSize));
    return sendForm(fd, 30000);
  }

  async function sendChunkWithRetry(session, file, index, progressState) {
    var chunkSize = Number(session.chunk_size);
    var start = index * chunkSize;
    var end = Math.min(file.size, start + chunkSize);
    var blob = file.slice(start, end);
    var lastError = null;

    for (var attempt = 1; attempt <= 4; attempt++) {
      try {
        progressState.inflight[index] = 0;
        var result = await sendRawChunk(session.upload_id, index, blob, function (loaded) {
          progressState.inflight[index] = Math.min(blob.size, loaded);
          renderAggregateProgress(progressState, file.size, session.total_chunks);
        });
        delete progressState.inflight[index];
        if (!progressState.completed[index]) {
          progressState.completed[index] = blob.size;
          progressState.completedBytes += blob.size;
        }
        renderAggregateProgress(progressState, file.size, session.total_chunks);
        return result;
      } catch (error) {
        delete progressState.inflight[index];
        lastError = error;
        if (error && error.status === 413) throw error;
        if (attempt === 4) break;
        setProgress((progressState.completedBytes / file.size) * 100, "Connection interrupted — retrying", "Part " + (index + 1) + " · attempt " + (attempt + 1) + " / 4");
        await sleep(350 * attempt);
      }
    }
    throw lastError || new Error("Upload part failed.");
  }

  function renderAggregateProgress(state, fileSize, totalChunks) {
    var inflightBytes = 0;
    Object.keys(state.inflight).forEach(function (key) { inflightBytes += Number(state.inflight[key] || 0); });
    var uploaded = Math.min(fileSize, state.completedBytes + inflightBytes);
    var pct = fileSize ? (uploaded / fileSize) * 100 : 0;
    var done = Object.keys(state.completed).length;
    setProgress(pct, "Uploading " + Math.floor(pct) + "%", done + " / " + totalChunks + " parts · " + mb(uploaded) + " / " + mb(fileSize));
  }

  async function uploadParallel(session, file) {
    var totalChunks = Number(session.total_chunks);
    var parallelism = Math.max(2, Math.min(8, Number(session.parallelism) || 6));
    var next = 0;
    var failed = null;
    var state = { completedBytes: 0, completed: {}, inflight: {} };

    async function worker() {
      while (true) {
        if (failed) return;
        var index = next++;
        if (index >= totalChunks) return;
        try {
          await sendChunkWithRetry(session, file, index, state);
        } catch (error) {
          failed = error;
          abortActive();
          throw error;
        }
      }
    }

    var workers = [];
    for (var i = 0; i < parallelism; i++) workers.push(worker());
    var settled = await Promise.allSettled(workers);
    if (failed) throw failed;
    for (var s = 0; s < settled.length; s++) {
      if (settled[s].status === "rejected") throw settled[s].reason;
    }
    setProgress(100, "Upload received", mb(file.size) + " · " + totalChunks + " parts");
  }

  async function finishSession(uploadId) {
    var lastError = null;
    for (var attempt = 1; attempt <= 3; attempt++) {
      try {
        var fd = new FormData(form);
        fd.delete("bundle");
        fd.set("action", "finish");
        fd.set("upload_id", uploadId);
        return await sendForm(fd, 240000);
      } catch (error) {
        lastError = error;
        if (error.status && error.status < 500 && error.status !== 409) throw error;
        if (attempt === 3) break;
        setProgress(100, "Server is verifying the bundle…", "Waiting for ZIP and hash verification · retry " + (attempt + 1) + " / 3");
        await sleep(1200 * attempt);
      }
    }
    throw lastError || new Error("Could not finish release verification.");
  }

  async function runUpload(file, requestedChunkSize) {
    setProgress(0, "Preparing fast secure upload…", mb(file.size));
    var session = await initSession(file, requestedChunkSize);
    try {
      await uploadParallel(session, file);
      setProgress(100, "Upload received. Verifying bundle…", "Checking ZIP, manifest, SHA-256 and SHA-512");
      return await finishSession(session.upload_id);
    } catch (error) {
      abortActive();
      await bestEffortCancel(session.upload_id);
      throw error;
    }
  }

  async function handleSubmit(event) {
    if (running) return;
    if (!form.reportValidity()) return;
    var file = fileInput && fileInput.files && fileInput.files[0];
    if (!file) { alert("Choose an update bundle ZIP first."); return; }
    if (file.size <= 0) { alert("The selected update bundle is empty."); return; }
    if (mode && mode.value === "licenses" && !form.querySelector('input[name="target_license_ids[]"]:checked')) {
      alert("Select at least one target license.");
      return;
    }

    running = true;
    button.disabled = true;
    fileInput.disabled = true;
    var chunkSize = 524288;

    try {
      var result;
      try {
        result = await runUpload(file, chunkSize);
      } catch (error) {
        if (error && error.status === 413) {
          chunkSize = 262144;
          setProgress(0, "Azure rejected 512 KB parts — switching automatically", "Retrying with 256 KB parts in parallel");
          await sleep(500);
          result = await runUpload(file, chunkSize);
        } else {
          throw error;
        }
      }
      setProgress(100, result.message || "Release verified successfully.", "Refreshing release history…");
      setTimeout(function () { window.location.reload(); }, 700);
    } catch (error) {
      console.error("Hercule fast release upload failed", error);
      button.disabled = false;
      fileInput.disabled = false;
      setProgress(0, "Upload failed", error.message || String(error));
      alert(error.message || "Update bundle upload failed.");
    } finally {
      running = false;
    }
  }

  // Capture on window fires before the legacy form listener. This safely replaces
  // the old sequential uploader without depending on script registration order.
  window.addEventListener("submit", function (event) {
    if (event.target !== form) return;
    event.preventDefault();
    event.stopImmediatePropagation();
    handleSubmit(event);
  }, true);
})();
