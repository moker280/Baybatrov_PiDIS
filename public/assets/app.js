'use strict';

// ================= Политика пароля (точное зеркало серверной проверки PHP) =================
function passwordPolicyResults(value) {
    const chars = Array.from(value);
    const lower = Array.from(value.toLowerCase());
    const isDigit = (c) => c >= '0' && c <= '9';
    const isLetter = (c) => /[a-zа-яё]/u.test(c);
    let forbidden = false;
    for (let i = 0; i + 1 < lower.length; i++) {
        const a = lower[i];
        const b = lower[i + 1];
        if (a === b) { forbidden = true; break; }
        const sameClass = (isDigit(a) && isDigit(b)) || (isLetter(a) && isLetter(b));
        if (sameClass && Math.abs(b.codePointAt(0) - a.codePointAt(0)) === 1) { forbidden = true; break; }
    }
    return {
        len: chars.length >= 6,
        dig: /[0-9]/u.test(value),
        low: /[a-zа-яё]/u.test(value),
        up: /[A-ZА-ЯЁ]/u.test(value),
        seq: chars.length > 0 && !forbidden
    };
}

function setRuleState(el, state) {
    el.classList.remove('ok', 'fail');
    const mark = el.querySelector('.pw-mark');
    if (state === 'ok') { el.classList.add('ok'); if (mark) mark.textContent = '✓'; }
    else if (state === 'fail') { el.classList.add('fail'); if (mark) mark.textContent = '✗'; }
    else if (mark) { mark.textContent = '·'; }
}

function initPasswordRules() {
    document.querySelectorAll('.pw-rules[data-for]').forEach((box) => {
        const input = document.getElementById(box.getAttribute('data-for'));
        if (!input) return;
        const optional = box.hasAttribute('data-optional');
        const refresh = () => {
            const value = input.value;
            const neutral = optional && value === '';
            const results = passwordPolicyResults(value);
            box.querySelectorAll('[data-rule]').forEach((li) => {
                const key = li.getAttribute('data-rule');
                setRuleState(li, neutral ? 'neutral' : (results[key] ? 'ok' : 'fail'));
            });
        };
        input.addEventListener('input', refresh);
        refresh();
    });
    document.querySelectorAll('.pw-match[data-password][data-confirm]').forEach((el) => {
        const pw = document.getElementById(el.getAttribute('data-password'));
        const confirm = document.getElementById(el.getAttribute('data-confirm'));
        if (!pw || !confirm) return;
        const rulesBox = document.querySelector('.pw-rules[data-for="' + pw.id + '"]');
        const optional = rulesBox && rulesBox.hasAttribute('data-optional');
        const refresh = () => {
            if (optional && pw.value === '' && confirm.value === '') { setRuleState(el, 'neutral'); return; }
            const ok = pw.value !== '' && pw.value === confirm.value;
            setRuleState(el, ok ? 'ok' : 'fail');
        };
        pw.addEventListener('input', refresh);
        confirm.addEventListener('input', refresh);
        refresh();
    });
}

