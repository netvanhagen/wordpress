jQuery(function ($) {
  if (typeof ARS_ADMIN === "undefined") return;

  const ajaxUrl = ARS_ADMIN.ajaxUrl || (typeof ajaxurl !== "undefined" ? ajaxurl : "");
  if (!ajaxUrl) return;

  function setProgress(pct, label) {
    const safePct = Math.max(0, Math.min(100, parseInt(pct || 0, 10)));
    $("#ars-progress-container").show();
    $("#ars-progress-bar")
      .css("width", safePct + "%")
      .text(label || safePct + "%");
  }

  function setBusy(isBusy) {
    $("#ars-start-index").prop("disabled", isBusy);
    $("#ars-clear-index").prop("disabled", isBusy);
  }

  function runIndex(offset) {
    offset = offset || 0;

    return $.ajax({
      url: ajaxUrl,
      type: "POST",
      dataType: "json",
      data: {
        action: "ars_index_batch",
        offset: offset,
        nonce: ARS_ADMIN.nonceAjax,
      },
    }).then(function (res) {
      if (!res || !res.success) {
        const msg = res && res.data ? res.data : "Onbekende fout";
        alert("Indexeren mislukt: " + msg);
        setBusy(false);
        return $.Deferred().reject();
      }

      const total = parseInt(res.data.total || 0, 10);
      const imported = parseInt(res.data.imported || 0, 10);
      const done = !!res.data.done;

      if (done) {
        setProgress(100, "100% voltooid");
        setTimeout(function () {
          location.reload();
        }, 800);
        return;
      }

      const newOffset = offset + imported;
      const percent = total > 0 ? Math.round((newOffset / total) * 100) : 0;
      setProgress(percent, percent + "%");
      return runIndex(newOffset);
    }).fail(function (xhr) {
      let msg = "Serverfout tijdens indexeren.";
      if (xhr && xhr.responseText) msg += " " + xhr.responseText;
      alert(msg);
      setBusy(false);
    });
  }

  $("#ars-start-index").on("click", function () {
    setBusy(true);
    setProgress(0, "0%");
    runIndex(0);
  });

  $("#ars-clear-index").on("click", function () {
    if (!confirm(ARS_ADMIN.confirmClear || "Index wissen en herindexeren?")) return;

    setBusy(true);
    setProgress(0, "Index wissen...");

    $.ajax({
      url: ajaxUrl,
      type: "POST",
      dataType: "json",
      data: {
        action: "ars_clear_index",
        nonce: ARS_ADMIN.nonceAjax,
      },
    })
      .done(function (res) {
        if (!res || !res.success) {
          const msg = res && res.data ? res.data : "Onbekende fout";
          alert("Index wissen mislukt: " + msg);
          setBusy(false);
          return;
        }
        setProgress(0, "Herindexeren...");
        runIndex(0);
      })
      .fail(function (xhr) {
        let msg = "Serverfout tijdens index wissen.";
        if (xhr && xhr.responseText) msg += " " + xhr.responseText;
        alert(msg);
        setBusy(false);
      });
  });

  $("#ars-add-pinned-form").on("submit", function (e) {
    e.preventDefault();

    $.ajax({
      url: ajaxUrl,
      type: "POST",
      dataType: "json",
      data: {
        action: "ars_save_pinned",
        term: $("#p_term").val(),
        post_id: $("#p_id").val(),
        pos: $("#p_pos").val(),
        nonce: ARS_ADMIN.noncePinned,
      },
    }).done(function () {
      location.reload();
    }).fail(function () {
      alert("Pinned opslaan mislukt.");
    });
  });

  $(".ars-delete-pinned").on("click", function () {
    if (!confirm(ARS_ADMIN.confirmDel || "Verwijderen?")) return;

    $.ajax({
      url: ajaxUrl,
      type: "POST",
      dataType: "json",
      data: {
        action: "ars_delete_pinned",
        id: $(this).data("id"),
        nonce: ARS_ADMIN.noncePinned,
      },
    }).done(function () {
      location.reload();
    }).fail(function () {
      alert("Pinned verwijderen mislukt.");
    });
  });

  $("#ars-wipe-logs").on("click", function () {
    if (!confirm(ARS_ADMIN.confirmLogs || "Logs wissen?")) return;

    $.ajax({
      url: ajaxUrl,
      type: "POST",
      dataType: "json",
      data: {
        action: "ars_clear_logs",
        nonce: ARS_ADMIN.nonceLog,
      },
    }).done(function () {
      location.reload();
    }).fail(function () {
      alert("Logs wissen mislukt.");
    });
  });

  $("#ars-prune-logs").on("click", function () {
    $.ajax({
      url: ajaxUrl,
      type: "POST",
      dataType: "json",
      data: {
        action: "ars_prune_logs",
        nonce: ARS_ADMIN.nonceLog,
      },
    }).done(function () {
      location.reload();
    }).fail(function () {
      alert("Logs opschonen mislukt.");
    });
  });
});