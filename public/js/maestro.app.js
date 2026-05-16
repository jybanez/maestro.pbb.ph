const bootstrap = window.__PBB_BOOTSTRAP__ ?? {};
const appEl = document.getElementById("app");
const POLL_INTERVAL_MS = 15000;
const GRID_ROW_HEIGHT = 40;
const GRID_VIRTUAL_THRESHOLD = 60;
const GRID_VIRTUAL_OVERSCAN = 10;
const SESSION_KEEPALIVE_WINDOW_MAX_MS = 3 * 60 * 1000;
const SESSION_KEEPALIVE_ACTIVITY_WINDOW_MAX_MS = 2 * 60 * 1000;
const SESSION_KEEPALIVE_MIN_WINDOW_MS = 15 * 1000;
const SESSION_ACTIVITY_WINDOW_MIN_MS = 30 * 1000;
const SESSION_KEEPALIVE_COOLDOWN_MS = 30 * 1000;
const SESSION_ACTIVITY_EVENTS = ["keydown", "pointerdown", "click", "scroll", "touchstart"];

const PAGE_DEFS = [
    { key: "dashboard", label: "Dashboard", eyebrow: "Overview", title: "Monitoring overview", description: "Current application, worker, queue, and event health across the monitored runtime." },
    { key: "workers", label: "Workers", eyebrow: "Workers", title: "Worker registry", description: "Current worker inventory with lifecycle and throughput visibility." },
    { key: "worker-events", label: "Worker Events", eyebrow: "Events", title: "Worker event feed", description: "Recent worker lifecycle and job activity across monitored applications." },
    { key: "applications", label: "Applications", eyebrow: "Applications", title: "Application monitoring surface", description: "Application ownership, worker coverage, stale counts, and last-seen activity." },
    { key: "queues", label: "Queues", eyebrow: "Queues", title: "Derived queue coverage", description: "Queue-level visibility derived from current workers and recent worker events." },
];

const state = {
    account: bootstrap.auth?.account ?? null,
    authenticated: Boolean(bootstrap.auth?.authenticated),
    csrfToken: String(bootstrap.security?.csrfToken ?? "").trim(),
    page: resolvePageFromLocation(),
    ui: {
        navbar: null,
        grid: null,
        formModal: null,
        loginFormModal: null,
        reauthFormModal: null,
        accountFormModal: null,
        changePasswordFormModal: null,
        alert: null,
        toast: null,
        emptyState: null,
    },
    grids: {},
    navbarInstance: null,
    session: {
        reloginOpen: false,
        lifetimeMs: Number(bootstrap.security?.sessionLifetimeSeconds ?? 0) * 1000,
        expiresAt: 0,
        timerId: null,
        keepaliveInFlight: false,
        keepaliveCooldownUntil: 0,
        lastUserActivityAt: 0,
        lastServerTouchAt: 0,
    },
    polling: {
        timerId: null,
        intervalMs: POLL_INTERVAL_MS,
    },
    management: {
        selectedApplicationCode: "",
        lastIssuedToken: null,
    },
    data: {
        loaded: false,
        loading: false,
        applications: [],
        workers: [],
        events: [],
        queues: [],
        summary: {
            runningWorkers: 0,
            staleWorkers: 0,
            busyWorkers: 0,
            failedEvents: 0,
        },
    },
    filters: {
        workers: { search: "", appCode: "", status: "", queueName: "" },
        workerEvents: { search: "", appCode: "", outcome: "", queueName: "" },
        applications: { search: "", environment: "", activity: "" },
        queues: { search: "", appCode: "", activity: "" },
    },
};

bootstrapApp().catch((error) => {
    console.error("Failed to initialize Maestro app.", error);
    if (appEl) {
        appEl.innerHTML = `
            <div class="maestro-app">
                <div class="maestro-empty">
                    <strong>Maestro could not initialize.</strong>
                    <span>${escapeHtml(error.message ?? "Unknown error")}</span>
                </div>
            </div>
        `;
    }
});

async function bootstrapApp() {
    await loadHelpers();
    bindGlobalEvents();
    bindSessionActivityEvents();
    renderAppShell();
    render();

    if (state.authenticated) {
        touchServerSession();
        await refreshCurrentUser();
    }

    await loadProtectedData();
}

async function loadHelpers() {
    const { helperUiBundleModules } = await import("/vendor/helpers.pbb.ph/dist/helpers.ui.bundle.min.js");
    const getHelperExport = (modulePath, exportName) => {
        const helperModule = helperUiBundleModules?.[modulePath];
        const exported = helperModule?.[exportName];
        if (typeof exported !== "function") {
            throw new Error(`Missing Helper bundle export: ${modulePath}#${exportName}`);
        }

        return exported;
    };

    const createNavbar = getHelperExport("./ui.navbar.js", "createNavbar");
    const createGrid = getHelperExport("./ui.grid.js", "createGrid");
    const createFormModal = getHelperExport("./ui.form.modal.js", "createFormModal");
    const createLoginFormModal = getHelperExport("./ui.form.modal.presets.js", "createLoginFormModal");
    const createReauthFormModal = getHelperExport("./ui.form.modal.presets.js", "createReauthFormModal");
    const createAccountFormModal = getHelperExport("./ui.form.modal.presets.js", "createAccountFormModal");
    const createChangePasswordFormModal = getHelperExport("./ui.form.modal.presets.js", "createChangePasswordFormModal");
    const uiAlert = getHelperExport("./ui.dialog.js", "uiAlert");
    const createToastStack = getHelperExport("./ui.toast.js", "createToastStack");
    const createEmptyState = getHelperExport("./ui.empty.state.js", "createEmptyState");

    state.ui.navbar = createNavbar;
    state.ui.grid = createGrid;
    state.ui.formModal = createFormModal;
    state.ui.loginFormModal = createLoginFormModal;
    state.ui.reauthFormModal = createReauthFormModal;
    state.ui.accountFormModal = createAccountFormModal;
    state.ui.changePasswordFormModal = createChangePasswordFormModal;
    state.ui.alert = uiAlert;
    state.ui.toast = createToastStack({ position: "bottom-right", defaultDuration: 2600 });
    state.ui.emptyState = createEmptyState;
}

function bindGlobalEvents() {
    window.addEventListener("hashchange", () => {
        const nextPage = resolvePageFromLocation();
        if (state.page === nextPage) return;
        state.page = nextPage;
        render();
    });

    document.addEventListener("visibilitychange", () => {
        if (document.hidden) {
            stopProtectedDataPolling();
            return;
        }

        if (state.authenticated) {
            startProtectedDataPolling();
            loadProtectedData({ force: true, silent: true });
        }
    });
}

function bindSessionActivityEvents() {
    const activityHandler = () => recordUserActivity();

    SESSION_ACTIVITY_EVENTS.forEach((eventName) => {
        document.addEventListener(eventName, activityHandler, {
            passive: eventName !== "keydown",
            capture: true,
        });
    });
}

function recordUserActivity() {
    state.session.lastUserActivityAt = Date.now();
}

function renderAppShell() {
    appEl.innerHTML = `
        <div class="maestro-app">
            <div class="maestro-shell">
                <header class="maestro-appbar" id="navbar-host"></header>

                <div class="maestro-body">
                    <section class="maestro-page" id="page-root"></section>
                </div>
            </div>
        </div>
    `;
}

function render() {
    renderTopbar();
    renderPage();
}

function renderTopbar() {
    const pageDef = PAGE_DEFS.find((page) => page.key === state.page) ?? PAGE_DEFS[0];
    const host = document.getElementById("navbar-host");
    if (!host || !state.ui.navbar) return;

    const options = {
        brandText: bootstrap.app?.name ?? "PBB Maestro",
        brandSubtitle: "Monitoring-first worker operations",
        activeId: state.page,
        items: PAGE_DEFS.map((page) => ({
            id: page.key,
            label: page.label,
        })),
        actions: state.authenticated && state.account
            ? [
                {
                    id: "user-menu",
                    label: getUserMenuLabel(),
                    menuItems: [
                        { id: "account", label: "Account" },
                        { id: "logout", label: "Logout", danger: true },
                    ],
                },
            ]
            : [
                { id: "login", label: "Login" },
            ],
        onNavigate(item) {
            if (!item?.id || item.id === "brand") return;
            navigateToPage(String(item.id));
        },
        onAction(action) {
            if (action?.id === "login") {
                void openLoginModal();
            }
        },
        onActionMenuSelect(action, menuItem) {
            if (action?.id !== "user-menu") return;
            if (menuItem?.id === "account") {
                void openAccountModal();
                return;
            }
            if (menuItem?.id === "logout") {
                void logout();
            }
        },
    };

    if (state.navbarInstance) {
        state.navbarInstance.update({}, options);
    } else {
        state.navbarInstance = state.ui.navbar(host, {}, options);
    }
}

