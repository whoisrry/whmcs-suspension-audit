# Changelog

## [Unreleased]

### Fixed
- Invoice di kolom Invoice kini wajib milik client yang sama dengan service (`tblinvoices.userid = tblhosting.userid`), agar invoice lama dari service yang pernah dipindah client tidak ikut tampil.

## [1.0.0] - 2026-11-21

### Added
- Addon `serviceSuspensionAudit` untuk WHMCS 8.13.x dan 9.x (PHP 8.1–8.3).
- Konfigurasi `reminder_days`, `show_free`, `show_onetime`.
- Daftar service Active yang lewat/dekat due date, highlight baris overdue, link ke service dan client.
- Service ID bisa diklik (buka di tab baru, gaya link default WHMCS).
- Kolom Invoice: invoice belum lunas terkait service (atau invoice terakhir sebagai fallback), dengan link ke halaman invoice.
- Ringkasan total, jumlah overdue, dan status opsi Free/One Time.
- File bahasa `lang/english.php`.
- README berisi kompatibilitas, instalasi, dan aturan filter.
