/**
 * navigation.js — model navigasi per peran (fungsi murni; label = kunci i18n `nav.*`).
 * Tahap proses berasal dari props Inertia `stages` (ProcessFlow) — tidak ada daftar tahap ganda di frontend.
 * Ikon = nama lucide (lihat components/app/AppIcon.vue).
 */

const OPERATOR_STAGES = {
    operator_lab_station: ['lab_kimia'],
    operator_wet:         ['receiving', 'wet_process'],
    operator_dry:         ['dry_process'],
    operator_lab_ltj:     ['project_plant'],
    operator_warehouse:   ['warehouse'],
}

const RFID_ROLES = ['company_admin', 'operator_lab_station', 'operator_lab_ltj', 'operator_wet', 'operator_dry', 'operator_warehouse']

/** @param {string} role @param {Array<{key:string,label:string,label_en:string,icon:string}>} stages */
export function buildNavigation(role, stages = []) {
    const stageChildren = (keys) => stages
        .filter(s => !keys || keys.includes(s.key))
        .map(s => ({ key: s.key, href: `/company/process/${s.key}`, icon: s.icon, label: s.label, label_en: s.label_en }))

    const rfid = RFID_ROLES.includes(role) ? [
        { key: 'rfid', href: '/company/hardware/rfid', icon: 'nfc', labelKey: 'nav.rfid' },
        {
            key: 'cctv', href: '/company/hardware/cctv', icon: 'video', labelKey: 'nav.cctv',
            children: [
                { key: 'cctv_monitor',   href: '/company/hardware/cctv',  icon: 'video',   labelKey: 'nav.cctv_monitor' },
                { key: 'cctv_snapshots', href: '/company/cctv/snapshots', icon: 'history', labelKey: 'nav.cctv_history' },
            ],
        },
    ] : []

    const dash   = { key: 'dashboard', href: '/company/dashboard', icon: 'layout-dashboard', labelKey: 'nav.dashboard' }
    const batches = (adminLabel) => ({ key: 'batches', href: '/company/batches', icon: 'boxes', labelKey: adminLabel ? 'nav.batches' : 'nav.batch_list' })
    const process = (keys) => ({ key: 'process', href: '/company/process', icon: 'workflow', labelKey: 'nav.process', children: stageChildren(keys) })
    const lab     = { key: 'lab', href: '/company/lab', icon: 'flask-conical', labelKey: 'nav.lab_analysis' }
    const exports = { key: 'exports', href: '/company/exports', icon: 'ship', labelKey: 'nav.export' }
    const reports = { key: 'reports', href: '/company/reports', icon: 'file-text', labelKey: 'nav.reports' }
    const settings = { key: 'settings', href: '/company/settings', icon: 'settings', labelKey: 'nav.settings' }

    switch (role) {
        case 'super_admin':
            return [
                { key: 'dashboard', href: '/super-admin/dashboard', icon: 'layout-dashboard', labelKey: 'nav.dashboard' },
                { key: 'users',     href: '/super-admin/users',     icon: 'users',            labelKey: 'nav.users' },
                { key: 'companies', href: '/super-admin/companies', icon: 'building-2',       labelKey: 'nav.companies' },
            ]
        case 'bim_admin':
        case 'bim_viewer':
        case 'government_auditor':
            return [{ key: 'bim_dashboard', href: '/bim/dashboard', icon: 'layout-dashboard', labelKey: 'nav.bim_dashboard' }]
        case 'company_admin':
            return [dash, batches(true), process(null), lab, exports, reports, settings, ...rfid]
        case 'operator_lab_station':
        case 'operator_wet':
        case 'operator_dry':
            return [dash, batches(false), process(OPERATOR_STAGES[role]), ...rfid]
        case 'operator_lab_ltj':
            return [dash, batches(false), lab, process(OPERATOR_STAGES[role]), ...rfid]
        case 'operator_warehouse':
            return [dash, batches(false), process(OPERATOR_STAGES[role]), exports, ...rfid]
        case 'company_auditor':
            return [dash, batches(false), process(null), lab, exports, reports]
        default:
            return []
    }
}

/** Aktif bila path sama atau di bawahnya; /company/batches hanya cocok persis (agar tak menimpa /batches/create). */
export function isActivePath(currentPath, href) {
    if (!href) return false
    const base = href.split('?')[0]
    const path = (currentPath || '').split('?')[0]
    if (base === '/company/batches') return path === base
    return path === base || path.startsWith(base + '/')
}