function renderPage() {
    const pageRoot = document.getElementById("page-root");
    const pageDef = PAGE_DEFS.find((page) => page.key === state.page) ?? PAGE_DEFS[0];
    destroyAllGrids();

    pageRoot.innerHTML = `
        <div class="maestro-page-shell">
            <header class="maestro-page-head">
                <div class="maestro-page-head-main">
                    <p class="ui-eyebrow">${escapeHtml(pageDef.eyebrow)}</p>
                    <h1 class="maestro-page-title">${escapeHtml(pageDef.title)}</h1>
                    <p class="maestro-page-copy">${escapeHtml(pageDef.description)}</p>
                </div>
                <div class="ui-inline">${renderHeaderBadges()}</div>
            </header>
            <div class="maestro-page-toolbar" id="page-toolbar"></div>
            <div class="maestro-page-summary is-empty" id="page-summary"></div>
            <div class="maestro-page-content">
                <section class="ui-panel maestro-page-surface" id="page-surface"></section>
            </div>
        </div>
    `;

    document.getElementById("page-surface")?.classList.remove("is-management-surface", "is-grid-fill-surface");

    if (!state.authenticated) {
        renderLoginRequired(pageDef);
        return;
    }

    switch (state.page) {
        case "dashboard":
            renderDashboardPage();
            break;
        case "workers":
            renderWorkersPage();
            break;
        case "worker-events":
            renderWorkerEventsPage();
            break;
        case "applications":
            renderApplicationsPage();
            break;
        case "queues":
            renderQueuesPage();
            break;
        default:
            renderDashboardPage();
            break;
    }
}

function renderHeaderBadges() {
    const summary = state.data.summary;
    return [
        `<span class="ui-badge">${state.data.applications.length} applications</span>`,
        `<span class="ui-badge">${state.data.workers.length} workers</span>`,
        `<span class="ui-badge">${summary.staleWorkers} stale</span>`,
    ].join("");
}

function renderLoginRequired(pageDef) {
    const toolbar = document.getElementById("page-toolbar");
    const surface = document.getElementById("page-surface");

    toolbar.innerHTML = `
        <div class="maestro-page-toolbar-main"><span class="ui-badge">Protected page</span></div>
        <div class="maestro-page-toolbar-side"><button type="button" class="ui-button ui-button-primary" data-action="login">Open Login</button></div>
    `;

    toolbar.querySelector('[data-action="login"]').addEventListener("click", () => openLoginModal());
    renderEmpty(surface, {
        title: `${pageDef.label} requires login`,
        description: "Start a session first so Maestro can load the protected operator data grids and summary pages.",
        actionLabel: "Open Login",
        onAction: () => openLoginModal(),
    });
}

function renderDashboardPage() {
    const toolbar = document.getElementById("page-toolbar");
    const summaryHost = document.getElementById("page-summary");
    const surface = document.getElementById("page-surface");
    const summary = state.data.summary;
    const recentEvents = state.data.events.slice(0, 6);
    const staleWorkers = state.data.workers.filter((worker) => worker.status === "stale").slice(0, 6);
    const busiestQueues = state.data.queues.slice(0, 5);

    toolbar.innerHTML = `
        <div class="maestro-page-toolbar-main">
            <span class="ui-badge">${summary.runningWorkers} running</span>
            <span class="ui-badge">${summary.busyWorkers} busy</span>
            <span class="ui-badge">${summary.failedEvents} failed events</span>
        </div>
        <div class="maestro-page-toolbar-side">
            <button type="button" class="ui-button ui-button-primary" data-jump="workers">Open Workers</button>
            <button type="button" class="ui-button ui-button-quiet" data-jump="worker-events">Open Events</button>
        </div>
    `;

    toolbar.querySelectorAll("[data-jump]").forEach((button) => {
        button.addEventListener("click", () => navigateToPage(button.dataset.jump));
    });

    summaryHost.classList.remove("is-empty");
    summaryHost.innerHTML = `
        <div class="maestro-stats">
            ${renderStat("Applications", state.data.applications.length, "Registered producer surfaces.")}
            ${renderStat("Workers", state.data.workers.length, "Current worker registry size.")}
            ${renderStat("Queues", state.data.queues.length, "Unique queues derived from workers/events.")}
            ${renderStat("Events", state.data.events.length, "Recent worker events loaded from the feed.")}
        </div>
    `;

    surface.innerHTML = `
        <div class="maestro-dashboard-columns">
            <section class="ui-panel maestro-dashboard-column">
                <div class="ui-shell-header">
                    <div>
                        <p class="ui-eyebrow">Stale Workers</p>
                        <h3 class="ui-title">Immediate attention</h3>
                    </div>
                    <span class="ui-badge">${summary.staleWorkers} stale</span>
                </div>
                <div class="maestro-panel-scroll maestro-card-list" id="dashboard-stale"></div>
            </section>
            <section class="ui-panel maestro-dashboard-column">
                <div class="ui-shell-header">
                    <div>
                        <p class="ui-eyebrow">Queue Coverage</p>
                        <h3 class="ui-title">Busiest derived queues</h3>
                    </div>
                    <span class="ui-badge">${state.data.queues.length} queues</span>
                </div>
                <div class="maestro-panel-scroll maestro-card-list" id="dashboard-queues"></div>
            </section>
            <section class="ui-panel maestro-dashboard-column">
                <div class="ui-shell-header">
                    <div>
                        <p class="ui-eyebrow">Recent Activity</p>
                        <h3 class="ui-title">Latest worker events</h3>
                    </div>
                    <button type="button" class="ui-button ui-button-link" data-jump="worker-events">View full feed</button>
                </div>
                <div class="maestro-panel-scroll maestro-feed" id="dashboard-events"></div>
            </section>
        </div>
    `;

    surface.querySelector('[data-jump="worker-events"]').addEventListener("click", () => navigateToPage("worker-events"));

    const staleHost = document.getElementById("dashboard-stale");
    const queueHost = document.getElementById("dashboard-queues");
    const eventHost = document.getElementById("dashboard-events");

    staleHost.innerHTML = staleWorkers.length
        ? staleWorkers.map((worker) => `
            <article class="maestro-card">
                <strong>${escapeHtml(worker.worker_id)}</strong>
                <span class="maestro-inline-meta">
                    <span>${escapeHtml(worker.application?.display_name ?? worker.application?.app_code ?? "Unknown app")}</span>
                    <span>${escapeHtml(worker.queue_name ?? "No queue")}</span>
                </span>
                <span class="maestro-meta">Last heartbeat: ${escapeHtml(formatTimestamp(worker.last_heartbeat_at))}</span>
            </article>
        `).join("")
        : `<div class="maestro-empty is-compact"><strong>No stale workers.</strong><span>Current worker telemetry does not show stale runtime instances.</span></div>`;

    queueHost.innerHTML = busiestQueues.length
        ? busiestQueues.map((queue) => `
            <article class="maestro-card">
                <strong>${escapeHtml(queue.queue_name)}</strong>
                <span class="maestro-inline-meta">
                    <span>${queue.workers_count} workers</span>
                    <span>${queue.events_count} events</span>
                    <span>${queue.failed_events_count} failed</span>
                </span>
                <span class="maestro-meta">${escapeHtml(queue.app_codes.join(", ") || "No app mapping")}</span>
            </article>
        `).join("")
        : `<div class="maestro-empty is-compact"><strong>No queue data yet.</strong><span>Queues will appear as soon as workers or events report queue names.</span></div>`;

    eventHost.innerHTML = recentEvents.length
        ? recentEvents.map((event) => `
            <article class="maestro-card">
                <strong>${escapeHtml(event.event_type)}</strong>
                <span class="maestro-inline-meta">
                    <span>${escapeHtml(event.worker_id)}</span>
                    <span>${escapeHtml(event.queue_name ?? "No queue")}</span>
                    <span>${escapeHtml(event.outcome ?? "n/a")}</span>
                </span>
                <span class="maestro-meta">${escapeHtml(formatTimestamp(event.occurred_at))}</span>
            </article>
        `).join("")
        : `<div class="maestro-empty is-compact"><strong>No events returned.</strong><span>The event feed will populate once worker events are ingested.</span></div>`;
}

