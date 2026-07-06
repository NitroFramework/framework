(function () {
  var panelOpen = true;
  var toggleBtn = document.getElementById("ndb-toggle-btn");
  var panels = document.getElementById("ndb-panels");

  function initToggle() {
    window.ndbToggle = function () {
      panelOpen = !panelOpen;
      panels.style.display = panelOpen ? "" : "none";
      toggleBtn.textContent = panelOpen ? "▲" : "▼";
    };
  }

  function initTabs() {
    window.ndbSwitchTab = function (id, el) {
      document.querySelectorAll(".ndb-tab").forEach(function (t) {
        t.classList.remove("ndb-tab-active");
      });
      el.classList.add("ndb-tab-active");
      document.querySelectorAll(".ndb-panel-content").forEach(function (p) {
        p.style.display = "none";
      });
      var target = document.getElementById("ndb-panel-" + id);
      if (target) target.style.display = "";
    };
  }

  function initResize() {
    var handle = document.getElementById("ndb-resize-handle");
    var startY, startHeight;

    handle.addEventListener("mousedown", function (e) {
      startY = e.clientY;
      startHeight = parseInt(panels.style.maxHeight) || panels.offsetHeight;
      document.addEventListener("mousemove", onDrag);
      document.addEventListener("mouseup", stopDrag);
      e.preventDefault();
    });

    function onDrag(e) {
      var delta = startY - e.clientY;
      var newHeight = Math.min(
        Math.max(startHeight + delta, 80),
        window.innerHeight * 0.9,
      );
      panels.style.maxHeight = newHeight + "px";
      panels.style.height = newHeight + "px";
    }

    function stopDrag() {
      document.removeEventListener("mousemove", onDrag);
      document.removeEventListener("mouseup", stopDrag);
      localStorage.setItem("ndb-panel-height", panels.style.maxHeight);
    }

    var saved = localStorage.getItem("ndb-panel-height");
    if (saved) panels.style.maxHeight = saved;
  }

  initToggle();
  initTabs();
  initResize();
})();
