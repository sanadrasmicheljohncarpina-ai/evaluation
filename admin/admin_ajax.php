/**
 * admin_ajax.js — Shared AJAX + UX utilities for PBI Admin sub-pages
 * Include this in every iframe page ONCE, at the bottom of <body>.
 */

/* ═══════════════════════════════════════════════════════════
   1. TOAST  (no reload required)
   ═══════════════════════════════════════════════════════════ */
window.PBI = window.PBI || {};

PBI.toast = function (msg, type = 'success') {
    const existing = document.getElementById('pbi-toast');
    if (existing) existing.remove();

    const el = document.createElement('div');
    el.id = 'pbi-toast';
    const isSuccess = type === 'success';
    el.style.cssText = `
        position:fixed;top:20px;right:20px;z-index:9999;
        background:${isSuccess ? 'rgba(34,197,94,.15)' : 'rgba(240,84,84,.15)'};
        border:1px solid ${isSuccess ? 'rgba(34,197,94,.35)' : 'rgba(240,84,84,.35)'};
        color:${isSuccess ? '#86efac' : '#fca5a5'};
        padding:13px 20px;border-radius:8px;font-size:13px;
        display:flex;align-items:center;gap:9px;
        box-shadow:0 6px 24px rgba(0,0,0,.4);
        font-family:'DM Sans',sans-serif;max-width:360px;
        animation:pbi-slideIn .28s ease;
    `;
    const icon = isSuccess ? 'fa-circle-check' : 'fa-circle-exclamation';
    el.innerHTML = `<i class="fa-solid ${icon}"></i><span>${msg}</span>`;
    document.body.appendChild(el);

    // inject keyframe once
    if (!document.getElementById('pbi-toast-style')) {
        const s = document.createElement('style');
        s.id = 'pbi-toast-style';
        s.textContent = `
            @keyframes pbi-slideIn{from{opacity:0;transform:translateX(18px)}to{opacity:1;transform:none}}
            @keyframes pbi-fadeOut{to{opacity:0;transform:translateX(18px);pointer-events:none}}
        `;
        document.head.appendChild(s);
    }

    setTimeout(() => {
        el.style.animation = 'pbi-fadeOut .35s ease forwards';
        setTimeout(() => el.remove(), 380);
    }, 3500);
};

/* ═══════════════════════════════════════════════════════════
   2. AJAX FETCH WRAPPER
   POST data (FormData or plain object) → JSON response
   ═══════════════════════════════════════════════════════════ */
PBI.post = async function (url, data) {
    let body;
    if (data instanceof FormData) {
        body = data;
    } else {
        body = new FormData();
        for (const [k, v] of Object.entries(data)) body.append(k, v);
    }
    const res  = await fetch(url, { method: 'POST', body });
    const json = await res.json();
    return json;          // { ok: bool, message: string, [extra fields] }
};

PBI.get = async function (url) {
    const res  = await fetch(url);
    const json = await res.json();
    return json;
};

/* ═══════════════════════════════════════════════════════════
   3. SPINNER  (shows inside a button while awaiting)
   ═══════════════════════════════════════════════════════════ */
PBI.spin = function (btn, on) {
    if (!btn) return;
    if (on) {
        btn._origHTML = btn.innerHTML;
        btn.disabled  = true;
        btn.innerHTML = `<i class="fa-solid fa-spinner fa-spin"></i> Processing…`;
    } else {
        btn.disabled  = false;
        btn.innerHTML = btn._origHTML || btn.innerHTML;
    }
};

/* ═══════════════════════════════════════════════════════════
   4. ROW HIGHLIGHT  (flash a table row after update)
   ═══════════════════════════════════════════════════════════ */
PBI.highlight = function (el, color = 'rgba(43,108,176,.22)') {
    if (!el) return;
    el.style.transition = 'background .1s';
    el.style.background = color;
    setTimeout(() => { el.style.background = ''; }, 900);
};