function renderWorkersPage() {
    const toolbar = document.getElementById("page-toolbar");
    const summaryHost = document.getElementById("page-summary");
    const surface = document.getElementById("page-surface");
    const filters = state.filters.workers;

    summaryHost.classList.add("is-empty");
    summaryHost.innerHTML = "";
    surface.classList.add("is-grid-fill-surface");

    toolbar.innerHTML = `
        <div class="maestro-page-toolbar-main">
            <input class="ui-input maestro-toolbar-search" id="workers-search" type="text" placeholder="Search worker id, host, queue, job" value="${escapeAttribute(filters.search)}">
            ${renderSelect("workers-app", getApplicationOptions(), filters.appCode, "All applications")}
            ${renderSelect("workers-status", getStatusOptions(), filters.status, "All statuses")}
            ${renderSelect("workers-queue", getQueueOptions(), filters.queueName, "All queues")}
        </div>
        <div class="maestro-page-toolbar-side">
            <span class="ui-badge">${applyWorkerFilters().length} shown / ${state.data.workers.length} loaded</span>
            <button type="button" class="ui-button ui-button-quiet" data-action="reset-workers">Reset</button>
        </div>
    `;

    surface.innerHTML = `<div class="maestro-grid-host" id="workers-grid-host"></div>`;
    bindWorkerFilters();
    renderWorkersGrid();
}

function renderWorkerEventsPage() {
    const toolbar = document.getElementById("page-toolbar");
    const summaryHost = document.getElementById("page-summary");
    const surface = document.getElementById("page-surface");
    const filters = state.filters.workerEvents;

    summaryHost.classList.add("is-empty");
    summaryHost.innerHTML = "";
    surface.classList.add("is-grid-fill-surface");

    toolbar.innerHTML = `
        <div class="maestro-page-toolbar-main">
            <input class="ui-input maestro-toolbar-search" id="events-search" type="text" placeholder="Search event, worker, job, queue" value="${escapeAttribute(filters.search)}">
            ${renderSelect("events-app", getApplicationOptions(), filters.appCode, "All applications")}
            ${renderSelect("events-outcome", getOutcomeOptions(), filters.outcome, "All outcomes")}
            ${renderSelect("events-queue", getQueueOptions(), filters.queueName, "All queues")}
        </div>
        <div class="maestro-page-toolbar-side">
            <span class="ui-badge">${applyEventFilters().length} shown / ${state.data.events.length} loaded</span>
            <button type="button" class="ui-button ui-button-quiet" data-action="reset-events">Reset</button>
        </div>
    `;

    surface.innerHTML = `<div class="maestro-grid-host" id="events-grid-host"></div>`;
    bindEventFilters();
    renderWorkerEventsGrid();
}

function renderApplicationsPage() {
    const toolbar = document.getElementById("page-toolbar");
    const summaryHost = document.getElementById("page-summary");
    const surface = document.getElementById("page-surface");
    const filters = state.filters.applications;

    summaryHost.classList.add("is-empty");
    summaryHost.innerHTML = "";
    surface.classList.add("is-management-surface");

    toolbar.innerHTML = `
        <div class="maestro-page-toolbar-main">
            <input class="ui-input maestro-toolbar-search" id="applications-search" type="text" placeholder="Search code or display name" value="${escapeAttribute(filters.search)}">
            ${renderSelect("applications-environment", getEnvironmentOptions(), filters.environment, "All environments")}
            ${renderSelect("applications-activity", getActivityOptions(), filters.activity, "All activity")}
        </div>
        <div class="maestro-page-toolbar-side">
            <span class="ui-badge">${applyApplicationFilters().length} shown / ${state.data.applications.length} loaded</span>
            <button type="button" class="ui-button ui-button-primary" data-action="create-application">Create Application</button>
            <button type="button" class="ui-button ui-button-quiet" data-action="reset-applications">Reset</button>
        </div>
    `;

    surface.innerHTML = `
        <div class="maestro-management-layout">
            <section class="ui-panel maestro-management-grid-panel">
                <div class="maestro-grid-host maestro-management-grid" id="applications-grid-host"></div>
            </section>
            <section class="ui-panel maestro-management-panel" id="applications-detail-host"></section>
        </div>
    `;
    bindApplicationFilters();
    renderApplicationsGrid();
    renderApplicationDetailPanel();
}

function renderQueuesPage() {
    const toolbar = document.getElementById("page-toolbar");
    const summaryHost = document.getElementById("page-summary");
    const surface = document.getElementById("page-surface");
    const filters = state.filters.queues;

    summaryHost.classList.add("is-empty");
    summaryHost.innerHTML = "";
    surface.classList.add("is-grid-fill-surface", "is-grid-fill-stack");

    toolbar.innerHTML = `
        <div class="maestro-page-toolbar-main">
            <input class="ui-input maestro-toolbar-search" id="queues-search" type="text" placeholder="Search queue or app code" value="${escapeAttribute(filters.search)}">
            ${renderSelect("queues-app", getApplicationOptions(), filters.appCode, "All applications")}
            ${renderSelect("queues-activity", getQueueActivityOptions(), filters.activity, "All coverage")}
        </div>
        <div class="maestro-page-toolbar-side">
            <span class="ui-badge">${applyQueueFilters().length} shown / ${state.data.queues.length} loaded</span>
            <button type="button" class="ui-button ui-button-quiet" data-action="reset-queues">Reset</button>
        </div>
    `;

    surface.innerHTML = `
        <p class="maestro-helper-note">Queue coverage is currently derived from worker registry and recent worker events. A dedicated queue endpoint can be added later if V1 needs stronger queue depth visibility.</p>
        <div class="maestro-grid-host" id="queues-grid-host"></div>
    `;
    bindQueueFilters();
    renderQueuesGrid();
}

function bindWorkerFilters() {
    document.getElementById("workers-search").addEventListener("input", (event) => {
        state.filters.workers.search = event.target.value;
        renderWorkersGrid();
    });
    document.getElementById("workers-app").addEventListener("change", (event) => {
        state.filters.workers.appCode = event.target.value;
        renderWorkersGrid();
    });
    document.getElementById("workers-status").addEventListener("change", (event) => {
        state.filters.workers.status = event.target.value;
        renderWorkersGrid();
    });
    document.getElementById("workers-queue").addEventListener("change", (event) => {
        state.filters.workers.queueName = event.target.value;
        renderWorkersGrid();
    });
    document.querySelector('[data-action="reset-workers"]').addEventListener("click", () => {
        state.filters.workers = { search: "", appCode: "", status: "", queueName: "" };
        renderPage();
    });
}

function bindEventFilters() {
    document.getElementById("events-search").addEventListener("input", (event) => {
        state.filters.workerEvents.search = event.target.value;
        renderWorkerEventsGrid();
    });
    document.getElementById("events-app").addEventListener("change", (event) => {
        state.filters.workerEvents.appCode = event.target.value;
        renderWorkerEventsGrid();
    });
    document.getElementById("events-outcome").addEventListener("change", (event) => {
        state.filters.workerEvents.outcome = event.target.value;
        renderWorkerEventsGrid();
    });
    document.getElementById("events-queue").addEventListener("change", (event) => {
        state.filters.workerEvents.queueName = event.target.value;
        renderWorkerEventsGrid();
    });
    document.querySelector('[data-action="reset-events"]').addEventListener("click", () => {
        state.filters.workerEvents = { search: "", appCode: "", outcome: "", queueName: "" };
        renderPage();
    });
}

function bindApplicationFilters() {
    document.getElementById("applications-search").addEventListener("input", (event) => {
        state.filters.applications.search = event.target.value;
        renderApplicationsGrid();
    });
    document.getElementById("applications-environment").addEventListener("change", (event) => {
        state.filters.applications.environment = event.target.value;
        renderApplicationsGrid();
    });
    document.getElementById("applications-activity").addEventListener("change", (event) => {
        state.filters.applications.activity = event.target.value;
        renderApplicationsGrid();
    });
    document.querySelector('[data-action="reset-applications"]').addEventListener("click", () => {
        state.filters.applications = { search: "", environment: "", activity: "" };
        renderPage();
    });
    document.querySelector('[data-action="create-application"]').addEventListener("click", () => openCreateApplicationModal());
}

