const API = 'api.php';

// ── State ──
let currentUser = null;
let bracket = [];
let myPicks = {};
let authMode = 'login';

// ── Helpers ──
async function api(action, body = null) {
    const opts = { method: body ? 'POST' : 'GET', headers: { 'Content-Type': 'application/json' } };
    if (body) {
        // Attach credentials to every POST
        if (currentUser && !body.name) {
            body.name = currentUser.name;
            body.pin = currentUser.pin;
        }
        opts.body = JSON.stringify(body);
    }
    const res = await fetch(`${API}?action=${action}`, opts);
    const data = await res.json();
    if (!res.ok) throw new Error(data.error || 'Request failed');
    return data;
}

function $(sel) { return document.querySelector(sel); }
function $$(sel) { return document.querySelectorAll(sel); }

// ── Auth ──
$$('.auth-tabs .tab').forEach(tab => {
    tab.addEventListener('click', () => {
        $$('.auth-tabs .tab').forEach(t => t.classList.remove('active'));
        tab.classList.add('active');
        authMode = tab.dataset.tab;
        $('#auth-btn').textContent = authMode === 'login' ? 'Sign In' : 'Register';
        $('#auth-error').textContent = '';
    });
});

$('#auth-form').addEventListener('submit', async (e) => {
    e.preventDefault();
    const name = $('#auth-name').value.trim();
    const pin = $('#auth-pin').value;
    $('#auth-error').textContent = '';

    try {
        const data = await api(authMode === 'login' ? 'login' : 'register', { name, pin });
        currentUser = { name: data.name, pin, is_admin: data.is_admin };
        sessionStorage.setItem('user', JSON.stringify(currentUser));
        showApp();
    } catch (err) {
        $('#auth-error').textContent = err.message;
    }
});

$('#logout-btn').addEventListener('click', () => {
    currentUser = null;
    sessionStorage.removeItem('user');
    $('#app').classList.add('hidden');
    $('#auth-modal').classList.add('active');
    $('#auth-name').value = '';
    $('#auth-pin').value = '';
});

// ── Auto-login from session ──
(function tryAutoLogin() {
    const saved = sessionStorage.getItem('user');
    if (saved) {
        currentUser = JSON.parse(saved);
        showApp();
    }
})();

async function showApp() {
    $('#auth-modal').classList.remove('active');
    $('#app').classList.remove('hidden');
    $('#user-name').textContent = currentUser.name;

    if (currentUser.is_admin) {
        $('#admin-btn').classList.remove('hidden');
    }

    await loadBracket();
    await loadMyPicks();
}

// ── Tab Navigation ──
$$('.tab-btn').forEach(btn => {
    btn.addEventListener('click', () => {
        $$('.tab-btn').forEach(b => b.classList.remove('active'));
        btn.classList.add('active');
        $$('.view').forEach(v => v.classList.add('hidden'));
        v = $(`#view-${btn.dataset.view}`);
        v.classList.remove('hidden');
        v.classList.add('active');

        if (btn.dataset.view === 'picks') loadAllPicks();
        if (btn.dataset.view === 'leaderboard') loadLeaderboard();
    });
});

// ── Bracket ──
async function loadBracket() {
    try {
        const data = await api('bracket');
        bracket = data.series;
        renderBracket();
    } catch (err) {
        console.error('Failed to load bracket:', err);
    }
}

async function loadMyPicks() {
    try {
        const data = await api('my_picks', { name: currentUser.name, pin: currentUser.pin });
        myPicks = {};
        data.picks.forEach(p => { myPicks[p.series_id] = p; });
        renderBracket();
    } catch (err) {
        console.error('Failed to load picks:', err);
    }
}

function renderBracket() {
    const confCards = $('#conf-finals-cards');
    const finalsCards = $('#finals-cards');
    confCards.innerHTML = '';
    finalsCards.innerHTML = '';

    bracket.forEach(s => {
        const card = createSeriesCard(s);
        if (s.round === 'conf_finals') confCards.appendChild(card);
        else finalsCards.appendChild(card);
    });
}

