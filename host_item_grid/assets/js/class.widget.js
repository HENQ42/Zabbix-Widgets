class CWidgetHostItemGrid extends CWidget {

    #overlay = null;

    #onBodyClick = (e) => {
        const cell = e.target.closest && e.target.closest('.higrid-cell.has-problems');
        if (!cell) return;

        const raw = cell.getAttribute('data-problems');
        if (!raw) return;

        let problems;
        try {
            problems = JSON.parse(raw);
        }
        catch (err) {
            return;
        }

        this.#showOverlay(problems, cell.getAttribute('data-hour') || '');
    };

    onActivate() {
        this._body.addEventListener('click', this.#onBodyClick);
    }

    onDeactivate() {
        this._body.removeEventListener('click', this.#onBodyClick);
        this.#hideOverlay();
    }

    onClearContents() {
        this.#hideOverlay();
    }

    #showOverlay(problems, hour) {
        this.#hideOverlay();

        const SEV_NAMES = ['Não classificado','Informação','Atenção','Média','Alta','Desastre'];
        const SEV_COLORS = ['#97AAB3','#7499FF','#FFC859','#FFA059','#E97659','#E45959'];

        const pad = (n) => (n < 10 ? '0' + n : '' + n);
        const fmtTs = (ts) => {
            const d = new Date(ts * 1000);
            return pad(d.getDate()) + '/' + pad(d.getMonth() + 1) + '/' + d.getFullYear()
                + ' ' + pad(d.getHours()) + ':' + pad(d.getMinutes()) + ':' + pad(d.getSeconds());
        };
        const fmtDur = (sec) => {
            if (sec < 60) return sec + 's';
            let m = Math.floor(sec / 60);
            const s = sec % 60;
            if (m < 60) return m + 'm ' + s + 's';
            const h = Math.floor(m / 60);
            m = m % 60;
            return h + 'h ' + m + 'm';
        };
        const esc = (s) => String(s).replace(/[&<>"']/g,
            (c) => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));

        problems = problems.slice().sort((a, b) => a.clock - b.clock);

        const overlay = document.createElement('div');
        overlay.style.cssText =
            'position:absolute;inset:0;background:rgba(0,0,0,0.55);z-index:100;' +
            'display:flex;align-items:center;justify-content:center;padding:8px;box-sizing:border-box;';

        const isDark = document.documentElement.getAttribute('color-scheme') === 'dark';
        const popupBg = isDark ? '#ededed' : '#ffffff';
        const popupBorder = isDark ? '#bfc6cb' : '#ccd5db';
        const popupText = '#1f2328';

        const box = document.createElement('div');
        box.style.cssText =
            'background:' + popupBg + ';border:1px solid ' + popupBorder + ';border-radius:6px;color:' + popupText + ';' +
            'width:100%;max-width:520px;max-height:100%;display:flex;flex-direction:column;overflow:hidden;box-shadow:0 8px 32px rgba(0,0,0,0.6);';

        const head = document.createElement('div');
        head.style.cssText =
            'display:flex;align-items:center;justify-content:space-between;' +
            'padding:10px 14px;border-bottom:1px solid rgba(0,0,0,0.12);flex-shrink:0;';

        const title = document.createElement('span');
        title.textContent = 'Problemas em ' + hour;
        title.style.cssText = 'font-size:12px;font-weight:600;color:' + popupText + ';';

        const closeBtn = document.createElement('button');
        closeBtn.type = 'button';
        closeBtn.textContent = '✕';
        closeBtn.style.cssText =
            'background:none;border:none;color:' + popupText + ';cursor:pointer;font-size:14px;' +
            'opacity:0.6;padding:2px 6px;border-radius:3px;';
        closeBtn.addEventListener('click', () => this.#hideOverlay());

        head.appendChild(title);
        head.appendChild(closeBtn);

        const body = document.createElement('div');
        body.style.cssText = 'overflow-y:auto;flex:1;padding:10px 14px;';
        body.innerHTML = problems.map((p) => {
            const sevIdx = Math.max(0, Math.min(5, p.severity | 0));
            const sev = '<span style="display:inline-block;padding:1px 7px;border-radius:8px;font-size:11px;font-weight:700;color:#fff;margin-left:6px;background:'
                + SEV_COLORS[sevIdx] + '">' + esc(SEV_NAMES[sevIdx]) + '</span>';
            const start = fmtTs(p.clock);
            let finish;
            if (p.r_clock) {
                finish = fmtTs(p.r_clock) + ' <em style="opacity:.8">(' + fmtDur(p.r_clock - p.clock) + ')</em>';
            }
            else {
                const nowSec = Math.floor(Date.now() / 1000);
                finish = '<strong>em andamento</strong> <em style="opacity:.8">(' + fmtDur(nowSec - p.clock) + ')</em>';
            }
            return '<div style="padding:8px 0;border-bottom:1px dashed rgba(128,128,128,0.3);">'
                + '<div style="font-weight:600;margin-bottom:4px;">' + esc(p.name) + sev + '</div>'
                + '<div style="font-size:12px;opacity:.85;">Início: ' + start + '</div>'
                + '<div style="font-size:12px;opacity:.85;">Fim: ' + finish + '</div>'
            + '</div>';
        }).join('');

        box.appendChild(head);
        box.appendChild(body);
        overlay.appendChild(box);

        overlay.addEventListener('click', (ev) => {
            if (ev.target === overlay) this.#hideOverlay();
        });

        this._body.appendChild(overlay);
        this.#overlay = overlay;
    }

    #hideOverlay() {
        if (this.#overlay) {
            this.#overlay.remove();
            this.#overlay = null;
        }
    }
}