function bindQueueFilters() {
    document.getElementById("queues-search").addEventListener("input", (event) => {
        state.filters.queues.search = event.target.value;
        renderQueuesGrid();
    });
    document.getElementById("queues-app").addEventListener("change", (event) => {
        state.filters.queues.appCode = event.target.value;
        renderQueuesGrid();
    });
    document.getElementById("queues-activity").addEventListener("change", (event) => {
        state.filters.queues.activity = event.target.value;
        renderQueuesGrid();
    });
    document.querySelector('[data-action="reset-queues"]').addEventListener("click", () => {
        state.filters.queues = { search: "", appCode: "", activity: "" };
        renderPage();
    });
}

function renderWorkersGrid() {
    const host = document.getElementById("workers-grid-host");
    const rows = applyWorkerFilters();
    destroyGrid("workers");

    if (!rows.length) {
        renderEmpty(host, {
            title: "No workers matched the current filters.",
            description: "Adjust the worker filters or wait for telemetry to populate the worker registry.",
        });
        return;
    }

    state.grids.workers = state.ui.grid(host, rows, {
        ...buildVirtualGridOptions("worker_id"),
        columns: [
            { key: "worker_id", label: "Worker", sortable: false },
            { key: "application", label: "Application", sortable: false, renderCell: ({ row }) => row.application?.display_name ?? row.application?.app_code ?? "Unknown app" },
            { key: "status", label: "Status", width: "110px", sortable: false, renderCell: ({ row }) => renderStatusMarkup(row.status) },
            { key: "queue_name", label: "Queue", width: "180px", sortable: false },
            { key: "last_heartbeat_at", label: "Last heartbeat", width: "220px", sortable: false, renderCell: ({ row }) => formatTimestamp(row.last_heartbeat_at) },
            { key: "processed_count", label: "Processed", width: "100px", sortable: false },
            { key: "failed_count", label: "Failed", width: "90px", sortable: false },
        ],
        emptyText: "No workers returned.",
    });
}

function renderWorkerEventsGrid() {
    const host = document.getElementById("events-grid-host");
    const rows = applyEventFilters();
    destroyGrid("workerEvents");

    if (!rows.length) {
        renderEmpty(host, {
            title: "No worker events matched the current filters.",
            description: "Adjust the event filters or wait for telemetry events to be ingested.",
        });
        return;
    }

    state.grids.workerEvents = state.ui.grid(host, rows, {
        ...buildVirtualGridOptions("event_id"),
        columns: [
            { key: "event_type", label: "Event", sortable: false, width: "170px" },
            { key: "worker_id", label: "Worker", sortable: false },
            { key: "queue_name", label: "Queue", sortable: false, width: "160px" },
            { key: "job_type", label: "Job", sortable: false, width: "180px" },
            { key: "outcome", label: "Outcome", sortable: false, width: "110px" },
            { key: "occurred_at", label: "Occurred", sortable: false, width: "220px", renderCell: ({ row }) => formatTimestamp(row.occurred_at) },
        ],
        emptyText: "No worker events returned.",
    });
}

function renderApplicationsGrid() {
    const host = document.getElementById("applications-grid-host");
    const rows = applyApplicationFilters();
    destroyGrid("applications");

    if (!rows.length) {
        renderEmpty(host, {
            title: "No applications matched the current filters.",
            description: "Adjust the application filters or register additional producer applications.",
        });
        renderApplicationDetailPanel();
        return;
    }

    normalizeApplicationManagementState(rows);

    state.grids.applications = state.ui.grid(host, rows, {
        ...buildVirtualGridOptions("app_code"),
        columns: [
            { key: "display_name", label: "Application", sortable: false },
            { key: "app_code", label: "Code", sortable: false, width: "120px" },
            { key: "environment", label: "Environment", sortable: false, width: "120px" },
            { key: "workers_count", label: "Workers", sortable: false, width: "100px" },
            { key: "busy_workers_count", label: "Busy", sortable: false, width: "90px" },
            { key: "stale_workers_count", label: "Stale", sortable: false, width: "90px" },
            { key: "active_telemetry_tokens_count", label: "Tokens", sortable: false, width: "90px" },
            { key: "last_seen_at", label: "Last seen", sortable: false, width: "220px", renderCell: ({ row }) => formatTimestamp(row.last_seen_at) },
            {
                key: "actions",
                label: "Actions",
                sortable: false,
                width: "210px",
                renderCell: ({ row }) => {
                    const actions = document.createElement("div");
                    actions.className = "ui-inline";

                    const manageButton = document.createElement("button");
                    manageButton.type = "button";
                    manageButton.className = "ui-button ui-button-quiet ui-button-sm";
                    manageButton.textContent = "Manage";
                    manageButton.addEventListener("click", (event) => {
                        event.stopPropagation();
                        state.management.selectedApplicationCode = row.app_code;
                        renderApplicationDetailPanel();
                    });

                    const issueButton = document.createElement("button");
                    issueButton.type = "button";
                    issueButton.className = "ui-button ui-button-quiet ui-button-sm";
                    issueButton.textContent = "Issue Token";
                    issueButton.addEventListener("click", (event) => {
                        event.stopPropagation();
                        openIssueTelemetryTokenModal(row.app_code);
                    });

                    actions.append(manageButton, issueButton);
                    return actions;
                },
            },
        ],
        emptyText: "No applications returned.",
    });

    renderApplicationDetailPanel();
}

function renderQueuesGrid() {
    const host = document.getElementById("queues-grid-host");
    const rows = applyQueueFilters();
    destroyGrid("queues");

    if (!rows.length) {
        renderEmpty(host, {
            title: "No queues matched the current filters.",
            description: "Queues appear only when worker or event telemetry reports queue names.",
        });
        return;
    }

    state.grids.queues = state.ui.grid(host, rows, {
        ...buildVirtualGridOptions("queue_name"),
        columns: [
            { key: "queue_name", label: "Queue", sortable: false },
            { key: "workers_count", label: "Workers", sortable: false, width: "100px" },
            { key: "running_workers_count", label: "Running", sortable: false, width: "100px" },
            { key: "stale_workers_count", label: "Stale", sortable: false, width: "90px" },
            { key: "events_count", label: "Events", sortable: false, width: "90px" },
            { key: "failed_events_count", label: "Failed", sortable: false, width: "90px" },
            { key: "app_codes", label: "Applications", sortable: false, renderCell: ({ row }) => row.app_codes.join(", ") },
        ],
        emptyText: "No queues returned.",
    });
}

async function refreshCurrentUser() {
    const response = await fetch(bootstrap.routes.user, {
        credentials: "same-origin",
        headers: { Accept: "application/json" },
    });

    if ((response.status === 401 || response.status === 419) && state.authenticated) {
        await handleExpiredSession();
        throw new Error("Session expired.");
    }

    if (!response.ok) {
        state.account = null;
        state.authenticated = false;
        clearSessionDeadline();
        render();
        return;
    }

    const payload = await response.json().catch(() => ({}));
    applySessionPayload(payload.data ?? {});
    touchServerSession();
}

async function loadProtectedData(options = {}) {
    const normalizedOptions = typeof options === "boolean"
        ? { force: options, silent: false }
        : {
            force: Boolean(options.force),
            silent: Boolean(options.silent),
        };
    const { force, silent } = normalizedOptions;

    if (!state.authenticated) {
        state.data.loaded = false;
        stopProtectedDataPolling();
        render();
        return;
    }

    if (state.data.loading) {
        return;
    }

    if (state.data.loaded && !force) {
        render();
        return;
    }

    state.data.loading = true;

    try {
        const [applicationsRes, workersRes, eventsRes] = await Promise.all([
            apiFetch(bootstrap.routes.applications, { touchSession: !silent }),
            apiFetch(bootstrap.routes.workers, { touchSession: !silent }),
            apiFetch(`${bootstrap.routes.workerEvents}?limit=200`, { touchSession: !silent }),
        ]);

        const [applicationsPayload, workersPayload, eventsPayload] = await Promise.all([
            applicationsRes.json(),
            workersRes.json(),
            eventsRes.json(),
        ]);

        state.data.applications = Array.isArray(applicationsPayload.data) ? applicationsPayload.data : [];
        state.data.workers = Array.isArray(workersPayload.data) ? workersPayload.data : [];
        state.data.events = Array.isArray(eventsPayload.data) ? eventsPayload.data : [];
        state.data.queues = deriveQueues(state.data.workers, state.data.events);
        state.data.summary = deriveSummary(state.data.workers, state.data.events);
        normalizeApplicationManagementState();
        state.data.loaded = true;
        render();
        if (!silent && force) {
            showToast("Maestro data refreshed.", "success");
        }
        startProtectedDataPolling();
    } catch (error) {
        if (error.message !== "Session expired.") {
            if (!silent) {
                showToast("Protected data could not be loaded.", "error");
            }
            render();
        }
    } finally {
        state.data.loading = false;
    }
}

