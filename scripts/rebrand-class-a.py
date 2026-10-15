#!/usr/bin/env python3
"""Rebrand MINETRA -> MINETRACE (Kelas A) — idempoten, per berkas/baris.

Pakai: python3 scripts/rebrand-class-a.py [--apply]
Tanpa --apply hanya menampilkan perubahan (dry-run).
Hanya berkas/baris yang sudah diklasifikasi (docs/minetrace/01_RENAME_INVENTORY.md).
"""
import re, sys

PAT = re.compile(r'minetra(?!ce)', re.I)

def repl(m):
    s = m.group(0)
    return 'MINETRACE' if s.isupper() else ('Minetrace' if s[0].isupper() else 'minetrace')

ALL = None  # seluruh berkas
SPEC = {
    # UI
    'resources/js/Layouts/AuthLayout.vue': ALL,
    'resources/js/Layouts/AppLayout.vue': {3, 435, 700},
    'resources/js/Pages/Auth/Login.vue': ALL,
    'resources/js/Pages/Auth/ForgotPassword.vue': ALL,
    'resources/js/Pages/Auth/ResetPassword.vue': ALL,
    'resources/js/Pages/SuperAdmin/Dashboard.vue': ALL,
    'resources/js/Pages/SuperAdmin/Users/Index.vue': ALL,
    'resources/js/Pages/SuperAdmin/Companies/Index.vue': ALL,
    'resources/js/Pages/Company/Batches/Create.vue': {811},
    'resources/js/lang/id.js': ALL,
    'resources/js/lang/en.js': ALL,
    'resources/js/app.js': {9},
    'resources/js/composables/core/useI18n.js': {2},
    'resources/css/app.css': ALL,
    'resources/views/app.blade.php': {8},
    # PDF / e-mail
    **{f'resources/views/pdf/reports/{n}.blade.php': ALL for n in
       ['lab-analysis', 'monthly', 'export-summary', 'mass-balance', 'batch-traceability']},
    'resources/views/pdf/export/packing-list.blade.php': ALL,
    'resources/views/pdf/export/notification.blade.php': ALL,
    'resources/views/pdf/export/coa.blade.php': {49, 188},
    'app/Notifications/ResetPasswordNotification.php': ALL,
    'app/Jobs/Reports/GenerateMonthlyReportJob.php': ALL,
    # API / docblock
    'app/Http/Controllers/Api/V1/Verify/ApiVerifyController.php': ALL,
    'app/Http/Controllers/Api/V1/Batch/ApiBatchController.php': ALL,
    'app/Http/Controllers/SuperAdmin/CompanyController.php': ALL,
    'app/Services/Hardware/RfidService.php': ALL,
    'app/Models/User.php': ALL, 'app/Models/Company.php': ALL, 'app/Enums/UserRole.php': ALL,
    'routes/api.php': ALL, 'routes/web.php': ALL, 'routes/console.php': ALL, 'config/app.php': ALL,
    'database/seeders/Core/DatabaseSeeder.php': ALL,
    'database/seeders/Core/UserSeeder.php': {11},
    'database/seeders/Companies/PtTimah/PtTimahCctvCameraSeeder.php': ALL,
    # skrip & contoh env (hanya banner/komentar)
    'start-local.sh': {5, 112}, 'stop-local.sh': {2}, 'deploy.sh': {9},
    '.env.local.example': {1},
    'docker/mediamtx/mediamtx.yml': {1}, 'docker/mediamtx/mediamtx.prod.yml': {1},
    # dokumen aktif
    'PANDUAN_PENGGUNA_MINETRACE.md': ALL,
    'MINETRACE_PROJECT_RESUME_COMPLETE.md': {1},
    'PRESENTASI_BRIEFING.md': {1, 9, 17},
    'ENVIRONMENT_MIGRATION_CHECKLIST.md': ALL,
    'SKRIP_DEMO_LIVE.md': {1},
}

apply = '--apply' in sys.argv
total = 0
for path, lines in SPEC.items():
    with open(path, encoding='utf-8') as f:
        src = f.read().split('\n')
    changed = 0
    for i, line in enumerate(src, 1):
        if lines is None or i in lines:
            new = PAT.sub(repl, line)
            if new != line:
                changed += 1
                if not apply:
                    print(f'{path}:{i}: {new.strip()[:110]}')
                src[i - 1] = new
    total += changed
    if apply and changed:
        with open(path, 'w', encoding='utf-8') as f:
            f.write('\n'.join(src))
print(f'-- {total} baris {"diubah" if apply else "akan diubah"} di {len(SPEC)} berkas')