function createSeriesCard(series) {
    const card = document.createElement('div');
    card.className = 'series-card';

    const pick = myPicks[series.id];
    const hasBothTeams = series.team1 && series.team2;
    const isLocked = series.picks_locked;
    const isCompleted = series.status === 'completed';

    const statusClass = `status-${series.status}`;
    const statusText = series.status.charAt(0).toUpperCase() + series.status.slice(1);

    let team1Class = '';
    let team2Class = '';
    if (isCompleted && series.actual_winner) {
        team1Class = series.team1 === series.actual_winner ? 'winner' : '';
        team2Class = series.team2 === series.actual_winner ? 'winner' : '';
    }

    card.innerHTML = `
        <div class="series-header">
            <span class="series-label">${series.label}</span>
            <span class="series-status ${statusClass}">${statusText}</span>
        </div>
        <div class="series-teams">
            <div class="team-row ${team1Class} ${!series.team1 ? 'tbd' : ''}">
                <span>${series.team1 || 'TBD'}</span>
                ${isCompleted && series.actual_winner === series.team1 ? `<span class="games-won">✓ (${series.actual_games})</span>` : ''}
            </div>
            <div class="team-row ${team2Class} ${!series.team2 ? 'tbd' : ''}">
                <span>${series.team2 || 'TBD'}</span>
                ${isCompleted && series.actual_winner === series.team2 ? `<span class="games-won">✓ (${series.actual_games})</span>` : ''}
            </div>
        </div>
        <div class="series-footer">
            <div class="my-pick-display">
                ${pick ? `Your pick: <strong>${pick.winner} in ${pick.games}</strong>` : '<span style="opacity:0.5">No pick yet</span>'}
            </div>
            ${hasBothTeams && !isLocked ? `<button class="pick-btn" data-series="${series.id}">
                ${pick ? 'Change Pick' : 'Make Pick'}
            </button>` : ''}
            ${isLocked && !pick ? '<span class="pick-btn" style="opacity:0.4;cursor:default">Locked</span>' : ''}
        </div>
    `;

    const pickBtn = card.querySelector('.pick-btn[data-series]');
    if (pickBtn) {
        pickBtn.addEventListener('click', () => openPickModal(series, pick));
    }

    return card;
}

// ── Pick Modal ──
let pickState = { seriesId: null, winner: null, games: null };

function openPickModal(series, existingPick) {
    pickState = {
        seriesId: series.id,
        winner: existingPick ? existingPick.winner : null,
        games: existingPick ? existingPick.games : null
    };

    $('#pick-title').textContent = series.label;
    $('#pick-team1').textContent = series.team1;
    $('#pick-team2').textContent = series.team2;

    // Reset selections
    $('#pick-team1').className = 'team-pick' + (pickState.winner === series.team1 ? ' selected' : '');
    $('#pick-team2').className = 'team-pick' + (pickState.winner === series.team2 ? ' selected' : '');
    $$('.game-btn').forEach(b => {
        b.className = 'game-btn' + (pickState.games == b.dataset.games ? ' selected' : '');
    });
    updateSubmitBtn();

    $('#pick-team1').onclick = () => {
        pickState.winner = series.team1;
        $('#pick-team1').classList.add('selected');
        $('#pick-team2').classList.remove('selected');
        updateSubmitBtn();
    };
    $('#pick-team2').onclick = () => {
        pickState.winner = series.team2;
        $('#pick-team2').classList.add('selected');
        $('#pick-team1').classList.remove('selected');
        updateSubmitBtn();
    };

    $$('.game-btn').forEach(btn => {
        btn.onclick = () => {
            pickState.games = parseInt(btn.dataset.games);
            $$('.game-btn').forEach(b => b.classList.remove('selected'));
            btn.classList.add('selected');
            updateSubmitBtn();
        };
    });

    $('#pick-modal').classList.add('active');
}