function deriveSummary(workers, events) {
    return {
        runningWorkers: workers.filter((worker) => worker.status === "running").length,
        staleWorkers: workers.filter((worker) => worker.status === "stale").length,
        busyWorkers: workers.filter((worker) => worker.status === "busy").length,
        failedEvents: events.filter((event) => String(event.outcome ?? "").toLowerCase() === "failure").length,
    };
}

function deriveQueues(workers, events) {
    const buckets = new Map();

    workers.forEach((worker) => {
        const queueName = String(worker.queue_name ?? "").trim();
        if (!queueName) return;

        const bucket = ensureQueueBucket(buckets, queueName);
        bucket.workers_count += 1;
        bucket.processed_count += Number(worker.processed_count ?? 0) || 0;
        bucket.failed_jobs_count += Number(worker.failed_count ?? 0) || 0;
        if (worker.status === "running" || worker.status === "busy") bucket.running_workers_count += 1;
        if (worker.status === "stale") bucket.stale_workers_count += 1;
        if (worker.application?.app_code) bucket.app_codes.add(worker.application.app_code);
        if (worker.last_heartbeat_at && (!bucket.last_seen_at || worker.last_heartbeat_at > bucket.last_seen_at)) {
            bucket.last_seen_at = worker.last_heartbeat_at;
        }
    });

    events.forEach((event) => {
        const queueName = String(event.queue_name ?? "").trim();
        if (!queueName) return;

        const bucket = ensureQueueBucket(buckets, queueName);
        bucket.events_count += 1;
        if (String(event.outcome ?? "").toLowerCase() === "failure") bucket.failed_events_count += 1;
        if (event.application?.app_code) bucket.app_codes.add(event.application.app_code);
        if (event.occurred_at && (!bucket.last_seen_at || event.occurred_at > bucket.last_seen_at)) {
            bucket.last_seen_at = event.occurred_at;
        }
    });

    return Array.from(buckets.values()).map((bucket) => ({
        ...bucket,
        app_codes: Array.from(bucket.app_codes).sort(),
    })).sort((left, right) => {
        return (right.workers_count + right.events_count) - (left.workers_count + left.events_count)
            || left.queue_name.localeCompare(right.queue_name);
    });
}

function ensureQueueBucket(map, queueName) {
    if (!map.has(queueName)) {
        map.set(queueName, {
            queue_name: queueName,
            workers_count: 0,
            running_workers_count: 0,
            stale_workers_count: 0,
            events_count: 0,
            failed_events_count: 0,
            processed_count: 0,
            failed_jobs_count: 0,
            last_seen_at: null,
            app_codes: new Set(),
        });
    }

    return map.get(queueName);
}

function applyWorkerFilters() {
    const filters = state.filters.workers;
    return state.data.workers.filter((worker) => {
        const search = filters.search.trim().toLowerCase();
        const haystack = [
            worker.worker_id,
            worker.host_name,
            worker.queue_name,
            worker.current_job_type,
            worker.application?.app_code,
            worker.application?.display_name,
        ].join(" ").toLowerCase();

        if (search && !haystack.includes(search)) return false;
        if (filters.appCode && worker.application?.app_code !== filters.appCode) return false;
        if (filters.status && worker.status !== filters.status) return false;
        if (filters.queueName && worker.queue_name !== filters.queueName) return false;
        return true;
    });
}

function applyEventFilters() {
    const filters = state.filters.workerEvents;
    return state.data.events.filter((event) => {
        const search = filters.search.trim().toLowerCase();
        const haystack = [
            event.event_type,
            event.worker_id,
            event.queue_name,
            event.job_type,
            event.job_id,
            event.application?.app_code,
            event.application?.display_name,
        ].join(" ").toLowerCase();

        if (search && !haystack.includes(search)) return false;
        if (filters.appCode && event.application?.app_code !== filters.appCode) return false;
        if (filters.outcome && String(event.outcome ?? "") !== filters.outcome) return false;
        if (filters.queueName && String(event.queue_name ?? "") !== filters.queueName) return false;
        return true;
    });
}

function applyApplicationFilters() {
    const filters = state.filters.applications;
    return state.data.applications.filter((application) => {
        const search = filters.search.trim().toLowerCase();
        const haystack = [application.display_name, application.app_code, application.environment].join(" ").toLowerCase();

        if (search && !haystack.includes(search)) return false;
        if (filters.environment && application.environment !== filters.environment) return false;
        if (filters.activity === "active" && !(application.workers_count > 0)) return false;
        if (filters.activity === "stale" && !(application.stale_workers_count > 0)) return false;
        return true;
    });
}

function applyQueueFilters() {
    const filters = state.filters.queues;
    return state.data.queues.filter((queue) => {
        const search = filters.search.trim().toLowerCase();
        const haystack = [queue.queue_name, ...queue.app_codes].join(" ").toLowerCase();

        if (search && !haystack.includes(search)) return false;
        if (filters.appCode && !queue.app_codes.includes(filters.appCode)) return false;
        if (filters.activity === "stale" && !(queue.stale_workers_count > 0)) return false;
        if (filters.activity === "failed" && !(queue.failed_events_count > 0 || queue.failed_jobs_count > 0)) return false;
        if (filters.activity === "running" && !(queue.running_workers_count > 0)) return false;
        return true;
    });
}

async function apiFetch(url, options = {}) {
    const { touchSession = true, ...fetchOptions } = options;
    const response = await fetch(url, {
        credentials: "same-origin",
        headers: {
            Accept: "application/json",
            "Content-Type": "application/json",
            "X-CSRF-TOKEN": state.csrfToken,
            ...(fetchOptions.headers || {}),
        },
        ...fetchOptions,
    });

    if ((response.status === 401 || response.status === 419) && state.authenticated) {
        await handleExpiredSession();
        throw new Error("Session expired.");
    }

    if (response.ok && state.authenticated && touchSession) {
        touchServerSession();
    }

    return response;
}

async function refreshCsrfToken() {
    const response = await fetch(bootstrap.routes.csrfToken, {
        credentials: "same-origin",
        headers: { Accept: "application/json" },
    });

    if (!response.ok) {
        throw new Error("CSRF token could not be refreshed.");
    }

    const payload = await response.json().catch(() => ({}));
    setCsrfToken(payload.data?.csrf_token ?? state.csrfToken);
}

async function openLoginModal() {
    const modal = state.ui.loginFormModal({
        title: "Operator Login",
        message: "Please sign in to continue.",
        submitLabel: "Login",
        busyMessage: "Signing in...",
        async onSubmit(values, ctx) {
            await refreshCsrfToken();

            const response = await fetch(bootstrap.routes.login, {
                method: "POST",
                credentials: "same-origin",
                headers: {
                    Accept: "application/json",
                    "Content-Type": "application/json",
                    "X-CSRF-TOKEN": state.csrfToken,
                },
                body: JSON.stringify({
                    email: String(values.email ?? "").trim(),
                    password: String(values.password ?? ""),
                }),
            });

            const result = await response.json().catch(() => ({}));

            if (!response.ok) {
                const message = String(result.message ?? "Login failed.");
                ctx.setErrors({ password: message });
                await state.ui.alert(message, { title: "Login Failed", size: "sm" });
                return false;
            }

            applySessionPayload(result.data ?? {});
            state.data.loaded = false;
            await refreshCurrentUser();
            await loadProtectedData(true);
            render();
            showToast("Operator session established.", "success");
            return true;
        },
    });

    modal.open();
}

