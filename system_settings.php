<!doctype html>
<html lang="en">
<head>
<meta charset="UTF-8" />
<meta name="viewport" content="width=device-width, initial-scale=1.0" />
<title>System & Period Settings</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&family=IBM+Plex+Mono:wght@400;500&display=swap" rel="stylesheet">
<style>
  :root{
    --bg:          #0A1628;
    --surface:     #121F38;
    --surface-2:   #16233F;
    --surface-3:   #1B294A;
    --border:      rgba(255,255,255,0.08);
    --border-strong: rgba(255,255,255,0.16);
    --text:        #F5F7FB;
    --text-muted:  #8291B3;
    --text-faint:  #5C6B8A;
    --blue:        #3B6FE0;
    --blue-hover:  #4C7CEA;
    --blue-soft:   rgba(59,111,224,0.16);
    --green:       #34D399;
    --green-soft:  rgba(52,211,153,0.14);
    --red:         #F87171;
    --red-soft:    rgba(248,113,113,0.14);
    --amber:       #FBBF24;
    --amber-soft:  rgba(251,191,36,0.14);
    --radius:      14px;
    font-size: 16px;
  }

  *{ box-sizing: border-box; }
  html,body{ margin:0; padding:0; }

  body{
    background: var(--bg);
    color: var(--text);
    font-family: 'Inter', sans-serif;
    -webkit-font-smoothing: antialiased;
    min-height: 100vh;
  }

  .shell{
    max-width: 1000px;
    margin: 0 auto;
    padding: 40px 28px 90px;
  }

  /* ---------- header ---------- */
  .top{
    display:flex;
    align-items:flex-start;
    justify-content:space-between;
    gap: 20px;
    margin-bottom: 22px;
  }
  .page-title{
    font-size: 28px;
    font-weight: 800;
    letter-spacing: -0.01em;
    margin: 0 0 6px;
  }
  .page-sub{
    color: var(--text-muted);
    font-size: 14px;
    margin: 0;
  }
  .btn{
    font-family:'Inter', sans-serif;
    font-size: 14px;
    font-weight: 600;
    padding: 11px 18px;
    border-radius: 10px;
    border: 1px solid var(--border);
    background: var(--surface-2);
    color: var(--text);
    cursor: pointer;
    transition: background .15s ease, border-color .15s ease, transform .1s ease;
    display:inline-flex; align-items:center; gap:8px;
    white-space: nowrap;
  }
  .btn:hover{ border-color: var(--border-strong); }
  .btn:active{ transform: translateY(1px); }
  .btn-primary{
    background: var(--blue);
    border-color: var(--blue);
    color: #fff;
  }
  .btn-primary:hover{ background: var(--blue-hover); }

  /* ---------- tabs ---------- */
  .tabs{
    display: inline-flex;
    gap: 4px;
    background: var(--surface-2);
    border: 1px solid var(--border);
    border-radius: 12px;
    padding: 4px;
    margin-bottom: 26px;
  }
  .tab{
    font-size: 13.5px;
    font-weight: 600;
    color: var(--text-muted);
    padding: 9px 16px;
    border-radius: 8px;
    cursor: default;
    user-select: none;
    transition: background .15s ease, color .15s ease;
  }
  .tab.active{
    color: #fff;
    background: var(--blue);
  }

  /* ---------- stat row (snapshot) ---------- */
  .stat-row{
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 16px;
    margin-bottom: 26px;
  }
  @media (max-width: 720px){ .stat-row{ grid-template-columns: 1fr; } }
  .stat-card{
    background: var(--surface);
    border: 1px solid var(--border);
    border-radius: var(--radius);
    padding: 20px 22px;
  }
  .stat-label{
    font-size: 11.5px;
    font-weight: 600;
    letter-spacing: 0.07em;
    text-transform: uppercase;
    color: var(--text-muted);
    margin-bottom: 10px;
  }
  .stat-value{
    font-size: 26px;
    font-weight: 700;
    color: var(--text);
    line-height: 1.15;
  }
  .stat-value.small{ font-size: 18px; }
  .stat-value.green{ color: var(--green); }
  .stat-value.red{ color: var(--red); }
  .stat-value.amber{ color: var(--amber); }
  .stat-note{
    font-family: 'IBM Plex Mono', monospace;
    font-size: 11.5px;
    color: var(--text-faint);
    margin-top: 8px;
  }

  /* ---------- sections ---------- */
  .section{
    background: var(--surface);
    border: 1px solid var(--border);
    border-radius: var(--radius);
    margin-bottom: 18px;
    overflow: hidden;
  }
  .section-head{
    display:flex; align-items:baseline; justify-content:space-between;
    gap: 12px;
    padding: 18px 22px;
    background: var(--surface-2);
    border-bottom: 1px solid var(--border);
  }
  .section-eyebrow{
    font-size: 11.5px;
    font-weight: 700;
    letter-spacing: 0.08em;
    color: #9DB4E8;
    text-transform: uppercase;
  }
  .section-hint{
    font-size: 12.5px;
    color: var(--text-faint);
  }
  .section-body{
    padding: 20px 22px 22px;
    display: grid;
    gap: 16px;
  }

  .field-row{
    display:grid;
    grid-template-columns: 230px 1fr;
    align-items: center;
    gap: 14px;
  }
  @media (max-width: 620px){
    .field-row{ grid-template-columns: 1fr; }
  }
  .field-label{
    font-size: 14px;
    font-weight: 600;
    color: var(--text);
  }
  .field-help{
    display:block;
    font-size: 12px;
    font-weight: 400;
    color: var(--text-faint);
    margin-top: 3px;
  }

  input[type="text"], input[type="datetime-local"], select{
    width: 100%;
    font-family: 'Inter', sans-serif;
    font-size: 14px;
    color: var(--text);
    background: var(--surface-2);
    border: 1px solid var(--border);
    border-radius: 9px;
    padding: 10px 12px;
    transition: border-color .15s ease, box-shadow .15s ease;
  }
  input::placeholder{ color: var(--text-faint); }
  input[type="datetime-local"]{ color-scheme: dark; }
  input:focus, select:focus{
    outline: none;
    border-color: var(--blue);
    box-shadow: 0 0 0 3px var(--blue-soft);
  }
  select:disabled, input:disabled{
    opacity: 0.5;
    cursor: not-allowed;
  }
  select{ appearance: none; -webkit-appearance:none;
    background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='10' height='6' viewBox='0 0 10 6'%3E%3Cpath d='M1 1l4 4 4-4' stroke='%238291B3' stroke-width='1.5' fill='none' stroke-linecap='round' stroke-linejoin='round'/%3E%3C/svg%3E");
    background-repeat: no-repeat;
    background-position: right 12px center;
    padding-right: 32px;
  }

  /* toggle switch */
  .switch{
    position: relative;
    width: 42px; height: 24px;
    flex: none;
    display:inline-block;
  }
  .switch input{ display:none; }
  .switch .track{
    position:absolute; inset:0;
    background: var(--surface-3);
    border: 1px solid var(--border);
    border-radius: 999px;
    transition: background .15s ease;
    cursor:pointer;
  }
  .switch .thumb{
    position:absolute; top:2px; left:2px;
    width:18px; height:18px;
    background:#fff;
    border-radius:50%;
    box-shadow: 0 1px 3px rgba(0,0,0,0.4);
    transition: transform .15s ease;
  }
  .switch input:checked + .track{ background: var(--blue); border-color: var(--blue); }
  .switch input:checked + .track .thumb{ transform: translateX(18px); }

  .toggle-row{
    display:flex; align-items:center; justify-content:space-between;
    gap: 14px;
    padding: 12px 0;
    border-bottom: 1px solid var(--border);
  }
  .toggle-row:last-child{ border-bottom:none; padding-bottom:0; }
  .toggle-copy .field-label{ margin:0; }

  /* radio control mode */
  .control-modes{
    display:grid;
    gap: 8px;
  }
  .control-option{
    display:flex; align-items:flex-start; gap: 10px;
    border: 1px solid var(--border);
    background: var(--surface-2);
    border-radius: 10px;
    padding: 12px 14px;
    cursor: pointer;
    transition: border-color .15s ease, background .15s ease;
  }
  .control-option:hover{ border-color: var(--border-strong); }
  .control-option input{ margin-top: 3px; accent-color: var(--blue); }
  .control-option.selected{ border-color: var(--blue); background: var(--blue-soft); }
  .control-option .co-title{ font-size: 14px; font-weight: 600; color: var(--text); }
  .control-option .co-desc{ font-size: 12px; color: var(--text-faint); margin-top:1px; }

  .maint-banner{
    display:none;
    align-items:center; gap:10px;
    background: var(--amber-soft);
    color: var(--amber);
    border: 1px solid rgba(251,191,36,0.35);
    border-radius: 10px;
    padding: 12px 16px;
    font-size: 13px;
    margin-bottom: 18px;
  }
  .maint-banner.show{ display:flex; }

  /* footer actions */
  .actions{
    display:flex; justify-content:flex-end; gap: 10px;
    padding-top: 8px;
  }

  .toast{
    position: fixed;
    bottom: 24px; left: 50%;
    transform: translateX(-50%) translateY(20px);
    background: var(--surface-3);
    border: 1px solid var(--border-strong);
    color: var(--text);
    font-size: 13px;
    padding: 12px 20px;
    border-radius: 10px;
    box-shadow: 0 8px 24px rgba(0,0,0,0.4);
    opacity: 0;
    pointer-events: none;
    transition: opacity .2s ease, transform .2s ease;
    display:flex; align-items:center; gap:8px;
  }
  .toast.show{ opacity: 1; transform: translateX(-50%) translateY(0); }
  .toast .dot{ width:7px; height:7px; border-radius:50%; background: var(--green); }

  ::-webkit-scrollbar{ width: 10px; }
  ::-webkit-scrollbar-thumb{ background: var(--surface-3); border-radius: 999px; }
  ::-webkit-scrollbar-track{ background: transparent; }