function updateSubmitBtn() {
    $('#submit-pick').disabled = !(pickState.winner && pickState.games);
}

$('#pick-close').addEventListener('click', () => {
    $('#pick-modal').classList.remove('active');
});

$('#submit-pick').addEventListener('click', async () => {
    try {
        await api('pick', {
            name: currentUser.name,
            pin: currentUser.pin,
            series_id: pickState.seriesId,
            winner: pickState.winner,
            games: pickState.games
        });
        $('#pick-modal').classList.remove('active');
        await loadMyPicks();
    } catch (err) {
        alert(err.message);
    }
});

// Close modals on backdrop click
$$('.modal').forEach(modal => {
    modal.addEventListener('click', (e) => {
        if (e.target === modal) modal.classList.remove('active');
    });
});

// ── All Picks View ──
async function loadAllPicks() {
    try {
        const [picksData, bracketData] = await Promise.all([
            api('picks'),
            api('bracket')
        ]);
        renderAllPicks(picksData.picks, bracketData.series);
    } catch (err) {
        console.error('Failed to load picks:', err);
    }
}

function renderAllPicks(picks, series) {
    const container = $('#picks-grid');
    container.innerHTML = '';

    if (picks.length === 0) {
        container.innerHTML = '<p style="text-align:center;color:var(--text-muted);padding:2rem">No picks submitted yet.</p>';
        return;
    }

    // Group by series
    const grouped = {};
    series.forEach(s => {
        grouped[s.id] = { series: s, picks: [] };
    });
    picks.forEach(p => {
        if (grouped[p.series_id]) grouped[p.series_id].picks.push(p);
    });

    Object.values(grouped).forEach(({ series: s, picks: sPicks }) => {
        if (sPicks.length === 0) return;

        const group = document.createElement('div');
        group.className = 'picks-series-group';

        const isLocked = s.picks_locked;
        const isCompleted = s.status === 'completed';

        let tableHTML = `<h3>${s.label} – ${s.team1 || 'TBD'} vs ${s.team2 || 'TBD'}</h3>`;

        if (!isLocked && !isCompleted) {
            tableHTML += '<p class="pick-hidden">Picks are hidden until the series is locked.</p>';
        } else {
            tableHTML += `<table class="picks-table">
                <thead><tr><th>Player</th><th>Winner</th><th>Games</th></tr></thead>
                <tbody>`;

            sPicks.forEach(p => {
                let rowClass = '';
                if (isCompleted) {
                    rowClass = p.winner === s.actual_winner ? 'correct' : 'incorrect';
                }
                tableHTML += `<tr class="${rowClass}">
                    <td>${escapeHtml(p.user_name)}</td>
                    <td>${escapeHtml(p.winner)}</td>
                    <td>${p.games}</td>
                </tr>`;
            });

            tableHTML += '</tbody></table>';
        }

        group.innerHTML = tableHTML;
        container.appendChild(group);
    });
}

// ── Leaderboard ──
async function loadLeaderboard() {
    try {
        const data = await api('leaderboard');
        renderLeaderboard(data.leaderboard, data.scoring);
    } catch (err) {
        console.error('Failed to load leaderboard:', err);
    }
}

function renderLeaderboard(entries, scoring) {
    const container = $('#leaderboard-table');
    container.innerHTML = '';

    const header = document.createElement('div');
    header.className = 'leaderboard-header';
    header.innerHTML = `
        <h2>Standings</h2>
        <p>Winner: ${scoring.correct_winner} pts · Exact games: +${scoring.correct_games} pts</p>
    `;
    container.appendChild(header);

    if (entries.length === 0) {
        container.innerHTML += '<p style="text-align:center;color:var(--text-muted)">No players yet.</p>';
        return;
    }

    entries.forEach((entry, i) => {
        const rank = i + 1;
        const rankClass = rank === 1 ? 'gold' : rank === 2 ? 'silver' : rank === 3 ? 'bronze' : '';

        const card = document.createElement('div');
        card.className = 'lb-card';
        card.innerHTML = `
            <div class="lb-rank ${rankClass}">${rank}</div>
            <div class="lb-info">
                <div class="lb-name">${escapeHtml(entry.name)}</div>
                <div class="lb-stats">${entry.correct_winners} series · ${entry.correct_games} exact</div>
            </div>
            <div>
                <div class="lb-points">${entry.points}</div>
                <div class="lb-points-label">pts</div>
            </div>
        `;
        container.appendChild(card);
    });
}

