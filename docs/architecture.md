# Arsitektur

## Tujuan

- Satu database SQLite per device, tetap bisa dipakai offline.
- Perubahan dikirim level baris, bukan seluruh file.
- Tidak butuh server sync khusus; object storage cukup.
- Framework hanya adapter tipis di atas core.

## Non-tujuan

- Realtime. Tidak ada push notification atau websocket.
- Multi-writer konkuren pada file SQLite yang sama (SQLite tetap single-writer).
- Shared schema ownership. Migrasi schema tetap tanggung jawab aplikasi.

## Kenapa bukan replikasi file

Upload file `.db` utuh saat WAL aktif bisa menghasilkan snapshot robek. `wal_checkpoint(TRUNCATE)` + lock + upload memperkecil risiko, tapi konflik tetap diselesaikan dengan menimpa seluruh database. Satu device yang basi bisa menghapus data device lain. Delta baris menghindari keduanya.

## Tabel internal

Dibuat oleh `install()`, idempotent.

| Tabel | Fungsi |
|---|---|
| `_sync_log` | antrean perubahan lokal: `tbl, pk, op, payload, changed_at, device_id, batch_id` |
| `_sync_row_state` | versi terakhir per baris: `(tbl, pk) -> (changed_at, device_id)` |
| `_sync_applied` | daftar batch remote yang sudah diterapkan; dipangkas oleh kompaksi |
| `_sync_state` | metadata: `device_id`, `schema_hash`, `installed_at`, `last_push_at`, `last_pull_at`, `last_batch_at` |
| `_sync_guard` | penanda satu baris; selama ada, trigger tidak menulis change-log |

`payload` adalah JSON seluruh kolom baris (bukan diff kolom). Upsert memakai `INSERT ... ON CONFLICT(pk) DO UPDATE`, bukan `INSERT OR REPLACE`, supaya `ON DELETE CASCADE` tidak ikut terpicu.

## Trigger

Tiga trigger per tabel: `_sync_ai_<t>` (insert), `_sync_au_<t>` (update), `_sync_ad_<t>` (delete).

```sql
CREATE TRIGGER "_sync_au_users" AFTER UPDATE ON "users"
WHEN NOT EXISTS (SELECT 1 FROM _sync_guard)
BEGIN
  INSERT INTO _sync_row_state (tbl, pk, changed_at, device_id)
  VALUES ('users', CAST(NEW."id" AS TEXT), <epoch_ms>, '<device>')
  ON CONFLICT (tbl, pk) DO UPDATE SET ...;

  INSERT INTO _sync_log (tbl, pk, op, payload, changed_at, device_id)
  SELECT 'users', CAST(NEW."id" AS TEXT), 'upsert', json_object(...), changed_at, device_id
  FROM _sync_row_state WHERE tbl = 'users' AND pk = CAST(NEW."id" AS TEXT);
END;
```

`changed_at` dihitung sekali per trigger lewat `_sync_row_state`, lalu dibaca ulang untuk log, sehingga log dan row-state tidak pernah beda timestamp.

Delete memakai `OLD`, bukan `NEW`.

`_sync_guard` adalah cara menekan feedback loop: saat engine menerapkan batch remote, satu baris dimasukkan ke guard di dalam transaksi, trigger berhenti mencatat, lalu guard dihapus. Tanpa ini, data remote akan ter-log sebagai perubahan lokal dan dipush balik selamanya.

## Push

```
loop:
  rows = SELECT * FROM _sync_log WHERE batch_id IS NULL ORDER BY id LIMIT chunk_size
  if empty: break
  batch = {batch_id, device_id, created_at, schema_hash, schema, entries[]}
  put("batches/<device>/<created_at>-<batch_id>.json", batch)
  DELETE FROM _sync_log WHERE id IN (rows)
```

Batch immutable. Nama key memakai timestamp monotonik + random id, jadi tidak ada overwrite. Timestamp batch dipaksa naik minimal 1 ms dari batch sebelumnya (`_sync_state.last_batch_at`) supaya key selalu urut sesuai urutan pembuatan, termasuk saat dua push terjadi di milidetik yang sama.

Push aman diulang: baris yang sudah ter-upload langsung dihapus dari `_sync_log`. Jika proses mati setelah `put()` tapi sebelum `DELETE`, batch ter-upload dua kali dengan key berbeda; pull tetap idempotent karena baris dibandingkan dengan `_sync_row_state`, bukan dihitung.

## Pull

```
for key in list("batches/"):
  skip if key milik device sendiri
  skip if key in _sync_applied
  batch = get(key)
  assert remote columns subset of local schema
  transaction:
    for entry in batch.entries:
      apply(entry)
    insert _sync_applied(key)
write checkpoint state/<device>.json
```

`apply(entry)`:

```
local = _sync_row_state[tbl, pk]
if local == remote: skip
if local exists and remote does not win: conflict++, skip
DELETE FROM _sync_log WHERE batch_id IS NULL AND tbl=? AND pk=?   -- buang perubahan lokal yang kalah
guard ON
  op == delete ? DELETE FROM tbl WHERE pk=?
               : INSERT ... ON CONFLICT(pk) DO UPDATE ...
guard OFF
_sync_row_state[tbl, pk] = remote version
```

Batch diterapkan dalam satu transaksi. Gagal di tengah batch = rollback penuh, `_sync_applied` tidak ditulis, batch dicoba lagi pada `pull()` berikutnya.

## Kompaksi dan checkpoint

