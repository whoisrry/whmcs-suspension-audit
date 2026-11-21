# Plan: Service Suspension Audit (WHMCS Addon Module)

## Tujuan
Menampilkan daftar service customer yang:
- Due date-nya dalam 7 hari ke depan, atau
- Due date-nya sudah lewat, tetapi status service masih `Active`.

Opsional, service dengan billing cycle `Free Account` dan `One Time` (tidak punya due date) bisa ditampilkan dengan checkbox di setting addon.

Tujuan utamanya mengidentifikasi service yang seharusnya sudah disuspend tetapi masih aktif.

## Struktur Direktori

Addon WHMCS wajib berada di `modules/addons/`. Nama folder harus sama dengan nama file PHP utama.

```
<whmcs_root>/
└── modules/
    └── addons/
        └── serviceSuspensionAudit/
            ├── serviceSuspensionAudit.php   # Config, activate, deactivate, output
            ├── lang/
            │   └── english.php              # (opsional) terjemahan label
            └── templates/
                └── audit.tpl                # (opsional) template Smarty jika ingin dipisah
```

Catatan penyesuaian:
- Nama folder dan nama file utama harus identik (case-sensitive di Linux).
- Fungsi harus berprefix `serviceSuspensionAudit_` sesuai konvensi WHMCS.
- Jika tidak butuh `lang/` atau `templates/`, cukup satu file saja.
- Jangan letakkan hooks di folder addon. Jika nanti perlu notifikasi otomatis, gunakan `includes/hooks/`.

## Konfigurasi Addon

| Field | Tipe | Default | Fungsi |
|---|---|---|---|
| `reminder_days` | text | 7 | Jumlah hari ke depan untuk cek due date |
| `show_free` | yesno | off | Tampilkan service `Free Account` |
| `show_onetime` | yesno | off | Tampilkan service `One Time` |

## Aturan Filter

| Billing Cycle | Dicek due date? | Kondisi tampil |
|---|---|---|
| Berulang (Monthly, Annually, dll) | Ya | `Active` AND `nextduedate <= hari ini + reminder_days` |
| `Free Account` | Tidak | `Active` AND `show_free` = on |
| `One Time` | Tidak | `Active` AND `show_onetime` = on |

Status tampilan:
- Lewat due date: baris merah muda.
- Due dalam N hari: status normal.
- Free / One Time: due date ditampilkan `-`, status `Tanpa due date`.

## Tahapan Pengerjaan

1. **Setup**
   - Buat folder dan file sesuai struktur di atas.
   - Set permission: 644 untuk file, 755 untuk folder.

2. **Konfigurasi Addon**
   - Isi `serviceSuspensionAudit_config()` (nama, versi, author).
   - Tambahkan field `reminder_days`, `show_free`, dan `show_onetime`.

3. **Aktivasi**
   - Aktifkan addon dari Setup > Addon Modules.
   - Set konfigurasi awal.
   - Berikan akses ke admin role yang relevan.

4. **Verifikasi Data**
   - Cek nilai `billingcycle` di database (`tblhosting`) agar persis `Free Account` dan `One Time`.
   - Jika ada perbedaan penulisan, sesuaikan array `$noDueCycles`.

5. **Query Data**
   - Ambil dari `tblhosting` join `tblclients` dan `tblproducts`.
   - Terapkan aturan filter di atas.
   - Urutkan berdasarkan `nextduedate` ascending.

6. **Output**
   - Tabel: ID, client, email, produk, billing cycle, domain, due date, status.
   - Highlight merah untuk yang sudah lewat due date.
   - Tampilkan total jumlah service dan status checkbox Free / One Time.

7. **Testing**
   - Uji di staging, bukan production.
   - Data dummy: due hari ini, H+3, H+8, H-5 (lewat, masih Active), Free Account, One Time.
   - Pastikan Free dan One Time muncul hanya saat checkbox dicentang.
   - Pastikan link ke `clientsservices.php` berfungsi.
   - Bandingkan jumlah hasil dengan query manual di database.

8. **Deploy**
   - Backup database dan direktori WHMCS sebelum deploy.
   - Copy folder addon ke production setelah lolos testing.
   - Verifikasi tampilan dan jumlah data.

## Pertanyaan Terbuka
- Apakah perlu fitur export CSV?
- Apakah perlu notifikasi email otomatis ke admin?

## Catatan Keamanan
- Jangan simpan kredensial database atau API key di file addon.
- Gunakan `htmlspecialchars()` untuk semua output dari database.
- Batasi akses addon hanya untuk admin yang berhak.