</style>
</head>
<body>
<div class="shell">

  <div class="top">
    <div>
      <p class="page-title">System &amp; Period Settings</p>
      <p class="page-sub">Configure academic terms and evaluation windows for College/University, JHS, and SHS.</p>
    </div>
    <button class="btn btn-primary" id="saveBtnTop">Save System Settings</button>
  </div>

  <div class="tabs">
    <div class="tab">Profile</div>
    <div class="tab">Security</div>
    <div class="tab active">System &amp; Period</div>
    <div class="tab">Appearance</div>
  </div>

  <!-- Live snapshot, styled like the stat cards -->
  <div class="stat-row">
    <div class="stat-card">
      <div class="stat-label">Academic Period</div>
      <div class="stat-value small" id="statAcademic">2026–2027 · College / University</div>
      <div class="stat-note" id="statTerm">1st Semester</div>
    </div>
    <div class="stat-card">
      <div class="stat-label">Evaluation Status</div>
      <div class="stat-value" id="statStatus">—</div>
      <div class="stat-note" id="statWindow">—</div>
    </div>
    <div class="stat-card">
      <div class="stat-label">Control Mode</div>
      <div class="stat-value small" id="statMode">Follow Schedule</div>
      <div class="stat-note" id="statTz">Asia/Manila</div>
    </div>
  </div>

  <div class="maint-banner" id="maintBanner">
    <strong>Maintenance mode is on.</strong>&nbsp;Only administrators can access the system right now.
  </div>

  <!-- ACADEMIC CONFIGURATION -->
  <div class="section">
    <div class="section-head">
      <span class="section-eyebrow">Academic Configuration</span>
      <span class="section-hint">Defines the current period, not the evaluation window</span>
    </div>
    <div class="section-body">
      <div class="field-row">
        <label class="field-label" for="academicYear">Academic Year</label>
        <input type="text" id="academicYear" value="2026–2027" />
      </div>
      <div class="field-row">
        <label class="field-label" for="academicStructure">Academic Structure</label>
        <select id="academicStructure">
          <option value="college">College / University</option>
          <option value="jhs">Junior High School</option>
          <option value="shs">Senior High School</option>
        </select>
      </div>
      <div class="field-row">
        <label class="field-label" for="academicTerm">
          Academic Term
          <span class="field-help">Choices update automatically with Academic Structure</span>
        </label>
        <select id="academicTerm"></select>
      </div>
    </div>
  </div>

  <!-- EVALUATION SCHEDULE -->
  <div class="section">
    <div class="section-head">
      <span class="section-eyebrow">Evaluation Schedule</span>
      <span class="section-hint">Independent from the academic period above</span>
    </div>
    <div class="section-body">
      <div class="field-row">
        <label class="field-label" for="evalOpens">Evaluation Opens</label>
        <input type="datetime-local" id="evalOpens" value="2026-10-15T08:00" />
      </div>
      <div class="field-row">
        <label class="field-label" for="evalCloses">Evaluation Closes</label>
        <input type="datetime-local" id="evalCloses" value="2026-10-30T23:59" />
      </div>
      <div class="field-row">
        <label class="field-label" for="timeZone">Time Zone</label>
        <select id="timeZone">
          <option value="Asia/Manila" selected>Asia/Manila</option>
          <option value="UTC">UTC</option>
          <option value="Asia/Singapore">Asia/Singapore</option>
          <option value="America/Los_Angeles">America/Los_Angeles</option>
        </select>
      </div>
      <div class="toggle-row">
        <div class="toggle-copy">
          <div class="field-label">Automatic Schedule</div>
          <span class="field-help">Open and close submissions automatically at the times above</span>
        </div>
        <label class="switch">
          <input type="checkbox" id="autoSchedule" checked>
          <span class="track"><span class="thumb"></span></span>
        </label>
      </div>
    </div>
  </div>

  <!-- EVALUATION ACCESS -->
  <div class="section">
    <div class="section-head">
      <span class="section-eyebrow">Evaluation Access</span>
      <span class="section-hint">Current status and manual override</span>
    </div>
    <div class="section-body">
      <div class="control-modes" id="controlModes">
        <label class="control-option" data-mode="schedule">
          <input type="radio" name="controlMode" value="schedule" checked>
          <div>
            <div class="co-title">Follow Schedule</div>
            <div class="co-desc">Status is determined automatically from the evaluation window</div>
          </div>
        </label>
        <label class="control-option" data-mode="open">
          <input type="radio" name="controlMode" value="open">
          <div>
            <div class="co-title">Force Open</div>
            <div class="co-desc">Allow submissions regardless of the scheduled period</div>
          </div>
        </label>
        <label class="control-option" data-mode="closed">
          <input type="radio" name="controlMode" value="closed">
          <div>
            <div class="co-title">Force Closed</div>
            <div class="co-desc">Block submissions regardless of the scheduled period</div>
          </div>
        </label>
      </div>
    </div>
  </div>

  <!-- EVALUATION RULES -->
  <div class="section">
    <div class="section-head">
      <span class="section-eyebrow">Evaluation Rules</span>
      <span class="section-hint">Submission and locking behavior</span>
    </div>
    <div class="section-body" id="rulesBody" style="gap:0;">
      <!-- populated by JS -->
    </div>
  </div>

  <!-- MAINTENANCE -->
  <div class="section">
    <div class="section-head">
      <span class="section-eyebrow">Maintenance</span>
      <span class="section-hint">System-wide, separate from evaluation access</span>
    </div>
    <div class="section-body">
      <div class="toggle-row" style="border-bottom:none;">
        <div class="toggle-copy">
          <div class="field-label">Maintenance Mode</div>
          <span class="field-help">Locks access for non-administrators; administrators keep access</span>
        </div>
        <label class="switch">
          <input type="checkbox" id="maintenanceMode">
          <span class="track"><span class="thumb"></span></span>
        </label>
      </div>
    </div>
  </div>

  <div class="actions">
    <button class="btn" id="cancelBtn">Cancel</button>
    <button class="btn btn-primary" id="saveBtn">Save System Settings</button>
  </div>