async function openAccountModal() {
    if (!state.authenticated || !state.account) return;

    const modal = state.ui.accountFormModal({
        title: "Account",
        submitLabel: "Save",
        busyMessage: "Saving account...",
        initialValues: {
            name: state.account.name ?? "",
            email: state.account.email ?? "",
        },
        extraActionsPlacement: "start",
        extraActions: [
            {
                id: "change-password",
                label: "Change Password",
                variant: "ghost",
                closeOnClick: true,
                onClick() {
                    window.setTimeout(() => {
                        void openChangePasswordModal();
                    }, 0);
                    return true;
                },
            },
        ],
        async onSubmit(values, ctx) {
            const response = await apiFetch(bootstrap.routes.userUpdate, {
                method: "POST",
                body: JSON.stringify({
                    name: String(values.name ?? "").trim(),
                    email: String(values.email ?? "").trim(),
                }),
            });

            const result = await response.json().catch(() => ({}));

            if (!response.ok) {
                ctx.setErrors(result.errors ?? {});
                ctx.setFormError(String(result.message ?? "Account could not be updated."));
                return false;
            }

            applySessionPayload(result.data ?? {});
            render();
            showToast("Account updated.", "success");
            return true;
        },
    });

    modal.open();
}

async function openChangePasswordModal() {
    if (!state.authenticated) return;

    const modal = state.ui.changePasswordFormModal({
        title: "Change Password",
        submitLabel: "Update Password",
        busyMessage: "Updating password...",
        async onSubmit(values, ctx) {
            const response = await apiFetch(bootstrap.routes.userPassword, {
                method: "POST",
                body: JSON.stringify({
                    current_password: String(values.current_password ?? ""),
                    new_password: String(values.new_password ?? ""),
                    confirm_password: String(values.confirm_password ?? ""),
                }),
            });

            const result = await response.json().catch(() => ({}));

            if (!response.ok) {
                ctx.setErrors(result.errors ?? {});
                ctx.setFormError(String(result.message ?? "Password could not be updated."));
                return false;
            }

            applySessionPayload(result.data ?? {});
            render();
            showToast("Password updated.", "success");
            return true;
        },
    });

    modal.open();
}

async function handleExpiredSession() {
    if (state.session.reloginOpen || !state.account?.email) return;
    clearSessionDeadline();
    stopProtectedDataPolling();
    state.session.reloginOpen = true;

    const modal = state.ui.reauthFormModal({
        title: "Session Expired",
        message: "Your session has expired. To continue, please enter your password again.",
        submitLabel: "Login",
        busyMessage: "Signing in...",
        identifierKind: "email",
        identifierValue: state.account.email,
        cancelLabel: "Cancel",
        showCloseButton: false,
        closeOnBackdrop: false,
        closeOnEscape: false,
        onClose: () => {
            state.session.reloginOpen = false;
        },
        async onSubmit(values, ctx) {
            await refreshCsrfToken();

            const response = await fetch(bootstrap.routes.login, {
                method: "POST",
                credentials: "same-origin",
                headers: {
                    Accept: "application/json",
                    "Content-Type": "application/json",
                    "X-CSRF-TOKEN": state.csrfToken,
                },
                body: JSON.stringify({
                    email: state.account?.email ?? "",
                    password: String(values.password ?? ""),
                }),
            });

            const result = await response.json().catch(() => ({}));

            if (!response.ok) {
                const message = String(result.message ?? "Login failed.");
                ctx.setErrors({ password: message });
                await state.ui.alert(message, { title: "Login Failed", size: "sm" });
                return false;
            }

            applySessionPayload(result.data ?? {});
            state.data.loaded = false;
            await refreshCurrentUser();
            await loadProtectedData(true);
            render();
            showToast("Session restored.", "success");
            return true;
        },
    });

    modal.update?.({
        actions: [
            {
                id: "cancel",
                label: "Cancel",
                variant: "ghost",
                closeOnClick: false,
                onClick() {
                    window.location.reload();
                    return false;
                },
            },
            {
                id: "submit",
                label: "Login",
                variant: "primary",
                submit: true,
            },
        ],
    });

    modal.open();
}

async function logout() {
    const response = await fetch(bootstrap.routes.logout, {
        method: "POST",
        credentials: "same-origin",
        headers: {
            Accept: "application/json",
            "Content-Type": "application/json",
            "X-CSRF-TOKEN": state.csrfToken,
        },
    });

    const payload = await response.json().catch(() => ({}));

    if (!response.ok) {
        showToast("Logout failed.", "error");
        return;
    }

    applySessionPayload({ account: null, csrf_token: payload.data?.csrf_token ?? state.csrfToken });
    state.data.loaded = false;
    state.data.applications = [];
    state.data.workers = [];
    state.data.events = [];
    state.data.queues = [];
    state.data.summary = deriveSummary([], []);
    state.management.selectedApplicationCode = "";
    state.management.lastIssuedToken = null;
    stopProtectedDataPolling();
    render();
    showToast("Operator session cleared.", "info");
}

function applySessionPayload(data = {}) {
    state.account = data.account ?? null;
    state.authenticated = Boolean(state.account);
    setCsrfToken(data.csrf_token ?? state.csrfToken);
    if (state.authenticated) {
        recordUserActivity();
        touchServerSession();
        if (!document.hidden) {
            startProtectedDataPolling();
        }
    } else {
        clearSessionDeadline();
        stopProtectedDataPolling();
    }
    window.__PBB_BOOTSTRAP__ = window.__PBB_BOOTSTRAP__ || {};
    window.__PBB_BOOTSTRAP__.auth = {
        authenticated: state.authenticated,
        account: state.account,
    };
}

function touchServerSession() {
    if (!state.authenticated || !(state.session.lifetimeMs > 0)) return;
    state.session.lastServerTouchAt = Date.now();
    state.session.expiresAt = Date.now() + state.session.lifetimeMs;
    state.session.keepaliveCooldownUntil = 0;
    startSessionWatcher();
}

function getKeepaliveWindowMs() {
    if (!(state.session.lifetimeMs > 0)) return 0;
    return Math.min(
        SESSION_KEEPALIVE_WINDOW_MAX_MS,
        Math.max(SESSION_KEEPALIVE_MIN_WINDOW_MS, Math.floor(state.session.lifetimeMs * 0.25)),
    );
}

function getKeepaliveActivityWindowMs() {
    if (!(state.session.lifetimeMs > 0)) return 0;
    return Math.min(
        SESSION_KEEPALIVE_ACTIVITY_WINDOW_MAX_MS,
        Math.max(SESSION_ACTIVITY_WINDOW_MIN_MS, Math.floor(state.session.lifetimeMs * 0.5)),
    );
}

function shouldAttemptKeepalive(remainingMs) {
    if (!state.authenticated || document.hidden || state.session.reloginOpen) return false;
    if (!(state.session.lifetimeMs > 0)) return false;
    if (!(state.session.lastServerTouchAt > 0) || !(state.session.lastUserActivityAt > 0)) return false;
    if (state.session.keepaliveInFlight) return false;
    if (Date.now() < state.session.keepaliveCooldownUntil) return false;
    if (remainingMs > getKeepaliveWindowMs()) return false;
    if (Date.now() - state.session.lastUserActivityAt > getKeepaliveActivityWindowMs()) return false;
    return true;
}

async function keepaliveSession() {
    if (!state.authenticated || state.session.keepaliveInFlight) return;
    state.session.keepaliveInFlight = true;

    try {
        const response = await apiFetch(bootstrap.routes.sessionPing, {
            method: "GET",
        });

        if (!response.ok) {
            throw new Error("Keepalive failed.");
        }

        const payload = await response.json().catch(() => ({}));
        if (payload.data?.csrf_token) {
            setCsrfToken(payload.data.csrf_token);
        }
        touchServerSession();
    } catch (error) {
        if (error.message === "Session expired.") {
            return;
        }

        state.session.keepaliveCooldownUntil = Date.now() + SESSION_KEEPALIVE_COOLDOWN_MS;
    } finally {
        state.session.keepaliveInFlight = false;
    }
}

function clearSessionDeadline() {
    state.session.expiresAt = 0;
    state.session.lastServerTouchAt = 0;
    state.session.lastUserActivityAt = 0;
    state.session.keepaliveInFlight = false;
    state.session.keepaliveCooldownUntil = 0;
    if (state.session.timerId) {
        window.clearInterval(state.session.timerId);
        state.session.timerId = null;
    }
}

function startSessionWatcher() {
    if (state.session.timerId || !(state.session.lifetimeMs > 0)) return;

    state.session.timerId = window.setInterval(async () => {
        if (!state.authenticated || state.session.reloginOpen || !(state.session.expiresAt > 0)) {
            return;
        }

        const remainingMs = state.session.expiresAt - Date.now();

        if (remainingMs <= 0) {
            await handleExpiredSession();
            return;
        }

        if (shouldAttemptKeepalive(remainingMs)) {
            await keepaliveSession();
        }

        if (Date.now() < state.session.expiresAt) {
            return;
        }

        await handleExpiredSession();
    }, 1000);
}