// ── Admin ──
$('#admin-btn').addEventListener('click', () => openAdminModal());

$('#admin-close').addEventListener('click', () => {
    $('#admin-modal').classList.remove('active');
});

async function openAdminModal() {
    const data = await api('bracket');
    const container = $('#admin-series-list');
    container.innerHTML = '';

    data.series.forEach(s => {
        const div = document.createElement('div');
        div.className = 'admin-series';
        div.innerHTML = `
            <h3>${s.label}</h3>
            <div class="admin-row">
                <div class="admin-field">
                    <label>Team 1</label>
                    <input type="text" value="${escapeAttr(s.team1)}" data-field="team1" maxlength="30">
                </div>
                <div class="admin-field">
                    <label>Team 2</label>
                    <input type="text" value="${escapeAttr(s.team2)}" data-field="team2" maxlength="30">
                </div>
            </div>
            <div class="admin-row">
                <div class="admin-field">
                    <label>Status</label>
                    <select data-field="status">
                        <option value="upcoming" ${s.status === 'upcoming' ? 'selected' : ''}>Upcoming</option>
                        <option value="active" ${s.status === 'active' ? 'selected' : ''}>Active</option>
                        <option value="completed" ${s.status === 'completed' ? 'selected' : ''}>Completed</option>
                    </select>
                </div>
                <div class="admin-field">
                    <label>Winner</label>
                    <input type="text" value="${escapeAttr(s.actual_winner)}" data-field="actual_winner" maxlength="30">
                </div>
                <div class="admin-field">
                    <label>Games</label>
                    <select data-field="actual_games">
                        <option value="0" ${s.actual_games == 0 ? 'selected' : ''}>—</option>
                        <option value="4" ${s.actual_games == 4 ? 'selected' : ''}>4</option>
                        <option value="5" ${s.actual_games == 5 ? 'selected' : ''}>5</option>
                        <option value="6" ${s.actual_games == 6 ? 'selected' : ''}>6</option>
                        <option value="7" ${s.actual_games == 7 ? 'selected' : ''}>7</option>
                    </select>
                </div>
            </div>
            <div class="admin-lock-row">
                <input type="checkbox" id="lock-${s.id}" ${s.picks_locked ? 'checked' : ''}>
                <label for="lock-${s.id}">Lock picks (no more changes)</label>
            </div>
            <div class="admin-save">
                <button class="btn primary small" data-save="${s.id}">Save</button>
            </div>
        `;

        div.querySelector(`[data-save="${s.id}"]`).addEventListener('click', async () => {
            const fields = {};
            div.querySelectorAll('[data-field]').forEach(el => {
                fields[el.dataset.field] = el.value;
            });
            fields.picks_locked = div.querySelector(`#lock-${s.id}`).checked ? 1 : 0;
            fields.actual_games = parseInt(fields.actual_games) || 0;
            fields.series_id = s.id;
            fields.name = currentUser.name;
            fields.pin = currentUser.pin;

            try {
                await api('admin_update_series', fields);
                await loadBracket();
                await loadMyPicks();
                alert('Saved!');
            } catch (err) {
                alert('Error: ' + err.message);
            }
        });

        container.appendChild(div);
    });

    $('#admin-modal').classList.add('active');
}

// ── Utils ──
function escapeHtml(str) {
    const div = document.createElement('div');
    div.textContent = str;
    return div.innerHTML;
}

function escapeAttr(str) {
    return (str || '').replace(/&/g, '&amp;').replace(/"/g, '&quot;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
}