</div>

<div class="toast" id="toast"><span class="dot"></span><span id="toastText">Saved</span></div>

<script>
  const TERM_OPTIONS = {
    college: ['1st Semester', '2nd Semester', 'Summer'],
    jhs: ['School Year'],
    shs: ['School Year']
  };
  const STRUCTURE_LABEL = {
    college: 'College / University',
    jhs: 'Junior High School',
    shs: 'Senior High School'
  };

  const RULES = [
    { id: 'onlyDuringPeriod', label: 'Allow submissions only during the evaluation period', help: 'Blocks submissions outside the configured window', def: true },
    { id: 'editAfterSubmit',  label: 'Allow students to edit after submission', help: 'Off by default to protect evaluation integrity', def: false },
    { id: 'oneSubmission',    label: 'Allow only one submission', help: 'Prevents duplicate or repeated submissions', def: true },
    { id: 'requireAll',       label: 'Require all required evaluations before submission', help: 'Blocks partial submissions', def: true },
    { id: 'autoLock',         label: 'Automatically lock an evaluation after submission', help: 'Prevents further changes once submitted', def: true },
    { id: 'countdown',        label: 'Show a countdown before the evaluation closes', help: 'Warns students the window is ending', def: true },
    { id: 'preventLate',      label: 'Prevent submissions after the closing time', help: 'Hard stop once the window closes', def: true },
  ];

  const els = {
    structure: document.getElementById('academicStructure'),
    term: document.getElementById('academicTerm'),
    year: document.getElementById('academicYear'),
    opens: document.getElementById('evalOpens'),
    closes: document.getElementById('evalCloses'),
    tz: document.getElementById('timeZone'),
    auto: document.getElementById('autoSchedule'),
    maintenance: document.getElementById('maintenanceMode'),
    maintBanner: document.getElementById('maintBanner'),
    statAcademic: document.getElementById('statAcademic'),
    statTerm: document.getElementById('statTerm'),
    statStatus: document.getElementById('statStatus'),
    statWindow: document.getElementById('statWindow'),
    statMode: document.getElementById('statMode'),
    statTz: document.getElementById('statTz'),
    rulesBody: document.getElementById('rulesBody'),
    controlModes: document.getElementById('controlModes'),
    toast: document.getElementById('toast'),
    toastText: document.getElementById('toastText'),
  };

  const MODE_LABEL = { schedule: 'Follow Schedule', open: 'Force Open', closed: 'Force Closed' };

  function populateTerms(selected) {
    const structure = els.structure.value;
    const options = TERM_OPTIONS[structure];
    els.term.innerHTML = '';
    options.forEach(opt => {
      const o = document.createElement('option');
      o.value = opt; o.textContent = opt;
      els.term.appendChild(o);
    });
    if (selected && options.includes(selected)) {
      els.term.value = selected;
    }
    els.term.disabled = options.length === 1;
  }

  function formatDateTime(value) {
    if (!value) return '—';
    const d = new Date(value);
    return d.toLocaleString('en-US', { month: 'short', day: 'numeric', hour: 'numeric', minute: '2-digit', hour12: true });
  }

  function getControlMode() {
    const checked = els.controlModes.querySelector('input[name="controlMode"]:checked');
    return checked ? checked.value : 'schedule';
  }

  function refreshControlOptionStyles() {
    els.controlModes.querySelectorAll('.control-option').forEach(opt => {
      const input = opt.querySelector('input');
      opt.classList.toggle('selected', input.checked);
    });
  }

  function computeStatus() {
    if (els.maintenance.checked) return { cls: '', color: 'text-muted', label: 'MAINTENANCE' };
    const mode = getControlMode();
    if (mode === 'open') return { color: 'amber', label: 'FORCED OPEN' };
    if (mode === 'closed') return { color: 'amber', label: 'FORCED CLOSED' };
    if (!els.auto.checked) return { color: '', label: 'CLOSED · MANUAL' };
    const now = new Date();
    const opens = new Date(els.opens.value);
    const closes = new Date(els.closes.value);
    if (isNaN(opens) || isNaN(closes)) return { color: '', label: 'NOT CONFIGURED' };
    if (now < opens) return { color: '', label: 'UPCOMING' };
    if (now > closes) return { color: 'red', label: 'CLOSED · ENDED' };
    return { color: 'green', label: 'OPEN' };
  }

  function render() {
    const structureLabel = STRUCTURE_LABEL[els.structure.value];
    els.statAcademic.textContent = `${els.year.value} · ${structureLabel}`;
    els.statTerm.textContent = els.term.value;
    els.statWindow.textContent = `${formatDateTime(els.opens.value)} – ${formatDateTime(els.closes.value)}`;
    els.statMode.textContent = MODE_LABEL[getControlMode()];
    els.statTz.textContent = els.tz.value;

    els.maintBanner.classList.toggle('show', els.maintenance.checked);
    refreshControlOptionStyles();

    const status = computeStatus();
    els.statStatus.textContent = status.label;
    els.statStatus.className = 'stat-value' + (status.color ? ' ' + status.color : '');
  }

  els.structure.addEventListener('change', () => { populateTerms(); render(); });
  els.term.addEventListener('change', render);
  els.year.addEventListener('input', render);
  els.opens.addEventListener('input', render);
  els.closes.addEventListener('input', render);
  els.tz.addEventListener('change', render);
  els.auto.addEventListener('change', render);
  els.maintenance.addEventListener('change', render);
  els.controlModes.addEventListener('change', render);

  RULES.forEach(rule => {
    const row = document.createElement('div');
    row.className = 'toggle-row';
    row.innerHTML = `
      <div class="toggle-copy">
        <div class="field-label">${rule.label}</div>
        <span class="field-help">${rule.help}</span>
      </div>
      <label class="switch">
        <input type="checkbox" id="rule_${rule.id}" ${rule.def ? 'checked' : ''}>
        <span class="track"><span class="thumb"></span></span>
      </label>
    `;
    els.rulesBody.appendChild(row);
  });

  populateTerms('1st Semester');
  render();

  function showToast(text) {
    els.toastText.textContent = text;
    els.toast.classList.add('show');
    setTimeout(() => els.toast.classList.remove('show'), 2200);
  }
  document.getElementById('saveBtn').addEventListener('click', () => showToast('System settings saved'));
  document.getElementById('saveBtnTop').addEventListener('click', () => showToast('System settings saved'));
  document.getElementById('cancelBtn').addEventListener('click', () => showToast('Changes discarded'));

  setInterval(render, 60000);
</script>
</body>
</html>