Tanpa kompaksi, bucket tumbuh tanpa batas. Setiap device menulis `state/<device>.json` setelah pull sukses:

```json
{
  "device_id": "laptop-01",
  "segment": "laptop-01",
  "updated_at": 1794142196049,
  "sources": {
    "phone-01": "sqlite-sync/batches/phone-01/1794142195000-a1b2.json"
  }
}
```

`sources` menyimpan key batch tertinggi yang sudah diterapkan per device sumber. Karena key per device monotonik, "sudah menerapkan key X" berarti "sudah menerapkan semua key <= X".

`compact(keepPerDevice)`:

1. Baca semua checkpoint di `state/`.
2. Kelompokkan batch per device sumber.
3. Untuk batch dari sumber S, cek semua checkpoint device **selain S**. Batch boleh dihapus hanya jika setiap checkpoint lain punya watermark `sources[S] >= key`.
4. Sisakan `keepPerDevice` batch terbaru per sumber (default 1) sebagai margin.
5. Hapus object batch + baris `_sync_applied` terkait.

Device sumber tidak perlu batch-nya sendiri, jadi checkpoint-nya tidak dihitung untuk batch miliknya. Jika belum ada checkpoint device lain, kompaksi tidak menghapus apa pun.

Konsekuensi: device yang belum pernah pull sebelum kompaksi kehilangan history itu. Karena itu kompaksi manual (cron), bukan otomatis di request. Snapshot (roadmap) akan menutup celah ini untuk device baru.

## Resolusi konflik

`remoteWins = remote.changed_at > local.changed_at`
`          || (sama && remote.device_id > local.device_id)`

Sifat:

- Komutatif: urutan penerapan batch tidak mengubah hasil akhir.
- Idempotent: menerapkan entry yang sama dua kali tidak berefek.
- Deterministik: semua device memilih pemenang yang sama tanpa server.

Trade-off: update yang lebih baru secara wall-clock menang. Jika jam device meleset, keputusan bisa salah. Sinkronkan NTP, atau ganti ke logical clock (Lamport/HLC) bila butuh ketahanan clock skew.

## Bootstrap

Saat `install()` pertama kali pada tabel yang belum punya row-state, seluruh baris yang ada dimasukkan ke `_sync_log` sebagai upsert. Jadi device pertama otomatis meng-upload isi database tanpa langkah snapshot terpisah.

Urutan penting: `install()` sebelum aplikasi menulis, atau jalankan `install()` setelah migrasi dan sebelum request pertama. Baris yang ditulis sebelum trigger ada tetap tertangkap lewat bootstrap, tapi hanya jika tabel belum punya row-state sama sekali.

## Schema drift

Setiap batch membawa `schema_hash` dan deskripsi schema (`tables -> pk + columns`).

Aturan saat `pull()`:

- Batch dengan kolom **lebih sedikit** dari schema lokal tetap diterapkan. Ini yang membuat batch lama masih valid setelah migrasi.
- Batch dengan tabel, primary key, atau kolom yang **tidak ada** di lokal melempar `SchemaMismatchException`. Device harus migrasi dulu.
- `install()` menyimpan hash. Jika hash berubah (misalnya setelah `ALTER TABLE`), semua baris di-log ulang sebagai upsert agar kolom baru ikut terkirim.

Tanpa aturan ini, merge dua schema berbeda bisa membuat data hilang. Jalankan migrasi yang sama di semua device sebelum `pull()`.

`install()` idempotent dan murah: jika meta table ada, hash cocok, dan ketiga trigger per tabel ada, method langsung return tanpa DDL. Jadi aman dipanggil di bootstrap tiap request.

## Skala dan biaya

- `list()` memindai seluruh prefix `batches/`. Untuk ribuan batch, ini jadi bottleneck (S3 ListObjects 1000 key/request).
- Setiap batch berisi hingga `chunk_size` baris, default 500.
- Payload penuh per baris, bukan diff kolom. Baris lebar = upload lebih besar.
- `_sync_applied` tumbuh satu baris per batch remote; kompaksi memangkasnya bersama batch.

## Batasan

1. **Device baru setelah kompaksi.** Batch yang sudah dihapus tidak bisa diputar ulang. Device yang belum pernah pull harus bootstrap dari snapshot (belum ada) atau dari device lain.
2. **Belum ada snapshot.** DB besar (ratusan MB) harus bootstrap lewat change-log, bukan satu file snapshot.
3. **Satu kolom primary key.** Composite key belum didukung.
4. **Tanpa enkripsi.** Payload JSON polos di bucket. Enkripsi di level bucket atau tambahkan envelope encryption.
5. **Tanpa auth di core.** `ObjectStore` tidak tahu identitas. Batasi akses di IAM/bucket policy per device.
6. **Tanpa retry/backoff.** `sync()` melempar exception; pemanggil yang mengatur retry (queue, cron, middleware).
7. **Tanpa `updated_at` aplikasi.** Versi memakai waktu trigger, bukan kolom bisnis.
8. **Kompaksi memindai seluruh prefix.** Sama seperti `pull()`, biayanya naik seiring jumlah batch. Jalankan dari cron, bukan tiap request.

## Roadmap yang masuk akal

1. Snapshot untuk bootstrap DB besar dan device baru setelah kompaksi.
2. HLC (hybrid logical clock) untuk tahan clock skew.
3. Enkripsi payload per workspace.
4. `pull()` incremental: index batch per device, bukan list seluruh prefix.
5. Composite primary key.
6. Adapter queue resmi (Laravel Horizon, Symfony Messenger).
