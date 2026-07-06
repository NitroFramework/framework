<div id="ndb-bar"<?= $oob ?>>
    <div id="ndb-resize-handle"></div>
    <div id="ndb-tabbar">
        <?= $tabButtons ?>
        <button id="ndb-toggle-btn" title="Toggle panel" onclick="ndbToggle()">▲</button>
        <button id="ndb-close-btn" title="Close" onclick="document.getElementById('ndb-bar').style.display='none'">✕</button>
    </div>
    <div id="ndb-panels">
        <?= $panelContents ?>
    </div>
</div>