function startProtectedDataPolling() {
    if (!state.authenticated || document.hidden || state.session.reloginOpen) return;
    if (state.polling.timerId) return;

    state.polling.timerId = window.setInterval(() => {
        if (!state.authenticated || document.hidden || state.session.reloginOpen) {
            return;
        }

        loadProtectedData({ force: true, silent: true });
    }, state.polling.intervalMs);
}

function stopProtectedDataPolling() {
    if (!state.polling.timerId) return;
    window.clearInterval(state.polling.timerId);
    state.polling.timerId = null;
}

function setCsrfToken(nextToken) {
    state.csrfToken = String(nextToken ?? state.csrfToken ?? "").trim();
    const meta = document.querySelector('meta[name="csrf-token"]');
    if (meta) {
        meta.setAttribute("content", state.csrfToken);
    }
    window.__PBB_BOOTSTRAP__ = window.__PBB_BOOTSTRAP__ || {};
    window.__PBB_BOOTSTRAP__.security = window.__PBB_BOOTSTRAP__.security || {};
    window.__PBB_BOOTSTRAP__.security.csrfToken = state.csrfToken;
}

function renderSelect(id, options, value, emptyLabel) {
    return `
        <select class="ui-input maestro-select" id="${id}">
            <option value="">${escapeHtml(emptyLabel)}</option>
            ${options.map((option) => `
                <option value="${escapeAttribute(option.value)}" ${String(option.value) === String(value) ? "selected" : ""}>
                    ${escapeHtml(option.label)}
                </option>
            `).join("")}
        </select>
    `;
}

function renderApplicationDetailPanel() {
    const host = document.getElementById("applications-detail-host");
    if (!host) return;

    const application = getSelectedApplication();

    if (!application) {
        host.innerHTML = `
            <div class="ui-shell-header">
                <div>
                    <p class="ui-eyebrow">Application Management</p>
                    <h3 class="ui-title">No application selected</h3>
                </div>
            </div>
            <div class="maestro-empty is-compact">
                <strong>Create an application or select one from the grid.</strong>
                <span>Maestro application records are required before a system can issue telemetry tokens and send worker heartbeat or event data.</span>
            </div>
        `;
        return;
    }

    const tokens = Array.isArray(application.telemetry_tokens) ? application.telemetry_tokens : [];
    const issuedToken = state.management.lastIssuedToken?.app_code === application.app_code ? state.management.lastIssuedToken : null;

    host.innerHTML = `
        <div class="ui-shell-header">
            <div>
                <p class="ui-eyebrow">Application Management</p>
                <h3 class="ui-title">${escapeHtml(application.display_name)}</h3>
            </div>
            <div class="ui-inline">
                <span class="ui-badge">${escapeHtml(application.app_code)}</span>
                <span class="ui-badge">${application.active_telemetry_tokens_count} active tokens</span>
            </div>
        </div>
        <div class="maestro-detail-stack">
            ${issuedToken ? `
                <section class="maestro-token-reveal">
                    <div class="maestro-token-reveal-copy">
                        <strong>New telemetry token issued</strong>
                        <span>Copy and store this now. Maestro will not show this plain-text token again after you leave this state.</span>
                    </div>
                    <code class="maestro-token-secret">${escapeHtml(issuedToken.token)}</code>
                    <div class="ui-inline">
                        <button type="button" class="ui-button ui-button-primary" data-action="copy-issued-token">Copy Token</button>
                        <button type="button" class="ui-button ui-button-quiet" data-action="dismiss-issued-token">Dismiss</button>
                    </div>
                </section>
            ` : ""}
            <section class="maestro-detail-meta">
                <div><span class="maestro-detail-key">Environment</span><strong>${escapeHtml(application.environment || "local")}</strong></div>
                <div><span class="maestro-detail-key">Base URL</span><strong>${escapeHtml(application.base_url || "Not set")}</strong></div>
                <div><span class="maestro-detail-key">Workers</span><strong>${application.workers_count}</strong></div>
                <div><span class="maestro-detail-key">Last token use</span><strong>${escapeHtml(formatTimestamp(application.last_token_used_at))}</strong></div>
            </section>
            <section>
                <div class="ui-shell-header">
                    <div>
                        <p class="ui-eyebrow">Telemetry Tokens</p>
                        <h4 class="ui-title">Issue and review tokens</h4>
                    </div>
                    <button type="button" class="ui-button ui-button-primary" data-action="issue-token">Issue Token</button>
                </div>
                ${tokens.length ? `
                    <div class="maestro-token-list">
                        ${tokens.map((token) => `
                            <article class="maestro-token-item">
                                <div>
                                    <strong>${escapeHtml(token.label)}</strong>
                                    <span>${escapeHtml(token.revoked_at ? "Revoked" : "Active")}</span>
                                </div>
                                <div>
                                    <span>Created ${escapeHtml(formatTimestamp(token.created_at))}</span>
                                    <span>Last used ${escapeHtml(formatTimestamp(token.last_used_at))}</span>
                                </div>
                            </article>
                        `).join("")}
                    </div>
                ` : `<div class="maestro-empty is-compact"><strong>No telemetry tokens yet.</strong><span>Issue a token before this application can send heartbeat or worker event telemetry.</span></div>`}
            </section>
        </div>
    `;

    host.querySelector('[data-action="issue-token"]')?.addEventListener("click", () => openIssueTelemetryTokenModal(application.app_code));
    host.querySelector('[data-action="copy-issued-token"]')?.addEventListener("click", async () => copyIssuedToken());
    host.querySelector('[data-action="dismiss-issued-token"]')?.addEventListener("click", () => {
        state.management.lastIssuedToken = null;
        renderApplicationDetailPanel();
    });
}

function getSelectedApplication() {
    return state.data.applications.find((application) => application.app_code === state.management.selectedApplicationCode) ?? null;
}

function normalizeApplicationManagementState(rows = state.data.applications) {
    const list = Array.isArray(rows) ? rows : [];

    if (!list.length) {
        state.management.selectedApplicationCode = "";
        return;
    }

    if (!state.management.selectedApplicationCode || !list.some((application) => application.app_code === state.management.selectedApplicationCode)) {
        state.management.selectedApplicationCode = list[0].app_code;
    }
}

async function openCreateApplicationModal() {
    const modal = state.ui.formModal({
        title: "Create Application",
        size: "sm",
        submitLabel: "Create Application",
        busyMessage: "Creating application...",
        initialValues: {
            environment: "local",
            issue_telemetry_token: true,
            token_label: "Primary token",
        },
        rows: [
            [{ type: "text", content: "Register a monitored system before issuing telemetry tokens and ingesting worker data." }],
            [{ type: "input", input: "text", name: "app_code", label: "Application code", placeholder: "relay", required: true }],
            [{ type: "input", input: "text", name: "display_name", label: "Display name", placeholder: "PBB Relay", required: true }],
            [{ type: "select", name: "environment", label: "Environment", value: "local", required: true, options: [
                { value: "local", label: "local" },
                { value: "staging", label: "staging" },
                { value: "production", label: "production" },
            ] }],
            [{ type: "input", input: "url", name: "base_url", label: "Base URL", placeholder: "https://relay.pbb.ph" }],
            [{ type: "checkbox", name: "issue_telemetry_token", label: "Issue initial telemetry token now", value: true }],
            [{ type: "input", input: "text", name: "token_label", label: "Initial token label", placeholder: "Primary token" }],
        ],
        async onSubmit(values, ctx) {
            const response = await apiFetch(bootstrap.routes.applications, {
                method: "POST",
                body: JSON.stringify({
                    app_code: String(values.app_code ?? "").trim(),
                    display_name: String(values.display_name ?? "").trim(),
                    environment: String(values.environment ?? "local").trim() || "local",
                    base_url: String(values.base_url ?? "").trim() || null,
                    issue_telemetry_token: Boolean(values.issue_telemetry_token),
                    token_label: String(values.token_label ?? "").trim() || null,
                }),
            });

            const result = await response.json().catch(() => ({}));

            if (!response.ok) {
                applyValidationErrors(ctx, result);
                return false;
            }

            upsertApplication(result.data?.application);
            state.management.selectedApplicationCode = result.data?.application?.app_code ?? state.management.selectedApplicationCode;
            state.management.lastIssuedToken = result.data?.plain_text_token
                ? { app_code: result.data.application.app_code, token: result.data.plain_text_token }
                : null;
            render();
            showToast("Application created.", "success");
            return true;
        },
    });

    modal.open();
}