/* ═══════════════════════════════════════════════════════════
   5. CONFIRM DIALOG  (Promise-based, no blocking confirm())
   ═══════════════════════════════════════════════════════════ */
PBI.confirm = function (msg) {
    return new Promise(resolve => {
        const overlay = document.createElement('div');
        overlay.style.cssText = `
            position:fixed;inset:0;background:rgba(0,0,0,.72);z-index:10000;
            display:flex;align-items:center;justify-content:center;padding:20px;
            backdrop-filter:blur(4px);animation:pbi-slideIn .2s ease;
            font-family:'DM Sans',sans-serif;
        `;
        overlay.innerHTML = `
            <div style="background:#172A45;border:1px solid rgba(255,255,255,.09);
                        border-radius:14px;padding:28px 26px;max-width:400px;width:100%;
                        box-shadow:0 20px 60px rgba(0,0,0,.6);">
                <p style="font-size:14px;color:#E0E6F0;line-height:1.6;margin-bottom:22px;">${msg}</p>
                <div style="display:flex;gap:10px;">
                    <button id="pbi-no"
                        style="flex:1;padding:10px;background:#0F1F3D;border:1px solid rgba(255,255,255,.08);
                               border-radius:8px;color:#E0E6F0;font-size:14px;font-weight:600;cursor:pointer;">
                        Cancel
                    </button>
                    <button id="pbi-yes"
                        style="flex:1;padding:10px;background:#F05454;border:none;border-radius:8px;
                               color:#fff;font-size:14px;font-weight:600;cursor:pointer;">
                        Confirm
                    </button>
                </div>
            </div>`;
        document.body.appendChild(overlay);
        overlay.querySelector('#pbi-yes').onclick = () => { overlay.remove(); resolve(true);  };
        overlay.querySelector('#pbi-no').onclick  = () => { overlay.remove(); resolve(false); };
        overlay.onclick = e => { if (e.target === overlay) { overlay.remove(); resolve(false); } };
    });
};/**
 * admin_ajax.js — Shared AJAX + UX utilities for PBI Admin sub-pages
 * Include this in every iframe page ONCE, at the bottom of <body>.
 */

/* ═══════════════════════════════════════════════════════════
   1. TOAST  (no reload required)
   ═══════════════════════════════════════════════════════════ */
window.PBI = window.PBI || {};

PBI.toast = function (msg, type = 'success') {
    const existing = document.getElementById('pbi-toast');
    if (existing) existing.remove();

    const el = document.createElement('div');
    el.id = 'pbi-toast';
    const isSuccess = type === 'success';
    el.style.cssText = `
        position:fixed;top:20px;right:20px;z-index:9999;
        background:${isSuccess ? 'rgba(34,197,94,.15)' : 'rgba(240,84,84,.15)'};
        border:1px solid ${isSuccess ? 'rgba(34,197,94,.35)' : 'rgba(240,84,84,.35)'};
        color:${isSuccess ? '#86efac' : '#fca5a5'};
        padding:13px 20px;border-radius:8px;font-size:13px;
        display:flex;align-items:center;gap:9px;
        box-shadow:0 6px 24px rgba(0,0,0,.4);
        font-family:'DM Sans',sans-serif;max-width:360px;
        animation:pbi-slideIn .28s ease;
    `;
    const icon = isSuccess ? 'fa-circle-check' : 'fa-circle-exclamation';
    el.innerHTML = `<i class="fa-solid ${icon}"></i><span>${msg}</span>`;
    document.body.appendChild(el);

    // inject keyframe once
    if (!document.getElementById('pbi-toast-style')) {
        const s = document.createElement('style');
        s.id = 'pbi-toast-style';
        s.textContent = `
            @keyframes pbi-slideIn{from{opacity:0;transform:translateX(18px)}to{opacity:1;transform:none}}
            @keyframes pbi-fadeOut{to{opacity:0;transform:translateX(18px);pointer-events:none}}
        `;
        document.head.appendChild(s);
    }

    setTimeout(() => {
        el.style.animation = 'pbi-fadeOut .35s ease forwards';
        setTimeout(() => el.remove(), 380);
    }, 3500);
};

