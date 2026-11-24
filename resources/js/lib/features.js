/**
 * features.js: on/off switches for UI features.
 *
 * CCTV_UI: CCTV is disabled in the UI for now (2026-11-24, owner's request). The CCTV menu is shown greyed out and cannot be clicked. Hidden: the
 * batch "Galeri Foto" tab, CCTV status badges on checkpoints, and the CCTV columns in the RFID logs.
 * The backend (capture, routes, data) is unchanged. Set this back to true to show CCTV again.
 */
export const CCTV_UI = false