// ================= Капча-пазл 2x2: перетаскивание + клики =================
function initCaptcha() {
    document.querySelectorAll('[data-captcha]').forEach((box) => {
        const form = box.closest('form');
        const tray = box.querySelector('[data-captcha-tray]');
        const cells = Array.from(box.querySelectorAll('.captcha-cell'));
        const inputs = Array.from(box.querySelectorAll('input[data-captcha-cell]'));
        const hint = box.querySelector('[data-captcha-hint]');
        const warning = box.querySelector('[data-captcha-warning]');
        const src = box.getAttribute('data-src');
        const csrf = box.getAttribute('data-csrf');
        let selected = null;

        function sync() {
            cells.forEach((cell, i) => {
                const piece = cell.querySelector('.captcha-piece');
                cell.classList.toggle('filled', !!piece);
                inputs[i].value = piece ? piece.getAttribute('data-token') : '';
            });
            if (inputs.every((input) => input.value !== '')) hint.classList.add('hidden');
        }

        function setSelected(piece) {
            if (selected) selected.classList.remove('selected');
            selected = (selected === piece) ? null : piece;
            if (selected) selected.classList.add('selected');
        }

        function placeInto(piece, cell) {
            const from = piece.parentElement;
            const existing = cell.querySelector('.captcha-piece');
            if (existing && existing !== piece) from.appendChild(existing); // обмен при занятой ячейке
            cell.appendChild(piece);
            setSelected(null);
            sync();
        }

        function findByToken(token) {
            return box.querySelector('.captcha-piece[data-token="' + token + '"]');
        }

        function build(tokens) {
            tray.innerHTML = '';
            cells.forEach((cell) => { cell.innerHTML = ''; });
            selected = null;
            tokens.forEach((token) => {
                const piece = document.createElement('div');
                piece.className = 'captcha-piece';
                piece.setAttribute('draggable', 'true');
                piece.setAttribute('data-token', token);
                const img = document.createElement('img');
                img.src = src + '?t=' + encodeURIComponent(token);
                img.alt = 'Фрагмент изображения';
                img.addEventListener('error', () => {
                    box.classList.add('captcha-broken');
                    warning.classList.remove('hidden');
                });
                piece.appendChild(img);
                piece.addEventListener('click', () => setSelected(piece));
                piece.addEventListener('dragstart', (ev) => {
                    ev.dataTransfer.setData('text/plain', token);
                });
                tray.appendChild(piece);
            });
            sync();
        }

        cells.forEach((cell) => {
            cell.addEventListener('click', () => { if (selected) placeInto(selected, cell); });
            cell.addEventListener('dragover', (ev) => ev.preventDefault());
            cell.addEventListener('drop', (ev) => {
                ev.preventDefault();
                const piece = findByToken(ev.dataTransfer.getData('text/plain'));
                if (piece) placeInto(piece, cell);
            });
        });

        // Возврат фрагмента в исходную область: клик по пустой части ленты или перетаскивание.
        tray.addEventListener('click', (ev) => {
            if (!selected || ev.target.closest('.captcha-piece')) return;
            tray.appendChild(selected);
            setSelected(null);
            sync();
        });
        tray.addEventListener('dragover', (ev) => ev.preventDefault());
        tray.addEventListener('drop', (ev) => {
            ev.preventDefault();
            const piece = findByToken(ev.dataTransfer.getData('text/plain'));
            if (piece) { tray.appendChild(piece); setSelected(null); sync(); }
        });

        box.querySelector('[data-captcha-reset]').addEventListener('click', () => {
            box.querySelectorAll('.captcha-piece').forEach((piece) => tray.appendChild(piece));
            setSelected(null);
            sync();
        });

        box.querySelector('[data-captcha-refresh]').addEventListener('click', () => {
            fetch(src, {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: 'action=refresh&csrf=' + encodeURIComponent(csrf)
            })
                .then((response) => {
                    if (!response.ok) throw new Error('refresh failed');
                    return response.json();
                })
                .then((data) => {
                    box.classList.remove('captcha-broken');
                    warning.classList.add('hidden');
                    build(data.tokens);
                })
                .catch(() => warning.classList.remove('hidden'));
        });

        if (form) {
            form.addEventListener('submit', (ev) => {
                sync();
                if (inputs.some((input) => input.value === '')) {
                    ev.preventDefault();
                    hint.classList.remove('hidden');
                }
            });
        }

        build(JSON.parse(box.getAttribute('data-tokens')));
    });
}

// ================= Обратный отсчёт одноразового кода =================
function initCountdown() {
    const el = document.querySelector('[data-countdown]');
    if (!el) return;
    const expires = parseInt(el.getAttribute('data-expires'), 10) || 0;
    const serverNow = parseInt(el.getAttribute('data-now'), 10) || 0;
    const offset = serverNow * 1000 - Date.now(); // поправка на расхождение часов клиента и сервера
    const form = document.querySelector('form[data-code-form]');
    const expiredHint = document.querySelector('[data-expired-hint]');
    const tick = () => {
        const left = Math.max(0, Math.round((expires * 1000 - (Date.now() + offset)) / 1000));
        if (left <= 0) {
            el.textContent = 'истёк';
            el.classList.add('expired');
            if (form) {
                const submit = form.querySelector('button[type="submit"]');
                if (submit) submit.disabled = true;
            }
            if (expiredHint) expiredHint.classList.remove('hidden');
            clearInterval(timer);
            return;
        }
        el.textContent = 'осталось ' + left + ' с';
    };
    const timer = setInterval(tick, 500);
    tick();
}

// ================= Подтверждения и уведомления =================
function initConfirmForms() {
    document.querySelectorAll('form[data-confirm]').forEach((form) => {
        form.addEventListener('submit', (ev) => {
            if (!window.confirm(form.getAttribute('data-confirm'))) ev.preventDefault();
        });
    });
}

function initFlashClose() {
    document.querySelectorAll('.flash-close').forEach((btn) => {
        btn.addEventListener('click', () => btn.closest('.flash').remove());
    });
}

document.addEventListener('DOMContentLoaded', () => {
    initPasswordRules();
    initCaptcha();
    initCountdown();
    initConfirmForms();
    initFlashClose();
});