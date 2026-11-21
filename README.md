# Service Suspension Audit (WHMCS Addon)

Addon admin WHMCS yang menampilkan service berstatus `Active` yang due date-nya sudah lewat atau jatuh tempo dalam N hari ke depan, yaitu service yang seharusnya sudah disuspend. Opsional: tampilkan juga service `Free Account` / `One Time`. Detail rancangan ada di [PLAN.md](PLAN.md).

## Kompatibilitas

| Komponen | Versi yang didukung |
|---|---|
| WHMCS | 8.13.x (diuji untuk 8.13.7), 9.x (9.1.1) |
| PHP | 8.1 – 8.3 (target 8.3) |

Modul hanya memakai API yang stabil di kedua versi WHMCS: `WHMCS\Database\Capsule` (query builder Laravel), fungsi addon standar (`_config`, `_activate`, `_deactivate`, `_output`), dan `fromMySQLDate()`/`logActivity()` bila tersedia. Modul bersifat read-only: tidak membuat tabel dan tidak mengubah data.

## Instalasi

1. Backup database dan direktori WHMCS.
2. Salin `modules/addons/serviceSuspensionAudit/` ke `<whmcs_root>/modules/addons/`.
3. Set permission: file 644, folder 755.
4. Aktifkan di **System Settings > Addon Modules** (WHMCS 8/9), lalu **Configure**:
   - `Reminder Days` (default 7, rentang 0–365)
   - `Show Free Account` / `Show One Time`
   - Centang role admin yang boleh mengakses.
5. Buka **Addons > Service Suspension Audit**.

## Aturan Filter

- Billing cycle berulang: `Active` dan `nextduedate <= hari ini + Reminder Days`. Baris yang sudah lewat due date diberi warna merah muda.
- `Free Account` (juga `Free`) dan `One Time` (juga `Onetime`): tampil hanya bila opsinya aktif, dengan due date `-` dan status `Tanpa due date`.
- Service berulang dengan `nextduedate` kosong/`0000-00-00` tidak ditampilkan.

Jika penulisan `billingcycle` di `tblhosting` berbeda, sesuaikan konstanta `SSA_CYCLES_FREE` / `SSA_CYCLES_ONETIME`. Cek dengan `SELECT DISTINCT billingcycle FROM tblhosting;`.

## Kolom Tabel

- **ID**: nomor service, bisa diklik untuk membuka `clientsservices.php` (tab baru).
- **Client**: link ke `clientssummary.php`.
- **Invoice**: invoice yang terkait service (item `tblinvoiceitems` bertipe `Hosting`). Invoice belum lunas (`Unpaid`, `Payment Pending`, `Collections`) ditampilkan semua dengan due date-nya. Jika tidak ada, ditampilkan invoice terakhir sebagai `Terakhir: #ID (Status)`. Semua invoice bisa diklik ke `invoices.php?action=edit`.

## Catatan

- "Hari ini" mengikuti timezone PHP server WHMCS (`date.timezone`).
- Label dapat diubah di `lang/english.php`.
- Uji di staging sebelum production.