/* ═══════════════════════════════════════════════════════════
   2. AJAX FETCH WRAPPER
   POST data (FormData or plain object) → JSON response
   ═══════════════════════════════════════════════════════════ */
PBI.post = async function (url, data) {
    let body;
    if (data instanceof FormData) {
        body = data;
    } else {
        body = new FormData();
        for (const [k, v] of Object.entries(data)) body.append(k, v);
    }
    const res  = await fetch(url, { method: 'POST', body });
    const json = await res.json();
    return json;          // { ok: bool, message: string, [extra fields] }
};

PBI.get = async function (url) {
    const res  = await fetch(url);
    const json = await res.json();
    return json;
};

/* ═══════════════════════════════════════════════════════════
   3. SPINNER  (shows inside a button while awaiting)
   ═══════════════════════════════════════════════════════════ */
PBI.spin = function (btn, on) {
    if (!btn) return;
    if (on) {
        btn._origHTML = btn.innerHTML;
        btn.disabled  = true;
        btn.innerHTML = `<i class="fa-solid fa-spinner fa-spin"></i> Processing…`;
    } else {
        btn.disabled  = false;
        btn.innerHTML = btn._origHTML || btn.innerHTML;
    }
};

/* ═══════════════════════════════════════════════════════════
   4. ROW HIGHLIGHT  (flash a table row after update)
   ═══════════════════════════════════════════════════════════ */
PBI.highlight = function (el, color = 'rgba(43,108,176,.22)') {
    if (!el) return;
    el.style.transition = 'background .1s';
    el.style.background = color;
    setTimeout(() => { el.style.background = ''; }, 900);
};

/* ═══════════════════════════════════════════════════════════
   5. CONFIRM DIALOG  (Promise-based, no blocking confirm())
   ═══════════════════════════════════════════════════════════ */
PBI.confirm = function (msg) {
    return new Promise(resolve => {
        const overlay = document.createElement('div');
        overlay.style.cssText = `
            position:fixed;inset:0;background:rgba(0,0,0,.72);z-index:10000;
            display:flex;align-items:center;justify-content:center;padding:20px;
            backdrop-filter:blur(4px);animation:pbi-slideIn .2s ease;
            font-family:'DM Sans',sans-serif;
        `;
        overlay.innerHTML = `
            <div style="background:#172A45;border:1px solid rgba(255,255,255,.09);
                        border-radius:14px;padding:28px 26px;max-width:400px;width:100%;
                        box-shadow:0 20px 60px rgba(0,0,0,.6);">
                <p style="font-size:14px;color:#E0E6F0;line-height:1.6;margin-bottom:22px;">${msg}</p>
                <div style="display:flex;gap:10px;">
                    <button id="pbi-no"
                        style="flex:1;padding:10px;background:#0F1F3D;border:1px solid rgba(255,255,255,.08);
                               border-radius:8px;color:#E0E6F0;font-size:14px;font-weight:600;cursor:pointer;">
                        Cancel
                    </button>
                    <button id="pbi-yes"
                        style="flex:1;padding:10px;background:#F05454;border:none;border-radius:8px;
                               color:#fff;font-size:14px;font-weight:600;cursor:pointer;">
                        Confirm
                    </button>
                </div>
            </div>`;
        document.body.appendChild(overlay);
        overlay.querySelector('#pbi-yes').onclick = () => { overlay.remove(); resolve(true);  };
        overlay.querySelector('#pbi-no').onclick  = () => { overlay.remove(); resolve(false); };
        overlay.onclick = e => { if (e.target === overlay) { overlay.remove(); resolve(false); } };
    });
};