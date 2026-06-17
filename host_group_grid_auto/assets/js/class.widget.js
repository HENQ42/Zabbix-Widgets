class CWidgetHostGroupGridAuto extends CWidget {

    #overlay = null;
    #drilldown = null;
    #openSiteId = null;
    #observer = null;
    // Nomes das seções atualmente recolhidas. Sobrevive aos refreshes de conteúdo (que recriam o DOM),
    // reaplicado em #applyCollapsedState() — o usuário não vê as seções reabrirem a cada ciclo.
    #collapsedSections = new Set();

    #isDrilldownAlive() {
        if (!this.#drilldown) return false;
        if (!this.#drilldown.isConnected) {
            // Stale reference left over after a widget content refresh.
            this.#drilldown = null;
            return false;
        }
        return true;
    }

    #onBodyClick = (e) => {
        // Host-name link inside the drill-down — let the browser navigate normally.
        if (e.target.closest && e.target.closest('.hggrid-host-link')) {
            return;
        }

        // Clique no título de uma seção: recolhe/expande os cards dela (toggle da classe `collapsed`).
        const sectionTitle = e.target.closest && e.target.closest('.hggrid-section-title');
        if (sectionTitle) {
            const section = sectionTitle.closest('.hggrid-section');
            if (section) {
                const key = this.#sectionKey(section);
                const collapsed = section.classList.toggle('collapsed');
                if (collapsed) {
                    this.#collapsedSections.add(key);
                }
                else {
                    this.#collapsedSections.delete(key);
                }
            }
            return;
        }

        // Drill-down back button.
        if (e.target.closest && e.target.closest('.hggrid-drilldown-back')) {
            this.#hideDrilldown();
            return;
        }

        // Any cell click — short-circuit so it never falls through to the card-level drill-down.
        const anyCell = e.target.closest && e.target.closest('.hggrid-cell');
        if (anyCell) {
            if (anyCell.classList.contains('has-problems')) {
                const raw = anyCell.getAttribute('data-problems');
                if (!raw) return;
                let problems;
                try { problems = JSON.parse(raw); } catch (err) { return; }
                this.#showOverlay(problems, anyCell.getAttribute('data-hour') || '');
            }
            return;
        }

        // Card click on the main grid → drill-down. Ignore clicks inside the drill-down itself.
        if (this.#isDrilldownAlive()) return;
        const box = e.target.closest && e.target.closest('.hggrid-box[data-site-id]');
        if (box) {
            const siteId = box.getAttribute('data-site-id');
            const detail = this.#getSiteDetail(siteId);
            if (detail) {
                this.#showDrilldown(detail);
            }
        }
    };

    onActivate() {
        this._body.addEventListener('click', this.#onBodyClick);
        this.#applyCollapsedState();

        // After a widget content refresh, Zabbix replaces this._body's children — the drill-down
        // div is wiped along with everything else. Watch for that and re-open it so the user
        // doesn't get bounced back to the main grid each refresh cycle. The collapsed sections are
        // recreated expanded by the fresh render, so we reapply their state here too.
        this.#observer = new MutationObserver(() => {
            this.#applyCollapsedState();
            if (this.#openSiteId && !this.#isDrilldownAlive()) {
                const detail = this.#getSiteDetail(this.#openSiteId);
                if (detail) {
                    this.#showDrilldown(detail);
                }
            }
        });
        this.#observer.observe(this._body, { childList: true });
    }

    // Chave de uma seção para lembrar o estado recolhido: o texto do seu nome (ex.: "Sites Instáveis").
    #sectionKey(section) {
        const nameEl = section.querySelector('.hggrid-section-name');
        return nameEl ? nameEl.textContent.trim() : '';
    }

    // Reaplica a classe `collapsed` às seções cujo nome está no conjunto de recolhidas.
    #applyCollapsedState() {
        if (!this._body) return;
        this._body.querySelectorAll('.hggrid-section').forEach((section) => {
            section.classList.toggle('collapsed', this.#collapsedSections.has(this.#sectionKey(section)));
        });
    }

    onDeactivate() {
        this._body.removeEventListener('click', this.#onBodyClick);
        if (this.#observer) {
            this.#observer.disconnect();
            this.#observer = null;
        }
        this.#hideOverlay();
        this.#hideDrilldown();
    }

    onClearContents() {
        this.#hideOverlay();
        // Don't reset #openSiteId here — we want the drill-down to reappear after a content refresh.
        if (this.#drilldown) {
            this.#drilldown.remove();
            this.#drilldown = null;
        }
    }

    #getSiteDetail(siteId) {
        const tag = this._body.querySelector('script[type="application/json"][data-hggrid-detail]');
        if (!tag) return null;
        try {
            const all = JSON.parse(tag.textContent);
            return all.find((s) => String(s.site_id) === String(siteId)) || null;
        }
        catch (err) { return null; }
    }

    #getColors() {
        const tag = this._body.querySelector('script[type="application/json"][data-hggrid-colors]');
        const defaults = { stable: '16A34A', critical: 'DC2626', warning: 'D97706' };
        if (!tag) return defaults;
        try {
            const c = JSON.parse(tag.textContent);
            return {
                stable: c.stable || defaults.stable,
                critical: c.critical || defaults.critical,
                warning: c.warning || defaults.warning
            };
        }
        catch (err) { return defaults; }
    }

    #stateMeta(state, colors) {
        if (state === 'critical') {
            return { color: colors.critical, bg: 'fee2e2', label: 'Crítico' };
        }
        if (state === 'unstable' || state === 'warning') {
            return { color: colors.warning, bg: 'fef3c7', label: state === 'unstable' ? 'Instável' : 'Atenção' };
        }
        return { color: colors.stable, bg: 'dcfce7', label: 'Estável' };
    }

    #showDrilldown(site) {
        this.#hideDrilldown(true);
        this.#openSiteId = site.site_id;

        const colors = this.#getColors();
        const siteMeta = this.#stateMeta(site.state, colors);

        const esc = (s) => String(s).replace(/[&<>"']/g,
            (c) => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));

        const types = site.types || [];
        const totalHosts = +site.total || 0;
        // Header summary built from the dynamically-discovered types, e.g. "CAMERA 5/6 · NVR 2/2".
        const typesSummary = types.length
            ? types.map((t) => esc(t.type) + ' ' + (+t.active) + '/' + (+t.total)).join(' · ')
            : (totalHosts + ' ' + (totalHosts === 1 ? 'host' : 'hosts'));

        const renderCell = (cell) => {
            let bg = '';
            let label = 'Estável';
            let extra = '';

            if (cell.state === 'future') {
                label = 'Futuro';
                extra = ' future';
            }
            else if (cell.state === 'critical') {
                bg = colors.critical;
                label = 'Crítico';
            }
            else if (cell.state === 'warning') {
                bg = colors.warning;
                label = 'Atenção';
            }
            else {
                bg = colors.stable;
            }

            const problems = cell.problems || [];
            const count = problems.length;
            let tooltip = cell.hour_label + ' — ' + label;
            if (count > 0) {
                tooltip += ' (' + count + ' ' + (count === 1 ? 'problema' : 'problemas') + ')';
            }

            const hasProblems = count > 0;
            const styleAttr = (cell.state !== 'future' && bg) ? (' style="background-color:#' + bg + ';"') : '';
            const classes = 'hggrid-cell' + extra + (hasProblems ? ' has-problems' : '');
            const data = hasProblems
                ? ' data-problems="' + esc(JSON.stringify(problems)) + '" data-hour="' + esc(cell.hour_label) + '"'
                : '';

            return '<div class="' + classes + '" title="' + esc(tooltip) + '"' + styleAttr + data + '></div>';
        };

        const renderHostCard = (host) => {
            const meta = this.#stateMeta(host.state, colors);
            const tl = host.timeline || [];
            const row1 = tl.slice(0, 12).map(renderCell).join('');
            const row2 = tl.slice(12, 24).map(renderCell).join('');
            const icon = '◉';
            const typeLabel = host.type || '';
            const onlineDot = host.online
                ? '<span class="hggrid-online ok" title="Online"></span>'
                : '<span class="hggrid-online bad" title="Offline"></span>';

            const itemRows = host.rows || [];
            const itemRowsHtml = itemRows.length
                ? '<div class="hggrid-host-rows" style="margin-top:6px;border-top:1px solid rgba(0,0,0,0.06);padding-top:4px;">'
                  + itemRows.map((r) => {
                      let valueStyle = '';
                      if (r.color) valueStyle += 'color:#' + r.color + ';';
                      if (r.bold) valueStyle += 'font-weight:bold;';
                      const valueText = (r.value !== '' && r.value !== null && r.value !== undefined) ? r.value : 'Sem dados';
                      return '<div style="display:flex;justify-content:space-between;align-items:center;padding:3px 0;font-size:11px;border-top:1px dashed rgba(0,0,0,0.06);">'
                          + '<span style="white-space:nowrap;overflow:hidden;text-overflow:ellipsis;margin-right:10px;flex:1 1 auto;min-width:0;">'
                          +   esc(r.label)
                          + '</span>'
                          + '<span style="white-space:nowrap;flex-shrink:0;' + valueStyle + '">' + esc(valueText) + '</span>'
                      + '</div>';
                  }).join('')
                  + '</div>'
                : '';

            const dashboardUrl = 'zabbix.php?action=host.dashboard.view&hostid=' + encodeURIComponent(host.hostid);

            return ''
                + '<div class="hggrid-box" style="border-left:5px solid #' + meta.color + ';">'
                +   '<div class="hggrid-header">'
                +     '<a href="' + dashboardUrl + '" class="hggrid-site-num hggrid-host-link" '
                +       'title="' + esc(host.name) + '" '
                +       'style="font-size:13px;letter-spacing:.3px;color:inherit;text-decoration:none;cursor:pointer;'
                +       'overflow:hidden;text-overflow:ellipsis;white-space:nowrap;flex:1 1 auto;min-width:0;">'
                +       '<span class="hggrid-badge-icon" style="margin-right:6px;">' + icon + '</span>'
                +       onlineDot
                +       esc(host.short_name || host.name)
                +     '</a>'
                +     '<span class="hggrid-status ' + host.state + '" '
                +       'style="background-color:#' + meta.bg + ';color:#' + meta.color + ';">'
                +       '<span class="hggrid-status-dot"></span>' + meta.label
                +     '</span>'
                +   '</div>'
                +   '<div class="hggrid-host-meta" style="font-size:10px;opacity:.7;margin-bottom:6px;">' + typeLabel + '</div>'
                +   '<div class="hggrid-timeline">' + row1 + '</div>'
                +   '<div class="hggrid-timeline">' + row2 + '</div>'
                +   itemRowsHtml
                + '</div>';
        };

        const hosts = site.hosts || [];
        const cardsHtml = hosts.length
            ? hosts.map(renderHostCard).join('')
            : '<div style="opacity:.7;padding:20px;text-align:center;">Nenhum host neste site.</div>';

        const wrap = document.createElement('div');
        wrap.className = 'hggrid-drilldown';
        wrap.style.cssText =
            'position:absolute;top:0;left:0;right:0;bottom:0;width:100%;height:100%;'
            + 'margin:0;padding:0;box-sizing:border-box;'
            + 'background:var(--hggrid-drilldown-bg);z-index:50;'
            + 'display:flex;flex-direction:column;overflow:hidden;';

        wrap.innerHTML = ''
            + '<div class="hggrid-drilldown-bar" style="'
            +   'display:flex;align-items:center;gap:10px;padding:10px 14px;'
            +   'border-top:1px solid var(--hggrid-bar-border);border-bottom:1px solid var(--hggrid-bar-border);'
            +   'background:var(--hggrid-bar-bg);color:var(--hggrid-bar-color);flex-shrink:0;">'
            +   '<button type="button" class="hggrid-drilldown-back" title="Voltar" style="'
            +     'background:var(--hggrid-bar-btn-bg) !important;border:1px solid var(--hggrid-bar-btn-border) !important;border-radius:4px !important;'
            +     'cursor:pointer;padding:0 !important;font-size:20px !important;font-weight:600 !important;'
            +     'line-height:1 !important;color:var(--hggrid-bar-color) !important;width:32px !important;height:32px !important;'
            +     'min-width:0 !important;display:inline-flex !important;align-items:center !important;'
            +     'justify-content:center !important;box-shadow:none !important;text-transform:none !important;'
            +     'letter-spacing:normal !important;font-family:inherit !important;flex-shrink:0;">←</button>'
            +   '<span class="hggrid-drilldown-tag" style="'
            +     'display:inline-flex;align-items:baseline;gap:6px;'
            +     'padding:3px 10px;border-radius:4px;'
            +     'background:color-mix(in srgb, var(--hggrid-accent) 22%, transparent);color:var(--hggrid-accent);'
            +     'font-size:10px;font-weight:700;letter-spacing:1px;">'
            +     'SITE'
            +     '<span style="font-size:10px;font-weight:700;letter-spacing:1px;">'
            +       esc(site.site_id)
            +     '</span>'
            +   '</span>'
            +   '<div style="flex:1;font-size:12px;color:var(--hggrid-bar-color);opacity:0.8;">'
            +     typesSummary
            +   '</div>'
            +   '<span class="hggrid-status ' + site.state + '" '
            +     'style="background-color:#' + siteMeta.bg + ';color:#' + siteMeta.color + ';">'
            +     '<span class="hggrid-status-dot"></span>' + siteMeta.label
            +   '</span>'
            + '</div>'
            + '<div class="hggrid-drilldown-grid hggrid-wrap" style="'
            +   'flex:1;overflow:auto;display:grid;gap:12px;padding:12px;'
            +   'grid-template-columns:repeat(auto-fill, minmax(280px, 1fr));'
            +   'align-content:start;align-items:start;grid-auto-rows:max-content;">'
            +   cardsHtml
            + '</div>';

        // The widget body needs to be a positioning context.
        const prevPos = this._body.style.position;
        this._body.dataset.hggridPrevPos = prevPos || '';
        this._body.style.position = 'relative';

        this._body.appendChild(wrap);
        this.#drilldown = wrap;
    }

    #hideDrilldown(keepOpenSite = false) {
        if (this.#drilldown) {
            this.#drilldown.remove();
            this.#drilldown = null;
            if (this._body && this._body.dataset.hggridPrevPos !== undefined) {
                this._body.style.position = this._body.dataset.hggridPrevPos || '';
                delete this._body.dataset.hggridPrevPos;
            }
        }
        if (!keepOpenSite) {
            this.#openSiteId = null;
        }
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

        problems = problems.slice().sort((a, b) => {
            if (b.severity !== a.severity) return b.severity - a.severity;
            return a.clock - b.clock;
        });

        const groups = new Map();
        for (const p of problems) {
            const key = p.host || '—';
            if (!groups.has(key)) groups.set(key, []);
            groups.get(key).push(p);
        }

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
            'width:100%;max-width:560px;max-height:100%;display:flex;flex-direction:column;overflow:hidden;box-shadow:0 8px 32px rgba(0,0,0,0.6);';

        const head = document.createElement('div');
        head.style.cssText =
            'display:flex;align-items:center;justify-content:space-between;' +
            'padding:10px 14px;border-bottom:1px solid rgba(0,0,0,0.12);flex-shrink:0;';

        const title = document.createElement('span');
        const totalProblems = problems.length;
        const totalHosts = groups.size;
        title.textContent = 'Problemas em ' + hour
            + ' — ' + totalProblems + ' ' + (totalProblems === 1 ? 'problema' : 'problemas')
            + ' em ' + totalHosts + ' ' + (totalHosts === 1 ? 'host' : 'hosts');
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
        body.style.cssText = 'overflow-y:auto;overscroll-behavior:contain;flex:1;padding:10px 14px;';

        const renderProblem = (p) => {
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
            return '<div style="padding:6px 0;border-bottom:1px dashed rgba(128,128,128,0.3);">'
                + '<div style="font-weight:600;margin-bottom:4px;">' + esc(p.name) + sev + '</div>'
                + '<div style="font-size:12px;opacity:.85;">Início: ' + start + '</div>'
                + '<div style="font-size:12px;opacity:.85;">Fim: ' + finish + '</div>'
            + '</div>';
        };

        let html = '';
        for (const [host, items] of groups) {
            html += '<div style="margin-bottom:10px;">'
                + '<div style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.4px;'
                + 'padding:4px 8px;background:rgba(128,128,128,0.12);border-radius:4px;margin-bottom:4px;">'
                + esc(host) + ' <span style="opacity:.7;font-weight:600;">('
                + items.length + ')</span></div>'
                + items.map(renderProblem).join('')
                + '</div>';
        }
        body.innerHTML = html;

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