async function openIssueTelemetryTokenModal(appCode) {
    const application = state.data.applications.find((item) => item.app_code === appCode);
    if (!application) return;

    const modal = state.ui.formModal({
        title: "Issue Telemetry Token",
        size: "sm",
        submitLabel: "Issue Token",
        busyMessage: "Issuing token...",
        initialValues: {
            label: `${application.display_name} token ${new Date().toISOString().slice(0, 10)}`,
        },
        rows: [
            [{ type: "text", content: "Issue a new telemetry token for this application. The plain-text token will be shown once after creation." }],
            [{ type: "display", name: "application", label: "Application", value: `${application.display_name} (${application.app_code})` }],
            [{ type: "input", input: "text", name: "label", label: "Token label", placeholder: "Worker host token", required: true }],
        ],
        async onSubmit(values, ctx) {
            const response = await apiFetch(`${bootstrap.routes.applicationTokensBase}/${encodeURIComponent(appCode)}/tokens`, {
                method: "POST",
                body: JSON.stringify({
                    label: String(values.label ?? "").trim(),
                }),
            });

            const result = await response.json().catch(() => ({}));

            if (!response.ok) {
                applyValidationErrors(ctx, result);
                return false;
            }

            upsertApplication(result.data?.application);
            state.management.selectedApplicationCode = result.data?.application?.app_code ?? appCode;
            state.management.lastIssuedToken = result.data?.plain_text_token
                ? { app_code: result.data.application.app_code, token: result.data.plain_text_token }
                : null;
            renderApplicationDetailPanel();
            renderApplicationsGrid();
            showToast("Telemetry token issued.", "success");
            return true;
        },
    });

    modal.open();
}

function applyValidationErrors(ctx, result) {
    const errors = result?.errors && typeof result.errors === "object" ? result.errors : {};
    const fieldErrors = Object.fromEntries(
        Object.entries(errors).map(([key, value]) => [key, Array.isArray(value) ? String(value[0] ?? "") : String(value ?? "")]),
    );
    if (Object.keys(fieldErrors).length) {
        ctx.setErrors(fieldErrors);
    }
    if (result?.message && !Object.keys(fieldErrors).length) {
        state.ui.alert(String(result.message), { title: "Request Failed", size: "sm" });
    }
}

function upsertApplication(application) {
    if (!application || !application.app_code) return;

    const next = [...state.data.applications];
    const existingIndex = next.findIndex((item) => item.app_code === application.app_code);

    if (existingIndex >= 0) {
        next.splice(existingIndex, 1, application);
    } else {
        next.push(application);
    }

    next.sort((left, right) => String(left.display_name || left.app_code).localeCompare(String(right.display_name || right.app_code)));
    state.data.applications = next;
    normalizeApplicationManagementState();
}

async function copyIssuedToken() {
    const issuedToken = state.management.lastIssuedToken?.token;
    if (!issuedToken) return;

    try {
        await navigator.clipboard.writeText(issuedToken);
        showToast("Telemetry token copied.", "success");
    } catch (error) {
        await state.ui.alert(issuedToken, { title: "Copy failed: token shown below", size: "sm" });
    }
}

function renderStat(label, value, meta) {
    return `
        <article class="maestro-stat">
            <span class="maestro-stat-key">${escapeHtml(label)}</span>
            <strong class="maestro-stat-value">${escapeHtml(value)}</strong>
            <span class="maestro-stat-meta">${escapeHtml(meta)}</span>
        </article>
    `;
}

function renderEmpty(host, options) {
    destroyGridByHost(host);

    if (state.ui.emptyState) {
        host.innerHTML = "";
        state.ui.emptyState(host, {
            actions: options.actionLabel ? [{
                id: "primary",
                label: options.actionLabel,
                className: "ui-button-primary",
            }] : [],
        }, {
            chrome: false,
            title: options.title,
            description: options.description,
            onActionClick: () => options.onAction?.(),
        });
        return;
    }

    host.innerHTML = `<div class="maestro-empty"><strong>${escapeHtml(options.title)}</strong><span>${escapeHtml(options.description)}</span></div>`;
}

function buildVirtualGridOptions(rowKey) {
    return {
        mode: "local",
        rowKey,
        chrome: false,
        enableSort: false,
        enableSearch: false,
        enablePagination: false,
        enableColumnResize: true,
        enableVirtualization: true,
        virtualRowHeight: GRID_ROW_HEIGHT,
        virtualOverscan: GRID_VIRTUAL_OVERSCAN,
        virtualThreshold: GRID_VIRTUAL_THRESHOLD,
        wrapCellContent: false,
    };
}

function destroyGrid(key) {
    state.grids[key]?.destroy?.();
    delete state.grids[key];
}

function destroyGridByHost() {}

function destroyAllGrids() {
    Object.keys(state.grids).forEach((key) => {
        destroyGrid(key);
    });
}

function showToast(message, type = "info") {
    if (state.ui.toast?.show) {
        state.ui.toast.show(String(message), { type });
    }
}

function getUserMenuLabel() {
    const nameInitial = String(state.account?.name ?? "").trim().charAt(0);
    const emailInitial = String(state.account?.email ?? "").trim().charAt(0);
    return (nameInitial || emailInitial || "U").toUpperCase();
}

function getApplicationOptions() {
    return state.data.applications.map((application) => ({
        value: application.app_code,
        label: application.display_name || application.app_code,
    }));
}

function getStatusOptions() {
    return Array.from(new Set(state.data.workers.map((worker) => worker.status).filter(Boolean))).sort().map((status) => ({ value: status, label: status }));
}

function getQueueOptions() {
    return Array.from(new Set([
        ...state.data.workers.map((worker) => worker.queue_name),
        ...state.data.events.map((event) => event.queue_name),
    ].filter(Boolean))).sort().map((queueName) => ({ value: queueName, label: queueName }));
}

function getOutcomeOptions() {
    return Array.from(new Set(state.data.events.map((event) => String(event.outcome ?? "")).filter(Boolean))).sort().map((outcome) => ({ value: outcome, label: outcome }));
}

function getEnvironmentOptions() {
    return Array.from(new Set(state.data.applications.map((application) => application.environment).filter(Boolean))).sort().map((environment) => ({ value: environment, label: environment }));
}

function getActivityOptions() {
    return [
        { value: "active", label: "Has workers" },
        { value: "stale", label: "Has stale workers" },
    ];
}

function getQueueActivityOptions() {
    return [
        { value: "running", label: "Has running workers" },
        { value: "stale", label: "Has stale workers" },
        { value: "failed", label: "Has failures" },
    ];
}

function resolvePageFromLocation() {
    const raw = String(window.location.hash || "#dashboard").replace(/^#\/?/, "");
    return PAGE_DEFS.some((page) => page.key === raw) ? raw : "dashboard";
}

function navigateToPage(pageKey) {
    const next = PAGE_DEFS.some((page) => page.key === pageKey) ? pageKey : "dashboard";
    if (state.page === next) return;
    window.location.hash = `#${next}`;
}

function renderStatusMarkup(status) {
    const normalized = String(status ?? "unknown").toLowerCase();
    const kind = normalized === "running" || normalized === "alive"
        ? "ok"
        : normalized === "stale" || normalized === "warning"
            ? "warn"
            : normalized === "stopped" || normalized === "failed"
                ? "error"
                : "";

    const root = document.createElement("span");
    root.className = "maestro-status";

    const dot = document.createElement("span");
    dot.className = `maestro-status-dot ${kind}`.trim();

    const label = document.createElement("span");
    label.textContent = normalized;

    root.append(dot, label);
    return root;
}

function formatTimestamp(value) {
    if (!value) return "n/a";
    const date = new Date(value);
    if (Number.isNaN(date.getTime())) return String(value);

    return new Intl.DateTimeFormat("en-PH", {
        year: "numeric",
        month: "short",
        day: "2-digit",
        hour: "2-digit",
        minute: "2-digit",
    }).format(date);
}

function escapeHtml(value) {
    return String(value ?? "")
        .replaceAll("&", "&amp;")
        .replaceAll("<", "&lt;")
        .replaceAll(">", "&gt;")
        .replaceAll('"', "&quot;")
        .replaceAll("'", "&#039;");
}

function escapeAttribute(value) {
    return escapeHtml(value).replaceAll("`", "&#096;");
}
